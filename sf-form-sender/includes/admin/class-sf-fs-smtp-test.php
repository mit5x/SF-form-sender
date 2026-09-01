<?php
/**
 * Проверка SMTP сервера с живым логом.
 *
 * Ответ отдаётся потоком: каждая строка диалога с сервером уходит в браузер
 * сразу, как только получена, а не после завершения проверки. Поэтому здесь
 * нет wp_send_json — он бы дождался конца.
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Smtp_Test {

	/** Действие admin-ajax. */
	const ACTION = 'sf_fs_smtp_test';

	/** Метка, по которой скрипт находит итог проверки в потоке. */
	const RESULT_MARK = '___SF_FS_RESULT___';

	/** @var SF_FS_Smtp_Test|null */
	private static $instance = null;

	/** @return SF_FS_Smtp_Test */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Блок проверки под кнопкой сохранения вкладки SMTP.
	 *
	 * @return void
	 */
	public static function render_widget() {
		?>
		<div class="sf-fs-smtp-test">
			<h3><?php esc_html_e( 'Проверка SMTP сервера', 'sf-form-sender' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Плагин соединится с сервером, поздоровается и попробует авторизоваться, показывая весь обмен строками. Письмо при этом не отправляется.', 'sf-form-sender' ); ?>
			</p>

			<p class="sf-fs-smtp-test__bar">
				<button type="button" class="button button-secondary" id="sf-fs-smtp-run">
					<?php esc_html_e( 'Проверить', 'sf-form-sender' ); ?>
				</button>
				<span class="sf-fs-smtp-test__warn" id="sf-fs-smtp-warn">
					<?php esc_html_e( 'Сохраните поля формы, перед тем как выполнить проверку.', 'sf-form-sender' ); ?>
				</span>
			</p>

			<pre class="sf-fs-log" id="sf-fs-smtp-log" hidden></pre>
			<p class="sf-fs-smtp-test__result" id="sf-fs-smtp-result" hidden></p>
		</div>
		<?php
	}

	/**
	 * Сама проверка.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		check_ajax_referer( self::ACTION );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		// nginx и часть прокси копят ответ целиком, пока их об этом не
		// попросить иначе — тогда «живого» лога не получится.
		header( 'X-Accel-Buffering: no' );

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		$host = (string) SF_FS_Settings::get( 'smtp_host' );
		if ( '' === trim( $host ) ) {
			$this->finish( false, __( 'Не заполнено поле SMTP Host.', 'sf-form-sender' ) );
		}

		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';

		$secure  = (string) SF_FS_Settings::get( 'smtp_secure' );
		$port    = (int) SF_FS_Settings::get( 'smtp_port' );
		$timeout = (int) SF_FS_Settings::get( 'smtp_timeout' );
		$user    = (string) SF_FS_Settings::get( 'smtp_user' );
		$pass    = (string) SF_FS_Settings::get( 'smtp_pass' );

		$smtp = new PHPMailer\PHPMailer\SMTP();
		$smtp->do_debug     = PHPMailer\PHPMailer\SMTP::DEBUG_CONNECTION;
		$smtp->Timeout      = $timeout;
		$smtp->Timelimit    = $timeout;
		$smtp->Debugoutput  = array( $this, 'line' );

		$address = 'ssl' === $secure ? 'ssl://' . $host : $host;
		$hello   = (string) SF_FS_Settings::get( 'phpmail_hostname' );
		if ( '' === $hello ) {
			$hello = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}

		$this->line( sprintf( '# %s %s:%d', __( 'Соединение с', 'sf-form-sender' ), $host, $port ), 0 );

		try {
			if ( ! $smtp->connect( $address, $port, $timeout ) ) {
				$this->finish( false, __( 'Не удалось установить соединение с сервером.', 'sf-form-sender' ) );
			}

			if ( ! $smtp->hello( $hello ) ) {
				$smtp->quit();
				$this->finish( false, __( 'Сервер не принял приветствие EHLO.', 'sf-form-sender' ) );
			}

			if ( 'tls' === $secure ) {
				if ( ! $smtp->startTLS() ) {
					$smtp->quit();
					$this->finish( false, __( 'Сервер не согласился включить шифрование STARTTLS.', 'sf-form-sender' ) );
				}
				// После STARTTLS положено здороваться заново — так требует
				// протокол, и часть серверов иначе отказывает в авторизации.
				$smtp->hello( $hello );
			}

			if ( '' !== $user ) {
				if ( ! $smtp->authenticate( $user, $pass ) ) {
					$error = $smtp->getError();
					$smtp->quit();
					$this->finish(
						false,
						__( 'Авторизация отклонена.', 'sf-form-sender' ) . ' ' . (string) $error['error'] . ' ' . (string) $error['detail']
					);
				}
			} else {
				$this->line( '# ' . __( 'Имя пользователя не задано — авторизация пропущена.', 'sf-form-sender' ), 0 );
			}

			$smtp->quit();
			$this->finish( true, __( 'Проверка пройдена: сервер отвечает и принимает эти данные.', 'sf-form-sender' ) );

		} catch ( Exception $e ) {
			$this->finish( false, $e->getMessage() );
		}
	}

	/**
	 * Одна строка лога — сразу в браузер.
	 *
	 * @param string $text  Строка.
	 * @param int    $level Уровень подробности PHPMailer.
	 * @return void
	 */
	public function line( $text, $level = 0 ) {
		unset( $level );

		// Пароль в открытом виде в логе не нужен: PHPMailer отдаёт его в
		// команде AUTH как есть.
		$pass = (string) SF_FS_Settings::get( 'smtp_pass' );
		$text = (string) $text;
		if ( '' !== $pass ) {
			$text = str_replace( array( $pass, base64_encode( $pass ) ), '***', $text ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}

		echo rtrim( $text ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		flush();
	}

	/**
	 * Итог проверки и конец ответа.
	 *
	 * @param bool   $ok      Успех.
	 * @param string $message Текст.
	 * @return void
	 */
	private function finish( $ok, $message ) {
		echo self::RESULT_MARK . wp_json_encode( array( 'ok' => (bool) $ok, 'message' => $message ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		flush();
		exit;
	}
}
