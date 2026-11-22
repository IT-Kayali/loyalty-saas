<?php
require 'public/index.php';require 'fixture.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$n=0;function yes($v,$s){global $n;if(!$v)throw new RuntimeException($s);$n++;echo "PASS $s\n";}
function rejects(callable $f,string $s,string $class=InvalidArgumentException::class){try{$f();}catch(Throwable $e){yes($e instanceof $class,$s);return;}throw new RuntimeException($s.' accepted');}
$f=webhook_list_filters([]);yes($f===['hook'=>'','event'=>'','status'=>'','from'=>'','to'=>'','before'=>''],'safe empty filter shape');
$f=webhook_list_filters(['hook'=>'0002','before'=>'0007','event'=>'%_!','status'=>'failed','from'=>'2024-02-29','to'=>'2024-02-29']);yes($f['hook']==='2'&&$f['before']==='7'&&$f['event']==='%_!','valid IDs canonicalized, event literal and leap day accepted');
yes(!array_key_exists('before',webhook_list_query($f))&&webhook_list_query($f)['event']==='%_!','export/pagination common query omits page cursor');
foreach(['hook','event','status','from','to','before','lang'] as $key)foreach([[],null,false,1] as $value)rejects(fn()=>webhook_list_filters([$key=>$value]),'nontext '.$key.' rejected');
foreach([['hook'=>'0'],['hook'=>'-1'],['hook'=>'1e2'],['before'=>'0'],['before'=>'1.0'],['before'=>str_repeat('9',25)],['event'=>str_repeat('ä',101)],['event'=>"\xff"],['event'=>"line\nline"],['event'=>"hidden\u{200b}"],['status'=>'retrying'],['from'=>'2026-02-30'],['to'=>'2026-13-01'],['to'=>'2026-1-01'],['from'=>'0000-01-01'],['from'=>'2026-11-22','to'=>'2026-11-21'],['lang'=>'xx'],['action'=>'retry']] as $input)rejects(fn()=>webhook_list_filters($input),'malformed/unknown filter rejected');
rejects(fn()=>webhook_list_filters(['before'=>'1'],true),'export refuses cursor instead of silently exporting suffix');
yes(webhook_list_filters(['hook'=>(string)PHP_INT_MAX,'event'=>str_repeat('ä',100)])['hook']===(string)PHP_INT_MAX,'exact integer and Unicode lengths accepted');
$info=webhook_list_fixture();$before=webhook_list_state();
$get=function(array $input=[],int $business=1,?int $uid=null)use($info){$q=webhook_list_select(webhook_list_filters($input),$uid??$info['owner'],$business,5001);try{return array_map('webhook_list_row',$q->fetchAll(PDO::FETCH_ASSOC));}finally{$q->closeCursor();}};
$rows=$get();yes(count($rows)===62,'all local rows and unusual old statuses retained without foreign business rows');yes(array_keys($rows[0])===WEBHOOK_LIST_FIELDS,'independent fixed metadata projection');
yes(count($get(['event'=>'card.points','status'=>'failed','hook'=>(string)$info['hooks'][0]]))===56,'combined filters retain malformed-time rows without date filter');
yes(count($get(['event'=>'%_!']))===1,'event exact match treats percent underscore and exclamation literally');
yes(count($get(['from'=>'2026-11-22','to'=>'2026-11-22','event'=>'card.points']))===2,'inclusive UTC endpoints exclude null, malformed and noncanonical timestamps');
yes(count($get(['from'=>'2026-11-21','to'=>'2026-11-21','event'=>'card.points']))===55,'end-of-day seconds included and hooks combined');
yes($get([],1,$info['other'])===[]&&$get(['hook'=>(string)$info['hooks'][3]])===[],'SQL owner and business scope cannot be bypassed by hook ID');
yes(count($get([],2))===1&&$get([],2)[0]['event']==='PRIVATE-SECOND-BUSINESS','same owner business boundary remains exact');
yes(count($get(['before'=>(string)$rows[49]['delivery_id']]))===12,'descending cursor excludes prior page, leaves older twelve');
$out=webhook_list_csv(webhook_list_select(webhook_list_filters([]),$info['owner'],1,5001));yes(!str_contains($out,'PRIVATE-PAYLOAD')&&!str_contains($out,'PRIVATE-RESPONSE')&&!str_contains($out,'PRIVATE-HOOK-SECRET')&&!str_contains($out,'PRIVATE-URL')&&!str_contains($out,'PRIVATE-FOREIGN')&&!str_contains($out,'PRIVATE-SECOND'),'CSV omits private columns and other businesses');
yes(str_contains($out,'"\'=1+2,""quoted""'."\r\n".'zweite Zeile"'),'CSV quotes multiline cells and prefixes risky formulas');
foreach(['=1','+1','-1','@x','＝1','＋1','－1','＠x'," \t=1","\u{2003}+1","\u{200b}@x","\ttext","\rtext","\ntext"] as $v)yes(str_starts_with(webhook_list_cell($v),'"\''),'risky prefix protected');
yes(webhook_list_cell('plain,"text"')==='"plain,""text"""','ordinary text remains exact apart from CSV quote escaping');
yes(webhook_list_row(['delivery_id'=>1,'payload'=>'PRIVATE'])['event']===null,'row projection ignores unexpected private keys');
rejects(fn()=>webhook_list_row(['oversized'=>1]),'oversized metadata fails closed',LengthException::class);rejects(fn()=>webhook_list_row(['event'=>"\xff"]),'invalid stored UTF8 fails closed',JsonException::class);
yes(str_contains($out,'2026-02-30 12:00:00')&&str_contains($out,'2026-11-22T12:00:00'),'CSV preserves invalid legacy time strings for diagnosis instead of silently normalizing');
yes(webhook_list_state()===$before,'all reads leave queues audit settings devices and hooks untouched');
yes(webhook_list_time('2026-02-30 00:00:00','empty')==='Ungültig gespeichert'&&webhook_list_time('2026-11-22T12:00:00','empty')==='Ungültig gespeichert','display rejects normalized calendar mistakes and noncanonical times');
yes(webhook_list_time('2026-11-22 12:00:00','empty')==='2026-11-22 12:00:00'&&webhook_list_time(null,'empty')==='empty','display preserves valid UTC and explicit absent-time label');
rejects(fn()=>webhook_list_row(['event'=>str_repeat('x',65537)]),'independent row guard rejects oversize without SQL flag',LengthException::class);
$proof=new PDO('sqlite::memory:');$proof->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$proof->exec('CREATE TABLE metadata(delivery_id INTEGER,webhook_id INTEGER,event TEXT,status TEXT,attempts INTEGER,last_attempt_at_utc TEXT,next_attempt_at_utc TEXT)');
$q=$proof->prepare("INSERT INTO metadata VALUES(?,1,?,'failed',2,NULL,NULL)");for($i=1;$i<=70;$i++)$q->execute([$i,str_repeat('x',59000)]);
$getProof=fn()=>$proof->query('SELECT * FROM metadata ORDER BY delivery_id');$length=strlen(webhook_list_csv($getProof()));$room=WEBHOOK_LIST_BYTES-$length;yes($room>0&&$room<70*(65536-59000),'byte-limit fixture starts below limit with room inside valid cells');
for($i=1;$i<=70&&$room>0;$i++){$add=min($room,65536-59000);$proof->prepare('UPDATE metadata SET event=? WHERE delivery_id=?')->execute([str_repeat('x',59000+$add),$i]);$room-=$add;}
yes($room===0&&strlen(webhook_list_csv($getProof()))===WEBHOOK_LIST_BYTES,'exact4MiB encoded CSV is accepted');
$proof->exec("UPDATE metadata SET event=event||'x' WHERE delivery_id=70");$q=$getProof();rejects(fn()=>webhook_list_csv($q),'one byte above4MiB rejects before any partial file',LengthException::class);yes($q->fetch()===false,'export closes read cursor even after size failure');
echo "$n webhook list domain assertions passed\n";
