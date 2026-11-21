<?php
// Standalone, no network, no Stripe credentials, no production database.
declare(strict_types=1);
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE subscriptions(user_id INTEGER PRIMARY KEY,stripe_customer_id TEXT,stripe_subscription_id TEXT,checkout_session_id TEXT,plan_key TEXT DEFAULT '',status TEXT DEFAULT 'none',cancel_at_period_end INTEGER DEFAULT 0,current_period_end INTEGER,updated_at TEXT)");
$pdo->exec("INSERT INTO subscriptions(user_id,stripe_customer_id,status) VALUES(1,'cus_test','none'),(2,'cus_other','none')");
function db(): PDO { return $GLOBALS['pdo']; }
function config(?string $key=null,mixed $default=null):mixed {
    return ['stripe.secret_key'=>'test-only','stripe.webhook_secret'=>'test-signing-secret',
        'stripe.plans'=>['starter'=>['price_id'=>'price_starter'],'pro'=>['price_id'=>'price_pro']]][$key] ?? $default;
}
function stripe_request(string $method,string $endpoint,array $data=[],string $idempotency=''):array { return $GLOBALS['remote']; }
$source=file_get_contents(__DIR__.'/../app/inc/billing.php');
$source=preg_replace('/^<\?php\s*declare\(strict_types=1\);/','',$source);
$source=str_replace('function stripe_request(', 'function stripe_request_network_unused(', $source);
eval($source);
$count=0;
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['count']++;echo 'PASS '.$label.PHP_EOL;}
$raw='{"id":"evt_unit","type":"test"}';$ts=1800000000;$secret='signing-test-only';$sig=hash_hmac('sha256',$ts.'.'.$raw,$secret);
check(stripe_signature_valid($raw,"t=$ts,v1=$sig",$secret,$ts),'valid raw body signature');
check(!stripe_signature_valid($raw.' ',"t=$ts,v1=$sig",$secret,$ts),'tampered raw body rejected');
check(!stripe_signature_valid($raw,"t=$ts,v1=$sig",$secret,$ts+301),'expired timestamp rejected');
check(!stripe_signature_valid($raw,"t=$ts,v1=$sig",$secret,$ts-301),'future timestamp rejected');
check(!stripe_signature_valid($raw,"t=$ts,v1=$sig",'wrong',$ts),'wrong signing secret rejected');
check(!stripe_signature_valid($raw,'v1='.$sig,$secret,$ts),'missing timestamp rejected');
check(!stripe_signature_valid($raw,'',$secret,$ts),'missing signature rejected');
check(stripe_signature_valid($raw,"t=$ts,v1=bad,v1=$sig",$secret,$ts),'multiple signatures support rotation');
check(!stripe_signature_valid($raw,"t=$ts,v1=$sig",'',$ts),'empty secret rejected');
check(billing_entitled(['status'=>'active']),'active subscription grants access');
check(billing_entitled(['status'=>'trialing']),'trial grants access');
foreach(['none','past_due','unpaid','canceled','incomplete','paused'] as $status)check(!billing_entitled(['status'=>$status]),$status.' does not grant access');
$remote=['id'=>'sub_test','customer'=>'cus_test','metadata'=>['user_id'=>'1'],'status'=>'active','items'=>['data'=>[['price'=>['id'=>'price_starter']]]],'current_period_end'=>1800000300,'cancel_at_period_end'=>false];
billing_sync_subscription('sub_test');$s=billing_account(1);
check($s['status']==='active' && $s['plan_key']==='starter','subscription becomes active from authoritative state');
check(billing_account(2)['status']==='none','other tenant is unaffected');
$remote['status']='past_due';billing_sync_subscription('sub_test');check(billing_account(1)['status']==='past_due','failed-payment state reflected');
$remote['items']['data'][0]['price']['id']='price_pro';$remote['status']='active';$remote['cancel_at_period_end']=true;billing_sync_subscription('sub_test');
check(billing_account(1)['plan_key']==='pro','portal plan change synced');
check((int)billing_account(1)['cancel_at_period_end']===1,'scheduled cancellation synced');
$remote['metadata']['user_id']='2';$remote['status']='canceled';billing_sync_subscription('sub_test');check(billing_account(1)['status']==='active','wrong tenant metadata ignored');
$remote['metadata']['user_id']='1';$remote['customer']='cus_unknown';billing_sync_subscription('sub_test');check(billing_account(1)['status']==='active','unrelated Stripe customer ignored');
$remote['customer']='cus_test';$remote['items']['data'][0]['price']['id']='price_unknown';
try {billing_sync_subscription('sub_test');check(false,'unknown price rejected');}catch(RuntimeException){check(true,'unknown price rejected for retry');}
check(billing_account(1)['plan_key']==='pro','unknown price does not corrupt local plan');
$remote['items']['data'][0]['price']['id']='price_pro';$remote['metadata']['user_id']='1';billing_sync_subscription('sub_test');check(billing_account(1)['status']==='canceled','final cancellation synced');
$remote['id']='sub_new';$remote['status']='active';billing_sync_subscription('sub_new');check(billing_account(1)['stripe_subscription_id']==='sub_new','new subscription after cancellation accepted');
$remote['id']='sub_test';$remote['status']='canceled';billing_sync_subscription('sub_test');check(billing_account(1)['stripe_subscription_id']==='sub_new' && billing_account(1)['status']==='active','late old-subscription event cannot cancel new subscription');
echo $count.' assertions passed'.PHP_EOL;
