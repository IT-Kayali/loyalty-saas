<?php
declare(strict_types=1);

/** Technical choices, not a legal compliance certificate. Change core version if its meaning changes. */
const WALLET_NEARBY_CORE = 'wallet-nearby-v1';
function wallet_nearby_core_text(): string
{
    return 'Freiwillige Wallet-Nähe: Geprüfte Geschäftskoordinaten werden mit deinem Mitgliedspass an den gewählten Wallet-Anbieter (Apple oder Google) übergeben. Unsere App fragt keine Kundenkoordinaten ab und speichert keine Bewegungsprofile. Der Wallet-Anbieter verarbeitet Standortdaten nach seinen eigenen Bedingungen und Gerätefreigaben. Apple kann die Karte passiv empfehlen; Google kann eine Nähe-Benachrichtigung zeigen. Betriebssystem und Anbieter bestimmen Anzeige, Entfernung und Zeitpunkt. Kein garantierter Alarm. Diese Wahl ist unabhängig von Marketing-E-Mails; Sammeln und Einlösen bleiben ohne Zustimmung möglich. Du kannst hier jederzeit ausschalten. Danach enthalten neu erzeugte Passdaten keine Geschäftskoordinaten mehr. Bereits gespeicherte oder heruntergeladene Kopien können alte Daten behalten. Entferne solche Kopien selbst aus Wallet und von deinen Geräten. Hier wird kein Fernlöschen, sofortiges Geräte-Update oder Widerruf von Anbieter-Berechtigungen ausgelöst.';
}
function wallet_nearby_settings(array $b): array
{
    $raw=json_decode((string)($b['wallet_nearby_notice'] ?? '{}'),true);
    $out=[];foreach(['controller','contact','privacy_url','extra'] as $key)$out[$key]=is_array($raw)&&is_string($raw[$key] ?? null)?$raw[$key]:'';
    return $out;
}
function wallet_nearby_notice(int $bid): array
{
    $q=db()->prepare('SELECT * FROM businesses WHERE id=?');$q->execute([$bid]);$b=$q->fetch();
    if(!$b)return [];
    return ['version'=>WALLET_NEARBY_CORE,'business_id'=>$bid,'business_name'=>$b['name'],'operator'=>wallet_nearby_settings($b),'core'=>wallet_nearby_core_text(),'locations'=>wallet_merchant_locations($bid)];
}
function wallet_nearby_hash(array $notice): string
{
    return hash('sha256',json_encode($notice,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}
function wallet_nearby_notice_ready(array $notice): bool
{
    $s=$notice['operator'] ?? [];
    return !empty($s['controller']) && !empty($s['contact']) && wallet_nearby_url_ok($s['privacy_url'] ?? '');
}
function wallet_nearby_url_ok(string $url): bool
{
    if(!filter_var($url,FILTER_VALIDATE_URL))return false;
    $p=parse_url($url);
    return ($p['scheme'] ?? '')==='https' && !isset($p['user']) && !isset($p['pass']);
}
function wallet_nearby_consent_current(array $member,array $notice): bool
{
    return (int)($member['wallet_nearby_consent'] ?? 0)===1 && wallet_nearby_notice_ready($notice)
        && hash_equals(wallet_nearby_hash($notice),(string)($member['wallet_nearby_notice_hash'] ?? ''));
}
/** Always re-read member choice. Stale caller data must not revive revoked permission. */
function wallet_member_locations(array $member): array
{
    $q=db()->prepare('SELECT * FROM members WHERE id=? AND business_id=?');$q->execute([(int)$member['id'],(int)$member['business_id']]);$m=$q->fetch();
    if(!$m || (int)$m['wallet_nearby_consent']!==1)return [];
    $notice=wallet_nearby_notice((int)$m['business_id']);
    return wallet_nearby_consent_current($m,$notice) ? $notice['locations'] : [];
}
function wallet_nearby_notice_html(array $notice): string
{
    $s=$notice['operator'];$out='';foreach(preg_split('/(?=Der Wallet-Anbieter|Diese Wahl|Bereits gespeicherte)/',$notice['core']) as $part)$out.='<p>'.h(trim($part)).'</p>';
    $out.='<dl><dt>Verantwortlicher (Angabe des Betreibers)</dt><dd>'.h($s['controller']?:'Noch nicht eingetragen').'</dd><dt>Datenschutzkontakt</dt><dd>'.h($s['contact']?:'Noch nicht eingetragen').'</dd></dl>';
    if(wallet_nearby_url_ok($s['privacy_url']))$out.='<p><a rel="noreferrer noopener" href="'.h($s['privacy_url']).'">Datenschutzhinweise des Betreibers (externe Seite)</a></p>';
    if($s['extra']!=='')$out.='<h3>Zusatz des Betreibers</h3><p class="nearby-copy">'.nl2br(h($s['extra'])).'</p>';
    $out.='<p>Aktuell enthaltene Geschäftskoordinaten: '.count($notice['locations']).'.</p>';
    if($notice['locations']){$out.='<ul>';foreach($notice['locations'] as $l)$out.='<li>'.h((string)$l['latitude']).', '.h((string)$l['longitude']).'</li>';$out.='</ul>';}
    return '<div class="wallet-nearby-copy">'.$out.'</div>';
}
function wallet_nearby_settings_save(int $uid,int $bid,array $input): void
{
    $values=[];foreach(['controller'=>200,'contact'=>300,'privacy_url'=>500,'extra'=>3000] as $key=>$max){$v=$input[$key] ?? null;if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen(trim($v))>$max||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$v))throw new InvalidArgumentException('Hinweise als gültigen Text innerhalb der Feldlängen angeben.');$values[$key]=trim($v);}
    if($values['privacy_url']!==''&&!wallet_nearby_url_ok($values['privacy_url']))throw new InvalidArgumentException('Datenschutzlink muss eine vollständige HTTPS-Adresse ohne Zugangsdaten sein.');
    if(!is_string($input['revision'] ?? null))throw new InvalidArgumentException('Prüfstand fehlt.');
    $db=db();$db->exec('BEGIN IMMEDIATE');try{
        $b=own_business($uid,$bid);if(!$b)throw new InvalidArgumentException('Unternehmen nicht verfügbar.');
        if(($error=plan_policy($uid,'core'))!==null)throw new InvalidArgumentException($error);
        if(!hash_equals(wallet_nearby_hash(wallet_nearby_settings($b)),$input['revision']))throw new InvalidArgumentException('Hinweise inzwischen geändert. Neuen Stand prüfen.');
        $db->prepare('UPDATE businesses SET wallet_nearby_notice=? WHERE id=?')->execute([json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$bid]);
        audit_log($uid,'business.wallet_nearby_notice',['business_id'=>$bid]);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function route_wallet_nearby_notice(int $bid,string $method): never
{
    $u=require_login();$b=own_business((int)$u['id'],$bid);if(!$b)not_found();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
    if($method==='POST'){require_plan_write((int)$u['id'],'core');try{wallet_nearby_settings_save((int)$u['id'],$bid,$_POST);flash('Betreiberhinweise gespeichert. Ein abweichender Hinweisstand erfordert eine neue Kundenbestätigung für künftige Nähe-Passdaten. Keine Geräte aktualisiert.');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}redirect('/business/'.$bid.'/wallet-nearby-notice');}
    if($method!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
    $s=wallet_nearby_settings($b);$body='<p><a href="/organization">← Organisation</a></p><h1>Datenschutz für Wallet-Nähe</h1><section class="panel"><h2>'.h($b['name']).'</h2><p>Du pflegst hier deine Betreiberangaben, keine juristisch geprüfte Vorlage. Rechtsgrundlage, Datenschutzhinweise, Verträge und Anbietertransfers vor produktiver Nutzung fachlich prüfen lassen. Keine DSGVO-Garantie. Leere Pflichtangaben sperren neue Nähe-Zustimmungen.</p><p>Die festen technischen Hinweise unten bleiben sichtbar. Weichen diese Angaben, der Unternehmensname oder die ausgegebenen Koordinaten vom bestätigten Stand ab, werden keine Nähe-Koordinaten in neue Passdaten aufgenommen. Eine neue Bestätigung ist dann nötig. Bei Rückkehr zum exakt bestätigten Stand gilt eine nicht widerrufene Zustimmung wieder. Diese Prüfung löscht keine alten Passkopien. Keine Massenupdates oder Nachrichten beim Speichern.</p><form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.wallet_nearby_hash($s).'">';
    foreach(['controller'=>['Verantwortlicher (Name / Anschrift)',200],'contact'=>['Datenschutzkontakt',300],'privacy_url'=>['Link zu deinen Datenschutzhinweisen (HTTPS)',500]] as $k=>[$label,$max])$body.='<label>'.$label.'<input name="'.$k.'" maxlength="'.$max.'" value="'.h($s[$k]).'"></label>';
    $body.='<label>Zusätzlicher Hinweis (optional, reiner Text)<textarea name="extra" rows="5" maxlength="3000">'.h($s['extra']).'</textarea></label><button class="btn">Betreiberhinweise speichern</button></form></section><section class="panel"><h2>Kundensicht: feste Hinweise und deine Angaben</h2>'.wallet_nearby_notice_html(wallet_nearby_notice($bid)).'</section>';
    page('Datenschutz für Wallet-Nähe',$body,$u);exit;
}
function wallet_nearby_member_save(string $email,int $mid,array $input): void
{
    if(!is_string($input['enabled'] ?? null)||!in_array($input['enabled'],['0','1'],true))throw new InvalidArgumentException('Nähe-Auswahl fehlt.');
    $on=$input['enabled']==='1';$db=db();$db->exec('BEGIN IMMEDIATE');try{
        $q=$db->prepare('SELECT * FROM members WHERE id=? AND lower(email)=lower(?)');$q->execute([$mid,$email]);$m=$q->fetch();if(!$m)throw new InvalidArgumentException('Mitgliedskonto nicht verfügbar.');
        $notice=wallet_nearby_notice((int)$m['business_id']);$hash=wallet_nearby_hash($notice);
        if($on && (!wallet_nearby_notice_ready($notice)||!is_string($input['notice_hash'] ?? null)||!hash_equals($hash,$input['notice_hash'])||($input['confirm'] ?? null)!=='1'))throw new InvalidArgumentException('Aktuelle Hinweise prüfen und freiwillige Zustimmung ausdrücklich bestätigen.');
        $GLOBALS['consent_update_authorized']=true;
        if($on)$db->prepare("UPDATE members SET wallet_nearby_consent=1,wallet_nearby_granted_at=datetime('now'),wallet_nearby_withdrawn_at=NULL,wallet_nearby_notice_hash=?,wallet_nearby_snapshot=? WHERE id=?")->execute([$hash,json_encode($notice,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$mid]);
        else $db->prepare("UPDATE members SET wallet_nearby_consent=0,wallet_nearby_withdrawn_at=datetime('now') WHERE id=?")->execute([$mid]);
        $db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}finally{$GLOBALS['consent_update_authorized']=false;}
}
function route_member_wallet_nearby(string $method): never
{
    $email=require_member();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
    if($method==='POST'){
        $id=$_POST['member_id'] ?? null;if(!is_string($id)||!ctype_digit($id))not_found();
        $q=db()->prepare('SELECT id FROM members WHERE id=? AND lower(email)=lower(?)');$q->execute([(int)$id,$email]);if(!$q->fetch())not_found();
        try{wallet_nearby_member_save($email,(int)$id,$_POST);flash('Deine Nähe-Auswahl wurde gespeichert. Sie gilt für neu erzeugte Passdaten. Alte Passkopien bei Bedarf selbst entfernen; keine Fernlöschung ausgelöst.');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}redirect('/member/wallet-nearby');
    }
    if($method!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
    $body='<p><a href="/wallet">← Mein Wallet</a></p><h1>Meine Wallet-Nähe</h1><p>Freiwillig und je Unternehmen getrennt. Ohne Zustimmung kannst du weiter sammeln und einlösen. Hier werden weder Marketing-E-Mails noch Geräteberechtigungen geändert.</p>';
    foreach(member_rows_by_email($email) as $m){$notice=wallet_nearby_notice((int)$m['business_id']);$hash=wallet_nearby_hash($notice);$active=wallet_nearby_consent_current($m,$notice);
        $body.='<section class="panel member-nearby" data-member-id="'.(int)$m['id'].'"><h2>'.h($m['business_name']).'</h2><p><strong>Status: '.($active?'Zugestimmt für aktuellen Hinweisstand':((int)$m['wallet_nearby_consent']===1?'Neue Bestätigung nötig, Hinweise oder Standorte geändert':'Ausgeschaltet')).'</strong></p>'.wallet_nearby_notice_html($notice);
        if(!wallet_nearby_notice_ready($notice))$body.='<p class="notice">Betreiberangaben fehlen. Neue Zustimmung derzeit nicht möglich. Ausschalten bleibt möglich.</p>';
        $body.='<form method="post" class="form">'.csrf_field().'<input type="hidden" name="member_id" value="'.(int)$m['id'].'"><input type="hidden" name="notice_hash" value="'.$hash.'"><input type="hidden" name="enabled" value="1"><label class="check"><input name="confirm" value="1" type="checkbox"> Ich habe die Hinweise gelesen und stimme der freiwilligen Wallet-Nähe für dieses Unternehmen zu.</label><button class="btn"'.(!wallet_nearby_notice_ready($notice)?' disabled':'').'>Nähe freiwillig einschalten</button></form><form method="post">'.csrf_field().'<input type="hidden" name="member_id" value="'.(int)$m['id'].'"><input type="hidden" name="enabled" value="0"><button class="btn ghost">Nähe ausschalten / widerrufen</button></form><p class="muted">Zuletzt zugestimmt: '.h($m['wallet_nearby_granted_at']?:'nie').'. Zuletzt ausgeschaltet: '.h($m['wallet_nearby_withdrawn_at']?:'nie').'. Zeiten in UTC. Dein Export enthält den zuletzt bestätigten Hinweisstand. Kein vollständiges Zustimmungsarchiv.</p></section>';
    }
    page('Meine Wallet-Nähe',$body);exit;
}
