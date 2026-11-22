<?php
function points_analytics_fixture(?DateTimeImmutable $now=null): array
{
    $db=db();$now=$now ?? new DateTimeImmutable('now',new DateTimeZone('UTC'));$w=points_analytics_window('last_month',$now);
    $db->prepare('INSERT OR IGNORE INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Other operator','other@points.invalid',password_hash('otherpass1',PASSWORD_DEFAULT)]);$other=(int)$db->query("SELECT id FROM users WHERE email='other@points.invalid'")->fetchColumn();
    $q=$db->prepare('SELECT id FROM businesses WHERE user_id=? LIMIT 1');$q->execute([$other]);$foreign=(int)$q->fetchColumn();if(!$foreign){$db->prepare('INSERT INTO businesses(user_id,name)VALUES(?,?)')->execute([$other,'PRIVATE-FOREIGN-BUSINESS']);$foreign=(int)$db->lastInsertId();}
    $program=function(int $bid,string $name)use($db):int{$q=$db->prepare("SELECT id FROM programs WHERE business_id=? AND type='points' AND name=?");$q->execute([$bid,$name]);$id=(int)$q->fetchColumn();if(!$id){$db->prepare("INSERT INTO programs(business_id,type,name)VALUES(?,'points',?)")->execute([$bid,$name]);$id=(int)$db->lastInsertId();}return $id;};
    $ids=[$program(1,'Punkteprogramm für handgemachte Prämien und wiederkehrende Besuche'),$program(1,'PRIVATE-SECOND-PROGRAM'),$program(2,'PRIVATE-SECOND-BUSINESS-PROGRAM'),$program($foreign,'PRIVATE-FOREIGN-PROGRAM')];
    $members=[];foreach([1,2,$foreign] as $i=>$bid){$q=$db->prepare('SELECT id FROM members WHERE business_id=? AND email=?');$email='PRIVATE-MEMBER-'.$bid.'@points.invalid';$q->execute([$bid,$email]);$mid=(int)$q->fetchColumn();if(!$mid){$db->prepare('INSERT INTO members(business_id,name,email,code)VALUES(?,?,?,?)')->execute([$bid,'PRIVATE-MEMBER-NAME',$email,strtoupper(substr(hash('sha256','points-fixture-'.$bid),0,10))]);$mid=(int)$db->lastInsertId();}$members[$bid]=$mid;}
    $cards=[];foreach($ids as $i=>$pid){$bid=$i<2?1:($i===2?2:$foreign);$db->prepare('INSERT OR IGNORE INTO cards(program_id,member_id)VALUES(?,?)')->execute([$pid,$members[$bid]]);$q=$db->prepare('SELECT id FROM cards WHERE program_id=? AND member_id=?');$q->execute([$pid,$members[$bid]]);$cards[]=(int)$q->fetchColumn();$db->prepare('DELETE FROM transactions WHERE card_id=?')->execute([end($cards)]);}
    $q=$db->prepare('INSERT INTO transactions(card_id,kind,amount,note,created_at,reversed_at)VALUES(?,?,?,?,?,?)');$start=new DateTimeImmutable($w['start'],new DateTimeZone('UTC'));$end=new DateTimeImmutable($w['end'],new DateTimeZone('UTC'));
    foreach([
        ['points_add',100,$w['start'],null],['points_add',50,$start->modify('+1 day')->setTime(12,0)->format('Y-m-d H:i:s'),null],
        ['points_redeem',60,$start->modify('+1 day')->setTime(12,0)->format('Y-m-d H:i:s'),null],['points_redeem',40,$end->modify('-1 second')->format('Y-m-d H:i:s'),null],
        ['points_add',999,$w['start'],'2026-01-01 00:00:00'],['points_redeem',999,$w['start'],'2026-01-01 00:00:00'],
        ['points_transfer_in',700,$w['start'],null],['points_transfer_out',-700,$w['start'],null],['points_expire',-300,$w['start'],null],['reversal',-100,$w['start'],null],['stamp_add',555,$w['start'],null],
        ['points_add',80,$w['previous_start'],null],['points_redeem',20,$w['previous_start'],null],
        ['points_add',777,(new DateTimeImmutable($w['previous_start'],new DateTimeZone('UTC')))->modify('-1 second')->format('Y-m-d H:i:s'),null],
        ['points_add',25,$w['end'],null],['points_add',888,$end->modify('+2 months')->format('Y-m-d H:i:s'),null],
    ] as [$kind,$amount,$at,$rev])$q->execute([$cards[0],$kind,$amount,'PRIVATE-TRANSACTION-NOTE',$at,$rev]);
    foreach(array_slice($cards,1) as $cid)$q->execute([$cid,'points_add',9000,'PRIVATE-OTHER-PROGRAM-NOTE',$w['start'],null]);
    return ['program'=>$ids[0],'programs'=>$ids,'cards'=>$cards,'members'=>$members,'foreign_user'=>$other,'foreign_business'=>$foreign,'window'=>$w];
}
function points_analytics_state(): array
{
    $state=[];foreach(db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table){$rows=db()->query('SELECT * FROM "'.str_replace('"','""',$table).'" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);$state[$table]=['count'=>count($rows),'hash'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];}return $state;
}
