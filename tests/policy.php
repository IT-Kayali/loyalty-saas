<?php
declare(strict_types=1);
// Run only in the disposable test install created by run-policy.py.
require getcwd().'/public/index.php';
$count=0;
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;$GLOBALS['count']++;}
function blocked(callable $write,string $label):void {
    try {$write();throw new RuntimeException('NOT BLOCKED: '.$label);}catch(PDOException $e){check(str_contains($e->getMessage(),'PLAN_RESTRICTED'),$label);}
}
$db=db();
blocked(fn()=> $db->exec("UPDATE cards SET stamps=stamps+1 WHERE id=1"),'inactive plan blocks card write at DB boundary');
blocked(fn()=> $db->exec("INSERT INTO members(business_id,name,code)VALUES(1,'Blocked','AAAA111111')"),'inactive plan blocks member create');
$db->exec("INSERT INTO subscriptions(user_id,status,plan_key)VALUES(1,'active','starter')");
$db->exec("UPDATE cards SET stamps=stamps WHERE id=1");check(true,'active plan permits card operation');
blocked(fn()=> $db->exec("INSERT INTO businesses(user_id,name)VALUES(1,'Extra')"),'business quantity cap');
blocked(fn()=> $db->exec("INSERT INTO programs(business_id,type,name)VALUES(1,'stamps','Extra')"),'program quantity cap');
blocked(fn()=> $db->exec("INSERT INTO members(business_id,name,code)VALUES(1,'Extra','AAAA222222')"),'member quantity cap');
blocked(fn()=> $db->exec("INSERT INTO staff(business_id,name,pin_hash)VALUES(1,'Extra','hash')"),'staff quantity cap');
blocked(fn()=> $db->exec("INSERT INTO campaigns(business_id,segment_id,name,subject,body)VALUES(1,1,'Blocked','test','test')"),'disabled campaign feature');
check(plan_policy(1,'api')!==null,'disabled API feature denied');
check(plan_policy(1,'designer')===null,'unlisted feature allowed for compatible custom plans');
$db->exec("UPDATE subscriptions SET status='past_due' WHERE user_id=1");
blocked(fn()=> $db->exec("UPDATE businesses SET name='Blocked' WHERE id=1"),'failed renewal blocks business changes');
blocked(fn()=> $db->exec("DELETE FROM staff WHERE id=1"),'inactive plan blocks staff deletion');
$GLOBALS['privacy_delete_authorized']=true;
$db->exec('DELETE FROM members WHERE id=1');
check((int)$db->query('SELECT COUNT(*) FROM members WHERE id=1')->fetchColumn()===0,'privacy deletion and cascades still allowed');
$GLOBALS['privacy_delete_authorized']=false;
$db->exec("UPDATE subscriptions SET status='active',plan_key='pro' WHERE user_id=1");
$db->exec("INSERT INTO businesses(user_id,name)VALUES(1,'Allowed')");check(true,'upgrade permits new company');
for($i=0;$i<3;$i++)check(rate_limit_take('unit','address',3,900,1800000000),'rate counter allowed attempt '.($i+1));
check(!rate_limit_take('unit','address',3,900,1800000000),'rate counter blocks fourth');
check(rate_limit_take('unit','address',3,900,1800000900),'rate counter resets next window');
echo $count.' assertions passed'.PHP_EOL;
