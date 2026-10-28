<?php
namespace OcMcp\Tools;

use OcMcp\Context;
use OcMcp\Tool;
use OcMcp\ToolResult;

/** The store's media library under image/catalog: newest first, by folder, by name, or the files of one phone upload. */
final class ListImages extends Tool {
    public function name(): string {
        return 'list_images';
    }

    public function describe(): array {
        return [
            'title'       => 'List images in the store',
            'description' => 'Images in the store\'s media library (image/catalog), newest first. Filter by folder (e.g. "products"), by a part of the file name, '
                . 'or pass upload_id from create_upload_link to get the photos the user has uploaded from the phone.',
            'inputSchema' => self::schema([
                'folder'    => self::prop('string', 'Folder under image/catalog, e.g. "products" or "blog/2026-10". Default: all.'),
                'q'         => self::prop('string', 'Part of the file name.'),
                'upload_id' => self::prop('integer', 'Photos of one phone upload (create_upload_link).'),
                'limit'     => self::prop('integer', 'Max results (default 30, max 100).'),
                'offset'    => self::prop('integer', 'Skip this many.'),
            ]),
            'annotations' => self::annotations(true, false, true),
        ];
    }

    public function run(array $a, Context $c): ToolResult {
        $r = $c->images()->find((string)$this->str($a, 'folder', ''), (string)$this->str($a, 'q', ''), $this->limit($a, 30, 100), $this->offset($a), (int)$this->int($a, 'upload_id', 0));

        foreach ($r['images'] as &$img) {
            $img['src'] = ImportImage::src($c, $img['path']);
        }

        if ((int)$this->int($a, 'upload_id', 0) > 0) {
            $up = $c->db->row("SELECT `used`, `max_files`, `expires`, `expires` > NOW() AS open FROM " . $c->db->t(\OcMcp\ImageStore::UPLOAD) . " WHERE `upload_id` = " . (int)$a['upload_id']);
            $r['upload'] = $up ? ['uploaded' => (int)$up['used'], 'max_files' => (int)$up['max_files'], 'link_open_until' => $up['expires'], 'open' => (bool)$up['open']] : null;
        }

        return ToolResult::data($r);
    }
}
