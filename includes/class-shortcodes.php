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
				if (in_array($status, [Post_Types::BOOKING_STATUS_PENDING, Post_Types::BOOKING_STATUS_CHANGES], true)) {
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
		$is_edit_mode = $edit_booking_id > 0 && $edit_booking !== null;

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

		// Prefill from existing booking in edit mode when form has not been submitted.
		if ($is_edit_mode && empty($_POST['cie_booking_submit'])) {
			$defaults = [
				'start_date' => (string) get_post_meta($edit_booking_id, '_cie_booking_start_date', true),
				'end_date' => (string) get_post_meta($edit_booking_id, '_cie_booking_end_date', true),
				'spaces' => (array) get_post_meta($edit_booking_id, '_cie_booking_spaces', true),
				'equipment' => (array) get_post_meta($edit_booking_id, '_cie_booking_equipment', true),
				'project_name' => (string) get_post_meta($edit_booking_id, '_cie_booking_project_name', true),
				'project_duration' => (string) get_post_meta($edit_booking_id, '_cie_booking_project_duration', true),
				'project_responsible' => (string) get_post_meta($edit_booking_id, '_cie_booking_project_responsible', true),
				'project_ip_email' => (string) get_post_meta($edit_booking_id, '_cie_booking_project_ip_email', true),
				'has_courses' => 'yes',
			];

			foreach ($defaults as $key => $value) {
				$current = $_POST[$key] ?? null;
				$is_empty_value = is_array($value) ? empty((array) $current) : trim((string) $current) === '';
				if (!isset($_POST[$key]) || $is_empty_value) {
					$_POST[$key] = $value;
				}
			}

			$_POST['use_space'] = !empty((array) ($_POST['spaces'] ?? [])) ? '1' : '';
			$_POST['use_equipment'] = !empty((array) ($_POST['equipment'] ?? [])) ? '1' : '';
		}

		$spaces = Bookings::get_resources('space', true);
		$equipment_grouped = Bookings::get_equipment_grouped(true);
		$range_prefill = trim((string) ($_POST['booking_range'] ?? ''));
		$posted_start = trim((string) ($_POST['start_date'] ?? ''));
		$posted_end = trim((string) ($_POST['end_date'] ?? ''));
		if ($range_prefill === '' && $posted_start !== '' && $posted_end !== '') {
			$range_prefill = $posted_start . ' to ' . $posted_end;
		}

		ob_start();
		?>
		<div id="cie-booking-form" class="cie-lab-booking">
			<h3>
				<?php
				echo $is_edit_mode
					? esc_html__('Editar reserva de equipos / espacios', 'cie-lab-booking')
					: esc_html__('Reserva de equipos / espacios', 'cie-lab-booking');
				?>
			</h3>
			<p>
				<?php
				echo $is_edit_mode
					? esc_html__('Revise y actualice los datos de su reserva. Cuando termine, envíe los cambios para que puedan validarse de nuevo.', 'cie-lab-booking')
					: esc_html__('Complete el siguiente formulario para reservar equipos / espacios del Laboratorio de Lingüística Experimental del Centro Internacional del Español.', 'cie-lab-booking');
				?>
			</p>

			<?php if ($edit_admin_message): ?>
				<p><strong><?php echo esc_html__('Cambios solicitados por el administrador:', 'cie-lab-booking'); ?></strong><br/>
				<?php echo esc_html($edit_admin_message); ?></p>
			<?php endif; ?>

			<?php if ($success): ?>
				<div class="cie-lab-booking__success">
					<div class="cie-lab-booking__success-title">
						<?php echo esc_html($is_edit_mode ? __('Cambios enviados', 'cie-lab-booking') : __('Reserva enviada', 'cie-lab-booking')); ?>
					</div>
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
				<fieldset data-cie-step="1" class="cie-step-card">
					<legend><?php echo esc_html__('1) Seleccione el rango de fechas de la reserva', 'cie-lab-booking'); ?></legend>
					<p>
						<label>
							<?php echo esc_html__('Fechas', 'cie-lab-booking'); ?><br/>
							<input type="text" class="cie-date-range" name="booking_range" placeholder="YYYY-MM-DD to YYYY-MM-DD" value="<?php echo esc_attr($range_prefill); ?>" required />
							<input type="hidden" name="start_date" value="<?php echo esc_attr($_POST['start_date'] ?? ''); ?>" />
							<input type="hidden" name="end_date" value="<?php echo esc_attr($_POST['end_date'] ?? ''); ?>" />
						</label>
					</p>
					<p class="cie-lab-booking__hint"><?php echo esc_html__('Solo se pueden seleccionar fechas desde hoy y hasta 3 meses en adelante.', 'cie-lab-booking'); ?></p>
					<div class="cie-lab-booking__notice" data-cie-notice="dates" style="display:none"></div>
				</fieldset>

				<fieldset data-cie-step="2" class="cie-step-card">
					<legend><?php echo esc_html__('2) Seleccione el tipo de instalación que quiere usar', 'cie-lab-booking'); ?></legend>
					<label class="cie-option">
						<input type="checkbox" name="use_space" value="1" <?php checked(!empty($_POST['use_space'])); ?> />
						<?php echo esc_html__('Laboratorio sin equipos', 'cie-lab-booking'); ?>
					</label>
					<label class="cie-option">
						<input type="checkbox" name="use_equipment" value="1" <?php checked(!empty($_POST['use_equipment'])); ?> />
						<?php echo esc_html__('Equipos (sin usar el laboratorio)', 'cie-lab-booking'); ?>
					</label>
					<div class="cie-lab-booking__notice" data-cie-notice="type" style="display:none"></div>
				</fieldset>

				<fieldset data-cie-step="3" class="cie-step-card">
					<legend><?php echo esc_html__('3) Espacios', 'cie-lab-booking'); ?></legend>
					<p class="cie-lab-booking__hint"><?php echo esc_html__('Seleccione qué espacio quiere reservar:', 'cie-lab-booking'); ?></p>
					<div class="cie-lab-booking__notice" data-cie-notice="spaces" style="display:none"></div>
					<?php foreach ($spaces as $space): ?>
						<label class="cie-option">
							<input type="checkbox" name="spaces[]" value="<?php echo esc_attr($space->ID); ?>" <?php echo in_array((string) $space->ID, (array) ($_POST['spaces'] ?? []), true) ? 'checked' : ''; ?> />
							<?php echo esc_html($space->post_title); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<fieldset data-cie-step="4" class="cie-step-card">
					<legend><?php echo esc_html__('4) Equipos', 'cie-lab-booking'); ?></legend>
					<div class="cie-lab-booking__notice" data-cie-notice="equipment" style="display:none"></div>
					<?php foreach ($equipment_grouped as $group => $items): ?>
						<details>
							<summary><?php echo esc_html(self::group_label($group)); ?></summary>
							<?php foreach ($items as $eq): ?>
								<?php $eq_qty = Bookings::get_resource_quantity((int) $eq->ID); ?>
								<label class="cie-option">
									<input type="checkbox" name="equipment[]" value="<?php echo esc_attr($eq->ID); ?>" <?php echo in_array((string) $eq->ID, (array) ($_POST['equipment'] ?? []), true) ? 'checked' : ''; ?> />
									<?php echo esc_html($eq->post_title); ?>
									<small><?php echo esc_html(sprintf(_n('%d unidad', '%d unidades', $eq_qty, 'cie-lab-booking'), $eq_qty)); ?></small>
								</label>
							<?php endforeach; ?>
						</details>
					<?php endforeach; ?>
				</fieldset>

				<fieldset data-cie-step="5" class="cie-step-card">
					<legend><?php echo esc_html__('5) ¿Ha realizado los cursos de formación para los recursos seleccionados?', 'cie-lab-booking'); ?></legend>
					<label class="cie-option">
						<input type="radio" name="has_courses" value="yes" <?php checked(($_POST['has_courses'] ?? '') === 'yes'); ?> required />
						<?php echo esc_html__('Sí', 'cie-lab-booking'); ?>
					</label>
					<label class="cie-option">
						<input type="radio" name="has_courses" value="no" <?php checked(($_POST['has_courses'] ?? '') === 'no'); ?> />
						<?php echo esc_html__('No', 'cie-lab-booking'); ?>
					</label>
					<div class="cie-lab-booking__notice" data-cie-notice="courses" style="display:none"></div>
				</fieldset>

				<fieldset data-cie-step="6" class="cie-step-card">
					<legend><?php echo esc_html__('6) Datos del proyecto', 'cie-lab-booking'); ?></legend>
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

				<p class="cie-lab-booking__submit" data-cie-submit-wrap style="display:none">
					<button type="submit" class="cie-btn cie-btn--primary" data-cie-submit>
						<?php echo esc_html($is_edit_mode ? __('Enviar cambios', 'cie-lab-booking') : __('Enviar reserva', 'cie-lab-booking')); ?>
					</button>
				</p>
				</div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function render_my_bookings($atts = []): string {
		// Backwards compatible combined view.
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('Debes iniciar sesión para ver tus reservas.', 'cie-lab-booking') . '</p>';
		}
		if (!Util::current_user_can_book()) {
			return '<p>' . esc_html__('Tu usuario no tiene permisos para ver reservas.', 'cie-lab-booking') . '</p>';
		}

		$atts = shortcode_atts(
			[
				'form_url' => '',
			],
			is_array($atts) ? $atts : []
		);

		$user_id = get_current_user_id();
		$action_notice = self::handle_user_booking_action($user_id);
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
		$uid = 'cie-my-bookings-' . wp_generate_uuid4();
		?>
		<div class="cie-lab-booking">
			<h3><?php echo esc_html__('Mis reservas', 'cie-lab-booking'); ?></h3>
			<?php echo self::render_action_notice($action_notice); ?>

			<div id="<?php echo esc_attr($uid); ?>" class="cie-inline-tabs" data-cie-inline-tabs>
				<style>
					/* Inline tabs CSS (scoped to this shortcode instance) */
					#<?php echo esc_html($uid); ?> .cie-inline-tabs__bar{display:flex;gap:8px;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:8px;margin-bottom:10px}
					#<?php echo esc_html($uid); ?> .cie-inline-tabs__tab{border:1px solid rgba(15,23,42,.2);background:#fff;color:#0f172a;border-radius:999px;padding:8px 12px;cursor:pointer;font-weight:800;font-size:13px;user-select:none}
					#<?php echo esc_html($uid); ?> .cie-inline-tabs__tab.is-active{background:#0ea5e9;border-color:#0284c7;color:#fff}
					#<?php echo esc_html($uid); ?> .cie-inline-tabs__select{margin-left:auto;display:none;padding:8px 10px;border-radius:10px;border:1px solid rgba(15,23,42,.2);background:#fff}
					#<?php echo esc_html($uid); ?> .cie-inline-tabs__panel{display:none}
					#<?php echo esc_html($uid); ?> .cie-inline-tabs__panel.is-active{display:block}
					@media (max-width:640px){
						#<?php echo esc_html($uid); ?> .cie-inline-tabs__tab{display:none}
						#<?php echo esc_html($uid); ?> .cie-inline-tabs__select{display:block}
					}
				</style>

				<div class="cie-inline-tabs__bar" role="tablist" aria-label="<?php echo esc_attr__('Mis reservas', 'cie-lab-booking'); ?>">
					<button type="button" class="cie-inline-tabs__tab is-active" role="tab" aria-selected="true" data-tab="current">
						<?php echo esc_html__('Reservas en curso', 'cie-lab-booking'); ?>
					</button>
					<button type="button" class="cie-inline-tabs__tab" role="tab" aria-selected="false" data-tab="history">
						<?php echo esc_html__('Histórico', 'cie-lab-booking'); ?>
					</button>
					<select class="cie-inline-tabs__select" aria-label="<?php echo esc_attr__('Seleccionar sección', 'cie-lab-booking'); ?>">
						<option value="current"><?php echo esc_html__('Reservas en curso', 'cie-lab-booking'); ?></option>
						<option value="history"><?php echo esc_html__('Histórico', 'cie-lab-booking'); ?></option>
					</select>
				</div>

				<div class="cie-inline-tabs__panel is-active" role="tabpanel" data-panel="current">
					<?php echo self::render_booking_list($current, (string) $atts['form_url'], true); ?>
				</div>
				<div class="cie-inline-tabs__panel" role="tabpanel" data-panel="history">
					<?php echo self::render_booking_list($history); ?>
				</div>

				<script>
					(function(){
						var root = document.getElementById(<?php echo wp_json_encode($uid); ?>);
						if(!root) return;
						var tabs = root.querySelectorAll('[data-tab]');
						var panels = root.querySelectorAll('[data-panel]');
						var select = root.querySelector('.cie-inline-tabs__select');

						function setActive(key){
							tabs.forEach(function(btn){
								var on = btn.getAttribute('data-tab') === key;
								btn.classList.toggle('is-active', on);
								btn.setAttribute('aria-selected', on ? 'true' : 'false');
							});
							panels.forEach(function(p){
								var on = p.getAttribute('data-panel') === key;
								p.classList.toggle('is-active', on);
							});
							if(select) select.value = key;
						}

						tabs.forEach(function(btn){
							btn.addEventListener('click', function(){
								setActive(btn.getAttribute('data-tab') || 'current');
							});
						});
						if(select){
							select.addEventListener('change', function(){
								setActive(select.value || 'current');
							});
						}
					})();
				</script>
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
		$action_notice = self::handle_user_booking_action($user_id);
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
			<?php echo self::render_action_notice($action_notice); ?>
			<?php echo self::render_booking_list($current, (string) $atts['form_url'], true); ?>
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
		$action_notice = self::handle_user_booking_action($user_id);
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
			<?php echo self::render_action_notice($action_notice); ?>
			<?php echo self::render_booking_list($history, (string) $atts['form_url']); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Public API helper for external plugins.
	 *
	 * Returns an HTML table with the booking history for the given user ID,
	 * including reservation dates, resources and current booking status.
	 */
	public static function get_user_booking_history_html(int $user_id): string {
		$user_id = (int) $user_id;
		if ($user_id <= 0 || !get_user_by('id', $user_id)) {
			return '<p><em>' . esc_html__('Usuario no válido.', 'cie-lab-booking') . '</em></p>';
		}

		$bookings = get_posts([
			'post_type' => Post_Types::CPT_BOOKING,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
			'author' => $user_id,
		]);

		echo "<h3>Reservas</h3>";
		
		if (!$bookings) {
			return '<p><em>' . esc_html__('No hay reservas para este usuario.', 'cie-lab-booking') . '</em></p>';
		}

		ob_start();
		?>
		<table class="wp-list-table widefat fixed striped table-view-list cie-user-booking-history">
			<thead>
				<tr>
					<th scope="col" class="manage-column"><?php echo esc_html__('Reserva', 'cie-lab-booking'); ?></th>
					<th scope="col" class="manage-column"><?php echo esc_html__('Solicitud', 'cie-lab-booking'); ?></th>
					<th scope="col" class="manage-column"><?php echo esc_html__('Fechas de reserva', 'cie-lab-booking'); ?></th>
					<th scope="col" class="manage-column"><?php echo esc_html__('Recursos', 'cie-lab-booking'); ?></th>
					<th scope="col" class="manage-column"><?php echo esc_html__('Estado', 'cie-lab-booking'); ?></th>
					<th scope="col" class="manage-column"><?php echo esc_html__('Acciones', 'cie-lab-booking'); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($bookings as $b): ?>
				<?php
				$start = (string) get_post_meta($b->ID, '_cie_booking_start_date', true);
				$end = (string) get_post_meta($b->ID, '_cie_booking_end_date', true);
				$status = (string) get_post_meta($b->ID, '_cie_booking_status', true);
				$spaces = (array) get_post_meta($b->ID, '_cie_booking_spaces', true);
				$equipment = (array) get_post_meta($b->ID, '_cie_booking_equipment', true);
				$detail_url = admin_url('admin.php?page=cie-lab-booking-booking&booking_id=' . (int) $b->ID);
				?>
				<tr>
					<td>
						<strong><?php echo esc_html(sprintf(__('Reserva #%d', 'cie-lab-booking'), (int) $b->ID)); ?></strong>
					</td>
					<td><?php echo esc_html((string) mysql2date(get_option('date_format'), (string) $b->post_date)); ?></td>
					<td><?php echo esc_html($start . ' - ' . $end); ?></td>
					<td><?php echo esc_html(self::resources_summary($spaces, $equipment)); ?></td>
					<td>
						<span class="cie-status-tag cie-status-tag--<?php echo esc_attr(self::status_slug($status)); ?>">
							<?php echo esc_html(self::status_label($status)); ?>
						</span>
					</td>
					<td>
						<a class="button button-small" href="<?php echo esc_url($detail_url); ?>">
							<?php echo esc_html__('Ver reserva', 'cie-lab-booking'); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return (string) ob_get_clean();
	}

	public static function render_calendar($atts = []): string {
		$atts = shortcode_atts(
			[
				'calendar' => 'general',
			],
			is_array($atts) ? $atts : []
		);
		$calendar_mode = sanitize_key((string) ($atts['calendar'] ?? 'general'));
		if (!in_array($calendar_mode, ['general', 'current_user'], true)) {
			$calendar_mode = 'general';
		}

		// Read-only calendar for users: show 3 months starting current month.
		$start = gmdate('Y-m-01');
		$end = gmdate('Y-m-d', strtotime('+3 months -1 day', strtotime($start)));
		if ($calendar_mode === 'current_user') {
			if (!is_user_logged_in()) {
				return '<p>' . esc_html__('Debes iniciar sesión para ver tu calendario de reservas.', 'cie-lab-booking') . '</p>';
			}
			$day_map = Bookings::build_day_map_for_user($start, $end, get_current_user_id());
		} else {
			$day_map = Bookings::build_day_map($start, $end);
		}

		ob_start();
		?>
		<div class="cie-lab-booking" data-cie-calendar-scope="<?php echo esc_attr($calendar_mode); ?>">
			<h3>
				<?php
				echo $calendar_mode === 'current_user'
					? esc_html__('Calendario de mis reservas', 'cie-lab-booking')
					: esc_html__('Calendario de reservas (solo lectura)', 'cie-lab-booking');
				?>
			</h3>
			<?php echo self::render_calendar_months($start, 3, $day_map); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function group_label(string $group): string {
		return Bookings::get_equipment_group_label($group);
	}

	/**
	 * @param array<int,\WP_Post> $bookings
	 */
	private static function render_booking_list(array $bookings, string $form_url = '', bool $show_actions = false): string {
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
					<?php if ($show_actions): ?>
						<th><?php echo esc_html__('Acciones', 'cie-lab-booking'); ?></th>
					<?php endif; ?>
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
				$base = self::resolve_form_base_url($form_url);
				$edit_url = add_query_arg(
					[
						'booking_id' => (int) $b->ID,
						'cie_booking_edit' => '1',
					],
					$base
				);
				$status_slug = self::status_slug($status);
				$can_edit = in_array($status, [Post_Types::BOOKING_STATUS_PENDING, Post_Types::BOOKING_STATUS_CHANGES], true);
				$can_delete = !in_array($status, [Post_Types::BOOKING_STATUS_CANCELLED, Post_Types::BOOKING_STATUS_REJECTED], true);
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
							<?php if (!$show_actions): ?>
								<br/><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html__('Editar y reenviar', 'cie-lab-booking'); ?></a>
							<?php endif; ?>
							<?php if ($admin_message): ?>
								<br/><small><?php echo esc_html($admin_message); ?></small>
							<?php endif; ?>
						<?php endif; ?>
					</td>
					<?php if ($show_actions): ?>
						<td class="cie-table__actions">
							<?php if ($can_edit): ?>
								<a
									class="cie-btn cie-booking-edit-link"
									href="<?php echo esc_url($edit_url . '#cie-booking-form'); ?>"
									data-booking-id="<?php echo esc_attr((string) $b->ID); ?>"
									data-form-url="<?php echo esc_attr($base); ?>"
								>
									<?php echo esc_html__('Editar', 'cie-lab-booking'); ?>
								</a>
							<?php endif; ?>
							<?php if ($can_delete): ?>
								<form method="post" style="display:inline-block;margin:0;">
									<?php wp_nonce_field('cie_my_booking_action', '_wpnonce_cie_my_booking_action'); ?>
									<input type="hidden" name="cie_my_booking_action" value="delete" />
									<input type="hidden" name="booking_id" value="<?php echo esc_attr((string) $b->ID); ?>" />
									<button type="submit" class="cie-btn" onclick="return confirm('<?php echo esc_js(__('¿Eliminar esta reserva?', 'cie-lab-booking')); ?>');">
										<?php echo esc_html__('Eliminar', 'cie-lab-booking'); ?>
									</button>
								</form>
							<?php endif; ?>
							<?php if (!$can_edit && !$can_delete): ?>
								—
							<?php endif; ?>
						</td>
					<?php endif; ?>
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

	private static function resolve_form_base_url(string $form_url = ''): string {
		$form_url = trim($form_url);
		if ($form_url !== '') {
			return remove_query_arg(['booking_id', 'cie_booking_edit'], $form_url);
		}

		$referer = wp_get_referer();
		if (is_string($referer) && $referer !== '') {
			return remove_query_arg(['booking_id', 'cie_booking_edit'], $referer);
		}

		$permalink = get_permalink();
		if (is_string($permalink) && $permalink !== '') {
			return remove_query_arg(['booking_id', 'cie_booking_edit'], $permalink);
		}

		return home_url('/');
	}

	/**
	 * @return array{type:string,message:string}
	 */
	private static function handle_user_booking_action(int $user_id): array {
		static $handled = null;
		if (is_array($handled)) {
			return $handled;
		}

		$handled = ['type' => '', 'message' => ''];
		if (empty($_POST['cie_my_booking_action'])) {
			return $handled;
		}

		if (empty($_POST['_wpnonce_cie_my_booking_action']) || !wp_verify_nonce((string) $_POST['_wpnonce_cie_my_booking_action'], 'cie_my_booking_action')) {
			$handled = ['type' => 'error', 'message' => __('No se ha podido procesar la acción (nonce inválido).', 'cie-lab-booking')];
			return $handled;
		}

		$action = sanitize_key((string) ($_POST['cie_my_booking_action'] ?? ''));
		$booking_id = (int) ($_POST['booking_id'] ?? 0);
		if ($action !== 'delete' || $booking_id <= 0) {
			$handled = ['type' => 'error', 'message' => __('Acción no válida.', 'cie-lab-booking')];
			return $handled;
		}

		$booking = Bookings::get_booking($booking_id);
		if (!$booking || (int) $booking->post_author !== $user_id) {
			$handled = ['type' => 'error', 'message' => __('No puede eliminar esta reserva.', 'cie-lab-booking')];
			return $handled;
		}

		$status = (string) get_post_meta($booking_id, '_cie_booking_status', true);
		if (in_array($status, [Post_Types::BOOKING_STATUS_CANCELLED, Post_Types::BOOKING_STATUS_REJECTED], true)) {
			$handled = ['type' => 'error', 'message' => __('La reserva ya no está activa.', 'cie-lab-booking')];
			return $handled;
		}

		Bookings::set_booking_status($booking_id, Post_Types::BOOKING_STATUS_CANCELLED);
		$handled = ['type' => 'success', 'message' => __('Reserva eliminada correctamente.', 'cie-lab-booking')];
		return $handled;
	}

	/**
	 * @param array{type:string,message:string} $notice
	 */
	private static function render_action_notice(array $notice): string {
		if (empty($notice['type']) || empty($notice['message'])) {
			return '';
		}
		$class = $notice['type'] === 'error' ? 'cie-lab-booking__errors' : 'cie-lab-booking__success';
		return '<div class="' . esc_attr($class) . '"><p>' . esc_html((string) $notice['message']) . '</p></div>';
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
