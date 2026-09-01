<?php
/**
 * Тексты уведомлений, которые видит посетитель сайта.
 *
 * Хранятся отдельной опцией и редактируются на вкладке «Уведомления».
 * При установке заполняются на языке сайта. Это не словарь интерфейса:
 * администратор может написать здесь что угодно, и переключение языка
 * админки эти тексты не меняет.
 *
 * Здесь только то, что плагин выявляет сам по своим настройкам: капча,
 * файлы, почта, база данных. Незаполненное обязательное поле и неверный
 * адрес почты сюда не входят — это проверка браузера или скрипта вебмастера,
 * и подменять её своими текстами плагин не должен.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Notices {

	const OPTION = 'sf_fs_notices';

	/** @var array<string,string>|null */
	private static $cache = null;

	/**
	 * Описание всех уведомлений.
	 *
	 * Ключи: label — подпись поля, default — текст по умолчанию (по-русски,
	 * переводится при установке), help — когда посетитель это увидит,
	 * tokens — допустимые подстановки.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function schema() {
		return array(
			'success'           => array(
				'label'   => __( 'Успешная отправка', 'sf-form-sender' ),
				'default' => 'Спасибо! Ваше сообщение отправлено, мы свяжемся с вами в ближайшее время.',
				'help'    => __( 'Показывается в контейнере успеха после того, как заявка принята.', 'sf-form-sender' ),
			),
			'error'             => array(
				'label'   => __( 'Общая ошибка отправки', 'sf-form-sender' ),
				'default' => 'Не удалось отправить сообщение. Попробуйте ещё раз или свяжитесь с нами другим способом.',
				'help'    => __( 'Запасной текст: показывается, когда причину назвать нельзя или для неё нет отдельного уведомления.', 'sf-form-sender' ),
			),
			'error_empty'       => array(
				'label'   => __( 'Пустая форма', 'sf-form-sender' ),
				'default' => 'Форма пустая: заполните хотя бы одно поле.',
				'help'    => __( 'Защита от случайной отправки формы, в которой нет ни одного заполненного поля.', 'sf-form-sender' ),
			),
			'error_expired'     => array(
				'label'   => __( 'Устаревшая страница', 'sf-form-sender' ),
				'default' => 'Страница была открыта слишком давно. Обновите её и отправьте форму заново.',
				'help'    => __( 'Проверочный ключ формы живёт сутки. Обычно это значит, что вкладку не закрывали больше суток.', 'sf-form-sender' ),
			),
			'error_captcha'     => array(
				'label'   => __( 'Капча не пройдена', 'sf-form-sender' ),
				'default' => 'Подтвердите, что вы не робот.',
				'help'    => __( 'Посетитель не отметил капчу или её проверка на сервере не удалась.', 'sf-form-sender' ),
			),
			'error_file_type'   => array(
				'label'   => __( 'Запрещённый тип файла', 'sf-form-sender' ),
				'default' => 'Файл «{file}» нельзя загрузить. Разрешены: {allowed}.',
				'help'    => __( 'Подстановки: {file} — имя файла, {allowed} — список разрешённых расширений.', 'sf-form-sender' ),
			),
			'error_file_content' => array(
				'label'   => __( 'Содержимое файла не совпадает с расширением', 'sf-form-sender' ),
				'default' => 'Не удалось разобрать содержимое файла «{file}». Проверьте, что это действительно {ext}, и попробуйте ещё раз.',
				'help'    => __( 'Расширение разрешено, но внутри файла оказалось не то, что обещает имя: файл повреждён или сохранён в формате, о котором сервер не знает. Если формат просто другой и он тоже разрешён, плагин молча примет файл под верным именем и этого уведомления не покажет. Подстановки: {file} — имя файла, {ext} — расширение.', 'sf-form-sender' ),
			),
			'error_file_size'   => array(
				'label'   => __( 'Файлы слишком большие', 'sf-form-sender' ),
				'default' => 'Общий размер файлов больше допустимых {limit} МБ.',
				'help'    => __( 'Подстановка: {limit} — ограничение из настроек.', 'sf-form-sender' ),
			),
			'error_file_upload' => array(
				'label'   => __( 'Файл не загрузился', 'sf-form-sender' ),
				'default' => 'Не удалось загрузить файл «{file}». Попробуйте ещё раз.',
				'help'    => __( 'Сбой при передаче файла на сервер. Подстановка: {file}.', 'sf-form-sender' ),
			),
			'error_mail'        => array(
				'label'   => __( 'Письмо не отправлено', 'sf-form-sender' ),
				'default' => 'Не удалось отправить письмо. Мы уже знаем о сбое, но лучше свяжитесь с нами другим способом.',
				'help'    => __( 'Почтовый сервер отказал. Если сохранение заявок включено, заявка при этом уже в базе.', 'sf-form-sender' ),
			),
			'error_db'          => array(
				'label'   => __( 'Заявка не сохранена', 'sf-form-sender' ),
				'default' => 'Не удалось сохранить заявку. Попробуйте позже.',
				'help'    => __( 'Ошибка базы данных. Видна, только если письмо тоже не ушло.', 'sf-form-sender' ),
			),
			'error_network'     => array(
				'label'   => __( 'Сервер не ответил', 'sf-form-sender' ),
				'default' => 'Сервер не ответил. Проверьте соединение и попробуйте ещё раз.',
				'help'    => __( 'Показывается браузером, когда запрос не дошёл до сайта.', 'sf-form-sender' ),
			),
			'sending'           => array(
				'label'   => __( 'Отправка идёт', 'sf-form-sender' ),
				'default' => 'Отправляем…',
				'help'    => __( 'Подпись на кнопке, пока заявка уходит на сервер. Оставьте поле пустым, чтобы подпись кнопки не менялась.', 'sf-form-sender' ),
			),
		);
	}

	/**
	 * Все тексты.
	 *
	 * @return array<string,string>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved = get_option( self::OPTION, array() );
			$saved = is_array( $saved ) ? $saved : array();
			$out   = array();
			foreach ( self::schema() as $key => $item ) {
				$out[ $key ] = isset( $saved[ $key ] ) ? (string) $saved[ $key ] : $item['default'];
			}
			self::$cache = $out;
		}
		return self::$cache;
	}

	/**
	 * Один текст с подстановками.
	 *
	 * @param string                $key    Ключ уведомления.
	 * @param array<string,scalar>  $tokens Подстановки без фигурных скобок.
	 * @return string
	 */
	public static function get( $key, $tokens = array() ) {
		$all  = self::all();
		$text = isset( $all[ $key ] ) ? $all[ $key ] : ( isset( $all['error'] ) ? $all['error'] : '' );

		foreach ( $tokens as $name => $value ) {
			$text = str_replace( '{' . $name . '}', (string) $value, $text );
		}
		return $text;
	}

	/**
	 * Сохранение текстов.
	 *
	 * @param array<string,string> $input Присланные значения.
	 * @return void
	 */
	public static function save( $input ) {
		$out = array();
		foreach ( self::schema() as $key => $item ) {
			$value = isset( $input[ $key ] ) ? (string) wp_unslash( $input[ $key ] ) : $item['default'];
			// Уведомления показываются как текст, разметка в них не нужна.
			$out[ $key ] = trim( wp_strip_all_tags( $value ) );
		}
		self::$cache = null;
		update_option( self::OPTION, $out );
	}

	/**
	 * Тексты по умолчанию на указанном языке.
	 *
	 * @param string $locale Локаль.
	 * @return array<string,string>
	 */
	public static function defaults_in( $locale ) {
		$out = array();
		foreach ( self::schema() as $key => $item ) {
			$out[ $key ] = SF_FS_I18n::translate_in( $item['default'], $locale );
		}
		return $out;
	}

	/**
	 * Первичное заполнение при установке — на языке сайта.
	 *
	 * @return void
	 */
	public static function install_defaults() {
		$saved = get_option( self::OPTION, null );
		$saved = is_array( $saved ) ? $saved : array();
		$new   = array_merge( self::defaults_in( SF_FS_I18n::site_locale() ), $saved );

		self::$cache = null;
		update_option( self::OPTION, $new );
	}

	/** Сброс разобранных значений — нужен тестам. */
	public static function flush_cache() {
		self::$cache = null;
	}
}
