<?php
declare(strict_types=1);

/** Ausschließlich der Anzeigename, keine Zahlungs-/Rechtsträgerdaten. */
function route_business_settings(int $id,string $method): never
{
    $u=require_login();$b=own_business((int)$u['id'],$id);if(!$b)not_found();
    header('Cache-Control: private, no-store');
    if($method==='POST') {
        $name=trim((string)($_POST['name'] ?? ''));
        if($name==='' || !mb_check_encoding($name,'UTF-8') || mb_strlen($name)>100 || preg_match('/[\x00-\x1f\x7f]/u',$name)) {
            flash('Unternehmensname mit 1 bis 100 Zeichen, ohne Steuerzeichen angeben.','err');redirect('/business/'.$id.'/settings');
        }
        db()->exec('BEGIN IMMEDIATE');
        try {
            $fresh=own_business((int)$u['id'],$id);
            if(!$fresh || !hash_equals(hash('sha256',(string)$fresh['name']),(string)($_POST['revision'] ?? '')))throw new InvalidArgumentException('Unternehmensname wurde inzwischen geändert. Aktuellen Stand prüfen und erneut bearbeiten.');
            db()->prepare('UPDATE businesses SET name=? WHERE id=? AND user_id=?')->execute([$name,$id,$u['id']]);
            audit_log((int)$u['id'],'business.rename',['business_id'=>$id]);db()->exec('COMMIT');
        } catch(Throwable $e) {
            db()->exec('ROLLBACK');if($e instanceof InvalidArgumentException){flash($e->getMessage(),'err');redirect('/business/'.$id.'/settings');}throw $e;
        }
        flash('Unternehmensname gespeichert. Bestehende Links, Codes und Guthaben bleiben unverändert.');redirect('/business/'.$id.'/settings');
    }
    page('Unternehmensname','<p><a href="/business/'.$id.'">← '.h($b['name']).'</a></p><h1>Unternehmensname</h1><section class="panel"><p>Ändert den Anzeigenamen dieses Unternehmens in der Webanwendung. Das ist keine Änderung des Rechnungsempfängers oder rechtlichen Firmennamens beim Zahlungsanbieter.</p><p class="muted">Eigene Texte im Karten-Designer, Programme, Standorte, Links, Codes und Guthaben bleiben unverändert. Bereits installierte Apple-/Google-Wallet-Pässe, Offlinekopien und erzeugte Dokumente werden dadurch nicht automatisch aktualisiert.</p><form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.hash('sha256',(string)$b['name']).'"><label>Unternehmensname<input name="name" maxlength="100" value="'.h($b['name']).'" required></label><button class="btn">Namen speichern</button></form></section><p><a href="/business/'.$id.'/design">Karten-Designer</a> · <a href="/organization">Netzwerke & Standorte</a> · <a href="/business/'.$id.'/team">Team & Rechte</a></p>',$u);exit;
}
