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

  function formatLongDate(ymd) {
    var d = parseYmd(ymd);
    if (!d) return ymd || '';
    return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'long', year: 'numeric' });
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
    $(document).on('keydown', function (event) {
      if (event.key === 'Escape') $modal.hide();
    });
    return $modal;
  }

  function openDayDetails(date, scope) {
    var $modal = ensureModal();
    $modal.find('.cie-modal__content').html('<h4>Detalle de ' + escapeHtml(formatLongDate(date)) + '</h4><p>Cargando...</p>');
    $modal.show();

    if (!window.CieLabBooking || !window.CieLabBooking.ajaxUrl) {
      $modal.find('.cie-modal__content').html('<h4>Detalle</h4><p>No se pudo cargar el detalle.</p>');
      return;
    }

    $.post(window.CieLabBooking.ajaxUrl, {
      action: 'cie_lab_booking_day_details',
      nonce: window.CieLabBooking.nonce,
      date: date,
      calendar_scope: scope || 'general'
    }).done(function (response) {
      if (!response || !response.success || !response.data) {
        $modal.find('.cie-modal__content').html('<h4>Detalle</h4><p>No se pudo cargar el detalle.</p>');
        return;
      }
      var data = response.data;
      var html = '<h4>Detalle de ' + escapeHtml(formatLongDate(date)) + '</h4>';
      if (!data.bookings.length && !data.blocks.length) {
        html += '<p><em>No hay reservas ni bloqueos.</em></p>';
        $modal.find('.cie-modal__content').html(html);
        return;
      }

      if (data.blocks.length) {
        html += '<h5>Mantenimiento</h5><div class="cie-cal-block-list">';
        data.blocks.forEach(function (block) {
          var resources = [];
          if (block.isGlobal) resources.push('Todos los recursos');
          if (Array.isArray(block.resources)) resources = resources.concat(block.resources);
          html += '<article class="cie-cal-block-card">';
          html += '<div><strong>Mantenimiento</strong></div>';
          html += '<div class="cie-cal-muted">' + escapeHtml(formatLongDate(block.start_date)) + ' - ' + escapeHtml(formatLongDate(block.end_date)) + '</div>';
          if (resources.length) {
            html += '<div class="cie-cal-muted">' + escapeHtml(resources.join(', ')) + '</div>';
          }
          html += '</article>';
        });
        html += '</div>';
      }

      html += '<h5>Reservas</h5>';
      if (!data.bookings.length) {
        html += '<p><em>No hay reservas.</em></p>';
      } else {
        html += '<div class="cie-cal-booking-list">';
        data.bookings.forEach(function (booking) {
          var resources = [];
          if (Array.isArray(booking.spaces)) resources = resources.concat(booking.spaces);
          if (Array.isArray(booking.equipment)) resources = resources.concat(booking.equipment);
          var occurrences = Array.isArray(booking.occurrences) ? booking.occurrences : [];
          var timeSummary = occurrences.map(function (occ) {
            if (occ.full_day) return 'Día completo';
            return (occ.start || '') + ' - ' + (occ.end || '');
          }).join(', ');
          html += '<article class="cie-cal-booking-card">';
          html += '<div class="cie-cal-booking-card__badges">';
          html += '<span class="cie-status-tag cie-status-tag--' + escapeHtml(statusSlug(booking.status)) + '">' + escapeHtml(statusLabel(booking.status)) + '</span>';
          html += '</div>';
          html += '<div class="cie-cal-booking-card__resource">' + escapeHtml(resources[0] || ('Reserva #' + booking.id)) + '</div>';
          html += '<div class="cie-cal-muted">' + escapeHtml(timeSummary || 'Día completo') + '</div>';
          if (resources.length > 1) {
            html += '<div class="cie-cal-booking-card__extra">' + escapeHtml(resources.slice(1).join(', ')) + '</div>';
          }
          html += '</article>';
        });
        html += '</div>';
      }
      $modal.find('.cie-modal__content').html(html);
    }).fail(function () {
      $modal.find('.cie-modal__content').html('<h4>Detalle</h4><p>No se pudo cargar el detalle.</p>');
    });
  }

  function initBookingForm($flow) {
    if (!$flow.length) return;

    var locale = (window.flatpickr && window.flatpickr.l10ns && window.flatpickr.l10ns.es) ? window.flatpickr.l10ns.es : 'default';

    $flow.find('input.cie-date').each(function () {
      if (!window.flatpickr) return;
      window.flatpickr(this, {
        dateFormat: 'Y-m-d',
        locale: locale,
        disableMobile: true,
        altInput: true,
        altFormat: 'd/m/Y',
        allowInput: true
      });
    });

    $flow.find('input.cie-date-multiple').each(function () {
      if (!window.flatpickr) return;
      window.flatpickr(this, {
        mode: 'multiple',
        conjunction: ', ',
        dateFormat: 'Y-m-d',
        locale: locale,
        disableMobile: true,
        altInput: true,
        altFormat: 'd/m/Y'
      });
    });

    function selectedMode() {
      return $flow.find('input[name="booking_mode"]:checked').val() || 'full_day';
    }

    function selectedFrequency() {
      return $flow.find('input[name="booking_frequency"]:checked').val() || 'single';
    }

    function selectedPrimaryDate() {
      var frequency = selectedFrequency();
      var start = String($flow.find('input[name="start_date"]').val() || '').trim();
      if (frequency === 'manual_dates') {
        var raw = String($flow.find('input[name="booking_dates_raw"]').val() || '').trim();
        if (!raw) return '';
        var first = raw.split(/[,\s;]+/).filter(Boolean)[0] || '';
        return first;
      }
      return start;
    }

    function selectedResources() {
      var spaces = [];
      var equipment = [];
      $flow.find('input[name="spaces[]"]:checked').each(function () {
        spaces.push(parseInt(String($(this).val()), 10));
      });
      $flow.find('input[name="equipment[]"]:checked').each(function () {
        equipment.push(parseInt(String($(this).val()), 10));
      });
      return {
        spaces: spaces.filter(Boolean),
        equipment: equipment.filter(Boolean)
      };
    }

    function parseRequires($input) {
      var raw = String($input.attr('data-cie-requires') || '').trim();
      if (!raw) return [];
      try {
        var parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return [];
        return parsed.map(function (id) { return parseInt(String(id), 10); }).filter(Boolean);
      } catch (error) {
        return [];
      }
    }

    function applyEquipmentDependencies() {
      var byId = {};
      var names = {};
      var messages = [];
      $flow.find('input[name="equipment[]"]').each(function () {
        var id = parseInt(String($(this).val()), 10);
        if (!id) return;
        byId[id] = $(this);
        names[id] = String($(this).attr('data-cie-equipment-name') || ('Equipo #' + id));
        $(this).removeAttr('data-cie-locked');
        $(this).closest('.cie-option').removeClass('cie-option--locked');
      });

      $flow.find('input[name="equipment[]"]:checked').each(function () {
        var sourceId = parseInt(String($(this).val()), 10);
        var reqIds = parseRequires($(this));
        reqIds.forEach(function (reqId) {
          var $required = byId[reqId];
          if (!$required || !$required.length || $required.prop('disabled')) return;
          $required.prop('checked', true);
          $required.attr('data-cie-locked', '1');
          $required.closest('.cie-option').addClass('cie-option--locked');
          messages.push('"' + names[sourceId] + '" requiere "' + names[reqId] + '".');
        });
      });

      var $notice = $flow.find('[data-cie-notice="equipment-deps"]');
      if (messages.length) {
        $notice.text(messages.join(' ')).show().addClass('is-info');
      } else {
        $notice.hide().text('').removeClass('is-info');
      }
    }

    function updateScheduleNotice() {
      var mode = selectedMode();
      var frequency = selectedFrequency();
      var start = String($flow.find('input[name="start_date"]').val() || '').trim();
      var end = String($flow.find('input[name="end_date"]').val() || '').trim();
      var timeStart = String($flow.find('input[name="booking_time_start"]').val() || '').trim();
      var timeEnd = String($flow.find('input[name="booking_time_end"]').val() || '').trim();
      var weeks = parseInt(String($flow.find('input[name="booking_recurrence_weeks"]').val() || '1'), 10);
      var manual = String($flow.find('input[name="booking_dates_raw"]').val() || '').trim();
      var summary = '';

      if (frequency === 'single') {
        if (mode === 'full_day') {
          summary = end ? ('Reserva de día completo del ' + formatLongDate(start) + ' al ' + formatLongDate(end) + '.') : ('Reserva de día completo para ' + formatLongDate(start) + '.');
        } else {
          summary = 'Reserva por horas para ' + formatLongDate(start) + ' (' + timeStart + ' - ' + timeEnd + ').';
        }
      } else if (frequency === 'weekly_repeat') {
        summary = (mode === 'full_day' ? 'Reserva semanal de día completo' : 'Reserva semanal por horas') + ' durante ' + (weeks || 1) + ' semanas.';
      } else {
        summary = (mode === 'full_day' ? 'Reserva de días sueltos' : 'Reserva por horas en días sueltos') + (manual ? ' (' + manual + ').' : '.');
      }
      var $notice = $flow.find('[data-cie-notice="schedule"]');
      $notice.text(summary).show();
    }

    var slotsXhr = null;
    function updateSlotsAvailability() {
      var mode = selectedMode();
      var resources = selectedResources();
      var date = selectedPrimaryDate();
      var $box = $flow.find('[data-cie-slot-availability]');
      if (mode !== 'time_range' || !date || (!resources.spaces.length && !resources.equipment.length)) {
        $box.hide().empty();
        return;
      }
      if (!window.CieLabBooking || !window.CieLabBooking.ajaxUrl) return;
      if (slotsXhr && slotsXhr.abort) slotsXhr.abort();

      $box.html('<div class="cie-cal-muted">Comprobando disponibilidad horaria...</div>').show();
      slotsXhr = $.post(window.CieLabBooking.ajaxUrl, {
        action: 'cie_lab_booking_time_slots',
        nonce: window.CieLabBooking.nonce,
        date: date,
        spaces: resources.spaces,
        equipment: resources.equipment
      }).done(function (response) {
        if (!response || !response.success || !response.data || !Array.isArray(response.data.slots)) {
          $box.html('<div class="cie-cal-muted">No se pudo obtener disponibilidad horaria.</div>').show();
          return;
        }
        var html = '<strong>Bloques disponibles para ' + escapeHtml(formatLongDate(date)) + '</strong><div class="cie-slot-grid">';
        response.data.slots.forEach(function (slot) {
          html += '<span class="cie-slot-chip ' + (slot.available ? 'is-available' : 'is-unavailable') + '">';
          html += escapeHtml(slot.start + ' - ' + slot.end);
          html += '</span>';
        });
        html += '</div>';
        $box.html(html).show();
      }).fail(function () {
        $box.html('<div class="cie-cal-muted">No se pudo obtener disponibilidad horaria.</div>').show();
      });
    }

    function updateVisibility() {
      var mode = selectedMode();
      var frequency = selectedFrequency();
      var useSpace = $flow.find('input[name="use_space"]').is(':checked');
      var useEquipment = $flow.find('input[name="use_equipment"]').is(':checked');

      $flow.find('[data-cie-only-mode]').each(function () {
        var targetMode = String($(this).attr('data-cie-only-mode'));
        $(this).toggle(targetMode === mode);
      });
      $flow.find('[data-cie-only-frequency]').each(function () {
        var targetFrequency = String($(this).attr('data-cie-only-frequency'));
        $(this).toggle(targetFrequency === frequency);
      });
      $flow.find('[data-cie-only-combo]').each(function () {
        var combo = String($(this).attr('data-cie-only-combo'));
        var visible = combo === 'single-full-day' && mode === 'full_day' && frequency === 'single';
        $(this).toggle(visible);
      });
      $flow.find('[data-cie-resource-section="spaces"]').toggle(useSpace);
      $flow.find('[data-cie-resource-section="equipment"]').toggle(useEquipment);

      updateScheduleNotice();
      applyEquipmentDependencies();
      updateSlotsAvailability();
    }

    $flow.on('change input', 'input, select, textarea', updateVisibility);
    $flow.on('click', 'input[name="equipment[]"][data-cie-locked="1"]', function (event) {
      event.preventDefault();
      $(this).prop('checked', true);
    });
    updateVisibility();
  }

  function renderScheduler($container) {
    if (!$container.length) return;

    var scope = String($container.attr('data-cie-calendar-scope') || 'general');
    var context = String($container.attr('data-cie-calendar-context') || 'front');
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
      var day = new Date(state.current.getTime());
      return { start: toYmd(day), end: toYmd(day) };
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
      if (event.statusSlug === 'changes') return 'is-changes';
      if (event.statusSlug === 'rejected') return 'is-rejected';
      if (event.statusSlug === 'cancelled') return 'is-cancelled';
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
      var label = '';
      if (state.view === 'month') {
        label = state.current.toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
      } else if (state.view === 'week') {
        var start = startOfWeek(state.current);
        var end = addDays(start, 6);
        label = formatLongDate(toYmd(start)) + ' - ' + formatLongDate(toYmd(end));
      } else {
        label = formatLongDate(toYmd(state.current));
      }

      var html = '<div class="cie-scheduler__toolbar">';
      html += '<div class="cie-scheduler__nav">';
      html += '<button type="button" data-cie-nav="-1">&larr;</button>';
      html += '<button type="button" data-cie-today="1">Hoy</button>';
      html += '<button type="button" data-cie-nav="1">&rarr;</button>';
      html += '</div>';
      html += '<div class="cie-scheduler__title">' + escapeHtml(label) + '</div>';
      html += '<div class="cie-scheduler__views">';
      html += '<button type="button" data-cie-view="month"' + (state.view === 'month' ? ' class="is-active"' : '') + '>Mes</button>';
      html += '<button type="button" data-cie-view="week"' + (state.view === 'week' ? ' class="is-active"' : '') + '>Semana</button>';
      html += '<button type="button" data-cie-view="day"' + (state.view === 'day' ? ' class="is-active"' : '') + '>Día</button>';
      html += '</div>';
      html += '</div>';
      return html;
    }

    function renderMonth() {
      var first = new Date(state.current.getFullYear(), state.current.getMonth(), 1);
      var gridStart = startOfWeek(first);
      var byDate = eventsByDate();
      var html = '<div class="cie-scheduler__month">';
      html += '<div class="cie-scheduler__week-header"><span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span></div>';
      html += '<div class="cie-scheduler__month-grid">';
      for (var i = 0; i < 42; i++) {
        var day = addDays(gridStart, i);
        var ymd = toYmd(day);
        var events = byDate[ymd] || [];
        var inCurrentMonth = day.getMonth() === state.current.getMonth();
        html += '<button type="button" class="cie-scheduler__day ' + (inCurrentMonth ? '' : 'is-muted') + '" data-cie-date="' + ymd + '">';
        html += '<span class="cie-scheduler__day-number">' + day.getDate() + '</span>';
        html += '<span class="cie-scheduler__day-events">';
        events.slice(0, 2).forEach(function (event) {
          html += '<span class="cie-scheduler__event-chip ' + eventClass(event) + '">' + escapeHtml(event.title) + '</span>';
        });
        if (events.length > 2) {
          html += '<span class="cie-scheduler__event-more">+' + (events.length - 2) + '</span>';
        }
        html += '</span></button>';
      }
      html += '</div></div>';
      return html;
    }

    function renderWeekOrDay() {
      var start = state.view === 'week' ? startOfWeek(state.current) : new Date(state.current.getTime());
      var days = state.view === 'week' ? 7 : 1;
      var rowStyle = ' style="grid-template-columns:76px repeat(' + days + ', minmax(0, 1fr));"';
      var byDate = eventsByDate();
      var html = '<div class="cie-scheduler__time-grid">';
      html += '<div class="cie-scheduler__time-header"' + rowStyle + '><span></span>';
      for (var d = 0; d < days; d++) {
        var day = addDays(start, d);
        var ymd = toYmd(day);
        html += '<button type="button" data-cie-date="' + ymd + '" class="cie-scheduler__time-day">';
        html += escapeHtml(day.toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short' }));
        html += '</button>';
      }
      html += '</div>';

      html += '<div class="cie-scheduler__all-day-row"' + rowStyle + '><div class="cie-scheduler__time-label">Todo el día</div>';
      for (var a = 0; a < days; a++) {
        var allDayDate = toYmd(addDays(start, a));
        var allDayEvents = (byDate[allDayDate] || []).filter(function (event) { return !!event.fullDay; });
        html += '<div class="cie-scheduler__time-cell" data-cie-date="' + allDayDate + '">';
        allDayEvents.forEach(function (event) {
          html += '<span class="cie-scheduler__event-chip ' + eventClass(event) + '">' + escapeHtml(event.title) + '</span>';
        });
        html += '</div>';
      }
      html += '</div>';

      for (var hour = 8; hour < 20; hour++) {
        var startMinutes = hour * 60;
        var endMinutes = (hour + 1) * 60;
        html += '<div class="cie-scheduler__time-row"' + rowStyle + '><div class="cie-scheduler__time-label">' + String(hour).padStart(2, '0') + ':00</div>';
        for (var c = 0; c < days; c++) {
          var cellDate = toYmd(addDays(start, c));
          var cellEvents = (byDate[cellDate] || []).filter(function (event) {
            if (event.fullDay || event.type === 'block') return false;
            var evStart = timeToMinutes(event.start || '');
            var evEnd = timeToMinutes(event.end || '');
            if (evStart < 0 || evEnd < 0) return false;
            return evStart < endMinutes && evEnd > startMinutes;
          });
          html += '<div class="cie-scheduler__time-cell" data-cie-date="' + cellDate + '">';
          cellEvents.forEach(function (event) {
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
      if (!window.CieLabBooking || !window.CieLabBooking.ajaxUrl) {
        $container.html('<p>No se pudo cargar el calendario.</p>');
        return;
      }
      var range = rangeForView();
      $container.html('<div class="cie-cal-muted">Cargando calendario...</div>');
      $.post(window.CieLabBooking.ajaxUrl, {
        action: 'cie_lab_booking_calendar_feed',
        nonce: window.CieLabBooking.nonce,
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
      if (!date) return;
      openDayDetails(date, scope);
    });

    if (context === 'front') load();
  }

  $(function () {
    $('.cie-lab-booking__flow[data-cie-booking-flow="2"]').each(function () {
      initBookingForm($(this));
    });

    $('.cie-scheduler[data-cie-scheduler="1"]').each(function () {
      renderScheduler($(this));
    });

    $(document).on('click', '.cie-booking-edit-link', function (event) {
      var $link = $(this);
      var bookingId = parseInt(String($link.data('bookingId') || ''), 10);
      if (!bookingId) return;
      event.preventDefault();
      var baseUrl = String($link.data('formUrl') || $link.attr('href') || window.location.href);
      try {
        var url = new URL(baseUrl, window.location.href);
        url.searchParams.set('booking_id', String(bookingId));
        url.searchParams.set('cie_booking_edit', '1');
        url.hash = 'cie-booking-form';
        window.location.assign(url.toString());
      } catch (error) {
        window.location.assign(baseUrl);
      }
    });
  });
})(jQuery);
