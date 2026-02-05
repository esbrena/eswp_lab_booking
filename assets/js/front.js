(function ($) {
  $(function () {
    var $root = $('.cie-lab-booking');
    var $flow = $root.find('.cie-lab-booking__flow[data-cie-booking-flow="1"]');
    var $start = $root.find('input.cie-date[name="start_date"]');
    var $end = $root.find('input.cie-date[name="end_date"]');

    function setup($el, onSelect) {
      if (!$el.length || !$el.datepicker) return;
      $el.datepicker({
        dateFormat: 'yy-mm-dd',
        numberOfMonths: 3,
        onSelect: onSelect,
      });
    }

    setup($start, function (dateText) {
      if ($end.length) {
        $end.datepicker('option', 'minDate', dateText);
      }
      updateFlow();
    });
    setup($end, function (dateText) {
      if ($start.length) {
        $start.datepicker('option', 'maxDate', dateText);
      }
      updateFlow();
    });

    function showNotice(key, message, type) {
      var $n = $flow.find('[data-cie-notice="' + key + '"]');
      if (!$n.length) return;
      if (!message) {
        $n.hide().removeClass('is-error is-info is-success').text('');
        return;
      }
      $n
        .removeClass('is-error is-info is-success')
        .addClass(type === 'error' ? 'is-error' : type === 'success' ? 'is-success' : 'is-info')
        .text(message)
        .show();
    }

    function ymd($input) {
      var v = ($input.val() || '').toString().trim();
      return /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : '';
    }

    function toggleStep(step, on) {
      var $fs = $flow.find('fieldset[data-cie-step="' + step + '"]');
      if (!$fs.length) return;
      $fs.toggle(!!on);
      // Disable inputs when hidden to avoid accidental submission.
      $fs.find('input,select,textarea,button').prop('disabled', !on);
    }

    function setSubmitEnabled(enabled) {
      var $btn = $flow.find('[data-cie-submit]');
      if ($btn.length) $btn.prop('disabled', !enabled);
    }

    var availabilityCache = {};
    var availabilityXhr = null;
    var availabilityTimer = null;

    function requestAvailability(start, end, cb) {
      var cacheKey = start + '|' + end;
      if (availabilityCache[cacheKey]) {
        cb(availabilityCache[cacheKey]);
        return;
      }
      if (!window.CieLabBooking || !window.CieLabBooking.ajaxUrl) return;

      if (availabilityXhr && availabilityXhr.abort) availabilityXhr.abort();

      availabilityXhr = $.post(window.CieLabBooking.ajaxUrl, {
        action: 'cie_lab_booking_availability',
        nonce: window.CieLabBooking.nonce,
        start_date: start,
        end_date: end,
      })
        .done(function (res) {
          if (res && res.success && res.data) {
            availabilityCache[cacheKey] = res.data;
            cb(res.data);
          } else {
            cb(null);
          }
        })
        .fail(function () {
          cb(null);
        });
    }

    function applyAvailability(data) {
      if (!data) return { spacesRemoved: 0, equipmentRemoved: 0 };
      var removedSpaces = 0;
      var removedEq = 0;

      // Spaces.
      $flow.find('input[type="checkbox"][name="spaces[]"]').each(function () {
        var id = parseInt($(this).val(), 10);
        var ok = data.spaces && data.spaces[id] !== undefined ? !!data.spaces[id] : true;
        $(this).prop('disabled', !ok);
        $(this).closest('label').toggleClass('cie-is-disabled', !ok);
        if (!ok && $(this).prop('checked')) {
          $(this).prop('checked', false);
          removedSpaces++;
        }
      });

      // Equipment.
      $flow.find('input[type="checkbox"][name="equipment[]"]').each(function () {
        var id = parseInt($(this).val(), 10);
        var ok = data.equipment && data.equipment[id] !== undefined ? !!data.equipment[id] : true;
        $(this).prop('disabled', !ok);
        $(this).closest('label').toggleClass('cie-is-disabled', !ok);
        if (!ok && $(this).prop('checked')) {
          $(this).prop('checked', false);
          removedEq++;
        }
      });

      return { spacesRemoved: removedSpaces, equipmentRemoved: removedEq };
    }

    function allDisabled(name) {
      var $items = $flow.find('input[type="checkbox"][name="' + name + '"]');
      if (!$items.length) return false;
      var enabled = $items.filter(function () {
        return !$(this).prop('disabled');
      });
      return enabled.length === 0;
    }

    function anyChecked(name) {
      return $flow.find('input[type="checkbox"][name="' + name + '"]:checked').length > 0;
    }

    function updateFlow() {
      if (!$flow.length) return;

      var start = ymd($start);
      var end = ymd($end);
      var datesOk = !!start && !!end && end >= start;

      var useSpace = $flow.find('input[name="use_space"]').is(':checked');
      var useEq = $flow.find('input[name="use_equipment"]').is(':checked');

      // Base visibility.
      toggleStep(3, datesOk && useSpace);
      toggleStep(4, datesOk && useEq);

      if (!datesOk) {
        showNotice('dates', '', 'info');
        showNotice('spaces', '', 'info');
        showNotice('equipment', '', 'info');
        showNotice('courses', '', 'info');
        toggleStep(5, false);
        toggleStep(6, false);
        setSubmitEnabled(false);
        return;
      }

      if (!useSpace && !useEq) {
        showNotice('type', 'Seleccione el tipo de instalación que quiere usar (espacios y/o equipos).', 'info');
        toggleStep(5, false);
        toggleStep(6, false);
        setSubmitEnabled(false);
      } else {
        showNotice('type', '', 'info');
      }

      // Debounced availability refresh.
      if (availabilityTimer) window.clearTimeout(availabilityTimer);
      availabilityTimer = window.setTimeout(function () {
        requestAvailability(start, end, function (data) {
          var removed = applyAvailability(data);

          // Step 3 messaging.
          if (useSpace) {
            if (allDisabled('spaces[]')) {
              showNotice(
                'spaces',
                'En las fechas seleccionadas los espacios del laboratorio están reservados. Seleccione otras fechas de reserva.',
                'error'
              );
            } else if (removed.spacesRemoved) {
              showNotice(
                'spaces',
                'En las fechas seleccionadas los espacios del laboratorio están reservados. Seleccione otras fechas de reserva.',
                'error'
              );
            } else {
              showNotice('spaces', '', 'info');
            }
          } else {
            showNotice('spaces', '', 'info');
          }

          // Step 4 messaging.
          if (useEq) {
            if (allDisabled('equipment[]')) {
              showNotice(
                'equipment',
                'En las fechas seleccionadas los equipos del laboratorio seleccionados están reservados. Seleccione otras fechas de reserva.',
                'error'
              );
            } else if (removed.equipmentRemoved) {
              showNotice(
                'equipment',
                'En las fechas seleccionadas los equipos del laboratorio seleccionados están reservados. Seleccione otras fechas de reserva.',
                'error'
              );
            } else {
              showNotice('equipment', '', 'info');
            }
          } else {
            showNotice('equipment', '', 'info');
          }

          // Step 5 visibility logic.
          var step3Ok = !useSpace || anyChecked('spaces[]');
          var step4Ok = !useEq || anyChecked('equipment[]');
          var canProceed = (useSpace || useEq) && step3Ok && step4Ok && !(useSpace && allDisabled('spaces[]')) && !(useEq && allDisabled('equipment[]'));

          toggleStep(5, canProceed);

          // Courses logic.
          var courses = $flow.find('input[name="has_courses"]:checked').val() || '';
          if (!canProceed) {
            showNotice('courses', '', 'info');
            toggleStep(6, false);
            setSubmitEnabled(false);
            return;
          }

          if (courses === 'no') {
            showNotice(
              'courses',
              'Antes de usar los equipos, tiene que realizar los cursos de formación. Acceda a su perfil en la intranet y solicite los cursos correspondientes',
              'error'
            );
            toggleStep(6, false);
            setSubmitEnabled(false);
            return;
          }

          if (courses === 'yes') {
            showNotice('courses', '', 'info');
            toggleStep(6, true);
            setSubmitEnabled(true);
          } else {
            showNotice('courses', '', 'info');
            toggleStep(6, false);
            setSubmitEnabled(false);
          }
        });
      }, 250);
    }

    // Bind changes.
    $flow.on('change', 'input', updateFlow);

    // Init.
    // Hide later steps by default until the logic enables them.
    toggleStep(3, false);
    toggleStep(4, false);
    toggleStep(5, false);
    toggleStep(6, false);
    setSubmitEnabled(false);
    updateFlow();

    // Calendar hover/click details (front + admin calendar shortcode rendered on front).
    var tooltip = null;
    var detailCache = {};
    var detailXhr = null;

    function ensureTooltip() {
      if (tooltip) return tooltip;
      tooltip = $('<div class="cie-cal-tooltip" />').hide();
      $('body').append(tooltip);
      return tooltip;
    }

    function statusLabel(status) {
      var map = {
        pending: 'Pendiente de validar',
        approved: 'Validada',
        rejected: 'No validada',
        changes_requested: 'Cambios solicitados',
        cancelled: 'Anulada',
      };
      return map[status] || status || '';
    }

    function fetchDayDetails(date, cb) {
      if (detailCache[date]) return cb(detailCache[date]);
      if (!window.CieLabBooking || !window.CieLabBooking.ajaxUrl) return cb(null);
      if (detailXhr && detailXhr.abort) detailXhr.abort();
      detailXhr = $.post(window.CieLabBooking.ajaxUrl, {
        action: 'cie_lab_booking_day_details',
        nonce: window.CieLabBooking.nonce,
        date: date,
      })
        .done(function (res) {
          if (res && res.success && res.data) {
            detailCache[date] = res.data;
            cb(res.data);
          } else cb(null);
        })
        .fail(function () {
          cb(null);
        });
    }

    function renderTooltip(date, data) {
      var $t = ensureTooltip();
      if (!data) {
        $t.text('No se pudo cargar el detalle.');
        return;
      }
      var bookings = data.bookings || [];
      var blocks = data.blocks || [];
      var html = '<div class="cie-cal-tooltip__date"><strong>' + date + '</strong></div>';
      if (blocks.length) {
        html += '<div class="cie-cal-tooltip__line">Mantenimiento: ' + blocks.length + '</div>';
      }
      html += '<div class="cie-cal-tooltip__line">Reservas: ' + bookings.length + '</div>';
      $t.html(html);
    }

    function ensureModal() {
      var $m = $('#cie-cal-modal');
      if ($m.length) return $m;
      $m = $(
        '<div id="cie-cal-modal" class="cie-modal" style="display:none">' +
          '<div class="cie-modal__backdrop" data-cie-close></div>' +
          '<div class="cie-modal__panel" role="dialog" aria-modal="true">' +
            '<button type="button" class="cie-modal__close" data-cie-close>&times;</button>' +
            '<div class="cie-modal__content"></div>' +
          '</div>' +
        '</div>'
      );
      $('body').append($m);
      $m.on('click', '[data-cie-close]', function () {
        $m.hide();
      });
      $(document).on('keydown', function (e) {
        if (e.key === 'Escape') $m.hide();
      });
      return $m;
    }

    function openModal(date, data) {
      var $m = ensureModal();
      var $c = $m.find('.cie-modal__content');
      if (!data) {
        $c.html('<p>No se pudo cargar el detalle.</p>');
        $m.show();
        return;
      }
      var bookings = data.bookings || [];
      var blocks = data.blocks || [];

      var html = '<h4>Detalle de ' + date + '</h4>';
      if (blocks.length) {
        html += '<h5>Mantenimiento</h5><ul>';
        blocks.forEach(function (b) {
          html += '<li>' + (b.start_date || '') + ' - ' + (b.end_date || '') + '</li>';
        });
        html += '</ul>';
      }

      html += '<h5>Reservas</h5>';
      if (!bookings.length) {
        html += '<p><em>No hay reservas.</em></p>';
      } else {
        html += '<ul class="cie-cal-list">';
        bookings.forEach(function (b) {
          var resources = [];
          if (b.spaces && b.spaces.length) resources = resources.concat(b.spaces);
          if (b.equipment && b.equipment.length) resources = resources.concat(b.equipment);
          html +=
            '<li>' +
            '<div><strong>' +
            (b.start_date || '') +
            ' - ' +
            (b.end_date || '') +
            '</strong></div>' +
            '<div class="cie-cal-muted">' +
            (statusLabel(b.status) ? statusLabel(b.status) + ' · ' : '') +
            (resources.length ? resources.join(', ') : 'Reservado') +
            '</div>' +
            (b.detailUrl ? '<div><a href="' + b.detailUrl + '">Ver detalle</a></div>' : '') +
            '</li>';
        });
        html += '</ul>';
      }
      $c.html(html);
      $m.show();
    }

    $root.on('mouseenter', '.cie-calendar-day[data-cie-date]', function (e) {
      var date = $(this).data('cie-date');
      if (!date) return;
      var $t = ensureTooltip();
      $t.text('Cargando...').show();
      $t.css({ left: e.pageX + 12, top: e.pageY + 12, position: 'absolute' });
      fetchDayDetails(date, function (data) {
        renderTooltip(date, data);
      });
    });

    $root.on('mousemove', '.cie-calendar-day[data-cie-date]', function (e) {
      if (!tooltip || !tooltip.is(':visible')) return;
      tooltip.css({ left: e.pageX + 12, top: e.pageY + 12 });
    });

    $root.on('mouseleave', '.cie-calendar-day[data-cie-date]', function () {
      if (tooltip) tooltip.hide();
    });

    $root.on('click', '.cie-calendar-day[data-cie-date]', function () {
      var date = $(this).data('cie-date');
      if (!date) return;
      fetchDayDetails(date, function (data) {
        openModal(date, data);
      });
    });
  });
})(jQuery);

