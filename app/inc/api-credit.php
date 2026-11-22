<?php
declare(strict_types=1);

final class LegacyApiCreditError extends RuntimeException {}

/** Legacy POSTs remain non-idempotent. Local balance, journal and awards commit together. */
function legacy_api_credit(int $bid,string $selector,int|string $target,string $action,mixed $inputAmount=0): array
{
    if(!in_array($selector,['member','card'],true)||!in_array($action,['points','stamp'],true)||($selector==='card'&&$action!=='points'))throw new InvalidArgumentException('Invalid legacy credit operation.');
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('SELECT user_id FROM businesses WHERE id=?');$q->execute([$bid]);$uid=$q->fetchColumn();
        if($uid===false)throw new LegacyApiCreditError('Unternehmen nicht gefunden',404);
        if(($error=plan_policy((int)$uid,'api'))!==null)throw new LegacyApiCreditError($error,402);
        if($selector==='member'){
            $member=api_member_by_code($bid,(string)$target);
            if(!$member)throw new LegacyApiCreditError('Mitglied nicht gefunden',404);
            // Preserve the old first-matching-active-card selection, not a new program choice.
            $q=$db->prepare('SELECT c.*,p.type AS program_type,p.stamps_required,p.name AS program_name FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=? AND p.business_id=? AND p.type=? AND p.active=1 LIMIT 1');
            $q->execute([$member['id'],$bid,$action==='stamp'?'stamps':'points']);$card=$q->fetch();
            if(!$card)throw new LegacyApiCreditError($action==='stamp'?'Keine aktive Stempelkarte':'Keine aktive Punktekarte',404);
        }else{
            // Explicit-card legacy credit historically also accepts inactive points programs.
            $q=$db->prepare('SELECT c.*,p.type AS program_type,p.stamps_required,p.name AS program_name FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.id=? AND p.business_id=?');
            $q->execute([(int)$target,$bid]);$card=$q->fetch();
            if(!$card||$card['program_type']!=='points')throw new LegacyApiCreditError('Punktekarte nicht gefunden',404);
            $q=$db->prepare('SELECT id,code FROM members WHERE id=? AND business_id=?');$q->execute([$card['member_id'],$bid]);$member=$q->fetch();
            if(!$member)throw new LegacyApiCreditError('Punktekarte nicht gefunden',404);
        }
        $cid=(int)$card['id'];$mid=(int)$member['id'];
        $response=['card_id'=>$cid];if($selector==='member')$response=['member'=>$member['code']]+$response;
        if($action==='points'){
            // Compatibility: this stage does not replace legacy casting/clamping with new validation.
            $base=max(1,min(10000,(int)$inputAmount));$mult=points_multiplier((int)$card['program_id']);$amount=$base*$mult;
            $db->prepare('UPDATE cards SET points=points+?,points_lifetime=points_lifetime+? WHERE id=?')->execute([$amount,$amount,$cid]);
            txn($cid,'points_add',$amount,'+'.$amount.' Punkte (API)'.($mult>1?' (Bonus-Zeit x'.$mult.')':''));
            $response+=['credited'=>$amount,'bonus_multiplier'=>$mult];
            $event=['event'=>'card.points','payload'=>['card_id'=>$cid,'member_id'=>$mid,'amount'=>$amount]];
        }else{
            $req=(int)$card['stamps_required'];$stamps=(int)$card['stamps']+1;$pending=(int)$card['pending_rewards'];$earned=false;
            if($stamps>=$req){$pending++;$stamps-=$req;$earned=true;}
            $db->prepare('UPDATE cards SET stamps=?,pending_rewards=? WHERE id=?')->execute([$stamps,$pending,$cid]);
            txn($cid,'stamp_add',1,'+1 Stempel (API) ('.$stamps.'/'.$req.')');
            if($earned)txn($cid,'reward_earned',1,'Belohnung verdient: '.$card['program_name']);
            $response+=['stamps'=>$stamps,'stamps_required'=>$req,'pending_rewards'=>$pending,'reward_earned'=>$earned];
            $event=['event'=>'card.stamp','payload'=>['card_id'=>$cid,'member_id'=>$mid]];
        }
        $events=[];foreach(achievement_award_locked($mid,$bid) as $award)$events[]=['event'=>'achievement.unlocked','payload'=>$award];
        // SQLite can promote overflowing INTEGER arithmetic to REAL. Never commit a rounded balance.
        $q=$db->prepare("SELECT COUNT(*) FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=? AND p.business_id=? AND (typeof(c.points)<>'integer' OR typeof(c.points_lifetime)<>'integer' OR typeof(c.stamps)<>'integer' OR typeof(c.pending_rewards)<>'integer')");$q->execute([$mid,$bid]);
        if((int)$q->fetchColumn()>0)throw new LegacyApiCreditError('Ganzzahliger Kartenstand würde überschritten oder ist ungültig',422);
        if($action==='points'){$q=$db->prepare('SELECT points FROM cards WHERE id=?');$q->execute([$cid]);$response['points']=(int)$q->fetchColumn();}
        $events[]=$event;$db->exec('COMMIT');
        return ['response'=>$response,'business_id'=>$bid,'card_id'=>$cid,'events'=>$events];
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}

/** One handoff per effect, after commit. Test callback is not configurable through HTTP. */
function legacy_api_credit_effects(array $booked,?callable $dispatch=null): bool
{
    $dispatch??=static function(string $kind,array $data):void{
        if($kind==='webhook')fire_webhook($data['business_id'],$data['event'],$data['payload']);
        else wallet_pass_update_for_card($data['card_id']);
    };
    $jobs=[];foreach($booked['events'] as $event)$jobs[]=['webhook',['business_id'=>$booked['business_id']]+$event];
    $jobs[]=['wallet',['card_id'=>$booked['card_id']]];$warning=false;
    foreach($jobs as [$kind,$data]){
        try{$dispatch($kind,$data);}catch(Throwable $e){
            $warning=true;
            // No exception message, member data, token or provider response in this diagnostic.
            error_log('Loyalty legacy credit follow-up '.$kind.' '.get_class($e));
        }
    }
    return $warning;
}

function route_legacy_api_credit(int $bid,string $selector,int|string $target,string $action,mixed $amount=0): never
{
    try{$booked=legacy_api_credit($bid,$selector,$target,$action,$amount);}
    catch(LegacyApiCreditError $e){if($e->getCode()===402)policy_http_error($e->getMessage());api_json(['error'=>$e->getMessage()],$e->getCode());}
    $response=$booked['response'];if(legacy_api_credit_effects($booked))$response['warning']='follow_up_incomplete';
    api_json($response);
}
