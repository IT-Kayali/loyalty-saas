<?php
declare(strict_types=1);
require 'public/index.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$n=0;function yes(bool $value,string $label): void {global $n;if(!$value)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$d=db();$d->exec('DELETE FROM maintenance_status');$now=strtotime('2026-11-22 13:00:00 UTC');
$s=maintenance_snapshot($now);yes(count($s['tasks'])===3&&$s['at']==='2026-11-22 13:00:00','exact fixed catalog and UTC snapshot');
foreach($s['tasks'] as $r)yes($r===['state'=>'missing','started_at'=>null,'finished_at'=>null],'missing receipt never invents a run');
foreach(['0001-01-01 00:00:00','2024-02-29 23:59:59','9999-12-31 23:59:59'] as $v)yes(maintenance_time($v),'canonical calendar accepted '.$v);
foreach([null,[],123,'','0000-01-01 00:00:00','2026-02-29 00:00:00','2026-11-22T00:00:00','2026-11-22 24:00:00',"2026-11-22 00:00:00\n",'2026-11-22 00:00:00Z',"2026-11-22\0 0:00:00"] as $v)yes(!maintenance_time($v),'noncanonical or invalid timestamp rejected');
$count=0;$value=maintenance_observe('mail_worker',function()use(&$count){$count++;return ['original'=>'result'];});
yes($value===['original'=>'result']&&$count===1,'observer returns original value, calls work once');$s=maintenance_snapshot();$r=$s['tasks']['mail_worker'];yes($r['state']==='completed'&&maintenance_time($r['started_at'])&&maintenance_time($r['finished_at']),'normal completion recorded');
yes(!str_contains(json_encode($s),'run_token'),'receipt token not selected for reporting');yes(count($d->query('SELECT * FROM maintenance_status')->fetchAll())===1,'one bounded row per task');
maintenance_observe('mail_worker',fn()=>null);yes((int)$d->query('SELECT count(*) FROM maintenance_status')->fetchColumn()===1,'repeat invocation replaces prior receipt rather than growing history');
$problem=new RuntimeException('PRIVATE-ORIGINAL-ERROR');try {maintenance_observe('points_expiry',function()use($problem){throw $problem;});throw new RuntimeException('missing rethrow');} catch(Throwable $e){yes($e===$problem,'original task exception identity preserved');}
yes(maintenance_snapshot()['tasks']['points_expiry']['state']==='failed','throwing task recorded failed');yes(!str_contains(json_encode($d->query('SELECT * FROM maintenance_status')->fetchAll()),'PRIVATE-ORIGINAL-ERROR'),'no exception details stored');
foreach(['','arbitrary','mail_worker/../../file'] as $task){$ran=false;try{maintenance_observe($task,function()use(&$ran){$ran=true;});}catch(InvalidArgumentException){}yes(!$ran,'unknown task cannot invoke work');}
maintenance_observe('webhook_retry',function()use($d){$d->prepare("UPDATE maintenance_status SET run_token=?,state='started',finished_at=NULL WHERE task='webhook_retry'")->execute([str_repeat('b',32)]);});
yes($d->query("SELECT state FROM maintenance_status WHERE task='webhook_retry'")->fetchColumn()==='started','older completion cannot close a newer token');
$d->exec('BEGIN IMMEDIATE');$before=$d->query('SELECT * FROM maintenance_status ORDER BY task')->fetchAll();$value=maintenance_observe('mail_worker',fn()=>17);yes($value===17,'receipt write lock does not stop or replace actual task result');$d->exec('COMMIT');yes($d->query('SELECT * FROM maintenance_status ORDER BY task')->fetchAll()===$before,'failed receipt start does not invent completion');
$value=maintenance_observe('mail_worker',function()use($d){$d->exec('BEGIN IMMEDIATE');return 23;});yes($value===23,'receipt finish failure does not override actual result');$d->exec('ROLLBACK');yes($d->query("SELECT state FROM maintenance_status WHERE task='mail_worker'")->fetchColumn()==='started','unrecorded finish remains honestly incomplete');
$put=function($state,$start,$end)use($d){$d->prepare("UPDATE maintenance_status SET state=?,started_at=?,finished_at=? WHERE task='mail_worker'")->execute([$state,$start,$end]);};
foreach([
 ['started','2026-11-22 12:00:00',null,'started'],
 ['completed','2026-11-22 12:00:00','2026-11-22 12:00:00','completed'],
 ['failed','2026-11-22 12:00:00','2026-11-22 13:00:00','failed'],
 ['started','2026-11-22 13:00:01',null,'invalid'],
 ['completed','2026-11-22 12:00:00','2026-11-22 13:00:01','invalid'],
 ['completed','2026-11-22 12:00:00','2026-11-22 11:59:59','invalid'],
 ['completed','2026-11-22 12:00:00',null,'invalid'],
 ['started','2026-11-22 12:00:00','2026-11-22 12:01:00','invalid'],
 ['started','PRIVATE-INVALID-TIME',null,'invalid'],
 ['completed','2026-02-30 00:00:00','2026-11-22 12:00:00','invalid'],
] as [$state,$start,$end,$expected]){$put($state,$start,$end);$r=maintenance_snapshot($now)['tasks']['mail_worker'];yes($r['state']===$expected,'exact state and time-integrity boundary');if($expected==='invalid')yes($r['started_at']===null&&$r['finished_at']===null,'bad raw timestamps excluded');}
$before=$d->query('SELECT * FROM maintenance_status ORDER BY task')->fetchAll();maintenance_snapshot($now);yes($d->query('SELECT * FROM maintenance_status ORDER BY task')->fetchAll()===$before,'snapshot performs no receipt repair');
$probe=new PDO('sqlite:'.config('db_path'));$probe->exec('PRAGMA busy_timeout=300;BEGIN IMMEDIATE;CREATE TABLE maintenance_probe(id INTEGER);ROLLBACK');yes(true,'read and logging transactions released');
yes(count($d->query('SELECT * FROM maintenance_status')->fetchAll())===3,'at most three registered receipts');
echo "$n maintenance domain assertions passed\n";
