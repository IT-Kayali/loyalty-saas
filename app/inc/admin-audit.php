<?php
declare(strict_types=1);
const ADMIN_AUDIT_EXPORT_ROWS=5000;
const ADMIN_AUDIT_EXPORT_BYTES=4*1024*1024;
const ADMIN_AUDIT_CELL_BYTES=65536;
const ADMIN_AUDIT_FIELDS=['audit_id','created_at_utc','account_email','action'];

/** Keep existing list filters; a download must not silently ignore selectors. */
function admin_audit_filters(array $input,bool $export=false): array
{
    if($export){
        foreach(array_keys($input) as $key)if(!in_array($key,['from','to','action','account','lang'],true))throw new InvalidArgumentException('Unbekannter Exportparameter. Der Export umfasst alle gefilterten Treffer, keinen Seiten-Cursor.');
        if(array_key_exists('lang',$input) && (!is_string($input['lang']) || !in_array($input['lang'],AVAILABLE_LANGS,true)))throw new InvalidArgumentException('Unbekannte Sprache.');
    }
    $f=[];
    foreach(['from','to','action','account','before'] as $key){
        $v=array_key_exists($key,$input)?$input[$key]:'';
        if(!is_string($v) || !mb_check_encoding($v,'UTF-8') || preg_match('/[\x00-\x1f\x7f]/',$v))throw new InvalidArgumentException('Filter müssen einzelne gültige Textwerte ohne Steuerzeichen sein.');
        $f[$key]=trim($v);
    }
    foreach(['from','to'] as $key)if($f[$key]!==''){
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$f[$key],new DateTimeZone('UTC'));
        if(!$d || $d->format('Y-m-d')!==$f[$key])throw new InvalidArgumentException('Gültige UTC-Daten im Format JJJJ-MM-TT angeben.');
    }
    if($f['from']!=='' && $f['to']!=='' && $f['from']>$f['to'])throw new InvalidArgumentException('Von darf nicht nach Bis liegen.');
    if(strlen($f['action'])>120 || ($f['action']!=='' && !preg_match('/^[a-zA-Z0-9_.:-]+$/D',$f['action'])))throw new InvalidArgumentException('Aktion exakt mit maximal 120 Zeichen angeben (Buchstaben, Zahlen, Punkt, Unterstrich, Doppelpunkt, Bindestrich).');
    if(mb_strlen($f['account'])>254)throw new InvalidArgumentException('Kontosuche mit maximal 254 Zeichen angeben.');
    if($f['before']!=='' && !preg_match('/^[1-9][0-9]{0,17}$/D',$f['before']))throw new InvalidArgumentException('Ungültige Folgeseite.');
    return $f;
}

/** Same legacy UTC string comparisons and literal account search for list and CSV. */
function admin_audit_where(array $f): array
{
    $where=['1=1'];$params=[];
    if($f['from']!==''){$where[]='a.created_at>=?';$params[]=$f['from'].' 00:00:00';}
    if($f['to']!==''){$where[]='a.created_at<=?';$params[]=$f['to'].' 23:59:59';}
    if($f['action']!==''){$where[]='a.action=?';$params[]=$f['action'];}
    if($f['account']!==''){$where[]="u.email LIKE ? ESCAPE '!'";$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$f['account']).'%';}
    if($f['before']!==''){$where[]='a.id<?';$params[]=(int)$f['before'];}
    return [implode(' AND ',$where),$params];
}

/** Never select details, even temporarily. Bound malformed legacy cells in SQL. */
function admin_audit_select(array $f,int $limit): PDOStatement
{
    [$where,$params]=admin_audit_where($f);$fields=['a.id AS audit_id'];$large=[];
    foreach(['a.created_at'=>'created_at_utc','u.email'=>'account_email','a.action'=>'action'] as $column=>$alias){
        $test='length(CAST('.$column.' AS BLOB))>'.ADMIN_AUDIT_CELL_BYTES;$large[]=$test;
        $fields[]='CASE WHEN '.$test.' THEN NULL ELSE '.$column.' END AS '.$alias;
    }
    $fields[]='CASE WHEN '.implode(' OR ',$large).' THEN 1 ELSE 0 END AS oversized';
    $q=db()->prepare('SELECT '.implode(',',$fields).' FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE '.$where.' ORDER BY a.id DESC LIMIT '.max(1,min(ADMIN_AUDIT_EXPORT_ROWS+1,$limit)));
    $q->execute($params);return $q;
}
function admin_audit_row(array $row): array
{
    if(!empty($row['oversized']))throw new LengthException('Gespeicherte Metadatenzelle größer als 64 KiB. Keine Teilausgabe erstellt.');
    $out=[];
    foreach(ADMIN_AUDIT_FIELDS as $field){
        $value=$row[$field] ?? null;
        if(is_string($value) && strlen($value)>ADMIN_AUDIT_CELL_BYTES)throw new LengthException('Gespeicherte Metadatenzelle größer als 64 KiB. Keine Teilausgabe erstellt.');
        $out[$field]=$value;
    }
    json_encode($out,JSON_THROW_ON_ERROR);return $out;
}
function admin_audit_csv_cell(string $value): string
{
    if(preg_match('/^[\p{Z}\p{Cc}\p{Cf}]*[=+@\-＝＋－＠]/u',$value) || preg_match('/^[\p{Z}\p{Cf}]*[\x00-\x1f\x7f]/u',$value))$value="'".$value;
    return '"'.str_replace('"','""',$value).'"';
}
function admin_audit_csv(PDOStatement $q): string
{
    $out=implode(',',array_map('admin_audit_csv_cell',ADMIN_AUDIT_FIELDS))."\r\n";$count=0;
    try{
        while($row=$q->fetch(PDO::FETCH_ASSOC)){
            if(++$count>ADMIN_AUDIT_EXPORT_ROWS)throw new LengthException('Mehr als 5000 Treffer. Filter enger wählen. Keine Teildatei erstellt.');
            $row=admin_audit_row($row);$out.=implode(',',array_map(fn($v)=>admin_audit_csv_cell((string)($v ?? '')),$row))."\r\n";
            if(strlen($out)>ADMIN_AUDIT_EXPORT_BYTES)throw new LengthException('Export größer als 4 MiB. Filter enger wählen. Keine Teildatei erstellt.');
        }
    }finally{$q->closeCursor();}
    return $out;
}
function admin_audit_query(array $f): array
{
    return array_filter(array_intersect_key($f,array_flip(['from','to','action','account'])),fn($v)=>$v!=='');
}

/** Private read-only metadata list and complete bounded filtered CSV. */
function route_admin_audit(string $method,bool $export=false): never
{
    $u=require_platform_admin();header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    if($method!=='GET'){header('Allow: GET');http_response_code(405);exit;}
    try{
        $f=admin_audit_filters($_GET,$export);
        if($export){
            $out=admin_audit_csv(admin_audit_select($f,ADMIN_AUDIT_EXPORT_ROWS+1));
            header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="admin-audit-'.gmdate('Y-m-d').'.csv"');echo $out;exit;
        }
        $q=admin_audit_select($f,101);try{$rows=array_map('admin_audit_row',$q->fetchAll(PDO::FETCH_ASSOC));}finally{$q->closeCursor();}
        $more=count($rows)>100;$rows=array_slice($rows,0,100);
    }catch(InvalidArgumentException|LengthException|JsonException $e){
        http_response_code(422);$message=$e instanceof JsonException?'Gespeicherte Metadaten sind nicht gültiges UTF-8. Keine Datei erstellt.':$e->getMessage();
        page('Protokollfilter prüfen','<h1>Protokollfilter prüfen</h1><p>'.h($message).'</p><p><a href="/admin/audit">Filter zurücksetzen</a></p>',$u);exit;
    }
    $body='<p><a href="/admin">← Admin-Dashboard</a></p><h1>Aktivitätsprotokoll</h1><p>Nur vorhandene Protokolleinträge, neueste ID zuerst, maximal 100 je Seite. Keine lückenlose Sicherheits- oder Änderungshistorie. Gespeicherte Detaildaten werden hier nicht angezeigt. Konto zeigt die heutige E-Mail, keinen historischen Identitätsnachweis.</p><form method="get" class="form inline-form"><label>Von (UTC, inklusive)<input type="date" name="from" value="'.h($f['from']).'"></label><label>Bis (UTC, inklusive)<input type="date" name="to" value="'.h($f['to']).'"></label><label>Aktion exakt<input name="action" maxlength="120" placeholder="support.partner_state" value="'.h($f['action']).'"></label><label>Konto enthält<input name="account" maxlength="254" value="'.h($f['account']).'"></label><button class="btn">Filtern</button><a href="/admin/audit">Zurücksetzen</a></form>';
    $body.='<section class="panel admin-audit-export"><h2>Gefilterte Metadaten als CSV</h2><p>Alle Treffer der Filter, nicht nur diese 100er-Seite. Maximal 5000 Zeilen und 4 MiB; sonst keine Teildatei.</p><a class="btn ghost" href="/admin/audit/export?'.h(http_build_query(admin_audit_query($f))).'">CSV herunterladen</a><p class="muted">Enthält private Konto-E-Mails. Datei geschützt aufbewahren.</p><details><summary>Inhalt und Grenzen</summary><p class="muted">Vier Felder: ID, gespeicherter UTC-Zeitwert, heutige Konto-E-Mail und Aktionscode. Keine JSON-Details. Fehlende Konten bleiben im CSV leer. Maximal 64 KiB je Metadatenzelle; sonst keine Teildatei. Zeitraumfilter vergleichen wie bisher gespeicherte Zeittexte; fehlerhafte Altdaten werden dadurch nicht geprüft oder repariert. Riskante Textanfänge erhalten einen Apostroph; keine universelle Tabellenprogramm-Garantie.</p></details></section>';
    $body.='<p>'.count($rows).' Einträge auf dieser Seite'.($f['before']!==''?' · ältere Folgeseite':'').'.</p><div class="table-wrap audit-table"><table><thead><tr><th>ID / UTC</th><th>Konto</th><th>Aktion</th></tr></thead><tbody>';
    foreach($rows as $row)$body.='<tr data-audit-id="'.(int)$row['audit_id'].'"><td data-label="ID / UTC">'.(int)$row['audit_id'].'<br>'.h($row['created_at_utc']).'</td><td data-label="Konto">'.h($row['account_email'] ?? 'Konto nicht mehr vorhanden').'</td><td data-label="Aktion">'.h($row['action']).'</td></tr>';
    if(!$rows)$body.='<tr><td colspan="3">Keine passenden Einträge.</td></tr>';
    $body.='</tbody></table></div>';
    if($more){$next=admin_audit_query($f)+['before'=>(string)end($rows)['audit_id']];$body.='<p><a class="btn ghost" href="/admin/audit?'.h(http_build_query($next)).'">Ältere 100</a></p>';}
    page('Aktivitätsprotokoll',$body,$u);exit;
}
