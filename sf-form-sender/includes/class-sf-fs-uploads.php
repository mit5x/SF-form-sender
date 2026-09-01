<?php
/**
 * Файлы, прикреплённые к формам.
 *
 * Плагин отвечает за свою часть проверки: расширение из белого списка, общий
 * размер, отсутствие исполняемых файлов. Если вебмастер дополнительно
 * ограничивает выбор файлов на стороне браузера — это его дело, сюда мы не
 * вмешиваемся.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Uploads {

	/** Каталог внутри uploads. */
	const DIRNAME = 'sf-form-sender';

	/**
	 * Расширения, которые не примем никогда, что бы ни стояло в настройках.
	 *
	 * Список намеренно шире PHP: на сервере может быть включён любой из этих
	 * обработчиков.
	 *
	 * @return array<int,string>
	 */
	public static function forbidden_extensions() {
		return array(
			'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8',
			'phps', 'phtm', 'phtml', 'phar', 'pht', 'inc',
			'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'ksh',
			'exe', 'com', 'bat', 'cmd', 'msi', 'scr', 'dll', 'so',
			'jar', 'jsp', 'jspx', 'asp', 'aspx', 'ashx', 'asmx', 'cfm',
			'htaccess', 'htpasswd', 'ini', 'conf', 'user',
			'js', 'mjs', 'vbs', 'ps1', 'hta', 'htm', 'html', 'shtml', 'svg', 'xhtml',
		);
	}

	/**
	 * Корень для файлов плагина.
	 *
	 * @return array{dir:string,url:string}
	 */
	public static function base() {
		$uploads = wp_upload_dir();
		return array(
			'dir' => trailingslashit( $uploads['basedir'] ) . self::DIRNAME,
			'url' => trailingslashit( $uploads['baseurl'] ) . self::DIRNAME,
		);
	}

	/**
	 * Создание каталога и запрет выполнения того, что в нём лежит.
	 *
	 * Это лишь то, что доступно плагину: на Apache помогает .htaccess, на
	 * nginx выполнение запрещается конфигурацией сервера — об этом написано
	 * во вкладке «Документация».
	 *
	 * @return void
	 */
	public static function protect_directory() {
		$base = self::base();
		$dir  = $base['dir'];

		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Каталог с файлами из форм: отдавать можно, выполнять нельзя.\n"
				. "php_flag engine off\n"
				. "<IfModule mod_php.c>\n\tphp_flag engine off\n</IfModule>\n"
				. "<IfModule mod_php7.c>\n\tphp_flag engine off\n</IfModule>\n"
				. "<IfModule mod_php8.c>\n\tphp_flag engine off\n</IfModule>\n"
				. "Options -ExecCGI -Indexes\n"
				. "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .cgi .pl .py\n"
				. "AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .cgi .pl .py .html .htm\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Разбор $_FILES в плоский список файлов.
	 *
	 * В форме может быть и одиночное поле, и множественное вида files[] —
	 * PHP раскладывает их по-разному.
	 *
	 * @param array<string,mixed> $files Обычно $_FILES.
	 * @return array<int,array{field:string,name:string,type:string,tmp_name:string,error:int,size:int}>
	 */
	public static function flatten( $files ) {
		$out = array();

		foreach ( (array) $files as $field => $item ) {
			if ( ! isset( $item['name'] ) ) {
				continue;
			}

			if ( is_array( $item['name'] ) ) {
				foreach ( array_keys( $item['name'] ) as $i ) {
					$out[] = array(
						'field'    => (string) $field,
						'name'     => (string) $item['name'][ $i ],
						'type'     => (string) $item['type'][ $i ],
						'tmp_name' => (string) $item['tmp_name'][ $i ],
						'error'    => (int) $item['error'][ $i ],
						'size'     => (int) $item['size'][ $i ],
					);
				}
				continue;
			}

			$out[] = array(
				'field'    => (string) $field,
				'name'     => (string) $item['name'],
				'type'     => (string) $item['type'],
				'tmp_name' => (string) $item['tmp_name'],
				'error'    => (int) $item['error'],
				'size'     => (int) $item['size'],
			);
		}

		// Пустые поля выбора файла приходят с UPLOAD_ERR_NO_FILE — это не
		// ошибка, посетитель просто ничего не приложил.
		return array_values(
			array_filter(
				$out,
				function ( $file ) {
					return UPLOAD_ERR_NO_FILE !== $file['error'] || '' !== $file['name'];
				}
			)
		);
	}

	/**
	 * Соответствие «расширение → тип содержимого» для разрешённых расширений.
	 *
	 * Берём таблицу WordPress, но отбираем по своему списку, а не по
	 * get_allowed_mime_types(). Тот список про медиатеку, его правят фильтром
	 * upload_mimes под свои задачи, и заявку с формы это касаться не должно:
	 * что принимать, сказано в настройках плагина.
	 *
	 * @param array<int,string> $allowed Разрешённые расширения.
	 * @return array<string,string> В формате WordPress: «jpg|jpeg|jpe» => mime.
	 */
	public static function mime_map( $allowed ) {
		$out = array();

		foreach ( wp_get_mime_types() as $pattern => $mime ) {
			$keep = array_values( array_intersect( explode( '|', $pattern ), $allowed ) );
			if ( $keep ) {
				$out[ implode( '|', $keep ) ] = $mime;
			}
		}

		return $out;
	}

	/**
	 * Расширения из таблицы типов, по одному.
	 *
	 * @param array<string,string> $map Результат mime_map().
	 * @return array<string,bool>
	 */
	private static function known_extensions( $map ) {
		$out = array();
		foreach ( array_keys( $map ) as $pattern ) {
			foreach ( explode( '|', $pattern ) as $ext ) {
				$out[ $ext ] = true;
			}
		}
		return $out;
	}

	/**
	 * Отказ с готовым текстом.
	 *
	 * @param string $message Текст уведомления.
	 * @return array{ok:bool,error:string,files:array<int,mixed>}
	 */
	private static function refuse( $message ) {
		return array( 'ok' => false, 'error' => $message, 'files' => array() );
	}

	/**
	 * Проверка списка файлов.
	 *
	 * Возвращает и сам список: у файла, содержимое которого оказалось другого
	 * типа, имя может смениться на верное.
	 *
	 * @param array<int,array<string,mixed>> $files Результат flatten().
	 * @return array{ok:bool,error:string,files:array<int,array<string,mixed>>}
	 */
	public static function validate( $files ) {
		if ( ! $files ) {
			return array( 'ok' => true, 'error' => '', 'files' => array() );
		}

		$allowed = SF_FS_Settings::allowed_extensions();
		$map     = self::mime_map( $allowed );
		$known   = self::known_extensions( $map );
		$limit   = (int) SF_FS_Settings::get( 'max_files_size' );
		$total   = 0;

		foreach ( $files as $i => $file ) {
			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				return self::refuse( SF_FS_Notices::get( 'error_file_upload', array( 'file' => $file['name'] ) ) );
			}

			$total += (int) $file['size'];

			if ( ! self::extension_allowed( $file['name'], $allowed ) ) {
				return self::refuse(
					SF_FS_Notices::get(
						'error_file_type',
						array(
							'file'    => $file['name'],
							'allowed' => implode( ', ', $allowed ),
						)
					)
				);
			}

			$ext = strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) );

			// Про такое расширение WordPress не знает, чего ждать внутри, —
			// сверять содержимое не с чем. Расширение при этом уже прошло и
			// свой список, и список запрещённых.
			if ( ! isset( $known[ $ext ] ) ) {
				continue;
			}

			$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $map );

			// WordPress разобрал содержимое и понял, что оно другого типа:
			// картинку пересохранили в другом формате, не переименовав файл.
			// Это не повод отказывать — принимаем под верным именем, если
			// настоящий тип тоже разрешён.
			if ( ! empty( $checked['proper_filename'] ) ) {
				if ( ! self::extension_allowed( $checked['proper_filename'], $allowed ) ) {
					return self::refuse(
						SF_FS_Notices::get(
							'error_file_type',
							array(
								'file'    => $file['name'],
								'allowed' => implode( ', ', $allowed ),
							)
						)
					);
				}

				$files[ $i ]['name'] = $checked['proper_filename'];
				$files[ $i ]['type'] = (string) $checked['type'];
				continue;
			}

			// Содержимое опознать не удалось вовсе: файл повреждён или это
			// формат, о котором сервер не знает. Отказываем — но говорим
			// именно про содержимое, а не про расширение: расширение-то
			// в списке разрешённых, и винить его было бы неправдой.
			if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
				return self::refuse(
					SF_FS_Notices::get(
						'error_file_content',
						array(
							'file' => $file['name'],
							'ext'  => $ext,
						)
					)
				);
			}
		}

		if ( $total > $limit * 1024 * 1024 ) {
			return self::refuse( SF_FS_Notices::get( 'error_file_size', array( 'limit' => $limit ) ) );
		}

		return array( 'ok' => true, 'error' => '', 'files' => $files );
	}

	/**
	 * Расширение файла разрешено?
	 *
	 * Проверяются все части имени после точек: у file.php.jpg опасной является
	 * не последняя часть, а предпоследняя.
	 *
	 * @param string           $name    Имя файла.
	 * @param array<int,string> $allowed Разрешённые расширения.
	 * @return bool
	 */
	public static function extension_allowed( $name, $allowed ) {
		$parts = explode( '.', strtolower( (string) $name ) );
		array_shift( $parts );   // Само имя без расширений.

		if ( ! $parts ) {
			return false;
		}

		$forbidden = self::forbidden_extensions();
		foreach ( $parts as $part ) {
			if ( in_array( $part, $forbidden, true ) ) {
				return false;
			}
		}

		$last = end( $parts );
		return in_array( $last, $allowed, true );
	}

	/**
	 * Сохранение файлов заявки на сервер.
	 *
	 * @param int                            $submission_id Заявка.
	 * @param array<int,array<string,mixed>> $files         Результат flatten().
	 * @return array<int,array<string,mixed>> Сохранённые файлы.
	 */
	public static function store( $submission_id, $files ) {
		self::protect_directory();

		$base = self::base();
		$dir  = $base['dir'] . '/' . (int) $submission_id;

		if ( ! wp_mkdir_p( $dir ) ) {
			return array();
		}

		$saved = array();
		foreach ( $files as $file ) {
			$name = self::clean_name( $file['name'] );
			$path = $dir . '/' . $name;

			// Два одинаковых имени в одной заявке — второму добавляем номер.
			$n = 2;
			while ( file_exists( $path ) ) {
				$dot  = strrpos( $name, '.' );
				$stem = false === $dot ? $name : substr( $name, 0, $dot );
				$ext  = false === $dot ? '' : substr( $name, $dot );
				$path = $dir . '/' . $stem . '-' . $n . $ext;
				++$n;
			}

			if ( ! @move_uploaded_file( $file['tmp_name'], $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				continue;
			}
			@chmod( $path, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			$saved[] = array(
				'orig_name'   => $file['name'],
				'stored_name' => basename( $path ),
				'rel_path'    => (int) $submission_id . '/' . basename( $path ),
				'size'        => (int) $file['size'],
				'mime'        => (string) $file['type'],
				'path'        => $path,
			);
		}

		return $saved;
	}

	/**
	 * Имя файла, пригодное для файловой системы.
	 *
	 * Штатная sanitize_file_name() здесь намеренно не вызывается. На русских
	 * сайтах её почти всегда перехватывает какой-нибудь Cyr-To-Lat и
	 * переводит имя в латиницу — и тогда переключатель «Транслитерировать
	 * названия файлов?» ничего не решает: имена транслитерируются, даже когда
	 * он выключен. Тот плагин ставили ради медиатеки, а не ради вложений из
	 * форм, поэтому здесь распоряжается наша настройка.
	 *
	 * Убираем только то, что действительно ломает путь или файловую систему.
	 * Буквы любого алфавита, пробелы и скобки остаются на месте.
	 *
	 * @param string $name Исходное имя.
	 * @return string
	 */
	public static function clean_name( $name ) {
		$name = wp_basename( (string) $name );

		// Разделители каталогов, управляющие символы и знаки, запрещённые в
		// именах файлов Windows.
		$name = (string) preg_replace( '#[\x00-\x1F\x7F/\\\\:*?"<>|]+#u', '', $name );
		$name = (string) preg_replace( '/\s+/u', ' ', $name );

		if ( SF_FS_Settings::on( 'translit_files' ) ) {
			$name = self::transliterate( $name );
		}

		// Точка в начале сделала бы файл скрытым, точка в конце ломает
		// Windows; пробелы по краям не нужны никому.
		$name = trim( $name, " ." );

		$dot  = strrpos( $name, '.' );
		$stem = false === $dot ? $name : substr( $name, 0, $dot );
		$ext  = false === $dot ? '' : substr( $name, $dot );

		// Пробел перед точкой расширения выглядит опечаткой и мешает читать
		// имя в списке заявок.
		$stem = trim( $stem );

		if ( mb_strlen( $stem ) > 150 ) {
			$stem = mb_substr( $stem, 0, 150 );
		}
		if ( '' === trim( $stem ) ) {
			$stem = 'file';
		}

		return $stem . $ext;
	}

	/**
	 * Транслитерация имени файла.
	 *
	 * @param string $name Имя.
	 * @return string
	 */
	public static function transliterate( $name ) {
		$map = array(
			'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
			'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
			'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
			'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c',
			'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
			'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
			'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
			'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
			'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
			'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
			'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o',
			'ú' => 'u', 'ù' => 'u', 'û' => 'u',
			'ñ' => 'n', 'ç' => 'c', 'ý' => 'y',
		);

		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
		$out   = strtr( $lower, $map );

		return preg_replace( '/[^A-Za-z0-9._-]+/u', '-', $out );
	}

	/**
	 * Ссылка на файл заявки.
	 *
	 * @param string $rel_path Путь вида «12/scan.pdf».
	 * @return string
	 */
	public static function url( $rel_path ) {
		$base = self::base();
		return $base['url'] . '/' . ltrim( (string) $rel_path, '/' );
	}

	/**
	 * Полный путь к файлу заявки.
	 *
	 * @param string $rel_path Путь вида «12/scan.pdf».
	 * @return string
	 */
	public static function path( $rel_path ) {
		$base = self::base();
		return $base['dir'] . '/' . ltrim( (string) $rel_path, '/' );
	}

	/**
	 * Удаление каталога заявки.
	 *
	 * @param int $submission_id Заявка.
	 * @return void
	 */
	public static function delete_submission_files( $submission_id ) {
		$base = self::base();
		self::remove_tree( $base['dir'] . '/' . (int) $submission_id );
	}

	/**
	 * Удаление каталогов всех заявок.
	 *
	 * @return void
	 */
	public static function delete_all_files() {
		$base = self::base();
		foreach ( (array) glob( $base['dir'] . '/*', GLOB_ONLYDIR ) as $dir ) {
			// Каталоги названы номерами заявок — на всякий случай убеждаемся
			// в этом, чтобы не снести что-то постороннее.
			if ( preg_match( '/^\d+$/', basename( $dir ) ) ) {
				self::remove_tree( $dir );
			}
		}
	}

	/**
	 * Рекурсивное удаление каталога.
	 *
	 * @param string $dir Каталог.
	 * @return void
	 */
	private static function remove_tree( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $item ) {
			if ( is_dir( $item ) ) {
				self::remove_tree( $item );
			} else {
				@unlink( $item ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
