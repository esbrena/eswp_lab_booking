(function ($) {
  $(function () {
    var $dates = $('input.cie-date');
    if ($dates.length && window.flatpickr) {
      var fpLocale = (window.flatpickr.l10ns && window.flatpickr.l10ns.es) ? window.flatpickr.l10ns.es : null;
      $dates.each(function () {
        window.flatpickr(this, {
          dateFormat: 'Y-m-d',
          locale: fpLocale || 'default',
          disableMobile: true,
          altInput: true,
          altFormat: 'd/m/Y',
          allowInput: true,
        });
      });
    }

    function setupBlockResourcePickers() {
      $('[data-cie-all-toggle="1"]').each(function () {
        var $toggle = $(this);
        var $container = $toggle.closest('form, .inside, .cie-admin-panel');
        var $picker = $container.find('[data-cie-resource-picker="1"]').first();
        if (!$picker.length) return;

        function refresh() {
          var all = $toggle.is(':checked');
          $picker.toggle(!all);
          $picker.find('input[type="checkbox"]').prop('disabled', all);
        }

        $toggle.on('change', refresh);
        refresh();
      });
    }

    function setupResourceMetaForm() {
      $('[data-cie-resource-kind="1"]').each(function () {
        var $kind = $(this);
        var $container = $kind.closest('.inside, form');
        var $groupWrap = $container.find('[data-cie-resource-group-wrap="1"]').first();
        var $qtyWrap = $container.find('[data-cie-resource-quantity-wrap="1"]').first();
        var $groupSelect = $container.find('[data-cie-resource-group-existing="1"]').first();
        var $groupNewInput = $container.find('input[name="cie_resource_group_new"]').first();

        function refreshKind() {
          var isEquipment = $kind.val() === 'equipment';
          $groupWrap.toggle(isEquipment);
          $qtyWrap.toggle(isEquipment);
          $groupWrap.find('input,select').prop('disabled', !isEquipment);
          $qtyWrap.find('input').prop('disabled', !isEquipment);
        }

        function refreshGroupMode() {
          var creatingNew = $groupSelect.val() === '__new__';
          if ($groupNewInput.length) {
            $groupNewInput.prop('disabled', !creatingNew);
            $groupNewInput.closest('p').toggle(creatingNew);
          }
        }

        $kind.on('change', refreshKind);
        $groupSelect.on('change', refreshGroupMode);
        refreshKind();
        refreshGroupMode();
      });
    }

    setupBlockResourcePickers();
    setupResourceMetaForm();

    // Calendar hover/click details on admin calendar table.
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

    function statusSlug(status) {
      var map = {
        pending: 'pending',
        approved: 'approved',
        rejected: 'rejected',
        changes_requested: 'changes',
        cancelled: 'cancelled',
      };
      return map[status] || 'unknown';
    }

    function fetchDayDetails(date, calendarScope, cb) {
      var scope = calendarScope || 'general';
      var cacheKey = date + '|' + scope;
      if (detailCache[cacheKey]) return cb(detailCache[cacheKey]);
      if (!window.CieLabBookingAdmin || !window.CieLabBookingAdmin.ajaxUrl) return cb(null);
      if (detailXhr && detailXhr.abort) detailXhr.abort();
      detailXhr = $.post(window.CieLabBookingAdmin.ajaxUrl, {
        action: 'cie_lab_booking_day_details',
        nonce: window.CieLabBookingAdmin.nonce,
        date: date,
        calendar_scope: scope,
      })
        .done(function (res) {
          if (res && res.success && res.data) {
            detailCache[cacheKey] = res.data;
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
        var blockResources = [];
        blocks.forEach(function (b) {
          if (b.isGlobal) blockResources.push('Global');
          if (b.resources && b.resources.length) blockResources = blockResources.concat(b.resources);
        });
        html += '<div class="cie-cal-tooltip__line"><strong>Mantenimiento</strong>: ' + (blockResources.length ? blockResources.slice(0, 3).join(', ') : blocks.length) + (blockResources.length > 3 ? '…' : '') + '</div>';
      }
      if (bookings.length) {
        var res = [];
        bookings.forEach(function (b) {
          if (b.spaces && b.spaces.length) res = res.concat(b.spaces);
          if (b.equipment && b.equipment.length) res = res.concat(b.equipment);
        });
        var uniq = {};
        res = res.filter(function (x) {
          if (!x) return false;
          if (uniq[x]) return false;
          uniq[x] = true;
          return true;
        });
        html += '<div class="cie-cal-tooltip__line"><strong>Reservado</strong>: ' + (res.length ? res.slice(0, 3).join(', ') : bookings.length) + (res.length > 3 ? '…' : '') + '</div>';
      } else {
        html += '<div class="cie-cal-tooltip__line">Sin reservas</div>';
      }
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

    function renderBookingCard(b) {
      var resources = [];
      if (b.spaces && b.spaces.length) resources = resources.concat(b.spaces);
      if (b.equipment && b.equipment.length) resources = resources.concat(b.equipment);
      var primaryResource = resources.length ? resources[0] : 'Reserva';
      var bookingDates = (b.start_date || '') + ' - ' + (b.end_date || '');
      var user = b.user ? (b.user.displayName || '') : '';
      var badges = '';
      if (b.spaces && b.spaces.length) {
        badges += '<span class="cie-resource-badge cie-resource-badge--space">Espacio</span>';
      }
      if (b.equipment && b.equipment.length) {
        badges += '<span class="cie-resource-badge cie-resource-badge--equipment">Equipo</span>';
      }
      if (b.status) {
        badges +=
          '<span class="cie-status-tag cie-status-tag--' +
          statusSlug(b.status) +
          '">' +
          statusLabel(b.status) +
          '</span>';
      }

      return (
        '<article class="cie-cal-booking-card">' +
          '<div class="cie-cal-booking-card__resource">' + primaryResource + '</div>' +
          '<div class="cie-cal-booking-card__meta">ID #' + (b.id || '') + ' · ' + bookingDates + (user ? ' · ' + user : '') + '</div>' +
          '<div class="cie-cal-booking-card__badges">' + badges + '</div>' +
          (resources.length > 1 ? '<div class="cie-cal-booking-card__extra">' + resources.slice(1).join(', ') + '</div>' : '') +
          (b.detailUrl ? '<div class="cie-cal-booking-card__actions"><a href="' + b.detailUrl + '">Ver detalle</a></div>' : '') +
        '</article>'
      );
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

      var html = '<h2 style="margin-top:0">Detalle de ' + date + '</h2>';
      if (blocks.length) {
        html += '<h3>Mantenimiento</h3><div class="cie-cal-block-list">';
        blocks.forEach(function (b) {
          var r = [];
          if (b.isGlobal) r.push('Global');
          if (b.resources && b.resources.length) r = r.concat(b.resources);
          html +=
            '<article class="cie-cal-block-card">' +
              '<div><strong>Mantenimiento</strong></div>' +
              '<div class="cie-cal-muted">' + (b.start_date || '') + ' - ' + (b.end_date || '') + '</div>' +
              (r.length ? '<div class="cie-cal-block-card__resources">' + r.join(', ') + '</div>' : '') +
              (b.reason ? '<div class="cie-cal-muted">' + b.reason + '</div>' : '') +
            '</article>';
        });
        html += '</div>';
      }

      html += '<h3>Reservas</h3>';
      if (!bookings.length) {
        html += '<p><em>No hay reservas.</em></p>';
      } else {
        html += '<div class="cie-cal-booking-list">';
        bookings.forEach(function (b) {
          html += renderBookingCard(b);
        });
        html += '</div>';
      }
      $c.html(html);
      $m.show();
    }

    $(document).on('mouseenter', '.cie-calendar-day[data-cie-date]', function (e) {
      var date = $(this).data('cie-date');
      if (!date) return;
      var scope = ($(this).closest('[data-cie-calendar-scope]').data('cie-calendar-scope') || 'general').toString();
      var $t = ensureTooltip();
      $t.text('Cargando...').show();
      $t.css({ left: e.pageX + 12, top: e.pageY + 12, position: 'absolute' });
      fetchDayDetails(date, scope, function (data) {
        renderTooltip(date, data);
      });
    });

    $(document).on('mousemove', '.cie-calendar-day[data-cie-date]', function (e) {
      if (!tooltip || !tooltip.is(':visible')) return;
      tooltip.css({ left: e.pageX + 12, top: e.pageY + 12 });
    });

    $(document).on('mouseleave', '.cie-calendar-day[data-cie-date]', function () {
      if (tooltip) tooltip.hide();
    });

    $(document).on('click', '.cie-calendar-day[data-cie-date]', function () {
      var date = $(this).data('cie-date');
      if (!date) return;
      var scope = ($(this).closest('[data-cie-calendar-scope]').data('cie-calendar-scope') || 'general').toString();
      fetchDayDetails(date, scope, function (data) {
        openModal(date, data);
      });
    });
  });
})(jQuery);

