/* global SF_FS_ADMIN */
/**
 * SF Form sender — поведение страниц админки.
 */
(function () {
	'use strict';

	var CFG = window.SF_FS_ADMIN || { texts: {} };

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	/* --------------------------------------------------------------------- *
	 * Подтверждение необратимых действий
	 * --------------------------------------------------------------------- */

	function initConfirm() {
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-sf-confirm]');
			if (!btn) return;
			if (!window.confirm(btn.getAttribute('data-sf-confirm'))) {
				e.preventDefault();
				e.stopPropagation();
			}
		}, true);
	}

	/* --------------------------------------------------------------------- *
	 * Поля, зависящие от переключателя
	 * --------------------------------------------------------------------- */

	function initDepends() {
		var rows = $$('[data-sf-depends]');
		if (!rows.length) return;

		function sync() {
			rows.forEach(function (row) {
				var master = $('[data-sf-key="' + row.getAttribute('data-sf-depends') + '"]');
				row.hidden = !(master && master.checked);
			});
		}

		document.addEventListener('change', function (e) {
			if (e.target.hasAttribute && e.target.hasAttribute('data-sf-key')) sync();
		});
		sync();
	}

	/**
	 * Две капчи в одном контейнере не уживаются: включая одну, выключаем другую.
	 */
	function initCaptchaChoice() {
		var yandex = $('[data-sf-key="yandex_captcha"]');
		var google = $('[data-sf-key="google_captcha"]');
		if (!yandex || !google) return;

		function pick(on, off) {
			on.addEventListener('change', function () {
				if (on.checked && off.checked) {
					off.checked = false;
					off.dispatchEvent(new Event('change', { bubbles: true }));
				}
			});
		}
		pick(yandex, google);
		pick(google, yandex);
	}

	/* --------------------------------------------------------------------- *
	 * Показать пароль
	 * --------------------------------------------------------------------- */

	function initPeek() {
		$$('.sf-fs-peek').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var input = document.getElementById(btn.getAttribute('data-target'));
				if (!input) return;
				input.type = input.type === 'password' ? 'text' : 'password';
			});
		});
	}

	/* --------------------------------------------------------------------- *
	 * Проверка SMTP
	 * --------------------------------------------------------------------- */

	function initSmtp() {
		var run = $('#sf-fs-smtp-run');
		var log = $('#sf-fs-smtp-log');
		var result = $('#sf-fs-smtp-result');
		var warn = $('#sf-fs-smtp-warn');
		if (!run || !log) return;

		// Отслеживание несохранённых правок: проверка идёт по тому, что лежит
		// в базе, а не по тому, что сейчас в полях.
		var form = $('.sf-fs-form');
		if (form && warn) {
			var initial = new FormData(form);
			var snapshot = JSON.stringify(Array.from(initial.entries()));

			form.addEventListener('input', function () {
				var now = JSON.stringify(Array.from(new FormData(form).entries()));
				warn.classList.toggle('is-dirty', now !== snapshot);
			});
		}

		run.addEventListener('click', function () {
			run.disabled = true;
			log.hidden = false;
			log.textContent = CFG.texts.testing + '\n';
			if (result) { result.hidden = true; result.textContent = ''; }

			var body = new URLSearchParams();
			body.set('action', 'sf_fs_smtp_test');
			body.set('_wpnonce', CFG.smtpNonce);

			fetch(CFG.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			}).then(function (response) {
				if (!response.body || !response.body.getReader) {
					// Браузер без потокового чтения — покажем лог целиком.
					return response.text().then(function (text) { paint(text); });
				}

				var reader = response.body.getReader();
				var decoder = new TextDecoder('utf-8');
				var buffer = '';

				function pump() {
					return reader.read().then(function (chunk) {
						if (chunk.done) { paint(buffer); return; }
						buffer += decoder.decode(chunk.value, { stream: true });
						paint(buffer);
						return pump();
					});
				}
				return pump();
			}).catch(function () {
				finish(false, CFG.texts.testFailed);
			}).then(function () {
				run.disabled = false;
			});

			function paint(text) {
				var mark = '___SF_FS_RESULT___';
				var at = text.indexOf(mark);

				if (at === -1) {
					log.textContent = text;
					log.scrollTop = log.scrollHeight;
					return;
				}

				log.textContent = text.slice(0, at);
				log.scrollTop = log.scrollHeight;

				try {
					var data = JSON.parse(text.slice(at + mark.length).trim());
					finish(!!data.ok, data.message);
				} catch (e) {
					finish(false, CFG.texts.testFailed);
				}
			}

			function finish(ok, message) {
				if (!result) return;
				result.hidden = false;
				result.textContent = message;
				result.className = 'sf-fs-smtp-test__result ' + (ok ? 'is-ok' : 'is-fail');
			}
		});
	}

	/* --------------------------------------------------------------------- *
	 * Настройка колонок таблицы заявок
	 * --------------------------------------------------------------------- */

	function initColumns() {
		var list = $('#sf-fs-column-list');
		var order = $('#sf-fs-column-order');
		if (!list || !order) return;

		function sync() {
			order.value = $$('li', list).map(function (li) {
				return li.getAttribute('data-key');
			}).join(',');
		}

		list.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-sf-move]');
			if (!btn) return;

			var li = btn.closest('li');
			if (btn.getAttribute('data-sf-move') === 'up') {
				if (li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
			} else if (li.nextElementSibling) {
				list.insertBefore(li.nextElementSibling, li);
			}
			sync();
		});

		sync();
	}

	/* --------------------------------------------------------------------- *
	 * Список заявок
	 * --------------------------------------------------------------------- */

	function initList() {
		var form = $('#sf-fs-list-form');
		if (!form) return;

		var what = $('#sf-fs-what', form);
		var all = $('#sf-fs-check-all', form);

		if (all) {
			all.addEventListener('change', function () {
				$$('input[name="ids[]"]', form).forEach(function (box) { box.checked = all.checked; });
			});
		}

		form.addEventListener('click', function (e) {
			var one = e.target.closest('[data-sf-one]');
			if (one) {
				// Кнопка «Удалить» в строке: отмечаем только эту заявку.
				$$('input[name="ids[]"]', form).forEach(function (box) {
					box.checked = box.value === one.getAttribute('data-sf-one');
				});
				what.value = 'delete';
				return;
			}

			if (e.target.closest('[data-sf-all]')) {
				what.value = 'delete_all';
				return;
			}

			var bulk = e.target.closest('[data-sf-bulk]');
			if (bulk) {
				what.value = 'delete';
				var picked = $$('input[name="ids[]"]:checked', form).length;
				if (!picked) {
					e.preventDefault();
					window.alert(CFG.texts.nothingPicked);
				}
			}
		});
	}

	/* --------------------------------------------------------------------- */

	function boot() {
		initConfirm();
		initDepends();
		initCaptchaChoice();
		initPeek();
		initSmtp();
		initColumns();
		initList();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
