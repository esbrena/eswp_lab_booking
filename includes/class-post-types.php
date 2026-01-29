<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Post_Types {
	public const CPT_RESOURCE = 'cie_resource';
	public const CPT_BOOKING = 'cie_booking';
	public const CPT_BLOCK = 'cie_block';

	// Booking statuses.
	public const BOOKING_STATUS_PENDING = 'pending';
	public const BOOKING_STATUS_APPROVED = 'approved';
	public const BOOKING_STATUS_REJECTED = 'rejected';
	public const BOOKING_STATUS_CHANGES = 'changes_requested';
	public const BOOKING_STATUS_CANCELLED = 'cancelled';

	public static function init(): void {
		add_action('init', [self::class, 'register']);
	}

	public static function register(): void {
		register_post_type(
			self::CPT_RESOURCE,
			[
				'labels' => [
					'name' => __('Recursos (CIE)', 'cie-lab-booking'),
					'singular_name' => __('Recurso', 'cie-lab-booking'),
				],
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => false,
				'supports' => ['title'],
				'capability_type' => 'post',
				'map_meta_cap' => true,
			]
		);

		register_post_type(
			self::CPT_BOOKING,
			[
				'labels' => [
					'name' => __('Reservas (CIE)', 'cie-lab-booking'),
					'singular_name' => __('Reserva', 'cie-lab-booking'),
				],
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => false,
				'supports' => ['title', 'author'],
				'capability_type' => 'post',
				'map_meta_cap' => true,
			]
		);

		register_post_type(
			self::CPT_BLOCK,
			[
				'labels' => [
					'name' => __('Bloqueos (CIE)', 'cie-lab-booking'),
					'singular_name' => __('Bloqueo', 'cie-lab-booking'),
				],
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => false,
				'supports' => ['title'],
				'capability_type' => 'post',
				'map_meta_cap' => true,
			]
		);
	}

	public static function seed_default_resources(): void {
		// Spaces: cabina, laboratorio.
		self::upsert_resource([
			'title' => 'Cabina',
			'kind' => 'space',
			'group' => 'spaces',
			'code' => 'SPACE_CABINA',
			'available' => '1',
		]);
		self::upsert_resource([
			'title' => 'Laboratorio',
			'kind' => 'space',
			'group' => 'spaces',
			'code' => 'SPACE_LAB',
			'available' => '1',
		]);

		$csv_path = CIE_LAB_BOOKING_DIR . '/assets/data/equipos.csv';
		if (!file_exists($csv_path)) {
			return;
		}

		$handle = fopen($csv_path, 'r');
		if (!$handle) {
			return;
		}

		$header = null;
		while (($row = fgetcsv($handle, 0, ';')) !== false) {
			if (!$header) {
				$header = $row;
				continue;
			}

			// Skip empty rows.
			$non_empty = array_filter($row, static fn($v) => trim((string) $v) !== '');
			if (empty($non_empty)) {
				continue;
			}

			$col = static function ($names) use ($header, $row): string {
				$names = (array) $names;
				foreach ($names as $name) {
					$idx = array_search($name, $header, true);
					if ($idx !== false) {
						return isset($row[$idx]) ? trim((string) $row[$idx]) : '';
					}
				}
				return '';
			};

			$code = $col('ID');
			$title = $col('Equipo');
			$model = $col('Modelo');
			$serial = $col(['N�mero de serie', 'Número de serie', 'Numero de serie']);
			$place = $col('Lugar de trabajo');

			if ($title === '' && $model !== '') {
				$title = $model;
			}

			if ($title === '') {
				continue;
			}

			self::upsert_resource([
				'title' => $title,
				'kind' => 'equipment',
				'group' => self::infer_equipment_group($title),
				'code' => $code,
				'model' => $model,
				'serial' => $serial,
				'place' => $place,
				'available' => '1',
			]);
		}

		fclose($handle);
	}

	private static function infer_equipment_group(string $title): string {
		$t = mb_strtolower($title);
		if (str_contains($t, 'eye') || str_contains($t, 'tobii') || str_contains($t, 'tracker')) {
			return 'eye-tracker';
		}
		if (str_contains($t, 'eeg')) {
			return 'eeg';
		}
		if (str_contains($t, 'uti') || str_contains($t, 'nasal') || str_contains($t, 'egg') || str_contains($t, 'fon')) {
			return 'phonetics';
		}
		if (str_contains($t, 'mic') || str_contains($t, 'grab') || str_contains($t, 'audio') || str_contains($t, 'interfaz')) {
			return 'recording';
		}

		return 'other';
	}

	/**
	 * @param array{title:string,kind:string,group:string,code:string,available:string,model?:string,serial?:string,place?:string} $data
	 */
	private static function upsert_resource(array $data): void {
		$existing = get_posts([
			'post_type' => self::CPT_RESOURCE,
			'post_status' => 'any',
			'posts_per_page' => 1,
			'meta_query' => [
				[
					'key' => '_cie_resource_code',
					'value' => $data['code'],
				],
			],
		]);

		$post_id = $existing ? (int) $existing[0]->ID : 0;
		if (!$post_id) {
			$post_id = wp_insert_post([
				'post_type' => self::CPT_RESOURCE,
				'post_status' => 'publish',
				'post_title' => $data['title'],
			]);
		} else {
			wp_update_post([
				'ID' => $post_id,
				'post_title' => $data['title'],
			]);
		}

		if (!$post_id || is_wp_error($post_id)) {
			return;
		}

		update_post_meta($post_id, '_cie_resource_kind', $data['kind']);
		update_post_meta($post_id, '_cie_resource_group', $data['group']);
		update_post_meta($post_id, '_cie_resource_code', $data['code']);
		update_post_meta($post_id, '_cie_resource_available', $data['available']);
		update_post_meta($post_id, '_cie_resource_model', $data['model'] ?? '');
		update_post_meta($post_id, '_cie_resource_serial', $data['serial'] ?? '');
		update_post_meta($post_id, '_cie_resource_place', $data['place'] ?? '');
	}
}

