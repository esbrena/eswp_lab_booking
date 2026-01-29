<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Admin {
	public static function init(): void {
		add_action('admin_menu', [self::class, 'register_menu']);
	}

	public static function register_menu(): void {
		add_menu_page(
			__('CIE - Reservas', 'cie-lab-booking'),
			__('CIE - Reservas', 'cie-lab-booking'),
			'manage_options',
			'cie-lab-booking',
			[self::class, 'render_dashboard'],
			'dashicons-calendar-alt',
			26
		);

		add_submenu_page(
			'cie-lab-booking',
			__('Calendario y reservas', 'cie-lab-booking'),
			__('Calendario y reservas', 'cie-lab-booking'),
			'manage_options',
			'cie-lab-booking-calendar',
			[self::class, 'render_calendar']
		);

		add_submenu_page(
			'cie-lab-booking',
			__('Reservas', 'cie-lab-booking'),
			__('Reservas', 'cie-lab-booking'),
			'manage_options',
			'edit.php?post_type=' . Post_Types::CPT_BOOKING
		);

		add_submenu_page(
			'cie-lab-booking',
			__('Recursos', 'cie-lab-booking'),
			__('Recursos', 'cie-lab-booking'),
			'manage_options',
			'edit.php?post_type=' . Post_Types::CPT_RESOURCE
		);

		add_submenu_page(
			'cie-lab-booking',
			__('Bloqueos', 'cie-lab-booking'),
			__('Bloqueos', 'cie-lab-booking'),
			'manage_options',
			'edit.php?post_type=' . Post_Types::CPT_BLOCK
		);

		add_submenu_page(
			null,
			__('Revisar reserva', 'cie-lab-booking'),
			__('Revisar reserva', 'cie-lab-booking'),
			'manage_options',
			'cie-lab-booking-booking',
			[self::class, 'render_booking_review']
		);
	}

	public static function render_dashboard(): void {
		echo '<div class="wrap"><h1>' . esc_html__('CIE - Reservas', 'cie-lab-booking') . '</h1>';
		echo '<p>' . esc_html__('Desde aquí puedes gestionar el calendario y las reservas.', 'cie-lab-booking') . '</p>';
		echo '</div>';
	}

	public static function render_calendar(): void {
		echo '<div class="wrap"><h1>' . esc_html__('Calendario y reservas', 'cie-lab-booking') . '</h1>';
		// TODO: Implementar calendario 2 años + validación/rechazo/modificación + disponibilidad recursos.
		echo '<p><em>' . esc_html__('Pendiente de implementación.', 'cie-lab-booking') . '</em></p>';
		echo '</div>';
	}

	public static function render_booking_review(): void {
		echo '<div class="wrap"><h1>' . esc_html__('Revisar reserva', 'cie-lab-booking') . '</h1>';
		// TODO: Pantalla de validación/rechazo/solicitar cambios para una reserva concreta.
		echo '<p><em>' . esc_html__('Pendiente de implementación.', 'cie-lab-booking') . '</em></p>';
		echo '</div>';
	}
}

