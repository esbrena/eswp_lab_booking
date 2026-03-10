<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Admin {
	public static function init(): void {
		add_action('admin_menu', [self::class, 'register_menu']);
		add_action('add_meta_boxes', [self::class, 'register_meta_boxes']);
		add_action('save_post', [self::class, 'save_meta_boxes'], 10, 2);

		add_filter('manage_' . Post_Types::CPT_BOOKING . '_posts_columns', [self::class, 'booking_columns']);
		add_action('manage_' . Post_Types::CPT_BOOKING . '_posts_custom_column', [self::class, 'booking_column_content'], 10, 2);
		add_filter('manage_' . Post_Types::CPT_RESOURCE . '_posts_columns', [self::class, 'resource_columns']);
		add_action('manage_' . Post_Types::CPT_RESOURCE . '_posts_custom_column', [self::class, 'resource_column_content'], 10, 2);
		add_filter('manage_' . Post_Types::CPT_BLOCK . '_posts_columns', [self::class, 'block_columns']);
		add_action('manage_' . Post_Types::CPT_BLOCK . '_posts_custom_column', [self::class, 'block_column_content'], 10, 2);

		add_action('restrict_manage_posts', [self::class, 'render_list_filters']);
		add_action('pre_get_posts', [self::class, 'apply_list_filters']);

		add_filter('post_row_actions', [self::class, 'booking_row_actions'], 10, 2);
	}

	public static function register_menu(): void {
		add_menu_page(
			__('Calendario y reservas', 'cie-lab-booking'),
			__('Calendario y reservas', 'cie-lab-booking'),
			'manage_options',
			'cie-lab-booking-calendar',
			[self::class, 'render_calendar'],
			'dashicons-calendar-alt',
			26
		);

		add_submenu_page(
			'cie-lab-booking-calendar',
			__('Calendario y reservas', 'cie-lab-booking'),
			__('Calendario y reservas', 'cie-lab-booking'),
			'manage_options',
			'cie-lab-booking-calendar',
			[self::class, 'render_calendar']
		);

		add_submenu_page(
			'cie-lab-booking-calendar',
			__('Ajustes y notificaciones', 'cie-lab-booking'),
			__('Ajustes y notificaciones', 'cie-lab-booking'),
			'manage_options',
			'cie-lab-booking-settings',
			[self::class, 'render_dashboard']
		);

		add_submenu_page(
			'cie-lab-booking-calendar',
			__('Reservas', 'cie-lab-booking'),
			__('Reservas', 'cie-lab-booking'),
			'manage_options',
			'edit.php?post_type=' . Post_Types::CPT_BOOKING
		);

		add_submenu_page(
			'cie-lab-booking-calendar',
			__('Recursos', 'cie-lab-booking'),
			__('Recursos', 'cie-lab-booking'),
			'manage_options',
			'edit.php?post_type=' . Post_Types::CPT_RESOURCE
		);

		add_submenu_page(
			'cie-lab-booking-calendar',
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
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('No tienes permisos.', 'cie-lab-booking'), 403);
		}

		if (!empty($_POST['cie_lab_booking_group_action'])) {
			check_admin_referer('cie_lab_booking_manage_groups', '_wpnonce_cie_lab_booking_groups');

			$action = sanitize_key((string) $_POST['cie_lab_booking_group_action']);
			$group_slug = sanitize_key((string) ($_POST['cie_group_slug'] ?? ''));
			$group_label = sanitize_text_field((string) ($_POST['cie_group_label'] ?? ''));

			if ($action === 'add') {
				$group_slug = $group_slug !== '' ? $group_slug : sanitize_title($group_label);
				if ($group_slug === '') {
					Util::admin_notice(__('Debe indicar un nombre de grupo válido.', 'cie-lab-booking'), 'error');
				} else {
					Bookings::upsert_equipment_group_label($group_slug, $group_label);
					Util::admin_notice(__('Grupo creado.', 'cie-lab-booking'), 'success');
				}
			} elseif ($action === 'update') {
				if ($group_slug === '') {
					Util::admin_notice(__('Grupo no válido.', 'cie-lab-booking'), 'error');
				} else {
					Bookings::upsert_equipment_group_label($group_slug, $group_label);
					Util::admin_notice(__('Grupo actualizado.', 'cie-lab-booking'), 'success');
				}
			} elseif ($action === 'delete') {
				if ($group_slug === '' || $group_slug === 'other') {
					Util::admin_notice(__('No se puede eliminar este grupo.', 'cie-lab-booking'), 'error');
				} else {
					Bookings::delete_equipment_group($group_slug, 'other');
					Util::admin_notice(__('Grupo eliminado. Los equipos asociados se han movido a "Otros".', 'cie-lab-booking'), 'success');
				}
			}
		}

		// Settings: notification email.
		if (!empty($_POST['cie_lab_booking_save_settings'])) {
			check_admin_referer('cie_lab_booking_save_settings', '_wpnonce_cie_lab_booking_settings');

			$email = trim((string) ($_POST['cie_lab_booking_notification_email'] ?? ''));
			if ($email !== '' && !is_email($email)) {
				Util::admin_notice(__('El correo de notificación no es válido.', 'cie-lab-booking'), 'error');
			} else {
				if ($email === '') {
					delete_option(Mailer::OPTION_NOTIFICATION_EMAIL);
				} else {
					update_option(Mailer::OPTION_NOTIFICATION_EMAIL, $email, false);
				}
				Util::admin_notice(__('Ajustes guardados.', 'cie-lab-booking'), 'success');
			}
		}

		$current_email = trim((string) get_option(Mailer::OPTION_NOTIFICATION_EMAIL, ''));
		$default_admin_email = trim((string) get_option('admin_email', ''));

		echo '<div class="wrap"><h1>' . esc_html__('Ajustes de reservas', 'cie-lab-booking') . '</h1>';
		echo '<p>' . esc_html__('Configura las notificaciones del sistema de reservas.', 'cie-lab-booking') . '</p>';

		echo '<hr/>';
		echo '<h2>' . esc_html__('Notificaciones', 'cie-lab-booking') . '</h2>';
		echo '<p>' . esc_html__('Define el correo al que se enviarán las notificaciones de nuevas reservas. Si lo dejas vacío, se usará el correo del administrador del sitio.', 'cie-lab-booking') . '</p>';

		echo '<form method="post" style="max-width:560px">';
		wp_nonce_field('cie_lab_booking_save_settings', '_wpnonce_cie_lab_booking_settings');
		echo '<input type="hidden" name="cie_lab_booking_save_settings" value="1" />';

		echo '<p><label><strong>' . esc_html__('Correo de notificación', 'cie-lab-booking') . '</strong><br/>';
		printf(
			'<input type="email" name="cie_lab_booking_notification_email" value="%1$s" placeholder="%2$s" style="width:100%%" />',
			esc_attr($current_email),
			esc_attr($default_admin_email)
		);
		echo '</label></p>';
		echo '<p><button class="button button-primary">' . esc_html__('Guardar', 'cie-lab-booking') . '</button></p>';
		echo '</form>';

		$groups_map = Bookings::get_equipment_groups_map();
		$group_counts = Bookings::get_equipment_group_resource_counts();
		echo '<hr/>';
		echo '<h2>' . esc_html__('Grupos de equipos', 'cie-lab-booking') . '</h2>';
		echo '<p>' . esc_html__('Desde aquí puede renombrar o eliminar grupos. Al eliminar un grupo, sus equipos pasan automáticamente al grupo "Otros".', 'cie-lab-booking') . '</p>';

		echo '<table class="widefat striped" style="max-width:980px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__('Slug', 'cie-lab-booking') . '</th>';
		echo '<th>' . esc_html__('Nombre visible', 'cie-lab-booking') . '</th>';
		echo '<th>' . esc_html__('Equipos', 'cie-lab-booking') . '</th>';
		echo '<th>' . esc_html__('Acciones', 'cie-lab-booking') . '</th>';
		echo '</tr></thead><tbody>';
		foreach ($groups_map as $group_slug => $group_label) {
			echo '<tr>';
			echo '<td><code>' . esc_html($group_slug) . '</code></td>';
			echo '<td>';
			echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
			wp_nonce_field('cie_lab_booking_manage_groups', '_wpnonce_cie_lab_booking_groups');
			echo '<input type="hidden" name="cie_lab_booking_group_action" value="update" />';
			echo '<input type="hidden" name="cie_group_slug" value="' . esc_attr($group_slug) . '" />';
			echo '<input type="text" name="cie_group_label" value="' . esc_attr($group_label) . '" style="min-width:220px;" />';
			echo '<button class="button button-secondary">' . esc_html__('Guardar nombre', 'cie-lab-booking') . '</button>';
			echo '</form>';
			echo '</td>';
			echo '<td>' . (int) ($group_counts[$group_slug] ?? 0) . '</td>';
			echo '<td>';
			if ($group_slug !== 'other') {
				echo '<form method="post" style="display:inline-block">';
				wp_nonce_field('cie_lab_booking_manage_groups', '_wpnonce_cie_lab_booking_groups');
				echo '<input type="hidden" name="cie_lab_booking_group_action" value="delete" />';
				echo '<input type="hidden" name="cie_group_slug" value="' . esc_attr($group_slug) . '" />';
				echo '<button class="button" onclick="return confirm(\'' . esc_js(__('¿Eliminar grupo?', 'cie-lab-booking')) . '\');">' . esc_html__('Eliminar', 'cie-lab-booking') . '</button>';
				echo '</form>';
			} else {
				echo '—';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<h3 style="margin-top:16px;">' . esc_html__('Crear nuevo grupo', 'cie-lab-booking') . '</h3>';
		echo '<form method="post" style="max-width:680px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
		wp_nonce_field('cie_lab_booking_manage_groups', '_wpnonce_cie_lab_booking_groups');
		echo '<input type="hidden" name="cie_lab_booking_group_action" value="add" />';
		echo '<p style="margin:0"><label>' . esc_html__('Nombre visible', 'cie-lab-booking') . '<br/><input type="text" name="cie_group_label" required /></label></p>';
		echo '<p style="margin:0"><label>' . esc_html__('Slug (opcional)', 'cie-lab-booking') . '<br/><input type="text" name="cie_group_slug" placeholder="' . esc_attr__('se-genera-automaticamente', 'cie-lab-booking') . '" /></label></p>';
		echo '<p style="margin:0"><button class="button button-primary">' . esc_html__('Crear grupo', 'cie-lab-booking') . '</button></p>';
		echo '</form>';

		echo '</div>';
	}

	public static function render_calendar(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('No tienes permisos.', 'cie-lab-booking'), 403);
		}

		// Quick-create maintenance block.
		if (!empty($_POST['cie_block_submit'])) {
			check_admin_referer('cie_block_submit', '_wpnonce_cie_block');

			$start = Util::normalize_date_ymd((string) ($_POST['block_start_date'] ?? ''));
			$end = Util::normalize_date_ymd((string) ($_POST['block_end_date'] ?? ''));
			$reason = sanitize_text_field((string) ($_POST['block_reason'] ?? ''));
			$block_all = !empty($_POST['block_all_resources']);
			$resource_ids = $block_all
				? []
				: array_values(array_filter(array_map('intval', (array) ($_POST['block_resource_ids'] ?? []))));

			if (!$start || !$end || $end < $start) {
				Util::admin_notice(__('Fechas de bloqueo inválidas.', 'cie-lab-booking'), 'error');
			} else {
				$block_id = wp_insert_post([
					'post_type' => Post_Types::CPT_BLOCK,
					'post_status' => 'publish',
					'post_title' => sprintf(__('Bloqueo %s - %s', 'cie-lab-booking'), $start, $end),
				]);

				if ($block_id && !is_wp_error($block_id)) {
					update_post_meta($block_id, '_cie_block_start_date', $start);
					update_post_meta($block_id, '_cie_block_end_date', $end);
					update_post_meta($block_id, '_cie_block_reason', $reason);
					update_post_meta($block_id, '_cie_block_resource_ids', $resource_ids);
					Util::admin_notice(__('Bloqueo creado.', 'cie-lab-booking'), 'success');
				} else {
					Util::admin_notice(__('No se pudo crear el bloqueo.', 'cie-lab-booking'), 'error');
				}
			}
		}

		$month = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', (string) $_GET['month']) : gmdate('Y-m');
		if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
			$month = gmdate('Y-m');
		}
		$booking_status_filter = isset($_GET['booking_status']) ? sanitize_key((string) $_GET['booking_status']) : '';

		// Admin calendar: show 3 months (same as front), starting from selected month.
		$start = $month . '-01';
		$end = gmdate('Y-m-d', strtotime('+3 months -1 day', strtotime($start)));
		$day_map = Bookings::build_day_map($start, $end);

		$bookings_query = [
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'posts_per_page' => 20,
			'orderby' => 'date',
			'order' => 'DESC',
		];
		if ($booking_status_filter !== '') {
			$bookings_query['meta_query'] = [
				[
					'key' => '_cie_booking_status',
					'value' => $booking_status_filter,
				],
			];
		}
		$bookings = get_posts($bookings_query);

		echo '<div class="wrap"><h1>' . esc_html__('Calendario y reservas', 'cie-lab-booking') . '</h1>';

		// Month selector (up to 24 months ahead).
		echo '<form method="get" class="cie-admin-calendar-toolbar">';
		echo '<input type="hidden" name="page" value="cie-lab-booking-calendar" />';
		echo '<label>' . esc_html__('Mes', 'cie-lab-booking') . ' ';
		echo '<select name="month">';
		$base = strtotime(gmdate('Y-m-01') . ' 00:00:00');
		for ($i = 0; $i <= 24; $i++) {
			$ts = strtotime('+' . $i . ' months', $base);
			$val = gmdate('Y-m', $ts);
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr($val),
				selected($val, $month, false),
				esc_html(date_i18n('F Y', $ts))
			);
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__('Estado reserva', 'cie-lab-booking') . ' ';
		echo '<select name="booking_status">';
		printf('<option value="">%s</option>', esc_html__('Todos', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_PENDING), selected($booking_status_filter, Post_Types::BOOKING_STATUS_PENDING, false), esc_html(self::status_label(Post_Types::BOOKING_STATUS_PENDING)));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_APPROVED), selected($booking_status_filter, Post_Types::BOOKING_STATUS_APPROVED, false), esc_html(self::status_label(Post_Types::BOOKING_STATUS_APPROVED)));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_CHANGES), selected($booking_status_filter, Post_Types::BOOKING_STATUS_CHANGES, false), esc_html(self::status_label(Post_Types::BOOKING_STATUS_CHANGES)));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_REJECTED), selected($booking_status_filter, Post_Types::BOOKING_STATUS_REJECTED, false), esc_html(self::status_label(Post_Types::BOOKING_STATUS_REJECTED)));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_CANCELLED), selected($booking_status_filter, Post_Types::BOOKING_STATUS_CANCELLED, false), esc_html(self::status_label(Post_Types::BOOKING_STATUS_CANCELLED)));
		echo '</select></label> ';
		echo '<button class="button">' . esc_html__('Ver', 'cie-lab-booking') . '</button>';
		echo '</form>';

		echo '<div class="cie-admin-calendar-layout">';
		echo '<section class="cie-admin-calendar-layout__left">';

		// Legend.
		echo '<div class="cie-lab-booking-admin__legend">';
		self::legend_item('#e5e7eb', __('Sin reservas', 'cie-lab-booking'));
		self::legend_item('#f59e0b', __('Reserva de espacios', 'cie-lab-booking'));
		self::legend_item('#3b82f6', __('Reserva de equipos', 'cie-lab-booking'));
		self::legend_item('#22c55e', __('Espacios + equipos', 'cie-lab-booking'));
		self::legend_item('#ef4444', __('No disponible (mantenimiento)', 'cie-lab-booking'));
		echo '</div>';

		// Calendar for 3 months (like front).
		echo self::render_calendar_months($start, 3, $day_map);
		echo '</section>';

		echo '<aside class="cie-admin-calendar-layout__right">';
		echo '<section class="cie-admin-panel">';
		echo '<h2>' . esc_html__('Reservas recientes', 'cie-lab-booking') . '</h2>';
		if (!$bookings) {
			echo '<p><em>' . esc_html__('No hay reservas para el filtro seleccionado.', 'cie-lab-booking') . '</em></p>';
		} else {
			echo '<table class="widefat striped cie-admin-mini-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__('Fechas', 'cie-lab-booking') . '</th>';
			echo '<th>' . esc_html__('Estado', 'cie-lab-booking') . '</th>';
			echo '<th>' . esc_html__('Usuario', 'cie-lab-booking') . '</th>';
			echo '<th>' . esc_html__('Recursos', 'cie-lab-booking') . '</th>';
			echo '</tr></thead><tbody>';
			foreach ($bookings as $b) {
				$link = admin_url('admin.php?page=cie-lab-booking-booking&booking_id=' . (int) $b->ID);
				$start_b = (string) get_post_meta($b->ID, '_cie_booking_start_date', true);
				$end_b = (string) get_post_meta($b->ID, '_cie_booking_end_date', true);
				$status = (string) get_post_meta($b->ID, '_cie_booking_status', true);
				$user = get_user_by('id', (int) $b->post_author);
				$user_name = $user ? (string) $user->display_name : (string) $b->post_author;
				$resources = self::resources_summary(
					(array) get_post_meta((int) $b->ID, '_cie_booking_spaces', true),
					(array) get_post_meta((int) $b->ID, '_cie_booking_equipment', true)
				);
				echo '<tr>';
				echo '<td><a href="' . esc_url($link) . '">' . esc_html($start_b . ' - ' . $end_b) . '</a><br/><small>' . esc_html($b->post_title) . '</small></td>';
				echo '<td>' . self::status_badge($status) . '<br/>' . self::activity_badge((int) $b->ID) . '</td>';
				echo '<td>' . esc_html($user_name) . '</td>';
				echo '<td>' . esc_html($resources) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';

		// Quick block form.
		$resources = get_posts([
			'post_type' => Post_Types::CPT_RESOURCE,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		]);
		echo '<section class="cie-admin-panel">';
		echo '<h2>' . esc_html__('Crear bloqueo por mantenimiento', 'cie-lab-booking') . '</h2>';
		echo '<form method="post">';
		wp_nonce_field('cie_block_submit', '_wpnonce_cie_block');
		echo '<input type="hidden" name="cie_block_submit" value="1" />';
		echo '<p><label>' . esc_html__('Desde', 'cie-lab-booking') . ' <input class="cie-date" type="text" name="block_start_date" placeholder="YYYY-MM-DD" required /></label></p>';
		echo '<p><label>' . esc_html__('Hasta', 'cie-lab-booking') . ' <input class="cie-date" type="text" name="block_end_date" placeholder="YYYY-MM-DD" required /></label></p>';
		echo '<p><label>' . esc_html__('Motivo', 'cie-lab-booking') . '<br/><input type="text" name="block_reason" style="width:420px" /></label></p>';
		echo '<label class="cie-admin-toggle">';
		echo '<input type="checkbox" name="block_all_resources" value="1" data-cie-all-toggle="1" /> ';
		echo esc_html__('Bloquear todos los recursos', 'cie-lab-booking');
		echo '</label>';
		echo '<div class="cie-admin-resource-picker" data-cie-resource-picker="1">';
		echo '<p><strong>' . esc_html__('Selección manual de recursos', 'cie-lab-booking') . '</strong></p>';
		foreach ($resources as $r) {
			printf(
				'<label><input type="checkbox" name="block_resource_ids[]" value="%1$d" /> %2$s <small>(%3$s)</small></label>',
				(int) $r->ID,
				esc_html($r->post_title),
				esc_html(self::resource_kind_label((string) get_post_meta((int) $r->ID, '_cie_resource_kind', true)))
			);
		}
		echo '</div>';
		echo '<p><button class="button button-primary">' . esc_html__('Crear bloqueo', 'cie-lab-booking') . '</button></p>';
		echo '</form>';
		echo '</section>';
		echo '</aside>';
		echo '</div>';

		echo '</div>';
	}

	public static function render_booking_review(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('No tienes permisos.', 'cie-lab-booking'), 403);
		}

		$booking_id = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
		$booking = $booking_id ? Bookings::get_booking($booking_id) : null;
		if (!$booking) {
			wp_die(esc_html__('Reserva no encontrada.', 'cie-lab-booking'), 404);
		}

		$status = (string) get_post_meta($booking_id, '_cie_booking_status', true);
		$start = (string) get_post_meta($booking_id, '_cie_booking_start_date', true);
		$end = (string) get_post_meta($booking_id, '_cie_booking_end_date', true);
		$spaces = (array) get_post_meta($booking_id, '_cie_booking_spaces', true);
		$equipment = (array) get_post_meta($booking_id, '_cie_booking_equipment', true);
		$admin_message = (string) get_post_meta($booking_id, '_cie_booking_admin_message', true);

		$project_name = (string) get_post_meta($booking_id, '_cie_booking_project_name', true);
		$project_duration = (string) get_post_meta($booking_id, '_cie_booking_project_duration', true);
		$project_responsible = (string) get_post_meta($booking_id, '_cie_booking_project_responsible', true);
		$project_ip_email = (string) get_post_meta($booking_id, '_cie_booking_project_ip_email', true);

		$user_id = (int) $booking->post_author;

		if (!empty($_POST['cie_booking_admin_action'])) {
			check_admin_referer('cie_booking_admin_action', '_wpnonce_cie_booking_admin');

			$action = sanitize_key((string) $_POST['cie_booking_admin_action']);
			$message = sanitize_textarea_field((string) ($_POST['cie_booking_admin_message'] ?? ''));

			if ($action === 'approve') {
				$conflicts = Bookings::find_conflicts($start, $end, array_map('intval', $spaces), array_map('intval', $equipment), $booking_id);
				if (!empty($conflicts['spaces']) || !empty($conflicts['equipment']) || !empty($conflicts['blocked'])) {
					Util::admin_notice(__('La reserva no puede validarse porque entra en conflicto con reservas ya validadas o con un bloqueo de mantenimiento.', 'cie-lab-booking'), 'error');
				} else {
					Bookings::set_booking_status($booking_id, Post_Types::BOOKING_STATUS_APPROVED);
					Mailer::notify_user_booking_status(
						$user_id,
						__('Reserva validada', 'cie-lab-booking'),
						sprintf(
							"%s\n\n%s: %s - %s",
							__('Su reserva de espacios/equipos en el Laboratorio de Lingüística Experimental ha sido validada.', 'cie-lab-booking'),
							__('Fechas', 'cie-lab-booking'),
							$start,
							$end
						)
					);
					Util::admin_notice(__('Reserva validada.', 'cie-lab-booking'), 'success');
					$status = Post_Types::BOOKING_STATUS_APPROVED;
				}
			} elseif ($action === 'reject') {
				Bookings::set_booking_status($booking_id, Post_Types::BOOKING_STATUS_REJECTED, $message);
				Mailer::notify_user_booking_status(
					$user_id,
					__('Reserva no validada', 'cie-lab-booking'),
					sprintf(
						"%s\n\n%s",
						__('Su reserva de espacios/equipos en el Laboratorio de Lingüística Experimental no ha sido validada.', 'cie-lab-booking'),
						$message
					)
				);
				Util::admin_notice(__('Reserva rechazada.', 'cie-lab-booking'), 'success');
				$status = Post_Types::BOOKING_STATUS_REJECTED;
				$admin_message = $message;
			} elseif ($action === 'changes') {
				Bookings::set_booking_status($booking_id, Post_Types::BOOKING_STATUS_CHANGES, $message);
				Mailer::notify_user_booking_status(
					$user_id,
					__('Cambios solicitados en su reserva', 'cie-lab-booking'),
					sprintf(
						"%s\n\n%s",
						__('Su reserva de espacios/equipos en el Laboratorio está pendiente de validar.', 'cie-lab-booking'),
						$message
					)
				);
				Util::admin_notice(__('Cambios solicitados enviados al usuario.', 'cie-lab-booking'), 'success');
				$status = Post_Types::BOOKING_STATUS_CHANGES;
				$admin_message = $message;
			} elseif ($action === 'cancel') {
				Bookings::set_booking_status($booking_id, Post_Types::BOOKING_STATUS_CANCELLED, $message);
				Mailer::notify_user_booking_status(
					$user_id,
					__('Reserva anulada', 'cie-lab-booking'),
					sprintf(
						"%s\n\n%s",
						__('El administrador ha anulado una reserva previa por razones técnicas.', 'cie-lab-booking'),
						$message
					)
				);
				Util::admin_notice(__('Reserva anulada.', 'cie-lab-booking'), 'success');
				$status = Post_Types::BOOKING_STATUS_CANCELLED;
				$admin_message = $message;
			}
		}

		echo '<div class="wrap"><h1>' . esc_html__('Revisar reserva', 'cie-lab-booking') . '</h1>';
		$edit_post_url = get_edit_post_link($booking_id, '');
		if ($edit_post_url) {
			echo '<p><a class="button button-secondary" href="' . esc_url($edit_post_url) . '">' . esc_html__('Editar reserva', 'cie-lab-booking') . '</a></p>';
		}

		echo '<p><strong>' . esc_html__('Estado:', 'cie-lab-booking') . '</strong> ' . esc_html(self::status_label($status)) . '</p>';
		echo '<p><strong>' . esc_html__('Fechas:', 'cie-lab-booking') . '</strong> ' . esc_html($start . ' - ' . $end) . '</p>';
		echo '<p><strong>' . esc_html__('Usuario:', 'cie-lab-booking') . '</strong> ' . esc_html((string) $user_id) . '</p>';

		echo '<h2>' . esc_html__('Recursos', 'cie-lab-booking') . '</h2>';
		echo '<ul>';
		foreach (array_merge((array) $spaces, (array) $equipment) as $rid) {
			$p = get_post((int) $rid);
			if ($p && $p->post_type === Post_Types::CPT_RESOURCE) {
				echo '<li>' . esc_html($p->post_title) . '</li>';
			}
		}
		echo '</ul>';

		echo '<h2>' . esc_html__('Proyecto', 'cie-lab-booking') . '</h2>';
		echo '<ul>';
		echo '<li><strong>' . esc_html__('Nombre:', 'cie-lab-booking') . '</strong> ' . esc_html($project_name) . '</li>';
		echo '<li><strong>' . esc_html__('Duración:', 'cie-lab-booking') . '</strong> ' . esc_html($project_duration) . '</li>';
		echo '<li><strong>' . esc_html__('Responsable:', 'cie-lab-booking') . '</strong> ' . esc_html($project_responsible) . '</li>';
		echo '<li><strong>' . esc_html__('IP/Director/a email:', 'cie-lab-booking') . '</strong> ' . esc_html($project_ip_email) . '</li>';
		echo '</ul>';

		echo '<h2>' . esc_html__('Acciones', 'cie-lab-booking') . '</h2>';
		echo '<form method="post">';
		wp_nonce_field('cie_booking_admin_action', '_wpnonce_cie_booking_admin');
		echo '<p><label>' . esc_html__('Mensaje (para rechazo/cambios/anulación)', 'cie-lab-booking') . '<br/>';
		printf('<textarea name="cie_booking_admin_message" rows="4" style="width:520px;">%s</textarea></label></p>', esc_textarea($admin_message));

		echo '<p>';
		echo '<button class="button button-primary" name="cie_booking_admin_action" value="approve">' . esc_html__('Validar', 'cie-lab-booking') . '</button> ';
		echo '<button class="button" name="cie_booking_admin_action" value="changes">' . esc_html__('Solicitar cambios', 'cie-lab-booking') . '</button> ';
		echo '<button class="button" name="cie_booking_admin_action" value="reject">' . esc_html__('Rechazar', 'cie-lab-booking') . '</button> ';
		echo '<button class="button" name="cie_booking_admin_action" value="cancel" onclick="return confirm(\'¿Anular reserva?\');">' . esc_html__('Anular', 'cie-lab-booking') . '</button>';
		echo '</p>';

		echo '</form>';

		echo '</div>';
	}

	public static function register_meta_boxes(): void {
		add_meta_box(
			'cie_resource_meta',
			__('Datos del recurso', 'cie-lab-booking'),
			[self::class, 'render_resource_meta_box'],
			Post_Types::CPT_RESOURCE,
			'normal',
			'default'
		);

		add_meta_box(
			'cie_booking_meta',
			__('Detalle de la reserva (CIE)', 'cie-lab-booking'),
			[self::class, 'render_booking_meta_box'],
			Post_Types::CPT_BOOKING,
			'normal',
			'default'
		);

		add_meta_box(
			'cie_block_meta',
			__('Bloqueo por mantenimiento', 'cie-lab-booking'),
			[self::class, 'render_block_meta_box'],
			Post_Types::CPT_BLOCK,
			'normal',
			'default'
		);
	}

	public static function render_resource_meta_box(\WP_Post $post): void {
		wp_nonce_field('cie_resource_meta', '_wpnonce_cie_resource_meta');
		$kind = (string) get_post_meta($post->ID, '_cie_resource_kind', true);
		$group = (string) get_post_meta($post->ID, '_cie_resource_group', true);
		$code = (string) get_post_meta($post->ID, '_cie_resource_code', true);
		$available = (string) get_post_meta($post->ID, '_cie_resource_available', true);
		$quantity = (int) get_post_meta($post->ID, '_cie_resource_quantity', true);
		if ($quantity < 1) {
			$quantity = 1;
		}

		$groups_map = Bookings::get_equipment_groups_map();
		$group = sanitize_key($group);
		if ($group !== '' && !isset($groups_map[$group])) {
			$groups_map[$group] = Bookings::get_equipment_group_label($group);
		}
		$group_existing = isset($groups_map[$group]) ? $group : '';
		$group_new = $group_existing === '' ? $group : '';

		echo '<p><label>' . esc_html__('Tipo', 'cie-lab-booking') . ' ';
		echo '<select name="cie_resource_kind" data-cie-resource-kind="1">';
		printf('<option value="space" %s>%s</option>', selected($kind, 'space', false), esc_html__('Espacio', 'cie-lab-booking'));
		printf('<option value="equipment" %s>%s</option>', selected($kind, 'equipment', false), esc_html__('Equipo', 'cie-lab-booking'));
		echo '</select></label></p>';

		echo '<div data-cie-resource-group-wrap="1">';
		echo '<p><label>' . esc_html__('Grupo de equipo', 'cie-lab-booking') . ' ';
		echo '<select name="cie_resource_group_existing" data-cie-resource-group-existing="1">';
		echo '<option value="">' . esc_html__('Seleccione un grupo', 'cie-lab-booking') . '</option>';
		foreach ($groups_map as $g => $g_label) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr($g),
				selected($group_existing, $g, false),
				esc_html($g_label)
			);
		}
		echo '<option value="__new__"' . selected($group_existing === '' && $group_new !== '', true, false) . '>' . esc_html__('Crear grupo nuevo…', 'cie-lab-booking') . '</option>';
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__('Nuevo grupo', 'cie-lab-booking') . ' ';
		printf('<input type="text" name="cie_resource_group_new" value="%s" placeholder="%s" />', esc_attr($group_new), esc_attr__('Ej.: microscopios', 'cie-lab-booking'));
		echo '</label></p>';
		echo '</div>';

		echo '<p data-cie-resource-quantity-wrap="1"><label>' . esc_html__('Cantidad disponible', 'cie-lab-booking') . ' ';
		printf('<input type="number" name="cie_resource_quantity" min="1" step="1" value="%d" />', (int) $quantity);
		echo '</label> <small>' . esc_html__('Para equipos: cuántas unidades del mismo equipo hay disponibles.', 'cie-lab-booking') . '</small></p>';

		echo '<p><label>' . esc_html__('Código/ID', 'cie-lab-booking') . ' ';
		printf('<input type="text" name="cie_resource_code" value="%s" />', esc_attr($code));
		echo '</label></p>';

		echo '<p><label>';
		printf('<input type="checkbox" name="cie_resource_available" value="1" %s /> ', checked($available, '1', false));
		echo esc_html__('Disponible', 'cie-lab-booking');
		echo '</label></p>';

		$history = Bookings::get_resource_booking_history((int) $post->ID, 80);
		echo '<hr/>';
		echo '<h3>' . esc_html__('Histórico de reservas de este recurso', 'cie-lab-booking') . '</h3>';
		if (!$history) {
			echo '<p><em>' . esc_html__('Sin reservas todavía.', 'cie-lab-booking') . '</em></p>';
			return;
		}

		echo '<table class="widefat striped cie-admin-mini-table"><thead><tr>';
		echo '<th>' . esc_html__('Fechas', 'cie-lab-booking') . '</th>';
		echo '<th>' . esc_html__('Usuario', 'cie-lab-booking') . '</th>';
		echo '<th>' . esc_html__('Estado', 'cie-lab-booking') . '</th>';
		echo '<th>' . esc_html__('Reserva', 'cie-lab-booking') . '</th>';
		echo '</tr></thead><tbody>';
		foreach ($history as $item) {
			echo '<tr>';
			echo '<td>' . esc_html($item['start_date'] . ' - ' . $item['end_date']) . '</td>';
			echo '<td>' . esc_html($item['user_name']) . '</td>';
			echo '<td>' . self::status_badge((string) $item['status']) . '<br/>' . ($item['is_active'] ? '<span class="cie-admin-badge is-active">' . esc_html__('Activa hoy', 'cie-lab-booking') . '</span>' : '') . '</td>';
			echo '<td><a href="' . esc_url((string) $item['detail_url']) . '">' . esc_html__('Ver reserva', 'cie-lab-booking') . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public static function render_block_meta_box(\WP_Post $post): void {
		wp_nonce_field('cie_block_meta', '_wpnonce_cie_block_meta');
		$start = (string) get_post_meta($post->ID, '_cie_block_start_date', true);
		$end = (string) get_post_meta($post->ID, '_cie_block_end_date', true);
		$reason = (string) get_post_meta($post->ID, '_cie_block_reason', true);
		$resource_ids = (array) get_post_meta($post->ID, '_cie_block_resource_ids', true);
		$is_global = empty($resource_ids);

		$resources = get_posts([
			'post_type' => Post_Types::CPT_RESOURCE,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		]);

		echo '<p><label>' . esc_html__('Desde', 'cie-lab-booking') . ' ';
		printf('<input class="cie-date" type="text" name="cie_block_start_date" value="%s" placeholder="YYYY-MM-DD" />', esc_attr($start));
		echo '</label></p>';

		echo '<p><label>' . esc_html__('Hasta', 'cie-lab-booking') . ' ';
		printf('<input class="cie-date" type="text" name="cie_block_end_date" value="%s" placeholder="YYYY-MM-DD" />', esc_attr($end));
		echo '</label></p>';

		echo '<p><label>' . esc_html__('Motivo', 'cie-lab-booking') . '<br/>';
		printf('<input type="text" name="cie_block_reason" style="width:520px" value="%s" />', esc_attr($reason));
		echo '</label></p>';

		echo '<label class="cie-admin-toggle">';
		printf('<input type="checkbox" name="cie_block_all_resources" value="1" data-cie-all-toggle="1" %s /> ', checked($is_global, true, false));
		echo esc_html__('Bloquear todos los recursos', 'cie-lab-booking');
		echo '</label>';
		echo '<div class="cie-admin-resource-picker" data-cie-resource-picker="1">';
		echo '<p><strong>' . esc_html__('Selección manual de recursos', 'cie-lab-booking') . '</strong></p>';
		foreach ($resources as $r) {
			$selected = in_array((string) $r->ID, array_map('strval', $resource_ids), true);
			printf(
				'<label><input type="checkbox" name="cie_block_resource_ids[]" value="%1$d" %2$s /> %3$s <small>(%4$s)</small></label>',
				(int) $r->ID,
				$selected ? 'checked' : '',
				esc_html($r->post_title),
				esc_html(self::resource_kind_label((string) get_post_meta((int) $r->ID, '_cie_resource_kind', true)))
			);
		}
		echo '</div>';
	}

	public static function render_booking_meta_box(\WP_Post $post): void {
		if (!current_user_can('manage_options')) {
			echo '<p>' . esc_html__('No tienes permisos.', 'cie-lab-booking') . '</p>';
			return;
		}

		wp_nonce_field('cie_booking_meta', '_wpnonce_cie_booking_meta');

		$booking_id = (int) $post->ID;
		$status = (string) get_post_meta($booking_id, '_cie_booking_status', true);
		$start = (string) get_post_meta($booking_id, '_cie_booking_start_date', true);
		$end = (string) get_post_meta($booking_id, '_cie_booking_end_date', true);
		$spaces = (array) get_post_meta($booking_id, '_cie_booking_spaces', true);
		$equipment = (array) get_post_meta($booking_id, '_cie_booking_equipment', true);
		$admin_message = (string) get_post_meta($booking_id, '_cie_booking_admin_message', true);

		$project_name = (string) get_post_meta($booking_id, '_cie_booking_project_name', true);
		$project_duration = (string) get_post_meta($booking_id, '_cie_booking_project_duration', true);
		$project_responsible = (string) get_post_meta($booking_id, '_cie_booking_project_responsible', true);
		$project_ip_email = (string) get_post_meta($booking_id, '_cie_booking_project_ip_email', true);

		$spaces_posts = Bookings::get_resources('space', false);
		$equipment_grouped = Bookings::get_equipment_grouped(false);

		$conflicts = [];
		if ($start && $end && $end >= $start) {
			$conf = Bookings::find_conflicts($start, $end, array_map('intval', (array) $spaces), array_map('intval', (array) $equipment), $booking_id);
			if (!empty($conf['spaces']) || !empty($conf['equipment']) || !empty($conf['blocked'])) {
				$conflicts = $conf;
			}
		}

		$review_link = admin_url('admin.php?page=cie-lab-booking-booking&booking_id=' . $booking_id);

		echo '<p><a class="button" href="' . esc_url($review_link) . '">' . esc_html__('Abrir vista de revisión', 'cie-lab-booking') . '</a></p>';

		if ($conflicts) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__('Aviso: esta reserva entra en conflicto con otras reservas validadas o con un bloqueo de mantenimiento.', 'cie-lab-booking');
			echo '</p></div>';
		}

		echo '<p><label><strong>' . esc_html__('Estado', 'cie-lab-booking') . '</strong><br/>';
		echo '<select name="cie_booking_status">';
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_PENDING), selected($status, Post_Types::BOOKING_STATUS_PENDING, false), esc_html__('Pendiente de validar', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_APPROVED), selected($status, Post_Types::BOOKING_STATUS_APPROVED, false), esc_html__('Validada', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_CHANGES), selected($status, Post_Types::BOOKING_STATUS_CHANGES, false), esc_html__('Cambios solicitados', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_REJECTED), selected($status, Post_Types::BOOKING_STATUS_REJECTED, false), esc_html__('No validada', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_CANCELLED), selected($status, Post_Types::BOOKING_STATUS_CANCELLED, false), esc_html__('Anulada', 'cie-lab-booking'));
		echo '</select></label></p>';

		echo '<p><label><strong>' . esc_html__('Fechas', 'cie-lab-booking') . '</strong><br/>';
		printf(
			'%1$s <input class="cie-date" type="text" name="cie_booking_start_date" value="%2$s" placeholder="YYYY-MM-DD" style="width:140px" /> &nbsp; %3$s <input class="cie-date" type="text" name="cie_booking_end_date" value="%4$s" placeholder="YYYY-MM-DD" style="width:140px" />',
			esc_html__('Desde', 'cie-lab-booking'),
			esc_attr($start),
			esc_html__('Hasta', 'cie-lab-booking'),
			esc_attr($end)
		);
		echo '</label></p>';

		echo '<hr/>';
		echo '<h3 style="margin:0 0 8px;">' . esc_html__('Espacios', 'cie-lab-booking') . '</h3>';
		if (!$spaces_posts) {
			echo '<p><em>' . esc_html__('No hay espacios configurados.', 'cie-lab-booking') . '</em></p>';
		} else {
			foreach ($spaces_posts as $sp) {
				$checked = in_array((string) $sp->ID, array_map('strval', (array) $spaces), true);
				printf(
					'<label style="display:block;margin:4px 0;"><input type="checkbox" name="cie_booking_spaces[]" value="%1$d" %2$s /> %3$s</label>',
					(int) $sp->ID,
					$checked ? 'checked' : '',
					esc_html($sp->post_title)
				);
			}
		}

		echo '<h3 style="margin:14px 0 8px;">' . esc_html__('Equipos', 'cie-lab-booking') . '</h3>';
		if (!$equipment_grouped) {
			echo '<p><em>' . esc_html__('No hay equipos configurados.', 'cie-lab-booking') . '</em></p>';
		} else {
			foreach ($equipment_grouped as $group => $items) {
				echo '<details style="margin:6px 0;"><summary>' . esc_html(self::equipment_group_label((string) $group)) . '</summary>';
				foreach ($items as $eq) {
					$checked = in_array((string) $eq->ID, array_map('strval', (array) $equipment), true);
					printf(
						'<label style="display:block;margin:4px 0 4px 16px;"><input type="checkbox" name="cie_booking_equipment[]" value="%1$d" %2$s /> %3$s</label>',
						(int) $eq->ID,
						$checked ? 'checked' : '',
						esc_html($eq->post_title)
					);
				}
				echo '</details>';
			}
		}

		echo '<hr/>';
		echo '<h3 style="margin:0 0 8px;">' . esc_html__('Proyecto', 'cie-lab-booking') . '</h3>';
		printf('<p><label>%1$s<br/><input type="text" name="cie_booking_project_name" value="%2$s" style="width:520px" /></label></p>', esc_html__('Nombre', 'cie-lab-booking'), esc_attr($project_name));
		printf('<p><label>%1$s<br/><input type="text" name="cie_booking_project_duration" value="%2$s" style="width:520px" /></label></p>', esc_html__('Duración', 'cie-lab-booking'), esc_attr($project_duration));
		printf('<p><label>%1$s<br/><input type="text" name="cie_booking_project_responsible" value="%2$s" style="width:520px" /></label></p>', esc_html__('Responsable', 'cie-lab-booking'), esc_attr($project_responsible));
		printf('<p><label>%1$s<br/><input type="email" name="cie_booking_project_ip_email" value="%2$s" style="width:520px" /></label></p>', esc_html__('Correo IP/Director/a', 'cie-lab-booking'), esc_attr($project_ip_email));

		echo '<hr/>';
		echo '<p><label><strong>' . esc_html__('Mensaje del administrador', 'cie-lab-booking') . '</strong><br/>';
		printf('<textarea name="cie_booking_admin_message" rows="4" style="width:520px;">%s</textarea>', esc_textarea($admin_message));
		echo '</label></p>';
	}

	public static function save_meta_boxes(int $post_id, \WP_Post $post): void {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (!current_user_can('manage_options')) {
			return;
		}

		if ($post->post_type === Post_Types::CPT_RESOURCE) {
			if (empty($_POST['_wpnonce_cie_resource_meta']) || !wp_verify_nonce((string) $_POST['_wpnonce_cie_resource_meta'], 'cie_resource_meta')) {
				return;
			}

			$kind = sanitize_key((string) ($_POST['cie_resource_kind'] ?? ''));
			$group_existing = sanitize_key((string) ($_POST['cie_resource_group_existing'] ?? ''));
			$group_new_label = sanitize_text_field((string) ($_POST['cie_resource_group_new'] ?? ''));
			$group_new = sanitize_title($group_new_label);
			$group = '';
			if ($kind === 'equipment') {
				if ($group_new !== '') {
					$group = $group_new;
					Bookings::upsert_equipment_group_label($group_new, $group_new_label);
				} elseif ($group_existing !== '' && $group_existing !== '__new__') {
					$group = $group_existing;
				}
				if ($group === '') {
					$group = 'other';
				}
			}
			if ($kind === 'space' && $group === '') {
				$group = 'spaces';
			}
			$code = sanitize_text_field((string) ($_POST['cie_resource_code'] ?? ''));
			$available = !empty($_POST['cie_resource_available']) ? '1' : '0';
			$quantity = max(1, (int) ($_POST['cie_resource_quantity'] ?? 1));
			if ($kind !== 'equipment') {
				$quantity = 1;
			}

			update_post_meta($post_id, '_cie_resource_kind', $kind);
			update_post_meta($post_id, '_cie_resource_group', $group);
			update_post_meta($post_id, '_cie_resource_code', $code);
			update_post_meta($post_id, '_cie_resource_available', $available);
			update_post_meta($post_id, '_cie_resource_quantity', $quantity);
		}

		if ($post->post_type === Post_Types::CPT_BLOCK) {
			if (empty($_POST['_wpnonce_cie_block_meta']) || !wp_verify_nonce((string) $_POST['_wpnonce_cie_block_meta'], 'cie_block_meta')) {
				return;
			}

			$start = Util::normalize_date_ymd((string) ($_POST['cie_block_start_date'] ?? ''));
			$end = Util::normalize_date_ymd((string) ($_POST['cie_block_end_date'] ?? ''));
			$reason = sanitize_text_field((string) ($_POST['cie_block_reason'] ?? ''));
			$block_all = !empty($_POST['cie_block_all_resources']);
			$resource_ids = $block_all
				? []
				: array_values(array_filter(array_map('intval', (array) ($_POST['cie_block_resource_ids'] ?? []))));

			if ($start) {
				update_post_meta($post_id, '_cie_block_start_date', $start);
			}
			if ($end) {
				update_post_meta($post_id, '_cie_block_end_date', $end);
			}
			update_post_meta($post_id, '_cie_block_reason', $reason);
			update_post_meta($post_id, '_cie_block_resource_ids', $resource_ids);
		}

		if ($post->post_type === Post_Types::CPT_BOOKING) {
			if (empty($_POST['_wpnonce_cie_booking_meta']) || !wp_verify_nonce((string) $_POST['_wpnonce_cie_booking_meta'], 'cie_booking_meta')) {
				return;
			}

			$status = sanitize_key((string) ($_POST['cie_booking_status'] ?? ''));
			$allowed = [
				Post_Types::BOOKING_STATUS_PENDING,
				Post_Types::BOOKING_STATUS_APPROVED,
				Post_Types::BOOKING_STATUS_REJECTED,
				Post_Types::BOOKING_STATUS_CHANGES,
				Post_Types::BOOKING_STATUS_CANCELLED,
			];
			if (!in_array($status, $allowed, true)) {
				$status = Post_Types::BOOKING_STATUS_PENDING;
			}

			$start = Util::normalize_date_ymd((string) ($_POST['cie_booking_start_date'] ?? ''));
			$end = Util::normalize_date_ymd((string) ($_POST['cie_booking_end_date'] ?? ''));

			$spaces = array_values(array_filter(array_map('intval', (array) ($_POST['cie_booking_spaces'] ?? []))));
			$equipment = array_values(array_filter(array_map('intval', (array) ($_POST['cie_booking_equipment'] ?? []))));

			$project_name = sanitize_text_field((string) ($_POST['cie_booking_project_name'] ?? ''));
			$project_duration = sanitize_text_field((string) ($_POST['cie_booking_project_duration'] ?? ''));
			$project_responsible = sanitize_text_field((string) ($_POST['cie_booking_project_responsible'] ?? ''));
			$project_ip_email = sanitize_text_field((string) ($_POST['cie_booking_project_ip_email'] ?? ''));
			$admin_message = sanitize_textarea_field((string) ($_POST['cie_booking_admin_message'] ?? ''));

			if ($start) {
				update_post_meta($post_id, '_cie_booking_start_date', $start);
			}
			if ($end) {
				update_post_meta($post_id, '_cie_booking_end_date', $end);
			}

			update_post_meta($post_id, '_cie_booking_spaces', $spaces);
			update_post_meta($post_id, '_cie_booking_equipment', $equipment);
			update_post_meta($post_id, '_cie_booking_project_name', $project_name);
			update_post_meta($post_id, '_cie_booking_project_duration', $project_duration);
			update_post_meta($post_id, '_cie_booking_project_responsible', $project_responsible);
			update_post_meta($post_id, '_cie_booking_project_ip_email', $project_ip_email);

			if ($admin_message !== '') {
				update_post_meta($post_id, '_cie_booking_admin_message', $admin_message);
			} else {
				delete_post_meta($post_id, '_cie_booking_admin_message');
			}

			// Prevent approving if conflicts exist.
			$start_eff = $start ?: (string) get_post_meta($post_id, '_cie_booking_start_date', true);
			$end_eff = $end ?: (string) get_post_meta($post_id, '_cie_booking_end_date', true);
			if ($status === Post_Types::BOOKING_STATUS_APPROVED && $start_eff && $end_eff && $end_eff >= $start_eff) {
				$conf = Bookings::find_conflicts($start_eff, $end_eff, $spaces, $equipment, $post_id);
				if (!empty($conf['spaces']) || !empty($conf['equipment']) || !empty($conf['blocked'])) {
					$status = Post_Types::BOOKING_STATUS_PENDING;
				}
			}

			update_post_meta($post_id, '_cie_booking_status', $status);
		}
	}

	public static function booking_columns(array $columns): array {
		$cols = [];
		$cols['cb'] = $columns['cb'] ?? '';
		$cols['title'] = __('Reserva', 'cie-lab-booking');
		$cols['cie_dates'] = __('Fechas', 'cie-lab-booking');
		$cols['cie_active'] = __('Actividad', 'cie-lab-booking');
		$cols['cie_resources'] = __('Recursos', 'cie-lab-booking');
		$cols['cie_status'] = __('Estado', 'cie-lab-booking');
		$cols['author'] = __('Usuario', 'cie-lab-booking');
		$cols['date'] = $columns['date'] ?? __('Fecha', 'cie-lab-booking');
		return $cols;
	}

	public static function booking_column_content(string $column, int $post_id): void {
		if ($column === 'cie_dates') {
			$start = (string) get_post_meta($post_id, '_cie_booking_start_date', true);
			$end = (string) get_post_meta($post_id, '_cie_booking_end_date', true);
			echo esc_html($start . ' - ' . $end);
		}
		if ($column === 'cie_status') {
			$status = (string) get_post_meta($post_id, '_cie_booking_status', true);
			echo self::status_badge($status);
		}
		if ($column === 'cie_active') {
			echo self::activity_badge($post_id);
		}
		if ($column === 'cie_resources') {
			$spaces = (array) get_post_meta($post_id, '_cie_booking_spaces', true);
			$equipment = (array) get_post_meta($post_id, '_cie_booking_equipment', true);
			echo esc_html(self::resources_summary($spaces, $equipment));
		}
	}

	public static function booking_row_actions(array $actions, \WP_Post $post): array {
		if ($post->post_type !== Post_Types::CPT_BOOKING) {
			return $actions;
		}
		$link = admin_url('admin.php?page=cie-lab-booking-booking&booking_id=' . (int) $post->ID);
		$actions['cie_review'] = '<a href="' . esc_url($link) . '">' . esc_html__('Revisar', 'cie-lab-booking') . '</a>';
		return $actions;
	}

	public static function resource_columns(array $columns): array {
		$cols = [];
		$cols['cb'] = $columns['cb'] ?? '';
		$cols['title'] = __('Recurso', 'cie-lab-booking');
		$cols['cie_resource_kind'] = __('Tipo', 'cie-lab-booking');
		$cols['cie_resource_group'] = __('Grupo', 'cie-lab-booking');
		$cols['cie_resource_quantity'] = __('Cantidad', 'cie-lab-booking');
		$cols['cie_resource_stock'] = __('Stock hoy', 'cie-lab-booking');
		$cols['cie_resource_reserved_by'] = __('Reservado por', 'cie-lab-booking');
		$cols['date'] = $columns['date'] ?? __('Fecha', 'cie-lab-booking');
		return $cols;
	}

	public static function resource_column_content(string $column, int $post_id): void {
		$kind = (string) get_post_meta($post_id, '_cie_resource_kind', true);
		$group = (string) get_post_meta($post_id, '_cie_resource_group', true);
		$quantity = Bookings::get_resource_quantity($post_id);
		$active_items = Bookings::get_resource_active_booking_items($post_id, gmdate('Y-m-d'));
		$reserved_count = count($active_items);
		$available_count = max($quantity - $reserved_count, 0);

		if ($column === 'cie_resource_kind') {
			echo '<span class="cie-admin-badge">' . esc_html(self::resource_kind_label($kind)) . '</span>';
		}
		if ($column === 'cie_resource_group') {
			if ($kind !== 'equipment') {
				echo '—';
			} else {
				echo esc_html($group !== '' ? self::equipment_group_label($group) : self::equipment_group_label('other'));
			}
		}
		if ($column === 'cie_resource_quantity') {
			echo (int) $quantity;
		}
		if ($column === 'cie_resource_stock') {
			echo '<span class="cie-admin-stock">';
			printf(
				esc_html__('%1$d libres / %2$d total', 'cie-lab-booking'),
				(int) $available_count,
				(int) $quantity
			);
			echo '</span><br/>';
			echo '<small>' . esc_html(sprintf(_n('%d reserva activa', '%d reservas activas', $reserved_count, 'cie-lab-booking'), $reserved_count)) . '</small>';
		}
		if ($column === 'cie_resource_reserved_by') {
			if (!$active_items) {
				echo '<span class="cie-admin-badge is-muted">' . esc_html__('Libre', 'cie-lab-booking') . '</span>';
				return;
			}
			foreach ($active_items as $item) {
				printf(
					'<div><a href="%1$s">%2$s</a> <small>(%3$s - %4$s)</small></div>',
					esc_url((string) $item['detail_url']),
					esc_html((string) $item['user_name']),
					esc_html((string) $item['start_date']),
					esc_html((string) $item['end_date'])
				);
			}
		}
	}

	public static function block_columns(array $columns): array {
		$cols = [];
		$cols['cb'] = $columns['cb'] ?? '';
		$cols['title'] = __('Bloqueo', 'cie-lab-booking');
		$cols['cie_block_dates'] = __('Fecha', 'cie-lab-booking');
		$cols['cie_block_reason'] = __('Motivo', 'cie-lab-booking');
		$cols['cie_block_resources'] = __('Recursos afectados', 'cie-lab-booking');
		$cols['cie_block_active'] = __('Activo', 'cie-lab-booking');
		$cols['date'] = $columns['date'] ?? __('Fecha', 'cie-lab-booking');
		return $cols;
	}

	public static function block_column_content(string $column, int $post_id): void {
		$start = (string) get_post_meta($post_id, '_cie_block_start_date', true);
		$end = (string) get_post_meta($post_id, '_cie_block_end_date', true);
		$reason = (string) get_post_meta($post_id, '_cie_block_reason', true);
		$resource_ids = array_map('intval', (array) get_post_meta($post_id, '_cie_block_resource_ids', true));
		$today = gmdate('Y-m-d');
		$is_active = $start !== '' && $end !== '' && $start <= $today && $today <= $end;

		if ($column === 'cie_block_dates') {
			echo esc_html($start . ' - ' . $end);
		}
		if ($column === 'cie_block_reason') {
			echo esc_html($reason !== '' ? $reason : '—');
		}
		if ($column === 'cie_block_resources') {
			echo esc_html(self::block_resources_label($resource_ids));
		}
		if ($column === 'cie_block_active') {
			echo $is_active
				? '<span class="cie-admin-badge is-active">' . esc_html__('Activo', 'cie-lab-booking') . '</span>'
				: '<span class="cie-admin-badge is-muted">' . esc_html__('Inactivo', 'cie-lab-booking') . '</span>';
		}
	}

	public static function render_list_filters(): void {
		global $typenow;
		if (!$typenow) {
			return;
		}

		if ($typenow === Post_Types::CPT_BOOKING) {
			$status = isset($_GET['cie_booking_status_filter']) ? sanitize_key((string) $_GET['cie_booking_status_filter']) : '';
			$activity = isset($_GET['cie_booking_activity_filter']) ? sanitize_key((string) $_GET['cie_booking_activity_filter']) : '';

			echo '<select name="cie_booking_status_filter">';
			echo '<option value="">' . esc_html__('Todos los estados', 'cie-lab-booking') . '</option>';
			printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_PENDING), selected($status, Post_Types::BOOKING_STATUS_PENDING, false), esc_html(self::status_label(Post_Types::BOOKING_STATUS_PENDING)));
			printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_APPROVED), selected($status, Post_Types::BOOKING_STATUS_APPROVED, false), esc_html(self::status_label(Post_Types::BOOKING_STATUS_APPROVED)));
			printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_CHANGES), selected($status, Post_Types::BOOKING_STATUS_CHANGES, false), esc_html(self::status_label(Post_Types::BOOKING_STATUS_CHANGES)));
			printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_REJECTED), selected($status, Post_Types::BOOKING_STATUS_REJECTED, false), esc_html(self::status_label(Post_Types::BOOKING_STATUS_REJECTED)));
			printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Post_Types::BOOKING_STATUS_CANCELLED), selected($status, Post_Types::BOOKING_STATUS_CANCELLED, false), esc_html(self::status_label(Post_Types::BOOKING_STATUS_CANCELLED)));
			echo '</select>';

			echo '<select name="cie_booking_activity_filter">';
			echo '<option value="">' . esc_html__('Todas las reservas', 'cie-lab-booking') . '</option>';
			printf('<option value="active" %s>%s</option>', selected($activity, 'active', false), esc_html__('Activas hoy', 'cie-lab-booking'));
			printf('<option value="upcoming" %s>%s</option>', selected($activity, 'upcoming', false), esc_html__('Próximas', 'cie-lab-booking'));
			printf('<option value="past" %s>%s</option>', selected($activity, 'past', false), esc_html__('Finalizadas', 'cie-lab-booking'));
			echo '</select>';
		}

		if ($typenow === Post_Types::CPT_RESOURCE) {
			$kind = isset($_GET['cie_resource_kind_filter']) ? sanitize_key((string) $_GET['cie_resource_kind_filter']) : '';
			$group = isset($_GET['cie_resource_group_filter']) ? sanitize_key((string) $_GET['cie_resource_group_filter']) : '';
			$reservation = isset($_GET['cie_resource_reservation_filter']) ? sanitize_key((string) $_GET['cie_resource_reservation_filter']) : '';

			echo '<select name="cie_resource_kind_filter">';
			echo '<option value="">' . esc_html__('Todos los tipos', 'cie-lab-booking') . '</option>';
			printf('<option value="space" %s>%s</option>', selected($kind, 'space', false), esc_html__('Espacio', 'cie-lab-booking'));
			printf('<option value="equipment" %s>%s</option>', selected($kind, 'equipment', false), esc_html__('Equipo', 'cie-lab-booking'));
			echo '</select>';

			echo '<select name="cie_resource_group_filter">';
			echo '<option value="">' . esc_html__('Todos los grupos', 'cie-lab-booking') . '</option>';
			foreach (Bookings::get_equipment_groups() as $g) {
				printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($g), selected($group, $g, false), esc_html(self::equipment_group_label($g)));
			}
			echo '</select>';

			echo '<select name="cie_resource_reservation_filter">';
			echo '<option value="">' . esc_html__('Reserva hoy: todos', 'cie-lab-booking') . '</option>';
			printf('<option value="reserved" %s>%s</option>', selected($reservation, 'reserved', false), esc_html__('Reservados hoy', 'cie-lab-booking'));
			printf('<option value="available" %s>%s</option>', selected($reservation, 'available', false), esc_html__('Libres hoy', 'cie-lab-booking'));
			echo '</select>';
		}

		if ($typenow === Post_Types::CPT_BLOCK) {
			$active = isset($_GET['cie_block_active_filter']) ? sanitize_key((string) $_GET['cie_block_active_filter']) : '';
			echo '<select name="cie_block_active_filter">';
			echo '<option value="">' . esc_html__('Todos los bloqueos', 'cie-lab-booking') . '</option>';
			printf('<option value="active" %s>%s</option>', selected($active, 'active', false), esc_html__('Activos hoy', 'cie-lab-booking'));
			printf('<option value="inactive" %s>%s</option>', selected($active, 'inactive', false), esc_html__('Inactivos hoy', 'cie-lab-booking'));
			echo '</select>';
		}
	}

	public static function apply_list_filters(\WP_Query $query): void {
		if (!is_admin() || !$query->is_main_query()) {
			return;
		}

		$post_type_raw = $query->get('post_type');
		if (is_array($post_type_raw)) {
			return;
		}
		$post_type = (string) $post_type_raw;
		if ($post_type === '') {
			return;
		}

		if ($post_type === Post_Types::CPT_BOOKING) {
			$status = isset($_GET['cie_booking_status_filter']) ? sanitize_key((string) $_GET['cie_booking_status_filter']) : '';
			$activity = isset($_GET['cie_booking_activity_filter']) ? sanitize_key((string) $_GET['cie_booking_activity_filter']) : '';
			$today = gmdate('Y-m-d');
			$meta = ['relation' => 'AND'];

			if ($status !== '') {
				$meta[] = [
					'key' => '_cie_booking_status',
					'value' => $status,
				];
			}
			if ($activity === 'active') {
				if ($status === '') {
					$meta[] = [
						'key' => '_cie_booking_status',
						'value' => Post_Types::BOOKING_STATUS_APPROVED,
					];
				}
				$meta[] = [
					'key' => '_cie_booking_start_date',
					'value' => $today,
					'compare' => '<=',
					'type' => 'DATE',
				];
				$meta[] = [
					'key' => '_cie_booking_end_date',
					'value' => $today,
					'compare' => '>=',
					'type' => 'DATE',
				];
			} elseif ($activity === 'upcoming') {
				$meta[] = [
					'key' => '_cie_booking_start_date',
					'value' => $today,
					'compare' => '>',
					'type' => 'DATE',
				];
			} elseif ($activity === 'past') {
				$meta[] = [
					'key' => '_cie_booking_end_date',
					'value' => $today,
					'compare' => '<',
					'type' => 'DATE',
				];
			}

			if (count($meta) > 1) {
				$query->set('meta_query', $meta);
			}
		}

		if ($post_type === Post_Types::CPT_RESOURCE) {
			$kind = isset($_GET['cie_resource_kind_filter']) ? sanitize_key((string) $_GET['cie_resource_kind_filter']) : '';
			$group = isset($_GET['cie_resource_group_filter']) ? sanitize_key((string) $_GET['cie_resource_group_filter']) : '';
			$reservation = isset($_GET['cie_resource_reservation_filter']) ? sanitize_key((string) $_GET['cie_resource_reservation_filter']) : '';

			$meta = ['relation' => 'AND'];
			if ($kind !== '') {
				$meta[] = [
					'key' => '_cie_resource_kind',
					'value' => $kind,
				];
			}
			if ($group !== '') {
				$meta[] = [
					'key' => '_cie_resource_group',
					'value' => $group,
				];
			}
			if (count($meta) > 1) {
				$query->set('meta_query', $meta);
			}

			if ($reservation !== '') {
				$reserved_ids = self::active_reserved_resource_ids(gmdate('Y-m-d'));
				if ($reservation === 'reserved') {
					$query->set('post__in', $reserved_ids ? $reserved_ids : [0]);
				} elseif ($reservation === 'available' && $reserved_ids) {
					$query->set('post__not_in', $reserved_ids);
				}
			}
		}

		if ($post_type === Post_Types::CPT_BLOCK) {
			$active = isset($_GET['cie_block_active_filter']) ? sanitize_key((string) $_GET['cie_block_active_filter']) : '';
			if ($active !== '') {
				$today = gmdate('Y-m-d');
				if ($active === 'active') {
					$query->set('meta_query', [
						[
							'key' => '_cie_block_start_date',
							'value' => $today,
							'compare' => '<=',
							'type' => 'DATE',
						],
						[
							'key' => '_cie_block_end_date',
							'value' => $today,
							'compare' => '>=',
							'type' => 'DATE',
						],
					]);
				} elseif ($active === 'inactive') {
					$query->set('meta_query', [
						'relation' => 'OR',
						[
							'key' => '_cie_block_start_date',
							'value' => $today,
							'compare' => '>',
							'type' => 'DATE',
						],
						[
							'key' => '_cie_block_end_date',
							'value' => $today,
							'compare' => '<',
							'type' => 'DATE',
						],
					]);
				}
			}
		}
	}

	private static function resources_summary(array $space_ids, array $equipment_ids): string {
		$names = [];
		foreach (array_merge($space_ids, $equipment_ids) as $rid) {
			$p = get_post((int) $rid);
			if ($p && $p->post_type === Post_Types::CPT_RESOURCE) {
				$names[] = $p->post_title;
			}
		}
		return implode(', ', $names);
	}

	private static function equipment_group_label(string $group): string {
		return Bookings::get_equipment_group_label($group);
	}

	private static function resource_kind_label(string $kind): string {
		if ($kind === 'space') {
			return (string) __('Espacio', 'cie-lab-booking');
		}
		if ($kind === 'equipment') {
			return (string) __('Equipo', 'cie-lab-booking');
		}
		return $kind;
	}

	private static function status_label(string $status): string {
		$map = [
			Post_Types::BOOKING_STATUS_PENDING => __('Pendiente de validar', 'cie-lab-booking'),
			Post_Types::BOOKING_STATUS_APPROVED => __('Validada', 'cie-lab-booking'),
			Post_Types::BOOKING_STATUS_REJECTED => __('No validada', 'cie-lab-booking'),
			Post_Types::BOOKING_STATUS_CHANGES => __('Cambios solicitados', 'cie-lab-booking'),
			Post_Types::BOOKING_STATUS_CANCELLED => __('Anulada', 'cie-lab-booking'),
		];
		return $map[$status] ?? $status;
	}

	private static function status_slug(string $status): string {
		$map = [
			Post_Types::BOOKING_STATUS_PENDING => 'pending',
			Post_Types::BOOKING_STATUS_APPROVED => 'approved',
			Post_Types::BOOKING_STATUS_REJECTED => 'rejected',
			Post_Types::BOOKING_STATUS_CHANGES => 'changes',
			Post_Types::BOOKING_STATUS_CANCELLED => 'cancelled',
		];
		return $map[$status] ?? 'unknown';
	}

	private static function status_badge(string $status): string {
		$slug = self::status_slug($status);
		return sprintf(
			'<span class="cie-admin-badge is-status-%1$s">%2$s</span>',
			esc_attr($slug),
			esc_html(self::status_label($status))
		);
	}

	private static function activity_badge(int $booking_id): string {
		$today = gmdate('Y-m-d');
		$status = (string) get_post_meta($booking_id, '_cie_booking_status', true);
		$start = (string) get_post_meta($booking_id, '_cie_booking_start_date', true);
		$end = (string) get_post_meta($booking_id, '_cie_booking_end_date', true);

		if ($status !== Post_Types::BOOKING_STATUS_APPROVED || $start === '' || $end === '') {
			return '<span class="cie-admin-badge is-muted">' . esc_html__('No activa', 'cie-lab-booking') . '</span>';
		}
		if ($start <= $today && $today <= $end) {
			return '<span class="cie-admin-badge is-active">' . esc_html__('Activa', 'cie-lab-booking') . '</span>';
		}
		if ($start > $today) {
			return '<span class="cie-admin-badge">' . esc_html__('Próxima', 'cie-lab-booking') . '</span>';
		}
		return '<span class="cie-admin-badge is-muted">' . esc_html__('Finalizada', 'cie-lab-booking') . '</span>';
	}

	/**
	 * @param array<int> $resource_ids
	 */
	private static function block_resources_label(array $resource_ids): string {
		$resource_ids = array_values(array_filter(array_map('intval', $resource_ids)));
		if (!$resource_ids) {
			return (string) __('Todos los recursos', 'cie-lab-booking');
		}

		$names = [];
		foreach ($resource_ids as $rid) {
			$p = get_post((int) $rid);
			if ($p && $p->post_type === Post_Types::CPT_RESOURCE) {
				$names[] = $p->post_title;
			}
		}
		return implode(', ', $names);
	}

	/**
	 * @return array<int>
	 */
	private static function active_reserved_resource_ids(string $date): array {
		$ids = [];
		$bookings = Bookings::get_overlapping_approved_bookings($date, $date);
		foreach ($bookings as $booking) {
			foreach ((array) get_post_meta((int) $booking->ID, '_cie_booking_spaces', true) as $rid) {
				$rid = (int) $rid;
				if ($rid > 0) {
					$ids[$rid] = $rid;
				}
			}
			foreach ((array) get_post_meta((int) $booking->ID, '_cie_booking_equipment', true) as $rid) {
				$rid = (int) $rid;
				if ($rid > 0) {
					$ids[$rid] = $rid;
				}
			}
		}
		return array_values($ids);
	}

	private static function legend_item(string $color, string $label): void {
		printf(
			'<span><span class="cie-lab-booking-admin__swatch" style="background:%1$s"></span> %2$s</span>',
			esc_attr($color),
			esc_html($label)
		);
	}

	/**
	 * @param array<string, array{has_space:bool,has_equipment:bool,blocked:bool}> $day_map
	 */
	private static function render_calendar_month(string $month_start, array $day_map): string {
		$first_ts = strtotime($month_start . ' 00:00:00');
		$month_label = date_i18n('F Y', $first_ts);
		$days_in_month = (int) gmdate('t', $first_ts);
		$first_weekday = (int) gmdate('N', $first_ts); // 1..7 (Mon..Sun)

		ob_start();
		?>
		<table class="widefat striped" style="max-width:820px;">
			<caption style="text-align:left;font-weight:900;font-size:20px;padding:10px 0;"><?php echo esc_html($month_label); ?></caption>
			<thead>
				<tr>
					<th>L</th><th>M</th><th>X</th><th>J</th><th>V</th><th>S</th><th>D</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<?php for ($i = 1; $i < $first_weekday; $i++): ?>
						<td></td>
					<?php endfor; ?>
					<?php
					$weekday = $first_weekday;
					for ($day = 1; $day <= $days_in_month; $day++):
						$ymd = gmdate('Y-m-d', strtotime(sprintf('%s +%d days', $month_start, $day - 1)));
						$state = $day_map[$ymd] ?? ['has_space' => false, 'has_equipment' => false, 'blocked' => false];
						$bg = '#e5e7eb';
						if ($state['blocked']) {
							$bg = '#ef4444';
						} elseif ($state['has_space'] && $state['has_equipment']) {
							$bg = '#22c55e';
						} elseif ($state['has_space']) {
							$bg = '#f59e0b';
						} elseif ($state['has_equipment']) {
							$bg = '#3b82f6';
						}
						?>
						<td class="cie-calendar-day" data-cie-date="<?php echo esc_attr($ymd); ?>" style="cursor:pointer;background:<?php echo esc_attr($bg); ?>;text-align:center;"><?php echo (int) $day; ?></td>
						<?php
						if ($weekday === 7 && $day !== $days_in_month) {
							echo '</tr><tr>';
							$weekday = 1;
						} else {
							$weekday++;
						}
					endfor;
					if ($weekday !== 1) {
						for ($i = $weekday; $i <= 7; $i++) {
							echo '<td></td>';
						}
					}
					?>
				</tr>
			</tbody>
		</table>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, array{has_space:bool,has_equipment:bool,blocked:bool}> $day_map
	 */
	private static function render_calendar_months(string $month_start_ymd, int $months, array $day_map): string {
		$start_ts = strtotime($month_start_ymd . ' 00:00:00');
		$out = '';
		for ($i = 0; $i < $months; $i++) {
			$m_ts = strtotime('+' . $i . ' months', $start_ts);
			$out .= self::render_calendar_month(gmdate('Y-m-01', $m_ts), $day_map);
			$out .= '<div style="height:10px"></div>';
		}
		return $out;
	}
}

