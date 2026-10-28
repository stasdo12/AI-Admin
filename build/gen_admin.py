#!/usr/bin/env python3
"""Generate the OpenCart 3 and OpenCart 4 admin views from packages/shared/admin/mcp_server.twig.

  [[i:name]]            icon class for the version (Font Awesome 4 in OC3, 6 in OC4)
  [[oc3]] ... [[/oc3]]  only in the OpenCart 3 view;  [[oc4]] ... [[/oc4]] only in the OpenCart 4 view
  [[admin_js]]          mcp_server.js: its {{ twig }} values go into a small inline array __M, the code into
                        src/mcp/assets/admin.<version>.js (cached by the browser); mcp_server.css -> src/mcp/assets/admin.css

  python3 build/gen_admin.py          write both views
  python3 build/gen_admin.py --check  fail when a view is out of date or a text key is missing in a language file
Generated files are written in place (same inode), which the dev stand needs for its single-file mounts.
"""
import os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'packages/shared/admin/mcp_server.twig')
SRC_JS = os.path.join(ROOT, 'packages/shared/admin/mcp_server.js')
SRC_CSS = os.path.join(ROOT, 'packages/shared/admin/mcp_server.css')
# served by the admin action "asset" from the core (both versions read the core folder): cached by the browser
ASSETS = {'css': os.path.join(ROOT, 'src/mcp/assets/admin.css'), 'oc3': os.path.join(ROOT, 'src/mcp/assets/admin.oc3.js'), 'oc4': os.path.join(ROOT, 'src/mcp/assets/admin.oc4.js')}
OUT = {
    'oc3': os.path.join(ROOT, 'packages/oc3/upload/admin/view/template/extension/module/mcp_server.twig'),
    'oc4': os.path.join(ROOT, 'packages/oc4/admin/view/template/module/mcp_server.twig'),
}
LANG = {
    'oc3': os.path.join(ROOT, 'packages/oc3/upload/admin/language/{}/extension/module/mcp_server.php'),
    'oc4': os.path.join(ROOT, 'packages/oc4/admin/language/{}/module/mcp_server.php'),
}
LANGUAGES = ['en-gb', 'uk-ua']

ICONS = {  # name: (OC3 / FA4, OC4 / FA6)
    'save': ('fa fa-save', 'fa-solid fa-floppy-disk'), 'back': ('fa fa-reply', 'fa-solid fa-reply'),
    'gauge': ('fa fa-tachometer', 'fa-solid fa-gauge'), 'access': ('fa fa-shield', 'fa-solid fa-user-shield'),
    'plug': ('fa fa-plug', 'fa-solid fa-plug'), 'history': ('fa fa-history', 'fa-solid fa-clock-rotate-left'),
    'sliders': ('fa fa-sliders', 'fa-solid fa-sliders'), 'copy': ('fa fa-copy', 'fa-solid fa-copy'),
    'dot': ('fa fa-circle', 'fa-solid fa-circle'), 'next': ('fa fa-chevron-right', 'fa-solid fa-chevron-right'),
    'warn': ('fa fa-exclamation-triangle', 'fa-solid fa-triangle-exclamation'), 'ok': ('fa fa-check-circle', 'fa-solid fa-circle-check'),
    'external': ('fa fa-external-link', 'fa-solid fa-arrow-up-right-from-square'), 'search': ('fa fa-search', 'fa-solid fa-magnifying-glass'),
    'lock': ('fa fa-lock', 'fa-solid fa-lock'), 'code': ('fa fa-code', 'fa-solid fa-code'), 'ban': ('fa fa-ban', 'fa-solid fa-ban'),
    'eye': ('fa fa-eye', 'fa-solid fa-eye'), 'refresh': ('fa fa-refresh', 'fa-solid fa-rotate'), 'trash': ('fa fa-trash', 'fa-solid fa-trash'),
    'bolt': ('fa fa-bolt', 'fa-solid fa-bolt'), 'undo': ('fa fa-undo', 'fa-solid fa-rotate-left'), 'list': ('fa fa-list', 'fa-solid fa-list'),
    'shield': ('fa fa-shield', 'fa-solid fa-shield-halved'), 'download': ('fa fa-download', 'fa-solid fa-download'),
    'archive': ('fa fa-archive', 'fa-solid fa-box-archive'), 'close': ('fa fa-times', 'fa-solid fa-xmark'),
    'table': ('fa fa-table', 'fa-solid fa-table-cells'), 'check': ('fa fa-check', 'fa-solid fa-check'), 'key': ('fa fa-key', 'fa-solid fa-key'),
    'magic': ('fa fa-magic', 'fa-solid fa-wand-magic-sparkles'), 'plus': ('fa fa-plus', 'fa-solid fa-plus'), 'eyeoff': ('fa fa-eye-slash', 'fa-solid fa-eye-slash'), 'pen': ('fa fa-pencil', 'fa-solid fa-pen'),
    'info': ('fa fa-info-circle', 'fa-solid fa-circle-info'), 'down': ('fa fa-chevron-down', 'fa-solid fa-chevron-down'),
    'db': ('fa fa-database', 'fa-solid fa-database'), 'alert': ('fa fa-exclamation-circle', 'fa-solid fa-circle-exclamation'),
    'telegram': ('fa fa-paper-plane', 'fa-brands fa-telegram'),
}

# keys provided by the controller or OpenCart itself, not by the module language file
NOT_LANGUAGE = {'server_now', 'mark_legend', 'acl_custom_json', 'header', 'column_left', 'footer', 'heading_title', 'cancel', 'back', 'action', 'save', 'breadcrumbs', 'success',
                'error_warning', 'error_acl', 'error_token', 'error_url_secret', 'error_path', 'mcp_url', 'mcp_base', 'secret_base', 'ai_connected', 'https', 'pretty_urls', 'seo_enabled',
                'token_set', 'connections', 'connect_clients', 'client', 'c', 'lv', 'endpoint_url', 'endpoint_secret_url',
                'endpoint_secret_query_url', 'oauth_metadata_url', 'claude_code_cmd', 'cursor_link', 'inspector_cmd', 'export_url',
                'log_total', 'opencart_version', 'active_connections', 'inactive_connections', 'licensed', 'module_lang', 'rename_url', 'check_url', 'wipe_preview_url', 'wipe_url', 'remove_url', 'module_version', 'asset_css_url', 'asset_js_url', 'clear_trash_url', 'trash', 'module_mcp_server_power', 'license_domain', 'connector_name', 'license_reason', 'license_info', 'license_url', 'php_version', 'ioncube_loaded', 'crypto_ok', 'columns_url', 'access_json', 'presets_json', 'stats_json',
                'rollback_all_url', 'content_json', 'prompts_json', 'content_url', 'prompts_url', 'webp_ok', 'changes_url', 'change_details_url', 'rollback_url', 'cancel_change_url', 'logs_url', 'archive_url', 'clear_log_url', 'archives_json', 'revoke_url', 'breadcrumb', 'free', 'support_tg'}


def split_js(js: str):
    """Twig values of the script -> (code with __M[i], list of inline JS expressions)."""
    vals, out, i = [], [], 0
    for m in re.finditer(r"\{\{.*?\}\}", js):
        a, b = m.start(), m.end()
        expr = m.group(0)
        pc, nc = js[a - 1] if a else '', js[b] if b < len(js) else ''
        line = js[js.rfind('\n', 0, a) + 1:a]
        if pc in "'\"" and nc == pc:            # the whole string literal: '{{ x|e('js') }}'
            out.append(js[i:a - 1]); vals.append(pc + expr + pc); i = b + 1
        elif pc == "'" or nc == "'" or line.count("'") % 2 == 1:   # inside a literal: '... {{ x }} ...'
            out.append(js[i:a] + "' + __M[%d] + '" % len(vals)); vals.append("'" + expr + "'"); i = b
            continue
        else:                                    # a bare value: {{ access_json|raw }}, true / false
            out.append(js[i:a]); vals.append(expr); i = b
        out.append('__M[%d]' % (len(vals) - 1))
    out.append(js[i:])
    return ''.join(out), vals


def render(src: str, ver: str) -> str:
    other = 'oc4' if ver == 'oc3' else 'oc3'
    out = re.sub(r'\[\[' + other + r'\]\].*?\[\[/' + other + r'\]\]\n?', '', src, flags=re.S)
    out = re.sub(r'\[\[/?' + ver + r'\]\]\n?', '', out)
    idx = 0 if ver == 'oc3' else 1

    def icon(m):
        if m.group(1) not in ICONS:
            sys.exit('unknown icon: ' + m.group(1))
        return ICONS[m.group(1)][idx]

    out = re.sub(r'\[\[i:([a-z_]+)\]\]', icon, out)
    if '[[admin_js]]' in out:
        js = re.sub(r'\[\[i:([a-z_]+)\]\]', icon, open(SRC_JS, encoding='utf-8').read())
        code, vals = split_js(js)
        RENDERED_JS[ver] = '/* generated by build/gen_admin.py from packages/shared/admin/mcp_server.js - do not edit */\n' + code
        inline = '<script type="text/javascript">var __M = [\n' + ',\n'.join(vals) + '\n];</script>\n<script type="text/javascript" src="{{ asset_js_url }}"></script>'
        out = out.replace('[[admin_js]]', inline)
    leftover = re.findall(r'\[\[[^\]]*\]\]', out)
    if leftover:
        sys.exit('unprocessed markers: ' + ', '.join(sorted(set(leftover))))
    return out


RENDERED_JS = {}


def lang_keys(path: str) -> set:
    return set(re.findall(r"^\$_\['([a-z0-9_]+)'\]", open(path, encoding='utf-8').read(), flags=re.M))


def used_keys(twig: str) -> set:
    keys = set(re.findall(r"\{\{\s*([a-z][a-z0-9_]*)\s*(?:\||\}\}|\?)", twig))
    keys |= set(re.findall(r"'((?:text|ui|entry|help|column)_[a-z0-9_]+)'", twig))
    for prefix, items in (('text_level_', ['none', 'read', 'write', 'full']),):
        for it in items:
            keys |= {prefix + it, prefix + it + '_help'}
    keys |= {'ui_help_lv_' + lv for lv in ('read', 'write', 'full', 'custom', 'none')}
    for cid in ('claude', 'chatgpt', 'perplexity'):
        keys.add('text_client_' + cid + '_sub')
    return {k for k in keys if k not in NOT_LANGUAGE and not k.endswith('_') and not k.startswith(('module_mcp_server_',))}


def main():
    check = '--check' in sys.argv
    src = open(SRC, encoding='utf-8').read()
    problems = []
    for ver, path in OUT.items():
        out = render(src, ver)
        if check:
            if open(path, encoding='utf-8').read() != out:
                problems.append(f'{os.path.relpath(path, ROOT)} is out of date: run python3 build/gen_admin.py')
        else:
            with open(path, 'r+', encoding='utf-8') as f:  # in place: keep the inode for single-file docker mounts
                f.seek(0); f.write(out); f.truncate()
        need = used_keys(out)
        for asset, body in ((ASSETS[ver], RENDERED_JS.get(ver, '')), (ASSETS['css'], '/* generated by build/gen_admin.py from packages/shared/admin/mcp_server.css - do not edit */\n' + open(SRC_CSS, encoding='utf-8').read())):
            old = open(asset, encoding='utf-8').read() if os.path.exists(asset) else None
            if check and old != body:
                problems.append(f'{os.path.relpath(asset, ROOT)} is out of date: run python3 build/gen_admin.py')
            elif not check and old != body:
                open(asset, 'w', encoding='utf-8').write(body)
        for lang in LANGUAGES:
            have = lang_keys(LANG[ver].format(lang))
            missing = sorted(need - have)
            if missing:
                problems.append(f'{ver} {lang}: missing language keys: ' + ', '.join(missing))
    if problems:
        print('\n'.join(problems)); sys.exit(1)
    print('admin views ' + ('up to date' if check else 'generated') + ', language keys complete')


if __name__ == '__main__':
    main()
