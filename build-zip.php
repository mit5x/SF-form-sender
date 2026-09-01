<?php
/**
 * Сборка архива плагина для установки через админку WordPress.
 *
 * Запуск из каталога plugins:
 *   php build-zip.php
 *
 * Результат — sf-form-sender.zip рядом со скриптом. Внутри архива один
 * каталог sf-form-sender: именно так WordPress ожидает увидеть плагин.
 *
 * Перед сборкой скрипт проверяет очевидное: что версия в заголовке плагина
 * совпадает с константой, что словари собраны и что в архив не уезжает
 * ничего лишнего.
 *
 * @package SF_Form_Sender
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Только из консоли.' );
}

$root   = __DIR__;
$source = $root . '/sf-form-sender';
$target = $root . '/sf-form-sender.zip';

/**
 * Что в архив не кладём.
 *
 * Исходники переводов (.po и .pot) кладём: так принято в каталоге плагинов
 * WordPress, и так проще тому, кто захочет поправить перевод у себя, не
 * заглядывая в репозиторий.
 */
$skip_names = array( '.git', '.gitignore', '.DS_Store', 'node_modules' );
$skip_ext   = array();

/* --- Проверки перед сборкой --------------------------------------------- */

$problems = array();

$main = file_get_contents( $source . '/sf-form-sender.php' );

preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $main, $header );
preg_match( "/define\(\s*'SF_FS_VERSION',\s*'([^']+)'/", $main, $constant );

if ( empty( $header[1] ) || empty( $constant[1] ) ) {
	$problems[] = 'Не найдена версия плагина.';
} elseif ( trim( $header[1] ) !== $constant[1] ) {
	$problems[] = sprintf( 'Версия в заголовке (%s) и в константе (%s) разошлись.', trim( $header[1] ), $constant[1] );
}

$languages = glob( $source . '/languages/*.mo' );
if ( count( $languages ) < 7 ) {
	$problems[] = sprintf( 'Собрано словарей: %d, ожидалось 7. Запустите python3 tools/build-languages.py', count( $languages ) );
}

foreach ( glob( $source . '/languages/*.po' ) as $po ) {
	$mo = substr( $po, 0, -3 ) . '.mo';
	if ( ! file_exists( $mo ) || filemtime( $mo ) < filemtime( $po ) ) {
		$problems[] = 'Словарь старше своего .po: ' . basename( $po );
	}
}

if ( $problems ) {
	echo "Сборка не выполнена:\n";
	foreach ( $problems as $problem ) {
		echo '  · ' . $problem . "\n";
	}
	exit( 1 );
}

/* --- Сборка -------------------------------------------------------------- */

if ( file_exists( $target ) ) {
	unlink( $target );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	exit( "Не удалось создать архив.\n" );
}

$files = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
		function ( $item ) use ( $skip_names ) {
			return ! in_array( $item->getFilename(), $skip_names, true );
		}
	),
	RecursiveIteratorIterator::SELF_FIRST
);

$count = 0;
$bytes = 0;

foreach ( $files as $file ) {
	$relative = 'sf-form-sender/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $source ) + 1 ) );

	if ( $file->isDir() ) {
		$zip->addEmptyDir( $relative );
		continue;
	}

	if ( in_array( strtolower( $file->getExtension() ), $skip_ext, true ) ) {
		continue;
	}

	$zip->addFile( $file->getPathname(), $relative );
	++$count;
	$bytes += $file->getSize();
}

$zip->close();

/* --- Проверка собранного архива ------------------------------------------ */

$inside  = archive_contents( $target );
$missing = check_complete( $source, $inside );

if ( $missing ) {
	unlink( $target );
	echo "Архив собрался неполным и удалён. Не хватает:\n";
	foreach ( $missing as $problem ) {
		echo '  · ' . $problem . "\n";
	}
	exit( 1 );
}

printf(
	"Собран %s\n  файлов: %d, до сжатия %s, архив %s\n  установка с нуля: всё, на что ссылается код, внутри\n",
	basename( $target ),
	$count,
	size( $bytes ),
	size( filesize( $target ) )
);

/**
 * Список файлов внутри архива.
 *
 * @param string $path Путь к архиву.
 * @return array<string,bool> Пути без приставки sf-form-sender/.
 */
function archive_contents( $path ) {
	$zip = new ZipArchive();
	$zip->open( $path );

	$out = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->getNameIndex( $i );
		if ( '/' !== substr( $name, -1 ) ) {
			$out[ substr( $name, strlen( 'sf-form-sender/' ) ) ] = true;
		}
	}
	$zip->close();

	return $out;
}

/**
 * Всё ли, на что ссылается код, попало в архив.
 *
 * Читаем не список файлов на диске, а сами исходники: подключения через
 * SF_FS_DIR, адреса стилей и скриптов через SF_FS_URL и словари языков.
 * Забытый в архиве файл — это белый экран у того, кто ставит плагин с нуля,
 * поэтому проверка идёт по коду, а не по нашей памяти о составе.
 *
 * @param string             $source Каталог с исходниками.
 * @param array<string,bool> $inside Что оказалось внутри архива.
 * @return array<int,string> Чего не хватает.
 */
function check_complete( $source, $inside ) {
	$missing = array();
	$seen    = array();

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $files as $file ) {
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		$code = file_get_contents( $file->getPathname() );

		// require/include через SF_FS_DIR и адреса файлов через SF_FS_URL.
		if ( preg_match_all( "/SF_FS_(?:DIR|URL)\s*\.\s*'([^']+)'/", $code, $matches ) ) {
			foreach ( $matches[1] as $path ) {
				$seen[ $path ] = true;
			}
		}
	}

	foreach ( array_keys( $seen ) as $path ) {
		// Пути к каталогу загрузок и прочее, чего в плагине нет, пропускаем:
		// проверяем только то, что лежит рядом с исходниками.
		if ( ! file_exists( $source . '/' . $path ) ) {
			continue;
		}
		if ( ! isset( $inside[ $path ] ) ) {
			$missing[] = $path . ' — код на него ссылается, а в архиве его нет';
		}
	}

	// Словари: без .mo интерфейс молча остаётся на языке исходников.
	foreach ( glob( $source . '/languages/*.mo' ) as $mo ) {
		$path = 'languages/' . basename( $mo );
		if ( ! isset( $inside[ $path ] ) ) {
			$missing[] = $path;
		}
	}

	foreach ( array( 'sf-form-sender.php', 'uninstall.php' ) as $path ) {
		if ( ! isset( $inside[ $path ] ) ) {
			$missing[] = $path;
		}
	}

	// Каждый php в архиве должен разбираться: битый файл валит весь сайт.
	foreach ( array_keys( $inside ) as $path ) {
		if ( 'php' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			continue;
		}
		exec( 'php -l ' . escapeshellarg( $source . '/' . $path ) . ' 2>&1', $output, $status );
		if ( 0 !== $status ) {
			$missing[] = $path . ' — синтаксическая ошибка: ' . implode( ' ', $output );
		}
		$output = array();
	}

	return $missing;
}

/**
 * Размер по-человечески.
 *
 * @param int $bytes Байты.
 * @return string
 */
function size( $bytes ) {
	return $bytes > 1048576
		? round( $bytes / 1048576, 1 ) . ' МБ'
		: round( $bytes / 1024 ) . ' КБ';
}
