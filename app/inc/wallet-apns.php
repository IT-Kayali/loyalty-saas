<?php
declare(strict_types=1);

/** Preserve deliberate legacy production activation, never promote sandbox to live implicitly. */
function apple_push_enabled_settings(array $settings): bool
{
    if(array_key_exists('updates_enabled',$settings))return $settings['updates_enabled']===true;
    return !($settings['apns_sandbox'] ?? false)
        &&is_string($settings['apns_key_id'] ?? null)&&$settings['apns_key_id']!==''
        &&is_string($settings['apns_topic'] ?? null)&&$settings['apns_topic']!==''
        &&is_string($settings['apns_auth_key'] ?? null)&&is_file($settings['apns_auth_key']);
}
function apple_pass_push_options(int $expires): array
{
    return [CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_2_0,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'{}',
        CURLOPT_HTTPHEADER=>['apns-topic: '.config('apple_wallet.pass_type_id'),'apns-push-type: background','apns-priority: 5','apns-expiration: '.$expires,'Content-Type: application/json'],
        CURLOPT_SSLCERT=>(string)config('apple_wallet.cert_path'),CURLOPT_SSLKEY=>(string)config('apple_wallet.key_path'),CURLOPT_KEYPASSWD=>(string)config('apple_wallet.key_password',''),
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2];
}
/** One attempt per distinct token. Responses never escape as raw provider text. */
function apple_pass_push_result(string $serial,string $token,array $response,array &$counts): void
{
    $status=(int)($response['status'] ?? 0);
    if($status===200)$counts['accepted']++;elseif($status>=400&&$status<500)$counts['rejected']++;else $counts['unknown']++;
    $reason=$response['body']['reason'] ?? null;
    if(($status===410&&in_array($reason,['Unregistered','ExpiredToken'],true))||($status===400&&in_array($reason,['BadDeviceToken','DeviceTokenNotForTopic'],true))){
        db()->prepare('DELETE FROM pass_devices WHERE serial=? AND pass_type_id=? AND push_token=?')->execute([$serial,(string)config('apple_wallet.pass_type_id'),$token]);
    }
}
/** Certificate-based production APNs. Callback exists only for isolated tests, never public config. */
function apple_pass_push_tokens(string $serial,array $tokens,int $expires,?callable $send=null): array
{
    $counts=['accepted'=>0,'rejected'=>0,'unknown'=>0];$valid=[];$seen=[];
    foreach($tokens as $token){
        if(!is_string($token)||!preg_match('/^[\x21-\x7E]{1,512}$/D',$token)){$counts['rejected']++;continue;}
        if(isset($seen[$token]))continue;$seen[$token]=true;
        $valid[]=$token;
    }
    if(!$valid)return $counts;
    $options=apple_pass_push_options($expires);
    if($send!==null){
        foreach($valid as $token){
            try{$response=$send('https://api.push.apple.com/3/device/'.rawurlencode($token),$options);}
            catch(Throwable $e){$response=['status'=>0];}
            apple_pass_push_result($serial,$token,is_array($response)?$response:['status'=>0],$counts);
        }
        return $counts;
    }
    if(!apple_push_ready()){$counts['rejected']+=count($valid);return $counts;}
    foreach(array_chunk($valid,10) as $batch){
        $multi=curl_multi_init();$handles=[];
        try{
            foreach($batch as $token){$h=curl_init('https://api.push.apple.com/3/device/'.rawurlencode($token));curl_setopt_array($h,$options);curl_multi_add_handle($multi,$h);$handles[]=[$h,$token];}
            do{$status=curl_multi_exec($multi,$running);if($running&&curl_multi_select($multi,.25)===-1)usleep(10000);}while($running&&$status===CURLM_OK);
            foreach($handles as [$h,$token]){
                $raw=curl_multi_getcontent($h);$body=is_string($raw)?json_decode($raw,true):null;
                $httpStatus=$status===CURLM_OK&&curl_errno($h)===0?(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE):0;
                apple_pass_push_result($serial,$token,['status'=>$httpStatus,'body'=>is_array($body)?$body:[]],$counts);
            }
        }finally{foreach($handles as [$h,$token]){curl_multi_remove_handle($multi,$h);curl_close($h);}curl_multi_close($multi);}
    }
    return $counts;
}
