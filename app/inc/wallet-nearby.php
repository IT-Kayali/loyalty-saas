<?php
declare(strict_types=1);

/** Branch coordinates are merchant data, not a customer's live location. */
function wallet_location_revision(array $l): string
{
    return hash('sha256',json_encode([(int)$l['id'],(int)$l['business_id'],(int)$l['active'],$l['wallet_latitude'],$l['wallet_longitude'],(int)$l['wallet_nearby']],JSON_THROW_ON_ERROR));
}
function wallet_coordinate(mixed $raw,int $limit): ?float
{
    if(!is_string($raw))throw new InvalidArgumentException('Koordinaten als einzelne Dezimalwerte angeben.');$raw=trim($raw);if($raw==='')return null;
    if(!preg_match('/^-?(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,7})?$/D',$raw))throw new InvalidArgumentException('Koordinaten mit Dezimalpunkt und maximal sieben Nachkommastellen angeben.');
    $value=(float)$raw;if(!is_finite($value) || abs($value)>$limit)throw new InvalidArgumentException('Breitengrad von -90 bis 90, Längengrad von -180 bis 180 angeben.');return $value;
}
function wallet_location_save(int $uid,int $id,array $input): void
{
    $lat=wallet_coordinate($input['latitude'] ?? null,90);$lon=wallet_coordinate($input['longitude'] ?? null,180);
    if(($lat===null)!==($lon===null))throw new InvalidArgumentException('Beide Koordinaten angeben oder beide leer lassen.');
    if(!is_string($input['enabled'] ?? null) || !in_array($input['enabled'],['0','1'],true) || !is_string($input['revision'] ?? null))throw new InvalidArgumentException('Auswahl und Prüfstand fehlen.');
    $on=$input['enabled']==='1';if($on && ($lat===null || ($input['confirm_location'] ?? null)!=='1'))throw new InvalidArgumentException('Geprüfte Geschäftskoordinaten und Aufnahme in den Kundenpass ausdrücklich bestätigen.');
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare('SELECT l.* FROM locations l JOIN businesses b ON b.id=l.business_id WHERE l.id=? AND b.user_id=?');$q->execute([$id,$uid]);$l=$q->fetch();if(!$l)throw new InvalidArgumentException('Standort nicht verfügbar.');
        if(($error=plan_policy($uid,'locations'))!==null)throw new InvalidArgumentException($error);
        if(!hash_equals(wallet_location_revision($l),$input['revision']))throw new InvalidArgumentException('Nähe-Einstellung oder Standortstatus wurde inzwischen geändert. Neuen Stand prüfen.');
        if($on && !$l['active'])throw new InvalidArgumentException('Inaktiven Standort nicht für Wallet-Nähe aktivieren.');
        // Count all selected rows, including paused locations, so reactivation cannot exceed ten.
        $q=$db->prepare('SELECT COUNT(*) FROM locations WHERE business_id=? AND wallet_nearby=1 AND id<>?');$q->execute([$l['business_id'],$id]);if($on && (int)$q->fetchColumn()>=10)throw new InvalidArgumentException('Maximal zehn ausgewählte Standorte je Unternehmen. Zuerst einen anderen abwählen.');
        $db->prepare('UPDATE locations SET wallet_latitude=?,wallet_longitude=?,wallet_nearby=? WHERE id=?')->execute([$lat,$lon,$on?1:0,$id]);
        audit_log($uid,'location.wallet_nearby',['location_id'=>$id,'business_id'=>(int)$l['business_id'],'enabled'=>$on]);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Fail closed on historic/manual corruption or over-cap selection, never leak cross-business branches. */
function wallet_merchant_locations(int $bid): array
{
    $q=db()->prepare('SELECT wallet_latitude, wallet_longitude FROM locations WHERE business_id=? AND active=1 AND wallet_nearby=1 ORDER BY id LIMIT 11');$q->execute([$bid]);$rows=$q->fetchAll();if(count($rows)>10)return [];
    $out=[];foreach($rows as $r){if($r['wallet_latitude']===null || $r['wallet_longitude']===null || !is_numeric($r['wallet_latitude']) || !is_numeric($r['wallet_longitude']))continue;$lat=(float)$r['wallet_latitude'];$lon=(float)$r['wallet_longitude'];if(!is_finite($lat)||!is_finite($lon)||abs($lat)>90||abs($lon)>180)continue;$out[]=['latitude'=>$lat,'longitude'=>$lon];}return $out;
}
function route_wallet_location(int $id,string $method): never
{
    $u=require_login();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');$q=db()->prepare('SELECT l.*,b.name AS business_name FROM locations l JOIN businesses b ON b.id=l.business_id WHERE l.id=? AND b.user_id=?');$q->execute([$id,$u['id']]);$l=$q->fetch();if(!$l)not_found();
    if($method==='POST'){require_plan_write((int)$u['id'],'locations');try{wallet_location_save((int)$u['id'],$id,$_POST);flash('Wallet-Nähe gespeichert. Kein Pass versendet oder Gerät benachrichtigt.');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}redirect('/location/'.$id.'/wallet-nearby');}
    if($method!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
    $q=db()->prepare('SELECT COUNT(*) FROM locations WHERE business_id=? AND wallet_nearby=1');$q->execute([$l['business_id']]);$selected=(int)$q->fetchColumn();
    $body='<p><a href="/organization">← Organisation</a></p><h1>Wallet-Karte in der Nähe</h1><section class="panel wallet-nearby-editor"><h2>'.h($l['name']).'</h2><p>'.h($l['business_name']).' · '.($l['active']?'Standort aktiv':'Standort inaktiv, wird nicht in neue Passdaten aufgenommen').'</p><p>Die Geschäftskoordinaten können in Apple-/Google-Wallet-Pässe dieses Unternehmens aufgenommen werden. Keine Kundenortung durch unsere App. Keine automatische Adressprüfung. Nur geprüfte, zur Veröffentlichung geeignete Geschäftsstandorte eintragen, keine Privatadresse.</p><p>Ausgewählt: '.$selected.' von maximal 10, einschließlich pausierter Standorte. Inaktive Standorte werden nicht ausgegeben. Netzwerke teilen diese Auswahl nicht.</p><form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.wallet_location_revision($l).'"><label>Breitengrad (-90 bis 90)<input name="latitude" inputmode="decimal" value="'.h((string)($l['wallet_latitude'] ?? '')).'" placeholder="51.0000000"></label><label>Längengrad (-180 bis 180)<input name="longitude" inputmode="decimal" value="'.h((string)($l['wallet_longitude'] ?? '')).'" placeholder="7.0000000"></label><p class="muted">Dezimalpunkt, bis zu sieben Nachkommastellen. Die Beispielwerte sind keine ermittelte Adresse. Beide leer lassen zum Entfernen, nur bei ausgeschalteter Auswahl.</p><label>In neue Wallet-Passdaten aufnehmen<select name="enabled"><option value="0"'.(!$l['wallet_nearby']?' selected':'').'>Nein</option><option value="1"'.($l['wallet_nearby']?' selected':'').'>Ja, diesen Geschäftsstandort</option></select></label><label class="check"><input type="checkbox" name="confirm_location" value="1"> Ich habe die Koordinaten geprüft und will diesen Geschäftsstandort in den Kundenpässen teilen (bei Ja erforderlich).</label><button class="btn">Wallet-Nähe speichern</button></form></section><section class="panel"><h2>Was damit funktioniert und was noch fehlt</h2><p>Apple: passive Kartenempfehlung auf dem Sperrbildschirm, kein eigener Alarm. Google: mögliche Nähe-Benachrichtigung, wenn Benachrichtigungen und präziser dauerhafter Standortzugriff für Google Wallet erlaubt sind. Das Betriebssystem bestimmt Anzeige, Entfernung und Zeitpunkt. Keine Garantie und kein eigener Radius.</p><p>Benötigt installierten gültigen Pass: Apple-Signierzertifikate bzw. Google-Issuer mit Service-Account. Ohne diese sind nur lokale Passdaten prüfbar, kein echter Gerätetest. Aktueller Setupstatus: Apple '.(apple_wallet_ready()?'Signierdateien vorhanden (nicht gerätegeprüft)':'nicht eingerichtet').', Google '.(google_wallet_ready()?'Zugangsdaten vorhanden (nicht gerätegeprüft)':'nicht eingerichtet').'.</p><p>Speichern löst keinen Massen-Update, Versand oder Push aus. Änderungen gelten bei neuer Passerzeugung bzw. dem nächsten vorhandenen Pass-Updatepfad. Bereits installierte oder heruntergeladene Kopien können alte Standorte behalten, auch nach Abwahl oder Deaktivierung. Für Standort-Relevanz sind keine Kundenkoordinaten und kein künstliches Datum nötig.</p></section>';
    page('Wallet-Karte in der Nähe',$body,$u);exit;
}
