#!/usr/bin/env python3
"""Set language keys in the module language files: python3 build/set_lang.py strings.py  (module with EN / UK dicts)."""
import importlib.util, os, re, sys
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FILES = {'en-gb': ['packages/oc3/upload/admin/language/en-gb/extension/module/mcp_server.php', 'packages/oc4/admin/language/en-gb/module/mcp_server.php'],
         'uk-ua': ['packages/oc3/upload/admin/language/uk-ua/extension/module/mcp_server.php', 'packages/oc4/admin/language/uk-ua/module/mcp_server.php']}
def php(v): return "'" + v.replace('\\', '\\\\').replace("'", "\\'") + "'"
def apply(path, strings):
    s = open(path, encoding='utf-8').read()
    for k, v in strings.items():
        line = "$_['" + k + "'] = " + php(v) + ";"
        rx = re.compile(r"^\$_\['" + re.escape(k) + r"'\]\s*=.*;$", re.M)
        if rx.search(s):
            s = rx.sub(lambda m: line, s, count=1)
        elif '\n// Error\n' in s:
            s = s.replace('\n// Error\n', '\n' + line + '\n// Error\n', 1)
        else:
            s = s.rstrip() + '\n' + line + '\n'
    with open(path, 'r+', encoding='utf-8') as f:
        f.seek(0); f.write(s); f.truncate()
spec = importlib.util.spec_from_file_location('strings', sys.argv[1]); mod = importlib.util.module_from_spec(spec); spec.loader.exec_module(mod)
for lang, d in (('en-gb', mod.EN), ('uk-ua', mod.UK)):
    for p in FILES[lang]:
        apply(os.path.join(ROOT, p), d)
print('language keys set:', len(mod.EN), 'en,', len(mod.UK), 'uk')
