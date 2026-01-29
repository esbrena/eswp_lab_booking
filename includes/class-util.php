<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Util {
	public const ROLE_BOOKING_USER = 'CIE_Usuarios';

	public static function current_user_can_book(): bool {
		if (!is_user_logged_in()) {
			return false;
		}

		$user = wp_get_current_user();
		if (!$user || empty($user->ID)) {
			return false;
		}

		// Allow admins to test/manage flows.
		if (user_can($user, 'manage_options')) {
			return true;
		}

		return in_array(self::ROLE_BOOKING_USER, (array) $user->roles, true);
	}

	public static function require_booking_user(): void {
		if (!self::current_user_can_book()) {
			wp_die(esc_html__('No tienes permisos para realizar reservas.', 'cie-lab-booking'), 403);
		}
	}

	/**
	 * Normalize date (YYYY-MM-DD) and validate.
	 */
	public static function normalize_date_ymd(string $date): ?string {
		$date = trim($date);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			return null;
		}

		[$y, $m, $d] = array_map('intval', explode('-', $date));
		if (!checkdate($m, $d, $y)) {
			return null;
		}

		return sprintf('%04d-%02d-%02d', $y, $m, $d);
	}

	public static function dates_overlap(string $startA, string $endA, string $startB, string $endB): bool {
		// Inclusive overlap.
		return !($endA < $startB || $endB < $startA);
	}

	public static function admin_notice(string $message, string $type = 'success'): void {
		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr($type),
			esc_html($message)
		);
	}
}

