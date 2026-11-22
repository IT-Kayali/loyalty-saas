<?php
declare(strict_types=1);

/** Retained rows, not delivery receipts or a worker heartbeat. No personal columns selected. */
function admin_queue_snapshot(?int $now=null): array
{
    $db=db();$db->exec('BEGIN');
    try {
        // Establish the read snapshot before taking its clock, including after a writer wait.
        $q=$db->query('SELECT 1 FROM mail_outbox LIMIT 1');$q->fetchColumn();$q->closeCursor();
        $now ??= time();$at=gmdate('Y-m-d H:i:s',$now);$stale=gmdate('Y-m-d H:i:s',$now-600);
        // Canonical UTC storage is required for the same lexical comparisons as the workers.
        $q=$db->prepare(<<<'SQL'
WITH jobs AS (
 SELECT status,created_at,claimed_at,
  CASE WHEN length(created_at)=19 AND datetime(created_at,'+0 seconds')=created_at THEN 1 ELSE 0 END AS created_valid,
  CASE WHEN length(claimed_at)=19 AND datetime(claimed_at,'+0 seconds')=claimed_at THEN 1 ELSE 0 END AS claim_valid
 FROM mail_outbox
)
SELECT COUNT(*) AS total,
 COALESCE(SUM(status='queued'),0) AS queued,
 COALESCE(SUM(status='sending'),0) AS sending,
 COALESCE(SUM(status='sending' AND claim_valid=1 AND claimed_at<:stale),0) AS stale_sending,
 COALESCE(SUM(status='unknown'),0) AS unknown,
 COALESCE(SUM(status='sent'),0) AS sent,
 COALESCE(SUM(status='skipped'),0) AS skipped,
 COALESCE(SUM(status NOT IN('queued','sending','unknown','sent','skipped')),0) AS other,
 COALESCE(SUM((status='queued' AND (created_valid=0 OR created_at>:now)) OR (status='sending' AND (claim_valid=0 OR claimed_at>:now))),0) AS bad_open_time,
 MIN(CASE WHEN status='queued' AND created_valid=1 AND created_at<=:now THEN created_at END) AS oldest_queued
FROM jobs
SQL);
        $q->execute(['stale'=>$stale,'now'=>$at]);$mail=$q->fetch();$q->closeCursor();
        $q=$db->prepare(<<<'SQL'
WITH jobs AS (
 SELECT status,next_attempt_at,lock_until,
  CASE WHEN length(next_attempt_at)=19 AND datetime(next_attempt_at,'+0 seconds')=next_attempt_at THEN 1 ELSE 0 END AS next_valid,
  CASE WHEN length(lock_until)=19 AND datetime(lock_until,'+0 seconds')=lock_until THEN 1 ELSE 0 END AS lock_valid
 FROM webhook_deliveries
)
SELECT COUNT(*) AS total,
 COALESCE(SUM(status='pending'),0) AS pending,
 COALESCE(SUM(status='failed'),0) AS failed,
 COALESCE(SUM(status='dead'),0) AS dead,
 COALESCE(SUM(status='delivered'),0) AS delivered,
 COALESCE(SUM(status NOT IN('pending','failed','dead','delivered')),0) AS other,
 COALESCE(SUM(status='failed' AND next_valid=1 AND next_attempt_at<=:now AND (lock_until IS NULL OR (lock_valid=1 AND lock_until<:now))),0) AS due_unlocked,
 COALESCE(SUM(status='failed' AND next_valid=1 AND next_attempt_at<=:now AND lock_valid=1 AND lock_until>=:now),0) AS due_locked,
 COALESCE(SUM(status='failed' AND next_attempt_at IS NULL),0) AS unscheduled,
 COALESCE(SUM(status!='delivered' AND lock_valid=1 AND lock_until<:now),0) AS expired_locks,
 COALESCE(SUM((status='failed' AND next_attempt_at IS NOT NULL AND next_valid=0) OR (status!='delivered' AND lock_until IS NOT NULL AND lock_valid=0)),0) AS bad_open_time,
 MIN(CASE WHEN status='failed' AND next_valid=1 AND next_attempt_at<=:now THEN next_attempt_at END) AS oldest_due
FROM jobs
SQL);
        $q->execute(['now'=>$at]);$hooks=$q->fetch();$q->closeCursor();$db->exec('COMMIT');
    } catch(Throwable $e) {try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
    foreach($mail as $key=>$value)if(!str_starts_with($key,'oldest_'))$mail[$key]=(int)$value;
    foreach($hooks as $key=>$value)if(!str_starts_with($key,'oldest_'))$hooks[$key]=(int)$value;
    return ['at'=>$at,'smtp_configured'=>smtp_ready(),'mail'=>$mail,'webhooks'=>$hooks];
}

/** Versioned local queue document, deliberately not the reference's full checks/summary API. */
function admin_health_document(array $snapshot): array
{
    return ['success'=>true,'data'=>[
        'schema_version'=>1,'scope'=>'retained_delivery_queues',
        'observed_at_utc'=>$snapshot['at'],'smtp_configured'=>$snapshot['smtp_configured'],
        'mail'=>$snapshot['mail'],'webhooks'=>$snapshot['webhooks'],
        'limitations'=>['retained_rows_only','overlapping_subcounts','canonical_utc_only',
            'smtp_configuration_only','pending_webhooks_not_retried','no_delivery_proof'],
        'not_checked'=>['worker_heartbeat','cron_execution','smtp_connectivity','inbox_delivery',
            'wallet_delivery','device_display','environment','migrations','storage','billing'],
    ]];
}

function admin_health_json_response(array $document,int $status=200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($document,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;
}

function route_admin_health_json(string $method): never
{
    // No redirect or HTML login body for monitoring clients. Session revocation still applies.
    try {
        $u=current_user();
        if(!$u)admin_health_json_response(['success'=>false,'error'=>'authentication_required'],401);
        if(!is_platform_admin($u))admin_health_json_response(['success'=>false,'error'=>'platform_access_required'],403);
        if($method!=='GET'){
            header('Allow: GET');admin_health_json_response(['success'=>false,'error'=>'method_not_allowed'],405);
        }
        $document=admin_health_document(admin_queue_snapshot());
    } catch(Throwable) {
        // No SQL, paths, exception text or partial aggregates in a failed response.
        admin_health_json_response(['success'=>false,'error'=>'snapshot_unavailable'],503);
    }
    admin_health_json_response($document);
}

function admin_health_metrics(string $group,array $values,array $labels): string
{
    $html='<dl class="health-metrics">';
    foreach($labels as $key=>$label)$html.='<div><dt>'.h($label).'</dt><dd data-health-key="'.h($group.'.'.$key).'">'.(int)$values[$key].'</dd></div>';
    return $html.'</dl>';
}

function route_admin_health(string $method): never
{
    $u=require_platform_admin();header('Cache-Control: private, no-store');
    if($method!=='GET'){header('Allow: GET');http_response_code(405);exit;}
    $s=admin_queue_snapshot();$m=$s['mail'];$w=$s['webhooks'];
    $body='<p><a href="/admin">← Admin-Dashboard</a></p><p class="eyebrow">PLATTFORM</p><h1>Versandzustand</h1><p>Gespeicherte Mail- und Webhook-Zeilen, plattformweit. Nur lesende Momentaufnahme vom <strong>'.h($s['at']).' UTC</strong>.</p><p class="muted">Kein Worker-Heartbeat und kein Nachweis laufender Cronjobs. Keine Empfänger oder Nachrichteninhalte. Zähler umfassen die aufbewahrten Daten, keinen festen Berichtszeitraum.</p><p class="health-actions"><a class="btn ghost" href="/admin/health">Momentaufnahme erneuern</a> <a class="btn ghost" href="/admin/health/json">JSON-Momentaufnahme</a></p><div class="health-grid">';
    $body.='<section class="panel" data-health-panel="mail"><h2>Mail-Outbox</h2><p>'.($s['smtp_configured']?'SMTP-Konfiguration vorhanden. Das beweist keine erreichbare Verbindung.':'SMTP nicht konfiguriert. Der Mail-Worker verarbeitet so keine Aufträge.').'</p>';
    $body.=admin_health_metrics('mail',$m,['total'=>'Alle gespeicherten Zeilen','queued'=>'Wartend (queued)','sending'=>'In Bearbeitung (sending)','stale_sending'=>'Davon länger als 10 Minuten','unknown'=>'Unklar (unknown)','sent'=>'Versandhelfer meldet Erfolg (sent)','skipped'=>'Übersprungen (skipped)','other'=>'Nicht erkannter Status','bad_open_time'=>'Offene Zeiten fehlen / ungültig / in Zukunft']);
    $body.='<p class="health-time">Älteste auswertbare wartende Mail:<br><strong>'.h($m['oldest_queued']!==null?$m['oldest_queued'].' UTC':'Keine auswertbare Zeit').'</strong></p><p class="muted">Bei gültiger SMTP-Konfiguration markiert der nächste Worker-Lauf sending-Einträge nach mehr als zehn Minuten als unknown. Unklare Mails nicht blind erneut senden. sent ist kein Nachweis der Ankunft im Postfach.</p></section>';
    $body.='<section class="panel" data-health-panel="webhooks"><h2>Webhook-Zustellungen</h2><p>Wiederholungen benötigen den eingerichteten Cronjob. Die Zähler starten keine Übertragung.</p>';
    $body.=admin_health_metrics('webhooks',$w,['total'=>'Alle gespeicherten Zeilen','pending'=>'Offen (pending)','failed'=>'Fehlgeschlagen (failed)','due_unlocked'=>'Davon fällig und nicht gesperrt','due_locked'=>'Davon fällig und noch gesperrt','dead'=>'Endgültig fehlgeschlagen (dead)','delivered'=>'Als zugestellt gespeichert (delivered)','other'=>'Nicht erkannter Status','unscheduled'=>'failed ohne nächsten Termin','expired_locks'=>'Offene Zeilen mit abgelaufener Sperre','bad_open_time'=>'Ungültige offene Zeitangaben']);
    $body.='<p class="health-time">Ältester fälliger Wiederholungstermin:<br><strong>'.h($w['oldest_due']!==null?$w['oldest_due'].' UTC':'Kein auswertbarer fälliger Termin').'</strong></p><p class="muted">Fällig heißt Termin erreicht, nicht erfolgreicher Versand. Eine Sperre genau zur Aufnahmezeit gilt noch als aktiv. pending wird vom Retry-Worker nicht automatisch übernommen; sein Anlagezeitpunkt ist nicht gespeichert. dead verlangt eine gesonderte Prüfung.</p></section></div>';
    $body.='<section class="panel health-limits"><h2>Grenzen dieser Ansicht</h2><p>Unterzähler können sich überschneiden. Nur kanonische UTC-Zeiten werden ausgewertet. Alte Zeilen können bereits bereinigt oder gelöscht sein. Keine Sicherheitsanalyse, automatische Reparatur oder Versandfreigabe.</p><p>Wallet-Einzelmitteilungen und technische Pass-Updates sind hier nicht erfasst. Providerannahme wäre auch dort kein Nachweis einer Anzeige auf dem Gerät.</p><p>Diese Seite sendet nichts, ändert keine Zustände und setzt keine Sperren zurück. Versand- und Betriebsbefehle stehen in der Projektdokumentation; unklare Ergebnisse zuerst beim Anbieter prüfen.</p></section>';
    page('Versandzustand',$body,$u);exit;
}
