<?php
declare(strict_types=1);

const STAFF_CAPABILITIES=['award','redeem','reverse','sell_pass'];
function staff_permissions(array $staff): array
{
    $base=match($staff['role'] ?? 'cashier') {
        'viewer'=>[],
        'cashier'=>['award','redeem','sell_pass'],
        'supervisor'=>STAFF_CAPABILITIES,
        default=>[],
    };
    $custom=json_decode((string)($staff['permissions'] ?? ''),true);
    if(is_array($custom))foreach(STAFF_CAPABILITIES as $cap)if(array_key_exists($cap,$custom)) {
        if($custom[$cap] && !in_array($cap,$base,true))$base[]=$cap;
        if(!$custom[$cap])$base=array_values(array_diff($base,[$cap]));
    }
    return $base;
}
function require_staff_permission(string $capability,?array $staff=null): void
{
    $staff ??=require_staff();
    if(!in_array($capability,staff_permissions($staff),true)) {
        http_response_code(403);page('Keine Berechtigung','<div class="auth-box"><h1>Keine Berechtigung</h1><p>Dein Teamzugang darf diese Aktion nicht ausführen. Bitte den Betreiber ansprechen.</p><a href="/staff">Zur Theke</a></div>');exit;
    }
}
function actor_permission(array $actor,string $capability): void
{
    if($actor['kind']==='staff')require_staff_permission($capability);
}
function network_options(int $uid,?int $selected=null): string
{
    $q=db()->prepare('SELECT id,name FROM networks WHERE user_id=? ORDER BY name');$q->execute([$uid]);$out='<option value="0">Kein Netzwerk</option>';
    foreach($q->fetchAll() as $n)$out.='<option value="'.(int)$n['id'].'"'.((int)$selected===(int)$n['id']?' selected':'').'>'.h($n['name']).'</option>';
    return $out;
}
function location_options(int $bid,?int $selected=null): string
{
    $q=db()->prepare('SELECT id,name,active FROM locations WHERE business_id=? ORDER BY name');$q->execute([$bid]);$out='<option value="0">Alle Standorte dieses Unternehmens</option>';
    foreach($q->fetchAll() as $l)$out.='<option value="'.(int)$l['id'].'"'.((int)$selected===(int)$l['id']?' selected':'').'>'.h($l['name']).($l['active']?'':' (inaktiv)').'</option>';
    return $out;
}
function route_organization(string $method): never
{
    $u=require_login();
    if($method==='POST') {
        require_plan_write((int)$u['id'],'locations');$action=$_POST['action'] ?? '';
        try {
            if($action==='network') {
                $name=mb_substr(trim((string)($_POST['name'] ?? '')),0,80);if($name==='')throw new InvalidArgumentException('Name fehlt.');
                db()->prepare('INSERT INTO networks(user_id,name)VALUES(?,?)')->execute([$u['id'],$name]);audit_log((int)$u['id'],'network.create');
            }elseif($action==='assign') {
                $b=own_business((int)$u['id'],(int)($_POST['business_id'] ?? 0));if(!$b)not_found();$network=(int)($_POST['network_id'] ?? 0);
                if($network){$q=db()->prepare('SELECT id FROM networks WHERE id=? AND user_id=?');$q->execute([$network,$u['id']]);if(!$q->fetch())not_found();}
                db()->prepare('UPDATE businesses SET network_id=? WHERE id=?')->execute([$network?:null,$b['id']]);audit_log((int)$u['id'],'network.assign',['business_id'=>$b['id']]);
            }elseif($action==='location') {
                $b=own_business((int)$u['id'],(int)($_POST['business_id'] ?? 0));if(!$b)not_found();$name=mb_substr(trim((string)($_POST['name'] ?? '')),0,80);if($name==='')throw new InvalidArgumentException('Name fehlt.');
                db()->prepare('INSERT INTO locations(business_id,name,address)VALUES(?,?,?)')->execute([$b['id'],$name,mb_substr(trim((string)($_POST['address'] ?? '')),0,200)]);audit_log((int)$u['id'],'location.create',['business_id'=>$b['id']]);
            }elseif($action==='location_toggle') {
                location_set_active((int)$u['id'],(int)($_POST['id'] ?? 0),$_POST);
            }
            flash('Organisation gespeichert.');
        }catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}
        redirect('/organization');
    }
    $body='<h1>Netzwerke & Standorte</h1><p class="muted">Netzwerke ordnen deine eigenen Unternehmen. Mitglieder, Programme und Guthaben bleiben strikt beim Unternehmen. Standorte teilen dessen Mitglieder, aber Teamaktionen tragen die Standortkennung.</p><section class="panel"><h2>Netzwerk anlegen</h2><form method="post" class="form inline-form">'.csrf_field().'<input type="hidden" name="action" value="network"><label>Name<input name="name" maxlength="80" required></label><button class="btn">Anlegen</button></form></section>';
    $q=db()->prepare('SELECT n.id,n.name,(SELECT COUNT(*) FROM businesses b WHERE b.network_id=n.id AND b.user_id=n.user_id) AS companies FROM networks n WHERE n.user_id=? ORDER BY n.name,n.id');$q->execute([$u['id']]);$networks=$q->fetchAll();$body.='<section class="panel network-list"><h2>Eigene Netzwerke</h2>';
    if(!$networks)$body.='<p>Noch keine Netzwerke.</p>';else{$body.='<ul>';foreach($networks as $n)$body.='<li><strong>'.h($n['name']).'</strong> · '.(int)$n['companies'].' Unternehmen · <a href="/network/'.(int)$n['id'].'/edit">Name bearbeiten</a> · <a href="/network/'.(int)$n['id'].'/delete">Löschen prüfen</a></li>';$body.='</ul>';}$body.='</section>';
    $q=db()->prepare('SELECT b.*,n.name AS network_name FROM businesses b LEFT JOIN networks n ON n.id=b.network_id WHERE b.user_id=? ORDER BY b.name');$q->execute([$u['id']]);
    foreach($q->fetchAll() as $b) {
        $bid=(int)$b['id'];$body.='<section class="panel organization-business"><h2>'.h($b['name']).'</h2><form method="post" class="form inline-form">'.csrf_field().'<input type="hidden" name="action" value="assign"><input type="hidden" name="business_id" value="'.$bid.'"><label>Netzwerk<select name="network_id">'.network_options((int)$u['id'],$b['network_id']!==null?(int)$b['network_id']:null).'</select></label><button class="btn ghost">Zuordnen</button></form><p><a href="/business/'.$bid.'/team">Team & Rechte verwalten</a></p><h3>Standorte</h3>';
        $l=db()->prepare('SELECT * FROM locations WHERE business_id=? ORDER BY name');$l->execute([$bid]);foreach($l->fetchAll() as $loc)$body.='<p><strong>'.h($loc['name']).'</strong> · '.h($loc['address']).' · '.($loc['active']?'aktiv':'inaktiv').' · <a href="/location/'.(int)$loc['id'].'/edit">Name / Adresse bearbeiten</a> · <a href="/location/'.(int)$loc['id'].'/wallet-nearby">Wallet-Nähe</a><form method="post" class="undo-form">'.csrf_field().'<input type="hidden" name="action" value="location_toggle"><input type="hidden" name="state_revision" value="'.location_state_revision($loc).'"><input type="hidden" name="active" value="'.($loc['active']?'0':'1').'"><button class="btn ghost small" name="id" value="'.(int)$loc['id'].'">'.($loc['active']?'Standort deaktivieren':'Standort aktivieren').'</button></form></p>';
        $body.='<form method="post" class="form inline-form">'.csrf_field().'<input type="hidden" name="action" value="location"><input type="hidden" name="business_id" value="'.$bid.'"><label>Standortname<input name="name" maxlength="80" required></label><label>Adresse<input name="address" maxlength="200"></label><button class="btn">Standort anlegen</button></form></section>';
    }
    page('Netzwerke & Standorte',$body,$u);exit;
}
function route_team(int $bid,string $method): never
{
    $u=require_login();$b=own_business((int)$u['id'],$bid);if(!$b)not_found();
    if($method==='POST') {
        require_plan_write((int)$u['id'],'staff');$q=db()->prepare('SELECT * FROM staff WHERE id=? AND business_id=?');$q->execute([(int)($_POST['staff_id'] ?? 0),$bid]);$staff=$q->fetch();if(!$staff)not_found();
        $role=(string)($_POST['role'] ?? 'viewer');if(!in_array($role,['viewer','cashier','supervisor'],true))$role='viewer';$location=(int)($_POST['location_id'] ?? 0);
        if($location){$q=db()->prepare('SELECT id FROM locations WHERE id=? AND business_id=?');$q->execute([$location,$bid]);if(!$q->fetch())not_found();}
        $pin=(string)($_POST['new_pin'] ?? '');if($pin!=='' && !preg_match('/^\d{6,12}$/D',$pin)){flash('Neue PIN muss 6 bis 12 Ziffern haben. Nichts gespeichert.','err');redirect('/business/'.$bid.'/team');}
        $permissions=[];foreach(STAFF_CAPABILITIES as $cap)$permissions[$cap]=isset($_POST['cap_'.$cap]);
        db()->prepare('UPDATE staff SET role=?,permissions=?,location_id=?,active=? WHERE id=?')->execute([$role,json_encode($permissions),$location?:null,isset($_POST['active'])?1:0,$staff['id']]);
        $pin=(string)($_POST['new_pin'] ?? '');if($pin!=='') {
            db()->prepare('UPDATE staff SET pin_hash=?,session_version=session_version+1 WHERE id=?')->execute([password_hash($pin,PASSWORD_DEFAULT),$staff['id']]);
        }
        audit_log((int)$u['id'],'team.update',['staff_id'=>$staff['id'],'role'=>$role,'location_id'=>$location]);flash('Teamzugang aktualisiert. Rechte wirken sofort.');redirect('/business/'.$bid.'/team');
    }
    $body='<p><a href="/business/'.$bid.'">← '.h($b['name']).'</a></p><h1>Team & Rechte</h1><p class="muted">Viewer liest, Kasse vergibt/löst ein/verkauft, Schichtleitung darf zusätzlich stornieren. Einzelne Häkchen sind die endgültigen Rechte. Keine Rolle erhält Betreiber-, Abrechnungs- oder Plattformzugriff.</p>';
    $q=db()->prepare('SELECT * FROM staff WHERE business_id=? ORDER BY name');$q->execute([$bid]);
    foreach($q->fetchAll() as $staff) {
        $rights=staff_permissions($staff);$body.='<section class="panel"><h2>'.h($staff['name']).'</h2><form method="post" class="form">'.csrf_field().'<input type="hidden" name="staff_id" value="'.(int)$staff['id'].'"><label>Rollenprofil (setzt die Häkchen neu)<select name="role">';
        foreach(['viewer'=>'Viewer','cashier'=>'Kasse','supervisor'=>'Schichtleitung'] as $v=>$label)$body.='<option value="'.$v.'"'.($staff['role']===$v?' selected':'').'>'.$label.'</option>';
        $body.='</select></label><label>Standort<select name="location_id">'.location_options($bid,$staff['location_id']!==null?(int)$staff['location_id']:null).'</select></label><label class="check"><input type="checkbox" name="active" '.($staff['active']?'checked':'').'> Zugang aktiv</label>';
        foreach(['award'=>'Punkte und Stempel vergeben','redeem'=>'Prämien/Gutscheine/Passbesuche einlösen','reverse'=>'Eigene Aktionen stornieren','sell_pass'=>'Prepaid-Pässe verkaufen'] as $cap=>$label)$body.='<label class="check"><input type="checkbox" name="cap_'.$cap.'" '.(in_array($cap,$rights,true)?'checked':'').'>'.$label.'</label>';
        $body.='<label>Neue PIN (leer = unverändert, 6–12 Ziffern)<input type="password" name="new_pin" inputmode="numeric" autocomplete="new-password" minlength="6" maxlength="12"></label><button class="btn">Zugang speichern</button></form></section>';
    }
    $body.=<<<'HTML'
<script>
document.querySelectorAll('select[name="role"]').forEach(function(select){select.addEventListener('change',function(){var caps=this.value==='supervisor'?['award','redeem','reverse','sell_pass']:(this.value==='cashier'?['award','redeem','sell_pass']:[]);var form=this.form;['award','redeem','reverse','sell_pass'].forEach(function(cap){form.elements['cap_'+cap].checked=caps.indexOf(cap)!==-1;});});});
</script>
HTML;
    page('Team & Rechte',$body,$u);exit;
}

/** Recheck effective rights under the caller's write transaction. */
function assert_business_actor(int $bid,array $actor,string $capability): array
{
    if($actor['kind']==='owner') {
        $q=db()->prepare('SELECT 1 FROM businesses WHERE id=? AND user_id=?');$q->execute([$bid,$actor['id']]);if($q->fetchColumn())return array_merge($actor,['location_id'=>null]);
    }elseif($actor['kind']==='staff') {
        $q=db()->prepare('SELECT s.* FROM staff s LEFT JOIN locations l ON l.id=s.location_id AND l.business_id=s.business_id WHERE s.id=? AND s.business_id=? AND s.active=1 AND (s.location_id IS NULL OR l.active=1)');$q->execute([$actor['id'],$bid]);$s=$q->fetch();
        if($s && in_array($capability,staff_permissions($s),true))return array_merge($actor,['location_id'=>$s['location_id']]);
    }
    throw new InvalidArgumentException('Keine Berechtigung für dieses Unternehmen oder diese Aktion.');
}

function network_revision(array $network): string
{
    return hash('sha256',json_encode([(int)$network['id'],(int)$network['user_id'],$network['name']],JSON_THROW_ON_ERROR));
}
function network_rename(int $uid,int $id,array $input): void
{
    if(!isset($input['name'],$input['revision']) || !is_string($input['name']) || !is_string($input['revision']))throw new InvalidArgumentException('Name und Prüfstand als einzelne Werte angeben.');
    $raw=$input['name'];$name=trim($raw);
    if($name==='' || !mb_check_encoding($raw,'UTF-8') || mb_strlen($name)>80 || preg_match('/[\x00-\x1f\x7f]/u',$raw))throw new InvalidArgumentException('Name mit 1 bis 80 Zeichen ohne Steuerzeichen angeben.');
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('SELECT * FROM networks WHERE id=? AND user_id=?');$q->execute([$id,$uid]);$network=$q->fetch();if(!$network)throw new InvalidArgumentException('Netzwerk nicht verfügbar.');
        if(($err=plan_policy($uid,'locations'))!==null)throw new InvalidArgumentException($err);
        if(!hash_equals(network_revision($network),$input['revision']))throw new InvalidArgumentException('Netzwerk wurde inzwischen geändert. Aktuellen Namen prüfen.');
        $db->prepare('UPDATE networks SET name=? WHERE id=?')->execute([$name,$id]);audit_log($uid,'network.rename',['network_id'=>$id]);$db->exec('COMMIT');
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}
function route_network_edit(int $id,string $method): never
{
    $u=require_login();header('Cache-Control: private, no-store');$q=db()->prepare('SELECT * FROM networks WHERE id=? AND user_id=?');$q->execute([$id,$u['id']]);$n=$q->fetch();if(!$n)not_found();
    if($method==='POST'){require_plan_write((int)$u['id'],'locations');try{network_rename((int)$u['id'],$id,$_POST);flash('Netzwerkname gespeichert. Zuordnungen und Salden unverändert.');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}redirect('/network/'.$id.'/edit');}
    if($method!=='GET'){http_response_code(405);exit;}
    $q=db()->prepare('SELECT COUNT(*) FROM businesses WHERE network_id=? AND user_id=?');$q->execute([$id,$u['id']]);$count=(int)$q->fetchColumn();
    page('Netzwerkname bearbeiten','<p><a href="/organization">← Organisation</a></p><h1>Netzwerkname bearbeiten</h1><section class="panel network-editor"><h2>'.h($n['name']).'</h2><p>Zugeordnete eigene Unternehmen: '.$count.'. Nur der Gruppierungsname wird geändert. Zuordnungen, Mitglieder, Programme und Salden bleiben unverändert. Kein Versand oder Wallet-Update.</p><form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.network_revision($n).'"><label>Name (1 bis 80 Zeichen)<input name="name" maxlength="80" value="'.h($n['name']).'" required></label><button class="btn">Netzwerknamen speichern</button></form><p>Ein älterer Tab kann einen inzwischen geänderten Namen nicht überschreiben. Keine Namenshistorie oder neue Netzwerkfreigabe.</p></section>',$u);exit;
}

function location_revision(array $location): string
{
    return hash('sha256',json_encode([(int)$location['id'],(int)$location['business_id'],$location['name'],$location['address']],JSON_THROW_ON_ERROR));
}
function location_edit(int $uid,int $id,array $input): void
{
    foreach(['name','address','revision'] as $key)if(!isset($input[$key]) || !is_string($input[$key]))throw new InvalidArgumentException('Name, Adresse und Prüfstand als einzelne Werte angeben.');
    $name=trim($input['name']);$address=trim($input['address']);
    foreach(['name'=>80,'address'=>200] as $key=>$max)if(!mb_check_encoding($input[$key],'UTF-8') || mb_strlen(trim($input[$key]))>$max || preg_match('/[\x00-\x1f\x7f]/u',$input[$key]))throw new InvalidArgumentException('Name maximal 80, Adresse maximal 200 Zeichen, ohne Steuerzeichen.');
    if($name==='')throw new InvalidArgumentException('Standortname fehlt.');
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('SELECT l.* FROM locations l JOIN businesses b ON b.id=l.business_id WHERE l.id=? AND b.user_id=?');$q->execute([$id,$uid]);$location=$q->fetch();if(!$location)throw new InvalidArgumentException('Standort nicht verfügbar.');
        if(($err=plan_policy($uid,'locations'))!==null)throw new InvalidArgumentException($err);
        if(!hash_equals(location_revision($location),$input['revision']))throw new InvalidArgumentException('Standort wurde inzwischen geändert. Aktuellen Namen und Adresse prüfen.');
        $db->prepare('UPDATE locations SET name=?,address=? WHERE id=?')->execute([$name,$address,$id]);audit_log($uid,'location.edit',['location_id'=>$id,'business_id'=>(int)$location['business_id']]);$db->exec('COMMIT');
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}
function route_location_edit(int $id,string $method): never
{
    $u=require_login();header('Cache-Control: private, no-store');$q=db()->prepare('SELECT l.*,b.name AS business_name FROM locations l JOIN businesses b ON b.id=l.business_id WHERE l.id=? AND b.user_id=?');$q->execute([$id,$u['id']]);$l=$q->fetch();if(!$l)not_found();
    if($method==='POST'){require_plan_write((int)$u['id'],'locations');try{location_edit((int)$u['id'],$id,$_POST);flash('Standortname und Adresse gespeichert. Aktivierung und Zuordnungen unverändert.');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}redirect('/location/'.$id.'/edit');}
    if($method!=='GET'){http_response_code(405);exit;}
    page('Standort bearbeiten','<p><a href="/organization">← Organisation</a></p><h1>Standort bearbeiten</h1><section class="panel location-editor"><h2>'.h($l['name']).'</h2><p>Unternehmen: '.h($l['business_name']).' · '.($l['active']?'Aktiv':'Inaktiv').'. Nur Name und beschreibende Adresse ändern. Unternehmensbindung, Aktivierung, Teamzuordnung, Buchungen und Salden bleiben unverändert.</p><form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.location_revision($l).'"><label>Name (1 bis 80 Zeichen)<input name="name" maxlength="80" value="'.h($l['name']).'" required></label><label>Adresse (optional, bis 200 Zeichen)<input name="address" maxlength="200" value="'.h($l['address']).'"></label><button class="btn">Standortdaten speichern</button></form><p>Keine Adressprüfung oder Geokodierung, kein Versand oder Wallet-Update. Ein älterer Tab kann inzwischen geänderte Standortdaten nicht überschreiben. Keine historische Namens-/Adresskopie.</p></section>',$u);exit;
}

function network_delete(int $uid,int $id,array $input): void
{
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('SELECT * FROM networks WHERE id=? AND user_id=?');$q->execute([$id,$uid]);$network=$q->fetch();if(!$network)throw new InvalidArgumentException('Netzwerk nicht verfügbar.');
        if(($err=plan_policy($uid,'locations'))!==null)throw new InvalidArgumentException($err);
        if(!isset($input['revision'],$input['confirm_name']) || !is_string($input['revision']) || !is_string($input['confirm_name']) || !hash_equals(network_revision($network),$input['revision']))throw new InvalidArgumentException('Netzwerk wurde inzwischen geändert. Aktuellen Stand prüfen.');
        if($input['confirm_name']!==$network['name'])throw new InvalidArgumentException('Den aktuellen Netzwerknamen exakt bestätigen.');
        $q=$db->prepare('SELECT 1 FROM businesses WHERE network_id=? LIMIT 1');$q->execute([$id]);if($q->fetchColumn())throw new InvalidArgumentException('Noch Unternehmen zugeordnet. Keine Löschung und keine automatische Abkopplung.');
        $db->prepare('DELETE FROM networks WHERE id=?')->execute([$id]);audit_log($uid,'network.delete',['network_id'=>$id]);$db->exec('COMMIT');
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}
function route_network_delete(int $id,string $method): never
{
    $u=require_login();header('Cache-Control: private, no-store');$q=db()->prepare('SELECT * FROM networks WHERE id=? AND user_id=?');$q->execute([$id,$u['id']]);$n=$q->fetch();if(!$n)not_found();
    if($method==='POST'){require_plan_write((int)$u['id'],'locations');try{network_delete((int)$u['id'],$id,$_POST);flash('Unzugeordnetes Netzwerk gelöscht. Keine Unternehmen oder Salden verändert.');redirect('/organization');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');redirect('/network/'.$id.'/delete');}}
    if($method!=='GET'){http_response_code(405);exit;}
    $q=db()->prepare('SELECT COUNT(*) FROM businesses WHERE network_id=?');$q->execute([$id]);$count=(int)$q->fetchColumn();
    $body='<p><a href="/organization">← Organisation</a></p><h1>Netzwerk löschen prüfen</h1><section class="panel network-editor"><h2>'.h($n['name']).'</h2><p>Zugeordnete Unternehmen: '.$count.'. Es wird nur die unzugeordnete Gruppierung endgültig gelöscht. Keine Unternehmens-, Standort-, Mitglieder- oder Saldolöschung, keine automatische Abkopplung. Nicht wiederherstellbar, kein Versand.</p>';
    if($count)$body.='<p>Gesperrt: Noch Unternehmen zugeordnet. Nichts wird gelöscht. Zuordnungen bei Bedarf zuerst bewusst über Organisation ändern.</p>';
    else $body.='<form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.network_revision($n).'"><label>Netzwerkname exakt bestätigen<input name="confirm_name" required autocomplete="off"></label><button class="btn">Unzugeordnetes Netzwerk endgültig löschen</button></form>';
    $body.='<p><a href="/organization">Abbrechen, nichts löschen</a></p></section>';page('Netzwerk löschen prüfen',$body,$u);exit;
}

/** Text edits are independent, only the activation state is compared and written. */
function location_state_revision(array $l): string
{
    return hash('sha256',json_encode([(int)$l['id'],(int)$l['business_id'],(int)$l['active']],JSON_THROW_ON_ERROR));
}
function location_set_active(int $uid,int $id,array $input): void
{
    if(!isset($input['active'],$input['state_revision']) || !is_string($input['active']) || !in_array($input['active'],['0','1'],true) || !is_string($input['state_revision']))throw new InvalidArgumentException('Aktivstatus und Prüfstand fehlen oder sind ungültig. Organisation neu laden.');
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('SELECT l.* FROM locations l JOIN businesses b ON b.id=l.business_id WHERE l.id=? AND b.user_id=?');$q->execute([$id,$uid]);$l=$q->fetch();if(!$l)throw new InvalidArgumentException('Standort nicht verfügbar.');
        if(($err=plan_policy($uid,'locations'))!==null)throw new InvalidArgumentException($err);
        if(!hash_equals(location_state_revision($l),$input['state_revision']))throw new InvalidArgumentException('Standortstatus wurde inzwischen geändert. Aktuellen Stand prüfen.');
        if((int)$l['active']===(int)$input['active'])throw new InvalidArgumentException('Standort hat bereits den gewünschten Status. Nichts geändert.');
        $db->prepare('UPDATE locations SET active=? WHERE id=?')->execute([(int)$input['active'],$id]);audit_log($uid,'location.state',['location_id'=>$id,'active'=>(int)$input['active']]);$db->exec('COMMIT');
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}
