#!/usr/bin/env python3
"""Atomic API/shop member creation. Disposable installations, no real providers."""
import concurrent.futures, hashlib, hmac, base64, json, os, pathlib, shutil, signal, subprocess, tempfile, time, urllib.error, urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(v,label):
 global n
 assert v,label
 n+=1;print('PASS',label,flush=True)

with tempfile.TemporaryDirectory(prefix='loyalty-member-upsert-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 base="<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=false;"
 def config(extra=''):(A/'config.php').write_text(base+extra+'return $c;')
 config();subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 domain=r'''<?php
require 'public/index.php';set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$n=0;function yes($v,$s){global $n;if(!$v)throw new RuntimeException($s);$n++;echo "PASS $s\n";}
function state(){return json_encode([db()->query('SELECT * FROM members ORDER BY id')->fetchAll(),db()->query('SELECT * FROM cards ORDER BY id')->fetchAll()]);}
$db=db();$events=[];$notify=function(...$args)use(&$events){$events[]=$args;};$warning=true;
$seed=$db->query('SELECT * FROM members WHERE business_id=1 ORDER BY id LIMIT 1')->fetch();$before=state();$same=api_member_upsert(1,strtoupper($seed['email']),'Never replace profile','api',$warning,$notify);
yes($same===$seed&&state()===$before&&$events===[]&&!$warning&&!empty($seed['password_hash']),'existing seeded account retains full password and consent row without effects');
$db->exec("INSERT INTO programs(business_id,type,name,uses_per_customer,pass_visits)VALUES(1,'voucher','Atomic voucher',7,0),(1,'pass','Atomic pass',1,12),(1,'points','Inactive atomic',1,0);UPDATE programs SET active=0 WHERE name='Inactive atomic'");
$m=api_member_upsert(1,'Atomic@EXAMPLE.COM','Atomic','api',$warning,$notify);
yes(!$warning&&$m['name']==='Atomic'&&$m['email']==='Atomic@EXAMPLE.COM','new member preserves helper inputs and resets warning');
$q=$db->prepare('SELECT c.*,p.type,p.uses_per_customer,p.pass_visits FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=?');$q->execute([$m['id']]);$cards=$q->fetchAll();$q->closeCursor();
yes(count($cards)===(int)$db->query('SELECT COUNT(*) FROM programs WHERE business_id=1 AND active=1')->fetchColumn(),'every currently active program gets a card');
yes(count(array_filter($cards,fn($c)=>$c['type']==='voucher'&&(int)$c['uses_left']===7))===1&&count(array_filter($cards,fn($c)=>$c['type']==='pass'&&(int)$c['visits_left']===12))===1,'voucher uses and pass visits keep configured initial values');
yes(count($events)===1&&$events[0]===[1,'member.joined',['member_id'=>(int)$m['id'],'name'=>'Atomic']],'one legacy-shaped joined event after creation');
$db->exec("INSERT INTO programs(business_id,type,name)VALUES(1,'points','Later atomic')");$before=state();$warning=true;$same=api_member_upsert(1,'aToMiC@example.com','Do not overwrite','api',$warning,$notify);
yes($same===$m&&state()===$before&&!$warning&&count($events)===1,'existing email lookup ignores ASCII case, leaves profile and cards unchanged, sends no event');
$other=api_member_upsert(2,'atomic@example.com','Other tenant','api',$warning,$notify);
yes($other['id']!==$m['id']&&(int)$other['business_id']===2,'email lookup is business-scoped');
$a=api_member_upsert(1,'','','api',$warning,$notify);$b=api_member_upsert(1,'','','api',$warning,$notify);
yes($a['id']!==$b['id']&&$a['name']==='Kunde'&&$b['name']==='Kunde','blank email still creates distinct members with original fallback');
$a=api_member_upsert(1,'fallback@example.com','','api',$warning,$notify);yes($a['name']==='fallback@example.com','empty name still falls back to nonempty email');
$db->prepare('INSERT INTO members(business_id,name,email,code)VALUES(?,?,?,?)')->execute([1,'Historical duplicate','atomic@example.com',member_code()]);
$q=$db->prepare('SELECT * FROM members WHERE business_id=? AND lower(email)=lower(?)');$q->execute([1,'atomic@example.com']);$expected=$q->fetch();$q->closeCursor();$before=state();$a=api_member_upsert(1,'ATOMIC@example.com','Replacement','api',$warning,$notify);
yes($a===$expected&&state()===$before,'historical duplicates keep original first-match query behavior, no merge or repair');
foreach(['member_abort','member_ignore','first_card','later_card','card_ignore'] as $mode){
 $table=str_starts_with($mode,'member')?'members':'cards';$when=$mode==='later_card'?" WHEN EXISTS(SELECT 1 FROM cards WHERE member_id=NEW.member_id)":'';$failure=str_ends_with($mode,'ignore')?'IGNORE':"ABORT,'PRIVATE-DB-MARKER'";
 $db->exec("CREATE TRIGGER upsert_failure BEFORE INSERT ON $table$when BEGIN SELECT RAISE($failure);END");$before=state();$eventCount=count($events);$failed=false;
 try{api_member_upsert(1,$mode.'@example.com','Rollback','api',$warning,$notify);}catch(Throwable){$failed=true;}
 $db->exec('DROP TRIGGER upsert_failure');yes($failed&&state()===$before&&count($events)===$eventCount,'member and complete card set roll back on '.$mode);
}
$before=state();$caught=false;try{api_member_upsert(999999,'missing@example.com','Missing','api',$warning,$notify);}catch(InvalidArgumentException){$caught=true;}
yes($caught&&state()===$before,'missing business creates nothing');
$caught=false;try{api_member_upsert(1,'bad-channel@example.com','Bad','webhooks',$warning,$notify);}catch(InvalidArgumentException){$caught=true;}
yes($caught&&state()===$before,'unknown channel is not accepted as a plan bypass');
$m=api_member_upsert(1,'failed-event@example.com','Committed','api',$warning,function(){throw new RuntimeException('PRIVATE-PROVIDER-MARKER');});
yes($warning&&$db->query('SELECT COUNT(*) FROM members WHERE id='.(int)$m['id'])->fetchColumn()==1,'thrown event error does not turn committed creation into failure');
$warning=true;$seen=0;$again=api_member_upsert(1,'failed-event@example.com','Retry','api',$warning,function()use(&$seen){$seen++;});
yes(!$warning&&$seen===0&&$again===$m,'reused member does not replay a failed joined event');
$visible=false;$m=api_member_upsert(1,'lock-free@example.com','Committed','api',$warning,function($bid,$event,$payload)use(&$visible){$second=new PDO('sqlite:'.config('db_path'));$second->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$second->exec('PRAGMA busy_timeout=1000;BEGIN IMMEDIATE');$visible=(int)$second->query('SELECT COUNT(*) FROM members WHERE id='.(int)$payload['member_id'])->fetchColumn()===1;$second->exec('CREATE TABLE IF NOT EXISTS test_provider_writer (id INTEGER)');$second->exec('COMMIT');});
yes(!$warning&&$visible,'provider handoff sees committed member and can acquire separate write lock');
$db->exec('UPDATE programs SET active=0 WHERE business_id=2');$m=api_member_upsert(2,'no-program@example.com','No programs','api',$warning,$notify);
yes((int)$db->query('SELECT COUNT(*) FROM cards WHERE member_id='.(int)$m['id'])->fetchColumn()===0,'business without active programs can still create member');
echo "$n member-upsert domain assertions passed\n";
'''
 (A/'domain.php').write_text(domain);p=subprocess.run([PHP,'domain.php'],cwd=A,capture_output=True,text=True);print(p.stdout,end='');assert p.returncode==0,p.stderr
 check('PRIVATE-PROVIDER-MARKER' not in p.stderr,'provider diagnostic does not expose exception content')
 sql("db()->exec('CREATE TABLE test_join_events(member_id INTEGER);UPDATE programs SET active=1');")
 worker=r'''<?php require 'public/index.php';db();file_put_contents('ready-'.$argv[1],'1');$end=microtime(true)+12;while(!is_file('go')){if(microtime(true)>$end)exit(3);usleep(1000);}try{$warning=false;$m=api_member_upsert((int)$argv[2],$argv[3],'Concurrent',$argv[4],$warning,function($bid,$event,$payload){db()->prepare('INSERT INTO test_join_events(member_id)VALUES(?)')->execute([$payload['member_id']]);});echo json_encode(['id'=>$m['id'],'warning'=>$warning]);}catch(Throwable $e){echo json_encode(['error'=>get_class($e),'plan'=>str_contains($e->getMessage(),'PLAN_RESTRICTED')]);}'''
 (A/'worker.php').write_text(worker)
 def waitfile(name):
  end=time.monotonic()+15
  while not (A/name).exists():
   assert time.monotonic()<end,name+' timeout';time.sleep(.005)
 def parallel(args):
  for f in [*A.glob('ready-*'),A/'go']:
   if f.exists():f.unlink()
  workers=[subprocess.Popen([PHP,'worker.php',str(i),*map(str,arg)],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for i,arg in enumerate(args)]
  try:
   end=time.monotonic()+15
   while len(list(A.glob('ready-*')))!=len(workers):
    assert time.monotonic()<end,'barrier timeout';time.sleep(.005)
   (A/'go').write_text('1');out=[]
   for w in workers:
    s,e=w.communicate(timeout=25);assert w.returncode==0,e;out.append(json.loads(s))
   return out
  finally:
   for w in workers:
    if w.poll() is None:w.kill();w.wait()
 out=parallel([(1,'parallel@example.com' if i%2 else 'PARALLEL@example.com','api') for i in range(12)])
 check(all('id' in x and not x['warning'] for x in out) and len({x['id'] for x in out})==1,'12 simultaneous mixed-case email upserts return one member')
 check(sql("echo db()->query('SELECT COUNT(*) FROM test_join_events')->fetchColumn();")=='1','concurrent same-email creation emits one joined event total')
 mid=out[0]['id'];check(sql(f"echo db()->query('SELECT COUNT(*) FROM cards WHERE member_id={mid}')->fetchColumn();")==sql("echo db()->query('SELECT COUNT(*) FROM programs WHERE business_id=1 AND active=1')->fetchColumn();"),'contended creation leaves complete card set')
 out=parallel([(1,'','api')]*6);check(len({x.get('id') for x in out})==6 and all('id' in x for x in out),'six concurrent blank-email requests remain six distinct creations')
 out=parallel([(1,'tenant-parallel@example.com','api'),(2,'tenant-parallel@example.com','api')]);check(all('id' in x for x in out) and out[0]['id']!=out[1]['id'],'concurrent same email in different businesses stays separate')
 # A blocker changes eligibility before creation can acquire its write lock.
 (A/'blocked.php').write_text("<?php require 'public/index.php';db();file_put_contents('blocked-ready','1');while(!is_file('blocked-go'))usleep(1000);$w=false;$m=api_member_upsert(1,'fresh-program@example.com','Fresh','api',$w,function(){});echo $m['id'];")
 (A/'locker.php').write_text("<?php require 'public/index.php';$d=db();$d->exec('BEGIN IMMEDIATE');$d->exec(\"UPDATE programs SET active=0 WHERE business_id=1 AND type='voucher'\");file_put_contents('locked','1');while(!is_file('release'))usleep(1000);$d->exec('COMMIT');")
 blocked=subprocess.Popen([PHP,'blocked.php'],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True);locker=None
 try:
  waitfile('blocked-ready');locker=subprocess.Popen([PHP,'locker.php'],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True);waitfile('locked');(A/'blocked-go').write_text('1');time.sleep(.08)
  check(blocked.poll() is None,'creation waits for earlier writer before reading programs')
  (A/'release').write_text('1');_,e=locker.communicate(timeout=15);assert locker.returncode==0,e;s,e=blocked.communicate(timeout=15);assert blocked.returncode==0,e
  check(sql(f"echo db()->query(\"SELECT COUNT(*) FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id={int(s)} AND p.type='voucher'\")->fetchColumn();")=='0','freshly deactivated program is not assigned after lock handover')
 finally:
  for w in [blocked,locker]:
   if w is not None and w.poll() is None:w.kill();w.wait()
 # Real API and signed Shopify HTTP, with several PHP workers.
 details=json.loads(sql("echo json_encode(['key'=>db()->query('SELECT api_key FROM businesses WHERE id=1')->fetchColumn(),'uid'=>db()->query('SELECT user_id FROM businesses WHERE id=1')->fetchColumn()]);"))
 sql("db()->exec(\"UPDATE businesses SET shopify_secret='local-test-secret',points_per_cent=0 WHERE id=1\");")
 headers={'Authorization':'Bearer '+details['key'],'Content-Type':'application/json'}
 def request(path,body,auth=None):
  raw=json.dumps(body,separators=(',',':')).encode();h=headers if auth is None else auth
  if path.startswith('/integrations/') and auth is None:h={'Content-Type':'application/json','X-Shopify-Topic':'orders/paid','X-Shopify-Hmac-Sha256':base64.b64encode(hmac.new(b'local-test-secret',raw,hashlib.sha256).digest()).decode()}
  try:
   with urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8099'+path,data=raw,headers=h),timeout=25) as r:return r.status,json.load(r)
  except urllib.error.HTTPError as r:return r.code,json.load(r)
 with (A/'server.log').open('w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4'),stdout=log,stderr=log,start_new_session=True)
  try:
   for _ in range(60):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login',timeout=1);break
    except OSError:time.sleep(.05)
   route='/api/v1/members';shop='/integrations/shopify/webhook/1'
   with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:out=list(pool.map(lambda _:request(route,{'name':'HTTP concurrent','email':'http-concurrent@example.com'}),range(16)))
   check(all(s==201 for s,_ in out) and len({x['code'] for _,x in out})==1,'16 concurrent real HTTP creations reuse one member')
   check(all(set(x)=={'id','code','name','email','joined_at','cards'} for _,x in out),'HTTP payload has no internal member columns or helper metadata')
   def mixed(i):
    if i%2:return request(route,{'name':'Mixed','email':'mixed-http@example.com'})
    return request(shop,{'id':100+i,'email':'MIXED-HTTP@example.com','total_price':'0'})
   with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:mixed_out=list(pool.map(mixed,range(16)))
   check(all(s in (200,201) for s,_ in mixed_out) and len({x.get('code',x.get('member_code')) for _,x in mixed_out})==1,'16 mixed real REST and signed Shopify requests share one member')
   check(request(route,{'name':'Unauth','email':'unauth@example.com'},{'Content-Type':'application/json'})[0]==401,'member creation still requires bearer authentication')
   check(request(route,{'email':'noname@example.com'})[0]==422,'member REST route still requires nonempty name')
   check(request(shop,{'id':10,'email':'unsigned@example.com'},{'X-Shopify-Topic':'orders/paid'})[0]==401,'Shopify creation still rejects invalid signature')
   # Force postcommit local enqueue failure before any actual provider transport.
   sql("db()->exec(\"INSERT INTO webhooks(business_id,url,events,secret)VALUES(1,'https://no-provider.invalid','member.joined','');CREATE TRIGGER http_join_failure BEFORE INSERT ON webhook_deliveries BEGIN SELECT RAISE(ABORT,'PRIVATE-EVENT-ERROR');END\");")
   status,result=request(route,{'name':'Committed warning','email':'http-warning@example.com'})
   check(status==201 and result.get('warning')=='member_event_incomplete','REST member creation returns committed success plus safe event warning')
   sql("db()->exec(\"UPDATE businesses SET points_per_cent=1 WHERE id=1\");")
   status,result=request('/api/v1/integrations/order',{'name':'Order warning','email':'order-warning@example.com','total':0.02,'reference':'warning-order-1'})
   check(status==200 and result.get('warning')=='member_event_incomplete' and result['points_credited']==2,'order route continues point credit after member event failure and exposes warning')
   status,result=request(shop,{'id':11,'email':'shop-warning@example.com','total_price':'0.03','customer':{'first_name':'Shop'}})
   check(status==200 and result.get('ok') is True and result.get('warning')=='member_event_incomplete' and result['points_credited']==3,'signed Shopify continues point credit after member event failure without false failure')
   check('PRIVATE-EVENT-ERROR' not in (A/'server.log').read_text(),'HTTP event diagnostics omit raw failure text')
   sql("db()->exec('DROP TRIGGER http_join_failure;DELETE FROM webhooks');")
   before=sql("echo json_encode([db()->query('SELECT * FROM members ORDER BY id')->fetchAll(),db()->query('SELECT * FROM cards ORDER BY id')->fetchAll()]);")
   sql("db()->exec(\"CREATE TRIGGER http_card_failure BEFORE INSERT ON cards WHEN EXISTS(SELECT 1 FROM cards WHERE member_id=NEW.member_id) BEGIN SELECT RAISE(ABORT,'PRIVATE-CARD-ERROR');END\");")
   status,result=request(route,{'name':'Rollback','email':'http-rollback@example.com'})
   check(status==500 and result.get('error')=='internal_error' and 'PRIVATE' not in json.dumps(result),'HTTP partial card failure is sanitized')
   check(sql("echo json_encode([db()->query('SELECT * FROM members ORDER BY id')->fetchAll(),db()->query('SELECT * FROM cards ORDER BY id')->fetchAll()]);")==before,'HTTP partial card failure rolls back member and preceding cards')
   sql("db()->exec('DROP TRIGGER http_card_failure');")
   # Channel-specific fresh policy and capacity are enforced without widening Shopify to API.
   uid=int(details['uid']);sql(f"db()->exec(\"INSERT INTO subscriptions(user_id,plan_key,status)VALUES({uid},'pro','active') ON CONFLICT(user_id) DO UPDATE SET plan_key='pro',status='active'\");")
   policy="$c['stripe']['enforce_limits']=true;$c['stripe']['plans']['pro']['features']=['api'=>false,'integrations'=>true];"
   config(policy)
   check(request(route,{'name':'Plan','email':'plan@example.com'})[0]==402,'REST API-disabled plan rejects creation')
   status,result=request(shop,{'id':12,'email':'allowed-shop@example.com','total_price':'0'})
   check(status==200 and result.get('ok') is True,'integrations-enabled Shopify still works when API feature is disabled')
   out=parallel([(1,'allowed-shop@example.com','api'),(1,'allowed-shop@example.com','integrations')]);check(out[0].get('plan') is True and 'id' in out[1],'helper independently checks correct channel even on existing member reuse')
   count=int(sql(f"echo db()->query('SELECT COUNT(*) FROM members m JOIN businesses b ON b.id=m.business_id WHERE b.user_id={uid}')->fetchColumn();"))
   config("$c['stripe']['enforce_limits']=true;$c['stripe']['plans']['pro']['limits']['members']="+str(count+1)+';')
   out=parallel([(1,'cap-one@example.com','api'),(1,'cap-two@example.com','api')]);check(sum('id' in x for x in out)==1 and sum(x.get('plan') is True for x in out)==1,'two competing creations cannot exceed one remaining member slot')
   winner='cap-one@example.com' if 'id' in out[0] else 'cap-two@example.com';check('id' in parallel([(1,winner,'api')])[0],'existing member remains reusable at full member capacity')
   config()
  finally:
   os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
 print(n,'member-upsert integration assertions passed',flush=True)
 print('No real providers, global uniqueness migration or whole-order atomicity claimed.',flush=True)
