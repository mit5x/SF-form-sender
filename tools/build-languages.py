#!/usr/bin/env python3
"""Сборка языковых файлов плагина SF Form sender.

Что делает:
  1. собирает строки из PHP-файлов плагина в languages/sf-form-sender.pot;
  2. подмешивает новые строки в существующие .po, не трогая переводы;
  3. компилирует .mo рядом с каждым .po.

Запуск из каталога plugins:
    python3 tools/build-languages.py

Исходный язык строк — русский, поэтому каталога ru_RU нет: без словаря
gettext отдаёт исходную строку, а она и есть перевод.
"""

import os
import re
import struct
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(ROOT, 'sf-form-sender')
LANGDIR = os.path.join(PLUGIN, 'languages')
DOMAIN = 'sf-form-sender'

# Функции перевода с одной формой и с двумя.
SINGLE = re.compile(
    r"\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'" + DOMAIN + r"'"
)
PLURAL = re.compile(
    r"\b_n\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,"
)
# Тексты уведомлений лежат в схеме значениями ключа default и переводятся
# через SF_FS_I18n::translate_in(), поэтому в каталог их нужно добавить тоже.
DEFAULTS = re.compile(r"'default'\s*=>\s*'((?:[^'\\]|\\.)*)'")

PLURAL_RULES = {
    'en_US': ('nplurals=2; plural=(n != 1);', 2),
    'de_DE': ('nplurals=2; plural=(n != 1);', 2),
    'es_ES': ('nplurals=2; plural=(n != 1);', 2),
    'fr_FR': ('nplurals=2; plural=(n > 1);', 2),
    'it_IT': ('nplurals=2; plural=(n != 1);', 2),
    'pt_BR': ('nplurals=2; plural=(n > 1);', 2),
    'zh_CN': ('nplurals=1; plural=0;', 1),
}


def unescape(text):
    """Строка из исходника PHP в одинарных кавычках."""
    return text.replace("\\'", "'").replace('\\\\', '\\')


def php_files():
    for base, _dirs, names in os.walk(PLUGIN):
        for name in sorted(names):
            if name.endswith('.php'):
                yield os.path.join(base, name)


def collect():
    """Все строки плагина в порядке появления.

    Возвращает список ключей: строка или пара строк для множественного числа.
    """
    seen, out = set(), []

    for path in sorted(php_files()):
        src = open(path, encoding='utf-8').read()
        found = []

        for match in SINGLE.finditer(src):
            found.append((match.start(), unescape(match.group(1))))
        for match in PLURAL.finditer(src):
            found.append((match.start(), (unescape(match.group(1)), unescape(match.group(2)))))
        if path.endswith('class-sf-fs-notices.php'):
            for match in DEFAULTS.finditer(src):
                found.append((match.start(), unescape(match.group(1))))

        for _pos, key in sorted(found):
            if key not in seen:
                seen.add(key)
                out.append(key)

    return out


def po_escape(text):
    return (text.replace('\\', '\\\\')
                .replace('"', '\\"')
                .replace('\n', '\\n'))


def write_pot(keys):
    lines = [
        '# Строки интерфейса SF Form sender.',
        '# Исходный язык — русский.',
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: SF Form sender\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"X-Domain: %s\\n"' % DOMAIN,
        '',
    ]
    for key in keys:
        if isinstance(key, tuple):
            lines.append('msgid "%s"' % po_escape(key[0]))
            lines.append('msgid_plural "%s"' % po_escape(key[1]))
            lines.append('msgstr[0] ""')
            lines.append('msgstr[1] ""')
        else:
            lines.append('msgid "%s"' % po_escape(key))
            lines.append('msgstr ""')
        lines.append('')

    os.makedirs(LANGDIR, exist_ok=True)
    with open(os.path.join(LANGDIR, DOMAIN + '.pot'), 'w', encoding='utf-8') as handle:
        handle.write('\n'.join(lines))


def parse_po(path):
    """Разбор .po: msgid → строка перевода или список форм."""
    entries, current = {}, None
    key = plural_key = None
    forms = {}
    buffer_target = None

    def flush():
        if key is None:
            return
        if plural_key is not None:
            entries[(key, plural_key)] = [forms.get(i, '') for i in sorted(forms)]
        else:
            entries[key] = forms.get(0, '')

    for raw in open(path, encoding='utf-8'):
        line = raw.strip()
        if not line or line.startswith('#'):
            continue

        if line.startswith('msgid_plural '):
            plural_key = eval_po_string(line[len('msgid_plural '):])
            buffer_target = ('plural_key', None)
            continue
        if line.startswith('msgid '):
            flush()
            key, plural_key, forms = eval_po_string(line[len('msgid '):]), None, {}
            buffer_target = ('key', None)
            continue
        if line.startswith('msgstr['):
            index = int(line[line.index('[') + 1:line.index(']')])
            forms[index] = eval_po_string(line[line.index(']') + 2:])
            buffer_target = ('form', index)
            continue
        if line.startswith('msgstr '):
            forms[0] = eval_po_string(line[len('msgstr '):])
            buffer_target = ('form', 0)
            continue
        if line.startswith('"') and buffer_target:
            piece = eval_po_string(line)
            kind, index = buffer_target
            if kind == 'key':
                key += piece
            elif kind == 'plural_key':
                plural_key += piece
            else:
                forms[index] = forms.get(index, '') + piece

    flush()
    entries.pop('', None)
    return entries


def eval_po_string(chunk):
    chunk = chunk.strip()
    if not chunk.startswith('"'):
        return ''
    body = chunk[1:chunk.rindex('"')]
    return (body.replace('\\n', '\n').replace('\\"', '"').replace('\\\\', '\\'))


def write_po(path, locale, keys, known):
    header, nplurals = PLURAL_RULES[locale]
    lines = [
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: SF Form sender\\n"',
        '"Language: %s\\n"' % locale,
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"Plural-Forms: %s\\n"' % header,
        '',
    ]

    for key in keys:
        if isinstance(key, tuple):
            value = known.get(key, [''] * nplurals)
            lines.append('msgid "%s"' % po_escape(key[0]))
            lines.append('msgid_plural "%s"' % po_escape(key[1]))
            for i in range(nplurals):
                lines.append('msgstr[%d] "%s"' % (i, po_escape(value[i] if i < len(value) else '')))
        else:
            lines.append('msgid "%s"' % po_escape(key))
            lines.append('msgstr "%s"' % po_escape(known.get(key, '')))
        lines.append('')

    with open(path, 'w', encoding='utf-8') as handle:
        handle.write('\n'.join(lines))


def compile_mo(po_path, mo_path, locale):
    """Сборка .mo. Формат простой, внешние утилиты не нужны."""
    entries = parse_po(po_path)
    header, nplurals = PLURAL_RULES[locale]

    table = {'': 'Content-Type: text/plain; charset=UTF-8\nPlural-Forms: %s\n' % header}

    for key, value in entries.items():
        if isinstance(key, tuple):
            forms = [v for v in value]
            if not any(forms):
                continue
            table[key[0] + '\x00' + key[1]] = '\x00'.join(forms[:nplurals])
        elif value:
            table[key] = value

    items = sorted(table.items())
    ids = b''
    strs = b''
    offsets = []

    for key, value in items:
        key_bytes = key.encode('utf-8')
        value_bytes = value.encode('utf-8')
        offsets.append((len(ids), len(key_bytes), len(strs), len(value_bytes)))
        ids += key_bytes + b'\x00'
        strs += value_bytes + b'\x00'

    count = len(items)
    key_start = 7 * 4 + 16 * count
    value_start = key_start + len(ids)

    key_table = b''
    value_table = b''
    for offset, length, value_offset, value_length in offsets:
        key_table += struct.pack('<II', length, offset + key_start)
        value_table += struct.pack('<II', value_length, value_offset + value_start)

    output = struct.pack('<Iiiiiii', 0x950412de, 0, count, 7 * 4, 7 * 4 + count * 8, 0, 0)
    output += key_table + value_table + ids + strs

    with open(mo_path, 'wb') as handle:
        handle.write(output)

    return count


def main():
    keys = collect()

    # Порядок строк каталога — он же порядок массива для fill-translations.py.
    if '--list' in sys.argv:
        for index, key in enumerate(keys):
            print('%d | %s' % (index, key[0] + ' ||| ' + key[1] if isinstance(key, tuple) else key))
        return 0

    write_pot(keys)
    print('строк в каталоге: %d' % len(keys))

    for locale in sorted(PLURAL_RULES):
        po_path = os.path.join(LANGDIR, '%s-%s.po' % (DOMAIN, locale))
        mo_path = os.path.join(LANGDIR, '%s-%s.mo' % (DOMAIN, locale))

        known = parse_po(po_path) if os.path.exists(po_path) else {}
        write_po(po_path, locale, keys, known)
        count = compile_mo(po_path, mo_path, locale)

        missing = sum(
            1 for key in keys
            if (isinstance(key, tuple) and not any(known.get(key, [])))
            or (not isinstance(key, tuple) and not known.get(key))
        )
        print('%s: переведено %d, без перевода %d' % (locale, count - 1, missing))

    return 0


if __name__ == '__main__':
    sys.exit(main())
