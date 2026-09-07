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
	 * Таблица заявок: прокрутка вбок и растягивание колонок мышью
	 * --------------------------------------------------------------------- */

	function initTable() {
		var wrap = $('#sf-fs-scroll');
		var table = $('#sf-fs-table');
		if (!wrap || !table) return;

		var view = $('.sf-fs-scroll__view', wrap);
		if (!view) return;

		var min = CFG.widthMin || 40;
		var max = CFG.widthMax || 1200;

		var cols = {};
		$$('col[data-key]', table).forEach(function (col) {
			cols[col.getAttribute('data-key')] = col;
		});

		/* Затемнение горит с той стороны, куда таблица ещё не прокручена. */
		function fades() {
			var rest = view.scrollWidth - view.clientWidth;
			wrap.classList.toggle('has-left', view.scrollLeft > 1);
			wrap.classList.toggle('has-right', rest > 1 && view.scrollLeft < rest - 1);
		}

		/* Таблица не уже суммы своих колонок — иначе браузер сожмёт их сам. */
		function least() {
			var sum = 32;
			$$('col[data-key]', table).forEach(function (col) {
				sum += parseInt(col.style.width, 10) || 0;
			});
			table.style.minWidth = sum + 'px';
		}

		/* Ширины целиком: вернувшаяся к своей ширине по умолчанию уходит
		   нулём и в настройках не оседает. */
		function widths() {
			var out = {};
			$$('th[data-key]', table).forEach(function (th) {
				var key = th.getAttribute('data-key');
				var col = cols[key];
				if (!col) return;
				var px = parseInt(col.style.width, 10) || 0;
				out[key] = px === (parseInt(th.getAttribute('data-default-width'), 10) || 0) ? 0 : px;
			});
			return out;
		}

		var pending = null;

		function save() {
			if (!CFG.widthNonce || !CFG.widthAction) return;

			// Границу тянут рывками: складываем правки в одну отправку.
			window.clearTimeout(pending);
			pending = window.setTimeout(function () {
				var body = new URLSearchParams();
				body.set('action', CFG.widthAction);
				body.set('_wpnonce', CFG.widthNonce);

				var map = widths();
				Object.keys(map).forEach(function (key) {
					body.set('widths[' + key + ']', map[key]);
				});

				fetch(CFG.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				}).catch(function () {
					// Ширина колонки не то, ради чего стоит пугать человека
					// сообщением: не сохранилось — вернётся прежняя.
				});
			}, 400);
		}

		function apply(col, width) {
			col.style.width = Math.max(min, Math.min(max, Math.round(width))) + 'px';
			least();
			fades();
		}

		var drag = null;

		table.addEventListener('pointerdown', function (e) {
			var grip = e.target.closest ? e.target.closest('[data-sf-resize]') : null;
			if (!grip || (e.button !== undefined && e.button !== 0)) return;

			var th = grip.closest('th[data-key]');
			var col = th && cols[th.getAttribute('data-key')];
			if (!col) return;

			e.preventDefault();
			drag = {
				col: col,
				th: th,
				grip: grip,
				id: e.pointerId,
				x: e.clientX,
				width: th.getBoundingClientRect().width
			};

			th.classList.add('is-resizing');
			document.body.classList.add('sf-fs-resizing');
			if (grip.setPointerCapture) grip.setPointerCapture(e.pointerId);
		});

		table.addEventListener('pointermove', function (e) {
			if (!drag) return;
			apply(drag.col, drag.width + (e.clientX - drag.x));
		});

		function stop() {
			if (!drag) return;
			drag.th.classList.remove('is-resizing');
			document.body.classList.remove('sf-fs-resizing');
			if (drag.grip.releasePointerCapture) {
				try { drag.grip.releasePointerCapture(drag.id); } catch (err) { /* указателя уже нет */ }
			}
			drag = null;
			save();
		}

		table.addEventListener('pointerup', stop);
		table.addEventListener('pointercancel', stop);

		/* Двойной щелчок по границе — ширина по умолчанию, как в таблицах. */
		table.addEventListener('dblclick', function (e) {
			var grip = e.target.closest ? e.target.closest('[data-sf-resize]') : null;
			if (!grip) return;

			var th = grip.closest('th[data-key]');
			var col = th && cols[th.getAttribute('data-key')];
			if (!col) return;

			apply(col, parseInt(th.getAttribute('data-default-width'), 10) || 120);
			save();
		});

		view.addEventListener('scroll', fades);
		window.addEventListener('resize', fades);
		least();
		fades();
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
		initTable();
		initList();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
