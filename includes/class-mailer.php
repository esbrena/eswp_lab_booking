<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Mailer {
	public const OPTION_NOTIFICATION_EMAIL = 'cie_lab_booking_notification_email';

	/**
	 * Notification destination for admin alerts.
	 *
	 * Defaults to the site's admin email. Can be overridden in plugin settings.
	 */
	public static function get_notification_email(): string {
		$opt = trim((string) get_option(self::OPTION_NOTIFICATION_EMAIL, ''));
		if ($opt !== '' && is_email($opt)) {
			return $opt;
		}
		$admin = trim((string) get_option('admin_email', ''));
		return is_email($admin) ? $admin : '';
	}

	public static function notify_admin_booking_submitted(int $booking_id): void {
		$to = self::get_notification_email();
		if ($to === '') {
			return;
		}
		$subject = __('Nueva reserva pendiente de validar', 'cie-lab-booking');
		$link = admin_url('admin.php?page=cie-lab-booking-booking&booking_id=' . $booking_id);
		$message = sprintf(
			"%s\n\n%s: %s",
			__('Se ha recibido una nueva reserva. Acceda para validar la reserva:', 'cie-lab-booking'),
			__('Enlace', 'cie-lab-booking'),
			$link
		);

		self::send($to, $subject, $message);
	}

	public static function notify_user_booking_status(int $user_id, string $subject, string $message): void {
		$user = get_user_by('id', $user_id);
		if (!$user || empty($user->user_email)) {
			return;
		}

		self::send($user->user_email, $subject, $message);
	}

	private static function send(string $to, string $subject, string $message): void {
		// TODO: Revisar configuración de wp_mail() en el hosting y habilitar envíos reales.
		// Mientras tanto dejamos el envío implementado para cuando esté listo.
		wp_mail($to, $subject, $message);
	}
}

