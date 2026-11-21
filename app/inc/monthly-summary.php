<?php
declare(strict_types=1);

/** UTC calendar month, not a financial or immutable historic report. */
function monthly_summary_window(mixed $month): array
{
    if(!is_string($month) || !preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/D',$month) || $month>gmdate('Y-m'))throw new InvalidArgumentException('Monat zwischen 2000-01 und dem aktuellen UTC-Monat wählen.');
    $start=new DateTimeImmutable($month.'-01 00:00:00',new DateTimeZone('UTC'));
    return [$start->format('Y-m-d H:i:s'),$start->modify('+1 month')->format('Y-m-d H:i:s')];
}
function monthly_summary_data(int $bid,string $start,string $end): array
{
    $db=db();$db->exec('BEGIN');
    try {
        $q=$db->prepare('SELECT COUNT(*) FROM members WHERE business_id=? AND created_at>=? AND created_at<?');$q->execute([$bid,$start,$end]);$new=(int)$q->fetchColumn();
        $q=$db->prepare("SELECT p.id,p.name,p.type,COUNT(t.id) AS actions,COUNT(DISTINCT CASE WHEN t.id IS NOT NULL THEN c.member_id END) AS active_members,COALESCE(SUM(CASE WHEN t.kind IN ('reward_redeem','points_redeem','voucher_use','voucher_code_use') THEN 1 ELSE 0 END),0) AS redeems
            FROM programs p LEFT JOIN cards c ON c.program_id=p.id
            LEFT JOIN transactions t ON t.card_id=c.id AND t.created_at>=? AND t.created_at<? AND t.reversed_at IS NULL AND t.kind<>'reversal'
            WHERE p.business_id=? GROUP BY p.id ORDER BY p.id LIMIT 201");$q->execute([$start,$end,$bid]);$programs=$q->fetchAll();
        if(count($programs)>200)throw new InvalidArgumentException('Mehr als 200 Programme. Kein unvollständiger Monatsbericht.');
        $q=$db->prepare("SELECT COUNT(DISTINCT c.member_id) FROM transactions t JOIN cards c ON c.id=t.card_id JOIN programs p ON p.id=c.program_id WHERE p.business_id=? AND t.created_at>=? AND t.created_at<? AND t.reversed_at IS NULL AND t.kind<>'reversal'");$q->execute([$bid,$start,$end]);$active=(int)$q->fetchColumn();
        $db->exec('COMMIT');
        return ['new_members'=>$new,'active_members'=>$active,'actions'=>array_sum(array_column($programs,'actions')),'redeems'=>array_sum(array_column($programs,'redeems')),'programs'=>$programs];
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function route_monthly_summary(int $bid,string $method): never
{
    $u=require_login();$b=own_business((int)$u['id'],$bid);if(!$b)not_found();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
    if($method!=='GET'){http_response_code(405);header('Allow: GET');exit;}
    $default=(new DateTimeImmutable('first day of last month',new DateTimeZone('UTC')))->format('Y-m');$month=$_GET['month'] ?? $default;
    $body='<p><a href="/business/'.$bid.'/analytics">← Analytics</a></p><h1>Monatsübersicht</h1><p>'.h($b['name']).'</p><form method="get" class="form inline-form"><label>Kalendermonat (UTC)<input type="month" name="month" min="2000-01" max="'.h(gmdate('Y-m')).'" value="'.h(is_string($month)?$month:'').'" required></label><button class="btn">Monat anzeigen</button></form>';
    try {
        [$start,$end]=monthly_summary_window($month);$d=monthly_summary_data($bid,$start,$end);
        $body.='<p class="muted">Zeitraum: '.h($start).' UTC bis vor '.h($end).' UTC. '.($month===gmdate('Y-m')?'Laufender Monat, noch unvollständig.':'Abgeschlossener Kalendermonat, aber keine eingefrorene Archivkopie.').' Stand: '.h(gmdate('Y-m-d H:i:s')).' UTC.</p><div class="grid monthly-kpis">';
        foreach(['new_members'=>'Neue Mitglieder','active_members'=>'Aktive Mitglieder','actions'=>'Journal-Aktionen','redeems'=>'Einlösungen'] as $key=>$label)$body.='<section class="panel"><h2>'.h($label).'</h2><p class="monthly-number" data-monthly="'.$key.'">'.(int)$d[$key].'</p></section>';
        $body.='</div><p>Journal-Aktionen zählen gespeicherte Buchungen ohne zurückgenommene Einträge und ohne Reversal-Zeilen. Einlösungen zählen Stempelprämien, Punkteprämien, einfache und Code-Gutscheine. Ein Ereignis zählt einmal, unabhängig vom Betrag. Aktive Mitglieder haben mindestens eine solche Journal-Aktion im Zeitraum und werden über Programme hinweg nur einmal gezählt.</p><h2>Programme im gewählten Monat</h2>';
        if(!$d['programs'])$body.='<p>Keine Programme vorhanden.</p>';
        else {
            $body.='<div class="monthly-programs"><table class="table"><thead><tr><th>Programm</th><th>Typ</th><th>Aktive Mitglieder</th><th>Journal-Aktionen</th><th>Einlösungen</th></tr></thead><tbody>';
            foreach($d['programs'] as $p){$body.='<tr>';foreach(['Programm'=>$p['name'],'Typ'=>type_label($p['type']),'Aktive Mitglieder'=>(int)$p['active_members'],'Journal-Aktionen'=>(int)$p['actions'],'Einlösungen'=>(int)$p['redeems']] as $label=>$value)$body.='<td data-label="'.h($label).'">'.h((string)$value).'</td>';$body.='</tr>';}
            $body.='</tbody></table></div>';
        }
        $body.='<p class="muted">Programmnamen und Zuordnungen entsprechen dem heutigen gespeicherten Stand. Neue Mitglieder zählen noch vorhandene Mitgliedseinträge mit Anlagezeit im Monat, nicht neue Personen über alle Unternehmen. Löschungen, spätere Stornos und Datenkorrekturen können frühere Monate ändern. Die aktiven Mitglieder je Programm dürfen nicht zur Gesamtzahl addiert werden. Keine historischen Salden, Umsatz-/Geldsummen, Prepaid-Verkaufszahlen oder vollständige Wirtschaftsauswertung. Kein automatischer Versand, E-Mail-Bericht, CSV oder PDF-Export.</p>';
    }catch(InvalidArgumentException $e){http_response_code(422);$body.='<p class="alert">'.h($e->getMessage()).'</p>';}
    page('Monatsübersicht',$body,$u,$b['brand_color']);exit;
}
