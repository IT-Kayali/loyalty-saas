#!/usr/bin/env python3
"""Persistent Wallet credentials across API-key rotation. Local fixtures, no provider calls."""
import http.cookiejar,io,json,os,pathlib,re,shutil,sqlite3,subprocess,tempfile,time,urllib.error,urllib.parse,urllib.request,zipfile
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
PREFIX="require 'public/index.php';set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});"
def yes(v,label):
 global n
 assert v,label
 n+=1;print('PASS',label,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-wallet-auth-') as tmp:
 A=pathlib.Path(tmp)
 for d in ['app','public','tools']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['base_url']='https://wallet.example.test';$c['apple_wallet']['pass_type_id']='pass.test.loyalty';$c['apple_wallet']['team_id']='LOCALTEST1';$c['apple_wallet']['cert_path']=__DIR__.'/test-cert.pem';$c['apple_wallet']['wwdr_path']=__DIR__.'/test-cert.pem';$c['apple_wallet']['key_path']=__DIR__.'/test-key.pem';$c['stripe']['enforce_limits']=getenv('WALLET_AUTH_PLAN')==='1';return $c;")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def php(code):return subprocess.check_output([PHP,'-r',PREFIX+code],cwd=A,text=True).strip()
 def barrier(label,i):return f"file_put_contents('{label}.ready.{i}','1');while(!is_file('{label}.go'))usleep(1000);"
 def release(label,count):
  deadline=time.monotonic()+10
  while not all((A/f'{label}.ready.{i}').exists() for i in range(count)):
   assert time.monotonic()<deadline,'workers did not reach barrier'
   time.sleep(.005)
  (A/f'{label}.go').write_text('start')
 # Recreate the previous released schema with legacy HMAC-derived credentials.
 php("$d=db();$out=[];foreach($d->query('SELECT m.id,m.business_id,m.code,b.api_key FROM members m JOIN businesses b ON b.id=m.business_id ORDER BY m.id') as $m){$out[]=['id'=>(int)$m['id'],'business_id'=>(int)$m['business_id'],'code'=>$m['code'],'auth'=>hash_hmac('sha256','pass:'.$m['code'],$m['api_key']),'image'=>hash_hmac('sha256','stamp-image:'.$m['code'],$m['api_key']),'api_key'=>$m['api_key']];}file_put_contents('legacy.json',json_encode($out));$d->exec(\"DROP TABLE member_wallet_auth;DELETE FROM platform_settings WHERE key='migration_wallet_auth_v1'\");")
 legacy=json.loads((A/'legacy.json').read_text());first=legacy[0]
 commands=[PREFIX+"echo db()->query('SELECT COUNT(*) FROM member_wallet_auth')->fetchColumn();"]*2+[PREFIX.replace("public/index.php","app/bootstrap.php")+"echo db()->query('SELECT COUNT(*) FROM member_wallet_auth')->fetchColumn();"]+[PREFIX+"business_api_key_regenerate(1,1);echo 'rotated';"]
 jobs=[subprocess.Popen([PHP,'-r',barrier('migration',i)+code],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for i,code in enumerate(commands)];release('migration',len(jobs))
 for i,job in enumerate(jobs):
  out,err=job.communicate(timeout=15);yes(job.returncode==0 and out==('rotated' if i==3 else str(len(legacy))),'concurrent migration/rotation worker'+str(i)+' preserves a complete credential set')
 code=r'''<?php
require 'public/index.php';set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$n=0;function yes($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$db=db();$legacy=json_decode(file_get_contents('legacy.json'),true);$first=$legacy[0];
foreach($legacy as $old){$m=pass_member($old['code']);yes(pass_auth_token($m)===$old['auth']&&wallet_image_token($m)===$old['image'],'migration preserves both legacy purposes for member'.$old['id']);}
yes((int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn()===count($legacy),'migration changes no memberships');
yes(!in_array('auth_token',array_column($db->query('PRAGMA table_info(members)')->fetchAll(),'name'),true),'credentials live outside broad member rows');
$m=pass_member($first['code']);$token=pass_auth_token($m);$image=wallet_image_token($m);$snapshot=apple_pass_snapshot($m);$payload=apple_pass_json($m);$beforeKey=$m['api_key'];$deliveries=(int)$db->query('SELECT COUNT(*) FROM wallet_push_deliveries')->fetchColumn();
yes(business_api_key_regenerate(1,1),'owner can rotate business API key');$fresh=pass_member($m['code']);
yes((int)$db->query('SELECT COUNT(*) FROM wallet_push_deliveries')->fetchColumn()===$deliveries,'API rotation creates no optional Wallet delivery');
yes($fresh['api_key']!==$beforeKey&&pass_auth_token($fresh)===$token&&wallet_image_token($fresh)===$image,'API key changes while both Wallet credentials stay stable');
yes(apple_pass_json($fresh)===$payload&&apple_pass_snapshot($fresh)['etag']===$snapshot['etag'],'API rotation alone changes no Apple payload, revision or ETag');
yes(business_api_key_regenerate(1,1)&&pass_auth_token(pass_member($m['code']))===$token,'repeated rotations retain installed pass authentication');
$otherKey=$db->query('SELECT api_key FROM businesses WHERE id=2')->fetchColumn();business_api_key_regenerate(1,1);yes($db->query('SELECT api_key FROM businesses WHERE id=2')->fetchColumn()===$otherKey,'rotation does not change another business API key');
$rows=$db->query('SELECT * FROM member_wallet_auth ORDER BY member_id')->fetchAll();wallet_auth_migrate($db);yes($db->query('SELECT * FROM member_wallet_auth ORDER BY member_id')->fetchAll()===$rows,'migration is idempotent and keeps first credentials');
$before=$db->query('SELECT api_key FROM businesses WHERE id=1')->fetchColumn();yes(!business_api_key_regenerate(999999,1)&&!business_api_key_regenerate(1,999999),'foreign or missing business cannot rotate');yes($db->query('SELECT api_key FROM businesses WHERE id=1')->fetchColumn()===$before,'rejected owner leaves actual key unchanged');
$db->exec("CREATE TRIGGER fail_api_audit BEFORE INSERT ON audit_logs WHEN NEW.action='api.key_regenerated' BEGIN SELECT RAISE(ABORT,'fixture audit failure'); END");try{business_api_key_regenerate(1,1);$failed=false;}catch(Throwable $e){$failed=true;}$db->exec('DROP TRIGGER fail_api_audit');
yes($failed&&$db->query('SELECT api_key FROM businesses WHERE id=1')->fetchColumn()===$before&&$db->query('SELECT * FROM member_wallet_auth ORDER BY member_id')->fetchAll()===$rows,'audit failure rolls key rotation back without disturbing credentials');
$audits=$db->query("SELECT details FROM audit_logs WHERE action='api.key_regenerated'")->fetchAll();$safe=true;foreach($audits as $audit)if(json_decode($audit['details'],true)!==['business_id'=>1])$safe=false;yes(count($audits)>=4&&$safe,'rotation audit contains business reference only, no credentials');
try{$db->prepare('UPDATE member_wallet_auth SET auth_token=? WHERE member_id=?')->execute([str_repeat('a',64),$m['id']]);$blocked=false;}catch(Throwable $e){$blocked=true;}yes($blocked&&pass_auth_token($m)===$token,'stored pass token cannot be silently overwritten');
try{$db->prepare('UPDATE member_wallet_auth SET image_token=? WHERE member_id=?')->execute([str_repeat('b',64),$m['id']]);$blocked=false;}catch(Throwable $e){$blocked=true;}yes($blocked&&wallet_image_token($m)===$image,'stored image capability cannot be silently overwritten');
try{wallet_member_credential($m,'untrusted-column');$blocked=false;}catch(InvalidArgumentException $e){$blocked=true;}yes($blocked,'credential purpose is a closed allowlist');
$db->prepare('INSERT INTO members(business_id,name,email,code)VALUES(1,?,?,?)')->execute(['Neues Testmitglied','new@wallet.example.test','ABCDEF0123']);$new=pass_member('ABCDEF0123');$before=(int)$db->query('SELECT COUNT(*) FROM member_wallet_auth')->fetchColumn();
$newToken=pass_auth_token($new);$newImage=wallet_image_token($new);yes(preg_match('/^[a-f0-9]{64}$/D',$newToken)&&$newToken!==$newImage,'new member has separate random 256-bit pass and image tokens');
yes($newToken!==hash_hmac('sha256','pass:'.$new['code'],$new['api_key'])&&$newImage!==hash_hmac('sha256','stamp-image:'.$new['code'],$new['api_key']),'new credentials no longer derive from API key');
yes((int)$db->query('SELECT COUNT(*) FROM member_wallet_auth')->fetchColumn()===$before+1&&pass_auth_token($new)===$newToken,'first use creates one stable row, repeat reads reuse it');
business_api_key_regenerate(1,1);yes(pass_auth_token(pass_member($new['code']))===$newToken&&wallet_image_token(pass_member($new['code']))===$newImage,'new member also survives API rotation');
$new['design']['stamp_image']='fixture.png';$object=google_pass_payload($new)['object'];yes(str_contains($object['heroImage']['sourceUri']['uri'],'/'.$newImage.'.png'),'Google image URL uses its own persistent image credential');
$serialized=json_encode(member_api_payload($m));yes(!str_contains($serialized,$token)&&!str_contains($serialized,$image),'member API payload exposes neither credential');
$id=(int)$new['id'];$db->prepare('DELETE FROM members WHERE id=?')->execute([$id]);$q=$db->prepare('SELECT COUNT(*) FROM member_wallet_auth WHERE member_id=?');$q->execute([$id]);yes((int)$q->fetchColumn()===0,'member deletion cascades private credential row');
$db->exec('UPDATE businesses SET api_key=NULL WHERE id=2');$db->prepare('INSERT INTO members(business_id,name,email,code)VALUES(2,?,?,?)')->execute(['Uninitialisierter Test','initial@wallet.example.test','ABCDEF0125']);$initial=pass_member('ABCDEF0125');$initialToken=pass_auth_token($initial);$initialImage=wallet_image_token($initial);
yes($initialToken!==hash_hmac('sha256','pass:'.$initial['code'],'')&&$initialImage!==hash_hmac('sha256','stamp-image:'.$initial['code'],''),'new member with missing API key never receives predictable empty-key credentials');wallet_auth_fill_empty_business_keys($db);yes(pass_auth_token(pass_member($initial['code']))===$initialToken,'later API-key initialization leaves independently created credential unchanged');
// Failed legacy migration must preserve both key and all-or-nothing credential state.
$p=new PDO('sqlite:'.ROOT_DIR.'/failed-migration.sqlite');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$p->exec("CREATE TABLE businesses(id INTEGER PRIMARY KEY,api_key TEXT);CREATE TABLE members(id INTEGER PRIMARY KEY,business_id INTEGER,code TEXT);CREATE TABLE platform_settings(key TEXT PRIMARY KEY,value TEXT);CREATE TABLE member_wallet_auth(member_id INTEGER PRIMARY KEY,auth_token TEXT,image_token TEXT);INSERT INTO businesses VALUES(1,'legacy-test-key');INSERT INTO members VALUES(1,1,'1000000001'),(2,1,'1000000002');CREATE TRIGGER fail_migration BEFORE INSERT ON member_wallet_auth WHEN NEW.member_id=2 BEGIN SELECT RAISE(ABORT,'fixture migration failure');END");
try{wallet_auth_migrate($p);$failed=false;}catch(Throwable $e){$failed=true;}
yes($failed&&(int)$p->query('SELECT COUNT(*) FROM member_wallet_auth')->fetchColumn()===0&&(int)$p->query('SELECT COUNT(*) FROM platform_settings')->fetchColumn()===0,'migration failure rolls back all credentials and completion marker');
yes($p->query('SELECT api_key FROM businesses')->fetchColumn()==='legacy-test-key','migration failure never changes business API key');$p->exec('DROP TRIGGER fail_migration');wallet_auth_migrate($p);yes((int)$p->query('SELECT COUNT(*) FROM member_wallet_auth')->fetchColumn()===2,'failed migration can be retried completely');
$p->exec("UPDATE platform_settings SET value='invalid' WHERE key='migration_wallet_auth_v1'");try{wallet_auth_migrate($p);$failed=false;}catch(Throwable $e){$failed=true;}yes($failed&&(int)$p->query('SELECT COUNT(*) FROM member_wallet_auth')->fetchColumn()===2,'corrupt migration marker fails closed instead of silently replacing credentials');
$p->exec("UPDATE platform_settings SET value='1';UPDATE businesses SET api_key=NULL;CREATE TRIGGER fail_key_fill BEFORE UPDATE OF api_key ON businesses BEGIN SELECT RAISE(ABORT,'fixture initialization failure');END");try{wallet_auth_fill_empty_business_keys($p);$failed=false;}catch(Throwable $e){$failed=true;}yes($failed&&$p->query('SELECT api_key FROM businesses')->fetchColumn()===null,'missing-key initialization failure rolls back without a partial key');$p->exec('DROP TRIGGER fail_key_fill');wallet_auth_fill_empty_business_keys($p);yes(strlen($p->query('SELECT api_key FROM businesses')->fetchColumn())===48,'failed key initialization can be retried with a random key');
$key=openssl_pkey_new(['private_key_bits'=>2048]);$csr=openssl_csr_new(['commonName'=>'LOCAL WALLET AUTH TEST ONLY'],$key);$cert=openssl_csr_sign($csr,null,$key,1);openssl_x509_export_to_file($cert,'test-cert.pem');openssl_pkey_export_to_file($key,'test-key.pem');
$db->prepare('INSERT INTO members(business_id,name,email,code)VALUES(1,?,?,?)')->execute(['Paralleler Test','parallel@wallet.example.test','ABCDEF0124']);
echo "$n Wallet credential domain assertions passed\n";
'''
 (A/'test-auth.php').write_text(code);subprocess.run([PHP,'test-auth.php'],cwd=A,check=True)
 # Concurrent first use creates one random credential pair, never differing issued tokens.
 jobs=[];pairs=[]
 for i in range(6):
  command=[PHP,'-r',PREFIX+"$m=pass_member('ABCDEF0124');"+barrier('first-use',i)+"echo json_encode([pass_auth_token($m),wallet_image_token($m)]);"]
  jobs.append(subprocess.Popen(command,cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True))
 release('first-use',6)
 for job in jobs:
  out,err=job.communicate(timeout=15);assert job.returncode==0,err;pairs.append(json.loads(out))
 yes(all(p==pairs[0] for p in pairs),'six concurrent first uses converge on exactly one credential pair')
 yes(php("echo db()->query(\"SELECT COUNT(*) FROM member_wallet_auth a JOIN members m ON m.id=a.member_id WHERE m.code='ABCDEF0124'\")->fetchColumn();")=='1','concurrent first use leaves one private row')
 # Missing-key bootstrap is an initializer, not another silent rotation or an empty-key freeze.
 php("db()->exec(\"CREATE TABLE key_fill_events(id INTEGER PRIMARY KEY);CREATE TRIGGER track_empty_fill AFTER UPDATE OF api_key ON businesses WHEN OLD.id=2 AND (OLD.api_key IS NULL OR OLD.api_key='') BEGIN INSERT INTO key_fill_events(id)VALUES(NULL);END;UPDATE businesses SET api_key=NULL WHERE id=2;\");")
 jobs=[subprocess.Popen([PHP,'-r',PREFIX+"db();echo 'ready';"],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(4)]
 for job in jobs:
  out,err=job.communicate(timeout=15);assert job.returncode==0 and out=='ready',err
 yes(php('echo db()->query(\'SELECT COUNT(*) FROM key_fill_events\')->fetchColumn();')=='1','four concurrent missing-key initializers assign only once')
 yes(php("$m=pass_member(db()->query('SELECT code FROM members WHERE business_id=2 ORDER BY id LIMIT 1')->fetchColumn());echo pass_auth_token($m);")==next(x['auth'] for x in legacy if x['business_id']==2),'missing-key initialization keeps an already stored pass credential')
 # New credentials stay off member exports and the existing route retains CSRF/ownership checks.
 php("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Other test owner','other@wallet.example.test',password_hash('unused-local-password',PASSWORD_DEFAULT)]);$u=(int)db()->lastInsertId();db()->prepare('INSERT INTO businesses(user_id,name,brand_color)VALUES(?,?,?)')->execute([$u,'Other test business','#123456']);")
 config=A/'config.php';config.write_text(config.read_text().replace('https://wallet.example.test','http://127.0.0.1:8099'))
 state=json.loads(php("$m=pass_member(db()->query('SELECT code FROM members WHERE business_id=1 ORDER BY id LIMIT 1')->fetchColumn());echo json_encode(['id'=>(int)$m['id'],'code'=>$m['code'],'auth'=>pass_auth_token($m),'image'=>wallet_image_token($m),'api'=>$m['api_key']]);"))
 U='http://127.0.0.1:8099';jar=http.cookiejar.CookieJar();owner=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
 def req(path,headers=None,data=None,opener=None,method=None):
  request=urllib.request.Request(U+path,data=data,headers=headers or {},method=method)
  try:
   with (opener or urllib.request.build_opener()).open(request) as r:return r.status,r.read(),dict(r.headers)
  except urllib.error.HTTPError as e:return e.code,e.read(),dict(e.headers)
 def form(path,fields,opener=owner):return req(path,{'Content-Type':'application/x-www-form-urlencoded'},urllib.parse.urlencode(fields).encode(),opener)
 def csrf(body):return re.search(r'name="csrf" value="([^"]+)"',body.decode()).group(1)
 log=open(A/'server.log','w');server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen(U+'/login');break
   except OSError:time.sleep(.1)
  auth={'Authorization':'ApplePass '+state['auth']};path='/api/pass/v1/passes/pass.test.loyalty/'+state['code'];reg='/api/pass/v1/devices/authTest/registrations/pass.test.loyalty/'+state['code'];image='/pass/stamps/'+state['code']+'/'+state['image']+'.png'
  status,body,headers=req(path,auth);yes(status==200 and json.loads(zipfile.ZipFile(io.BytesIO(body)).read('pass.json'))['authenticationToken']==state['auth'],'installed legacy credential can fetch an actual locally signed pass')
  etag=headers['ETag'];baseline_image=req(image);yes(baseline_image[0]==200 and baseline_image[1].startswith(b'\x89PNG'),'legacy Google stamp image capability works before rotation')
  yes(baseline_image[2]['Cache-Control']=='private, no-cache' and baseline_image[2]['Referrer-Policy']=='no-referrer','tokenized image responses forbid shared caching and referrer disclosure')
  login=req('/login',opener=owner);form('/login',{'csrf':csrf(login[1]),'email':'demo@loyalty.local','password':'demo1234'})
  before=php("echo db()->query('SELECT api_key FROM businesses WHERE id=1')->fetchColumn();")
  yes(form('/apikey/regenerate',{'business_id':'1'})[0]==403 and php("echo db()->query('SELECT api_key FROM businesses WHERE id=1')->fetchColumn();")==before,'rotation route still requires CSRF')
  page=req('/business/1',opener=owner);form('/apikey/regenerate',{'csrf':csrf(page[1]),'business_id':'1'})
  after=php("echo db()->query('SELECT api_key FROM businesses WHERE id=1')->fetchColumn();");yes(after!=before,'existing owner route rotates the real API key')
  yes(req('/api/v1/business',{'Authorization':'Bearer '+before})[0]==401 and req('/api/v1/business',{'Authorization':'Bearer '+after})[0]==200,'old API bearer is rejected and replacement bearer works')
  yes(req(path,{**auth,'If-None-Match':etag})[0]==304,'old installed pass authentication and ETag survive the HTTP API rotation')
  yes(req(reg,{**auth,'Content-Type':'application/json'},b'{"pushToken":"local-auth-fixture"}')[0]==201,'old installed pass can still register a device after rotation')
  after_image=req(image);yes(after_image[0]==200 and after_image[1]==baseline_image[1],'old Google image URL survives API rotation with unchanged PNG content')
  yes(req(path,{'Authorization':'ApplePass '+after})[0]==401 and req(path,{'Authorization':'ApplePass '+before})[0]==401,'API key is not itself an ApplePass credential')
  wrong='/pass/stamps/'+state['code']+'/'+state['auth']+'.png';yes(req(wrong)[0]==404,'Apple credential cannot substitute for image capability')
  current=req('/business/1',opener=owner);foreign=php("echo db()->query('SELECT api_key FROM businesses WHERE id=3')->fetchColumn();");form('/apikey/regenerate',{'csrf':csrf(current[1]),'business_id':'3'});yes(php("echo db()->query('SELECT api_key FROM businesses WHERE id=3')->fetchColumn();")==foreign,'owner route cannot rotate another owner business')
  member=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));page=req('/member/login',opener=member);form('/member/login',{'csrf':csrf(page[1]),'email':'aylin@example.com','password':'kunde1234'},member)
  status,export,_=req('/member/export',opener=member);yes(status==200 and export.startswith(b'{') and state['auth'].encode() not in export and state['image'].encode() not in export,'authenticated member export contains no private Wallet credentials')
 finally:server.terminate();server.wait();log.close()
 archive=A.parent/(A.name+'-auth-backup.zip')
 try:
  subprocess.run([PHP,'tools/backup.php','backup',str(archive)],cwd=A,check=True,capture_output=True)
  php('business_api_key_regenerate(1,1);')
  subprocess.run([PHP,'tools/backup.php','restore',str(archive),'--yes'],cwd=A,check=True,capture_output=True)
  saved=json.loads(php("$m=pass_member(db()->query('SELECT code FROM members WHERE business_id=1 ORDER BY id LIMIT 1')->fetchColumn());echo json_encode([pass_auth_token($m),wallet_image_token($m)]);"))
  yes(saved==[state['auth'],state['image']],'supported full backup/restore keeps captured Wallet credentials while resetting only sync metadata')
 finally:archive.unlink(missing_ok=True)
 retry=PREFIX+"$raw=new PDO('sqlite:'.config('db_path'));$raw->exec(\"UPDATE platform_settings SET value='invalid' WHERE key='migration_wallet_auth_v1'\");$failed=0;for($i=0;$i<2;$i++){try{db();}catch(Throwable $e){$failed++;}}$raw->exec(\"UPDATE platform_settings SET value='1' WHERE key='migration_wallet_auth_v1'\");if($failed!==2||!db())throw new RuntimeException('partial connection reused');echo 'safe';"
 yes(subprocess.check_output([PHP,'-r',retry],cwd=A,text=True)=='safe','caught initialization errors cannot reuse a partially migrated connection')
 plan=PREFIX+"$d=db();$d->exec(\"UPDATE subscriptions SET status='canceled' WHERE user_id=1\");$key=$d->query('SELECT api_key FROM businesses WHERE id=1')->fetchColumn();try{business_api_key_regenerate(1,1);$blocked=false;}catch(InvalidArgumentException $e){$blocked=true;}if(!$blocked||$d->query('SELECT api_key FROM businesses WHERE id=1')->fetchColumn()!==$key)throw new RuntimeException('plan guard failed');echo 'blocked';"
 result=subprocess.check_output([PHP,'-r',plan],cwd=A,env=dict(os.environ,WALLET_AUTH_PLAN='1'),text=True);yes(result=='blocked','domain rotation helper independently respects inactive API plan')
 print(n,'Wallet credential migration/concurrency/HTTP assertions passed',flush=True)
 print('Self-signed local fixtures only. No Apple/Google provider or device acceptance.',flush=True)
