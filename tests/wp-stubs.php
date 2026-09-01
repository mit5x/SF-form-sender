<?php
/**
 * Заглушки WordPress для автотестов плагина.
 *
 * WordPress здесь не поднимается: он нужен целиком, с базой и веб-сервером, а
 * проверять надо логику плагина. Поэтому воспроизведены только те функции,
 * которыми плагин действительно пользуется, и ровно в том поведении, на
 * которое он рассчитывает.
 *
 * Место базы данных занимает SQLite: запросы плагина простые, и на них
 * настоящая база отвечает так же, зато таблицы создаются из того же самого
 * CREATE TABLE, что уедет на сайт.
 *
 * @package SF_Form_Sender
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Только из консоли.' );
}

define( 'SF_TEST_SANDBOX', sys_get_temp_dir() . '/sf-fs-test-' . getmypid() );
@mkdir( SF_TEST_SANDBOX . '/wp-admin/includes', 0777, true );
@mkdir( SF_TEST_SANDBOX . '/wp-includes/PHPMailer', 0777, true );
@mkdir( SF_TEST_SANDBOX . '/uploads', 0777, true );
file_put_contents( SF_TEST_SANDBOX . '/wp-admin/includes/upgrade.php', '<?php' );

define( 'ABSPATH', SF_TEST_SANDBOX . '/' );
define( 'WPINC', 'wp-includes' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

/* ---------------------------------------------------------------------- */
/* Хранилище опций                                                         */
/* ---------------------------------------------------------------------- */

$GLOBALS['sf_options'] = array(
	'admin_email' => 'admin@example.com',
	'blogname'    => 'Тестовый сайт',
	'WPLANG'      => 'ru_RU',
	'date_format' => 'd.m.Y',
	'time_format' => 'H:i',
);

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['sf_options'] ) ? $GLOBALS['sf_options'][ $name ] : $default;
}

function update_option( $name, $value ) {
	$GLOBALS['sf_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['sf_options'][ $name ] );
	return true;
}

/* ---------------------------------------------------------------------- */
/* Хуки: запоминаем, но не вызываем — плагин на это не рассчитывает         */
/* ---------------------------------------------------------------------- */

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
function remove_action( $hook, $callback, $priority = 10 ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
function remove_filter( $hook, $callback, $priority = 10 ) {}
function do_action( $hook, ...$args ) {}
function apply_filters( $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['sf_filters'][ $hook ] ) ) {
		return call_user_func_array( $GLOBALS['sf_filters'][ $hook ], array_merge( array( $value ), $args ) );
	}
	return $value;
}
function register_activation_hook( $file, $callback ) {}
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/' ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.com/wp-content/plugins/sf-form-sender/'; }
function plugin_basename( $file ) { return 'sf-form-sender/sf-form-sender.php'; }
function is_admin() { return ! empty( $GLOBALS['sf_is_admin'] ); }

/* ---------------------------------------------------------------------- */
/* Перевод                                                                 */
/* ---------------------------------------------------------------------- */

$GLOBALS['sf_textdomains'] = array();

function load_textdomain( $domain, $mofile ) {
	$GLOBALS['sf_textdomains'][ $domain ] = sf_test_read_mo( $mofile );
	return true;
}

function load_plugin_textdomain( $domain, $deprecated, $path ) {
	return true;
}

function translate( $text, $domain = 'default' ) {
	$table = isset( $GLOBALS['sf_textdomains'][ $domain ] ) ? $GLOBALS['sf_textdomains'][ $domain ] : array();
	return isset( $table[ $text ] ) ? $table[ $text ] : $text;
}

function __( $text, $domain = 'default' ) { return translate( $text, $domain ); }
function _e( $text, $domain = 'default' ) { echo translate( $text, $domain ); }
function esc_html__( $text, $domain = 'default' ) { return esc_html( translate( $text, $domain ) ); }
function esc_attr__( $text, $domain = 'default' ) { return esc_attr( translate( $text, $domain ) ); }
function esc_html_e( $text, $domain = 'default' ) { echo esc_html__( $text, $domain ); }
function esc_attr_e( $text, $domain = 'default' ) { echo esc_attr__( $text, $domain ); }
function _n( $single, $plural, $number, $domain = 'default' ) { return 1 === (int) $number ? $single : $plural; }

/**
 * Чтение .mo — той же раскладкой, что пишет наш сборщик.
 *
 * @param string $file Путь.
 * @return array<string,string>
 */
function sf_test_read_mo( $file ) {
	if ( ! file_exists( $file ) ) {
		return array();
	}
	$data  = file_get_contents( $file );
	$magic = unpack( 'V', substr( $data, 0, 4 ) )[1];
	if ( 0x950412de !== $magic ) {
		return array();
	}

	$head  = unpack( 'Vrevision/Vcount/Vkeys/Vvalues', substr( $data, 4, 16 ) );
	$table = array();

	for ( $i = 0; $i < $head['count']; $i++ ) {
		$key   = unpack( 'Vlen/Voff', substr( $data, $head['keys'] + $i * 8, 8 ) );
		$value = unpack( 'Vlen/Voff', substr( $data, $head['values'] + $i * 8, 8 ) );
		$id    = substr( $data, $key['off'], $key['len'] );
		$str   = substr( $data, $value['off'], $value['len'] );
		// Множественные формы записаны через \0; для заглушки берём первую.
		$table[ explode( "\0", $id )[0] ] = explode( "\0", $str )[0];
	}

	return $table;
}

/* ---------------------------------------------------------------------- */
/* Экранирование и чистка ввода                                            */
/* ---------------------------------------------------------------------- */

function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $url ) { return filter_var( (string) $url, FILTER_VALIDATE_URL ) ? (string) $url : ''; }
function esc_textarea( $text ) { return esc_html( $text ); }

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function sanitize_text_field( $text ) {
	$text = strip_tags( (string) $text );
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', $text ) );
}

function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
/** @var int Сколько раз плагин позвал sanitize_file_name(). */
$GLOBALS['sf_sanitize_calls'] = 0;

/**
 * Заглушка ведёт себя как сайт с установленным Cyr-To-Lat: такие плагины
 * вешают на sanitize_file_name() фильтр и переводят имя в латиницу. Именно
 * из-за этого имена вложений транслитерировались при выключенной настройке.
 * Заглушка считает вызовы, чтобы тест поймал возврат к прежнему поведению.
 *
 * @param string $name Имя файла.
 * @return string
 */
function sanitize_file_name( $name ) {
	++$GLOBALS['sf_sanitize_calls'];

	$map  = array(
		'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
		'з' => 'z', 'и' => 'i', 'й' => 'j', 'к' => 'k', 'л' => 'l', 'м' => 'm',
		'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
		'у' => 'u', 'ф' => 'f', 'ы' => 'y', 'я' => 'ya',
	);
	$name = strtr( mb_strtolower( (string) $name, 'UTF-8' ), $map );

	return preg_replace( '/[^A-Za-z0-9._-]+/u', '-', $name );
}
function wp_strip_all_tags( $text ) { return trim( strip_tags( (string) $text ) ); }
function wp_specialchars_decode( $text, $quotes = null ) { return htmlspecialchars_decode( (string) $text, ENT_QUOTES ); }
function wp_check_invalid_utf8( $text, $strip = false ) { return (string) $text; }
function wp_basename( $path ) { return basename( str_replace( '\\', '/', (string) $path ) ); }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE ); }
function is_email( $value ) { return (bool) filter_var( (string) $value, FILTER_VALIDATE_EMAIL ); }
function number_format_i18n( $number ) { return (string) $number; }
function size_format( $bytes ) { return $bytes . ' B'; }
function trailingslashit( $path ) { return rtrim( (string) $path, '/\\' ) . '/'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time(); }
function wp_salt( $scheme = 'auth' ) { return 'sf-test-salt-' . $scheme; }
function home_url( $path = '' ) { return 'https://example.com' . $path; }
function admin_url( $path = '' ) { return 'https://example.com/wp-admin/' . $path; }
function wp_get_referer() { return 'https://example.com/'; }
function wp_date( $format, $time ) { return date( $format, $time ); }
function nocache_headers() {}
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); }

function wp_upload_dir() {
	return array(
		'basedir' => SF_TEST_SANDBOX . '/uploads',
		'baseurl' => 'https://example.com/wp-content/uploads',
	);
}

/**
 * Таблица типов WordPress — сокращённая до того, что встречается в формах.
 *
 * @return array<string,string>
 */
function wp_get_mime_types() {
	return array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'gif'          => 'image/gif',
		'pdf'          => 'application/pdf',
		'zip'          => 'application/zip',
		'doc'          => 'application/msword',
		'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'          => 'application/vnd.ms-excel',
		'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ppt'          => 'application/vnd.ms-powerpoint',
		'pptx'         => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'txt'          => 'text/plain',
	);
}

/**
 * Тип по расширению — как wp_check_filetype().
 *
 * @param string                    $filename Имя файла.
 * @param array<string,string>|null $mimes    Таблица типов.
 * @return array{ext:string|false,type:string|false}
 */
function wp_check_filetype( $filename, $mimes = null ) {
	$mimes = $mimes ? $mimes : wp_get_mime_types();

	foreach ( $mimes as $pattern => $mime ) {
		if ( preg_match( '!\.(' . $pattern . ')$!i', $filename, $found ) ) {
			return array( 'ext' => strtolower( $found[1] ), 'type' => $mime );
		}
	}

	return array( 'ext' => false, 'type' => false );
}

/**
 * Настоящий тип картинки по её содержимому — как wp_get_image_mime().
 *
 * @param string $file Путь.
 * @return string|false
 */
function wp_get_image_mime( $file ) {
	$type = @exif_imagetype( $file ); // phpcs:ignore
	return $type ? image_type_to_mime_type( $type ) : false;
}

/**
 * Сверка содержимого с расширением — как wp_check_filetype_and_ext().
 *
 * Заглушка намеренно повторяет поведение настоящей функции, включая
 * proper_filename: именно на этом месте плагин отказывал в приёме файла,
 * расширение которого разрешено, и упрощённая заглушка такой дефект
 * пропустила бы.
 *
 * @param string                    $file     Путь к файлу.
 * @param string                    $filename Имя файла.
 * @param array<string,string>|null $mimes    Таблица типов.
 * @return array{ext:string|false,type:string|false,proper_filename:string|false}
 */
function wp_check_filetype_and_ext( $file, $filename, $mimes = null ) {
	$proper_filename = false;

	$checked = wp_check_filetype( $filename, $mimes );
	$ext     = $checked['ext'];
	$type    = $checked['type'];

	if ( $type && 0 === strpos( $type, 'image/' ) ) {
		$real = wp_get_image_mime( $file );

		if ( ! $real ) {
			$ext  = false;
			$type = false;
		} elseif ( $real !== $type ) {
			$to_ext = array(
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/gif'  => 'gif',
				'image/webp' => 'webp',
			);

			if ( ! empty( $to_ext[ $real ] ) ) {
				$parts = explode( '.', $filename );
				array_pop( $parts );
				$parts[] = $to_ext[ $real ];
				$renamed = implode( '.', $parts );

				if ( $renamed !== $filename ) {
					$proper_filename = $renamed;
				}

				$checked = wp_check_filetype( $renamed, $mimes );
				$ext     = $checked['ext'];
				$type    = $checked['type'];
			} else {
				$ext  = false;
				$type = false;
			}
		}
	} elseif ( $type && function_exists( 'finfo_open' ) ) {
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$real  = finfo_file( $finfo, $file );
		finfo_close( $finfo );

		// Настоящий WordPress не придирается к типам, которые finfo называет
		// одинаково для целого семейства форматов.
		$vague = array( 'application/octet-stream', 'application/zip', 'application/encrypted', 'application/CDFV2-encrypted', 'text/plain' );

		if ( ! in_array( $real, $vague, true ) && $real !== $type ) {
			$ext  = false;
			$type = false;
		}
	}

	return array( 'ext' => $ext, 'type' => $type, 'proper_filename' => $proper_filename );
}

/* ---------------------------------------------------------------------- */
/* Сеть и почта                                                            */
/* ---------------------------------------------------------------------- */

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/** @var array<string,mixed> Что вернуть на запрос к внешней службе. */
$GLOBALS['sf_http_response'] = array( 'body' => '{"status":"ok"}' );

function wp_remote_get( $url, $args = array() ) { return $GLOBALS['sf_http_response']; }
function wp_remote_post( $url, $args = array() ) { return $GLOBALS['sf_http_response']; }
function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? $response['body'] : ''; }
function add_query_arg( $args, $url = '' ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

/** @var array<int,array<string,mixed>> Отправленные письма. */
$GLOBALS['sf_mail'] = array();

/** @var bool Следующее письмо должно «не уйти». */
$GLOBALS['sf_mail_fails'] = false;

function wp_mail( $to, $subject, $body, $headers = array(), $attachments = array() ) {
	$GLOBALS['sf_mail'][] = compact( 'to', 'subject', 'body', 'headers', 'attachments' );
	return ! $GLOBALS['sf_mail_fails'];
}

/* ---------------------------------------------------------------------- */
/* Ответ AJAX                                                              */
/* ---------------------------------------------------------------------- */

/** Ответ плагина, пойманный вместо вывода в браузер. */
class SF_Test_Response extends Exception {
	public $data;

	public function __construct( $data ) {
		parent::__construct( 'json' );
		$this->data = $data;
	}
}

function wp_send_json( $data, $status = null ) {
	throw new SF_Test_Response( $data );
}

/* ---------------------------------------------------------------------- */
/* База данных на SQLite                                                   */
/* ---------------------------------------------------------------------- */

class SF_Test_Wpdb {

	/** @var string */
	public $prefix = 'wp_';

	/** @var int */
	public $insert_id = 0;

	/** @var PDO */
	private $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}

	public function get_charset_collate() {
		return '';
	}

	/**
	 * Подстановка значений, как это делает WordPress.
	 *
	 * @param string $query  Запрос с %s и %d.
	 * @param mixed  ...$args Значения.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( $args && is_array( $args[0] ) && 1 === count( $args ) ) {
			$args = $args[0];
		}
		$pdo = $this->pdo;

		return preg_replace_callback(
			'/%[sdf]/',
			function ( $match ) use ( &$args, $pdo ) {
				$value = array_shift( $args );
				if ( '%d' === $match[0] ) {
					return (string) (int) $value;
				}
				if ( '%f' === $match[0] ) {
					return (string) (float) $value;
				}
				return $pdo->quote( (string) $value );
			},
			$query
		);
	}

	/**
	 * SHOW TABLES LIKE в SQLite не существует — переводим на sqlite_master.
	 *
	 * @param string $query Запрос.
	 * @return string
	 */
	private function translate( $query ) {
		if ( preg_match( "/^SHOW TABLES LIKE (.+)$/i", trim( $query ), $match ) ) {
			return 'SELECT name FROM sqlite_master WHERE type = "table" AND name = ' . $match[1];
		}
		return $query;
	}

	public function get_var( $query ) {
		$statement = $this->pdo->query( $this->translate( $query ) );
		$row       = $statement->fetch( PDO::FETCH_NUM );
		return false === $row ? null : $row[0];
	}

	public function get_row( $query, $output = ARRAY_A ) {
		$statement = $this->pdo->query( $this->translate( $query ) );
		$row       = $statement->fetch( PDO::FETCH_ASSOC );
		return false === $row ? null : $row;
	}

	public function get_results( $query, $output = ARRAY_A ) {
		$statement = $this->pdo->query( $this->translate( $query ) );
		return $statement->fetchAll( PDO::FETCH_ASSOC );
	}

	public function query( $query ) {
		return $this->pdo->exec( $this->translate( $query ) );
	}

	public function insert( $table, $data, $format = null ) {
		$columns = array_keys( $data );
		$holders = array_fill( 0, count( $columns ), '?' );

		$statement = $this->pdo->prepare(
			'INSERT INTO ' . $table . ' (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $holders ) . ')'
		);
		$ok = $statement->execute( array_values( $data ) );

		$this->insert_id = $ok ? (int) $this->pdo->lastInsertId() : 0;
		return $ok ? 1 : false;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$set = array();
		foreach ( array_keys( $data ) as $column ) {
			$set[] = $column . ' = ?';
		}
		$conditions = array();
		foreach ( array_keys( $where ) as $column ) {
			$conditions[] = $column . ' = ?';
		}

		$statement = $this->pdo->prepare(
			'UPDATE ' . $table . ' SET ' . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $conditions )
		);
		$statement->execute( array_merge( array_values( $data ), array_values( $where ) ) );

		return $statement->rowCount();
	}

	/** @return PDO Прямой доступ — нужен заглушке dbDelta. */
	public function pdo() {
		return $this->pdo;
	}
}

$GLOBALS['wpdb'] = new SF_Test_Wpdb();

/**
 * Заглушка dbDelta: создаёт таблицу из настоящего CREATE TABLE плагина,
 * переписав типы MySQL на типы SQLite.
 *
 * Так тест проверяет и сам текст запроса: опечатка в описании таблицы
 * свалит создание, а не проедет незамеченной.
 *
 * @param string $sql Запрос CREATE TABLE.
 * @return void
 */
function dbDelta( $sql ) { // phpcs:ignore WordPress.NamingConventions
	global $wpdb;

	if ( ! preg_match( '/CREATE TABLE\s+(\S+)\s*\((.*)\)\s*;?\s*$/s', trim( $sql ), $match ) ) {
		throw new RuntimeException( 'Не разобран CREATE TABLE: ' . $sql );
	}

	$table   = $match[1];
	$columns = array();
	$indexes = array();

	foreach ( explode( ',', $match[2] ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}

		if ( preg_match( '/^PRIMARY KEY\s+\((\w+)\)$/i', $line ) ) {
			continue;   // Первичный ключ уже объявлен вместе с колонкой.
		}
		if ( preg_match( '/^(UNIQUE\s+)?KEY\s+(\w+)\s+\((.+)\)$/i', $line, $key ) ) {
			$indexes[] = 'CREATE ' . ( trim( $key[1] ) ? 'UNIQUE ' : '' ) . 'INDEX '
				. $table . '_' . $key[2] . ' ON ' . $table . ' (' . $key[3] . ')';
			continue;
		}

		$parts = preg_split( '/\s+/', $line, 3 );
		$name  = $parts[0];
		$rest  = isset( $parts[2] ) ? $parts[2] : '';

		if ( false !== stripos( $rest, 'AUTO_INCREMENT' ) ) {
			$columns[] = $name . ' INTEGER PRIMARY KEY AUTOINCREMENT';
			continue;
		}

		$type = preg_match( '/^(bigint|int|smallint|tinyint)/i', $parts[1] ) ? 'INTEGER' : 'TEXT';
		$rest = preg_replace( '/\bunsigned\b/i', '', $rest );
		$columns[] = trim( $name . ' ' . $type . ' ' . $rest );
	}

	$wpdb->pdo()->exec( 'CREATE TABLE IF NOT EXISTS ' . $table . ' (' . implode( ', ', $columns ) . ')' );
	foreach ( $indexes as $index ) {
		$wpdb->pdo()->exec( str_replace( 'CREATE ', 'CREATE ', $index ) . '' );
	}
}

/* ---------------------------------------------------------------------- */
/* Сам плагин                                                              */
/* ---------------------------------------------------------------------- */

define( 'SF_FS_VERSION', 'test' );
define( 'SF_FS_FILE', dirname( __DIR__ ) . '/sf-form-sender/sf-form-sender.php' );
define( 'SF_FS_DIR', dirname( __DIR__ ) . '/sf-form-sender/' );
define( 'SF_FS_URL', 'https://example.com/wp-content/plugins/sf-form-sender/' );
define( 'SF_FS_BASENAME', 'sf-form-sender/sf-form-sender.php' );

require_once SF_FS_DIR . 'includes/class-sf-fs-i18n.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-settings.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-notices.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-db.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-tracking.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-uploads.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-captcha.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-mailer.php';
require_once SF_FS_DIR . 'includes/class-sf-fs-interceptor.php';
require_once SF_FS_DIR . 'includes/admin/class-sf-fs-columns.php';

/**
 * Метки перехвата — их объявляет главный файл плагина, который в тестах
 * не подключается.
 *
 * @return array<string,string>
 */
function sf_fs_markers() {
	return array(
		'attr'          => 'sf-form-sender-interceptor',
		'attr_data'     => 'data-sf-form-sender',
		'fail_class'    => 'sf_form_sender_interceptor_fail',
		'ok_class'      => 'sf_form_sender_interceptor_success',
		'captcha_class' => 'sf_form_sender_interceptor_captcha',
	);
}

/**
 * Уборка песочницы по завершении.
 */
register_shutdown_function(
	function () {
		$remove = function ( $dir ) use ( &$remove ) {
			foreach ( (array) glob( $dir . '/*' ) as $item ) {
				is_dir( $item ) ? $remove( $item ) : @unlink( $item );
			}
			@rmdir( $dir );
		};
		$remove( SF_TEST_SANDBOX );
	}
);
