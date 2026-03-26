<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Bookings {
	public const OPTION_EQUIPMENT_GROUP_LABELS = 'cie_lab_booking_equipment_group_labels';
	public const BOOKING_MODE_FULL_DAY = 'full_day';
	public const BOOKING_MODE_TIME_RANGE = 'time_range';
	public const BOOKING_FREQUENCY_SINGLE = 'single';
	public const BOOKING_FREQUENCY_WEEKLY_REPEAT = 'weekly_repeat';
	public const BOOKING_FREQUENCY_MANUAL_DATES = 'manual_dates';
	public const BOOKING_DAY_SCOPE_SINGLE = 'single_day';
	public const BOOKING_DAY_SCOPE_RANGE = 'date_range';
	public const BOOKING_DAY_SCOPE_MULTI = 'multiple_days';
	public const BOOKING_REPEAT_MODE_NONE = 'none';
	public const BOOKING_REPEAT_MODE_DAILY = 'daily';
	public const BOOKING_REPEAT_MODE_WEEKLY = 'weekly';
	public const BOOKING_REPEAT_MODE_BIWEEKLY = 'biweekly';
	public const BOOKING_TIME_MIN = '08:00';
	public const BOOKING_TIME_MAX = '20:00';
	public const OPTION_MAX_RECURRENCE_WEEKS = 'cie_lab_booking_max_recurrence_weeks';
	public const OPTION_MAX_RANGE_DAYS = 'cie_lab_booking_max_range_days';
	public const DEFAULT_MAX_RECURRENCE_WEEKS = 5;
	public const DEFAULT_MAX_RANGE_DAYS = 5;

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

	public static function get_max_recurrence_weeks(): int {
		$value = (int) get_option(self::OPTION_MAX_RECURRENCE_WEEKS, self::DEFAULT_MAX_RECURRENCE_WEEKS);
		if ($value < 1) {
			$value = self::DEFAULT_MAX_RECURRENCE_WEEKS;
		}
		return min($value, 52);
	}

	public static function get_max_range_days(): int {
		$value = (int) get_option(self::OPTION_MAX_RANGE_DAYS, self::DEFAULT_MAX_RANGE_DAYS);
		if ($value < 1) {
			$value = self::DEFAULT_MAX_RANGE_DAYS;
		}
		// Functional hard cap required by specification.
		return min($value, 5);
	}

	/**
	 * Validate and normalize booking request following flow 2.9.
	 *
	 * @return array{ok:bool,errors:array<string>,data?:array<string,mixed>}
	 */
	public static function validate_booking_request(array $raw): array {
		$errors = [];
		$mode = self::normalize_booking_mode((string) ($raw['booking_mode'] ?? self::BOOKING_MODE_FULL_DAY));
		$frequency = self::normalize_booking_frequency((string) ($raw['booking_frequency'] ?? self::BOOKING_FREQUENCY_SINGLE));
		$time_start = self::normalize_time_hm((string) ($raw['booking_time_start'] ?? ''));
		$time_end = self::normalize_time_hm((string) ($raw['booking_time_end'] ?? ''));

		$schedule_meta = [];
		$occurrences = self::build_occurrences_from_request($raw, $mode, $frequency, $time_start, $time_end, $errors, $schedule_meta);
		if (!$occurrences) {
			$errors[] = __('Seleccione fechas válidas para la reserva.', 'cie-lab-booking');
		}

		$start = '';
		$end = '';
		if ($occurrences) {
			$dates = array_map(
				static function (array $occ): string {
					return (string) ($occ['date'] ?? '');
				},
				$occurrences
			);
			sort($dates);
			$start = (string) reset($dates);
			$end = (string) end($dates);

			$today = gmdate('Y-m-d');
			$max_date = gmdate('Y-m-d', strtotime('+12 months', strtotime($today . ' 00:00:00')));
			if ($start < $today) {
				$errors[] = __('La reserva solo permite fechas desde hoy.', 'cie-lab-booking');
			}
			if ($end > $max_date) {
				$errors[] = __('La reserva solo permite fechas dentro de los próximos 12 meses.', 'cie-lab-booking');
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

		if (!$start || !$end || !$occurrences) {
			return ['ok' => false, 'errors' => $errors];
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
				'booking_mode' => $mode,
				'booking_frequency' => $frequency,
				'booking_day_scope' => (string) ($schedule_meta['day_scope'] ?? self::normalize_booking_day_scope((string) ($raw['booking_day_scope'] ?? self::BOOKING_DAY_SCOPE_SINGLE))),
				'booking_repeat_mode' => (string) ($schedule_meta['repeat_mode'] ?? self::BOOKING_REPEAT_MODE_NONE),
				'booking_time_start' => $time_start,
				'booking_time_end' => $time_end,
				'booking_time_slots' => self::serialize_time_slots(
					array_values(
						array_filter(
							array_map(
								static function (array $occ): array {
									return [
										'start' => (string) ($occ['start'] ?? ''),
										'end' => (string) ($occ['end'] ?? ''),
									];
								},
								array_values(
									array_filter(
										$occurrences,
										static function (array $occ): bool {
											return empty($occ['full_day']);
										}
									)
								)
							),
							static function (array $slot): bool {
								return $slot['start'] !== '' && $slot['end'] !== '';
							}
						)
					)
				),
				'booking_recurrence_weeks' => (int) ($schedule_meta['recurrence_weeks'] ?? max(1, min(self::get_max_recurrence_weeks(), (int) ($raw['booking_recurrence_weeks'] ?? 1)))),
				'booking_weekdays' => self::normalize_weekdays((array) ($raw['booking_weekdays'] ?? [])),
				'booking_selected_dates' => isset($schedule_meta['selected_dates']) && is_array($schedule_meta['selected_dates'])
					? self::normalize_date_list((array) $schedule_meta['selected_dates'])
					: self::extract_selected_dates_from_raw($raw),
				'occurrences' => $occurrences,
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
			if (!self::booking_has_occurrence_on_date((int) $booking->ID, (string) $date)) {
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
			$is_active = $status === Post_Types::BOOKING_STATUS_APPROVED && self::booking_has_occurrence_on_date((int) $booking->ID, $today);

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

	private static function normalize_booking_mode(string $mode): string {
		$mode = sanitize_key($mode);
		if (!in_array($mode, [self::BOOKING_MODE_FULL_DAY, self::BOOKING_MODE_TIME_RANGE], true)) {
			return self::BOOKING_MODE_FULL_DAY;
		}
		return $mode;
	}

	private static function normalize_booking_frequency(string $frequency): string {
		$frequency = sanitize_key($frequency);
		if (!in_array($frequency, [self::BOOKING_FREQUENCY_SINGLE, self::BOOKING_FREQUENCY_WEEKLY_REPEAT, self::BOOKING_FREQUENCY_MANUAL_DATES], true)) {
			return self::BOOKING_FREQUENCY_SINGLE;
		}
		return $frequency;
	}

	private static function normalize_booking_day_scope(string $scope): string {
		$scope = sanitize_key($scope);
		if (!in_array($scope, [self::BOOKING_DAY_SCOPE_SINGLE, self::BOOKING_DAY_SCOPE_RANGE, self::BOOKING_DAY_SCOPE_MULTI], true)) {
			return self::BOOKING_DAY_SCOPE_SINGLE;
		}
		return $scope;
	}

	private static function normalize_booking_repeat_mode(string $mode): string {
		$mode = sanitize_key($mode);
		if (!in_array($mode, [self::BOOKING_REPEAT_MODE_NONE, self::BOOKING_REPEAT_MODE_DAILY, self::BOOKING_REPEAT_MODE_WEEKLY, self::BOOKING_REPEAT_MODE_BIWEEKLY], true)) {
			return self::BOOKING_REPEAT_MODE_NONE;
		}
		return $mode;
	}

	/**
	 * Public helper used by UI layers.
	 */
	public static function normalize_repeat_mode_value(string $mode): string {
		return self::normalize_booking_repeat_mode($mode);
	}

	private static function normalize_time_hm(string $time): ?string {
		$time = trim($time);
		if (!preg_match('/^\d{2}\:\d{2}$/', $time)) {
			return null;
		}
		[$hour, $minute] = array_map('intval', explode(':', $time));
		if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
			return null;
		}
		return sprintf('%02d:%02d', $hour, $minute);
	}

	private static function time_to_minutes(string $time): int {
		$normalized = self::normalize_time_hm($time);
		if (!$normalized) {
			return -1;
		}
		[$h, $m] = array_map('intval', explode(':', $normalized));
		return ($h * 60) + $m;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @param array<string> &$errors
	 * @return array<int,array{start:string,end:string}>
	 */
	private static function extract_time_slots_from_request(array $raw, ?string $fallback_start, ?string $fallback_end, array &$errors): array {
		$min_minutes = self::time_to_minutes(self::BOOKING_TIME_MIN);
		$max_minutes = self::time_to_minutes(self::BOOKING_TIME_MAX);
		$slots = [];

		foreach ((array) ($raw['booking_time_slots'] ?? []) as $raw_slot) {
			$slot = trim((string) $raw_slot);
			if ($slot === '') {
				continue;
			}
			if (!preg_match('/^(\d{2}\:\d{2})\-(\d{2}\:\d{2})$/', $slot, $matches)) {
				continue;
			}
			$slot_start = self::normalize_time_hm((string) $matches[1]);
			$slot_end = self::normalize_time_hm((string) $matches[2]);
			if (!$slot_start || !$slot_end) {
				continue;
			}
			$start_minutes = self::time_to_minutes($slot_start);
			$end_minutes = self::time_to_minutes($slot_end);
			if ($start_minutes < $min_minutes || $end_minutes > $max_minutes || $start_minutes >= $end_minutes) {
				continue;
			}
			if (($start_minutes % 60) !== 0 || ($end_minutes % 60) !== 0) {
				continue;
			}
			$key = $slot_start . '-' . $slot_end;
			$slots[$key] = ['start' => $slot_start, 'end' => $slot_end];
		}

		if (empty($slots) && $fallback_start && $fallback_end) {
			$start_minutes = self::time_to_minutes($fallback_start);
			$end_minutes = self::time_to_minutes($fallback_end);
			if ($start_minutes >= 0 && $end_minutes >= 0 && $start_minutes < $end_minutes && $start_minutes >= $min_minutes && $end_minutes <= $max_minutes) {
				if (($start_minutes % 60) === 0 && ($end_minutes % 60) === 0) {
					$key = $fallback_start . '-' . $fallback_end;
					$slots[$key] = ['start' => $fallback_start, 'end' => $fallback_end];
				}
			}
		}

		if (empty($slots)) {
			$errors[] = __('Seleccione al menos un bloque horario válido (08:00-20:00).', 'cie-lab-booking');
			return [];
		}

		return array_values($slots);
	}

	/**
	 * @param array<int,array{start:string,end:string}> $slots
	 * @return array<int,string>
	 */
	private static function serialize_time_slots(array $slots): array {
		$out = [];
		foreach ($slots as $slot) {
			$start = self::normalize_time_hm((string) ($slot['start'] ?? ''));
			$end = self::normalize_time_hm((string) ($slot['end'] ?? ''));
			if (!$start || !$end) {
				continue;
			}
			$out[$start . '-' . $end] = $start . '-' . $end;
		}
		return array_values($out);
	}

	/**
	 * @param array<int,string> $values
	 * @return array<int,string>
	 */
	private static function normalize_time_slot_values(array $values): array {
		$normalized = [];
		foreach ($values as $value) {
			$slot = trim((string) $value);
			if (!preg_match('/^(\d{2}\:\d{2})\-(\d{2}\:\d{2})$/', $slot, $matches)) {
				continue;
			}
			$start = self::normalize_time_hm((string) $matches[1]);
			$end = self::normalize_time_hm((string) $matches[2]);
			if (!$start || !$end) {
				continue;
			}
			$normalized[$start . '-' . $end] = $start . '-' . $end;
		}
		return array_values($normalized);
	}

	/**
	 * @return array<int,string>
	 */
	private static function normalize_date_list(array $dates): array {
		$out = [];
		foreach ($dates as $date) {
			$normalized = Util::normalize_date_ymd((string) $date);
			if ($normalized) {
				$out[$normalized] = $normalized;
			}
		}
		$values = array_values($out);
		sort($values);
		return $values;
	}

	/**
	 * @return array<int,int>
	 */
	private static function normalize_weekdays(array $weekdays): array {
		$out = [];
		foreach ($weekdays as $weekday) {
			$w = (int) $weekday;
			if ($w >= 1 && $w <= 7) {
				$out[$w] = $w;
			}
		}
		$values = array_values($out);
		sort($values);
		return $values;
	}

	/**
	 * @return array<int,string>
	 */
	private static function extract_selected_dates_from_raw(array $raw): array {
		$candidates = [];
		foreach ((array) ($raw['booking_selected_dates'] ?? []) as $date) {
			$candidates[] = (string) $date;
		}
		foreach ((array) ($raw['booking_dates'] ?? []) as $date) {
			$candidates[] = (string) $date;
		}
		$raw_dates = trim((string) ($raw['booking_dates_raw'] ?? ''));
		if ($raw_dates !== '') {
			foreach (preg_split('/[\s\,\;]+/', $raw_dates) ?: [] as $date) {
				$candidates[] = (string) $date;
			}
		}
		return self::normalize_date_list($candidates);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @param array<string> &$errors
	 * @return array<int,array{date:string,start:string,end:string,full_day:bool}>
	 */
	private static function build_occurrences_from_request(array $raw, string $mode, string $frequency, ?string $time_start, ?string $time_end, array &$errors, array &$schedule_meta = []): array {
		$start = isset($raw['start_date']) ? Util::normalize_date_ymd((string) $raw['start_date']) : null;
		$end = isset($raw['end_date']) ? Util::normalize_date_ymd((string) $raw['end_date']) : null;
		if ((!$start || !$end) && !empty($raw['booking_range'])) {
			[$range_start, $range_end] = self::parse_date_range_input((string) $raw['booking_range']);
			$start = $start ?: $range_start;
			$end = $end ?: $range_end;
		}
		$day_scope = self::normalize_booking_day_scope((string) ($raw['booking_day_scope'] ?? self::BOOKING_DAY_SCOPE_SINGLE));
		if ($frequency === self::BOOKING_FREQUENCY_MANUAL_DATES && $day_scope === self::BOOKING_DAY_SCOPE_SINGLE) {
			$day_scope = self::BOOKING_DAY_SCOPE_MULTI;
		}

		$max_recurrence_weeks = self::get_max_recurrence_weeks();
		$max_range_days = self::get_max_range_days();
		$requested_weeks = (int) ($raw['booking_recurrence_weeks'] ?? 1);
		$recurrence_weeks = max(1, min($max_recurrence_weeks, $requested_weeks));
		if ($requested_weeks > $max_recurrence_weeks) {
			$errors[] = sprintf(
				/* translators: %d: max weeks */
				__('El número máximo de semanas permitido es %d.', 'cie-lab-booking'),
				$max_recurrence_weeks
			);
		}

		$repeat_mode = self::normalize_booking_repeat_mode((string) ($raw['booking_repeat_mode'] ?? self::BOOKING_REPEAT_MODE_NONE));
		if ($repeat_mode === self::BOOKING_REPEAT_MODE_NONE && $frequency === self::BOOKING_FREQUENCY_WEEKLY_REPEAT) {
			$repeat_mode = self::BOOKING_REPEAT_MODE_WEEKLY;
		}

		$needs_time = $mode === self::BOOKING_MODE_TIME_RANGE;
		$time_slots = [];
		if ($needs_time) {
			$time_slots = self::extract_time_slots_from_request($raw, $time_start, $time_end, $errors);
		}

		$base_dates = [];
		if ($day_scope === self::BOOKING_DAY_SCOPE_MULTI) {
			$base_dates = self::extract_selected_dates_from_raw($raw);
			if (!$base_dates) {
				$errors[] = __('Seleccione al menos una fecha para la reserva.', 'cie-lab-booking');
			}
		} elseif ($day_scope === self::BOOKING_DAY_SCOPE_RANGE) {
			if (!$start) {
				$errors[] = __('Seleccione una fecha de inicio válida.', 'cie-lab-booking');
			}
			$end = $end ?: $start;
			if ($start && $end && $end < $start) {
				$errors[] = __('La fecha "hasta" no puede ser anterior a la fecha "desde".', 'cie-lab-booking');
			}
			if ($start && $end && $end >= $start) {
				$base_dates = self::normalize_date_list(self::build_date_range($start, $end));
				if (count($base_dates) > $max_range_days) {
					$errors[] = sprintf(
						/* translators: %d: max days */
						__('El rango de días no puede superar %d días.', 'cie-lab-booking'),
						$max_range_days
					);
				}
			}
		} else {
			if (!$start) {
				$errors[] = __('Seleccione una fecha de inicio válida.', 'cie-lab-booking');
			}
			if ($start) {
				$base_dates = [$start];
			}
		}

		$base_dates = self::normalize_date_list($base_dates);
		$base_same_week = self::dates_in_same_week($base_dates);
		$can_repeat = false;
		if ($day_scope === self::BOOKING_DAY_SCOPE_SINGLE) {
			$can_repeat = !empty($base_dates);
		} elseif ($day_scope === self::BOOKING_DAY_SCOPE_RANGE) {
			$can_repeat = !empty($base_dates) && $base_same_week && count($base_dates) <= $max_range_days;
		} elseif ($day_scope === self::BOOKING_DAY_SCOPE_MULTI) {
			$can_repeat = !empty($base_dates) && $base_same_week;
		}

		if ($repeat_mode !== self::BOOKING_REPEAT_MODE_NONE && !$can_repeat) {
			$errors[] = __('La repetición seleccionada solo está disponible cuando los días pertenecen a la misma semana.', 'cie-lab-booking');
			$repeat_mode = self::BOOKING_REPEAT_MODE_NONE;
		}
		if ($repeat_mode === self::BOOKING_REPEAT_MODE_DAILY && $day_scope !== self::BOOKING_DAY_SCOPE_SINGLE) {
			$errors[] = __('La repetición diaria solo está disponible al seleccionar un único día.', 'cie-lab-booking');
			$repeat_mode = self::BOOKING_REPEAT_MODE_NONE;
		}

		$expanded_dates = $base_dates;
		if ($repeat_mode === self::BOOKING_REPEAT_MODE_DAILY && !empty($base_dates)) {
			$expanded_dates = [];
			$total_days = $recurrence_weeks * 7;
			$origin = (string) $base_dates[0];
			for ($offset = 0; $offset < $total_days; $offset++) {
				$expanded_dates[] = gmdate('Y-m-d', strtotime('+' . $offset . ' day', strtotime($origin . ' 00:00:00')));
			}
		} elseif (($repeat_mode === self::BOOKING_REPEAT_MODE_WEEKLY || $repeat_mode === self::BOOKING_REPEAT_MODE_BIWEEKLY) && !empty($base_dates)) {
			$expanded_dates = [];
			$step = $repeat_mode === self::BOOKING_REPEAT_MODE_BIWEEKLY ? 2 : 1;
			for ($week = 0; $week < $recurrence_weeks; $week += $step) {
				foreach ($base_dates as $base_date) {
					$expanded_dates[] = gmdate('Y-m-d', strtotime('+' . ($week * 7) . ' day', strtotime($base_date . ' 00:00:00')));
				}
			}
		}

		$expanded_dates = self::normalize_date_list($expanded_dates);

		$occurrences = [];
		if ($mode === self::BOOKING_MODE_FULL_DAY) {
			foreach ($expanded_dates as $date) {
				$occurrences[] = ['date' => $date, 'start' => '', 'end' => '', 'full_day' => true];
			}
		} else {
			$occurrences = array_merge($occurrences, self::build_time_occurrences($expanded_dates, $time_slots));
		}

		$occurrences = self::normalize_occurrences($occurrences);
		if (count($occurrences) > 180) {
			$errors[] = __('La reserva excede el número máximo de ocurrencias permitidas (180).', 'cie-lab-booking');
		}

		$schedule_meta = [
			'day_scope' => $day_scope,
			'repeat_mode' => $repeat_mode,
			'recurrence_weeks' => $recurrence_weeks,
			'selected_dates' => $base_dates,
		];

		return $occurrences;
	}

	/**
	 * @param array<int,string> $dates
	 */
	private static function dates_in_same_week(array $dates): bool {
		$dates = self::normalize_date_list($dates);
		if (count($dates) <= 1) {
			return true;
		}
		$first_week = gmdate('o-W', strtotime(((string) $dates[0]) . ' 00:00:00'));
		foreach ($dates as $date) {
			if (gmdate('o-W', strtotime($date . ' 00:00:00')) !== $first_week) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return array<int,string>
	 */
	private static function build_date_range(string $start, string $end): array {
		$start = Util::normalize_date_ymd($start) ?: '';
		$end = Util::normalize_date_ymd($end) ?: '';
		if ($start === '' || $end === '' || $end < $start) {
			return [];
		}
		$dates = [];
		$cursor = strtotime($start . ' 00:00:00');
		$end_ts = strtotime($end . ' 00:00:00');
		while ($cursor <= $end_ts) {
			$dates[] = gmdate('Y-m-d', $cursor);
			$cursor = strtotime('+1 day', $cursor);
		}
		return $dates;
	}

	/**
	 * @param array<int,string> $dates
	 * @param array<int,array{start:string,end:string}> $slots
	 * @return array<int,array{date:string,start:string,end:string,full_day:bool}>
	 */
	private static function build_time_occurrences(array $dates, array $slots): array {
		$occurrences = [];
		foreach (self::normalize_date_list($dates) as $date) {
			foreach ($slots as $slot) {
				$slot_start = self::normalize_time_hm((string) ($slot['start'] ?? ''));
				$slot_end = self::normalize_time_hm((string) ($slot['end'] ?? ''));
				if (!$slot_start || !$slot_end) {
					continue;
				}
				$occurrences[] = [
					'date' => $date,
					'start' => $slot_start,
					'end' => $slot_end,
					'full_day' => false,
				];
			}
		}
		return $occurrences;
	}

	/**
	 * @param array<int,array<string,mixed>> $occurrences
	 * @return array<int,array{date:string,start:string,end:string,full_day:bool}>
	 */
	private static function normalize_occurrences(array $occurrences): array {
		$normalized = [];
		foreach ($occurrences as $occ) {
			$date = Util::normalize_date_ymd((string) ($occ['date'] ?? ''));
			if (!$date) {
				continue;
			}
			$full_day = !empty($occ['full_day']);
			$start = $full_day ? '' : ((self::normalize_time_hm((string) ($occ['start'] ?? '')) ?: ''));
			$end = $full_day ? '' : ((self::normalize_time_hm((string) ($occ['end'] ?? '')) ?: ''));
			if (!$full_day && ($start === '' || $end === '')) {
				continue;
			}
			$key = $date . '|' . ($full_day ? 'full' : ($start . '-' . $end));
			$normalized[$key] = [
				'date' => $date,
				'start' => $start,
				'end' => $end,
				'full_day' => $full_day,
			];
		}
		$values = array_values($normalized);
		usort(
			$values,
			static function (array $a, array $b): int {
				if ($a['date'] !== $b['date']) {
					return strcmp($a['date'], $b['date']);
				}
				if ($a['full_day'] !== $b['full_day']) {
					return $a['full_day'] ? -1 : 1;
				}
				return strcmp((string) $a['start'], (string) $b['start']);
			}
		);
		return $values;
	}

	/**
	 * @return array<int,array{date:string,start:string,end:string,full_day:bool}>
	 */
	private static function build_full_day_occurrences(string $start_date, string $end_date): array {
		$start_date = Util::normalize_date_ymd($start_date) ?: '';
		$end_date = Util::normalize_date_ymd($end_date) ?: '';
		if ($start_date === '' || $end_date === '' || $end_date < $start_date) {
			return [];
		}

		$occurrences = [];
		$cursor = strtotime($start_date . ' 00:00:00');
		$end_ts = strtotime($end_date . ' 00:00:00');
		while ($cursor <= $end_ts) {
			$occurrences[] = [
				'date' => gmdate('Y-m-d', $cursor),
				'start' => '',
				'end' => '',
				'full_day' => true,
			];
			$cursor = strtotime('+1 day', $cursor);
		}
		return $occurrences;
	}

	public static function booking_has_occurrence_on_date(int $booking_id, string $date): bool {
		$date = Util::normalize_date_ymd($date) ?: '';
		if ($date === '') {
			return false;
		}
		return !empty(self::get_booking_occurrences($booking_id, $date, $date));
	}

	/**
	 * @return array<int,array{date:string,start:string,end:string,full_day:bool}>
	 */
	public static function get_booking_occurrences(int $booking_id, ?string $range_start = null, ?string $range_end = null): array {
		$stored = get_post_meta($booking_id, '_cie_booking_occurrences', true);
		$occurrences = [];
		if (is_array($stored) && !empty($stored)) {
			$occurrences = self::normalize_occurrences($stored);
		}

		if (!$occurrences) {
			$start = (string) get_post_meta($booking_id, '_cie_booking_start_date', true);
			$end = (string) get_post_meta($booking_id, '_cie_booking_end_date', true);
			$mode = self::normalize_booking_mode((string) get_post_meta($booking_id, '_cie_booking_mode', true));
			if ($mode === self::BOOKING_MODE_TIME_RANGE) {
				$date_scope = self::normalize_booking_day_scope((string) get_post_meta($booking_id, '_cie_booking_day_scope', true));
				$date_list = [$start];
				if ($date_scope === self::BOOKING_DAY_SCOPE_RANGE && $start !== '' && $end !== '' && $end >= $start) {
					$date_list = self::build_date_range($start, $end);
				}
				$slots_meta = self::normalize_time_slot_values((array) get_post_meta($booking_id, '_cie_booking_time_slots', true));
				$slots = [];
				foreach ($slots_meta as $slot_value) {
					if (preg_match('/^(\d{2}\:\d{2})\-(\d{2}\:\d{2})$/', $slot_value, $matches)) {
						$slots[] = [
							'start' => (string) $matches[1],
							'end' => (string) $matches[2],
						];
					}
				}
				if (!$slots) {
					$time_start = self::normalize_time_hm((string) get_post_meta($booking_id, '_cie_booking_time_start', true)) ?: '';
					$time_end = self::normalize_time_hm((string) get_post_meta($booking_id, '_cie_booking_time_end', true)) ?: '';
					if ($time_start !== '' && $time_end !== '') {
						$slots[] = ['start' => $time_start, 'end' => $time_end];
					}
				}
				if ($date_list && $slots) {
					$occurrences = self::build_time_occurrences($date_list, $slots);
				}
			}
			if (!$occurrences) {
				$occurrences = self::build_full_day_occurrences($start, $end);
			}
		}

		if ($range_start !== null) {
			$range_start = Util::normalize_date_ymd($range_start);
		}
		if ($range_end !== null) {
			$range_end = Util::normalize_date_ymd($range_end);
		}
		if ($range_start && $range_end) {
			$occurrences = array_values(
				array_filter(
					$occurrences,
					static function (array $occ) use ($range_start, $range_end): bool {
						$date = (string) ($occ['date'] ?? '');
						return $date >= $range_start && $date <= $range_end;
					}
				)
			);
		}

		return $occurrences;
	}

	/**
	 * @param array{date:string,start:string,end:string,full_day:bool} $a
	 * @param array{date:string,start:string,end:string,full_day:bool} $b
	 */
	private static function occurrences_overlap(array $a, array $b): bool {
		if ((string) ($a['date'] ?? '') !== (string) ($b['date'] ?? '')) {
			return false;
		}
		if (!empty($a['full_day']) || !empty($b['full_day'])) {
			return true;
		}
		$a_start = self::time_to_minutes((string) ($a['start'] ?? ''));
		$a_end = self::time_to_minutes((string) ($a['end'] ?? ''));
		$b_start = self::time_to_minutes((string) ($b['start'] ?? ''));
		$b_end = self::time_to_minutes((string) ($b['end'] ?? ''));
		if ($a_start < 0 || $a_end < 0 || $b_start < 0 || $b_end < 0) {
			return true;
		}
		return $a_start < $b_end && $b_start < $a_end;
	}

	/**
	 * @param array{date:string,start:string,end:string,full_day:bool} $needle
	 * @param array<int,array{date:string,start:string,end:string,full_day:bool}> $haystack
	 */
	private static function occurrence_overlaps_any(array $needle, array $haystack): bool {
		foreach ($haystack as $candidate) {
			if (self::occurrences_overlap($needle, $candidate)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int,array{date:string,start:string,end:string,full_day:bool}> $occurrences
	 * @param array<int> $space_ids
	 * @param array<int> $equipment_ids
	 * @return array{spaces:array<int>,equipment:array<int>,blocked:bool}
	 */
	public static function find_conflicts_for_occurrences(array $occurrences, array $space_ids, array $equipment_ids, int $exclude_booking_id = 0): array {
		$occurrences = self::normalize_occurrences($occurrences);
		$space_ids = array_values(array_unique(array_filter(array_map('intval', $space_ids))));
		$equipment_ids = array_values(array_unique(array_filter(array_map('intval', $equipment_ids))));
		if (!$occurrences) {
			return ['spaces' => [], 'equipment' => [], 'blocked' => false];
		}

		$dates = array_map(
			static function (array $occ): string {
				return (string) $occ['date'];
			},
			$occurrences
		);
		sort($dates);
		$range_start = (string) reset($dates);
		$range_end = (string) end($dates);

		$space_overlaps = [];
		$equipment_overlaps = [];
		foreach ($space_ids as $rid) {
			$space_overlaps[$rid] = array_fill(0, count($occurrences), 0);
		}
		foreach ($equipment_ids as $rid) {
			$equipment_overlaps[$rid] = array_fill(0, count($occurrences), 0);
		}

		$approved = self::get_overlapping_approved_bookings($range_start, $range_end);
		foreach ($approved as $booking) {
			$booking_id = (int) $booking->ID;
			if ($exclude_booking_id > 0 && $booking_id === $exclude_booking_id) {
				continue;
			}
			$booking_occurrences = self::get_booking_occurrences($booking_id, $range_start, $range_end);
			if (!$booking_occurrences) {
				continue;
			}

			$booking_spaces = array_values(array_filter(array_map('intval', (array) get_post_meta($booking_id, '_cie_booking_spaces', true))));
			$booking_equipment = array_values(array_filter(array_map('intval', (array) get_post_meta($booking_id, '_cie_booking_equipment', true))));

			$space_hits = array_values(array_intersect($space_ids, $booking_spaces));
			$equipment_hits = array_values(array_intersect($equipment_ids, $booking_equipment));
			if (!$space_hits && !$equipment_hits) {
				continue;
			}

			foreach ($occurrences as $index => $occurrence) {
				if (!self::occurrence_overlaps_any($occurrence, $booking_occurrences)) {
					continue;
				}
				foreach ($space_hits as $rid) {
					$space_overlaps[$rid][$index] = (int) ($space_overlaps[$rid][$index] ?? 0) + 1;
				}
				foreach ($equipment_hits as $rid) {
					$equipment_overlaps[$rid][$index] = (int) ($equipment_overlaps[$rid][$index] ?? 0) + 1;
				}
			}
		}

		$conflicting_space_ids = [];
		foreach ($space_ids as $rid) {
			$quantity = self::get_resource_quantity($rid);
			foreach ((array) ($space_overlaps[$rid] ?? []) as $reserved_count) {
				if ((int) $reserved_count >= $quantity) {
					$conflicting_space_ids[] = $rid;
					break;
				}
			}
		}

		$conflicting_equipment_ids = [];
		foreach ($equipment_ids as $rid) {
			$quantity = self::get_resource_quantity($rid);
			foreach ((array) ($equipment_overlaps[$rid] ?? []) as $reserved_count) {
				if ((int) $reserved_count >= $quantity) {
					$conflicting_equipment_ids[] = $rid;
					break;
				}
			}
		}

		$blocks = self::get_overlapping_blocks($range_start, $range_end);
		$blocked = false;
		if ($blocks) {
			$selected_resources = array_merge($space_ids, $equipment_ids);
			foreach ($blocks as $block) {
				$block_start = Util::normalize_date_ymd((string) get_post_meta((int) $block->ID, '_cie_block_start_date', true)) ?: '';
				$block_end = Util::normalize_date_ymd((string) get_post_meta((int) $block->ID, '_cie_block_end_date', true)) ?: '';
				if ($block_start === '' || $block_end === '' || $block_end < $block_start) {
					continue;
				}
				$blocked_ids = array_values(array_filter(array_map('intval', (array) get_post_meta((int) $block->ID, '_cie_block_resource_ids', true))));
				$is_global = empty($blocked_ids);
				$resource_hit = $is_global || !empty(array_intersect($selected_resources, $blocked_ids));
				if (!$resource_hit) {
					continue;
				}
				foreach ($occurrences as $occurrence) {
					$date = (string) $occurrence['date'];
					if ($date >= $block_start && $date <= $block_end) {
						$blocked = true;
						break 2;
					}
				}
			}
		}

		return [
			'spaces' => array_values(array_unique($conflicting_space_ids)),
			'equipment' => array_values(array_unique($conflicting_equipment_ids)),
			'blocked' => $blocked,
		];
	}

	/**
	 * @param array<int> $space_ids
	 * @param array<int> $equipment_ids
	 * @return array<int,array{start:string,end:string,available:bool,reasons:array<int,string>}>
	 */
	public static function get_daily_time_slots_availability(string $date, array $space_ids, array $equipment_ids): array {
		$date = Util::normalize_date_ymd($date) ?: '';
		if ($date === '') {
			return [];
		}

		$space_ids = array_values(array_unique(array_filter(array_map('intval', $space_ids))));
		$equipment_ids = array_values(array_unique(array_filter(array_map('intval', $equipment_ids))));
		$slots = [];
		$start_minutes = self::time_to_minutes(self::BOOKING_TIME_MIN);
		$end_minutes = self::time_to_minutes(self::BOOKING_TIME_MAX);
		if ($start_minutes < 0 || $end_minutes <= $start_minutes) {
			return [];
		}

		for ($cursor = $start_minutes; $cursor < $end_minutes; $cursor += 60) {
			$slot_start = sprintf('%02d:%02d', (int) floor($cursor / 60), (int) ($cursor % 60));
			$slot_end_minutes = min($cursor + 60, $end_minutes);
			$slot_end = sprintf('%02d:%02d', (int) floor($slot_end_minutes / 60), (int) ($slot_end_minutes % 60));
			$occurrence = [[
				'date' => $date,
				'start' => $slot_start,
				'end' => $slot_end,
				'full_day' => false,
			]];
			$conflicts = self::find_conflicts_for_occurrences($occurrence, $space_ids, $equipment_ids);
			$available = empty($conflicts['spaces']) && empty($conflicts['equipment']) && empty($conflicts['blocked']);
			$reasons = [];
			if (!empty($conflicts['blocked'])) {
				$reasons[] = (string) __('Mantenimiento', 'cie-lab-booking');
			}
			if (!empty($conflicts['spaces'])) {
				$reasons[] = (string) __('Espacios no disponibles', 'cie-lab-booking');
			}
			if (!empty($conflicts['equipment'])) {
				$reasons[] = (string) __('Equipos no disponibles', 'cie-lab-booking');
			}
			$slots[] = [
				'start' => $slot_start,
				'end' => $slot_end,
				'available' => $available,
				'reasons' => $reasons,
			];
		}

		return $slots;
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
		update_post_meta($post_id, '_cie_booking_mode', (string) ($data['booking_mode'] ?? self::BOOKING_MODE_FULL_DAY));
		update_post_meta($post_id, '_cie_booking_frequency', (string) ($data['booking_frequency'] ?? self::BOOKING_FREQUENCY_SINGLE));
		update_post_meta($post_id, '_cie_booking_day_scope', (string) ($data['booking_day_scope'] ?? self::BOOKING_DAY_SCOPE_SINGLE));
		update_post_meta($post_id, '_cie_booking_repeat_mode', (string) ($data['booking_repeat_mode'] ?? self::BOOKING_REPEAT_MODE_NONE));
		update_post_meta($post_id, '_cie_booking_time_start', (string) ($data['booking_time_start'] ?? ''));
		update_post_meta($post_id, '_cie_booking_time_end', (string) ($data['booking_time_end'] ?? ''));
		update_post_meta($post_id, '_cie_booking_time_slots', self::normalize_time_slot_values((array) ($data['booking_time_slots'] ?? [])));
		update_post_meta($post_id, '_cie_booking_recurrence_weeks', max(1, (int) ($data['booking_recurrence_weeks'] ?? 1)));
		update_post_meta($post_id, '_cie_booking_weekdays', self::normalize_weekdays((array) ($data['booking_weekdays'] ?? [])));
		update_post_meta($post_id, '_cie_booking_selected_dates', self::normalize_date_list((array) ($data['booking_selected_dates'] ?? [])));
		update_post_meta($post_id, '_cie_booking_occurrences', self::normalize_occurrences((array) ($data['occurrences'] ?? [])));

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
		update_post_meta($booking_id, '_cie_booking_mode', (string) ($data['booking_mode'] ?? self::BOOKING_MODE_FULL_DAY));
		update_post_meta($booking_id, '_cie_booking_frequency', (string) ($data['booking_frequency'] ?? self::BOOKING_FREQUENCY_SINGLE));
		update_post_meta($booking_id, '_cie_booking_day_scope', (string) ($data['booking_day_scope'] ?? self::BOOKING_DAY_SCOPE_SINGLE));
		update_post_meta($booking_id, '_cie_booking_repeat_mode', (string) ($data['booking_repeat_mode'] ?? self::BOOKING_REPEAT_MODE_NONE));
		update_post_meta($booking_id, '_cie_booking_time_start', (string) ($data['booking_time_start'] ?? ''));
		update_post_meta($booking_id, '_cie_booking_time_end', (string) ($data['booking_time_end'] ?? ''));
		update_post_meta($booking_id, '_cie_booking_time_slots', self::normalize_time_slot_values((array) ($data['booking_time_slots'] ?? [])));
		update_post_meta($booking_id, '_cie_booking_recurrence_weeks', max(1, (int) ($data['booking_recurrence_weeks'] ?? 1)));
		update_post_meta($booking_id, '_cie_booking_weekdays', self::normalize_weekdays((array) ($data['booking_weekdays'] ?? [])));
		update_post_meta($booking_id, '_cie_booking_selected_dates', self::normalize_date_list((array) ($data['booking_selected_dates'] ?? [])));
		update_post_meta($booking_id, '_cie_booking_occurrences', self::normalize_occurrences((array) ($data['occurrences'] ?? [])));

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
		$occurrences = self::build_full_day_occurrences($start_date, $end_date);
		return self::find_conflicts_for_occurrences($occurrences, $space_ids, $equipment_ids, $exclude_booking_id);
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
			$has_space = !empty((array) get_post_meta($b->ID, '_cie_booking_spaces', true));
			$has_eq = !empty((array) get_post_meta($b->ID, '_cie_booking_equipment', true));
			$occurrences = self::get_booking_occurrences((int) $b->ID, $start, $end);
			foreach ($occurrences as $occ) {
				$k = (string) ($occ['date'] ?? '');
				if ($k !== '' && isset($map[$k])) {
					$map[$k]['has_space'] = $map[$k]['has_space'] || $has_space;
					$map[$k]['has_equipment'] = $map[$k]['has_equipment'] || $has_eq;
				}
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
			$has_space = !empty((array) get_post_meta($b->ID, '_cie_booking_spaces', true));
			$has_eq = !empty((array) get_post_meta($b->ID, '_cie_booking_equipment', true));
			$occurrences = self::get_booking_occurrences((int) $b->ID, $start, $end);
			foreach ($occurrences as $occ) {
				$k = (string) ($occ['date'] ?? '');
				if ($k !== '' && isset($map[$k])) {
					$map[$k]['has_space'] = $map[$k]['has_space'] || $has_space;
					$map[$k]['has_equipment'] = $map[$k]['has_equipment'] || $has_eq;
				}
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
			$booking_occurrences = self::get_booking_occurrences((int) $booking->ID, $start, $end);
			if (!$booking_occurrences) {
				continue;
			}
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

