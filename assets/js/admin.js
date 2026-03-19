(function ($) {
  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function parseYmd(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ''))) return null;
    var parts = String(value).split('-');
    return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
  }

  function toYmd(date) {
    var y = date.getFullYear();
    var m = String(date.getMonth() + 1).padStart(2, '0');
    var d = String(date.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
  }

  function addDays(date, days) {
    var copy = new Date(date.getTime());
    copy.setDate(copy.getDate() + days);
    return copy;
  }

  function startOfWeek(date) {
    var d = new Date(date.getTime());
    var day = d.getDay();
    var shift = day === 0 ? -6 : 1 - day;
    d.setDate(d.getDate() + shift);
    d.setHours(0, 0, 0, 0);
    return d;
  }

  function formatLongDate(ymd) {
    var d = parseYmd(ymd);
    if (!d) return ymd || '';
    return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'long', year: 'numeric' });
  }

  function timeToMinutes(value) {
    if (!/^\d{2}:\d{2}$/.test(String(value || ''))) return -1;
    var parts = String(value).split(':');
    return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
  }

  function statusSlug(status) {
    var map = {
      pending: 'pending',
      approved: 'approved',
      rejected: 'rejected',
      changes_requested: 'changes',
      cancelled: 'cancelled',
      blocked: 'blocked'
    };
    return map[status] || status || 'unknown';
  }

  function statusLabel(status) {
    var map = {
      pending: 'Pendiente',
      approved: 'Validada',
      rejected: 'Rechazada',
      changes_requested: 'Cambios solicitados',
      cancelled: 'Anulada',
      blocked: 'Mantenimiento'
    };
    return map[status] || status || '';
  }

  function setupDatePickers() {
    if (!window.flatpickr) return;
    var locale = (window.flatpickr.l10ns && window.flatpickr.l10ns.es) ? window.flatpickr.l10ns.es : 'default';
    $('input.cie-date').each(function () {
      window.flatpickr(this, {
        dateFormat: 'Y-m-d',
        locale: locale,
        disableMobile: true,
        altInput: true,
        altFormat: 'd/m/Y',
        allowInput: true
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
      var $depWrap = $container.find('[data-cie-resource-dependency-wrap="1"]').first();
      var $groupSelect = $container.find('[data-cie-resource-group-existing="1"]').first();
      var $groupNewInput = $container.find('input[name="cie_resource_group_new"]').first();

      function refreshKind() {
        var isEquipment = $kind.val() === 'equipment';
        $groupWrap.toggle(isEquipment);
        $qtyWrap.toggle(isEquipment);
        $depWrap.toggle(isEquipment);
        $groupWrap.find('input,select').prop('disabled', !isEquipment);
        $qtyWrap.find('input').prop('disabled', !isEquipment);
        $depWrap.find('input').prop('disabled', !isEquipment);
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

  function ensureModal() {
    var $modal = $('#cie-cal-modal');
    if ($modal.length) return $modal;
    $modal = $(
      '<div id="cie-cal-modal" class="cie-modal" style="display:none">' +
        '<div class="cie-modal__backdrop" data-cie-close="1"></div>' +
        '<div class="cie-modal__panel" role="dialog" aria-modal="true">' +
          '<button type="button" class="cie-modal__close" data-cie-close="1">&times;</button>' +
          '<div class="cie-modal__content"></div>' +
        '</div>' +
      '</div>'
    );
    $('body').append($modal);
    $modal.on('click', '[data-cie-close="1"]', function () {
      $modal.hide();
    });
    return $modal;
  }

  function openDayDetails(date, scope) {
    var $modal = ensureModal();
    $modal.find('.cie-modal__content').html('<h4>Detalle de ' + escapeHtml(formatLongDate(date)) + '</h4><p>Cargando...</p>');
    $modal.show();
    if (!window.CieLabBookingAdmin || !window.CieLabBookingAdmin.ajaxUrl) return;

    $.post(window.CieLabBookingAdmin.ajaxUrl, {
      action: 'cie_lab_booking_day_details',
      nonce: window.CieLabBookingAdmin.nonce,
      date: date,
      calendar_scope: scope || 'general'
    }).done(function (response) {
      if (!response || !response.success || !response.data) {
        $modal.find('.cie-modal__content').html('<p>No se pudo cargar el detalle.</p>');
        return;
      }
      var data = response.data;
      var html = '<h4>Detalle de ' + escapeHtml(formatLongDate(date)) + '</h4>';
      html += '<h5>Mantenimiento</h5>';
      if (!data.blocks.length) {
        html += '<p><em>Sin bloqueos.</em></p>';
      } else {
        html += '<div class="cie-cal-block-list">';
        data.blocks.forEach(function (block) {
          var resources = [];
          if (block.isGlobal) resources.push('Todos los recursos');
          if (Array.isArray(block.resources)) resources = resources.concat(block.resources);
          html += '<article class="cie-cal-block-card">';
          html += '<div><strong>Mantenimiento</strong></div>';
          html += '<div class="cie-cal-muted">' + escapeHtml(block.start_date + ' - ' + block.end_date) + '</div>';
          html += '<div class="cie-cal-muted">' + escapeHtml(resources.join(', ')) + '</div>';
          html += '</article>';
        });
        html += '</div>';
      }

      html += '<h5>Reservas</h5>';
      if (!data.bookings.length) {
        html += '<p><em>Sin reservas.</em></p>';
      } else {
        html += '<div class="cie-cal-booking-list">';
        data.bookings.forEach(function (booking) {
          var resources = [];
          if (Array.isArray(booking.spaces)) resources = resources.concat(booking.spaces);
          if (Array.isArray(booking.equipment)) resources = resources.concat(booking.equipment);
          html += '<article class="cie-cal-booking-card">';
          html += '<div class="cie-cal-booking-card__badges"><span class="cie-status-tag cie-status-tag--' + escapeHtml(statusSlug(booking.status)) + '">' + escapeHtml(statusLabel(booking.status)) + '</span></div>';
          html += '<div class="cie-cal-booking-card__resource">' + escapeHtml(resources[0] || ('Reserva #' + booking.id)) + '</div>';
          html += '<div class="cie-cal-booking-card__meta">' + escapeHtml(booking.start_date + ' - ' + booking.end_date) + '</div>';
          if (resources.length > 1) {
            html += '<div class="cie-cal-booking-card__extra">' + escapeHtml(resources.slice(1).join(', ')) + '</div>';
          }
          html += '</article>';
        });
        html += '</div>';
      }
      $modal.find('.cie-modal__content').html(html);
    }).fail(function () {
      $modal.find('.cie-modal__content').html('<p>No se pudo cargar el detalle.</p>');
    });
  }

  function renderScheduler($container) {
    if (!$container.length || !window.CieLabBookingAdmin) return;
    var scope = String($container.attr('data-cie-calendar-scope') || 'general');
    var state = {
      view: 'month',
      current: new Date(),
      events: []
    };

    function rangeForView() {
      if (state.view === 'month') {
        var first = new Date(state.current.getFullYear(), state.current.getMonth(), 1);
        var last = new Date(state.current.getFullYear(), state.current.getMonth() + 1, 0);
        return { start: toYmd(first), end: toYmd(last) };
      }
      if (state.view === 'week') {
        var weekStart = startOfWeek(state.current);
        var weekEnd = addDays(weekStart, 6);
        return { start: toYmd(weekStart), end: toYmd(weekEnd) };
      }
      return { start: toYmd(state.current), end: toYmd(state.current) };
    }

    function move(delta) {
      if (state.view === 'month') {
        state.current = new Date(state.current.getFullYear(), state.current.getMonth() + delta, 1);
      } else if (state.view === 'week') {
        state.current = addDays(state.current, delta * 7);
      } else {
        state.current = addDays(state.current, delta);
      }
      load();
    }

    function eventClass(event) {
      if (event.type === 'block') return 'is-block';
      if (event.statusSlug === 'approved') return 'is-approved';
      if (event.statusSlug === 'pending') return 'is-pending';
      return 'is-default';
    }

    function eventsByDate() {
      var map = {};
      state.events.forEach(function (event) {
        if (!map[event.date]) map[event.date] = [];
        map[event.date].push(event);
      });
      return map;
    }

    function renderToolbar() {
      var html = '<div class="cie-scheduler__toolbar">';
      html += '<div class="cie-scheduler__nav"><button type="button" data-cie-nav="-1">&larr;</button><button type="button" data-cie-today="1">Hoy</button><button type="button" data-cie-nav="1">&rarr;</button></div>';
      html += '<div class="cie-scheduler__views"><button type="button" data-cie-view="month"' + (state.view === 'month' ? ' class="is-active"' : '') + '>Mes</button><button type="button" data-cie-view="week"' + (state.view === 'week' ? ' class="is-active"' : '') + '>Semana</button><button type="button" data-cie-view="day"' + (state.view === 'day' ? ' class="is-active"' : '') + '>Día</button></div>';
      html += '</div>';
      return html;
    }

    function renderMonth() {
      var first = new Date(state.current.getFullYear(), state.current.getMonth(), 1);
      var gridStart = startOfWeek(first);
      var byDate = eventsByDate();
      var html = '<div class="cie-scheduler__month"><div class="cie-scheduler__week-header"><span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span></div><div class="cie-scheduler__month-grid">';
      for (var i = 0; i < 42; i++) {
        var day = addDays(gridStart, i);
        var ymd = toYmd(day);
        var events = byDate[ymd] || [];
        html += '<button type="button" class="cie-scheduler__day" data-cie-date="' + ymd + '">';
        html += '<span class="cie-scheduler__day-number">' + day.getDate() + '</span>';
        events.slice(0, 2).forEach(function (event) {
          html += '<span class="cie-scheduler__event-chip ' + eventClass(event) + '">' + escapeHtml(event.title) + '</span>';
        });
        html += '</button>';
      }
      html += '</div></div>';
      return html;
    }

    function renderWeekOrDay() {
      var start = state.view === 'week' ? startOfWeek(state.current) : new Date(state.current.getTime());
      var days = state.view === 'week' ? 7 : 1;
      var rowStyle = ' style="grid-template-columns:76px repeat(' + days + ', minmax(0, 1fr));"';
      var byDate = eventsByDate();
      var html = '<div class="cie-scheduler__time-grid"><div class="cie-scheduler__time-header"' + rowStyle + '><span></span>';
      for (var d = 0; d < days; d++) {
        var day = addDays(start, d);
        html += '<button type="button" data-cie-date="' + toYmd(day) + '" class="cie-scheduler__time-day">' + escapeHtml(day.toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short' })) + '</button>';
      }
      html += '</div>';

      for (var hour = 8; hour < 20; hour++) {
        var startMinutes = hour * 60;
        var endMinutes = (hour + 1) * 60;
        html += '<div class="cie-scheduler__time-row"' + rowStyle + '><div class="cie-scheduler__time-label">' + String(hour).padStart(2, '0') + ':00</div>';
        for (var c = 0; c < days; c++) {
          var date = toYmd(addDays(start, c));
          var events = (byDate[date] || []).filter(function (event) {
            if (event.fullDay || event.type === 'block') return false;
            var evStart = timeToMinutes(event.start || '');
            var evEnd = timeToMinutes(event.end || '');
            return evStart < endMinutes && evEnd > startMinutes;
          });
          html += '<div class="cie-scheduler__time-cell" data-cie-date="' + date + '">';
          events.forEach(function (event) {
            html += '<span class="cie-scheduler__event-chip ' + eventClass(event) + '">' + escapeHtml((event.start || '') + ' ' + event.title) + '</span>';
          });
          html += '</div>';
        }
        html += '</div>';
      }

      html += '</div>';
      return html;
    }

    function render() {
      var html = renderToolbar();
      html += state.view === 'month' ? renderMonth() : renderWeekOrDay();
      $container.html(html);
    }

    function load() {
      var range = rangeForView();
      $container.html('<div class="cie-cal-muted">Cargando calendario...</div>');
      $.post(window.CieLabBookingAdmin.ajaxUrl, {
        action: 'cie_lab_booking_calendar_feed',
        nonce: window.CieLabBookingAdmin.nonce,
        start_date: range.start,
        end_date: range.end,
        calendar_scope: scope
      }).done(function (response) {
        if (!response || !response.success || !response.data || !Array.isArray(response.data.events)) {
          $container.html('<p>No se pudo cargar el calendario.</p>');
          return;
        }
        state.events = response.data.events;
        render();
      }).fail(function () {
        $container.html('<p>No se pudo cargar el calendario.</p>');
      });
    }

    $container.on('click', '[data-cie-nav]', function () {
      move(parseInt(String($(this).attr('data-cie-nav')), 10));
    });
    $container.on('click', '[data-cie-today]', function () {
      state.current = new Date();
      load();
    });
    $container.on('click', '[data-cie-view]', function () {
      state.view = String($(this).attr('data-cie-view') || 'month');
      load();
    });
    $container.on('click', '[data-cie-date]', function () {
      var date = String($(this).attr('data-cie-date') || '');
      if (date) openDayDetails(date, scope);
    });

    load();
  }

  $(function () {
    setupDatePickers();
    setupBlockResourcePickers();
    setupResourceMetaForm();

    $('.cie-scheduler[data-cie-scheduler="1"]').each(function () {
      renderScheduler($(this));
    });
  });
})(jQuery);

