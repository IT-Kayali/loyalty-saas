<?php
declare(strict_types=1);

function reward_fields(array $input): array
{
    $name=$input['name'] ?? null;$cost=$input['points_cost'] ?? null;
    if(!is_string($name)||!is_string($cost)||!mb_check_encoding($name,'UTF-8')||preg_match('/[\x00-\x1F\x7F]/u',$name)||mb_strlen(trim($name))>120||trim($name)==='')throw new InvalidArgumentException('Prämienname mit 1 bis 120 Zeichen ohne Steuerzeichen angeben.');
    if(!preg_match('/^[1-9][0-9]{0,5}$/D',$cost)||(int)$cost>100000)throw new InvalidArgumentException('Punktekosten als ganze Zahl von 1 bis 100000 angeben.');
    return [trim($name),(int)$cost];
}
function reward_owned(int $uid,int $id): array|false
{
    $q=db()->prepare("SELECT r.*,p.name AS program_name FROM rewards r JOIN programs p ON p.id=r.program_id JOIN businesses b ON b.id=p.business_id WHERE r.id=? AND b.user_id=? AND p.type='points'");$q->execute([$id,$uid]);return $q->fetch();
}
function reward_revision(array $reward): string
{
    return hash('sha256',json_encode([(int)$reward['id'],(int)$reward['program_id'],$reward['name'],(int)$reward['points_cost']],JSON_THROW_ON_ERROR));
}
function reward_edit(int $uid,int $id,array $input): void
{
    [$name,$cost]=reward_fields($input);
    if(!is_string($input['revision'] ?? null))throw new InvalidArgumentException('Prüfstand fehlt.');
    $db=db();$db->exec('BEGIN IMMEDIATE');try{
        $r=reward_owned($uid,$id);if(!$r)throw new InvalidArgumentException('Prämie nicht verfügbar.');
        if(($err=plan_policy($uid,'programs'))!==null)throw new InvalidArgumentException($err);
        if(!hash_equals(reward_revision($r),$input['revision']))throw new InvalidArgumentException('Prämie wurde inzwischen geändert. Aktuellen Stand prüfen.');
        $db->prepare('UPDATE rewards SET name=?,points_cost=? WHERE id=?')->execute([$name,$cost,$id]);
        audit_log($uid,'reward.edit',['reward_id'=>$id,'program_id'=>(int)$r['program_id']]);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function route_reward_edit(int $id,string $method): never
{
    $u=require_login();header('Cache-Control: private, no-store');$r=reward_owned((int)$u['id'],$id);if(!$r)not_found();
    if($method==='POST'){require_plan_write((int)$u['id'],'programs');try{reward_edit((int)$u['id'],$id,$_POST);flash('Prämie gespeichert. Bestehende Einlösecodes und Buchungen bleiben unverändert.');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}redirect('/reward/'.$id.'/edit');}
    if($method!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
    $body='<p><a href="/program/'.(int)$r['program_id'].'">← '.h($r['program_name']).'</a></p><h1>Punkteprämie bearbeiten</h1><section class="panel reward-editor"><p>Name und Punktekosten gelten für künftige direkte Einlösungen und neu erzeugte Einlösecodes. Bereits ausgestellte Codes behalten ihren gespeicherten Namen und Preis. Die Punkte werden weiter erst beim Einlösen abgebucht, nicht reserviert. Bestehende Salden, Lifetime-Werte und Journalbuchungen ändern sich nicht.</p><form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.reward_revision($r).'"><label>Name (1 bis 120 Zeichen)<input name="name" maxlength="120" value="'.h($r['name']).'" required></label><label>Punktekosten<input type="number" name="points_cost" min="1" max="100000" step="1" value="'.(int)$r['points_cost'].'" required></label><button class="btn">Prämie speichern</button></form><p class="muted">Zeitfenster und Programmzuordnung werden hier nicht geändert. Vorhandene Zeitfenster gelten auch beim Einlösen alter Codes. Kein Versand oder Wallet-Push, keine rückwirkende Preisanpassung. Ein alter Tab kann einen abweichenden Namen-/Kostenstand nicht überschreiben.</p></section>';
    page('Punkteprämie bearbeiten',$body,$u);exit;
}
function route_reward_create(): never
{
    $u=require_login();$raw=$_POST['program_id'] ?? null;
    $p=is_string($raw)&&ctype_digit($raw)?own_program((int)$u['id'],(int)$raw):false;
    if(!$p||$p['type']!=='points')not_found();require_plan_write((int)$u['id'],'programs');
    try{
        [$name,$cost]=reward_fields($_POST);$db=db();$db->exec('BEGIN IMMEDIATE');try{
            $fresh=own_program((int)$u['id'],(int)$p['id']);if(!$fresh||$fresh['type']!=='points')throw new InvalidArgumentException('Punkteprogramm nicht verfügbar.');
            $db->prepare('INSERT INTO rewards(program_id,name,points_cost)VALUES(?,?,?)')->execute([$p['id'],$name,$cost]);
            audit_log((int)$u['id'],'reward.create',['reward_id'=>(int)$db->lastInsertId(),'program_id'=>(int)$p['id']]);$db->exec('COMMIT');
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}flash('Prämie hinzugefügt.');
    }catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}redirect('/program/'.(int)$p['id']);
}
