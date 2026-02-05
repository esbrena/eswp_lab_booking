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
		add_filter('post_row_actions', [self::class, 'booking_row_actions'], 10, 2);
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
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('No tienes permisos.', 'cie-lab-booking'), 403);
		}

		// Quick-create maintenance block.
		if (!empty($_POST['cie_block_submit'])) {
			check_admin_referer('cie_block_submit', '_wpnonce_cie_block');

			$start = Util::normalize_date_ymd((string) ($_POST['block_start_date'] ?? ''));
			$end = Util::normalize_date_ymd((string) ($_POST['block_end_date'] ?? ''));
			$reason = sanitize_text_field((string) ($_POST['block_reason'] ?? ''));
			$resource_ids = array_values(array_filter(array_map('intval', (array) ($_POST['block_resource_ids'] ?? []))));

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

		$start = $month . '-01';
		$end = gmdate('Y-m-t', strtotime($start));
		$day_map = Bookings::build_day_map($start, $end);

		$pending = get_posts([
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'posts_per_page' => 20,
			'orderby' => 'date',
			'order' => 'DESC',
			'meta_query' => [
				[
					'key' => '_cie_booking_status',
					'value' => Post_Types::BOOKING_STATUS_PENDING,
				],
			],
		]);

		echo '<div class="wrap"><h1>' . esc_html__('Calendario y reservas', 'cie-lab-booking') . '</h1>';

		// Month selector (up to 24 months ahead).
		echo '<form method="get" style="margin:12px 0;">';
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
		echo '<button class="button">' . esc_html__('Ver', 'cie-lab-booking') . '</button>';
		echo '</form>';

		// Legend.
		echo '<div class="cie-lab-booking-admin__legend">';
		self::legend_item('#e5e7eb', __('Sin reservas', 'cie-lab-booking'));
		self::legend_item('#f59e0b', __('Reserva de espacios', 'cie-lab-booking'));
		self::legend_item('#3b82f6', __('Reserva de equipos', 'cie-lab-booking'));
		self::legend_item('#22c55e', __('Espacios + equipos', 'cie-lab-booking'));
		self::legend_item('#ef4444', __('No disponible (mantenimiento)', 'cie-lab-booking'));
		echo '</div>';

		// Calendar for month.
		echo self::render_calendar_month($start, $day_map);

		// Pending list.
		echo '<h2>' . esc_html__('Reservas pendientes de validar', 'cie-lab-booking') . '</h2>';
		if (!$pending) {
			echo '<p><em>' . esc_html__('No hay reservas pendientes.', 'cie-lab-booking') . '</em></p>';
		} else {
			echo '<ul>';
			foreach ($pending as $b) {
				$link = admin_url('admin.php?page=cie-lab-booking-booking&booking_id=' . (int) $b->ID);
				$start_b = (string) get_post_meta($b->ID, '_cie_booking_start_date', true);
				$end_b = (string) get_post_meta($b->ID, '_cie_booking_end_date', true);
				printf(
					'<li><a href="%1$s">%2$s</a> <small>(%3$s - %4$s)</small></li>',
					esc_url($link),
					esc_html($b->post_title),
					esc_html($start_b),
					esc_html($end_b)
				);
			}
			echo '</ul>';
		}

		// Quick block form.
		$resources = get_posts([
			'post_type' => Post_Types::CPT_RESOURCE,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		]);
		echo '<h2>' . esc_html__('Crear bloqueo por mantenimiento', 'cie-lab-booking') . '</h2>';
		echo '<form method="post">';
		wp_nonce_field('cie_block_submit', '_wpnonce_cie_block');
		echo '<input type="hidden" name="cie_block_submit" value="1" />';
		echo '<p><label>' . esc_html__('Desde', 'cie-lab-booking') . ' <input class="cie-date" type="text" name="block_start_date" placeholder="YYYY-MM-DD" required /></label></p>';
		echo '<p><label>' . esc_html__('Hasta', 'cie-lab-booking') . ' <input class="cie-date" type="text" name="block_end_date" placeholder="YYYY-MM-DD" required /></label></p>';
		echo '<p><label>' . esc_html__('Motivo', 'cie-lab-booking') . '<br/><input type="text" name="block_reason" style="width:420px" /></label></p>';
		echo '<p><label>' . esc_html__('Recursos afectados (vacío = todos)', 'cie-lab-booking') . '<br/>';
		echo '<select name="block_resource_ids[]" multiple size="6" style="min-width:420px;">';
		foreach ($resources as $r) {
			printf('<option value="%1$d">%2$s</option>', (int) $r->ID, esc_html($r->post_title));
		}
		echo '</select></label></p>';
		echo '<p><button class="button button-primary">' . esc_html__('Crear bloqueo', 'cie-lab-booking') . '</button></p>';
		echo '</form>';

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
				$conflicts = Bookings::find_conflicts($start, $end, array_map('intval', $spaces), array_map('intval', $equipment));
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

		echo '<p><label>' . esc_html__('Tipo', 'cie-lab-booking') . ' ';
		echo '<select name="cie_resource_kind">';
		printf('<option value="space" %s>%s</option>', selected($kind, 'space', false), esc_html__('Espacio', 'cie-lab-booking'));
		printf('<option value="equipment" %s>%s</option>', selected($kind, 'equipment', false), esc_html__('Equipo', 'cie-lab-booking'));
		echo '</select></label></p>';

		echo '<p><label>' . esc_html__('Grupo', 'cie-lab-booking') . ' ';
		printf('<input type="text" name="cie_resource_group" value="%s" />', esc_attr($group));
		echo '</label></p>';

		echo '<p><label>' . esc_html__('Código/ID', 'cie-lab-booking') . ' ';
		printf('<input type="text" name="cie_resource_code" value="%s" />', esc_attr($code));
		echo '</label></p>';

		echo '<p><label>';
		printf('<input type="checkbox" name="cie_resource_available" value="1" %s /> ', checked($available, '1', false));
		echo esc_html__('Disponible', 'cie-lab-booking');
		echo '</label></p>';
	}

	public static function render_block_meta_box(\WP_Post $post): void {
		wp_nonce_field('cie_block_meta', '_wpnonce_cie_block_meta');
		$start = (string) get_post_meta($post->ID, '_cie_block_start_date', true);
		$end = (string) get_post_meta($post->ID, '_cie_block_end_date', true);
		$reason = (string) get_post_meta($post->ID, '_cie_block_reason', true);
		$resource_ids = (array) get_post_meta($post->ID, '_cie_block_resource_ids', true);

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

		echo '<p><label>' . esc_html__('Recursos afectados (vacío = todos)', 'cie-lab-booking') . '<br/>';
		echo '<select name="cie_block_resource_ids[]" multiple size="8" style="min-width:520px;">';
		foreach ($resources as $r) {
			$selected = in_array((string) $r->ID, array_map('strval', $resource_ids), true);
			printf('<option value="%1$d" %2$s>%3$s</option>', (int) $r->ID, $selected ? 'selected' : '', esc_html($r->post_title));
		}
		echo '</select></label></p>';
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
			$group = sanitize_key((string) ($_POST['cie_resource_group'] ?? ''));
			$code = sanitize_text_field((string) ($_POST['cie_resource_code'] ?? ''));
			$available = !empty($_POST['cie_resource_available']) ? '1' : '0';

			update_post_meta($post_id, '_cie_resource_kind', $kind);
			update_post_meta($post_id, '_cie_resource_group', $group);
			update_post_meta($post_id, '_cie_resource_code', $code);
			update_post_meta($post_id, '_cie_resource_available', $available);
		}

		if ($post->post_type === Post_Types::CPT_BLOCK) {
			if (empty($_POST['_wpnonce_cie_block_meta']) || !wp_verify_nonce((string) $_POST['_wpnonce_cie_block_meta'], 'cie_block_meta')) {
				return;
			}

			$start = Util::normalize_date_ymd((string) ($_POST['cie_block_start_date'] ?? ''));
			$end = Util::normalize_date_ymd((string) ($_POST['cie_block_end_date'] ?? ''));
			$reason = sanitize_text_field((string) ($_POST['cie_block_reason'] ?? ''));
			$resource_ids = array_values(array_filter(array_map('intval', (array) ($_POST['cie_block_resource_ids'] ?? []))));

			if ($start) {
				update_post_meta($post_id, '_cie_block_start_date', $start);
			}
			if ($end) {
				update_post_meta($post_id, '_cie_block_end_date', $end);
			}
			update_post_meta($post_id, '_cie_block_reason', $reason);
			update_post_meta($post_id, '_cie_block_resource_ids', $resource_ids);
		}
	}

	public static function booking_columns(array $columns): array {
		$cols = [];
		$cols['cb'] = $columns['cb'] ?? '';
		$cols['title'] = __('Reserva', 'cie-lab-booking');
		$cols['cie_dates'] = __('Fechas', 'cie-lab-booking');
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
			echo esc_html(self::status_label($status));
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
			<caption style="text-align:left;font-weight:bold;padding:8px 0;"><?php echo esc_html($month_label); ?></caption>
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
						<td style="background:<?php echo esc_attr($bg); ?>;text-align:center;"><?php echo (int) $day; ?></td>
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
}

