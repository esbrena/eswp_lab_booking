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

  function isYmd(value) {
    return /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));
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

  function datesAreConsecutive(dates) {
    var items = normalizeYmdList(dates);
    if (items.length < 2) return true;
    for (var i = 1; i < items.length; i++) {
      var prev = parseYmd(items[i - 1]);
      var curr = parseYmd(items[i]);
      if (!prev || !curr) return false;
      var expected = addDays(prev, 1);
      if (toYmd(expected) !== toYmd(curr)) return false;
    }
    return true;
  }

  function formatCompactFullDayRange(startYmd, endYmd) {
    var start = parseYmd(startYmd);
    var end = parseYmd(endYmd);
    if (!start || !end || end < start) {
      return formatDayMonth(startYmd) + ' a ' + formatDayMonth(endYmd) + ' · Día completo';
    }
    var sameMonth = start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear();
    if (sameMonth) {
      return (
        String(start.getDate()) +
        ' a ' +
        String(end.getDate()) +
        ' de ' +
        end.toLocaleDateString('es-ES', { month: 'long' }) +
        ' · Día completo'
      );
    }
    return formatDayMonth(startYmd) + ' a ' + formatDayMonth(endYmd) + ' · Día completo';
  }

  function normalizeYmdList(values) {
    var map = {};
    (Array.isArray(values) ? values : []).forEach(function (value) {
      var ymd = String(value || '').trim();
      if (/^\d{4}-\d{2}-\d{2}$/.test(ymd)) map[ymd] = true;
    });
    return Object.keys(map).sort();
  }

  function parseManualDates(raw) {
    return normalizeYmdList(String(raw || '').split(/[\s,;]+/).filter(Boolean));
  }

  function buildDateRange(start, end) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(start || ''))) return [];
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(end || ''))) return [];
    if (end < start) return [];
    var out = [];
    var cursor = parseYmd(start);
    var last = parseYmd(end);
    if (!cursor || !last) return [];
    while (cursor <= last) {
      out.push(toYmd(cursor));
      cursor = addDays(cursor, 1);
    }
    return out;
  }

  function shiftDatesByDays(dates, days) {
    return normalizeYmdList((Array.isArray(dates) ? dates : []).map(function (ymd) {
      var d = parseYmd(ymd);
      return d ? toYmd(addDays(d, days)) : '';
    }));
  }

  function expandDatesForFrequency(baseDates, dayScope, frequency, weeks) {
    var normalized = normalizeYmdList(baseDates);
    var safeWeeks = parseInt(String(weeks || '1'), 10);
    if (!safeWeeks || safeWeeks < 1) safeWeeks = 1;
    if (!normalized.length) return [];
    if (dayScope === 'single_day') {
      var anchor = normalized[0];
      if (frequency === 'daily') {
        var outDaily = [];
        for (var i = 0; i < safeWeeks * 7; i++) {
          var d = parseYmd(anchor);
          if (!d) break;
          outDaily.push(toYmd(addDays(d, i)));
        }
        return normalizeYmdList(outDaily);
      }
      if (frequency === 'weekly_repeat' || frequency === 'biweekly_repeat') {
        var step = frequency === 'biweekly_repeat' ? 14 : 7;
        var outRepeat = [];
        for (var j = 0; j < safeWeeks; j++) {
          outRepeat = outRepeat.concat(shiftDatesByDays([anchor], j * step));
        }
        return normalizeYmdList(outRepeat);
      }
      return normalized;
    }
    if (frequency === 'weekly_repeat' || frequency === 'biweekly_repeat') {
      var delta = frequency === 'biweekly_repeat' ? 14 : 7;
      var out = [];
      for (var k = 0; k < safeWeeks; k++) {
        out = out.concat(shiftDatesByDays(normalized, k * delta));
      }
      return normalizeYmdList(out);
    }
    return normalized;
  }

  function normalizeSlotValue(slot) {
    var value = String(slot || '').trim();
    var match = value.match(/^(\d{2}:\d{2})-(\d{2}:\d{2})$/);
    if (!match) return null;
    return { start: match[1], end: match[2] };
  }

  function buildOccurrencesFromDatesAndSlots(mode, dates, slots) {
    var out = [];
    var normalizedDates = normalizeYmdList(dates);
    if (mode === 'full_day') {
      normalizedDates.forEach(function (date) {
        out.push({ date: date, start: '', end: '', full_day: true });
      });
      return out;
    }
    var normalizedSlots = [];
    (Array.isArray(slots) ? slots : []).forEach(function (slot) {
      var parsed = normalizeSlotValue(slot);
      if (parsed) normalizedSlots.push(parsed);
    });
    normalizedDates.forEach(function (date) {
      normalizedSlots.forEach(function (slot) {
        out.push({ date: date, start: slot.start, end: slot.end, full_day: false });
      });
    });
    return out;
  }

  function summarizeOccurrences(occurrences, maxDays) {
    var grouped = {};
    (Array.isArray(occurrences) ? occurrences : []).forEach(function (occ) {
      var date = String((occ && occ.date) || '').trim();
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return;
      if (!grouped[date]) grouped[date] = { fullDay: false, slots: {} };
      if (!!occ.full_day) {
        grouped[date].fullDay = true;
        return;
      }
      var start = String((occ && occ.start) || '').trim();
      var end = String((occ && occ.end) || '').trim();
      if (/^\d{2}:\d{2}$/.test(start) && /^\d{2}:\d{2}$/.test(end)) {
        grouped[date].slots[start + '-' + end] = start + ' - ' + end;
      }
    });
    var dates = Object.keys(grouped).sort();
    var allFullDay = dates.length > 0 && dates.every(function (date) { return grouped[date].fullDay; });
    if (allFullDay && dates.length > 1 && datesAreConsecutive(dates)) {
      return {
        dates: dates,
        lines: [formatCompactFullDayRange(dates[0], dates[dates.length - 1])]
      };
    }
    var lines = [];
    var limit = parseInt(String(maxDays || '3'), 10);
    if (!limit || limit < 1) limit = 1;
    dates.slice(0, limit).forEach(function (date) {
      var base = formatWeekdayDayMonth(date);
      if (grouped[date].fullDay) {
        lines.push(base + ' · Día completo');
        return;
      }
      var slots = Object.keys(grouped[date].slots).sort().map(function (k) { return grouped[date].slots[k]; });
      lines.push(base + ' · ' + (slots.length ? slots.join(', ') : 'Horario pendiente'));
    });
    if (dates.length > limit) {
      lines.push('y ' + (dates.length - limit) + ' día(s) más');
    }
    return { dates: dates, lines: lines };
  }

  function bookingTypeLabel(type) {
    if (type === 'equipment') return 'Equipos';
    if (type === 'combined') return 'Equipos + Espacios';
    return 'Espacios';
  }

  function bookingTypeBadge(type) {
    var safe = (type === 'equipment' || type === 'combined') ? type : 'space';
    return (
      '<span class="cie-booking-type-badge cie-booking-type-badge--' +
      escapeHtml(safe) +
      '">' +
      escapeHtml(bookingTypeLabel(safe)) +
      '</span>'
    );
  }

  function repeatLineFromFrequency(frequency, dates) {
    var safeDates = normalizeYmdList(dates);
    if (!safeDates.length || frequency === 'single') return '';
    var until = formatLongDate(safeDates[safeDates.length - 1]);
    if (frequency === 'daily') return 'Repetición: Cada día, hasta ' + until;
    if (frequency === 'weekly_repeat') return 'Repetición: Cada semana, hasta ' + until;
    if (frequency === 'biweekly_repeat') return 'Repetición: Semana salteada, hasta ' + until;
    return '';
  }

  function totalLineFromOccurrences(occurrences, mode) {
    var items = Array.isArray(occurrences) ? occurrences : [];
    if (!items.length) return '';
    var dayMap = {};
    var slotCount = 0;
    var totalMinutes = 0;
    items.forEach(function (occ) {
      var date = String((occ && occ.date) || '');
      if (/^\d{4}-\d{2}-\d{2}$/.test(date)) dayMap[date] = true;
      if (!!occ.full_day) return;
      var start = timeToMinutes(String((occ && occ.start) || ''));
      var end = timeToMinutes(String((occ && occ.end) || ''));
      if (start >= 0 && end > start) {
        slotCount++;
        totalMinutes += (end - start);
      }
    });
    var days = Object.keys(dayMap).length;
    if (mode === 'full_day' || slotCount === 0) {
      return 'Total: ' + days + ' día(s) reservados';
    }
    var hours = (totalMinutes / 60);
    var hoursLabel = Number.isInteger(hours) ? String(hours) : String(Math.round(hours * 100) / 100);
    return 'Total: ' + days + ' día(s) · ' + slotCount + ' bloque(s) · ' + hoursLabel + ' h';
  }

  function renderBookingDetailCard(data) {
    var title = String((data && data.title) || '').trim();
    var bookingType = String((data && data.bookingType) || 'space').trim();
    var projectName = String((data && data.projectName) || '').trim();
    var projectResponsible = String((data && data.projectResponsible) || '').trim();
    var lines = Array.isArray(data && data.occurrenceLines) ? data.occurrenceLines : [];
    var repeat = String((data && data.repeatLine) || '').trim();
    var total = String((data && data.totalLine) || '').trim();
    var fallback = String((data && data.fallbackLine) || '').trim();

    var html = '<div class="cie-booking-detail">';
    html += '<div class="cie-booking-detail__title">';
    html += '<span class="cie-booking-detail__title-text">' + escapeHtml(title || 'Reserva') + '</span>';
    html += bookingTypeBadge(bookingType);
    html += '</div>';
    html += '<div class="cie-booking-detail__block">';
    html += '<div class="cie-booking-detail__block-title cie-booking-detail__block-title--clock"></div>';
    if (lines.length) {
      lines.forEach(function (line) {
        html += '<div class="cie-booking-detail__line">' + escapeHtml(line) + '</div>';
      });
    } else if (fallback) {
      html += '<div class="cie-booking-detail__line">' + escapeHtml(fallback) + '</div>';
    }
    if (repeat) html += '<div class="cie-booking-detail__line">' + escapeHtml(repeat) + '</div>';
    if (total) html += '<div class="cie-booking-detail__line cie-booking-detail__line--total">' + escapeHtml(total) + '</div>';
    html += '</div>';
    html += '<div class="cie-booking-detail__block">';
    html += '<div class="cie-booking-detail__block-title cie-booking-detail__block-title--list"></div>';
    html += '<div class="cie-booking-detail__line">Proyecto: ' + escapeHtml(projectName || 'Sin especificar') + '</div>';
    html += '<div class="cie-booking-detail__line">Responsable: ' + escapeHtml(projectResponsible || 'Sin especificar') + '</div>';
    html += '</div>';
    html += '</div>';
    return html;
  }

  function buildDetailDataFromBooking(booking, maxDays) {
    var safeBooking = booking || {};
    var occurrences = Array.isArray(safeBooking.occurrences) ? safeBooking.occurrences : [];
    var summarized = summarizeOccurrences(occurrences, maxDays || 3);
    var title = String(safeBooking.title || '').trim();
    if (!title && Array.isArray(safeBooking.resources) && safeBooking.resources.length) {
      title = safeBooking.resources.join(', ');
    }
    if (!title && safeBooking.id) title = 'Reserva #' + safeBooking.id;
    return {
      title: title || 'Reserva',
      bookingType: String(safeBooking.type || safeBooking.resourceType || 'space'),
      occurrenceLines: summarized.lines,
      repeatLine: repeatLineFromFrequency(String(safeBooking.frequency || 'single'), summarized.dates),
      totalLine: totalLineFromOccurrences(occurrences, String(safeBooking.mode || '')),
      projectName: safeBooking.project && safeBooking.project.name ? safeBooking.project.name : '',
      projectResponsible: safeBooking.project && safeBooking.project.responsible ? safeBooking.project.responsible : '',
      fallbackLine: 'Sin detalle horario'
    };
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
      var html = '<div class="cie-booking-modal-header"><h4>Detalle de reserva</h4><span class="cie-status-tag cie-status-tag--' + escapeHtml(statusSlug(booking.status)) + '">' + escapeHtml(statusLabel(booking.status)) + '</span></div>';
      html += renderBookingDetailCard(buildDetailDataFromBooking(booking, 4));
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
      calendar_scope: scope || 'general',
      filter_resource: (filters && filters.resourceName) ? String(filters.resourceName) : 'all'
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
          if (block.isGlobal) return false;
          if (!Array.isArray(block.resources)) return false;
          return block.resources.indexOf(resourceFilter) !== -1;
        });
      } else {
        blocks = blocks.filter(function (block) {
          return !!block.isGlobal;
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
          html += renderBookingDetailCard(buildDetailDataFromBooking(booking, 2));
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

    var formAvailabilityState = {
      currentMonth: monthStart(new Date()),
      cache: {}
    };
    var maxWeeks = parseInt(String($flow.attr('data-cie-max-weeks') || '5'), 10);
    if (!maxWeeks || maxWeeks < 1) maxWeeks = 5;
    var maxRangeDays = parseInt(String($flow.attr('data-cie-max-range-days') || '5'), 10);
    if (!maxRangeDays || maxRangeDays < 1) maxRangeDays = 5;

    function setDateInputValue($input, value) {
      var nextValue = String(value || '').trim();
      if (!$input || !$input.length || !isYmd(nextValue)) return;
      if (String($input.val() || '').trim() === nextValue) return;
      var input = $input.get(0);
      if (input && input._flatpickr) {
        input._flatpickr.setDate(nextValue, true, 'Y-m-d');
        return;
      }
      $input.val(nextValue).trigger('change');
    }

    function normalizeDateRangeInputs() {
      var $startInput = $flow.find('input[name="start_date"]').first();
      var $endInput = $flow.find('input[name="end_date"]').first();
      if (!$startInput.length || !$endInput.length) return;
      var startValue = String($startInput.val() || '').trim();
      var endValue = String($endInput.val() || '').trim();
      var startDate = parseYmd(startValue);
      var endDate = parseYmd(endValue);
      if (!startDate) return;
      if (!endValue) {
        setDateInputValue($endInput, toYmd(startDate));
        return;
      }
      if (!endDate) return;
      if (endDate < startDate) {
        endDate = new Date(startDate.getTime());
        setDateInputValue($endInput, toYmd(startDate));
      }
      var diff = Math.round((endDate.getTime() - startDate.getTime()) / 86400000) + 1;
      if (diff > maxRangeDays) {
        var capped = addDays(startDate, maxRangeDays - 1);
        setDateInputValue($endInput, toYmd(capped));
      }
    }

    function selectedMode() {
      return String($flow.find('[name="booking_mode"]').val() || 'full_day');
    }

    function selectedFrequency() {
      return String($flow.find('[name="booking_frequency"]').val() || 'single');
    }

    function selectedDayScope() {
      var scope = String($flow.find('[name="booking_day_scope"]').first().val() || 'date_range');
      if (scope !== 'single_day' && scope !== 'date_range' && scope !== 'loose_days') {
        return 'date_range';
      }
      return scope;
    }

    function selectedInstallationType() {
      return String($flow.find('input[name="booking_installation_type"]:checked').val() || 'combined');
    }

    function hasSelectedResources() {
      var resources = selectedResources();
      return resources.spaces.length > 0 || resources.equipment.length > 0;
    }

    function currentPhase() {
      return String($flow.attr('data-cie-current-phase') || 'resources');
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

    function updateSelectedTags() {
      var $wrap = $flow.find('[data-cie-selected-tags="1"]');
      if (!$wrap.length) return;
      var html = '';
      $flow.find('input[name="spaces[]"]:checked,input[name="equipment[]"]:checked').each(function () {
        var $input = $(this);
        var id = String($input.val() || '');
        var kind = $input.attr('name') === 'spaces[]' ? 'space' : 'equipment';
        var name = String($input.attr('data-cie-equipment-name') || $input.attr('data-cie-space-name') || '').trim();
        if (!id || !name) return;
        html += '<button type="button" class="cie-selected-tag" data-cie-unselect-resource="' + escapeHtml(kind + ':' + id) + '">';
        html += '<span>' + escapeHtml(name) + '</span><span aria-hidden="true">&times;</span></button>';
      });
      if (!html) {
        html = '<span class="cie-selected-tags__empty">Todavía no has seleccionado recursos.</span>';
      }
      $wrap.html(html);
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
        $(this).closest('.cie-resource-toggle').removeClass('cie-resource-toggle--locked');
      });

      $flow.find('input[name="equipment[]"]:checked').each(function () {
        var sourceId = parseInt(String($(this).val()), 10);
        parseRequires($(this)).forEach(function (reqId) {
          var $required = byId[reqId];
          if (!$required || !$required.length || $required.prop('disabled')) return;
          $required.prop('checked', true);
          $required.attr('data-cie-locked', '1');
          $required.closest('.cie-resource-toggle').addClass('cie-resource-toggle--locked');
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
      var installType = selectedInstallationType();
      var bookingType = installType === 'combined' ? 'combined' : (installType === 'equipment' ? 'equipment' : 'space');
      var start = String($flow.find('input[name="start_date"]').val() || '').trim();
      var end = String($flow.find('input[name="end_date"]').val() || '').trim();
      if (!end && start) {
        end = start;
      }
      if (isYmd(start) && isYmd(end) && end < start) {
        end = start;
      }
      var weeks = parseInt(String($flow.find('input[name="booking_recurrence_weeks"]').val() || '1'), 10);
      var manual = String($flow.find('input[name="booking_dates_raw"]').val() || '').trim();
      var slots = selectedTimeSlots();
      var names = selectedResourceNames();
      var title = names.length ? names.join(', ') : 'Sin recursos seleccionados';
      var baseDates = [];
      if (dayScope === 'single_day') baseDates = normalizeYmdList([start]);
      else if (dayScope === 'date_range') baseDates = buildDateRange(start, end);
      else baseDates = parseManualDates(manual);
      var dates = expandDatesForFrequency(baseDates, dayScope, frequency, weeks);
      var occurrences = buildOccurrencesFromDatesAndSlots(mode, dates, slots);
      var summarized = summarizeOccurrences(occurrences, 3);
      var projectName = String($flow.find('input[name="project_name"]').val() || '').trim();
      var projectResponsible = String($flow.find('input[name="project_responsible"]').val() || '').trim();
      var fallbackLine = '';
      if (!end && start) {
        end = start;
      }
      if (!dates.length) {
        fallbackLine = 'Seleccione los días de la reserva.';
      } else if (mode === 'time_range' && !slots.length) {
        fallbackLine = 'Seleccione al menos un bloque horario.';
      }
      if (title === 'Sin recursos seleccionados') {
        fallbackLine = 'Seleccione uno o más recursos para continuar.';
      }
      var html = renderBookingDetailCard({
        title: title,
        bookingType: bookingType,
        occurrenceLines: summarized.lines,
        repeatLine: repeatLineFromFrequency(frequency, summarized.dates),
        totalLine: totalLineFromOccurrences(occurrences, mode),
        projectName: projectName,
        projectResponsible: projectResponsible,
        fallbackLine: fallbackLine
      });
      $flow.find('[data-cie-notice="schedule"]').html(html).show();
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
        var enabled = !!slot.available;
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

    function updateContinueState() {
      var selectedCount = selectedResourceNames().length;
      var $button = $flow.find('[data-cie-continue="resources"]');
      $button.toggleClass('is-disabled', selectedCount === 0);
      $button.prop('aria-disabled', selectedCount === 0 ? 'true' : 'false');
    }

    function setPhase(phase) {
      var valid = { resources: true, planning: true, details: true };
      var next = valid[phase] ? phase : 'resources';
      $flow.attr('data-cie-current-phase', next);
      $flow.find('[data-cie-phase]').hide();
      if (next === 'resources') {
        $flow.find('[data-cie-phase="resources"]').show();
        updateCalendarPreview();
        return;
      }
      if (next === 'planning') {
        $flow.find('[data-cie-phase="planning"]').show();
      } else {
        $flow.find('[data-cie-phase="details"]').show();
      }
      updateCalendarPreview();
    }

    function applyResourceSearch() {
      var query = String($flow.find('[data-cie-resource-search="1"]').val() || '').toLowerCase().trim();
      $flow.find('[data-cie-resource-row="1"]').each(function () {
        var label = String($(this).attr('data-cie-resource-label') || '').toLowerCase();
        $(this).toggle(query === '' || label.indexOf(query) !== -1);
      });
      $flow.find('[data-cie-group-header]').each(function () {
        var group = String($(this).attr('data-cie-group-header') || '');
        var visibleRows = $flow.find('[data-cie-resource-row="1"][data-cie-resource-group="' + group + '"]:visible').length;
        $(this).toggle(visibleRows > 0);
      });
    }

    function applyEquipmentGroupCollapsed() {
      var query = String($flow.find('[data-cie-resource-search="1"]').val() || '').toLowerCase().trim();
      $flow.find('[data-cie-group-header]').each(function () {
        var group = String($(this).attr('data-cie-group-header') || '');
        var $toggle = $flow.find('[data-cie-toggle-group="' + group + '"]').first();
        if (!$toggle.length) return;
        var expanded = String($toggle.attr('aria-expanded') || 'true') === 'true';
        $flow.find('[data-cie-resource-row="1"][data-cie-resource-group="' + group + '"]').each(function () {
          var label = String($(this).attr('data-cie-resource-label') || '').toLowerCase();
          var matchesSearch = query === '' || label.indexOf(query) !== -1;
          $(this).toggle(expanded && matchesSearch);
        });
      });
    }

    function updateCalendarPreview() {
      var $scheduler = $flow.closest('form').find('.cie-scheduler[data-cie-form-linked-scheduler="1"]').first();
      if (!$scheduler.length) return;
      var api = $scheduler.data('cieSchedulerApi');
      if (!api || typeof api.setPreviewReservation !== 'function') return;
      api.setPreviewReservation(null);
    }

    function showResourcesRequiredNotice() {
      $flow.find('[data-cie-notice="resources-required"]')
        .text('No has seleccionado ningún recurso.')
        .show();
    }

    function hideResourcesRequiredNotice() {
      $flow.find('[data-cie-notice="resources-required"]').hide().text('');
    }

    function buildVerificationPayload() {
      var mode = selectedMode();
      var frequency = selectedFrequency();
      var dayScope = selectedDayScope();
      var start = String($flow.find('input[name="start_date"]').val() || '').trim();
      var end = String($flow.find('input[name="end_date"]').val() || '').trim();
      if (!end && start) {
        end = start;
      }
      if (isYmd(start) && isYmd(end) && end < start) {
        end = start;
      }
      var weeks = parseInt(String($flow.find('input[name="booking_recurrence_weeks"]').val() || '1'), 10);
      var manual = String($flow.find('input[name="booking_dates_raw"]').val() || '').trim();
      var slots = selectedTimeSlots();
      var resources = selectedResources();
      var baseDates = [];
      if (dayScope === 'single_day') baseDates = normalizeYmdList([start]);
      else if (dayScope === 'date_range') baseDates = buildDateRange(start, end);
      else baseDates = parseManualDates(manual);
      var dates = expandDatesForFrequency(baseDates, dayScope, frequency, weeks);
      return {
        mode: mode,
        frequency: frequency,
        dayScope: dayScope,
        occurrences: buildOccurrencesFromDatesAndSlots(mode, dates, slots),
        spaces: resources.spaces,
        equipment: resources.equipment
      };
    }

    var verifyXhr = null;
    function verifyReservationAndContinue() {
      var payload = buildVerificationPayload();
      var $notice = $flow.find('[data-cie-notice="planning-verification"]');
      var $detailsNotice = $flow.find('[data-cie-notice="verification-status"]');
      if (!payload.spaces.length && !payload.equipment.length) {
        $notice.removeClass('is-info is-success').addClass('is-error').text('Debes seleccionar al menos un recurso antes de verificar.');
        $detailsNotice.removeClass('is-info is-success').addClass('is-error').text('Debes seleccionar al menos un recurso antes de verificar.');
        return;
      }
      if (!payload.occurrences.length) {
        $notice.removeClass('is-info is-success').addClass('is-error').text('La disponibilidad solicitada no está disponible, modifíquela e inténtelo de nuevo.');
        $detailsNotice.removeClass('is-info is-success').addClass('is-error').text('La disponibilidad solicitada no está disponible, modifíquela e inténtelo de nuevo.');
        return;
      }
      if (!window.CieLabBooking || !window.CieLabBooking.ajaxUrl) {
        $notice.removeClass('is-info is-success').addClass('is-error').text('No se ha podido lanzar la verificación.');
        $detailsNotice.removeClass('is-info is-success').addClass('is-error').text('No se ha podido lanzar la verificación.');
        return;
      }
      if (verifyXhr && verifyXhr.abort) verifyXhr.abort();
      $notice.removeClass('is-error is-success').addClass('is-info').text('Verificando conflictos de la reserva...');
      verifyXhr = $.post(window.CieLabBooking.ajaxUrl, {
        action: 'cie_lab_booking_verify_reservation',
        nonce: window.CieLabBooking.nonce,
        occurrences: JSON.stringify(payload.occurrences),
        spaces: payload.spaces,
        equipment: payload.equipment
      }).done(function (response) {
        if (!response || !response.success || !response.data) {
          var message = (response && response.data && response.data.message) ? String(response.data.message) : 'La disponibilidad solicitada no está disponible, modifíquela e inténtelo de nuevo.';
          $notice.removeClass('is-info is-success').addClass('is-error').text(message);
          $detailsNotice.removeClass('is-info is-success').addClass('is-error').text(message);
          return;
        }
        $notice.removeClass('is-info is-error').addClass('is-success').text(String(response.data.message || 'La reserva no tiene conflictos.'));
        $detailsNotice.removeClass('is-info is-error').addClass('is-success').text(String(response.data.message || 'La reserva no tiene conflictos.'));
        setPhase('details');
        var $details = $flow.find('[data-cie-phase="details"]');
        if ($details.length) {
          $('html, body').animate({ scrollTop: Math.max(0, $details.offset().top - 30) }, 200);
        }
      }).fail(function () {
        $notice.removeClass('is-info is-success').addClass('is-error').text('La disponibilidad solicitada no está disponible, modifíquela e inténtelo de nuevo.');
        $detailsNotice.removeClass('is-info is-success').addClass('is-error').text('La disponibilidad solicitada no está disponible, modifíquela e inténtelo de nuevo.');
      });
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
      $flow.find('[data-cie-hide-day-scope]').each(function () {
        $(this).toggle(String($(this).attr('data-cie-hide-day-scope')) !== dayScope);
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
      normalizeDateRangeInputs();
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
        normalizeDateRangeInputs();
      }

      applyResourceSearch();
      applyEquipmentGroupCollapsed();
      updateSelectedTags();
      updateScheduleNotice();
      updateSlotsAvailability();
      updateFormAvailability();
      updateContinueState();
      syncLinkedScheduler();
      updateCalendarPreview();
      if (selectedResourceNames().length) hideResourcesRequiredNotice();
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
    $flow.on('click', '[data-cie-toggle-group]', function (event) {
      event.preventDefault();
      var $btn = $(this);
      var group = String($btn.attr('data-cie-toggle-group') || '');
      if (!group) return;
      var expanded = String($btn.attr('aria-expanded') || 'true') === 'true';
      $btn.attr('aria-expanded', expanded ? 'false' : 'true');
      applyEquipmentGroupCollapsed();
    });
    $flow.on('click', '[data-cie-unselect-resource]', function (event) {
      event.preventDefault();
      var token = String($(this).attr('data-cie-unselect-resource') || '');
      var parts = token.split(':');
      if (parts.length !== 2) return;
      var kind = parts[0];
      var id = parts[1];
      if (kind === 'space') {
        $flow.find('input[name="spaces[]"][value="' + id + '"]').prop('checked', false);
      } else if (kind === 'equipment') {
        $flow.find('input[name="equipment[]"][value="' + id + '"]').prop('checked', false);
      }
      updateVisibility();
    });
    $flow.on('click', '.cie-resource-toggle', function (event) {
      if ($(event.target).is('input')) return;
      event.preventDefault();
      var $input = $(this).find('input[type="checkbox"]').first();
      if (!$input.length || $input.prop('disabled')) return;
      $input.prop('checked', !$input.is(':checked')).trigger('change');
    });
    $flow.on('click', '[data-cie-continue]', function (event) {
      event.preventDefault();
      var key = String($(this).attr('data-cie-continue') || '');
      if (key === 'resources') {
        if (!selectedResourceNames().length) {
          showResourcesRequiredNotice();
          return;
        }
        hideResourcesRequiredNotice();
        setPhase('planning');
        var $planning = $flow.find('[data-cie-phase="planning"]');
        if ($planning.length) {
          $('html, body').animate({ scrollTop: Math.max(0, $planning.offset().top - 30) }, 200);
        }
        return;
      }
      if (key === 'planning') {
        verifyReservationAndContinue();
      }
    });
    $flow.on('click', '[data-cie-edit-resources]', function (event) {
      event.preventDefault();
      setPhase('resources');
      var $resources = $flow.find('[data-cie-phase="resources"]');
      if ($resources.length) {
        $('html, body').animate({ scrollTop: Math.max(0, $resources.offset().top - 30) }, 200);
      }
    });
    $flow.on('click', '[data-cie-back-planning]', function (event) {
      event.preventDefault();
      setPhase('planning');
      var $planning = $flow.find('[data-cie-phase="planning"]');
      if ($planning.length) {
        $('html, body').animate({ scrollTop: Math.max(0, $planning.offset().top - 30) }, 200);
      }
    });
    $flow.on('input', '[data-cie-resource-search="1"]', function () {
      applyResourceSearch();
      applyEquipmentGroupCollapsed();
    });
    $flow.on('change', 'input[name="booking_time_slots[]"]', function () {
      var $chip = $(this).closest('.cie-slot-chip');
      $chip.toggleClass('is-selected', $(this).is(':checked'));
      updateScheduleNotice();
    });
    var $form = $flow.closest('form');
    $form.on('submit', function (event) {
      var $currentForm = $(this);
      if ($currentForm.data('cieFinalValidationOk') === '1') {
        return;
      }
      event.preventDefault();
      var payload = buildVerificationPayload();
      var $detailsNotice = $flow.find('[data-cie-notice="verification-status"]');
      if (!payload.occurrences.length || (!payload.spaces.length && !payload.equipment.length)) {
        $detailsNotice.removeClass('is-info is-success').addClass('is-error').text('La disponibilidad solicitada no está disponible, modifíquela e inténtelo de nuevo.');
        return;
      }
      $detailsNotice.removeClass('is-error is-success').addClass('is-info').text('Validando disponibilidad antes de confirmar la reserva...');
      $.post(window.CieLabBooking.ajaxUrl, {
        action: 'cie_lab_booking_verify_reservation',
        nonce: window.CieLabBooking.nonce,
        occurrences: JSON.stringify(payload.occurrences),
        spaces: payload.spaces,
        equipment: payload.equipment
      }).done(function (response) {
        if (!response || !response.success || !response.data) {
          var errorMessage = (response && response.data && response.data.message) ? String(response.data.message) : 'La disponibilidad solicitada no está disponible, modifíquela e inténtelo de nuevo.';
          $detailsNotice.removeClass('is-info is-success').addClass('is-error').text(errorMessage);
          return;
        }
        $detailsNotice.removeClass('is-info is-error').addClass('is-success').text(String(response.data.message || 'Disponibilidad confirmada. Enviando reserva...'));
        $currentForm.data('cieFinalValidationOk', '1');
        $currentForm.get(0).submit();
      }).fail(function () {
        $detailsNotice.removeClass('is-info is-success').addClass('is-error').text('La disponibilidad solicitada no está disponible, modifíquela e inténtelo de nuevo.');
      });
    });
    setPhase('resources');
    applyResourceSearch();
    applyEquipmentGroupCollapsed();
    updateVisibility();
  }

  function renderEventChip(event, withTime) {
    var classes = [
      'cie-scheduler__event-chip',
      event.type === 'block' ? 'is-block' : 'is-booking',
      'is-' + statusSlug(event.status || ''),
      event.isPast ? 'is-past' : '',
      resourceTypeClass(event.resourceType || ''),
      event.isPreview ? 'is-preview' : ''
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
      filterResource: 'all',
      previewPayload: null
    };

    function withPreview(events) {
      var base = Array.isArray(events) ? events.slice() : [];
      if (!state.previewPayload || !Array.isArray(state.previewPayload.occurrences)) {
        return base;
      }
      var occurrences = state.previewPayload.occurrences;
      var title = String(state.previewPayload.title || 'Mi reserva');
      var resources = Array.isArray(state.previewPayload.resources) ? state.previewPayload.resources : [];
      var resourceType = String(state.previewPayload.resourceType || 'combined');
      occurrences.forEach(function (occ, index) {
        var date = String((occ && occ.date) || '');
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return;
        var fullDay = !!(occ && occ.full_day);
        base.push({
          id: 'preview-' + index + '-' + date,
          type: 'booking',
          bookingId: 0,
          date: date,
          start: fullDay ? '' : String((occ && occ.start) || ''),
          end: fullDay ? '' : String((occ && occ.end) || ''),
          fullDay: fullDay,
          title: title,
          resources: resources,
          resourceType: resourceType,
          status: 'pending',
          isPast: false,
          isPreview: true
        });
      });
      return base;
    }

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
        fullDayEvents.slice(0, 2).forEach(function (event) {
          html += renderEventChip(event, false);
        });
        if (fullDayEvents.length > 2) {
          html += '<span class="cie-scheduler__event-more"><button type="button" class="cie-scheduler__event-more-link" data-cie-open-day-more="' + allDayDate + '">Ver más…</button></span>';
        }
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
        calendar_scope: scope,
        filter_resource: state.filterResource
      }).done(function (response) {
        if (!response || !response.success || !response.data || !Array.isArray(response.data.events)) {
          $container.html('<p>No se pudo cargar el calendario.</p>');
          return;
        }
        state.events = withPreview(response.data.events.filter(function (event) { return !event.isPreview; }));
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
      },
      setPreviewReservation: function (payload) {
        if (!payload || !Array.isArray(payload.occurrences) || !payload.occurrences.length) {
          state.previewPayload = null;
          state.events = state.events.filter(function (event) { return !event.isPreview; });
          render();
          return;
        }
        state.previewPayload = {
          occurrences: payload.occurrences,
          title: String(payload.title || 'Mi reserva'),
          resources: Array.isArray(payload.resources) ? payload.resources : [],
          resourceType: String(payload.resourceType || 'combined')
        };
        var withoutPreview = state.events.filter(function (event) { return !event.isPreview; });
        state.events = withPreview(withoutPreview);
        render();
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
