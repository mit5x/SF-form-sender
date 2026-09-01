<?php
/**
 * Приём содержимого формы.
 *
 * Единственная точка входа для всех перехваченных форм сайта. Порядок работы:
 * проверить, что это не робот → проверить файлы → сохранить заявку →
 * отправить письмо → вернуть браузеру текст уведомления.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Interceptor {

	/** Действие admin-ajax. */
	const ACTION = 'sf_fs_submit';

	/** Сколько живёт ключ формы, секунд. */
	const TICKET_TTL = DAY_IN_SECONDS;

	/** Служебные поля, которые в заявку не попадают. */
	const SERVICE_FIELDS = array(
		'action', 'sf_fs_ticket', 'sf_fs_form', 'sf_fs_elapsed',
		'sf_fs_labels', 'sf_fs_page', 'sf_fs_title', 'sf_fs_captcha',
	);

	/** @var SF_FS_Interceptor|null */
	private static $instance = null;

	/** @return SF_FS_Interceptor */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Значение поля запроса строкой.
	 *
	 * Робот пришлёт что угодно, в том числе sf_fs_ticket[]=1 — тогда в
	 * $_POST окажется массив. Служебные поля всегда одиночные, поэтому
	 * массив здесь означает «значения нет».
	 *
	 * @param string $key Имя поля.
	 * @return string
	 */
	private static function post( $key ) {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '';
		}
		return (string) wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security
	}

	/* ------------------------------------------------------------------ */
	/* Ключ формы                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Ключ, который выдаётся странице и возвращается вместе с формой.
	 *
	 * Обычный nonce WordPress тут не годится: он привязан к сессии и живёт
	 * ровно 12 часов, а страницы с формами почти всегда закешированы и
	 * отдаются всем посетителям одинаковыми. Ключ подписан солью сайта, внутри
	 * — момент отрисовки страницы: по нему же считается, сколько посетитель
	 * пробыл на сайте.
	 *
	 * @param int|null $time Момент выдачи; null — сейчас.
	 * @return string
	 */
	public static function make_ticket( $time = null ) {
		$time = null === $time ? time() : (int) $time;
		return $time . '.' . hash_hmac( 'sha256', (string) $time, wp_salt( 'sf_fs_ticket' ) );
	}

	/**
	 * Разбор ключа.
	 *
	 * @param string $ticket Ключ.
	 * @return int Момент выдачи или 0, если ключ подделан или просрочен.
	 */
	public static function read_ticket( $ticket ) {
		$parts = explode( '.', (string) $ticket, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return 0;
		}

		$time = (int) $parts[0];
		if ( ! hash_equals( hash_hmac( 'sha256', (string) $time, wp_salt( 'sf_fs_ticket' ) ), $parts[1] ) ) {
			return 0;
		}
		if ( $time > time() + 300 || $time < time() - self::TICKET_TTL ) {
			return 0;
		}
		return $time;
	}

	/* ------------------------------------------------------------------ */
	/* Обработка                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Приём формы.
	 *
	 * @return void
	 */
	public function handle() {
		$ticket_time = self::read_ticket( self::post( 'sf_fs_ticket' ) );
		if ( ! $ticket_time ) {
			$this->fail( SF_FS_Notices::get( 'error_expired' ), 'expired' );
		}

		// Ловушки для роботов. Робот должен уйти с той же картинкой, что и
		// живой человек, иначе он быстро подберёт обход.
		if ( $this->looks_like_bot( $ticket_time ) ) {
			$this->done( SF_FS_Notices::get( 'success' ), true );
		}

		if ( ! SF_FS_Captcha::verify( self::post( SF_FS_Captcha::TOKEN_FIELD ), $this->client_ip() ) ) {
			$this->fail( SF_FS_Notices::get( 'error_captcha' ), 'captcha' );
		}

		$fields = $this->collect_fields();
		$files  = SF_FS_Uploads::flatten( $_FILES );

		if ( ! $fields && ! $files ) {
			$this->fail( SF_FS_Notices::get( 'error_empty' ), 'empty' );
		}

		$check = SF_FS_Uploads::validate( $files );
		if ( ! $check['ok'] ) {
			$this->fail( $check['error'], 'files' );
		}
		// У файла, содержимое которого оказалось другого типа, проверка
		// поправила имя на верное — дальше идём с ним.
		$files = $check['files'];

		$payload = $this->build_payload( $fields );

		/**
		 * Последняя возможность вмешаться до сохранения и отправки.
		 *
		 * Вернув WP_Error, тема или другой плагин отменяет отправку и задаёт
		 * свой текст ошибки.
		 *
		 * @param array<string,mixed> $payload Данные заявки.
		 */
		$filtered = apply_filters( 'sf_fs_before_send', $payload );
		if ( is_wp_error( $filtered ) ) {
			$this->fail( $filtered->get_error_message(), 'filtered' );
		}
		$payload = is_array( $filtered ) ? $filtered : $payload;

		$saved_id  = 0;
		$db_failed = false;

		if ( SF_FS_Settings::on( 'save_to_db' ) ) {
			if ( ! SF_FS_Db::tables_ready() ) {
				SF_FS_Db::install();
			}
			$saved_id = SF_FS_Db::save_submission(
				array(
					'fields'     => $payload['stored_fields'],
					'labels'     => $payload['labels'],
					'ip'         => $payload['ip'],
					'url'        => $payload['url'],
					'date'       => $payload['date'],
					'form_id'    => $payload['form_id'],
					'user_agent' => $payload['user_agent'],
				)
			);
			$db_failed = ! $saved_id;
		}

		// Файлы кладём в каталог заявки. Если сохранение заявок выключено или
		// не удалось, файлы всё равно уходят вложением — прямо из временного
		// каталога PHP, он жив до конца запроса.
		$payload['files']       = array();
		$payload['attachments'] = array();

		if ( $files ) {
			if ( $saved_id && SF_FS_Settings::on( 'save_files' ) ) {
				$stored = SF_FS_Uploads::store( $saved_id, $files );
				foreach ( $stored as $file ) {
					SF_FS_Db::add_file( $saved_id, $file );
					$payload['files'][]       = $file;
					$payload['attachments'][] = $file['path'];
				}
			} else {
				foreach ( $files as $file ) {
					$payload['files'][]       = array( 'orig_name' => $file['name'] );
					$payload['attachments'][] = $file['tmp_name'];
				}
			}
		}

		$mail_ok = SF_FS_Mailer::send( $payload );

		/**
		 * Заявка обработана.
		 *
		 * @param array<string,mixed> $payload Данные заявки.
		 * @param int                 $saved_id Номер заявки в базе или 0.
		 * @param bool                $mail_ok  Письмо ушло.
		 */
		do_action( 'sf_fs_after_send', $payload, $saved_id, $mail_ok );

		if ( ! $mail_ok ) {
			// Письмо не ушло, но заявка в базе — посетителю не о чем жалеть,
			// он своё дело сделал. Ругаемся, только если не сохранилось ничего.
			if ( $saved_id ) {
				$this->done( SF_FS_Notices::get( 'success' ), false, $saved_id );
			}
			$this->fail( SF_FS_Notices::get( 'error_mail' ), 'mail' );
		}

		if ( $db_failed && 'none' === SF_FS_Settings::get( 'mail_method' ) ) {
			$this->fail( SF_FS_Notices::get( 'error_db' ), 'db' );
		}

		$this->done( SF_FS_Notices::get( 'success' ), false, $saved_id );
	}

	/**
	 * Похоже на робота?
	 *
	 * @param int $ticket_time Момент выдачи ключа формы.
	 * @return bool
	 */
	private function looks_like_bot( $ticket_time ) {
		if ( SF_FS_Settings::on( 'honeypot' ) ) {
			$name = (string) SF_FS_Settings::get( 'honeypot_name' );
			// Заполненной приманкой считается любое непустое значение, в том
			// числе массив: обойти ловушку, прислав website[]=спам, не выйдет.
			$raw  = isset( $_POST[ $name ] ) ? $_POST[ $name ] : ''; // phpcs:ignore WordPress.Security
			$sent = is_array( $raw ) ? implode( '', $raw ) : (string) $raw;

			if ( '' !== $name && '' !== trim( $sent ) ) {
				return true;
			}
		}

		$min = (int) SF_FS_Settings::get( 'min_seconds' );
		if ( $min <= 0 ) {
			return false;
		}

		// Двe оценки времени на сайте. Ключ формы даёт честный отсчёт, но на
		// закешированной странице он «стареет» вместе с кешем; браузер знает
		// правду, но робот может прислать что угодно. Меньшая из двух оценок
		// одновременно и не обижает живого посетителя, и не верит роботу
		// на слово.
		$by_ticket  = max( 0, time() - $ticket_time );
		$by_browser = (int) self::post( 'sf_fs_elapsed' );
		$elapsed    = min( $by_ticket, max( 0, $by_browser ) );

		return $elapsed < $min;
	}

	/**
	 * Поля формы, пригодные для хранения.
	 *
	 * @return array<string,string>
	 */
	private function collect_fields() {
		$skip = self::SERVICE_FIELDS;
		if ( SF_FS_Settings::on( 'honeypot' ) ) {
			$skip[] = (string) SF_FS_Settings::get( 'honeypot_name' );
		}

		$out = array();
		foreach ( (array) $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification
			$key = (string) $key;
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}

			$value = wp_unslash( $value );
			if ( is_array( $value ) ) {
				$flat = array();
				foreach ( $value as $item ) {
					if ( is_scalar( $item ) ) {
						$flat[] = (string) $item;
					}
				}
				$value = implode( ', ', $flat );
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$out[ SF_FS_Db::sanitize_key( $key ) ] = mb_substr( wp_check_invalid_utf8( $value, true ), 0, 20000 );

			if ( count( $out ) >= 100 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Подписи полей, присланные страницей.
	 *
	 * Скрипт берёт их из тега label рядом с полем — так администратор сразу
	 * видит в списке заявок «Ваше имя», а не «name». Значения приходят из
	 * браузера, поэтому чистим их как любой ввод.
	 *
	 * @param array<string,string> $fields Поля заявки.
	 * @return array<string,string>
	 */
	private function collect_labels( $fields ) {
		$map = json_decode( self::post( 'sf_fs_labels' ), true );
		if ( ! is_array( $map ) ) {
			return array();
		}

		$out = array();
		foreach ( $map as $key => $label ) {
			if ( ! is_scalar( $label ) ) {
				continue;
			}
			$key = SF_FS_Db::sanitize_key( (string) $key );
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}
			$label = trim( sanitize_text_field( (string) $label ) );
			if ( '' !== $label ) {
				$out[ $key ] = mb_substr( $label, 0, 255 );
			}
		}

		return $out;
	}

	/**
	 * Сбор всех данных заявки в одну структуру.
	 *
	 * @param array<string,string> $fields Поля формы.
	 * @return array<string,mixed>
	 */
	private function build_payload( $fields ) {
		$labels = $this->collect_labels( $fields );

		$ip   = $this->client_ip();
		$url  = $this->page_url();
		$date = current_time( 'mysql' );

		// Зарезервированные имена: если вебмастер завёл в форме поле sf_url
		// или sf_ip_sender, его значение важнее того, что определил плагин.
		if ( isset( $fields[ SF_FS_Db::FIELD_IP ] ) ) {
			$ip = $fields[ SF_FS_Db::FIELD_IP ];
		}
		if ( isset( $fields[ SF_FS_Db::FIELD_URL ] ) ) {
			$url = $fields[ SF_FS_Db::FIELD_URL ];
		}
		if ( isset( $fields[ SF_FS_Db::FIELD_DATE ] ) ) {
			$date = $fields[ SF_FS_Db::FIELD_DATE ];
		}

		$tracking      = SF_FS_Settings::on( 'track_get' ) ? SF_FS_Tracking::params() : array();
		$tracking_text = SF_FS_Settings::on( 'attach_get_to_mail' ) ? SF_FS_Tracking::as_text( $tracking ) : '';

		// Поля, которые уезжают в базу. Зарезервированные оттуда убираем: их
		// место — колонки таблицы заявок, иначе они задвоятся в списке.
		$stored = $fields;
		foreach ( SF_FS_Db::reserved_fields() as $reserved ) {
			unset( $stored[ $reserved ] );
		}
		if ( SF_FS_Settings::on( 'save_get_to_db' ) && $tracking ) {
			$stored = array_merge( $stored, SF_FS_Tracking::as_fields( $tracking ) );
			$labels = array_merge( $labels, SF_FS_Tracking::labels( $tracking ) );
		}

		return array(
			'fields'        => $fields,
			'stored_fields' => $stored,
			'labels'        => $labels,
			'files'         => array(),
			'attachments'   => array(),
			'ip'            => $ip,
			'url'           => $url,
			'date'          => $date,
			'form_id'       => $this->form_id(),
			'page_title'    => sanitize_text_field( self::post( 'sf_fs_title' ) ),
			'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'tracking'      => $tracking,
			'tracking_text' => $tracking_text,
		);
	}

	/**
	 * Имя формы: то, что задал вебмастер, либо выданное плагином.
	 *
	 * @return string
	 */
	private function form_id() {
		$raw = sanitize_text_field( self::post( 'sf_fs_form' ) );
		return '' !== $raw ? mb_substr( $raw, 0, 64 ) : 'form-1';
	}

	/**
	 * Адрес страницы, с которой ушла форма.
	 *
	 * @return string
	 */
	private function page_url() {
		$url = esc_url_raw( self::post( 'sf_fs_page' ) );

		// Чужие адреса не принимаем: страница обязана быть своей.
		if ( '' !== $url && 0 === strpos( $url, home_url() ) ) {
			return $url;
		}
		return (string) wp_get_referer();
	}

	/**
	 * Адрес посетителя.
	 *
	 * За обратным прокси (nginx перед php-fpm, Cloudflare) в REMOTE_ADDR
	 * лежит адрес самого прокси — тогда смотрим на заголовок, который он
	 * проставил. Заголовку верим, только если запрос действительно пришёл
	 * из локальной сети.
	 *
	 * @return string
	 */
	public function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		$is_local = $remote && ! filter_var(
			$remote,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);

		if ( $is_local && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$chain = explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$first = trim( $chain[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	/* ------------------------------------------------------------------ */
	/* Ответы                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Успешный ответ.
	 *
	 * @param string $message Текст.
	 * @param bool   $silent  Заявка отброшена как спам, но посетителю об этом
	 *                        знать не нужно.
	 * @param int    $id      Номер заявки.
	 * @return void
	 */
	private function done( $message, $silent = false, $id = 0 ) {
		wp_send_json(
			array(
				'success' => true,
				'message' => $message,
				'silent'  => (bool) $silent,
				'id'      => (int) $id,
			)
		);
	}

	/**
	 * Ответ с ошибкой.
	 *
	 * @param string $message Текст.
	 * @param string $code    Код причины — для отладки и обработчиков в теме.
	 * @return void
	 */
	private function fail( $message, $code ) {
		wp_send_json(
			array(
				'success' => false,
				'message' => '' !== $message ? $message : SF_FS_Notices::get( 'error' ),
				'code'    => $code,
			)
		);
	}
}
