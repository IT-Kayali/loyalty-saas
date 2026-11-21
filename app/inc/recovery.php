<?php
declare(strict_types=1);
function route_forgot_password(string $method): never
{
    if($method==='POST') {
        $email=strtolower(trim((string)($_POST['email'] ?? '')));$kind=($_POST['kind'] ?? '')==='member'?'member':'owner';
        if(filter_var($email,FILTER_VALIDATE_EMAIL) && smtp_ready()) {
            $table=$kind==='owner'?'users':'members';$q=db()->prepare('SELECT id FROM '.$table.' WHERE lower(email)=?');$q->execute([$email]);
            if($q->fetch()) {
                $token=bin2hex(random_bytes(32));$expires=gmdate('Y-m-d H:i:s',time()+1800);
                db()->prepare('INSERT INTO password_resets(email,kind,token_hash,expires_at)VALUES(?,?,?,?)')->execute([$email,$kind,hash('sha256',$token),$expires]);
                queue_mail(null,null,$email,'Passwort zurücksetzen',"Ein Link zum Zurücksetzen wurde angefordert. Er ist 30 Minuten gültig und funktioniert einmal.\n\n".base_url().'/reset-password?token='.$token."\n\nFalls du das nicht warst, ignoriere diese Nachricht.",'transactional','reset:'.hash('sha256',$token));
            }
        }
        flash('Falls ein passendes Konto besteht und der E-Mail-Versand eingerichtet ist, wurde ein Link angefordert. Bitte auch den Spamordner prüfen.');redirect('/forgot-password');
    }
    page('Passwort vergessen','<div class="auth-box"><h1>Passwort vergessen</h1><p class="muted">Einmaliger Link, 30 Minuten gültig. Bei Staff-PINs hilft der Betreiber.</p><form method="post" class="form">'.csrf_field().'<label>Kontotyp<select name="kind"><option value="owner">Betreiber</option><option value="member">Mitglied</option></select></label><label>E-Mail<input type="email" name="email" required></label><button class="btn">Link anfordern</button></form><p><a href="/login">Zur Anmeldung</a></p></div>');exit;
}
function route_reset_password(string $method): never
{
    $token=(string)($_POST['token'] ?? $_GET['token'] ?? '');
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))not_found();
    $q=db()->prepare("SELECT * FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>datetime('now')");$q->execute([hash('sha256',$token)]);$reset=$q->fetch();
    if(!$reset){page('Link abgelaufen','<div class="auth-box"><h1>Link ungültig oder abgelaufen</h1><p><a href="/forgot-password">Neuen Link anfordern</a></p></div>');exit;}
    if($method==='POST') {
        $pw=(string)($_POST['password'] ?? '');if(strlen($pw)<12 || strlen($pw)>200){flash('Passwort mit 12 bis 200 Zeichen wählen.','err');redirect('/reset-password?token='.$token);}
        db()->exec('BEGIN IMMEDIATE');
        try {
            $q=db()->prepare("UPDATE password_resets SET used_at=datetime('now') WHERE id=? AND used_at IS NULL AND expires_at>datetime('now')");$q->execute([$reset['id']]);
            if($q->rowCount()!==1)throw new RuntimeException('Reset already consumed');
            $table=$reset['kind']==='owner'?'users':'members';$GLOBALS['password_reset_authorized']=true;
            db()->prepare('UPDATE '.$table.' SET password_hash=?,session_version=session_version+1 WHERE lower(email)=?')->execute([password_hash($pw,PASSWORD_DEFAULT),$reset['email']]);$GLOBALS['password_reset_authorized']=false;
            db()->prepare("UPDATE password_resets SET used_at=datetime('now') WHERE email=? AND kind=? AND used_at IS NULL")->execute([$reset['email'],$reset['kind']]);
            db()->exec('COMMIT');
        }catch(Throwable $e){try{db()->exec('ROLLBACK');}catch(Throwable){}throw $e;}
        $_SESSION=[];session_regenerate_id(true);flash('Passwort geändert. Bitte neu anmelden.');redirect($reset['kind']==='owner'?'/login':'/member/login');
    }
    page('Neues Passwort','<div class="auth-box"><h1>Neues Passwort</h1><form method="post" class="form">'.csrf_field().'<input type="hidden" name="token" value="'.h($token).'"><label>Passwort (12–200 Zeichen)<input type="password" name="password" minlength="12" maxlength="200" autocomplete="new-password" required></label><button class="btn">Passwort speichern</button></form></div>');exit;
}
