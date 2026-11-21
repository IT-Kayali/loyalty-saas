<?php
declare(strict_types=1);

function paypal_ready(): bool
{
    return in_array(config('paypal.mode','sandbox'),['sandbox','live'],true) && config('paypal.client_id','')!=='' && config('paypal.secret','')!=='' && config('paypal.webhook_id','')!=='';
}
function paypal_origin(): string {return config('paypal.mode','sandbox')==='live'?'https://api-m.paypal.com':'https://api-m.sandbox.paypal.com';}

/** Fixed origins and explicit endpoint allowlist. Never follow caller-supplied URLs. */
function paypal_request(string $method,string $path,array $data=[],string $requestId='',?string $rawBody=null): array
{
    if(!paypal_ready())throw new RuntimeException('PayPal ist nicht eingerichtet.');
    if(!in_array($method,['GET','POST'],true) || !preg_match('#^/v1/(billing/subscriptions(?:/[A-Z0-9-]+(?:/cancel)?)?|notifications/verify-webhook-signature)$#D',$path))throw new RuntimeException('Ungültiger PayPal-Endpunkt.');
    $auth=curl_init(paypal_origin().'/v1/oauth2/token');
    curl_setopt_array($auth,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_USERPWD=>config('paypal.client_id').':'.config('paypal.secret'),CURLOPT_POSTFIELDS=>'grant_type=client_credentials',CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $raw=curl_exec($auth);$code=(int)curl_getinfo($auth,CURLINFO_HTTP_CODE);curl_close($auth);$token=is_string($raw)?json_decode($raw,true):null;
    if($code!==200 || !is_string($token['access_token'] ?? null))throw new RuntimeException('PayPal-Anmeldung fehlgeschlagen. Provider-Konfiguration prüfen.');
    $headers=['Authorization: Bearer '.$token['access_token'],'Content-Type: application/json','Accept: application/json','Prefer: return=representation'];
    if($requestId!=='')$headers[]='PayPal-Request-Id: '.$requestId;
    $ch=curl_init(paypal_origin().$path);$correlation='';
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>static function($ch,$header)use(&$correlation){if(str_starts_with(strtolower($header),'paypal-debug-id:'))$correlation=trim(substr($header,16));return strlen($header);}]);
    if($method==='POST')curl_setopt($ch,CURLOPT_POSTFIELDS,$rawBody ?? json_encode($data,JSON_THROW_ON_ERROR));
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if(preg_match('/^[A-Za-z0-9-]{1,100}$/D',$correlation))error_log('PayPal correlation '.$correlation.' HTTP '.$code);
    if($code<200 || $code>=300)throw new RuntimeException('PayPal-Anfrage nicht bestätigt. Nicht mit neuer Vorgangskennung wiederholen; Status prüfen.');
    if($code===204 || $raw==='')return [];
    $result=is_string($raw)?json_decode($raw,true):null;if(!is_array($result))throw new RuntimeException('Ungültige PayPal-Antwort.');return $result;
}
function paypal_plan(string $key): array
{
    $plan=billing_plans()[$key] ?? null;$id=(string)config('paypal.plans.'.$key.'.plan_id','');
    if(!$plan || !preg_match('/^P-[A-Z0-9]{24}$/D',$id))throw new RuntimeException('PayPal-Plan nicht konfiguriert.');
    return ['id'=>$id,'currency'=>(string)config('paypal.plans.'.$key.'.currency','EUR')];
}
function paypal_state(string $state): string
{
    return match($state){'ACTIVE'=>'active','CANCELLED'=>'canceled','EXPIRED'=>'incomplete_expired','SUSPENDED'=>'unpaid',default=>'incomplete'};
}

/** Persist request identity before an external call. Ambiguous outcomes reuse it. */
function paypal_create(int $uid,string $planKey): string
{
    if(!paypal_ready())throw new RuntimeException('PayPal ist nicht eingerichtet.');$plan=paypal_plan($planKey);$db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $s=billing_account($uid);
        if((int)$s['paypal_payment_hold']>0)throw new RuntimeException('Eine Rückzahlung/Rückbuchung ist ungeklärt. Vor neuem Abo durch Plattformbetreiber prüfen lassen.');
        if(($s['provider'] ?? 'stripe')==='stripe' && ($s['checkout_session_id'] || ($s['stripe_subscription_id'] && !in_array($s['status'],['canceled','incomplete_expired'],true))))throw new RuntimeException('Bestehendes Stripe-Abo oder offenen Checkout zuerst klären. Kein paralleles PayPal-Abo.');
        if($s['provider']==='paypal' && $s['paypal_subscription_id'] && $s['status']==='incomplete' && $s['plan_key']===$planKey){$db->exec('COMMIT');return $s['paypal_subscription_id'];}
        if($s['paypal_subscription_id'] && $s['provider']==='paypal' && !in_array($s['status'],['canceled','incomplete_expired'],true))throw new RuntimeException('Bestehendes PayPal-Abo zuerst prüfen. Ein Planwechsel kündigt niemals automatisch.');
        $attempt=null;
        if($s['paypal_request_id']){$q=$db->prepare('SELECT * FROM paypal_attempts WHERE request_id=?');$q->execute([$s['paypal_request_id']]);$attempt=$q->fetch();}
        if($attempt && !$attempt['finished']) {
            if($attempt['plan_key']!==$planKey)throw new RuntimeException('Ein anderer PayPal-Vorgang ist noch offen. Erst abschließen oder klären.');
            if(strtotime($attempt['created_at'].' UTC')<time()-70*3600)throw new RuntimeException('Ungeklärter alter PayPal-Vorgang. Vor neuem Versuch im Providerkonto abgleichen.');
        }else {
            $requestId=bin2hex(random_bytes(16));$custom=substr(hash('sha256',base_url()),0,16).':'.$uid.':'.$requestId;
            $db->prepare('INSERT INTO paypal_attempts(request_id,user_id,plan_key,plan_id,custom_id)VALUES(?,?,?,?,?)')->execute([$requestId,$uid,$planKey,$plan['id'],$custom]);
            $attempt=['request_id'=>$requestId,'plan_key'=>$planKey,'plan_id'=>$plan['id'],'custom_id'=>$custom];
            $db->prepare("UPDATE subscriptions SET provider='paypal',paypal_request_id=?,paypal_subscription_id=NULL,status='incomplete',plan_key=?,cancel_at_period_end=0,current_period_end=NULL WHERE user_id=?")->execute([$requestId,$planKey,$uid]);
        }
        $db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    $remote=paypal_request('POST','/v1/billing/subscriptions',['plan_id'=>$attempt['plan_id'],'custom_id'=>$attempt['custom_id']],$attempt['request_id']);
    $id=(string)($remote['id'] ?? '');if(!preg_match('/^I-[A-Z0-9]+$/D',$id))throw new RuntimeException('PayPal hat keine gültige Abokennung geliefert.');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $s=billing_account($uid);if($s['paypal_request_id']!==$attempt['request_id'] || $s['provider']!=='paypal')throw new RuntimeException('Abovorgang wurde inzwischen geändert. Bitte abgleichen.');
        $db->prepare('UPDATE paypal_attempts SET subscription_id=? WHERE request_id=?')->execute([$id,$attempt['request_id']]);
        $db->prepare('UPDATE subscriptions SET paypal_subscription_id=? WHERE user_id=?')->execute([$id,$uid]);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    return $id;
}

/** Verified events and authenticated refreshes both reconcile provider state. */
function paypal_sync(string $id): void
{
    if(!preg_match('/^I-[A-Z0-9]+$/D',$id))throw new RuntimeException('Ungültiges PayPal-Abo.');
    $remote=paypal_request('GET','/v1/billing/subscriptions/'.$id);if(($remote['id'] ?? '')!==$id)throw new RuntimeException('PayPal-Abo stimmt nicht überein.');
    $q=db()->prepare('SELECT a.*,s.provider,s.paypal_request_id,s.paypal_subscription_id,s.paypal_payment_hold FROM paypal_attempts a JOIN subscriptions s ON s.user_id=a.user_id WHERE a.custom_id=?');$q->execute([(string)($remote['custom_id'] ?? '')]);$a=$q->fetch();
    if(!$a || $a['provider']!=='paypal' || $a['paypal_request_id']!==$a['request_id'] || ($a['paypal_subscription_id'] && $a['paypal_subscription_id']!==$id) || ($a['subscription_id'] && $a['subscription_id']!==$id))return;
    if(($remote['plan_id'] ?? '')!==$a['plan_id'] || paypal_plan($a['plan_key'])['id']!==$a['plan_id'])throw new RuntimeException('Unbekannte oder geänderte PayPal-Planbindung.');
    $state=paypal_state((string)($remote['status'] ?? ''));if($state==='active' && ((int)$a['paypal_payment_hold']>0 || (int)($remote['billing_info']['failed_payments_count'] ?? 0)>0))$state='past_due';$end=isset($remote['billing_info']['next_billing_time'])?strtotime($remote['billing_info']['next_billing_time']):null;
    db()->prepare('UPDATE paypal_attempts SET subscription_id=?,finished=? WHERE request_id=?')->execute([$id,(int)in_array($state,['canceled','incomplete_expired'],true),$a['request_id']]);
    db()->prepare("UPDATE subscriptions SET paypal_subscription_id=?,plan_key=?,status=?,current_period_end=?,cancel_at_period_end=0,updated_at=datetime('now') WHERE user_id=? AND provider='paypal' AND paypal_request_id=?")->execute([$id,$a['plan_key'],$state,$end?:null,$a['user_id'],$a['request_id']]);
}

/** Preserve the raw event JSON inside PayPal's postback verification request. */
function paypal_signature_valid(string $raw,array $headers): bool
{
    if(!paypal_ready() || strlen($raw)>1048576 || !is_array(json_decode($raw,true)))return false;
    $fields=['transmission_id'=>'paypal-transmission-id','transmission_time'=>'paypal-transmission-time','cert_url'=>'paypal-cert-url','auth_algo'=>'paypal-auth-algo','transmission_sig'=>'paypal-transmission-sig'];$body=[];
    foreach($fields as $field=>$header){$value=$headers[$header] ?? '';if(!is_string($value) || $value==='' || strlen($value)>4096)return false;$body[$field]=$value;}
    $host=config('paypal.mode','sandbox')==='live'?['api.paypal.com','api-m.paypal.com']:['api.sandbox.paypal.com','api-m.sandbox.paypal.com'];
    $url=parse_url($body['cert_url']);if(($url['scheme'] ?? '')!=='https' || !in_array($url['host'] ?? '',$host,true) || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['query']) || isset($url['fragment']) || !preg_match('#^/v1/notifications/certs/[A-Za-z0-9-]+$#D',$url['path'] ?? ''))return false;
    if($body['auth_algo']!=='SHA256withRSA')return false;
    $body['webhook_id']=config('paypal.webhook_id');$encoded=json_encode($body,JSON_THROW_ON_ERROR);$post=substr($encoded,0,-1).',"webhook_event":'.$raw.'}';
    $r=paypal_request('POST','/v1/notifications/verify-webhook-signature',[],'',$post);return ($r['verification_status'] ?? '')==='SUCCESS';
}
/** Called only after provider signature verification, under the webhook transaction. */
function paypal_event_subscription(string $type,array $resource): string
{
    if(str_starts_with($type,'BILLING.SUBSCRIPTION.'))return (string)($resource['id'] ?? '');
    if(!str_starts_with($type,'PAYMENT.SALE.'))return '';
    $id=(string)($resource['billing_agreement_id'] ?? '');
    $sale=(string)($resource['sale_id'] ?? ($type==='PAYMENT.SALE.REFUNDED'?'':($resource['id'] ?? '')));
    if($id!=='' && preg_match('/^I-[A-Z0-9]+$/D',$id) && preg_match('/^[A-Za-z0-9-]{1,100}$/D',$sale)) {
        db()->prepare('INSERT OR IGNORE INTO paypal_sales(sale_id,subscription_id)VALUES(?,?)')->execute([$sale,$id]);
        $q=db()->prepare('SELECT subscription_id FROM paypal_sales WHERE sale_id=?');$q->execute([$sale]);if($q->fetchColumn()!==$id)throw new RuntimeException('Conflicting sale binding.');
    }
    if($id==='' && $sale!==''){$q=db()->prepare('SELECT subscription_id FROM paypal_sales WHERE sale_id=?');$q->execute([$sale]);$id=(string)($q->fetchColumn() ?: '');}
    // An early refund is retried until the signed sale event supplies its binding.
    if(in_array($type,['PAYMENT.SALE.REFUNDED','PAYMENT.SALE.REVERSED'],true) && $id==='')throw new RuntimeException('Refund sale binding not yet known. Reconcile provider events.');
    return $id;
}

function route_paypal_webhook(string $method): never
{
    if($method!=='POST'){http_response_code(405);header('Allow: POST');exit;}
    if(!paypal_ready()){http_response_code(503);exit('PayPal not configured');}
    $raw=(string)file_get_contents('php://input',false,null,0,1048577);$headers=[];foreach(['transmission-id','transmission-time','cert-url','auth-algo','transmission-sig'] as $h)$headers['paypal-'.$h]=(string)($_SERVER['HTTP_PAYPAL_'.strtoupper(str_replace('-','_',$h))] ?? '');
    try {
        if(!paypal_signature_valid($raw,$headers)){http_response_code(403);exit('Invalid signature');}$e=json_decode($raw,true);
        if(!preg_match('/^[A-Za-z0-9-]{1,100}$/D',(string)($e['id'] ?? ''))){http_response_code(400);exit;}
        db()->exec('BEGIN IMMEDIATE');$q=db()->prepare('SELECT id FROM paypal_events WHERE id=?');$q->execute([$e['id']]);
        if(!$q->fetch()) {
            $type=(string)($e['event_type'] ?? '');$r=$e['resource'] ?? [];$id=paypal_event_subscription($type,$r);
            if($id!==''){
                paypal_sync($id);
                if(in_array($type,['PAYMENT.SALE.REFUNDED','PAYMENT.SALE.REVERSED'],true))db()->prepare("UPDATE subscriptions SET paypal_payment_hold=1,status=CASE WHEN status IN ('canceled','incomplete_expired') THEN status ELSE 'past_due' END WHERE provider='paypal' AND paypal_subscription_id=?")->execute([$id]);
            }
            db()->prepare('INSERT INTO paypal_events(id,event_type)VALUES(?,?)')->execute([$e['id'],$type]);
        }
        db()->exec('COMMIT');echo 'ok';
    }catch(Throwable){try{db()->exec('ROLLBACK');}catch(Throwable){}http_response_code(500);echo 'Retry later';}exit;
}

function route_paypal(string $method): never
{
    $u=require_login();$uid=(int)$u['id'];$s=billing_account($uid);
    if($method==='POST') {
        header('Cache-Control: no-store');$action=(string)($_POST['action'] ?? '');
        try {
            if($action==='create'){$id=paypal_create($uid,(string)($_POST['plan'] ?? ''));header('Content-Type: application/json');echo json_encode(['subscriptionId'=>$id]);exit;}
            if($s['provider']!=='paypal' || !$s['paypal_subscription_id'])throw new RuntimeException('Kein eigenes PayPal-Abo verknüpft.');
            if($action==='cancel') {
                if(($_POST['confirm_cancel'] ?? '')!=='1')throw new RuntimeException('Kündigung und möglichen sofortigen Zugriffsverlust ausdrücklich bestätigen.');
                paypal_request('POST','/v1/billing/subscriptions/'.$s['paypal_subscription_id'].'/cancel',['reason'=>'Account owner explicitly confirmed cancellation.']);
            }elseif($action!=='refresh')throw new RuntimeException('Ungültige Aktion.');
            db()->exec('BEGIN IMMEDIATE');paypal_sync($s['paypal_subscription_id']);db()->exec('COMMIT');flash('Aktueller PayPal-Status geprüft.');redirect('/billing/paypal');
        }catch(Throwable $e){try{db()->exec('ROLLBACK');}catch(Throwable){}if($action==='create'){http_response_code(409);header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()]);exit;}flash($e->getMessage(),'err');redirect('/billing/paypal');}
    }
    $body='<h1>PayPal-Abrechnung</h1><p><a href="/billing">Alle Abrechnungsoptionen</a></p><p class="notice">Ein SaaS-Abo für dein Betreiberkonto. Keine Kundenzahlung an der Kasse. Ein Planwechsel kündigt niemals automatisch ein bestehendes Abo. Kündigung kann den Schreibzugriff sofort sperren; ein neues Abo benötigt eine neue Zustimmung bei PayPal.</p>';
    if(!paypal_ready())$body.='<p class="notice">PayPal ist nicht eingerichtet. Es werden keine Zahlungen ausgelöst. Setup: docs/PAYPAL.md</p>';
    $body.='<section class="panel"><h2>Aktuelles Abo</h2><p>Plan: '.h($s['plan_key'] ?: 'Keiner').' · Status: '.h($s['status']).' · Anbieter: '.h($s['provider']).'</p>';
    if($s['provider']==='paypal' && $s['paypal_subscription_id'])$body.='<form method="post">'.csrf_field().'<button class="btn ghost" name="action" value="refresh">Status bei PayPal prüfen</button></form><h3>PayPal-Abo kündigen</h3><p>Kündigung ist nicht zum Monatsende vorgemerkt. Sobald PayPal das Abo als beendet meldet, sind betriebliche Änderungen gesperrt. Daten bleiben lesbar.</p><form method="post" class="form">'.csrf_field().'<label class="check"><input type="checkbox" name="confirm_cancel" value="1" required> Ich will dieses Abo kündigen und verstehe den möglichen sofortigen Zugriffsverlust. Ein Ersatzabo wird NICHT automatisch abgeschlossen.</label><button class="btn" name="action" value="cancel">PayPal-Abo jetzt kündigen</button></form>';
    if($s['paypal_payment_hold'])$body.='<p class="notice">Rückzahlung/Rückbuchung muss durch den Betreiber der Plattform geprüft werden. Keine automatische Reaktivierung.</p>';
    $body.='</section><section class="panel"><h2>PayPal-Abo auswählen</h2><label>Plan<select id="paypal-plan">';$plans=[];
    foreach(billing_plans() as $key=>$p){try{$pp=paypal_plan($key);}catch(Throwable){continue;}$plans[$key]=$pp;$body.='<option value="'.h($key).'">'.h($p['name'] ?? $key).'</option>';}
    if(!$plans)$body.='<option value="">Kein Plan eingerichtet</option>';
    $body.='</select></label><p>Verbindliche Preise, Steuern und Laufzeit werden im PayPal-Fenster vor deiner Zustimmung angezeigt.</p><p id="paypal-message" role="status">'.(!$plans?'Keine PayPal-Plan-IDs konfiguriert.':'Zahlungsoption wird geprüft.').'</p><paypal-button id="paypal-subscribe" type="subscribe" hidden></paypal-button></section>';
    if(paypal_ready() && $plans){$body.='<script type="application/json" id="paypal-config">'.json_encode(['clientId'=>config('paypal.client_id'),'csrf'=>csrf_token(),'plans'=>$plans],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS).'</script><script src="/assets/paypal.js"></script><script async src="'.(config('paypal.mode','sandbox')==='live'?'https://www.paypal.com':'https://www.sandbox.paypal.com').'/web-sdk/v6/core" onload="loyaltyPayPalLoaded()"></script>';}
    page('PayPal-Abrechnung',$body,$u);exit;
}
