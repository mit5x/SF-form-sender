<?php
/**
 * Удаление плагина.
 *
 * Убираем только настройки. Заявки, файлы и таблицы остаются: их присылали
 * живые люди, и удалять их заодно с плагином — не то, чего ждёт администратор,
 * который просто решил обновить плагин переустановкой. Очистить список заявок
 * можно кнопкой «Удалить все» на странице «Заявки», а таблицы — руками.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'sf_fs_settings', 'sf_fs_notices', 'sf_fs_admin_locale', 'sf_fs_columns', 'sf_fs_per_page', 'sf_fs_db_version' ) as $option ) {
	delete_option( $option );
}
