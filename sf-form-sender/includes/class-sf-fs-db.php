<?php
/**
 * Хранение заявок.
 *
 * Формы на разных сайтах называют поля как угодно: name, nombre, nom, nome,
 * isim. Заранее завести колонку под каждое имя нельзя, поэтому вместо широкой
 * таблицы с фиксированными колонками используется три связанных:
 *
 *   sf_fs_fields       — словарь встреченных имён полей и подписи к ним,
 *                        которые администратор правит в админке;
 *   sf_fs_values       — значения полей;
 *   sf_fs_submissions  — сами заявки: время, ip, адрес страницы, число файлов;
 *   sf_fs_files        — файлы заявки (четвёртая таблица: список файлов —
 *                        не значение поля, у него свои размер, mime и путь).
 *
 * Время отправки, ip и адрес страницы лежат колонками в таблице заявок: по ним
 * идёт сортировка и постраничная навигация, и они есть у каждой заявки. Но
 * имена sf_date, sf_ip_sender и sf_url зарезервированы: если вебмастер завёл в
 * форме поле с таким именем, значение из формы перезапишет то, что определил
 * плагин.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Db {

	/** Версия схемы. Меняется, когда меняется состав таблиц. */
	const SCHEMA_VERSION = '1.0.0';

	/** Зарезервированные имена полей формы. */
	const FIELD_IP   = 'sf_ip_sender';
	const FIELD_URL  = 'sf_url';
	const FIELD_DATE = 'sf_date';

	/** Приставка у полей, пришедших из сохранённых GET параметров. */
	const GET_PREFIX = 'sf_get_';

	/**
	 * Имя таблицы с префиксом сайта.
	 *
	 * @param string $name Короткое имя: submissions, fields, values, files.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'sf_fs_' . $name;
	}

	/**
	 * Зарезервированные имена полей.
	 *
	 * @return array<int,string>
	 */
	public static function reserved_fields() {
		return array( self::FIELD_IP, self::FIELD_URL, self::FIELD_DATE );
	}

	/**
	 * Создание таблиц.
	 *
	 * dbDelta сам смотрит, что уже есть: повторный вызов на существующих
	 * таблицах ничего не ломает и ошибки не даёт.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$s       = self::table( 'submissions' );
		$f       = self::table( 'fields' );
		$v       = self::table( 'values' );
		$u       = self::table( 'files' );

		dbDelta(
			"CREATE TABLE $s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				created_at_gmt datetime NOT NULL,
				form_id varchar(64) NOT NULL DEFAULT '',
				page_url text NOT NULL,
				ip varchar(64) NOT NULL DEFAULT '',
				user_agent varchar(255) NOT NULL DEFAULT '',
				files_count smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $f (
				id int(10) unsigned NOT NULL AUTO_INCREMENT,
				field_key varchar(191) NOT NULL,
				label varchar(255) NOT NULL DEFAULT '',
				is_system tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY field_key (field_key)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $v (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				submission_id bigint(20) unsigned NOT NULL,
				field_id int(10) unsigned NOT NULL,
				value longtext NOT NULL,
				PRIMARY KEY  (id),
				KEY submission_id (submission_id),
				KEY field_id (field_id)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $u (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				submission_id bigint(20) unsigned NOT NULL,
				orig_name varchar(255) NOT NULL DEFAULT '',
				stored_name varchar(255) NOT NULL DEFAULT '',
				rel_path varchar(255) NOT NULL DEFAULT '',
				size bigint(20) unsigned NOT NULL DEFAULT 0,
				mime varchar(100) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY submission_id (submission_id)
			) $charset;"
		);

		update_option( 'sf_fs_db_version', self::SCHEMA_VERSION );
	}

	/**
	 * Все таблицы на месте?
	 *
	 * @return bool
	 */
	public static function tables_ready() {
		global $wpdb;
		foreach ( array( 'submissions', 'fields', 'values', 'files' ) as $name ) {
			$table = self::table( $name );
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return false;
			}
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Словарь полей                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Все известные поля.
	 *
	 * @return array<string,array{id:int,field_key:string,label:string,is_system:int}>
	 */
	public static function fields() {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT id, field_key, label, is_system FROM ' . self::table( 'fields' ) . ' ORDER BY id ASC', ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$row['id']        = (int) $row['id'];
			$row['is_system'] = (int) $row['is_system'];
			$out[ $row['field_key'] ] = $row;
		}
		return $out;
	}

	/**
	 * Идентификатор поля по имени; при первой встрече поле заводится.
	 *
	 * @param string $key   Имя поля из формы.
	 * @param string $label Подпись, если поле встретилось впервые.
	 * @return int
	 */
	public static function field_id( $key, $label = '' ) {
		global $wpdb;

		$key   = self::sanitize_key( $key );
		$table = self::table( 'fields' );

		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE field_key = %s", $key ) );
		if ( $id ) {
			return (int) $id;
		}

		$wpdb->insert(
			$table,
			array(
				'field_key'  => $key,
				'label'      => '' !== $label ? $label : $key,
				'is_system'  => in_array( $key, self::reserved_fields(), true ) ? 1 : 0,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s' )
		);

		// Гонка двух одновременных отправок: вставка не прошла — значит поле
		// уже завели, просто читаем его заново.
		return (int) ( $wpdb->insert_id ? $wpdb->insert_id : $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE field_key = %s", $key ) ) );
	}

	/**
	 * Переименование подписи поля.
	 *
	 * @param int    $id    Идентификатор поля.
	 * @param string $label Подпись.
	 * @return void
	 */
	public static function set_field_label( $id, $label ) {
		global $wpdb;
		$wpdb->update(
			self::table( 'fields' ),
			array( 'label' => mb_substr( (string) $label, 0, 255 ) ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Приведение имени поля к безопасному виду.
	 *
	 * Из формы может прийти что угодно, включая массивы вида files[] и
	 * названия с пробелами. Буквы любого алфавита оставляем: поле вполне может
	 * называться «имя» или «nombre», и превращать такое имя в кашу незачем.
	 *
	 * @param string $key Имя.
	 * @return string
	 */
	public static function sanitize_key( $key ) {
		$key = (string) $key;
		$key = preg_replace( '/[^\p{L}\p{N}_\-.\[\]]/u', '_', $key );
		$key = trim( (string) $key, '_' );
		if ( '' === $key ) {
			$key = 'field';
		}
		return mb_substr( $key, 0, 191 );
	}

	/* ------------------------------------------------------------------ */
	/* Заявки                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Сохранение заявки.
	 *
	 * @param array{fields:array<string,string>,ip:string,url:string,form_id:string,date:string,user_agent:string,labels:array<string,string>} $data Данные.
	 * @return int Идентификатор заявки или 0 при ошибке.
	 */
	public static function save_submission( $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert(
			self::table( 'submissions' ),
			array(
				'created_at'     => isset( $data['date'] ) && '' !== $data['date'] ? $data['date'] : $now,
				'created_at_gmt' => current_time( 'mysql', 1 ),
				'form_id'        => mb_substr( (string) $data['form_id'], 0, 64 ),
				'page_url'       => (string) $data['url'],
				'ip'             => mb_substr( (string) $data['ip'], 0, 64 ),
				'user_agent'     => mb_substr( (string) $data['user_agent'], 0, 255 ),
				'files_count'    => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ! $ok ) {
			return 0;
		}

		$submission_id = (int) $wpdb->insert_id;
		$labels        = isset( $data['labels'] ) ? (array) $data['labels'] : array();

		foreach ( (array) $data['fields'] as $key => $value ) {
			$label    = isset( $labels[ $key ] ) ? $labels[ $key ] : '';
			$field_id = self::field_id( $key, $label );
			if ( ! $field_id ) {
				continue;
			}
			$wpdb->insert(
				self::table( 'values' ),
				array(
					'submission_id' => $submission_id,
					'field_id'      => $field_id,
					'value'         => (string) $value,
				),
				array( '%d', '%d', '%s' )
			);
		}

		return $submission_id;
	}

	/**
	 * Запись файла заявки.
	 *
	 * @param int                                                                          $submission_id Заявка.
	 * @param array{orig_name:string,stored_name:string,rel_path:string,size:int,mime:string} $file          Файл.
	 * @return void
	 */
	public static function add_file( $submission_id, $file ) {
		global $wpdb;

		$wpdb->insert(
			self::table( 'files' ),
			array(
				'submission_id' => (int) $submission_id,
				'orig_name'     => mb_substr( $file['orig_name'], 0, 255 ),
				'stored_name'   => mb_substr( $file['stored_name'], 0, 255 ),
				'rel_path'      => mb_substr( $file['rel_path'], 0, 255 ),
				'size'          => (int) $file['size'],
				'mime'          => mb_substr( $file['mime'], 0, 100 ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		$table = self::table( 'submissions' );
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET files_count = files_count + 1 WHERE id = %d", (int) $submission_id ) );
	}

	/**
	 * Страница списка заявок.
	 *
	 * @param int $per_page Строк на странице.
	 * @param int $page     Номер страницы, с единицы.
	 * @return array<int,array<string,mixed>>
	 */
	public static function submissions( $per_page, $page ) {
		global $wpdb;

		$per_page = max( 1, (int) $per_page );
		$offset   = max( 0, ( (int) $page - 1 ) * $per_page );
		$table    = self::table( 'submissions' );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);

		return self::attach_values( (array) $rows );
	}

	/**
	 * Значения полей для набора заявок — одним запросом.
	 *
	 * @param array<int,array<string,mixed>> $rows Заявки.
	 * @return array<int,array<string,mixed>>
	 */
	private static function attach_values( $rows ) {
		global $wpdb;

		if ( ! $rows ) {
			return array();
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row['id'];
		}
		$in = implode( ',', array_map( 'intval', $ids ) );

		$v = self::table( 'values' );
		$f = self::table( 'fields' );

		$values = $wpdb->get_results(
			"SELECT v.submission_id, f.field_key, v.value
			 FROM $v v INNER JOIN $f f ON f.id = v.field_id
			 WHERE v.submission_id IN ($in)",
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $values as $value ) {
			$map[ (int) $value['submission_id'] ][ $value['field_key'] ] = $value['value'];
		}

		foreach ( $rows as $i => $row ) {
			$id                  = (int) $row['id'];
			$rows[ $i ]['id']    = $id;
			$rows[ $i ]['data']  = isset( $map[ $id ] ) ? $map[ $id ] : array();
		}

		return $rows;
	}

	/**
	 * Одна заявка со значениями и файлами.
	 *
	 * @param int $id Идентификатор.
	 * @return array<string,mixed>|null
	 */
	public static function submission( $id ) {
		global $wpdb;

		$table = self::table( 'submissions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}

		$rows          = self::attach_values( array( $row ) );
		$row           = $rows[0];
		$row['files']  = self::files( (int) $id );

		return $row;
	}

	/**
	 * Файлы заявки.
	 *
	 * @param int $submission_id Заявка.
	 * @return array<int,array<string,mixed>>
	 */
	public static function files( $submission_id ) {
		global $wpdb;

		$table = self::table( 'files' );
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE submission_id = %d ORDER BY id ASC", (int) $submission_id ),
			ARRAY_A
		);
	}

	/**
	 * Сколько всего заявок.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'submissions' ) );
	}

	/**
	 * Удаление заявок вместе со значениями и файлами.
	 *
	 * @param array<int,int> $ids Идентификаторы.
	 * @return int Сколько удалено.
	 */
	public static function delete( $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		$ids = array_filter(
			$ids,
			function ( $id ) {
				return $id > 0;
			}
		);
		if ( ! $ids ) {
			return 0;
		}

		foreach ( $ids as $id ) {
			SF_FS_Uploads::delete_submission_files( $id );
		}

		$in = implode( ',', $ids );
		$wpdb->query( 'DELETE FROM ' . self::table( 'values' ) . " WHERE submission_id IN ($in)" );
		$wpdb->query( 'DELETE FROM ' . self::table( 'files' ) . " WHERE submission_id IN ($in)" );

		return (int) $wpdb->query( 'DELETE FROM ' . self::table( 'submissions' ) . " WHERE id IN ($in)" );
	}

	/**
	 * Удаление всех заявок.
	 *
	 * Словарь полей остаётся: подписи колонок настраивал администратор, терять
	 * его работу из-за очистки списка заявок незачем.
	 *
	 * @return int Сколько удалено.
	 */
	public static function delete_all() {
		global $wpdb;

		SF_FS_Uploads::delete_all_files();

		$count = self::count();
		$wpdb->query( 'DELETE FROM ' . self::table( 'values' ) );
		$wpdb->query( 'DELETE FROM ' . self::table( 'files' ) );
		$wpdb->query( 'DELETE FROM ' . self::table( 'submissions' ) );

		return $count;
	}
}
