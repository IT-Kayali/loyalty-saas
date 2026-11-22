<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Etappe 3: Membership Tiers + Bonus Hours                            */
/* Doku: "Membership tiers" + "Bonus hours"                            */
/* ------------------------------------------------------------------ */

/** Aktuelle Stufe fuer ein Punkte-Programm anhand der Lifetime-Punkte. */
function current_tier(int $programId, int $lifetimePoints): ?array
{
    $stmt = db()->prepare('SELECT * FROM tiers WHERE program_id = ? AND min_points <= ? ORDER BY min_points DESC LIMIT 1');
    $stmt->execute([$programId, $lifetimePoints]);
    return $stmt->fetch() ?: null;
}

/** Naechste Stufe (fuer Fortschrittsanzeige). */
function next_tier(int $programId, int $lifetimePoints): ?array
{
    $stmt = db()->prepare('SELECT * FROM tiers WHERE program_id = ? AND min_points > ? ORDER BY min_points LIMIT 1');
    $stmt->execute([$programId, $lifetimePoints]);
    return $stmt->fetch() ?: null;
}

/** Aktiver Punkte-Multiplikator (Bonus Hours) fuer jetzt. */
function points_multiplier(int $programId): int
{
    $wd = (int)date('w');
    $h = (int)date('G');
    $stmt = db()->prepare('SELECT multiplier FROM bonus_hours
        WHERE program_id = ? AND weekday = ? AND start_hour <= ? AND end_hour > ?
        ORDER BY multiplier DESC LIMIT 1');
    $stmt->execute([$programId, $wd, $h, $h]);
    $row = $stmt->fetch();
    return $row ? max(1, (int)$row['multiplier']) : 1;
}

/** Tier-Anzeige (Badge + Fortschritt) fuer Karten-Panels und Wallet. */
function tier_html(int $programId, int $lifetimePoints): string
{
    $tier = current_tier($programId, $lifetimePoints);
    $next = next_tier($programId, $lifetimePoints);
    if (!$tier && !$next) {
        return '';
    }
    $out = '<p class="tier-line">';
    if ($tier) {
        $out .= '<span class="badge tier-badge">★ ' . h($tier['name']) . '</span> ';
        if ($tier['benefit']) {
            $out .= '<span class="muted">' . h($tier['benefit']) . '</span> ';
        }
    }
    if ($next) {
        $out .= '<span class="muted">' . t('Noch {n} Punkte bis {tier}', ['n' => (int)$next['min_points'] - $lifetimePoints, 'tier' => $next['name']]) . '</span>';
    }
    return $out . '</p>';
}

/* ------------------------------------------------------------------ */
/* Owner: Tiers und Bonus Hours verwalten (auf der Programmseite)      */
/* ------------------------------------------------------------------ */

/** Shared strict fields for creation and edit, no truncation or numeric coercion. */
function tier_fields(array $input): array
{
    foreach(['name','benefit','min_points'] as $key)if(!isset($input[$key]) || !is_string($input[$key]))throw new InvalidArgumentException('Name, Vorteil und Schwelle als einzelne Werte angeben.');
    foreach(['name'=>40,'benefit'=>120] as $key=>$max)if(!mb_check_encoding($input[$key],'UTF-8') || mb_strlen(trim($input[$key]))>$max || preg_match('/[\x00-\x1f\x7f]/u',$input[$key]))throw new InvalidArgumentException('Name maximal 40, Vorteil maximal 120 Zeichen, ohne Steuerzeichen.');
    $name=trim($input['name']);if($name==='')throw new InvalidArgumentException('Stufenname fehlt.');
    if(!preg_match('/^(0|[1-9][0-9]{0,6})$/D',$input['min_points']) || (int)$input['min_points']>1000000)throw new InvalidArgumentException('Lifetime-Schwelle als ganze Zahl von 0 bis 1000000 angeben.');
    return [$name,(int)$input['min_points'],trim($input['benefit'])];
}
function tier_revision(array $tier): string
{
    return hash('sha256',json_encode([(int)$tier['id'],(int)$tier['program_id'],$tier['name'],(int)$tier['min_points'],$tier['benefit']],JSON_THROW_ON_ERROR));
}
function tier_owned(int $uid,int $id): array|false
{
    $q=db()->prepare("SELECT t.*,p.name AS program_name,p.type FROM tiers t JOIN programs p ON p.id=t.program_id JOIN businesses b ON b.id=p.business_id WHERE t.id=? AND b.user_id=? AND p.type='points'");$q->execute([$id,$uid]);return $q->fetch();
}
function tier_edit(int $uid,int $id,array $input): void
{
    [$name,$threshold,$benefit]=tier_fields($input);
    if(!is_string($input['revision'] ?? null))throw new InvalidArgumentException('Prüfstand fehlt.');
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $t=tier_owned($uid,$id);if(!$t)throw new InvalidArgumentException('Stufe nicht verfügbar.');
        if(($error=plan_policy($uid,'tiers'))!==null)throw new InvalidArgumentException($error);
        if(!hash_equals(tier_revision($t),$input['revision']))throw new InvalidArgumentException('Stufe wurde inzwischen geändert. Aktuellen Stand prüfen.');
        $db->prepare('UPDATE tiers SET name=?,min_points=?,benefit=? WHERE id=?')->execute([$name,$threshold,$benefit,$id]);
        audit_log($uid,'tier.edit',['tier_id'=>$id,'program_id'=>(int)$t['program_id']]);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function route_tier_edit(int $id,string $method): never
{
    $u=require_login();header('Cache-Control: private, no-store');$t=tier_owned((int)$u['id'],$id);if(!$t)not_found();
    if($method==='POST'){require_plan_write((int)$u['id'],'tiers');try{tier_edit((int)$u['id'],$id,$_POST);flash('Stufe gespeichert. Keine Punktebuchung oder Nachricht ausgelöst.');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}redirect('/tier/'.$id.'/edit');}
    if($method!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
    page('Mitgliedsstufe bearbeiten','<p><a href="/program/'.(int)$t['program_id'].'">← '.h($t['program_name']).'</a></p><h1>Mitgliedsstufe bearbeiten</h1><section class="panel tier-editor"><p>Stufen werden aus den aktuellen Lifetime-Punkten berechnet. Eine neue Schwelle kann die angezeigte Stufe bestehender Mitglieder beim nächsten Abruf ändern. Keine Punkte werden vergeben oder abgezogen. Der Vorteil ist ein Beschreibungstext, keine automatisch gebuchte Vergünstigung.</p><form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.tier_revision($t).'"><label>Name (1 bis 40 Zeichen)<input name="name" maxlength="40" value="'.h($t['name']).'" required></label><label>Ab Lifetime-Punkten<input type="number" name="min_points" min="0" max="1000000" step="1" value="'.(int)$t['min_points'].'" required></label><label>Vorteil (optional, maximal 120 Zeichen)<input name="benefit" maxlength="120" value="'.h($t['benefit']).'"></label><button class="btn">Stufe speichern</button></form><p class="muted">Kein Versand, Wallet-Push oder Rückruf von Offlinekopien. Gleiche Schwellen sind wie bisher möglich, daher unterschiedliche Schwellen für eine eindeutige Staffelung wählen. Ein alter Tab kann einen inzwischen geänderten Stand nicht überschreiben.</p></section>',$u);exit;
}
function route_tier_create(): never
{
    $u=require_login();$raw=$_POST['program_id'] ?? null;
    $p=is_string($raw) && ctype_digit($raw)?own_program((int)$u['id'],(int)$raw):false;
    if(!$p || $p['type']!=='points')not_found();require_plan_write((int)$u['id'],'tiers');
    try {
        [$name,$threshold,$benefit]=tier_fields($_POST);$db=db();$db->exec('BEGIN IMMEDIATE');
        try {$fresh=own_program((int)$u['id'],(int)$p['id']);if(!$fresh || $fresh['type']!=='points')throw new InvalidArgumentException('Punkteprogramm nicht verfügbar.');
            $db->prepare('INSERT INTO tiers(program_id,name,min_points,benefit)VALUES(?,?,?,?)')->execute([$p['id'],$name,$threshold,$benefit]);
            audit_log((int)$u['id'],'tier.create',['tier_id'=>(int)$db->lastInsertId(),'program_id'=>(int)$p['id']]);$db->exec('COMMIT');
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
        flash('Stufe angelegt.');
    }catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}
    redirect('/program/'.(int)$p['id']);
}

/** Legacy direct POSTs cannot delete without reviewing the current rule. */
function route_tier_delete(): never
{
    require_login();http_response_code(409);page('Stufe zuerst prüfen','<h1>Stufe zuerst prüfen</h1><p>Bitte das Punkteprogramm neu öffnen und „Löschen prüfen“ bei der gewünschten Stufe wählen.</p>',current_user());exit;
}

function bonus_fields(array $input): array
{
    $values=[];
    foreach(['weekday'=>[0,6],'start_hour'=>[0,23],'end_hour'=>[1,24],'multiplier'=>[2,10]] as $key=>[$min,$max]){
        $raw=$input[$key] ?? null;if(!is_string($raw) || !preg_match('/^(0|[1-9][0-9]?)$/D',$raw) || (int)$raw<$min || (int)$raw>$max)throw new InvalidArgumentException('Wochentag 0–6, Beginn 0–23, Ende 1–24 und Multiplikator 2–10 als ganze Zahlen angeben.');$values[$key]=(int)$raw;
    }
    if($values['end_hour']<=$values['start_hour'])throw new InvalidArgumentException('Ende muss nach Beginn liegen. Für Mitternacht zwei getrennte Zeitfenster anlegen.');
    return $values;
}
function bonus_revision(array $b): string
{
    return hash('sha256',json_encode(array_map(static fn($key)=>(int)$b[$key],['id','program_id','weekday','start_hour','end_hour','multiplier']),JSON_THROW_ON_ERROR));
}
function bonus_owned(int $uid,int $id): array|false
{
    $q=db()->prepare("SELECT bh.*,p.name AS program_name FROM bonus_hours bh JOIN programs p ON p.id=bh.program_id JOIN businesses b ON b.id=p.business_id WHERE bh.id=? AND b.user_id=? AND p.type='points'");$q->execute([$id,$uid]);return $q->fetch();
}
function bonus_edit(int $uid,int $id,array $input): void
{
    $v=bonus_fields($input);if(!is_string($input['revision'] ?? null))throw new InvalidArgumentException('Prüfstand fehlt.');$db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $b=bonus_owned($uid,$id);if(!$b)throw new InvalidArgumentException('Bonuszeit nicht verfügbar.');if(($error=plan_policy($uid,'bonus_hours'))!==null)throw new InvalidArgumentException($error);
        if(!hash_equals(bonus_revision($b),$input['revision']))throw new InvalidArgumentException('Bonuszeit wurde inzwischen geändert. Aktuellen Stand prüfen.');
        $db->prepare('UPDATE bonus_hours SET weekday=?,start_hour=?,end_hour=?,multiplier=? WHERE id=?')->execute([$v['weekday'],$v['start_hour'],$v['end_hour'],$v['multiplier'],$id]);audit_log($uid,'bonus.edit',['bonus_id'=>$id,'program_id'=>(int)$b['program_id']]);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function route_bonus_edit(int $id,string $method): never
{
    $u=require_login();header('Cache-Control: private, no-store');$b=bonus_owned((int)$u['id'],$id);if(!$b)not_found();
    if($method==='POST'){require_plan_write((int)$u['id'],'bonus_hours');try{bonus_edit((int)$u['id'],$id,$_POST);flash('Bonuszeit gespeichert. Keine bestehenden Buchungen geändert.');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}redirect('/bonus/'.$id.'/edit');}
    if($method!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
    $opts='';foreach(weekday_names() as $i=>$name)$opts.='<option value="'.$i.'"'.($i===(int)$b['weekday']?' selected':'').'>'.h($name).'</option>';
    $body='<p><a href="/program/'.(int)$b['program_id'].'">← '.h($b['program_name']).'</a></p><h1>Bonuszeit bearbeiten</h1><section class="panel bonus-editor"><p>Diese Regel wirkt auf künftige Punktevergaben, soweit der jeweilige Buchungspfad Bonuszeiten verwendet. Bestehende Punkte, Lifetime-Punkte und Buchungen werden nicht neu berechnet. Kein Versand.</p><p>Serverzeitzone: <strong>'.h(date_default_timezone_get()).'</strong>. Die Unternehmenszeitzone gilt hier nicht. Beginn einschließlich, Ende ausschließlich. Bei Überschneidung gilt wie bisher der höchste Multiplikator, nicht deren Produkt.</p><form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.bonus_revision($b).'"><label>Wochentag<select name="weekday">'.$opts.'</select></label>';
    foreach(['start_hour'=>['Beginn (Stunde)',0,23],'end_hour'=>['Ende (Stunde, 24 = Tagesende)',1,24],'multiplier'=>['Multiplikator',2,10]] as $key=>[$label,$min,$max])$body.='<label>'.h($label).'<input type="number" name="'.$key.'" min="'.$min.'" max="'.$max.'" step="1" value="'.(int)$b[$key].'" required></label>';
    $body.='<button class="btn">Bonuszeit speichern</button></form><p class="muted">Nur volle Stunden innerhalb eines Tages. Für Mitternacht zwei Zeitfenster anlegen. Kein Datum-/Feiertagskalender, kein automatischer Wallet-Push. Ein alter Tab kann einen inzwischen geänderten Stand nicht überschreiben.</p></section>';
    page('Bonuszeit bearbeiten',$body,$u);exit;
}
function route_bonus_create(): never
{
    $u=require_login();$raw=$_POST['program_id'] ?? null;$p=is_string($raw) && ctype_digit($raw)?own_program((int)$u['id'],(int)$raw):false;
    if(!$p || $p['type']!=='points')not_found();require_plan_write((int)$u['id'],'bonus_hours');
    try {
        $v=bonus_fields($_POST);$db=db();$db->exec('BEGIN IMMEDIATE');
        try {
            $fresh=own_program((int)$u['id'],(int)$p['id']);if(!$fresh || $fresh['type']!=='points')throw new InvalidArgumentException('Punkteprogramm nicht verfügbar.');
            $db->prepare('INSERT INTO bonus_hours(program_id,weekday,start_hour,end_hour,multiplier)VALUES(?,?,?,?,?)')->execute([$p['id'],$v['weekday'],$v['start_hour'],$v['end_hour'],$v['multiplier']]);audit_log((int)$u['id'],'bonus.create',['bonus_id'=>(int)$db->lastInsertId(),'program_id'=>(int)$p['id']]);$db->exec('COMMIT');
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
        flash('Bonuszeit angelegt.');
    }catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}
    redirect('/program/'.(int)$p['id']);
}

function route_bonus_delete(): never
{
    require_login();http_response_code(409);page('Bonuszeit zuerst prüfen','<h1>Bonuszeit zuerst prüfen</h1><p>Bitte das Punkteprogramm neu öffnen und „Löschen prüfen“ bei der gewünschten Bonuszeit wählen.</p>',current_user());exit;
}

/** One fixed rule, current-content check and ID audit in the same write transaction. */
function program_rule_delete(int $uid,string $kind,int $id,array $input): int
{
    if(!in_array($kind,['tier','bonus'],true))throw new InvalidArgumentException('Unbekannte Regel.');
    if(!is_string($input['revision'] ?? null) || ($input['confirm_delete'] ?? null)!=='1')throw new InvalidArgumentException('Aktuellen Stand und dauerhafte Löschung bestätigen.');
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $rule=$kind==='tier'?tier_owned($uid,$id):bonus_owned($uid,$id);if(!$rule)throw new InvalidArgumentException('Regel ist nicht mehr verfügbar. Es wurde nichts gelöscht.');
        if(($err=plan_policy($uid,$kind==='tier'?'tiers':'bonus_hours'))!==null)throw new InvalidArgumentException($err);
        $revision=$kind==='tier'?tier_revision($rule):bonus_revision($rule);
        if(!hash_equals($revision,$input['revision']))throw new InvalidArgumentException('Regel wurde inzwischen geändert. Neuen Stand vor dem Löschen prüfen.');
        if($kind==='tier' && (!is_string($input['name_confirm'] ?? null) || $input['name_confirm']!==$rule['name']))throw new InvalidArgumentException('Aktuellen Stufennamen exakt eingeben.');
        $table=$kind==='tier'?'tiers':'bonus_hours';$db->prepare('DELETE FROM '.$table.' WHERE id=? AND program_id=?')->execute([$id,$rule['program_id']]);
        audit_log($uid,$kind.'.delete',[$kind.'_id'=>$id,'program_id'=>(int)$rule['program_id']]);$db->exec('COMMIT');return (int)$rule['program_id'];
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function route_program_rule_delete(string $kind,int $id,string $method): never
{
    $u=require_login();header('Cache-Control: private, no-store');$rule=$kind==='tier'?tier_owned((int)$u['id'],$id):bonus_owned((int)$u['id'],$id);if(!$rule)not_found();
    $url='/'.$kind.'/'.$id.'/delete';$label=$kind==='tier'?'Mitgliedsstufe':'Bonuszeit';$error='';
    if($method==='POST'){
        require_plan_write((int)$u['id'],$kind==='tier'?'tiers':'bonus_hours');
        try{$pid=program_rule_delete((int)$u['id'],$kind,$id,$_POST);flash($label.' gelöscht. Keine bestehenden Punkte oder Buchungen geändert.');redirect('/program/'.$pid);}catch(InvalidArgumentException $e){http_response_code(409);$error='<p class="alert">'.h($e->getMessage()).'</p>';}
        // Refresh after a lost race before showing a new confirmation form.
        $rule=$kind==='tier'?tier_owned((int)$u['id'],$id):bonus_owned((int)$u['id'],$id);if(!$rule)not_found();
    }elseif($method!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
    $revision=$kind==='tier'?tier_revision($rule):bonus_revision($rule);$pid=(int)$rule['program_id'];
    $body='<p><a href="/program/'.$pid.'">← '.h($rule['program_name']).'</a></p><h1>'.$label.' löschen prüfen</h1>'.$error.'<section class="panel rule-delete">';
    if($kind==='tier')$body.='<h2>'.h($rule['name']).'</h2><p>Ab '.(int)$rule['min_points'].' Lifetime-Punkten'.($rule['benefit']!==''?' · '.h($rule['benefit']):'').'</p><p>Diese Stufe wird dauerhaft entfernt. Bestehende Mitglieder können beim nächsten Abruf eine andere oder keine Stufe sehen. Der beschriebene Vorteil wird nicht automatisch gewährt oder zurückgenommen.</p>';
    else {$days=weekday_names();$body.='<h2>'.h($days[(int)$rule['weekday']] ?? 'Unbekannter Wochentag').' '.(int)$rule['start_hour'].'–'.(int)$rule['end_hour'].' Uhr · x'.(int)$rule['multiplier'].'</h2><p>Serverzeitzone: '.h(date_default_timezone_get()).'. Die Unternehmenszeitzone gilt hier nicht. Diese Regel wird dauerhaft entfernt. Künftige Vergaben können dadurch einen niedrigeren Multiplikator erhalten. Bei Überschneidungen gilt weiter der höchste verbleibende Wert.</p>';}
    $body.='<p>Keine bestehenden Punkte, Lifetime-Punkte, Journalbuchungen oder Prämien ändern sich. Kein Versand oder Wallet-Push. Keine Wiederherstellung. Bereits laufende Buchungen werden nicht rückwirkend korrigiert.</p><form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.$revision.'">';
    if($kind==='tier')$body.='<label>Aktuellen Stufennamen exakt eingeben<input name="name_confirm" autocomplete="off" required></label>';
    $body.='<label class="check"><input type="checkbox" name="confirm_delete" value="1" required> Ich habe diese Regel und ihre Wirkung geprüft und will sie dauerhaft löschen.</label><button class="btn">'.$label.' endgültig löschen</button><a class="btn ghost" href="/program/'.$pid.'">Abbrechen</a></form><p class="muted">Ein geänderter oder bereits gelöschter Stand wird nicht blind gelöscht. Nach einer Änderung neu prüfen und erneut bestätigen.</p></section>';
    page($label.' löschen prüfen',$body,$u);exit;
}
