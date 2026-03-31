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
    return (
      date.getFullYear() +
      '-' +
      String(date.getMonth() + 1).padStart(2, '0') +
      '-' +
      String(date.getDate()).padStart(2, '0')
    );
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

  function formatDayMonth(ymd) {
    var d = parseYmd(ymd);
    if (!d) return ymd || '';
    return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'long' });
  }

  function formatWeekdayDayMonth(ymd) {
    var d = parseYmd(ymd);
    if (!d) return ymd || '';
    return d.toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'long' });
  }

  function uniqueDatesFromOccurrences(occurrences) {
    var map = {};
    (Array.isArray(occurrences) ? occurrences : []).forEach(function (occ) {
      var date = String((occ && occ.date) || '');
      if (/^\d{4}-\d{2}-\d{2}$/.test(date)) map[date] = true;
    });
    return Object.keys(map).sort();
  }

  function areConsecutiveDates(dates) {
    if (!dates.length) return false;
    for (var i = 1; i < dates.length; i++) {
      var prev = parseYmd(dates[i - 1]);
      var cur = parseYmd(dates[i]);
      if (!prev || !cur) return false;
      var diff = Math.round((cur.getTime() - prev.getTime()) / 86400000);
      if (diff !== 1) return false;
    }
    return true;
  }

  function describeBookingDates(booking) {
    var dates = uniqueDatesFromOccurrences(booking.occurrences);
    if (!dates.length) {
      var start = String(booking.start_date || '');
      var end = String(booking.end_date || '');
      if (start && end && start !== end) return formatDayMonth(start) + ' - ' + formatDayMonth(end);
      if (start) return formatWeekdayDayMonth(start);
      return '';
    }
    if (dates.length === 1) return formatWeekdayDayMonth(dates[0]);
    if (areConsecutiveDates(dates)) {
      return formatDayMonth(dates[0]) + ' - ' + formatDayMonth(dates[dates.length - 1]);
    }
    return dates.map(formatDayMonth).join(', ');
  }

  function describeBookingDuration(booking) {
    var mode = String(booking.mode || '');
    if (mode === 'full_day') return 'Todo el día';
    var slots = Array.isArray(booking.time_slots) ? booking.time_slots : [];
    if (slots.length === 1 && /^\d{2}:\d{2}-\d{2}:\d{2}$/.test(slots[0])) {
      var p = String(slots[0]).split('-');
      return 'De ' + p[0] + ' a ' + p[1] + ' horas';
    }
    if (booking.time_start && booking.time_end) {
      return 'De ' + booking.time_start + ' a ' + booking.time_end + ' horas';
    }
    if (slots.length > 1) {
      return 'Por horas (' + slots.join(', ') + ')';
    }
    return 'Por horas';
  }

  function weekdayNameForDate(ymd) {
    var d = parseYmd(ymd);
    if (!d) return '';
    return d.toLocaleDateString('es-ES', { weekday: 'long' });
  }

  function describeBookingRepeat(booking) {
    var frequency = String(booking.frequency || 'single');
    var dates = uniqueDatesFromOccurrences(booking.occurrences);
    if (frequency === 'single') return 'Sin repetición';
    if (frequency === 'daily') return 'Cada día';
    if (frequency === 'biweekly_repeat') return 'Semana salteada';
    if (frequency === 'weekly_repeat') {
      if (dates.length === 1) {
        var w = weekdayNameForDate(dates[0]);
        return w ? ('Todos los ' + w) : 'Cada semana';
      }
      var labels = {};
      dates.slice(0, 7).forEach(function (date) {
        var d = parseYmd(date);
        if (!d) return;
        labels[d.getDay()] = d.toLocaleDateString('es-ES', { weekday: 'short' }).replace('.', '');
      });
      var days = Object.keys(labels).sort().map(function (k) { return labels[k]; });
      return days.length ? ('Cada semana ' + days.join(', ')) : 'Cada semana';
    }
    return 'Sin repetición';
  }

  function bookingUntilDate(booking) {
    var dates = uniqueDatesFromOccurrences(booking.occurrences);
    if (dates.length) return dates[dates.length - 1];
    return String(booking.end_date || '');
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

  function currentDateYmd() {
    return toYmd(new Date());
  }

  function currentHourStartMinutes() {
    var now = new Date();
    return now.getHours() * 60;
  }

  function isPastHourlySlot(dateYmd, slotStartHm) {
    if (String(dateYmd || '') !== currentDateYmd()) return false;
    var slotStart = timeToMinutes(slotStartHm);
    if (slotStart < 0) return false;
    return slotStart < currentHourStartMinutes();
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

  function renderTimelineSlots(date, bookings, blocks) {
    var hours = [];
    for (var m = 8 * 60; m < 20 * 60; m += 60) {
      hours.push({
        start: m,
        end: m + 60,
        blocked: false,
        bookingIds: []
      });
    }

    var isDayBlocked = false;
    blocks.forEach(function (block) {
      if (date >= String(block.start_date || '') && date <= String(block.end_date || '')) {
        isDayBlocked = true;
      }
    });

    var bookingNames = {};
    bookings.forEach(function (booking) {
      bookingNames[booking.id] = booking.title || ('Reserva #' + booking.id);
      var occs = Array.isArray(booking.occurrences) ? booking.occurrences : [];
      occs.forEach(function (occ) {
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
          var overlap = occStart < slot.end && occEnd > slot.start;
          if (overlap && slot.bookingIds.indexOf(booking.id) === -1) {
            slot.bookingIds.push(booking.id);
          }
        });
      });
    });

    var html = '<div class="cie-day-timeline">';
    html += '<h5>Huecos y ocupación del día</h5>';
    html += '<div class="cie-day-timeline__table">';
    hours.forEach(function (slot) {
      var isBlocked = isDayBlocked;
      var hasBooking = slot.bookingIds.length > 0;
      var stateClass = isBlocked ? 'is-blocked' : hasBooking ? 'is-busy' : 'is-free';
      html += '<div class="cie-day-timeline__row ' + stateClass + '">';
      html += '<div class="cie-day-timeline__hour">' + minutesToHourLabel(slot.start) + '</div>';
      html += '<div class="cie-day-timeline__state">';
      if (isBlocked) {
        html += 'Bloqueado por mantenimiento';
      } else if (hasBooking) {
        html += slot.bookingIds
          .map(function (id) {
            return (
              '<span class="cie-inline-link" data-cie-booking-id="' +
              id +
              '" role="button" tabindex="0">' +
              escapeHtml(bookingNames[id]) +
              '</span>'
            );
          })
          .join(', ');
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
    if (!id) return;
    var $modal = ensureModal();
    $modal.find('.cie-modal__content').html('<h4>Detalle de reserva</h4><p>Cargando...</p>');
    $modal.show();

    if (!window.CieLabBooking || !window.CieLabBooking.ajaxUrl) {
      $modal.find('.cie-modal__content').html('<p>No se pudo cargar el detalle.</p>');
      return;
    }

    $.post(window.CieLabBooking.ajaxUrl, {
      action: 'cie_lab_booking_booking_detail',
      nonce: window.CieLabBooking.nonce,
      booking_id: id
    }).done(function (response) {
      if (!response || !response.success || !response.data) {
        $modal.find('.cie-modal__content').html('<p>No se pudo cargar el detalle.</p>');
        return;
      }
      var booking = response.data;
      var html = '<h4>' + escapeHtml(booking.title || ('Reserva #' + booking.id)) + '</h4>';
      html += '<p><span class="cie-status-tag cie-status-tag--' + escapeHtml(statusSlug(booking.status)) + '">' + escapeHtml(statusLabel(booking.status)) + '</span></p>';
      html += '<p><strong>Fecha:</strong> ' + escapeHtml(describeBookingDates(booking)) + '</p>';
      html += '<p><strong>Duración:</strong> ' + escapeHtml(describeBookingDuration(booking)) + '</p>';
      html += '<p><strong>Repetición:</strong> ' + escapeHtml(describeBookingRepeat(booking)) + '</p>';
      var until = bookingUntilDate(booking);
      if (until) {
        html += '<p><strong>Hasta:</strong> ' + escapeHtml(formatLongDate(until)) + '</p>';
      }
      if (Array.isArray(booking.resources) && booking.resources.length) {
        html += '<p><strong>Recursos:</strong> ' + escapeHtml(booking.resources.join(', ')) + '</p>';
      }
      if (booking.project && booking.project.name) {
        html += '<h5>Proyecto</h5>';
        html += '<p><strong>Nombre:</strong> ' + escapeHtml(booking.project.name) + '</p>';
        html += '<p><strong>Responsable:</strong> ' + escapeHtml(booking.project.responsible || '') + '</p>';
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
          var resources = [];
          if (Array.isArray(booking.spaces)) resources = resources.concat(booking.spaces);
          if (Array.isArray(booking.equipment)) resources = resources.concat(booking.equipment);
          return resources.indexOf(resourceFilter) !== -1;
        });
        blocks = blocks.filter(function (block) {
          if (block.isGlobal) return true;
          if (!Array.isArray(block.resources)) return false;
          return block.resources.indexOf(resourceFilter) !== -1;
        });
      }
      bookings.sort(function (a, b) {
        var aOcc = Array.isArray(a.occurrences) ? a.occurrences : [];
        var bOcc = Array.isArray(b.occurrences) ? b.occurrences : [];
        var aFirst = aOcc.length ? aOcc[0] : null;
        var bFirst = bOcc.length ? bOcc[0] : null;
        var aDate = String((aFirst && aFirst.date) || a.start_date || date || '');
        var bDate = String((bFirst && bFirst.date) || b.start_date || date || '');
        if (aDate !== bDate) return aDate.localeCompare(bDate);
        var aStart = String((aFirst && aFirst.start) || '');
        var bStart = String((bFirst && bFirst.start) || '');
        var aMin = aStart ? timeToMinutes(aStart) : 0;
        var bMin = bStart ? timeToMinutes(bStart) : 0;
        if (aMin !== bMin) return aMin - bMin;
        return String(a.title || '').localeCompare(String(b.title || ''));
      });
      blocks.sort(function (a, b) {
        return String(a.start_date || '').localeCompare(String(b.start_date || ''));
      });
      var html = '<h4>Detalle de ' + escapeHtml(formatLongDate(date)) + '</h4>';
      html += renderTimelineSlots(date, bookings, blocks);

      if (blocks.length) {
        html += '<h5>Mantenimiento</h5><div class="cie-cal-block-list">';
        blocks.forEach(function (block) {
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

      html += '<h5>Reservas del día</h5>';
      if (!bookings.length) {
        html += '<p><em>No hay reservas.</em></p>';
      } else {
        html += '<div class="cie-cal-booking-list">';
        bookings.forEach(function (booking) {
          var resources = [];
          if (Array.isArray(booking.spaces)) resources = resources.concat(booking.spaces);
          if (Array.isArray(booking.equipment)) resources = resources.concat(booking.equipment);
          html += '<article class="cie-cal-booking-card">';
          html += '<div class="cie-cal-booking-card__badges"><span class="cie-status-tag cie-status-tag--' + escapeHtml(statusSlug(booking.status)) + '">' + escapeHtml(statusLabel(booking.status)) + '</span></div>';
          html += '<div class="cie-cal-booking-card__resource">' + escapeHtml(booking.title || resources[0] || ('Reserva #' + booking.id)) + '</div>';
          html += '<div class="cie-cal-muted">' + escapeHtml(resources.join(', ')) + '</div>';
          html += '<div class="cie-cal-booking-card__actions"><a href="#" data-cie-booking-id="' + booking.id + '">Ver detalle</a></div>';
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
        minDate: 'today',
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
        minDate: 'today',
        locale: locale,
        disableMobile: true,
        altInput: true,
        altFormat: 'd/m/Y'
      });
    });

    var formAvailabilityState = {
      currentMonth: monthStart(new Date()),
      cache: {}
    };
    var maxWeeks = parseInt(String($flow.attr('data-cie-max-weeks') || '5'), 10);
    if (!maxWeeks || maxWeeks < 1) maxWeeks = 5;
    var maxRangeDays = parseInt(String($flow.attr('data-cie-max-range-days') || '5'), 10);
    if (!maxRangeDays || maxRangeDays < 1) maxRangeDays = 5;

    function selectedMode() {
      return String($flow.find('[name="booking_mode"]').val() || 'full_day');
    }

    function selectedFrequency() {
      return String($flow.find('[name="booking_frequency"]').val() || 'single');
    }

    function selectedDayScope() {
      return String($flow.find('input[name="booking_day_scope"]:checked').val() || 'single_day');
    }

    function selectedInstallationType() {
      return String($flow.find('input[name="booking_installation_type"]:checked').val() || 'combined');
    }

    function syncFrequencyOptions() {
      var dayScope = selectedDayScope();
      var scopeKey = dayScope === 'single_day' ? 'single_day' : 'all';
      var $select = $flow.find('select[name="booking_frequency"]');
      if (!$select.length) return;
      $select.find('option').each(function () {
        var allowed = String($(this).attr('data-cie-frequency-scope') || 'all');
        var visible = allowed === 'all' || allowed === scopeKey;
        $(this).prop('disabled', !visible).toggle(visible);
      });
      var current = String($select.val() || 'single');
      var $current = $select.find('option[value="' + current + '"]');
      if (!$current.length || $current.prop('disabled')) {
        $select.val('single');
      }
      $flow.find('input[name="booking_recurrence_weeks"]').attr('max', String(maxWeeks));
    }

    function selectedPrimaryDate() {
      var dayScope = selectedDayScope();
      var start = String($flow.find('input[name="start_date"]').val() || '').trim();
      if (dayScope === 'loose_days') {
        var raw = String($flow.find('input[name="booking_dates_raw"]').val() || '').trim();
        if (!raw) return '';
        return raw.split(/[,\s;]+/).filter(Boolean)[0] || '';
      }
      return start;
    }

    function selectedTimeSlots() {
      var slots = [];
      $flow.find('input[name="booking_time_slots[]"]:checked').each(function () {
        slots.push(String($(this).val() || '').trim());
      });
      return slots.filter(Boolean);
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

    function selectedResourceNames() {
      var names = [];
      $flow.find('input[name="spaces[]"]:checked,input[name="equipment[]"]:checked').each(function () {
        var text = String($(this).attr('data-cie-equipment-name') || $(this).attr('data-cie-space-name') || '').trim();
        if (!text) {
          text = String($(this).closest('label').clone().children().remove().end().text() || '').replace(/\s+/g, ' ').trim();
        }
        if (text) names.push(text);
      });
      return names;
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
        parseRequires($(this)).forEach(function (reqId) {
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

    function syncInstallationHiddenInputs() {
      var type = selectedInstallationType();
      var useSpace = type === 'space' || type === 'combined';
      var useEquipment = type === 'equipment' || type === 'combined';
      var $form = $flow.closest('form');
      $form.find('[data-cie-hidden-use-space="1"]').val(useSpace ? '1' : '');
      $form.find('[data-cie-hidden-use-equipment="1"]').val(useEquipment ? '1' : '');

      $flow.find('.cie-installation-card').removeClass('is-active');
      $flow.find('.cie-installation-card[data-cie-installation-card="' + type + '"]').addClass('is-active');
      return { useSpace: useSpace, useEquipment: useEquipment };
    }

    function syncLinkedScheduler() {
      var $scheduler = $flow.closest('form').find('.cie-scheduler[data-cie-form-linked-scheduler="1"]').first();
      if (!$scheduler.length) return;
      var api = $scheduler.data('cieSchedulerApi');
      if (!api) return;
      var installType = selectedInstallationType();
      var resourceType = installType === 'combined' ? 'combined' : (installType === 'equipment' ? 'equipment' : 'space');
      var names = selectedResourceNames();
      api.setFilters({
        resourceType: names.length ? resourceType : 'all',
        resourceNames: names
      });
      var startDate = String($flow.find('input[name="start_date"]').val() || '').trim();
      if (/^\d{4}-\d{2}-\d{2}$/.test(startDate)) {
        api.setFocusDate(startDate);
      }
    }

    function updateScheduleNotice() {
      var mode = selectedMode();
      var frequency = selectedFrequency();
      var dayScope = selectedDayScope();
      var start = String($flow.find('input[name="start_date"]').val() || '').trim();
      var end = String($flow.find('input[name="end_date"]').val() || '').trim();
      var weeks = parseInt(String($flow.find('input[name="booking_recurrence_weeks"]').val() || '1'), 10);
      var manual = String($flow.find('input[name="booking_dates_raw"]').val() || '').trim();
      var slots = selectedTimeSlots();
      var summary = '';

      if (frequency === 'single') {
        if (mode === 'full_day') {
          if (dayScope === 'date_range' && end) {
            summary = 'Reserva de día completo del ' + formatLongDate(start) + ' al ' + formatLongDate(end) + '.';
          } else if (dayScope === 'loose_days') {
            summary = 'Reserva de día completo para días sueltos' + (manual ? ' (' + manual + ').' : '.');
          } else {
            summary = 'Reserva de día completo para ' + formatLongDate(start) + '.';
          }
        } else {
          summary = dayScope === 'loose_days'
            ? 'Reserva por horas en días sueltos (' + (slots.length || 0) + ' bloques seleccionados).'
            : 'Reserva por horas para ' + formatLongDate(start) + ' (' + (slots.length || 0) + ' bloques seleccionados).';
        }
      } else if (frequency === 'daily') {
        summary = (mode === 'full_day' ? 'Reserva diaria de día completo' : 'Reserva diaria por horas') + ' durante ' + (weeks || 1) + ' semanas.';
      } else if (frequency === 'weekly_repeat') {
        summary = (mode === 'full_day' ? 'Reserva semanal de día completo' : 'Reserva semanal por horas') + ' durante ' + (weeks || 1) + ' semanas.';
      } else if (frequency === 'biweekly_repeat') {
        summary = (mode === 'full_day' ? 'Reserva en semanas salteadas de día completo' : 'Reserva en semanas salteadas por horas') + ' durante ' + (weeks || 1) + ' semanas.';
      } else {
        summary =
          (mode === 'full_day' ? 'Reserva de días sueltos' : 'Reserva por horas en días sueltos') +
          (manual ? ' (' + manual + ').' : '.');
      }
      $flow.find('[data-cie-notice="schedule"]').text(summary).show();
    }

    var slotsXhr = null;
    function baseHourSlots() {
      var slots = [];
      for (var m = 8 * 60; m < 20 * 60; m += 60) {
        var start = String(Math.floor(m / 60)).padStart(2, '0') + ':00';
        var endMinutes = m + 60;
        var end = String(Math.floor(endMinutes / 60)).padStart(2, '0') + ':00';
        slots.push({
          start: start,
          end: end,
          available: false
        });
      }
      return slots;
    }

    function renderSlotSelector(date, slots, extraMessage) {
      var $box = $flow.find('[data-cie-slot-availability]');
      var $selector = $flow.find('[data-cie-slot-selector]');
      var selected = {};
      selectedTimeSlots().forEach(function (slot) {
        selected[slot] = true;
      });
      var html = '<strong>Bloques para ' + escapeHtml(formatLongDate(date)) + '</strong><div class="cie-slot-grid">';
      var selectorHtml = '';
      slots.forEach(function (slot) {
        var slotKey = String(slot.start + '-' + slot.end);
        var elapsed = !!slot.elapsed || isPastHourlySlot(date, String(slot.start || ''));
        var enabled = !!slot.available && !elapsed;
        var selectedAttr = enabled && selected[slotKey] ? ' checked' : '';
        var disabledAttr = enabled ? '' : ' disabled';
        var selectedClass = enabled && selected[slotKey] ? ' is-selected' : '';
        var stateClass = enabled ? 'is-available' : 'is-unavailable';
        html += '<span class="cie-slot-chip ' + stateClass + selectedClass + '">' + escapeHtml(slot.start + ' - ' + slot.end) + '</span>';
        selectorHtml += '<label class="cie-slot-chip ' + stateClass + selectedClass + '">';
        selectorHtml += '<input type="checkbox" name="booking_time_slots[]" value="' + escapeHtml(slotKey) + '"' + selectedAttr + disabledAttr + ' />';
        selectorHtml += escapeHtml(slot.start + ' - ' + slot.end) + '</label>';
      });
      html += '</div>';
      if (extraMessage) {
        html += '<div class="cie-cal-muted" style="margin-top:6px;">' + escapeHtml(extraMessage) + '</div>';
      }
      $box.html(html).show();
      $selector.html(selectorHtml).show();
    }

    function updateSlotsAvailability() {
      var mode = selectedMode();
      var resources = selectedResources();
      var date = selectedPrimaryDate();
      var $box = $flow.find('[data-cie-slot-availability]');
      var $selector = $flow.find('[data-cie-slot-selector]');
      if (mode !== 'time_range' || !date) {
        $box.hide().empty();
        $selector.hide();
        return;
      }
      if (!resources.spaces.length && !resources.equipment.length) {
        renderSlotSelector(date, baseHourSlots(), 'Seleccione al menos un espacio o equipo para activar los slots disponibles.');
        updateScheduleNotice();
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
        var selected = {};
        selectedTimeSlots().forEach(function (slot) {
          selected[slot] = true;
        });
        renderSlotSelector(date, response.data.slots);
        updateScheduleNotice();
        updateContinueState();
        updateFormAvailability();
      }).fail(function () {
        $box.html('<div class="cie-cal-muted">No se pudo obtener disponibilidad horaria.</div>').show();
        $selector.hide();
      });
    }

    function renderFormAvailability(daysMap) {
      var $container = $flow.find('[data-cie-form-availability="1"]');
      if (!$container.length) return;
      var first = monthStart(formAvailabilityState.currentMonth);
      var last = monthEnd(first);
      var gridStart = startOfWeek(first);
      var monthLabel = first.toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
      var html = '<div class="cie-form-availability__toolbar">';
      html += '<button type="button" class="cie-btn" data-cie-form-av-nav="-1">&larr;</button>';
      html += '<strong>' + escapeHtml(monthLabel) + '</strong>';
      html += '<button type="button" class="cie-btn" data-cie-form-av-nav="1">&rarr;</button>';
      html += '</div>';
      html += '<div class="cie-form-availability__legend">';
      html += '<span class="is-available">Disponible</span><span class="is-busy">Ocupado</span><span class="is-blocked">Bloqueado</span>';
      html += '</div>';
      html += '<div class="cie-form-availability__week-header"><span>L</span><span>M</span><span>X</span><span>J</span><span>V</span><span>S</span><span>D</span></div>';
      html += '<div class="cie-form-availability__grid">';
      for (var i = 0; i < 42; i++) {
        var day = addDays(gridStart, i);
        var ymd = toYmd(day);
        var state = daysMap[ymd] ? String(daysMap[ymd].status || 'available') : 'available';
        var classes = 'cie-form-availability__day is-' + state + (day.getMonth() === first.getMonth() ? '' : ' is-muted');
        html += '<button type="button" class="' + classes + '" data-cie-form-day="' + ymd + '">' + day.getDate() + '</button>';
      }
      html += '</div>';
      html += '<small>Click en un día para ver sus huecos y reservas.</small>';
      $container.html(html);
    }

    var formAvailabilityXhr = null;
    function updateFormAvailability() {
      var resources = selectedResources();
      var mode = selectedMode();
      var timeStart = String($flow.find('input[name="booking_time_start"]').val() || '').trim();
      var timeEnd = String($flow.find('input[name="booking_time_end"]').val() || '').trim();
      var timeSlots = selectedTimeSlots();
      var $container = $flow.find('[data-cie-form-availability="1"]');
      if (!resources.spaces.length && !resources.equipment.length) {
        $container.html('<p class="cie-cal-muted">Seleccione al menos un espacio o equipo para ver disponibilidad.</p>');
        return;
      }
      if (!window.CieLabBooking || !window.CieLabBooking.ajaxUrl) return;
      var first = monthStart(formAvailabilityState.currentMonth);
      var last = monthEnd(first);
      var cacheKey = [
        toYmd(first),
        toYmd(last),
        mode,
        timeStart,
        timeEnd,
        timeSlots.join('-'),
        resources.spaces.join('-'),
        resources.equipment.join('-')
      ].join('|');
      if (formAvailabilityState.cache[cacheKey]) {
        renderFormAvailability(formAvailabilityState.cache[cacheKey]);
        return;
      }
      if (formAvailabilityXhr && formAvailabilityXhr.abort) formAvailabilityXhr.abort();
      $container.html('<p class="cie-cal-muted">Cargando disponibilidad...</p>');
      formAvailabilityXhr = $.post(window.CieLabBooking.ajaxUrl, {
        action: 'cie_lab_booking_resource_availability_calendar',
        nonce: window.CieLabBooking.nonce,
        start_date: toYmd(first),
        end_date: toYmd(last),
        spaces: resources.spaces,
        equipment: resources.equipment,
        booking_mode: mode,
        booking_time_start: timeStart,
        booking_time_end: timeEnd,
        booking_time_slots: timeSlots
      }).done(function (response) {
        if (!response || !response.success || !response.data || !response.data.days) {
          $container.html('<p class="cie-cal-muted">No se pudo cargar la disponibilidad.</p>');
          return;
        }
        formAvailabilityState.cache[cacheKey] = response.data.days;
        renderFormAvailability(response.data.days);
      }).fail(function () {
        $container.html('<p class="cie-cal-muted">No se pudo cargar la disponibilidad.</p>');
      });
    }

    function updateContinueState() {}

    function setDetailsVisible(show) {
      $flow.find('[data-cie-phase="details"]').show();
    }

    function updateVisibility() {
      var mode = selectedMode();
      var frequency = selectedFrequency();
      var dayScope = selectedDayScope();
      var install = syncInstallationHiddenInputs();
      syncFrequencyOptions();

      $flow.find('[data-cie-only-mode]').each(function () {
        $(this).toggle(String($(this).attr('data-cie-only-mode')) === mode);
      });
      $flow.find('[data-cie-only-frequency]').each(function () {
        $(this).toggle(String($(this).attr('data-cie-only-frequency')) === frequency);
      });
      $flow.find('[data-cie-only-day-scope]').each(function () {
        $(this).toggle(String($(this).attr('data-cie-only-day-scope')) === dayScope);
      });

      $flow.find('[data-cie-resource-section="spaces"]').toggle(install.useSpace);
      $flow.find('[data-cie-resource-section="equipment"]').toggle(install.useEquipment);

      if (!install.useSpace) {
        $flow.find('input[name="spaces[]"]').prop('checked', false);
      }
      if (!install.useEquipment) {
        $flow.find('input[name="equipment[]"]').prop('checked', false);
      } else {
        applyEquipmentDependencies();
      }
      if (mode !== 'time_range') {
        $flow.find('input[name="booking_time_slots[]"]').prop('checked', false);
      }
      var weeks = parseInt(String($flow.find('input[name="booking_recurrence_weeks"]').val() || '1'), 10);
      if (!weeks || weeks < 1) weeks = 1;
      if (weeks > maxWeeks) {
        weeks = maxWeeks;
        $flow.find('input[name="booking_recurrence_weeks"]').val(String(maxWeeks));
      }
      if (dayScope === 'date_range') {
        var start = parseYmd(String($flow.find('input[name="start_date"]').val() || ''));
        var end = parseYmd(String($flow.find('input[name="end_date"]').val() || ''));
        if (start && end) {
          var diff = Math.round((end.getTime() - start.getTime()) / 86400000) + 1;
          if (diff > maxRangeDays) {
            var cap = addDays(start, maxRangeDays - 1);
            $flow.find('input[name="end_date"]').val(toYmd(cap));
          }
        }
      }

      updateScheduleNotice();
      updateSlotsAvailability();
      updateFormAvailability();
      updateContinueState();
      syncLinkedScheduler();
    }

    $flow.on('change input', 'input,select,textarea', function (event) {
      if ($(event.target).is('input[name="booking_time_slots[]"]')) return;
      updateVisibility();
    });
    $flow.on('click', 'input[name="equipment[]"][data-cie-locked="1"]', function (event) {
      event.preventDefault();
      $(this).prop('checked', true);
    });
    $flow.on('click', '[data-cie-form-av-nav]', function (event) {
      event.preventDefault();
      var delta = parseInt(String($(this).attr('data-cie-form-av-nav')), 10);
      if (!delta) return;
      formAvailabilityState.currentMonth = new Date(
        formAvailabilityState.currentMonth.getFullYear(),
        formAvailabilityState.currentMonth.getMonth() + delta,
        1
      );
      updateFormAvailability();
    });
    $flow.on('click', '[data-cie-form-day]', function () {
      var date = String($(this).attr('data-cie-form-day') || '');
      if (date) openDayDetails(date, 'general');
    });
    $flow.on('click', '[data-cie-continue]', function (event) {
      event.preventDefault();
      setDetailsVisible(true);
      var $details = $flow.find('[data-cie-phase="details"]');
      if ($details.length) {
        $('html, body').animate({ scrollTop: Math.max(0, $details.offset().top - 30) }, 200);
      }
    });
    $flow.on('change', 'input[name="booking_time_slots[]"]', function () {
      var $chip = $(this).closest('.cie-slot-chip');
      $chip.toggleClass('is-selected', $(this).is(':checked'));
      updateScheduleNotice();
    });
    setDetailsVisible(true);
    updateVisibility();
  }

  function renderEventChip(event, withTime) {
    var classes = [
      'cie-scheduler__event-chip',
      event.type === 'block' ? 'is-block' : 'is-booking',
      'is-' + statusSlug(event.status || ''),
      event.isPast ? 'is-past' : '',
      resourceTypeClass(event.resourceType || '')
    ].join(' ');
    var label = (withTime && event.start ? event.start + ' ' : '') + (event.title || 'Reserva');
    var attrs = '';
    if (event.type === 'booking' && event.bookingId) {
      attrs = ' data-cie-booking-id="' + event.bookingId + '" role="button" tabindex="0"';
    }
    return '<span class="' + classes + '"' + attrs + '>' + escapeHtml(label) + '</span>';
  }

  function renderScheduler($container) {
    if (!$container.length) return;
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
      if (state.view === 'month') {
        var first = monthStart(state.current);
        var last = monthEnd(state.current);
        return { start: toYmd(first), end: toYmd(last) };
      }
      if (state.view === 'week') {
        var weekStart = startOfWeek(state.current);
        return { start: toYmd(weekStart), end: toYmd(addDays(weekStart, 6)) };
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

    function capitalize(str) {
     return str.charAt(0).toUpperCase() + str.slice(1);
    }

    function renderToolbar() {
      var label = '';
      var options = resourceOptions();
      if (state.view === 'month') {
        label = state.current.toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
      } else if (state.view === 'week') {
        //var s = startOfWeek(state.current);
        //label = formatLongDate(toYmd(s)) + ' - ' + formatLongDate(toYmd(addDays(s, 6)));
       
          var s = startOfWeek(state.current);
          var e = addDays(s, 6);

          var startMonth = s.toLocaleDateString('es-ES', { month: 'long' });
          var endMonth = e.toLocaleDateString('es-ES', { month: 'long' });

          var startYear = s.getFullYear();
          var endYear = e.getFullYear();

          if (startMonth === endMonth && startYear === endYear) {
            // Misma semana dentro del mismo mes
            label = `${capitalize(startMonth)} ${startYear}`;
          } else if (startYear === endYear) {
            // Mes distinto pero mismo año
            label = `${capitalize(startMonth)} - ${capitalize(endMonth)} ${startYear}`;
          } else {
            // Año distinto (caso raro, pero posible en diciembre/enero)
            label = `${capitalize(startMonth)} ${startYear} - ${capitalize(endMonth)} ${endYear}`;
          }
        

      } else {
        label = formatLongDate(toYmd(state.current));
      }
      return (
        '<div class="cie-scheduler__toolbar">' +
          '<div class="cie-scheduler__nav">' +
            '<button type="button" data-cie-nav="-1">&larr;</button>' +
            '<button type="button" data-cie-today="1">Hoy</button>' +
            '<button type="button" data-cie-nav="1">&rarr;</button>' +
          '</div>' +
          '<div class="cie-scheduler__title">' + escapeHtml(label) + '</div>' +
          '<div class="cie-scheduler__views">' +
            '<select data-cie-view-select>' +
              '<option value="month"' + (state.view === 'month' ? ' selected' : '') + '>Mes</option>' +
              '<option value="week"' + (state.view === 'week' ? ' selected' : '') + '>Semana</option>' +
              '<option value="day"' + (state.view === 'day' ? ' selected' : '') + '>Día</option>' +
            '</select>' +
          '</div>' +
          '<!--<div class="cie-scheduler__filters">' +
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
          '</div> -->' +
        '</div>'
      );
    }

    function renderMonth() {
      var first = monthStart(state.current);
      var gridStart = startOfWeek(first);
      var byDate = eventsByDate();
      var todayYmd = toYmd(new Date());
      var html = '<div class="cie-scheduler__month">';
      html += '<div class="cie-scheduler__week-header"><span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span></div>';
      html += '<div class="cie-scheduler__month-grid">';
      for (var i = 0; i < 42; i++) {
        var day = addDays(gridStart, i);
        var ymd = toYmd(day);
        var events = byDate[ymd] || [];
        var inMonth = day.getMonth() === first.getMonth();
        var dayClasses = ['cie-scheduler__day'];
        if (!inMonth) dayClasses.push('is-muted');
        if (ymd === todayYmd) dayClasses.push('is-today');
        html += '<div class="' + dayClasses.join(' ') + '" data-cie-open-day="' + ymd + '">';
        html += '<span class="cie-scheduler__day-number">' + day.getDate() + '</span>';
        html += '<span class="cie-scheduler__day-events">';
        events.slice(0, 2).forEach(function (event) {
          html += renderEventChip(event, false);
        });
        if (events.length > 2) {
          html += '<span class="cie-scheduler__event-more"><button type="button" class="cie-scheduler__event-more-link" data-cie-open-day-more="' + ymd + '">Ver más…</button></span>';
        }
        html += '</span></div>';
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
      var html = '<div class="cie-scheduler__time-grid">';
      html += '<div class="cie-scheduler__time-header"' + rowStyle + '><span></span>';
      for (var d = 0; d < days; d++) {
        var day = addDays(start, d);
        var ymd = toYmd(day);

        var weekday = day.toLocaleDateString('es-ES', { weekday: 'short' });
        var dayNumber = day.toLocaleDateString('es-ES', { day: 'numeric' });

        // quitar punto y poner en mayúsculas
        weekday = weekday.replace('.', '').toUpperCase();

        var dayHeaderClasses = ['day-header'];
        if (ymd === todayYmd) dayHeaderClasses.push('is-today');
        html += '<div class="' + dayHeaderClasses.join(' ') + '"><div class="weekday">' + escapeHtml(weekday) +'</div><div class="day-number">' + escapeHtml(dayNumber) + '</div></div>';

        //html += '<button type="button" class="cie-scheduler__time-day" data-cie-open-day="' + ymd + '">'+ escapeHtml(day.toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short' })) + '</button>';
      }
      html += '</div>';

      html += '<div class="cie-scheduler__all-day-row"' + rowStyle + '><div class="cie-scheduler__time-label">Todo el día</div>';
      for (var a = 0; a < days; a++) {
        var allDayDate = toYmd(addDays(start, a));
        var fullDayEvents = (byDate[allDayDate] || []).filter(function (event) { return !!event.fullDay; });
        var allDayClasses = ['cie-scheduler__time-cell'];
        if (allDayDate === todayYmd) allDayClasses.push('is-today');
        html += '<div class="' + allDayClasses.join(' ') + '" data-cie-open-day="' + allDayDate + '">';
        fullDayEvents.forEach(function (event) {
          html += renderEventChip(event, false);
        });
        html += '</div>';
      }
      html += '</div>';

      for (var hour = 8; hour < 20; hour++) {
        var rowStart = hour * 60;
        var rowEnd = (hour + 1) * 60;
        html += '<div class="cie-scheduler__time-row"' + rowStyle + '><div class="cie-scheduler__time-label">' + String(hour).padStart(2, '0') + ':00</div>';
        for (var c = 0; c < days; c++) {
          var cellDate = toYmd(addDays(start, c));
          var cellEvents = (byDate[cellDate] || []).filter(function (event) {
            if (event.fullDay || event.type === 'block') return false;
            var evStart = timeToMinutes(event.start || '');
            return evStart >= rowStart && evStart < rowEnd;
          });
          var cellClasses = ['cie-scheduler__time-cell'];
          if (cellDate === todayYmd) {
            cellClasses.push('is-today');
            if (nowMinutes >= rowStart && nowMinutes < rowEnd) {
              cellClasses.push('is-current-time');
            }
          }
          html += '<div class="' + cellClasses.join(' ') + '" data-cie-open-day="' + cellDate + '">';
          cellEvents.forEach(function (event) {
            html += renderEventChip(event, true);
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
    $container.on('change', '[data-cie-view-select]', function () {
      state.view = String($(this).val() || 'month');
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
      event.preventDefault();
      if ($(event.target).closest('[data-cie-open-day-more]').length) return;
      if ($(event.target).closest('[data-cie-booking-id]').length) return;
      var date = String($(this).attr('data-cie-open-day') || '');
      if (date) {
        openDayDetails(date, scope, {
          resourceType: state.filterType,
          resourceName: state.filterResource
        });
      }
    });
    $container.on('click', '[data-cie-open-day-more]', function (event) {
      event.preventDefault();
      event.stopPropagation();
      var date = String($(this).attr('data-cie-open-day-more') || '');
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

    $container.data('cieSchedulerApi', {
      setFilters: function (filters) {
        if (!filters || typeof filters !== 'object') return;
        if (filters.resourceType) {
          state.filterType = String(filters.resourceType);
        }
        if (Array.isArray(filters.resourceNames) && filters.resourceNames.length) {
          state.filterResource = String(filters.resourceNames[0]);
        } else {
          state.filterResource = 'all';
        }
        render();
      },
      setFocusDate: function (ymd) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(ymd || ''))) return;
        var next = parseYmd(String(ymd));
        if (toYmd(state.current) === toYmd(next)) return;
        state.current = next;
        load();
      }
    });

    load();
  }

  $(function () {
    $('.cie-scheduler[data-cie-scheduler="1"]').each(function () {
      renderScheduler($(this));
    });

    $('.cie-lab-booking__flow[data-cie-booking-flow="2"]').each(function () {
      initBookingForm($(this));
    });

    $(document).on('click', '[data-cie-booking-id]', function (event) {
      if ($(event.target).closest('.cie-scheduler').length) return;
      event.preventDefault();
      openBookingDetails($(this).attr('data-cie-booking-id'));
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
