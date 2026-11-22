<?php
declare(strict_types=1);

/** Optional messages are single-member operations. No class broadcast or automatic retries. */
function wallet_message_member(int $bid,int $mid): ?array
{
    $q=db()->prepare('SELECT m.* FROM members m WHERE m.business_id=? AND m.id=?');$q->execute([$bid,$mid]);return $q->fetch() ?: null;
}
function wallet_message_recipient_hash(array $m): string
{
    return wallet_push_hash(array_intersect_key($m,array_flip(['id','business_id','name','email','code'])));
}
function wallet_message_provider_identity(string $provider): string
{
    $paths=$provider==='apple'?['apple_wallet.cert_path','apple_wallet.key_path','apple_wallet.wwdr_path']:['google_wallet.service_account_json'];
    $files=[];foreach($paths as $key){$path=(string)config($key,'');$files[$key]=[$path,is_file($path)&&is_readable($path)?hash_file('sha256',$path):null];}
    return wallet_push_hash([$provider,base_url(),$provider==='apple'?[(string)config('apple_wallet.pass_type_id',''),(string)config('apple_wallet.team_id','')]:[(string)config('google_wallet.issuer_id',''),(string)config('google_wallet.class_suffix','loyalty')],$files]);
}
function wallet_message_account_label(string $provider): string
{
    $id=(string)config($provider==='apple'?'apple_wallet.pass_type_id':'google_wallet.issuer_id','');
    return ($provider==='apple'?'Pass Type ID: ':'Google-Issuer: ').($id!==''?$id:'nicht eingerichtet');
}
function wallet_message_validate(array $input): array
{
    $p=$input['provider'] ?? null;if(!is_string($p)||!isset(wallet_push_providers()[$p]))throw new InvalidArgumentException('Anbieter ausdrücklich auswählen.');
    $mid=$input['member_id'] ?? null;if(!is_string($mid)||!ctype_digit($mid)||(int)$mid<1)throw new InvalidArgumentException('Mitglied ausdrücklich auswählen.');
    $v=['member_id'=>(int)$mid,'provider'=>$p];
    foreach(['header'=>80,'body'=>$p==='apple'?240:500] as $key=>$max){$text=$input[$key] ?? null;if(!is_string($text)||!mb_check_encoding($text,'UTF-8')||mb_strlen(trim($text))>$max||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$text)||strip_tags($text)!==$text)throw new InvalidArgumentException('Nachricht als gültigen reinen Text innerhalb der Feldlängen angeben.');$v[$key]=trim($text);}
    if($v['body']===''||($p==='google'&&$v['header']===''))throw new InvalidArgumentException('Nachrichtentext und bei Google Titel sind erforderlich.');
    if($p==='apple')$v['header']='';
    $minutes=$input['valid_minutes'] ?? null;if(!is_string($minutes)||!in_array($minutes,['15','60','360','1440'],true))throw new InvalidArgumentException('Gültigkeit auswählen.');
    $v['expires_at']=gmdate('Y-m-d H:i:s',time()+(int)$minutes*60);return $v;
}
function wallet_message_preview(int $uid,int $bid,array $input): array
{
    if(!own_business($uid,$bid))throw new InvalidArgumentException('Unternehmen nicht verfügbar.');
    if(($e=plan_policy($uid,'core'))!==null)throw new InvalidArgumentException($e);
    $v=wallet_message_validate($input);$m=wallet_message_member($bid,$v['member_id']);if(!$m)throw new InvalidArgumentException('Mitglied gehört nicht zu diesem Unternehmen.');
    if(!wallet_push_allowed((int)$m['id'],$v['provider']))throw new InvalidArgumentException('Keine aktuelle freiwillige Kundenwahl für diesen Anbieter.');
    $choice=wallet_push_choice((int)$m['id'],$v['provider']);$business=own_business($uid,$bid);
    return $v+['id'=>bin2hex(random_bytes(16)),'user_id'=>$uid,'business_id'=>$bid,'recipient_hash'=>wallet_message_recipient_hash($m),'choice_revision'=>(int)$choice['revision'],'notice_hash'=>$choice['notice_hash'],'settings_revision'=>wallet_push_revision($business),'provider_identity'=>wallet_message_provider_identity($v['provider']),'provider_account'=>wallet_message_account_label($v['provider']),'review_expires'=>time()+600,'review_version'=>'wallet-message-send-v1'];
}
function wallet_message_readiness(string $provider): ?string
{
    if(!config('wallet_messages.enabled',false))return 'Optionaler Wallet-Versand ist in der Serverkonfiguration ausgeschaltet.';
    if(parse_url(base_url(),PHP_URL_SCHEME)!=='https')return 'Für den optionalen Wallet-Versand muss base_url eine HTTPS-Adresse sein.';
    if(!function_exists('curl_init'))return 'cURL ist nicht verfügbar.';
    if($provider==='google')return google_wallet_ready()?null:'Google-Issuer oder Service-Account-Datei fehlen.';
    if($provider!=='apple')return 'Anbieter ungültig.';
    if(!apple_wallet_ready())return 'Apple-Passsignatur ist nicht eingerichtet.';
    if(!(curl_version()['features'] & CURL_VERSION_HTTP2))return 'Apple-Push benötigt cURL mit HTTP/2.';
    if(!apple_push_enabled_settings((array)config('apple_wallet',[])))return 'Apple-Updates sind serverseitig nicht freigegeben (apple_wallet.updates_enabled).';
    if(!apple_push_ready())return 'Apple-Updates benötigen HTTPS und lesbare Pass-Signierdateien.';
    return null;
}
function wallet_message_fingerprint(array $v): string { return wallet_push_hash([$v['provider'],$v['header'],$v['body']]); }
/** Reserve once under lock, then hand off outside the DB lock. A crash is not a retry instruction. */
function wallet_message_send(int $uid,int $bid,array $review,?callable $transport=null): array
{
    $db=db();$db->exec('BEGIN IMMEDIATE');try{
        $b=own_business($uid,$bid);if(!$b)throw new InvalidArgumentException('Unternehmen nicht verfügbar.');
        if(($e=plan_policy($uid,'core'))!==null)throw new InvalidArgumentException($e);
        if(($review['user_id'] ?? null)!==$uid||($review['business_id'] ?? null)!==$bid||($review['review_version'] ?? null)!=='wallet-message-send-v1'||!is_string($review['id'] ?? null)||!preg_match('/^[a-f0-9]{32}$/D',$review['id']))throw new InvalidArgumentException('Versandvorschau ungültig.');
        $q=$db->prepare('SELECT * FROM wallet_push_deliveries WHERE id=? AND user_id=? AND business_id=?');$q->execute([$review['id'],$uid,$bid]);if($existing=$q->fetch()){$db->exec('COMMIT');return $existing;}
        if(($review['review_expires'] ?? 0)<time()||($review['expires_at'] ?? '')<=gmdate('Y-m-d H:i:s'))throw new InvalidArgumentException('Vorschau abgelaufen. Erneut prüfen.');
        $m=wallet_message_member($bid,(int)$review['member_id']);if(!$m||!hash_equals($review['recipient_hash'],wallet_message_recipient_hash($m))||!hash_equals($review['settings_revision'],wallet_push_revision($b)))throw new InvalidArgumentException('Empfänger oder Einstellungen inzwischen geändert. Neue Vorschau nötig.');
        $p=$review['provider'];if(!is_string($review['provider_identity'] ?? null)||!hash_equals($review['provider_identity'],wallet_message_provider_identity($p)))throw new InvalidArgumentException('Wallet-Konto oder Signierkonfiguration inzwischen geändert. Neue Vorschau nötig.');$choice=wallet_push_choice((int)$m['id'],$p);
        if(!wallet_push_allowed((int)$m['id'],$p)||(int)$choice['revision']!==(int)$review['choice_revision']||!hash_equals($review['notice_hash'],$choice['notice_hash']))throw new InvalidArgumentException('Kundenwahl inzwischen geändert. Neue Vorschau nötig.');
        if($transport===null&&($e=wallet_message_readiness($p))!==null)throw new InvalidArgumentException($e);
        $q=$db->prepare("SELECT COUNT(*) FROM wallet_push_deliveries WHERE member_id=? AND provider=? AND created_at>datetime('now','-24 hours')");$q->execute([$m['id'],$p]);if((int)$q->fetchColumn()>=3)throw new InvalidArgumentException('App-Grenze: höchstens drei Versuche je Mitglied und Anbieter in 24 Stunden.');
        $hash=wallet_message_fingerprint($review);$q=$db->prepare("SELECT id FROM wallet_push_deliveries WHERE member_id=? AND provider=? AND content_hash=? AND created_at>datetime('now','-24 hours')");$q->execute([$m['id'],$p,$hash]);if($q->fetch())throw new InvalidArgumentException('Derselbe Text wurde hier in den letzten 24 Stunden bereits zur Übertragung übernommen. Kein automatisches Wiederholen.');
        $tokens=[];if($p==='apple'){$q=$db->prepare('SELECT DISTINCT push_token FROM pass_devices WHERE serial=? AND pass_type_id=? LIMIT 11');$q->execute([$m['code'],(string)config('apple_wallet.pass_type_id','')]);$tokens=array_column($q->fetchAll(),'push_token');if(!$tokens||count($tokens)>10)throw new InvalidArgumentException('Apple benötigt eine bis zehn aktuelle Geräteregistrierungen für diesen Pass.');}
        $db->prepare('INSERT INTO wallet_push_deliveries(id,user_id,business_id,member_id,provider,header,body,content_hash,expires_at,notice_hash,choice_revision,state,published)VALUES(?,?,?,?,?,?,?,?,?,?,?,\'processing\',?)')->execute([$review['id'],$uid,$bid,$m['id'],$p,$review['header'],$review['body'],$hash,$review['expires_at'],$review['notice_hash'],$review['choice_revision'],(int)($p==='apple')]);
        audit_log($uid,'wallet.message_handoff',['business_id'=>$bid,'delivery_id'=>$review['id'],'provider'=>$p]);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    try{$result=$transport!==null?$transport($review,$m,$tokens):($p==='google'?wallet_message_google($review,$m):wallet_message_apple($review,$m,$tokens));}catch(Throwable $e){$result=['state'=>'unknown','code'=>'transport_exception'];}
    if(!is_array($result)||!in_array($result['state'] ?? null,['accepted','partial','rejected','unknown'],true))$result=['state'=>'unknown','code'=>'invalid_transport_result'];
    // Never store raw provider responses, credentials, device tokens or exceptions.
    $codes=['google_accepted','google_precheck_failed','google_no_holder','google_message_limit','google_rejected','google_unknown','apple_accepted','apple_partial','apple_rejected','apple_unknown','transport_exception','invalid_transport_result'];
    $code=in_array($result['code'] ?? '',$codes,true)?$result['code']:'invalid_transport_result';
    if($code==='invalid_transport_result')$result['state']='unknown';
    $db->prepare("UPDATE wallet_push_deliveries SET state=?,outcome=?,finished_at=datetime('now') WHERE id=? AND state='processing'")->execute([$result['state'],$code,$review['id']]);
    $q=$db->prepare('SELECT * FROM wallet_push_deliveries WHERE id=?');$q->execute([$review['id']]);return $q->fetch() ?: ['id'=>$review['id'],'state'=>'unknown','outcome'=>'local_record_unavailable'];
}
function wallet_message_review_current(array $v): bool
{
    $m=wallet_message_member($v['business_id'],$v['member_id']);$choice=wallet_push_choice($v['member_id'],$v['provider']);$b=own_business($v['user_id'],$v['business_id']);
    return $m&&$b&&is_string($v['provider_identity'] ?? null)&&hash_equals($v['provider_identity'],wallet_message_provider_identity($v['provider']))&&wallet_push_allowed($v['member_id'],$v['provider'])&&(int)$choice['revision']===$v['choice_revision']&&hash_equals($v['recipient_hash'],wallet_message_recipient_hash($m))&&hash_equals($v['settings_revision'],wallet_push_revision($b))&&$v['expires_at']>gmdate('Y-m-d H:i:s');
}
function wallet_message_google(array $v,array $m,?callable $request=null,?string $accessToken=null): array
{
    $request??=fn($method,$url,$body,$headers)=>http_request_json($method,$url,$body,$headers);
    $token=$accessToken??google_access_token();if(!$token)return ['state'=>'rejected','code'=>'google_precheck_failed'];
    $id=google_object_id($m);$url='https://walletobjects.googleapis.com/walletobjects/v1/genericObject/'.rawurlencode($id);$auth=['Authorization: Bearer '.$token,'Content-Type: application/json'];
    $existing=$request('GET',$url,'',$auth);
    if(($existing['status'] ?? 0)!==200||($existing['body']['id'] ?? null)!==$id)return ['state'=>'rejected','code'=>'google_precheck_failed'];
    if(($existing['body']['hasUsers'] ?? false)!==true)return ['state'=>'rejected','code'=>'google_no_holder'];
    if(count($existing['body']['messages'] ?? [])>=10)return ['state'=>'rejected','code'=>'google_message_limit'];
    if(!wallet_message_review_current($v))return ['state'=>'rejected','code'=>'google_precheck_failed'];
    $body=['message'=>['id'=>'loyalty-'.$v['id'],'header'=>$v['header'],'body'=>$v['body'],'messageType'=>'TEXT_AND_NOTIFY','displayInterval'=>['endTime'=>gmdate('Y-m-d\TH:i:s\Z',strtotime($v['expires_at'].' UTC'))]]];
    $r=$request('POST',$url.'/addMessage',json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$auth);$status=(int)($r['status'] ?? 0);
    $seen=false;foreach(($r['body']['resource']['messages'] ?? []) as $message)if(($message['id'] ?? null)==='loyalty-'.$v['id'])$seen=true;
    if($status===200&&($r['body']['resource']['id'] ?? null)===$id&&$seen)return ['state'=>'accepted','code'=>'google_accepted'];
    return $status>=400&&$status<500?['state'=>'rejected','code'=>'google_rejected']:['state'=>'unknown','code'=>'google_unknown'];
}
/** Official certificate-based pass update path, production APNs only, empty payload. */
function wallet_message_apple_options(array $v): array
{
    return apple_pass_push_options(strtotime($v['expires_at'].' UTC'));
}
function wallet_message_apple(array $v,array $m,array $tokens,?callable $send=null): array
{
    if(!wallet_message_review_current($v))return ['state'=>'rejected','code'=>'apple_rejected'];
    $counts=apple_pass_push_tokens($m['code'],$tokens,strtotime($v['expires_at'].' UTC'),$send);
    $total=array_sum($counts);
    if($total>0&&$counts['accepted']===$total)return ['state'=>'accepted','code'=>'apple_accepted'];
    if($counts['accepted']>0)return ['state'=>'partial','code'=>'apple_partial'];
    return $counts['unknown']>0?['state'=>'unknown','code'=>'apple_unknown']:['state'=>'rejected','code'=>'apple_rejected'];
}
function wallet_message_apple_field(array $member): array
{
    // Keep the key stable, including before a first message, so an update can change an existing field.
    $field=['key'=>'issuer_message','label'=>'Wichtige Karteninformation','value'=>'Keine aktuelle Mitteilung'];
    if(!wallet_push_allowed((int)$member['id'],'apple'))return $field;
    $choice=wallet_push_choice((int)$member['id'],'apple');
    $q=db()->prepare("SELECT body FROM wallet_push_deliveries WHERE member_id=? AND provider='apple' AND published=1 AND choice_revision=? AND notice_hash=? AND expires_at>datetime('now') ORDER BY created_at DESC,rowid DESC LIMIT 1");$q->execute([$member['id'],$choice['revision'],$choice['notice_hash']]);$body=$q->fetchColumn();if($body!==false){$field['value']=$body;$field['changeMessage']='%@';}return $field;
}
function wallet_message_export(int $mid): array
{
    $q=db()->prepare('SELECT provider,header,body,created_at,expires_at,state,outcome,finished_at,published FROM wallet_push_deliveries WHERE member_id=? ORDER BY created_at,id');$q->execute([$mid]);return $q->fetchAll();
}

/** Server-operator maintenance, not exposed as an HTTP action. Thirty days preserves the 24h safety ledger. */
function wallet_message_cleanup(bool $execute=false): int
{
    $db=db();$where="created_at<datetime('now','-30 days') AND expires_at<datetime('now')";
    if(!$execute)return (int)$db->query('SELECT COUNT(*) FROM wallet_push_deliveries WHERE '.$where)->fetchColumn();
    $GLOBALS['privacy_delete_authorized']=true;
    try{return $db->exec('DELETE FROM wallet_push_deliveries WHERE '.$where);}finally{$GLOBALS['privacy_delete_authorized']=false;}
}
