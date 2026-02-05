<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Mailer {
	/**
	 * Hosting no envía mails actualmente.
	 *
	 * TODO: Sustituir esto por los administradores reales (o una opción del plugin)
	 * cuando el hosting tenga el envío de correo configurado.
	 */
	public const ADMIN_FALLBACK_EMAIL = 'esther.g.brena@gmail.com';

	public static function notify_admin_booking_submitted(int $booking_id): void {
		$subject = __('Nueva reserva pendiente de validar', 'cie-lab-booking');
		$link = admin_url('admin.php?page=cie-lab-booking-booking&booking_id=' . $booking_id);
		$message = sprintf(
			"%s\n\n%s: %s",
			__('Se ha recibido una nueva reserva. Acceda para validar la reserva:', 'cie-lab-booking'),
			__('Enlace', 'cie-lab-booking'),
			$link
		);

		self::send(self::ADMIN_FALLBACK_EMAIL, $subject, $message);
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

