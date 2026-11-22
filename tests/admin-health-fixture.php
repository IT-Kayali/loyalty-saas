<?php
function health_fixture(int $now):void {
 $d=db();$d->exec('DELETE FROM mail_outbox;DELETE FROM webhook_deliveries;DELETE FROM webhooks');
 $date=fn($offset)=>gmdate('Y-m-d H:i:s',$now+$offset);
 $rows=[['queued',$date(-1200),null],['queued',$date(-60),null],['queued',$date(60),null],['queued','not-a-time',null],['queued','',null],['sending',$date(-1200),$date(-601)],['sending',$date(-1200),$date(-600)],['sending',$date(-1200),null],['sending',$date(-1200),$date(60)],['sending',$date(-1200),'invalid-claim'],['unknown',$date(-1200),null],['sent',$date(-1200),null],['skipped',$date(-1200),null],['PRIVATE-STATUS<script>',$date(-1200),null]];
 $q=$d->prepare("INSERT INTO mail_outbox(business_id,recipient,subject,body,category,dedup_key,status,created_at,claimed_at,last_error)VALUES(1,'PRIVATE-RECIPIENT@local.invalid','PRIVATE-SUBJECT','PRIVATE-BODY','transactional',?,?,?,?, 'PRIVATE-MAIL-ERROR')");
 foreach($rows as $i=>$r)$q->execute(['health-fixture-'.$i,$r[0],$r[1],$r[2]]);
 $d->exec("INSERT INTO webhooks(business_id,url,events,secret)VALUES(1,'https://PRIVATE-URL.invalid','member.joined','PRIVATE-SECRET')");$id=(int)$d->lastInsertId();
 $rows=[['pending',null,null],['pending',null,$date(-1)],['failed',$date(0),null],['failed',$date(0),$date(-1)],['failed',$date(0),$date(0)],['failed',$date(-60),$date(60)],['failed',$date(60),null],['failed',null,null],['failed','invalid-next',null],['failed',$date(0),'invalid-lock'],['dead',null,null],['delivered',null,$date(-1)],['PRIVATE-STATUS<script>',null,null]];
 $q=$d->prepare("INSERT INTO webhook_deliveries(webhook_id,event,payload,response,status,next_attempt_at,lock_until,delivered_at)VALUES(?,'PRIVATE-EVENT','PRIVATE-PAYLOAD','PRIVATE-RESPONSE',?,?,?,?)");
 foreach($rows as $r)$q->execute([$id,$r[0],$r[1],$r[2],$date(-1200)]);
}
function health_state():string {
 $all=[];foreach(['mail_outbox','webhook_deliveries','wallet_push_deliveries','audit_logs','platform_settings','pass_devices'] as $table)$all[$table]=db()->query('SELECT * FROM '.$table)->fetchAll();return json_encode($all);
}
