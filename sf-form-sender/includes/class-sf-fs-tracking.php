<?php
/**
 * Рекламные метки из адреса страницы.
 *
 * Записывает их скрипт на стороне браузера — так метки переживают кеширование
 * страниц, при котором PHP до посетителя вообще не доходит. Здесь только
 * чтение и приведение к виду, годному для письма и базы данных.
 *
 * Значения накапливаются: посетитель мог прийти сначала с одной рекламы, потом
 * с другой, и в заявке должны быть видны обе.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Tracking {

	/** Cookie с накопленными метками. */
	const COOKIE = 'sf_fs_track';

	/** Cookie с моментом первого захода на сайт. */
	const COOKIE_SEEN = 'sf_fs_seen';

	/** Сколько значений храним у одного параметра. */
	const MAX_VALUES = 20;

	/** Сколько параметров храним всего. */
	const MAX_PARAMS = 40;

	/**
	 * Разобранные метки.
	 *
	 * @param string|null $raw Содержимое cookie; null — взять из запроса.
	 * @return array<string,array<int,string>>
	 */
	public static function params( $raw = null ) {
		if ( null === $raw ) {
			$raw = isset( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		}
		$raw = (string) $raw;
		if ( '' === $raw ) {
			return array();
		}

		$data = json_decode( rawurldecode( $raw ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$out = array();
		foreach ( $data as $name => $values ) {
			$name = self::clean_name( (string) $name );
			if ( '' === $name ) {
				continue;
			}

			$list = array();
			foreach ( (array) $values as $value ) {
				if ( is_scalar( $value ) ) {
					$value = trim( sanitize_text_field( (string) $value ) );
					if ( '' !== $value && ! in_array( $value, $list, true ) ) {
						$list[] = mb_substr( $value, 0, 255 );
					}
				}
				if ( count( $list ) >= self::MAX_VALUES ) {
					break;
				}
			}

			if ( $list ) {
				$out[ $name ] = $list;
			}
			if ( count( $out ) >= self::MAX_PARAMS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Имя параметра, пригодное для показа и хранения.
	 *
	 * @param string $name Имя.
	 * @return string
	 */
	public static function clean_name( $name ) {
		$name = preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $name );
		return mb_substr( (string) $name, 0, 64 );
	}

	/**
	 * Метки блоком для письма.
	 *
	 * @param array<string,array<int,string>> $params Метки.
	 * @return string
	 */
	public static function as_text( $params ) {
		$lines = array();
		foreach ( $params as $name => $values ) {
			$lines[] = $name . ': ' . implode( ', ', $values );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Метки в виде полей заявки.
	 *
	 * Имена получают приставку sf_get_, чтобы метка utm_source не смешалась
	 * с полем формы с тем же именем.
	 *
	 * @param array<string,array<int,string>> $params Метки.
	 * @return array<string,string>
	 */
	public static function as_fields( $params ) {
		$out = array();
		foreach ( $params as $name => $values ) {
			$out[ SF_FS_Db::GET_PREFIX . $name ] = implode( ', ', $values );
		}
		return $out;
	}

	/**
	 * Подписи для полей меток в админке.
	 *
	 * @param array<string,array<int,string>> $params Метки.
	 * @return array<string,string>
	 */
	public static function labels( $params ) {
		$out = array();
		foreach ( array_keys( $params ) as $name ) {
			$out[ SF_FS_Db::GET_PREFIX . $name ] = 'GET: ' . $name;
		}
		return $out;
	}
}
