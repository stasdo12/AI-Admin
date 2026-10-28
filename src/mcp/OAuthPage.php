<?php
namespace OcMcp;

/**
 * Self-contained HTML for the OAuth consent page (no theme dependency, works in any OpenCart).
 */
final class OAuthPage {
    public static function strings(): array {
        return [
            'title'            => 'Connect an AI assistant to %s',
            'intro'            => '<b>%1$s</b> asks for access to the store <b>%2$s</b> through the MCP server. Sign in with your OpenCart administrator account to allow it.',
            'lang'             => 'en',
            'client_source'    => 'Client: %s',
            'source_cimd'      => 'verified identity document',
            'source_dcr'       => 'registered application',
            'access_heading'   => 'What the assistant may do',
            'access_note'      => 'Every connected assistant gets the rights the store owner sets in the admin: AI Admin → Access (which sections it sees, edits or may delete). Nothing is chosen here.',
            'username'         => 'Administrator login',
            'password'         => 'Password',
            'allow'            => 'Allow access',
            'deny'             => 'Cancel',
            'error_login'      => 'Wrong login or password, the account is disabled, or its user group has no "Modify" right for the AI Admin module (System → Users → User Groups).',
            'error_csrf'       => 'The form has expired, please try again.',
            'error_rate_limited'=> 'Too many failed attempts. Try again in 15 minutes.',
            'footer'           => 'You can revoke this connection at any time in the store admin: Extensions → Modules → AI Admin → Connections.',
            'fatal_title'      => 'Connection request rejected',
            'redirect_note'    => 'After signing in you will be sent back to %s.',
        ];
    }

    public static function render(array $view, array $t = []): string {
        $t = array_merge(self::strings(), $t);
        $e = function ($v): string {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        };

        $css = 'body{margin:0;background:#f1f5f9;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#0f172a}'
            . '.wrap{max-width:460px;margin:6vh auto;padding:0 16px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px 28px 22px;box-shadow:0 10px 30px rgba(15,23,42,.06)}'
            . 'h1{font-size:20px;margin:0 0 10px}p{margin:0 0 14px;color:#334155}.muted{color:#64748b;font-size:13px}'
            . 'label{display:block;font-size:13px;font-weight:600;margin:14px 0 6px;color:#334155}input[type=text],input[type=password]{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px}'
            . '.btns{display:flex;gap:10px;margin-top:20px}button{flex:1;padding:11px 14px;border-radius:9px;border:1px solid #cbd5e1;background:#fff;font-size:15px;cursor:pointer}button.primary{background:#1e3a8a;border-color:#1e3a8a;color:#fff;font-weight:600}'
            . '.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:9px;padding:10px 12px;margin-bottom:12px;font-size:14px}.ok{background:#dcfce7;color:#166534;border-radius:9px;padding:10px 12px;font-size:14px}'
            . '.badge{display:inline-block;background:#e0e7ff;color:#3730a3;border-radius:999px;padding:2px 10px;font-size:12px;margin-bottom:12px}';

        $head = '<!doctype html><html lang="' . $e($t['lang']) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $e(sprintf($t['title'], $view['store_name'] ?? '')) . '</title><style>' . $css . '</style></head><body><div class="wrap"><div class="card">';
        $foot = '</div><p class="muted" style="margin-top:14px;text-align:center">AI Admin for OpenCart</p></div></body></html>';

        if (!empty($view['fatal'])) {
            return $head . '<h1>' . $e($t['fatal_title']) . '</h1><div class="err"><b>' . $e($view['fatal']) . '</b><br>' . $e($view['fatal_description'] ?? '') . '</div>' . $foot;
        }

        $html = $head;
        $html .= '<span class="badge">' . $e(sprintf($t['client_source'], ($view['client_source'] ?? '') === 'cimd' ? $t['source_cimd'] : $t['source_dcr'])) . '</span>';
        $html .= '<h1>' . $e(sprintf($t['title'], $view['store_name'])) . '</h1>';
        $html .= '<p>' . sprintf($t['intro'], $e($view['client_name']), $e($view['store_name'])) . '</p>';

        if (!empty($view['error'])) {
            $key = 'error_' . $view['error'];
            $html .= '<div class="err">' . $e($t[$key] ?? $view['error']) . '</div>';
        }

        $html .= '<form method="post" action="' . $e($view['action']) . '" autocomplete="off">';

        foreach ($view['params'] as $k => $v) {
            if ($v !== '' && $v !== null) {
                $html .= '<input type="hidden" name="' . $e($k) . '" value="' . $e($v) . '">';
            }
        }

        $html .= '<input type="hidden" name="csrf" value="' . $e($view['csrf']) . '">';
        $html .= '<label>' . $e($t['access_heading']) . '</label>';
        $html .= '<div class="ok">' . $e($t['access_note']) . '</div>'; // rights come from the Access tab, the same for every connection

        $html .= '<label for="u">' . $e($t['username']) . '</label><input type="text" id="u" name="username" value="' . $e($view['username'] ?? '') . '" autocapitalize="off" autocorrect="off" required>';
        $html .= '<label for="p">' . $e($t['password']) . '</label><input type="password" id="p" name="password" required>';
        $html .= '<div class="btns"><button type="submit" name="mcp_consent" value="deny">' . $e($t['deny']) . '</button><button type="submit" name="mcp_consent" value="allow" class="primary">' . $e($t['allow']) . '</button></div>';
        $html .= '</form>';
        $html .= '<p class="muted" style="margin-top:16px">' . $e(sprintf($t['redirect_note'], parse_url((string)$view['redirect_uri'], PHP_URL_HOST) ?: $view['redirect_uri'])) . '</p>';
        $html .= '<p class="muted">' . $e($t['footer']) . '</p>';

        return $html . $foot;
    }
}
