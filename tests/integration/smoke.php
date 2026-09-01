<?php
/**
 * Дымовая проверка на настоящем WordPress.
 *
 * Остальные тесты работают на заглушках: они быстрые и подробные, но заглушка
 * по определению не поймает того, что ломается именно в живом WordPress —
 * не создавшуюся таблицу, фатальную ошибку при отрисовке страницы админки,
 * незагрузившийся словарь. Установка плагина из архива на чистый сайт — самый
 * опасный сценарий, и проверяется он здесь.
 *
 * Запуск на сайте с уже установленным и включённым плагином:
 *   wp eval-file tests/integration/smoke.php
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Запускается только через wp eval-file.\n" );
}

$passed = 0;
$failed = array();

/**
 * Одна проверка.
 *
 * @param string $title     Что проверяем.
 * @param bool   $condition Итог.
 * @param mixed  $got       Что получилось — печатается при отказе.
 * @return void
 */
function sf_check( $title, $condition, $got = null ) {
	global $passed, $failed;

	if ( $condition ) {
		++$passed;
		echo '  ok   ' . $title . "\n";
		return;
	}

	$failed[] = $title;
	echo '  FAIL ' . $title;
	if ( null !== $got ) {
		echo ' | получено: ' . ( is_scalar( $got ) ? (string) $got : wp_json_encode( $got ) );
	}
	echo "\n";
}

/**
 * Заголовок группы.
 *
 * @param string $title Название.
 * @return void
 */
function sf_group( $title ) {
	echo "\n" . $title . "\n";
}

/* ---------------------------------------------------------------------- */
sf_group( 'Плагин поднялся' );
/* ---------------------------------------------------------------------- */

sf_check( 'главный файл выполнился', defined( 'SF_FS_VERSION' ) );
sf_check( 'версия непустая', defined( 'SF_FS_VERSION' ) && '' !== SF_FS_VERSION, defined( 'SF_FS_VERSION' ) ? SF_FS_VERSION : '' );

foreach ( array( 'SF_FS_Settings', 'SF_FS_Notices', 'SF_FS_Db', 'SF_FS_Tracking', 'SF_FS_Uploads', 'SF_FS_Captcha', 'SF_FS_Mailer', 'SF_FS_Interceptor', 'SF_FS_Frontend', 'SF_FS_I18n' ) as $class ) {
	sf_check( 'класс ' . $class . ' на месте', class_exists( $class ) );
}

// В wp eval-file is_admin() ложно, поэтому классы админки главный файл не
// подключал. Подключаем сами: страницы всё равно надо нарисовать.
foreach ( array( 'class-sf-fs-columns', 'class-sf-fs-admin', 'class-sf-fs-settings-page', 'class-sf-fs-submissions-page', 'class-sf-fs-smtp-test' ) as $file ) {
	require_once SF_FS_DIR . 'includes/admin/' . $file . '.php';
}

sf_check( 'классы админки подключаются без ошибок', class_exists( 'SF_FS_Settings_Page' ) && class_exists( 'SF_FS_Submissions_Page' ) );

/* ---------------------------------------------------------------------- */
sf_group( 'Таблицы' );
/* ---------------------------------------------------------------------- */

global $wpdb;

sf_check( 'все четыре таблицы созданы', SF_FS_Db::tables_ready() );
sf_check( 'версия схемы записана', (string) get_option( 'sf_fs_db_version' ) === SF_FS_Db::SCHEMA_VERSION, get_option( 'sf_fs_db_version' ) );

$expected = array(
	'submissions' => array( 'id', 'created_at', 'created_at_gmt', 'form_id', 'page_url', 'ip', 'user_agent', 'files_count' ),
	'fields'      => array( 'id', 'field_key', 'label', 'is_system', 'created_at' ),
	'values'      => array( 'id', 'submission_id', 'field_id', 'value' ),
	'files'       => array( 'id', 'submission_id', 'orig_name', 'stored_name', 'rel_path', 'size', 'mime' ),
);

foreach ( $expected as $short => $columns ) {
	$table   = SF_FS_Db::table( $short );
	$present = $wpdb->get_col( "SHOW COLUMNS FROM $table" ); // phpcs:ignore WordPress.DB
	$missing = array_diff( $columns, (array) $present );

	sf_check( 'колонки таблицы ' . $short . ' на месте', ! $missing, implode( ', ', $missing ) );
}

/* ---------------------------------------------------------------------- */
sf_group( 'Настройки и уведомления' );
/* ---------------------------------------------------------------------- */

$settings = get_option( SF_FS_Settings::OPTION );
sf_check( 'опция настроек создана при установке', is_array( $settings ) && $settings );
sf_check( 'способ отправки по умолчанию — phpmail', 'phpmail' === SF_FS_Settings::get( 'mail_method' ), SF_FS_Settings::get( 'mail_method' ) );
sf_check( 'сохранение заявок включено по умолчанию', SF_FS_Settings::on( 'save_to_db' ) );
sf_check( 'тема письма заполнена', '' !== trim( (string) SF_FS_Settings::get( 'mail_subject' ) ), SF_FS_Settings::get( 'mail_subject' ) );
// Не сверяем с адресом администратора буквально: проверку могут запустить и
// на сайте, где получатель уже задан своим.
$recipients = SF_FS_Settings::recipients();
$bad        = array_filter(
	$recipients,
	function ( $address ) {
		return ! is_email( $address );
	}
);
sf_check( 'получатель письма определён и он корректен', $recipients && ! $bad, implode( ', ', $recipients ) );

$notices = get_option( SF_FS_Notices::OPTION );
sf_check( 'тексты уведомлений созданы при установке', is_array( $notices ) && $notices );

$empty = array();
foreach ( array_keys( SF_FS_Notices::schema() ) as $key ) {
	if ( '' === trim( (string) SF_FS_Notices::get( $key ) ) ) {
		$empty[] = $key;
	}
}
sf_check( 'ни одно уведомление не осталось пустым', ! $empty, implode( ', ', $empty ) );

sf_check(
	'подстановки в уведомлении работают',
	false !== strpos( SF_FS_Notices::get( 'error_file_size', array( 'limit' => 7 ) ), '7' ),
	SF_FS_Notices::get( 'error_file_size', array( 'limit' => 7 ) )
);

/* ---------------------------------------------------------------------- */
sf_group( 'Каталог загрузок' );
/* ---------------------------------------------------------------------- */

SF_FS_Uploads::protect_directory();
$base = SF_FS_Uploads::base();

sf_check( 'каталог создан', is_dir( $base['dir'] ), $base['dir'] );
sf_check( 'выполнение файлов закрыто через .htaccess', file_exists( $base['dir'] . '/.htaccess' ) );
sf_check( 'список каталога закрыт index.html', file_exists( $base['dir'] . '/index.html' ) );

$rules = file_exists( $base['dir'] . '/.htaccess' ) ? file_get_contents( $base['dir'] . '/.htaccess' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
sf_check( 'в правилах выключен обработчик PHP', false !== strpos( $rules, 'engine off' ) );

/* ---------------------------------------------------------------------- */
sf_group( 'Заявка: запись, чтение, удаление' );
/* ---------------------------------------------------------------------- */

$before = SF_FS_Db::count();

$id = SF_FS_Db::save_submission(
	array(
		'fields'     => array( 'name' => 'Дымовая проверка', 'комментарий' => "первая строка\nвторая" ),
		'labels'     => array( 'name' => 'Ваше имя' ),
		'ip'         => '203.0.113.9',
		'url'        => home_url( '/contacts/' ),
		'date'       => current_time( 'mysql' ),
		'form_id'    => 'smoke',
		'user_agent' => 'wp eval-file',
	)
);

sf_check( 'заявка сохранена', $id > 0, $id );
sf_check( 'счётчик заявок вырос', SF_FS_Db::count() === $before + 1 );

$row = $id ? SF_FS_Db::submission( $id ) : null;
sf_check( 'заявка читается обратно', is_array( $row ) );
sf_check( 'значение поля на месте', $row && isset( $row['data']['name'] ) && 'Дымовая проверка' === $row['data']['name'], $row ? $row['data'] : null );
sf_check( 'кириллическое имя поля выдержало обход базы', $row && isset( $row['data']['комментарий'] ), $row ? array_keys( $row['data'] ) : null );
sf_check( 'подпись поля запомнилась', isset( SF_FS_Db::fields()['name'] ) && 'Ваше имя' === SF_FS_Db::fields()['name']['label'] );

// Письмо только собираем: отправлять с тестового сайта некуда и незачем.
$body = SF_FS_Mailer::body(
	array(
		'fields'        => array( 'name' => 'Дымовая проверка' ),
		'labels'        => array( 'name' => 'Ваше имя' ),
		'files'         => array(),
		'ip'            => '203.0.113.9',
		'url'           => home_url( '/contacts/' ),
		'date'          => current_time( 'mysql' ),
		'tracking_text' => 'utm_source: smoke',
	)
);
sf_check( 'тело письма собирается', false !== strpos( $body, 'Ваше имя' ) && false !== strpos( $body, 'utm_source: smoke' ) );

sf_check( 'заявка удаляется', $id && 1 === SF_FS_Db::delete( array( $id ) ) );
sf_check( 'после удаления заявок столько же, сколько было', SF_FS_Db::count() === $before );

/* ---------------------------------------------------------------------- */
sf_group( 'Страницы админки рисуются' );
/* ---------------------------------------------------------------------- */

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) {
	wp_set_current_user( $admins[0]->ID );
}
sf_check( 'администратор найден и у него есть права', current_user_can( 'manage_options' ) );

// paginate_links() смотрит на адрес текущего запроса, а в консоли его нет.
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=' . SF_FS_Admin::PAGE;

foreach ( array_keys( SF_FS_Settings::tabs() ) as $tab ) {
	$_GET['tab'] = $tab;

	ob_start();
	SF_FS_Settings_Page::render();
	$html = ob_get_clean();

	sf_check( 'вкладка «' . $tab . '» отрисована', strlen( $html ) > 500, strlen( $html ) );
	sf_check( 'на вкладке «' . $tab . '» есть переключатель языка', false !== strpos( $html, 'sf_fs_locale' ) );
}

unset( $_GET['tab'] );

$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=' . SF_FS_Admin::PAGE_SUBMISSIONS;

ob_start();
SF_FS_Submissions_Page::render();
$html = ob_get_clean();

sf_check( 'страница заявок отрисована', strlen( $html ) > 500, strlen( $html ) );
sf_check( 'на ней есть настройка колонок', false !== strpos( $html, 'column_order' ) );

$columns = array_column( SF_FS_Columns::visible(), 'key' );
sf_check( 'постоянные колонки показываются', in_array( '_id', $columns, true ) && in_array( '_delete', $columns, true ), implode( ',', $columns ) );

/* ---------------------------------------------------------------------- */
sf_group( 'Скрипт на сайте' );
/* ---------------------------------------------------------------------- */

$config = SF_FS_Frontend::instance()->config();

sf_check( 'адрес обработчика задан', false !== strpos( (string) $config['ajaxUrl'], 'admin-ajax.php' ) );
sf_check( 'ключ формы выдан и он читается обратно', SF_FS_Interceptor::read_ticket( $config['ticket'] ) > 0 );
sf_check( 'селектор ищет оба атрибута', false !== strpos( $config['selector'], 'sf-form-sender-interceptor' ) && false !== strpos( $config['selector'], 'data-sf-form-sender' ) );
sf_check( 'тексты уведомлений уехали в скрипт', '' !== (string) $config['texts']['error'] );

foreach ( array( 'assets/js/front.js', 'assets/css/front.css', 'assets/js/admin.js', 'assets/css/admin.css' ) as $asset ) {
	sf_check( 'файл ' . $asset . ' на месте', file_exists( SF_FS_DIR . $asset ) );
}

/* ---------------------------------------------------------------------- */
sf_group( 'Словари' );
/* ---------------------------------------------------------------------- */

$known = array(
	'en_US' => 'Settings',
	'de_DE' => 'Einstellungen',
	'es_ES' => 'Ajustes',
	'fr_FR' => 'Réglages',
	'it_IT' => 'Impostazioni',
	'pt_BR' => 'Configurações',
	'zh_CN' => '设置',
);

foreach ( $known as $locale => $expected_word ) {
	sf_check( 'файл словаря ' . $locale . ' на месте', file_exists( SF_FS_DIR . 'languages/sf-form-sender-' . $locale . '.mo' ) );
	sf_check(
		'словарь ' . $locale . ' грузится и переводит',
		SF_FS_I18n::translate_in( 'Настройки', $locale ) === $expected_word,
		SF_FS_I18n::translate_in( 'Настройки', $locale )
	);
}

sf_check( 'русскому словарь не нужен — это язык исходников', 'Настройки' === SF_FS_I18n::translate_in( 'Настройки', 'ru_RU' ) );

/* ---------------------------------------------------------------------- */

echo "\n" . str_repeat( '-', 60 ) . "\n";
echo 'Пройдено: ' . $passed . "\n";

if ( $failed ) {
	echo 'Провалено: ' . count( $failed ) . "\n";
	foreach ( $failed as $title ) {
		echo '  · ' . $title . "\n";
	}
	exit( 1 );
}

echo "Дымовая проверка пройдена.\n";
