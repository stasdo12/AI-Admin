<?php
namespace OcMcp;

/**
 * The one-time phone upload page (create_upload_link). Works without login: the secret link is the key, it only takes
 * images, a limited number, until it expires. The browser shrinks photos before sending (camera photos are often larger
 * than the hosting's upload limit); the server checks and re-encodes every file like import_image.
 */
final class UploadPage {
    const STRINGS = [
        'uk' => ['title' => 'Завантаження фото', 'intro' => 'Фото для магазину «%s». Виберіть фото з галереї або зробіть знімок.', 'pick' => 'Вибрати фото',
                 'send' => 'Завантажити', 'left' => 'Можна ще: %d', 'done' => 'Готово. Поверніться в чат і напишіть, що фото завантажені.',
                 'expired' => 'Посилання недійсне або вже використане. Попросіть асистента створити нове.', 'failed' => 'Не вдалося завантажити', 'sending' => 'Завантажую…',
                 'too_big' => 'Файл завеликий для хостингу. Спробуйте інше фото.', 'only_images' => 'Можна завантажувати лише фото.',
                 'title_csv' => 'Завантаження файлу', 'intro_csv' => 'Файл CSV для магазину «%s»: прайс, нові товари або покупці. Виберіть файл.', 'pick_csv' => 'Вибрати файл CSV',
                 'done_csv' => 'Готово. Поверніться в чат і напишіть, що файл завантажено.', 'only_csv' => 'Можна завантажити лише файл CSV (до 10 МБ).'],
        'en' => ['title' => 'Upload photos', 'intro' => 'Photos for the store “%s”. Pick photos from the gallery or take a picture.', 'pick' => 'Choose photos',
                 'send' => 'Upload', 'left' => 'Remaining: %d', 'done' => 'Done. Go back to the chat and say the photos are uploaded.',
                 'expired' => 'This link is invalid or used up. Ask the assistant for a new one.', 'failed' => 'Upload failed', 'sending' => 'Uploading…',
                 'too_big' => 'The file is too large for the hosting. Try another photo.', 'only_images' => 'Only photos can be uploaded.',
                 'title_csv' => 'Upload a file', 'intro_csv' => 'A CSV file for the store “%s”: a price list, new products or customers. Pick the file.', 'pick_csv' => 'Choose the CSV file',
                 'done_csv' => 'Done. Go back to the chat and say the file is uploaded.', 'only_csv' => 'Only a CSV file can be uploaded (up to 10 MB).'],
    ];

    /** @var Db */
    private $db;
    /** @var callable (string $batch) => ImageStore for the upload's change batch */
    private $images;
    /** @var array */
    private $t;
    /** @var string */
    private $lang;

    /** @var int longest side the browser shrinks photos to before sending */
    private $maxSide;
    /** @var string OpenCart's storage folder: CSV uploads go to its mcp-import/ */
    private $storageDir;

    public function __construct(Db $db, callable $images, string $acceptLanguage, string $storeLanguage = '', int $maxSide = 2000, string $storageDir = '') {
        $this->db = $db;
        $this->storageDir = $storageDir;
        $this->maxSide = max(200, $maxSide);
        $this->images = $images;
        $this->lang = 'en';

        foreach (explode(',', strtolower($acceptLanguage)) as $part) {
            $tag = substr(trim(explode(';', $part)[0]), 0, 2);

            if ($tag === 'uk' || $tag === 'en') {
                $this->lang = $tag;
                break;
            }

            if ($tag === 'ru') {
                $this->lang = 'uk';
                break;
            }
        }

        if ($acceptLanguage === '' && strpos($storeLanguage, 'uk') === 0) {
            $this->lang = 'uk';
        }

        $this->t = self::STRINGS[$this->lang];
    }

    /** @param array $files $_FILES['photo'] of one request (the page sends one photo per request) */
    public function handle(string $token, string $method, ?array $file, string $storeName, int $hourly): HttpResponse {
        $up = $token === '' ? null : $this->db->row("SELECT * FROM " . $this->db->t(ImageStore::UPLOAD) . " WHERE `token_hash` = " . $this->db->q(hash('sha256', $token)) . " AND `expires` > NOW()");

        if ($method !== 'POST') {
            return $this->page($up, $storeName);
        }

        if ($up === null) {
            return $this->json(410, ['error' => $this->t['expired']]);
        }

        $csv = (string)$up['purpose'] === 'csv';

        if ($file === null || !isset($file['error']) || is_array($file['error'])) {
            return $this->json(400, ['error' => $this->t[$csv ? 'only_csv' : 'only_images']]);
        }

        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            return $this->json(413, ['error' => in_array((int)$file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? $this->t['too_big'] : $this->t['failed']]);
        }

        if (!is_uploaded_file((string)$file['tmp_name']) && PHP_SAPI !== 'cli') {
            return $this->json(400, ['error' => $this->t['failed']]);
        }

        if ($csv) {
            return $this->handleCsv($up, $file);
        }

        if (!(new RateLimiter($this->db, 3600))->hit('mcp-images', $hourly, false)) {
            return $this->json(429, ['error' => $this->t['failed'] . ' (limit)']);
        }

        // claim one slot of the link atomically: two parallel requests cannot both take the last one
        $id = (int)$up['upload_id'];

        if ($this->db->exec("UPDATE " . $this->db->t(ImageStore::UPLOAD) . " SET `used` = `used` + 1 WHERE `upload_id` = " . $id . " AND `used` < `max_files` AND `expires` > NOW()") !== 1) {
            return $this->json(410, ['error' => $this->t['expired']]);
        }

        try {
            $n = (int)$this->db->value("SELECT `used` FROM " . $this->db->t(ImageStore::UPLOAD) . " WHERE `upload_id` = " . $id, 'used');
            $name = trim((string)$up['name']) !== '' ? (string)$up['name'] : pathinfo((string)($file['name'] ?? 'photo'), PATHINFO_FILENAME);
            $r = call_user_func($this->images, (string)$up['batch_id'])->fromBytes((string)file_get_contents((string)$file['tmp_name']), (string)($up['dir'] ?? '') !== '' ? (string)$up['dir'] : ImageStore::INCOMING, $name, 'upload:' . $id, $id);
        } catch (\Throwable $e) {
            $this->db->exec("UPDATE " . $this->db->t(ImageStore::UPLOAD) . " SET `used` = GREATEST(`used` - 1, 0) WHERE `upload_id` = " . $id); // the slot is free again

            return $this->json(422, ['error' => $this->t['failed'] . ': ' . $e->getMessage()]);
        }

        return $this->json(200, ['ok' => true, 'path' => $r['path'], 'left' => max(0, (int)$up['max_files'] - $n)]);
    }

    /** One CSV file for import_data: text only, up to 10 MB, kept in the storage folder (never under the web root's image dir). */
    private function handleCsv(array $up, array $file): HttpResponse {
        $id = (int)$up['upload_id'];
        $size = (int)($file['size'] ?? 0);
        $name = strtolower((string)($file['name'] ?? ''));

        if ($size <= 0 || $size > DataImport::MAX_BYTES || !preg_match('/\.(csv|txt|tsv)$/', $name)) {
            return $this->json(400, ['error' => $this->t['only_csv']]);
        }

        $head = (string)file_get_contents((string)$file['tmp_name'], false, null, 0, 4096);

        if ($head === '' || preg_match('/[\x00-\x08\x0E-\x1F]/', $head)) {
            return $this->json(400, ['error' => $this->t['only_csv']]); // binary content is not a CSV
        }

        if ($this->storageDir === '' || $this->db->exec("UPDATE " . $this->db->t(ImageStore::UPLOAD) . " SET `used` = 1 WHERE `upload_id` = " . $id . " AND `used` < `max_files` AND `expires` > NOW()") !== 1) {
            return $this->json(410, ['error' => $this->t['expired']]);
        }

        try {
            DataImport::store($this->storageDir, $id, (string)$file['tmp_name']);
        } catch (\Throwable $e) {
            $this->db->exec("UPDATE " . $this->db->t(ImageStore::UPLOAD) . " SET `used` = 0 WHERE `upload_id` = " . $id);

            return $this->json(422, ['error' => $this->t['failed'] . ': ' . $e->getMessage()]);
        }

        return $this->json(200, ['ok' => true, 'rows' => max(0, substr_count($head, "\n")), 'left' => 0]);
    }

    private function json(int $status, array $data): HttpResponse {
        return self::secure(HttpResponse::json($status, $data));
    }

    private static function secure(HttpResponse $r, string $nonce = ''): HttpResponse {
        return $r->withHeader('Cache-Control', 'no-store')->withHeader('X-Frame-Options', 'DENY')->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src blob: data:; style-src 'unsafe-inline'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" . ($nonce !== '' ? "; script-src 'nonce-$nonce'" : ''));
    }

    private function page(?array $up, string $storeName): HttpResponse {
        $t = $this->t;
        $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $nonce = bin2hex(random_bytes(12));
        $css = 'body{margin:0;background:#f1f5f9;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#0f172a}'
            . '.wrap{max-width:520px;margin:4vh auto;padding:0 16px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;box-shadow:0 10px 30px rgba(15,23,42,.06)}'
            . 'h1{font-size:22px;margin:0 0 8px}p{margin:0 0 16px;color:#334155}.muted{color:#64748b;font-size:14px}'
            . '.pick{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;box-sizing:border-box;padding:18px;border:2px dashed #93c5fd;border-radius:14px;background:#eff6ff;color:#1e3a8a;font-weight:600;font-size:17px;cursor:pointer}'
            . '.pick input{display:none}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:14px}.cell{position:relative;aspect-ratio:1;border-radius:10px;overflow:hidden;background:#e2e8f0}'
            . '.cell img{width:100%;height:100%;object-fit:cover;display:block}.cell .st{position:absolute;left:0;right:0;bottom:0;padding:3px 6px;font-size:12px;color:#fff;background:rgba(15,23,42,.6)}'
            . '.cell.ok .st{background:rgba(22,101,52,.85)}.cell.bad .st{background:rgba(153,27,27,.85)}.done{display:none;margin-top:16px;background:#dcfce7;color:#166534;border-radius:10px;padding:12px 14px}'
            . '.err{background:#fee2e2;color:#991b1b;border-radius:10px;padding:12px 14px}';
        $html = '<!doctype html><html lang="' . $this->lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . $e($t['title']) . '</title><style>' . $css . '</style></head><body><div class="wrap"><div class="card"><h1>' . $e($t['title']) . '</h1>';

        if ($up === null || (int)$up['used'] >= (int)$up['max_files']) {
            return self::secure($this->html(($up === null ? 410 : 200), $html . '<div class="err">' . $e($t['expired']) . '</div></div></div></body></html>'));
        }

        $left = (int)$up['max_files'] - (int)$up['used'];

        if ((string)$up['purpose'] === 'csv') {
            $html = str_replace('<h1>' . $e($t['title']) . '</h1>', '<h1>' . $e($t['title_csv']) . '</h1>', $html);
            $html .= '<p>' . $e(sprintf($t['intro_csv'], $storeName)) . '</p>'
                . '<label class="pick"><input type="file" id="f" accept=".csv,.txt,.tsv,text/csv,text/plain">📄 ' . $e($t['pick_csv']) . '</label>'
                . '<p class="muted" id="st" style="margin-top:10px"></p><div class="done" id="done">' . $e($t['done_csv']) . '</div>'
                . '</div></div><script nonce="' . $nonce . '">'
                . 'var T=' . json_encode(['sending' => $t['sending'], 'failed' => $t['failed']], JSON_UNESCAPED_UNICODE) . ';'
                . 'document.getElementById("f").addEventListener("change",async function(){var f=this.files[0];if(!f)return;var st=document.getElementById("st");st.textContent=T.sending;'
                . 'try{var fd=new FormData();fd.append("file",f,f.name);var r=await fetch(location.href,{method:"POST",body:fd,credentials:"omit"});var j=await r.json();if(!r.ok)throw new Error(j.error||T.failed);'
                . 'st.textContent="✓ "+f.name;document.getElementById("done").style.display="block";this.disabled=true}catch(e){st.textContent=e.message||T.failed}this.value=""});'
                . '</script></body></html>';

            return self::secure($this->html(200, $html), $nonce);
        }

        $html .= '<p>' . $e(sprintf($t['intro'], $storeName)) . '</p>'
            . '<label class="pick"><input type="file" id="f" accept="image/*" multiple>📷 ' . $e($t['pick']) . '</label>'
            . '<p class="muted" id="left" style="margin-top:10px">' . $e(sprintf($t['left'], $left)) . '</p><div class="grid" id="g"></div><div class="done" id="done">' . $e($t['done']) . '</div>'
            . '</div></div><script nonce="' . $nonce . '">'
            . 'var left=' . $left . ',max=' . $this->maxSide . ',T=' . json_encode(['sending' => $t['sending'], 'failed' => $t['failed'], 'left' => $t['left']], JSON_UNESCAPED_UNICODE) . ';'
            . 'function shrink(file){return new Promise(function(ok){var u=URL.createObjectURL(file),i=new Image();i.onload=function(){var k=Math.min(1,max/Math.max(i.naturalWidth,i.naturalHeight));'
            . 'if(k>=1&&file.size<4e6&&/jpe?g|png|webp/.test(file.type)){ok(file);return}var c=document.createElement("canvas");c.width=Math.round(i.naturalWidth*k);c.height=Math.round(i.naturalHeight*k);'
            . 'c.getContext("2d").drawImage(i,0,0,c.width,c.height);c.toBlob(function(b){ok(b||file)},"image/jpeg",0.9)};i.onerror=function(){ok(file)};i.src=u})}'
            . 'async function send(file){if(left<=0)return;var cell=document.createElement("div");cell.className="cell";var img=document.createElement("img");img.src=URL.createObjectURL(file);'
            . 'var st=document.createElement("div");st.className="st";st.textContent=T.sending;cell.appendChild(img);cell.appendChild(st);document.getElementById("g").appendChild(cell);'
            . 'try{var b=await shrink(file),fd=new FormData();fd.append("photo",b,(file.name||"photo").replace(/\.\w+$/,"")+".jpg");var r=await fetch(location.href,{method:"POST",body:fd,credentials:"omit"});'
            . 'var j=await r.json();if(!r.ok)throw new Error(j.error||T.failed);cell.className="cell ok";st.textContent="✓";left=j.left;document.getElementById("left").textContent=T.left.replace("%d",left);document.getElementById("done").style.display="block"}'
            . 'catch(e){cell.className="cell bad";st.textContent=e.message||T.failed}}'
            . 'document.getElementById("f").addEventListener("change",async function(){for(var f of Array.from(this.files)){if(left<=0)break;await send(f)}this.value=""});'
            . '</script></body></html>';

        return self::secure($this->html(200, $html), $nonce);
    }

    private function html(int $status, string $body): HttpResponse {
        $r = new HttpResponse();
        $r->status = $status;
        $r->headers['Content-Type'] = 'text/html; charset=utf-8';
        $r->body = $body;

        return $r;
    }
}
