#!/usr/bin/env python3
"""Single-member Wallet handoff, replay protection and provider contracts, using fake transports only."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='loyalty-wallet-message-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['apple_wallet']['pass_type_id']='pass.test.loyalty';return $c;")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 code=r'''<?php
require 'public/index.php';
$n=0;function yes($value,$why){global $n;if(!$value)throw new RuntimeException($why);$n++;echo "PASS $why\n";}
function denied(callable $fn){try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
function approve(int $mid,string $p):void{$m=db()->query('SELECT * FROM members WHERE id='.$mid)->fetch();$c=wallet_push_choice($mid,$p);wallet_push_member_save($m['email'],$mid,['provider'=>$p,'enabled'=>'1','confirm'=>'1','choice_revision'=>(string)$c['revision'],'notice_hash'=>wallet_push_hash(wallet_push_notice((int)$m['business_id'],$p))]);}
function proposal(int $mid,string $text='Heute geänderte Bedingungen',string $p='google'):array{return wallet_message_preview(1,1,['member_id'=>(string)$mid,'provider'=>$p,'header'=>'Karteninformation','body'=>$text,'valid_minutes'=>'60']);}
$mid=(int)db()->query("SELECT id FROM members WHERE business_id=1 AND email='aylin@example.com'")->fetchColumn();$ben=(int)db()->query("SELECT id FROM members WHERE business_id=1 AND email='ben@example.com'")->fetchColumn();
$gm=pass_member(wallet_message_member(1,$mid)['code']);$gp=google_pass_payload($gm);yes(array_keys($gp['class'])===['id'],'GenericClass uses only documented neutral identity, branding remains object-specific');
$patch=google_object_patch($gp['object']);yes(array_key_exists('logo',$patch)&&$patch['logo']===null&&array_key_exists('heroImage',$patch)&&$patch['heroImage']===null,'PATCH explicitly clears removed object images using JSON null');
yes(!array_key_exists('messages',$patch)&&!array_key_exists('hasUsers',$patch),'balance PATCH leaves provider messages and ownership state untouched');
$gm['design']['logo']='test-logo.png';$gm['design']['stamp_image']='test-stamps.png';$gp=google_pass_payload($gm);$patch=google_object_patch($gp['object']);yes(isset($patch['logo']['sourceUri']['uri'],$patch['heroImage']['sourceUri']['uri'])&&array_keys($gp['class'])===['id'],'configured logo and hero image remain object-specific in PATCH');
$b=own_business(1,1);$s=wallet_push_settings($b);$s['controller']='Demo GmbH, Beispielweg 1';$s['contact']='privacy@example.test';$s['privacy_url']='https://example.test/privacy';$s['revision']=wallet_push_revision($b);wallet_push_settings_save(1,1,$s);
yes(denied(fn()=>proposal($mid)),'cannot preview without current provider choice');approve($mid,'google');approve($mid,'apple');approve($ben,'google');
yes(wallet_message_readiness('google')!==null,'server default keeps optional sends disabled');
foreach([['provider'=>''],['provider'=>'other'],['member_id'=>'0'],['member_id'=>'999999'],['body'=>''],['body'=>'<b>HTML</b>'],['body'=>str_repeat('x',501)],['header'=>str_repeat('x',81)],['valid_minutes'=>'0'],['valid_minutes'=>['60']],['provider'=>['google']]] as $bad){$input=array_merge(['member_id'=>(string)$mid,'provider'=>'google','header'=>'Karteninfo','body'=>'Text','valid_minutes'=>'60'],$bad);yes(denied(fn()=>wallet_message_preview(1,1,$input)),'invalid input or foreign target rejected');}
$v=proposal($mid);yes($v['member_id']===$mid&&$v['provider']==='google'&&$v['review_expires']>time()&&strlen($v['id'])===32,'preview fixes one member/provider and expires');
yes((int)db()->query('SELECT COUNT(*) FROM wallet_push_deliveries')->fetchColumn()===0,'preview has no delivery or provider effect');
yes(denied(fn()=>wallet_message_send(1,1,$v)),'actual default-disabled sender cannot reserve a handoff');
$cache=['token'=>'fixture-token','expires'=>time()+600,'account_hash'=>'account-a'];
yes(google_token_cache_usable($cache,'account-a',time()),'unexpired cache is usable only for its current account');
yes(!google_token_cache_usable($cache,'account-b',time()),'account change cannot reuse the previous account access token');
unset($cache['account_hash']);yes(!google_token_cache_usable($cache,'account-a',time()),'legacy unbound token cache cannot be reused');
$db=db();$db->exec('CREATE TABLE test_transport_calls(value TEXT)');
$fake=function($v,$m,$tokens){db()->prepare('INSERT INTO test_transport_calls(value)VALUES(?)')->execute([$v['id']]);return ['state'=>'accepted','code'=>'google_accepted'];};
$old=$v;wallet_push_member_save('aylin@example.com',$mid,['provider'=>'google','enabled'=>'0']);yes(denied(fn()=>wallet_message_send(1,1,$old,$fake)),'withdrawal after preview blocks handoff');approve($mid,'google');yes(denied(fn()=>wallet_message_send(1,1,$old,$fake)),'reapproval does not revive old review');
$v=proposal($mid);$db->prepare('UPDATE members SET name=? WHERE id=?')->execute(['Andere Anzeige',$mid]);yes(denied(fn()=>wallet_message_send(1,1,$v,$fake)),'changed recipient details invalidate review');$db->prepare('UPDATE members SET name=? WHERE id=?')->execute(['Aylin Demir',$mid]);
$v=proposal($mid);$b=own_business(1,1);$s=wallet_push_settings($b);$s['google_body']='Eine andere Vorlage';$s['revision']=wallet_push_revision($b);wallet_push_settings_save(1,1,$s);yes(denied(fn()=>wallet_message_send(1,1,$v,$fake)),'changed owner settings invalidate review');
$v=proposal($mid);$v['provider_identity']=str_repeat('0',64);yes(denied(fn()=>wallet_message_send(1,1,$v,$fake)),'changed provider account or signing files invalidate review');
$v=proposal($mid);$v['review_expires']=time()-1;yes(denied(fn()=>wallet_message_send(1,1,$v,$fake)),'expired review rejected');
$v=proposal($mid);$v['expires_at']='2000-01-01 00:00:00';yes(denied(fn()=>wallet_message_send(1,1,$v,$fake)),'expired message rejected');
$v=proposal($mid);yes(denied(fn()=>wallet_message_send(1,2,$v,$fake)),'review cannot be reused for another business');
yes((int)$db->query('SELECT COUNT(*) FROM test_transport_calls')->fetchColumn()===0,'failed revalidation never invokes transport');
$v=proposal($mid,'Einmalige Testmitteilung');$sent=wallet_message_send(1,1,$v,$fake);yes($sent['state']==='accepted'&&$sent['outcome']==='google_accepted','fake successful handoff records technical acceptance');
$again=wallet_message_send(1,1,$v,$fake);yes($again['id']===$sent['id']&&(int)$db->query('SELECT COUNT(*) FROM test_transport_calls')->fetchColumn()===1,'same review replay does not call transport again');
yes(denied(fn()=>wallet_message_send(1,1,proposal($mid,'Einmalige Testmitteilung'),$fake)),'new review with same text is blocked for 24 hours');
$unknown=wallet_message_send(1,1,proposal($mid,'Andere wichtige Information'),fn()=>throw new RuntimeException('provider secret must not persist'));yes($unknown['state']==='unknown'&&$unknown['outcome']==='transport_exception','transport exception remains unknown, no raw exception stored');
$third=wallet_message_send(1,1,proposal($mid,'Dritte Information'),fn()=>['state'=>'rejected','code'=>'google_rejected']);yes($third['state']==='rejected','explicit rejection recorded');
yes(denied(fn()=>wallet_message_send(1,1,proposal($mid,'Vierter Versuch'),$fake)),'three-attempt local rolling-day quota includes unknown/rejected attempts');
// Provider request shape. This helper receives a test-only callback, not a configurable public endpoint.
$g=proposal($ben,'Google Nachricht');$m=wallet_message_member(1,$ben);$calls=[];$object=google_object_id($m);
$http=function($method,$url,$body,$headers)use(&$calls,$object,$g){$calls[]=[$method,$url,$body,$headers];return $method==='GET'?['status'=>200,'body'=>['id'=>$object,'hasUsers'=>true,'messages'=>[['id'=>'unrelated']]]]:['status'=>200,'body'=>['resource'=>['id'=>$object,'messages'=>[['id'=>'loyalty-'.$g['id']]]]]];};
$result=wallet_message_google($g,$m,$http,'fake-token');$payload=json_decode($calls[1][2],true);yes($result['state']==='accepted'&&count($calls)===2,'Google success requires object read and verified AddMessage response');
yes($calls[0][0]==='GET'&&$calls[1][0]==='POST'&&str_ends_with($calls[1][1],'/'.rawurlencode($object).'/addMessage'),'Google is object-specific, no class broadcast, create or PUT');
yes($payload['message']['messageType']==='TEXT_AND_NOTIFY'&&$payload['message']['header']==='Karteninformation'&&$payload['message']['body']==='Google Nachricht'&&isset($payload['message']['displayInterval']['endTime']),'Google uses correct field casing, exact words and bounded display interval');
foreach([['status'=>404,'body'=>[]],['status'=>500,'body'=>[]],['status'=>200,'body'=>['id'=>$object,'hasUsers'=>false]],['status'=>200,'body'=>['id'=>$object,'hasUsers'=>true,'messages'=>array_fill(0,10,[])]]] as $pre){$calls=[];$r=wallet_message_google($g,$m,function($method,$url,$body,$headers)use(&$calls,$pre){$calls[]=$method;return $pre;},'fake-token');yes($r['state']==='rejected'&&$calls===['GET'],'Google failed precheck never posts or removes other messages');}
$calls=[];$r=wallet_message_google($g,$m,function($method,$url,$body,$headers)use(&$calls,$object){$calls[]=$method;return $method==='GET'?['status'=>200,'body'=>['id'=>$object,'hasUsers'=>true]]:['status'=>0,'body'=>[]];},'fake-token');yes($r['state']==='unknown','ambiguous POST result is unknown, not auto-retried');
$calls=[];$r=wallet_message_google($g,$m,function($method,$url,$body,$headers)use(&$calls,$object){$calls[]=$method;return $method==='GET'?['status'=>200,'body'=>['id'=>$object,'hasUsers'=>true]]:['status'=>200,'body'=>['resource'=>['id'=>$object,'messages'=>[]]]];},'fake-token');yes($r['state']==='unknown','unexpected success response cannot claim acceptance');
$calls=[];$r=wallet_message_google($g,$m,function($method,$url,$body,$headers)use(&$calls,$object,$ben){$calls[]=$method;wallet_push_member_save('ben@example.com',$ben,['provider'=>'google','enabled'=>'0']);return ['status'=>200,'body'=>['id'=>$object,'hasUsers'=>true]];},'fake-token');yes($calls===['GET']&&$r['state']==='rejected','withdrawal during Google precheck prevents plaintext message POST');approve($ben,'google');
// Apple publishes a pass field before an APNs handoff. Fake transport only, never a real certificate.
$a=proposal($mid,'Wichtige Apple Änderung','apple');yes(denied(fn()=>wallet_message_send(1,1,$a,$fake)),'Apple requires device registrations even with fake transport');
$db->prepare('INSERT INTO pass_devices(device_library_id,pass_type_id,serial,push_token)VALUES(?,?,?,?)')->execute(['device-test','pass.test.loyalty',wallet_message_member(1,$mid)['code'],'fake-push-token']);
$a=proposal($mid,'Wichtige Apple Änderung','apple');$r=wallet_message_send(1,1,$a,fn()=>['state'=>'rejected','code'=>'apple_rejected']);$field=wallet_message_apple_field(wallet_message_member(1,$mid));yes($r['published']===1&&$r['state']==='rejected'&&$field['value']==='Wichtige Apple Änderung'&&$field['changeMessage']==='%@','Apple content can be fetched even when update signal rejected, reported separately');
wallet_push_member_save('aylin@example.com',$mid,['provider'=>'apple','enabled'=>'0']);yes(!isset(wallet_message_apple_field(wallet_message_member(1,$mid))['changeMessage']),'withdrawal removes issuer message from future Apple payloads');approve($mid,'apple');
yes(wallet_message_apple_field(wallet_message_member(1,$mid))['value']==='Keine aktuelle Mitteilung','reapproval does not republish an old Apple message');
yes(count(wallet_message_export($mid))===4,'member export contains own approved handoff attempts');
$details=$db->query("SELECT details FROM audit_logs WHERE action='wallet.message_handoff'")->fetchAll();yes(!str_contains(json_encode($details),'Testmitteilung')&&!str_contains(json_encode($details),'aylin@'),'audit contains delivery/business identifiers, not message or contact');
try{$db->exec('UPDATE wallet_push_deliveries SET member_id='.$ben);$blocked=false;}catch(Throwable $e){$blocked=true;}yes($blocked,'delivery identity is immutable');
yes(!str_contains(json_encode(wallet_message_export($mid)),'fake-push-token')&&!str_contains(json_encode(wallet_message_export($mid)),'fake-token'),'export contains no device or access token');
yes(wallet_message_cleanup(false)===0,'retention dry run preserves all recent safety records');
$options=wallet_message_apple_options($a);yes($options[CURLOPT_POSTFIELDS]==='{}'&&$options[CURLOPT_SSLCERT]===config('apple_wallet.cert_path')&&$options[CURLOPT_SSLKEY]===config('apple_wallet.key_path')&&$options[CURLOPT_HTTP_VERSION]===CURL_HTTP_VERSION_2_0,'Apple wire options use empty payload, pass certificate/key and HTTP2, not arbitrary alert content');
$before=(int)$db->query('SELECT COUNT(*) FROM wallet_push_deliveries')->fetchColumn();
$db->exec("CREATE TRIGGER fail_handoff_audit BEFORE INSERT ON audit_logs WHEN NEW.action='wallet.message_handoff' BEGIN SELECT RAISE(ABORT,'test failure'); END");
try{wallet_message_send(1,1,proposal($ben,'Audit rollback'),$fake);$failed=false;}catch(Throwable $e){$failed=true;}
yes($failed&&(int)$db->query('SELECT COUNT(*) FROM wallet_push_deliveries')->fetchColumn()===$before,'audit failure rolls back reservation before transport');$db->exec('DROP TRIGGER fail_handoff_audit');
$db->exec("INSERT INTO wallet_push_deliveries(id,user_id,business_id,member_id,provider,header,body,content_hash,expires_at,notice_hash,choice_revision,state,created_at)SELECT '00000000000000000000000000000000',user_id,business_id,member_id,provider,header,body,content_hash,datetime('now','-31 days'),notice_hash,choice_revision,'unknown',datetime('now','-32 days') FROM wallet_push_deliveries LIMIT 1");
yes(wallet_message_cleanup(false)===1&&(int)$db->query('SELECT COUNT(*) FROM wallet_push_deliveries')->fetchColumn()===$before+1,'cleanup dry run counts but does not delete expired old attempts');
yes(wallet_message_cleanup(true)===1&&(int)$db->query('SELECT COUNT(*) FROM wallet_push_deliveries')->fetchColumn()===$before,'cleanup removes old expired attempts but preserves recent quota records');

// Leave a valid customer choice and friendly owner/member records for UI previews.
$db->prepare('UPDATE members SET name=? WHERE id=?')->execute(['Aylin Demir',$mid]);echo "$n wallet message domain assertions passed\n";
'''
 (A/'test-message.php').write_text(code)
 subprocess.run([PHP,'test-message.php'],cwd=A,check=True)
 (A/'tools').mkdir();shutil.copy(R/'tools/wallet-message-cleanup.php',A/'tools/wallet-message-cleanup.php')
 for args in [[],['--execute']]:
  done=subprocess.run([PHP,'tools/wallet-message-cleanup.php',*args],cwd=A,check=True,capture_output=True,text=True);assert done.stdout.strip().endswith(': 0'),done.stdout
 print('PASS cleanup CLI dry run and explicit execution on isolated fixtures')
 log=open(A/'server.log','w');s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  subprocess.run(['node',str(R/'tests/wallet-message-ui.cjs')],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
