<?php
/**
 * Колонки списка заявок.
 *
 * Имена полей приходят из форм, и заранее их не знает никто. Поэтому список
 * колонок собирается из двух частей: постоянные колонки самого плагина и
 * поля, которые он однажды встретил в формах.
 *
 * Порядок и видимость хранятся одной опцией, подписи полей — в таблице полей:
 * подпись относится к полю, а не к таблице, и переживает переустановку.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Columns {

	const OPTION = 'sf_fs_columns';

	/**
	 * Постоянные колонки плагина.
	 *
	 * fixed — колонку нельзя убрать из таблицы, можно только переставить.
	 *
	 * @return array<string,array{label:string,fixed:bool,default:bool}>
	 */
	public static function builtin() {
		return array(
			'_id'     => array(
				'label'   => __( 'Номер', 'sf-form-sender' ),
				'fixed'   => true,
				'default' => true,
			),
			'_date'   => array(
				'label'   => __( 'Дата и время', 'sf-form-sender' ),
				'fixed'   => true,
				'default' => true,
			),
			'_ip'     => array(
				'label'   => __( 'IP отправителя', 'sf-form-sender' ),
				'fixed'   => true,
				'default' => true,
			),
			'_files'  => array(
				'label'   => __( 'Файлов', 'sf-form-sender' ),
				'fixed'   => true,
				'default' => true,
			),
			'_view'   => array(
				'label'   => __( 'Посмотреть', 'sf-form-sender' ),
				'fixed'   => true,
				'default' => true,
			),
			'_delete' => array(
				'label'   => __( 'Удалить', 'sf-form-sender' ),
				'fixed'   => true,
				'default' => true,
			),
			'_url'    => array(
				'label'   => __( 'Страница', 'sf-form-sender' ),
				'fixed'   => false,
				'default' => false,
			),
			'_form'   => array(
				'label'   => __( 'Форма', 'sf-form-sender' ),
				'fixed'   => false,
				'default' => false,
			),
		);
	}

	/**
	 * Сохранённые порядок и видимость.
	 *
	 * @return array{order:array<int,string>,visible:array<int,string>}
	 */
	private static function saved() {
		$saved = get_option( self::OPTION, array() );
		return array(
			'order'   => isset( $saved['order'] ) && is_array( $saved['order'] ) ? $saved['order'] : array(),
			'visible' => isset( $saved['visible'] ) && is_array( $saved['visible'] ) ? $saved['visible'] : array(),
		);
	}

	/**
	 * Все колонки в нужном порядке.
	 *
	 * Новые поля, которых ещё не было при последнем сохранении, добавляются в
	 * конец и по умолчанию не показываются: иначе одна форма с десятком
	 * технических полей разом развалила бы таблицу.
	 *
	 * @return array<int,array{key:string,label:string,fixed:bool,visible:bool,field_id:int}>
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
			);
		}

		foreach ( $fields as $key => $field ) {
			$known[ $key ] = array(
				'key'      => $key,
				'label'    => '' !== $field['label'] ? $field['label'] : $key,
				'fixed'    => false,
				'default'  => false,
				'field_id' => $field['id'],
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
				'key'      => $key,
				'label'    => $item['label'],
				'fixed'    => $item['fixed'],
				'visible'  => $visible,
				'field_id' => $item['field_id'],
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
	 * @param array<int,string> $order   Ключи в нужном порядке.
	 * @param array<int,string> $visible Ключи показываемых колонок.
	 * @return void
	 */
	public static function save( $order, $visible ) {
		update_option(
			self::OPTION,
			array(
				'order'   => array_values( array_map( 'strval', $order ) ),
				'visible' => array_values( array_map( 'strval', $visible ) ),
			)
		);
	}
}
