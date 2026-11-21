<?php
declare(strict_types=1);

function money_cents(string $value): int
{
    $value=trim(str_replace(',','.',$value));
    if(!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$value))throw new InvalidArgumentException('Betrag mit maximal zwei Nachkommastellen angeben.');
    [$whole,$frac]=array_pad(explode('.',$value,2),2,'');
    return (int)$whole*100+(int)str_pad($frac,2,'0');
}
function money_label(int $cents,string $currency='EUR'): string {return number_format($cents/100,2,',','.').' '.$currency;}
function valid_currency(string $v): string {if(!in_array($v,['EUR','USD','GBP','CHF'],true))throw new InvalidArgumentException('Währung nicht unterstützt.');return $v;}
function date_or_null(string $value): ?string {
    if($value==='')return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$d || $d->format('Y-m-d')!==$value)throw new InvalidArgumentException('Ungültiges Datum.');return $value;
}
function business_actor(int $bid): array
{
    $u=str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH) ?: '', '/staff/')?null:current_user();
    if($u && own_business((int)$u['id'],$bid))return ['kind'=>'owner','id'=>(int)$u['id'],'user'=>$u];
    $staff=current_staff();if($staff && (int)$staff['business_id']===$bid)return ['kind'=>'staff','id'=>(int)$staff['id'],'user'=>null,'location_id'=>$staff['location_id']];
    not_found();
}
function voucher_rules(array $p): array
{
    $defaults=['type'=>'fixed','value'=>500,'currency'=>'EUR','min_spend'=>0,'max_discount'=>0,'max_total'=>0,'per_member'=>1,'starts'=>null,'ends'=>null,'min_lifetime'=>0,'item'=>''];
    $r=json_decode((string)($p['voucher_rules'] ?? ''),true);return array_merge($defaults,is_array($r)?array_intersect_key($r,$defaults):[]);
}
function voucher_quote(array $r,int $spend): int
{
    if($spend<(int)$r['min_spend'])throw new InvalidArgumentException('Mindestkaufbetrag nicht erreicht.');
    $discount=match($r['type']) {
        'percent'=>intdiv($spend*(int)$r['value'],10000),
        'fixed'=>min($spend,(int)$r['value']),
        'product'=>0,
        'points'=>0,
        default=>throw new InvalidArgumentException('Ungültiger Gutscheintyp'),
    };
    if((int)$r['max_discount']>0)$discount=min($discount,(int)$r['max_discount']);
    return min($spend,$discount);
}
function voucher_redeem(string $code,int $memberId,int $spend,array $actor,string $operation): array
{
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare('SELECT v.*,p.business_id,p.voucher_rules,p.active FROM voucher_codes v JOIN programs p ON p.id=v.program_id WHERE v.code=?');$q->execute([$code]);$v=$q->fetch();
        if($v && $v['status']==='cancelled')throw new InvalidArgumentException('Gutschein wurde storniert.');
        if(!$v || !$v['active'] || $v['status']!=='active')throw new InvalidArgumentException('Gutschein nicht aktiv.');
        $actor=assert_business_actor((int)$v['business_id'],$actor,'redeem');
        $r=json_decode($v['rules_snapshot'],true);$today=gmdate('Y-m-d');
        if(($r['starts'] && $today<$r['starts'])||($r['ends'] && $today>$r['ends']))throw new InvalidArgumentException('Gutschein außerhalb der Gültigkeit.');
        $q=$db->prepare('SELECT id FROM members WHERE id=? AND business_id=?');$q->execute([$memberId,$v['business_id']]);if(!$q->fetch())throw new InvalidArgumentException('Mitglied gehört nicht zu diesem Unternehmen.');
        $q=$db->prepare('SELECT * FROM voucher_redemptions WHERE operation=? AND business_id=?');$q->execute([$operation,$v['business_id']]);
        if($old=$q->fetch()){$db->exec('COMMIT');return $old;}
        if((int)$v['used']>=(int)$v['max_uses'])throw new InvalidArgumentException('Gutschein aufgebraucht.');
        if($v['member_id']!==null && (int)$v['member_id']!==$memberId)throw new InvalidArgumentException('Gutschein ist einem anderen Mitglied zugeordnet.');
        $q=$db->prepare('SELECT COUNT(*) FROM voucher_redemptions WHERE program_id=? AND member_id=? AND reversed_at IS NULL');$q->execute([$v['program_id'],$memberId]);if((int)$q->fetchColumn()>=(int)$r['per_member'])throw new InvalidArgumentException('Mitgliedslimit erreicht.');
        $q=$db->prepare('SELECT COUNT(*) FROM voucher_redemptions WHERE program_id=? AND reversed_at IS NULL');$q->execute([$v['program_id']]);if((int)$r['max_total']>0 && (int)$q->fetchColumn()>=(int)$r['max_total'])throw new InvalidArgumentException('Kampagnenlimit erreicht.');
        $q=$db->prepare('SELECT COALESCE(SUM(points_lifetime),0) FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=? AND p.business_id=?');$q->execute([$memberId,$v['business_id']]);if((int)$q->fetchColumn()<(int)$r['min_lifetime'])throw new InvalidArgumentException('Mitglied erfüllt die Punktebedingung nicht.');
        $discount=voucher_quote($r,$spend);
        $q=$db->prepare('SELECT id FROM cards WHERE member_id=? AND program_id=?');$q->execute([$memberId,$v['program_id']]);$cid=$q->fetchColumn();
        if(!$cid){$db->prepare('INSERT INTO cards(member_id,program_id,uses_left)VALUES(?,?,0)')->execute([$memberId,$v['program_id']]);$cid=$db->lastInsertId();}
        $pointsCard=null;
        if($r['type']==='points') {
            $q=$db->prepare("SELECT c.id FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=? AND p.business_id=? AND p.type='points' AND p.active=1 LIMIT 1");$q->execute([$memberId,$v['business_id']]);$pointsCard=$q->fetchColumn();
            if(!$pointsCard)throw new InvalidArgumentException('Keine aktive Punktekarte vorhanden.');
            $db->prepare('UPDATE cards SET points=points+?,points_lifetime=points_lifetime+? WHERE id=?')->execute([(int)$r['value'],(int)$r['value'],$pointsCard]);
            txn((int)$pointsCard,'points_add',(int)$r['value'],'Gutscheinbonus',$actor);
        }
        $db->prepare('UPDATE voucher_codes SET used=used+1 WHERE id=?')->execute([$v['id']]);
        $snapshot=json_encode(['rules'=>$r,'discount'=>$discount,'spend'=>$spend,'points_card'=>$pointsCard],JSON_UNESCAPED_UNICODE);
        $db->prepare('INSERT INTO voucher_redemptions(business_id,program_id,code_id,member_id,operation,spend_cents,discount_cents,actor_kind,actor_id,snapshot)VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$v['business_id'],$v['program_id'],$v['id'],$memberId,$operation,$spend,$discount,$actor['kind'],$actor['id'],$snapshot]);
        $id=(int)$db->lastInsertId();
        $db->prepare('UPDATE voucher_redemptions SET location_id=? WHERE id=?')->execute([$actor['location_id'] ?? null,$id]);
        txn((int)$cid,'voucher_code_use',1,'Code-Gutschein eingelöst: '.($r['type']==='product'?$r['item']:money_label($discount,$r['currency'])),$actor);
        $db->exec('COMMIT');return ['id'=>$id,'discount_cents'=>$discount,'spend_cents'=>$spend,'currency'=>$r['currency']];
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}
function voucher_form_html(array $p): string
{
    $r=voucher_rules($p);$id=(int)$p['id'];
    $html='<section class="panel"><h2>Gutschein-Regeln & Batch-Codes</h2><p class="muted">Code-Gutscheine sind zusätzlich zu bisherigen einfachen Gutscheinkarten. Rabatte werden dokumentiert, keine Zahlung ausgelöst.</p><form method="post" action="/program/'.$id.'/vouchers" class="form">'.csrf_field().'<input type="hidden" name="action" value="rules"><label>Rabattart<select name="type">';
    foreach(['percent'=>'Prozent','fixed'=>'Fester Betrag','product'=>'Gratisprodukt','points'=>'Bonuspunkte'] as $v=>$n)$html.='<option value="'.$v.'"'.($r['type']===$v?' selected':'').'>'.$n.'</option>';
    $html.='</select></label><label>Wert (bei Prozent z.B. 20, bei Bonuspunkten ganze Punkte)<input name="value" value="'.h(in_array($r['type'],['percent','fixed'],true)?number_format($r['value']/100,2,'.',''):(string)$r['value']).'" required></label><label>Währung<select name="currency">';foreach(['EUR','USD','GBP','CHF'] as $c)$html.='<option'.($r['currency']===$c?' selected':'').'>'.$c.'</option>';
    $html.='</select></label><label>Gratisprodukt<input name="item" value="'.h($r['item']).'" maxlength="100"></label>';
    foreach(['min_spend'=>'Mindestkaufbetrag','max_discount'=>'Maximalrabatt (0 = kein Cap)'] as $k=>$n)$html.='<label>'.$n.'<input name="'.$k.'" value="'.number_format($r[$k]/100,2,'.','').'"></label>';
    foreach(['max_total'=>'Gesamteinlösungen (0 = unbegrenzt)','per_member'=>'Einlösungen pro Mitglied','min_lifetime'=>'Mindestens Lifetime-Punkte'] as $k=>$n)$html.='<label>'.$n.'<input type="number" min="0" name="'.$k.'" value="'.(int)$r[$k].'"></label>';
    foreach(['starts'=>'Gültig ab (UTC)','ends'=>'Gültig bis (UTC)'] as $k=>$n)$html.='<label>'.$n.'<input type="date" name="'.$k.'" value="'.h($r[$k]).'"></label>';
    $html.='<button class="btn">Regeln speichern</button></form></section><section class="panel"><h2>Codes erzeugen</h2><form method="post" action="/program/'.$id.'/vouchers" class="form inline-form">'.csrf_field().'<input type="hidden" name="action" value="batch"><label>Batchname<input name="name" maxlength="80" required></label><label>Anzahl<input type="number" name="count" min="1" max="500" value="25"></label><button class="btn">Batch erzeugen</button></form><p><a href="/program/'.$id.'/voucher-export">Alle Codes als CSV exportieren</a> · <a href="/program/'.$id.'/voucher-report">Code-Gutschein-Auswertung</a> · <a href="/business/'.$p['business_id'].'/voucher-counter">Code an der Kasse einlösen</a></p><p class="muted">Offene Codes können auch abgelaufen sein. Storno sperrt weitere Einlösungen, erstattet aber keine früheren Nutzungen. Wiederherstellen ändert weder Laufzeit noch Zähler.</p><div class="table-wrap voucher-batches"><table><tr><th>Batch</th><th>Codes</th><th>Einlösungen</th><th>Offene Codes</th><th>Storniert</th><th></th></tr>';
    $q=db()->prepare("SELECT batch,COUNT(*) AS codes,SUM(used) AS used,SUM(CASE WHEN status='active' AND used<max_uses THEN 1 ELSE 0 END) AS open,SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS cancelled FROM voucher_codes WHERE program_id=? GROUP BY batch ORDER BY MAX(id) DESC LIMIT 20");$q->execute([$id]);
    foreach($q->fetchAll() as $row) {
        $act='';
        foreach(['batch_cancel'=>[(int)$row['open'],'Stornieren','Alle '.(int)$row['open'].' offenen Codes dieses Batches stornieren? Vollständig aufgebrauchte Codes bleiben unverändert; bei teilweise genutzten Codes werden weitere Nutzungen gesperrt.'],'batch_restore'=>[(int)$row['cancelled'],'Wiederherstellen','Stornierte Codes dieses Batches wieder aktivieren?']] as $a=>[$cnt,$label,$ask])
            if($cnt>0)$act.='<form method="post" action="/program/'.$id.'/vouchers" class="inline-action" onsubmit="return confirm(\''.h($ask).'\')">'.csrf_field().'<input type="hidden" name="action" value="'.$a.'"><input type="hidden" name="batch" value="'.h($row['batch']).'"><button class="btn ghost small">'.$label.'</button></form>';
        $html.='<tr><td data-label="Batch">'.h($row['batch']).'</td><td data-label="Codes">'.(int)$row['codes'].'</td><td data-label="Einlösungen">'.(int)$row['used'].'</td><td data-label="Offene Codes">'.(int)$row['open'].'</td><td data-label="Storniert">'.(int)$row['cancelled'].'</td><td data-label="Aktion">'.$act.'</td></tr>';
    }
    return $html.'</table></div></section>';
}
function route_voucher_program(int $id): never
{
    $u=require_login();$p=own_program((int)$u['id'],$id);if(!$p || $p['type']!=='voucher')not_found();require_plan_write((int)$u['id'],'vouchers');
    try {
        if(($_POST['action'] ?? '')==='rules') {
            $type=(string)($_POST['type'] ?? '');if(!in_array($type,['percent','fixed','product','points'],true))throw new InvalidArgumentException('Ungültiger Typ.');
            $value=in_array($type,['percent','fixed'],true)?money_cents((string)($_POST['value'] ?? '0')):(int)($_POST['value'] ?? 0);
            if($value<0 || ($type==='percent' && ($value<1 || $value>10000)) || ($type==='points' && ($value<1 || $value>1000000)))throw new InvalidArgumentException('Wert außerhalb des erlaubten Bereichs.');
            $r=['type'=>$type,'value'=>$value,'currency'=>valid_currency((string)($_POST['currency'] ?? 'EUR')),'min_spend'=>money_cents((string)($_POST['min_spend'] ?? '0')),'max_discount'=>money_cents((string)($_POST['max_discount'] ?? '0')),'max_total'=>max(0,min(1000000,(int)($_POST['max_total'] ?? 0))),'per_member'=>max(1,min(1000,(int)($_POST['per_member'] ?? 1))),'min_lifetime'=>max(0,(int)($_POST['min_lifetime'] ?? 0)),'item'=>mb_substr(trim((string)($_POST['item'] ?? '')),0,100),'starts'=>date_or_null((string)($_POST['starts'] ?? '')),'ends'=>date_or_null((string)($_POST['ends'] ?? ''))];
            if($r['starts'] && $r['ends'] && $r['starts']>$r['ends'])throw new InvalidArgumentException('Ende liegt vor Beginn.');
            if($type==='product' && $r['item']==='')throw new InvalidArgumentException('Produktname fehlt.');
            db()->prepare('UPDATE programs SET voucher_rules=? WHERE id=?')->execute([json_encode($r,JSON_UNESCAPED_UNICODE),$id]);
        } elseif(($_POST['action'] ?? '')==='batch') {
            $name=mb_substr(trim((string)($_POST['name'] ?? '')),0,80);$count=(int)($_POST['count'] ?? 0);
            if($name===''||$count<1||$count>500)throw new InvalidArgumentException('Batchname und 1 bis 500 Codes angeben.');
            db()->exec('BEGIN IMMEDIATE');
            $dup=db()->prepare('SELECT 1 FROM voucher_codes WHERE program_id=? AND batch=? LIMIT 1');$dup->execute([$id,$name]);
            if($dup->fetchColumn())throw new InvalidArgumentException('Ein Batch mit diesem Namen existiert schon. Bitte einen anderen Namen wählen.');
            $q=db()->prepare('INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot)VALUES(?,?,?,?)');
            for($i=0;$i<$count;$i++)$q->execute([$id,$name,strtoupper(bin2hex(random_bytes(12))),json_encode(voucher_rules($p),JSON_UNESCAPED_UNICODE)]);
            db()->exec('COMMIT');
        } elseif(in_array(($_POST['action'] ?? ''),['batch_cancel','batch_restore'],true)) {
            $batch=(string)($_POST['batch'] ?? '');$cancel=$_POST['action']==='batch_cancel';
            db()->exec('BEGIN IMMEDIATE');
            $q=db()->prepare($cancel?"UPDATE voucher_codes SET status='cancelled' WHERE program_id=? AND batch=? AND status='active' AND used<max_uses":"UPDATE voucher_codes SET status='active' WHERE program_id=? AND batch=? AND status='cancelled'");
            $q->execute([$id,$batch]);$n=$q->rowCount();
            if($n<1){db()->exec('ROLLBACK');throw new InvalidArgumentException($cancel?'Keine offenen Codes in diesem Batch.':'Keine stornierten Codes in diesem Batch.');}
            audit_log((int)$u['id'],$cancel?'voucher.batch_cancel':'voucher.batch_restore',['program_id'=>$id,'batch'=>mb_substr($batch,0,80),'codes'=>$n]);
            db()->exec('COMMIT');
            flash($n.($cancel?' Codes storniert. Sie lassen sich nicht mehr einlösen.':' Codes wieder aktiviert.'));redirect('/program/'.$id);
        } else throw new InvalidArgumentException('Ungültige Aktion.');
        flash('Gutschein-Einstellungen gespeichert.');
    }catch(InvalidArgumentException $e){try{db()->exec('ROLLBACK');}catch(Throwable){}flash($e->getMessage(),'err');}
    redirect('/program/'.$id);
}
function route_voucher_export(int $id): never
{
    $u=require_login();$p=own_program((int)$u['id'],$id);if(!$p)not_found();
    $q=db()->prepare('SELECT batch,code,used,max_uses,status FROM voucher_codes WHERE program_id=? ORDER BY id LIMIT 10001');$q->execute([$id]);$rows=$q->fetchAll();if(count($rows)>10000){http_response_code(422);echo 'Exportlimit erreicht';exit;}
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="voucher-codes.csv"');header('Cache-Control: private, no-store');$f=fopen('php://output','w');fputcsv($f,['Batch','Code','Used','Maximum','Status'],',','"','');
    foreach($rows as $r){foreach($r as &$v)if(is_string($v)&&preg_match('/^[\s]*[=+@-]/u',$v))$v="'".$v;unset($v);fputcsv($f,array_values($r),',','"','');}fclose($f);exit;
}
function route_voucher_counter(int $bid,string $method): never
{
    $a=business_actor($bid);$q=db()->prepare('SELECT * FROM businesses WHERE id=?');$q->execute([$bid]);$b=$q->fetch();if(!$b)not_found();
    if($method==='POST') {
        actor_permission($a,'redeem');
        require_plan_write((int)$b['user_id'],'vouchers');
        $code=strtoupper(trim((string)($_POST['code'] ?? '')));$q=db()->prepare('SELECT v.id FROM voucher_codes v JOIN programs p ON p.id=v.program_id WHERE v.code=? AND p.business_id=?');$q->execute([$code,$bid]);if(!$q->fetch())not_found();
        try {
            $op=(string)($_POST['operation'] ?? '');if(!preg_match('/^[a-f0-9]{32}$/D',$op))throw new InvalidArgumentException('Ungültige Vorgangskennung.');
            if(($_POST['action'] ?? '')==='preview') {
                $q=db()->prepare('SELECT v.rules_snapshot,v.status,v.used,v.max_uses,p.name FROM voucher_codes v JOIN programs p ON p.id=v.program_id WHERE v.code=? AND p.business_id=?');$q->execute([$code,$bid]);$v=$q->fetch();
                if($v && $v['status']==='cancelled')throw new InvalidArgumentException('Gutschein wurde storniert.');
                if(!$v || $v['status']!=='active' || $v['used']>=$v['max_uses'])throw new InvalidArgumentException('Gutschein nicht aktiv oder aufgebraucht.');
                $memberQuery=db()->prepare('SELECT name,code FROM members WHERE id=? AND business_id=?');$memberQuery->execute([(int)($_POST['member_id'] ?? 0),$bid]);$chosenMember=$memberQuery->fetch();if(!$chosenMember)throw new InvalidArgumentException('Mitglied passt nicht zum Unternehmen.');
                $rules=json_decode($v['rules_snapshot'],true);$spend=money_cents((string)($_POST['spend'] ?? '0'));$discount=voucher_quote($rules,$spend);
                $benefit=match($rules['type']){'product'=>'Gratisprodukt: '.$rules['item'],'points'=>(int)$rules['value'].' Bonuspunkte',default=>'Rabatt: '.money_label($discount,$rules['currency'])};
                page('Einlösung prüfen','<h1>Einlösung prüfen</h1><section class="panel"><h2>'.h($v['name']).'</h2><p>Unternehmen: '.h($b['name']).'</p><p>Mitglied: '.h($chosenMember['name']).' · '.h($chosenMember['code']).'</p><p>Kaufbetrag: '.h(money_label($spend,$rules['currency'])).'</p><p>'.h($benefit).'</p><p>Restbetrag extern zu bezahlen: '.h(money_label($spend-$discount,$rules['currency'])).'</p><p class="muted">Berechtigung, Gültigkeit und Restnutzungen werden beim Bestätigen erneut geprüft. Keine Zahlung wird ausgelöst.</p><form method="post">'.csrf_field().'<input type="hidden" name="code" value="'.h($code).'"><input type="hidden" name="member_id" value="'.(int)$_POST['member_id'].'"><input type="hidden" name="spend" value="'.h((string)$_POST['spend']).'"><input type="hidden" name="operation" value="'.h($op).'"><button class="btn" name="action" value="redeem">Verbindlich einlösen</button></form></section>',$a['user']);exit;
            }
            $r=voucher_redeem($code,(int)($_POST['member_id'] ?? 0),money_cents((string)($_POST['spend'] ?? '0')),$a,$op);
            flash('Gutschein eingelöst. Rabatt: '.money_label((int)$r['discount_cents'],$r['currency'] ?? json_decode($r['snapshot'] ?? '{}',true)['rules']['currency'] ?? 'EUR').'. Nur dokumentiert, Zahlung erfolgt extern.');
        }catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}
        redirect(($a['kind']==='staff'?'/staff':'').'/business/'.$bid.'/voucher-counter');
    }
    $prefill=strtoupper(trim((string)($_GET['code'] ?? '')));
    if($prefill!=='' && !preg_match('/^[A-F0-9]{24}$/D',$prefill))not_found();
    if($prefill!=='') {
        actor_permission($a,'redeem');
        $q=db()->prepare('SELECT v.id FROM voucher_codes v JOIN programs p ON p.id=v.program_id WHERE v.code=? AND p.business_id=?');$q->execute([$prefill,$bid]);if(!$q->fetch())not_found();
    }
    $q=db()->prepare('SELECT id,name,code FROM members WHERE business_id=? ORDER BY name LIMIT 500');$q->execute([$bid]);$opts='<option value="">Mitglied wählen</option>';foreach($q->fetchAll() as $m)$opts.='<option value="'.(int)$m['id'].'">'.h($m['name']).' · '.h($m['code']).'</option>';
    page('Gutschein-Kasse','<p><a href="'.($a['kind']==='owner'?'/business/'.$bid:'/staff').'">← Zurück</a></p><h1>Code-Gutschein einlösen</h1><p class="muted">Unternehmen: '.h($b['name']).'. Keine Zahlung wird ausgelöst. Ein Scan übernimmt nur den Code. Mitglied und Kaufbetrag prüfen, danach die Einlösung ausdrücklich bestätigen.</p><form method="post" class="form panel">'.csrf_field().'<input type="hidden" name="operation" value="'.bin2hex(random_bytes(16)).'"><label>Gutscheincode<input name="code" maxlength="24" value="'.h($prefill).'" required autocomplete="off"></label><label>Mitglied<select name="member_id" required>'.$opts.'</select></label><label>Kaufbetrag in Gutscheinwährung<input name="spend" inputmode="decimal" value="0.00" required></label><label class="check"><input type="checkbox" required> Richtigen Kunden und externen Kaufbetrag geprüft</label><button class="btn" name="action" value="preview">Einlösung prüfen</button></form>',$a['user']);exit;
}

/** Kurzbeschreibung des Werts aus einem Regel-Snapshot. */
function voucher_value_label(array $r): string
{
    return match($r['type'] ?? '') {
        'percent'=>rtrim(rtrim(number_format(((int)$r['value'])/100,2,',',''),'0'),',').' % Rabatt',
        'fixed'=>money_label((int)$r['value'],(string)($r['currency'] ?? 'EUR')).' Rabatt',
        'product'=>(string)($r['item'] ?? 'Produkt'),
        'points'=>(int)$r['value'].' Punkte',
        default=>'Gutschein',
    };
}
/** Persoenliche, noch nutzbare Gutschein-Codes eines Mitglieds (Wallet). */
function member_vouchers_html(array $memberIds): string
{
    if(!$memberIds)return '';
    $q=db()->query('SELECT v.code,v.rules_snapshot,p.name,b.name AS business FROM voucher_codes v JOIN programs p ON p.id=v.program_id JOIN businesses b ON b.id=p.business_id
        WHERE v.member_id IN ('.implode(',',$memberIds).") AND v.status='active' AND v.used<v.max_uses AND p.active=1 ORDER BY v.id DESC LIMIT 20");
    $today=gmdate('Y-m-d');$h='';
    foreach($q->fetchAll() as $v) {
        $r=json_decode((string)$v['rules_snapshot'],true) ?: [];
        if(!empty($r['ends']) && $r['ends']<$today)continue;
        if(!empty($r['starts']) && $r['starts']>$today)continue;
        $h.='<li class="voucher-wallet-item"><div class="qr-box" data-qr="V-'.h($v['code']).'" data-qr-size="160"></div><div><strong>'.h($v['name']).'</strong> · '.h($v['business']).'<br><code>'.h($v['code']).'</code> · '.h(voucher_value_label($r)).(!empty($r['ends'])?' · gültig bis '.h(date('d.m.Y',strtotime($r['ends']))):'').'</div></li>';
    }
    return $h===''?'':'<section class="panel member-vouchers" style="text-align:left;margin-bottom:16px"><h2>Deine Gutscheine</h2><ul>'.$h.'</ul><p class="muted">QR an der Kasse scannen lassen oder Code nennen. Erst das Team bestätigt die Einlösung. Nicht weitergeben: Der QR enthält deinen Gutscheincode.</p></section>';
}

/** Code-Gutschein-Auswertung, keine Umsatz- oder Zahlungsabrechnung. */
function voucher_report_data(int $pid,string $from,string $to): array
{
    if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$from) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$to) || date_or_null($from)===null || date_or_null($to)===null || $from>$to)throw new InvalidArgumentException('Gültigen Zeitraum angeben.');
    $start=new DateTimeImmutable($from,new DateTimeZone('UTC'));$end=new DateTimeImmutable($to,new DateTimeZone('UTC'));
    if((int)$start->diff($end)->days>365)throw new InvalidArgumentException('Höchstens 366 Kalendertage je Auswertung.');
    $params=[$pid,$from.' 00:00:00',$end->modify('+1 day')->format('Y-m-d').' 00:00:00'];
    $q=db()->prepare('SELECT member_id,created_at,spend_cents,discount_cents,snapshot,reversed_at FROM voucher_redemptions WHERE program_id=? AND created_at>=? AND created_at<? ORDER BY created_at,id LIMIT 10001');$q->execute($params);$rows=$q->fetchAll();
    if(count($rows)>10000)throw new InvalidArgumentException('Mehr als 10.000 Einlösungen im Zeitraum. Bitte einen kürzeren Zeitraum wählen.');
    $out=['count'=>0,'reversed'=>0,'members'=>0,'unknown'=>0,'groups'=>[],'days'=>[]];$members=[];
    foreach($rows as $row) {
        if($row['reversed_at']!==null){$out['reversed']++;continue;}
        $out['count']++;$members[(int)$row['member_id']]=true;$day=substr($row['created_at'],0,10);$out['days'][$day]=($out['days'][$day] ?? 0)+1;
        $snapshot=json_decode((string)$row['snapshot'],true);$r=is_array($snapshot) && is_array($snapshot['rules'] ?? null)?$snapshot['rules']:[]; $type=$r['type'] ?? ''; $currency=$r['currency'] ?? '';
        if(!in_array($type,['fixed','percent','product','points'],true) || !in_array($currency,['EUR','USD','GBP','CHF'],true)){ $out['unknown']++;continue; }
        $key=$currency.':'.$type;
        $out['groups'][$key] ??= ['currency'=>$currency,'type'=>$type,'count'=>0,'spend'=>0,'discount'=>0,'points'=>0];
        $g=&$out['groups'][$key];$g['count']++;$g['spend']+=(int)$row['spend_cents'];
        if(in_array($type,['fixed','percent'],true))$g['discount']+=(int)$row['discount_cents'];
        if($type==='points')$g['points']+=(int)($r['value'] ?? 0);unset($g);
    }
    $out['members']=count($members);ksort($out['groups']);ksort($out['days']);return $out;
}
/** Aggregierter Export: keine Codes, Namen, Adressen oder frei eingegebenen Texte. */
function voucher_report_csv(array $d,string $from,string $to): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="voucher-report-'.$from.'-'.$to.'.csv"');
    $f=fopen('php://output','w');
    fputcsv($f,['Zeilentyp','Von_UTC','Bis_UTC','Tag_UTC','Rabattart','Waehrung','Einloesungen','Mitglieder','Stornos_ausgeschlossen','Unbekannte_Snapshots','Kaufbetrag_Cent','Geldrabatt_Cent','Bonuspunkte','Hinweis'],',','"','');
    fputcsv($f,['gesamt',$from,$to,'','','',$d['count'],$d['members'],$d['reversed'],$d['unknown'],'','','','Kein Umsatz/Zahlungsnachweis. Kaufbetraege sind Kasseneingaben; keine Waehrungsumrechnung. Unbekannte Snapshots nur in Gesamt/Tag. Datenloeschungen koennen Zahlen aendern.'],',','"','');
    foreach($d['groups'] as $g)fputcsv($f,['wertgruppe',$from,$to,'',$g['type'],$g['currency'],$g['count'],'','','',$g['spend'],in_array($g['type'],['fixed','percent'],true)?$g['discount']:'',$g['type']==='points'?$g['points']:'','Geldwerte in Cent der genannten Waehrung. Produkte nicht bewertet.'],',','"','');
    foreach($d['days'] as $day=>$count)fputcsv($f,['tag',$from,$to,$day,'','',$count,'','','','','','','UTC; nur Tage mit Einloesungen.'],',','"','');
    fclose($f);exit;
}
function route_voucher_report(int $pid): never
{
    $u=require_login();$p=own_program((int)$u['id'],$pid);if(!$p || $p['type']!=='voucher')not_found();
    header('Cache-Control: private, no-store');
    $from=(string)($_GET['from'] ?? gmdate('Y-m-d',time()-29*86400));$to=(string)($_GET['to'] ?? gmdate('Y-m-d'));
    $body='<p><a href="/program/'.$pid.'">← '.h($p['name']).'</a></p><h1>Code-Gutschein-Auswertung</h1><p class="muted">Nur dokumentierte Code-Einlösungen dieses Programms, nicht einfache Gutscheinkarten. Alle Daten und Tagesgrenzen in UTC. Kein Zahlungsnachweis, Umsatz oder Gewinn. Wiederholte Käufe können mehrfach enthalten sein.</p><form method="get" class="form inline-form panel"><label>Von (UTC)<input type="date" name="from" value="'.h($from).'" required></label><label>Bis einschließlich (UTC)<input type="date" name="to" value="'.h($to).'" required></label><button class="btn">Auswerten</button></form>';
    try {
        $format=(string)($_GET['format'] ?? 'html');
        if(!in_array($format,['html','csv'],true))throw new InvalidArgumentException('Unbekanntes Berichtsformat.');
        $d=voucher_report_data($pid,$from,$to);
        if($format==='csv')voucher_report_csv($d,$from,$to);
        $body.='<p><a class="btn ghost" href="/program/'.$pid.'/voucher-report?'.h(http_build_query(['from'=>$from,'to'=>$to,'format'=>'csv'])).'">Auswertung als CSV</a></p><p class="muted">CSV enthält aggregierte Gesamt-, Wertgruppen- und Tageszeilen. Keine Namen, E-Mails oder Gutscheincodes. Geldwerte in Cent, kein Umsatznachweis.</p>';
        $body.='<section class="panel voucher-report-summary"><h2>Im gewählten Zeitraum</h2><p><strong>'.$d['count'].'</strong> Einlösungen · <strong>'.$d['members'].'</strong> unterschiedliche Mitglieder</p><p class="muted">'.$d['reversed'].' als rückgängig markierte Einlösungen ausgeschlossen. Bei späteren Mitglieder-/Datenlöschungen kann sich die Auswertung ändern.</p></section>';
        if($d['unknown'])$body.='<p class="alert">'.$d['unknown'].' Einlösungen ohne auswertbaren Regel-Snapshot sind in Anzahl und Tagesverlauf enthalten, nicht in Wertgruppen.</p>';
        $types=['fixed'=>'Festbetrag','percent'=>'Prozent','product'=>'Gratisprodukt','points'=>'Bonuspunkte'];
        $body.='<section class="panel"><h2>Wertgruppen</h2><p class="muted">Keine Währungsumrechnung. Erfasste Kaufbeträge sind Kasseneingaben, kein bestätigter Umsatz. Produkte werden nur gezählt; Punkte nicht in Geld umgerechnet.</p><div class="table-wrap voucher-report-table"><table><thead><tr><th>Art / Währung</th><th>Einlösungen</th><th>Erfasste Kaufbeträge</th><th>Geldrabatt</th><th>Bonuspunkte</th></tr></thead><tbody>';
        foreach($d['groups'] as $g)$body.='<tr><td data-label="Art / Währung">'.h($types[$g['type']].' / '.$g['currency']).'</td><td data-label="Einlösungen">'.$g['count'].'</td><td data-label="Erfasste Kaufbeträge">'.h(money_label($g['spend'],$g['currency'])).'</td><td data-label="Geldrabatt">'.(in_array($g['type'],['fixed','percent'],true)?h(money_label($g['discount'],$g['currency'])):'Nicht bewertet').'</td><td data-label="Bonuspunkte">'.($g['type']==='points'?$g['points']:'Nicht zutreffend').'</td></tr>';
        $body.='</tbody></table></div>'.(!$d['groups']?'<p>Keine auswertbaren Wertgruppen im Zeitraum.</p>':'').'</section><section class="panel"><h2>Einlösungen pro UTC-Tag</h2><ul>';
        foreach($d['days'] as $day=>$count)$body.='<li>'.h($day).': '.$count.'</li>';
        $body.='</ul>'.(!$d['days']?'<p>Noch keine Einlösungen im Zeitraum.</p>':'').'</section>';
    } catch(InvalidArgumentException $e) {http_response_code(422);$body.='<p class="alert">'.h($e->getMessage()).'</p>';}
    page('Code-Gutschein-Auswertung',$body,$u);exit;
}
