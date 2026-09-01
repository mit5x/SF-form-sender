#!/usr/bin/env python3
"""Заливка готовых переводов в .po по порядку строк каталога.

Вспомогательный инструмент первичного перевода: JSON — это массив строк
ровно в том порядке, в котором их выводит `build-languages.py --list`.
Для строк с множественным числом элемент массива сам является массивом форм.

    python3 tools/fill-translations.py de_DE /путь/de.json

Дальше .po правится как обычный файл перевода, и этот скрипт больше не нужен.
"""

import importlib.util
import json
import os
import sys

# Имя соседнего модуля с дефисом, обычным import его не взять.
spec = importlib.util.spec_from_file_location(
    'build_languages', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'build-languages.py')
)
build = importlib.util.module_from_spec(spec)
spec.loader.exec_module(build)


def main():
    if len(sys.argv) < 3:
        print(__doc__)
        return 1

    locale, path = sys.argv[1], sys.argv[2]
    keys = build.collect()
    values = json.load(open(path, encoding='utf-8'))

    if len(values) != len(keys):
        print('Ожидалось %d строк, получено %d.' % (len(keys), len(values)))
        return 1

    known = {}
    for key, value in zip(keys, values):
        if isinstance(key, tuple):
            known[key] = value if isinstance(value, list) else [value, value]
        else:
            known[key] = value

    po_path = os.path.join(build.LANGDIR, '%s-%s.po' % (build.DOMAIN, locale))
    build.write_po(po_path, locale, keys, known)
    count = build.compile_mo(
        po_path,
        os.path.join(build.LANGDIR, '%s-%s.mo' % (build.DOMAIN, locale)),
        locale,
    )
    print('%s: записано %d строк' % (locale, count - 1))
    return 0


if __name__ == '__main__':
    sys.exit(main())
