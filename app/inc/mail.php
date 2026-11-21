<?php
declare(strict_types=1);

function smtp_ready(): bool
{
    return (bool)config('smtp.enabled',false) && (string)config('smtp.host','')!==''
        && filter_var((string)config('smtp.from',''),FILTER_VALIDATE_EMAIL)!==false;
}
function mail_secret(): string
{
    $path=ROOT_DIR.'/data/mail-signing.key';
    $old=umask(0077);$f=fopen($path,'c+');umask($old);
    if(!$f || !flock($f,LOCK_EX))throw new RuntimeException('Mail signing key unavailable');
    $key=trim((string)stream_get_contents($f));
    if($key===''){$key=bin2hex(random_bytes(32));rewind($f);fwrite($f,$key);fflush($f);chmod($path,0600);}
    flock($f,LOCK_UN);fclose($f);
    if(strlen($key)!==64)throw new RuntimeException('Mail signing key unavailable');return $key;
}
function unsubscribe_token(int $memberId): string {return hash_hmac('sha256','unsubscribe:'.$memberId,mail_secret());}
function smtp_deliver(array $job): void
{
    if(!smtp_ready())throw new RuntimeException('SMTP not configured');
    $host=(string)config('smtp.host');$port=(int)config('smtp.port',587);$from=(string)config('smtp.from');
    if(!preg_match('/^[A-Za-z0-9.-]+$/D',$host)||!in_array($port,[465,587],true))throw new RuntimeException('SMTP host or port invalid');
    $ips=gethostbynamel($host) ?: [];
    $v6=@dns_get_record($host,DNS_AAAA) ?: [];foreach($v6 as $r)if(isset($r['ipv6']))$ips[]=$r['ipv6'];
    if(!$ips)throw new RuntimeException('SMTP host did not resolve');
    foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('SMTP requires a public mail server');
    if(!filter_var($job['recipient'],FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$job['recipient'].$from))throw new RuntimeException('Invalid email address');
    $encoded=static fn(string $s):string=>'=?UTF-8?B?'.base64_encode($s).'?=';
    $headers=['From: '.$encoded((string)config('smtp.from_name','Loyalty')).' <'.$from.'>',
        'To: <'.$job['recipient'].'>','Subject: '.$encoded($job['subject']),
        'Date: '.gmdate('D, d M Y H:i:s').' +0000',
        'Message-ID: <loyalty-'.$job['id'].'-'.substr(hash('sha256',base_url()),0,12).'@'.$host.'>',
        'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64'];
    $body=$job['body'];
    if($job['category']==='marketing' && $job['member_id']) {
        $url=base_url().'/unsubscribe/'.$job['member_id'].'/'.unsubscribe_token((int)$job['member_id']);
        $headers[]='List-Unsubscribe: <'.$url.'>';
        $body.="\n\nE-Mails abbestellen / Unsubscribe:\n".$url;
    }
    $data=implode("\r\n",$headers)."\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n");
    $stream=fopen('php://temp','w+');fwrite($stream,$data);rewind($stream);
    $ch=curl_init(($port===465?'smtps':'smtp').'://'.$host.':'.$port);
    curl_setopt_array($ch,[CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$stream,CURLOPT_INFILESIZE=>strlen($data),
        CURLOPT_MAIL_FROM=>$from,CURLOPT_MAIL_RCPT=>[$job['recipient']],CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_USE_SSL=>CURLUSESSL_ALL,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_RESOLVE=>[$host.':'.$port.':'.(str_contains($ips[0],':')?'['.$ips[0].']':$ips[0])]]);
    if((string)config('smtp.user','')!=='')curl_setopt_array($ch,[CURLOPT_USERNAME=>(string)config('smtp.user'),CURLOPT_PASSWORD=>(string)config('smtp.pass')]);
    $res=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_errno($ch);curl_close($ch);fclose($stream);
    if($res===false||$err||$code<200||$code>=300)throw new RuntimeException('SMTP outcome uncertain or rejected; inspect provider logs before retry');
}
function queue_mail(?int $bid,?int $mid,string $recipient,string $subject,string $body,string $category,string $dedup): bool
{
    if(!smtp_ready() || !filter_var($recipient,FILTER_VALIDATE_EMAIL))return false;
    if(!in_array($category,['marketing','transactional'],true))throw new InvalidArgumentException('Invalid category');
    if($category==='marketing') {
        $q=db()->prepare('SELECT marketing_consent,b.user_id FROM members m JOIN businesses b ON b.id=m.business_id WHERE m.id=? AND m.business_id=?');$q->execute([$mid,$bid]);$m=$q->fetch();
        if(!$m || !$m['marketing_consent'] || plan_policy((int)$m['user_id'],'campaigns')!==null)return false;
    }
    $q=db()->prepare('INSERT OR IGNORE INTO mail_outbox(business_id,member_id,recipient,subject,body,category,dedup_key)VALUES(?,?,?,?,?,?,?)');
    $q->execute([$bid,$mid,$recipient,mb_substr($subject,0,180),mb_substr($body,0,20000),$category,$dedup]);return $q->rowCount()===1;
}
function mail_worker(int $limit=25,?callable $transport=null): array
{
    $stats=['sent'=>0,'unknown'=>0,'skipped'=>0];if(!smtp_ready())return $stats;
    // A killed worker might already have handed a message to SMTP. Never resend blindly.
    db()->exec("UPDATE mail_outbox SET status='unknown',last_error='Worker interrupted; check SMTP provider before retry' WHERE status='sending' AND claimed_at<datetime('now','-10 minutes')");
    for($i=0;$i<max(1,min(100,$limit));$i++) {
        db()->exec('BEGIN IMMEDIATE');$job=db()->query("SELECT * FROM mail_outbox WHERE status='queued' ORDER BY id LIMIT 1")->fetch();
        if(!$job){db()->exec('COMMIT');break;}
        db()->prepare("UPDATE mail_outbox SET status='sending',claimed_at=datetime('now'),attempts=attempts+1 WHERE id=?")->execute([$job['id']]);db()->exec('COMMIT');
        if($job['category']==='marketing') {
            $q=db()->prepare('SELECT m.marketing_consent,b.user_id FROM members m JOIN businesses b ON b.id=m.business_id WHERE m.id=?');$q->execute([$job['member_id']]);$m=$q->fetch();
            if(!$m || !$m['marketing_consent'] || plan_policy((int)$m['user_id'],'campaigns')!==null) {
                db()->prepare("UPDATE mail_outbox SET status='skipped',body='',last_error='No consent or plan permission' WHERE id=?")->execute([$job['id']]);$stats['skipped']++;continue;
            }
        }
        try {
            ($transport ?? 'smtp_deliver')($job);
            db()->prepare("UPDATE mail_outbox SET status='sent',sent_at=datetime('now'),body='',last_error='' WHERE id=?")->execute([$job['id']]);$stats['sent']++;
        }catch(Throwable) {
            // SMTP cannot guarantee exactly-once. Unknown messages require operator review.
            db()->prepare("UPDATE mail_outbox SET status='unknown',last_error='SMTP rejected or uncertain; check provider logs' WHERE id=?")->execute([$job['id']]);$stats['unknown']++;
        }
    }
    return $stats;
}
function automation_enqueue(): int
{
    if(!smtp_ready())return 0;$queued=0;
    $rules=db()->query('SELECT a.*,b.user_id FROM mail_automations a JOIN businesses b ON b.id=a.business_id WHERE a.enabled=1')->fetchAll();
    foreach($rules as $rule) {
        if(plan_policy((int)$rule['user_id'],'campaigns')!==null)continue;
        $q=db()->prepare('SELECT m.*,COALESCE((SELECT MAX(t.created_at) FROM transactions t JOIN cards c ON c.id=t.card_id WHERE c.member_id=m.id),m.created_at) AS last_activity FROM members m WHERE m.business_id=? AND m.marketing_consent=1');$q->execute([$rule['business_id']]);
        foreach($q->fetchAll() as $m) {
            $cycle='once';$eligible=false;$days=max(1,(int)$rule['days']);
            if($rule['event']==='welcome')$eligible=strtotime($m['created_at'].' UTC')>=time()-7*86400;
            if($rule['event']==='winback'){$eligible=strtotime($m['last_activity'].' UTC')<=time()-$days*86400;$cycle=substr($m['last_activity'],0,10);}
            if($rule['event']==='birthday'){$eligible=$m['birthday']!==null && substr($m['birthday'],5)===gmdate('m-d');$cycle=gmdate('Y');}
            if(!$eligible)continue;
            $subject=str_replace('{name}',$m['name'],$rule['subject']);$body=str_replace('{name}',$m['name'],$rule['body']);
            $dedup='auto:'.$rule['id'].':'.$m['id'].':'.$cycle;
            if(!empty($rule['voucher_program_id'])) {
                $queued+=automation_voucher_mail($rule,$m,$subject,$body,$dedup);continue;
            }
            $queued+=(int)queue_mail((int)$rule['business_id'],(int)$m['id'],$m['email'],$subject,$body,'marketing',$dedup);
        }
    }
    return $queued;
}
/** Persoenlichen Gutschein erzeugen und E-Mail mit Code einreihen, beides atomar und je Zyklus nur einmal. */
function automation_voucher_mail(array $rule,array $m,string $subject,string $body,string $dedup): int
{
    $db=db();
    $x=$db->prepare('SELECT 1 FROM mail_outbox WHERE dedup_key=?');$x->execute([$dedup]);if($x->fetch())return 0;
    $q=$db->prepare("SELECT * FROM programs WHERE id=? AND business_id=? AND type='voucher' AND active=1");$q->execute([$rule['voucher_program_id'],$rule['business_id']]);
    $p=$q->fetch();if(!$p)return 0;
    $r=voucher_rules($p);$until=gmdate('Y-m-d',time()+max(1,(int)$rule['voucher_days'])*86400);
    if($r['ends'] && $r['ends']<$until)$until=$r['ends'];
    if($r['ends'] && $r['ends']<gmdate('Y-m-d'))return 0;
    $r['ends']=$until;$code=strtoupper(bin2hex(random_bytes(8)));
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare('INSERT INTO voucher_codes(program_id,member_id,batch,code,rules_snapshot)VALUES(?,?,?,?,?)')
            ->execute([$p['id'],$m['id'],'auto:'.$rule['event'].':'.$rule['id'],$code,json_encode($r,JSON_UNESCAPED_UNICODE)]);
        $date=date('d.m.Y',strtotime($until));
        $body=str_contains($body,'{code}')?str_replace(['{code}','{valid_until}'],[$code,$date],$body):$body."\n\nDein Gutscheincode: ".$code." (gültig bis ".$date.")";
        $body=str_replace('{valid_until}',$date,$body);
        if(!queue_mail((int)$rule['business_id'],(int)$m['id'],$m['email'],str_replace(['{code}','{valid_until}'],[$code,$date],$subject),$body,'marketing',$dedup)){$db->exec('ROLLBACK');return 0;}
        $db->exec('COMMIT');return 1;
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}
function automation_voucher_label(array $a): string
{
    if(empty($a['voucher_program_id']))return '';
    $q=db()->prepare('SELECT name FROM programs WHERE id=?');$q->execute([$a['voucher_program_id']]);
    return ' · Gutschein: '.h((string)($q->fetchColumn() ?: 'Programm gelöscht')).' ('.(int)$a['voucher_days'].' Tage)';
}
function voucher_select_html(int $bid): string
{
    $q=db()->prepare("SELECT id,name FROM programs WHERE business_id=? AND type='voucher' AND active=1 ORDER BY name");$q->execute([$bid]);
    $o='<option value="0">Kein Gutschein</option>';foreach($q->fetchAll() as $p)$o.='<option value="'.(int)$p['id'].'">'.h($p['name']).'</option>';
    return '<label>Persönlichen Gutschein beilegen<select name="voucher_program_id">'.$o.'</select></label><label>Gutschein gültig (Tage)<input type="number" name="voucher_days" value="30" min="1" max="365"></label><p class="muted">Mit Gutschein erzeugt jede Mail einen eigenen Einmal-Code für dieses Mitglied. Platzhalter: {name}, {code}, {valid_until}. Ohne {code} im Text wird der Code unten angehängt. Regeln und Limits kommen vom gewählten Gutschein-Programm.</p>';
}
function route_unsubscribe(int $id,string $token,string $method): never
{
    if(!hash_equals(unsubscribe_token($id),$token))not_found();
    if($method==='POST') {
        $GLOBALS['consent_update_authorized']=true;db()->prepare('UPDATE members SET marketing_consent=0 WHERE id=?')->execute([$id]);$GLOBALS['consent_update_authorized']=false;
        db()->prepare("UPDATE mail_outbox SET status='skipped',body='',last_error='Unsubscribed' WHERE member_id=? AND category='marketing' AND status='queued'")->execute([$id]);
        page('Abgemeldet','<div class="auth-box"><h1>E-Mails abbestellt</h1><p>Für dieses Unternehmen werden keine weiteren Marketing-E-Mails versendet. Konto- und Sicherheitsnachrichten sind davon getrennt.</p></div>');exit;
    }
    page('E-Mails abbestellen','<div class="auth-box"><h1>E-Mails abbestellen</h1><form method="post">'.csrf_field().'<button class="btn">Abmeldung bestätigen</button></form></div>');exit;
}
function route_mail_admin(int $bid,string $method): never
{
    $u=require_login();$b=own_business((int)$u['id'],$bid);if(!$b)not_found();
    if($method==='POST') {
        require_plan_write((int)$u['id'],'campaigns');$action=$_POST['action'] ?? '';
        if($action==='automation') {
            $event=(string)($_POST['event'] ?? '');$subject=trim((string)($_POST['subject'] ?? ''));$body=trim((string)($_POST['body'] ?? ''));
            $vp=(int)($_POST['voucher_program_id'] ?? 0);$vpid=null;
            if($vp>0) {
                $c=db()->prepare("SELECT id FROM programs WHERE id=? AND business_id=? AND type='voucher'");$c->execute([$vp,$bid]);
                if(!$c->fetch()){flash('Gutschein-Programm gehört nicht zu diesem Unternehmen.','err');redirect('/business/'.$bid.'/mail');}
                $vpid=$vp;
            }
            if(in_array($event,['welcome','winback','birthday'],true)&&$subject!==''&&$body!=='')db()->prepare('INSERT INTO mail_automations(business_id,event,days,subject,body,voucher_program_id,voucher_days)VALUES(?,?,?,?,?,?,?)')->execute([$bid,$event,max(1,min(3650,(int)($_POST['days'] ?? 30))),mb_substr($subject,0,180),mb_substr($body,0,20000),$vpid,max(1,min(365,(int)($_POST['voucher_days'] ?? 30)))]);
        }elseif($action==='toggle')db()->prepare('UPDATE mail_automations SET enabled=1-enabled WHERE id=? AND business_id=?')->execute([(int)($_POST['id'] ?? 0),$bid]);
        flash('E-Mail-Einstellungen gespeichert. Versand erfolgt nur durch den Worker.');redirect('/business/'.$bid.'/mail');
    }
    $html='<p><a href="/business/'.$bid.'">← '.h($b['name']).'</a></p><h1>E-Mail & Automationen</h1><div class="notice">'.(smtp_ready()?'SMTP-Konfiguration vorhanden. Providerverbindung und Zustellung separat prüfen. Verarbeitung erst durch CLI-Worker.':'SMTP deaktiviert oder nicht eingerichtet. Kein E-Mail-Versand.').'</div><p class="muted">Marketing nur mit ausdrücklicher Einwilligung. Vorhandene Mitglieder werden nicht automatisch angemeldet. {name} wird personalisiert. Versandbestätigung bedeutet Annahme durch den Mailserver, nicht gelesen oder zugestellt im Posteingang.</p><section class="panel"><h2>Automation anlegen</h2><form method="post" class="form">'.csrf_field().'<input type="hidden" name="action" value="automation"><label>Ereignis<select name="event"><option value="welcome">Willkommen (Beitritt in den letzten 7 Tagen, einmal)</option><option value="winback">Inaktiv seit X Tagen (einmal je letzter Aktivität)</option><option value="birthday">Geburtstag (jährlich)</option></select></label><label>Tage bei Inaktivität<input type="number" name="days" value="30" min="1" max="3650"></label>'.voucher_select_html($bid).'<label>Betreff<input name="subject" maxlength="180" required></label><label>Text<textarea name="body" rows="6" maxlength="20000" required></textarea></label><button class="btn">Automation speichern</button></form></section><h2>Automationen</h2>';
    $q=db()->prepare('SELECT * FROM mail_automations WHERE business_id=? ORDER BY id DESC');$q->execute([$bid]);foreach($q->fetchAll() as $a)$html.='<section class="panel"><strong>'.h($a['subject']).'</strong><p>'.h($a['event']).' · '.($a['enabled']?'aktiv':'pausiert').automation_voucher_label($a).'</p><form method="post">'.csrf_field().'<input type="hidden" name="action" value="toggle"><button class="btn ghost" name="id" value="'.(int)$a['id'].'">Umschalten</button></form></section>';
    $html.='<h2>Versandprotokoll</h2><div class="table-wrap"><table><tr><th>UTC</th><th>Betreff</th><th>Status</th><th>Hinweis</th></tr>';
    $q=db()->prepare('SELECT created_at,subject,status,last_error FROM mail_outbox WHERE business_id=? ORDER BY id DESC LIMIT 100');$q->execute([$bid]);foreach($q->fetchAll() as $j)$html.='<tr><td>'.h($j['created_at']).'</td><td>'.h($j['subject']).'</td><td>'.h($j['status']).'</td><td>'.h($j['last_error']).'</td></tr>';
    page('E-Mail & Automationen',$html.'</table></div>',$u);exit;
}

function route_member_preferences(string $method): never
{
    $email=require_member();$rows=member_rows_by_email($email);
    if($method==='POST') {
        $mid=(int)($_POST['member_id'] ?? 0);$member=null;foreach($rows as $m)if((int)$m['id']===$mid)$member=$m;if(!$member)not_found();
        try {
            $birthday=date_or_null((string)($_POST['birthday'] ?? ''));
            if($birthday && ($birthday>gmdate('Y-m-d') || $birthday<'1900-01-01'))throw new InvalidArgumentException('Geburtstag prüfen.');
            $GLOBALS['consent_update_authorized']=true;
            db()->prepare("UPDATE members SET marketing_consent=?,consent_source='preferences-v1',consent_at=datetime('now'),birthday=? WHERE id=?")->execute([isset($_POST['marketing_consent'])?1:0,$birthday,$mid]);
            $GLOBALS['consent_update_authorized']=false;flash('Einstellungen gespeichert.');
        }catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}
        redirect('/member/preferences');
    }
    $body='<p><a href="/wallet">← Mein Wallet</a></p><h1>E-Mail & Datenschutz</h1><p class="muted">Freiwillige Marketing-Einwilligung pro Unternehmen. Sicherheitsnachrichten sind davon getrennt. Geburtstag ist freiwillig.</p>';
    foreach($rows as $m) {
        $q=db()->prepare('SELECT marketing_consent,birthday FROM members WHERE id=?');$q->execute([$m['id']]);$p=$q->fetch();
        $body.='<section class="panel"><h2>'.h($m['business_name']).'</h2><form method="post" class="form">'.csrf_field().'<input type="hidden" name="member_id" value="'.(int)$m['id'].'"><label class="check"><input type="checkbox" name="marketing_consent" '.($p['marketing_consent']?'checked':'').'> Marketing-E-Mails dieses Unternehmens erlauben. Jederzeit abbestellbar.</label><label>Geburtstag (optional)<input type="date" name="birthday" value="'.h($p['birthday']).'"></label><button class="btn">Speichern</button></form></section>';
    }
    page('E-Mail & Datenschutz',$body);exit;
}
