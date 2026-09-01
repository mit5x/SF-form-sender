=== SF Form sender ===
Contributors: saytformat
Tags: forms, contact form, smtp, antispam, utm
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Universal form interceptor: one attribute in your form tag, and the plugin mails the submission, stores it and keeps the robots out.

== Description ==

SF Form sender is built for developers who write their own theme markup. You
keep your form exactly as designed; the plugin takes over sending it.

`<form method="post" sf-form-sender-interceptor>`

That single attribute is the whole integration. Notification containers, the
captcha, the decoy field and the service fields are added by the plugin when
they are missing — and left alone when you place them yourself, so your CSS
stays in charge.

* Submits without reloading the page. Errors and the success notice appear in
  containers you can put anywhere in the form.
* Sends by SMTP or by the server's own mail, with full PHPMailer settings and
  a DKIM signature. Those settings apply to this plugin's messages only.
* Stores submissions in the site database and shows them on their own admin
  page, with the set and the order of columns up to you — field names come
  from your forms, so column titles are yours to map.
* Keeps advertising tags from the page address (utm_source, utm_medium, yclid,
  gclid and any of your own) in the visitor's cookie, appending new values
  rather than replacing them, and attaches them to the message.
* Accepts file attachments: allow-list of extensions, a total size limit,
  content checked against the extension, optional transliteration of names,
  and execution blocked in the upload directory.
* Anti-spam: Yandex SmartCaptcha or Google reCAPTCHA (v2 and v3), a hidden
  decoy field and a trap for submissions that arrive too fast.
* Does not touch validation. An empty required field is handled by the browser
  itself; your own script keeps working through a cancelable event.
* Interface in eight languages, switchable independently of the site language.

No external services are contacted unless you turn a captcha on.

= Interception =

Add the attribute to as many forms as you like — the plugin numbers them
itself. Give the attribute a value if you want a readable name in the
submission list:

`<form sf-form-sender-interceptor="calculator">`

Prefer valid markup? `data-sf-form-sender` works exactly the same.

= Hooks for themes =

In the browser:

`form.addEventListener('sf-form-sender:before-send', e => e.preventDefault())`
`form.addEventListener('sf-form-sender:success', e => e.detail.id)`
`form.addEventListener('sf-form-sender:error', e => e.detail.code)`

In PHP:

`add_filter( 'sf_fs_before_send', function ( $payload ) { return $payload; } );`
`add_action( 'sf_fs_after_send', function ( $payload, $id, $mail_ok ) {}, 10, 3 );`

== Installation ==

1. Upload the archive on *Plugins → Add New → Upload Plugin* and activate it.
   Activation creates four tables, seeds the settings, writes the visitor
   notification texts in the site language and closes the upload directory to
   file execution.
2. Fill in *SF Form sender → Settings → General → Where to send the messages*.
   An empty field means the site administrator address.
3. Add `sf-form-sender-interceptor` to the form tag in your template.

The Documentation tab on the settings screen covers the rest.

== Frequently Asked Questions ==

= The form has novalidate and my own JavaScript check. Will the plugin fight it? =

No. The plugin never adds `novalidate` and never validates on its own. Hook
your check to `sf-form-sender:before-send` and call `preventDefault()` — the
sending is cancelled.

= Why is a file with an allowed extension refused? =

Because its content does not match the extension. If the content is another
allowed format, the plugin accepts the file under the correct extension
instead of refusing; the refusal means the content could not be recognised at
all. The notification says so explicitly.

= Does the plugin change how other plugins send mail? =

No. The SMTP and phpmail settings are applied around a single `wp_mail` call
and removed straight after.

= Are the submissions deleted when I remove the plugin? =

No. Uninstalling removes the settings only. Submissions, files and tables stay:
they were sent by real people. Clear them with the "Delete all" button on the
Submissions page.

== Screenshots ==

1. Settings, the General tab.
2. Submissions, with the column setup open.
3. The SMTP server test with its live log.

== Changelog ==

= 1.0.0 =
* First release.
* Interception by a single attribute, with the plugin numbering the forms.
* Sending by SMTP or phpmail, DKIM, live SMTP test.
* Submissions stored in four tables, with configurable columns.
* Advertising tags from GET kept in a cookie and attached to the message.
* File attachments with an allow-list, size limit and content check.
* Yandex SmartCaptcha, Google reCAPTCHA v2 and v3, decoy field, timing trap.
* Interface in Russian, English, German, Spanish, French, Italian, Brazilian
  Portuguese and Simplified Chinese.
