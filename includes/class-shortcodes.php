<?php

namespace CIE_Lab_Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class Shortcodes {
	public static function init(): void {
		add_shortcode('cie_booking_form', [self::class, 'render_booking_form']);
		add_shortcode('cie_my_bookings', [self::class, 'render_my_bookings']);
		add_shortcode('cie_my_bookings_current', [self::class, 'render_my_bookings_current']);
		add_shortcode('cie_my_bookings_history', [self::class, 'render_my_bookings_history']);
		add_shortcode('cie_my_active_bookings_card', [self::class, 'render_my_active_bookings_card']);
		add_shortcode('cie_booking_calendar', [self::class, 'render_calendar']);
	}

	public static function render_booking_form($atts = []): string {
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('Debes iniciar sesión para realizar una reserva.', 'cie-lab-booking') . '</p>';
		}
		if (!Util::current_user_can_book()) {
			return '<p>' . esc_html__('Tu usuario no tiene permisos para realizar reservas.', 'cie-lab-booking') . '</p>';
		}

		$atts = shortcode_atts(
			[
				'my_bookings_url' => '',
			],
			is_array($atts) ? $atts : []
		);

		$errors = [];
		$success = '';
		$user_id = get_current_user_id();

		$edit_booking_id = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
		$edit_booking = null;
		$edit_admin_message = '';
		if ($edit_booking_id) {
			$edit_booking = Bookings::get_booking($edit_booking_id);
			if ($edit_booking && (int) $edit_booking->post_author === $user_id) {
				$status = (string) get_post_meta($edit_booking_id, '_cie_booking_status', true);
				if ($status === Post_Types::BOOKING_STATUS_CHANGES) {
					$edit_admin_message = (string) get_post_meta($edit_booking_id, '_cie_booking_admin_message', true);
				} else {
					$edit_booking = null;
					$edit_booking_id = 0;
				}
			} else {
				$edit_booking = null;
				$edit_booking_id = 0;
			}
		}

		if (!empty($_POST['cie_booking_submit'])) {
			check_admin_referer('cie_booking_submit', '_wpnonce_cie_booking');

			$validation = Bookings::validate_booking_request($_POST);
			if (!$validation['ok']) {
				$errors = $validation['errors'];
			} else {
				$booking_id = !empty($_POST['booking_id'])
					? Bookings::update_booking((int) $_POST['booking_id'], $user_id, $validation['data'])
					: Bookings::create_booking($user_id, $validation['data']);
				if (is_wp_error($booking_id)) {
					$errors[] = __('No se pudo crear la reserva. Inténtelo de nuevo.', 'cie-lab-booking');
				} else {
					Mailer::notify_admin_booking_submitted((int) $booking_id);

					Mailer::notify_user_booking_status(
						$user_id,
						__('Reserva recibida', 'cie-lab-booking'),
						__('Su reserva ha sido recibida correctamente. Recibirá un mensaje de confirmación cuando sea validada por el administrador del laboratorio.', 'cie-lab-booking')
					);

					$success = __('Su reserva ha sido recibida correctamente. Recibirá a su correo electrónico un mensaje de confirmación cuando sea validada por el administrador del laboratorio.', 'cie-lab-booking');
				}
			}
		}

		// Prefill from existing booking if editing and not yet posted.
		if ($edit_booking_id && empty($_POST)) {
			$_POST['start_date'] = (string) get_post_meta($edit_booking_id, '_cie_booking_start_date', true);
			$_POST['end_date'] = (string) get_post_meta($edit_booking_id, '_cie_booking_end_date', true);
			$_POST['spaces'] = (array) get_post_meta($edit_booking_id, '_cie_booking_spaces', true);
			$_POST['equipment'] = (array) get_post_meta($edit_booking_id, '_cie_booking_equipment', true);
			$_POST['use_space'] = !empty($_POST['spaces']) ? '1' : '';
			$_POST['use_equipment'] = !empty($_POST['equipment']) ? '1' : '';
			$_POST['has_courses'] = 'yes';
			$_POST['project_name'] = (string) get_post_meta($edit_booking_id, '_cie_booking_project_name', true);
			$_POST['project_duration'] = (string) get_post_meta($edit_booking_id, '_cie_booking_project_duration', true);
			$_POST['project_responsible'] = (string) get_post_meta($edit_booking_id, '_cie_booking_project_responsible', true);
			$_POST['project_ip_email'] = (string) get_post_meta($edit_booking_id, '_cie_booking_project_ip_email', true);
		}

		$spaces = Bookings::get_resources('space', true);
		$equipment_grouped = Bookings::get_equipment_grouped(true);

		ob_start();
		?>
		<div class="cie-lab-booking">
			<h3><?php echo esc_html__('Reserva de equipos / espacios', 'cie-lab-booking'); ?></h3>
			<p><?php echo esc_html__('Complete el siguiente formulario para reservar equipos / espacios del Laboratorio de Lingüística Experimental del Centro Internacional del Español.', 'cie-lab-booking'); ?></p>

			<?php if ($edit_admin_message): ?>
				<p><strong><?php echo esc_html__('Cambios solicitados por el administrador:', 'cie-lab-booking'); ?></strong><br/>
				<?php echo esc_html($edit_admin_message); ?></p>
			<?php endif; ?>

			<?php if ($success): ?>
				<div class="cie-lab-booking__success">
					<div class="cie-lab-booking__success-title"><?php echo esc_html__('Reserva enviada', 'cie-lab-booking'); ?></div>
					<p><?php echo esc_html($success); ?></p>
					<div class="cie-lab-booking__success-actions">
						<?php
						$another_url = remove_query_arg(['booking_id'], (string) get_permalink());
						$my_url = trim((string) ($atts['my_bookings_url'] ?? ''));
						?>
						<a class="cie-btn cie-btn--primary" href="<?php echo esc_url($another_url); ?>">
							<?php echo esc_html__('Hacer otra reserva', 'cie-lab-booking'); ?>
						</a>
						<?php if ($my_url !== ''): ?>
							<a class="cie-btn" href="<?php echo esc_url($my_url); ?>">
								<?php echo esc_html__('Volver a mis reservas', 'cie-lab-booking'); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
				<?php
				// Hide form after successful submission.
				return (string) ob_get_clean();
				?>
			<?php endif; ?>

			<?php if ($errors): ?>
				<div class="cie-lab-booking__errors">
					<ul>
						<?php foreach ($errors as $e): ?>
							<li><?php echo esc_html($e); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field('cie_booking_submit', '_wpnonce_cie_booking'); ?>
				<input type="hidden" name="cie_booking_submit" value="1" />
				<?php if ($edit_booking_id): ?>
					<input type="hidden" name="booking_id" value="<?php echo esc_attr($edit_booking_id); ?>" />
				<?php endif; ?>

				<div class="cie-lab-booking__flow" data-cie-booking-flow="1">
				<fieldset data-cie-step="1">
					<legend><?php echo esc_html__('(1) Seleccione en el calendario las fechas de la reserva', 'cie-lab-booking'); ?></legend>
					<p>
						<label>
							<?php echo esc_html__('Desde', 'cie-lab-booking'); ?>
							<input type="text" class="cie-date" name="start_date" placeholder="YYYY-MM-DD" value="<?php echo esc_attr($_POST['start_date'] ?? ''); ?>" required />
						</label>
					</p>
					<p>
						<label>
							<?php echo esc_html__('Hasta', 'cie-lab-booking'); ?>
							<input type="text" class="cie-date" name="end_date" placeholder="YYYY-MM-DD" value="<?php echo esc_attr($_POST['end_date'] ?? ''); ?>" required />
						</label>
					</p>
					<div class="cie-lab-booking__notice" data-cie-notice="dates" style="display:none"></div>
				</fieldset>

				<fieldset data-cie-step="2">
					<legend><?php echo esc_html__('(2) Seleccione el tipo de instalación que quiere usar', 'cie-lab-booking'); ?></legend>
					<label>
						<input type="checkbox" name="use_space" value="1" <?php checked(!empty($_POST['use_space'])); ?> />
						<?php echo esc_html__('el laboratorio sin equipos', 'cie-lab-booking'); ?>
					</label><br/>
					<label>
						<input type="checkbox" name="use_equipment" value="1" <?php checked(!empty($_POST['use_equipment'])); ?> />
						<?php echo esc_html__('los equipos (sin usar el laboratorio)', 'cie-lab-booking'); ?>
					</label>
					<div class="cie-lab-booking__notice" data-cie-notice="type" style="display:none"></div>
				</fieldset>

				<fieldset data-cie-step="3">
					<legend><?php echo esc_html__('(3) Espacios', 'cie-lab-booking'); ?></legend>
					<p class="cie-lab-booking__hint"><?php echo esc_html__('Seleccione qué espacio quiere reservar:', 'cie-lab-booking'); ?></p>
					<div class="cie-lab-booking__notice" data-cie-notice="spaces" style="display:none"></div>
					<?php foreach ($spaces as $space): ?>
						<label>
							<input type="checkbox" name="spaces[]" value="<?php echo esc_attr($space->ID); ?>" <?php echo in_array((string) $space->ID, (array) ($_POST['spaces'] ?? []), true) ? 'checked' : ''; ?> />
							<?php echo esc_html($space->post_title); ?>
						</label><br/>
					<?php endforeach; ?>
				</fieldset>

				<fieldset data-cie-step="4">
					<legend><?php echo esc_html__('(4) Equipos', 'cie-lab-booking'); ?></legend>
					<div class="cie-lab-booking__notice" data-cie-notice="equipment" style="display:none"></div>
					<?php foreach ($equipment_grouped as $group => $items): ?>
						<details>
							<summary><?php echo esc_html(self::group_label($group)); ?></summary>
							<?php foreach ($items as $eq): ?>
								<label>
									<input type="checkbox" name="equipment[]" value="<?php echo esc_attr($eq->ID); ?>" <?php echo in_array((string) $eq->ID, (array) ($_POST['equipment'] ?? []), true) ? 'checked' : ''; ?> />
									<?php echo esc_html($eq->post_title); ?>
								</label><br/>
							<?php endforeach; ?>
						</details>
					<?php endforeach; ?>
				</fieldset>

				<fieldset data-cie-step="5">
					<legend><?php echo esc_html__('(5) ¿Ha realizado los cursos de formación para el uso de los espacios/equipos seleccionados?', 'cie-lab-booking'); ?></legend>
					<label>
						<input type="radio" name="has_courses" value="yes" <?php checked(($_POST['has_courses'] ?? '') === 'yes'); ?> required />
						<?php echo esc_html__('Sí', 'cie-lab-booking'); ?>
					</label>
					<label style="margin-left:12px">
						<input type="radio" name="has_courses" value="no" <?php checked(($_POST['has_courses'] ?? '') === 'no'); ?> />
						<?php echo esc_html__('No', 'cie-lab-booking'); ?>
					</label>
					<div class="cie-lab-booking__notice" data-cie-notice="courses" style="display:none"></div>
				</fieldset>

				<fieldset data-cie-step="6">
					<legend><?php echo esc_html__('(6) Datos del proyecto', 'cie-lab-booking'); ?></legend>
					<p>
						<label>
							<?php echo esc_html__('Nombre del proyecto*', 'cie-lab-booking'); ?><br/>
							<input type="text" name="project_name" value="<?php echo esc_attr($_POST['project_name'] ?? ''); ?>" required />
						</label>
					</p>
					<p>
						<label>
							<?php echo esc_html__('Duración del proyecto*', 'cie-lab-booking'); ?><br/>
							<input type="text" name="project_duration" value="<?php echo esc_attr($_POST['project_duration'] ?? ''); ?>" required />
						</label>
					</p>
					<p>
						<label>
							<?php echo esc_html__('Responsable del proyecto*', 'cie-lab-booking'); ?><br/>
							<input type="text" name="project_responsible" value="<?php echo esc_attr($_POST['project_responsible'] ?? ''); ?>" required />
						</label>
					</p>
					<p>
						<label>
							<?php echo esc_html__('Correo electrónico del IP/Director/a de tesis doctoral*', 'cie-lab-booking'); ?><br/>
							<input type="email" name="project_ip_email" value="<?php echo esc_attr($_POST['project_ip_email'] ?? ''); ?>" required />
						</label>
					</p>
				</fieldset>

				<p>
					<button type="submit" data-cie-submit><?php echo esc_html__('Enviar', 'cie-lab-booking'); ?></button>
				</p>
				</div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function render_my_bookings(): string {
		// Backwards compatible combined view.
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('Debes iniciar sesión para ver tus reservas.', 'cie-lab-booking') . '</p>';
		}
		if (!Util::current_user_can_book()) {
			return '<p>' . esc_html__('Tu usuario no tiene permisos para ver reservas.', 'cie-lab-booking') . '</p>';
		}

		$user_id = get_current_user_id();
		$today = gmdate('Y-m-d');

		$bookings = get_posts([
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
			'author' => $user_id,
		]);

		$current = [];
		$history = [];
		foreach ($bookings as $b) {
			[$bucket] = self::bucket_booking($b, $today);
			if ($bucket === 'current') {
				$current[] = $b;
			} else {
				$history[] = $b;
			}
		}

		ob_start();
		$uid = 'cie-tabs-' . wp_generate_uuid4();
		?>
		<div class="cie-lab-booking">
			<h3><?php echo esc_html__('Mis reservas', 'cie-lab-booking'); ?></h3>

			<!-- Desktop: CSS-only native tabs (radio + label) -->
			<div class="cie-native-tabs">
				<input class="cie-native-tabs__radio" type="radio" name="<?php echo esc_attr($uid); ?>" id="<?php echo esc_attr($uid . '-current'); ?>" checked>
				<input class="cie-native-tabs__radio" type="radio" name="<?php echo esc_attr($uid); ?>" id="<?php echo esc_attr($uid . '-history'); ?>">

				<div class="cie-native-tabs__bar" role="tablist" aria-label="<?php echo esc_attr__('Mis reservas', 'cie-lab-booking'); ?>">
					<label class="cie-native-tabs__tab" for="<?php echo esc_attr($uid . '-current'); ?>" role="tab">
						<?php echo esc_html__('Reservas en curso', 'cie-lab-booking'); ?>
					</label>
					<label class="cie-native-tabs__tab" for="<?php echo esc_attr($uid . '-history'); ?>" role="tab">
						<?php echo esc_html__('Histórico', 'cie-lab-booking'); ?>
					</label>
				</div>

				<div class="cie-native-tabs__panel cie-native-tabs__panel--current" role="tabpanel">
					<?php echo self::render_booking_list($current); ?>
				</div>
				<div class="cie-native-tabs__panel cie-native-tabs__panel--history" role="tabpanel">
					<?php echo self::render_booking_list($history); ?>
				</div>
			</div>

			<!-- Mobile: native dropdown/accordion (details) -->
			<div class="cie-native-accordion">
				<details open>
					<summary><?php echo esc_html__('Reservas en curso', 'cie-lab-booking'); ?></summary>
					<div class="cie-native-accordion__panel">
						<?php echo self::render_booking_list($current); ?>
					</div>
				</details>
				<details>
					<summary><?php echo esc_html__('Histórico', 'cie-lab-booking'); ?></summary>
					<div class="cie-native-accordion__panel">
						<?php echo self::render_booking_list($history); ?>
					</div>
				</details>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function render_my_active_bookings_card($atts = []): string {
		if (!is_user_logged_in()) {
			return '';
		}
		if (!Util::current_user_can_book()) {
			return '';
		}

		$atts = shortcode_atts(
			[
				'title' => __('Reservas activas', 'cie-lab-booking'),
				'link_url' => '',
				'link_label' => __('Ver mis reservas', 'cie-lab-booking'),
			],
			is_array($atts) ? $atts : []
		);

		$user_id = get_current_user_id();
		$today = gmdate('Y-m-d');
		$count = self::count_active_bookings($user_id, $today);

		ob_start();
		?>
		<div class="cie-card">
			<div class="cie-card__title"><?php echo esc_html((string) $atts['title']); ?></div>
			<div class="cie-card__value"><?php echo (int) $count; ?></div>
			<?php if (trim((string) $atts['link_url']) !== ''): ?>
				<div class="cie-card__actions">
					<a class="cie-btn" href="<?php echo esc_url((string) $atts['link_url']); ?>">
						<?php echo esc_html((string) $atts['link_label']); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function render_my_bookings_current($atts = []): string {
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('Debes iniciar sesión para ver tus reservas.', 'cie-lab-booking') . '</p>';
		}
		if (!Util::current_user_can_book()) {
			return '<p>' . esc_html__('Tu usuario no tiene permisos para ver reservas.', 'cie-lab-booking') . '</p>';
		}

		$atts = shortcode_atts(
			[
				'title' => __('Reservas en curso', 'cie-lab-booking'),
				'form_url' => '',
			],
			is_array($atts) ? $atts : []
		);

		$user_id = get_current_user_id();
		$today = gmdate('Y-m-d');
		$bookings = get_posts([
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
			'author' => $user_id,
		]);

		$current = [];
		foreach ($bookings as $b) {
			[$bucket] = self::bucket_booking($b, $today);
			if ($bucket === 'current') {
				$current[] = $b;
			}
		}

		ob_start();
		?>
		<div class="cie-lab-booking">
			<h3><?php echo esc_html((string) $atts['title']); ?></h3>
			<?php echo self::render_booking_list($current, (string) $atts['form_url']); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function render_my_bookings_history($atts = []): string {
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('Debes iniciar sesión para ver tus reservas.', 'cie-lab-booking') . '</p>';
		}
		if (!Util::current_user_can_book()) {
			return '<p>' . esc_html__('Tu usuario no tiene permisos para ver reservas.', 'cie-lab-booking') . '</p>';
		}

		$atts = shortcode_atts(
			[
				'title' => __('Histórico de reservas', 'cie-lab-booking'),
				'form_url' => '',
			],
			is_array($atts) ? $atts : []
		);

		$user_id = get_current_user_id();
		$today = gmdate('Y-m-d');
		$bookings = get_posts([
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
			'author' => $user_id,
		]);

		$history = [];
		foreach ($bookings as $b) {
			[$bucket] = self::bucket_booking($b, $today);
			if ($bucket === 'history') {
				$history[] = $b;
			}
		}

		ob_start();
		?>
		<div class="cie-lab-booking">
			<h3><?php echo esc_html((string) $atts['title']); ?></h3>
			<?php echo self::render_booking_list($history, (string) $atts['form_url']); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function render_calendar(): string {
		// Read-only calendar for users: show 3 months starting current month.
		$start = gmdate('Y-m-01');
		$end = gmdate('Y-m-d', strtotime('+3 months -1 day', strtotime($start)));
		$day_map = Bookings::build_day_map($start, $end);

		ob_start();
		?>
		<div class="cie-lab-booking">
			<h3><?php echo esc_html__('Calendario de reservas (solo lectura)', 'cie-lab-booking'); ?></h3>
			<?php echo self::render_calendar_months($start, 3, $day_map); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function group_label(string $group): string {
		$map = [
			'recording' => __('Equipos de grabación', 'cie-lab-booking'),
			'phonetics' => __('Equipos de análisis fonético', 'cie-lab-booking'),
			'eye-tracker' => __('Equipos de eye-tracker', 'cie-lab-booking'),
			'eeg' => __('Equipos de EEG', 'cie-lab-booking'),
			'other' => __('Otros', 'cie-lab-booking'),
		];
		return $map[$group] ?? $group;
	}

	/**
	 * @param array<int,\WP_Post> $bookings
	 */
	private static function render_booking_list(array $bookings, string $form_url = ''): string {
		if (!$bookings) {
			return '<p><em>' . esc_html__('No hay reservas.', 'cie-lab-booking') . '</em></p>';
		}

		ob_start();
		?>
		<table class="cie-table">
			<thead>
				<tr>
					<th><?php echo esc_html__('Fechas', 'cie-lab-booking'); ?></th>
					<th><?php echo esc_html__('Recursos', 'cie-lab-booking'); ?></th>
					<th><?php echo esc_html__('Estado', 'cie-lab-booking'); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($bookings as $b): ?>
				<?php
				$start = (string) get_post_meta($b->ID, '_cie_booking_start_date', true);
				$end = (string) get_post_meta($b->ID, '_cie_booking_end_date', true);
				$status = (string) get_post_meta($b->ID, '_cie_booking_status', true);
				$admin_message = (string) get_post_meta($b->ID, '_cie_booking_admin_message', true);
				$spaces = (array) get_post_meta($b->ID, '_cie_booking_spaces', true);
				$equipment = (array) get_post_meta($b->ID, '_cie_booking_equipment', true);
				$base = $form_url !== '' ? $form_url : (string) get_permalink();
				$edit_url = add_query_arg(['booking_id' => (int) $b->ID], $base);
				$status_slug = self::status_slug($status);
				?>
				<tr>
					<td><?php echo esc_html($start . ' - ' . $end); ?></td>
					<td>
						<?php echo esc_html(self::resources_summary($spaces, $equipment)); ?>
					</td>
					<td>
						<span class="cie-status-tag cie-status-tag--<?php echo esc_attr($status_slug); ?>">
							<?php echo esc_html(self::status_label($status)); ?>
						</span>
						<?php if ($status === Post_Types::BOOKING_STATUS_CHANGES): ?>
							<br/><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html__('Editar y reenviar', 'cie-lab-booking'); ?></a>
							<?php if ($admin_message): ?>
								<br/><small><?php echo esc_html($admin_message); ?></small>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return (string) ob_get_clean();
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

	/**
	 * @return array{0:'current'|'history',1:string} bucket + status
	 */
	private static function bucket_booking(\WP_Post $booking, string $today): array {
		$status = (string) get_post_meta($booking->ID, '_cie_booking_status', true);
		$end = (string) get_post_meta($booking->ID, '_cie_booking_end_date', true);

		// Cancelled and rejected always go to history.
		if (in_array($status, [Post_Types::BOOKING_STATUS_CANCELLED, Post_Types::BOOKING_STATUS_REJECTED], true)) {
			return ['history', $status];
		}

		// Ongoing/upcoming for non-cancelled/non-rejected.
		if ($end !== '' && $end >= $today) {
			return ['current', $status];
		}

		return ['history', $status];
	}

	private static function count_active_bookings(int $user_id, string $today): int {
		$ids = get_posts([
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'author' => $user_id,
			'meta_query' => [
				[
					'key' => '_cie_booking_end_date',
					'value' => $today,
					'compare' => '>=',
					'type' => 'DATE',
				],
				[
					'key' => '_cie_booking_status',
					'value' => [Post_Types::BOOKING_STATUS_CANCELLED, Post_Types::BOOKING_STATUS_REJECTED],
					'compare' => 'NOT IN',
				],
			],
		]);

		return is_array($ids) ? count($ids) : 0;
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

	/**
	 * @param array<string, array{has_space:bool,has_equipment:bool,blocked:bool}> $day_map
	 */
	private static function render_calendar_months(string $month_start_ymd, int $months, array $day_map): string {
		$start_ts = strtotime($month_start_ymd . ' 00:00:00');
		$out = '';
		for ($i = 0; $i < $months; $i++) {
			$m_ts = strtotime('+' . $i . ' months', $start_ts);
			$out .= self::render_calendar_month(gmdate('Y-m-01', $m_ts), $day_map);
		}
		return $out;
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
		<table class="cie-lab-booking__calendar" style="margin-bottom:16px;">
			<caption style="text-align:left;"><?php echo esc_html($month_label); ?></caption>
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
						$bg = '#e5e7eb'; // grey (none)
						if ($state['blocked']) {
							$bg = '#ef4444'; // red
						} elseif ($state['has_space'] && $state['has_equipment']) {
							$bg = '#22c55e'; // green
						} elseif ($state['has_space']) {
							$bg = '#f59e0b'; // yellow
						} elseif ($state['has_equipment']) {
							$bg = '#3b82f6'; // blue
						}
						?>
						<td class="cie-calendar-day" data-cie-date="<?php echo esc_attr($ymd); ?>" style="cursor:pointer;background:<?php echo esc_attr($bg); ?>;color:#111;text-align:center;">
							<?php echo (int) $day; ?>
						</td>
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

