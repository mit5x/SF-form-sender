<?php
/**
 * Админка: меню, подключение файлов, приём всех форм плагина.
 *
 * Все сохранения идут через admin-post.php и заканчиваются переадресацией
 * обратно на вкладку — иначе обновление страницы отправляло бы форму повторно.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Admin {

	/** Слаг страницы настроек. */
	const PAGE = 'sf-form-sender';

	/** Слаг страницы заявок. */
	const PAGE_SUBMISSIONS = 'sf-form-sender-submissions';

	/**
	 * Действие admin-ajax для ширины колонок.
	 *
	 * Ширину меняют мышью, и переадресация после каждого движения границы
	 * увела бы страницу с места, поэтому сохранение идёт отдельным запросом.
	 * Проверки те же, что и у форм: права и подпись.
	 */
	const AJAX_WIDTHS = 'sf_fs_save_widths';

	/** @var SF_FS_Admin|null */
	private static $instance = null;

	/** @return SF_FS_Admin */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . SF_FS_BASENAME, array( $this, 'action_links' ) );

		add_action( 'admin_post_sf_fs_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_sf_fs_save_notices', array( $this, 'save_notices' ) );
		add_action( 'admin_post_sf_fs_save_locale', array( $this, 'save_locale' ) );
		add_action( 'admin_post_sf_fs_save_columns', array( $this, 'save_columns' ) );
		add_action( 'admin_post_sf_fs_submissions', array( $this, 'submissions_action' ) );
		add_action( 'wp_ajax_' . self::AJAX_WIDTHS, array( $this, 'save_widths' ) );
	}

	/**
	 * Пункты меню.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'SF Form sender', 'sf-form-sender' ),
			__( 'SF Form sender', 'sf-form-sender' ),
			'manage_options',
			self::PAGE_SUBMISSIONS,
			array( SF_FS_Submissions_Page::class, 'render' ),
			'dashicons-email-alt',
			58.6
		);

		add_submenu_page(
			self::PAGE_SUBMISSIONS,
			__( 'Заявки', 'sf-form-sender' ),
			__( 'Заявки', 'sf-form-sender' ),
			'manage_options',
			self::PAGE_SUBMISSIONS,
			array( SF_FS_Submissions_Page::class, 'render' )
		);

		add_submenu_page(
			self::PAGE_SUBMISSIONS,
			__( 'Настройки SF Form sender', 'sf-form-sender' ),
			__( 'Настройки', 'sf-form-sender' ),
			'manage_options',
			self::PAGE,
			array( SF_FS_Settings_Page::class, 'render' )
		);
	}

	/**
	 * Ссылка «Настройки» в списке плагинов.
	 *
	 * @param array<int,string> $links Ссылки.
	 * @return array<int,string>
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Настройки', 'sf-form-sender' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Стили и скрипты только на своих страницах.
	 *
	 * @param string $hook Текущая страница админки.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}

		wp_enqueue_style( 'sf-fs-admin', SF_FS_URL . 'assets/css/admin.css', array(), SF_FS_VERSION );
		wp_enqueue_script( 'sf-fs-admin', SF_FS_URL . 'assets/js/admin.js', array(), SF_FS_VERSION, true );

		wp_localize_script(
			'sf-fs-admin',
			'SF_FS_ADMIN',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'smtpNonce'   => wp_create_nonce( SF_FS_Smtp_Test::ACTION ),
				'widthAction' => self::AJAX_WIDTHS,
				'widthNonce'  => wp_create_nonce( self::AJAX_WIDTHS ),
				'widthMin'    => SF_FS_Columns::MIN_WIDTH,
				'widthMax'    => SF_FS_Columns::MAX_WIDTH,
				'texts'       => array(
					'unsaved'       => __( 'Сохраните поля формы, перед тем как выполнить проверку.', 'sf-form-sender' ),
					'testing'       => __( 'Идёт проверка…', 'sf-form-sender' ),
					'testFailed'    => __( 'Проверка не удалась: сервер не ответил.', 'sf-form-sender' ),
					'confirmAll'    => __( 'Удалить все заявки без возможности восстановления?', 'sf-form-sender' ),
					'confirmSome'   => __( 'Удалить выбранные заявки?', 'sf-form-sender' ),
					'confirmOne'    => __( 'Удалить заявку?', 'sf-form-sender' ),
					'nothingPicked' => __( 'Ни одна заявка не выбрана.', 'sf-form-sender' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Приём форм                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Проверка прав и подписи формы.
	 *
	 * @param string $action Действие для nonce.
	 * @return void
	 */
	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'sf-form-sender' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Возврат на страницу, с которой пришли.
	 *
	 * @param string               $page Слаг страницы.
	 * @param array<string,string> $args Дополнительные параметры адреса.
	 * @return void
	 */
	private function back( $page, $args = array() ) {
		$url = add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Сохранение вкладки настроек.
	 *
	 * @return void
	 */
	public function save_settings() {
		$this->guard( 'sf_fs_save_settings' );

		$tab  = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'main';
		$tabs = SF_FS_Settings::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'main';
		}

		$input = isset( $_POST['sf_fs'] ) && is_array( $_POST['sf_fs'] ) ? $_POST['sf_fs'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		SF_FS_Settings::save_tab( $tab, $input );

		if ( 'main' === $tab ) {
			// Каталог для файлов и запрет их выполнения — на случай, если
			// сохранение включило приём файлов.
			SF_FS_Uploads::protect_directory();
		}

		$this->back( self::PAGE, array( 'tab' => $tab, 'saved' => '1' ) );
	}

	/**
	 * Сохранение текстов уведомлений.
	 *
	 * @return void
	 */
	public function save_notices() {
		$this->guard( 'sf_fs_save_notices' );

		$reset = isset( $_POST['reset_locale'] ) ? sanitize_text_field( wp_unslash( $_POST['reset_locale'] ) ) : '';
		if ( '' !== $reset ) {
			SF_FS_Notices::save( SF_FS_Notices::defaults_in( $reset ) );
			$this->back( self::PAGE, array( 'tab' => 'notices', 'saved' => '1' ) );
		}

		$input = isset( $_POST['sf_fs_notice'] ) && is_array( $_POST['sf_fs_notice'] ) ? $_POST['sf_fs_notice'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		SF_FS_Notices::save( $input );

		$this->back( self::PAGE, array( 'tab' => 'notices', 'saved' => '1' ) );
	}

	/**
	 * Смена языка интерфейса плагина.
	 *
	 * @return void
	 */
	public function save_locale() {
		$this->guard( 'sf_fs_save_locale' );

		$locale = isset( $_POST['sf_fs_locale'] ) ? sanitize_text_field( wp_unslash( $_POST['sf_fs_locale'] ) ) : '';
		SF_FS_I18n::set_admin_locale( $locale );

		$page = isset( $_POST['back_page'] ) ? sanitize_key( wp_unslash( $_POST['back_page'] ) ) : self::PAGE;
		$tab  = isset( $_POST['back_tab'] ) ? sanitize_key( wp_unslash( $_POST['back_tab'] ) ) : '';

		$this->back( $page, $tab ? array( 'tab' => $tab ) : array() );
	}

	/**
	 * Сохранение состава и порядка колонок списка заявок.
	 *
	 * @return void
	 */
	public function save_columns() {
		$this->guard( 'sf_fs_save_columns' );

		$order   = isset( $_POST['column_order'] ) ? (string) wp_unslash( $_POST['column_order'] ) : '';
		$visible = isset( $_POST['column_visible'] ) && is_array( $_POST['column_visible'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['column_visible'] ) )
			: array();

		SF_FS_Columns::save( array_filter( array_map( 'trim', explode( ',', $order ) ) ), $visible );

		// Подписи полей: администратор сопоставляет имя из формы и название
		// колонки прямо здесь же.
		if ( isset( $_POST['field_label'] ) && is_array( $_POST['field_label'] ) ) {
			foreach ( wp_unslash( $_POST['field_label'] ) as $id => $label ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( is_scalar( $label ) ) {
					SF_FS_Db::set_field_label( (int) $id, sanitize_text_field( (string) $label ) );
				}
			}
		}

		$per_page = isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 20;
		update_option( 'sf_fs_per_page', max( 5, min( 500, $per_page ) ) );

		$this->back( self::PAGE_SUBMISSIONS, array( 'saved' => '1' ) );
	}

	/**
	 * Сохранение ширины колонок, изменённой мышью.
	 *
	 * @return void
	 */
	public function save_widths() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'sf-form-sender' ) ), 403 );
		}
		check_ajax_referer( self::AJAX_WIDTHS );

		$widths = isset( $_POST['widths'] ) && is_array( $_POST['widths'] ) ? wp_unslash( $_POST['widths'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		wp_send_json_success( array( 'widths' => SF_FS_Columns::save_widths( $widths ) ) );
	}

	/**
	 * Удаление заявок.
	 *
	 * @return void
	 */
	public function submissions_action() {
		$this->guard( 'sf_fs_submissions' );

		$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '';
		$args = array();

		if ( 'delete_all' === $what ) {
			$args['deleted'] = SF_FS_Db::delete_all();
		} elseif ( 'delete' === $what ) {
			$ids             = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$args['deleted'] = SF_FS_Db::delete( array_map( 'intval', $ids ) );
		}

		$paged = isset( $_POST['paged'] ) ? (int) $_POST['paged'] : 1;
		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}

		$this->back( self::PAGE_SUBMISSIONS, $args );
	}

	/* ------------------------------------------------------------------ */
	/* Мелочи для страниц                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Переключатель языка интерфейса в правом верхнем углу.
	 *
	 * @param string $page Слаг текущей страницы.
	 * @param string $tab  Текущая вкладка.
	 * @return void
	 */
	public static function language_switcher( $page, $tab = '' ) {
		$current = SF_FS_I18n::admin_locale();
		?>
		<form class="sf-fs-lang" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'sf_fs_save_locale' ); ?>
			<input type="hidden" name="action" value="sf_fs_save_locale">
			<input type="hidden" name="back_page" value="<?php echo esc_attr( $page ); ?>">
			<input type="hidden" name="back_tab" value="<?php echo esc_attr( $tab ); ?>">

			<label for="sf-fs-locale"><?php esc_html_e( 'Язык интерфейса', 'sf-form-sender' ); ?></label>
			<select id="sf-fs-locale" name="sf_fs_locale">
				<?php foreach ( SF_FS_I18n::languages() as $code => $title ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $current ); ?>>
						<?php echo esc_html( $title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Применить', 'sf-form-sender' ); ?></button>
			<span class="sf-fs-lang__note">
				<?php
				printf(
					/* translators: %s — язык сайта из настроек WordPress. */
					esc_html__( 'По умолчанию — язык сайта (%s).', 'sf-form-sender' ),
					esc_html( SF_FS_I18n::site_locale() )
				);
				?>
			</span>
		</form>
		<?php
	}
}
