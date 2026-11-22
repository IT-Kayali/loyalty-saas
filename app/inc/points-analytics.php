<?php
declare(strict_types=1);
const POINTS_ANALYTICS_RANGES=['today'=>'Heute','yesterday'=>'Gestern','this_week'=>'Diese Woche','last_week'=>'Letzte Woche','this_month'=>'Dieser Monat','last_month'=>'Letzter Monat','this_year'=>'Dieses Jahr','last_year'=>'Letztes Jahr'];

function points_analytics_range(array $input): string
{
    foreach(array_keys($input) as $key)if(!in_array($key,['range','lang'],true))throw new InvalidArgumentException('Unbekannter Auswertungsparameter.');
    if(array_key_exists('lang',$input) && (!is_string($input['lang']) || !in_array($input['lang'],AVAILABLE_LANGS,true)))throw new InvalidArgumentException('Unbekannte Sprache.');
    $range=array_key_exists('range',$input)?$input['range']:'this_month';
    if(!is_string($range) || !array_key_exists($range,POINTS_ANALYTICS_RANGES))throw new InvalidArgumentException('Einen Zeitraum aus der Liste wählen.');
    return $range;
}
/** Complete UTC calendar boundaries; current periods stop at the captured as-of second. */
function points_analytics_window(string $range,?DateTimeImmutable $now=null): array
{
    if(!array_key_exists($range,POINTS_ANALYTICS_RANGES))throw new InvalidArgumentException('Unbekannter Zeitraum.');
    $now=($now ?? new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    $day=$now->setTime(0,0);$month=$day->modify('first day of this month');$year=$day->setDate((int)$day->format('Y'),1,1);
    [$start,$step,$bucket]=match($range){
        'today'=>[$day,'1 day','hour'],'yesterday'=>[$day->modify('-1 day'),'1 day','hour'],
        'this_week'=>[$day->modify('-'.((int)$day->format('N')-1).' days'),'1 week','day'],
        'last_week'=>[$day->modify('-'.((int)$day->format('N')+6).' days'),'1 week','day'],
        'this_month'=>[$month,'1 month','day'],'last_month'=>[$month->modify('-1 month'),'1 month','day'],
        'this_year'=>[$year,'1 year','month'],'last_year'=>[$year->modify('-1 year'),'1 year','month'],
    };
    $end=$start->modify('+'.$step);$previous=$start->modify('-'.$step);
    return ['range'=>$range,'start'=>$start->format('Y-m-d H:i:s'),'end'=>$end->format('Y-m-d H:i:s'),'previous_start'=>$previous->format('Y-m-d H:i:s'),'previous_end'=>$start->format('Y-m-d H:i:s'),'as_of'=>$now->format('Y-m-d H:i:s'),'partial'=>$end>$now,'bucket'=>$bucket];
}
function points_analytics_program(int $uid,int $pid): ?array
{
    $q=db()->prepare("SELECT p.id,p.name,p.business_id,p.active,b.name AS business_name,b.brand_color FROM programs p JOIN businesses b ON b.id=p.business_id WHERE p.id=? AND p.type='points' AND b.user_id=?");$q->execute([$pid,$uid]);return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
function points_analytics_bins(string $start,string $end,string $asOf,string $bucket): array
{
    [$format,$step]=match($bucket){'hour'=>['Y-m-d H','+1 hour'],'day'=>['Y-m-d','+1 day'],'month'=>['Y-m','+1 month']};
    $cursor=new DateTimeImmutable($start,new DateTimeZone('UTC'));$bins=[];
    while($cursor->format('Y-m-d H:i:s')<$end && $cursor->format('Y-m-d H:i:s')<=$asOf){
        $key=$cursor->format($format);$bins[$key]=['period'=>$bucket==='hour'?$key.':00':$key,'issued'=>0,'redeemed'=>0,'claims'=>0];$cursor=$cursor->modify($step);
    }
    return $bins;
}
/** One read transaction for program metadata, integrity check and both periods. */
function points_analytics_data(int $uid,int $pid,string $range,?DateTimeImmutable $now=null): ?array
{
    $w=points_analytics_window($range,$now);$db=db();$db->beginTransaction();
    try{
        $p=points_analytics_program($uid,$pid);if(!$p){$db->commit();return null;}
        foreach(['name','business_name'] as $key)if(!mb_check_encoding((string)$p[$key],'UTF-8') || strlen((string)$p[$key])>65536)throw new DomainException('Invalid report metadata');
        $scope=" FROM transactions t JOIN cards c ON c.id=t.card_id JOIN programs p ON p.id=c.program_id JOIN businesses b ON b.id=p.business_id LEFT JOIN members m ON m.id=c.member_id WHERE p.id=? AND p.type='points' AND b.user_id=? AND t.kind IN ('points_add','points_redeem') AND t.reversed_at IS NULL";
        // Refuse incomplete or guessed numbers, including malformed records outside the chosen period.
        $valid="m.business_id=p.business_id AND typeof(t.amount)='integer' AND t.amount>=0 AND length(t.created_at)=19 AND substr(t.created_at,1,4) BETWEEN '0001' AND '9999' AND datetime(t.created_at,'+0 seconds')=t.created_at";
        $q=$db->prepare('SELECT 1'.$scope.' AND COALESCE(('.$valid.'),0)=0 LIMIT 1');$q->execute([$pid,$uid]);$bad=$q->fetchColumn();$q->closeCursor();if($bad!==false)throw new DomainException('Unusable points journal');
        $length=match($w['bucket']){'hour'=>13,'day'=>10,'month'=>7};$result=[];
        foreach(['current'=>[$w['start'],$w['end']],'previous'=>[$w['previous_start'],$w['previous_end']]] as $which=>[$start,$end]){
            $bins=points_analytics_bins($start,$end,$w['as_of'],$w['bucket']);$totals=['issued'=>0,'redeemed'=>0,'claims'=>0];
            $q=$db->prepare("SELECT substr(t.created_at,1,$length) AS period,SUM(CASE WHEN t.kind='points_add' THEN t.amount ELSE 0 END) AS issued,SUM(CASE WHEN t.kind='points_redeem' THEN t.amount ELSE 0 END) AS redeemed,SUM(CASE WHEN t.kind='points_redeem' THEN 1 ELSE 0 END) AS claims".$scope.' AND m.business_id=p.business_id AND t.created_at>=? AND t.created_at<? AND t.created_at<=? GROUP BY period ORDER BY period');$q->execute([$pid,$uid,$start,$end,$w['as_of']]);
            try{while($row=$q->fetch(PDO::FETCH_ASSOC)){
                if(!isset($bins[$row['period']]))throw new DomainException('Unexpected report bucket');
                foreach($totals as $key=>$unused){$value=$row[$key];if(!is_int($value) || $value<0 || $value>PHP_INT_MAX-$totals[$key])throw new OverflowException('Report integer range exceeded');$bins[$row['period']][$key]=$value;$totals[$key]+=$value;}
            }}finally{$q->closeCursor();}
            $result[$which]=['totals'=>$totals,'series'=>array_values($bins)];
        }
        $db->commit();return ['program'=>$p,'window'=>$w]+$result;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function points_analytics_chart(array $series): string
{
    $max=1;foreach($series as $row)$max=max($max,$row['issued'],$row['redeemed']);$count=max(1,count($series));$step=640/$count;$width=min(12,$step*.34);$bars='';$ticks='';
    foreach($series as $i=>$row){$x=60+$i*$step;
        foreach(['issued'=>'#C94B22','redeemed'=>'#246783'] as $key=>$color){$height=$row[$key]/$max*120;if($height>0)$bars.='<rect x="'.($x+($key==='issued'?0:$width+1)).'" y="'.(140-$height).'" width="'.$width.'" height="'.$height.'" fill="'.$color.'"><title>'.h($row['period']).': '.(int)$row[$key].' '.($key==='issued'?'gutgeschriebene':'eingelöste').' Punkte</title></rect>';}
        if($i%max(1,(int)ceil($count/5))===0){$label=strlen($row['period'])===7?substr($row['period'],5):substr($row['period'],11,5);if($label==='')$label=substr($row['period'],8,2).'.'.substr($row['period'],5,2).'.';$ticks.='<text x="'.$x.'" y="162" text-anchor="'.($i>=$count-2?'end':'start').'" font-size="11" fill="#61574F">'.h($label).'</text>';}
    }
    return '<svg viewBox="0 0 720 174" role="img" aria-labelledby="points-chart-title points-chart-desc"><title id="points-chart-title">Punkteverlauf im gewählten UTC-Zeitraum</title><desc id="points-chart-desc">Orange: gutgeschriebene Punkte. Blau: eingelöste Punkte. Zahlen stehen auch in der Tabelle. Keine Balken für Nullwerte.</desc><text x="0" y="24" font-size="11" fill="#61574F">Max.</text><text x="30" y="140" font-size="11" fill="#61574F">0</text><line x1="58" y1="140" x2="704" y2="140" stroke="#CFC5BA"/>'.$bars.$ticks.'</svg><p class="muted">Skala: 0 bis '.$max.' Punkte.</p>';
}
function route_points_analytics(int $pid,string $method): never
{
    $u=require_login();header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');$p=points_analytics_program((int)$u['id'],$pid);if(!$p)not_found();
    if($method!=='GET'){header('Allow: GET');http_response_code(405);exit;}
    try{$range=points_analytics_range($_GET);$d=points_analytics_data((int)$u['id'],$pid,$range);if(!$d)not_found();}
    catch(InvalidArgumentException $e){http_response_code(422);page('Zeitraum prüfen','<h1>Zeitraum prüfen</h1><p>'.h($e->getMessage()).'</p><a href="/program/'.$pid.'/analytics">Auswertung öffnen</a>',$u);exit;}
    catch(Throwable $e){http_response_code(503);page('Auswertung nicht verfügbar','<h1>Auswertung nicht verfügbar</h1><p>Die gespeicherten Punktebuchungen sind gerade nicht vollständig auswertbar. Keine Teilkennzahlen angezeigt. Bitte Daten und Betrieb getrennt prüfen.</p>',$u);exit;}
    $p=$d['program'];$w=$d['window'];$body='<section class="points-analytics"><p><a href="/business/'.(int)$p['business_id'].'/analytics">← Unternehmens-Analytics</a> · <a href="/program/'.$pid.'">Zum Programm</a></p><p class="eyebrow">'.h($p['business_name']).'</p><h1>Punkte-Auswertung</h1><h2>'.h($p['name']).'</h2>'.(!(int)$p['active']?'<p class="alert">Inaktives Programm, gespeicherte Daten bleiben lesbar.</p>':'').'<form method="get" class="form inline-form"><input type="hidden" name="lang" value="'.h(lang()).'"><label>Kalenderzeitraum (UTC)<select name="range">';
    foreach(POINTS_ANALYTICS_RANGES as $value=>$label)$body.='<option value="'.$value.'" '.($value===$range?'selected':'').'>'.$label.'</option>';
    $lastDay=(new DateTimeImmutable($w['end'],new DateTimeZone('UTC')))->modify('-1 second')->format('Y-m-d');$dates=substr($w['start'],0,10);if($dates!==$lastDay)$dates.=' bis '.$lastDay;
    $body.='</select></label><button class="btn">Zeitraum anzeigen</button></form><p><strong>'.h(POINTS_ANALYTICS_RANGES[$range]).' (UTC)</strong>: '.h($dates).'.</p><p class="muted">Stand: '.h($w['as_of']).' UTC. '.($w['partial']?'Laufender Zeitraum, nur Buchungen bis zu diesem Stand.':'Abgeschlossener Kalenderzeitraum, neu berechnet.').' Vergleich: ganze Vorperiode, nicht auf gleiche Laufzeit gekürzt.</p><details><summary>Genaue UTC-Grenzen</summary><p class="muted">Gewählt: '.h($w['start']).' UTC bis vor '.h($w['end']).' UTC. Stand einschließlich der angezeigten Sekunde. Vorperiode: '.h($w['previous_start']).' UTC bis vor '.h($w['previous_end']).' UTC. Keine eingefrorene Archivkopie.</p></details><div class="grid points-analytics-kpis">';
    foreach(['issued'=>'Punkte gutgeschrieben','redeemed'=>'Punkte eingelöst','claims'=>'Prämieneinlösungen'] as $key=>$label)$body.='<section class="panel"><h2>'.h($label).'</h2><p class="points-analytics-number" data-points-metric="'.$key.'">'.(int)$d['current']['totals'][$key].'</p><p class="muted">Vorperiode: <span data-points-previous="'.$key.'">'.(int)$d['previous']['totals'][$key].'</span></p></section>';
    $body.='</div><p>Gutschriften summieren <code>points_add</code>, eingelöste Punkte die positiv gespeicherten Kosten aus <code>points_redeem</code>. Jede solche Einlösung zählt als eine Prämieneinlösung. Stornierte Einträge, Transfers, Verfall und andere Journalarten zählen nicht. Keine neue Buchung oder Änderung von Salden.</p><h2>Verlauf im gewählten Zeitraum</h2><div class="panel points-analytics-chart">'.points_analytics_chart($d['current']['series']).'<p class="muted">Orange: Gutschriften · Blau: eingelöste Punkte. Einheit: Punkte. Keine Seitenaufrufe oder Prämienanzahl auf dieser Skala.</p></div><div class="points-analytics-series"><table class="table"><caption>UTC-Abschnitte: '.h(match($w['bucket']){'hour'=>'Stundenbeginn','day'=>'Kalendertag','month'=>'Kalendermonat'}).'. Zukünftige Abschnitte fehlen; der letzte kann noch laufen.</caption><thead><tr><th>Abschnitt (UTC)</th><th>Punkte gutgeschrieben</th><th>Punkte eingelöst</th><th>Prämieneinlösungen</th></tr></thead><tbody>';
    foreach($d['current']['series'] as $row)$body.='<tr><td data-label="Abschnitt (UTC)">'.h($row['period']).'</td><td data-label="Punkte gutgeschrieben">'.$row['issued'].'</td><td data-label="Punkte eingelöst">'.$row['redeemed'].'</td><td data-label="Prämieneinlösungen">'.$row['claims'].'</td></tr>';
    $body.='</tbody></table></div><p class="muted">Aktuelle Programm-/Unternehmensnamen und noch vorhandenes Journal, kein historischer Saldo. Spätere Stornos und Löschungen ändern frühere Zahlen. Nichtkanonische Zeitwerte, ungültige Mengen oder widersprüchliche Mitgliedszuordnungen in den nicht stornierten Punktebuchungen dieses Programms verhindern den gesamten Bericht, auch außerhalb des gewählten Zeitraums. Keine geschätzten Ersatzwerte.</p><p class="muted">Noch keine Aufrufmessung, Einlösequote, Prozenttrends, personenbezogene Aktivitätsliste oder neue Exportdatei. Keine Umsatz-/Geldrechnung und keine vollständige Referenzparität. Die bisherigen Unternehmens-Allzeitwerte und Monatsberichte bleiben unverändert.</p></section>';
    page('Punkte-Auswertung',$body,$u,$p['brand_color']);exit;
}
