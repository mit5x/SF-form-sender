<?php
/**
 * Язык интерфейса плагина.
 *
 * Исходные строки написаны по-русски, поэтому ru_RU обходится без словаря —
 * он и есть исходник. Для остальных языков в languages/ лежат .po и собранные
 * из них .mo.
 *
 * Язык интерфейса плагина не привязан к языку админки WordPress: он
 * переключается в правом верхнем углу страницы настроек и по умолчанию равен
 * языку сайта (WPLANG).
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_I18n {

	const OPTION = 'sf_fs_admin_locale';

	/** @var SF_FS_I18n|null */
	private static $instance = null;

	/** @var array<string,bool> Уже подгруженные словари: локаль => да. */
	private static $loaded = array();

	/**
	 * Языки, на которые переведён интерфейс.
	 *
	 * Названия намеренно на самих языках — так их узнают независимо от того,
	 * на каком языке сейчас админка.
	 *
	 * @return array<string,string>
	 */
	public static function languages() {
		return array(
			'ru_RU' => 'Русский',
			'en_US' => 'English',
			'de_DE' => 'Deutsch',
			'es_ES' => 'Español',
			'fr_FR' => 'Français',
			'it_IT' => 'Italiano',
			'pt_BR' => 'Português (Brasil)',
			'zh_CN' => '中文（简体）',
		);
	}

	/** @return SF_FS_I18n */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load' ), 1 );
		add_filter( 'plugin_locale', array( $this, 'filter_locale' ), 10, 2 );
	}

	/**
	 * Подключение словаря.
	 *
	 * @return void
	 */
	public function load() {
		load_plugin_textdomain( 'sf-form-sender', false, dirname( SF_FS_BASENAME ) . '/languages' );
	}

	/**
	 * Язык словаря плагина.
	 *
	 * На страницах админки — выбранный администратором. Всё остальное, включая
	 * приём формы через admin-ajax, говорит на языке сайта: подписи в письме
	 * читает получатель заявки, и язык, выбранный кем-то для своей админки,
	 * тут ни при чём.
	 *
	 * @param string $locale Текущая локаль.
	 * @param string $domain Текстовый домен.
	 * @return string
	 */
	public function filter_locale( $locale, $domain ) {
		if ( 'sf-form-sender' !== $domain ) {
			return $locale;
		}

		$in_admin = is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() );

		return $in_admin ? self::admin_locale() : self::site_locale();
	}

	/**
	 * Язык сайта из WPLANG.
	 *
	 * @return string
	 */
	public static function site_locale() {
		$locale = (string) get_option( 'WPLANG' );
		if ( '' === $locale ) {
			$locale = defined( 'WPLANG' ) && WPLANG ? (string) WPLANG : 'en_US';
		}
		return self::normalize( $locale );
	}

	/**
	 * Язык интерфейса плагина в админке.
	 *
	 * @return string
	 */
	public static function admin_locale() {
		$saved = (string) get_option( self::OPTION, '' );
		return '' !== $saved ? self::normalize( $saved ) : self::site_locale();
	}

	/**
	 * Сохранение выбранного языка интерфейса.
	 *
	 * @param string $locale Локаль.
	 * @return void
	 */
	public static function set_admin_locale( $locale ) {
		update_option( self::OPTION, self::normalize( $locale ) );
	}

	/**
	 * Ближайший поддерживаемый язык.
	 *
	 * ru, ru_UA и любые другие варианты сводятся к ru_RU; неизвестное —
	 * к английскому.
	 *
	 * @param string $locale Локаль.
	 * @return string
	 */
	public static function normalize( $locale ) {
		$locale    = str_replace( '-', '_', trim( (string) $locale ) );
		$languages = self::languages();

		if ( isset( $languages[ $locale ] ) ) {
			return $locale;
		}

		$short = strtolower( substr( $locale, 0, 2 ) );
		foreach ( array_keys( $languages ) as $known ) {
			if ( strtolower( substr( $known, 0, 2 ) ) === $short ) {
				return $known;
			}
		}
		return 'en_US';
	}

	/**
	 * Перевод строки на конкретный язык, не меняя язык интерфейса.
	 *
	 * Нужен при установке: тексты уведомлений записываются на языке сайта,
	 * даже если админка сейчас говорит на другом.
	 *
	 * @param string $text   Исходная строка (по-русски).
	 * @param string $locale Локаль.
	 * @return string
	 */
	public static function translate_in( $text, $locale ) {
		$locale = self::normalize( $locale );
		if ( 'ru_RU' === $locale ) {
			return $text;   // Исходные строки и так русские.
		}

		$domain = 'sf-form-sender-' . $locale;
		if ( ! isset( self::$loaded[ $locale ] ) ) {
			$mofile = SF_FS_DIR . 'languages/sf-form-sender-' . $locale . '.mo';
			if ( file_exists( $mofile ) ) {
				load_textdomain( $domain, $mofile );
			}
			self::$loaded[ $locale ] = true;
		}
		return translate( $text, $domain );
	}
}
