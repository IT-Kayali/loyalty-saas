<?php
/** Wiederholt faellige fehlgeschlagene Webhook-Zustellungen. Cron, z. B. jede Minute: php tools/webhook-retry.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/public/index.php';
$lock = fopen(ROOT_DIR . '/data/webhook-retry.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Worker laeuft bereits\n"); exit(1); }
[$t, $o] = webhook_retry_due();
echo "Versucht: $t, zugestellt: $o\n";
