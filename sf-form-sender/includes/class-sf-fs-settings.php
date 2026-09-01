<?php
/**
 * Настройки плагина.
 *
 * Все поля описаны одной схемой: по ней рисуется страница настроек, по ней же
 * чистятся присланные значения и берутся значения по умолчанию. Добавить поле —
 * значит дописать одну строку в schema(), больше нигде ничего менять не нужно.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Settings {

	/** Имя опции со всеми настройками. */
	const OPTION = 'sf_fs_settings';

	/** @var array<string,mixed>|null Разобранные настройки. */
	private static $cache = null;

	/**
	 * Описание вкладок. Тексты переводятся при выводе, поэтому здесь только
	 * ключи и порядок.
	 *
	 * @return array<string,string>
	 */
	public static function tabs() {
		return array(
			'main'     => __( 'Основное', 'sf-form-sender' ),
			'antispam' => __( 'Антиспам', 'sf-form-sender' ),
			'smtp'     => __( 'SMTP', 'sf-form-sender' ),
			'phpmail'  => __( 'Phpmail', 'sf-form-sender' ),
			'notices'  => __( 'Уведомления', 'sf-form-sender' ),
			'docs'     => __( 'Документация', 'sf-form-sender' ),
		);
	}

	/**
	 * Схема полей.
	 *
	 * Ключи поля:
	 *   tab      — вкладка;
	 *   type     — text|password|email|number|checkbox|select|textarea;
	 *   default  — значение при установке;
	 *   label    — подпись;
	 *   help     — краткое описание, зачем поле нужно;
	 *   options  — варианты для select;
	 *   group    — заголовок группы полей (ставится перед первым полем группы);
	 *   depends  — поле показывается, только если указанный переключатель включён;
	 *   min/max/step — для number.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function schema() {
		return array(

			/* ------------------------------------------------------------ */
			/* Основное                                                      */
			/* ------------------------------------------------------------ */

			'mail_method'        => array(
				'tab'     => 'main',
				'type'    => 'select',
				'default' => 'phpmail',
				'label'   => __( 'Как отправлять содержимое форм на e-mail?', 'sf-form-sender' ),
				'help'    => __( 'phpmail — штатная почта сервера, ничего настраивать не нужно. SMTP — отправка через почтовый сервер с авторизацией, письма реже попадают в спам. «Не отправлять» — заявки только сохраняются в базу данных.', 'sf-form-sender' ),
				'options' => array(
					'none'    => __( 'Не отправлять', 'sf-form-sender' ),
					'smtp'    => __( 'SMTP', 'sf-form-sender' ),
					'phpmail' => __( 'phpmail', 'sf-form-sender' ),
				),
			),

			'mail_to'            => array(
				'tab'     => 'main',
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'Кому отправлять письма', 'sf-form-sender' ),
				'help'    => __( 'Один или несколько адресов через запятую. Если поле пустое, письма уходят на адрес администратора сайта из «Настройки → Общие».', 'sf-form-sender' ),
			),

			'mail_subject'       => array(
				'tab'     => 'main',
				'type'    => 'text',
				'default' => '',   // Проставляется при установке переводом.
				'label'   => __( 'Тема письма', 'sf-form-sender' ),
				'help'    => __( 'Допустимые подстановки: {site} — название сайта, {page} — заголовок страницы, {form} — номер или имя формы.', 'sf-form-sender' ),
			),

			'track_get'          => array(
				'tab'     => 'main',
				'type'    => 'checkbox',
				'default' => 1,
				'label'   => __( 'Записывать содержимое входящих GET параметров?', 'sf-form-sender' ),
				'help'    => __( 'Метки рекламных систем (utm_source и любые другие) из адреса страницы сохраняются в cookie посетителя бессрочно. Новые значения дописываются к уже сохранённым, а не заменяют их.', 'sf-form-sender' ),
			),

			'attach_get_to_mail' => array(
				'tab'     => 'main',
				'type'    => 'checkbox',
				'default' => 1,
				'depends' => 'track_get',
				'label'   => __( 'Прикреплять содержимое сохранённых GET параметров к отправляемому письму?', 'sf-form-sender' ),
				'help'    => __( 'В конце письма появится отдельный блок вида «имя_параметра: значение1, значение2». По нему сразу видно, что заявку оставил посетитель с рекламы, а не спам-робот.', 'sf-form-sender' ),
			),

			'save_to_db'         => array(
				'tab'     => 'main',
				'type'    => 'checkbox',
				'default' => 1,
				'label'   => __( 'Сохранять отправленные заявки в базу данных сайта?', 'sf-form-sender' ),
				'help'    => __( 'Заявки складываются в собственные таблицы плагина и доступны в разделе «Заявки». Письмо может не дойти, а запись в базе останется.', 'sf-form-sender' ),
			),

			'save_get_to_db'     => array(
				'tab'     => 'main',
				'type'    => 'checkbox',
				'default' => 1,
				'depends' => 'track_get',
				'label'   => __( 'Сохранять записанные GET параметры в базу данных сайта?', 'sf-form-sender' ),
				'help'    => __( 'Параметр с несколькими значениями сохраняется одной строкой через запятую.', 'sf-form-sender' ),
			),

			'save_files'         => array(
				'tab'     => 'main',
				'type'    => 'checkbox',
				'default' => 0,
				'group'   => __( 'Файлы, прикреплённые к формам', 'sf-form-sender' ),
				'label'   => __( 'Сохранять прикреплённые к формам файлы на сервер?', 'sf-form-sender' ),
				'help'    => __( 'Файлы кладутся в /uploads/sf-form-sender/номер-заявки/. Если выключено, файлы всё равно уходят вложением в письмо, но на сервере не остаются.', 'sf-form-sender' ),
			),

			'allowed_ext'        => array(
				'tab'     => 'main',
				'type'    => 'text',
				'default' => 'pdf,jpg,webp,png,zip,doc,docx,xls,xlsx,ppt,pptx',
				'label'   => __( 'Разрешённые расширения файлов, через запятую', 'sf-form-sender' ),
				'help'    => __( 'Проверка на стороне плагина. Исполняемые файлы (php, phtml, cgi, exe и подобные) плагин не примет, даже если добавить их в этот список. Кроме расширения сверяется и содержимое файла: если оно другого разрешённого типа, файл примется под верным именем, а неопознанное содержимое не пройдёт.', 'sf-form-sender' ),
			),

			'max_files_size'     => array(
				'tab'     => 'main',
				'type'    => 'number',
				'default' => 10,
				'min'     => 1,
				'max'     => 512,
				'label'   => __( 'Максимальный размер всех отправляемых файлов (Мегабайт)', 'sf-form-sender' ),
				'help'    => __( 'Считается сумма всех файлов одной заявки. Сервер может ограничивать загрузку жёстче — смотрите upload_max_filesize и post_max_size.', 'sf-form-sender' ),
			),

			'translit_files'     => array(
				'tab'     => 'main',
				'type'    => 'checkbox',
				'default' => 0,
				'label'   => __( 'Транслитерировать названия файлов?', 'sf-form-sender' ),
				'help'    => __( 'Имя «Договор.pdf» станет «dogovor.pdf». Полезно, если файлы потом скачиваются по прямой ссылке.', 'sf-form-sender' ),
			),

			/* ------------------------------------------------------------ */
			/* Антиспам                                                      */
			/* ------------------------------------------------------------ */

			'yandex_captcha'     => array(
				'tab'     => 'antispam',
				'type'    => 'checkbox',
				'default' => 0,
				'group'   => __( 'Yandex SmartCaptcha', 'sf-form-sender' ),
				'label'   => __( 'Использовать Yandex SmartCaptcha в формах?', 'sf-form-sender' ),
				'help'    => __( 'Капча выводится в контейнере sf_form_sender_interceptor_captcha. Если вебмастер такой контейнер не сделал, плагин добавит его сам над кнопкой отправки.', 'sf-form-sender' ),
			),

			'yandex_client_key'  => array(
				'tab'     => 'antispam',
				'type'    => 'text',
				'default' => '',
				'depends' => 'yandex_captcha',
				'label'   => __( 'Клиентский ключ Yandex SmartCaptcha', 'sf-form-sender' ),
				'help'    => __( 'Ключ сайта из консоли Yandex Cloud. Виден в исходном коде страницы — это нормально.', 'sf-form-sender' ),
			),

			'yandex_server_key'  => array(
				'tab'     => 'antispam',
				'type'    => 'password',
				'default' => '',
				'depends' => 'yandex_captcha',
				'label'   => __( 'Серверный ключ Yandex SmartCaptcha', 'sf-form-sender' ),
				'help'    => __( 'Секретный ключ той же капчи. Нужен для проверки ответа на сервере, никому не показывайте.', 'sf-form-sender' ),
			),

			'google_captcha'     => array(
				'tab'     => 'antispam',
				'type'    => 'checkbox',
				'default' => 0,
				'group'   => __( 'Google reCAPTCHA', 'sf-form-sender' ),
				'label'   => __( 'Использовать Google reCAPTCHA в формах?', 'sf-form-sender' ),
				'help'    => __( 'Одновременно можно включить только одну капчу. При включении этой Yandex SmartCaptcha будет выключена.', 'sf-form-sender' ),
			),

			'google_version'     => array(
				'tab'     => 'antispam',
				'type'    => 'select',
				'default' => 'v2',
				'depends' => 'google_captcha',
				'label'   => __( 'Версия reCAPTCHA', 'sf-form-sender' ),
				'help'    => __( 'v2 — знакомая галочка «Я не робот». v3 — незаметная проверка, которая выдаёт оценку от 0 до 1 и ничего не показывает посетителю.', 'sf-form-sender' ),
				'options' => array(
					'v2' => __( 'v2 — галочка «Я не робот»', 'sf-form-sender' ),
					'v3' => __( 'v3 — незаметная, по оценке', 'sf-form-sender' ),
				),
			),

			'google_site_key'    => array(
				'tab'     => 'antispam',
				'type'    => 'text',
				'default' => '',
				'depends' => 'google_captcha',
				'label'   => __( 'Ключ сайта reCAPTCHA', 'sf-form-sender' ),
				'help'    => __( 'Site key из консоли Google reCAPTCHA.', 'sf-form-sender' ),
			),

			'google_secret_key'  => array(
				'tab'     => 'antispam',
				'type'    => 'password',
				'default' => '',
				'depends' => 'google_captcha',
				'label'   => __( 'Секретный ключ reCAPTCHA', 'sf-form-sender' ),
				'help'    => __( 'Secret key той же пары ключей. Используется только на сервере.', 'sf-form-sender' ),
			),

			'google_score'       => array(
				'tab'     => 'antispam',
				'type'    => 'number',
				'default' => 0.5,
				'min'     => 0,
				'max'     => 1,
				'step'    => 0.1,
				'depends' => 'google_captcha',
				'label'   => __( 'Минимальная оценка для reCAPTCHA v3', 'sf-form-sender' ),
				'help'    => __( 'Заявки с оценкой ниже указанной считаются роботом. 0.5 — рекомендация Google, повышайте осторожно.', 'sf-form-sender' ),
			),

			'min_seconds'        => array(
				'tab'     => 'antispam',
				'type'    => 'number',
				'default' => 4,
				'min'     => 0,
				'max'     => 3600,
				'group'   => __( 'Простые ловушки', 'sf-form-sender' ),
				'label'   => __( 'Время в секундах, больше которого пользователя не считать ботом', 'sf-form-sender' ),
				'help'    => __( 'Если пользователь провёл на сайте меньше указанного числа секунд, содержимое формы не будет отправлено, но визуально отобразится как будто отправка состоялась. 0 — проверка выключена.', 'sf-form-sender' ),
			),

			'honeypot'           => array(
				'tab'     => 'antispam',
				'type'    => 'checkbox',
				'default' => 1,
				'label'   => __( 'Добавить пустое скрытое поле приманку?', 'sf-form-sender' ),
				'help'    => __( 'Скрытое input поле с именем website (имя можно поменять ниже). Для обычных пользователей поле скрыто, а роботы его видят. Если поле оказывается не пустым, содержимое формы не будет отправлено, но визуально отобразится как будто отправка состоялась.', 'sf-form-sender' ),
			),

			'honeypot_name'      => array(
				'tab'     => 'antispam',
				'type'    => 'text',
				'default' => 'website',
				'depends' => 'honeypot',
				'label'   => __( 'Имя поля приманки', 'sf-form-sender' ),
				'help'    => __( 'Роботы охотнее заполняют поля с узнаваемыми именами: website, url, company.', 'sf-form-sender' ),
			),

			/* ------------------------------------------------------------ */
			/* SMTP                                                          */
			/* ------------------------------------------------------------ */

			'smtp_host'          => array(
				'tab'     => 'smtp',
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'SMTP Host', 'sf-form-sender' ),
				'help'    => __( 'Адрес почтового сервера, например smtp.yandex.ru.', 'sf-form-sender' ),
			),

			'smtp_port'          => array(
				'tab'     => 'smtp',
				'type'    => 'number',
				'default' => 465,
				'min'     => 1,
				'max'     => 65535,
				'label'   => __( 'SMTP Port', 'sf-form-sender' ),
				'help'    => __( 'Обычно 465 для SSL и 587 для TLS.', 'sf-form-sender' ),
			),

			'smtp_user'          => array(
				'tab'     => 'smtp',
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'SMTP Username', 'sf-form-sender' ),
				'help'    => __( 'Чаще всего совпадает с почтовым ящиком, от имени которого уходят письма.', 'sf-form-sender' ),
			),

			'smtp_pass'          => array(
				'tab'     => 'smtp',
				'type'    => 'password',
				'default' => '',
				'label'   => __( 'SMTP Password', 'sf-form-sender' ),
				'help'    => __( 'Пароль хранится в таблице настроек WordPress в открытом виде — так устроены все плагины отправки почты. Заводите отдельный пароль приложения, если почтовая служба это умеет.', 'sf-form-sender' ),
			),

			'smtp_timeout'       => array(
				'tab'     => 'smtp',
				'type'    => 'number',
				'default' => 10,
				'min'     => 1,
				'max'     => 300,
				'label'   => __( 'Предельное время ожидания ответа SMTP сервера (секунд)', 'sf-form-sender' ),
				'help'    => __( 'Если сервер не ответил за это время, отправка прерывается и посетитель не ждёт зря.', 'sf-form-sender' ),
			),

			'smtp_secure'        => array(
				'tab'     => 'smtp',
				'type'    => 'select',
				'default' => 'ssl',
				'label'   => __( 'Шифрование SMTP', 'sf-form-sender' ),
				'help'    => __( 'SSL — соединение шифруется сразу (порт 465). TLS — шифрование включается командой STARTTLS (порт 587).', 'sf-form-sender' ),
				'options' => array(
					'none' => __( 'Нет', 'sf-form-sender' ),
					'ssl'  => __( 'SSL', 'sf-form-sender' ),
					'tls'  => __( 'TLS', 'sf-form-sender' ),
				),
			),

			/* ------------------------------------------------------------ */
			/* Phpmail                                                       */
			/* ------------------------------------------------------------ */

			'phpmail_sender'     => array(
				'tab'     => 'phpmail',
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'PHPmail Sender', 'sf-form-sender' ),
				'help'    => __( 'Адрес в конверте письма (Return-Path), на него уходят отчёты о недоставке.', 'sf-form-sender' ),
			),

			'phpmail_hostname'   => array(
				'tab'     => 'phpmail',
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'PHPmail Hostname', 'sf-form-sender' ),
				'help'    => __( 'Имя, которым сервер представляется в команде HELO. По умолчанию берётся из имени сайта.', 'sf-form-sender' ),
			),

			'phpmail_reply_to'   => array(
				'tab'     => 'phpmail',
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'PHPmail Reply-To', 'sf-form-sender' ),
				'help'    => __( 'Адрес, который подставится в ответ на письмо. Если пусто, плагин подставит e-mail отправителя формы, когда он в ней есть.', 'sf-form-sender' ),
			),

			'phpmail_from_name'  => array(
				'tab'     => 'phpmail',
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'PHPmail From Name', 'sf-form-sender' ),
				'help'    => __( 'Отображаемое имя отправителя письма.', 'sf-form-sender' ),
			),

			'phpmail_from'       => array(
				'tab'     => 'phpmail',
				'type'    => 'text',
				'default' => '',
				'label'   => __( 'PHPmail From', 'sf-form-sender' ),
				'help'    => __( 'Адрес отправителя. Должен быть на домене сайта, иначе письма попадут в спам.', 'sf-form-sender' ),
			),

			'dkim_domain'        => array(
				'tab'     => 'phpmail',
				'type'    => 'text',
				'default' => '',
				'group'   => __( 'Подпись DKIM', 'sf-form-sender' ),
				'label'   => __( 'DKIM_domain', 'sf-form-sender' ),
				'help'    => __( 'Домен, для которого выпущена подпись, например example.com.', 'sf-form-sender' ),
			),

			'dkim_selector'      => array(
				'tab'     => 'phpmail',
				'type'    => 'text',
				'default' => 'mail',
				'label'   => __( 'DKIM_selector', 'sf-form-sender' ),
				'help'    => __( 'Селектор из DNS-записи вида selector._domainkey.example.com.', 'sf-form-sender' ),
			),

			'dkim_private_string' => array(
				'tab'     => 'phpmail',
				'type'    => 'textarea',
				'default' => '',
				'label'   => __( 'DKIM_private_string', 'sf-form-sender' ),
				'help'    => __( 'Закрытый ключ целиком, вместе со строками BEGIN и END.', 'sf-form-sender' ),
			),
		);
	}

	/**
	 * Значения по умолчанию.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		$out = array();
		foreach ( self::schema() as $key => $field ) {
			$out[ $key ] = $field['default'];
		}
		return $out;
	}

	/**
	 * Все настройки.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * Одна настройка.
	 *
	 * @param string $key     Ключ.
	 * @param mixed  $default Значение, если ключа нет вообще.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Настройка как переключатель.
	 *
	 * @param string $key Ключ.
	 * @return bool
	 */
	public static function on( $key ) {
		return (bool) self::get( $key, 0 );
	}

	/**
	 * Сохранение настроек одной вкладки.
	 *
	 * Поля чужих вкладок не трогаем: страница присылает только свои, и без
	 * этого сохранение вкладки SMTP обнуляло бы всё остальное.
	 *
	 * @param string              $tab   Вкладка.
	 * @param array<string,mixed> $input Присланные значения.
	 * @return void
	 */
	public static function save_tab( $tab, $input ) {
		$schema  = self::schema();
		$current = self::all();

		foreach ( $schema as $key => $field ) {
			if ( $field['tab'] !== $tab ) {
				continue;
			}
			// Снятая галочка не приходит в POST вовсе — это не «нет значения»,
			// а именно ноль.
			if ( 'checkbox' === $field['type'] ) {
				$current[ $key ] = isset( $input[ $key ] ) ? 1 : 0;
				continue;
			}
			// Массив вместо строки — либо подделанный запрос, либо ошибка в
			// разметке страницы настроек; в обоих случаях значение не наше.
			if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[ $key ] ) ) {
				continue;
			}
			$current[ $key ] = self::sanitize_value( $field, $input[ $key ] );
		}

		$current = self::cross_check( $current, $tab );

		self::$cache = $current;
		update_option( self::OPTION, $current );
	}

	/**
	 * Правила, которые нельзя проверить по одному полю.
	 *
	 * @param array<string,mixed> $values Значения.
	 * @param string              $tab    Сохраняемая вкладка.
	 * @return array<string,mixed>
	 */
	private static function cross_check( $values, $tab ) {
		if ( 'antispam' === $tab && $values['yandex_captcha'] && $values['google_captcha'] ) {
			// Обе капчи в одном контейнере не уживаются. Оставляем ту, которую
			// включили этим сохранением: страница присылает состояние обеих.
			$values['yandex_captcha'] = 0;
		}
		return $values;
	}

	/**
	 * Чистка одного значения по описанию поля.
	 *
	 * @param array<string,mixed> $field Описание.
	 * @param mixed               $value Значение.
	 * @return mixed
	 */
	private static function sanitize_value( $field, $value ) {
		switch ( $field['type'] ) {
			case 'number':
				$value = is_numeric( $value ) ? $value + 0 : $field['default'];
				if ( isset( $field['min'] ) && $value < $field['min'] ) {
					$value = $field['min'];
				}
				if ( isset( $field['max'] ) && $value > $field['max'] ) {
					$value = $field['max'];
				}
				return isset( $field['step'] ) ? (float) $value : (int) $value;

			case 'select':
				$value = (string) $value;
				return isset( $field['options'][ $value ] ) ? $value : $field['default'];

			case 'textarea':
				return trim( (string) wp_unslash( $value ) );

			case 'checkbox':
				return $value ? 1 : 0;

			default:
				return sanitize_text_field( wp_unslash( (string) $value ) );
		}
	}

	/**
	 * Первичное заполнение опции при активации.
	 *
	 * Существующие значения не перезаписываются — плагин можно выключать и
	 * включать, не теряя настройки.
	 *
	 * @return void
	 */
	public static function install_defaults() {
		$saved = get_option( self::OPTION, null );
		$saved = is_array( $saved ) ? $saved : array();
		$new   = array_merge( self::defaults(), $saved );

		if ( '' === (string) $new['mail_subject'] ) {
			// Тема письма на языке сайта: в момент установки интерфейс плагина
			// ещё не переключён, а язык сайта уже известен.
			$new['mail_subject'] = SF_FS_I18n::translate_in( 'Заявка с сайта: {site}', SF_FS_I18n::site_locale() );
		}

		self::$cache = $new;
		update_option( self::OPTION, $new );
	}

	/**
	 * Список адресов получателей письма.
	 *
	 * @return array<int,string>
	 */
	public static function recipients() {
		$raw = trim( (string) self::get( 'mail_to' ) );
		if ( '' === $raw ) {
			return array( (string) get_option( 'admin_email' ) );
		}
		$out = array();
		foreach ( explode( ',', $raw ) as $item ) {
			$item = trim( $item );
			if ( is_email( $item ) ) {
				$out[] = $item;
			}
		}
		return $out ? $out : array( (string) get_option( 'admin_email' ) );
	}

	/**
	 * Разрешённые расширения файлов, уже без явно опасных.
	 *
	 * @return array<int,string>
	 */
	public static function allowed_extensions() {
		$raw = strtolower( (string) self::get( 'allowed_ext' ) );
		$out = array();
		foreach ( preg_split( '/[\s,]+/', $raw ) as $ext ) {
			$ext = ltrim( trim( $ext ), '.' );
			if ( '' === $ext || ! preg_match( '/^[a-z0-9]{1,10}$/', $ext ) ) {
				continue;
			}
			if ( in_array( $ext, SF_FS_Uploads::forbidden_extensions(), true ) ) {
				continue;
			}
			$out[ $ext ] = $ext;
		}
		return array_values( $out );
	}

	/** Сброс разобранных значений — нужен тестам. */
	public static function flush_cache() {
		self::$cache = null;
	}
}
