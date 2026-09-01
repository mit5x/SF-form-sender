<?php
/**
 * Отправка письма с содержимым формы.
 *
 * Плагин не подменяет почту всего сайта: настройки SMTP и phpmail
 * применяются только к своим письмам, на время одного вызова. Письма других
 * плагинов и самого WordPress уходят как раньше.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Mailer {

	/** @var bool Идёт отправка нашего письма. */
	private static $sending = false;

	/** @var string Текст последней ошибки почтового сервера. */
	private static $last_error = '';

	/**
	 * Отправка письма с заявкой.
	 *
	 * @param array<string,mixed> $payload Данные заявки, см. SF_FS_Interceptor.
	 * @return bool
	 */
	public static function send( $payload ) {
		$method = (string) SF_FS_Settings::get( 'mail_method' );
		if ( 'none' === $method ) {
			return true;   // Отправка выключена — это не ошибка.
		}

		self::$last_error = '';

		$to      = SF_FS_Settings::recipients();
		$subject = self::subject( $payload );
		$body    = self::body( $payload );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$reply_to = self::reply_to( $payload );
		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		self::$sending = true;
		add_action( 'phpmailer_init', array( __CLASS__, 'configure' ), 99 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'catch_error' ) );

		$sent = wp_mail( $to, $subject, $body, $headers, $payload['attachments'] );

		remove_action( 'phpmailer_init', array( __CLASS__, 'configure' ), 99 );
		remove_action( 'wp_mail_failed', array( __CLASS__, 'catch_error' ) );
		self::$sending = false;

		return (bool) $sent;
	}

	/**
	 * Запоминает причину отказа почтового сервера.
	 *
	 * @param WP_Error $error Ошибка.
	 * @return void
	 */
	public static function catch_error( $error ) {
		if ( is_wp_error( $error ) ) {
			self::$last_error = $error->get_error_message();
		}
	}

	/** @return string Текст последней ошибки. */
	public static function last_error() {
		return self::$last_error;
	}

	/**
	 * Настройка PHPMailer под выбранный способ отправки.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mailer Почтовик WordPress.
	 * @return void
	 */
	public static function configure( $mailer ) {
		if ( ! self::$sending ) {
			return;
		}

		if ( 'smtp' === SF_FS_Settings::get( 'mail_method' ) ) {
			self::apply_smtp( $mailer );
		} else {
			self::apply_phpmail( $mailer );
		}

		self::apply_dkim( $mailer );

		// Текстовая версия для почтовых программ без HTML.
		if ( '' === (string) $mailer->AltBody ) {
			$mailer->AltBody = wp_strip_all_tags( str_replace( array( '</tr>', '<br>', '<br />' ), "\n", $mailer->Body ) );
		}
	}

	/**
	 * Параметры SMTP.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mailer Почтовик.
	 * @return void
	 */
	public static function apply_smtp( $mailer ) {
		$mailer->isSMTP();
		$mailer->Host    = (string) SF_FS_Settings::get( 'smtp_host' );
		$mailer->Port    = (int) SF_FS_Settings::get( 'smtp_port' );
		$mailer->Timeout = (int) SF_FS_Settings::get( 'smtp_timeout' );

		$secure          = (string) SF_FS_Settings::get( 'smtp_secure' );
		$mailer->SMTPSecure = 'none' === $secure ? '' : $secure;
		$mailer->SMTPAutoTLS = 'none' !== $secure;

		$user = (string) SF_FS_Settings::get( 'smtp_user' );
		$pass = (string) SF_FS_Settings::get( 'smtp_pass' );

		if ( '' !== $user ) {
			$mailer->SMTPAuth = true;
			$mailer->Username = $user;
			$mailer->Password = $pass;

			// Отправитель по умолчанию — тот, под кем авторизовались: почтовые
			// службы отвергают письма с чужим адресом в поле From.
			if ( is_email( $user ) ) {
				$mailer->setFrom( $user, $mailer->FromName, false );
				$mailer->Sender = $user;
			}
		}

		// Поля вкладки Phpmail заполняют то, что не задано SMTP.
		$from = (string) SF_FS_Settings::get( 'phpmail_from' );
		if ( is_email( $from ) ) {
			$mailer->setFrom( $from, (string) SF_FS_Settings::get( 'phpmail_from_name' ) ?: $mailer->FromName, false );
		}
	}

	/**
	 * Параметры штатной отправки PHP.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mailer Почтовик.
	 * @return void
	 */
	public static function apply_phpmail( $mailer ) {
		$mailer->isMail();

		$from      = (string) SF_FS_Settings::get( 'phpmail_from' );
		$from_name = (string) SF_FS_Settings::get( 'phpmail_from_name' );
		if ( is_email( $from ) ) {
			$mailer->setFrom( $from, '' !== $from_name ? $from_name : $mailer->FromName, false );
		} elseif ( '' !== $from_name ) {
			$mailer->FromName = $from_name;
		}

		$sender = (string) SF_FS_Settings::get( 'phpmail_sender' );
		if ( is_email( $sender ) ) {
			$mailer->Sender = $sender;
		}

		$hostname = (string) SF_FS_Settings::get( 'phpmail_hostname' );
		if ( '' !== $hostname ) {
			$mailer->Hostname = $hostname;
		}

		$reply_to = (string) SF_FS_Settings::get( 'phpmail_reply_to' );
		if ( is_email( $reply_to ) ) {
			$mailer->clearReplyTos();
			$mailer->addReplyTo( $reply_to );
		}
	}

	/**
	 * Подпись DKIM.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mailer Почтовик.
	 * @return void
	 */
	public static function apply_dkim( $mailer ) {
		$domain = trim( (string) SF_FS_Settings::get( 'dkim_domain' ) );
		$key    = trim( (string) SF_FS_Settings::get( 'dkim_private_string' ) );

		if ( '' === $domain || '' === $key ) {
			return;
		}

		$mailer->DKIM_domain         = $domain;
		$mailer->DKIM_selector       = (string) SF_FS_Settings::get( 'dkim_selector' );
		$mailer->DKIM_private_string = $key;
		$mailer->DKIM_identity       = $mailer->From;
	}

	/**
	 * Тема письма с подстановками.
	 *
	 * @param array<string,mixed> $payload Данные заявки.
	 * @return string
	 */
	public static function subject( $payload ) {
		$subject = trim( (string) SF_FS_Settings::get( 'mail_subject' ) );
		if ( '' === $subject ) {
			$subject = __( 'Заявка с сайта: {site}', 'sf-form-sender' );
		}

		return strtr(
			$subject,
			array(
				'{site}' => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
				'{page}' => (string) $payload['page_title'],
				'{form}' => (string) $payload['form_id'],
			)
		);
	}

	/**
	 * Кому отвечать: e-mail из формы, если он там есть.
	 *
	 * @param array<string,mixed> $payload Данные заявки.
	 * @return string
	 */
	public static function reply_to( $payload ) {
		foreach ( $payload['fields'] as $value ) {
			if ( is_string( $value ) && is_email( trim( $value ) ) ) {
				return trim( $value );
			}
		}
		return '';
	}

	/**
	 * Тело письма.
	 *
	 * @param array<string,mixed> $payload Данные заявки.
	 * @return string
	 */
	public static function body( $payload ) {
		$rows = '';
		foreach ( $payload['fields'] as $key => $value ) {
			$label = isset( $payload['labels'][ $key ] ) && '' !== $payload['labels'][ $key ]
				? $payload['labels'][ $key ]
				: $key;

			$rows .= '<tr>'
				. '<th style="text-align:left;vertical-align:top;padding:6px 14px 6px 0;color:#555;font-weight:600;white-space:nowrap">' . esc_html( $label ) . '</th>'
				. '<td style="vertical-align:top;padding:6px 0">' . nl2br( esc_html( (string) $value ) ) . '</td>'
				. '</tr>';
		}

		$html = '<div style="font:14px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1d2327">';
		$html .= '<table style="border-collapse:collapse">' . $rows . '</table>';

		if ( $payload['files'] ) {
			$names = array();
			foreach ( $payload['files'] as $file ) {
				$names[] = esc_html( $file['orig_name'] );
			}
			$html .= '<p style="margin:18px 0 0"><strong>' . esc_html__( 'Прикреплённые файлы', 'sf-form-sender' ) . ':</strong><br>'
				. implode( '<br>', $names ) . '</p>';
		}

		if ( $payload['tracking_text'] ) {
			$html .= '<p style="margin:18px 0 0"><strong>' . esc_html__( 'Сохранённые GET параметры', 'sf-form-sender' ) . ':</strong></p>';
			$html .= '<pre style="margin:4px 0 0;font:13px/1.5 Consolas,Menlo,monospace;white-space:pre-wrap">'
				. esc_html( $payload['tracking_text'] ) . '</pre>';
		}

		$html .= '<hr style="margin:20px 0;border:0;border-top:1px solid #dcdcde">';
		$html .= '<p style="margin:0;color:#646970;font-size:13px">'
			. esc_html__( 'Страница', 'sf-form-sender' ) . ': <a href="' . esc_url( $payload['url'] ) . '">' . esc_html( $payload['url'] ) . '</a><br>'
			. esc_html__( 'IP отправителя', 'sf-form-sender' ) . ': ' . esc_html( $payload['ip'] ) . '<br>'
			. esc_html__( 'Дата и время', 'sf-form-sender' ) . ': ' . esc_html( $payload['date'] )
			. '</p></div>';

		return $html;
	}
}
