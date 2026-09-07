<?php
/**
 * Plugin Name:       SF Form sender
 * Plugin URI:        https://web-format.net
 * Description:       Универсальный перехватчик форм: принимает содержимое любой отмеченной формы, отправляет письмо (SMTP или phpmail), сохраняет заявку в базу данных, прикладывает рекламные метки из GET и защищает форму от роботов.
 * Version:           1.1.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Saytformat
 * Author URI:        https://web-format.net
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sf-form-sender
 * Domain Path:       /languages
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SF_FS_VERSION', '1.1.0' );
define( 'SF_FS_FILE', __FILE__ );
define( 'SF_FS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SF_FS_URL', plugin_dir_url( __FILE__ ) );
define( 'SF_FS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Слово, по которому плагин узнаёт форму, и все производные от него имена.
 *
 * Собрано в одном месте: если однажды придётся переименовать механизм
 * перехвата, меняется только эта таблица, а не разбросанные по коду строки.
 */
function sf_fs_markers() {
	return array(
		// Атрибуты тега <form>. Первый — из технического задания, второй —
		// то же самое в виде корректного для HTML5 data-атрибута.
		'attr'         => 'sf-form-sender-interceptor',
		'attr_data'    => 'data-sf-form-sender',
		// Классы контейнеров, которые вебмастер может разместить сам.
		'fail_class'   => 'sf_form_sender_interceptor_fail',
		'ok_class'     => 'sf_form_sender_interceptor_success',
		'captcha_class' => 'sf_form_sender_interceptor_captcha',
	);
}

require_once SF_FS_DIR . 'includes/class-sf-fs-i18n.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-settings.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-notices.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-db.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-tracking.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-uploads.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-captcha.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-mailer.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-interceptor.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-frontend.php';

if ( is_admin() ) {
	require_once SF_FS_DIR . 'includes/admin/class-sf-fs-columns.php';
	require_once SF_FS_DIR . 'includes/admin/class-sf-fs-admin.php';
	require_once SF_FS_DIR . 'includes/admin/class-sf-fs-settings-page.php';
	require_once SF_FS_DIR . 'includes/admin/class-sf-fs-submissions-page.php';
	require_once SF_FS_DIR . 'includes/admin/class-sf-fs-smtp-test.php';
}

/**
 * Запуск плагина.
 *
 * Каждый класс сам вешает свои хуки в конструкторе — здесь только порядок
 * создания, чтобы его было видно одним взглядом.
 */
function sf_fs_boot() {
	SF_FS_I18n::instance();
	SF_FS_Frontend::instance();
	SF_FS_Interceptor::instance();

	if ( is_admin() ) {
		SF_FS_Admin::instance();
		SF_FS_Smtp_Test::instance();
	}
}
add_action( 'plugins_loaded', 'sf_fs_boot' );

/**
 * Установка: таблицы и значения по умолчанию.
 *
 * Тексты уведомлений заполняются языком сайта — так администратор сразу
 * видит в полях осмысленные значения, а не пустые строки.
 */
function sf_fs_activate() {
	require_once SF_FS_DIR . 'includes/class-sf-fs-db.php';
	SF_FS_Db::install();
	SF_FS_Settings::install_defaults();
	SF_FS_Notices::install_defaults();
	SF_FS_Uploads::protect_directory();
}
register_activation_hook( __FILE__, 'sf_fs_activate' );

/**
 * Таблицы могли не появиться, если плагин обновили копированием файлов,
 * минуя активацию. Дешёвая проверка версии схемы на каждом запросе админки.
 */
function sf_fs_maybe_upgrade() {
	if ( (string) get_option( 'sf_fs_db_version' ) === SF_FS_Db::SCHEMA_VERSION ) {
		return;
	}
	SF_FS_Db::install();
	SF_FS_Settings::install_defaults();
	SF_FS_Notices::install_defaults();
}
add_action( 'admin_init', 'sf_fs_maybe_upgrade' );
