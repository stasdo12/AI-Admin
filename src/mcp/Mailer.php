<?php
namespace OcMcp;

/**
 * Mail campaigns prepared and sent by the AI (`{prefix}mcp_mail`).
 *
 * Two steps: prepare() stores a draft with the resolved recipient list and returns a preview; send() requires the
 * draft id and confirm=true. Sending goes through the store's own OpenCart mail settings (the AI never sees SMTP data),
 * respects an hourly limit and continues on later requests until the queue is empty. Broadcasts to many customers only go
 * to newsletter subscribers and always carry an unsubscribe link.
 */
final class Mailer {
    const TABLE = 'mcp_mail';
    const DRAFT_TTL = 1800;        // a prepared draft expires after 30 minutes
    const MAX_RECIPIENTS = 5000;   // per mail

    /** @var Db */
    private $db;
    /** @var callable|null function(string $to, string $subject, string $html, string $text): void */
    private $transport;
    /** @var RateLimiter|null */
    private $limiter;
    /** @var int */
    private $hourly;
    /** @var string */
    private $storeUrl;

    public function __construct(Db $db, ?callable $transport, ?RateLimiter $limiter, int $hourlyLimit, string $storeUrl) {
        $this->db = $db;
        $this->transport = $transport;
        $this->limiter = $limiter;
        $this->hourly = max(0, $hourlyLimit);
        $this->storeUrl = rtrim($storeUrl, '/') . '/';
    }

    /**
     * @param array $audience customer_ids[] | customer_group_id | newsletter (bool) | all_customers (bool)
     * @return array preview
     */
    public function prepare(string $client, string $topic, string $subject, string $body, array $audience): array {
        $subject = trim($subject);
        $body = trim($body);

        if ($subject === '' || $body === '') {
            throw new ToolException('subject and body are required.');
        }

        $where = ['c.`status` = 1', "c.`email` <> ''"];
        $broadcast = false;

        if (!empty($audience['customer_ids']) && is_array($audience['customer_ids'])) {
            $where[] = 'c.`customer_id` IN (' . $this->db->ints($audience['customer_ids']) . ')';
        } else {
            $broadcast = true;

            if (!empty($audience['customer_group_id'])) {
                $where[] = 'c.`customer_group_id` = ' . (int)$audience['customer_group_id'];
            } elseif (empty($audience['all_customers']) && empty($audience['newsletter'])) {
                throw new ToolException('Choose an audience: customer_ids, customer_group_id, newsletter=true or all_customers=true.');
            }

            $where[] = 'c.`newsletter` = 1'; // broadcasts only reach customers who agreed to receive them
        }

        $rows = $this->db->rows("SELECT c.`customer_id`, c.`firstname`, c.`lastname`, c.`email` FROM " . $this->db->t('customer') . " c WHERE " . implode(' AND ', $where)
            . " ORDER BY c.`customer_id` LIMIT " . (self::MAX_RECIPIENTS + 1));

        if (count($rows) > self::MAX_RECIPIENTS) {
            throw new ToolException('More than ' . self::MAX_RECIPIENTS . ' recipients; narrow the audience (for example by customer group).');
        }

        if (!$rows) {
            throw new ToolException('No recipients match this audience' . ($broadcast ? ' (broadcasts only go to customers subscribed to the newsletter).' : '.'));
        }

        $recipients = array_map(function ($r) {
            return ['customer_id' => (int)$r['customer_id'], 'email' => $r['email'], 'name' => trim($r['firstname'] . ' ' . $r['lastname'])];
        }, $rows);

        $html = strpos($body, '<') !== false ? $body : nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

        if ($broadcast) {
            $html .= '<hr><p style="font-size:12px;color:#666">You receive this e-mail because you subscribed to our newsletter. <a href="' . htmlspecialchars($this->storeUrl . 'index.php?route=account/newsletter', ENT_QUOTES, 'UTF-8') . '">Unsubscribe</a></p>';
        }

        $text = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));

        $this->db->exec("INSERT INTO " . $this->db->t(self::TABLE) . " SET `date_added` = NOW(), `client` = " . $this->db->q(substr($client, 0, 128)) . ", `topic` = " . $this->db->q(mb_substr($topic, 0, 128))
            . ", `subject` = " . $this->db->q(mb_substr($subject, 0, 255)) . ", `html` = " . $this->db->q($html) . ", `text` = " . $this->db->q($text)
            . ", `recipients` = " . $this->db->q((string)json_encode($recipients, JSON_UNESCAPED_UNICODE)) . ", `audience` = " . $this->db->q((string)json_encode($audience))
            . ", `broadcast` = " . ($broadcast ? 1 : 0) . ", `status` = 'draft', `total` = " . count($recipients) . ", `sent` = 0, `failed` = 0"
            . ", `expires` = " . $this->db->q(date('Y-m-d H:i:s', time() + self::DRAFT_TTL)) . ", `last_error` = ''");
        $id = $this->db->lastId();

        return [
            'mail_id'      => $id,
            'status'       => 'draft',
            'topic'        => $topic,
            'subject'      => $subject,
            'recipients'   => count($recipients),
            'broadcast'    => $broadcast,
            'sample'       => array_map(function ($r) { return ['customer_id' => $r['customer_id'], 'name' => $r['name'], 'email' => Logger::mask($r['email'])]; }, array_slice($recipients, 0, 5)),
            'expires'      => date('Y-m-d H:i:s', time() + self::DRAFT_TTL),
            'hourly_limit' => $this->hourly,
            'text_preview' => mb_substr($text, 0, 500),
        ];
    }

    /** Messages sent within one request; the rest continues on later requests (keeps every request short). */
    const PER_REQUEST = 50;

    /** Start (or continue) sending a draft. */
    public function send(int $mailId): array {
        if ($this->transport === null) {
            throw new ToolException('Sending e-mail is not available in this store.');
        }

        // claim the draft in ONE guarded statement: of two parallel send_mail calls only one starts it
        $claimed = $this->db->exec("UPDATE " . $this->db->t(self::TABLE) . " SET `status` = 'sending' WHERE `mail_id` = " . $mailId . " AND `status` = 'draft' AND `expires` >= NOW()");
        $m = $this->db->row("SELECT `status`, `expires` FROM " . $this->db->t(self::TABLE) . " WHERE `mail_id` = " . $mailId);

        if ($m === null) {
            throw new ToolException("Mail $mailId not found.");
        }

        if ($claimed !== 1) {
            if ($m['status'] === 'draft') {
                $this->db->exec("UPDATE " . $this->db->t(self::TABLE) . " SET `status` = 'expired' WHERE `mail_id` = " . $mailId . " AND `status` = 'draft' AND `expires` < NOW()");
                throw new ToolException('The draft has expired; prepare it again so the recipient list is fresh.');
            }

            if ($m['status'] !== 'sending') {
                throw new ToolException('Mail ' . $mailId . ' is ' . $m['status'] . '.');
            }
            // already sending: continuing is safe, every recipient is claimed one by one
        }

        return $this->deliver($mailId);
    }

    /**
     * Send the next messages of one mail (hourly limit and PER_REQUEST permitting). At most once per recipient: each
     * slot is claimed with `sent = sent + 1 WHERE sent + failed = i AND status = 'sending'` before the transport is called,
     * so parallel requests never send the same message twice and a cancel stops the loop at the next message.
     */
    private function deliver(int $mailId): array {
        $m = $this->db->row("SELECT * FROM " . $this->db->t(self::TABLE) . " WHERE `mail_id` = " . $mailId);
        $recipients = json_decode((string)$m['recipients'], true) ?: [];
        $total = count($recipients);
        $stoppedByLimit = false;
        $t = $this->db->t(self::TABLE);

        for ($i = (int)$m['sent'] + (int)$m['failed'], $done = 0; $i < $total && $done < self::PER_REQUEST; $i++, $done++) {
            if ($this->limiter && $this->hourly > 0 && !$this->limiter->hit('mail-hour', $this->hourly, false)) {
                $stoppedByLimit = true;
                break;
            }

            if ($this->db->exec("UPDATE " . $t . " SET `sent` = `sent` + 1 WHERE `mail_id` = " . $mailId . " AND `status` = 'sending' AND `sent` + `failed` = " . $i) !== 1) {
                break; // another request took this message, or the mail was cancelled
            }

            try {
                call_user_func($this->transport, $recipients[$i]['email'], (string)$m['subject'], (string)$m['html'], (string)$m['text']);
            } catch (\Throwable $e) {
                $this->db->exec("UPDATE " . $t . " SET `sent` = `sent` - 1, `failed` = `failed` + 1, `last_error` = " . $this->db->q(mb_substr($e->getMessage(), 0, 250)) . " WHERE `mail_id` = " . $mailId);
            }
        }

        $this->db->exec("UPDATE " . $t . " SET `status` = 'sent' WHERE `mail_id` = " . $mailId . " AND `status` = 'sending' AND `sent` + `failed` >= " . $total);
        $now = $this->db->row("SELECT `status`, `sent`, `failed`, `last_error` FROM " . $t . " WHERE `mail_id` = " . $mailId);

        return ['mail_id' => $mailId, 'status' => (string)$now['status'], 'total' => $total, 'sent' => (int)$now['sent'], 'failed' => (int)$now['failed'],
                'waiting_for_hourly_limit' => $stoppedByLimit, 'continues_later' => $now['status'] === 'sending', 'last_error' => (string)$now['last_error']];
    }

    /** Continue mails that are waiting for the hourly limit or PER_REQUEST (called on later requests). */
    public function continueQueued(): void {
        if ($this->transport === null) {
            return;
        }

        try {
            foreach ($this->db->rows("SELECT `mail_id` FROM " . $this->db->t(self::TABLE) . " WHERE `status` = 'sending' ORDER BY `mail_id` LIMIT 3") as $r) {
                $this->deliver((int)$r['mail_id']);
            }
        } catch (\Throwable $e) {
        }
    }

    public function cancel(int $mailId): int {
        return $this->db->exec("UPDATE " . $this->db->t(self::TABLE) . " SET `status` = 'cancelled' WHERE `mail_id` = " . $mailId . " AND `status` IN ('draft', 'sending')");
    }

    public function list(int $limit = 20): array {
        return $this->db->rows("SELECT `mail_id`, `date_added`, `client`, `topic`, `subject`, `broadcast`, `status`, `total`, `sent`, `failed`, `expires`, `last_error` FROM " . $this->db->t(self::TABLE) . " ORDER BY `mail_id` DESC LIMIT " . max(1, min(100, $limit)));
    }
}
