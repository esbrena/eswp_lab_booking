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
			$max_weeks = max(1, min(5, (int) ($_POST['cie_lab_booking_max_recurrence_weeks'] ?? Bookings::get_max_recurrence_weeks())));
			$max_range_days = max(1, min(5, (int) ($_POST['cie_lab_booking_max_range_days'] ?? Bookings::get_max_range_days())));
			if ($email !== '' && !is_email($email)) {
				Util::admin_notice(__('El correo de notificación no es válido.', 'cie-lab-booking'), 'error');
			} else {
				if ($email === '') {
					delete_option(Mailer::OPTION_NOTIFICATION_EMAIL);
				} else {
					update_option(Mailer::OPTION_NOTIFICATION_EMAIL, $email, false);
				}
				update_option(Bookings::OPTION_MAX_RECURRING_WEEKS, $max_weeks, false);
				update_option(Bookings::OPTION_MAX_RANGE_DAYS, $max_range_days, false);
				Util::admin_notice(__('Ajustes guardados.', 'cie-lab-booking'), 'success');
			}
		}

		$current_email = trim((string) get_option(Mailer::OPTION_NOTIFICATION_EMAIL, ''));
		$default_admin_email = trim((string) get_option('admin_email', ''));
		$current_max_weeks = Bookings::get_max_recurrence_weeks();
		$current_max_range_days = Bookings::get_max_range_days();

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
		echo '<p><label><strong>' . esc_html__('Máximo de semanas para repetición', 'cie-lab-booking') . '</strong><br/>';
		printf(
			'<input type="number" min="1" max="5" step="1" name="cie_lab_booking_max_recurrence_weeks" value="%d" style="width:120px" />',
			(int) $current_max_weeks
		);
		echo '</label></p>';
		echo '<p><label><strong>' . esc_html__('Máximo de días para rango', 'cie-lab-booking') . '</strong><br/>';
		printf(
			'<input type="number" min="1" max="5" step="1" name="cie_lab_booking_max_range_days" value="%d" style="width:120px" />',
			(int) $current_max_range_days
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

		$booking_status_filter = isset($_GET['booking_status']) ? sanitize_key((string) $_GET['booking_status']) : '';
		$booking_view_filter = isset($_GET['booking_view']) ? sanitize_key((string) $_GET['booking_view']) : 'approved';
		if (!in_array($booking_view_filter, ['approved', 'pending', 'all'], true)) {
			$booking_view_filter = 'approved';
		}

		echo '<div class="wrap"><h1>' . esc_html__('Calendario y reservas', 'cie-lab-booking') . '</h1>';

		// Booking list filter toolbar.
		echo '<form method="get" class="cie-admin-calendar-toolbar">';
		echo '<input type="hidden" name="page" value="cie-lab-booking-calendar" />';
		echo '<label>' . esc_html__('Vista calendario', 'cie-lab-booking') . ' ';
		echo '<select name="booking_view">';
		printf('<option value="%1$s" %2$s>%3$s</option>', 'approved', selected($booking_view_filter, 'approved', false), esc_html__('Validadas', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', 'pending', selected($booking_view_filter, 'pending', false), esc_html__('Pendientes de validar', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', 'all', selected($booking_view_filter, 'all', false), esc_html__('Todas', 'cie-lab-booking'));
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
		echo '<div class="cie-lab-booking-admin__legend">';
		self::legend_item('#f59e0b', __('Reserva de espacios', 'cie-lab-booking'));
		self::legend_item('#3b82f6', __('Reserva de equipos', 'cie-lab-booking'));
		self::legend_item('#22c55e', __('Reserva combinada', 'cie-lab-booking'));
		self::legend_item('#ef4444', __('Mantenimiento', 'cie-lab-booking'));
		echo '</div>';
		echo '<div class="cie-scheduler" data-cie-scheduler="1" data-cie-calendar-scope="general" data-cie-calendar-context="admin" data-cie-booking-view="' . esc_attr($booking_view_filter) . '"></div>';
		echo '</section>';
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
				$occurrences = Bookings::get_booking_occurrences($booking_id);
				$conflicts = Bookings::find_conflicts_for_occurrences($occurrences, array_map('intval', $spaces), array_map('intval', $equipment), $booking_id);
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

		echo '<h2>' . esc_html__('Detalle', 'cie-lab-booking') . '</h2>';
		echo self::booking_schedule_detail_html($booking_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p><strong>' . esc_html__('Duración del proyecto:', 'cie-lab-booking') . '</strong> ' . esc_html($project_duration !== '' ? $project_duration : '—') . '</p>';
		echo '<p><strong>' . esc_html__('IP/Director/a email:', 'cie-lab-booking') . '</strong> ' . esc_html($project_ip_email !== '' ? $project_ip_email : '—') . '</p>';

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
		$required_equipment = array_values(array_filter(array_map('intval', (array) get_post_meta($post->ID, '_cie_resource_required_equipment', true))));

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

		$all_equipment = Bookings::get_resources('equipment', false);
		echo '<div data-cie-resource-dependency-wrap="1">';
		echo '<p><strong>' . esc_html__('Interdependencia de equipos', 'cie-lab-booking') . '</strong></p>';
		echo '<p>' . esc_html__('Si el usuario selecciona este equipo en el formulario, también se seleccionarán automáticamente los equipos marcados aquí y no podrán desmarcarse mientras el equipo principal esté seleccionado.', 'cie-lab-booking') . '</p>';
		echo '<div class="cie-admin-resource-picker">';
		if (!$all_equipment) {
			echo '<p><em>' . esc_html__('No hay equipos disponibles para relacionar.', 'cie-lab-booking') . '</em></p>';
		} else {
			$has_options = false;
			foreach ($all_equipment as $eq) {
				if ((int) $eq->ID === (int) $post->ID) {
					continue;
				}
				$has_options = true;
				$checked = in_array((int) $eq->ID, $required_equipment, true) ? 'checked' : '';
				printf(
					'<label><input type="checkbox" name="cie_resource_required_equipment[]" value="%1$d" %2$s /> %3$s</label>',
					(int) $eq->ID,
					$checked,
					esc_html((string) $eq->post_title)
				);
			}
			if (!$has_options) {
				echo '<p><em>' . esc_html__('No hay otros equipos para relacionar.', 'cie-lab-booking') . '</em></p>';
			}
		}
		echo '</div>';
		echo '</div>';

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
		$booking_mode = sanitize_key((string) get_post_meta($booking_id, '_cie_booking_mode', true));
		if (!in_array($booking_mode, [Bookings::BOOKING_MODE_FULL_DAY, Bookings::BOOKING_MODE_TIME_RANGE], true)) {
			$booking_mode = Bookings::BOOKING_MODE_FULL_DAY;
		}
		$booking_frequency = sanitize_key((string) get_post_meta($booking_id, '_cie_booking_frequency', true));
		if (!in_array($booking_frequency, [Bookings::BOOKING_FREQUENCY_SINGLE, Bookings::BOOKING_FREQUENCY_DAILY, Bookings::BOOKING_FREQUENCY_WEEKLY_REPEAT, Bookings::BOOKING_FREQUENCY_BIWEEKLY_REPEAT], true)) {
			$booking_frequency = Bookings::BOOKING_FREQUENCY_SINGLE;
		}
		$booking_day_scope = sanitize_key((string) get_post_meta($booking_id, '_cie_booking_day_scope', true));
		if (!in_array($booking_day_scope, [Bookings::BOOKING_DAY_SCOPE_SINGLE, Bookings::BOOKING_DAY_SCOPE_RANGE, Bookings::BOOKING_DAY_SCOPE_LOOSE], true)) {
			$booking_day_scope = Bookings::BOOKING_DAY_SCOPE_SINGLE;
		}
		$booking_time_start = trim((string) get_post_meta($booking_id, '_cie_booking_time_start', true));
		$booking_time_end = trim((string) get_post_meta($booking_id, '_cie_booking_time_end', true));
		$booking_time_slots = array_values(array_filter(array_map('strval', (array) get_post_meta($booking_id, '_cie_booking_time_slots', true))));
		$booking_recurrence_weeks = max(1, min(Bookings::get_max_recurrence_weeks(), (int) get_post_meta($booking_id, '_cie_booking_recurrence_weeks', true)));
		$booking_selected_dates = array_values(array_filter(array_map('strval', (array) get_post_meta($booking_id, '_cie_booking_selected_dates', true))));
		$booking_dates_raw = implode(', ', $booking_selected_dates);

		$spaces_posts = Bookings::get_resources('space', false);
		$equipment_grouped = Bookings::get_equipment_grouped(false);

		$conflicts = [];
		if ($start && $end && $end >= $start) {
			$conf = Bookings::find_conflicts_for_occurrences(Bookings::get_booking_occurrences($booking_id), array_map('intval', (array) $spaces), array_map('intval', (array) $equipment), $booking_id);
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

		echo '<p><strong>' . esc_html__('Lógica de fechas y repetición', 'cie-lab-booking') . '</strong></p>';
		echo '<p><label>' . esc_html__('Ámbito de días', 'cie-lab-booking') . '<br/>';
		echo '<select name="cie_booking_day_scope">';
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Bookings::BOOKING_DAY_SCOPE_SINGLE), selected($booking_day_scope, Bookings::BOOKING_DAY_SCOPE_SINGLE, false), esc_html__('Un día', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Bookings::BOOKING_DAY_SCOPE_RANGE), selected($booking_day_scope, Bookings::BOOKING_DAY_SCOPE_RANGE, false), esc_html__('Rango de días', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Bookings::BOOKING_DAY_SCOPE_LOOSE), selected($booking_day_scope, Bookings::BOOKING_DAY_SCOPE_LOOSE, false), esc_html__('Días sueltos', 'cie-lab-booking'));
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
		printf('<p><label>%1$s<br/><input type="text" class="cie-date-multiple" name="cie_booking_dates_raw" value="%2$s" placeholder="YYYY-MM-DD, YYYY-MM-DD" style="width:320px" /></label></p>', esc_html__('Días sueltos (opcional)', 'cie-lab-booking'), esc_attr($booking_dates_raw));
		echo '<p><label>' . esc_html__('Repetición', 'cie-lab-booking') . '<br/>';
		echo '<select name="cie_booking_frequency">';
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Bookings::BOOKING_FREQUENCY_SINGLE), selected($booking_frequency, Bookings::BOOKING_FREQUENCY_SINGLE, false), esc_html__('Sin repetición', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Bookings::BOOKING_FREQUENCY_DAILY), selected($booking_frequency, Bookings::BOOKING_FREQUENCY_DAILY, false), esc_html__('Cada día', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Bookings::BOOKING_FREQUENCY_WEEKLY_REPEAT), selected($booking_frequency, Bookings::BOOKING_FREQUENCY_WEEKLY_REPEAT, false), esc_html__('Cada semana', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Bookings::BOOKING_FREQUENCY_BIWEEKLY_REPEAT), selected($booking_frequency, Bookings::BOOKING_FREQUENCY_BIWEEKLY_REPEAT, false), esc_html__('Semana salteada', 'cie-lab-booking'));
		echo '</select></label></p>';
		printf('<p><label>%1$s<br/><input type="number" min="1" max="%2$d" step="1" name="cie_booking_recurrence_weeks" value="%3$d" style="width:110px" /></label></p>', esc_html__('Semanas de repetición', 'cie-lab-booking'), (int) Bookings::get_max_recurrence_weeks(), (int) $booking_recurrence_weeks);
		echo '<p><label>' . esc_html__('Duración', 'cie-lab-booking') . '<br/>';
		echo '<select name="cie_booking_mode">';
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Bookings::BOOKING_MODE_FULL_DAY), selected($booking_mode, Bookings::BOOKING_MODE_FULL_DAY, false), esc_html__('Día completo', 'cie-lab-booking'));
		printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr(Bookings::BOOKING_MODE_TIME_RANGE), selected($booking_mode, Bookings::BOOKING_MODE_TIME_RANGE, false), esc_html__('Por horas', 'cie-lab-booking'));
		echo '</select></label></p>';
		printf('<p><label>%1$s<br/><input type="time" name="cie_booking_time_start" value="%2$s" /></label> &nbsp; <label>%3$s<br/><input type="time" name="cie_booking_time_end" value="%4$s" /></label></p>', esc_html__('Desde (fallback)', 'cie-lab-booking'), esc_attr($booking_time_start), esc_html__('Hasta (fallback)', 'cie-lab-booking'), esc_attr($booking_time_end));
		echo '<p><strong>' . esc_html__('Bloques horarios', 'cie-lab-booking') . '</strong></p>';
		echo '<div class="cie-admin-resource-picker">';
		for ($h = 8; $h < 20; $h++) {
			$slot_start = sprintf('%02d:00', $h);
			$slot_end = sprintf('%02d:00', $h + 1);
			$slot_value = $slot_start . '-' . $slot_end;
			printf(
				'<label style="display:inline-flex;align-items:center;gap:6px;margin-right:10px;"><input type="checkbox" name="cie_booking_time_slots[]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr($slot_value),
				checked(in_array($slot_value, $booking_time_slots, true), true, false),
				esc_html($slot_start . ' - ' . $slot_end)
			);
		}
		echo '</div>';

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
			$required_equipment = array_values(array_filter(array_map('intval', (array) ($_POST['cie_resource_required_equipment'] ?? []))));
			$required_equipment = array_values(array_diff($required_equipment, [$post_id]));
			if ($kind !== 'equipment') {
				$quantity = 1;
				$required_equipment = [];
			}

			update_post_meta($post_id, '_cie_resource_kind', $kind);
			update_post_meta($post_id, '_cie_resource_group', $group);
			update_post_meta($post_id, '_cie_resource_code', $code);
			update_post_meta($post_id, '_cie_resource_available', $available);
			update_post_meta($post_id, '_cie_resource_quantity', $quantity);
			update_post_meta($post_id, '_cie_resource_required_equipment', $required_equipment);
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

			$schedule_errors = [];
			$normalized_schedule = Bookings::normalize_schedule_request([
				'start_date' => (string) ($_POST['cie_booking_start_date'] ?? ''),
				'end_date' => (string) ($_POST['cie_booking_end_date'] ?? ''),
				'booking_day_scope' => (string) ($_POST['cie_booking_day_scope'] ?? Bookings::BOOKING_DAY_SCOPE_SINGLE),
				'booking_frequency' => (string) ($_POST['cie_booking_frequency'] ?? Bookings::BOOKING_FREQUENCY_SINGLE),
				'booking_mode' => (string) ($_POST['cie_booking_mode'] ?? Bookings::BOOKING_MODE_FULL_DAY),
				'booking_time_start' => (string) ($_POST['cie_booking_time_start'] ?? ''),
				'booking_time_end' => (string) ($_POST['cie_booking_time_end'] ?? ''),
				'booking_time_slots' => (array) ($_POST['cie_booking_time_slots'] ?? []),
				'booking_recurrence_weeks' => (int) ($_POST['cie_booking_recurrence_weeks'] ?? 1),
				'booking_dates_raw' => (string) ($_POST['cie_booking_dates_raw'] ?? ''),
			], $schedule_errors);
			$start = (string) ($normalized_schedule['start_date'] ?? '');
			$end = (string) ($normalized_schedule['end_date'] ?? '');
			$occurrences = (array) ($normalized_schedule['occurrences'] ?? []);

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
			update_post_meta($post_id, '_cie_booking_mode', (string) ($normalized_schedule['mode'] ?? Bookings::BOOKING_MODE_FULL_DAY));
			update_post_meta($post_id, '_cie_booking_frequency', (string) ($normalized_schedule['frequency'] ?? Bookings::BOOKING_FREQUENCY_SINGLE));
			update_post_meta($post_id, '_cie_booking_day_scope', (string) ($normalized_schedule['day_scope'] ?? Bookings::BOOKING_DAY_SCOPE_SINGLE));
			update_post_meta($post_id, '_cie_booking_time_start', (string) ($normalized_schedule['time_start'] ?? ''));
			update_post_meta($post_id, '_cie_booking_time_end', (string) ($normalized_schedule['time_end'] ?? ''));
			update_post_meta($post_id, '_cie_booking_time_slots', (array) ($normalized_schedule['time_slots'] ?? []));
			update_post_meta($post_id, '_cie_booking_recurrence_weeks', (int) ($normalized_schedule['recurrence_weeks'] ?? 1));
			update_post_meta($post_id, '_cie_booking_weekdays', (array) ($normalized_schedule['weekdays'] ?? []));
			update_post_meta($post_id, '_cie_booking_selected_dates', (array) ($normalized_schedule['selected_dates'] ?? []));
			if (!empty($occurrences)) {
				update_post_meta($post_id, '_cie_booking_occurrences', $occurrences);
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
			$occurrences_for_conflict = !empty($occurrences) ? $occurrences : Bookings::get_booking_occurrences($post_id);
			if ($status === Post_Types::BOOKING_STATUS_APPROVED && $start_eff && $end_eff && $end_eff >= $start_eff) {
				$conf = Bookings::find_conflicts_for_occurrences($occurrences_for_conflict, $spaces, $equipment, $post_id);
				if (!empty($conf['spaces']) || !empty($conf['equipment']) || !empty($conf['blocked'])) {
					$status = Post_Types::BOOKING_STATUS_PENDING;
				}
			}
			if (!empty($schedule_errors)) {
				$status = Post_Types::BOOKING_STATUS_PENDING;
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

	private static function booking_schedule_detail_html(int $booking_id): string {
		$mode = sanitize_key((string) get_post_meta($booking_id, '_cie_booking_mode', true));
		$frequency = sanitize_key((string) get_post_meta($booking_id, '_cie_booking_frequency', true));
		$spaces = (array) get_post_meta($booking_id, '_cie_booking_spaces', true);
		$equipment = (array) get_post_meta($booking_id, '_cie_booking_equipment', true);
		$resource_title = self::resources_summary($spaces, $equipment);
		if ($resource_title === '') {
			$resource_title = sprintf(__('Reserva #%d', 'cie-lab-booking'), $booking_id);
		}
		$project_name = trim((string) get_post_meta($booking_id, '_cie_booking_project_name', true));
		$project_responsible = trim((string) get_post_meta($booking_id, '_cie_booking_project_responsible', true));
		$occurrences = Bookings::get_booking_occurrences($booking_id);
		$occurrence_lines = self::booking_occurrence_lines($occurrences, 4);
		$repeat_line = self::booking_repeat_line($frequency, $occurrences);
		$total_line = self::booking_total_line($occurrences, $mode);

		$html = '<div class="cie-booking-detail">';
		$html .= '<div class="cie-booking-detail__title">' . esc_html($resource_title) . '</div>';
		$html .= '<div class="cie-booking-detail__block">';
		$html .= '<div class="cie-booking-detail__block-title cie-booking-detail__block-title--clock"></div>';
		foreach ($occurrence_lines as $line) {
			$html .= '<div class="cie-booking-detail__line">' . esc_html($line) . '</div>';
		}
		if ($repeat_line !== '') {
			$html .= '<div class="cie-booking-detail__line">' . esc_html($repeat_line) . '</div>';
		}
		if ($total_line !== '') {
			$html .= '<div class="cie-booking-detail__line cie-booking-detail__line--total">' . esc_html($total_line) . '</div>';
		}
		$html .= '</div>';
		$html .= '<div class="cie-booking-detail__block">';
		$html .= '<div class="cie-booking-detail__block-title cie-booking-detail__block-title--list"></div>';
		$html .= '<div class="cie-booking-detail__line">' . esc_html__('Proyecto: ', 'cie-lab-booking') . esc_html($project_name !== '' ? $project_name : __('Sin especificar', 'cie-lab-booking')) . '</div>';
		$html .= '<div class="cie-booking-detail__line">' . esc_html__('Responsable: ', 'cie-lab-booking') . esc_html($project_responsible !== '' ? $project_responsible : __('Sin especificar', 'cie-lab-booking')) . '</div>';
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * @param array<int,array{date:string,start:string,end:string,full_day:bool}> $occurrences
	 * @return array<int,string>
	 */
	private static function booking_occurrence_lines(array $occurrences, int $max_days = 3): array {
		$grouped = [];
		foreach ($occurrences as $occurrence) {
			$date = (string) ($occurrence['date'] ?? '');
			if ($date === '') {
				continue;
			}
			if (!isset($grouped[$date])) {
				$grouped[$date] = [
					'full_day' => false,
					'slots' => [],
				];
			}
			if (!empty($occurrence['full_day'])) {
				$grouped[$date]['full_day'] = true;
				continue;
			}
			$start = trim((string) ($occurrence['start'] ?? ''));
			$end = trim((string) ($occurrence['end'] ?? ''));
			if ($start !== '' && $end !== '') {
				$grouped[$date]['slots'][$start . '-' . $end] = $start . ' - ' . $end;
			}
		}
		$dates = array_keys($grouped);
		sort($dates);
		$lines = [];
		foreach (array_slice($dates, 0, max(1, $max_days)) as $date) {
			$label = self::format_date_with_weekday($date);
			if (!empty($grouped[$date]['full_day'])) {
				$lines[] = $label . ' · ' . __('Día completo', 'cie-lab-booking');
			} else {
				$slots = array_values((array) $grouped[$date]['slots']);
				$lines[] = $label . ' · ' . ($slots ? implode(', ', $slots) : __('Horario pendiente', 'cie-lab-booking'));
			}
		}
		if (count($dates) > max(1, $max_days)) {
			$lines[] = sprintf(
				/* translators: %d: hidden day count */
				__('y %d día(s) más', 'cie-lab-booking'),
				count($dates) - max(1, $max_days)
			);
		}
		return $lines;
	}

	/**
	 * @param array<int,array{date:string,start:string,end:string,full_day:bool}> $occurrences
	 */
	private static function booking_repeat_line(string $frequency, array $occurrences): string {
		if ($frequency === Bookings::BOOKING_FREQUENCY_SINGLE || !$occurrences) {
			return '';
		}
		$dates = array_values(array_filter(array_map(static function (array $occurrence): string {
			return (string) ($occurrence['date'] ?? '');
		}, $occurrences)));
		if (!$dates) {
			return '';
		}
		sort($dates);
		$until = self::format_date_long((string) end($dates));
		if ($frequency === Bookings::BOOKING_FREQUENCY_DAILY) {
			return __('Repetición: Cada día', 'cie-lab-booking') . ', ' . __('hasta', 'cie-lab-booking') . ' ' . $until;
		}
		if ($frequency === Bookings::BOOKING_FREQUENCY_WEEKLY_REPEAT) {
			return __('Repetición: Cada semana', 'cie-lab-booking') . ', ' . __('hasta', 'cie-lab-booking') . ' ' . $until;
		}
		if ($frequency === Bookings::BOOKING_FREQUENCY_BIWEEKLY_REPEAT) {
			return __('Repetición: Semana salteada', 'cie-lab-booking') . ', ' . __('hasta', 'cie-lab-booking') . ' ' . $until;
		}
		return '';
	}

	/**
	 * @param array<int,array{date:string,start:string,end:string,full_day:bool}> $occurrences
	 */
	private static function booking_total_line(array $occurrences, string $mode): string {
		if (!$occurrences) {
			return '';
		}
		$unique_dates = [];
		$slot_count = 0;
		$total_minutes = 0;
		foreach ($occurrences as $occurrence) {
			$date = (string) ($occurrence['date'] ?? '');
			if ($date !== '') {
				$unique_dates[$date] = true;
			}
			if (!empty($occurrence['full_day'])) {
				continue;
			}
			$slot_count++;
			$start_minutes = self::hm_to_minutes((string) ($occurrence['start'] ?? ''));
			$end_minutes = self::hm_to_minutes((string) ($occurrence['end'] ?? ''));
			if ($start_minutes >= 0 && $end_minutes > $start_minutes) {
				$total_minutes += ($end_minutes - $start_minutes);
			}
		}
		$days_count = count($unique_dates);
		if ($mode === Bookings::BOOKING_MODE_FULL_DAY || $slot_count === 0) {
			return sprintf(
				/* translators: %d: total day count */
				__('Total: %d día(s) reservados', 'cie-lab-booking'),
				$days_count
			);
		}
		$hours = $total_minutes > 0 ? rtrim(rtrim(number_format($total_minutes / 60, 2, '.', ''), '0'), '.') : '0';
		return sprintf(
			/* translators: 1: days, 2: slots, 3: hours */
			__('Total: %1$d día(s) · %2$d bloque(s) · %3$s h', 'cie-lab-booking'),
			$days_count,
			$slot_count,
			$hours
		);
	}

	private static function hm_to_minutes(string $value): int {
		if (!preg_match('/^\d{2}\:\d{2}$/', $value)) {
			return -1;
		}
		$parts = explode(':', $value);
		return ((int) $parts[0] * 60) + (int) $parts[1];
	}

	private static function format_date_with_weekday(string $ymd): string {
		$ts = strtotime($ymd . ' 00:00:00');
		if ($ts === false) {
			return $ymd;
		}
		return wp_date('l, j \\d\\e F', $ts);
	}

	private static function format_date_long(string $ymd): string {
		$ts = strtotime($ymd . ' 00:00:00');
		if ($ts === false) {
			return $ymd;
		}
		return wp_date('j \\d\\e F', $ts);
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
		$is_active = self::booking_is_active_today($booking_id);
		$label = $is_active ? __('Activa', 'cie-lab-booking') : __('No activa', 'cie-lab-booking');
		$state = $is_active ? 'is-active' : 'is-inactive';
		return sprintf(
			'<span class="cie-admin-activity %1$s"><span class="cie-admin-activity__dot" aria-hidden="true"></span>%2$s</span>',
			esc_attr($state),
			esc_html($label)
		);
	}

	private static function booking_is_active_today(int $booking_id): bool {
		$today = gmdate('Y-m-d');
		$status = (string) get_post_meta($booking_id, '_cie_booking_status', true);
		if ($status !== Post_Types::BOOKING_STATUS_APPROVED) {
			return false;
		}
		return Bookings::booking_has_occurrence_on_date($booking_id, $today);
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

