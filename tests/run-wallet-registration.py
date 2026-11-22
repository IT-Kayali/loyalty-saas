#!/usr/bin/env python3
"""APNs stale-response fences and conservative 410 cleanup. No provider calls."""
import json, os, pathlib, shutil, subprocess, tempfile, time
R = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')
with tempfile.TemporaryDirectory(prefix='loyalty-wallet-registration-') as temp:
 A = pathlib.Path(temp)
 for d in ['app', 'public']: shutil.copytree(R/d, A/d)
 for f in ['seed.php', 'config.example.php']: shutil.copy(R/f, A/f)
 (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['base_url']='https://wallet.example.test';$c['apple_wallet']['pass_type_id']='pass.test.loyalty';return $c;")
 subprocess.run([PHP, 'seed.php'], cwd=A, check=True, capture_output=True)
 def sql(code):
  return subprocess.check_output([PHP, '-r', "require 'public/index.php';"+code], cwd=A, text=True).strip()
 # Model precisely the preceding release, not merely a fresh installation.
 sql("db()->exec(\"INSERT INTO pass_devices(device_library_id,pass_type_id,serial,push_token,created_at,registration_revision) SELECT 'upgrade-existing','pass.test.loyalty',code,'legacy-token','2020-01-01 00:00:00',17 FROM members LIMIT 1;UPDATE apple_pass_clock SET revision=19;ALTER TABLE pass_devices DROP COLUMN registration_generation;ALTER TABLE pass_devices DROP COLUMN registered_at_ms;\");")
 (A/'upgrade.php').write_text("<?php file_put_contents('ready-'.$argv[1],'1');$end=microtime(true)+10;while(!is_file('upgrade-go')){if(microtime(true)>$end)exit(3);usleep(1000);}require 'public/index.php';echo json_encode([db()->query(\"SELECT id,push_token,created_at,registration_revision,registration_generation,registered_at_ms FROM pass_devices WHERE device_library_id='upgrade-existing'\")->fetch(PDO::FETCH_ASSOC),(int)db()->query('SELECT revision FROM apple_pass_clock')->fetchColumn()]);")
 workers = [subprocess.Popen([PHP, 'upgrade.php', str(i)], cwd=A, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for i in range(6)]
 try:
  end = time.monotonic()+10
  while len(list(A.glob('ready-*'))) != 6:
   assert time.monotonic()<end, 'migration start barrier timed out'
   time.sleep(.005)
  (A/'upgrade-go').write_text('1')
  for worker in workers:
   output, error = worker.communicate(timeout=15)
   assert worker.returncode == 0, error
   row, clock = json.loads(output)
   assert row == {'id':1,'push_token':'legacy-token','created_at':'2020-01-01 00:00:00','registration_revision':17,'registration_generation':0,'registered_at_ms':0} and clock == 19, (row, clock)
 finally:
  for worker in workers:
   if worker.poll() is None: worker.kill(); worker.wait()
 print('PASS six barrier-synchronized upgrades preserve legacy identity, token, catch-up and clock', flush=True)
 (A/'reregister.php').write_text("<?php require 'public/index.php';apple_pass_register($argv[1],(string)config('apple_wallet.pass_type_id'),$argv[2],$argv[3]);echo 'registered';")
 code = r'''<?php
require 'public/index.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$n=0;function yes($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$db=db();$serial=$db->query('SELECT code FROM members ORDER BY id LIMIT 1')->fetchColumn();$type=(string)config('apple_wallet.pass_type_id');$expires=time()+3600;
function row(string $device):array{$q=db()->prepare('SELECT * FROM pass_devices WHERE device_library_id=?');$q->execute([$device]);return $q->fetch()?:[];}
function backdate(string $device,int $at):void{db()->prepare('UPDATE pass_devices SET registered_at_ms=? WHERE device_library_id=?')->execute([$at,$device]);}
function rawreg(string $device,string $type,string $serial,string $token):void{db()->prepare('INSERT INTO pass_devices(device_library_id,pass_type_id,serial,push_token)VALUES(?,?,?,?)')->execute([$device,$type,$serial,$token]);}
function signal(string $serial,string $token,array $response):array{global $expires;return apple_pass_push_tokens($serial,[$token],$expires,fn()=>$response);}
$db->exec('DELETE FROM pass_devices');
$cases=[[400,'BadDeviceToken'],[400,'DeviceTokenNotForTopic'],[410,'Unregistered'],[410,'ExpiredToken']];
foreach($cases as [$status,$reason])foreach(['same','changed','aba','reinsert','additional'] as $kind){
 $db->exec('DELETE FROM pass_devices');$token='race-token';$oldAt=(int)floor(microtime(true)*1000)-3000;
 apple_pass_register('target',$type,$serial,$token);backdate('target',$oldAt);$before=row('target');
 apple_pass_register('stale-duplicate',$type,$serial,$token);backdate('stale-duplicate',$oldAt);
 rawreg('other-serial',$type,'other-serial',$token);rawreg('other-type','pass.other.type',$serial,$token);
 $calls=0;$counts=apple_pass_push_tokens($serial,[$token,$token],$expires,function()use(&$calls,$db,$kind,$serial,$type,$token,$oldAt,$status,$reason){
  $calls++;
  if($kind==='additional')apple_pass_register('fresh-device',$type,$serial,$token);
  else{
   if($kind==='reinsert')$db->exec("DELETE FROM pass_devices WHERE device_library_id='target'");
   if($kind==='aba')apple_pass_register('target',$type,$serial,'temporary-token');
   apple_pass_register('target',$type,$serial,$kind==='changed'?'replacement-token':$token);
   // Deliberately indistinguishable wall times: only the monotonic fence can help.
   if($kind==='same')backdate('target',$oldAt);
  }
  return ['status'=>$status,'body'=>['reason'=>$reason,'timestamp'=>$oldAt+1000]];
 });
 $fresh=row($kind==='additional'?'fresh-device':'target');
 yes($counts===['accepted'=>0,'rejected'=>1,'unknown'=>0]&&$calls===1&&$fresh&&$fresh['registration_generation']>$before['registration_generation']&&$fresh['push_token']===($kind==='changed'?'replacement-token':$token)&&!row('stale-duplicate')&&row('other-serial')&&row('other-type'),'fresh '.$kind.' survives while captured duplicate is removed, scoped and not retried: '.$status.' '.$reason);
 if(in_array($kind,['same','changed','aba'],true))yes($fresh['id']===$before['id']&&$fresh['created_at']===$before['created_at']&&$fresh['registration_revision']===$before['registration_revision'],'retry keeps row identity, creation and catch-up: '.$kind.' '.$reason);
 if($kind==='reinsert')yes($fresh['id']!==$before['id'],'deleted and reinserted identity is new: '.$reason);
 if($kind==='additional')yes(!row('target'),'preexisting target removed without erasing additional device: '.$reason);
}
// Real second PHP process: no write lock or transaction may span provider I/O.
foreach([400,410] as $status){
 $db->exec('DELETE FROM pass_devices');apple_pass_register('parallel',$type,$serial,'parallel-token');$old=row('parallel');backdate('parallel',(int)floor(microtime(true)*1000)-5000);
 $counts=apple_pass_push_tokens($serial,['parallel-token'],$expires,function()use($serial,$status){
  $p=proc_open([PHP_BINARY,'reregister.php','parallel',$serial,'parallel-token'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if(!is_resource($p))throw new RuntimeException('worker start failed');
  stream_set_blocking($pipes[1],false);$out='';$end=microtime(true)+5;
  do{$out.=stream_get_contents($pipes[1]);$state=proc_get_status($p);if(!$state['running'])break;usleep(1000);}while(microtime(true)<$end);
  if($state['running'])proc_terminate($p,9);
  $out.=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);
  if($out!=='registered')throw new RuntimeException('parallel registration blocked or failed: '.$error);
  return ['status'=>$status,'body'=>['reason'=>$status===400?'BadDeviceToken':'Unregistered','timestamp'=>(int)floor(microtime(true)*1000)]];
 });
 yes($counts['rejected']===1&&row('parallel')['registration_generation']>$old['registration_generation'],'separate process can commit same-token refresh before '.$status.' cleanup');
}
$db->exec('DELETE FROM pass_devices');
$now=(int)floor(microtime(true)*1000);
$timings=[
 ['valid older', $now-3000, $now-2000, true],
 ['newer registration', $now-1000, $now-2000, false],
 ['same millisecond', $now-2000, $now-2000, false],
 ['legacy unknown time', 0, $now-2000, false],
 ['missing', $now-3000, null, false],
 ['zero', $now-3000, 0, false],
 ['negative', $now-3000, -1, false],
 ['string', $now-3000, (string)($now-2000), false],
 ['float', $now-3000, (float)($now-2000), false],
 ['boolean', $now-3000, true, false],
 ['array', $now-3000, [], false],
 ['future', $now-3000, $now+3600000, false],
 ['huge', $now-3000, PHP_INT_MAX, false],
 ['seconds not milliseconds', $now-3000, time()-10, false],
];
foreach(['Unregistered','ExpiredToken'] as $reason)foreach($timings as [$label,$at,$invalid,$remove]){
 apple_pass_register('timing',$type,$serial,'timing-token');backdate('timing',$at);
 $body=['reason'=>$reason];if($label!=='missing')$body['timestamp']=$invalid;
 $counts=signal($serial,'timing-token',['status'=>410,'body'=>$body]);
 yes($counts['rejected']===1&&((!row('timing'))===$remove),'410 '.$reason.' timestamp: '.$label);
 $db->exec("DELETE FROM pass_devices WHERE device_library_id='timing'");
}
rawreg('legacy400',$type,$serial,'legacy400-token');signal($serial,'legacy400-token',['status'=>400,'body'=>['reason'=>'BadDeviceToken']]);yes(!row('legacy400'),'legacy generation zero still permits a current 400 cleanup');
foreach([['status'=>410,'body'=>'invalid-json-shape'],['status'=>410,'body'=>['reason'=>'Unexpected','timestamp'=>$now]],['status'=>403,'body'=>['reason'=>'Unregistered','timestamp'=>$now]]] as $i=>$response){
 apple_pass_register('malformed'.$i,$type,$serial,'malformed'.$i);backdate('malformed'.$i,$now-3000);$counts=signal($serial,'malformed'.$i,$response);
 yes($counts['rejected']===1&&row('malformed'.$i),'malformed body or mismatched status/reason is nondestructive '.$i);
}
// Simulate a restore epoch switch plus a lower clock. An old response must not match.
$db->exec('DELETE FROM pass_devices');apple_pass_register('epoch',$type,$serial,'epoch-token');$before=row('epoch');
$counts=apple_pass_push_tokens($serial,['epoch-token'],$expires,function()use($db,$serial,$type){
 $db->prepare('UPDATE apple_pass_clock SET generation=?,revision=0 WHERE id=1')->execute([bin2hex(random_bytes(32))]);
 apple_pass_register('epoch',$type,$serial,'epoch-token');
 return ['status'=>400,'body'=>['reason'=>'BadDeviceToken']];
});
yes($counts['rejected']===1&&row('epoch')&&row('epoch')['registration_generation']<$before['registration_generation'],'clock epoch change prevents stale cleanup even if counter values are reused');
$before=row('epoch');$clock=$db->query('SELECT revision FROM apple_pass_clock WHERE id=1')->fetchColumn();$failed=false;
try{apple_pass_register('nonexistent',$type,'missing-member','unused');}catch(InvalidArgumentException $e){$failed=true;}
yes($failed&&!row('nonexistent')&&$db->query('SELECT revision FROM apple_pass_clock WHERE id=1')->fetchColumn()===$clock,'failed registration rolls back its clock tick');
$db->exec("CREATE TRIGGER registration_test_failure BEFORE UPDATE ON pass_devices BEGIN SELECT RAISE(ABORT,'test failure');END");$failed=false;
try{apple_pass_register('epoch',$type,$serial,'changed');}catch(PDOException $e){$failed=true;}
$db->exec('DROP TRIGGER registration_test_failure');yes($failed&&row('epoch')===$before&&$db->query('SELECT revision FROM apple_pass_clock WHERE id=1')->fetchColumn()===$clock,'failed upsert atomically preserves metadata and clock');
$db->exec('DELETE FROM apple_pass_clock');$calls=0;$counts=apple_pass_push_tokens($serial,['epoch-token'],$expires,function()use(&$calls){$calls++;return ['status'=>200];});
yes($calls===0&&$counts===['accepted'=>0,'rejected'=>0,'unknown'=>1]&&row('epoch'),'missing fence fails closed before any provider attempt');
echo "$n APNs registration assertions passed\n";
'''
 (A/'test-registration.php').write_text(code)
 subprocess.run([PHP,'test-registration.php'],cwd=A,check=True)
 print('No real APNs requests, device receipt, trusted certificates or online restore tested.',flush=True)
