<?php
declare(strict_types=1);
const SUPPORT_TOPICS=['billing'=>'Abrechnung','technical'=>'Technik','account'=>'Konto','feedback'=>'Feedback','other'=>'Sonstiges'];
const SUPPORT_STATUS=['open'=>'Offen','awaiting'=>'Wartet auf Antwort','closed'=>'Geschlossen'];
const SUPPORT_CHANNELS=['partner'=>'Angemeldete Betreiber','contact'=>'Kontaktformular (unbestätigt)'];
const SUPPORT_PRIORITY=['low'=>'Niedrig','normal'=>'Normal','high'=>'Hoch'];
function support_user(int $id): array {$q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);$u=$q->fetch();if(!$u)throw new InvalidArgumentException('Konto nicht verfügbar.');return $u;}
function support_load(string $ref,?array $user,bool $admin): array
{
    $q=db()->prepare('SELECT * FROM support_tickets WHERE reference=? AND deleted_at IS NULL');$q->execute([$ref]);$t=$q->fetch();
    if(!$t || (!$admin && (!$user || $t['channel']!=='partner' || (int)$t['user_id']!==(int)$user['id'])))throw new InvalidArgumentException('Ticket nicht verfügbar.');return $t;
}
/** No bodies in email notifications, internal notes or audit. Ticket and outbox commit together. */
function support_notify(array $t,bool $toOperator,int $messageId): void
{
    $recipient=$toOperator?(string)config('support_email',''):$t['requester_email'];
    // A public contact address is unverified. Never email it a thread or reply content.
    if(!$toOperator && $t['channel']==='contact')return;
    if(!filter_var($recipient,FILTER_VALIDATE_EMAIL) || preg_match('/@(example\.(com|org|net)|.*\.test|.*\.local)$/i',$recipient))return;
    $url=base_url().($toOperator?'/admin/support/':'/support/').$t['reference'];
    queue_mail(null,null,$recipient,'Loyalty Support '.$t['reference'],'Neue Aktivität zu deinem Supportvorgang. Bitte in der Plattform anmelden: '.$url.' Antworten per E-Mail werden nicht in den Vorgang übernommen.','transactional','support-'.$messageId.'-'.($toOperator?'operator':'partner'));
}
function support_write(?int $uid,bool $admin,array $input,bool $contact=false): string
{
    $user=$uid?support_user($uid):null;if($admin && (!$user || !is_platform_admin($user)))throw new InvalidArgumentException('Kein Plattform-Zugriff.');if(!$contact && !$user)throw new InvalidArgumentException('Anmeldung erforderlich.');
    $op=(string)($input['operation'] ?? '');if(!preg_match('/^[a-f0-9]{32}$/D',$op))throw new InvalidArgumentException('Vorgangskennung fehlt.');
    $action=(string)($input['action'] ?? 'create');$ref=(string)($input['reference'] ?? '');$body=trim((string)($input['message'] ?? ''));$subject=trim((string)($input['subject'] ?? ''));$topic=(string)($input['topic'] ?? 'other');$internal=!empty($input['internal']);
    if($internal && !$admin)throw new InvalidArgumentException('Interne Notizen nur für Plattform-Admins.');
    $actor=$contact?'contact:'.hash('sha256',session_id()):'user:'.$uid.':'.($admin?'admin':'partner');$hash=hash('sha256',$op.json_encode(array_diff_key($input,['csrf'=>1]),JSON_THROW_ON_ERROR));$db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare('SELECT o.*,t.reference FROM support_operations o JOIN support_tickets t ON t.id=o.ticket_id WHERE operation=?');$q->execute([$op]);
        if($old=$q->fetch()){if(!hash_equals($actor,$old['actor']) || !hash_equals($hash,$old['request_hash']))throw new InvalidArgumentException('Vorgangskennung steht für andere Eingaben.');$db->exec('COMMIT');return $old['reference'];}
        if($action==='create'){
            if($admin || !isset(SUPPORT_TOPICS[$topic]) || $subject==='' || mb_strlen($subject)>160)throw new InvalidArgumentException('Thema und Betreff (max. 160 Zeichen) erforderlich.');
            $name=$contact?trim((string)($input['name'] ?? '')):$user['name'];$email=$contact?strtolower(trim((string)($input['email'] ?? ''))):$user['email'];
            if($name==='' || mb_strlen($name)>120 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254)throw new InvalidArgumentException('Name und gültige E-Mail erforderlich.');
            if($body==='' || mb_strlen($body)>10000)throw new InvalidArgumentException('Nachricht mit 1 bis 10000 Zeichen erforderlich.');
            $ref=strtoupper(bin2hex(random_bytes(8)));$db->prepare('INSERT INTO support_tickets(reference,user_id,channel,requester_name,requester_email,topic,subject)VALUES(?,?,?,?,?,?,?)')->execute([$ref,$contact?null:$uid,$contact?'contact':'partner',$name,$email,$topic,$subject]);$id=(int)$db->lastInsertId();$q=$db->prepare('SELECT * FROM support_tickets WHERE id=?');$q->execute([$id]);$t=$q->fetch();
        }else{
            if($contact)throw new InvalidArgumentException('Kein öffentlicher Threadzugriff.');$t=support_load($ref,$user,$admin);$id=(int)$t['id'];
            if((int)($input['version'] ?? 0)!==(int)$t['version'])throw new InvalidArgumentException('Der Vorgang wurde inzwischen geändert. Seite neu laden und Stand prüfen.');
        }
        if(in_array($action,['create','reply'],true)){
            if($body==='' || mb_strlen($body)>10000)throw new InvalidArgumentException('Nachricht mit 1 bis 10000 Zeichen erforderlich.');
            if($t['channel']==='contact' && !$internal && $action==='reply')throw new InvalidArgumentException('Kontaktadresse unbestätigt. In dieser Stufe nur interne Bearbeitung, kein öffentlicher Antwortversand.');
            $db->prepare('INSERT INTO support_messages(ticket_id,author_id,internal,body)VALUES(?,?,?,?)')->execute([$id,$contact?null:$uid,(int)$internal,$body]);$mid=(int)$db->lastInsertId();
            $next=$internal?$t['status']:($admin?'awaiting':'open');$db->prepare("UPDATE support_tickets SET status=?,version=version+1,updated_at=datetime('now') WHERE id=?")->execute([$next,$id]);
            if(!$internal)support_notify($t,!$admin,$mid);
        }elseif($action==='partner_state' && !$admin && !$contact){
            $status=$input['status'] ?? null;
            if(!is_string($status) || !in_array($status,['open','closed'],true))throw new InvalidArgumentException('Nur öffnen oder schließen erlaubt.');
            $db->prepare("UPDATE support_tickets SET status=?,version=version+1,updated_at=datetime('now') WHERE id=?")->execute([$status,$id]);
        }elseif($action==='status' && $admin){
            $status=(string)($input['status'] ?? '');$priority=(string)($input['priority'] ?? '');$assignee=(int)($input['assignee'] ?? 0);
            if(!isset(SUPPORT_STATUS[$status],SUPPORT_PRIORITY[$priority]) || ($assignee && !is_platform_admin(support_user($assignee))))throw new InvalidArgumentException('Status, Priorität oder Admin-Zuweisung ungültig.');
            $db->prepare("UPDATE support_tickets SET status=?,priority=?,assignee=?,version=version+1,updated_at=datetime('now') WHERE id=?")->execute([$status,$priority,$assignee?:null,$id]);
        }elseif($action==='delete' && $admin){
            if(($input['confirm_reference'] ?? '')!==$ref)throw new InvalidArgumentException('Zum Ausblenden die Ticketreferenz exakt bestätigen.');
            $db->prepare("UPDATE support_tickets SET deleted_at=datetime('now'),version=version+1 WHERE id=?")->execute([$id]);
        }else throw new InvalidArgumentException('Aktion nicht erlaubt.');
        if($uid)audit_log($uid,'support.'.$action.($internal?'.internal':''),['reference'=>$ref]);
        $db->prepare('INSERT INTO support_operations(operation,actor,request_hash,ticket_id)VALUES(?,?,?,?)')->execute([$op,$actor,$hash,$id]);$db->exec('COMMIT');return $ref;
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}
function support_form(array $t,string $action,string $fields,string $label): string
{
    return '<form method="post" class="form">'.csrf_field().'<input type="hidden" name="operation" value="'.bin2hex(random_bytes(16)).'"><input type="hidden" name="action" value="'.h($action).'"><input type="hidden" name="reference" value="'.h($t['reference'] ?? '').'"><input type="hidden" name="version" value="'.(int)($t['version'] ?? 0).'">'.$fields.'<button class="btn">'.h(t($label)).'</button></form>';
}
function route_support(string $method,bool $admin,bool $contact=false): never
{
    $u=$contact?null:($admin?require_platform_admin():require_login());$base=$admin?'/admin/support':'/support';$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$ref=basename($path);if(!preg_match('/^[A-F0-9]{16}$/D',$ref))$ref='';
    if($method==='POST'){
        if(!rate_limit_take('support-post',($u?'user:'.$u['id']:'ip:'.request_ip()),$contact?5:30,3600))rate_limit_stop();
        if($contact && trim((string)($_POST['website'] ?? ''))!==''){flash('Nachricht entgegengenommen.');redirect('/contact');}
        $post=$_POST;$act=(string)($post['action'] ?? 'create');
        // The target is bound to the URL: list/contact routes only create, ticket routes only act on their own reference.
        $bad=$contact?($act!=='create' || !empty($post['internal'])):($ref===''?$act!=='create':($act==='create' || (string)($post['reference'] ?? '')!==$ref));
        if($bad){http_response_code(422);page('Supporteingabe prüfen','<h1>Supporteingabe prüfen</h1><p>Aktion passt nicht zu dieser Seite. Ticket neu öffnen und erneut versuchen.</p>',$u);exit;}
        try{$ref=support_write($u?(int)$u['id']:null,$admin,$_POST,$contact);flash($contact?'Nachricht gespeichert. Die Referenz dient nur der Zuordnung: '.$ref.'. Die Kontaktadresse ist nicht verifiziert.':'Supportvorgang gespeichert.');redirect($contact?'/contact':($base.(($_POST['action'] ?? '')==='delete'?'':'/'.$ref)));}catch(InvalidArgumentException $e){http_response_code(422);page('Supporteingabe prüfen','<h1>Supporteingabe prüfen</h1><p>'.h($e->getMessage()).'</p><p>Zurück im Browser: Eingaben prüfen. Bei unklarer Antwort dasselbe Formular erneut senden, nicht mit neuer Kennung neu anlegen.</p>',$u);exit;}
    }
    if($method!=='GET'){http_response_code(405);exit;}
    $body='<h1>'.h(t($admin?'Support-Inbox':($contact?'Kontakt':'Hilfe & Support'))).'</h1><p class="muted">'.h(t('Keine Passwörter, Zahlungsdaten oder API-Schlüssel eingeben. Antworten per E-Mail werden nicht in den Thread übernommen.')).'</p>';
    if($contact){$body.='<p>Die E-Mail-Adresse ist eine unbestätigte Kontaktangabe und gewährt keinen Kontozugriff. Diese Basisversion speichert die Anfrage für den Betreiber, ohne automatische Antwort an die eingegebene Adresse.</p>';}
    if($ref){
        try{$t=support_load($ref,$u,$admin);}catch(InvalidArgumentException){not_found();}
        $body.='<p><a href="'.$base.'">Zur Übersicht</a></p><section class="panel"><p class="eyebrow">'.h($t['reference']).' · '.h($t['channel']).'</p><h2>'.h($t['subject']).'</h2><p>'.h(support_label(SUPPORT_TOPICS,(string)$t['topic'])).' · '.h(support_label(SUPPORT_STATUS,(string)$t['status'])).' · '.h(support_label(SUPPORT_PRIORITY,(string)$t['priority'])).'</p>';
        if($admin){$body.='<p>Absenderangabe: '.h($t['requester_name']).' · '.h($t['requester_email']).($t['channel']==='contact'?' (nicht verifiziert)':' (angemeldetes Betreiberkonto)').'</p>';if($t['user_id']){$owner=support_user((int)$t['user_id']);$q=db()->prepare('SELECT name FROM businesses WHERE user_id=? ORDER BY id');$q->execute([$owner['id']]);$sq=db()->prepare('SELECT plan_key,status FROM subscriptions WHERE user_id=?');$sq->execute([$owner['id']]);$sub=$sq->fetch()?:[];$body.='<p>Unternehmen: '.h(implode(', ',$q->fetchAll(PDO::FETCH_COLUMN))).' · Plan: '.h($sub['plan_key'] ?? 'Keiner').' / '.h($sub['status'] ?? 'none').'</p>';}}
        $body.='</section>';if(!$admin)$body.='<p><a class="btn ghost" href="/support/'.h($t['reference']).'/export">Sichtbaren Thread als JSON laden</a></p><p class="muted">Enthält Betreff und sichtbare Nachrichten mit Autorennamen und UTC-Zeitpunkten, keine internen Notizen. Datei privat aufbewahren. Keine vollständige Änderungshistorie.</p>';$body.='<div class="support-thread">';$q=db()->prepare('SELECT m.*,u.name AS author FROM support_messages m LEFT JOIN users u ON u.id=m.author_id WHERE ticket_id=?'.($admin?'':' AND internal=0').' ORDER BY m.id');$q->execute([$t['id']]);foreach($q->fetchAll() as $msg)$body.='<article class="panel"><p class="muted">'.($msg['internal']?'INTERNE NOTIZ · ':'').h($msg['author'] ?? 'Kontaktformular').' · '.h($msg['created_at']).' UTC</p><p style="white-space:pre-wrap;overflow-wrap:anywhere">'.h($msg['body']).'</p></article>';$body.='</div>';
        $fields='<label>Nachricht<textarea name="message" rows="6" maxlength="10000" required></textarea></label>';if($admin)$fields.='<label class="check"><input type="checkbox" name="internal" value="1" '.($t['channel']==='contact'?'checked required':'').'> Interne Notiz, nur für Plattform-Admins</label>';
        $body.=support_form($t,'reply',$fields,$t['channel']==='contact'?'Interne Notiz speichern':'Antwort speichern');
        if(!$admin){$closed=$t['status']==='closed';$body.='<section class="panel support-partner-state"><h2>'.($closed?'Ticket wieder öffnen':'Ticket abschließen').'</h2><p>Ändert nur den Status dieses eigenen Tickets. Nachrichten bleiben erhalten. Kein Versand, keine Löschung. Eine spätere Antwort öffnet das Ticket ebenfalls wieder.</p>'.support_form($t,'partner_state','<input type="hidden" name="status" value="'.($closed?'open':'closed').'">',$closed?'Eigenes Ticket wieder öffnen':'Eigenes Ticket schließen').'</section>';}
        if($admin){$status='';foreach(SUPPORT_STATUS as $v=>$label)$status.='<option value="'.$v.'" '.($v===$t['status']?'selected':'').'>'.h(t($label)).'</option>';$pri='';foreach(SUPPORT_PRIORITY as $v=>$label)$pri.='<option value="'.$v.'" '.($v===$t['priority']?'selected':'').'>'.h(t($label)).'</option>';$ass='<option value="0">'.h(t('Nicht zugewiesen')).'</option>';foreach(db()->query('SELECT * FROM users ORDER BY name')->fetchAll() as $a)if(is_platform_admin($a))$ass.='<option value="'.(int)$a['id'].'" '.((int)$a['id']===(int)$t['assignee']?'selected':'').'>'.h($a['name'].' · '.$a['email']).'</option>';$body.='<section class="panel"><h2>Bearbeitung</h2>'.support_form($t,'status','<label>'.h(t('Status')).'<select name="status">'.$status.'</select></label><label>'.h(t('Priorität')).'<select name="priority">'.$pri.'</select></label><label>'.h(t('Zuständig')).'<select name="assignee">'.$ass.'</select></label>','Bearbeitungsstand speichern').'</section><details class="panel"><summary>Ticket ausblenden</summary><p>Kein dauerhaftes Löschen. Thread und Nachrichten bleiben gespeichert, sind danach nicht mehr erreichbar. Keine Benachrichtigung.</p>'.support_form($t,'delete','<label>Referenz zur Bestätigung<input name="confirm_reference" required autocomplete="off"></label>','Ticket ausblenden').'</details>';}
        page('Support '.$ref,$body,$u);exit;
    }
    if(!$contact){
        header('Cache-Control: private, no-store');
        try{$filters=support_list_filters($_GET,$admin);}catch(InvalidArgumentException $e){http_response_code(422);page('Supportfilter prüfen','<h1>Supportfilter prüfen</h1><p>'.h($e->getMessage()).'</p>',$u);exit;}
        $query=$filters['q'];$status=$filters['status'];$topic=$filters['topic'];$priority=$filters['priority'];$assignee=$filters['assignee'];$channel=$filters['channel'];$after=$filters['after'];
        $body.='<form class="form inline-form" method="get"><input type="hidden" name="lang" value="'.h(lang()).'"><label>'.h(t($admin?'Referenz, Betreff, Absender oder Zuständigkeit':'Referenz, Betreff')).'<input name="q" maxlength="100" value="'.h($query).'"></label><label>'.h(t('Status')).'<select name="status"><option value="">'.h(t('Alle')).'</option>';foreach(SUPPORT_STATUS as $v=>$label)$body.='<option value="'.$v.'" '.($status===$v?'selected':'').'>'.h(t($label)).'</option>';$body.='</select></label>';
        $body.='<label>'.h(t('Thema')).'<select name="topic"><option value="">'.h(t('Alle Themen')).'</option>';foreach(SUPPORT_TOPICS as $v=>$label)$body.='<option value="'.$v.'" '.($topic===$v?'selected':'').'>'.h(t($label)).'</option>';$body.='</select></label>';
        if($admin){$body.='<label>'.h(t('Priorität')).'<select name="priority"><option value="">'.h(t('Alle')).'</option>';foreach(SUPPORT_PRIORITY as $v=>$label)$body.='<option value="'.$v.'" '.($priority===$v?'selected':'').'>'.h(t($label)).'</option>';$body.='</select></label><label>'.h(t('Kanal')).'<select name="channel"><option value="">'.h(t('Alle Eingänge')).'</option>';foreach(SUPPORT_CHANNELS as $v=>$label)$body.='<option value="'.$v.'" '.($channel===$v?'selected':'').'>'.h(t($label)).'</option>';$body.='</select></label><label>'.h(t('Zuständig')).'<select name="assignee"><option value="">'.h(t('Alle')).'</option><option value="0" '.($assignee==='0'?'selected':'').'>'.h(t('Nicht zugewiesen')).'</option>';$current=false;foreach(db()->query('SELECT * FROM users ORDER BY name,id')->fetchAll() as $a)if(is_platform_admin($a)){$selected=(string)$a['id']===$assignee;$current=$current||$selected;$body.='<option value="'.(int)$a['id'].'" '.($selected?'selected':'').'>'.h($a['name'].' · '.$a['email']).'</option>';}if(!$current && ctype_digit($assignee) && (int)$assignee>0)$body.='<option value="'.(int)$assignee.'" selected>'.h(t('Ehemalige / unbekannte Admin-ID')).' '.(int)$assignee.'</option>';$body.='</select></label>';}$body.='<button class="btn">'.h(t('Suchen')).'</button></form>';
        if($admin)$body.='<p class="muted support-search-hint">'.h(t('Auch sichtbare Status-, Prioritäts- und Kanalbezeichnungen. Listen- und Exportlinks behalten die Suchsprache.')).'</p>';
        [$where,$params]=support_list_where($filters,$admin,(int)$u['id']);
        if($admin){
            $body.='<section class="panel support-list-export"><h2>'.h(t('Gefilterte Liste herunterladen')).'</h2><p>'.h(t('Alle Treffer der gewählten Filter, nicht nur diese 50er-Seite. Maximal 5000 Treffer und 4 MiB, sonst keine Teildatei. Ohne Nachrichten, interne Notizen oder Absender-E-Mail-Feld.')).'</p><div class="actions">';
            foreach(['csv'=>'CSV','tsv'=>'TSV','json'=>'JSON'] as $format=>$label)$body.='<a class="btn ghost" href="/admin/support/export?'.h(http_build_query(support_list_filter_query($filters)+['format'=>$format])).'">'.h(t('Liste als {format}',['format'=>$label])).'</a>';
            $body.='</div><p class="muted">'.h(t('Datei enthält private Namen und Betreffzeilen. Sicher aufbewahren. CSV/TSV markieren riskante Textanfänge mit einem Apostroph. Für unveränderte Textwerte JSON wählen.')).'</p></section>';
        }
        $q=db()->prepare('SELECT t.*,a.name AS assigned FROM support_tickets t LEFT JOIN users a ON a.id=t.assignee WHERE '.$where.' ORDER BY t.id LIMIT 51');$q->execute($params);$rows=$q->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);$body.='<div class="table-wrap support-inbox-table"><table><thead><tr><th>'.h(t('Vorgang')).'</th><th>'.h(t('Status / Priorität')).'</th><th>'.h(t('Zuletzt (UTC)')).'</th>'.($admin?'<th>'.h(t('Kanal / Absender / Zuständig')).'</th>':'').'</tr></thead><tbody>';foreach($rows as $t)$body.='<tr><td data-label="'.h(t('Vorgang')).'"><a href="'.$base.'/'.h($t['reference']).'">'.h($t['subject']).'</a><br><small>'.h($t['reference']).' · '.h(support_label(SUPPORT_TOPICS,(string)$t['topic'])).'</small></td><td data-label="'.h(t('Status / Priorität')).'">'.h(support_label(SUPPORT_STATUS,(string)$t['status'])).' / '.h(support_label(SUPPORT_PRIORITY,(string)$t['priority'])).'</td><td data-label="'.h(t('Zuletzt (UTC)')).'">'.h($t['updated_at']).'</td>'.($admin?'<td data-label="'.h(t('Kanal / Absender / Zuständig')).'">'.h(support_label(SUPPORT_CHANNELS,(string)$t['channel'])).'<br>'.h($t['requester_name']).' / '.h($t['assigned'] ?? t('Nicht zugewiesen')).'</td>':'').'</tr>';$body.='</tbody></table></div>';if($more)$body.='<p><a class="btn ghost" href="'.$base.'?'.h(http_build_query(support_list_filter_query($filters)+['after'=>(int)end($rows)['id']])).'">'.h(t('Nächste 50')).'</a></p>';
    }
    if(!$admin){$topics='';foreach(SUPPORT_TOPICS as $v=>$label)$topics.='<option value="'.$v.'">'.h(t($label)).'</option>';$fields=($contact?'<label>Name<input name="name" maxlength="120" required></label><label>E-Mail<input type="email" name="email" maxlength="254" required></label><div hidden aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>':'').'<label>'.h(t('Thema')).'<select name="topic">'.$topics.'</select></label><label>Betreff<input name="subject" maxlength="160" required></label><label>Nachricht<textarea name="message" rows="6" maxlength="10000" required></textarea></label>';$body.='<section class="panel"><h2>Neue Nachricht</h2>'.support_form([],'create',$fields,'Nachricht speichern').'</section>';}
    page(t($admin?'Support-Inbox':'Support'),$body,$u);exit;
}

/** Portabler Partner-Thread: ausschliesslich dieselben oeffentlichen Nachrichten wie in der App. */
function route_support_export(string $ref): never
{
    $u=require_login();header('Cache-Control: private, no-store');
    $db=db();$db->exec('BEGIN');
    try {
        $t=support_load($ref,$u,false);
        $q=$db->prepare('SELECT m.body,m.created_at,u.name AS author_name FROM support_messages m LEFT JOIN users u ON u.id=m.author_id WHERE m.ticket_id=? AND m.internal=0 ORDER BY m.id LIMIT 1001');$q->execute([$t['id']]);$messages=$q->fetchAll();
        if(count($messages)>1000)throw new LengthException('Mehr als 1000 sichtbare Nachrichten. Kein unvollständiger Export erzeugt.');
        $out=['format'=>'loyalty-support-thread','version'=>1,'exported_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'scope'=>'Eigener Betreiber-Thread, nur sichtbare Nachrichten. Keine internen Notizen oder vollständige Änderungshistorie.',
            'ticket'=>['reference'=>$t['reference'],'subject'=>$t['subject'],'topic'=>$t['topic'],'status'=>$t['status'],'created_at_utc'=>$t['created_at'],'updated_at_utc'=>$t['updated_at']],
            'messages'=>array_map(fn($m)=>['author'=>$m['author_name'] ?? 'Kontaktformular','created_at_utc'=>$m['created_at'],'body'=>$m['body']],$messages)];
        $json=json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(strlen($json)>2*1024*1024)throw new LengthException('Export größer als 2 MiB. Kein unvollständiger Export erzeugt.');
        $db->exec('COMMIT');
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}if($e instanceof InvalidArgumentException)not_found();if($e instanceof LengthException){http_response_code(422);page('Supportexport nicht erstellt','<h1>Supportexport nicht erstellt</h1><p>'.h($e->getMessage()).'</p>',$u);exit;}throw $e;}
    header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="support-'.$ref.'.json"');echo $json;exit;
}
