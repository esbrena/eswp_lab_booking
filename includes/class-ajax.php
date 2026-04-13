<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Ajax {
	public static function init(): void {
		// Availability checks used by the booking form flow.
		add_action('wp_ajax_cie_lab_booking_availability', [self::class, 'availability']);
		add_action('wp_ajax_cie_lab_booking_time_slots', [self::class, 'time_slots']);
		add_action('wp_ajax_cie_lab_booking_resource_availability_calendar', [self::class, 'resource_availability_calendar']);
		add_action('wp_ajax_cie_lab_booking_verify_reservation', [self::class, 'verify_reservation']);

		// Calendar hover / click details (front + admin).
		add_action('wp_ajax_cie_lab_booking_day_details', [self::class, 'day_details']);
		add_action('wp_ajax_nopriv_cie_lab_booking_day_details', [self::class, 'day_details']);
		add_action('wp_ajax_cie_lab_booking_calendar_feed', [self::class, 'calendar_feed']);
		add_action('wp_ajax_nopriv_cie_lab_booking_calendar_feed', [self::class, 'calendar_feed']);
		add_action('wp_ajax_cie_lab_booking_booking_detail', [self::class, 'booking_detail']);
		add_action('wp_ajax_nopriv_cie_lab_booking_booking_detail', [self::class, 'booking_detail']);
	}

	private static function verify_any_nonce(array $nonces): bool {
		$nonce = (string) ($_POST['nonce'] ?? '');
		if ($nonce === '') {
			return false;
		}
		foreach ($nonces as $action) {
			if (wp_verify_nonce($nonce, $action)) {
				return true;
			}
		}
		return false;
	}

	public static function availability(): void {
		Util::require_booking_user();

		if (!self::verify_any_nonce(['cie_lab_booking'])) {
			wp_send_json_error(['message' => __('Nonce inválido.', 'cie-lab-booking')], 403);
		}

		$start = Util::normalize_date_ymd((string) ($_POST['start_date'] ?? ''));
		$end = Util::normalize_date_ymd((string) ($_POST['end_date'] ?? ''));
		if (!$start || !$end || $end < $start) {
			wp_send_json_error(['message' => __('Fechas inválidas.', 'cie-lab-booking')], 400);
		}

		$space_posts = Bookings::get_resources('space', true);
		$equipment_posts = Bookings::get_resources('equipment', true);

		$space_ids = array_map(static fn($p) => (int) $p->ID, $space_posts);
		$equipment_ids = array_map(static fn($p) => (int) $p->ID, $equipment_posts);

		$availability = Bookings::get_resources_availability($start, $end, $space_ids, $equipment_ids);

		wp_send_json_success([
			'start_date' => $start,
			'end_date' => $end,
			'blocked' => $availability['blocked'],
			'spaces' => $availability['spaces'], // id => bool
			'equipment' => $availability['equipment'], // id => bool
		]);
	}

	public static function time_slots(): void {
		Util::require_booking_user();

		if (!self::verify_any_nonce(['cie_lab_booking'])) {
			wp_send_json_error(['message' => __('Nonce inválido.', 'cie-lab-booking')], 403);
		}

		$date = Util::normalize_date_ymd((string) ($_POST['date'] ?? ''));
		if (!$date) {
			wp_send_json_error(['message' => __('Fecha inválida.', 'cie-lab-booking')], 400);
		}

		$space_ids = array_values(array_filter(array_map('intval', (array) ($_POST['spaces'] ?? []))));
		$equipment_ids = array_values(array_filter(array_map('intval', (array) ($_POST['equipment'] ?? []))));
		if (!$space_ids && !$equipment_ids) {
			wp_send_json_error(['message' => __('Seleccione al menos un recurso.', 'cie-lab-booking')], 400);
		}

		$slots = Bookings::get_daily_time_slots_availability($date, $space_ids, $equipment_ids);
		wp_send_json_success([
			'date' => $date,
			'slots' => $slots,
		]);
	}

	public static function resource_availability_calendar(): void {
		Util::require_booking_user();

		if (!self::verify_any_nonce(['cie_lab_booking'])) {
			wp_send_json_error(['message' => __('Nonce inválido.', 'cie-lab-booking')], 403);
		}

		$start = Util::normalize_date_ymd((string) ($_POST['start_date'] ?? ''));
		$end = Util::normalize_date_ymd((string) ($_POST['end_date'] ?? ''));
		if (!$start || !$end || $end < $start) {
			wp_send_json_error(['message' => __('Rango de fechas inválido.', 'cie-lab-booking')], 400);
		}

		$space_ids = array_values(array_filter(array_map('intval', (array) ($_POST['spaces'] ?? []))));
		$equipment_ids = array_values(array_filter(array_map('intval', (array) ($_POST['equipment'] ?? []))));
		if (!$space_ids && !$equipment_ids) {
			wp_send_json_error(['message' => __('Seleccione al menos un recurso.', 'cie-lab-booking')], 400);
		}

		$mode = sanitize_key((string) ($_POST['booking_mode'] ?? Bookings::BOOKING_MODE_FULL_DAY));
		$time_start = trim((string) ($_POST['booking_time_start'] ?? ''));
		$time_end = trim((string) ($_POST['booking_time_end'] ?? ''));
		$time_slots = array_values(array_filter(array_map('sanitize_text_field', (array) ($_POST['booking_time_slots'] ?? []))));

		$map = [];
		$cursor = strtotime($start . ' 00:00:00');
		$end_ts = strtotime($end . ' 00:00:00');
		while ($cursor <= $end_ts) {
			$date = gmdate('Y-m-d', $cursor);
			$occurrence = [[
				'date' => $date,
				'start' => '',
				'end' => '',
				'full_day' => true,
			]];
			if ($mode === Bookings::BOOKING_MODE_TIME_RANGE && $time_start !== '' && $time_end !== '') {
				$occurrence = [[
					'date' => $date,
					'start' => $time_start,
					'end' => $time_end,
					'full_day' => false,
				]];
			}
			if ($mode === Bookings::BOOKING_MODE_TIME_RANGE && !empty($time_slots)) {
				$occurrence = [];
				foreach ($time_slots as $raw_slot) {
					if (!preg_match('/^(\d{2}\:\d{2})\-(\d{2}\:\d{2})$/', (string) $raw_slot, $matches)) {
						continue;
					}
					$occurrence[] = [
						'date' => $date,
						'start' => (string) $matches[1],
						'end' => (string) $matches[2],
						'full_day' => false,
					];
				}
				if (empty($occurrence)) {
					$occurrence = [[
						'date' => $date,
						'start' => '',
						'end' => '',
						'full_day' => true,
					]];
				}
			}
			$conflicts = Bookings::find_conflicts_for_occurrences($occurrence, $space_ids, $equipment_ids);
			$status = 'available';
			if (!empty($conflicts['blocked'])) {
				$status = 'blocked';
			} elseif (!empty($conflicts['spaces']) || !empty($conflicts['equipment'])) {
				$status = 'busy';
			}
			$map[$date] = [
				'status' => $status,
				'spaces_conflict' => array_values(array_map('intval', (array) ($conflicts['spaces'] ?? []))),
				'equipment_conflict' => array_values(array_map('intval', (array) ($conflicts['equipment'] ?? []))),
			];
			$cursor = strtotime('+1 day', $cursor);
		}

		wp_send_json_success([
			'start_date' => $start,
			'end_date' => $end,
			'days' => $map,
		]);
	}

	public static function verify_reservation(): void {
		Util::require_booking_user();

		if (!self::verify_any_nonce(['cie_lab_booking'])) {
			wp_send_json_error(['message' => __('Nonce inválido.', 'cie-lab-booking')], 403);
		}

		$raw_occurrences = (string) wp_unslash((string) ($_POST['occurrences'] ?? '[]'));
		$decoded = json_decode($raw_occurrences, true);
		if (!is_array($decoded)) {
			wp_send_json_error(['message' => __('La disponibilidad solicitada no está disponible, modifíquela e inténtelo de nuevo.', 'cie-lab-booking')]);
		}

		$occurrences = Bookings::sanitize_occurrences((array) $decoded);
		if (!$occurrences) {
			wp_send_json_error(['message' => __('La disponibilidad solicitada no está disponible, modifíquela e inténtelo de nuevo.', 'cie-lab-booking')]);
		}

		$space_ids = array_values(array_filter(array_map('intval', (array) ($_POST['spaces'] ?? []))));
		$equipment_ids = array_values(array_filter(array_map('intval', (array) ($_POST['equipment'] ?? []))));
		if (!$space_ids && !$equipment_ids) {
			wp_send_json_error(['message' => __('Debes seleccionar al menos un recurso para verificar la reserva.', 'cie-lab-booking')]);
		}

		$conflicts = Bookings::find_conflicts_for_occurrences($occurrences, $space_ids, $equipment_ids);
		$has_conflicts = !empty($conflicts['spaces']) || !empty($conflicts['equipment']) || !empty($conflicts['blocked']);
		if ($has_conflicts) {
			wp_send_json_error([
				'message' => __('La disponibilidad solicitada no está disponible, modifíquela e inténtelo de nuevo.', 'cie-lab-booking'),
				'conflicts' => $conflicts,
			]);
		}

		wp_send_json_success([
			'message' => __('La disponibilidad es válida.', 'cie-lab-booking'),
		]);
	}

	public static function calendar_feed(): void {
		if (!self::verify_any_nonce(['cie_lab_booking', 'cie_lab_booking_admin'])) {
			wp_send_json_error(['message' => __('Nonce inválido.', 'cie-lab-booking')], 403);
		}

		$start = Util::normalize_date_ymd((string) ($_POST['start_date'] ?? ''));
		$end = Util::normalize_date_ymd((string) ($_POST['end_date'] ?? ''));
		if (!$start || !$end || $end < $start) {
			wp_send_json_error(['message' => __('Rango de fechas inválido.', 'cie-lab-booking')], 400);
		}

		$is_admin = current_user_can('manage_options');
		$can_book = Util::current_user_can_book();
		$user_id = get_current_user_id();
		$booking_view = sanitize_key((string) ($_POST['booking_view'] ?? ''));
		if (!in_array($booking_view, ['approved', 'pending', 'all'], true)) {
			$booking_view = $is_admin ? 'approved' : 'all';
		}
		$calendar_scope = sanitize_key((string) ($_POST['calendar_scope'] ?? 'general'));
		if (!in_array($calendar_scope, ['general', 'current_user'], true)) {
			$calendar_scope = 'general';
		}
		$filter_resource = sanitize_text_field((string) wp_unslash((string) ($_POST['filter_resource'] ?? 'all')));
		if ($filter_resource === '') {
			$filter_resource = 'all';
		}
		if ($calendar_scope === 'current_user' && !$user_id) {
			wp_send_json_error(['message' => __('Debes iniciar sesión para ver este calendario.', 'cie-lab-booking')], 403);
		}

		if ($calendar_scope === 'current_user' && $user_id) {
			$bookings = Bookings::get_overlapping_user_bookings_for_calendar($start, $end, (int) $user_id);
		} else {
			$bookings = Bookings::get_overlapping_bookings_for_calendar($start, $end, $is_admin);
			if ($is_admin && $calendar_scope === 'general') {
				$bookings = self::filter_bookings_by_admin_view($bookings, $booking_view);
			}
			// Front users should always see their own pending/changing bookings in the global calendar.
			if (!$is_admin && $user_id > 0) {
				$user_extra = Bookings::get_overlapping_user_bookings_for_calendar($start, $end, (int) $user_id);
				$by_id = [];
				foreach ($bookings as $booking) {
					$by_id[(int) $booking->ID] = $booking;
				}
				foreach ($user_extra as $booking) {
					$by_id[(int) $booking->ID] = $booking;
				}
				$bookings = array_values($by_id);
			}
		}

		$today = gmdate('Y-m-d');
		$events = [];
		foreach ($bookings as $booking) {
			$booking_id = (int) $booking->ID;
			$status = (string) get_post_meta($booking_id, '_cie_booking_status', true);
			$spaces = (array) get_post_meta($booking_id, '_cie_booking_spaces', true);
			$equipment = (array) get_post_meta($booking_id, '_cie_booking_equipment', true);
			$resource_names = ($is_admin || $can_book || $calendar_scope === 'current_user')
				? self::resource_names(array_merge($spaces, $equipment))
				: [];
			$booking_type = self::booking_type_from_resources($spaces, $equipment);
			$title = self::calendar_event_title($booking_type, $resource_names);
			$occurrences = Bookings::get_booking_occurrences($booking_id, $start, $end);
			$user = get_user_by('id', (int) $booking->post_author);

			foreach ($occurrences as $index => $occ) {
				$full_day = !empty($occ['full_day']);
				$occ_date = (string) ($occ['date'] ?? '');
				$is_past = ($occ_date !== '' && $occ_date < $today);
				$events[] = [
					'id' => 'booking-' . $booking_id . '-' . $index,
					'type' => 'booking',
					'bookingId' => $booking_id,
					'date' => $occ_date,
					'start' => $full_day ? '' : (string) ($occ['start'] ?? ''),
					'end' => $full_day ? '' : (string) ($occ['end'] ?? ''),
					'fullDay' => $full_day,
					'title' => $title,
					'resources' => $resource_names,
					'resourceType' => $booking_type,
					'status' => $status,
					'statusSlug' => self::status_slug($status),
					'isPast' => $is_past,
					'user' => $is_admin && $user ? (string) $user->display_name : '',
					'detailUrl' => $is_admin ? admin_url('admin.php?page=cie-lab-booking-booking&booking_id=' . $booking_id) : null,
				];
			}
		}

		$blocks = Bookings::get_overlapping_blocks($start, $end);
		foreach ($blocks as $block) {
			$block_id = (int) $block->ID;
			$block_start = Util::normalize_date_ymd((string) get_post_meta($block_id, '_cie_block_start_date', true)) ?: '';
			$block_end = Util::normalize_date_ymd((string) get_post_meta($block_id, '_cie_block_end_date', true)) ?: '';
			if ($block_start === '' || $block_end === '' || $block_end < $block_start) {
				continue;
			}
			$cursor = max(strtotime($start . ' 00:00:00'), strtotime($block_start . ' 00:00:00'));
			$last = min(strtotime($end . ' 00:00:00'), strtotime($block_end . ' 00:00:00'));
			$blocked_ids = array_values(array_filter(array_map('intval', (array) get_post_meta($block_id, '_cie_block_resource_ids', true))));
			$is_global = !$blocked_ids;
			$resource_names = $is_global ? [(string) __('Todos los recursos', 'cie-lab-booking')] : self::resource_names($blocked_ids);
			if (!self::block_matches_resource_filter($is_global, $resource_names, $filter_resource)) {
				continue;
			}
			while ($cursor <= $last) {
				$day = gmdate('Y-m-d', $cursor);
				$events[] = [
					'id' => 'block-' . $block_id . '-' . $day,
					'type' => 'block',
					'bookingId' => 0,
					'date' => $day,
					'start' => '',
					'end' => '',
					'fullDay' => true,
					'title' => (string) __('Mantenimiento', 'cie-lab-booking'),
					'resources' => $resource_names,
					'status' => 'blocked',
					'statusSlug' => 'blocked',
					'isPast' => ($day < gmdate('Y-m-d')),
					'user' => '',
					'detailUrl' => $is_admin ? get_edit_post_link($block_id, '') : null,
				];
				$cursor = strtotime('+1 day', $cursor);
			}
		}

		wp_send_json_success([
			'start_date' => $start,
			'end_date' => $end,
			'events' => $events,
		]);
	}

	public static function booking_detail(): void {
		if (!self::verify_any_nonce(['cie_lab_booking', 'cie_lab_booking_admin'])) {
			wp_send_json_error(['message' => __('Nonce inválido.', 'cie-lab-booking')], 403);
		}

		$booking_id = (int) ($_POST['booking_id'] ?? 0);
		$booking = $booking_id ? Bookings::get_booking($booking_id) : null;
		if (!$booking) {
			wp_send_json_error(['message' => __('Reserva no encontrada.', 'cie-lab-booking')], 404);
		}

		$is_admin = current_user_can('manage_options');
		$user_id = get_current_user_id();
		$owns = $user_id && ((int) $booking->post_author === $user_id);
		$can_book = Util::current_user_can_book();
		if (!$is_admin && !$can_book && !$owns) {
			wp_send_json_error(['message' => __('No tienes permisos para ver esta reserva.', 'cie-lab-booking')], 403);
		}

		$spaces = (array) get_post_meta($booking_id, '_cie_booking_spaces', true);
		$equipment = (array) get_post_meta($booking_id, '_cie_booking_equipment', true);
		$space_names = self::resource_names($spaces);
		$equipment_names = self::resource_names($equipment);
		$all_resources = array_values(array_unique(array_merge($space_names, $equipment_names)));
		$status = (string) get_post_meta($booking_id, '_cie_booking_status', true);
		$booking_type = self::booking_type_from_resources($spaces, $equipment);
		$title = self::calendar_event_title($booking_type, $all_resources);

		$owner = get_user_by('id', (int) $booking->post_author);
		wp_send_json_success([
			'id' => $booking_id,
			'title' => $title,
			'status' => $status,
			'statusSlug' => self::status_slug($status),
			'type' => $booking_type,
			'mode' => (string) get_post_meta($booking_id, '_cie_booking_mode', true),
			'frequency' => (string) get_post_meta($booking_id, '_cie_booking_frequency', true),
			'day_scope' => (string) get_post_meta($booking_id, '_cie_booking_day_scope', true),
			'start_date' => (string) get_post_meta($booking_id, '_cie_booking_start_date', true),
			'end_date' => (string) get_post_meta($booking_id, '_cie_booking_end_date', true),
			'time_start' => (string) get_post_meta($booking_id, '_cie_booking_time_start', true),
			'time_end' => (string) get_post_meta($booking_id, '_cie_booking_time_end', true),
			'time_slots' => array_values(array_filter(array_map('strval', (array) get_post_meta($booking_id, '_cie_booking_time_slots', true)))),
			'spaces' => $space_names,
			'equipment' => $equipment_names,
			'resources' => $all_resources,
			'occurrences' => Bookings::get_booking_occurrences($booking_id),
			'project' => [
				'name' => (string) get_post_meta($booking_id, '_cie_booking_project_name', true),
				'duration' => (string) get_post_meta($booking_id, '_cie_booking_project_duration', true),
				'responsible' => (string) get_post_meta($booking_id, '_cie_booking_project_responsible', true),
				'ip_email' => (string) get_post_meta($booking_id, '_cie_booking_project_ip_email', true),
			],
			'user' => ($is_admin && $owner) ? [
				'id' => (int) $owner->ID,
				'displayName' => (string) $owner->display_name,
				'email' => (string) $owner->user_email,
			] : null,
		]);
	}

	public static function day_details(): void {
		// Accept both front and admin nonces (admin will send its own).
		if (!self::verify_any_nonce(['cie_lab_booking', 'cie_lab_booking_admin'])) {
			wp_send_json_error(['message' => __('Nonce inválido.', 'cie-lab-booking')], 403);
		}

		$start = Util::normalize_date_ymd((string) ($_POST['start_date'] ?? $_POST['date'] ?? ''));
		$end = Util::normalize_date_ymd((string) ($_POST['end_date'] ?? $_POST['date'] ?? ''));
		if (!$start || !$end || $end < $start) {
			wp_send_json_error(['message' => __('Fechas inválidas.', 'cie-lab-booking')], 400);
		}

		$is_admin = current_user_can('manage_options');
		$can_book = Util::current_user_can_book();
		$user_id = get_current_user_id();
		$booking_view = sanitize_key((string) ($_POST['booking_view'] ?? ''));
		if (!in_array($booking_view, ['approved', 'pending', 'all'], true)) {
			$booking_view = $is_admin ? 'approved' : 'all';
		}
		$calendar_scope = sanitize_key((string) ($_POST['calendar_scope'] ?? 'general'));
		if (!in_array($calendar_scope, ['general', 'current_user'], true)) {
			$calendar_scope = 'general';
		}
		$filter_resource = sanitize_text_field((string) wp_unslash((string) ($_POST['filter_resource'] ?? 'all')));
		if ($filter_resource === '') {
			$filter_resource = 'all';
		}
		if ($calendar_scope === 'current_user' && !$user_id) {
			wp_send_json_error(['message' => __('Debes iniciar sesión para ver este calendario.', 'cie-lab-booking')], 403);
		}

		if ($calendar_scope === 'current_user' && $user_id) {
			$bookings = Bookings::get_overlapping_user_bookings_for_calendar($start, $end, (int) $user_id);
		} else {
			$bookings = Bookings::get_overlapping_bookings_for_calendar($start, $end, $is_admin);
			if ($is_admin && $calendar_scope === 'general') {
				$bookings = self::filter_bookings_by_admin_view($bookings, $booking_view);
			}
			if (!$is_admin && $user_id > 0) {
				$user_extra = Bookings::get_overlapping_user_bookings_for_calendar($start, $end, (int) $user_id);
				$by_id = [];
				foreach ($bookings as $booking) {
					$by_id[(int) $booking->ID] = $booking;
				}
				foreach ($user_extra as $booking) {
					$by_id[(int) $booking->ID] = $booking;
				}
				$bookings = array_values($by_id);
			}
		}
		$blocks = Bookings::get_overlapping_blocks($start, $end);

		$booking_items = [];
		foreach ($bookings as $b) {
			$bid = (int) $b->ID;
			$status = (string) get_post_meta($bid, '_cie_booking_status', true);
			$bs = (string) get_post_meta($bid, '_cie_booking_start_date', true);
			$be = (string) get_post_meta($bid, '_cie_booking_end_date', true);
			$spaces = (array) get_post_meta($bid, '_cie_booking_spaces', true);
			$equipment = (array) get_post_meta($bid, '_cie_booking_equipment', true);
			$booking_type = self::booking_type_from_resources($spaces, $equipment);

			// By default: show minimal data.
			$item = [
				'id' => $bid,
				'start_date' => $bs,
				'end_date' => $be,
				'status' => $status,
				'statusSlug' => self::status_slug($status),
				'resourceType' => $booking_type,
				'title' => '',
				'mode' => (string) get_post_meta($bid, '_cie_booking_mode', true),
				'frequency' => (string) get_post_meta($bid, '_cie_booking_frequency', true),
				'occurrences' => [],
				'spaces' => [],
				'equipment' => [],
				'user' => null,
				'project' => null,
				'detailUrl' => null,
			];

			$owns = $user_id && ((int) $b->post_author === (int) $user_id);

			$can_see_resource_names = $is_admin || $can_book || $calendar_scope === 'current_user' || $owns;
			if ($can_see_resource_names) {
				foreach (array_merge((array) $spaces, (array) $equipment) as $rid) {
					$p = get_post((int) $rid);
					if ($p && $p->post_type === Post_Types::CPT_RESOURCE) {
						$kind = (string) get_post_meta((int) $p->ID, '_cie_resource_kind', true);
						if ($kind === 'space') {
							$item['spaces'][] = $p->post_title;
						} elseif ($kind === 'equipment') {
							$item['equipment'][] = $p->post_title;
						}
					}
				}
			}

			if ($is_admin) {
				$u = get_user_by('id', (int) $b->post_author);
				$item['user'] = [
					'id' => (int) $b->post_author,
					'displayName' => $u ? (string) $u->display_name : '',
					'email' => $u ? (string) $u->user_email : '',
				];
				$item['project'] = [
					'name' => (string) get_post_meta($bid, '_cie_booking_project_name', true),
					'responsible' => (string) get_post_meta($bid, '_cie_booking_project_responsible', true),
				];
				$item['detailUrl'] = admin_url('admin.php?page=cie-lab-booking-booking&booking_id=' . $bid);
			} elseif ($owns) {
				// User can only see detail link for its own booking (edit flow depends on status).
				$item['detailUrl'] = null;
			}
			$item['title'] = self::calendar_event_title($booking_type, array_values(array_unique(array_merge((array) $item['spaces'], (array) $item['equipment']))));
			$item['occurrences'] = Bookings::get_booking_occurrences($bid, $start, $end);

			$booking_items[] = $item;
		}

		$block_items = [];
		foreach ($blocks as $block) {
			$blocked_ids = (array) get_post_meta((int) $block->ID, '_cie_block_resource_ids', true);
			$blocked_ids = array_values(array_filter(array_map('intval', $blocked_ids)));
			$is_global = empty($blocked_ids);

			$resources = [];
			// Show resource names when viewer is admin or booking user.
			if (!$is_global && ($is_admin || $can_book)) {
				foreach ($blocked_ids as $rid) {
					$p = get_post((int) $rid);
					if ($p && $p->post_type === Post_Types::CPT_RESOURCE) {
						$resources[] = $p->post_title;
					}
				}
			}
			if (!self::block_matches_resource_filter($is_global, $resources, $filter_resource)) {
				continue;
			}

			$block_items[] = [
				'start_date' => (string) get_post_meta((int) $block->ID, '_cie_block_start_date', true),
				'end_date' => (string) get_post_meta((int) $block->ID, '_cie_block_end_date', true),
				'isGlobal' => $is_global,
				'resources' => $resources,
				'reason' => $is_admin ? (string) get_post_meta((int) $block->ID, '_cie_block_reason', true) : '',
			];
		}

		wp_send_json_success([
			'start_date' => $start,
			'end_date' => $end,
			'bookings' => $booking_items,
			'blocks' => $block_items,
			'viewer' => [
				'isAdmin' => $is_admin,
				'canBook' => $can_book,
				'userId' => (int) $user_id,
				'calendarScope' => $calendar_scope,
			],
		]);
	}

	private static function status_slug(string $status): string {
		$map = [
			Post_Types::BOOKING_STATUS_PENDING => 'pending',
			Post_Types::BOOKING_STATUS_APPROVED => 'approved',
			Post_Types::BOOKING_STATUS_REJECTED => 'rejected',
			Post_Types::BOOKING_STATUS_CHANGES => 'changes',
			Post_Types::BOOKING_STATUS_CANCELLED => 'cancelled',
		];
		return $map[$status] ?? 'unknown';
	}

	/**
	 * @param array<int|string> $resource_ids
	 * @return array<int,string>
	 */
	private static function resource_names(array $resource_ids): array {
		$names = [];
		foreach ($resource_ids as $rid) {
			$post = get_post((int) $rid);
			if ($post && $post->post_type === Post_Types::CPT_RESOURCE) {
				$names[] = (string) $post->post_title;
			}
		}
		return array_values(array_unique($names));
	}

	/**
	 * @param array<int|string> $spaces
	 * @param array<int|string> $equipment
	 */
	private static function booking_type_from_resources(array $spaces, array $equipment): string {
		$has_space = !empty(array_values(array_filter(array_map('intval', $spaces))));
		$has_equipment = !empty(array_values(array_filter(array_map('intval', $equipment))));
		if ($has_space && $has_equipment) {
			return 'combined';
		}
		if ($has_space) {
			return 'space';
		}
		if ($has_equipment) {
			return 'equipment';
		}
		return 'generic';
	}

	/**
	 * @param array<int,string> $resource_names
	 */
	private static function calendar_event_title(string $booking_type, array $resource_names): string {
		if ($booking_type === 'combined') {
			if (!empty($resource_names)) {
				return (string) __('Reserva combinada', 'cie-lab-booking') . ': ' . implode(', ', array_slice($resource_names, 0, 2));
			}
			return (string) __('Reserva combinada', 'cie-lab-booking');
		}
		if ($booking_type === 'space') {
			return !empty($resource_names) ? (string) $resource_names[0] : (string) __('Reserva de espacio', 'cie-lab-booking');
		}
		if ($booking_type === 'equipment') {
			return !empty($resource_names) ? (string) $resource_names[0] : (string) __('Reserva de equipo', 'cie-lab-booking');
		}
		return (string) __('Reserva', 'cie-lab-booking');
	}

	/**
	 * @param array<int,string> $resource_names
	 */
	private static function block_matches_resource_filter(bool $is_global, array $resource_names, string $filter_resource): bool {
		$filter_resource = trim($filter_resource);
		if ($filter_resource === '' || $filter_resource === 'all') {
			return $is_global;
		}
		if ($is_global) {
			return false;
		}
		return in_array($filter_resource, $resource_names, true);
	}

	/**
	 * @param array<int,\WP_Post> $bookings
	 * @return array<int,\WP_Post>
	 */
	private static function filter_bookings_by_admin_view(array $bookings, string $view): array {
		if ($view === 'all') {
			return $bookings;
		}

		$out = [];
		foreach ($bookings as $booking) {
			$status = (string) get_post_meta((int) $booking->ID, '_cie_booking_status', true);
			if ($view === 'approved' && $status === Post_Types::BOOKING_STATUS_APPROVED) {
				$out[] = $booking;
				continue;
			}
			if ($view === 'pending' && in_array($status, [Post_Types::BOOKING_STATUS_PENDING, Post_Types::BOOKING_STATUS_CHANGES], true)) {
				$out[] = $booking;
			}
		}

		return $out;
	}
}

