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

    function fetchDayDetails(date, cb) {
      if (detailCache[date]) return cb(detailCache[date]);
      if (!window.CieLabBookingAdmin || !window.CieLabBookingAdmin.ajaxUrl) return cb(null);
      if (detailXhr && detailXhr.abort) detailXhr.abort();
      detailXhr = $.post(window.CieLabBookingAdmin.ajaxUrl, {
        action: 'cie_lab_booking_day_details',
        nonce: window.CieLabBookingAdmin.nonce,
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
        html += '<h3>Mantenimiento</h3><ul>';
        blocks.forEach(function (b) {
          var r = [];
          if (b.isGlobal) r.push('Global');
          if (b.resources && b.resources.length) r = r.concat(b.resources);
          html +=
            '<li>' +
            (b.start_date || '') +
            ' - ' +
            (b.end_date || '') +
            (r.length ? ' · ' + r.join(', ') : '') +
            (b.reason ? ' · ' + b.reason : '') +
            '</li>';
        });
        html += '</ul>';
      }

      html += '<h3>Reservas</h3>';
      if (!bookings.length) {
        html += '<p><em>No hay reservas.</em></p>';
      } else {
        html += '<ul class="cie-cal-list">';
        bookings.forEach(function (b) {
          var resources = [];
          if (b.spaces && b.spaces.length) resources = resources.concat(b.spaces);
          if (b.equipment && b.equipment.length) resources = resources.concat(b.equipment);
          var user = b.user ? (b.user.displayName || '') : '';
          html +=
            '<li>' +
            '<div><strong>' +
            (b.start_date || '') +
            ' - ' +
            (b.end_date || '') +
            '</strong></div>' +
            '<div class="cie-cal-muted">' +
            (statusLabel(b.status) ? statusLabel(b.status) + ' · ' : '') +
            (user ? user + ' · ' : '') +
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

    $(document).on('mouseenter', '.cie-calendar-day[data-cie-date]', function (e) {
      var date = $(this).data('cie-date');
      if (!date) return;
      var $t = ensureTooltip();
      $t.text('Cargando...').show();
      $t.css({ left: e.pageX + 12, top: e.pageY + 12, position: 'absolute' });
      fetchDayDetails(date, function (data) {
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
      fetchDayDetails(date, function (data) {
        openModal(date, data);
      });
    });
  });
})(jQuery);

