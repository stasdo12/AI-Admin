<?php
namespace OcMcp;

/**
 * Images the AI brings into the store: downloaded by URL or uploaded from a phone, checked (real image, size, pixels,
 * public address only), re-encoded with GD (EXIF with the phone's location and anything hidden in the file is gone,
 * phone photos turned upright), shrunk to the owner's maximum side, named after the page (KMU-2010 transliteration)
 * and put into the page's own folder under image/catalog (placeFor()). The same photo is stored once. Every file is recorded with the change
 * batch of the call, so rolling the batch back removes the files nothing uses any more.
 */
final class ImageStore {
    const TABLE = 'mcp_image';
    const UPLOAD = 'mcp_upload';
    const TRASH = 'mcp_image_trash';
    /** Outside image/catalog (the root of OpenCart's image manager): a deleted photo cannot be picked there and vanish 30 days later. */
    const TRASH_DIR = '.mcp-trash';
    /** Where the trash was until 2026-10-09; Installer::upgrade moves it. */
    const OLD_TRASH_DIR = 'catalog/_trash';
    const MAX_BYTES = 15728640;   // 15 MB
    const MAX_PIXELS = 40000000;  // 40 MP: larger images could exhaust memory while decoding
    const MIN_FREE = 52428800;    // keep 50 MB of disk free

    /** @var Db */
    private $db;
    /** @var string absolute path of OpenCart's image/ directory, with trailing slash */
    private $dir;
    /** @var array */
    private $s;
    /** @var ChangeJournal|null the change journal of the current call: imports show up in History and roll back */
    private $journal;
    /** @var callable|null (string $url) => string body; replaces the network in tests */
    private $fetcher;

    /**
     * Output formats. keep: JPG stays JPG, PNG stays PNG, GIF -> PNG (animation is not kept); WebP / AVIF / BMP -> JPG, or PNG when
     * the picture has transparent pixels (not every theme and OpenCart version shows WebP, see PLAN §21.1). The others are explicit.
     */
    const FORMATS = ['keep', 'jpg', 'png', 'webp'];

    public function __construct(Db $db, string $imageDir, array $settings, ?ChangeJournal $journal, ?callable $fetcher = null) {
        $this->db = $db;
        $this->dir = rtrim($imageDir, '/') . '/';
        $this->s = $settings;
        $this->journal = $journal;
        $this->fetcher = $fetcher;
    }

    const INCOMING = 'catalog/incoming';

    /** Where INCOMING was until 2026-10-12: photos waiting there still move to their page. */
    const OLD_INCOMING = 'catalog/_incoming';

    public static function isIncoming(string $path): bool {
        return strpos($path, self::INCOMING . '/') === 0 || strpos($path, self::OLD_INCOMING . '/') === 0;
    }

    /**
     * Where the images of a page live and how they are named, one folder per page, everything under image/catalog so
     * OpenCart's image manager shows it:
     *   product      catalog/products/<manufacturer or no-brand>/<product>/<product>-N.jpg
     *   category     catalog/category/<category>/<category>-N.jpg
     *   manufacturer catalog/manufacturers/<manufacturer>/<manufacturer>-N.jpg
     *   article      catalog/blog/<article>/...,  blog topic catalog/blog/rubrics/<topic>/...
     *   information  catalog/articles/<page>/...
     * A page that already has a photo in such a folder keeps it (renamed pages do not move); a folder another page of the
     * same kind uses gets a number: cholovichi, cholovichi-2. Photos saved before 2026-10-12 stay where they are.
     * @param array|null $profile the page type (ContentEntities) of a blog page: its tables and title column
     * @return array{dir:string,name:string}
     */
    public static function placeFor(Db $db, int $languageId, string $entity, int $id, ?array $profile = null): array {
        $L = $languageId;
        $q = function (string $sql, string $col) use ($db) { return (string)($db->value($sql, $col) ?? ''); };
        $slug = function (string $v, string $fallback) { $s = Slug::make(html_entity_decode($v, ENT_QUOTES, 'UTF-8'), 50); return $s !== '' ? $s : $fallback; };
        $text = function (string $table, string $col, string $idCol) use ($db, $q, $L, $id) {
            return $db->tableExists($table) ? $q("SELECT `" . $col . "` AS v FROM " . $db->t($table) . " WHERE `" . $idCol . "` = " . $id . " ORDER BY (`language_id` = " . $L . ") DESC LIMIT 1", 'v') : '';
        };

        switch ($entity) {
            case 'product':
                $name = $text('product_description', 'name', 'product_id');
                $brand = $q("SELECT m.`name` FROM " . $db->t('product') . " p JOIN " . $db->t('manufacturer') . " m ON m.`manufacturer_id` = p.`manufacturer_id` WHERE p.`product_id` = " . $id, 'name');
                $name = $name !== '' ? $name : 'product-' . $id;

                return ['dir' => self::pageFolder($db, 'catalog/products/' . $slug($brand, 'no-brand'), $slug($name, 'product-' . $id), [['product', 'product_id'], ['product_image', 'product_id']], $id), 'name' => $name];
            case 'category':
                $name = $text('category_description', 'name', 'category_id') ?: 'category-' . $id;

                return ['dir' => self::pageFolder($db, 'catalog/category', $slug($name, 'category-' . $id), [['category', 'category_id']], $id), 'name' => $name];
            case 'manufacturer':
                $name = $q("SELECT `name` FROM " . $db->t('manufacturer') . " WHERE `manufacturer_id` = " . $id, 'name') ?: 'brand-' . $id;

                return ['dir' => self::pageFolder($db, 'catalog/manufacturers', $slug($name, 'brand-' . $id), [['manufacturer', 'manufacturer_id']], $id), 'name' => $name];
            case 'article':
            case 'topic':
                $def = $entity === 'article' ? ['table' => 'article', 'id' => 'article_id', 'desc' => 'article_description', 'title' => 'name']
                    : ($db->tableExists('topic_description') ? ['table' => 'topic', 'id' => 'topic_id', 'desc' => 'topic_description', 'title' => 'name']
                        : ['table' => 'blog_category', 'id' => 'blog_category_id', 'desc' => 'blog_category_description', 'title' => 'name']);
                $d = ($profile ?: []) + $def;
                $name = $text((string)$d['desc'], (string)$d['title'], (string)$d['id']) ?: $entity . '-' . $id;
                $owners = [[(string)(($d['image_in'] ?? '') === 'desc' ? $d['desc'] : $d['table']), (string)$d['id']]];

                return ['dir' => self::pageFolder($db, $entity === 'article' ? 'catalog/blog' : 'catalog/blog/rubrics', $slug($name, $entity . '-' . $id), $owners, $id), 'name' => $name];
            default:
                $name = $text('information_description', 'title', 'information_id') ?: 'page-' . $id;

                return ['dir' => self::pageFolder($db, 'catalog/articles', $slug($name, 'page-' . $id), [['information', 'information_id']], $id), 'name' => $name];
        }
    }

    /**
     * The page's own folder under $root: the one its photos are already in, else $root/$slug, numbered when another page
     * of the same kind has photos there.
     * @param array $owners [table, id column] whose `image` column holds the page's photos
     */
    private static function pageFolder(Db $db, string $root, string $slug, array $owners, int $id): string {
        $has = [];

        foreach ($owners as $o) {
            if ($db->tableExists($o[0]) && $db->rows("SHOW COLUMNS FROM " . $db->t($o[0]) . " LIKE 'image'")) {
                $has[] = $o;
            }
        }

        $like = function (string $dir) use ($db) { return $db->q(addcslashes($dir, '%_\\') . '/%'); };

        foreach ($has as $o) {
            foreach ($db->rows("SELECT `image` FROM " . $db->t($o[0]) . " WHERE `" . $o[1] . "` = " . $id . " AND `image` LIKE " . $like($root) . " LIMIT 20") as $r) {
                $rest = explode('/', substr((string)$r['image'], strlen($root) + 1));

                if (count($rest) === 2 && $rest[0] !== '' && $rest[0] !== 'rubrics') {
                    return $root . '/' . $rest[0]; // the page already lives here
                }
            }
        }

        for ($n = 1; $n < 50; $n++) {
            $dir = $root . '/' . $slug . ($n > 1 ? '-' . $n : '');
            $taken = false;

            foreach ($has as $o) {
                $taken = $taken || $db->row("SELECT 1 AS x FROM " . $db->t($o[0]) . " WHERE `" . $o[1] . "` <> " . $id . " AND `image` LIKE " . $like($dir) . " LIMIT 1") !== null;
            }

            if (!$taken) {
                return $dir;
            }
        }

        return $root . '/' . $slug . '-' . $id;
    }

    /**
     * An image imported before its page existed (catalog/incoming) moves to the page's folder when it is attached,
     * unless something else already uses it. Its record and the journal follow, so a rollback still finds it, and the
     * record keeps the old path: the other pages the same photo was imported for still find it there (existing()).
     * @param array|null $self [table, id column, id] of the page being saved: its own use does not count
     */
    public function settle(string $path, array $place, ?array $self = null): string {
        if (!self::isIncoming($path) || $this->usedElsewhere($path, $self)) {
            return $path;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $new = $this->target($place['dir'], $place['name'], $ext);

        if (!is_dir(dirname($this->dir . $new)) && !@mkdir(dirname($this->dir . $new), 0755, true) && !is_dir(dirname($this->dir . $new))) {
            return $path;
        }

        if (!@rename($this->dir . $path, $this->dir . $new)) {
            return $path;
        }

        $moved = $this->hasMovedFrom() ? ", `moved_from` = " . $this->db->q($path) : '';

        foreach ($this->db->rows("SELECT * FROM " . $this->db->t(self::TABLE) . " WHERE `path` = " . $this->db->q($path)) as $row) {
            $this->db->exec("UPDATE " . $this->db->t(self::TABLE) . " SET `path` = " . $this->db->q($new) . $moved . " WHERE `image_id` = " . (int)$row['image_id']);

            if ($this->journal !== null) {
                $this->journal->refreshAfter(self::TABLE, ['image_id' => (string)$row['image_id']], array_merge($row, ['path' => $new], $moved !== '' ? ['moved_from' => $path] : []));
            }
        }

        return $new;
    }

    /** Is the image used by anything but the page being saved? */
    private function usedElsewhere(string $path, ?array $self): bool {
        $hits = self::usages($this->db, $path);

        if ($self === null || $hits !== [$self[0]]) {
            return $hits !== [];
        }

        // only the page's own table: another row of it may use the photo too
        return $this->db->row("SELECT 1 AS x FROM " . $this->db->t($self[0]) . " WHERE `image` = " . $this->db->q($path) . " AND `" . $self[1] . "` <> " . (int)$self[2] . " LIMIT 1") !== null;
    }

    private function hasMovedFrom(): bool {
        static $has = [];
        $key = $this->db->prefix();

        if (!isset($has[$key])) {
            $has[$key] = $this->db->rows("SHOW COLUMNS FROM " . $this->db->t(self::TABLE) . " LIKE 'moved_from'") !== [];
        }

        return $has[$key];
    }


    // ------------------------------------------------------------------ in

    /** @return array path (relative to image/), width, height, bytes, duplicate */
    /**
     * @param string|null $bytes the file when it was already downloaded (fetchMany)
     * @param array $opts square (bool: centred on a white square), min_size (int: flag photos smaller than this)
     */
    public function fromUrl(string $url, string $dir, string $name, string $format = 'keep', ?string $bytes = null, array $opts = []): array {
        if ($bytes === null) {
            $bytes = $this->fetcher !== null ? (string)call_user_func($this->fetcher, $url) : self::download($url);
        }

        return $this->fromBytes($bytes, $dir, $name !== '' ? $name : self::nameFromUrl($url), 'url:' . substr($url, 0, 240), 0, $format, $opts);
    }

    /** @param string $dir folder under image/ (placeFor() or INCOMING) */
    public function fromBytes(string $bytes, string $dir, string $name, string $source, int $uploadId = 0, string $format = 'keep', array $opts = []): array {
        if (!in_array($format, self::FORMATS, true)) {
            throw new ToolException('format must be one of: ' . implode(', ', self::FORMATS) . '.');
        }

        if ($format === 'webp' && !function_exists('imagewebp')) {
            throw new ToolException('This server cannot write WebP (PHP GD without WebP). Use format "keep" or "jpg".');
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new ToolException('This server has no GD image library; images cannot be processed. Ask the hosting provider to enable PHP GD.');
        }

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new ToolException('The image is empty or larger than 15 MB.');
        }

        $sha = sha1($bytes);
        $known = $this->db->row("SELECT `path`, `width`, `height`, `bytes` FROM " . $this->db->t(self::TABLE) . " WHERE `sha1` = " . $this->db->q($sha) . " ORDER BY `image_id` LIMIT 1");

        // the same photo is stored once, in the format asked for: an older copy in another format (e.g. the WebP that earlier
        // versions made automatically) does not count, the photo is encoded again
        if ($known !== null && is_file($this->dir . $known['path']) && self::formatFits(strtolower(pathinfo((string)$known['path'], PATHINFO_EXTENSION)), $format)
            && (empty($opts['square']) || (int)$known['width'] === (int)$known['height'])) {
            return ['path' => $known['path'], 'width' => (int)$known['width'], 'height' => (int)$known['height'], 'bytes' => (int)$known['bytes'], 'duplicate' => true]
                + self::sizeFlags((int)$known['width'], (int)$known['height'], (int)$known['width'], (int)$known['height'], $opts);
        }

        $free = @disk_free_space($this->dir);

        if (is_float($free) && $free < self::MIN_FREE + strlen($bytes) * 2) {
            throw new ToolException('Not enough free disk space on the hosting for more images.');
        }

        [$out, $ext, $w, $h, $ow, $oh] = $this->process($bytes, $format, $opts) + [4 => 0, 5 => 0];
        $rel = $this->target($dir, $name, $ext);
        $abs = $this->dir . $rel;

        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true) && !is_dir(dirname($abs))) {
            throw new ToolException('Cannot create the folder image/' . dirname($rel) . ' (check write permissions of image/catalog).');
        }

        $tmp = $abs . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $out) !== strlen($out)) {
            @unlink($tmp);
            throw new ToolException('Cannot write image/' . $rel . ' (check write permissions of image/catalog).');
        }

        // create the name exclusively: two imports at once can pick the same free name, and rename() would overwrite the first photo
        for ($try = 0; ; $try++) {
            if (@link($tmp, $abs)) {
                @unlink($tmp);
                break;
            }

            if (!file_exists($abs) && $try === 0 && @rename($tmp, $abs)) {
                break; // a host without hard links: the free name was checked a moment ago
            }

            if ($try >= 5) {
                @unlink($tmp);
                throw new ToolException('Cannot write image/' . $rel . ' (check write permissions of image/catalog).');
            }

            $rel = $this->target($dir, $name, $ext);
            $abs = $this->dir . $rel;
        }

        @chmod($abs, 0644);
        $snap = $this->journal ? $this->journal->snapshot(self::TABLE, '0') : null;
        $this->db->exec("INSERT INTO " . $this->db->t(self::TABLE) . " SET `path` = " . $this->db->q($rel) . ", `sha1` = " . $this->db->q($sha) . ", `source` = " . $this->db->q(substr($source, 0, 255))
            . ", `batch_id` = " . $this->db->q($this->journal ? $this->journal->batch() : '') . ", `upload_id` = " . $uploadId . ", `width` = " . $w . ", `height` = " . $h . ", `bytes` = " . strlen($out) . ", `date_added` = NOW()");

        if ($snap !== null) {
            $this->journal->commit($snap, "`image_id` = " . (int)$this->db->lastId());
        }

        return ['path' => $rel, 'width' => $w, 'height' => $h, 'bytes' => strlen($out), 'duplicate' => false] + self::sizeFlags($ow ?: $w, $oh ?: $h, $w, $h, $opts);
    }

    /** too_small when the photo as it came is under min_size on its shorter side; not_square when the stored file is not square. */
    private static function sizeFlags(int $w, int $h, int $storedW, int $storedH, array $opts): array {
        $out = [];

        if (!empty($opts['min_size']) && min($w, $h) < (int)$opts['min_size']) {
            $out['too_small'] = min($w, $h) . ' px on the shorter side, ' . (int)$opts['min_size'] . ' wanted';
        }

        if ($storedW !== $storedH) {
            $out['not_square'] = true;
        }

        return $out;
    }

    /** @return array{0:string,1:string,2:int,3:int} encoded bytes, extension, width, height */
    /** @return array [bytes, ext, width, height, original width, original height] */
    public function process(string $bytes, string $format = 'keep', array $opts = []): array {
        $info = @getimagesizefromstring($bytes);
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];

        foreach (['IMAGETYPE_WEBP' => 'webp', 'IMAGETYPE_BMP' => 'bmp', 'IMAGETYPE_AVIF' => 'avif'] as $const => $t) {
            if (defined($const)) {
                $types[constant($const)] = $t;
            }
        }

        if (!is_array($info) || !isset($types[$info[2]])) {
            throw new ToolException('This is not a JPG, PNG, GIF, WebP, AVIF or BMP image. (Photos from an iPhone in HEIC: use the phone upload page, it converts them.)');
        }

        [$w, $h] = [(int)$info[0], (int)$info[1]];

        if ($w < 1 || $h < 1 || $w * $h > self::MAX_PIXELS) {
            throw new ToolException('The image is ' . $w . 'x' . $h . ' pixels; at most 40 megapixels are accepted.');
        }

        self::ensureMemory($w * $h * 5 + ContentPolicy::IMG_WIDTH ** 2 * 5 + 8 * 1048576);
        $src = @imagecreatefromstring($bytes);

        if ($src === false) {
            throw new ToolException('The image file is damaged or this server cannot read ' . strtoupper($types[$info[2]]) . ' images.');
        }

        $type = $types[$info[2]];
        $orientation = $type === 'jpg' ? self::orientation($bytes) : 1;

        if (in_array($orientation, [3, 6, 8], true)) {
            $rot = @imagerotate($src, [3 => 180, 6 => -90, 8 => 90][$orientation], 0);

            if ($rot !== false) {
                imagedestroy($src);
                $src = $rot;
                [$w, $h] = [imagesx($src), imagesy($src)];
            }
        }

        $alpha = in_array($type, ['png', 'gif', 'webp', 'avif'], true);
        [$ow, $oh] = [$w, $h];

        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }

        if (!empty($opts['square']) && $w !== $h) {
            // the photo centred on a white square, never stretched or cut (how catalog product photos look)
            $side = max($w, $h);
            self::ensureMemory($side * $side * 5 + $w * $h * 5 + 8 * 1048576);
            $sq = imagecreatetruecolor($side, $side);
            imagefill($sq, 0, 0, imagecolorallocate($sq, 255, 255, 255));
            imagealphablending($sq, true);
            imagecopy($sq, $src, (int)(($side - $w) / 2), (int)(($side - $h) / 2), 0, 0, $w, $h);
            imagedestroy($src);
            [$src, $w, $h, $alpha] = [$sq, $side, $side, false];
        }

        if ($alpha) {
            imagealphablending($src, false);
            imagesavealpha($src, true);
        }

        // always scaled by width: wider than the limit -> exactly the limit, never enlarged
        if (($this->s['img_resize'] ?? true) && $w > ContentPolicy::IMG_WIDTH) {
            [$src, $w, $h] = self::scale($src, $w, $h, ContentPolicy::IMG_WIDTH, $alpha);
        }

        $ext = self::outputFormat($type, $format, $alpha && $type !== 'png' && $type !== 'gif' ? self::hasTransparency($src, $w, $h) : $alpha);

        if ($ext === 'jpg' && $alpha) {
            $src = self::flatten($src, $w, $h); // JPEG has no transparency: transparent parts become white, not black
        }
        $out = self::encode($src, $ext, 85);

        // a stored image is at most 2 MB: lower the quality first, then the size
        foreach ([75, 65] as $q) {
            if (strlen($out) <= ContentPolicy::IMG_MAX_BYTES || $ext === 'png') {
                break;
            }

            $out = self::encode($src, $ext, $q);
        }

        for ($i = 0; strlen($out) > ContentPolicy::IMG_MAX_BYTES && $i < 6 && $w > 400; $i++) {
            [$src, $w, $h] = self::scale($src, $w, $h, (int)round($w * 0.8), $alpha);
            $out = self::encode($src, $ext, $ext === 'png' ? 0 : 75);
        }

        imagedestroy($src);

        return [$out, $ext, $w, $h, $ow, $oh];
    }

    /** @return array{0:resource|\GdImage,1:int,2:int} */
    private static function scale($src, int $w, int $h, int $nw, bool $alpha): array {
        $nh = max(1, (int)round($h * $nw / $w));
        $dst = imagecreatetruecolor($nw, $nh);

        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        return [$dst, $nw, $nh];
    }

    /** keep -> the source format where every theme shows it; jpg / png / webp as asked. */
    public static function outputFormat(string $sourceType, string $format, bool $transparent): string {
        if ($format !== 'keep') {
            return $format;
        }

        if ($sourceType === 'jpg') {
            return 'jpg';
        }

        if ($sourceType === 'png' || $sourceType === 'gif') {
            return 'png';
        }

        return $transparent ? 'png' : 'jpg'; // webp, avif, bmp
    }

    /** A stored copy with this extension satisfies the requested format. */
    public static function formatFits(string $ext, string $format): bool {
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;

        return $format === 'keep' ? in_array($ext, ['jpg', 'png'], true) : $ext === $format;
    }

    /** Does the picture really use transparency? Sampled on a grid of at most ~10 000 points. */
    private static function hasTransparency($img, int $w, int $h): bool {
        $step = max(1, (int)ceil(sqrt($w * $h / 10000)));

        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Copy onto white (for JPEG). */
    private static function flatten($src, int $w, int $h) {
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagealphablending($dst, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        return $dst;
    }

    private static function encode($img, string $ext, int $quality): string {
        ob_start();

        if ($ext === 'webp') {
            imagewebp($img, null, $quality);
        } elseif ($ext === 'jpg') {
            imageinterlace($img, true);
            imagejpeg($img, null, $quality);
        } else {
            imagepng($img, null, 9);
        }

        return (string)ob_get_clean();
    }

    /**
     * GD keeps ~5 bytes per pixel while decoding and resizing; a shared host's 128 MB limit would end in a fatal error
     * the AI never sees. Raise the limit when the host allows it, otherwise refuse with a clear answer.
     */
    private static function ensureMemory(int $need): void {
        $limit = self::bytes((string)ini_get('memory_limit'));

        if ($limit <= 0 || memory_get_usage(true) + $need < $limit) {
            return;
        }

        $want = memory_get_usage(true) + $need + 16 * 1048576;
        @ini_set('memory_limit', (string)(int)ceil($want / 1048576) . 'M');

        if (self::bytes((string)ini_get('memory_limit')) < $want) {
            throw new ToolException('This photo is too large for the memory the hosting gives PHP (' . ini_get('memory_limit') . '). Send a smaller photo (up to about '
                . (int)floor(sqrt(max(1, $limit - memory_get_usage(true)) / 5) / 100) * 100 . ' px per side) or ask the hosting to raise memory_limit.');
        }
    }

    private static function bytes(string $v): int {
        $v = trim($v);

        if ($v === '' || $v === '-1') {
            return -1;
        }

        $n = (int)$v;
        $unit = strtolower(substr($v, -1));

        return $unit === 'g' ? $n * 1073741824 : ($unit === 'm' ? $n * 1048576 : ($unit === 'k' ? $n * 1024 : $n));
    }

    /** EXIF orientation of a JPEG (1 = upright), read without the exif extension. */
    public static function orientation(string $jpeg): int {
        $len = strlen($jpeg);
        $i = 2;

        while ($i + 4 < $len && $jpeg[$i] === "\xFF") {
            $marker = ord($jpeg[$i + 1]);
            $size = unpack('n', substr($jpeg, $i + 2, 2))[1];

            if ($marker === 0xE1 && substr($jpeg, $i + 4, 6) === "Exif\0\0") {
                $tiff = substr($jpeg, $i + 10, $size - 8);
                $le = substr($tiff, 0, 2) === 'II';
                $u16 = function ($o) use ($tiff, $le) { return strlen($tiff) >= $o + 2 ? unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0; };
                $u32 = function ($o) use ($tiff, $le) { return strlen($tiff) >= $o + 4 ? unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0; };
                $ifd = $u32(4);
                $n = $u16($ifd);

                for ($k = 0; $k < $n && $k < 200; $k++) {
                    $e = $ifd + 2 + $k * 12;

                    if ($u16($e) === 0x0112) {
                        $v = $u16($e + 8);

                        return $v >= 1 && $v <= 8 ? $v : 1;
                    }
                }

                return 1;
            }

            if ($marker === 0xDA) {
                break; // image data starts: no EXIF before it
            }

            $i += 2 + $size;
        }

        return 1;
    }

    /** <dir>/<name>-N.<ext>: the first free number, an existing file is never overwritten. */
    private function target(string $dir, string $name, string $ext): string {
        $dir = trim(preg_replace('~/+~', '/', str_replace(['..', '\\'], '', $dir)), '/');
        $dir = $dir !== '' ? $dir : self::INCOMING;
        $base = Slug::make($name, 60) ?: 'image';

        for ($n = 1; $n < 1000; $n++) {
            $rel = $dir . '/' . $base . '-' . $n . '.' . $ext;

            if (!file_exists($this->dir . $rel)) {
                return $rel;
            }
        }

        return $dir . '/' . $base . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    }

    private static function nameFromUrl(string $url): string {
        $base = pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);

        return rawurldecode((string)$base);
    }

    // ------------------------------------------------------------------ download (public addresses only)

    /** Only http(s) on ports 80 / 443 to a host whose every address is public; returns the IP to connect to. */
    public static function checkUrl(string $url): array {
        $p = parse_url($url);

        if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            throw new ToolException('Give a plain http(s) link to the image.');
        }

        $scheme = strtolower($p['scheme']);
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (!in_array($port, [80, 443], true)) {
            throw new ToolException('Only links on the standard ports 80 / 443 are downloaded.');
        }

        $host = strtolower(trim($p['host'], '[]'));
        $ips = NetGuard::resolve($host);

        if (!$ips) {
            throw new ToolException("The address $host could not be resolved.");
        }

        // the same addresses that are checked are the ones connected to (a second lookup could answer differently)
        if (!filter_var($host, FILTER_VALIDATE_IP) && NetGuard::isLocalName($host) || array_filter($ips, function ($ip) { return !NetGuard::isPublic($ip); })) {
            throw new ToolException('Links to local or internal network addresses are not downloaded.');
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0], 'scheme' => $scheme];
    }



    /** What a browser sends: some shops' image servers refuse or reset anything else. */
    const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';

    /** Total seconds one import_image call spends downloading (the links are fetched in parallel). */
    const DOWNLOAD_BUDGET = 25;

    public static function download(string $url): string {
        if (!function_exists('curl_init')) {
            throw new ToolException('This server has no PHP cURL; images cannot be downloaded by link.');
        }

        $http11 = false;

        for ($hop = 0; $hop < 5; $hop++) {
            $body = '';
            $ch = self::handle($url, $body, $http11); // every redirect target is checked again
            $ok = curl_exec($ch);
            $r = self::outcome($ch, $ok, $body);
            curl_close($ch);

            if ($r['retry11'] && !$http11) {
                $http11 = true; // the server reset the HTTP/2 stream or refused it: once more over HTTP/1.1
                continue;
            }

            if ($http11 && ($r['code'] === 0 || in_array($r['code'], [403, 406], true))) {
                // refused over HTTP/2 and silent or refusing over HTTP/1.1: the image server lets browsers only
                throw new ToolException('The image server refuses downloads by programs (bot protection, e.g. big brand sites): take the photo from another shop that sells the product, or ask the user to upload it (create_upload_link).');
            }

            if ($r['location'] !== '') {
                $url = $r['location'];
                continue;
            }

            return self::bodyOrFail($r, $body);
        }

        throw new ToolException('Too many redirects.');
    }

    /**
     * Download several links at once within one time budget (shared hosting: a slow CDN must not hold the call for
     * minutes). Redirects and the HTTP/1.1 retry go through download() afterwards, still inside the budget.
     * @return array<int, string|\Throwable> index => file bytes or the reason it failed
     */
    public function fetchMany(array $urls, float $budget = self::DOWNLOAD_BUDGET): array {
        $out = [];

        if ($this->fetcher !== null || !function_exists('curl_multi_init') || count($urls) < 2) {
            foreach ($urls as $i => $url) {
                try {
                    $out[$i] = $this->fetcher !== null ? (string)call_user_func($this->fetcher, $url) : self::download($url);
                } catch (\Throwable $e) {
                    $out[$i] = $e;
                }
            }

            return $out;
        }

        $start = microtime(true);
        $mh = curl_multi_init();
        $handles = [];
        $bodies = [];

        foreach ($urls as $i => $url) {
            try {
                $bodies[$i] = '';
                $handles[$i] = self::handle((string)$url, $bodies[$i], false);
                curl_multi_add_handle($mh, $handles[$i]);
            } catch (\Throwable $e) {
                $out[$i] = $e;
            }
        }

        $finished = []; // index => cURL result code
        $collect = function () use ($mh, $handles, &$finished) {
            while (($msg = curl_multi_info_read($mh)) !== false) {
                foreach ($handles as $i => $ch) {
                    if ($ch === $msg['handle']) {
                        $finished[$i] = (int)$msg['result'];
                    }
                }
            }
        };

        do {
            curl_multi_exec($mh, $running);
            $collect();

            if ($running && curl_multi_select($mh, 0.5) === -1) {
                usleep(50000);
            }
        } while ($running > 0 && microtime(true) - $start < $budget);

        curl_multi_exec($mh, $running);
        $collect();
        $later = [];

        foreach ($handles as $i => $ch) {
            if (!isset($finished[$i])) {
                $out[$i] = new ToolException('Not downloaded within ' . (int)$budget . ' s (the server is slow): try this link again on its own, or another link.');
            } else {
                $r = self::outcome($ch, $finished[$i] === 0, $bodies[$i], $finished[$i] !== 0 ? (function_exists('curl_strerror') ? (string)curl_strerror($finished[$i]) : 'error ' . $finished[$i]) : '');

                if ($r['retry11'] || $r['location'] !== '') {
                    $later[] = $i;
                } else {
                    try {
                        $out[$i] = self::bodyOrFail($r, $bodies[$i]);
                    } catch (\Throwable $e) {
                        $out[$i] = $e;
                    }
                }
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        foreach ($later as $i) {
            try {
                $out[$i] = microtime(true) - $start < $budget ? self::download((string)$urls[$i]) : new ToolException('Not downloaded within ' . (int)$budget . ' s: try this link again on its own.');
            } catch (\Throwable $e) {
                $out[$i] = $e;
            }
        }

        ksort($out);

        return $out;
    }

    /** A cURL handle for one checked link: it connects to the checked address only, at most 15 MB. */
    private static function handle(string $url, string &$body, bool $http11) {
        $t = self::checkUrl($url);
        $p = parse_url($url);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE        => [$t['host'] . ':' . $t['port'] . ':' . (strpos($t['ip'], ':') !== false ? '[' . $t['ip'] . ']' : $t['ip'])], // connect to the checked address only
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_LOW_SPEED_LIMIT => 1, // a server that keeps the connection open without sending anything (bot protection) is left after 8 s
            CURLOPT_LOW_SPEED_TIME => $http11 ? 6 : 8,
            CURLOPT_HEADER         => false,
            CURLOPT_USERAGENT      => self::BROWSER_UA,
            CURLOPT_HTTPHEADER     => ['Accept: image/avif,image/webp,image/apng,image/png,image/jpeg,image/*;q=0.8,*/*;q=0.5', 'Accept-Language: en-US,en;q=0.9',
                                       'Referer: ' . strtolower((string)$p['scheme']) . '://' . $p['host'] . '/'],
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body) {
                $body .= $chunk;

                return strlen($body) > self::MAX_BYTES ? 0 : strlen($chunk); // abort above 15 MB
            },
        ]);

        if ($http11) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        }

        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        return $ch;
    }

    /** @return array{code:int, location:string, error:string, retry11:bool, ok:bool} */
    private static function outcome($ch, $ok, string $body, string $err = ''): array {
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $err !== '' ? $err : (string)curl_error($ch);
        $location = $code >= 300 && $code < 400 ? (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL) : '';

        return ['code' => $code, 'location' => $location, 'error' => $err, 'ok' => $ok !== false,
                'retry11' => strlen($body) <= self::MAX_BYTES && (preg_match('~HTTP/?2|stream~i', $err) === 1 || in_array($code, [403, 406], true))];
    }

    private static function bodyOrFail(array $r, string $body): string {
        if (strlen($body) > self::MAX_BYTES) {
            throw new ToolException('The image is larger than 15 MB.');
        }

        if ($r['code'] === 0 || (!$r['ok'] && $body === '')) {
            throw new ToolException('Download failed: ' . ($r['error'] !== '' ? $r['error'] : 'no answer'));
        }

        if ($r['code'] !== 200) {
            throw new ToolException('The link answered HTTP ' . $r['code'] . ' instead of an image.');
        }

        return $body;
    }

    // ------------------------------------------------------------------ media library

    /** Files under image/catalog (newest first), optionally in one folder, matching a name, or from one phone upload. */
    public function find(string $folder, string $q, int $limit, int $offset, int $uploadId = 0): array {
        if ($uploadId > 0) {
            $rows = $this->db->rows("SELECT `path`, `width`, `height`, `bytes`, `date_added` FROM " . $this->db->t(self::TABLE) . " WHERE `upload_id` = " . $uploadId . " ORDER BY `image_id`");

            return ['total' => count($rows), 'images' => array_values(array_filter($rows, function ($r) { return is_file($this->dir . $r['path']); }))];
        }

        $root = $this->dir . 'catalog/';
        $folder = trim(str_replace(['..', '\\'], '', $folder), '/');
        $start = realpath($root . $folder);

        if ($start === false || strpos($start . '/', realpath($root) . '/') !== 0 || !is_dir($start)) {
            return ['total' => 0, 'images' => []];
        }

        $files = [];
        $seen = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($start, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if (++$seen > 20000) {
                break; // huge libraries: the newest of the first 20 000 files
            }

            if (!$f->isFile() || !preg_match('/\.(jpe?g|png|gif|webp)$/i', $f->getFilename()) || strpos($f->getPathname(), '/' . self::TRASH_DIR . '/') !== false) {
                continue;
            }

            if ($q !== '' && stripos($f->getFilename(), $q) === false) {
                continue;
            }

            $files[] = [substr($f->getPathname(), strlen($this->dir)), $f->getMTime(), $f->getSize()];
        }

        usort($files, function ($a, $b) { return $b[1] <=> $a[1]; });
        $out = [];

        foreach (array_slice($files, $offset, $limit) as [$path, $mtime, $size]) {
            $info = @getimagesize($this->dir . $path);
            $out[] = ['path' => $path, 'width' => $info ? $info[0] : null, 'height' => $info ? $info[1] : null, 'bytes' => $size, 'modified' => date('Y-m-d H:i', $mtime)];
        }

        return ['total' => count($files), 'images' => $out];
    }

    /** A path the AI gives for an existing image: relative to image/, inside it, a real image file. */
    public function existing(string $path, string $storeUrl = ''): string {
        $p = trim($path);

        if ($storeUrl !== '' && preg_match('~^https?://~i', $p)) {
            $own = strtolower((string)parse_url($storeUrl, PHP_URL_HOST));
            $host = strtolower((string)parse_url($p, PHP_URL_HOST));

            if (preg_replace('/^www\./', '', $own) !== preg_replace('/^www\./', '', $host)) {
                throw new ToolException("$path is on another site: import it first with import_image.");
            }

            $p = (string)parse_url($p, PHP_URL_PATH);
        }

        $p = rawurldecode(ltrim($p, '/'));
        $pos = strpos($p, 'image/');

        if (strpos($p, 'catalog/') !== 0 && $pos !== false) {
            $p = substr($p, $pos + 6); // /image/catalog/x.jpg or /shop/image/catalog/x.jpg
        }

        $real = realpath($this->dir . $p);

        if ($real === false && self::isIncoming($p) && $this->hasMovedFrom()) {
            // imported once for several pages: it moved to the folder of the first page it was attached to
            $now = $this->db->value("SELECT `path` FROM " . $this->db->t(self::TABLE) . " WHERE `moved_from` = " . $this->db->q($p) . " ORDER BY `image_id` DESC LIMIT 1", 'path');

            if ($now !== null) {
                $real = realpath($this->dir . $now);
            }
        }

        $root = realpath($this->dir) . '/';

        if ($real === false || strpos($real, $root) !== 0 || !is_file($real) || !@getimagesize($real)) {
            throw new ToolException("Image $path not found in the store's image folder. Use list_images to see what is there, or import_image to add it.");
        }

        $p = substr($real, strlen($root)); // the canonical path: catalog/../x and catalog//x never get past the checks that follow

        if (strpos($p, self::TRASH_DIR . '/') === 0) {
            throw new ToolException("Image $path is a deleted photo (in the trash). Roll the deletion back to use it again.");
        }

        return $p;
    }

    // ------------------------------------------------------------------ deleting

    /**
     * "Delete" a photo from the site: the file moves to image/.mcp-trash/<time>/..., its resized copies go, and a journal
     * record makes the deletion show up in History; rolling it back puts the file where it was.
     */
    public function trash(string $path): string {
        $to = self::TRASH_DIR . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '/' . preg_replace('~^catalog/~', '', $path);

        if (!is_dir(dirname($this->dir . $to)) && !@mkdir(dirname($this->dir . $to), 0755, true) && !is_dir(dirname($this->dir . $to))) {
            throw new ToolException('Cannot create the folder image/' . self::TRASH_DIR . ' (check write permissions of image/catalog).');
        }

        self::protectTrash($this->dir);

        // the record first, then the move: a crash in between leaves a record of a file still in place (a rollback simply finds
        // nothing to bring back), never a file in the trash nobody knows about
        $snap = $this->journal ? $this->journal->snapshot(self::TRASH, '0') : null;
        $this->db->exec("INSERT INTO " . $this->db->t(self::TRASH) . " SET `path` = " . $this->db->q($path) . ", `trash_path` = " . $this->db->q($to)
            . ", `batch_id` = " . $this->db->q($this->journal ? $this->journal->batch() : '') . ", `date_added` = NOW()");
        $id = (int)$this->db->lastId();

        if ($snap !== null) {
            $this->journal->commit($snap, "`trash_id` = " . $id);
        }

        if (!@rename($this->dir . $path, $this->dir . $to)) {
            $this->db->exec("DELETE FROM " . $this->db->t(self::TRASH) . " WHERE `trash_id` = " . $id);
            @rmdir(dirname($this->dir . $to));
            throw new ToolException(is_file($this->dir . $path) ? 'Cannot delete image/' . $path . ' (check write permissions of image/catalog).' : 'image/' . $path . ' is already deleted.');
        }

        $stem = substr($path, 0, (int)strrpos($path, '.'));

        foreach ((array)glob($this->dir . 'cache/' . $stem . '-*') as $cached) {
            @unlink($cached); // resized copies OpenCart made for the storefront; it makes them again if the photo comes back
        }

        return $to;
    }

    /** The trash keeps deleted photos for TRASH_DAYS days, then they go for good (their deletion can no longer be rolled back). */
    const TRASH_DAYS = 30;

    /** Files and bytes in the photo trash, and the date of its oldest folder. */
    public static function trashStats(string $imageDir): array {
        $root = rtrim($imageDir, '/') . '/' . self::TRASH_DIR;
        $out = ['files' => 0, 'bytes' => 0, 'oldest' => null];

        if (!is_dir($root)) {
            return $out;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && $f->getFilename() !== '.htaccess' && $f->getFilename() !== 'index.html') {
                $out['files']++;
                $out['bytes'] += (int)$f->getSize();
                $t = preg_match('~/' . preg_quote(self::TRASH_DIR, '~') . '/(\d{4})(\d{2})(\d{2})-~', $f->getPathname(), $m) ? $m[1] . '-' . $m[2] . '-' . $m[3] : date('Y-m-d', (int)$f->getMTime()); // the day of the deletion
                $out['oldest'] = $out['oldest'] === null || $t < $out['oldest'] ? $t : $out['oldest'];
            }
        }

        return $out;
    }

    /** Delete the whole photo trash (its deletions can no longer be rolled back). @return array{files:int, bytes:int} */
    public static function emptyTrash(string $imageDir): array {
        $root = rtrim($imageDir, '/') . '/' . self::TRASH_DIR;
        $stats = self::trashStats($imageDir);

        if (is_dir($root) && strpos(realpath($root) ?: '', realpath(rtrim($imageDir, '/')) ?: "\0") === 0) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }

            @rmdir($root);
        }

        return ['files' => $stats['files'], 'bytes' => $stats['bytes']];
    }

    public static function purgeTrash(Db $db, string $imageDir): int {
        $dir = rtrim($imageDir, '/') . '/';
        $n = 0;

        try {
            $rows = $db->rows("SELECT `trash_id`, `trash_path` FROM " . $db->t(self::TRASH) . " WHERE `date_added` < DATE_SUB(NOW(), INTERVAL " . self::TRASH_DAYS . " DAY) LIMIT 200");
        } catch (\Throwable $e) {
            return 0; // older install without the table
        }

        foreach ($rows as $r) {
            $tp = (string)$r['trash_path'];

            if (strpos($tp, self::TRASH_DIR . '/') === 0 && strpos($tp, '..') === false && is_file($dir . $tp) && @unlink($dir . $tp)) {
                $n++;

                for ($d = dirname($dir . $tp); strpos($d, $dir . self::TRASH_DIR . '/') === 0 && @rmdir($d); $d = dirname($d)) {
                    // drop the emptied folders
                }
            }

            $db->exec("DELETE FROM " . $db->t(self::TRASH) . " WHERE `trash_id` = " . (int)$r['trash_id']);
        }

        return $n + self::purgeOrphans($db, $dir);
    }

    /**
     * Trash folders that no record knows any more (the table was lost with a site move, or an old uninstall dropped it) go
     * when their date - the folder name, YYYYMMDD-HHMMSS-xxxxxx - is older than the trash keeps photos.
     */
    private static function purgeOrphans(Db $db, string $dir): int {
        $root = $dir . self::TRASH_DIR;

        if (!is_dir($root)) {
            return 0;
        }

        $n = 0;
        $limit = date('Ymd', time() - self::TRASH_DAYS * 86400);

        foreach ((array)glob($root . '/*', GLOB_ONLYDIR) as $folder) {
            $base = basename((string)$folder);

            if (!preg_match('/^(\d{8})-\d{6}-[a-f0-9]+$/', $base, $m) || $m[1] >= $limit
                || $db->row("SELECT 1 AS x FROM " . $db->t(self::TRASH) . " WHERE `trash_path` LIKE " . $db->q(self::TRASH_DIR . '/' . $base . '/%') . " LIMIT 1") !== null) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string)$folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : (@unlink($f->getPathname()) && $n++);
            }

            @rmdir((string)$folder);
        }

        return $n;
    }

    /** Deleted photos are never served: .htaccess for Apache / LiteSpeed, an empty index for listings; on nginx the random folder names keep them hidden. */
    public static function protectTrash(string $imageDir): void {
        $root = rtrim($imageDir, '/') . '/' . self::TRASH_DIR;

        if (!is_dir($root)) {
            @mkdir($root, 0755, true);
        }

        if (!is_file($root . '/.htaccess')) {
            @file_put_contents($root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }

        if (!is_file($root . '/index.html')) {
            @file_put_contents($root . '/index.html', '');
        }
    }

    /** Move the trash out of image/catalog (until 2026-10-09 it was image/catalog/_trash). */
    public static function moveOldTrash(Db $db, string $imageDir): void {
        $dir = rtrim($imageDir, '/') . '/';
        $old = $dir . self::OLD_TRASH_DIR;

        if (!is_dir($old)) {
            return;
        }

        self::protectTrash($dir);

        foreach ((array)glob($old . '/*', GLOB_ONLYDIR) as $folder) {
            $target = $dir . self::TRASH_DIR . '/' . basename((string)$folder);

            if (!file_exists($target) && @rename((string)$folder, $target)) {
                $db->exec("UPDATE " . $db->t(self::TRASH) . " SET `trash_path` = CONCAT(" . $db->q(self::TRASH_DIR . '/') . ", SUBSTRING(`trash_path`, " . (strlen(self::OLD_TRASH_DIR) + 2) . "))"
                    . " WHERE `trash_path` LIKE " . $db->q(self::OLD_TRASH_DIR . '/' . basename((string)$folder) . '/%'));
            }
        }

        @unlink($old . '/.htaccess');
        @rmdir($old);
    }

    /**
     * Put one deleted photo back from the trash (called by the rollback BEFORE its trash record goes, so a crash in between
     * leaves the record, and the next attempt finds the file already home). true = moved, null = nothing to move, false = failed.
     */
    public static function restoreOne(array $row, string $imageDir): ?bool {
        $dir = rtrim($imageDir, '/') . '/';
        $tp = (string)($row['trash_path'] ?? '');
        $p = (string)($row['path'] ?? '');

        if ((strpos($tp, self::TRASH_DIR . '/') !== 0 && strpos($tp, self::OLD_TRASH_DIR . '/') !== 0) || strpos($p, 'catalog/') !== 0 || strpos($tp . $p, '..') !== false || strpos($p, self::OLD_TRASH_DIR . '/') === 0) {
            return false; // only trash -> library, whatever the table holds
        }

        $from = $dir . $tp;

        if (!is_file($from)) {
            return null; // already back (an earlier attempt) or never moved
        }

        $to = $dir . $p;

        for ($i = 1; file_exists($to) && $i < 100; $i++) {
            $to = $dir . preg_replace('/(\.[a-z0-9]+)$/i', '-restored-' . $i . '$1', $p); // a new file took the name meanwhile: keep both
        }

        if (file_exists($to) || (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0755, true)) || !@rename($from, $to)) {
            return false;
        }

        for ($d = dirname($from); (strpos($d, $dir . self::TRASH_DIR . '/') === 0 || strpos($d, $dir . self::OLD_TRASH_DIR . '/') === 0) && @rmdir($d); $d = dirname($d)) {
            // drop the emptied folders of this deletion, up to the trash itself
        }

        return true;
    }

    // ------------------------------------------------------------------ rollback

    /** Image files a batch brought in (read before the batch is rolled back: the rollback removes their records). */
    public static function batchFiles(Db $db, string $batch): array {
        try {
            return $db->rows("SELECT `image_id`, `path` FROM " . $db->t(self::TABLE) . " WHERE `batch_id` = " . $db->q($batch));
        } catch (\Throwable $e) {
            return []; // older install without the table
        }
    }

    /** After a batch is rolled back: delete the files it brought in that no page, banner or text uses any more. */
    public static function rollbackFiles(Db $db, array $rows, string $imageDir): int {
        $dir = rtrim($imageDir, '/') . '/';
        $n = 0;

        foreach ($rows as $r) {
            if (self::used($db, (string)$r['path'])) {
                continue;
            }

            if (is_file($dir . $r['path']) && @unlink($dir . $r['path'])) {
                $n++;
            }

            $stem = substr((string)$r['path'], 0, (int)strrpos((string)$r['path'], '.'));

            foreach ((array)glob($dir . 'cache/' . $stem . '-*') as $cached) {
                @unlink($cached); // resized copies OpenCart made for the storefront
            }

            $db->exec("DELETE FROM " . $db->t(self::TABLE) . " WHERE `image_id` = " . (int)$r['image_id']);
        }

        return $n;
    }

    /** Is the image referenced anywhere in the store (page images, galleries, banners, texts, settings)? */
    public static function used(Db $db, string $path): bool {
        return self::usages($db, $path, true) !== [];
    }

    /** Where the image is used: table names (product, product_image, category_description, ...); $first stops at the first hit. */
    public static function usages(Db $db, string $path, bool $first = false): array {
        $q = $db->q($path);
        $like = $db->q('%' . addcslashes($path, '%_') . '%');
        $checks = [['product', "`image` = $q"], ['product_image', "`image` = $q"], ['category', "`image` = $q"], ['manufacturer', "`image` = $q"],
                   ['banner_image', "`image` = $q"], ['article_description', "`image` = $q"], ['topic_description', "`image` = $q"], ['setting', "`value` = $q"],
                   ['product_description', "`description` LIKE $like"], ['category_description', "`description` LIKE $like"], ['information_description', "`description` LIKE $like"],
                   ['article_description', "`description` LIKE $like"], ['manufacturer_description', "`description` LIKE $like"], ['topic_description', "`description` LIKE $like"],
                   ['option_value', "`image` = $q"], ['voucher_theme', "`image` = $q"], ['information', "`image` = $q"], ['module', "`setting` LIKE $like"]];

        // texts keep paths HTML-escaped (&amp;) or URL-encoded (%20): look for those too
        foreach (array_unique([htmlspecialchars($path, ENT_QUOTES, 'UTF-8'), str_replace('%2F', '/', rawurlencode($path)), str_replace('/', '\\/', $path)]) as $alt) {
            if ($alt === $path) {
                continue;
            }

            $altLike = $db->q('%' . addcslashes($alt, '%_') . '%');

            foreach (['product_description', 'category_description', 'information_description', 'article_description', 'manufacturer_description', 'topic_description'] as $t) {
                $checks[] = [$t, "`description` LIKE $altLike"];
            }

            $checks[] = ['module', "`setting` LIKE $altLike"];
        }

        $out = [];

        foreach ($checks as [$table, $where]) {
            try {
                if (!in_array($table, $out, true) && $db->row("SELECT 1 AS x FROM " . $db->t($table) . " WHERE " . $where . " LIMIT 1") !== null) {
                    $out[] = $table;

                    if ($first) {
                        return $out;
                    }
                }
            } catch (\Throwable $e) {
                // table not in this OpenCart version
            }
        }

        return $out;
    }
}
