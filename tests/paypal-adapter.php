<?php
// Local test adapter only. The runner copies this into a disposable installation.
function paypal_request(string $method,string $path,array $data=[],string $requestId='',?string $rawBody=null): array
{
    $file=ROOT_DIR.'/provider.json';$s=json_decode(file_get_contents($file),true);$s['calls'][]=[$method,$path,$requestId];
    if($path==='/v1/notifications/verify-webhook-signature') {
        $body=json_decode($rawBody,true);$prefix=strpos($rawBody,',"webhook_event":');$raw=substr($rawBody,$prefix+17,-1);
        $valid=hash_equals(hash_hmac('sha256',$raw,'local-adapter-only'),(string)($body['transmission_sig'] ?? ''));
        file_put_contents($file,json_encode($s));return ['verification_status'=>$valid?'SUCCESS':'FAILURE'];
    }
    if($method==='POST' && $path==='/v1/billing/subscriptions') {
        if(!isset($s['requests'][$requestId])){$id='I-LOCAL'.(count($s['requests'] ?? [])+1);$s['requests'][$requestId]=$id;$s['subs'][$id]=['id'=>$id,'plan_id'=>$data['plan_id'],'custom_id'=>$data['custom_id'],'status'=>'APPROVAL_PENDING'];}
        $id=$s['requests'][$requestId];file_put_contents($file,json_encode($s));return $s['subs'][$id];
    }
    $id=explode('/',$path)[4] ?? '';
    if($method==='POST' && str_ends_with($path,'/cancel')){$s['subs'][$id]['status']='CANCELLED';file_put_contents($file,json_encode($s));return [];}
    if(!empty($s['auto_approve']) && isset($s['subs'][$id]))$s['subs'][$id]['status']='ACTIVE';
    file_put_contents($file,json_encode($s));if(!isset($s['subs'][$id]))throw new RuntimeException('Local provider subscription missing.');return $s['subs'][$id];
}
