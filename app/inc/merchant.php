<?php
declare(strict_types=1);

/** A business key is the tenant boundary. No caller-supplied business ID is trusted. */
function merchant_write(array $business,array $input,string $target,int $id): array
{
    if(!in_array($target,['member','card'],true) || $id<0)throw new InvalidArgumentException('Ungültiges Ziel.');
    $op=(string)($input['operation'] ?? '');if(!preg_match('/^[a-f0-9]{32}$/D',$op))throw new InvalidArgumentException('Vorgangskennung fehlt.');
    $action=(string)($input['action'] ?? '');$amount=filter_var($input['amount'] ?? 0,FILTER_VALIDATE_INT);$reward=(int)($input['reward_id'] ?? 0);
    $name=trim((string)($input['name'] ?? ''));$email=strtolower(trim((string)($input['email'] ?? '')));
    $hash=hash('sha256',$op.json_encode([$target,$id,$action,$amount,$reward,$name,$email],JSON_THROW_ON_ERROR));$bid=(int)$business['id'];$db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare('SELECT request_hash,response FROM merchant_operations WHERE business_id=? AND operation=?');$q->execute([$bid,$op]);
        if($old=$q->fetch()){if(!hash_equals($old['request_hash'],$hash))throw new InvalidArgumentException('Vorgangskennung wurde für andere Daten verwendet.');$db->exec('COMMIT');return json_decode($old['response'],true);}
        if($target==='member') {
            if($name==='' || mb_strlen($name)>120 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254)throw new InvalidArgumentException('Name und gültige E-Mail erforderlich.');
            $q=$db->prepare('SELECT id FROM members WHERE business_id=? AND lower(email)=lower(?) AND id<>?');$q->execute([$bid,$email,$id]);if($q->fetch())throw new InvalidArgumentException('Diese E-Mail gehört bereits zu einem Mitglied.');
            if($id===0){$db->prepare('INSERT INTO members(business_id,name,email,code)VALUES(?,?,?,?)')->execute([$bid,$name,$email,member_code()]);$id=(int)$db->lastInsertId();$q=$db->prepare('SELECT * FROM programs WHERE business_id=? AND active=1');$q->execute([$bid]);foreach($q->fetchAll() as $p)$db->prepare('INSERT INTO cards(program_id,member_id,uses_left,visits_left)VALUES(?,?,?,?)')->execute([$p['id'],$id,$p['type']==='voucher'?(int)$p['uses_per_customer']:null,$p['type']==='pass'?(int)$p['pass_visits']:null]);}
            else {$q=$db->prepare('SELECT id,email FROM members WHERE id=? AND business_id=?');$q->execute([$id,$bid]);if(!($existing=$q->fetch()))throw new InvalidArgumentException('Mitglied nicht gefunden.');if(strtolower($existing['email'])!==$email)throw new InvalidArgumentException('E-Mail-Änderungen sind im Händlerdashboard nicht erlaubt. Identität in der Loyalty-App prüfen.');$db->prepare('UPDATE members SET name=? WHERE id=?')->execute([$name,$id]);}
            $q=$db->prepare('SELECT * FROM members WHERE id=?');$q->execute([$id]);$saved=$q->fetch();$response=['ok'=>true,'member'=>['id'=>(int)$saved['id'],'code'=>$saved['code']]];
        }else {
            $q=$db->prepare('SELECT c.*,p.type,p.name,p.stamps_required,p.business_id FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.id=? AND p.business_id=? AND p.active=1');$q->execute([$id,$bid]);$card=$q->fetch();if(!$card)throw new InvalidArgumentException('Aktive Karte nicht gefunden.');
            $credited=0;$mult=1;
            if($action==='points_add' && $card['type']==='points'){
                if($amount===false || $amount<1 || $amount>10000)throw new InvalidArgumentException('Punkte müssen zwischen 1 und 10000 liegen.');$mult=points_multiplier((int)$card['program_id']);$credited=$amount*$mult;
                $db->prepare('UPDATE cards SET points=points+?,points_lifetime=points_lifetime+? WHERE id=?')->execute([$credited,$credited,$id]);txn($id,'points_add',$credited,'WordPress/API: +'.$credited.' Punkte');
            }elseif($action==='stamp' && $card['type']==='stamps'){
                $req=(int)$card['stamps_required'];if($req<1)throw new InvalidArgumentException('Stempelziel ungültig.');$earned=(int)$card['stamps']+1 >= $req;
                $db->prepare('UPDATE cards SET stamps=?,pending_rewards=pending_rewards+? WHERE id=?')->execute([((int)$card['stamps']+1)%$req,(int)$earned,$id]);txn($id,'stamp_add',1,'WordPress/API: +1 Stempel');if($earned)txn($id,'reward_earned',1,'Stempelbelohnung verdient');
            }elseif($action==='redeem_stamp_reward' && $card['type']==='stamps' && (int)$card['pending_rewards']>0){$db->prepare('UPDATE cards SET pending_rewards=pending_rewards-1 WHERE id=?')->execute([$id]);txn($id,'reward_redeem',1,'WordPress/API: Stempelbelohnung eingelöst');}
            elseif($action==='redeem_reward' && $card['type']==='points'){
                $q=$db->prepare('SELECT * FROM rewards WHERE id=? AND program_id=?');$q->execute([$reward,$card['program_id']]);$r=$q->fetch();if(!$r || (int)$r['points_cost']<1 || (int)$card['points']<(int)$r['points_cost'])throw new InvalidArgumentException('Prämie nicht verfügbar oder zu wenig Punkte.');
                $db->prepare('UPDATE cards SET points=points-? WHERE id=?')->execute([$r['points_cost'],$id]);txn($id,'points_redeem',(int)$r['points_cost'],'WordPress/API: '.$r['name']);
            }else throw new InvalidArgumentException('Aktion nicht möglich.');
            $q=$db->prepare('SELECT * FROM cards WHERE id=?');$q->execute([$id]);$fresh=$q->fetch();$response=['ok'=>true,'card_id'=>$id,'member_id'=>(int)$fresh['member_id'],'points'=>(int)$fresh['points'],'stamps'=>(int)$fresh['stamps'],'pending_rewards'=>(int)$fresh['pending_rewards'],'credited'=>$credited,'bonus_multiplier'=>$mult];
        }
        $db->prepare('INSERT INTO merchant_operations(business_id,member_id,operation,request_hash,response)VALUES(?,?,?,?,?)')->execute([$bid,$target==='member'?$id:(int)$fresh['member_id'],$op,$hash,json_encode($response,JSON_THROW_ON_ERROR)]);$db->exec('COMMIT');return $response;
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}
function route_merchant_api(array $b,string $path,string $method,array $input): never
{
    $bid=(int)$b['id'];header('Cache-Control: private, no-store');
    if($path==='/api/v1/merchant/summary' && $method==='GET'){
        $q=db()->prepare('SELECT COUNT(*) FROM members WHERE business_id=?');$q->execute([$bid]);$members=(int)$q->fetchColumn();
        $q=db()->prepare('SELECT COUNT(*) AS cards,COALESCE(SUM(c.points),0) AS points,COALESCE(SUM(c.stamps),0) AS stamps,COALESCE(SUM(c.pending_rewards),0) AS pending_rewards FROM cards c JOIN programs p ON p.id=c.program_id WHERE p.business_id=?');$q->execute([$bid]);api_json(['business'=>['id'=>$bid,'name'=>$b['name']],'members'=>$members,'balances'=>$q->fetch(),'as_of'=>gmdate(DATE_ATOM)]);
    }
    if($path==='/api/v1/merchant/members' && $method==='GET'){
        $search=mb_substr(trim((string)($_GET['q'] ?? '')),0,100);$after=max(0,(int)($_GET['after'] ?? 0));$like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%';
        $q=db()->prepare("SELECT * FROM members WHERE business_id=? AND id>? AND (name LIKE ? ESCAPE '\' OR email LIKE ? ESCAPE '\' OR code LIKE ? ESCAPE '\') ORDER BY id LIMIT 51");$q->execute([$bid,$after,$like,$like,$like]);$rows=$q->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);api_json(['members'=>array_map('member_api_payload',$rows),'next_after'=>$more?(int)end($rows)['id']:null,'search'=>$search]);
    }
    if(preg_match('#^/api/v1/merchant/members/([A-Fa-f0-9]{10})$#D',$path,$m) && $method==='GET'){
        $member=api_member_by_code($bid,strtoupper($m[1]));if(!$member)api_json(['error'=>'Mitglied nicht gefunden.'],404);$payload=member_api_payload($member);
        $q=db()->prepare('SELECT r.id,r.name,r.points_cost,r.program_id FROM rewards r JOIN programs p ON p.id=r.program_id WHERE p.business_id=?');$q->execute([$bid]);$payload['rewards']=$q->fetchAll();api_json($payload);
    }
    if($method==='POST'){
        $target='';$id=0;if($path==='/api/v1/merchant/members')$target='member';
        elseif(preg_match('#^/api/v1/merchant/members/([1-9]\d*)/edit$#D',$path,$m)){$target='member';$id=(int)$m[1];}
        elseif(preg_match('#^/api/v1/merchant/cards/([1-9]\d*)/action$#D',$path,$m)){$target='card';$id=(int)$m[1];}
        if($target!==''){try{api_json(merchant_write($b,$input,$target,$id));}catch(InvalidArgumentException $e){api_json(['error'=>$e->getMessage()],422);}}
    }
    api_json(['error'=>'Endpunkt nicht gefunden.'],404);
}
