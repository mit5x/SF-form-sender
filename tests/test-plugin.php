<?php
/**
 * Автотесты плагина SF Form sender.
 *
 * Запуск из каталога plugins:
 *   php tests/test-plugin.php
 *
 * Проверяется то, что нельзя увидеть глазами за один проход по админке:
 * подпись ключа формы, ловушки для роботов, разбор полей и зарезервированные
 * имена, правила для файлов, накопление рекламных меток, чистка настроек,
 * порядок колонок, письмо и полный путь заявки от POST до записи в базе.
 *
 * @package SF_Form_Sender
 */

require __DIR__ . '/wp-stubs.php';

/* ---------------------------------------------------------------------- */
/* Крошечный набор проверок                                                */
/* ---------------------------------------------------------------------- */

$GLOBALS['sf_passed'] = 0;
$GLOBALS['sf_failed'] = array();
$GLOBALS['sf_group']  = '';

function group( $title ) {
	$GLOBALS['sf_group'] = $title;
	echo "\n" . $title . "\n";
}

function check( $title, $condition, $got = null ) {
	if ( $condition ) {
		++$GLOBALS['sf_passed'];
		echo "  ok   " . $title . "\n";
		return;
	}
	$GLOBALS['sf_failed'][] = $GLOBALS['sf_group'] . ' → ' . $title;
	echo "  FAIL " . $title;
	if ( null !== $got ) {
		echo ' | получено: ' . ( is_scalar( $got ) ? (string) $got : var_export( $got, true ) );
	}
	echo "\n";
}

function same( $title, $expected, $actual ) {
	check( $title, $expected === $actual, $actual );
}

/**
 * Начальное состояние настроек перед каждой группой тестов.
 *
 * @param array<string,mixed> $overrides Что поменять.
 * @return void
 */
function settings( $overrides = array() ) {
	SF_FS_Settings::flush_cache();
	update_option( SF_FS_Settings::OPTION, array_merge( SF_FS_Settings::defaults(), $overrides ) );
	SF_FS_Settings::flush_cache();
}

SF_FS_Notices::flush_cache();
update_option( SF_FS_Notices::OPTION, array() );
settings();

/* ====================================================================== */
group( 'Ключ формы' );
/* ====================================================================== */

$ticket = SF_FS_Interceptor::make_ticket();
check( 'свежий ключ принимается', SF_FS_Interceptor::read_ticket( $ticket ) > 0 );

$old = SF_FS_Interceptor::make_ticket( time() - 3600 );
same( 'ключ часовой давности читается', time() - 3600, SF_FS_Interceptor::read_ticket( $old ) );

$stale = SF_FS_Interceptor::make_ticket( time() - DAY_IN_SECONDS - 60 );
same( 'ключ старше суток отбрасывается', 0, SF_FS_Interceptor::read_ticket( $stale ) );

$forged = ( time() - 10 ) . '.' . str_repeat( 'a', 64 );
same( 'подделанная подпись отбрасывается', 0, SF_FS_Interceptor::read_ticket( $forged ) );

$moved = explode( '.', $ticket );
same( 'подмена времени в ключе отбрасывается', 0, SF_FS_Interceptor::read_ticket( ( (int) $moved[0] - 500 ) . '.' . $moved[1] ) );
same( 'мусор вместо ключа отбрасывается', 0, SF_FS_Interceptor::read_ticket( 'ерунда' ) );
same( 'ключ из будущего отбрасывается', 0, SF_FS_Interceptor::read_ticket( SF_FS_Interceptor::make_ticket( time() + 3600 ) ) );

/* ====================================================================== */
group( 'Имена полей и зарезервированные имена' );
/* ====================================================================== */

same( 'скобки массива сохраняются', 'files[]', SF_FS_Db::sanitize_key( 'files[]' ) );
same( 'буквы любого алфавита сохраняются, пробел заменяется', 'ваше_имя', SF_FS_Db::sanitize_key( 'ваше имя' ) );
same( 'пустое имя получает запасное', 'field', SF_FS_Db::sanitize_key( '' ) );
same( 'опасные символы вычищаются', 'a_b_c', SF_FS_Db::sanitize_key( 'a<b>c' ) );
check( 'длинное имя обрезается', mb_strlen( SF_FS_Db::sanitize_key( str_repeat( 'x', 300 ) ) ) === 191 );

/* ====================================================================== */
group( 'Рекламные метки' );
/* ====================================================================== */

$cookie = rawurlencode( wp_json_encode( array( 'utm_source' => array( 'google', 'yandex' ), 'my_var' => array( 'test' ) ) ) );
$params = SF_FS_Tracking::params( $cookie );

same( 'два значения одного параметра', array( 'google', 'yandex' ), $params['utm_source'] );
same( 'блок для письма', "utm_source: google, yandex\nmy_var: test", SF_FS_Tracking::as_text( $params ) );
same( 'поля получают приставку', 'google, yandex', SF_FS_Tracking::as_fields( $params )['sf_get_utm_source'] );
same( 'подпись поля метки', 'GET: my_var', SF_FS_Tracking::labels( $params )['sf_get_my_var'] );
same( 'битая cookie не роняет разбор', array(), SF_FS_Tracking::params( 'не json' ) );
same( 'пустая cookie', array(), SF_FS_Tracking::params( '' ) );

$dirty = rawurlencode( wp_json_encode( array( 'плохое имя!' => array( 'v' ), 'ok' => array( '', '  x  ' ) ) ) );
$clean = SF_FS_Tracking::params( $dirty );
check( 'имя без латиницы отбрасывается', ! isset( $clean['плохое имя!'] ) );
same( 'пустые значения выбрасываются, лишние пробелы снимаются', array( 'x' ), $clean['ok'] );

$many = array();
for ( $i = 0; $i < 60; $i++ ) {
	$many[ 'p' . $i ] = array( 'v' );
}
check( 'число параметров ограничено', count( SF_FS_Tracking::params( rawurlencode( wp_json_encode( $many ) ) ) ) <= SF_FS_Tracking::MAX_PARAMS );

/* ====================================================================== */
group( 'Файлы' );
/* ====================================================================== */

$allowed = SF_FS_Settings::allowed_extensions();
check( 'разрешённые расширения разобраны', in_array( 'pdf', $allowed, true ) && in_array( 'docx', $allowed, true ) );

settings( array( 'allowed_ext' => 'pdf, php ,JPG,.png,exe' ) );
$allowed = SF_FS_Settings::allowed_extensions();
same( 'исполняемые расширения не попадают в список даже из настроек', array( 'pdf', 'jpg', 'png' ), $allowed );

check( 'обычный файл проходит', SF_FS_Uploads::extension_allowed( 'scan.pdf', $allowed ) );
check( 'регистр не мешает', SF_FS_Uploads::extension_allowed( 'SCAN.PDF', $allowed ) );
check( 'php не проходит', ! SF_FS_Uploads::extension_allowed( 'shell.php', $allowed ) );
check( 'двойное расширение не проходит', ! SF_FS_Uploads::extension_allowed( 'shell.php.jpg', $allowed ) );
check( 'файл без расширения не проходит', ! SF_FS_Uploads::extension_allowed( 'noext', $allowed ) );
check( 'неразрешённое расширение не проходит', ! SF_FS_Uploads::extension_allowed( 'archive.rar', $allowed ) );
check( 'скрытый файл не проходит', ! SF_FS_Uploads::extension_allowed( '.htaccess', $allowed ) );

settings( array( 'translit_files' => 1 ) );
same( 'транслитерация имени', 'dogovor-1.pdf', SF_FS_Uploads::clean_name( 'Договор 1.pdf' ) );

/*
 * Имя файла: настройка плагина, и только она.
 *
 * На сайте, где стоит Cyr-To-Lat или подобный плагин, sanitize_file_name()
 * переводит имя в латиницу. Пока имя чистилось ею, «одинаковое название
 * (2).jpg» превращалось в «odinakovoe-nazvanie.jpg» при выключенном
 * переключателе — то самое, на что и жаловались.
 */
settings( array( 'translit_files' => 0 ) );
$GLOBALS['sf_sanitize_calls'] = 0;

same( 'кириллица в имени сохраняется', 'Договор.pdf', SF_FS_Uploads::clean_name( 'Договор.pdf' ) );
same( 'пробелы и скобки сохраняются', 'одинаковое название (2).jpg', SF_FS_Uploads::clean_name( 'одинаковое название (2).jpg' ) );
same( 'чистка не зовёт sanitize_file_name, которую подменяют другие плагины', 0, $GLOBALS['sf_sanitize_calls'] );

same( 'путь в имени отбрасывается', 'passwd', SF_FS_Uploads::clean_name( '../../etc/passwd' ) );
same( 'скрытым файл не станет', 'htaccess', SF_FS_Uploads::clean_name( '.htaccess' ) );
same( 'запрещённые в Windows знаки убираются', 'отчёт.pdf', SF_FS_Uploads::clean_name( 'от:чё*т?.pdf' ) );
same( 'повторные пробелы схлопываются', 'два слова.pdf', SF_FS_Uploads::clean_name( '  два   слова .pdf' ) );
same( 'пустое имя получает запасное', 'file', SF_FS_Uploads::clean_name( '   ' ) );
check( 'длинное имя обрезается, а расширение остаётся', '.pdf' === substr( SF_FS_Uploads::clean_name( str_repeat( 'я', 400 ) . '.pdf' ), -4 ) );

$files = SF_FS_Uploads::flatten(
	array(
		'files' => array(
			'name'     => array( 'a.pdf', '' ),
			'type'     => array( 'application/pdf', '' ),
			'tmp_name' => array( '/tmp/a', '' ),
			'error'    => array( UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE ),
			'size'     => array( 10, 0 ),
		),
		'scan'  => array(
			'name'     => 'b.png',
			'type'     => 'image/png',
			'tmp_name' => '/tmp/b',
			'error'    => UPLOAD_ERR_OK,
			'size'     => 20,
		),
	)
);
same( 'пустые поля выбора файла не считаются файлами', 2, count( $files ) );
same( 'имя поля сохраняется', 'files', $files[0]['field'] );

// Настоящие файлы во временном каталоге — для проверки размера и типа.
$tmp_ok = tempnam( sys_get_temp_dir(), 'sf' ) . '.pdf';
file_put_contents( $tmp_ok, '%PDF-1.4' . "\n" . str_repeat( 'x', 1024 ) );

/*
 * Картинки делаем настоящими: разбор содержимого — единственное место, где
 * подделка ничего не докажет.
 */
$img       = imagecreatetruecolor( 8, 8 );
$real_jpeg = SF_TEST_SANDBOX . '/real.jpg';
$real_png  = SF_TEST_SANDBOX . '/real.png';
$fake_jpeg = SF_TEST_SANDBOX . '/fake.jpg';   // PNG, переименованный в .jpg.
$broken    = SF_TEST_SANDBOX . '/broken.jpg'; // Не картинка вовсе.

imagejpeg( $img, $real_jpeg );
imagepng( $img, $real_png );
copy( $real_png, $fake_jpeg );
file_put_contents( $broken, 'это просто текст, а не картинка' );

/**
 * Один файл на входе проверки.
 *
 * @param string $name Имя, каким его прислал браузер.
 * @param string $path Путь к настоящему файлу.
 * @param int    $size Размер.
 * @return array<string,mixed>
 */
function file_item( $name, $path, $size = 100 ) {
	return array(
		'field'    => 'files',
		'name'     => $name,
		'type'     => '',
		'tmp_name' => $path,
		'error'    => UPLOAD_ERR_OK,
		'size'     => $size,
	);
}

settings( array( 'max_files_size' => 10 ) );

// То самое имя из отчёта: пробелы, скобки, кириллица. Содержимое — настоящий
// JPEG, и отказывать тут не за что.
$check = SF_FS_Uploads::validate( array( file_item( 'одинаковое название (2).jpg', $real_jpeg ) ) );
check( 'пробелы, скобки и кириллица в имени не мешают', $check['ok'], $check['error'] );
same( 'имя такого файла не меняется', 'одинаковое название (2).jpg', $check['files'][0]['name'] );

// Картинку пересохранили в другом формате, не переименовав файл. Отказывать
// незачем: настоящий тип тоже разрешён — принимаем под верным именем.
settings( array( 'max_files_size' => 10, 'allowed_ext' => 'jpg,png,pdf' ) );
$check = SF_FS_Uploads::validate( array( file_item( 'снимок.jpg', $fake_jpeg ) ) );
check( 'содержимое другого разрешённого типа принимается', $check['ok'], $check['error'] );
same( 'имя исправлено на верное расширение', 'снимок.png', $check['files'][0]['name'] );

// А если настоящий тип не разрешён — отказ, и по делу.
settings( array( 'max_files_size' => 10, 'allowed_ext' => 'jpg,pdf' ) );
$check = SF_FS_Uploads::validate( array( file_item( 'снимок.jpg', $fake_jpeg ) ) );
check( 'содержимое неразрешённого типа не проходит', ! $check['ok'] );

// Содержимое опознать не удалось. Расширение при этом разрешено, и винить
// его в тексте ошибки было бы неправдой — именно на этом и споткнулись.
settings( array( 'max_files_size' => 10 ) );
$check = SF_FS_Uploads::validate( array( file_item( 'битый.jpg', $broken ) ) );
check( 'неопознанное содержимое не проходит', ! $check['ok'] );
check( 'ошибка говорит про содержимое, а не про расширение', false === mb_strpos( $check['error'], 'Разрешены' ), $check['error'] );
check( 'в тексте названо расширение файла', false !== mb_strpos( $check['error'], 'jpg' ), $check['error'] );

// Список типов берём свой, а не медиатеки: чужой фильтр upload_mimes не
// должен решать, что принимать в форме.
$map = SF_FS_Uploads::mime_map( array( 'jpg', 'pdf' ) );
check( 'в таблице типов только наши расширения', array_keys( $map ) === array( 'jpg', 'pdf' ), implode( ',', array_keys( $map ) ) );

settings( array( 'max_files_size' => 1 ) );
$check = SF_FS_Uploads::validate(
	array(
		array( 'field' => 'f', 'name' => 'doc.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp_ok, 'error' => UPLOAD_ERR_OK, 'size' => 500 ),
	)
);
check( 'маленький разрешённый файл проходит', $check['ok'] );

$check = SF_FS_Uploads::validate(
	array(
		array( 'field' => 'f', 'name' => 'doc.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp_ok, 'error' => UPLOAD_ERR_OK, 'size' => 900 * 1024 ),
		array( 'field' => 'f', 'name' => 'doc2.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp_ok, 'error' => UPLOAD_ERR_OK, 'size' => 900 * 1024 ),
	)
);
check( 'сумма размеров проверяется, а не размер каждого', ! $check['ok'] );
check( 'текст ошибки про размер', false !== mb_strpos( $check['error'], '1' ) );

$check = SF_FS_Uploads::validate(
	array( array( 'field' => 'f', 'name' => 'x.exe', 'type' => 'application/octet-stream', 'tmp_name' => $tmp_ok, 'error' => UPLOAD_ERR_OK, 'size' => 10 ) )
);
check( 'исполняемый файл отклоняется', ! $check['ok'] );

$check = SF_FS_Uploads::validate(
	array( array( 'field' => 'f', 'name' => 'x.pdf', 'type' => '', 'tmp_name' => $tmp_ok, 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 10 ) )
);
check( 'ошибка загрузки отдаётся своим уведомлением', ! $check['ok'] );

unlink( $tmp_ok );

/* ====================================================================== */
group( 'Настройки' );
/* ====================================================================== */

settings();
SF_FS_Settings::save_tab( 'main', array( 'max_files_size' => '9999', 'mail_method' => 'выдумка', 'mail_to' => ' me@example.com ' ) );
same( 'число сверх максимума прижимается', 512, SF_FS_Settings::get( 'max_files_size' ) );
same( 'неизвестный вариант select заменяется значением по умолчанию', 'phpmail', SF_FS_Settings::get( 'mail_method' ) );
same( 'текст очищается от пробелов', 'me@example.com', SF_FS_Settings::get( 'mail_to' ) );
same( 'снятая галочка сохраняется нулём', 0, SF_FS_Settings::get( 'track_get' ) );

SF_FS_Settings::save_tab( 'smtp', array( 'smtp_port' => '587' ) );
same( 'сохранение чужой вкладки не трогает наши поля', 'me@example.com', SF_FS_Settings::get( 'mail_to' ) );
same( 'число приводится к целому', 587, SF_FS_Settings::get( 'smtp_port' ) );

SF_FS_Settings::save_tab( 'antispam', array( 'yandex_captcha' => '1', 'google_captcha' => '1' ) );
check( 'две капчи разом не включаются', ! ( SF_FS_Settings::on( 'yandex_captcha' ) && SF_FS_Settings::on( 'google_captcha' ) ) );

settings( array( 'mail_to' => 'a@example.com, мусор, b@example.com' ) );
same( 'получатели разбираются, мусор отбрасывается', array( 'a@example.com', 'b@example.com' ), SF_FS_Settings::recipients() );

settings( array( 'mail_to' => '' ) );
same( 'без получателей письмо идёт администратору', array( 'admin@example.com' ), SF_FS_Settings::recipients() );

/* ====================================================================== */
group( 'Тексты уведомлений' );
/* ====================================================================== */

SF_FS_Notices::flush_cache();
update_option( SF_FS_Notices::OPTION, array() );

same(
	'подстановки в тексте',
	'Общий размер файлов больше допустимых 7 МБ.',
	SF_FS_Notices::get( 'error_file_size', array( 'limit' => 7 ) )
);
check( 'неизвестный ключ отдаёт общую ошибку', false !== mb_strpos( SF_FS_Notices::get( 'нет-такого' ), 'Не удалось' ) );

SF_FS_Notices::save( array( 'success' => "  <b>Готово!</b>\nСпасибо  " ) );
same( 'разметка из уведомления убирается', "Готово!\nСпасибо", SF_FS_Notices::get( 'success' ) );
check( 'незаполненное уведомление берёт значение по умолчанию', '' !== SF_FS_Notices::get( 'error_captcha' ) );

$german = SF_FS_Notices::defaults_in( 'de_DE' );
check( 'тексты по умолчанию переводятся на язык сайта', false !== mb_strpos( $german['success'], 'Danke' ) || false !== mb_strpos( $german['success'], 'Vielen' ) );

SF_FS_Notices::flush_cache();
update_option( SF_FS_Notices::OPTION, array() );

/* ====================================================================== */
group( 'Язык интерфейса' );
/* ====================================================================== */

same( 'известный язык', 'de_DE', SF_FS_I18n::normalize( 'de_DE' ) );
same( 'короткая запись', 'fr_FR', SF_FS_I18n::normalize( 'fr' ) );
same( 'дефис вместо подчёркивания', 'pt_BR', SF_FS_I18n::normalize( 'pt-BR' ) );
same( 'вариант языка сводится к известному', 'ru_RU', SF_FS_I18n::normalize( 'ru_UA' ) );
same( 'незнакомый язык — английский', 'en_US', SF_FS_I18n::normalize( 'xx_YY' ) );
same( 'русский не требует словаря', 'Настройки', SF_FS_I18n::translate_in( 'Настройки', 'ru_RU' ) );
same( 'перевод на английский', 'Settings', SF_FS_I18n::translate_in( 'Настройки', 'en_US' ) );
same( 'перевод на китайский', '设置', SF_FS_I18n::translate_in( 'Настройки', 'zh_CN' ) );

update_option( 'WPLANG', 'it_IT' );
same( 'язык по умолчанию берётся из WPLANG', 'it_IT', SF_FS_I18n::admin_locale() );
SF_FS_I18n::set_admin_locale( 'fr_FR' );
same( 'выбранный язык побеждает язык сайта', 'fr_FR', SF_FS_I18n::admin_locale() );
same( 'язык сайта при этом не меняется', 'it_IT', SF_FS_I18n::site_locale() );
update_option( 'WPLANG', 'ru_RU' );
delete_option( SF_FS_I18n::OPTION );

/* ====================================================================== */
group( 'Капча' );
/* ====================================================================== */

settings();
same( 'без ключей капчи нет', 'none', SF_FS_Captcha::provider() );
check( 'без капчи проверка не мешает', SF_FS_Captcha::verify( '', '1.2.3.4' ) );

settings( array( 'yandex_captcha' => 1, 'yandex_client_key' => 'ключ', 'yandex_server_key' => 'секрет' ) );
same( 'включённая капча Яндекса', 'yandex', SF_FS_Captcha::provider() );
check( 'пустой ответ не проходит', ! SF_FS_Captcha::verify( '', '1.2.3.4' ) );

$GLOBALS['sf_http_response'] = array( 'body' => '{"status":"ok"}' );
check( 'ответ ok проходит', SF_FS_Captcha::verify( 'token', '1.2.3.4' ) );

$GLOBALS['sf_http_response'] = array( 'body' => '{"status":"failed"}' );
check( 'ответ failed не проходит', ! SF_FS_Captcha::verify( 'token', '1.2.3.4' ) );

$GLOBALS['sf_http_response'] = new WP_Error( 'http', 'нет связи' );
check( 'недоступность службы не разворачивает посетителя', SF_FS_Captcha::verify( 'token', '1.2.3.4' ) );

settings( array( 'google_captcha' => 1, 'google_site_key' => 'k', 'google_secret_key' => 's', 'google_version' => 'v3', 'google_score' => 0.5 ) );
$GLOBALS['sf_http_response'] = array( 'body' => '{"success":true,"score":0.9}' );
check( 'высокая оценка v3 проходит', SF_FS_Captcha::verify( 'token', '1.2.3.4' ) );
$GLOBALS['sf_http_response'] = array( 'body' => '{"success":true,"score":0.1}' );
check( 'низкая оценка v3 не проходит', ! SF_FS_Captcha::verify( 'token', '1.2.3.4' ) );

$config = SF_FS_Captcha::front_config();
check( 'адрес скрипта v3 содержит ключ сайта', false !== strpos( $config['script'], 'render=k' ) );

$GLOBALS['sf_http_response'] = array( 'body' => '{"status":"ok"}' );
settings();

/* ====================================================================== */
group( 'Письмо' );
/* ====================================================================== */

$payload = array(
	'fields'        => array( 'name' => 'Иван', 'email' => 'ivan@example.com', 'comment' => "первая\nвторая" ),
	'labels'        => array( 'name' => 'Ваше имя' ),
	'files'         => array( array( 'orig_name' => 'скан.pdf' ) ),
	'attachments'   => array(),
	'ip'            => '10.0.0.1',
	'url'           => 'https://example.com/page/',
	'date'          => '2026-08-31 12:00:00',
	'form_id'       => 'contacts',
	'page_title'    => 'Контакты',
	'tracking'      => array(),
	'tracking_text' => "utm_source: google",
);

settings( array( 'mail_subject' => 'Заявка с {site}: {form} — {page}' ) );
same( 'подстановки в теме письма', 'Заявка с Тестовый сайт: contacts — Контакты', SF_FS_Mailer::subject( $payload ) );

same( 'адрес для ответа берётся из формы', 'ivan@example.com', SF_FS_Mailer::reply_to( $payload ) );
same( 'без адреса в форме ответ не задаётся', '', SF_FS_Mailer::reply_to( array( 'fields' => array( 'name' => 'Иван' ) ) ) );

$body = SF_FS_Mailer::body( $payload );
check( 'подпись поля вместо имени', false !== mb_strpos( $body, 'Ваше имя' ) );
check( 'поле без подписи выводится по имени', false !== mb_strpos( $body, 'comment' ) );
check( 'перенос строки превращается в разметку', false !== mb_strpos( $body, "первая<br" ) );
check( 'список файлов в письме', false !== mb_strpos( $body, 'скан.pdf' ) );
check( 'блок меток в письме', false !== mb_strpos( $body, 'utm_source: google' ) );
check( 'служебные данные в подвале письма', false !== mb_strpos( $body, '10.0.0.1' ) );

/*
 * Поля идут строками, а не таблицей: длинное название поля растягивало
 * таблицу шире окна почтовой программы и уводило значения за край.
 */
check( 'поля письма не в таблице', false === mb_strpos( $body, '<table' ) && false === mb_strpos( $body, '<tr' ) );
check( 'название поля с двоеточием отдельной строкой', false !== mb_strpos( $body, 'Ваше имя:</div>' ) );
check( 'значение идёт следующим блоком', false !== mb_strpos( $body, 'Ваше имя:</div><div' ) );

/*
 * Текстовая версия собирается из этой же разметки: если перенос брать
 * только от </tr>, всё письмо слипается в одну строку.
 */
$plain = SF_FS_Mailer::plain( $body );
check( 'в текстовой версии нет разметки', false === mb_strpos( $plain, '<' ) );
check( 'каждое поле в текстовой версии на своей строке', false !== mb_strpos( $plain, "Ваше имя:\nИван" ) );
// nl2br оставляет за <br /> настоящий перевод строки: если не убрать его
// вместе с тегом, на месте одного переноса выйдет два.
check( 'перенос внутри значения не удвоился', false !== mb_strpos( $plain, "первая\nвторая" ) );
// После блока меток идёт подвал письма — без переноса они слипаются.
check( 'блок меток отделён от подвала', false !== mb_strpos( $plain, "utm_source: google\nСтраница" ) );

/** Подставной PHPMailer: интересно, что плагин в нём выставит. */
class SF_Test_Mailer {
	public $Host = '', $Port = 0, $Timeout = 0, $SMTPSecure = 'x', $SMTPAutoTLS = null;
	public $SMTPAuth = false, $Username = '', $Password = '', $Sender = '', $Hostname = '';
	public $From = 'wp@example.com', $FromName = 'WordPress', $Body = '', $AltBody = '';
	public $DKIM_domain = '', $DKIM_selector = '', $DKIM_private_string = '', $DKIM_identity = '';
	public $mode = '';
	public $replies = array();

	public function isSMTP() { $this->mode = 'smtp'; }
	public function isMail() { $this->mode = 'mail'; }
	public function setFrom( $address, $name = '', $auto = true ) { $this->From = $address; $this->FromName = $name; }
	public function clearReplyTos() { $this->replies = array(); }
	public function addReplyTo( $address ) { $this->replies[] = $address; }
}

settings(
	array(
		'smtp_host'    => 'smtp.example.com',
		'smtp_port'    => 465,
		'smtp_user'    => 'robot@example.com',
		'smtp_pass'    => 'секрет',
		'smtp_secure'  => 'ssl',
		'smtp_timeout' => 7,
	)
);
$mailer = new SF_Test_Mailer();
SF_FS_Mailer::apply_smtp( $mailer );
same( 'выбран режим SMTP', 'smtp', $mailer->mode );
same( 'хост', 'smtp.example.com', $mailer->Host );
same( 'таймаут', 7, $mailer->Timeout );
same( 'шифрование', 'ssl', $mailer->SMTPSecure );
check( 'авторизация включена', $mailer->SMTPAuth );
same( 'отправитель — тот, под кем авторизовались', 'robot@example.com', $mailer->From );

settings( array( 'smtp_secure' => 'none', 'smtp_host' => 'h', 'smtp_user' => '' ) );
$mailer = new SF_Test_Mailer();
SF_FS_Mailer::apply_smtp( $mailer );
same( 'без шифрования поле пустое', '', $mailer->SMTPSecure );
check( 'без имени пользователя авторизация не включается', ! $mailer->SMTPAuth );

settings(
	array(
		'phpmail_from'      => 'sender@example.com',
		'phpmail_from_name' => 'Сайт',
		'phpmail_sender'    => 'bounce@example.com',
		'phpmail_hostname'  => 'example.com',
		'phpmail_reply_to'  => 'reply@example.com',
	)
);
$mailer = new SF_Test_Mailer();
SF_FS_Mailer::apply_phpmail( $mailer );
same( 'выбран режим phpmail', 'mail', $mailer->mode );
same( 'адрес отправителя', 'sender@example.com', $mailer->From );
same( 'имя отправителя', 'Сайт', $mailer->FromName );
same( 'конверт', 'bounce@example.com', $mailer->Sender );
same( 'имя в HELO', 'example.com', $mailer->Hostname );
same( 'адрес для ответа', array( 'reply@example.com' ), $mailer->replies );

settings( array( 'dkim_domain' => 'example.com', 'dkim_selector' => 'mail', 'dkim_private_string' => 'КЛЮЧ' ) );
$mailer = new SF_Test_Mailer();
SF_FS_Mailer::apply_dkim( $mailer );
same( 'домен подписи', 'example.com', $mailer->DKIM_domain );
same( 'ключ подписи', 'КЛЮЧ', $mailer->DKIM_private_string );

settings( array( 'dkim_domain' => 'example.com', 'dkim_private_string' => '' ) );
$mailer = new SF_Test_Mailer();
SF_FS_Mailer::apply_dkim( $mailer );
same( 'без ключа подпись не ставится', '', $mailer->DKIM_domain );

/* ====================================================================== */
group( 'База данных' );
/* ====================================================================== */

settings();
SF_FS_Db::install();
check( 'таблицы созданы', SF_FS_Db::tables_ready() );

$id = SF_FS_Db::save_submission(
	array(
		'fields'     => array( 'name' => 'Иван', 'phone' => '+7 900 000-00-00' ),
		'labels'     => array( 'name' => 'Ваше имя' ),
		'ip'         => '10.0.0.1',
		'url'        => 'https://example.com/a/',
		'date'       => '2026-08-31 10:00:00',
		'form_id'    => 'contacts',
		'user_agent' => 'Тест',
	)
);
check( 'заявка сохранена', $id > 0 );

$second = SF_FS_Db::save_submission(
	array(
		'fields'     => array( 'name' => 'Пётр', 'nombre' => 'Pedro' ),
		'labels'     => array(),
		'ip'         => '10.0.0.2',
		'url'        => 'https://example.com/b/',
		'date'       => '2026-08-31 11:00:00',
		'form_id'    => 'calc',
		'user_agent' => '',
	)
);

same( 'обе заявки на месте', 2, SF_FS_Db::count() );

$fields = SF_FS_Db::fields();
same( 'подпись поля запомнилась', 'Ваше имя', $fields['name']['label'] );
same( 'поле без подписи названо своим именем', 'nombre', $fields['nombre']['label'] );
same( 'одинаковое поле двух форм заведено один раз', 3, count( $fields ) );

$row = SF_FS_Db::submission( $id );
same( 'значения читаются', 'Иван', $row['data']['name'] );
same( 'ip заявки', '10.0.0.1', $row['ip'] );
same( 'дата заявки', '2026-08-31 10:00:00', $row['created_at'] );

$page = SF_FS_Db::submissions( 1, 1 );
same( 'на странице одна заявка', 1, count( $page ) );
same( 'сначала свежие', $second, $page[0]['id'] );

$page = SF_FS_Db::submissions( 1, 2 );
same( 'вторая страница', $id, $page[0]['id'] );

SF_FS_Db::add_file( $id, array( 'orig_name' => 'скан.pdf', 'stored_name' => 'skan.pdf', 'rel_path' => $id . '/skan.pdf', 'size' => 100, 'mime' => 'application/pdf' ) );
$row = SF_FS_Db::submission( $id );
same( 'счётчик файлов вырос', 1, (int) $row['files_count'] );
same( 'файл записан', 'скан.pdf', $row['files'][0]['orig_name'] );

SF_FS_Db::set_field_label( $fields['nombre']['id'], 'Имя (испанский)' );
$fields = SF_FS_Db::fields();
same( 'подпись переименована', 'Имя (испанский)', $fields['nombre']['label'] );

same( 'удаление одной заявки', 1, SF_FS_Db::delete( array( $second ) ) );
same( 'осталась одна', 1, SF_FS_Db::count() );
check( 'удалённой заявки нет', null === SF_FS_Db::submission( $second ) );
same( 'удаление пустого списка ничего не ломает', 0, SF_FS_Db::delete( array() ) );

same( 'очистка списка', 1, SF_FS_Db::delete_all() );
same( 'заявок не осталось', 0, SF_FS_Db::count() );
check( 'словарь полей после очистки цел', count( SF_FS_Db::fields() ) === 3 );

/* ====================================================================== */
group( 'Колонки списка заявок' );
/* ====================================================================== */

delete_option( SF_FS_Columns::OPTION );
$columns = SF_FS_Columns::all();
$keys    = array_column( $columns, 'key' );

check( 'постоянные колонки на месте', in_array( '_id', $keys, true ) && in_array( '_view', $keys, true ) && in_array( '_delete', $keys, true ) );
check( 'поля форм добавлены', in_array( 'name', $keys, true ) );

$visible = array_column( SF_FS_Columns::visible(), 'key' );
check( 'по умолчанию видны только постоянные колонки', in_array( '_id', $visible, true ) && ! in_array( 'name', $visible, true ) );
check( 'страница и форма по умолчанию скрыты', ! in_array( '_url', $visible, true ) && ! in_array( '_form', $visible, true ) );

SF_FS_Columns::save( array( 'name', '_id', '_date' ), array( 'name' ) );
$columns = SF_FS_Columns::all();
$keys    = array_column( $columns, 'key' );
same( 'сохранённый порядок соблюдён', 'name', $keys[0] );
check( 'новые колонки дописаны в конец', in_array( '_delete', $keys, true ) );

$visible = array_column( SF_FS_Columns::visible(), 'key' );
check( 'выбранное поле показывается', in_array( 'name', $visible, true ) );
check( 'постоянную колонку нельзя спрятать', in_array( '_delete', $visible, true ) );
check( 'невыбранное поле скрыто', ! in_array( 'nombre', $visible, true ) );

/*
 * Ширина колонки: её тянут мышью в таблице, а порядок и видимость правят
 * формой рядом. Одно не должно затирать другое.
 */
$by_key = array_column( SF_FS_Columns::all(), 'width', 'key' );
same( 'у постоянной колонки своя ширина по умолчанию', 80, $by_key['_id'] );
same( 'у поля формы общая ширина по умолчанию', SF_FS_Columns::DEFAULT_WIDTH, $by_key['name'] );

SF_FS_Columns::save_widths( array( 'name' => 320, '_id' => 0 ) );
$by_key = array_column( SF_FS_Columns::all(), 'width', 'key' );
same( 'сохранённая ширина применена', 320, $by_key['name'] );
same( 'нулевая ширина возвращает значение по умолчанию', 80, $by_key['_id'] );

$columns = array_column( SF_FS_Columns::all(), null, 'key' );
same( 'ширина по умолчанию сохранена отдельно от текущей', 180, $columns['name']['default_width'] );

SF_FS_Columns::save_widths( array( 'name' => 5, '_date' => 99999 ) );
$by_key = array_column( SF_FS_Columns::all(), 'width', 'key' );
same( 'слишком узкую колонку подтянули к пределу', SF_FS_Columns::MIN_WIDTH, $by_key['name'] );
same( 'слишком широкую колонку подрезали до предела', SF_FS_Columns::MAX_WIDTH, $by_key['_date'] );

SF_FS_Columns::save_widths( array( 'name' => 260 ) );
SF_FS_Columns::save( array( '_id', 'name' ), array( 'name' ) );
$by_key = array_column( SF_FS_Columns::all(), 'width', 'key' );
same( 'сохранение порядка не сбрасывает ширину', 260, $by_key['name'] );
same( 'порядок при этом сохранён', '_id', array_column( SF_FS_Columns::all(), 'key' )[0] );

SF_FS_Columns::save( array( '_id', 'name' ), array( 'name' ), array( 'name' => 210 ) );
$by_key = array_column( SF_FS_Columns::all(), 'width', 'key' );
same( 'переданные ширины сохранение принимает', 210, $by_key['name'] );

delete_option( SF_FS_Columns::OPTION );

/* ====================================================================== */
group( 'Приём формы: путь заявки целиком' );
/* ====================================================================== */

/**
 * Отправка формы обработчику плагина.
 *
 * @param array<string,mixed> $post   Поля.
 * @param array<string,mixed> $files  Файлы.
 * @return array<string,mixed> Ответ плагина.
 */
function submit( $post, $files = array() ) {
	$_POST                     = $post;
	$_FILES                    = $files;
	$_SERVER['REMOTE_ADDR']    = '203.0.113.7';
	$_SERVER['HTTP_USER_AGENT'] = 'Тестовый браузер';

	try {
		SF_FS_Interceptor::instance()->handle();
	} catch ( SF_Test_Response $response ) {
		return $response->data;
	}
	return array();
}

/**
 * Обычный набор полей формы.
 *
 * @param array<string,mixed> $extra Что добавить или заменить.
 * @return array<string,mixed>
 */
function form_post( $extra = array() ) {
	return array_merge(
		array(
			'sf_fs_ticket'  => SF_FS_Interceptor::make_ticket( time() - 60 ),
			'sf_fs_form'    => 'contacts',
			'sf_fs_elapsed' => '60',
			'sf_fs_page'    => 'https://example.com/contacts/',
			'sf_fs_title'   => 'Контакты',
			'sf_fs_labels'  => wp_json_encode( array( 'name' => 'Ваше имя' ) ),
			'name'          => 'Иван',
			'email'         => 'ivan@example.com',
		),
		$extra
	);
}

settings( array( 'save_to_db' => 1, 'mail_method' => 'phpmail', 'min_seconds' => 4, 'honeypot' => 1 ) );
SF_FS_Db::delete_all();
$GLOBALS['sf_mail'] = array();

$answer = submit( form_post() );
check( 'форма принята', ! empty( $answer['success'] ) );
same( 'заявка записана', 1, SF_FS_Db::count() );
same( 'письмо ушло', 1, count( $GLOBALS['sf_mail'] ) );

$row = SF_FS_Db::submission( $answer['id'] );
same( 'значение поля', 'Иван', $row['data']['name'] );
same( 'ip определён по запросу', '203.0.113.7', $row['ip'] );
same( 'адрес страницы', 'https://example.com/contacts/', $row['page_url'] );
same( 'имя формы', 'contacts', $row['form_id'] );
check( 'служебные поля в заявку не попали', ! isset( $row['data']['sf_fs_ticket'] ) && ! isset( $row['data']['sf_fs_labels'] ) );
same( 'подпись поля пришла из формы', 'Ваше имя', SF_FS_Db::fields()['name']['label'] );

SF_FS_Db::delete_all();
$GLOBALS['sf_mail'] = array();

$answer = submit( form_post( array( 'website' => 'https://spam.example' ) ) );
check( 'приманка: посетителю показан успех', ! empty( $answer['success'] ) );
check( 'приманка: помечено как тихий отказ', ! empty( $answer['silent'] ) );
same( 'приманка: заявка не сохранена', 0, SF_FS_Db::count() );
same( 'приманка: письмо не отправлено', 0, count( $GLOBALS['sf_mail'] ) );

$answer = submit( form_post( array( 'sf_fs_elapsed' => '1' ) ) );
check( 'слишком быстрая отправка: показан успех', ! empty( $answer['success'] ) );
check( 'слишком быстрая отправка: тихий отказ', ! empty( $answer['silent'] ) );
same( 'слишком быстрая отправка: ничего не сохранено', 0, SF_FS_Db::count() );

// Робот может прислать какое угодно «время на сайте», но ключ формы выдан
// секунду назад — по меньшей из двух оценок это всё равно робот.
$answer = submit( form_post( array( 'sf_fs_ticket' => SF_FS_Interceptor::make_ticket( time() - 1 ), 'sf_fs_elapsed' => '99999' ) ) );
check( 'подделанное время на сайте не помогает', ! empty( $answer['silent'] ) );

// Обратный случай: страница отдана из кеша час назад, но человек только что
// открыл её — верим браузеру и не пускаем.
$answer = submit( form_post( array( 'sf_fs_ticket' => SF_FS_Interceptor::make_ticket( time() - 3600 ), 'sf_fs_elapsed' => '1' ) ) );
check( 'старый ключ из кеша не отменяет проверку времени', ! empty( $answer['silent'] ) );

$answer = submit( form_post( array( 'website' => array( 'спам' ) ) ) );
check( 'приманку не обойти массивом', ! empty( $answer['silent'] ) );

$answer = submit( form_post( array( 'sf_fs_ticket' => array( '1', '2' ) ) ) );
same( 'массив вместо ключа формы не роняет обработчик', 'expired', $answer['code'] );

$answer = submit( form_post( array( 'sf_fs_title' => array( 'a' ), 'sf_fs_form' => array( 'b' ), 'sf_fs_labels' => array( 'c' ) ) ) );
check( 'массивы вместо служебных полей не роняют обработчик', ! empty( $answer['success'] ) );

$answer = submit( form_post( array( 'sf_fs_ticket' => 'подделка' ) ) );
check( 'без верного ключа — отказ', empty( $answer['success'] ) );
same( 'код причины', 'expired', $answer['code'] );

$answer = submit(
	array(
		'sf_fs_ticket'  => SF_FS_Interceptor::make_ticket( time() - 60 ),
		'sf_fs_elapsed' => '60',
	)
);
same( 'пустая форма отклоняется', 'empty', $answer['code'] );

settings( array( 'honeypot' => 0, 'min_seconds' => 0, 'save_to_db' => 1, 'mail_method' => 'phpmail' ) );
SF_FS_Db::delete_all();

$answer = submit(
	form_post(
		array(
			'sf_ip_sender' => '198.51.100.4',
			'sf_url'       => 'https://example.com/своя-страница/',
			'interests'    => array( 'дизайн', 'вёрстка' ),
			'empty_field'  => '   ',
		)
	)
);
$row = SF_FS_Db::submission( $answer['id'] );
same( 'поле формы перекрывает определённый плагином ip', '198.51.100.4', $row['ip'] );
same( 'поле формы перекрывает адрес страницы', 'https://example.com/своя-страница/', $row['page_url'] );
check( 'зарезервированные поля не задваиваются в значениях', ! isset( $row['data']['sf_ip_sender'] ) );
same( 'множественное поле склеивается', 'дизайн, вёрстка', $row['data']['interests'] );
check( 'пустое поле не сохраняется', ! isset( $row['data']['empty_field'] ) );

SF_FS_Db::delete_all();
settings( array( 'honeypot' => 0, 'min_seconds' => 0, 'save_to_db' => 1, 'mail_method' => 'phpmail', 'track_get' => 1, 'save_get_to_db' => 1, 'attach_get_to_mail' => 1 ) );
$_COOKIE[ SF_FS_Tracking::COOKIE ] = rawurlencode( wp_json_encode( array( 'utm_source' => array( 'google', 'yandex' ) ) ) );
$GLOBALS['sf_mail'] = array();

$answer = submit( form_post() );
$row    = SF_FS_Db::submission( $answer['id'] );
same( 'метки сохранены в заявке', 'google, yandex', $row['data']['sf_get_utm_source'] );
check( 'метки приложены к письму', false !== mb_strpos( $GLOBALS['sf_mail'][0]['body'], 'utm_source: google, yandex' ) );

settings( array( 'honeypot' => 0, 'min_seconds' => 0, 'save_to_db' => 1, 'mail_method' => 'phpmail', 'track_get' => 1, 'save_get_to_db' => 0, 'attach_get_to_mail' => 0 ) );
SF_FS_Db::delete_all();
$GLOBALS['sf_mail'] = array();

$answer = submit( form_post() );
$row    = SF_FS_Db::submission( $answer['id'] );
check( 'выключенное сохранение меток соблюдается', ! isset( $row['data']['sf_get_utm_source'] ) );
check( 'выключенное приложение меток соблюдается', false === mb_strpos( $GLOBALS['sf_mail'][0]['body'], 'utm_source' ) );

unset( $_COOKIE[ SF_FS_Tracking::COOKIE ] );

settings( array( 'honeypot' => 0, 'min_seconds' => 0, 'save_to_db' => 0, 'mail_method' => 'phpmail' ) );
SF_FS_Db::delete_all();
$GLOBALS['sf_mail'] = array();

$answer = submit( form_post() );
check( 'без сохранения в базу форма всё равно принимается', ! empty( $answer['success'] ) );
same( 'без сохранения в базу заявок не прибавилось', 0, SF_FS_Db::count() );
same( 'письмо при этом ушло', 1, count( $GLOBALS['sf_mail'] ) );

settings( array( 'honeypot' => 0, 'min_seconds' => 0, 'save_to_db' => 1, 'mail_method' => 'phpmail' ) );
SF_FS_Db::delete_all();
$GLOBALS['sf_mail_fails'] = true;
$GLOBALS['sf_mail']       = array();

$answer = submit( form_post() );
check( 'письмо не ушло, но заявка в базе — посетителю показан успех', ! empty( $answer['success'] ) );
same( 'заявка сохранена несмотря на сбой почты', 1, SF_FS_Db::count() );

settings( array( 'honeypot' => 0, 'min_seconds' => 0, 'save_to_db' => 0, 'mail_method' => 'phpmail' ) );
$answer = submit( form_post() );
check( 'ни письма, ни записи — посетителю показана ошибка', empty( $answer['success'] ) );
same( 'код причины — почта', 'mail', $answer['code'] );
$GLOBALS['sf_mail_fails'] = false;

settings( array( 'honeypot' => 0, 'min_seconds' => 0, 'save_to_db' => 1, 'mail_method' => 'none' ) );
SF_FS_Db::delete_all();
$GLOBALS['sf_mail'] = array();

$answer = submit( form_post() );
check( 'способ «не отправлять» не считается ошибкой', ! empty( $answer['success'] ) );
same( 'письмо не отправлялось', 0, count( $GLOBALS['sf_mail'] ) );
same( 'заявка сохранена', 1, SF_FS_Db::count() );

settings( array( 'honeypot' => 0, 'min_seconds' => 0, 'save_to_db' => 1, 'mail_method' => 'none', 'yandex_captcha' => 1, 'yandex_client_key' => 'k', 'yandex_server_key' => 's' ) );
SF_FS_Db::delete_all();
$GLOBALS['sf_http_response'] = array( 'body' => '{"status":"failed"}' );

$answer = submit( form_post( array( SF_FS_Captcha::TOKEN_FIELD => 'плохой' ) ) );
same( 'непройденная капча отклоняет заявку', 'captcha', $answer['code'] );
same( 'заявка при этом не сохранена', 0, SF_FS_Db::count() );

$GLOBALS['sf_http_response'] = array( 'body' => '{"status":"ok"}' );
$answer = submit( form_post( array( SF_FS_Captcha::TOKEN_FIELD => 'хороший' ) ) );
check( 'пройденная капча пропускает заявку', ! empty( $answer['success'] ) );

$GLOBALS['sf_filters']['sf_fs_before_send'] = function ( $payload ) {
	return new WP_Error( 'нет', 'Отменено темой.' );
};
$answer = submit( form_post( array( SF_FS_Captcha::TOKEN_FIELD => 'хороший' ) ) );
same( 'фильтр темы отменяет отправку', 'Отменено темой.', $answer['message'] );
unset( $GLOBALS['sf_filters']['sf_fs_before_send'] );

/* ====================================================================== */
group( 'Вкладка «Документация»' );
/* ====================================================================== */

$markers = sf_fs_markers();

ob_start();
require SF_FS_DIR . 'includes/admin/views/docs.php';
$docs = ob_get_clean();

check( 'атрибут перехвата назван в тексте, а не только в примере', substr_count( $docs, $markers['attr'] ) >= 4 );
check( 'показан и вариант со своим именем формы', false !== mb_strpos( $docs, $markers['attr'] . '=&quot;' ) || false !== mb_strpos( $docs, $markers['attr'] . '="' ) );
check( 'назван data-атрибут для валидатора разметки', false !== mb_strpos( $docs, $markers['attr_data'] ) );
check( 'перечислены все utm-метки, а не одна', false !== mb_strpos( $docs, 'utm_medium' ) && false !== mb_strpos( $docs, 'utm_campaign' ) && false !== mb_strpos( $docs, 'utm_term' ) );
check( 'показано, во что превращается метка', false !== mb_strpos( $docs, SF_FS_Db::GET_PREFIX . 'utm_source' ) );
check( 'зарезервированные имена полей на месте', false !== mb_strpos( $docs, SF_FS_Db::FIELD_URL ) );

// Незакрытая подстановка в переводе видна сразу и портит текст на экране.
check( 'в тексте не осталось незаполненных подстановок', ! preg_match( '/%\d?\$?s/', $docs ), $docs ? '' : null );

foreach ( array( 'en_US', 'de_DE', 'zh_CN' ) as $locale ) {
	$translated = SF_FS_I18n::translate_in( 'Достаточно одного атрибута в теге формы: %1$s, а если хотите задать форме своё имя — %2$s.', $locale );
	check(
		'перевод вводного абзаца на ' . $locale . ' сохранил обе подстановки',
		false !== mb_strpos( $translated, '%1$s' ) && false !== mb_strpos( $translated, '%2$s' ),
		$translated
	);
}

/* ====================================================================== */

echo "\n" . str_repeat( '─', 60 ) . "\n";
printf( "Пройдено: %d\n", $GLOBALS['sf_passed'] );

if ( $GLOBALS['sf_failed'] ) {
	printf( "Провалено: %d\n", count( $GLOBALS['sf_failed'] ) );
	foreach ( $GLOBALS['sf_failed'] as $title ) {
		echo '  · ' . $title . "\n";
	}
	exit( 1 );
}

echo "Все проверки пройдены.\n";
exit( 0 );
