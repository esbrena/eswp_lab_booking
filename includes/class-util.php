<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Util {
	/**
	 * Roles allowed to create bookings.
	 *
	 * Note: WP stores role "slugs" in $user->roles, not display names. We keep a
	 * tolerant allow/deny list because sites may register these roles with
	 * different slugs/casing.
	 */
	public const ROLE_ALLOW_BOOKING = [
		// Common slugs/casing for "CIE Usuario".
		'CIE_Usuario',
		'cie_usuario',
		'CIE_Usuarios', // legacy in this plugin
		'cie_usuarios',
	];

	/**
	 * Roles explicitly blocked from creating bookings.
	 */
	public const ROLE_DENY_BOOKING = [
		// "CIE Nuevo Usuario" should NOT be able to book.
		'CIE_Nuevo_Usuario',
		'cie_nuevo_usuario',
		'CIE_NuevoUsuario',
		'cie_nuevoUsuario',
	];

	/**
	 * Lowercase helper (mbstring optional).
	 */
	public static function lc(string $value): string {
		if (function_exists('mb_strtolower')) {
			return (string) mb_strtolower($value);
		}
		return strtolower($value);
	}

	/**
	 * PHP 7.4-compatible str_contains().
	 */
	public static function contains(string $haystack, string $needle): bool {
		if ($needle === '') {
			return true;
		}
		return strpos($haystack, $needle) !== false;
	}

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

		$roles = (array) $user->roles;

		// Explicit deny list wins.
		foreach (self::ROLE_DENY_BOOKING as $deny) {
			if (in_array($deny, $roles, true)) {
				return false;
			}
		}

		foreach (self::ROLE_ALLOW_BOOKING as $allow) {
			if (in_array($allow, $roles, true)) {
				return true;
			}
		}

		return false;
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

