<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/public/index.php';
$lock=fopen(ROOT_DIR.'/data/mail-worker.lock','c');if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Worker already running\n");exit(1);}
maintenance_observe('mail_worker',function() use($argv) {
if(!smtp_ready()){
    // Ohne SMTP: nur Automationen mit Wallet-Inbox-Kanal ausfuehren, keine Mails senden.
    $queued=automation_enqueue();echo json_encode(['automation_delivered'=>$queued,'smtp'=>'disabled, no emails sent'],JSON_UNESCAPED_UNICODE).PHP_EOL;
    return;
}
$queued=automation_enqueue();$stats=mail_worker((int)($argv[1] ?? 25));
// Expired/reset links and sent bodies must not become a permanent credential archive.
db()->exec("DELETE FROM password_resets WHERE expires_at<datetime('now','-1 day')");
db()->exec("DELETE FROM mail_outbox WHERE category='transactional' AND created_at<datetime('now','-1 day') AND status!='sending'");
db()->exec("DELETE FROM mail_outbox WHERE created_at<datetime('now','-90 days') AND status IN ('sent','skipped')");
echo json_encode(['automation_queued'=>$queued]+$stats,JSON_UNESCAPED_UNICODE).PHP_EOL;
});
flock($lock,LOCK_UN);fclose($lock);
