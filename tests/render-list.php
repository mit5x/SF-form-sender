<?php
/**
 * Разметка страницы «Заявки» для браузерной проверки.
 *
 * Печатает то же, что увидит администратор, но на заглушках WordPress:
 * так браузерный тест смотрит на настоящую разметку плагина, а не на
 * переписанную от руки копию, которая разойдётся с ней на первой же правке.
 *
 * Запуск:
 *   php tests/render-list.php
 *
 * @package SF_Form_Sender
 */

require __DIR__ . '/wp-stubs.php';

SF_FS_Db::install();

// Заявки с длинными значениями: узкая колонка должна их обрезать, а не
// растягивать таблицу.
$fields = array(
	'name'     => 'Дмитрий',
	'calc'     => 'Многостраничный сайт — 45 000 ₽; Акции — + 3 000 ₽; Сотрудники — + 3 000 ₽',
	'task'     => 'Расскажите о задаче: нужен сайт студии с калькулятором и формой заявки',
	'email'    => 'dima@web-format.net',
	'phone'    => '+7 900 000-00-00',
	'comment'  => "первая строка\nвторая строка",
);
$labels = array(
	'name'    => 'Имя',
	'calc'    => 'Калькулятор',
	'task'    => 'Расскажите о задаче',
	'email'   => 'E-mail',
	'phone'   => 'Телефон',
	'comment' => 'Комментарий',
);

for ( $i = 1; $i <= 3; $i++ ) {
	SF_FS_Db::save_submission(
		array(
			'date'       => '2026-09-0' . $i . ' 20:56:00',
			'form_id'    => 'calculator',
			'url'        => 'https://example.com/#calculator',
			'ip'         => '188.241.196.243',
			'user_agent' => 'Mozilla/5.0',
			'fields'     => $fields,
			'labels'     => $labels,
		)
	);
}

// Колонок нарочно много: таблица должна оказаться шире окна.
SF_FS_Columns::save(
	array_merge( array( '_id', '_date' ), array_keys( $fields ), array( '_files', '_view', '_delete', '_url' ) ),
	array_merge( array_keys( $fields ), array( '_url' ) )
);

ob_start();
SF_FS_Submissions_Page::render();
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput
