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

		// Calendar hover / click details (front + admin).
		add_action('wp_ajax_cie_lab_booking_day_details', [self::class, 'day_details']);
		add_action('wp_ajax_nopriv_cie_lab_booking_day_details', [self::class, 'day_details']);
		add_action('wp_ajax_cie_lab_booking_calendar_feed', [self::class, 'calendar_feed']);
		add_action('wp_ajax_nopriv_cie_lab_booking_calendar_feed', [self::class, 'calendar_feed']);
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
		$user_id = get_current_user_id();
		$calendar_scope = sanitize_key((string) ($_POST['calendar_scope'] ?? 'general'));
		if (!in_array($calendar_scope, ['general', 'current_user'], true)) {
			$calendar_scope = 'general';
		}
		if ($calendar_scope === 'current_user' && !$user_id) {
			wp_send_json_error(['message' => __('Debes iniciar sesión para ver este calendario.', 'cie-lab-booking')], 403);
		}

		if ($calendar_scope === 'current_user' && $user_id) {
			$bookings = Bookings::get_overlapping_user_bookings_for_calendar($start, $end, (int) $user_id);
		} else {
			$bookings = Bookings::get_overlapping_bookings_for_calendar($start, $end, $is_admin);
		}

		$events = [];
		foreach ($bookings as $booking) {
			$booking_id = (int) $booking->ID;
			$status = (string) get_post_meta($booking_id, '_cie_booking_status', true);
			$spaces = (array) get_post_meta($booking_id, '_cie_booking_spaces', true);
			$equipment = (array) get_post_meta($booking_id, '_cie_booking_equipment', true);
			$resource_names = self::resource_names(array_merge($spaces, $equipment));
			$occurrences = Bookings::get_booking_occurrences($booking_id, $start, $end);
			$user = get_user_by('id', (int) $booking->post_author);

			foreach ($occurrences as $index => $occ) {
				$full_day = !empty($occ['full_day']);
				$events[] = [
					'id' => 'booking-' . $booking_id . '-' . $index,
					'type' => 'booking',
					'bookingId' => $booking_id,
					'date' => (string) $occ['date'],
					'start' => $full_day ? '' : (string) ($occ['start'] ?? ''),
					'end' => $full_day ? '' : (string) ($occ['end'] ?? ''),
					'fullDay' => $full_day,
					'title' => !empty($resource_names) ? $resource_names[0] : (string) __('Reserva', 'cie-lab-booking'),
					'resources' => $resource_names,
					'status' => $status,
					'statusSlug' => self::status_slug($status),
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
		$calendar_scope = sanitize_key((string) ($_POST['calendar_scope'] ?? 'general'));
		if (!in_array($calendar_scope, ['general', 'current_user'], true)) {
			$calendar_scope = 'general';
		}
		if ($calendar_scope === 'current_user' && !$user_id) {
			wp_send_json_error(['message' => __('Debes iniciar sesión para ver este calendario.', 'cie-lab-booking')], 403);
		}

		if ($calendar_scope === 'current_user' && $user_id) {
			$bookings = Bookings::get_overlapping_user_bookings_for_calendar($start, $end, (int) $user_id);
		} else {
			$bookings = Bookings::get_overlapping_bookings_for_calendar($start, $end, $is_admin);
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

			// By default: show minimal data.
			$item = [
				'id' => $bid,
				'start_date' => $bs,
				'end_date' => $be,
				'status' => $status,
				'statusSlug' => self::status_slug($status),
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
}

