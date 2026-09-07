"""Таблица заявок в админке: прокрутка, затемнения и ширина колонок.

Всё это видно только в живом браузере: прокрутка появляется от того, что
таблица шире окна, затемнение зажигается по её положению, а ширину колонки
меняют, протащив мышью границу. Проверить такое разбором HTML нельзя.

Разметку печатает сам плагин — `php tests/render-list.php` на заглушках
WordPress, — поэтому тест смотрит на настоящую страницу, а не на её копию.
Заглушены только вещи по ту сторону браузера: адрес admin-ajax отвечает
согласием, а тест смотрит, что именно ему отправили.

Запуск (нужны playwright и chromium):
    python3 tests/test-admin-table.py
"""

import http.server
import json
import os
import pathlib
import shutil
import socketserver
import subprocess
import sys
import tempfile
import threading

from playwright.sync_api import sync_playwright

HERE = pathlib.Path(__file__).resolve().parent
PLUGIN = HERE.parent / 'sf-form-sender'
CHROME = os.environ.get('SF_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome')

WORK = pathlib.Path(tempfile.mkdtemp(prefix='sf-admin-'))
shutil.copy(PLUGIN / 'assets/js/admin.js', WORK / 'admin.js')
shutil.copy(PLUGIN / 'assets/css/admin.css', WORK / 'admin.css')

ok, bad = [], []


def check(name, condition, extra=''):
    (ok if condition else bad).append(name)
    print(('  ok   ' if condition else '  FAIL ') + name + (f' | {extra}' if extra and not condition else ''))


def group(title):
    print('\n' + title)


# --- Стенд ---------------------------------------------------------------

page = subprocess.run(
    ['php', str(HERE / 'render-list.php')],
    capture_output=True, text=True, check=True,
).stdout

PAGE = """<!doctype html>
<html lang="ru"><head><meta charset="utf-8">
<link rel="stylesheet" href="admin.css">
<style>
  /* Немного из оформления самой админки: без него таблица не прижата
     к краям окна и ширины не сойдутся. */
  body { margin: 0; font: 13px sans-serif; }
  .wrap { margin: 0; padding: 0 20px 0 0; }
  table { border-collapse: collapse; }
  th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #dcdcde; }
</style>
</head><body>
__PAGE__
<script>
window.SF_FS_ADMIN = {
    ajaxUrl: '/ajax',
    widthAction: 'sf_fs_save_widths',
    widthNonce: 'test-nonce',
    widthMin: 40,
    widthMax: 1200,
    texts: {}
};
window.SF_SENT = [];
var realFetch = window.fetch;
window.fetch = function (url, options) {
    window.SF_SENT.push(String((options && options.body) || ''));
    return Promise.resolve({ ok: true, json: function () { return Promise.resolve({ success: true }); } });
};
</script>
<script src="admin.js"></script>
</body></html>
"""

(WORK / 'index.html').write_text(PAGE.replace('__PAGE__', page), encoding='utf-8')


class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(WORK), **kwargs)

    def log_message(self, *args):
        pass


class Server(socketserver.TCPServer):
    allow_reuse_address = True


server = Server(('127.0.0.1', 0), Handler)
threading.Thread(target=server.serve_forever, daemon=True).start()
BASE = 'http://127.0.0.1:%d/' % server.server_address[1]

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME) if os.path.exists(CHROME) else p.chromium.launch()
    context = browser.new_context(viewport={'width': 900, 'height': 800})
    page = context.new_page()
    page.goto(BASE, wait_until='load')

    view = page.locator('.sf-fs-scroll__view')
    wrap = page.locator('.sf-fs-scroll')

    def opacity(selector):
        return float(page.evaluate(
            's => getComputedStyle(document.querySelector(s)).opacity', selector))

    def opacity_settles(selector, visible):
        """Затемнение проявляется плавно, поэтому ждём конца перехода.

        Без ожидания проверка читает середину анимации, и на медленной
        машине получает 0.3 там, где через миг будет 1.
        """
        for _ in range(40):
            if (opacity(selector) > 0.5) is visible:
                return True
            page.wait_for_timeout(50)
        return (opacity(selector) > 0.5) is visible

    # ------------------------------------------------------------------
    group('Прокрутка таблицы вбок')

    metrics = view.evaluate('el => ({ scroll: el.scrollWidth, client: el.clientWidth })')
    check('таблица шире своей рамки', metrics['scroll'] > metrics['client'],
          json.dumps(metrics))
    check('рамка не шире окна', metrics['client'] <= 900, metrics['client'])
    check('страница вбок не разъезжается',
          page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'))

    # ------------------------------------------------------------------
    group('Затемнения по краям')

    check('справа затемнение есть', 'has-right' in (wrap.get_attribute('class') or ''))
    check('слева затемнения нет', 'has-left' not in (wrap.get_attribute('class') or ''))
    check('затемнение не перехватывает щелчки',
          page.evaluate("getComputedStyle(document.querySelector('.sf-fs-scroll__fade--right')).pointerEvents") == 'none')
    check('справа затемнение видно', opacity_settles('.sf-fs-scroll__fade--right', True))
    check('слева затемнение не видно', opacity_settles('.sf-fs-scroll__fade--left', False))

    view.evaluate('el => { el.scrollLeft = 200; }')
    page.wait_for_timeout(100)
    check('после прокрутки затемнение появилось слева', 'has-left' in (wrap.get_attribute('class') or ''))
    check('справа оно ещё горит', 'has-right' in (wrap.get_attribute('class') or ''))

    view.evaluate('el => { el.scrollLeft = el.scrollWidth; }')
    page.wait_for_timeout(100)
    check('у правого края правое затемнение погасло', 'has-right' not in (wrap.get_attribute('class') or ''))
    check('левое при этом горит', 'has-left' in (wrap.get_attribute('class') or ''))

    view.evaluate('el => { el.scrollLeft = 0; }')
    page.wait_for_timeout(100)
    check('вернулись к началу — левое погасло', 'has-left' not in (wrap.get_attribute('class') or ''))

    # ------------------------------------------------------------------
    group('Ширина колонки меняется мышью')

    col = page.locator('col[data-key="name"]')
    head = page.locator('th[data-key="name"]')
    before = head.bounding_box()['width']

    grip = head.locator('.sf-fs-col__grip')
    box = grip.bounding_box()
    page.mouse.move(box['x'] + box['width'] / 2, box['y'] + box['height'] / 2)
    page.mouse.down()
    page.mouse.move(box['x'] + box['width'] / 2 + 120, box['y'] + box['height'] / 2, steps=6)
    page.mouse.up()
    page.wait_for_timeout(100)

    after = head.bounding_box()['width']
    check('колонка стала шире примерно на столько, на сколько тянули',
          abs(after - before - 120) < 6, f'было {before}, стало {after}')
    check('ширина записана в col', (col.get_attribute('style') or '').startswith('width:'),
          col.get_attribute('style'))
    check('соседняя колонка не поехала',
          abs(page.locator('th[data-key="_date"]').bounding_box()['width'] - 150) < 2)

    page.wait_for_timeout(600)
    sent = page.evaluate('window.SF_SENT')
    check('ширину отправили на сохранение', any('sf_fs_save_widths' in body for body in sent), str(sent))
    check('в отправленном есть подпись', any('_wpnonce=test-nonce' in body for body in sent))
    check('в отправленном есть изменённая колонка',
          any('widths%5Bname%5D=' in body and 'widths%5Bname%5D=0' not in body for body in sent), str(sent))
    check('нетронутая колонка ушла нулём',
          any('widths%5B_date%5D=0' in body for body in sent), str(sent))
    check('на все рывки мыши ушёл один запрос', len(sent) == 1, str(len(sent)))

    # ------------------------------------------------------------------
    group('Пределы и возврат к ширине по умолчанию')

    page.evaluate('window.SF_SENT = []')
    box = grip.bounding_box()
    page.mouse.move(box['x'] + box['width'] / 2, box['y'] + box['height'] / 2)
    page.mouse.down()
    page.mouse.move(box['x'] - 600, box['y'] + box['height'] / 2, steps=8)
    page.mouse.up()
    page.wait_for_timeout(100)
    check('уже предела колонка не становится', head.bounding_box()['width'] >= 40,
          head.bounding_box()['width'])

    grip.dblclick()
    page.wait_for_timeout(700)
    check('двойной щелчок вернул ширину по умолчанию',
          abs(head.bounding_box()['width'] - 180) < 2, head.bounding_box()['width'])
    sent = page.evaluate('window.SF_SENT')
    check('возврат к умолчанию тоже сохраняется',
          any('widths%5Bname%5D=0' in body for body in sent), str(sent))

    # ------------------------------------------------------------------
    group('Содержимое ячеек')

    check('длинное значение не растягивает колонку',
          abs(page.locator('th[data-key="calc"]').bounding_box()['width'] - 180) < 2,
          page.locator('th[data-key="calc"]').bounding_box()['width'])
    check('в шапке подпись колонки, а не имя поля',
          page.locator('th[data-key="calc"] .sf-fs-col__label').inner_text().strip() == 'Калькулятор')

    browser.close()

server.shutdown()
shutil.rmtree(WORK, ignore_errors=True)

print('\n' + '─' * 60)
print('Пройдено: %d' % len(ok))
if bad:
    print('Не пройдено: %d' % len(bad))
    for name in bad:
        print('  · ' + name)
    sys.exit(1)
print('Все проверки пройдены.')
