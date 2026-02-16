<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Ajax {
	public static function init(): void {
		// Availability checks used by the booking form flow.
		add_action('wp_ajax_cie_lab_booking_availability', [self::class, 'availability']);

		// Calendar hover / click details (front + admin).
		add_action('wp_ajax_cie_lab_booking_day_details', [self::class, 'day_details']);
		add_action('wp_ajax_nopriv_cie_lab_booking_day_details', [self::class, 'day_details']);
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

		$bookings = Bookings::get_overlapping_bookings_for_calendar($start, $end, $is_admin);
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
				'spaces' => [],
				'equipment' => [],
				'user' => null,
				'project' => null,
				'detailUrl' => null,
			];

			$owns = $user_id && ((int) $b->post_author === (int) $user_id);

			$can_see_resource_names = $is_admin || $can_book;
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
			],
		]);
	}
}

