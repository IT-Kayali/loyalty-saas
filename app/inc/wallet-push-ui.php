<?php
declare(strict_types=1);

function wallet_message_state_label(string $state): string
{
    return ['processing'=>'Übergabe begonnen, Ergebnis offen','accepted'=>'Anbieter hat technisch angenommen, kein Empfangsnachweis','partial'=>'Teilweise angenommen, kein Empfangsnachweis','rejected'=>'Nicht übernommen oder abgelehnt, keine Wiederholung','unknown'=>'Ergebnis unklar, nicht automatisch wiederholen'][$state] ?? 'Unbekannter Status';
}
function wallet_message_review_html(array $v,array $m,array $u): string
{
    $ready=wallet_message_readiness($v['provider']);$body='<section class="panel wallet-push-copy"><h2>Empfänger und Text prüfen</h2><dl><dt>Mitglied</dt><dd>'.h($m['name']).' · '.h($m['email']).' · Nr. '.(int)$m['id'].'</dd><dt>Anbieter</dt><dd>'.h(wallet_push_providers()[$v['provider']]).'</dd><dt>Wallet-Konto</dt><dd>'.h($v['provider_account']).'</dd><dt>Nachricht gültig bis</dt><dd>'.h($v['expires_at']).' UTC</dd></dl>';
    if($v['header']!=='')$body.='<h3>'.h($v['header']).'</h3>';
    $body.='<p class="notice">'.nl2br(h($v['body'])).'</p><p>Ein einzelner Mitgliedspass, kein Klassen- oder Massenversand. Alle Personen/Geräte, die diesen Pass besitzen, können den Text sehen. Prüfe Empfänger, Inhalt und Gültigkeit. Keine Werbung, Links zu fremden Angeboten oder vertraulichen Angaben. Die App kann die inhaltliche Zulässigkeit nicht automatisch bestätigen.</p>';
    $body.=$v['provider']==='apple'?'<p>Apple: Feldänderung im Pass und leeres APNs-Updatesignal. Der neue Inhalt kann schon beim nächsten Passabruf sichtbar werden, auch wenn APNs ablehnt. Kein frei adressierbarer Alarm, keine Garantie für deinen exakten Sperrbildschirmtext. Bereits installierte alte Pässe haben das neue Feld möglicherweise noch nicht.</p>':'<p>Google: objektbezogene Nachricht in den Passdetails mit möglicher Benachrichtigung. Google bestimmt den Sperrbildschirmtext. Vor dem Nachrichtenaufruf werden ein vorhandener Kartenhalter und freie Nachrichtenkapazität geprüft. Keine neue Klasse oder Karte wird durch diesen Versand angelegt.</p>';
    $body.='<p>App-Grenze: höchstens drei Übergabeversuche je Mitglied und Anbieter in 24 Stunden. Gleicher Text wird 24 Stunden nicht erneut übernommen. Anbieter können strenger begrenzen. Unklare Ergebnisse und Teilannahmen werden nicht automatisch wiederholt. Ist eine Übergabe begonnen, lässt sie sich hier nicht zurückrufen. Eine spätere Abwahl stoppt keinen bereits laufenden Provideraufruf. Diese Vorschau verfällt nach zehn Minuten oder bei Änderung von Empfänger, Einstellungen oder Kundenwahl.</p>';
    if($ready!==null)$body.='<p class="notice">Versand gesperrt: '.h($ready).' Vorschau ist möglich, hier wird nichts übertragen.</p>';
    $body.='<form method="post" class="form">'.csrf_field().'<input type="hidden" name="action" value="send"><input type="hidden" name="review_id" value="'.h($v['id']).'"><label class="check"><input type="checkbox" name="confirm" value="1"> Ich bestätige genau den angezeigten Mitgliedspass, Anbieter, Text und die Gültigkeit.</label><label class="check"><input type="checkbox" name="important" value="1"> Dies ist eine wichtige zeitkritische Karteninformation, keine Werbenachricht. Ich habe die Rechtsgrundlage und die mögliche Sichtbarkeit auf allen Passkopien geprüft.</label><button class="btn"'.($ready!==null?' disabled':'').'>Diesen einen Pass jetzt benachrichtigen</button></form><p><a href="/business/'.(int)$v['business_id'].'/wallet-message">Vorschau verwerfen</a></p></section>';
    return $body;
}
function route_wallet_message(int $bid,string $method): never
{
    $u=require_login();$uid=(int)$u['id'];$b=own_business($uid,$bid);if(!$b)not_found();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
    if(!in_array($method,['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit;}
    $_SESSION['wallet_message_reviews']??=[];
    foreach($_SESSION['wallet_message_reviews'] as $key=>$review)if(($review['review_expires'] ?? 0)<time())unset($_SESSION['wallet_message_reviews'][$key]);
    $reviewHtml='';
    if($method==='POST'){
        require_plan_write($uid,'core');
        try{
            if(($_POST['action'] ?? null)==='preview'){
                $v=wallet_message_preview($uid,$bid,$_POST);
                while(count($_SESSION['wallet_message_reviews'])>=10)array_shift($_SESSION['wallet_message_reviews']);
                $_SESSION['wallet_message_reviews'][$v['id']]=$v;
                $reviewHtml=wallet_message_review_html($v,wallet_message_member($bid,$v['member_id']),$u);
            }elseif(($_POST['action'] ?? null)==='send'){
                $id=$_POST['review_id'] ?? null;
                if(!is_string($id)||!isset($_SESSION['wallet_message_reviews'][$id])||($_POST['confirm'] ?? null)!=='1'||($_POST['important'] ?? null)!=='1')throw new InvalidArgumentException('Aktuelle Vorschau und beide ausdrücklichen Bestätigungen sind erforderlich.');
                $v=$_SESSION['wallet_message_reviews'][$id];$sent=wallet_message_send($uid,$bid,$v);
                unset($_SESSION['wallet_message_reviews'][$id]);
                flash(wallet_message_state_label($sent['state']).'. Technikstatus: '.$sent['outcome'].'. Keine automatische Wiederholung.');redirect('/business/'.$bid.'/wallet-message');
            }else throw new InvalidArgumentException('Aktion ungültig.');
        }catch(InvalidArgumentException $e){flash($e->getMessage(),'err');redirect('/business/'.$bid.'/wallet-message');}
    }
    $body='<p><a href="/business/'.$bid.'/wallet-push">← Hinweise & Textvorlagen</a></p><h1>Einzelne Wallet-Mitteilung</h1><p>'.h($b['name']).'. Erst Mitglied und Anbieter auswählen, dann Textvorschau und ausdrückliche Bestätigung. Kein Versand durch Auswahl oder Vorschau. Ohne vollständige Einrichtung bleibt der Abschluss gesperrt.</p>'.$reviewHtml;
    if($reviewHtml===''){
        $q=db()->prepare('SELECT id,name,email FROM members WHERE business_id=? ORDER BY name,id LIMIT 201');$q->execute([$bid]);$members=$q->fetchAll();$capped=count($members)>200;$members=array_slice($members,0,200);
        $selected=is_string($_GET['member_id'] ?? null)&&ctype_digit($_GET['member_id'])?(int)$_GET['member_id']:0;$provider=is_string($_GET['provider'] ?? null)&&isset(wallet_push_providers()[$_GET['provider']])?$_GET['provider']:'';
        $selectedMember=$selected?wallet_message_member($bid,$selected):null;
        if($selectedMember&&!in_array($selected,array_map(fn($m)=>(int)$m['id'],$members),true))$members[]=$selectedMember;
        $body.='<section class="panel"><h2>Mitglied und Anbieter</h2><form method="get" class="form"><label>Mitglied<select name="member_id" required><option value="">Bitte auswählen</option>';
        foreach($members as $m)$body.='<option value="'.(int)$m['id'].'"'.((int)$m['id']===$selected?' selected':'').'>'.h($m['name'].' · '.$m['email'].' · Nr. '.$m['id']).'</option>';
        $body.='</select></label><label>Anbieter<select name="provider" required><option value="">Bitte auswählen</option>';
        foreach(wallet_push_providers() as $p=>$label)$body.='<option value="'.$p.'"'.($p===$provider?' selected':'').'>'.$label.'</option>';
        $body.='</select></label><button class="btn ghost">Textvorlage laden</button></form>';
        if($capped)$body.='<p>Erste 200 Mitglieder. Weitere Mitglieder über den Link auf ihrer privaten Mitgliedsseite öffnen.</p>';
        $body.='</section>';
        if($selectedMember&&$provider!==''){
            $s=wallet_push_settings($b);$allowed=wallet_push_allowed($selected,$provider);
            $body.='<section class="panel"><h2>Text bearbeiten</h2><p>'.h($selectedMember['name']).' · '.h($selectedMember['email']).' · '.h(wallet_push_providers()[$provider]).'</p><p>Kundenwahl: '.($allowed?'aktuell zugestimmt':'nicht aktuell zugestimmt, Vorschau/Versand gesperrt').'.</p><form method="post" class="form">'.csrf_field().'<input type="hidden" name="action" value="preview"><input type="hidden" name="member_id" value="'.$selected.'"><input type="hidden" name="provider" value="'.$provider.'">';
            if($provider==='google')$body.='<label>Titel (maximal 80 Zeichen)<input name="header" maxlength="80" value="'.h($s['google_header']).'"></label>';
            else $body.='<input type="hidden" name="header" value="">';
            $body.='<label>Nachricht (maximal '.($provider==='apple'?240:500).' Zeichen)<textarea name="body" rows="4" maxlength="'.($provider==='apple'?240:500).'">'.h($s[$provider==='apple'?'apple_text':'google_body']).'</textarea></label><label>Gültig ab Vorschau für<select name="valid_minutes"><option value="15">15 Minuten</option><option value="60" selected>1 Stunde</option><option value="360">6 Stunden</option><option value="1440">24 Stunden</option></select></label><p>Google: Anzeigeintervall der Nachricht. Apple: Ausgabe bei neuen Passabrufen nur bis Ablauf, keine garantierte Entfernung einer alten Gerätekopie. Keine planmäßige Fernlöschung.</p><button class="btn"'.(!$allowed?' disabled':'').'>Empfänger und Text prüfen, noch nicht senden</button></form></section>';
        }
    }
    $q=db()->prepare('SELECT d.*,m.name AS member_name FROM wallet_push_deliveries d JOIN members m ON m.id=d.member_id WHERE d.business_id=? AND d.user_id=? ORDER BY d.created_at DESC,d.rowid DESC LIMIT 20');$q->execute([$bid,$uid]);$history=$q->fetchAll();
    if($history){$body.='<section class="panel wallet-push-copy"><h2>Letzte 20 Übergabeversuche</h2><p>Technische Annahme ist kein Geräteempfang. Apple-Inhalt kann trotz abgelehntem Signal bei einem Passabruf sichtbar werden. Keine automatischen Wiederholungen.</p>';foreach($history as $d)$body.='<article><h3>'.h($d['member_name']).' · '.h(wallet_push_providers()[$d['provider']]).'</h3><p>'.h(wallet_message_state_label($d['state'])).'<br>Technikstatus: '.h($d['outcome']?:'offen').'<br>'.h($d['created_at']).' UTC · Gültig bis '.h($d['expires_at']).' UTC</p><p>'.nl2br(h(trim($d['header']."\n".$d['body']))).'</p></article>';$body.='</section>';}
    page('Einzelne Wallet-Mitteilung',$body,$u);exit;
}
