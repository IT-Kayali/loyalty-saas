<?php
declare(strict_types=1);

function pass_terms(array $p): array
{
    return ['kind'=>$p['pass_kind'] ?? 'visits','visits'=>(int)$p['pass_visits'],'days'=>(int)($p['pass_valid_days'] ?? 0),
        'price_cents'=>(int)($p['pass_price_cents'] ?? 0),'currency'=>$p['pass_currency'] ?? 'EUR','name'=>$p['name']];
}
function pass_sale(int $programId,int $memberId,array $actor,string $receipt,int $paid,string $operation): int
{
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare("SELECT * FROM programs WHERE id=? AND type='pass' AND active=1");$q->execute([$programId]);$p=$q->fetch();if(!$p)throw new InvalidArgumentException('Produkt nicht verfügbar.');
        $q=$db->prepare('SELECT id FROM members WHERE id=? AND business_id=?');$q->execute([$memberId,$p['business_id']]);if(!$q->fetch())throw new InvalidArgumentException('Mitglied passt nicht zum Unternehmen.');
        $actor=assert_business_actor((int)$p['business_id'],$actor,'sell_pass');
        $terms=pass_terms($p);if(!in_array($terms['kind'],['visits','unlimited'],true))throw new InvalidArgumentException('Passart nicht freigegeben.');
        if($terms['kind']==='unlimited' && $terms['days']<1)throw new InvalidArgumentException('Unbegrenzter Pass braucht ein Ablaufdatum.');
        if($paid<0 || $paid>100000000 || !preg_match('/^[\pL\pN ._\/-]{1,80}$/uD',$receipt))throw new InvalidArgumentException('Tatsächliche externe Belegreferenz und gültigen Betrag angeben.');
        $q=$db->prepare('SELECT id,member_id,program_id FROM prepaid_issues WHERE business_id=? AND (operation=? OR receipt=?)');$q->execute([$p['business_id'],$operation,$receipt]);if($existing=$q->fetch()){if((int)$existing['member_id']!==$memberId || (int)$existing['program_id']!==$programId)throw new InvalidArgumentException('Beleg wurde bereits für einen anderen Verkauf verwendet.');$db->exec('COMMIT');return (int)$existing['id'];}
        $expires=$terms['days']>0?gmdate('Y-m-d H:i:s',time()+$terms['days']*86400):null;
        $db->prepare('INSERT INTO prepaid_issues(business_id,program_id,member_id,code,operation,receipt,name,kind,remaining,original_visits,paid_cents,currency,expires_at,terms,actor_kind,actor_id)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$p['business_id'],$programId,$memberId,strtoupper(bin2hex(random_bytes(10))),$operation,$receipt,$terms['name'],$terms['kind'],$terms['kind']==='visits'?$terms['visits']:null,$terms['kind']==='visits'?$terms['visits']:null,$paid,$terms['currency'],$expires,json_encode($terms,JSON_UNESCAPED_UNICODE),$actor['kind'],$actor['id']]);
        $id=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO prepaid_ledger(issue_id,business_id,kind,quantity,amount_cents,operation,actor_kind,actor_id,location_id)VALUES(?,?,'sale',?,?,?, ?,?,?)")
            ->execute([$id,$p['business_id'],($terms['kind']==='visits'?$terms['visits']:0),$paid,$operation,$actor['kind'],$actor['id'],$actor['location_id'] ?? null]);
        $db->prepare('UPDATE prepaid_issues SET location_id=? WHERE id=?')->execute([$actor['location_id'] ?? null,$id]);
        $db->exec('COMMIT');return $id;
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}
function pass_use(int $id,int $quantity,int $expectedVersion,array $actor,string $operation): void
{
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare('SELECT * FROM prepaid_issues WHERE id=?');$q->execute([$id]);$p=$q->fetch();if(!$p)throw new InvalidArgumentException('Pass nicht gefunden.');
        $actor=assert_business_actor((int)$p['business_id'],$actor,'redeem');
        $q=$db->prepare('SELECT id FROM prepaid_ledger WHERE issue_id=? AND operation=?');$q->execute([$id,$operation]);if($q->fetch()){$db->exec('COMMIT');return;}
        if((int)$p['version']!==$expectedVersion)throw new InvalidArgumentException('Stand wurde inzwischen geändert. Bitte aktuellen Pass erneut prüfen.');
        if($p['expires_at'] && $p['expires_at']<=gmdate('Y-m-d H:i:s'))throw new InvalidArgumentException('Pass abgelaufen.');
        if($quantity<1 || $quantity>100 || ($p['kind']==='visits' && $quantity>(int)$p['remaining']))throw new InvalidArgumentException('Nicht genügend Besuche oder ungültige Anzahl.');
        if($p['kind']==='unlimited' && $quantity!==1)throw new InvalidArgumentException('Bei unbegrenzten Pässen jeweils einen Besuch dokumentieren.');
        $db->prepare('UPDATE prepaid_issues SET remaining=CASE WHEN remaining IS NULL THEN NULL ELSE remaining-? END,version=version+1 WHERE id=?')->execute([$quantity,$id]);
        $db->prepare("INSERT INTO prepaid_ledger(issue_id,business_id,kind,quantity,operation,actor_kind,actor_id,location_id)VALUES(?,?,'visit',?,?,?,?,?)")->execute([$id,$p['business_id'],$quantity,$operation,$actor['kind'],$actor['id'],$actor['location_id'] ?? null]);
        $db->exec('COMMIT');
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}
function pass_reverse(int $entryId,array $actor,string $operation): void
{
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare('SELECT l.*,i.kind AS pass_kind,i.expires_at FROM prepaid_ledger l JOIN prepaid_issues i ON i.id=l.issue_id WHERE l.id=?');$q->execute([$entryId]);$e=$q->fetch();
        if(!$e || $e['kind']!=='visit')throw new InvalidArgumentException('Nur ein Besuch kann storniert werden.');
        $actor=assert_business_actor((int)$e['business_id'],$actor,'reverse');
        if($actor['kind']==='staff' && ($e['actor_kind']!=='staff' || (int)$e['actor_id']!==$actor['id']))throw new InvalidArgumentException('Staff darf nur eigene Besuche stornieren.');
        $q=$db->prepare('SELECT id FROM prepaid_ledger WHERE reverses_id=?');$q->execute([$entryId]);if($q->fetch()){$db->exec('COMMIT');return;}
        $db->prepare("INSERT INTO prepaid_ledger(issue_id,business_id,kind,quantity,operation,actor_kind,actor_id,reverses_id,location_id)VALUES(?,?,'reversal',?,?,?,?,?,?)")->execute([$e['issue_id'],$e['business_id'],$e['quantity'],$operation,$actor['kind'],$actor['id'],$entryId,$actor['location_id'] ?? null]);
        $db->prepare('UPDATE prepaid_issues SET remaining=CASE WHEN remaining IS NULL THEN NULL ELSE remaining+? END,version=version+1 WHERE id=?')->execute([$e['quantity'],$e['issue_id']]);
        $db->exec('COMMIT');
    }catch(Throwable $ex){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $ex;}
}
function prepaid_program_html(array $p): string
{
    $t=pass_terms($p);$q=db()->prepare('SELECT currency,COUNT(*) AS sold,COALESCE(SUM(paid_cents),0) AS revenue FROM prepaid_issues WHERE program_id=? GROUP BY currency');$q->execute([$p['id']]);$stats=$q->fetchAll();$summary='';foreach($stats as $stat)$summary.='<p>'.(int)$stat['sold'].' Verkäufe · '.h(money_label((int)$stat['revenue'],$stat['currency'])).'</p>';
    return '<section class="panel"><h2>Verkaufte Pässe</h2>'.$summary.'<p class="muted">Keine Zahlung in dieser App. Jede Ausgabe speichert Preis, Besuchszahl und Laufzeit separat. Historische Beträge können verschiedene Währungen enthalten, daher nach einem Währungswechsel CSV getrennt auswerten.</p><form method="post" action="/program/'.(int)$p['id'].'/prepaid-settings" class="form">'.csrf_field().'<label>Passart<select name="kind"><option value="visits"'.($t['kind']==='visits'?' selected':'').'>Besuchspaket</option><option value="unlimited"'.($t['kind']==='unlimited'?' selected':'').'>Unbegrenzt mit Laufzeit</option></select></label><label>Besuche<input type="number" name="visits" min="1" max="10000" value="'.$t['visits'].'"></label><label>Gültigkeit ab Verkauf in Tagen (0 = kein Ablauf bei Besuchspaket)<input type="number" name="days" min="0" max="3650" value="'.$t['days'].'"></label><label>Standardpreis<input name="price" value="'.number_format($t['price_cents']/100,2,'.','').'"></label><label>Währung<select name="currency">'.implode('',array_map(fn($c)=>'<option'.($c===$t['currency']?' selected':'').'>'.$c.'</option>',['EUR','USD','GBP','CHF'])).'</select></label><button class="btn">Künftige Verkäufe konfigurieren</button></form><p><a href="/business/'.(int)$p['business_id'].'/prepaid-export">Verkaufs-/Besuchsledger als CSV</a></p></section>';
}
function route_prepaid_settings(int $id): never
{
    $u=require_login();$p=own_program((int)$u['id'],$id);if(!$p || $p['type']!=='pass')not_found();require_plan_write((int)$u['id'],'prepaid');
    try {
        $kind=(string)($_POST['kind'] ?? '');$visits=(int)($_POST['visits'] ?? 0);$days=(int)($_POST['days'] ?? 0);
        if(!in_array($kind,['visits','unlimited'],true)||$visits<1||$visits>10000||$days<0||$days>3650||($kind==='unlimited'&&$days===0))throw new InvalidArgumentException('Passart, Besuche oder Gültigkeit ungültig.');
        db()->prepare('UPDATE programs SET pass_kind=?,pass_visits=?,pass_valid_days=?,pass_price_cents=?,pass_currency=? WHERE id=?')->execute([$kind,$visits,$days,money_cents((string)($_POST['price'] ?? '0')),valid_currency((string)($_POST['currency'] ?? 'EUR')),$id]);flash('Produkt gespeichert. Bereits verkaufte Pässe bleiben unverändert.');
    }catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}
    redirect('/program/'.$id);
}
function prepaid_member_html(int $memberId,string $base,bool $readOnly=false): string
{
    $q=db()->prepare('SELECT * FROM members WHERE id=?');$q->execute([$memberId]);$m=$q->fetch();if(!$m)return '';
    $q=db()->prepare('SELECT * FROM prepaid_issues WHERE member_id=? ORDER BY COALESCE(expires_at,\'9999\'),id DESC LIMIT 100');$q->execute([$memberId]);$issues=$q->fetchAll();
    $html='<section class="panel"><h2>Gekaufte Prepaid-Pässe</h2><p class="muted">Jeder Verkauf ist ein eigener Pass. Bestehende einfache Mehrfachkarten werden separat geführt.</p>';
    foreach($issues as $p) {
        $expired=$p['expires_at'] && $p['expires_at']<=gmdate('Y-m-d H:i:s');$empty=$p['kind']==='visits' && (int)$p['remaining']===0;
        $html.='<article class="pass-item"><h3>'.h($p['name']).'</h3><p><code>'.h(substr($p['code'],-8)).'</code> · '.($p['kind']==='unlimited'?'Unbegrenzt':(int)$p['remaining'].' / '.(int)$p['original_visits'].' Besuche').'</p><p class="muted">'.($expired?'Abgelaufen':($empty?'Aufgebraucht':'Aktiv')).' · '.($p['expires_at']?'Bis '.h($p['expires_at']).' UTC':'Ohne Ablauf').'</p>';
        if(!$readOnly && !$expired && !$empty)$html.='<form method="post" action="'.$base.'/prepaid/'.(int)$p['id'].'/use" class="form inline-form">'.csrf_field().'<input type="hidden" name="version" value="'.(int)$p['version'].'"><input type="hidden" name="operation" value="'.bin2hex(random_bytes(16)).'"><label>Besuche<input type="number" name="quantity" value="1" min="1" max="'.($p['kind']==='unlimited'?1:min(100,(int)$p['remaining'])).'"></label><button class="btn">Besuch bestätigen</button></form>';
        $q=db()->prepare('SELECT l.*,r.id AS reversal FROM prepaid_ledger l LEFT JOIN prepaid_ledger r ON r.reverses_id=l.id WHERE l.issue_id=? ORDER BY l.id DESC LIMIT 15');$q->execute([$p['id']]);$html.='<details><summary>Verlauf</summary><ul>';
        foreach($q->fetchAll() as $e){$html.='<li>'.h($e['created_at']).' · '.h(['sale'=>'Verkauf','visit'=>'Besuch','reversal'=>'Storno'][$e['kind']] ?? $e['kind']).' · '.(int)$e['quantity'];if(!$readOnly && $e['kind']==='visit' && !$e['reversal'])$html.='<form method="post" class="undo-form" action="'.$base.'/prepaid/'.(int)$p['id'].'/reverse">'.csrf_field().'<input type="hidden" name="entry_id" value="'.(int)$e['id'].'"><input type="hidden" name="operation" value="'.bin2hex(random_bytes(16)).'"><button class="undo-btn">Stornieren</button></form>';$html.='</li>';}
        $html.='</ul></details></article>';
    }
    if(!$readOnly) {
        $q=db()->prepare("SELECT * FROM programs WHERE business_id=? AND type='pass' AND active=1 ORDER BY name");$q->execute([$m['business_id']]);$opts='';foreach($q->fetchAll() as $p)$opts.='<option value="'.(int)$p['id'].'">'.h($p['name']).' · '.h(money_label((int)$p['pass_price_cents'],$p['pass_currency'])).'</option>';
        if($opts)$html.='<h3>Neuen Pass verkaufen</h3><p class="muted">Zahlung zuerst extern entgegennehmen. Hier nur den tatsächlichen Verkauf dokumentieren, keine Karte wird belastet.</p><form method="post" action="'.$base.'/prepaid/sell" class="form">'.csrf_field().'<input type="hidden" name="member_id" value="'.$memberId.'"><input type="hidden" name="operation" value="'.bin2hex(random_bytes(16)).'"><label>Produkt<select name="program_id">'.$opts.'</select></label><label>Tatsächlich bezahlter Betrag in Produktwährung<input name="paid" inputmode="decimal" required></label><label>Externe Belegreferenz (eindeutig)<input name="receipt" maxlength="80" required></label><label class="check"><input type="checkbox" name="payment_confirmed" value="1" required> Zahlung extern erhalten und richtigen Kunden geprüft</label><button class="btn">Verkauf prüfen</button></form>';
    }
    return $html.'</section>';
}
function route_prepaid_action(string $action,int $id=0): never
{
    if($action==='sell') {
        $q=db()->prepare('SELECT * FROM members WHERE id=?');$q->execute([(int)($_POST['member_id'] ?? 0)]);$m=$q->fetch();if(!$m)not_found();$bid=(int)$m['business_id'];
    }else {
        $q=db()->prepare('SELECT * FROM prepaid_issues WHERE id=?');$q->execute([$id]);$issue=$q->fetch();if(!$issue)not_found();$bid=(int)$issue['business_id'];$m=['id'=>$issue['member_id']];
    }
    $actor=business_actor($bid);actor_permission($actor,match($action){'sell'=>'sell_pass','reverse'=>'reverse',default=>'redeem'});$q=db()->prepare('SELECT * FROM businesses WHERE id=?');$q->execute([$bid]);$b=$q->fetch();require_plan_write((int)$b['user_id'],'prepaid');
    $back=$actor['kind']==='owner'?'/member/':'/staff/member/';$base=$actor['kind']==='owner'?'':'/staff';
    try {
        $operation=(string)($_POST['operation'] ?? '');if(!preg_match('/^[a-f0-9]{32}$/D',$operation))throw new InvalidArgumentException('Vorgangskennung fehlt.');
        if($action==='sell') {
            if(empty($_POST['payment_confirmed']))throw new InvalidArgumentException('Externe Zahlung bestätigen.');
            $q=db()->prepare("SELECT * FROM programs WHERE id=? AND business_id=? AND type='pass'");$q->execute([(int)($_POST['program_id'] ?? 0),$bid]);$p=$q->fetch();if(!$p)not_found();
            $paid=money_cents((string)($_POST['paid'] ?? ''));$termsHash=hash('sha256',json_encode(pass_terms($p)));
            if(($_POST['confirm_sale'] ?? '')==='1' && !hash_equals($termsHash,(string)($_POST['terms_hash'] ?? '')))throw new InvalidArgumentException('Produkt wurde inzwischen geändert. Bitte Verkauf erneut prüfen.');
            $receipt=trim((string)($_POST['receipt'] ?? ''));
            if(($_POST['confirm_sale'] ?? '')!=='1') {
                $t=pass_terms($p);page('Verkauf prüfen','<h1>Verkauf prüfen</h1><section class="panel"><h2>'.h($p['name']).'</h2><p>Unternehmen: '.h($b['name']).'</p><p>Mitglied: '.h($m['name']).' (#'.(int)$m['id'].')</p><p>'.($t['kind']==='unlimited'?'Unbegrenzt':$t['visits'].' Besuche').' · '.($t['days']>0?$t['days'].' Tage':'Ohne Ablauf').'</p><p>Extern bezahlt: '.h(money_label($paid,$t['currency'])).'</p><p>Beleg: '.h($receipt).'</p><form method="post" action="'.$base.'/prepaid/sell">'.csrf_field().'<input type="hidden" name="member_id" value="'.(int)$m['id'].'"><input type="hidden" name="program_id" value="'.(int)$p['id'].'"><input type="hidden" name="operation" value="'.h($operation).'"><input type="hidden" name="paid" value="'.h((string)$_POST['paid']).'"><input type="hidden" name="receipt" value="'.h($receipt).'"><input type="hidden" name="payment_confirmed" value="1"><input type="hidden" name="terms_hash" value="'.h($termsHash).'"><button class="btn" name="confirm_sale" value="1">Verkauf verbindlich dokumentieren</button></form><p class="muted">Keine Zahlung durch die App. Nach Bestätigung ist dies ein eigener verkaufter Pass.</p></section>',$actor['user']);exit;
            }
            $id=pass_sale((int)$p['id'],(int)$m['id'],$actor,$receipt,$paid,$operation);flash('Verkauf dokumentiert. Pass #'.$id.' ist in der Kundenkarte sichtbar.');
        }elseif($action==='use') {pass_use($id,(int)($_POST['quantity'] ?? 1),(int)($_POST['version'] ?? -1),$actor,$operation);flash('Besuch dokumentiert.');}
        elseif($action==='reverse') {
            $q=db()->prepare('SELECT id FROM prepaid_ledger WHERE id=? AND issue_id=?');$q->execute([(int)($_POST['entry_id'] ?? 0),$id]);if(!$q->fetch())not_found();pass_reverse((int)$_POST['entry_id'],$actor,$operation);flash('Besuch storniert.');
        }
    }catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}
    redirect($back.(int)$m['id']);
}
function route_prepaid_export(int $bid): never
{
    $u=require_login();if(!own_business((int)$u['id'],$bid))not_found();
    $q=db()->prepare('SELECT l.id,l.created_at,i.code,i.name,i.currency,i.receipt,l.kind,l.quantity,l.amount_cents,l.reverses_id FROM prepaid_ledger l JOIN prepaid_issues i ON i.id=l.issue_id WHERE l.business_id=? ORDER BY l.id LIMIT 10001');$q->execute([$bid]);$rows=$q->fetchAll();if(count($rows)>10000){http_response_code(422);echo 'Exportlimit erreicht';exit;}
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="prepaid-ledger.csv"');header('Cache-Control: private, no-store');$f=fopen('php://output','w');fputcsv($f,['ID','UTC','Pass','Name','Currency','Receipt','Action','Quantity','Cents','Reverses'],',','"','');foreach($rows as $row){foreach($row as &$v)if(is_string($v)&&preg_match('/^[\s]*[=+@-]/u',$v))$v="'".$v;unset($v);fputcsv($f,array_values($row),',','"','');}fclose($f);exit;
}
