<?php
declare(strict_types=1);
require getcwd().'/public/index.php';
$n=0;function ok(bool $b,string $s):void{if(!$b)throw new RuntimeException($s);echo 'PASS '.$s.PHP_EOL;$GLOBALS['n']++;}
$db=db();$mid=(int)$db->query('SELECT id FROM members WHERE business_id=1 LIMIT 1')->fetchColumn();$email=(string)$db->query('SELECT email FROM members WHERE id='.$mid)->fetchColumn();
ok(!queue_mail(1,$mid,$email,'Offer','Text','marketing','test:no-consent'),'no marketing without opt-in');
$db->exec('UPDATE members SET marketing_consent=1 WHERE id='.$mid);
ok(queue_mail(1,$mid,$email,'Offer','Text','marketing','test:yes'),'consented email queued');ok(!queue_mail(1,$mid,$email,'Offer','Text','marketing','test:yes'),'outbox deduplication');
$captured=[];$stats=mail_worker(10,function($j)use(&$captured){$captured[]=$j;});ok($stats['sent']===1 && count($captured)===1,'worker submits once');
ok($db->query("SELECT body FROM mail_outbox WHERE dedup_key='test:yes'")->fetchColumn()==='','sent body removed');
mail_worker(10,function(){throw new Exception('must not run');});ok(count($captured)===1,'sent job not resubmitted');
queue_mail(1,$mid,$email,'Revoked','Text','marketing','test:revoked');$db->exec('UPDATE members SET marketing_consent=0 WHERE id='.$mid);$stats=mail_worker(10,function(){throw new Exception('must not run');});ok($stats['skipped']===1,'consent rechecked at delivery time');
queue_mail(null,null,'reset@example.test','Reset','Secret test text','transactional','test:reset');$stats=mail_worker(10,function(){throw new RuntimeException('transport ambiguous');});ok($stats['unknown']===1,'ambiguous SMTP outcome not called delivered');
$stats=mail_worker(10,function(){throw new Exception('must not run');});ok($stats['unknown']===0 && $stats['sent']===0,'unknown delivery not retried blindly');
$db->exec("INSERT INTO mail_automations(business_id,event,subject,body)VALUES(1,'welcome','Hello {name}','Welcome {name}')");
$db->exec('UPDATE members SET marketing_consent=1 WHERE id='.$mid);ok(automation_enqueue()===1,'eligible welcome queued');ok(automation_enqueue()===0,'welcome deduplicated across scheduler runs');
$db->exec("INSERT INTO mail_automations(business_id,event,subject,body)VALUES(1,'birthday','Birthday','Happy birthday')");$db->prepare('UPDATE members SET birthday=? WHERE id=?')->execute(['1999-'.gmdate('m-d'),$mid]);ok(automation_enqueue()===1,'birthday rule only for matching date');ok(automation_enqueue()===0,'birthday not repeated in same year');
$db->exec("INSERT INTO mail_automations(business_id,event,days,subject,body)VALUES(1,'winback',30,'We miss you','Come back')");$db->prepare("UPDATE members SET created_at='2000-01-01' WHERE id=?")->execute([$mid]);$db->exec("DELETE FROM transactions WHERE card_id IN(SELECT id FROM cards WHERE member_id=$mid)");ok(automation_enqueue()===1,'inactive member queued');ok(automation_enqueue()===0,'winback once per inactivity period');
$token=unsubscribe_token($mid);ok(strlen($token)===64 && hash_equals($token,unsubscribe_token($mid)),'stable unsubscription token');ok(!hash_equals($token,unsubscribe_token($mid+1)),'unsubscription bound to member');
echo $n.' assertions passed'.PHP_EOL;
