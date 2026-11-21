<?php
declare(strict_types=1);
function route_offline_snapshot(): never
{
    $email=current_member_email();if(!$email)api_json(['error'=>'Anmeldung erforderlich'],401);
    $cards=[];foreach(member_rows_by_email($email) as $member){$q=db()->prepare('SELECT c.*,p.name AS program,p.type,p.stamps_required FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=? AND p.active=1 ORDER BY c.id');$q->execute([$member['id']]);foreach($q->fetchAll() as $c){if(count($cards)>=30)break 2;$cards[]=['business'=>$member['business_name'],'program'=>$c['program'],'type'=>$c['type'],'code'=>$member['code'],'points'=>(int)$c['points'],'stamps'=>(int)$c['stamps'],'stamps_required'=>(int)$c['stamps_required'],'pending_rewards'=>(int)$c['pending_rewards'],'remaining'=>$c['type']==='voucher'?(int)$c['uses_left']:(int)$c['visits_left']];}}
    header('Cache-Control: private, no-store');api_json(['version'=>1,'account'=>hash('sha256',strtolower($email)),'saved_at'=>gmdate(DATE_ATOM),'expires_at'=>gmdate(DATE_ATOM,time()+86400),'cards'=>$cards]);
}
