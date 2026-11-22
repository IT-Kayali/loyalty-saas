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

function route_tier_delete(): never
{
    $u = require_login();
    $p = own_program((int)$u['id'], (int)($_POST['program_id'] ?? 0));
    if ($p) {
        db()->prepare('DELETE FROM tiers WHERE id = ? AND program_id = ?')
            ->execute([(int)$_POST['tier_id'], $p['id']]);
        flash(t('Stufe gelöscht.'));
        redirect('/program/' . (int)$p['id']);
    }
    redirect('/dashboard');
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
    $u = require_login();
    $p = own_program((int)$u['id'], (int)($_POST['program_id'] ?? 0));
    if ($p) {
        db()->prepare('DELETE FROM bonus_hours WHERE id = ? AND program_id = ?')
            ->execute([(int)$_POST['bonus_id'], $p['id']]);
        flash(t('Bonus-Zeit gelöscht.'));
        redirect('/program/' . (int)$p['id']);
    }
    redirect('/dashboard');
}
