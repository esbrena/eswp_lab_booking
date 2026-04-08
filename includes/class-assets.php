<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Assets {
	/**
	 * @return array<int,string>
	 */
	private static function front_shortcode_tags(): array {
		return [
			'cie_booking_form',
			'cie_my_bookings',
			'cie_my_bookings_current',
			'cie_my_bookings_history',
			'cie_my_active_bookings_card',
			'cie_booking_calendar',
		];
	}

	private static function page_needs_front_scripts(): bool {
		$post = get_post();
		if (!$post) {
			return (bool) apply_filters('cie_lab_booking_force_front_scripts', false);
		}

		$tags = self::front_shortcode_tags();
		$content = (string) $post->post_content;
		foreach ($tags as $tag) {
			if (has_shortcode($content, $tag)) {
				return true;
			}
		}

		// Elementor stores content in JSON under _elementor_data, not in post_content.
		$elementor_data = (string) get_post_meta((int) $post->ID, '_elementor_data', true);
		if ($elementor_data !== '') {
			foreach ($tags as $tag) {
				if (strpos($elementor_data, '[' . $tag) !== false || strpos($elementor_data, $tag) !== false) {
					return true;
				}
			}
		}

		return (bool) apply_filters('cie_lab_booking_force_front_scripts', false);
	}

	private static function asset_version(string $relative_path): string {
		$abs = CIE_LAB_BOOKING_DIR . '/' . ltrim($relative_path, '/');
		if (file_exists($abs)) {
			return (string) filemtime($abs);
		}
		return CIE_LAB_BOOKING_VERSION;
	}

	public static function init(): void {
		add_action('wp_enqueue_scripts', [self::class, 'enqueue_front']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin']);
	}

	public static function enqueue_front(): void {
		// Front CSS is always loaded so shortcodes rendered by builders
		// (e.g. Elementor) keep visual styles even if content isn't in post_content.
		wp_enqueue_style(
			'cie-lab-booking-front',
			CIE_LAB_BOOKING_URL . 'assets/css/front.css',
			[],
			self::asset_version('assets/css/front.css')
		);

		if (!self::page_needs_front_scripts()) {
			return;
		}

		// Flatpickr (more reliable than jQuery UI datepicker across themes).
		wp_enqueue_style(
			'cie-flatpickr',
			CIE_LAB_BOOKING_URL . 'assets/vendor/flatpickr/flatpickr.min.css',
			[],
			self::asset_version('assets/vendor/flatpickr/flatpickr.min.css')
		);
		wp_enqueue_script(
			'cie-flatpickr',
			CIE_LAB_BOOKING_URL . 'assets/vendor/flatpickr/flatpickr.min.js',
			[],
			self::asset_version('assets/vendor/flatpickr/flatpickr.min.js'),
			true
		);
		wp_enqueue_script(
			'cie-flatpickr-es',
			CIE_LAB_BOOKING_URL . 'assets/vendor/flatpickr/es.js',
			['cie-flatpickr'],
			self::asset_version('assets/vendor/flatpickr/es.js'),
			true
		);
		wp_enqueue_script(
			'cie-lab-booking-front',
			CIE_LAB_BOOKING_URL . 'assets/js/front.js',
			['jquery', 'cie-flatpickr', 'cie-flatpickr-es'],
			self::asset_version('assets/js/front.js'),
			true
		);

		wp_localize_script('cie-lab-booking-front', 'CieLabBooking', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('cie_lab_booking'),
		]);
	}

	public static function enqueue_admin(string $hook_suffix): void {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$is_plugin_page = strpos($hook_suffix, 'cie-lab-booking') !== false;
		$is_cie_post_type = $screen
			&& isset($screen->post_type)
			&& in_array((string) $screen->post_type, [Post_Types::CPT_BOOKING, Post_Types::CPT_RESOURCE, Post_Types::CPT_BLOCK], true);

		// Only on our admin pages and post types.
		if (!$is_plugin_page && !$is_cie_post_type) {
			return;
		}

		// Ensure jQuery is loaded on our admin pages.
		wp_enqueue_script('jquery');

		wp_enqueue_style(
			'cie-flatpickr',
			CIE_LAB_BOOKING_URL . 'assets/vendor/flatpickr/flatpickr.min.css',
			[],
			self::asset_version('assets/vendor/flatpickr/flatpickr.min.css')
		);
		wp_enqueue_script(
			'cie-flatpickr',
			CIE_LAB_BOOKING_URL . 'assets/vendor/flatpickr/flatpickr.min.js',
			[],
			self::asset_version('assets/vendor/flatpickr/flatpickr.min.js'),
			true
		);
		wp_enqueue_script(
			'cie-flatpickr-es',
			CIE_LAB_BOOKING_URL . 'assets/vendor/flatpickr/es.js',
			['cie-flatpickr'],
			self::asset_version('assets/vendor/flatpickr/es.js'),
			true
		);

		wp_enqueue_style(
			'cie-lab-booking-admin',
			CIE_LAB_BOOKING_URL . 'assets/css/admin.css',
			[],
			self::asset_version('assets/css/admin.css')
		);
		wp_enqueue_script(
			'cie-lab-booking-admin',
			CIE_LAB_BOOKING_URL . 'assets/js/admin.js',
			['jquery', 'cie-flatpickr', 'cie-flatpickr-es'],
			self::asset_version('assets/js/admin.js'),
			true
		);

		wp_localize_script('cie-lab-booking-admin', 'CieLabBookingAdmin', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('cie_lab_booking_admin'),
		]);
	}
}

