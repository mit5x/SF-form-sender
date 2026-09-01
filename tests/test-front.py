"""Перехват форм на стороне браузера: проверка front.js.

Всё, что делает скрипт, видно только в живом браузере: он ищет формы,
дописывает в них недостающее, копит рекламные метки в cookie и отправляет
данные без перезагрузки страницы. Поэтому стенд открывается настоящим
Chromium, а сервер и ответ на отправку подставные.

Стенд поднимается по http, а не открывается файлом: cookie на file:// не
работают, а метки как раз в cookie и живут.

Запуск (нужны playwright и chromium):
    python3 tests/test-front.py
"""

import http.server
import os
import pathlib
import shutil
import socketserver
import sys
import tempfile
import threading

from playwright.sync_api import sync_playwright

HERE = pathlib.Path(__file__).resolve().parent
PLUGIN = HERE.parent / 'sf-form-sender'
# Путь к Chromium: свой, если задан и существует, иначе тот, который
# playwright поставил себе сам, — так тест работает и в CI, и локально.
CHROME = os.environ.get('SF_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome')

WORK = pathlib.Path(tempfile.mkdtemp(prefix='sf-front-'))
shutil.copy(PLUGIN / 'assets/js/front.js', WORK / 'front.js')
shutil.copy(PLUGIN / 'assets/css/front.css', WORK / 'front.css')
shutil.copy(HERE / 'front-fixture.html', WORK / 'index.html')

ok, bad = [], []


def check(name, condition, extra=''):
    (ok if condition else bad).append(name)
    print(('  ok   ' if condition else '  FAIL ') + name + (f' | {extra}' if extra and not condition else ''))


class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(WORK), **kwargs)

    def log_message(self, *args):
        pass


server = socketserver.TCPServer(('127.0.0.1', 0), Handler)
threading.Thread(target=server.serve_forever, daemon=True).start()
BASE = 'http://127.0.0.1:%d/index.html' % server.server_address[1]


with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME) if os.path.exists(CHROME) else p.chromium.launch()
    context = browser.new_context()

    def load(query=''):
        page = context.new_page()
        page.goto(BASE + query)
        page.wait_for_timeout(150)
        return page

    # ------------------------------------------------------------------
    print('\nПодготовка форм')
    page = load()

    check('форма без атрибута не тронута',
          page.evaluate("() => !document.getElementById('c').hasAttribute('data-sf-fs-ready')"))
    check('все отмеченные формы подключены',
          page.evaluate("() => document.querySelectorAll('form[data-sf-fs-ready]').length") == 3)

    check('форме без имени выдан номер',
          page.evaluate("() => document.getElementById('a').getAttribute('data-sf-form-id')") == 'form-1')
    check('имя из значения атрибута сохранено',
          page.evaluate("() => document.getElementById('b').getAttribute('data-sf-form-id')") == 'калькулятор')

    check('контейнеры созданы там, где их не было',
          page.evaluate("() => document.querySelectorAll('#a .sf_form_sender_interceptor_fail, #a .sf_form_sender_interceptor_success').length") == 2)
    check('созданные контейнеры получили оформление плагина',
          page.evaluate("() => document.querySelector('#a .sf_form_sender_interceptor_fail').classList.contains('sf-fs-box')"))
    check('созданные контейнеры встали перед обёрткой кнопки, а не внутри неё',
          page.evaluate("""() => {
              var kids = Array.from(document.getElementById('a').children);
              var wrap = kids.findIndex(function (el) { return el.className === 'wrap'; });
              var fail = kids.findIndex(function (el) { return el.classList.contains('sf_form_sender_interceptor_fail'); });
              var ok = kids.findIndex(function (el) { return el.classList.contains('sf_form_sender_interceptor_success'); });
              return fail > -1 && ok > -1 && fail < wrap && ok < wrap;
          }"""))
    check('плагин не отключает штатную проверку браузера',
          page.evaluate("() => document.getElementById('a').noValidate") is False)
    check('novalidate вебмастера плагин тоже не трогает',
          page.evaluate("() => document.getElementById('d').noValidate") is True)

    check('чужие контейнеры не продублированы',
          page.evaluate("() => document.querySelectorAll('#b .sf_form_sender_interceptor_fail').length") == 1)
    check('чужие контейнеры не получили оформление плагина',
          page.evaluate("() => !document.querySelector('#b .sf_form_sender_interceptor_fail').classList.contains('sf-fs-box')"))
    check('контейнеры спрятаны',
          page.evaluate("() => document.querySelector('#b .my-ok').hidden"))
    check('спрятанный контейнер действительно не виден',
          page.evaluate("() => getComputedStyle(document.querySelector('#a .sf_form_sender_interceptor_fail')).display") == 'none')

    # Плагин прячет контейнеры вебмастера тем же атрибутом hidden, которым
    # пользуется тема. Свой класс с display:none тут был бы ловушкой: тема
    # сняла бы hidden, а сообщение так и осталось бы невидимым.
    check('контейнер вебмастера показывается снятием одного лишь hidden',
          page.evaluate("""() => {
              var box = document.querySelector('#b .my-fail');
              box.textContent = 'сообщение темы';
              box.hidden = false;
              return getComputedStyle(box).display !== 'none';
          }"""))

    check('поле-приманка добавлено',
          page.evaluate("() => !!document.querySelector('#a input[name=\\'website\\']')"))
    check('приманка уведена за край экрана, а не спрятана',
          page.evaluate("() => getComputedStyle(document.querySelector('#a .sf-fs-hp')).position") == 'absolute')

    # ------------------------------------------------------------------
    print('\nРекламные метки')
    page = load('?utm_source=google&my_var=test')
    cookies = {c['name']: c['value'] for c in context.cookies()}
    check('метки записаны в cookie', 'utm_source' in cookies.get('sf_fs_track', ''), str(cookies))
    check('момент первого захода записан', cookies.get('sf_fs_seen', '').isdigit())
    first_seen = cookies.get('sf_fs_seen')

    page = load('?utm_source=yandex')
    track = {c['name']: c['value'] for c in context.cookies()}['sf_fs_track']
    from urllib.parse import unquote
    import json
    saved = json.loads(unquote(track))
    check('новое значение дописано к прежнему', saved['utm_source'] == ['google', 'yandex'], str(saved))
    check('прежний параметр не потерян', saved.get('my_var') == ['test'], str(saved))

    page = load('?utm_source=yandex')
    saved = json.loads(unquote({c['name']: c['value'] for c in context.cookies()}['sf_fs_track']))
    check('повторное значение не дублируется', saved['utm_source'] == ['google', 'yandex'], str(saved))
    check('момент первого захода не перезаписан',
          {c['name']: c['value'] for c in context.cookies()}.get('sf_fs_seen') == first_seen)

    # ------------------------------------------------------------------
    print('\nПроверку заполнения плагин не подменяет')
    page = load()
    page.click('#a button[type=submit]')
    page.wait_for_timeout(100)

    check('незаполненное required не уходит на сервер',
          page.evaluate('() => window.__sent.length') == 0)
    check('до плагина дело не дошло — событие не вызывалось',
          page.evaluate('() => window.__events.length') == 0)
    check('курсор переведён в незаполненное поле браузером',
          page.evaluate('() => document.activeElement.id') == 'a-name')
    check('плагин не написал ничего от себя',
          page.evaluate("() => document.querySelector('#a .sf_form_sender_interceptor_fail').textContent") == '')
    check('контейнер ошибки остался скрытым',
          page.evaluate("() => document.querySelector('#a .sf_form_sender_interceptor_fail').hidden"))

    page.fill('#a-name', 'Иван')
    page.fill('#a-mail', 'не почта')
    page.click('#a button[type=submit]')
    page.wait_for_timeout(100)
    check('неверный тип email браузер тоже отсекает сам',
          page.evaluate('() => window.__sent.length') == 0)
    check('курсор переведён в поле почты',
          page.evaluate('() => document.activeElement.id') == 'a-mail')

    # Форма с novalidate: браузер молчит, работает проверка вебмастера.
    page.click('#d button[type=submit]')
    page.wait_for_timeout(150)
    check('на форме с novalidate плагин спрашивает разрешения у вебмастера',
          page.evaluate('() => window.__ownCheck') == 'сработала своя проверка')
    check('своя проверка вебмастера отменяет отправку',
          page.evaluate('() => window.__sent.length') == 0)

    page.fill('#d-name', 'Иван')
    page.click('#d button[type=submit]')
    page.wait_for_timeout(200)
    check('после своей проверки форма уходит',
          page.evaluate('() => window.__sent.length') == 1)

    # ------------------------------------------------------------------
    print('\nОтправка')
    page = load()
    page.fill('#a-name', 'Иван')
    page.fill('#a-mail', 'ivan@example.com')
    page.click('#a button[type=submit]')
    page.wait_for_timeout(200)

    sent = page.evaluate('() => window.__sent[0]')
    check('запрос ушёл на admin-ajax', sent and sent['url'] == '/admin-ajax.php')
    body = sent['body'] if sent else {}
    check('назван обработчик', body.get('action') == 'sf_fs_submit')
    check('ключ формы передан', body.get('sf_fs_ticket') == '1000000.подпись')
    check('имя формы передано', body.get('sf_fs_form') == 'form-1')
    check('поля формы переданы', body.get('name') == 'Иван' and body.get('email') == 'ivan@example.com')
    check('адрес страницы передан', 'index.html' in body.get('sf_fs_page', ''))
    check('время на сайте передано', body.get('sf_fs_elapsed', '').isdigit())
    check('подписи полей собраны из тегов label',
          json.loads(body.get('sf_fs_labels', '{}')).get('name') == 'Ваше имя',
          body.get('sf_fs_labels'))
    check('приманка ушла пустой', body.get('website') == '')

    check('показано уведомление об успехе',
          page.inner_text('#a .sf_form_sender_interceptor_success').strip() == 'Спасибо, заявка принята.')
    check('контейнер ошибки остался скрытым',
          page.evaluate("() => document.querySelector('#a .sf_form_sender_interceptor_fail').hidden"))
    check('форма очищена', page.input_value('#a-name') == '')
    check('событие успеха вызвано с текстом и номером',
          'success:Спасибо, заявка принята.:42' in page.evaluate('() => window.__events'))
    check('подпись кнопки вернулась', page.inner_text('#a button[type=submit]').strip() == 'Отправить')
    check('кнопка снова доступна', page.evaluate("() => !document.querySelector('#a button').disabled"))

    # ------------------------------------------------------------------
    print('\nОтказы')
    page = load()
    page.evaluate("() => { window.__reply = { success: false, message: 'Капча не пройдена.', code: 'captcha' }; }")
    page.fill('#a-name', 'Иван')
    page.click('#a button[type=submit]')
    page.wait_for_timeout(200)

    check('показан текст ошибки от сервера',
          page.inner_text('#a .sf_form_sender_interceptor_fail').strip() == 'Капча не пройдена.')
    check('уведомление об успехе не показано',
          page.evaluate("() => document.querySelector('#a .sf_form_sender_interceptor_success').hidden"))
    check('форма не очищена', page.input_value('#a-name') == 'Иван')
    check('событие ошибки несёт код причины', 'error:captcha' in page.evaluate('() => window.__events'))

    page = load()
    page.evaluate('() => { window.__failNetwork = true; }')
    page.fill('#a-name', 'Иван')
    page.click('#a button[type=submit]')
    page.wait_for_timeout(200)
    check('сорванный запрос показывает свой текст',
          page.inner_text('#a .sf_form_sender_interceptor_fail').strip() == 'Сервер не ответил.')
    check('кнопка не осталась заблокированной',
          page.evaluate("() => !document.querySelector('#a button').disabled"))

    # ------------------------------------------------------------------
    print('\nВмешательство темы')
    page = load()
    page.evaluate('() => { window.__cancelA = true; }')
    page.fill('#a-name', 'Иван')
    page.click('#a button[type=submit]')
    page.wait_for_timeout(200)

    check('отменяемое событие вызвано', 'before-send' in page.evaluate('() => window.__events'))
    check('preventDefault отменяет отправку', page.evaluate('() => window.__sent.length') == 0)

    # ------------------------------------------------------------------
    print('\nФормы, появившиеся позже')
    page = load()
    page.evaluate("""() => {
        var form = document.createElement('form');
        form.id = 'late';
        form.setAttribute('sf-form-sender-interceptor', '');
        form.innerHTML = '<input name="name"><button type="submit">Отправить</button>';
        document.body.appendChild(form);
    }""")
    page.wait_for_timeout(200)

    check('форма из модального окна тоже подключена',
          page.evaluate("() => document.getElementById('late').hasAttribute('data-sf-fs-ready')"))
    check('ей выдан свой номер',
          page.evaluate("() => document.getElementById('late').getAttribute('data-sf-form-id')") == 'form-4')
    check('контейнеры добавлены и ей',
          page.evaluate("() => document.querySelectorAll('#late .sf-fs-box').length") == 2)

    browser.close()

server.shutdown()
shutil.rmtree(WORK, ignore_errors=True)

print('\n' + '─' * 60)
print('Пройдено: %d' % len(ok))
if bad:
    print('Провалено: %d' % len(bad))
    for name in bad:
        print('  · ' + name)
    sys.exit(1)
print('Все проверки пройдены.')
