<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Bookings {
	public const OPTION_EQUIPMENT_GROUP_LABELS = 'cie_lab_booking_equipment_group_labels';

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
		if ((!$start || !$end) && !empty($raw['booking_range'])) {
			[$range_start, $range_end] = self::parse_date_range_input((string) $raw['booking_range']);
			$start = $start ?: $range_start;
			$end = $end ?: $range_end;
		}
		if (!$start || !$end) {
			$errors[] = __('Seleccione fechas válidas para la reserva.', 'cie-lab-booking');
		} elseif ($end < $start) {
			$errors[] = __('La fecha "hasta" no puede ser anterior a la fecha "desde".', 'cie-lab-booking');
		} else {
			$today = gmdate('Y-m-d');
			$max_date = gmdate('Y-m-d', strtotime('+3 months', strtotime($today . ' 00:00:00')));
			if ($start < $today) {
				$errors[] = __('La reserva solo permite fechas desde hoy.', 'cie-lab-booking');
			}
			if ($end > $max_date) {
				$errors[] = __('La reserva solo permite fechas dentro de los próximos 3 meses.', 'cie-lab-booking');
			}
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

	public static function get_resource_quantity(int $resource_id): int {
		$quantity = (int) get_post_meta($resource_id, '_cie_resource_quantity', true);
		return $quantity > 0 ? $quantity : 1;
	}

	/**
	 * @return array<int>
	 */
	public static function get_equipment_required_ids(int $equipment_id): array {
		$required = array_values(array_filter(array_map('intval', (array) get_post_meta($equipment_id, '_cie_resource_required_equipment', true))));
		$required = array_values(array_diff($required, [$equipment_id]));
		if (!$required) {
			return [];
		}

		$valid = [];
		foreach ($required as $rid) {
			$p = get_post($rid);
			if ($p && $p->post_type === Post_Types::CPT_RESOURCE) {
				$kind = (string) get_post_meta((int) $p->ID, '_cie_resource_kind', true);
				if ($kind === 'equipment') {
					$valid[] = (int) $p->ID;
				}
			}
		}

		return array_values(array_unique($valid));
	}

	/**
	 * @return array<int,string>
	 */
	public static function get_equipment_groups(): array {
		return array_keys(self::get_equipment_groups_map());
	}

	/**
	 * @return array<string,string> slug => label
	 */
	public static function get_equipment_groups_map(): array {
		$raw = get_option(self::OPTION_EQUIPMENT_GROUP_LABELS, []);
		$map = [];

		if (is_array($raw)) {
			foreach ($raw as $slug => $label) {
				$group_slug = sanitize_key((string) $slug);
				if ($group_slug === '') {
					continue;
				}
				$group_label = sanitize_text_field((string) $label);
				$map[$group_slug] = $group_label !== '' ? $group_label : self::humanize_equipment_group_slug($group_slug);
			}
		}

		foreach (self::get_resources('equipment', false) as $resource) {
			$group = sanitize_key((string) get_post_meta((int) $resource->ID, '_cie_resource_group', true));
			if ($group === '') {
				$group = 'other';
			}
			if (!isset($map[$group])) {
				$map[$group] = self::humanize_equipment_group_slug($group);
			}
		}

		if (!isset($map['other'])) {
			$map['other'] = (string) __('Otros', 'cie-lab-booking');
		}

		uasort(
			$map,
			static function (string $a, string $b): int {
				return strcasecmp($a, $b);
			}
		);

		return $map;
	}

	public static function get_equipment_group_label(string $group): string {
		$group = sanitize_key($group);
		if ($group === '') {
			$group = 'other';
		}

		$map = self::get_equipment_groups_map();
		if (isset($map[$group]) && trim((string) $map[$group]) !== '') {
			return (string) $map[$group];
		}

		return self::humanize_equipment_group_slug($group);
	}

	public static function upsert_equipment_group_label(string $group_slug, string $label): void {
		$group_slug = sanitize_key($group_slug);
		if ($group_slug === '') {
			return;
		}

		$label = sanitize_text_field($label);
		if ($label === '') {
			$label = self::humanize_equipment_group_slug($group_slug);
		}

		$map = self::get_equipment_groups_map();
		$map[$group_slug] = $label;
		update_option(self::OPTION_EQUIPMENT_GROUP_LABELS, $map, false);
	}

	public static function delete_equipment_group(string $group_slug, string $replacement_slug = 'other'): void {
		$group_slug = sanitize_key($group_slug);
		$replacement_slug = sanitize_key($replacement_slug);
		if ($group_slug === '' || $group_slug === 'other') {
			return;
		}
		if ($replacement_slug === '') {
			$replacement_slug = 'other';
		}

		$equipment = self::get_resources('equipment', false);
		foreach ($equipment as $resource) {
			$current = sanitize_key((string) get_post_meta((int) $resource->ID, '_cie_resource_group', true));
			if ($current === $group_slug) {
				update_post_meta((int) $resource->ID, '_cie_resource_group', $replacement_slug);
			}
		}

		$map = self::get_equipment_groups_map();
		unset($map[$group_slug]);
		if (!isset($map[$replacement_slug])) {
			$map[$replacement_slug] = self::humanize_equipment_group_slug($replacement_slug);
		}
		update_option(self::OPTION_EQUIPMENT_GROUP_LABELS, $map, false);
	}

	/**
	 * @return array<string,int> slug => resource count
	 */
	public static function get_equipment_group_resource_counts(): array {
		$counts = [];
		foreach (self::get_resources('equipment', false) as $resource) {
			$group = sanitize_key((string) get_post_meta((int) $resource->ID, '_cie_resource_group', true));
			if ($group === '') {
				$group = 'other';
			}
			$counts[$group] = (int) ($counts[$group] ?? 0) + 1;
		}

		return $counts;
	}

	private static function humanize_equipment_group_slug(string $group): string {
		$group = sanitize_key($group);
		$map = [
			'recording' => __('Equipos de grabación', 'cie-lab-booking'),
			'phonetics' => __('Equipos de análisis fonético', 'cie-lab-booking'),
			'eye-tracker' => __('Equipos de eye-tracker', 'cie-lab-booking'),
			'eeg' => __('Equipos de EEG', 'cie-lab-booking'),
			'other' => __('Otros', 'cie-lab-booking'),
		];
		if (isset($map[$group])) {
			return (string) $map[$group];
		}

		return ucwords(str_replace('-', ' ', $group));
	}

	/**
	 * @return array<int,array{
	 *   booking_id:int,
	 *   start_date:string,
	 *   end_date:string,
	 *   status:string,
	 *   user_id:int,
	 *   user_name:string,
	 *   user_email:string,
	 *   detail_url:string
	 * }>
	 */
	public static function get_resource_active_booking_items(int $resource_id, ?string $date = null): array {
		$date = $date ?: gmdate('Y-m-d');
		$bookings = self::get_overlapping_approved_bookings($date, $date);
		$items = [];
		foreach ($bookings as $booking) {
			$spaces = array_map('intval', (array) get_post_meta((int) $booking->ID, '_cie_booking_spaces', true));
			$equipment = array_map('intval', (array) get_post_meta((int) $booking->ID, '_cie_booking_equipment', true));
			if (!in_array($resource_id, array_merge($spaces, $equipment), true)) {
				continue;
			}

			$user = get_user_by('id', (int) $booking->post_author);
			$items[] = [
				'booking_id' => (int) $booking->ID,
				'start_date' => (string) get_post_meta((int) $booking->ID, '_cie_booking_start_date', true),
				'end_date' => (string) get_post_meta((int) $booking->ID, '_cie_booking_end_date', true),
				'status' => (string) get_post_meta((int) $booking->ID, '_cie_booking_status', true),
				'user_id' => (int) $booking->post_author,
				'user_name' => $user ? (string) $user->display_name : (string) __('Usuario', 'cie-lab-booking'),
				'user_email' => $user ? (string) $user->user_email : '',
				'detail_url' => admin_url('admin.php?page=cie-lab-booking-booking&booking_id=' . (int) $booking->ID),
			];
		}

		return $items;
	}

	/**
	 * @return array<int,array{
	 *   booking_id:int,
	 *   start_date:string,
	 *   end_date:string,
	 *   status:string,
	 *   submitted_at:string,
	 *   user_id:int,
	 *   user_name:string,
	 *   user_email:string,
	 *   detail_url:string,
	 *   is_active:bool
	 * }>
	 */
	public static function get_resource_booking_history(int $resource_id, int $limit = 200): array {
		$needle = 'i:' . (int) $resource_id . ';';
		$posts = get_posts([
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'posts_per_page' => $limit,
			'orderby' => 'date',
			'order' => 'DESC',
			'meta_query' => [
				'relation' => 'OR',
				[
					'key' => '_cie_booking_spaces',
					'value' => $needle,
					'compare' => 'LIKE',
				],
				[
					'key' => '_cie_booking_equipment',
					'value' => $needle,
					'compare' => 'LIKE',
				],
			],
		]);
		$posts = is_array($posts) ? $posts : [];

		$today = gmdate('Y-m-d');
		$history = [];
		foreach ($posts as $booking) {
			$user = get_user_by('id', (int) $booking->post_author);
			$status = (string) get_post_meta((int) $booking->ID, '_cie_booking_status', true);
			$start = (string) get_post_meta((int) $booking->ID, '_cie_booking_start_date', true);
			$end = (string) get_post_meta((int) $booking->ID, '_cie_booking_end_date', true);
			$is_active = $status === Post_Types::BOOKING_STATUS_APPROVED && $start !== '' && $end !== '' && $start <= $today && $today <= $end;

			$history[] = [
				'booking_id' => (int) $booking->ID,
				'start_date' => $start,
				'end_date' => $end,
				'status' => $status,
				'submitted_at' => (string) $booking->post_date,
				'user_id' => (int) $booking->post_author,
				'user_name' => $user ? (string) $user->display_name : (string) __('Usuario', 'cie-lab-booking'),
				'user_email' => $user ? (string) $user->user_email : '',
				'detail_url' => admin_url('admin.php?page=cie-lab-booking-booking&booking_id=' . (int) $booking->ID),
				'is_active' => $is_active,
			];
		}

		return $history;
	}

	/**
	 * @return array{0:?string,1:?string}
	 */
	private static function parse_date_range_input(string $range): array {
		$range = trim($range);
		if ($range === '') {
			return [null, null];
		}

		$parts = preg_split('/\s+to\s+/i', $range);
		if (!$parts || empty($parts[0])) {
			return [null, null];
		}

		$start = Util::normalize_date_ymd((string) $parts[0]);
		$end = isset($parts[1]) ? Util::normalize_date_ymd((string) $parts[1]) : $start;
		if (!$start || !$end) {
			return [null, null];
		}

		return [$start, $end];
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

		$equipment_ids = array_values(array_unique(array_filter(array_map('intval', $equipment_ids))));
		$selected = array_fill_keys($equipment_ids, true);
		$queue = $equipment_ids;
		$safety = 0;
		while (!empty($queue) && $safety < 500) {
			$safety++;
			$current_id = (int) array_shift($queue);
			foreach (self::get_equipment_required_ids($current_id) as $req_id) {
				if (!isset($selected[$req_id])) {
					$selected[$req_id] = true;
					$queue[] = $req_id;
				}
			}
		}
		$equipment_ids = array_values(array_map('intval', array_keys($selected)));

		// Legacy title-based dependencies kept for backward compatibility.
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
	public static function find_conflicts(string $start_date, string $end_date, array $space_ids, array $equipment_ids, int $exclude_booking_id = 0): array {
		$space_ids = array_values(array_unique(array_filter(array_map('intval', $space_ids))));
		$equipment_ids = array_values(array_unique(array_filter(array_map('intval', $equipment_ids))));

		$reserved_space_counts = [];
		$reserved_equipment_counts = [];

		$approved = self::get_overlapping_approved_bookings($start_date, $end_date);
		foreach ($approved as $booking) {
			if ($exclude_booking_id > 0 && (int) $booking->ID === $exclude_booking_id) {
				continue;
			}

			foreach ((array) get_post_meta((int) $booking->ID, '_cie_booking_spaces', true) as $rid) {
				$rid = (int) $rid;
				if ($rid > 0) {
					$reserved_space_counts[$rid] = (int) ($reserved_space_counts[$rid] ?? 0) + 1;
				}
			}

			foreach ((array) get_post_meta((int) $booking->ID, '_cie_booking_equipment', true) as $rid) {
				$rid = (int) $rid;
				if ($rid > 0) {
					$reserved_equipment_counts[$rid] = (int) ($reserved_equipment_counts[$rid] ?? 0) + 1;
				}
			}
		}

		$conflicting_space_ids = [];
		foreach ($space_ids as $rid) {
			$quantity = self::get_resource_quantity($rid);
			$reserved = (int) ($reserved_space_counts[$rid] ?? 0);
			if ($reserved >= $quantity) {
				$conflicting_space_ids[] = $rid;
			}
		}

		$conflicting_equipment_ids = [];
		foreach ($equipment_ids as $rid) {
			$quantity = self::get_resource_quantity($rid);
			$reserved = (int) ($reserved_equipment_counts[$rid] ?? 0);
			if ($reserved >= $quantity) {
				$conflicting_equipment_ids[] = $rid;
			}
		}

		$blocked = self::has_block_in_range($start_date, $end_date, array_merge($space_ids, $equipment_ids));

		return [
			'spaces' => $conflicting_space_ids,
			'equipment' => $conflicting_equipment_ids,
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
	 * Build per-day state map from current user reservations + maintenance blocks.
	 *
	 * @return array<string, array{has_space:bool,has_equipment:bool,blocked:bool}>
	 */
	public static function build_day_map_for_user(string $start, string $end, int $user_id): array {
		$user_bookings = self::get_overlapping_user_bookings_for_calendar($start, $end, $user_id);
		$blocks = self::get_overlapping_blocks($start, $end);

		$map = [];
		$cursor = strtotime($start . ' 00:00:00');
		$end_ts = strtotime($end . ' 00:00:00');
		while ($cursor <= $end_ts) {
			$key = gmdate('Y-m-d', $cursor);
			$map[$key] = ['has_space' => false, 'has_equipment' => false, 'blocked' => false];
			$cursor = strtotime('+1 day', $cursor);
		}

		foreach ($user_bookings as $b) {
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
	 * @return array{
	 *   blocked:bool,
	 *   spaces:array<int,bool>,
	 *   equipment:array<int,bool>,
	 *   space_stats:array<int,array{quantity:int,reserved:int,available:int}>,
	 *   equipment_stats:array<int,array{quantity:int,reserved:int,available:int}>
	 * }
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
					$reserved_space[$rid] = (int) ($reserved_space[$rid] ?? 0) + 1;
				}
			}
			foreach ((array) get_post_meta($booking->ID, '_cie_booking_equipment', true) as $rid) {
				$rid = (int) $rid;
				if ($rid) {
					$reserved_equipment[$rid] = (int) ($reserved_equipment[$rid] ?? 0) + 1;
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
		$space_stats = [];
		foreach ($space_ids as $id) {
			$is_blocked = $blocked_all || isset($blocked_resources[$id]);
			$quantity = self::get_resource_quantity($id);
			$reserved = (int) ($reserved_space[$id] ?? 0);
			$available_units = max($quantity - $reserved, 0);
			$space_map[$id] = !$is_blocked && $available_units > 0;
			$space_stats[$id] = [
				'quantity' => $quantity,
				'reserved' => $reserved,
				'available' => $is_blocked ? 0 : $available_units,
			];
		}

		$equipment_map = [];
		$equipment_stats = [];
		foreach ($equipment_ids as $id) {
			$is_blocked = $blocked_all || isset($blocked_resources[$id]);
			$quantity = self::get_resource_quantity($id);
			$reserved = (int) ($reserved_equipment[$id] ?? 0);
			$available_units = max($quantity - $reserved, 0);
			$equipment_map[$id] = !$is_blocked && $available_units > 0;
			$equipment_stats[$id] = [
				'quantity' => $quantity,
				'reserved' => $reserved,
				'available' => $is_blocked ? 0 : $available_units,
			];
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
			'space_stats' => $space_stats,
			'equipment_stats' => $equipment_stats,
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

	/**
	 * @return array<int,\WP_Post>
	 */
	public static function get_overlapping_user_bookings_for_calendar(string $start_date, string $end_date, int $user_id): array {
		$posts = get_posts([
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
			'author' => $user_id,
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
					'value' => [Post_Types::BOOKING_STATUS_CANCELLED, Post_Types::BOOKING_STATUS_REJECTED],
					'compare' => 'NOT IN',
				],
			],
		]);

		return is_array($posts) ? $posts : [];
	}
}

