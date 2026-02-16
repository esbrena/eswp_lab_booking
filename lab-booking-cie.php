<?php
/**
 * Plugin Name:       CIE Lab Booking
 * Description:       Gestión de reservas (espacios/equipos) para el CIE.
 * Version:           0.1.0
 * Author:            CIE
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       cie-lab-booking
 */

if (!defined('ABSPATH')) {
	exit;
}

define('CIE_LAB_BOOKING_VERSION', '0.1.0');
define('CIE_LAB_BOOKING_FILE', __FILE__);
define('CIE_LAB_BOOKING_DIR', __DIR__);
define('CIE_LAB_BOOKING_URL', plugin_dir_url(__FILE__));

require_once CIE_LAB_BOOKING_DIR . '/includes/bootstrap.php';

register_activation_hook(__FILE__, ['CIE_Lab_Booking\\Bootstrap', 'activate']);
register_deactivation_hook(__FILE__, ['CIE_Lab_Booking\\Bootstrap', 'deactivate']);

add_action('plugins_loaded', ['CIE_Lab_Booking\\Bootstrap', 'init']);

