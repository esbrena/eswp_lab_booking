<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/class-util.php';
require_once __DIR__ . '/class-post-types.php';
require_once __DIR__ . '/class-assets.php';
require_once __DIR__ . '/class-mailer.php';
require_once __DIR__ . '/class-bookings.php';
require_once __DIR__ . '/class-shortcodes.php';
require_once __DIR__ . '/class-admin.php';

final class Bootstrap {
	public static function init(): void {
		Post_Types::init();
		Assets::init();
		Shortcodes::init();
		Admin::init();
	}

	public static function activate(): void {
		Post_Types::register();
		flush_rewrite_rules();

		Post_Types::seed_default_resources();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}

