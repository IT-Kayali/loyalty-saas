<?php
declare(strict_types=1);

function is_platform_admin(array $user): bool
{
    return in_array(strtolower((string)$user['email']), array_map('strtolower', (array)config('platform_admin_emails', [])), true);
}

function require_platform_admin(): array
{
    $u = require_login();
    if (!is_platform_admin($u)) { http_response_code(403);page('403','<h1>403</h1><p>Kein Plattform-Zugriff.</p>',$u);exit; }
    return $u;
}

function platform_setting(string $key, string $default=''): string
{
    $q=db()->prepare('SELECT value FROM platform_settings WHERE key=?');$q->execute([$key]);
    $v=$q->fetchColumn();return $v===false ? $default : (string)$v;
}

function audit_log(int $userId, string $action, array $details=[]): void
{
    db()->prepare('INSERT INTO audit_logs (user_id,action,details) VALUES (?,?,?)')
        ->execute([$userId,$action,json_encode($details,JSON_UNESCAPED_UNICODE)]);
}

function route_admin(string $method): never
{
    $u=require_platform_admin();
    if ($method==='POST') {
        $action=$_POST['action'] ?? '';
        if ($action==='settings') {
            $name=mb_substr(trim((string)($_POST['platform_name'] ?? 'Loyalty')),0,60);
            foreach (['platform_name'=>$name,'registration_open'=>isset($_POST['registration_open'])?'1':'0'] as $key=>$value) {
                db()->prepare('INSERT INTO platform_settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute([$key,$value]);
            }
            audit_log((int)$u['id'],'platform.settings');
            flash(t('Plattform-Einstellungen gespeichert.'));
        }
        if ($action==='webhook_retry') {
            $r=webhook_attempt((int)($_POST['delivery_id'] ?? 0));
            if($r==='locked'){flash(t('Diese Zustellung wird gerade gesendet. Bitte kurz warten.'),'err');redirect('/admin');}
            audit_log((int)$u['id'],'webhook.retry',['delivery'=>(int)($_POST['delivery_id'] ?? 0),'result'=>$r]);
            flash($r==='delivered' ? t('Webhook zugestellt.') : t('Zustellung weiter fehlgeschlagen. Details im Protokoll.'), $r==='delivered' ? 'ok' : 'err');
        }
        if ($action==='webhook_ack') {
            $n=db()->exec("UPDATE webhook_deliveries SET dead_ack=1 WHERE status='dead' AND dead_ack=0");
            audit_log((int)$u['id'],'webhook.ack',['count'=>$n]);
            flash(t('Hinweis als gesehen markiert.'));
        }
        if ($action==='webhook_run_due') {
            [$tried,$ok]=webhook_retry_due();
            audit_log((int)$u['id'],'webhook.run_due',['tried'=>$tried,'delivered'=>$ok]);
            flash(sprintf(t('Fällige Wiederholungen: %d versucht, %d zugestellt.'),$tried,$ok));
        }
        redirect('/admin');
    }
    $stats=[];
    foreach(['users','businesses','members','programs','transactions'] as $table) $stats[$table]=(int)db()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    $dead=(int)db()->query("SELECT COUNT(*) FROM webhook_deliveries WHERE status='dead' AND dead_ack=0")->fetchColumn();
    $body=$dead?'<section class="panel" role="alert" style="border-color:#c0392b"><strong>'.sprintf(t('%d Webhook-Zustellung(en) endgültig fehlgeschlagen'),$dead).'</strong><p class="muted">'.t('Alle Versuche sind verbraucht. Unten ansehen und wiederholen oder als gesehen markieren.').'</p><form method="post">'.csrf_field().'<input type="hidden" name="action" value="webhook_ack"><button class="btn ghost small">'.t('Als gesehen markieren').'</button></form></section>':'';
    $body.='<p class="admin-links"><a class="btn" href="/admin/support">Supporttickets</a><a class="btn ghost" href="/admin/health">Versandzustand</a></p><p class="eyebrow">PLATTFORM</p><h1>' . t('Admin-Dashboard') . '</h1><p class="muted">' . t('Plattformweiter Zugriff. Getrennt vom Dashboard eines einzelnen Betreibers.') . '</p><div class="grid">';
    foreach($stats as $key=>$value) $body.='<section class="panel"><p class="muted">'.h($key).'</p><strong class="big-number">'.$value.'</strong></section>';
    $body.='</div><h2>'.t('Unternehmen & Betreiber').'</h2><div class="table-wrap"><table><thead><tr><th>ID</th><th>Unternehmen</th><th>Betreiber</th><th>Plan</th><th>Status</th><th>Mitglieder</th></tr></thead><tbody>';
    $rows=db()->query('SELECT b.id,b.name,u.email,s.plan_key,s.status,(SELECT COUNT(*) FROM members m WHERE m.business_id=b.id) AS members FROM businesses b JOIN users u ON u.id=b.user_id LEFT JOIN subscriptions s ON s.user_id=u.id ORDER BY b.id DESC LIMIT 200')->fetchAll();
    foreach($rows as $r) $body.='<tr><td>'.(int)$r['id'].'</td><td>'.h($r['name']).'</td><td>'.h($r['email']).'</td><td>'.h($r['plan_key'] ?: 'Keiner').'</td><td>'.h($r['status'] ?: 'none').'</td><td>'.(int)$r['members'].'</td></tr>';
    $body.='</tbody></table></div><p class="muted">Maximal 200 neueste Unternehmen.</p><h2>'.t('Systemzustand').'</h2><div class="grid">';
    $checks=[
        'SQLite-Verbindung'=>true,
        'Planprüfung aktiviert'=>(bool)config('stripe.enforce_limits',false),
        'Session-Cookies geschützt'=>(bool)ini_get('session.cookie_httponly'),
        'GD / Bildverarbeitung'=>extension_loaded('gd'),
        'cURL / HTTPS'=>extension_loaded('curl'),
        'Stripe eingerichtet'=>billing_ready(),
        'Apple Wallet eingerichtet'=>apple_wallet_ready(),
        'Google Wallet eingerichtet'=>google_wallet_ready(),
        'HTTPS Basis-URL'=>str_starts_with(base_url(),'https://'),
        'DB außerhalb Webroot'=>!str_starts_with((string)realpath((string)config('db_path')), (string)realpath(ROOT_DIR.'/public').DIRECTORY_SEPARATOR),
    ];
    foreach($checks as $label=>$ok) $body.='<section class="panel"><strong>'.h($label).'</strong><p>'.($ok?'✓ Bereit':'○ Nicht eingerichtet / prüfen').'</p></section>';
    $failed=(int)db()->query("SELECT COUNT(*) FROM webhook_deliveries WHERE status!='delivered'")->fetchColumn();
    $body.='</div><h2>'.t('Webhook-Zustellungen zur Prüfung').': '.$failed.'</h2>';
    $rowsF='';
    foreach(db()->query("SELECT d.id,d.event,d.status,d.response,d.attempts,d.next_attempt_at,d.delivered_at,w.url,b.name AS bname FROM webhook_deliveries d JOIN webhooks w ON w.id=d.webhook_id JOIN businesses b ON b.id=w.business_id WHERE d.status!='delivered' ORDER BY d.id DESC LIMIT 20")->fetchAll() as $d) {
        $rowsF.='<section class="panel"><strong>'.h($d['bname']).'</strong> <span class="muted">'.h($d['event']).' &middot; '.h($d['status']).' &middot; '.(int)$d['attempts'].' Versuch(e)'.($d['next_attempt_at'] ? ' &middot; nächster '.h($d['next_attempt_at']).' UTC' : '').'</span><p><code style="word-break:break-all">'.h($d['url']).'</code><br><span class="muted">'.h($d['response']).'</span></p><form method="post">'.csrf_field().'<input type="hidden" name="action" value="webhook_retry"><input type="hidden" name="delivery_id" value="'.(int)$d['id'].'"><button class="btn ghost small">'.t('Wiederholen').'</button></form></section>';
    }
    if($rowsF) $body.='<div class="grid">'.$rowsF.'</div><p class="muted">Maximal 20 neueste. failed = wird automatisch wiederholt (Cron), dead = nur manuell.</p>';
    $body.='<form method="post" class="form inline-form">'.csrf_field().'<input type="hidden" name="action" value="webhook_run_due"><button class="btn ghost small">'.t('Fällige Wiederholungen jetzt ausführen').'</button></form><p></p>';
    $body.='<section class="panel"><h2>'.t('Transaktionen exportieren').'</h2><p>CSV mit Datum, Unternehmen, Programm, Aktion und Betrag. Maximal 10.000 Einträge pro Zeitraum.</p><form method="get" action="/admin/export" class="form inline-form"><label>Von<input type="date" name="from" value="'.date('Y-m-01').'" required></label><label>Bis<input type="date" name="to" value="'.date('Y-m-d').'" required></label><button class="btn">CSV herunterladen</button></form></section>';
    $body.='<section class="panel"><h2>'.t('Plattform-Einstellungen').'</h2><form method="post" class="form">'.csrf_field().'<input type="hidden" name="action" value="settings"><label>Plattform-Name<input name="platform_name" maxlength="60" value="'.h(platform_setting('platform_name','Loyalty')).'" required></label><label class="check"><input type="checkbox" name="registration_open" '.(platform_setting('registration_open','1')==='1'?'checked':'').'> Betreiber-Registrierung erlauben</label><button class="btn">Speichern</button></form></section>';
    $body.='<h2>'.t('Aktivitätsprotokoll').'</h2><p><a class="btn ghost" href="/admin/audit">Filtern und ältere Einträge lesen</a></p><div class="table-wrap"><table><thead><tr><th>UTC</th><th>Konto</th><th>Aktion</th></tr></thead><tbody>';
    foreach(db()->query('SELECT a.created_at,u.email,a.action FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')->fetchAll() as $a) $body.='<tr><td>'.h($a['created_at']).'</td><td>'.h($a['email']).'</td><td>'.h($a['action']).'</td></tr>';
    page(t('Admin-Dashboard'),$body.'</tbody></table></div>',$u);exit;
}

function route_admin_export(): never
{
    $u=require_platform_admin();$from=(string)($_GET['from'] ?? '');$to=(string)($_GET['to'] ?? '');
    $valid=static function(string $date): bool {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        return $d && $d->format('Y-m-d')===$date;
    };
    if (!$valid($from) || !$valid($to) || $from>$to) { http_response_code(400);echo 'Ungültiger Zeitraum';exit; }
    $q=db()->prepare('SELECT t.id,t.created_at,b.name AS business,p.name AS program,t.kind,t.amount,t.note FROM transactions t JOIN cards c ON c.id=t.card_id JOIN programs p ON p.id=c.program_id JOIN businesses b ON b.id=p.business_id WHERE t.created_at>=? AND t.created_at<date(?,\'+1 day\') ORDER BY t.id LIMIT 10001');$q->execute([$from,$to]);$rows=$q->fetchAll();
    if(count($rows)>10000) { flash('Zu viele Zeilen. Bitte den Zeitraum verkleinern.','err');redirect('/admin'); }
    audit_log((int)$u['id'],'ledger.export',['from'=>$from,'to'=>$to,'rows'=>count($rows)]);
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="loyalty-transactions.csv"');
    $out=fopen('php://output','w');fputcsv($out,['ID','UTC','Business','Program','Action','Amount','Note'],',','"','');
    foreach($rows as $row) {
        foreach($row as &$v) if(is_string($v) && preg_match('/^[\s]*[=+@-]/u',$v)) $v="'".$v;
        unset($v);fputcsv($out,array_values($row),',','"','');
    }
    fclose($out);exit;
}

/** Read-only index of existing audit rows, without stored detail payloads. */
function route_admin_audit(string $method): never
{
    $u=require_platform_admin();header('Cache-Control: private, no-store');
    if($method!=='GET'){header('Allow: GET');http_response_code(405);exit;}
    try{
        $filters=[];foreach(['from','to','action','account','before'] as $key){$v=$_GET[$key] ?? '';if(!is_string($v) || preg_match('/[\x00-\x1f\x7f]/',$v))throw new InvalidArgumentException('Filter müssen einzelne Werte ohne Steuerzeichen sein.');$filters[$key]=trim($v);}
        ['from'=>$from,'to'=>$to,'action'=>$action,'account'=>$account,'before'=>$before]=$filters;
        foreach([$from,$to] as $date)if($date!==''){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));if(!$d || $d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Gültige UTC-Daten im Format JJJJ-MM-TT angeben.');}
        if($from!=='' && $to!=='' && $from>$to)throw new InvalidArgumentException('Von darf nicht nach Bis liegen.');
        if(strlen($action)>120 || ($action!=='' && !preg_match('/^[a-zA-Z0-9_.:-]+$/D',$action)))throw new InvalidArgumentException('Aktion exakt mit maximal 120 Zeichen angeben (Buchstaben, Zahlen, Punkt, Unterstrich, Doppelpunkt, Bindestrich).');
        if(!mb_check_encoding($account,'UTF-8') || mb_strlen($account)>254 || preg_match('/[\x00-\x1f\x7f]/u',$account))throw new InvalidArgumentException('Kontosuche mit maximal 254 Zeichen ohne Steuerzeichen angeben.');
        if($before!=='' && (!preg_match('/^[1-9][0-9]{0,17}$/D',$before)))throw new InvalidArgumentException('Ungültige Folgeseite.');
    }catch(InvalidArgumentException $e){http_response_code(422);page('Protokollfilter prüfen','<h1>Protokollfilter prüfen</h1><p>'.h($e->getMessage()).'</p><p><a href="/admin/audit">Filter zurücksetzen</a></p>',$u);exit;}
    $where=['1=1'];$params=[];
    if($from!==''){$where[]='a.created_at>=?';$params[]=$from.' 00:00:00';}
    if($to!==''){$where[]='a.created_at<=?';$params[]=$to.' 23:59:59';}
    if($action!==''){$where[]='a.action=?';$params[]=$action;}
    if($account!==''){$where[]="u.email LIKE ? ESCAPE '!'";$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$account).'%';}
    if($before!==''){$where[]='a.id<?';$params[]=(int)$before;}
    $q=db()->prepare('SELECT a.id,a.created_at,a.action,u.email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE '.implode(' AND ',$where).' ORDER BY a.id DESC LIMIT 101');$q->execute($params);$rows=$q->fetchAll();$more=count($rows)>100;$rows=array_slice($rows,0,100);
    $body='<p><a href="/admin">← Admin-Dashboard</a></p><h1>Aktivitätsprotokoll</h1><p>Nur vorhandene Protokolleinträge, neueste ID zuerst, maximal 100 je Seite. Keine lückenlose Sicherheits- oder Änderungshistorie. Gespeicherte Detaildaten werden hier nicht angezeigt. Konto zeigt die heutige E-Mail, keinen historischen Identitätsnachweis.</p><form method="get" class="form inline-form"><label>Von (UTC, inklusive)<input type="date" name="from" value="'.h($from).'"></label><label>Bis (UTC, inklusive)<input type="date" name="to" value="'.h($to).'"></label><label>Aktion exakt<input name="action" maxlength="120" placeholder="support.partner_state" value="'.h($action).'"></label><label>Konto enthält<input name="account" maxlength="254" value="'.h($account).'"></label><button class="btn">Filtern</button><a href="/admin/audit">Zurücksetzen</a></form><p>'.count($rows).' Einträge auf dieser Seite'.($before!==''?' · ältere Folgeseite':'').'.</p><div class="table-wrap audit-table"><table><thead><tr><th>ID / UTC</th><th>Konto</th><th>Aktion</th></tr></thead><tbody>';
    foreach($rows as $row)$body.='<tr data-audit-id="'.(int)$row['id'].'"><td data-label="ID / UTC">'.(int)$row['id'].'<br>'.h($row['created_at']).'</td><td data-label="Konto">'.h($row['email'] ?? 'Konto nicht mehr vorhanden').'</td><td data-label="Aktion">'.h($row['action']).'</td></tr>';
    if(!$rows)$body.='<tr><td colspan="3">Keine passenden Einträge.</td></tr>';
    $body.='</tbody></table></div>';
    if($more){$filters['before']=(string)end($rows)['id'];$body.='<p><a class="btn ghost" href="/admin/audit?'.h(http_build_query($filters)).'">Ältere 100</a></p>';}
    page('Aktivitätsprotokoll',$body,$u);exit;
}
