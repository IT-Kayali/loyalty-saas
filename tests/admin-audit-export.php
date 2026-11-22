<?php
require 'public/index.php';require 'fixture.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$n=0;
function yes($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function rejects(callable $call,string $label,string $class=InvalidArgumentException::class){try{$call();}catch(Throwable $e){yes($e instanceof $class,$label);return;}throw new RuntimeException($label.' accepted');}
$f=admin_audit_filters([]);yes($f===['from'=>'','to'=>'','action'=>'','account'=>'','before'=>''],'existing empty filter shape');
$f=admin_audit_filters(['from'=>'2024-02-29','to'=>'2024-02-29','action'=>' fixture.export ','account'=>' %_! ','before'=>'123']);yes($f['account']==='%_!'&&$f['action']==='fixture.export'&&$f['before']==='123','leap day and existing surrounding-space normalization retained');
yes(!array_key_exists('before',admin_audit_query($f))&&count(admin_audit_query($f))===4,'export URL includes every effective filter, never cursor');
foreach(['from','to','action','account','before'] as $key)foreach([[],null,false,1,"\xff","bad\nvalue"] as $value)rejects(fn()=>admin_audit_filters([$key=>$value]),'invalid type, encoding or control rejected for '.$key);
foreach([['from'=>'2026-02-30'],['to'=>'2026-13-01'],['from'=>'2026-1-01'],['from'=>'2026-11-02','to'=>'2026-11-01'],['action'=>'a b'],['action'=>'a%'],['action'=>str_repeat('a',121)],['account'=>str_repeat('ä',255)],['before'=>'0'],['before'=>'01'],['before'=>'-1'],['before'=>'1.0'],['before'=>str_repeat('9',19)]] as $input)rejects(fn()=>admin_audit_filters($input),'malformed existing filter rejected');
yes(admin_audit_filters(['account'=>str_repeat('ä',254),'action'=>str_repeat('a',120),'before'=>str_repeat('9',18)])['before']===str_repeat('9',18),'exact previous filter length limits retained');
yes(admin_audit_filters(['legacy_unused'=>'ignored'])===admin_audit_filters([]),'legacy list still ignores unknown keys');
foreach(['before','format','business_id','user_id','callback','legacy_unused'] as $key)rejects(fn()=>admin_audit_filters([$key=>''],true),'download rejects unsupported selector '.$key);
foreach([[],null,'xx'] as $value)rejects(fn()=>admin_audit_filters(['lang'=>$value],true),'invalid optional export language rejected');
yes(admin_audit_filters(['lang'=>'en'],true)===admin_audit_filters([],true),'CSV data contract independent of supported UI locale');
$info=admin_audit_export_fixture();$before=admin_audit_export_state();
$get=function(array $input=[]){$q=admin_audit_select(admin_audit_filters($input),5001);try{return array_map('admin_audit_row',$q->fetchAll(PDO::FETCH_ASSOC));}finally{$q->closeCursor();}};
$rows=$get();yes(count($rows)===$info['rows']&&array_keys($rows[0])===ADMIN_AUDIT_FIELDS,'four fixed metadata fields only across all platform actors');
$ids=array_column($rows,'audit_id');$sorted=$ids;rsort($sorted);yes($ids===$sorted,'descending stable IDs, not event-time sorting');
yes(count($get(['action'=>'fixture.export','account'=>'DEMO@LOYALTY.LOCAL','from'=>'2026-11-01','to'=>'2026-11-01']))===205,'combined exact action, current-account and inclusive UTC filters');
yes(count($get(['action'=>'fixture.boundary','from'=>'2026-11-01','to'=>'2026-11-01']))===2,'both existing UTC-day endpoints included');
yes(count($get(['action'=>'fixture.literal','account'=>'%_!']))===1,'percent underscore exclamation in account search are literal');
yes($get(['account'=>"' OR 1=1 --"])===[],'account SQL-looking text remains a bound value');
yes(count($get(['before'=>(string)$rows[99]['audit_id']]))===$info['rows']-100,'list cursor still limits only older pages');
yes($get(['action'=>'fixture.missing_actor'])[0]['account_email']===null,'deleted actor stays null, not invented system identity');
$out=admin_audit_csv(admin_audit_select(admin_audit_filters([],true),5001));yes(!str_contains($out,'PRIVATE-JSON-DETAILS')&&!str_contains($out,'PRIVATE-LARGE-DETAILS'),'neither small nor megabyte detail payloads selected or encoded');
yes(str_contains($out,'"\'=1+2,""quoted""'."\r\n".'second line"'),'formula prefix and multiline comma/quote CSV encoded safely');
yes(str_contains($out,'2026-11-01T12:00:00')&&str_contains($out,'not-a-time'),'legacy time values preserved without false normalization');
foreach(['=1','+1','-1','@x','＝1','＋1','－1','＠x'," \t=1","\u{2003}+1","\u{200b}@x","\ttext","\rtext","\ntext","\x00text"] as $v)yes(str_starts_with(admin_audit_csv_cell($v),'"\''),'risky spreadsheet prefix gets apostrophe');
yes(admin_audit_csv_cell('plain,"text"')==='"plain,""text"""','ordinary values retain characters with CSV quote escaping');
yes(admin_audit_row(['audit_id'=>1,'details'=>'PRIVATE'])===['audit_id'=>1,'created_at_utc'=>null,'account_email'=>null,'action'=>null],'projection ignores unexpected private keys');
rejects(fn()=>admin_audit_row(['oversized'=>1]),'SQL oversized flag fails closed',LengthException::class);
rejects(fn()=>admin_audit_row(['action'=>str_repeat('x',65537)]),'independent 64KiB row guard fails closed',LengthException::class);
rejects(fn()=>admin_audit_row(['action'=>"\xff"]),'invalid stored UTF8 fails closed',JsonException::class);
yes(admin_audit_row(['action'=>str_repeat('x',65536)])['action']===str_repeat('x',65536),'exact 64KiB metadata cell allowed');
yes(admin_audit_export_state()===$before,'all domain list/export reads leave every application table unchanged');
// Small synthetic metadata table tests byte accounting without large private data.
$proof=new PDO('sqlite::memory:');$proof->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$proof->exec('CREATE TABLE metadata(audit_id INTEGER,created_at_utc TEXT,account_email TEXT,action TEXT)');
$q=$proof->prepare("INSERT INTO metadata VALUES(?,'2026-11-01 12:00:00','owner@example.invalid',?)");for($i=1;$i<=70;$i++)$q->execute([$i,str_repeat('x',59000)]);
$getProof=fn()=>$proof->query('SELECT * FROM metadata ORDER BY audit_id');$room=ADMIN_AUDIT_EXPORT_BYTES-strlen(admin_audit_csv($getProof()));yes($room>0&&$room<70*(65536-59000),'byte fixture starts below limit with room within valid cells');
for($i=1;$i<=70&&$room>0;$i++){$add=min($room,65536-59000);$proof->prepare('UPDATE metadata SET action=? WHERE audit_id=?')->execute([str_repeat('x',59000+$add),$i]);$room-=$add;}
yes($room===0&&strlen(admin_audit_csv($getProof()))===ADMIN_AUDIT_EXPORT_BYTES,'exact4MiB encoded CSV accepted');
$proof->exec("UPDATE metadata SET action=action||'x' WHERE audit_id=70");$q=$getProof();rejects(fn()=>admin_audit_csv($q),'one byte over4MiB rejects entire CSV',LengthException::class);yes($q->fetch()===false,'size failure closes cursor');
$proof->exec('DELETE FROM metadata');$q=$proof->prepare("INSERT INTO metadata VALUES(?,NULL,NULL,'fixture.limit')");for($i=1;$i<=5000;$i++)$q->execute([$i]);
yes(substr_count(admin_audit_csv($getProof()),"\r\n")===5001,'exact5000 metadata rows accepted');$q->execute([5001]);$q=$getProof();rejects(fn()=>admin_audit_csv($q),'exact5001 refuses partial CSV',LengthException::class);yes($q->fetch()===false,'row-count failure closes cursor');
$proof->exec('DELETE FROM metadata');yes(admin_audit_csv($getProof())===implode(',',array_map('admin_audit_csv_cell',ADMIN_AUDIT_FIELDS))."\r\n",'empty result is exact header-only CSV');
echo "$n admin audit export domain assertions passed\n";
