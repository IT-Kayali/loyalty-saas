<?php
declare(strict_types=1);

/** A process invocation receipt, not a scheduler heartbeat or delivery receipt. */
function maintenance_tasks(): array
{
    return [
        'mail_worker'=>['Mail / Automation','Vorhandene Automationen vorbereiten und bei aktivem SMTP die Mail-Outbox bearbeiten. Ohne SMTP bleibt der vorhandene Inbox-Pfad möglich.'],
        'points_expiry'=>['Punkteverfall','Vorhandene aktivierte Inaktivitätsregeln für Punkteprogramme verarbeiten.'],
        'webhook_retry'=>['Webhook-Wiederholungen','Fällige fehlgeschlagene Zustellungen über den vorhandenen Retry-Helfer prüfen.'],
    ];
}

/** Separate, short writes. Never hold a logging transaction across actual work. */
function maintenance_connection(): PDO
{
    db(); // Additive schema must exist before opening the independent receipt connection.
    $d=new PDO('sqlite:'.(string)config('db_path',ROOT_DIR.'/data/app.sqlite'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $d->exec('PRAGMA busy_timeout=150');return $d;
}

function maintenance_notice(bool $completion): void
{
    // Broken/closed STDERR must not turn an observation failure into a task failure.
    try {@fwrite(STDERR,$completion?"CLI run completion status unavailable\n":"CLI run status unavailable; task still executes\n");}catch(Throwable){}
}

function maintenance_observe(string $task,callable $work): mixed
{
    if(PHP_SAPI!=='cli'||!array_key_exists($task,maintenance_tasks()))throw new InvalidArgumentException('Invalid maintenance task');
    $receipt=null;$token=null;
    try {
        $receipt=maintenance_connection();$token=bin2hex(random_bytes(16));
        $q=$receipt->prepare("INSERT INTO maintenance_status(task,run_token,started_at,finished_at,state) VALUES(?,?,?,NULL,'started') ON CONFLICT(task) DO UPDATE SET run_token=excluded.run_token,started_at=excluded.started_at,finished_at=NULL,state='started'");
        $q->execute([$task,$token,gmdate('Y-m-d H:i:s')]);$q->closeCursor();
    } catch(Throwable) {
        $receipt=null;maintenance_notice(false);
    }
    $state='failed';
    try {$result=$work();$state='completed';return $result;}
    finally {
        if($receipt!==null)try {
            // An earlier overlapping invocation must not close a newer invocation's receipt.
            $q=$receipt->prepare('UPDATE maintenance_status SET finished_at=?,state=? WHERE task=? AND run_token=?');
            $q->execute([gmdate('Y-m-d H:i:s'),$state,$task,$token]);$q->closeCursor();
        } catch(Throwable) {maintenance_notice(true);}
    }
}

function maintenance_time(mixed $value): bool
{
    if(!is_string($value)||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$value)||substr($value,0,4)<'0001')return false;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return $d!==false&&$d->format('Y-m-d H:i:s')===$value;
}

function maintenance_snapshot(?int $now=null): array
{
    $d=db();$d->exec('BEGIN');
    try {
        $q=$d->query("SELECT task,started_at,finished_at,state FROM maintenance_status WHERE task IN('mail_worker','points_expiry','webhook_retry')");
        $rows=$q->fetchAll();$q->closeCursor();$at=gmdate('Y-m-d H:i:s',$now??time());$d->exec('COMMIT');
    } catch(Throwable $e) {try{$d->exec('ROLLBACK');}catch(Throwable){}throw $e;}
    $out=[];foreach(maintenance_tasks() as $key=>$unused)$out[$key]=['state'=>'missing','started_at'=>null,'finished_at'=>null];
    foreach($rows as $row) {
        $start=$row['started_at'];$end=$row['finished_at'];$state=$row['state'];
        $valid=in_array($state,['started','completed','failed'],true)&&maintenance_time($start)&&$start<=$at;
        $valid=$valid&&($state==='started'?$end===null:(maintenance_time($end)&&$end>=$start&&$end<=$at));
        $out[$row['task']]=$valid?['state'=>$state,'started_at'=>$start,'finished_at'=>$end]:['state'=>'invalid','started_at'=>null,'finished_at'=>null];
    }
    return ['at'=>$at,'tasks'=>$out];
}

function route_admin_maintenance(string $method): never
{
    $u=require_platform_admin();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
    if($method!=='GET'){header('Allow: GET');http_response_code(405);exit;}
    try {$s=maintenance_snapshot();}
    catch(Throwable) {http_response_code(503);page('CLI-Laufstatus','<h1>CLI-Laufstatus nicht verfügbar</h1><p>Keine verlässliche Momentaufnahme. Daten und Betrieb getrennt prüfen.</p>',$u);exit;}
    $labels=['missing'=>'Kein Lauf erfasst','started'=>'Kein Abschluss gespeichert','completed'=>'Ohne geworfenen Fehler beendet','failed'=>'Mit Fehler beendet','invalid'=>'Gespeicherte Daten prüfen'];
    $body='<div class="maintenance-view"><p><a href="/admin/health">← Versandzustand</a></p><p class="eyebrow">PLATTFORM</p><h1>CLI-Laufstatus</h1><p>Zuletzt gespeicherter Aufruf je vorhandenem CLI-Befehl. Momentaufnahme: <strong>'.h($s['at']).' UTC</strong>.</p><p class="muted">Kein Beweis für eingerichtete Cronjobs, einen lebenden Prozess oder zugestellte Nachrichten. Manuelle CLI-Aufrufe und Cron sind hier nicht unterscheidbar.</p><p><a class="btn ghost" href="/admin/maintenance">Momentaufnahme erneuern</a></p><div class="maintenance-grid">';
    foreach(maintenance_tasks() as $task=>[$title,$description]) {
        $r=$s['tasks'][$task];$body.='<section class="panel" data-maintenance-task="'.h($task).'"><h2>'.h($title).'</h2><p>'.h($description).'</p><p class="maintenance-state" data-maintenance-state="'.h($r['state']).'">'.h($labels[$r['state']]).'</p><dl><dt>Letzter gespeicherter Start (UTC)</dt><dd>'.h($r['started_at']??'Nicht auswertbar / nicht erfasst').'</dd><dt>Abschluss dieses Aufrufs (UTC)</dt><dd>'.h($r['finished_at']??'Nicht auswertbar / nicht erfasst').'</dd></dl></section>';
    }
    $body.='</div><section class="panel"><h2>Was dieser Status nicht sagt</h2><p>Ein fehlender Datensatz bedeutet nicht, dass die Aufgabe nie lief. Erst Aufrufe mit dieser Erweiterung erzeugen einen Beleg. Kein Abschluss kann einen noch laufenden, abgebrochenen oder nicht protokollierbaren Aufruf bedeuten.</p><p>Ohne geworfenen Fehler beendet beschreibt nur den PHP-Aufruf. Übersprungene Arbeit, deaktiviertes SMTP, einzelne unklare Mailergebnisse oder fehlgeschlagene Webhook-Versuche können trotzdem vorkommen. Versandzustand und Anbieter getrennt prüfen.</p><p>Je Aufgabe bleibt nur der zuletzt gespeicherte Start mit seinem Abschluss erhalten. Ein älterer überlappender Aufruf überschreibt keinen neueren Beleg. Keine vollständige Laufhistorie, automatische Fehlerreparatur oder Überfälligkeitswertung. Direkte Helferaufrufe über Web/API, Backup, PayPal-Abgleich und Wallet-Bereinigung sind nicht erfasst.</p><p>Diese GET-Seite startet keine Aufgabe, sendet nichts und verändert keinen Laufstatus. Kein Run-now-Befehl und keine automatische Aktualisierung.</p></section></div>';
    page('CLI-Laufstatus',$body,$u);exit;
}
