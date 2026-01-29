<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Shortcodes {
	public static function init(): void {
		add_shortcode('cie_booking_form', [self::class, 'render_booking_form']);
		add_shortcode('cie_my_bookings', [self::class, 'render_my_bookings']);
		add_shortcode('cie_booking_calendar', [self::class, 'render_calendar']);
	}

	public static function render_booking_form(): string {
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('Debes iniciar sesión para realizar una reserva.', 'cie-lab-booking') . '</p>';
		}
		if (!Util::current_user_can_book()) {
			return '<p>' . esc_html__('Tu usuario no tiene permisos para realizar reservas.', 'cie-lab-booking') . '</p>';
		}

		// TODO: Implementar formulario 2.9 completo (fechas, espacio/equipos, validaciones, proyecto, envío).
		return '<div class="cie-lab-booking cie-lab-booking--placeholder">'
			. '<p><strong>' . esc_html__('Formulario de reserva (pendiente de implementación)', 'cie-lab-booking') . '</strong></p>'
			. '</div>';
	}

	public static function render_my_bookings(): string {
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('Debes iniciar sesión para ver tus reservas.', 'cie-lab-booking') . '</p>';
		}
		if (!Util::current_user_can_book()) {
			return '<p>' . esc_html__('Tu usuario no tiene permisos para ver reservas.', 'cie-lab-booking') . '</p>';
		}

		// TODO: Listado de reservas en curso / histórico (2.7.A Mis reservas).
		return '<div class="cie-lab-booking cie-lab-booking--placeholder">'
			. '<p><strong>' . esc_html__('Mis reservas (pendiente de implementación)', 'cie-lab-booking') . '</strong></p>'
			. '</div>';
	}

	public static function render_calendar(): string {
		// TODO: Calendario de reservas (solo lectura) con códigos de color (2.7.B).
		return '<div class="cie-lab-booking cie-lab-booking--placeholder">'
			. '<p><strong>' . esc_html__('Calendario (pendiente de implementación)', 'cie-lab-booking') . '</strong></p>'
			. '</div>';
	}
}

