#!/usr/bin/env python3
"""PassKit revision/cursor/cache contracts. Self-signed local fixtures, never real Apple access."""
import io,json,os,pathlib,shutil,subprocess,tempfile,time,urllib.request,urllib.error,zipfile
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(value,label):
 global n
 assert value,label
 n+=1;print('PASS',label,flush=True)

with tempfile.TemporaryDirectory(prefix='loyalty-pass-service-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public','tools']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['base_url']='https://wallet.example.test';$c['apple_wallet']['pass_type_id']='pass.test.loyalty';$c['apple_wallet']['team_id']='TESTTEAM01';$c['apple_wallet']['cert_path']=__DIR__.'/test-cert.pem';$c['apple_wallet']['wwdr_path']=__DIR__.'/test-cert.pem';$c['apple_wallet']['key_path']=__DIR__.'/test-key.pem';return $c;")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 # Reproduce the released pre-versioning schema, then open it from four PHP processes.
 sql("db()->exec(\"DROP TABLE apple_pass_versions;DROP TABLE apple_pass_clock;DROP TABLE pass_devices;CREATE TABLE pass_devices(id INTEGER PRIMARY KEY AUTOINCREMENT,device_library_id TEXT NOT NULL,pass_type_id TEXT NOT NULL,serial TEXT NOT NULL,push_token TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT(datetime('now')),UNIQUE(device_library_id,pass_type_id,serial));\");")
 migration=[PHP,'-r',"require 'public/index.php';echo json_encode([array_column(db()->query('PRAGMA table_info(pass_devices)')->fetchAll(),'name'),db()->query('SELECT length(generation) FROM apple_pass_clock WHERE id=1')->fetchColumn(),db()->query('SELECT COUNT(*) FROM members')->fetchColumn()]);"]
 starters=[subprocess.Popen(migration,cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(4)]
 for starter in starters:
  output,error=starter.communicate(timeout=15);assert starter.returncode==0,error;state=json.loads(output);assert 'registration_revision' in state[0] and state[1]==64 and state[2]==4,state
 check(True,'four concurrent upgrades preserve data and initialize one complete sync schema')
 code=r'''<?php
require 'public/index.php';
$n=0;function yes($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$key=openssl_pkey_new(['private_key_bits'=>2048]);$csr=openssl_csr_new(['commonName'=>'LOCAL TEST ONLY'],$key);$cert=openssl_csr_sign($csr,null,$key,1);openssl_x509_export_to_file($cert,'test-cert.pem');openssl_pkey_export_to_file($key,'test-key.pem');
$codes=db()->query('SELECT code FROM members ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);$a=$codes[0];$b=$codes[1];$c=$codes[2];$type='pass.test.loyalty';
function snap($code){return apple_pass_snapshot(pass_member($code));}
function change($code){$m=pass_member($code);db()->prepare('UPDATE cards SET stamps=stamps+1 WHERE id=?')->execute([$m['cards'][0]['id']]);}
$x=snap($a);yes(preg_match('/^[a-f0-9]{64}$/D',$x['generation'])===1&&$x['revision']>0,'persistent random sync generation and first monotonic revision');
yes(snap($a)['revision']===$x['revision'],'unchanged snapshot never advances revision');$y=snap($b);yes($y['revision']>$x['revision'],'revisions increase across different passes');
yes($x['date_safe']===0&&!apple_pass_not_modified($x,null,gmdate(DATE_RFC7231,$x['modified_at'])),'first-seen legacy pass cannot falsely304 from an old date');
yes(apple_pass_not_modified($x,$x['etag'],null),'matching weak ETag validates unchanged content');
yes(apple_pass_not_modified($x,substr($x['etag'],2),null),'weak comparison accepts matching strong ETag');
yes(apple_pass_not_modified($x,'"other", '.$x['etag'],null)&&apple_pass_not_modified($x,'*',null),'ETag list and wildcard supported');
$safe=$x;$safe['date_safe']=1;$safe['modified_at']=time()-5;$date=gmdate(DATE_RFC7231,$safe['modified_at']);
yes(apple_pass_not_modified($safe,null,$date),'unambiguous modification date supports304');
yes(!apple_pass_not_modified($safe,'"different"',$date),'stale ETag overrides an otherwise matching date');
yes(!apple_pass_not_modified($safe,null,'yesterday')&&!apple_pass_not_modified($safe,null,'invalid'),'malformed/relative dates are ignored');
yes(!apple_pass_not_modified($safe,null,gmdate(DATE_RFC7231,time()+3600)),'future dates cannot hide current changes');
$unsafe=$safe;$unsafe['date_safe']=0;yes(!apple_pass_not_modified($unsafe,null,$date),'ambiguous same-second dates always return a body');
yes(!apple_pass_register('deviceA',$type,$a,'fixture-token-a'),'first authenticated registration is new');$first=db()->query("SELECT * FROM pass_devices WHERE device_library_id='deviceA'")->fetch();
yes(apple_pass_register('deviceA',$type,$a,'refreshed-token-a'),'registration retry is existing');$again=db()->query("SELECT * FROM pass_devices WHERE device_library_id='deviceA'")->fetch();
yes($again['id']===$first['id']&&$again['registration_revision']===$first['registration_revision']&&$again['created_at']===$first['created_at']&&$again['push_token']==='refreshed-token-a','token refresh preserves identity/time/catch-up revision');
apple_pass_register('deviceA',$type,$b,'fixture-token-b');apple_pass_register('deviceB',$type,$c,'fixture-token-c');
$all=apple_pass_changes('deviceA',$type,null);$expected=[$a,$b];sort($expected);yes($all['serialNumbers']===$expected,'first poll includes only this device registered passes');$tag=$all['lastUpdated'];
yes(apple_pass_changes('deviceA',$type,$tag)===null,'unchanged device has no matching passes');
change($a);$changed=apple_pass_changes('deviceA',$type,$tag);yes($changed['serialNumbers']===[$a]&&$changed['lastUpdated']!==$tag,'only one changed registered pass returned with newer cursor');
$prior=snap($a);change($a);$next=snap($a);change($a);$last=snap($a);yes($last['revision']>$next['revision']&&$next['revision']>$prior['revision'],'rapid changes have distinct monotonic revisions even in one second');
$future=time()+3600;db()->prepare('UPDATE apple_pass_versions SET date_highwater=? WHERE member_id=?')->execute([$future,pass_member($b)['id']]);change($b);$back=snap($b);change($b);$backAgain=snap($b);yes($back['date_safe']===0&&$backAgain['date_safe']===0&&$backAgain['date_highwater']===$future,'backwards clock preserves date high-water across subsequent changes');
yes(apple_pass_changes('deviceA',$type,'2026-11-22T00:00:00+00:00')['serialNumbers']===$expected,'legacy ISO timestamp safely resynchronizes all registered passes');
yes(apple_pass_changes('deviceA',$type,'0000000000000000.1')['serialNumbers']===$expected,'foreign generation safely resynchronizes');
yes(apple_pass_changes('deviceA',$type,apple_pass_cursor(PHP_INT_MAX,'deviceA',$type,$last['generation']))['serialNumbers']===$expected,'future cursor safely resynchronizes');
$decoded=apple_pass_cursor_revision($tag,'deviceA',$type,$x['generation']);yes(is_int($decoded)&&!str_contains($tag,$x['generation'])&&!str_contains($x['etag'],$x['generation']),'device cursor and ETag hide synchronization secret and global counter');
$tampered=$tag;$tampered[12]=$tag[12]==='A'?'B':'A';yes(apple_pass_cursor_revision($tampered,'deviceA',$type,$x['generation'])===null,'tampered cursor fails authentication');
yes(apple_pass_cursor_revision($tag,'deviceB',$type,$x['generation'])===null&&apple_pass_cursor_revision($tag,'deviceA','pass.other',$x['generation'])===null,'cursor is bound to exact device and pass type');
yes(apple_pass_changes('unknown',$type,null)===null&&apple_pass_changes('deviceA','pass.other',null)===null,'unknown device and wrong type have no matching rows');
$recent=apple_pass_changes('deviceA',$type,null)['lastUpdated'];$old=snap($c);apple_pass_register('deviceA',$type,$c,'fixture-copy');$catchup=apple_pass_changes('deviceA',$type,$recent);yes($catchup['serialNumbers']===[$c],'newly registered old pass catches up beyond device previous cursor');
$stable=snap($a);$_SESSION['lang']='en';$m=pass_member($a);yes(snap($a)['revision']===$stable['revision']&&apple_pass_json($m)['storeCard']['primaryFields'][0]['label']==='STEMPEL'&&$_SESSION['lang']==='en','viewer language cannot mutate issuer pass or stored language preference');
$asset=$m;$asset['design']['logo']='fixture-logo.png';$folder=config('upload_dir');if(!is_dir($folder))mkdir($folder,0775,true);file_put_contents($folder.'/fixture-logo.png','one');$hash=apple_pass_content_hash($asset);file_put_contents($folder.'/fixture-logo.png','two');yes($hash!==apple_pass_content_hash($asset),'same-name asset byte changes invalidate pass content');
$original=file_get_contents('test-cert.pem');$hash=apple_pass_content_hash($m);file_put_contents('test-cert.pem',$original."\n");yes($hash!==apple_pass_content_hash($m),'signing certificate changes invalidate pass content');file_put_contents('test-cert.pem',$original);
$clock=db()->query('SELECT revision FROM apple_pass_clock')->fetchColumn();change($a);db()->exec("CREATE TRIGGER test_version_fail BEFORE INSERT ON apple_pass_versions BEGIN SELECT RAISE(ABORT,'test failure');END");try{snap($a);$failed=false;}catch(Throwable $e){$failed=true;}yes($failed&&db()->query('SELECT revision FROM apple_pass_clock')->fetchColumn()===$clock,'metadata failure rolls back global clock atomically');db()->exec('DROP TRIGGER test_version_fail');snap($a);
// Optional message changes are visible to the revision hash, not just balances.
$m=pass_member($a);$bid=(int)$m['business_id'];$mid=(int)$m['id'];$owner=(int)db()->query('SELECT user_id FROM businesses WHERE id='.$bid)->fetchColumn();$business=own_business($owner,$bid);$settings=wallet_push_settings($business);$settings['controller']='Demo GmbH, Testweg 1';$settings['contact']='privacy@example.test';$settings['privacy_url']='https://example.test/privacy';$settings['revision']=wallet_push_revision($business);wallet_push_settings_save($owner,$bid,$settings);
$choice=wallet_push_choice($mid,'apple');wallet_push_member_save($m['email'],$mid,['provider'=>'apple','enabled'=>'1','confirm'=>'1','choice_revision'=>(string)$choice['revision'],'notice_hash'=>wallet_push_hash(wallet_push_notice($bid,'apple'))]);
$before=snap($a);$review=wallet_message_preview($owner,$bid,['member_id'=>(string)$mid,'provider'=>'apple','header'=>'','body'=>'Wichtige lokale Testinformation','valid_minutes'=>'15']);wallet_message_send($owner,$bid,$review,fn()=>['state'=>'rejected','code'=>'apple_rejected']);$message=snap($a);yes($message['revision']>$before['revision'],'new published Apple message changes revision even after rejected signal');
db()->prepare("INSERT INTO wallet_push_deliveries(id,user_id,business_id,member_id,provider,header,body,content_hash,expires_at,notice_hash,choice_revision,state,published)SELECT 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',user_id,business_id,member_id,provider,header,'Kurz sichtbare Testinformation',content_hash,datetime('now','+1 second'),notice_hash,choice_revision,'rejected',1 FROM wallet_push_deliveries WHERE id=?")->execute([$review['id']]);$expiring=snap($a);sleep(2);yes(snap($a)['revision']>$expiring['revision'],'message expiry changes freshly observed pass revision without a resend');
$beforeWithdrawal=snap($a);wallet_push_member_save($m['email'],$mid,['provider'=>'apple','enabled'=>'0']);yes(snap($a)['revision']>$beforeWithdrawal['revision'],'message withdrawal changes pass revision');
$beforeClock=db()->query('SELECT revision FROM apple_pass_clock')->fetchColumn();db()->exec("CREATE TRIGGER test_registration_fail BEFORE INSERT ON pass_devices BEGIN SELECT RAISE(ABORT,'test failure');END");try{apple_pass_register('failedDevice',$type,$a,'fixture');$failed=false;}catch(Throwable $e){$failed=true;}yes($failed&&db()->query('SELECT revision FROM apple_pass_clock')->fetchColumn()===$beforeClock,'registration failure atomically rolls back catch-up clock');db()->exec('DROP TRIGGER test_registration_fail');
$deletedMember=(int)pass_member($c)['id'];snap($c);$beforeClock=db()->query('SELECT revision FROM apple_pass_clock')->fetchColumn();db()->exec('SAVEPOINT test_delete');$GLOBALS['privacy_delete_authorized']=true;db()->exec('DELETE FROM members WHERE id='.$deletedMember);yes(db()->query('SELECT COUNT(*) FROM apple_pass_versions WHERE member_id='.$deletedMember)->fetchColumn()===0&&db()->query('SELECT revision FROM apple_pass_clock')->fetchColumn()===$beforeClock,'member deletion cascades its local version without resetting global clock');db()->exec('ROLLBACK TO test_delete; RELEASE test_delete');$GLOBALS['privacy_delete_authorized']=false;
file_put_contents('http-fixture.json',json_encode(['code'=>$a,'other'=>$b,'third'=>$c,'token'=>pass_auth_token(pass_member($a)),'member_id'=>$mid]));
echo "$n PassKit domain assertions passed\n";
'''
 (A/'test-pass-service.php').write_text(code)
 subprocess.run([PHP,'test-pass-service.php'],cwd=A,check=True)
 fixture=json.loads((A/'http-fixture.json').read_text());code=fixture['code'];auth={'Authorization':'ApplePass '+fixture['token']};typ='pass.test.loyalty'
 sql("$m=pass_member('"+code+"');db()->prepare('UPDATE cards SET stamps=stamps+1 WHERE id=?')->execute([$m['cards'][0]['id']]);")
 command=[PHP,'-r',"require 'public/index.php';$m=pass_member('"+code+"');echo json_encode(apple_pass_snapshot($m));"]
 workers=[subprocess.Popen(command,cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(2)]
 states=[]
 for worker in workers:
  output,error=worker.communicate(timeout=15);assert worker.returncode==0,error;states.append(json.loads(output))
 check(states[0]['revision']==states[1]['revision'] and states[0]['content_hash']==states[1]['content_hash'],'parallel snapshot refresh has one shared committed revision')
 def req(path,headers=None,body=None,method=None):
  request=urllib.request.Request('http://127.0.0.1:8099'+path,headers=headers or {},data=body,method=method)
  try:
   with urllib.request.urlopen(request,timeout=15) as r:return r.status,r.read(),r.headers
  except urllib.error.HTTPError as r:return r.code,r.read(),r.headers
 log=open(A/'server.log','w');server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  path=f'/api/pass/v1/passes/{typ}/{code}';reg=f'/api/pass/v1/devices/httpDevice/registrations/{typ}/{code}';poll=f'/api/pass/v1/devices/httpDevice/registrations/{typ}'
  check(req(path)[0]==401,'pass body requires pass authorization')
  check(req(path,{'Authorization':'ApplePass wrong'})[0]==401,'wrong pass token rejected')
  check(req(path.replace(typ,'pass.other'),auth)[0]==404,'wrong pass type rejected')
  status,body,headers=req(path,auth);check(status==200 and body[:2]==b'PK','authorized updated pass returns signed local-fixture ZIP')
  with zipfile.ZipFile(io.BytesIO(body)) as z:
   payload=json.loads(z.read('pass.json'));check(payload['serialNumber']==code and 'signature' in z.namelist(),'fixture package contains matching pass and signature, no Apple acceptance implied')
  tag=headers['ETag'];modified=headers['Last-Modified'];check(headers['Cache-Control']=='private, no-cache' and headers['Vary']=='Authorization','private response validates every reuse')
  unchanged=req(path,{**auth,'If-None-Match':tag})
  check(unchanged[:2]==(304,b'') and unchanged[2]['Content-Type']=='application/vnd.apple.pkpass','matching ETag returns304 without body and retains correct pass media type')
  check(req(path,{'If-None-Match':tag})[0]==401,'validator cannot bypass authorization')
  check(req(path,{**auth,'If-None-Match':'"stale"','If-Modified-Since':modified})[0]==200,'stale ETag takes precedence over date on HTTP route')
  sql('db()->exec("UPDATE apple_pass_versions SET modified_at=strftime(\'%s\',\'now\')-5,date_highwater=strftime(\'%s\',\'now\')-5,date_safe=1 WHERE member_id='+str(fixture['member_id'])+'");')
  _,_,date_headers=req(path,auth)
  check(req(path,{**auth,'If-Modified-Since':date_headers['Last-Modified']})[:2]==(304,b''),'unambiguous HTTP modification date returns304')
  sql('db()->exec("UPDATE apple_pass_versions SET modified_at=strftime(\'%s\',\'now\')+3600,date_highwater=strftime(\'%s\',\'now\')+3600 WHERE member_id='+str(fixture['member_id'])+'");')
  _,_,future_headers=req(path,auth)
  import email.utils
  check(email.utils.parsedate_to_datetime(future_headers['Last-Modified']).timestamp()<=time.time(),'clock rollback never emits a future Last-Modified')
  os.rename(A/'test-cert.pem',A/'temporarily-missing.pem')
  try:check(req(path,{**auth,'If-None-Match':tag})[0]==501,'signing readiness is checked before a conditional304')
  finally:os.rename(A/'temporarily-missing.pem',A/'test-cert.pem')
  check(req('/pass/apple/'+code+'.pkpass',{'If-None-Match':tag})[0]==304,'direct pass route uses same stable semantic validator')
  for invalid in [b'{}',b'null',b'{"pushToken":[]}',b'{"pushToken":"bad token"}',json.dumps({'pushToken':'x'*513}).encode()]:
   check(req(reg,{**auth,'Content-Type':'application/json'},invalid)[0]==400,'malformed registration token rejected')
  check(req(reg,{'Content-Type':'application/json'},b'{"pushToken":"fixture"}')[0]==401,'registration requires pass authorization')
  good=b'{"pushToken":"http-fixture-token"}';check(req(reg,{**auth,'Content-Type':'application/json'},good)[0]==201,'new HTTP registration returns201')
  check(req(reg,{**auth,'Content-Type':'application/json'},good)[0]==200,'registration retry returns200 without replacing row')
  status,body,hh=req(poll);changes=json.loads(body);check(status==200 and changes['serialNumbers']==[code] and hh['Cache-Control']=='private, no-store','device poll returns only its registered serial privately')
  check(req(poll+'?passesUpdatedSince='+changes['lastUpdated'])[:2]==(204,b''),'unchanged HTTP poll returns204 and no body')
  check(req(poll+'?passesUpdatedSince%5B%5D=x')[0]==400,'array cursor rejected at HTTP boundary')
  check(req(poll.replace(typ,'pass.other'))[0]==404,'poll cannot read a different pass type')
  check(req(poll.replace('httpDevice','absentDevice'))[0]==204,'unknown device poll has no matches')
  before=sql('echo db()->query("SELECT revision FROM apple_pass_clock")->fetchColumn();')
  sql("$m=pass_member('"+code+"');db()->prepare('UPDATE cards SET stamps=stamps+1 WHERE id=?')->execute([$m['cards'][0]['id']]);")
  status,body,hh=req(path,{**auth,'If-None-Match':tag});check(status==200 and hh['ETag']!=tag,'changed balance cannot return304 for previous ETag')
  check(int(sql('echo db()->query("SELECT revision FROM apple_pass_clock")->fetchColumn();'))>int(before),'HTTP refresh persists its higher change revision')
  check(req(reg,auth,method='DELETE')[0]==200 and req(poll)[0]==204,'unregistration stops this device receiving serial list')
 finally:server.terminate();server.wait();log.close()
 # A generation change prevents old device cursors/ETags surviving our supported restore flow.
 archive=str(A.parent/(A.name+'-backup.zip'))
 try:
  old=sql('echo db()->query("SELECT generation FROM apple_pass_clock")->fetchColumn();')
  subprocess.run([PHP,'tools/backup.php','backup',archive],cwd=A,check=True,capture_output=True)
  subprocess.run([PHP,'tools/backup.php','restore',archive,'--yes'],cwd=A,check=True,capture_output=True)
  state=json.loads(sql('echo json_encode([db()->query("SELECT generation FROM apple_pass_clock")->fetchColumn(),db()->query("SELECT COUNT(*) FROM apple_pass_versions")->fetchColumn(),db()->query("SELECT MAX(registration_revision) FROM pass_devices")->fetchColumn()]);'))
  check(state[0]!=old and state[1]==0 and state[2]==0,'supported restore rotates generation and clears only synchronization metadata')
  check(sql('echo db()->query("SELECT COUNT(*) FROM members")->fetchColumn();')=='4','restore metadata reset preserves actual members')
 finally:pathlib.Path(archive).unlink(missing_ok=True)
 print(n,'PassKit HTTP/restore assertions passed')
