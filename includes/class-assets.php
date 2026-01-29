<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Assets {
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

		wp_enqueue_style('jquery-ui-datepicker');
		wp_enqueue_style(
			'cie-lab-booking-front',
			CIE_LAB_BOOKING_URL . 'assets/css/front.css',
			[],
			CIE_LAB_BOOKING_VERSION
		);
		wp_enqueue_script(
			'cie-lab-booking-front',
			CIE_LAB_BOOKING_URL . 'assets/js/front.js',
			['jquery', 'jquery-ui-datepicker'],
			CIE_LAB_BOOKING_VERSION,
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

		wp_enqueue_style(
			'cie-lab-booking-admin',
			CIE_LAB_BOOKING_URL . 'assets/css/admin.css',
			[],
			CIE_LAB_BOOKING_VERSION
		);
		wp_enqueue_script(
			'cie-lab-booking-admin',
			CIE_LAB_BOOKING_URL . 'assets/js/admin.js',
			['jquery', 'jquery-ui-datepicker'],
			CIE_LAB_BOOKING_VERSION,
			true
		);

		wp_localize_script('cie-lab-booking-admin', 'CieLabBookingAdmin', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('cie_lab_booking_admin'),
		]);
	}
}

