<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Bookings {
	/**
	 * Create booking post + meta.
	 *
	 * @param array{
	 *   start_date:string,
	 *   end_date:string,
	 *   spaces:array<int>,
	 *   equipment:array<int>,
	 *   project_name:string,
	 *   project_duration:string,
	 *   project_responsible:string,
	 *   project_ip_email:string
	 * } $data
	 *
	 * @return int|\WP_Error Booking post ID.
	 */
	public static function create_booking(int $user_id, array $data) {
		$title = sprintf(
			/* translators: %d: user id */
			__('Reserva %d', 'cie-lab-booking'),
			time()
		);

		$post_id = wp_insert_post([
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'post_title' => $title,
			'post_author' => $user_id,
		]);

		if (is_wp_error($post_id) || !$post_id) {
			return is_wp_error($post_id) ? $post_id : new \WP_Error('cie_booking_create_failed', 'Could not create booking');
		}

		update_post_meta($post_id, '_cie_booking_status', Post_Types::BOOKING_STATUS_PENDING);
		update_post_meta($post_id, '_cie_booking_start_date', $data['start_date']);
		update_post_meta($post_id, '_cie_booking_end_date', $data['end_date']);
		update_post_meta($post_id, '_cie_booking_spaces', array_values(array_map('intval', $data['spaces'])));
		update_post_meta($post_id, '_cie_booking_equipment', array_values(array_map('intval', $data['equipment'])));

		update_post_meta($post_id, '_cie_booking_project_name', $data['project_name']);
		update_post_meta($post_id, '_cie_booking_project_duration', $data['project_duration']);
		update_post_meta($post_id, '_cie_booking_project_responsible', $data['project_responsible']);
		update_post_meta($post_id, '_cie_booking_project_ip_email', $data['project_ip_email']);

		return (int) $post_id;
	}

	public static function get_booking(int $booking_id): ?\WP_Post {
		$post = get_post($booking_id);
		if (!$post || $post->post_type !== Post_Types::CPT_BOOKING) {
			return null;
		}
		return $post;
	}
}

