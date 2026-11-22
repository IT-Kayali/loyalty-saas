#!/usr/bin/env python3
"""Certificate APNs contracts, activation and failure isolation. Fake transports only."""
import os,pathlib,shutil,subprocess,tempfile
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='loyalty-wallet-apns-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 for f in ['cert.pem','key.pem','wwdr.pem']:(A/f).write_text('LOCAL NONSECRET TEST FIXTURE, NOT A CERTIFICATE')
 (A/'config.php').write_text("""<?php
$c=require __DIR__.'/config.example.php';$c['base_url']='https://wallet.example.test';
$c['wallet_messages']['enabled']=true;
$c['apple_wallet']['pass_type_id']='pass.test.loyalty';
foreach(['cert_path'=>'cert.pem','key_path'=>'key.pem','wwdr_path'=>'wwdr.pem'] as $key=>$file)$c['apple_wallet'][$key]=__DIR__.'/'.$file;
$c['apple_wallet']['key_password']='test-only-phrase';
$mode=getenv('APNS_TEST_MODE')?:'enabled';$c['apple_wallet']['updates_enabled']=$mode!=='disabled';
if($mode==='http')$c['base_url']='http://wallet.example.test';
if($mode==='missing')$c['apple_wallet']['key_path']=__DIR__.'/not-present.pem';
return $c;
""")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 code=r'''<?php
require 'public/index.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$n=0;function yes($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$db=db();$m=pass_member($db->query('SELECT code FROM members WHERE business_id=1 LIMIT 1')->fetchColumn());$serial=$m['code'];$type=(string)config('apple_wallet.pass_type_id');
function reg(string $device,string $type,string $serial,string $token):void{db()->prepare('INSERT INTO pass_devices(device_library_id,pass_type_id,serial,push_token)VALUES(?,?,?,?)')->execute([$device,$type,$serial,$token]);}
function countreg(string $type,string $serial,string $token):int{$q=db()->prepare('SELECT COUNT(*) FROM pass_devices WHERE pass_type_id=? AND serial=? AND push_token=?');$q->execute([$type,$serial,$token]);return (int)$q->fetchColumn();}
yes(apple_push_ready()&&wallet_message_readiness('apple')===null,'explicit activation, HTTPS and local files pass setup gate without claiming certificate validity');
yes(!apple_push_enabled_settings([]),'no flag and no deliberate legacy setup stays disabled');
yes(apple_push_enabled_settings(['updates_enabled'=>true]),'explicit boolean true selects production intent');
yes(!apple_push_enabled_settings(['updates_enabled'=>'true'])&&!apple_push_enabled_settings(['updates_enabled'=>1]),'string and numeric truthiness cannot silently activate sends');
$legacy=ROOT_DIR.'/legacy-intent.p8';file_put_contents($legacy,'NOT A REAL SECRET');chmod($legacy,0000);
$settings=['apns_key_id'=>'LEGACYKEY','apns_topic'=>$type,'apns_auth_key'=>$legacy,'apns_sandbox'=>false];
yes(apple_push_enabled_settings($settings),'legacy deliberate production intent remains recognized without reading p8 contents');
yes(!apple_push_enabled_settings(array_replace($settings,['apns_sandbox'=>true])),'legacy sandbox is never silently promoted to production');
yes(!apple_push_enabled_settings(array_replace($settings,['updates_enabled'=>false])),'explicit false overrides legacy production setup');
yes(apple_push_enabled_settings(array_replace($settings,['updates_enabled'=>true,'apns_sandbox'=>true])),'explicit new activation overrides obsolete sandbox metadata and selects production');
yes(!apple_push_enabled_settings(array_replace($settings,['apns_topic'=>'']))&&!apple_push_enabled_settings(array_replace($settings,['apns_key_id'=>''])),'partial legacy metadata is not activation');
chmod($legacy,0600);unlink($legacy);yes(!apple_push_enabled_settings($settings),'missing legacy key is not past production intent');
$expires=time()+1234;$wire=apple_pass_push_options($expires);$headers=$wire[CURLOPT_HTTPHEADER];
yes($wire[CURLOPT_POST]===true&&$wire[CURLOPT_POSTFIELDS]==='{}','signal payload is an empty JSON dictionary, never message text');
yes($wire[CURLOPT_HTTP_VERSION]===CURL_HTTP_VERSION_2_0&&$wire[CURLOPT_SSLVERSION]===CURL_SSLVERSION_TLSv1_2,'HTTP2 and TLS1.2 minimum selected');
yes($wire[CURLOPT_SSLCERT]===config('apple_wallet.cert_path')&&$wire[CURLOPT_SSLKEY]===config('apple_wallet.key_path')&&$wire[CURLOPT_KEYPASSWD]==='test-only-phrase','same pass certificate, private key and optional passphrase selected');
yes($wire[CURLOPT_SSL_VERIFYPEER]===true&&$wire[CURLOPT_SSL_VERIFYHOST]===2&&$wire[CURLOPT_FOLLOWLOCATION]===false,'peer and host verification stay on, redirects forbidden');
yes($wire[CURLOPT_TIMEOUT]===8&&$wire[CURLOPT_CONNECTTIMEOUT]===4,'bounded request and connect timeout');
yes(in_array('apns-topic: '.$type,$headers,true)&&in_array('apns-push-type: background',$headers,true)&&in_array('apns-priority: 5',$headers,true),'pass-type topic, background type and priority5 selected');
yes(in_array('apns-expiration: '.$expires,$headers,true)&&!str_contains(implode('\n',$headers),'Authorization:'),'exact expiry and no p8/JWT authorization header');
$calls=[];$counts=apple_pass_push_tokens($serial,['token-ok','token-ok','token/a+b',[],null,true,'has space',str_repeat('x',513)],$expires,function($url,$options)use(&$calls,$wire){$calls[]=[$url,$options];return ['status'=>200];});
yes($counts===['accepted'=>2,'rejected'=>5,'unknown'=>0]&&count($calls)===2,'deduplicate valid strings after validation, invalid arrays/scalars never reach transport');
yes($calls[0][0]==='https://api.push.apple.com/3/device/token-ok'&&$calls[1][0]==='https://api.push.apple.com/3/device/token%2Fa%2Bb'&&$calls[1][1]===$wire,'fixed production URL, URL-encoded token and shared reviewed options');
$cases=[200,400,403,410,410,429,500,503,0,201,302];$calls=0;
$counts=apple_pass_push_tokens($serial,array_map(fn($i)=>'status-'.$i,array_keys($cases)),$expires,function()use(&$calls,$cases){return ['status'=>$cases[$calls++]];});
yes($counts===['accepted'=>1,'rejected'=>5,'unknown'=>5]&&$calls===count($cases),'only200 accepted,4xx rejected, other responses unknown, one attempt per token');
$calls=0;$counts=apple_pass_push_tokens($serial,['throwing'],$expires,function()use(&$calls){$calls++;throw new RuntimeException('not stored');});
yes($counts===['accepted'=>0,'rejected'=>0,'unknown'=>1]&&$calls===1,'transport exception stays unknown and is never retried');
$counts=apple_pass_push_tokens($serial,['malformed'],$expires,fn()=>false);yes($counts['unknown']===1,'nonarray callback response cannot claim acceptance');
$calls=0;$counts=apple_pass_push_tokens($serial,[],$expires,function()use(&$calls){$calls++;});yes(array_sum($counts)===0&&$calls===0,'empty target set does not call transport');
foreach([[400,'BadDeviceToken'],[400,'DeviceTokenNotForTopic'],[410,'Unregistered'],[410,'ExpiredToken']] as $i=>[$status,$reason]){
 $token='bad-'.$i;reg('target-'.$i,$type,$serial,$token);reg('target-duplicate-'.$i,$type,$serial,$token);reg('other-serial-'.$i,$type,'other-serial',$token);reg('other-type-'.$i,'pass.other.type',$serial,$token);
 $counts=apple_pass_push_tokens($serial,[$token],$expires,fn()=>['status'=>$status,'body'=>['reason'=>$reason]]);
 yes($counts['rejected']===1&&countreg($type,$serial,$token)===0&&countreg($type,'other-serial',$token)===1&&countreg('pass.other.type',$serial,$token)===1,'invalid registration cleanup scoped to exact serial/type/token case'.$i);
}
reg('retain-auth',$type,$serial,'keep-auth');reg('retain-json',$type,$serial,'keep-json');
apple_pass_push_tokens($serial,['keep-auth'],$expires,fn()=>['status'=>403,'body'=>['reason'=>'BadCertificate']]);
apple_pass_push_tokens($serial,['keep-json'],$expires,fn()=>['status'=>400,'body'=>['reason'=>'Unexpected']]);
yes(countreg($type,$serial,'keep-auth')===1&&countreg($type,$serial,'keep-json')===1,'certificate or unrecognized errors do not erase device registrations');
$db->exec('DELETE FROM pass_devices');reg('balance-one',$type,$serial,'balance-token');reg('balance-dupe',$type,$serial,'balance-token');reg('balance-other-type','pass.other.type',$serial,'wrong-type');reg('balance-other-serial',$type,'other-serial','wrong-serial');
$calls=[];$before=time();$deliveries=(int)$db->query('SELECT COUNT(*) FROM wallet_push_deliveries')->fetchColumn();
apns_push_serial($serial,function($url,$options)use(&$calls){$calls[]=[$url,$options];return ['status'=>200];});
yes(count($calls)===1&&str_ends_with($calls[0][0],'/balance-token'),'balance wrapper selects distinct tokens for configured pass type and serial only');
$expiry=array_values(array_filter($calls[0][1][CURLOPT_HTTPHEADER],fn($v)=>str_starts_with($v,'apns-expiration: ')))[0];$expiry=(int)substr($expiry,17);
yes($expiry>=$before+3600&&$expiry<=time()+3600,'balance hint has bounded one-hour lifetime');
yes((int)$db->query('SELECT COUNT(*) FROM wallet_push_deliveries')->fetchColumn()===$deliveries&&wallet_message_apple_field($m)['value']==='Keine aktuelle Mitteilung','balance update cannot publish optional text or create an optional handoff');
// Force the Apple query to fail before any network request. The booked balance stays available.
$card=(int)$m['cards'][0]['id'];$balance=$db->query('SELECT * FROM cards WHERE id='.$card)->fetch();$db->exec('SAVEPOINT apns_failure;DROP TABLE pass_devices');
wallet_pass_update_for_card($card);yes($db->query('SELECT * FROM cards WHERE id='.$card)->fetch()===$balance,'Apple failure does not break or undo an already recorded card action');$db->exec('ROLLBACK TO apns_failure;RELEASE apns_failure');
// Optional transport still checks a fresh choice immediately before the shared signal helper.
$business=own_business(1,1);$settings=wallet_push_settings($business);$settings['controller']='APNs-Testbetrieb';$settings['contact']='datenschutz@apns.example.test';$settings['privacy_url']='https://apns.example.test/privacy';$settings['revision']=wallet_push_revision($business);wallet_push_settings_save(1,1,$settings);
$mid=(int)$m['id'];$choice=wallet_push_choice($mid,'apple');wallet_push_member_save($m['email'],$mid,['provider'=>'apple','enabled'=>'1','confirm'=>'1','choice_revision'=>(string)$choice['revision'],'notice_hash'=>wallet_push_hash(wallet_push_notice(1,'apple'))]);
$review=wallet_message_preview(1,1,['member_id'=>(string)$mid,'provider'=>'apple','header'=>'','body'=>'Zeitkritische Karteninformation','valid_minutes'=>'60']);
$calls=[];$result=wallet_message_apple($review,$m,['optional-one'],function($url,$options)use(&$calls){$calls[]=[$url,$options];return ['status'=>200];});
yes($result['state']==='accepted'&&count($calls)===1&&$calls[0][1]===wallet_message_apple_options($review),'optional wrapper shares certificate transport and exact reviewed expiry');
$result=wallet_message_apple($review,$m,[],fn()=>['status'=>200]);yes($result['state']==='rejected','zero optional targets never count as accepted');
$i=0;$result=wallet_message_apple($review,$m,['optional-one','optional-two'],function()use(&$i){return ['status'=>++$i===1?200:503];});yes($result['state']==='partial','mixed optional acceptance is partial');
$result=wallet_message_apple($review,$m,['optional-one'],fn()=>['status'=>503]);yes($result['state']==='unknown','optional server failure stays unknown');
$result=wallet_message_apple($review,$m,['optional-one'],fn()=>['status'=>403]);yes($result['state']==='rejected','optional4xx stays rejected');
wallet_push_member_save($m['email'],$mid,['provider'=>'apple','enabled'=>'0']);$calls=0;$result=wallet_message_apple($review,$m,['optional-one'],function()use(&$calls){$calls++;return ['status'=>200];});
yes($result['state']==='rejected'&&$calls===0,'withdrawal before optional handoff prevents even a fake provider call');
echo "$n APNs domain assertions passed\n";
'''
 (A/'test-apns.php').write_text(code)
 subprocess.run([PHP,'test-apns.php'],cwd=A,check=True)
 for mode in ['disabled','http','missing']:
  env=dict(os.environ,APNS_TEST_MODE=mode)
  code="require 'public/index.php';if(apple_push_ready()||wallet_message_readiness('apple')===null)throw new RuntimeException('gate failed');$calls=0;apns_push_serial('fake',function()use(&$calls){$calls++;});if($calls)throw new RuntimeException('disabled dispatch');echo 'PASS configuration gate';"
  code=code.replace("echo 'PASS configuration gate';","echo 'PASS configuration gate';")
  done=subprocess.run([PHP,'-r',code],cwd=A,env=env,check=True,capture_output=True,text=True);assert done.stdout=='PASS configuration gate',done.stdout
  print('PASS',mode,'blocks balance and optional readiness before transport',flush=True)
 print('No real APNs requests, valid certificates or device delivery tested.',flush=True)
