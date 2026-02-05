<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Assets {
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
		if (!is_singular()) {
			return;
		}

		$post = get_post();
		if (!$post) {
			return;
		}

		$needs = has_shortcode($post->post_content, 'cie_booking_form')
			|| has_shortcode($post->post_content, 'cie_my_bookings')
			|| has_shortcode($post->post_content, 'cie_booking_calendar');

		if (!$needs) {
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

		wp_enqueue_style(
			'cie-lab-booking-front',
			CIE_LAB_BOOKING_URL . 'assets/css/front.css',
			[],
			self::asset_version('assets/css/front.css')
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
		// Only on our pages.
		if (strpos($hook_suffix, 'cie-lab-booking') === false && strpos($hook_suffix, Post_Types::CPT_BOOKING) === false) {
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

