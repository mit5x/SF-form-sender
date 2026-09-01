<?php
/**
 * Страница настроек: шесть вкладок.
 *
 * Вкладка сохраняется отдельно от остальных: так на большой странице видно,
 * что именно ты сейчас меняешь, и случайное сохранение не трогает чужие поля.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Settings_Page {

	/**
	 * Вывод страницы.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = SF_FS_Settings::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'main'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'main';
		}
		?>
		<div class="wrap sf-fs">
			<div class="sf-fs-head">
				<h1><?php esc_html_e( 'SF Form sender', 'sf-form-sender' ); ?></h1>
				<?php SF_FS_Admin::language_switcher( SF_FS_Admin::PAGE, $tab ); ?>
			</div>

			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Настройки сохранены.', 'sf-form-sender' ); ?></p>
				</div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper sf-fs-tabs">
				<?php foreach ( $tabs as $slug => $title ) : ?>
					<a class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . SF_FS_Admin::PAGE . '&tab=' . $slug ) ); ?>">
						<?php echo esc_html( $title ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<div class="sf-fs-panel">
				<?php
				if ( 'docs' === $tab ) {
					self::render_docs();
				} elseif ( 'notices' === $tab ) {
					self::render_notices();
				} else {
					self::render_fields( $tab );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Поля обычной вкладки.
	 *
	 * @param string $tab Вкладка.
	 * @return void
	 */
	private static function render_fields( $tab ) {
		$schema = SF_FS_Settings::schema();
		$values = SF_FS_Settings::all();

		$intro = self::tab_intro( $tab );
		if ( '' !== $intro ) {
			echo '<p class="sf-fs-intro">' . wp_kses_post( $intro ) . '</p>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sf-fs-form">
			<?php wp_nonce_field( 'sf_fs_save_settings' ); ?>
			<input type="hidden" name="action" value="sf_fs_save_settings">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">

			<table class="form-table sf-fs-table" role="presentation">
				<tbody>
				<?php
				foreach ( $schema as $key => $field ) {
					if ( $field['tab'] !== $tab ) {
						continue;
					}
					if ( isset( $field['group'] ) ) {
						echo '<tr class="sf-fs-group"><th colspan="2"><h3>' . esc_html( $field['group'] ) . '</h3></th></tr>';
					}
					self::render_field( $key, $field, $values[ $key ] );
				}
				?>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Сохранить', 'sf-form-sender' ); ?></button>
			</p>
		</form>
		<?php

		if ( 'smtp' === $tab ) {
			SF_FS_Smtp_Test::render_widget();
		}
	}

	/**
	 * Пояснение к вкладке.
	 *
	 * @param string $tab Вкладка.
	 * @return string
	 */
	private static function tab_intro( $tab ) {
		if ( 'smtp' === $tab ) {
			return esc_html__( 'Настройки параметров SMTP сервера, которые будут использоваться, если выбран данный способ отправки форм на e-mail.', 'sf-form-sender' );
		}
		if ( 'phpmail' === $tab ) {
			return '<strong>' . esc_html__( 'ВАЖНО!', 'sf-form-sender' ) . '</strong> '
				. esc_html__( 'Заполняйте значения только в том случае, если вы твёрдо знаете, что это такое, и уверены, что вам это действительно нужно. В иных случаях оставьте данные поля пустыми.', 'sf-form-sender' );
		}
		if ( 'antispam' === $tab ) {
			return esc_html__( 'Четыре независимые преграды: капча, ловушка на слишком быструю отправку и скрытое поле-приманка. Первые две видит посетитель, остальные — только робот.', 'sf-form-sender' );
		}
		return '';
	}

	/**
	 * Одно поле.
	 *
	 * @param string              $key   Ключ.
	 * @param array<string,mixed> $field Описание.
	 * @param mixed               $value Значение.
	 * @return void
	 */
	private static function render_field( $key, $field, $value ) {
		$id      = 'sf-fs-' . $key;
		$name    = 'sf_fs[' . $key . ']';
		$depends = isset( $field['depends'] ) ? $field['depends'] : '';
		?>
		<tr class="sf-fs-row" <?php echo $depends ? 'data-sf-depends="' . esc_attr( $depends ) . '"' : ''; ?>>
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			</th>
			<td>
				<?php
				switch ( $field['type'] ) {
					case 'checkbox':
						?>
						<label class="sf-fs-switch">
							<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
								value="1" <?php checked( (bool) $value ); ?>
								data-sf-key="<?php echo esc_attr( $key ); ?>">
							<span><?php esc_html_e( 'Да', 'sf-form-sender' ); ?></span>
						</label>
						<?php
						break;

					case 'select':
						?>
						<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" data-sf-key="<?php echo esc_attr( $key ); ?>">
							<?php foreach ( $field['options'] as $option => $title ) : ?>
								<option value="<?php echo esc_attr( $option ); ?>" <?php selected( (string) $value, (string) $option ); ?>>
									<?php echo esc_html( $title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php
						break;

					case 'textarea':
						?>
						<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
							rows="6" class="large-text code"><?php echo esc_textarea( (string) $value ); ?></textarea>
						<?php
						break;

					case 'number':
						?>
						<input type="number" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
							value="<?php echo esc_attr( (string) $value ); ?>"
							<?php echo isset( $field['min'] ) ? 'min="' . esc_attr( (string) $field['min'] ) . '"' : ''; ?>
							<?php echo isset( $field['max'] ) ? 'max="' . esc_attr( (string) $field['max'] ) . '"' : ''; ?>
							<?php echo isset( $field['step'] ) ? 'step="' . esc_attr( (string) $field['step'] ) . '"' : ''; ?>
							class="small-text">
						<?php
						break;

					case 'password':
						?>
						<input type="password" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
							value="<?php echo esc_attr( (string) $value ); ?>" class="regular-text" autocomplete="new-password">
						<button type="button" class="button sf-fs-peek" data-target="<?php echo esc_attr( $id ); ?>">
							<?php esc_html_e( 'Показать', 'sf-form-sender' ); ?>
						</button>
						<?php
						break;

					default:
						?>
						<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
							value="<?php echo esc_attr( (string) $value ); ?>" class="regular-text">
						<?php
				}
				?>

				<?php if ( ! empty( $field['help'] ) ) : ?>
					<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Вкладка «Уведомления».
	 *
	 * @return void
	 */
	private static function render_notices() {
		$schema = SF_FS_Notices::schema();
		$values = SF_FS_Notices::all();
		?>
		<p class="sf-fs-intro">
			<?php esc_html_e( 'Тексты, которые видит посетитель сайта. При установке плагина они записываются на языке сайта и дальше живут отдельно от языка интерфейса: переключение языка админки эти тексты не меняет.', 'sf-form-sender' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'sf_fs_save_notices' ); ?>
			<input type="hidden" name="action" value="sf_fs_save_notices">

			<table class="form-table sf-fs-table" role="presentation">
				<tbody>
				<?php foreach ( $schema as $key => $item ) : ?>
					<tr>
						<th scope="row">
							<label for="sf-fs-notice-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $item['label'] ); ?></label>
						</th>
						<td>
							<textarea id="sf-fs-notice-<?php echo esc_attr( $key ); ?>"
								name="sf_fs_notice[<?php echo esc_attr( $key ); ?>]"
								rows="2" class="large-text"><?php echo esc_textarea( $values[ $key ] ); ?></textarea>
							<p class="description"><?php echo esc_html( $item['help'] ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Сохранить', 'sf-form-sender' ); ?></button>
			</p>

			<hr>

			<p class="sf-fs-reset">
				<label for="sf-fs-reset-locale"><?php esc_html_e( 'Заменить все тексты значениями по умолчанию на языке', 'sf-form-sender' ); ?></label>
				<select id="sf-fs-reset-locale" name="reset_locale">
					<option value=""><?php esc_html_e( '— не менять —', 'sf-form-sender' ); ?></option>
					<?php foreach ( SF_FS_I18n::languages() as $code => $title ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $title ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button" data-sf-confirm="<?php esc_attr_e( 'Все тексты уведомлений будут заменены. Продолжить?', 'sf-form-sender' ); ?>">
					<?php esc_html_e( 'Заменить', 'sf-form-sender' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * Вкладка «Документация».
	 *
	 * @return void
	 */
	private static function render_docs() {
		$markers = sf_fs_markers();
		require SF_FS_DIR . 'includes/admin/views/docs.php';
	}
}
