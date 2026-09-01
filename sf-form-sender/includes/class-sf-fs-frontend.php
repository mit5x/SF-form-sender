<?php
/**
 * Подключение скрипта перехвата к страницам сайта.
 *
 * Плагин ничего не знает о вёрстке темы и не переписывает разметку на сервере:
 * формы находит и дополняет скрипт в браузере. Поэтому перехват одинаково
 * работает и с формами из шаблона, и с формами, которые появились на странице
 * позже — например, в модальном окне.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Frontend {

	/** @var SF_FS_Frontend|null */
	private static $instance = null;

	/** @return SF_FS_Frontend */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Скрипт и стили перехвата.
	 *
	 * @return void
	 */
	public function enqueue() {
		wp_enqueue_style( 'sf-form-sender', SF_FS_URL . 'assets/css/front.css', array(), SF_FS_VERSION );

		wp_enqueue_script( 'sf-form-sender', SF_FS_URL . 'assets/js/front.js', array(), SF_FS_VERSION, true );
		wp_localize_script( 'sf-form-sender', 'SF_FORM_SENDER', $this->config() );
	}

	/**
	 * Всё, что скрипту нужно знать о настройках.
	 *
	 * @return array<string,mixed>
	 */
	public function config() {
		$markers = sf_fs_markers();
		$notices = SF_FS_Notices::all();

		return array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'action'    => SF_FS_Interceptor::ACTION,
			'ticket'    => SF_FS_Interceptor::make_ticket(),
			'selector'  => 'form[' . $markers['attr'] . '], form[' . $markers['attr_data'] . ']',
			'attrs'     => array( $markers['attr'], $markers['attr_data'] ),
			'classes'   => array(
				'fail'    => $markers['fail_class'],
				'success' => $markers['ok_class'],
				'captcha' => $markers['captcha_class'],
			),
			'honeypot'  => SF_FS_Settings::on( 'honeypot' ) ? (string) SF_FS_Settings::get( 'honeypot_name' ) : '',
			'trackGet'  => SF_FS_Settings::on( 'track_get' ),
			'cookie'    => SF_FS_Tracking::COOKIE,
			'seenCookie' => SF_FS_Tracking::COOKIE_SEEN,
			'maxValues' => SF_FS_Tracking::MAX_VALUES,
			'maxParams' => SF_FS_Tracking::MAX_PARAMS,
			'captcha'   => SF_FS_Captcha::front_config(),
			'captchaField' => SF_FS_Captcha::TOKEN_FIELD,
			'texts'     => array(
				'network' => $notices['error_network'],
				'error'   => $notices['error'],
				'sending' => $notices['sending'],
			),
		);
	}
}
