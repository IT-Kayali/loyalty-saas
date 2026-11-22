<?php
declare(strict_types=1);

/** Explicit choices for optional issuer messages, not for balance refresh or nearby suggestions. */
const WALLET_PUSH_CORE = 'wallet-push-v1';
function wallet_push_providers(): array { return ['apple'=>'Apple Wallet','google'=>'Google Wallet']; }
function wallet_push_fields(): array { return ['controller'=>200,'contact'=>300,'privacy_url'=>500,'extra'=>3000,'apple_text'=>240,'google_header'=>80,'google_body'=>500]; }
function wallet_push_settings(array $business): array
{
    $raw=json_decode((string)($business['wallet_push_settings'] ?? '{}'),true);
    $defaults=['controller'=>'','contact'=>'','privacy_url'=>'','extra'=>'','apple_text'=>'Wichtige Änderung an deiner Kundenkarte. Bitte prüfe die aktuellen Angaben.','google_header'=>'Wichtige Karteninformation','google_body'=>'Bitte prüfe die aktuelle Änderung an deiner Kundenkarte.'];
    foreach($defaults as $key=>$default)if(is_array($raw)&&is_string($raw[$key] ?? null))$defaults[$key]=$raw[$key];
    return $defaults;
}
function wallet_push_revision(array $business): string
{
    return hash('sha256',json_encode([wallet_push_settings($business),(int)($business['wallet_push_notice_revision'] ?? 0)],JSON_THROW_ON_ERROR));
}
function wallet_push_notice(int $bid,string $provider): array
{
    if(!isset(wallet_push_providers()[$provider]))throw new InvalidArgumentException('Wallet-Anbieter ungültig.');
    $q=db()->prepare('SELECT * FROM businesses WHERE id=?');$q->execute([$bid]);$b=$q->fetch();if(!$b)return [];
    $s=wallet_push_settings($b);$operator=array_intersect_key($s,array_flip(['controller','contact','privacy_url','extra']));
    return ['version'=>WALLET_PUSH_CORE,'business_id'=>$bid,'business_name'=>$b['name'],'revision'=>(int)$b['wallet_push_notice_revision'],'provider'=>$provider,'operator'=>$operator,'purpose'=>'Freiwillige, wichtige und zeitkritische Informationen zu deiner Kundenkarte. Keine Werbung oder allgemeinen Angebote. Die App übermittelt dem ausgewählten Wallet-Anbieter einen Kartenbezug und den vom Betreiber verfassten Text; beim Apple-Update werden registrierte Geräte über den Passabruf informiert. Dafür werden Kartenkennungen und bei Apple registrierte Geräte-/Pushkennungen verarbeitet. Texte können auf dem Sperrbildschirm und im Pass sichtbar sein. Keine vertraulichen Angaben verwenden. Diese Wahl ist unabhängig von Marketing-E-Mails, Wallet-Nähe und laufenden Kartenstand-Updates. Sammeln und Einlösen bleiben ohne Zustimmung möglich. Jeder Anbieter ist einzeln wählbar und jederzeit hier ausschaltbar. Apple-/Google-Konto, gültiger installierter Pass und Gerätefreigaben sind zusätzlich nötig.','limits'=>$provider==='apple'?'Apple zeigt unter Umständen eine Änderungsmitteilung, wenn sich ein Passfeld gegenüber der Gerätekopie geändert hat. Kein frei adressierbarer Alarm. Gleicher Text, gebündelte Updates oder Geräteeinstellungen können eine Anzeige verhindern.':'Google fügt eine Nachricht in den Pass ein und kann eine Benachrichtigung zeigen. Google bestimmt den Sperrbildschirmtext und begrenzt benachrichtigende Nachrichten auf höchstens drei pro Pass in 24 Stunden; weitere Drosselung ist möglich.','withdrawal'=>'Ausschalten sperrt künftige optionale Mitteilungen über diese App. Bereits übermittelte Texte, Passkopien und Geräteberechtigungen werden dadurch nicht gelöscht oder zurückgerufen. Kein garantierter, sofortiger oder nachgewiesener Empfang. Betreiberangaben müssen vollständig sein; nach Änderung ist eine neue Bestätigung nötig. Gespeichert wird hier nur die letzte Wahl mit Hinweisstand, kein vollständiges Einwilligungsarchiv.'];
}
function wallet_push_hash(array $value): string { return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); }
function wallet_push_notice_ready(array $notice): bool
{
    $s=$notice['operator'] ?? [];return !empty($s['controller'])&&!empty($s['contact'])&&wallet_nearby_url_ok($s['privacy_url'] ?? '');
}
function wallet_push_choice(int $mid,string $provider): array
{
    $q=db()->prepare('SELECT * FROM wallet_push_choices WHERE member_id=? AND provider=?');$q->execute([$mid,$provider]);
    return $q->fetch() ?: ['member_id'=>$mid,'provider'=>$provider,'enabled'=>0,'revision'=>0,'granted_at'=>null,'withdrawn_at'=>null,'notice_hash'=>'','snapshot'=>'{}'];
}
/** Fresh lookup is deliberate: callers cannot revive a withdrawal with cached member data. */
function wallet_push_allowed(int $mid,string $provider): bool
{
    if(!isset(wallet_push_providers()[$provider]))return false;
    $q=db()->prepare('SELECT business_id FROM members WHERE id=?');$q->execute([$mid]);$bid=$q->fetchColumn();if($bid===false)return false;
    $choice=wallet_push_choice($mid,$provider);$notice=wallet_push_notice((int)$bid,$provider);
    return (int)$choice['enabled']===1&&wallet_push_notice_ready($notice)&&hash_equals(wallet_push_hash($notice),(string)$choice['notice_hash']);
}
function wallet_push_settings_save(int $uid,int $bid,array $input): void
{
    $values=[];foreach(wallet_push_fields() as $key=>$max){$v=$input[$key] ?? null;if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen(trim($v))>$max||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$v))throw new InvalidArgumentException('Alle Felder als gültigen Text innerhalb der angezeigten Längen angeben.');$values[$key]=trim($v);}
    if($values['privacy_url']!==''&&!wallet_nearby_url_ok($values['privacy_url']))throw new InvalidArgumentException('Datenschutzlink muss eine vollständige HTTPS-Adresse ohne Zugangsdaten sein.');
    foreach(['apple_text','google_header','google_body'] as $key)if($values[$key]===''||strip_tags($values[$key])!==$values[$key])throw new InvalidArgumentException('Textvorlagen benötigen reinen Text ohne HTML.');
    if(!is_string($input['revision'] ?? null))throw new InvalidArgumentException('Prüfstand fehlt.');
    $db=db();$db->exec('BEGIN IMMEDIATE');try{
        $b=own_business($uid,$bid);if(!$b)throw new InvalidArgumentException('Unternehmen nicht verfügbar.');
        if(($error=plan_policy($uid,'core'))!==null)throw new InvalidArgumentException($error);
        if(!hash_equals(wallet_push_revision($b),$input['revision']))throw new InvalidArgumentException('Einstellungen inzwischen geändert. Neuen Stand prüfen.');
        $keys=array_flip(['controller','contact','privacy_url','extra']);$changed=array_intersect_key(wallet_push_settings($b),$keys)!==array_intersect_key($values,$keys);
        $db->prepare('UPDATE businesses SET wallet_push_settings=?,wallet_push_notice_revision=wallet_push_notice_revision+? WHERE id=?')->execute([json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),(int)$changed,$bid]);
        audit_log($uid,'business.wallet_push_settings',['business_id'=>$bid,'notice_changed'=>$changed]);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function wallet_push_member_save(string $email,int $mid,array $input): void
{
    $provider=$input['provider'] ?? null;$enabled=$input['enabled'] ?? null;
    if(!is_string($provider)||!isset(wallet_push_providers()[$provider])||!is_string($enabled)||!in_array($enabled,['0','1'],true))throw new InvalidArgumentException('Anbieter und eindeutige Auswahl fehlen.');
    $db=db();$db->exec('BEGIN IMMEDIATE');try{
        $q=$db->prepare('SELECT * FROM members WHERE id=? AND lower(email)=lower(?)');$q->execute([$mid,$email]);$m=$q->fetch();if(!$m)throw new InvalidArgumentException('Mitgliedskonto nicht verfügbar.');
        $notice=wallet_push_notice((int)$m['business_id'],$provider);$choice=wallet_push_choice($mid,$provider);$on=$enabled==='1';
        if($on&&(!wallet_push_notice_ready($notice)||!is_string($input['notice_hash'] ?? null)||!hash_equals(wallet_push_hash($notice),$input['notice_hash'])||!is_string($input['choice_revision'] ?? null)||$input['choice_revision']!==(string)$choice['revision']||($input['confirm'] ?? null)!=='1'))throw new InvalidArgumentException('Aktuelle Hinweise und Auswahl neu laden, dann freiwillig bestätigen.');
        $GLOBALS['wallet_push_choice_authorized']=true;
        $db->prepare('INSERT OR IGNORE INTO wallet_push_choices(member_id,provider) VALUES(?,?)')->execute([$mid,$provider]);
        if($on)$db->prepare("UPDATE wallet_push_choices SET enabled=1,revision=revision+1,granted_at=datetime('now'),withdrawn_at=NULL,notice_hash=?,snapshot=? WHERE member_id=? AND provider=?")->execute([wallet_push_hash($notice),json_encode($notice,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$mid,$provider]);
        else $db->prepare("UPDATE wallet_push_choices SET enabled=0,revision=revision+1,withdrawn_at=datetime('now') WHERE member_id=? AND provider=?")->execute([$mid,$provider]);
        $db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}finally{$GLOBALS['wallet_push_choice_authorized']=false;}
}
function wallet_push_notice_html(array $n): string
{
    $s=$n['operator'];$html='<p>'.h($n['purpose']).'</p><p>'.h($n['limits']).'</p><p>'.h($n['withdrawal']).'</p><dl><dt>Verantwortlicher (Angabe des Betreibers)</dt><dd>'.h($s['controller']?:'Noch nicht eingetragen').'</dd><dt>Datenschutzkontakt</dt><dd>'.h($s['contact']?:'Noch nicht eingetragen').'</dd></dl>';
    if(wallet_nearby_url_ok($s['privacy_url']))$html.='<p><a rel="noreferrer noopener" href="'.h($s['privacy_url']).'">Datenschutzhinweise des Betreibers</a></p>';
    if($s['extra']!=='')$html.='<h3>Zusatz des Betreibers</h3><p>'.nl2br(h($s['extra'])).'</p>';
    return '<div class="wallet-push-copy">'.$html.'</div>';
}
function wallet_push_export(int $mid): array
{
    $out=[];foreach(wallet_push_providers() as $provider=>$label){$c=wallet_push_choice($mid,$provider);$q=db()->prepare('SELECT business_id FROM members WHERE id=?');$q->execute([$mid]);$bid=(int)$q->fetchColumn();$out[$provider]=['zugestimmt'=>(bool)$c['enabled'],'bestaetigt_am'=>$c['granted_at'],'ausgeschaltet_am'=>$c['withdrawn_at'],'hinweis_hash'=>$c['notice_hash'],'bestaetigter_hinweis'=>json_decode($c['snapshot'],true),'aktueller_hinweis'=>wallet_push_notice($bid,$provider),'aktuell_zulaessig'=>wallet_push_allowed($mid,$provider)];}return $out;
}
function route_wallet_push_settings(int $bid,string $method): never
{
    $u=require_login();$b=own_business((int)$u['id'],$bid);if(!$b)not_found();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
    if($method==='POST'){require_plan_write((int)$u['id'],'core');try{wallet_push_settings_save((int)$u['id'],$bid,$_POST);flash('Hinweise und Vorlagen gespeichert. Keine Mitteilung versandt, kein Kunde eingeschaltet.');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}redirect('/business/'.$bid.'/wallet-push');}
    if($method!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
    $s=wallet_push_settings($b);$body='<p><a href="/organization">← Organisation</a></p><h1>Wallet-Mitteilungen</h1><section class="panel"><h2>'.h($b['name']).'</h2><p>Vorbereitung, noch kein Versandweg in dieser Etappe. Hier gespeicherte Texte werden nicht an Wallet-Anbieter übertragen. Die Wahl eines Kunden ersetzt weder einen installierten Pass noch Geräteberechtigungen. Keine Werbekampagnen und keine NFC-Funktion.</p><p>Betreiberhinweise sind keine juristisch geprüfte Vorlage. Rechtsgrundlage, Datenschutztexte, Minderjährige, Anbietertransfers und Löschfristen vor Produktivbetrieb prüfen lassen. Keine DSGVO-Garantie. Geänderte Betreiberangaben erfordern neue Bestätigung, auch wenn du später zu alten Angaben zurückkehrst. Textvorlagen ändern nicht den Zweck oder eine Kundenwahl.</p><form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.wallet_push_revision($b).'">';
    foreach(['controller'=>'Verantwortlicher (Name / Anschrift)','contact'=>'Datenschutzkontakt','privacy_url'=>'Datenschutzhinweise (HTTPS)'] as $key=>$label)$body.='<label>'.$label.'<input name="'.$key.'" maxlength="'.wallet_push_fields()[$key].'" value="'.h($s[$key]).'"></label>';
    $body.='<label>Zusatz des Betreibers (maximal 3000 Zeichen)<textarea name="extra" rows="4" maxlength="3000">'.h($s['extra']).'</textarea></label><h3>Apple: zeitkritische Passänderung</h3><p>Kein frei adressierbarer Alarm. Dieser Text ist eine Vorlage für ein geändertes Passfeld, keine Zusage für eine Sperrbildschirm-Anzeige.</p><label>Textvorlage (maximal 240 Zeichen)<textarea name="apple_text" rows="3" maxlength="240">'.h($s['apple_text']).'</textarea></label><h3>Google: passbezogene Nachricht</h3><p>Nachricht im Pass; mögliche Benachrichtigung mit von Google bestimmtem Sperrbildschirmtext. Maximal drei benachrichtigende Nachrichten pro Pass in 24 Stunden, weitere Drosselung möglich.</p><label>Titelvorlage (maximal 80 Zeichen)<input name="google_header" maxlength="80" value="'.h($s['google_header']).'"></label><label>Textvorlage (maximal 500 Zeichen)<textarea name="google_body" rows="4" maxlength="500">'.h($s['google_body']).'</textarea></label><p>Reiner Text, kein HTML, keine vertraulichen Angaben oder Werbung. Speichern sendet nichts und aktualisiert keine installierten Pässe.</p><button class="btn">Hinweise und Vorlagen speichern</button></form></section>';
    foreach(wallet_push_providers() as $p=>$label)$body.='<section class="panel"><h2>Kundenhinweise: '.$label.'</h2>'.wallet_push_notice_html(wallet_push_notice($bid,$p)).'</section>';
    page('Wallet-Mitteilungen',$body,$u);exit;
}
function route_member_wallet_push(string $method): never
{
    $email=require_member();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
    if($method==='POST'){$id=$_POST['member_id'] ?? null;if(!is_string($id)||!ctype_digit($id))not_found();$q=db()->prepare('SELECT id FROM members WHERE id=? AND lower(email)=lower(?)');$q->execute([(int)$id,$email]);if(!$q->fetch())not_found();try{wallet_push_member_save($email,(int)$id,$_POST);flash('Deine Wallet-Mitteilungswahl wurde gespeichert. Kein Versand und keine Änderung deiner Geräteberechtigungen.');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}redirect('/member/wallet-push');}
    if($method!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
    $body='<p><a href="/wallet">← Mein Wallet</a></p><h1>Meine Wallet-Mitteilungen</h1><p>Freiwillig, je Unternehmen und Anbieter getrennt. Zunächst ausgeschaltet. Kein Einfluss auf Sammeln, Einlösen, Marketing-E-Mails oder Wallet-Nähe. Diese Etappe speichert nur deine Wahl; noch kein Versandweg.</p>';
    foreach(member_rows_by_email($email) as $m)foreach(wallet_push_providers() as $p=>$label){$notice=wallet_push_notice((int)$m['business_id'],$p);$c=wallet_push_choice((int)$m['id'],$p);$active=wallet_push_allowed((int)$m['id'],$p);$body.='<section class="panel member-push" data-member-id="'.(int)$m['id'].'" data-provider="'.$p.'"><h2>'.h($m['business_name']).' · '.$label.'</h2><p><strong>Status: '.($active?'Zugestimmt für aktuellen Hinweisstand':((int)$c['enabled']===1?'Neue Bestätigung nötig':'Ausgeschaltet')).'</strong></p>'.wallet_push_notice_html($notice);
        if(!wallet_push_notice_ready($notice))$body.='<p class="notice">Betreiberangaben fehlen. Einschalten derzeit nicht möglich; Ausschalten bleibt möglich.</p>';
        $hidden='<input type="hidden" name="member_id" value="'.(int)$m['id'].'"><input type="hidden" name="provider" value="'.$p.'">';
        $body.='<form method="post" class="form">'.csrf_field().$hidden.'<input type="hidden" name="notice_hash" value="'.wallet_push_hash($notice).'"><input type="hidden" name="choice_revision" value="'.(int)$c['revision'].'"><input type="hidden" name="enabled" value="1"><label class="check"><input type="checkbox" name="confirm" value="1"> Ich habe die Hinweise gelesen und stimme freiwillig den beschriebenen Mitteilungen über '.$label.' zu.</label><button class="btn"'.(!wallet_push_notice_ready($notice)?' disabled':'').'>Mitteilungen freiwillig einschalten</button></form><form method="post">'.csrf_field().$hidden.'<input type="hidden" name="enabled" value="0"><button class="btn ghost">Ausschalten / widerrufen</button></form><p class="muted">Zuletzt bestätigt: '.h($c['granted_at']?:'nie').'. Zuletzt ausgeschaltet: '.h($c['withdrawn_at']?:'nie').'. Zeiten in UTC. Export enthält den zuletzt bestätigten Hinweisstand, kein vollständiges Archiv.</p></section>';
    }page('Meine Wallet-Mitteilungen',$body);exit;
}
