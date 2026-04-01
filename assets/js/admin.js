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
    return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
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

  function monthStart(date) {
    return new Date(date.getFullYear(), date.getMonth(), 1);
  }

  function monthEnd(date) {
    return new Date(date.getFullYear(), date.getMonth() + 1, 0);
  }

  function formatLongDate(ymd) {
    var d = parseYmd(ymd);
    if (!d) return ymd || '';
    return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'long', year: 'numeric' });
  }

  function isPastDate(ymd) {
    var d = parseYmd(ymd);
    if (!d) return false;
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    d.setHours(0, 0, 0, 0);
    return d < today;
  }

  function timeToMinutes(value) {
    if (!/^\d{2}:\d{2}$/.test(String(value || ''))) return -1;
    var parts = String(value).split(':');
    return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
  }

  function minutesToHourLabel(minutes) {
    var h = Math.floor(minutes / 60);
    return String(h).padStart(2, '0') + ':00';
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

  function resourceTypeClass(resourceType) {
    if (resourceType === 'combined') return 'is-resource-combined';
    if (resourceType === 'space') return 'is-resource-space';
    if (resourceType === 'equipment') return 'is-resource-equipment';
    return '';
  }

  function setupDatePickers() {
    if (!window.flatpickr) return;
    var locale = (window.flatpickr.l10ns && window.flatpickr.l10ns.es) ? window.flatpickr.l10ns.es : 'default';
    var todayYmd = toYmd(new Date());
    $('input.cie-date').each(function () {
      window.flatpickr(this, {
        dateFormat: 'Y-m-d',
        locale: locale,
        disableMobile: true,
        altInput: true,
        altFormat: 'd/m/Y',
        allowInput: true,
        minDate: todayYmd
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

  function renderTimelineSlots(date, bookings, blocks) {
    var hours = [];
    for (var m = 8 * 60; m < 20 * 60; m += 60) {
      hours.push({ start: m, end: m + 60, bookingIds: [] });
    }
    var isDayBlocked = false;
    blocks.forEach(function (block) {
      if (date >= String(block.start_date || '') && date <= String(block.end_date || '')) isDayBlocked = true;
    });
    var bookingNames = {};
    bookings.forEach(function (booking) {
      bookingNames[booking.id] = booking.title || ('Reserva #' + booking.id);
      (booking.occurrences || []).forEach(function (occ) {
        if (occ.date !== date) return;
        if (occ.full_day) {
          hours.forEach(function (slot) {
            if (slot.bookingIds.indexOf(booking.id) === -1) slot.bookingIds.push(booking.id);
          });
          return;
        }
        var occStart = timeToMinutes(occ.start || '');
        var occEnd = timeToMinutes(occ.end || '');
        hours.forEach(function (slot) {
          if (occStart < slot.end && occEnd > slot.start && slot.bookingIds.indexOf(booking.id) === -1) {
            slot.bookingIds.push(booking.id);
          }
        });
      });
    });

    var html = '<div class="cie-day-timeline"><h5>Huecos y ocupación del día</h5><div class="cie-day-timeline__table">';
    hours.forEach(function (slot) {
      var hasBooking = slot.bookingIds.length > 0;
      var rowClass = isDayBlocked ? 'is-blocked' : hasBooking ? 'is-busy' : 'is-free';
      html += '<div class="cie-day-timeline__row ' + rowClass + '"><div class="cie-day-timeline__hour">' + minutesToHourLabel(slot.start) + '</div><div class="cie-day-timeline__state">';
      if (isDayBlocked) {
        html += 'Bloqueado por mantenimiento';
      } else if (hasBooking) {
        html += slot.bookingIds.map(function (id) {
          return '<span class="cie-inline-link" data-cie-booking-id="' + id + '" role="button" tabindex="0">' + escapeHtml(bookingNames[id]) + '</span>';
        }).join(', ');
      } else {
        html += 'Disponible';
      }
      html += '</div></div>';
    });
    html += '</div></div>';
    return html;
  }

  function openBookingDetails(bookingId) {
    var id = parseInt(String(bookingId || ''), 10);
    if (!id || !window.CieLabBookingAdmin) return;
    var $modal = ensureModal();
    $modal.find('.cie-modal__content').html('<h4>Detalle de reserva</h4><p>Cargando...</p>');
    $modal.show();
    $.post(window.CieLabBookingAdmin.ajaxUrl, {
      action: 'cie_lab_booking_booking_detail',
      nonce: window.CieLabBookingAdmin.nonce,
      booking_id: id
    }).done(function (response) {
      if (!response || !response.success || !response.data) {
        $modal.find('.cie-modal__content').html('<p>No se pudo cargar el detalle.</p>');
        return;
      }
      var booking = response.data;
      var html = '<h4>' + escapeHtml(booking.title || ('Reserva #' + booking.id)) + '</h4>';
      html += '<p><span class="cie-status-tag cie-status-tag--' + escapeHtml(statusSlug(booking.status)) + '">' + escapeHtml(statusLabel(booking.status)) + '</span></p>';
      html += '<p><strong>Rango:</strong> ' + escapeHtml(booking.start_date || '') + ' - ' + escapeHtml(booking.end_date || '') + '</p>';
      if (booking.time_start && booking.time_end) {
        html += '<p><strong>Horario:</strong> ' + escapeHtml(booking.time_start + ' - ' + booking.time_end) + '</p>';
      }
      if (Array.isArray(booking.resources) && booking.resources.length) {
        html += '<p><strong>Recursos:</strong> ' + escapeHtml(booking.resources.join(', ')) + '</p>';
      }
      if (booking.user && booking.user.displayName) {
        html += '<p><strong>Usuario:</strong> ' + escapeHtml(booking.user.displayName) + ' (' + escapeHtml(booking.user.email || '') + ')</p>';
      }
      if (booking.project && booking.project.name) {
        html += '<h5>Proyecto</h5><p><strong>Nombre:</strong> ' + escapeHtml(booking.project.name) + '</p>';
        html += '<p><strong>Responsable:</strong> ' + escapeHtml(booking.project.responsible || '') + '</p>';
      }
      if (Array.isArray(booking.occurrences) && booking.occurrences.length) {
        html += '<h5>Ocurrencias</h5><ul>';
        booking.occurrences.slice(0, 40).forEach(function (occ) {
          var line = occ.date + (occ.full_day ? ' (día completo)' : ' (' + occ.start + ' - ' + occ.end + ')');
          html += '<li>' + escapeHtml(line) + '</li>';
        });
        html += '</ul>';
      }
      $modal.find('.cie-modal__content').html(html);
    }).fail(function () {
      $modal.find('.cie-modal__content').html('<p>No se pudo cargar el detalle.</p>');
    });
  }

  function openDayDetails(date, scope, filters) {
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
      var bookings = Array.isArray(data.bookings) ? data.bookings : [];
      var blocks = Array.isArray(data.blocks) ? data.blocks : [];
      var resourceFilter = (filters && filters.resourceName) ? String(filters.resourceName) : 'all';
      var typeFilter = (filters && filters.resourceType) ? String(filters.resourceType) : 'all';
      if (typeFilter !== 'all') {
        bookings = bookings.filter(function (booking) {
          return String(booking.resourceType || 'space') === typeFilter;
        });
      }
      if (resourceFilter !== 'all') {
        bookings = bookings.filter(function (booking) {
          var resources = [].concat(booking.spaces || [], booking.equipment || []);
          return resources.indexOf(resourceFilter) !== -1;
        });
        blocks = blocks.filter(function (block) {
          if (block.isGlobal) return true;
          if (!Array.isArray(block.resources)) return false;
          return block.resources.indexOf(resourceFilter) !== -1;
        });
      }
      var html = '<h4>Detalle de ' + escapeHtml(formatLongDate(date)) + '</h4>';
      html += renderTimelineSlots(date, bookings, blocks);
      html += '<h5>Reservas del día</h5>';
      if (!bookings.length) {
        html += '<p><em>Sin reservas.</em></p>';
      } else {
        html += '<div class="cie-cal-booking-list">';
        bookings.forEach(function (booking) {
          var resources = [].concat(booking.spaces || [], booking.equipment || []);
          html += '<article class="cie-cal-booking-card">';
          html += '<div class="cie-cal-booking-card__badges"><span class="cie-status-tag cie-status-tag--' + escapeHtml(statusSlug(booking.status)) + '">' + escapeHtml(statusLabel(booking.status)) + '</span></div>';
          html += '<div class="cie-cal-booking-card__resource">' + escapeHtml(booking.title || resources[0] || ('Reserva #' + booking.id)) + '</div>';
          html += '<div class="cie-cal-muted">' + escapeHtml(resources.join(', ')) + '</div>';
          html += '<div class="cie-cal-booking-card__actions"><a href="#" data-cie-booking-id="' + booking.id + '">Ver detalle</a></div>';
          html += '</article>';
        });
        html += '</div>';
      }
      if (blocks.length) {
        html += '<h5>Mantenimiento</h5><div class="cie-cal-block-list">';
        blocks.forEach(function (block) {
          var resources = [];
          if (block.isGlobal) resources.push('Todos los recursos');
          if (Array.isArray(block.resources)) resources = resources.concat(block.resources);
          html += '<article class="cie-cal-block-card"><div><strong>Mantenimiento</strong></div>';
          html += '<div class="cie-cal-muted">' + escapeHtml(block.start_date + ' - ' + block.end_date) + '</div>';
          html += '<div class="cie-cal-muted">' + escapeHtml(resources.join(', ')) + '</div></article>';
        });
        html += '</div>';
      }
      $modal.find('.cie-modal__content').html(html);
    }).fail(function () {
      $modal.find('.cie-modal__content').html('<p>No se pudo cargar el detalle.</p>');
    });
  }

  function renderEventChip(event, withTime) {
    var classes = [
      'cie-scheduler__event-chip',
      event.type === 'block' ? 'is-block' : 'is-booking',
      resourceTypeClass(event.resourceType || '')
    ];
    if (event.type === 'booking' && event.statusSlug) {
      classes.push('is-' + String(event.statusSlug));
    }
    if (event.isPast) {
      classes.push('is-past');
    }
    classes = classes.join(' ');
    var label = (withTime && event.start ? event.start + ' ' : '') + (event.title || 'Reserva');
    var attrs = '';
    if (event.type === 'booking' && event.bookingId) attrs = ' data-cie-booking-id="' + event.bookingId + '" role="button" tabindex="0"';
    return '<span class="' + classes + '"' + attrs + '>' + escapeHtml(label) + '</span>';
  }

  function renderScheduler($container) {
    if (!$container.length || !window.CieLabBookingAdmin) return;
    var scope = String($container.attr('data-cie-calendar-scope') || 'general');
    var defaultView = String($container.attr('data-cie-default-view') || 'month');
    var state = {
      view: (defaultView === 'week' || defaultView === 'day') ? defaultView : 'month',
      current: new Date(),
      events: [],
      filterType: 'all',
      filterResource: 'all'
    };

    function rangeForView() {
      if (state.view === 'month') return { start: toYmd(monthStart(state.current)), end: toYmd(monthEnd(state.current)) };
      if (state.view === 'week') {
        var start = startOfWeek(state.current);
        return { start: toYmd(start), end: toYmd(addDays(start, 6)) };
      }
      return { start: toYmd(state.current), end: toYmd(state.current) };
    }

    function move(delta) {
      if (state.view === 'month') state.current = new Date(state.current.getFullYear(), state.current.getMonth() + delta, 1);
      else if (state.view === 'week') state.current = addDays(state.current, delta * 7);
      else state.current = addDays(state.current, delta);
      load();
    }

    function eventsByDate() {
      var map = {};
      filteredEvents().forEach(function (event) {
        if (!map[event.date]) map[event.date] = [];
        map[event.date].push(event);
      });
      return map;
    }

    function resourceOptions() {
      var seen = {};
      state.events.forEach(function (event) {
        (Array.isArray(event.resources) ? event.resources : []).forEach(function (name) {
          var key = String(name || '').trim();
          if (!key) return;
          seen[key] = key;
        });
      });
      return Object.keys(seen).sort();
    }

    function filteredEvents() {
      return state.events.filter(function (event) {
        if (state.filterType !== 'all' && event.type === 'booking' && String(event.resourceType || 'space') !== state.filterType) {
          return false;
        }
        if (state.filterResource !== 'all') {
          var names = Array.isArray(event.resources) ? event.resources : [];
          if (event.type === 'block' && event.isGlobal) return true;
          if (names.indexOf(state.filterResource) === -1) return false;
        }
        return true;
      });
    }

    function renderToolbar() {
      var options = resourceOptions();
      return (
        '<div class="cie-scheduler__toolbar">' +
          '<div class="cie-scheduler__nav">' +
            '<button type="button" data-cie-nav="-1">&larr;</button>' +
            '<button type="button" data-cie-today="1">Hoy</button>' +
            '<button type="button" data-cie-nav="1">&rarr;</button>' +
          '</div>' +
          '<div class="cie-scheduler__views">' +
            '<button type="button" data-cie-view="month"' + (state.view === 'month' ? ' class="is-active"' : '') + '>Mes</button>' +
            '<button type="button" data-cie-view="week"' + (state.view === 'week' ? ' class="is-active"' : '') + '>Semana</button>' +
            '<button type="button" data-cie-view="day"' + (state.view === 'day' ? ' class="is-active"' : '') + '>Día</button>' +
          '</div>' +
          '<div class="cie-scheduler__filters">' +
            '<select data-cie-filter-type>' +
              '<option value="all"' + (state.filterType === 'all' ? ' selected' : '') + '>Todos</option>' +
              '<option value="combined"' + (state.filterType === 'combined' ? ' selected' : '') + '>Combinada</option>' +
              '<option value="equipment"' + (state.filterType === 'equipment' ? ' selected' : '') + '>Solo equipo</option>' +
              '<option value="space"' + (state.filterType === 'space' ? ' selected' : '') + '>Solo espacio</option>' +
            '</select>' +
            '<select data-cie-filter-resource>' +
              '<option value="all"' + (state.filterResource === 'all' ? ' selected' : '') + '>Todos los recursos</option>' +
              options.map(function (name) {
                return '<option value="' + escapeHtml(name) + '"' + (state.filterResource === name ? ' selected' : '') + '>' + escapeHtml(name) + '</option>';
              }).join('') +
            '</select>' +
          '</div>' +
        '</div>'
      );
    }

    function renderMonth() {
      var first = monthStart(state.current);
      var gridStart = startOfWeek(first);
      var byDate = eventsByDate();
      var todayYmd = toYmd(new Date());
      var html = '<div class="cie-scheduler__month"><div class="cie-scheduler__week-header"><span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span></div><div class="cie-scheduler__month-grid">';
      for (var i = 0; i < 42; i++) {
        var day = addDays(gridStart, i);
        var ymd = toYmd(day);
        var events = byDate[ymd] || [];
        var dayClasses = ['cie-scheduler__day'];
        if (day.getMonth() !== first.getMonth()) dayClasses.push('is-muted');
        if (ymd === todayYmd) dayClasses.push('is-today');
        html += '<div class="' + dayClasses.join(' ') + '" data-cie-open-day="' + ymd + '"><span class="cie-scheduler__day-number">' + day.getDate() + '</span>';
        events.slice(0, 2).forEach(function (event) {
          html += renderEventChip(event, false);
        });
        html += '</div>';
      }
      html += '</div></div>';
      return html;
    }

    function renderWeekOrDay() {
      var start = state.view === 'week' ? startOfWeek(state.current) : new Date(state.current.getTime());
      var days = state.view === 'week' ? 7 : 1;
      var rowStyle = ' style="grid-template-columns:76px repeat(' + days + ', minmax(0, 1fr));"';
      var byDate = eventsByDate();
      var now = new Date();
      var todayYmd = toYmd(now);
      var nowMinutes = (now.getHours() * 60) + now.getMinutes();
      var html = '<div class="cie-scheduler__time-grid"><div class="cie-scheduler__time-header"' + rowStyle + '><span></span>';
      for (var d = 0; d < days; d++) {
        var day = addDays(start, d);
        var ymd = toYmd(day);
        var dayBtnClasses = ['cie-scheduler__time-day'];
        if (ymd === todayYmd) dayBtnClasses.push('is-today');
        html += '<button type="button" class="' + dayBtnClasses.join(' ') + '" data-cie-open-day="' + ymd + '">' + escapeHtml(day.toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short' })) + '</button>';
      }
      html += '</div>';
      html += '<div class="cie-scheduler__all-day-row"' + rowStyle + '><div class="cie-scheduler__time-label">Todo el día</div>';
      for (var a = 0; a < days; a++) {
        var allDay = toYmd(addDays(start, a));
        var allDayEvents = (byDate[allDay] || []).filter(function (event) { return !!event.fullDay; });
        var allDayClasses = ['cie-scheduler__time-cell'];
        if (allDay === todayYmd) allDayClasses.push('is-today');
        html += '<div class="' + allDayClasses.join(' ') + '" data-cie-open-day="' + allDay + '">';
        allDayEvents.forEach(function (event) { html += renderEventChip(event, false); });
        html += '</div>';
      }
      html += '</div>';
      for (var hour = 8; hour < 20; hour++) {
        var rowStart = hour * 60;
        var rowEnd = (hour + 1) * 60;
        html += '<div class="cie-scheduler__time-row"' + rowStyle + '><div class="cie-scheduler__time-label">' + String(hour).padStart(2, '0') + ':00</div>';
        for (var c = 0; c < days; c++) {
          var date = toYmd(addDays(start, c));
          var cellEvents = (byDate[date] || []).filter(function (event) {
            if (event.fullDay || event.type === 'block') return false;
            var evStart = timeToMinutes(event.start || '');
            return evStart >= rowStart && evStart < rowEnd;
          });
          var cellClasses = ['cie-scheduler__time-cell'];
          if (date === todayYmd) {
            cellClasses.push('is-today');
            if (nowMinutes >= rowStart && nowMinutes < rowEnd) {
              cellClasses.push('is-current-time');
            }
          }
          html += '<div class="' + cellClasses.join(' ') + '" data-cie-open-day="' + date + '">';
          cellEvents.forEach(function (event) { html += renderEventChip(event, true); });
          html += '</div>';
        }
        html += '</div>';
      }
      html += '</div>';
      return html;
    }

    function render() {
      $container.html(renderToolbar() + (state.view === 'month' ? renderMonth() : renderWeekOrDay()));
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
    $container.on('change', '[data-cie-filter-type]', function () {
      state.filterType = String($(this).val() || 'all');
      render();
    });
    $container.on('change', '[data-cie-filter-resource]', function () {
      state.filterResource = String($(this).val() || 'all');
      render();
    });
    $container.on('click', '[data-cie-open-day]', function (event) {
      if ($(event.target).closest('[data-cie-booking-id]').length) return;
      event.preventDefault();
      var date = String($(this).attr('data-cie-open-day') || '');
      if (date) {
        openDayDetails(date, scope, {
          resourceType: state.filterType,
          resourceName: state.filterResource
        });
      }
    });
    $container.on('click keydown', '[data-cie-booking-id]', function (event) {
      if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      event.stopPropagation();
      openBookingDetails($(this).attr('data-cie-booking-id'));
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
    $(document).on('click', '[data-cie-booking-id]', function (event) {
      if ($(event.target).closest('.cie-scheduler').length) return;
      event.preventDefault();
      openBookingDetails($(this).attr('data-cie-booking-id'));
    });
  });
})(jQuery);

