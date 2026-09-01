<?php
/**
 * Вкладка «Документация».
 *
 * @package SF_Form_Sender
 *
 * @var array<string,string> $markers Имена атрибутов и классов.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$attr    = $markers['attr'];
$fail    = $markers['fail_class'];
$ok      = $markers['ok_class'];
$captcha = $markers['captcha_class'];

// Имя формы из примеров. Оно встречается дважды, и в переводах должно
// совпадать — поэтому одна строка на оба места.
$example = __( 'калькулятор', 'sf-form-sender' );
?>
<div class="sf-fs-docs">

	<h2><?php esc_html_e( 'Как настроить перехват содержимого форм', 'sf-form-sender' ); ?></h2>

	<p>
		<?php
		printf(
			/* translators: 1 — атрибут перехвата, 2 — тот же атрибут со значением. */
			esc_html__( 'Достаточно одного атрибута в теге формы: %1$s, а если хотите задать форме своё имя — %2$s.', 'sf-form-sender' ),
			'<code>' . esc_html( $attr ) . '</code>',
			'<code>' . esc_html( $attr ) . '="' . esc_html( $example ) . '"</code>'
		);
		?>
	</p>

	<p>
		<?php esc_html_e( 'Всё остальное — контейнеры уведомлений, капча, поле-приманка, служебные поля — плагин добавит сам, если их нет.', 'sf-form-sender' ); ?>
	</p>

	<pre><code>&lt;form method="post" <?php echo esc_html( $attr ); ?>&gt;
    &lt;input type="text" name="name"&gt;
    &lt;input type="email" name="email"&gt;
    &lt;button type="submit"&gt;<?php esc_html_e( 'Отправить', 'sf-form-sender' ); ?>&lt;/button&gt;
&lt;/form&gt;</code></pre>

	<p class="description">
		<?php
		printf(
			/* translators: 1 — атрибут перехвата, 2 — имя data-атрибута. */
			esc_html__( 'Атрибут %1$s придуман нами, в стандарте HTML такого нет: сервисы проверки разметки вроде validator.w3.org сочтут его ошибкой. Если такие проверки для вас важны, напишите вместо него %2$s — это обычный data-атрибут, к нему у стандарта претензий не будет. Плагин понимает оба написания, в работе они ничем не отличаются.', 'sf-form-sender' ),
			'<code>' . esc_html( $attr ) . '</code>',
			'<code>' . esc_html( $markers['attr_data'] ) . '</code>'
		);
		?>
	</p>

	<h3><?php esc_html_e( 'Контейнеры уведомлений', 'sf-form-sender' ); ?></h3>

	<p>
		<?php esc_html_e( 'Два контейнера можно разместить в удобных местах формы. По умолчанию плагин делает их невидимыми, а при необходимости показывает текст ошибки или уведомление об успешной отправке.', 'sf-form-sender' ); ?>
	</p>

	<pre><code>&lt;div class="<?php echo esc_html( $fail ); ?>"&gt;&lt;/div&gt;
&lt;div class="<?php echo esc_html( $ok ); ?>"&gt;&lt;/div&gt;</code></pre>

	<p>
		<?php esc_html_e( 'Оформление этих контейнеров задаёт вебмастер своим CSS: собственные стили плагин к ним не применяет. Если контейнеров в форме нет, плагин создаст их сам над кнопкой отправки и оформит по-своему.', 'sf-form-sender' ); ?>
	</p>

	<h3><?php esc_html_e( 'Контейнер капчи', 'sf-form-sender' ); ?></h3>

	<pre><code>&lt;div class="<?php echo esc_html( $captcha ); ?>"&gt;&lt;/div&gt;</code></pre>

	<p>
		<?php esc_html_e( 'Работает так же: есть в форме — капча появится там, нет — плагин добавит контейнер над кнопкой отправки. Ключи капчи задаются на вкладке «Антиспам».', 'sf-form-sender' ); ?>
	</p>

	<h3><?php esc_html_e( 'Несколько форм на одной странице', 'sf-form-sender' ); ?></h3>

	<p>
		<?php esc_html_e( 'Всем формам ставится один и тот же атрибут — различать их вебмастеру не нужно. Плагин сам нумерует формы в порядке появления на странице: form-1, form-2 и так далее, и номер попадает в заявку.', 'sf-form-sender' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'Если понятное имя всё же нужно, его можно написать значением атрибута — тогда в заявке будет оно:', 'sf-form-sender' ); ?>
	</p>

	<pre><code>&lt;form <?php echo esc_html( $attr ); ?>="<?php echo esc_html( $example ); ?>"&gt;</code></pre>

	<h3><?php esc_html_e( 'Обязательные поля и проверка', 'sf-form-sender' ); ?></h3>

	<p>
		<?php esc_html_e( 'Проверку заполнения плагин не трогает вовсе. Незаполненное поле с атрибутом required браузер обрабатывает сам: переводит в него курсор и показывает свою подсказку, а до плагина дело даже не доходит. Так же он поступает с типами email, url, tel и с атрибутом pattern.', 'sf-form-sender' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'Если вебмастер поставил форме novalidate и проверяет её собственным скриптом, плагин не мешает и ему: своя проверка вешается на отменяемое событие ниже и точно так же отменяет отправку.', 'sf-form-sender' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'Собственные уведомления плагин показывает только про то, что задано в его настройках: капча, разрешённые типы и размер файлов, ловушки антиспама, сбой почты или базы данных. Их тексты — на вкладке «Уведомления».', 'sf-form-sender' ); ?>
	</p>

	<h3><?php esc_html_e( 'Зарезервированные имена полей', 'sf-form-sender' ); ?></h3>

	<p>
		<?php esc_html_e( 'В каждой заявке плагин сам сохраняет ip отправителя, полный адрес страницы и время отправки. Если в форме есть поля с такими именами, значения из формы важнее:', 'sf-form-sender' ); ?>
	</p>

	<ul class="sf-fs-list">
		<li><code><?php echo esc_html( SF_FS_Db::FIELD_IP ); ?></code> — <?php esc_html_e( 'ip отправителя', 'sf-form-sender' ); ?></li>
		<li><code><?php echo esc_html( SF_FS_Db::FIELD_URL ); ?></code> — <?php esc_html_e( 'адрес страницы', 'sf-form-sender' ); ?></li>
		<li><code><?php echo esc_html( SF_FS_Db::FIELD_DATE ); ?></code> — <?php esc_html_e( 'дата и время отправки', 'sf-form-sender' ); ?></li>
	</ul>

	<p>
		<?php
		printf(
			/* translators: 1 — приставка имён полей, 2 — пример имени параметра, 3 — он же с приставкой. */
			esc_html__( 'Приставку %1$s получают все сохранённые GET параметры без исключения: и весь набор utm-меток (utm_source, utm_medium, utm_campaign, utm_content, utm_term), и метки рекламных систем вроде yclid или gclid, и любые ваши собственные. Например, %2$s превращается в %3$s. Приставка нужна, чтобы метка не смешалась с полем формы, названным так же.', 'sf-form-sender' ),
			'<code>' . esc_html( SF_FS_Db::GET_PREFIX ) . '</code>',
			'<code>utm_source</code>',
			'<code>' . esc_html( SF_FS_Db::GET_PREFIX ) . 'utm_source</code>'
		);
		?>
	</p>

	<h3><?php esc_html_e( 'Файлы', 'sf-form-sender' ); ?></h3>

	<p>
		<?php esc_html_e( 'Поля выбора файлов работают без настройки — достаточно обычного input с типом file. Множественный выбор тоже поддерживается:', 'sf-form-sender' ); ?>
	</p>

	<pre><code>&lt;input type="file" name="files[]" multiple&gt;</code></pre>

	<p>
		<?php esc_html_e( 'Файлы уходят вложением в письмо всегда, а на сервере остаются, только если включена соответствующая настройка на вкладке «Основное».', 'sf-form-sender' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'Кроме расширения плагин сверяет и содержимое файла. Если внутри оказался другой разрешённый формат — так бывает, когда картинку пересохранили, не переименовав файл, — плагин молча примет её под верным расширением. Откажет он только тогда, когда содержимое не удалось опознать вовсе.', 'sf-form-sender' ); ?>
	</p>

	<p>
		<?php esc_html_e( 'Имя файла сохраняется таким, каким его прислал посетитель: кириллица, пробелы и скобки остаются на месте. Убирается только то, что ломает путь или файловую систему. Перевести имена в латиницу можно переключателем «Транслитерировать названия файлов?» — и это единственное, что на них влияет: плагины перевода имён для медиатеки вложениям из форм не указ.', 'sf-form-sender' ); ?>
	</p>

	<h3><?php esc_html_e( 'Как вмешаться в отправку из темы', 'sf-form-sender' ); ?></h3>

	<p>
		<?php esc_html_e( 'На элементе формы плагин вызывает три события. Первое отменяемое: это место для собственных проверок темы.', 'sf-form-sender' ); ?>
	</p>

	<pre><code>form.addEventListener('sf-form-sender:before-send', function (e) {
    if (!myOwnCheck()) e.preventDefault();   // <?php esc_html_e( 'отменит отправку', 'sf-form-sender' ); ?>

});

form.addEventListener('sf-form-sender:success', function (e) {
    console.log(e.detail.message, e.detail.id);
});

form.addEventListener('sf-form-sender:error', function (e) {
    console.log(e.detail.code, e.detail.message);
});</code></pre>

	<p><?php esc_html_e( 'На стороне PHP есть фильтр и действие:', 'sf-form-sender' ); ?></p>

	<pre><code>// <?php esc_html_e( 'изменить данные заявки или отменить её, вернув WP_Error', 'sf-form-sender' ); ?>

add_filter( 'sf_fs_before_send', function ( $payload ) { return $payload; } );

// <?php esc_html_e( 'заявка обработана', 'sf-form-sender' ); ?>

add_action( 'sf_fs_after_send', function ( $payload, $id, $mail_ok ) {}, 10, 3 );</code></pre>

	<h3><?php esc_html_e( 'Что стоит знать заранее', 'sf-form-sender' ); ?></h3>

	<ul class="sf-fs-list">
		<li>
			<?php esc_html_e( 'Формы отправляются без перезагрузки страницы. Если JavaScript у посетителя выключен, форма уйдёт обычной отправкой браузера и плагин её не увидит.', 'sf-form-sender' ); ?>
		</li>
		<li>
			<?php esc_html_e( 'Ключ формы живёт сутки. При кешировании страниц дольше суток посетитель получит уведомление «Страница была открыта слишком давно» — держите время жизни кеша меньше.', 'sf-form-sender' ); ?>
		</li>
		<li>
			<?php esc_html_e( 'Запрет на выполнение файлов в каталоге загрузок плагин ставит через .htaccess. На nginx этот файл не читается — закройте выполнение PHP в /uploads/ настройками сервера.', 'sf-form-sender' ); ?>
		</li>
		<li>
			<?php esc_html_e( 'Ловушка на время и поле-приманка отсекают простых роботов и ничего не показывают посетителю. Настоящую защиту от целенаправленного спама даёт только капча.', 'sf-form-sender' ); ?>
		</li>
	</ul>

	<h3><?php esc_html_e( 'Проверка после установки', 'sf-form-sender' ); ?></h3>

	<ol class="sf-fs-list">
		<li><?php esc_html_e( 'Отправьте форму с заполненными полями — должно появиться уведомление об успехе, а заявка — в разделе «Заявки».', 'sf-form-sender' ); ?></li>
		<li><?php esc_html_e( 'Отправьте форму сразу после загрузки страницы: если время в настройках больше нуля, уведомление будет успешным, но заявка не появится — это ловушка на роботов.', 'sf-form-sender' ); ?></li>
		<li><?php esc_html_e( 'Зайдите на сайт по адресу с ?utm_source=test и отправьте форму — метка должна оказаться в письме и в заявке.', 'sf-form-sender' ); ?></li>
		<li><?php esc_html_e( 'Проверьте SMTP кнопкой на соответствующей вкладке, если выбран этот способ отправки.', 'sf-form-sender' ); ?></li>
	</ol>
</div>
