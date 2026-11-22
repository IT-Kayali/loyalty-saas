<?php
function webhook_list_fixture(int $count=54): array
{
    $d=db();$d->exec('DELETE FROM webhook_deliveries;DELETE FROM webhooks');$owner=(int)$d->query("SELECT id FROM users WHERE email='demo@loyalty.local'")->fetchColumn();
    $q=$d->prepare('INSERT OR IGNORE INTO users(name,email,password_hash)VALUES(?,?,?)');$q->execute(['Other','other@metadata.invalid',password_hash('otherpass1',PASSWORD_DEFAULT)]);$other=(int)$d->query("SELECT id FROM users WHERE email='other@metadata.invalid'")->fetchColumn();
    $q=$d->prepare('SELECT id FROM businesses WHERE user_id=? LIMIT 1');$q->execute([$other]);$foreign=(int)$q->fetchColumn();if(!$foreign){$d->prepare('INSERT INTO businesses(user_id,name)VALUES(?,?)')->execute([$other,'PRIVATE-FOREIGN-BUSINESS']);$foreign=(int)$d->lastInsertId();}
    $q=$d->prepare('INSERT INTO webhooks(business_id,url,events,secret)VALUES(?,?,?,?)');$ids=[];foreach([1,1,2,$foreign] as $bid){$q->execute([$bid,'https://PRIVATE-URL.invalid/target','card.points','PRIVATE-HOOK-SECRET']);$ids[]=(int)$d->lastInsertId();}
    $q=$d->prepare('INSERT INTO webhook_deliveries(webhook_id,event,payload,status,response,attempts,delivered_at,next_attempt_at)VALUES(?,?,?,?,?,?,?,?)');$d->exec('BEGIN');
    for($i=1;$i<=$count;$i++)$q->execute([$ids[0],'card.points','PRIVATE-PAYLOAD','failed','PRIVATE-RESPONSE',2,'2026-11-21 23:59:59','2026-11-22 12:00:00']);
    foreach([
        [$ids[0],'card.points','delivered','2026-11-22 00:00:00',null],
        [$ids[0],'card.points','dead','2026-11-22 23:59:59',null],
        [$ids[0],'card.points','pending',null,null],
        [$ids[0],'card.points','failed','2026-02-30 12:00:00',null],
        [$ids[0],'card.points','failed','2026-11-22T12:00:00',null],
        [$ids[1],'card.points','failed','2026-11-21 12:00:00',null],
        [$ids[0],'%_!','failed','2026-11-22 12:00:00',null],
        [$ids[0],"=1+2,\"quoted\"\r\nzweite Zeile",'PRIVATE-RAW-STATUS<script>','2026-11-22 12:00:00',null],
        [$ids[2],'PRIVATE-SECOND-BUSINESS','failed','2026-11-22 12:00:00',null],
        [$ids[3],'PRIVATE-FOREIGN-EVENT','failed','2026-11-22 12:00:00',null],
    ] as [$hook,$event,$status,$at,$next])$q->execute([$hook,$event,'PRIVATE-PAYLOAD',$status,'PRIVATE-RESPONSE',1,$at,$next]);
    $d->exec('COMMIT');return ['owner'=>$owner,'other'=>$other,'foreign_business'=>$foreign,'hooks'=>$ids];
}
function webhook_list_state(): array
{
    $state=[];foreach(['webhooks','webhook_deliveries','mail_outbox','audit_logs','platform_settings','wallet_push_deliveries','pass_devices'] as $table)$state[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();return $state;
}
