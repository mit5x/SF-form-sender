<?php
/**
 * Капча: Yandex SmartCaptcha или Google reCAPTCHA.
 *
 * Плагин отдаёт браузеру только то, что нужно для показа виджета, а ответ
 * посетителя всегда перепроверяет на сервере: без серверной проверки капча
 * обходится одной строкой в консоли браузера.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Captcha {

	/** Поле, в котором браузер присылает ответ капчи. */
	const TOKEN_FIELD = 'sf_fs_captcha';

	/**
	 * Какая капча включена.
	 *
	 * @return string yandex|google|none
	 */
	public static function provider() {
		if ( SF_FS_Settings::on( 'yandex_captcha' ) && '' !== trim( (string) SF_FS_Settings::get( 'yandex_client_key' ) ) ) {
			return 'yandex';
		}
		if ( SF_FS_Settings::on( 'google_captcha' ) && '' !== trim( (string) SF_FS_Settings::get( 'google_site_key' ) ) ) {
			return 'google';
		}
		return 'none';
	}

	/**
	 * Настройки капчи для скрипта на сайте.
	 *
	 * @return array<string,mixed>
	 */
	public static function front_config() {
		$provider = self::provider();

		if ( 'yandex' === $provider ) {
			return array(
				'provider' => 'yandex',
				'sitekey'  => (string) SF_FS_Settings::get( 'yandex_client_key' ),
				'script'   => 'https://smartcaptcha.yandexcloud.net/captcha.js?render=onload&onload=sfFsCaptchaReady',
				'lang'     => self::yandex_lang(),
			);
		}

		if ( 'google' === $provider ) {
			$version = 'v3' === SF_FS_Settings::get( 'google_version' ) ? 'v3' : 'v2';
			$sitekey = (string) SF_FS_Settings::get( 'google_site_key' );
			return array(
				'provider' => 'google',
				'version'  => $version,
				'sitekey'  => $sitekey,
				'script'   => 'v3' === $version
					? 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $sitekey )
					: 'https://www.google.com/recaptcha/api.js?render=explicit&onload=sfFsCaptchaReady',
			);
		}

		return array( 'provider' => 'none' );
	}

	/**
	 * Язык виджета Yandex SmartCaptcha.
	 *
	 * Сервис понимает ограниченный набор языков; для остальных оставляем
	 * английский.
	 *
	 * @return string
	 */
	private static function yandex_lang() {
		$short = strtolower( substr( SF_FS_I18n::site_locale(), 0, 2 ) );
		$known = array( 'ru', 'en', 'be', 'kk', 'tt', 'uk', 'uz', 'tr' );
		return in_array( $short, $known, true ) ? $short : 'en';
	}

	/**
	 * Проверка ответа капчи.
	 *
	 * @param string $token Ответ из формы.
	 * @param string $ip    Адрес посетителя.
	 * @return bool
	 */
	public static function verify( $token, $ip ) {
		$provider = self::provider();
		if ( 'none' === $provider ) {
			return true;
		}
		if ( '' === trim( (string) $token ) ) {
			return false;
		}

		return 'yandex' === $provider
			? self::verify_yandex( $token, $ip )
			: self::verify_google( $token, $ip );
	}

	/**
	 * Проверка Yandex SmartCaptcha.
	 *
	 * @param string $token Ответ.
	 * @param string $ip    Адрес.
	 * @return bool
	 */
	private static function verify_yandex( $token, $ip ) {
		$response = wp_remote_get(
			add_query_arg(
				array(
					'secret' => (string) SF_FS_Settings::get( 'yandex_server_key' ),
					'token'  => $token,
					'ip'     => $ip,
				),
				'https://smartcaptcha.yandexcloud.net/validate'
			),
			array( 'timeout' => 5 )
		);

		if ( is_wp_error( $response ) ) {
			// Сервис не ответил. Разворачивать живого посетителя из-за чужой
			// недоступности хуже, чем пропустить одну заявку.
			return true;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && isset( $body['status'] ) && 'ok' === $body['status'];
	}

	/**
	 * Проверка Google reCAPTCHA.
	 *
	 * @param string $token Ответ.
	 * @param string $ip    Адрес.
	 * @return bool
	 */
	private static function verify_google( $token, $ip ) {
		$response = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 5,
				'body'    => array(
					'secret'   => (string) SF_FS_Settings::get( 'google_secret_key' ),
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return true;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return false;
		}

		if ( 'v3' === SF_FS_Settings::get( 'google_version' ) ) {
			$score = isset( $body['score'] ) ? (float) $body['score'] : 0.0;
			return $score >= (float) SF_FS_Settings::get( 'google_score' );
		}

		return true;
	}
}
