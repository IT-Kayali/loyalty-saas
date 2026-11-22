<?php
declare(strict_types=1);
const WEBHOOK_LIST_ROWS=5000;
const WEBHOOK_LIST_BYTES=4*1024*1024;
const WEBHOOK_LIST_CELL_BYTES=65536;
const WEBHOOK_LIST_FIELDS=['delivery_id','webhook_id','event','status','attempts','last_attempt_at_utc','next_attempt_at_utc'];

/** Invalid filters never silently widen a metadata download. */
function webhook_list_filters(array $input,bool $export=false): array
{
    $allowed=['hook','event','status','from','to','lang'];if(!$export)$allowed[]='before';
    foreach(array_keys($input) as $key)if(!in_array($key,$allowed,true))throw new InvalidArgumentException('Unbekannter Listenparameter. Ein Export umfasst alle gefilterten Treffer, keinen Seiten-Cursor.');
    $text=function(string $key)use($input):string{
        $v=array_key_exists($key,$input)?$input[$key]:'';if(!is_string($v) || preg_match('//u',$v)!==1 || preg_match('/[\p{Cc}\p{Cf}]/u',$v))throw new InvalidArgumentException('Filter müssen gültiger Text ohne Steuerzeichen sein.');return $v;
    };
    if(array_key_exists('lang',$input) && !in_array($text('lang'),AVAILABLE_LANGS,true))throw new InvalidArgumentException('Unbekannte Sprache.');
    $f=[];foreach(['hook','event','status','from','to'] as $key)$f[$key]=$text($key);$f['before']=$export?'':$text('before');
    foreach(['hook','before'] as $key)if($f[$key]!==''){
        $v=$f[$key];if(!preg_match('/^[0-9]+$/D',$v))throw new InvalidArgumentException('Webhook und Seiten-Cursor müssen positive ganze Zahlen sein.');$v=ltrim($v,'0');$max=(string)PHP_INT_MAX;
        if($v==='' || strlen($v)>strlen($max) || (strlen($v)===strlen($max) && strcmp($v,$max)>0))throw new InvalidArgumentException('Webhook oder Seiten-Cursor außerhalb des Bereichs.');$f[$key]=$v;
    }
    if(mb_strlen($f['event'])>100)throw new InvalidArgumentException('Eventfilter darf höchstens 100 Zeichen haben.');
    if($f['status']!=='' && !in_array($f['status'],['pending','failed','dead','delivered'],true))throw new InvalidArgumentException('Unbekannter Zustellstatus.');
    foreach(['from','to'] as $key)if($f[$key]!==''){
        $v=$f[$key];$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC'));
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$v) || str_starts_with($v,'0000') || !$d || $d->format('Y-m-d')!==$v)throw new InvalidArgumentException('Datum muss ein gültiger Kalendertag im Format JJJJ-MM-TT sein.');
    }
    if($f['from']!=='' && $f['to']!=='' && $f['from']>$f['to'])throw new InvalidArgumentException('Von-Datum liegt nach dem Bis-Datum.');
    return $f;
}

function webhook_list_where(array $f,int $userId,int $businessId): array
{
    $where='b.id=? AND b.user_id=?';$params=[$businessId,$userId];
    foreach(['hook'=>'d.webhook_id','event'=>'d.event','status'=>'d.status'] as $key=>$column)if($f[$key]!==''){$where.=' AND '.$column.'=?';$params[]=$f[$key];}
    if($f['before']!==''){$where.=' AND d.id<?';$params[]=(int)$f['before'];}
    if($f['from']!=='' || $f['to']!==''){
        // delivered_at also records failed attempts. Never pretend it is a creation/success time.
        $where.=" AND length(d.delivered_at)=19 AND datetime(d.delivered_at,'+0 seconds')=d.delivered_at";
        if($f['from']!==''){$where.=' AND d.delivered_at>=?';$params[]=$f['from'].' 00:00:00';}
        if($f['to']!==''){$where.=' AND d.delivered_at<=?';$params[]=$f['to'].' 23:59:59';}
    }
    return [$where,$params];
}
function webhook_list_query(array $f): array
{
    return array_filter(array_intersect_key($f,array_flip(['hook','event','status','from','to'])),fn($v)=>$v!=='')+['lang'=>lang()];
}

/** Select metadata only. Bound corrupt legacy cells before fetching, not after allocating them. */
function webhook_list_select(array $f,int $userId,int $businessId,int $limit): PDOStatement
{
    [$where,$params]=webhook_list_where($f,$userId,$businessId);$fields=['d.id AS delivery_id','d.webhook_id'];$large=[];
    foreach(['event'=>'event','status'=>'status','attempts'=>'attempts','delivered_at'=>'last_attempt_at_utc','next_attempt_at'=>'next_attempt_at_utc'] as $column=>$alias){
        $test='length(CAST(d.'.$column.' AS BLOB))>'.WEBHOOK_LIST_CELL_BYTES;$large[]=$test;
        $fields[]='CASE WHEN '.$test.' THEN NULL ELSE d.'.$column.' END AS '.$alias;
    }
    $fields[]='CASE WHEN '.implode(' OR ',$large).' THEN 1 ELSE 0 END AS oversized';
    $q=db()->prepare('SELECT '.implode(',',$fields).' FROM webhook_deliveries d JOIN webhooks w ON w.id=d.webhook_id JOIN businesses b ON b.id=w.business_id WHERE '.$where.' ORDER BY d.id DESC LIMIT '.max(1,min(WEBHOOK_LIST_ROWS+1,$limit)));$q->execute($params);return $q;
}
function webhook_list_row(array $row): array
{
    if(!empty($row['oversized']))throw new LengthException('Gespeicherte Metadatenzelle größer als 64 KiB. Keine Teilausgabe erstellt.');
    $out=[];foreach(WEBHOOK_LIST_FIELDS as $field){$value=$row[$field] ?? null;if(is_string($value) && strlen($value)>WEBHOOK_LIST_CELL_BYTES)throw new LengthException('Gespeicherte Metadatenzelle größer als 64 KiB. Keine Teilausgabe erstellt.');$out[$field]=$value;}
    json_encode($out,JSON_THROW_ON_ERROR);return $out;
}
function webhook_list_cell(string $value): string
{
    if(preg_match('/^[\p{Z}\p{Cc}\p{Cf}]*[=+@\-＝＋－＠]/u',$value) || preg_match('/^[\p{Z}\p{Cf}]*[\x00-\x1f\x7f]/u',$value))$value="'".$value;
    return '"'.str_replace('"','""',$value).'"';
}
function webhook_list_csv(PDOStatement $q): string
{
    $out=implode(',',array_map('webhook_list_cell',WEBHOOK_LIST_FIELDS))."\r\n";$count=0;
    try{
        while($row=$q->fetch(PDO::FETCH_ASSOC)){
            if(++$count>WEBHOOK_LIST_ROWS)throw new LengthException('Mehr als 5000 Treffer. Filter enger wählen. Keine Teildatei erstellt.');
            $row=webhook_list_row($row);$out.=implode(',',array_map(fn($v)=>webhook_list_cell((string)($v ?? '')),$row))."\r\n";
            if(strlen($out)>WEBHOOK_LIST_BYTES)throw new LengthException('Export größer als 4 MiB. Filter enger wählen. Keine Teildatei erstellt.');
        }
    }finally{$q->closeCursor();}
    return $out;
}

/** Display only a validated UTC time as a time. CSV keeps the stored diagnostic value. */
function webhook_list_time(?string $value,string $empty): string
{
    if($value===null)return $empty;
    if(strlen($value)!==19 || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$value))return 'Ungültig gespeichert';
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return $date && $date->format('Y-m-d H:i:s')===$value?$value:'Ungültig gespeichert';
}

function route_webhook_list(int $businessId,string $method,bool $export=false): never
{
    $u=require_login();header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    $b=own_business((int)$u['id'],$businessId);if(!$b)not_found();
    if($method!=='GET'){http_response_code(405);header('Allow: GET');exit;}
    $base='/business/'.$businessId.'/webhook-deliveries';
    try{
        $f=webhook_list_filters($_GET,$export);
        if($export){$out=webhook_list_csv(webhook_list_select($f,(int)$u['id'],$businessId,WEBHOOK_LIST_ROWS+1));header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="webhook-deliveries-'.$businessId.'-'.gmdate('Y-m-d').'.csv"');echo $out;exit;}
        $q=webhook_list_select($f,(int)$u['id'],$businessId,51);try{$rows=array_map('webhook_list_row',$q->fetchAll(PDO::FETCH_ASSOC));}finally{$q->closeCursor();}$more=count($rows)>50;$rows=array_slice($rows,0,50);
    }catch(InvalidArgumentException|LengthException|JsonException $e){http_response_code(422);$message=$e instanceof JsonException?'Gespeicherte Metadaten sind nicht gültiges UTF-8. Keine Datei erstellt.':$e->getMessage();page('Webhook-Metadaten prüfen','<h1>Webhook-Metadaten prüfen</h1><p>'.h($message).'</p><p><a href="'.$base.'">Zur Zustellliste</a></p>',$u);exit;}
    $body='<p><a href="/business/'.$businessId.'">← Zum Unternehmen</a></p><p class="eyebrow">'.h($b['name']).'</p><h1>Webhook-Zustellliste</h1><p>Nur gespeicherte Metadaten dieses eigenen Unternehmens, neueste Kennungen zuerst. Kein Versand oder Retry auf dieser Seite.</p><p class="muted">Letzter Versuch ist kein Zustellnachweis und kein Beleg eines Netzwerkaufrufs: Auch abgewiesene oder deaktivierte Ziele erhalten einen Verarbeitungszeitpunkt. Ohne gespeicherten Versuch gibt es keine Zeitangabe. Zeitraumfilter beziehen sich ausschließlich auf kanonische UTC-Zeiten des letzten Versuchs, nicht auf die Ereignis- oder Anlagezeit.</p>';
    $body.='<form method="get" class="form inline-form"><input type="hidden" name="lang" value="'.h(lang()).'"><label>Webhook-ID<input name="hook" inputmode="numeric" value="'.h($f['hook']).'"></label><label>Event, exakt<input name="event" maxlength="100" value="'.h($f['event']).'"></label><label>Status<select name="status"><option value="">Alle</option>';
    foreach(['pending','failed','dead','delivered'] as $status)$body.='<option value="'.$status.'" '.($f['status']===$status?'selected':'').'>'.$status.'</option>';
    $body.='</select></label><label>Letzter Versuch ab (UTC)<input type="date" name="from" value="'.h($f['from']).'"></label><label>Letzter Versuch bis (UTC)<input type="date" name="to" value="'.h($f['to']).'"></label><button class="btn">Filtern</button></form><section class="panel webhook-list-export"><h2>Gefilterte Metadaten als CSV</h2><p>Alle Treffer der Filter, nicht nur diese 50er-Seite. Maximal 5000 Zeilen, 4 MiB und 64 KiB je Metadatenzelle; sonst keine Teildatei.</p><a class="btn ghost" href="'.$base.'/export?'.h(http_build_query(webhook_list_query($f))).'">CSV herunterladen</a><p class="muted">Ohne Payload, Secret, Ziel-URL oder rohe Antwort. Riskante Textanfänge erhalten einen Apostroph. Keine universelle Tabellenprogramm-Garantie. Laufzeit, HTTP-Code, Event-ID und Testkennzeichnung werden bisher nicht getrennt gespeichert und hier nicht erfunden.</p></section>';
    $body.='<div class="table-wrap webhook-delivery-table"><table><thead><tr><th>Zustellung / Webhook</th><th>Event / Status</th><th>Versuche</th><th>Letzter Versuch (UTC)</th><th>Nächster Termin (UTC)</th></tr></thead><tbody>';
    foreach($rows as $row)$body.='<tr><td data-label="Zustellung / Webhook">'.(int)$row['delivery_id'].' / '.(int)$row['webhook_id'].'</td><td data-label="Event / Status">'.h(mb_strimwidth((string)$row['event'],0,160,'…')).'<br>'.h(mb_strimwidth((string)$row['status'],0,160,'…')).'</td><td data-label="Versuche">'.h(mb_strimwidth((string)$row['attempts'],0,160,'…')).'</td><td data-label="Letzter Versuch (UTC)">'.h(webhook_list_time($row['last_attempt_at_utc'],'Nicht gespeichert')).'</td><td data-label="Nächster Termin (UTC)">'.h(webhook_list_time($row['next_attempt_at_utc'],'Kein Termin')).'</td></tr>';
    if(!$rows)$body.='<tr><td colspan="5">Keine passenden gespeicherten Zustellungen.</td></tr>';
    $body.='</tbody></table></div>';if($more)$body.='<p><a class="btn ghost" href="'.$base.'?'.h(http_build_query(webhook_list_query($f)+['before'=>(int)end($rows)['delivery_id']])).'">Ältere 50</a></p>';
    $body.='<p class="muted">Lange Textwerte sind in der Ansicht gekürzt, nicht im CSV. Ungültige Zeitangaben sind markiert; das CSV behält gespeicherte Rohwerte und normalisiert sie nicht. Keine unveränderliche Statistik: Löschen eines Webhooks entfernt auch sein Protokoll. Keine automatische Bereinigung oder Wiederholung durch diesen Abruf. Unbekannte Altstatuswerte bleiben in der ungefilterten Ansicht sichtbar.</p>';
    page('Webhook-Zustellliste',$body,$u);exit;
}
