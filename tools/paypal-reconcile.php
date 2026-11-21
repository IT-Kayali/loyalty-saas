#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/public/index.php';
$options=getopt('',['user:','release-hold','reason:']);$uid=(int)($options['user'] ?? 0);$reason=trim((string)($options['reason'] ?? ''));
if($uid<1){fwrite(STDERR,"Usage: php tools/paypal-reconcile.php --user=ID [--release-hold --reason=CASE_REFERENCE]\nRead-only provider refresh by default. Release only after an operator resolved the refund/reversal outside this tool.\n");exit(2);}
try {
    $s=billing_account($uid);if($s['provider']!=='paypal' || !$s['paypal_subscription_id'])throw new RuntimeException('No linked PayPal subscription.');
    $release=array_key_exists('release-hold',$options);
    if($release && (strlen($reason)<8 || strlen($reason)>200))throw new RuntimeException('Hold release requires a case reference/reason of 8 to 200 bytes. Never include credentials.');
    db()->exec('BEGIN IMMEDIATE');
    if($release)db()->prepare('UPDATE subscriptions SET paypal_payment_hold=0 WHERE user_id=?')->execute([$uid]);
    paypal_sync($s['paypal_subscription_id']);$fresh=billing_account($uid);
    if($release && $fresh['status']!=='active')throw new RuntimeException('Provider did not confirm an active subscription. Hold release rolled back.');
    audit_log($uid,$release?'paypal.hold.operator_release':'paypal.operator_refresh',['reason'=>$release?$reason:'provider reconciliation','subscription_id'=>$s['paypal_subscription_id']]);
    db()->exec('COMMIT');echo 'Account '.$uid.': '.$fresh['status'].', hold '.(int)$fresh['paypal_payment_hold'].PHP_EOL;
}catch(Throwable $e){try{db()->exec('ROLLBACK');}catch(Throwable){}fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
