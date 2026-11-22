<?php
declare(strict_types=1);
const SUPPORT_LIST_EXPORT_ROWS=5000;
const SUPPORT_LIST_EXPORT_BYTES=4*1024*1024;
const SUPPORT_LIST_FIELDS=['reference','subject','topic','status','priority','updated_at_utc','channel','requester_name','assigned_name'];

/** Parse only applicable filters. Invalid applicable input must never silently widen the result. */
function support_list_filters(array $input,bool $admin,bool $export=false): array
{
    if($export){
        foreach(array_keys($input) as $key)if(!in_array($key,['q','status','topic','priority','assignee','channel','format'],true))throw new InvalidArgumentException('Unbekannter Exportparameter. Der Export umfasst alle gefilterten Treffer, keinen Seiten-Cursor.');
    }
    $text=function(string $key)use($input):string{
        $v=array_key_exists($key,$input)?$input[$key]:'';
        if(!is_string($v) || preg_match('//u',$v)!==1 || preg_match('/[\p{Cc}\p{Cf}]/u',$v))throw new InvalidArgumentException('Filter müssen gültiger Text ohne Steuerzeichen sein.');
        return $v;
    };
    $integer=function(string $v):string{
        if(!preg_match('/^[0-9]+$/D',$v))throw new InvalidArgumentException('Seiten-Cursor und Zuständigkeit müssen nichtnegative ganze Zahlen sein.');
        $v=ltrim($v,'0');if($v==='')return '0';$max=(string)PHP_INT_MAX;
        if(strlen($v)>strlen($max) || (strlen($v)===strlen($max) && strcmp($v,$max)>0))throw new InvalidArgumentException('Zahlenfilter ist zu groß.');
        return $v;
    };
    $f=['q'=>trim($text('q')),'status'=>$text('status'),'topic'=>$text('topic'),'priority'=>'','assignee'=>'','channel'=>'','after'=>0];
    if(mb_strlen($f['q'])>100)throw new InvalidArgumentException('Suchtext darf höchstens 100 Zeichen haben.');
    foreach(['status'=>SUPPORT_STATUS,'topic'=>SUPPORT_TOPICS] as $key=>$allowed)if($f[$key]!=='' && !isset($allowed[$f[$key]]))throw new InvalidArgumentException('Unbekannter Status oder unbekanntes Thema.');
    if($admin){
        foreach(['priority'=>SUPPORT_PRIORITY,'channel'=>SUPPORT_CHANNELS] as $key=>$allowed){$f[$key]=$text($key);if($f[$key]!=='' && !isset($allowed[$f[$key]]))throw new InvalidArgumentException('Unbekannte Priorität oder unbekannter Eingangskanal.');}
        $f['assignee']=$text('assignee');if($f['assignee']!=='')$f['assignee']=$integer($f['assignee']);
    }
    if(!$export && array_key_exists('after',$input) && $input['after']!=='')$f['after']=(int)$integer($text('after'));
    return $f;
}

/** One predicate for list and export, including literal LIKE escaping and partner ownership. */
function support_list_where(array $f,bool $admin,int $userId): array
{
    $where='t.deleted_at IS NULL AND t.id>?';$params=[$f['after']];
    if(!$admin){$where.=" AND t.user_id=? AND t.channel='partner'";$params[]=$userId;}
    foreach(['status','topic'] as $key)if($f[$key]!==''){$where.=' AND t.'.$key.'=?';$params[]=$f[$key];}
    if($admin){
        foreach(['priority','channel'] as $key)if($f[$key]!==''){$where.=' AND t.'.$key.'=?';$params[]=$f[$key];}
        if($f['assignee']!==''){$where.=' AND COALESCE(t.assignee,0)=CAST(? AS INTEGER)';$params[]=(int)$f['assignee'];}
    }
    if($f['q']!==''){
        $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$f['q']).'%';
        $cols=$admin?['t.reference','t.subject','t.requester_name','t.requester_email',"COALESCE(a.name,'Nicht zugewiesen')"]:['t.reference','t.subject'];
        $where.=' AND ('.implode(' OR ',array_map(fn($c)=>$c." LIKE ? ESCAPE '!'",$cols)).')';foreach($cols as $_)$params[]=$like;
    }
    return [$where,$params];
}
function support_list_filter_query(array $f): array
{
    return array_filter(array_intersect_key($f,array_flip(['q','status','topic','priority','assignee','channel'])),fn($v)=>$v!=='');
}

/** Always quote fields. Protective apostrophe changes risky text; use JSON for original values. */
function support_list_cell(string $value): string
{
    if(preg_match('/^[\p{Z}\p{Cc}\p{Cf}]*[=+@\-＝＋－＠]/u',$value) || preg_match('/^[\p{Z}\p{Cf}]*[\x00-\x1f\x7f]/u',$value))$value="'".$value;
    return '"'.str_replace('"','""',$value).'"';
}
function support_list_encode(array $rows,string $format): string
{
    if(!in_array($format,['csv','tsv','json'],true))throw new InvalidArgumentException('Unbekanntes Exportformat.');
    if(count($rows)>SUPPORT_LIST_EXPORT_ROWS)throw new LengthException('Mehr als 5000 Treffer. Filter enger wählen. Keine Teildatei erstellt.');
    // Project again here so a future query change cannot add private fields to JSON.
    $rows=array_map(function(array $row):array{$safe=[];foreach(SUPPORT_LIST_FIELDS as $key)$safe[$key]=$row[$key] ?? null;return $safe;},$rows);
    // Validate all text without replacement in every format. JSON also preserves exact raw values.
    $json=json_encode(['format'=>'loyalty-support-inbox','version'=>1,'exported_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'row_count'=>count($rows),'row_limit'=>SUPPORT_LIST_EXPORT_ROWS,'byte_limit'=>SUPPORT_LIST_EXPORT_BYTES,'truncated'=>false,'tickets'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if($format==='json')$out=$json;
    else{
        $separator=$format==='tsv'?"\t":',';
        $out=implode($separator,array_map('support_list_cell',SUPPORT_LIST_FIELDS))."\r\n";
        foreach($rows as $row){$cells=[];foreach(SUPPORT_LIST_FIELDS as $key)$cells[]=support_list_cell((string)($row[$key] ?? ''));$out.=implode($separator,$cells)."\r\n";if(strlen($out)>SUPPORT_LIST_EXPORT_BYTES)break;}
    }
    if(strlen($out)>SUPPORT_LIST_EXPORT_BYTES)throw new LengthException('Export größer als 4 MiB. Filter enger wählen. Keine Teildatei erstellt.');
    return $out;
}
function route_support_list_export(string $method): never
{
    $u=require_platform_admin();header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    if($method!=='GET'){http_response_code(405);header('Allow: GET');exit;}
    try{
        $f=support_list_filters($_GET,true,true);$format=$_GET['format'] ?? 'csv';
        if(!is_string($format) || !in_array($format,['csv','tsv','json'],true))throw new InvalidArgumentException('Exportformat muss CSV, TSV oder JSON sein.');
        [$where,$params]=support_list_where($f,true,(int)$u['id']);
        // One SELECT is one consistent read snapshot, including current assignee names. No message join.
        $q=db()->prepare('SELECT t.reference,t.subject,t.topic,t.status,t.priority,t.updated_at AS updated_at_utc,t.channel,t.requester_name,a.name AS assigned_name FROM support_tickets t LEFT JOIN users a ON a.id=t.assignee WHERE '.$where.' ORDER BY t.id LIMIT '.(SUPPORT_LIST_EXPORT_ROWS+1));$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        $out=support_list_encode($rows,$format);
    }catch(InvalidArgumentException|LengthException|JsonException $e){
        http_response_code(422);$message=$e instanceof JsonException?'Gespeicherter Text ist nicht gültiges UTF-8. Keine Datei erstellt.':$e->getMessage();page('Supportexport nicht erstellt','<h1>Supportexport nicht erstellt</h1><p>'.h($message).'</p><p><a href="/admin/support">Zur Support-Inbox</a></p>',$u);exit;
    }
    header('Content-Type: '.(['csv'=>'text/csv','tsv'=>'text/tab-separated-values','json'=>'application/json'][$format]).'; charset=utf-8');header('Content-Disposition: attachment; filename="support-inbox-'.gmdate('Y-m-d').'.'.$format.'"');echo $out;exit;
}
