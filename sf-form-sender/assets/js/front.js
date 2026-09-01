/* global SF_FORM_SENDER */
/**
 * SF Form sender — перехват форм на стороне браузера.
 *
 * Что делает скрипт:
 *   1. складывает GET параметры адреса в cookie, дополняя уже накопленные;
 *   2. находит формы, отмеченные атрибутом перехвата, и раздаёт им имена;
 *   3. дописывает в форму то, чего в ней нет: контейнеры уведомлений,
 *      контейнер капчи, поле-приманку и служебные поля;
 *   4. отправляет форму без перезагрузки страницы и показывает ответ.
 *
 * Тема может вмешаться в отправку через события на элементе формы:
 *   sf-form-sender:before-send  — отменяемое, preventDefault() отменит отправку;
 *   sf-form-sender:success      — заявка принята, detail = { message, id };
 *   sf-form-sender:error        — отказ, detail = { message, code }.
 */
(function () {
	'use strict';

	var CFG = window.SF_FORM_SENDER;
	if (!CFG) return;

	var DAY = 86400000;
	var COOKIE_LIMIT = 3800;   // Браузеры хранят не больше 4 КБ на одну cookie.
	var forms = [];            // Уже подключённые формы.
	var captchaReady = false;
	var captchaQueue = [];

	/* --------------------------------------------------------------------- *
	 * Мелкие помощники
	 * --------------------------------------------------------------------- */

	function readCookie(name) {
		var parts = ('; ' + document.cookie).split('; ' + name + '=');
		return parts.length === 2 ? parts.pop().split(';').shift() : '';
	}

	function writeCookie(name, value, days) {
		var date = new Date(Date.now() + days * DAY);
		var secure = location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = name + '=' + value + '; expires=' + date.toUTCString() +
			'; path=/; SameSite=Lax' + secure;
	}

	function text(el) {
		return (el.textContent || '').replace(/\s+/g, ' ').trim();
	}

	/* --------------------------------------------------------------------- *
	 * Рекламные метки из адреса страницы
	 *
	 * Cookie пишется в браузере, а не на сервере: страницы с формами почти
	 * всегда закешированы, и до PHP запрос попросту не доходит.
	 * --------------------------------------------------------------------- */

	function collectGetParams() {
		if (!CFG.trackGet) return;

		var stored = {};
		var raw = readCookie(CFG.cookie);
		if (raw) {
			try { stored = JSON.parse(decodeURIComponent(raw)) || {}; } catch (e) { stored = {}; }
		}

		var incoming = new URLSearchParams(location.search);
		var changed = false;

		incoming.forEach(function (value, key) {
			key = String(key).replace(/[^A-Za-z0-9_\-.]/g, '').slice(0, 64);
			value = String(value).trim().slice(0, 255);
			if (!key || !value) return;

			if (!stored[key]) {
				if (Object.keys(stored).length >= CFG.maxParams) return;
				stored[key] = [];
			}
			// Значения именно дополняются: посетитель мог прийти сначала с
			// одной рекламы, потом с другой — в заявке нужны обе.
			if (stored[key].indexOf(value) === -1 && stored[key].length < CFG.maxValues) {
				stored[key].push(value);
				changed = true;
			}
		});

		if (changed) {
			var packed = encodeURIComponent(JSON.stringify(stored));
			if (packed.length <= COOKIE_LIMIT) {
				writeCookie(CFG.cookie, packed, 3650);
			}
		}
	}

	/**
	 * Момент первого захода на сайт. По нему считается, сколько посетитель
	 * провёл на сайте, — это нужно ловушке на слишком быструю отправку.
	 */
	function markFirstSeen() {
		if (!readCookie(CFG.seenCookie)) {
			writeCookie(CFG.seenCookie, String(Math.floor(Date.now() / 1000)), 3650);
		}
	}

	function secondsOnSite() {
		var seen = parseInt(readCookie(CFG.seenCookie), 10);
		if (!seen) return 0;
		return Math.max(0, Math.floor(Date.now() / 1000) - seen);
	}

	/* --------------------------------------------------------------------- *
	 * Капча
	 * --------------------------------------------------------------------- */

	function loadCaptcha() {
		var c = CFG.captcha;
		if (!c || c.provider === 'none' || !c.script) return;

		// Обе службы зовут этот обработчик, когда их скрипт готов.
		window.sfFsCaptchaReady = function () {
			captchaReady = true;
			captchaQueue.splice(0).forEach(function (fn) { fn(); });
		};

		var script = document.createElement('script');
		script.src = c.script;
		script.async = true;
		script.defer = true;
		document.head.appendChild(script);

		// v3 не вызывает onload: там готовность отслеживает сам grecaptcha.
		if (c.provider === 'google' && c.version === 'v3') {
			script.addEventListener('load', function () {
				if (window.grecaptcha && window.grecaptcha.ready) {
					window.grecaptcha.ready(window.sfFsCaptchaReady);
				}
			});
		}
	}

	function whenCaptchaReady(fn) {
		if (captchaReady) fn();
		else captchaQueue.push(fn);
	}

	function renderCaptcha(item) {
		var c = CFG.captcha;
		if (!c || c.provider === 'none') return;

		// v3 ничего не рисует: она молча оценивает поведение посетителя.
		if (c.provider === 'google' && c.version === 'v3') return;

		whenCaptchaReady(function () {
			if (c.provider === 'yandex' && window.smartCaptcha) {
				item.captchaId = window.smartCaptcha.render(item.captchaBox, {
					sitekey: c.sitekey,
					hl: c.lang
				});
			} else if (c.provider === 'google' && window.grecaptcha) {
				item.captchaId = window.grecaptcha.render(item.captchaBox, {
					sitekey: c.sitekey
				});
			}
		});
	}

	/**
	 * Ответ капчи для отправки. Для v3 это обещание, для остальных — строка.
	 */
	function captchaToken(item) {
		var c = CFG.captcha;
		if (!c || c.provider === 'none') return Promise.resolve('');

		if (c.provider === 'google' && c.version === 'v3') {
			if (!window.grecaptcha || !window.grecaptcha.execute) return Promise.resolve('');
			return window.grecaptcha.execute(c.sitekey, { action: 'submit' });
		}

		if (item.captchaId === null || item.captchaId === undefined) return Promise.resolve('');

		var api = c.provider === 'yandex' ? window.smartCaptcha : window.grecaptcha;
		return Promise.resolve(api && api.getResponse ? api.getResponse(item.captchaId) || '' : '');
	}

	function resetCaptcha(item) {
		var c = CFG.captcha;
		if (!c || item.captchaId === null || item.captchaId === undefined) return;
		var api = c.provider === 'yandex' ? window.smartCaptcha : window.grecaptcha;
		if (api && api.reset) api.reset(item.captchaId);
	}

	/* --------------------------------------------------------------------- *
	 * Подготовка формы
	 * --------------------------------------------------------------------- */

	/**
	 * Имя формы.
	 *
	 * Вебмастеру достаточно поставить один и тот же атрибут на все формы —
	 * различать их плагин умеет сам. Но если он написал значение атрибута,
	 * это имя и используется: так удобнее разбирать заявки.
	 */
	function formName(form, index) {
		for (var i = 0; i < CFG.attrs.length; i++) {
			var value = form.getAttribute(CFG.attrs[i]);
			if (value) return String(value).slice(0, 64);
		}
		return 'form-' + index;
	}

	/**
	 * Контейнер уведомления: свой, если вебмастер его сделал, иначе новый над
	 * кнопкой отправки.
	 */
	function ensureBox(form, cls, role) {
		var box = form.querySelector('.' + cls);
		if (!box) {
			box = document.createElement('div');
			box.className = cls + ' sf-fs-box';
			var submit = form.querySelector('[type="submit"], button:not([type="button"]):not([type="reset"])');
			if (submit && submit.parentNode) {
				// Кнопка часто завёрнута в обёртку с отступами — встаём перед
				// самой верхней обёрткой, чтобы уведомление не оказалось
				// внутри кнопки.
				var anchor = submit;
				while (anchor.parentNode && anchor.parentNode !== form) anchor = anchor.parentNode;
				form.insertBefore(box, anchor);
			} else {
				form.appendChild(box);
			}
		}
		if (role) box.setAttribute('role', role);
		box.hidden = true;
		return box;
	}

	function ensureCaptchaBox(form) {
		if (!CFG.captcha || CFG.captcha.provider === 'none') return null;
		var cls = CFG.classes.captcha;
		var box = form.querySelector('.' + cls);
		if (!box) {
			box = document.createElement('div');
			box.className = cls;
			var submit = form.querySelector('[type="submit"], button:not([type="button"]):not([type="reset"])');
			if (submit && submit.parentNode) {
				var anchor = submit;
				while (anchor.parentNode && anchor.parentNode !== form) anchor = anchor.parentNode;
				form.insertBefore(box, anchor);
			} else {
				form.appendChild(box);
			}
		}
		return box;
	}

	/**
	 * Поле-приманка. Человек его не видит, робот заполняет.
	 */
	function ensureHoneypot(form) {
		if (!CFG.honeypot) return;
		if (form.querySelector('[name="' + CFG.honeypot + '"]')) return;

		var wrap = document.createElement('div');
		wrap.className = 'sf-fs-hp';
		wrap.setAttribute('aria-hidden', 'true');

		var input = document.createElement('input');
		input.type = 'text';
		input.name = CFG.honeypot;
		input.value = '';
		input.tabIndex = -1;
		input.autocomplete = 'off';

		wrap.appendChild(input);
		form.appendChild(wrap);
	}

	/**
	 * Подписи полей — из тега label рядом с полем.
	 *
	 * Администратор увидит в списке заявок «Ваше имя», а не «name», и ему не
	 * придётся сопоставлять имена полей вручную. Переименовать всё равно
	 * можно — в настройках колонок.
	 */
	function collectLabels(form) {
		var map = {};
		var controls = form.querySelectorAll('input[name], textarea[name], select[name]');

		Array.prototype.forEach.call(controls, function (el) {
			var name = el.name;
			if (!name || map[name] || el.type === 'hidden' || name === CFG.honeypot) return;

			var label = null;
			if (el.id && window.CSS && CSS.escape) {
				label = form.querySelector('label[for="' + CSS.escape(el.id) + '"]');
			}
			if (!label) label = el.closest('label');
			if (!label) return;

			var value = text(label).replace(/[\s*:]+$/, '');
			if (value) map[name] = value.slice(0, 100);
		});

		return map;
	}

	function attach(form) {
		if (form.hasAttribute('data-sf-fs-ready')) return;
		form.setAttribute('data-sf-fs-ready', '1');

		var item = {
			form: form,
			name: formName(form, forms.length + 1),
			fail: ensureBox(form, CFG.classes.fail, 'alert'),
			ok: ensureBox(form, CFG.classes.success, 'status'),
			captchaBox: ensureCaptchaBox(form),
			captchaId: null,
			busy: false
		};

		form.setAttribute('data-sf-form-id', item.name);
		ensureHoneypot(form);
		if (item.captchaBox) renderCaptcha(item);

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			send(item);
		});

		forms.push(item);
	}

	function scan() {
		Array.prototype.forEach.call(document.querySelectorAll(CFG.selector), attach);
	}

	/* --------------------------------------------------------------------- *
	 * Отправка
	 * --------------------------------------------------------------------- */

	/*
	 * Показываем и прячем контейнеры одним только атрибутом hidden — тем же,
	 * которым пользуется вебмастер. Свой класс с display:none тут был бы
	 * ловушкой: тема сняла бы hidden, про чужой класс не зная, и её
	 * собственное сообщение осталось бы невидимым.
	 */

	function show(box, message) {
		if (!box) return;
		box.textContent = message;
		box.hidden = false;
	}

	function hide(box) {
		if (!box) return;
		box.hidden = true;
	}

	function emit(form, name, detail) {
		var event;
		try {
			event = new CustomEvent(name, { bubbles: true, cancelable: true, detail: detail });
		} catch (e) {
			event = document.createEvent('CustomEvent');
			event.initCustomEvent(name, true, true, detail);
		}
		return form.dispatchEvent(event);
	}

	function buttons(item) {
		return item.form.querySelectorAll('[type="submit"], button:not([type="button"]):not([type="reset"])');
	}

	function setBusy(item, busy) {
		item.busy = busy;
		Array.prototype.forEach.call(buttons(item), function (btn) {
			btn.disabled = busy;
			if (!CFG.texts.sending) return;
			if (busy) {
				btn.setAttribute('data-sf-fs-label', btn.innerHTML);
				btn.textContent = CFG.texts.sending;
			} else if (btn.hasAttribute('data-sf-fs-label')) {
				btn.innerHTML = btn.getAttribute('data-sf-fs-label');
				btn.removeAttribute('data-sf-fs-label');
			}
		});
	}

	/*
	 * Сюда мы попадаем уже после проверки заполнения.
	 *
	 * Обязательные поля, типы и шаблоны — дело браузера: при незаполненном
	 * required он вообще не даёт событию submit случиться, сам переводит
	 * курсор в поле и показывает свою подсказку. Плагин в это не вмешивается
	 * и ничего не подменяет. Если вебмастер поставил форме novalidate и
	 * проверяет её своим скриптом — его проверка живёт в обработчике события
	 * sf-form-sender:before-send и точно так же может отменить отправку.
	 *
	 * Свои сообщения плагин показывает только про то, что задано в его
	 * настройках: капча, файлы, антиспам, почта.
	 */
	function send(item) {
		if (item.busy) return;

		hide(item.fail);
		hide(item.ok);

		if (!emit(item.form, 'sf-form-sender:before-send', { name: item.name })) return;

		setBusy(item, true);

		captchaToken(item).then(function (token) {
			var data = new FormData(item.form);
			data.append('action', CFG.action);
			data.append('sf_fs_ticket', CFG.ticket);
			data.append('sf_fs_form', item.name);
			data.append('sf_fs_elapsed', String(secondsOnSite()));
			data.append('sf_fs_labels', JSON.stringify(collectLabels(item.form)));
			data.append('sf_fs_page', location.href);
			data.append('sf_fs_title', document.title);
			if (token) data.append(CFG.captchaField, token);

			return fetch(CFG.ajaxUrl, {
				method: 'POST',
				body: data,
				credentials: 'same-origin'
			});
		}).then(function (response) {
			return response.json();
		}).then(function (result) {
			setBusy(item, false);

			if (result && result.success) {
				show(item.ok, result.message);
				item.form.reset();
				resetCaptcha(item);
				emit(item.form, 'sf-form-sender:success', {
					name: item.name,
					message: result.message,
					id: result.id || 0
				});
				return;
			}

			var message = (result && result.message) || CFG.texts.error;
			show(item.fail, message);
			resetCaptcha(item);
			emit(item.form, 'sf-form-sender:error', {
				name: item.name,
				message: message,
				code: (result && result.code) || 'error'
			});
		}).catch(function () {
			setBusy(item, false);
			show(item.fail, CFG.texts.network);
			emit(item.form, 'sf-form-sender:error', {
				name: item.name,
				message: CFG.texts.network,
				code: 'network'
			});
		});
	}

	/* --------------------------------------------------------------------- */

	function boot() {
		collectGetParams();
		markFirstSeen();
		loadCaptcha();
		scan();

		// Формы могут появиться позже: модальное окно, подгруженный блок,
		// конструктор страниц. Пересчёт откладываем до ближайшей отрисовки —
		// иначе на бойкой странице scan() дёргался бы сотни раз подряд.
		if (window.MutationObserver) {
			var pending = false;
			new MutationObserver(function () {
				if (pending) return;
				pending = true;
				window.requestAnimationFrame(function () {
					pending = false;
					scan();
				});
			}).observe(document.documentElement, { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
