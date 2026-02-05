<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Bookings {
	/**
	 * Equipment dependency rules from the flow annex.
	 *
	 * NOTE: This is intentionally minimal and manual, matching the described constraints.
	 *
	 * @return array<string, array<string>> Map of "needle title contains" => required "needle title contains".
	 */
	public static function equipment_dependency_rules(): array {
		return [
			// Tobii Pro Fusion 250 <-> portátil realización de experimentos.
			'tobii pro fusion 250' => ['portátil tobii pro fusion 250: realización de experimentos'],
			'portátil tobii pro fusion 250: realización de experimentos' => ['tobii pro fusion 250'],
			// Tobii Pro Spectrum 1200 -> ordenador sobremesa realización de experimentos.
			'tobii pro spectrum 1200' => ['ordenador sobremesa tobii pro spectrum 1200: realización de experimentos'],
		];
	}

	/**
	 * @return array<int,\WP_Post> Resources of a given kind.
	 */
	public static function get_resources(string $kind, bool $only_available = true): array {
		$args = [
			'post_type' => Post_Types::CPT_RESOURCE,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
			'meta_query' => [
				[
					'key' => '_cie_resource_kind',
					'value' => $kind,
				],
			],
		];

		if ($only_available) {
			$args['meta_query'][] = [
				'key' => '_cie_resource_available',
				'value' => '1',
			];
		}

		$posts = get_posts($args);
		return is_array($posts) ? $posts : [];
	}

	/**
	 * @return array<string, array<int,\WP_Post>> group => posts
	 */
	public static function get_equipment_grouped(bool $only_available = true): array {
		$equipment = self::get_resources('equipment', $only_available);
		$grouped = [];
		foreach ($equipment as $post) {
			$group = (string) get_post_meta($post->ID, '_cie_resource_group', true);
			if ($group === '') {
				$group = 'other';
			}
			if (!isset($grouped[$group])) {
				$grouped[$group] = [];
			}
			$grouped[$group][] = $post;
		}
		return $grouped;
	}

	/**
	 * Validate and normalize booking request following flow 2.9.
	 *
	 * @return array{ok:bool,errors:array<string>,data?:array<string,mixed>}
	 */
	public static function validate_booking_request(array $raw): array {
		$errors = [];

		$start = isset($raw['start_date']) ? Util::normalize_date_ymd((string) $raw['start_date']) : null;
		$end = isset($raw['end_date']) ? Util::normalize_date_ymd((string) $raw['end_date']) : null;
		if (!$start || !$end) {
			$errors[] = __('Seleccione fechas válidas para la reserva.', 'cie-lab-booking');
		} elseif ($end < $start) {
			$errors[] = __('La fecha "hasta" no puede ser anterior a la fecha "desde".', 'cie-lab-booking');
		}

		$use_space = !empty($raw['use_space']);
		$use_equipment = !empty($raw['use_equipment']);
		if (!$use_space && !$use_equipment) {
			$errors[] = __('Seleccione el tipo de instalación que quiere usar (espacios y/o equipos).', 'cie-lab-booking');
		}

		$spaces = array_values(array_filter(array_map('intval', (array) ($raw['spaces'] ?? []))));
		$equipment = array_values(array_filter(array_map('intval', (array) ($raw['equipment'] ?? []))));

		if ($use_space && empty($spaces)) {
			$errors[] = __('Seleccione qué espacio quiere reservar (cabina y/o laboratorio).', 'cie-lab-booking');
		}
		if ($use_equipment && empty($equipment)) {
			$errors[] = __('Seleccione los equipos que quiere reservar.', 'cie-lab-booking');
		}

		$has_courses = (string) ($raw['has_courses'] ?? '');
		if ($has_courses !== 'yes') {
			$errors[] = __('Antes de usar los equipos/espacios, tiene que realizar los cursos de formación. Acceda a su perfil en la intranet y solicite los cursos correspondientes.', 'cie-lab-booking');
		}

		$project_name = trim((string) ($raw['project_name'] ?? ''));
		$project_duration = trim((string) ($raw['project_duration'] ?? ''));
		$project_responsible = trim((string) ($raw['project_responsible'] ?? ''));
		$project_ip_email = trim((string) ($raw['project_ip_email'] ?? ''));

		if ($project_name === '' || $project_duration === '' || $project_responsible === '' || $project_ip_email === '') {
			$errors[] = __('Rellene todos los datos del proyecto (obligatorios).', 'cie-lab-booking');
		} elseif (!is_email($project_ip_email)) {
			$errors[] = __('El correo electrónico del IP/Director/a no es válido.', 'cie-lab-booking');
		}

		// Apply equipment dependency rules (server-side).
		$equipment = self::apply_equipment_dependencies($equipment, $errors);

		if (!$start || !$end) {
			return ['ok' => false, 'errors' => $errors];
		}

		// Availability checks per flow 2.9 (based on calendar of validated bookings + blocks).
		$conflicts = self::find_conflicts($start, $end, $spaces, $equipment);
		if (!empty($conflicts['spaces'])) {
			$errors[] = __('En las fechas seleccionadas los espacios del laboratorio están reservados. Seleccione otras fechas de reserva.', 'cie-lab-booking');
		}
		if (!empty($conflicts['equipment'])) {
			$errors[] = __('En las fechas seleccionadas los equipos del laboratorio seleccionados están reservados. Seleccione otras fechas de reserva.', 'cie-lab-booking');
		}
		if (!empty($conflicts['blocked'])) {
			$errors[] = __('En las fechas seleccionadas hay días no disponibles por mantenimiento. Seleccione otras fechas de reserva.', 'cie-lab-booking');
		}

		if ($errors) {
			return ['ok' => false, 'errors' => $errors];
		}

		return [
			'ok' => true,
			'errors' => [],
			'data' => [
				'start_date' => $start,
				'end_date' => $end,
				'spaces' => $spaces,
				'equipment' => $equipment,
				'project_name' => $project_name,
				'project_duration' => $project_duration,
				'project_responsible' => $project_responsible,
				'project_ip_email' => $project_ip_email,
			],
		];
	}

	/**
	 * @param array<int> $equipment_ids
	 * @param array<string> &$errors
	 * @return array<int>
	 */
	private static function apply_equipment_dependencies(array $equipment_ids, array &$errors): array {
		if (empty($equipment_ids)) {
			return $equipment_ids;
		}

		$rules = self::equipment_dependency_rules();
		if (empty($rules)) {
			return $equipment_ids;
		}

		$selected_titles = [];
		foreach ($equipment_ids as $id) {
			$p = get_post($id);
			if ($p && $p->post_type === Post_Types::CPT_RESOURCE) {
				$selected_titles[$id] = Util::lc($p->post_title);
			}
		}

		$required_to_add = [];
		foreach ($selected_titles as $id => $title_lc) {
			foreach ($rules as $needle => $required_needles) {
				if (!Util::contains($title_lc, $needle)) {
					continue;
				}

				foreach ($required_needles as $req_needle) {
					$req_id = self::find_equipment_id_by_title_contains($req_needle);
					if ($req_id && !in_array($req_id, $equipment_ids, true)) {
						$required_to_add[] = $req_id;
						$errors[] = sprintf(
							/* translators: %s: required equipment name */
							__('Debe añadir a su reserva la opción: %s', 'cie-lab-booking'),
							$req_needle
						);
					}
				}
			}
		}

		if (!empty($required_to_add)) {
			$equipment_ids = array_values(array_unique(array_merge($equipment_ids, $required_to_add)));
		}

		return $equipment_ids;
	}

	private static function find_equipment_id_by_title_contains(string $needle): int {
		$needle = Util::lc($needle);
		$equipment = self::get_resources('equipment', false);
		foreach ($equipment as $p) {
			if (Util::contains(Util::lc($p->post_title), $needle)) {
				return (int) $p->ID;
			}
		}
		return 0;
	}

	/**
	 * Create booking post + meta.
	 *
	 * @param array{
	 *   start_date:string,
	 *   end_date:string,
	 *   spaces:array<int>,
	 *   equipment:array<int>,
	 *   project_name:string,
	 *   project_duration:string,
	 *   project_responsible:string,
	 *   project_ip_email:string
	 * } $data
	 *
	 * @return int|\WP_Error Booking post ID.
	 */
	public static function create_booking(int $user_id, array $data) {
		$title = sprintf(
			/* translators: %d: user id */
			__('Reserva %d', 'cie-lab-booking'),
			time()
		);

		$post_id = wp_insert_post([
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'post_title' => $title,
			'post_author' => $user_id,
		]);

		if (is_wp_error($post_id) || !$post_id) {
			return is_wp_error($post_id) ? $post_id : new \WP_Error('cie_booking_create_failed', 'Could not create booking');
		}

		update_post_meta($post_id, '_cie_booking_status', Post_Types::BOOKING_STATUS_PENDING);
		update_post_meta($post_id, '_cie_booking_start_date', $data['start_date']);
		update_post_meta($post_id, '_cie_booking_end_date', $data['end_date']);
		update_post_meta($post_id, '_cie_booking_spaces', array_values(array_map('intval', $data['spaces'])));
		update_post_meta($post_id, '_cie_booking_equipment', array_values(array_map('intval', $data['equipment'])));

		update_post_meta($post_id, '_cie_booking_project_name', $data['project_name']);
		update_post_meta($post_id, '_cie_booking_project_duration', $data['project_duration']);
		update_post_meta($post_id, '_cie_booking_project_responsible', $data['project_responsible']);
		update_post_meta($post_id, '_cie_booking_project_ip_email', $data['project_ip_email']);

		return (int) $post_id;
	}

	/**
	 * Update an existing booking owned by the user.
	 *
	 * @return int|\WP_Error Booking post ID.
	 */
	public static function update_booking(int $booking_id, int $user_id, array $data) {
		$booking = self::get_booking($booking_id);
		if (!$booking) {
			return new \WP_Error('cie_booking_not_found', 'Booking not found');
		}
		if ((int) $booking->post_author !== $user_id) {
			return new \WP_Error('cie_booking_forbidden', 'Not allowed');
		}

		$status = (string) get_post_meta($booking_id, '_cie_booking_status', true);
		if (!in_array($status, [Post_Types::BOOKING_STATUS_PENDING, Post_Types::BOOKING_STATUS_CHANGES], true)) {
			return new \WP_Error('cie_booking_not_editable', 'Booking not editable');
		}

		update_post_meta($booking_id, '_cie_booking_status', Post_Types::BOOKING_STATUS_PENDING);
		update_post_meta($booking_id, '_cie_booking_start_date', $data['start_date']);
		update_post_meta($booking_id, '_cie_booking_end_date', $data['end_date']);
		update_post_meta($booking_id, '_cie_booking_spaces', array_values(array_map('intval', $data['spaces'])));
		update_post_meta($booking_id, '_cie_booking_equipment', array_values(array_map('intval', $data['equipment'])));

		update_post_meta($booking_id, '_cie_booking_project_name', $data['project_name']);
		update_post_meta($booking_id, '_cie_booking_project_duration', $data['project_duration']);
		update_post_meta($booking_id, '_cie_booking_project_responsible', $data['project_responsible']);
		update_post_meta($booking_id, '_cie_booking_project_ip_email', $data['project_ip_email']);

		// Clear previous admin message after resubmission.
		delete_post_meta($booking_id, '_cie_booking_admin_message');

		return $booking_id;
	}

	public static function get_booking(int $booking_id): ?\WP_Post {
		$post = get_post($booking_id);
		if (!$post || $post->post_type !== Post_Types::CPT_BOOKING) {
			return null;
		}
		return $post;
	}

	/**
	 * @return array{spaces:array<int>,equipment:array<int>,blocked:bool}
	 */
	public static function find_conflicts(string $start_date, string $end_date, array $space_ids, array $equipment_ids): array {
		$conflicting_space_ids = [];
		$conflicting_equipment_ids = [];

		$approved = self::get_overlapping_approved_bookings($start_date, $end_date);
		foreach ($approved as $booking) {
			$b_spaces = (array) get_post_meta($booking->ID, '_cie_booking_spaces', true);
			$b_equipment = (array) get_post_meta($booking->ID, '_cie_booking_equipment', true);

			if ($space_ids && $b_spaces) {
				$intersection = array_values(array_intersect(array_map('intval', $space_ids), array_map('intval', $b_spaces)));
				$conflicting_space_ids = array_merge($conflicting_space_ids, $intersection);
			}

			if ($equipment_ids && $b_equipment) {
				$intersection = array_values(array_intersect(array_map('intval', $equipment_ids), array_map('intval', $b_equipment)));
				$conflicting_equipment_ids = array_merge($conflicting_equipment_ids, $intersection);
			}
		}

		$blocked = self::has_block_in_range($start_date, $end_date, array_merge($space_ids, $equipment_ids));

		return [
			'spaces' => array_values(array_unique(array_filter(array_map('intval', $conflicting_space_ids)))),
			'equipment' => array_values(array_unique(array_filter(array_map('intval', $conflicting_equipment_ids)))),
			'blocked' => $blocked,
		];
	}

	/**
	 * @return array<int,\WP_Post>
	 */
	public static function get_overlapping_approved_bookings(string $start_date, string $end_date): array {
		$posts = get_posts([
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
			'meta_query' => [
				[
					'key' => '_cie_booking_status',
					'value' => Post_Types::BOOKING_STATUS_APPROVED,
				],
				[
					'key' => '_cie_booking_start_date',
					'value' => $end_date,
					'compare' => '<=',
					'type' => 'DATE',
				],
				[
					'key' => '_cie_booking_end_date',
					'value' => $start_date,
					'compare' => '>=',
					'type' => 'DATE',
				],
			],
		]);

		return is_array($posts) ? $posts : [];
	}

	/**
	 * @return array<int,\WP_Post>
	 */
	public static function get_overlapping_blocks(string $start_date, string $end_date): array {
		$posts = get_posts([
			'post_type' => Post_Types::CPT_BLOCK,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
			'meta_query' => [
				[
					'key' => '_cie_block_start_date',
					'value' => $end_date,
					'compare' => '<=',
					'type' => 'DATE',
				],
				[
					'key' => '_cie_block_end_date',
					'value' => $start_date,
					'compare' => '>=',
					'type' => 'DATE',
				],
			],
		]);

		return is_array($posts) ? $posts : [];
	}

	/**
	 * If $resource_ids is empty => any block counts.
	 */
	public static function has_block_in_range(string $start_date, string $end_date, array $resource_ids = []): bool {
		$resource_ids = array_values(array_filter(array_map('intval', $resource_ids)));
		$blocks = self::get_overlapping_blocks($start_date, $end_date);
		if (!$blocks) {
			return false;
		}

		if (!$resource_ids) {
			return true;
		}

		foreach ($blocks as $block) {
			$blocked_ids = (array) get_post_meta($block->ID, '_cie_block_resource_ids', true);
			$blocked_ids = array_values(array_filter(array_map('intval', $blocked_ids)));
			if (!$blocked_ids) {
				return true; // Global block.
			}
			if (array_intersect($resource_ids, $blocked_ids)) {
				return true;
			}
		}

		return false;
	}

	public static function set_booking_status(int $booking_id, string $status, string $admin_message = ''): void {
		update_post_meta($booking_id, '_cie_booking_status', $status);
		if ($admin_message !== '') {
			update_post_meta($booking_id, '_cie_booking_admin_message', $admin_message);
		}
	}

	/**
	 * Build per-day state map for calendar color logic.
	 *
	 * @return array<string, array{has_space:bool,has_equipment:bool,blocked:bool}>
	 */
	public static function build_day_map(string $start, string $end): array {
		$approved = self::get_overlapping_approved_bookings($start, $end);
		$blocks = self::get_overlapping_blocks($start, $end);

		$map = [];
		$cursor = strtotime($start . ' 00:00:00');
		$end_ts = strtotime($end . ' 00:00:00');
		while ($cursor <= $end_ts) {
			$key = gmdate('Y-m-d', $cursor);
			$map[$key] = ['has_space' => false, 'has_equipment' => false, 'blocked' => false];
			$cursor = strtotime('+1 day', $cursor);
		}

		foreach ($approved as $b) {
			$bs = (string) get_post_meta($b->ID, '_cie_booking_start_date', true);
			$be = (string) get_post_meta($b->ID, '_cie_booking_end_date', true);
			$has_space = !empty((array) get_post_meta($b->ID, '_cie_booking_spaces', true));
			$has_eq = !empty((array) get_post_meta($b->ID, '_cie_booking_equipment', true));
			$cur = strtotime($bs . ' 00:00:00');
			$be_ts = strtotime($be . ' 00:00:00');
			while ($cur <= $be_ts) {
				$k = gmdate('Y-m-d', $cur);
				if (isset($map[$k])) {
					$map[$k]['has_space'] = $map[$k]['has_space'] || $has_space;
					$map[$k]['has_equipment'] = $map[$k]['has_equipment'] || $has_eq;
				}
				$cur = strtotime('+1 day', $cur);
			}
		}

		foreach ($blocks as $block) {
			$bs = (string) get_post_meta($block->ID, '_cie_block_start_date', true);
			$be = (string) get_post_meta($block->ID, '_cie_block_end_date', true);
			$cur = strtotime($bs . ' 00:00:00');
			$be_ts = strtotime($be . ' 00:00:00');
			while ($cur <= $be_ts) {
				$k = gmdate('Y-m-d', $cur);
				if (isset($map[$k])) {
					$map[$k]['blocked'] = true;
				}
				$cur = strtotime('+1 day', $cur);
			}
		}

		return $map;
	}

	/**
	 * Per-resource availability map for a date range.
	 *
	 * @param array<int> $space_ids
	 * @param array<int> $equipment_ids
	 * @return array{blocked:bool,spaces:array<int,bool>,equipment:array<int,bool>}
	 */
	public static function get_resources_availability(string $start, string $end, array $space_ids, array $equipment_ids): array {
		$space_ids = array_values(array_unique(array_filter(array_map('intval', $space_ids))));
		$equipment_ids = array_values(array_unique(array_filter(array_map('intval', $equipment_ids))));

		$reserved_space = [];
		$reserved_equipment = [];
		$approved = self::get_overlapping_approved_bookings($start, $end);
		foreach ($approved as $booking) {
			foreach ((array) get_post_meta($booking->ID, '_cie_booking_spaces', true) as $rid) {
				$rid = (int) $rid;
				if ($rid) {
					$reserved_space[$rid] = true;
				}
			}
			foreach ((array) get_post_meta($booking->ID, '_cie_booking_equipment', true) as $rid) {
				$rid = (int) $rid;
				if ($rid) {
					$reserved_equipment[$rid] = true;
				}
			}
		}

		$blocks = self::get_overlapping_blocks($start, $end);
		$blocked_all = false;
		$blocked_resources = [];
		foreach ($blocks as $block) {
			$ids = (array) get_post_meta($block->ID, '_cie_block_resource_ids', true);
			$ids = array_values(array_filter(array_map('intval', $ids)));
			if (!$ids) {
				$blocked_all = true;
				break;
			}
			foreach ($ids as $rid) {
				$blocked_resources[$rid] = true;
			}
		}

		$space_map = [];
		foreach ($space_ids as $id) {
			$is_blocked = $blocked_all || isset($blocked_resources[$id]);
			$space_map[$id] = !$is_blocked && !isset($reserved_space[$id]);
		}

		$equipment_map = [];
		foreach ($equipment_ids as $id) {
			$is_blocked = $blocked_all || isset($blocked_resources[$id]);
			$equipment_map[$id] = !$is_blocked && !isset($reserved_equipment[$id]);
		}

		// If there are any blocks and they intersect relevant resources, consider the range blocked.
		$any_blocked = false;
		if ($blocks) {
			if ($blocked_all) {
				$any_blocked = true;
			} else {
				foreach (array_merge($space_ids, $equipment_ids) as $rid) {
					if (isset($blocked_resources[(int) $rid])) {
						$any_blocked = true;
						break;
					}
				}
			}
		}

		return [
			'blocked' => $any_blocked,
			'spaces' => $space_map,
			'equipment' => $equipment_map,
		];
	}

	/**
	 * Bookings shown in calendar detail popups.
	 *
	 * Non-admin viewers only see approved bookings.
	 * Admin viewers see all non-cancelled bookings.
	 *
	 * @return array<int,\WP_Post>
	 */
	public static function get_overlapping_bookings_for_calendar(string $start_date, string $end_date, bool $include_all_statuses = false): array {
		if (!$include_all_statuses) {
			return self::get_overlapping_approved_bookings($start_date, $end_date);
		}

		$posts = get_posts([
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
			'meta_query' => [
				[
					'key' => '_cie_booking_start_date',
					'value' => $end_date,
					'compare' => '<=',
					'type' => 'DATE',
				],
				[
					'key' => '_cie_booking_end_date',
					'value' => $start_date,
					'compare' => '>=',
					'type' => 'DATE',
				],
				[
					'key' => '_cie_booking_status',
					'value' => Post_Types::BOOKING_STATUS_CANCELLED,
					'compare' => '!=',
				],
			],
		]);

		return is_array($posts) ? $posts : [];
	}
}

