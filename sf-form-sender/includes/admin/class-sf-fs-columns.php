<?php
/**
 * Колонки списка заявок.
 *
 * Имена полей приходят из форм, и заранее их не знает никто. Поэтому список
 * колонок собирается из двух частей: постоянные колонки самого плагина и
 * поля, которые он однажды встретил в формах.
 *
 * Порядок, видимость и ширина хранятся одной опцией, подписи полей — в
 * таблице полей: подпись относится к полю, а не к таблице, и переживает
 * переустановку.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Columns {

	const OPTION = 'sf_fs_columns';

	/** Уже меньше — в колонку не влезет даже кнопка. */
	const MIN_WIDTH = 40;

	/** Больше — таблица превращается в один бесконечный столбец. */
	const MAX_WIDTH = 1200;

	/** Ширина колонки поля формы, пока её не потянули мышью. */
	const DEFAULT_WIDTH = 180;

	/**
	 * Постоянные колонки плагина.
	 *
	 * fixed — колонку нельзя убрать из таблицы, можно только переставить.
	 * width — ширина, пока администратор не потянул границу мышью.
	 *
	 * @return array<string,array{label:string,fixed:bool,default:bool,width:int}>
	 */
	public static function builtin() {
		return array(
			'_id'     => array(
				'label'   => __( 'Номер', 'sf-form-sender' ),
				'fixed'   => true,
				'default' => true,
				'width'   => 80,
			),
			'_date'   => array(
				'label'   => __( 'Дата и время', 'sf-form-sender' ),
				'fixed'   => true,
				'default' => true,
				'width'   => 150,
			),
			'_ip'     => array(
				'label'   => __( 'IP отправителя', 'sf-form-sender' ),
				'fixed'   => true,
				'default' => true,
				'width'   => 130,
			),
			'_files'  => array(
				'label'   => __( 'Файлов', 'sf-form-sender' ),
				'fixed'   => true,
				'default' => true,
				'width'   => 90,
			),
			'_view'   => array(
				'label'   => __( 'Посмотреть', 'sf-form-sender' ),
				'fixed'   => true,
				'default' => true,
				'width'   => 120,
			),
			'_delete' => array(
				'label'   => __( 'Удалить', 'sf-form-sender' ),
				'fixed'   => true,
				'default' => true,
				'width'   => 110,
			),
			'_url'    => array(
				'label'   => __( 'Страница', 'sf-form-sender' ),
				'fixed'   => false,
				'default' => false,
				'width'   => 240,
			),
			'_form'   => array(
				'label'   => __( 'Форма', 'sf-form-sender' ),
				'fixed'   => false,
				'default' => false,
				'width'   => 150,
			),
		);
	}

	/**
	 * Сохранённые порядок, видимость и ширина.
	 *
	 * @return array{order:array<int,string>,visible:array<int,string>,widths:array<string,int>}
	 */
	private static function saved() {
		$saved = get_option( self::OPTION, array() );
		return array(
			'order'   => isset( $saved['order'] ) && is_array( $saved['order'] ) ? $saved['order'] : array(),
			'visible' => isset( $saved['visible'] ) && is_array( $saved['visible'] ) ? $saved['visible'] : array(),
			'widths'  => isset( $saved['widths'] ) && is_array( $saved['widths'] ) ? self::clean_widths( $saved['widths'] ) : array(),
		);
	}

	/**
	 * Ширины к целым пикселям в разумных пределах.
	 *
	 * Ширина приходит из браузера, поэтому доверять ей нельзя: ноль означает
	 * «вернуть колонке ширину по умолчанию» и в хранилище не попадает.
	 *
	 * @param array<string,mixed> $widths Что прислали.
	 * @return array<string,int>
	 */
	public static function clean_widths( $widths ) {
		$out = array();
		foreach ( $widths as $key => $width ) {
			$key   = (string) $key;
			$width = (int) $width;
			if ( '' === $key || $width <= 0 ) {
				continue;
			}
			$out[ $key ] = max( self::MIN_WIDTH, min( self::MAX_WIDTH, $width ) );
		}
		return $out;
	}

	/**
	 * Все колонки в нужном порядке.
	 *
	 * Новые поля, которых ещё не было при последнем сохранении, добавляются в
	 * конец и по умолчанию не показываются: иначе одна форма с десятком
	 * технических полей разом развалила бы таблицу.
	 *
	 * @return array<int,array{key:string,label:string,fixed:bool,visible:bool,field_id:int,width:int,default_width:int}>
	 */
	public static function all() {
		$saved   = self::saved();
		$builtin = self::builtin();
		$fields  = SF_FS_Db::tables_ready() ? SF_FS_Db::fields() : array();
		$known   = array();

		foreach ( $builtin as $key => $item ) {
			$known[ $key ] = array(
				'key'      => $key,
				'label'    => $item['label'],
				'fixed'    => $item['fixed'],
				'default'  => $item['default'],
				'field_id' => 0,
				'width'    => $item['width'],
			);
		}

		foreach ( $fields as $key => $field ) {
			$known[ $key ] = array(
				'key'      => $key,
				'label'    => '' !== $field['label'] ? $field['label'] : $key,
				'fixed'    => false,
				'default'  => false,
				'field_id' => $field['id'],
				'width'    => self::DEFAULT_WIDTH,
			);
		}

		// Сначала то, что администратор расставил сам, потом всё новое.
		$ordered = array();
		foreach ( $saved['order'] as $key ) {
			if ( isset( $known[ $key ] ) ) {
				$ordered[ $key ] = $known[ $key ];
				unset( $known[ $key ] );
			}
		}
		foreach ( $known as $key => $item ) {
			$ordered[ $key ] = $item;
		}

		$configured = ! empty( $saved['order'] );

		$out = array();
		foreach ( $ordered as $key => $item ) {
			$visible = $item['fixed'];
			if ( ! $visible ) {
				if ( in_array( $key, $saved['visible'], true ) ) {
					$visible = true;
				} elseif ( ! $configured ) {
					$visible = $item['default'];
				}
			}

			$out[] = array(
				'key'           => $key,
				'label'         => $item['label'],
				'fixed'         => $item['fixed'],
				'visible'       => $visible,
				'field_id'      => $item['field_id'],
				'width'         => isset( $saved['widths'][ $key ] ) ? $saved['widths'][ $key ] : $item['width'],
				'default_width' => $item['width'],
			);
		}

		return $out;
	}

	/**
	 * Только показываемые колонки.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function visible() {
		return array_values(
			array_filter(
				self::all(),
				function ( $column ) {
					return $column['visible'];
				}
			)
		);
	}

	/**
	 * Сохранение настройки колонок.
	 *
	 * Ширины лежат в той же опции, но правятся мышью прямо в таблице, а не
	 * этой формой, поэтому по умолчанию она их не трогает.
	 *
	 * @param array<int,string>       $order   Ключи в нужном порядке.
	 * @param array<int,string>       $visible Ключи показываемых колонок.
	 * @param array<string,int>|null  $widths  Ширины, либо null — оставить прежние.
	 * @return void
	 */
	public static function save( $order, $visible, $widths = null ) {
		$saved = self::saved();

		update_option(
			self::OPTION,
			array(
				'order'   => array_values( array_map( 'strval', $order ) ),
				'visible' => array_values( array_map( 'strval', $visible ) ),
				'widths'  => null === $widths ? $saved['widths'] : self::clean_widths( $widths ),
			)
		);
	}

	/**
	 * Сохранение одних только ширин.
	 *
	 * Приходит вся таблица ширин целиком: колонка, которую вернули к ширине
	 * по умолчанию, приходит нулём и из хранилища пропадает.
	 *
	 * @param array<string,mixed> $widths Ключ колонки → ширина в пикселях.
	 * @return array<string,int> Что в итоге сохранено.
	 */
	public static function save_widths( $widths ) {
		$saved = self::saved();
		$clean = self::clean_widths( $widths );

		update_option(
			self::OPTION,
			array(
				'order'   => $saved['order'],
				'visible' => $saved['visible'],
				'widths'  => $clean,
			)
		);

		return $clean;
	}

	/**
	 * Ширины показываемых колонок.
	 *
	 * @return array<string,int>
	 */
	public static function widths() {
		$out = array();
		foreach ( self::all() as $column ) {
			$out[ $column['key'] ] = $column['width'];
		}
		return $out;
	}
}
