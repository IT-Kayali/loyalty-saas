#!/usr/bin/env python3
"""Legacy REST credits: atomic local writes, concurrent workers and real HTTP. No providers."""
import concurrent.futures, http.cookiejar, json, os, pathlib, re, shutil, signal, subprocess, tempfile, time, urllib.error, urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(ok,label):
 global n
 assert ok,label
 n+=1;print('PASS',label,flush=True)

with tempfile.TemporaryDirectory(prefix='loyalty-api-credit-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 config="<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=false;return $c;"
 (A/'config.php').write_text(config)
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 sql("db()->exec(\"INSERT OR IGNORE INTO cards(program_id,member_id) SELECT p.id,m.id FROM programs p JOIN members m ON m.business_id=p.business_id WHERE p.business_id=1 AND p.type IN('points','stamps') AND m.id=(SELECT MIN(id) FROM members WHERE business_id=1)\");")
 code=r'''<?php
require 'public/index.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$n=0;function yes($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$db=db();$m=$db->query('SELECT * FROM members WHERE business_id=1 ORDER BY id LIMIT 1')->fetch();$mid=(int)$m['id'];$code=$m['code'];
$q=$db->prepare("SELECT c.id,c.program_id,p.type,p.stamps_required FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=? AND p.business_id=1 AND p.type IN('points','stamps')");$q->execute([$mid]);$cards=[];foreach($q->fetchAll() as $row)$cards[$row['type']]=$row;
$pid=(int)$cards['points']['id'];$sid=(int)$cards['stamps']['id'];$pp=(int)$cards['points']['program_id'];$sp=(int)$cards['stamps']['program_id'];
function state():string{$all=[];foreach(['cards','transactions','member_achievements','member_messages'] as $table)$all[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();return json_encode($all,JSON_THROW_ON_ERROR);}
function resetlocal():void{global $db;$db->exec('DELETE FROM member_achievements;DELETE FROM achievements;DELETE FROM member_messages;DELETE FROM transactions;DELETE FROM bonus_hours;UPDATE cards SET points=0,points_lifetime=0,stamps=0,pending_rewards=0;UPDATE programs SET active=1');}
function rule(string $metric,int $threshold,int $reward,string $name='Atomic milestone'):void{db()->prepare('INSERT INTO achievements(business_id,name,metric,threshold,reward_points)VALUES(1,?,?,?,?)')->execute([$name,$metric,$threshold,$reward]);}
resetlocal();
$a=legacy_api_credit(1,'member',$code,'points',4);
yes($a['response']===['member'=>$code,'card_id'=>$pid,'credited'=>4,'bonus_multiplier'=>1,'points'=>4],'member points preserves response fields and exact committed balance');
$b=legacy_api_credit(1,'card',$pid,'points',3);yes($b['response']===['card_id'=>$pid,'credited'=>3,'bonus_multiplier'=>1,'points'=>7],'explicit-card points uses same atomic helper');
yes((int)$db->query('SELECT SUM(amount) FROM transactions WHERE card_id='.$pid)->fetchColumn()===7,'journal equals both core point credits');
foreach([0,-9,'bad',1.9,10001] as $input){$v=legacy_api_credit(1,'card',$pid,'points',$input);yes($v['response']['credited']===max(1,min(10000,(int)$input)),'legacy input casting and clamping retained '.json_encode($input));}
$wd=(int)date('w');$hour=(int)date('G');$db->prepare('INSERT INTO bonus_hours(program_id,weekday,start_hour,end_hour,multiplier)VALUES(?,?,?,?,?)')->execute([$pp,$wd,$hour,$hour+1,3]);$v=legacy_api_credit(1,'card',$pid,'points',2);yes($v['response']['credited']===6&&$v['response']['bonus_multiplier']===3,'server-time bonus multiplier retained');
resetlocal();$db->prepare('UPDATE programs SET stamps_required=5 WHERE id=?')->execute([$sp]);$db->prepare('UPDATE cards SET stamps=4,pending_rewards=2 WHERE id=?')->execute([$sid]);
$v=legacy_api_credit(1,'member',$code,'stamp');yes($v['response']===['member'=>$code,'card_id'=>$sid,'stamps'=>0,'stamps_required'=>5,'pending_rewards'=>3,'reward_earned'=>true],'stamp rollover returns atomic snapshot');
yes((int)$db->query("SELECT COUNT(*) FROM transactions WHERE card_id=$sid AND kind IN('stamp_add','reward_earned')")->fetchColumn()===2,'rollover journals both stamp and earned reward');
resetlocal();rule('points_lifetime',5,7);$v=legacy_api_credit(1,'member',$code,'points',5);
yes($v['response']['points']===12&&count($v['events'])===2,'response includes award bonus from same transaction');
yes((int)$db->query('SELECT COUNT(*) FROM member_achievements')->fetchColumn()===1&&(int)$db->query('SELECT COUNT(*) FROM member_messages')->fetchColumn()===1&&(int)$db->query('SELECT SUM(amount) FROM transactions WHERE card_id='.$pid)->fetchColumn()===12,'award, inbox, balance and journals agree');
$v=legacy_api_credit(1,'card',$pid,'points',1);yes($v['response']['points']===13&&count($v['events'])===1,'later action cannot award same milestone twice');
resetlocal();rule('stamps_total',1,9);$v=legacy_api_credit(1,'member',$code,'stamp');yes((int)$db->query('SELECT points FROM cards WHERE id='.$pid)->fetchColumn()===9&&$v['response']['stamps']===1,'stamp credit can atomically award points on separate card');
foreach(['first_journal','rollover_journal','award_row','award_journal','award_inbox'] as $failure){
 resetlocal();rule('points_lifetime',1,7);
 if($failure==='rollover_journal'){$db->prepare('UPDATE cards SET stamps=4 WHERE id=?')->execute([$sid]);$trigger="BEFORE INSERT ON transactions WHEN NEW.kind='reward_earned'";}
 elseif($failure==='first_journal')$trigger="BEFORE INSERT ON transactions WHEN NEW.kind='points_add'";
 elseif($failure==='award_row')$trigger='BEFORE INSERT ON member_achievements';
 elseif($failure==='award_journal')$trigger="BEFORE INSERT ON transactions WHEN NEW.note LIKE '%Meilenstein:%'";
 else $trigger='BEFORE INSERT ON member_messages';
 $db->exec("CREATE TRIGGER credit_failure $trigger BEGIN SELECT RAISE(ABORT,'PRIVATE-DATABASE-FAILURE');END");$before=state();$failed=false;
 try{legacy_api_credit(1,'member',$code,$failure==='rollover_journal'?'stamp':'points',1);}catch(PDOException $e){$failed=true;}
 $db->exec('DROP TRIGGER credit_failure');yes($failed&&state()===$before,'complete rollback on '.$failure);
}
resetlocal();
$other=$db->query('SELECT c.id FROM cards c JOIN programs p ON p.id=c.program_id WHERE p.business_id=2 LIMIT 1')->fetchColumn();
foreach([['member','0000000000','points'],['member','0000000000','stamp'],['card',(int)$other,'points'],['card',$sid,'points'],['card',999999,'points']] as [$selector,$target,$action]){
 $before=state();$status=0;try{legacy_api_credit(1,$selector,$target,$action,1);}catch(LegacyApiCreditError $e){$status=$e->getCode();}
 yes($status===404&&state()===$before,'missing, foreign or wrong-type target has no write '.json_encode([$selector,$target,$action]));
}
$db->prepare('UPDATE programs SET active=0 WHERE id=?')->execute([$pp]);$status=0;try{legacy_api_credit(1,'member',$code,'points',1);}catch(LegacyApiCreditError $e){$status=$e->getCode();}
yes($status===404,'member selector still excludes inactive programs');
$v=legacy_api_credit(1,'card',$pid,'points',1);yes($v['response']['points']===1,'legacy explicit-card inactive-program behavior retained, not silently tightened');
resetlocal();$db->prepare('UPDATE cards SET points=?,points_lifetime=? WHERE id=?')->execute([PHP_INT_MAX,PHP_INT_MAX,$pid]);$before=state();$status=0;try{legacy_api_credit(1,'card',$pid,'points',1);}catch(LegacyApiCreditError $e){$status=$e->getCode();}
yes($status===422&&state()===$before,'SQLite integer-to-real overflow rolls back rather than rounding balance');
resetlocal();$db->prepare('UPDATE cards SET points=?,points_lifetime=? WHERE id=?')->execute([PHP_INT_MAX-1,PHP_INT_MAX-1,$pid]);rule('points_lifetime',1,10);$before=state();$status=0;try{legacy_api_credit(1,'card',$pid,'points',1);}catch(LegacyApiCreditError $e){$status=$e->getCode();}
yes($status===422&&state()===$before,'overflow from award bonus rolls back complete action too');
resetlocal();rule('points_lifetime',1,7);$booked=legacy_api_credit(1,'member',$code,'points',1);$before=state();$seen=[];
$warning=legacy_api_credit_effects($booked,function($kind,$data)use(&$seen){$seen[]=$kind;if($kind==='webhook')throw new RuntimeException('PRIVATE-PROVIDER-TOKEN');});
yes($warning&&$seen===['webhook','webhook','wallet']&&state()===$before,'post-commit failures do not undo booking or skip later effects');
$seen=[];$warning=legacy_api_credit_effects($booked,function($kind,$data)use(&$seen){$seen[]=$kind;});yes(!$warning&&count($seen)===3,'each available effect attempted once without retry');
// A second connection must see the committed credit and acquire a writer during dispatch.
$warning=legacy_api_credit_effects($booked,function($kind,$data)use($db,$pid){if($kind!=='wallet')return;$second=new PDO('sqlite:'.config('db_path'));$second->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$second->exec('PRAGMA busy_timeout=1000;BEGIN IMMEDIATE');if((int)$second->query('SELECT points FROM cards WHERE id='.$pid)->fetchColumn()!==8)throw new RuntimeException('commit not visible');$second->exec('ROLLBACK');});
yes(!$warning,'provider dispatch sees committed result and holds no write lock');
resetlocal();
$db->exec("INSERT INTO programs(business_id,type,name)VALUES(1,'points','Second points program')");$extraProgram=(int)$db->lastInsertId();$db->prepare('INSERT INTO cards(program_id,member_id)VALUES(?,?)')->execute([$extraProgram,$mid]);$extraCard=(int)$db->lastInsertId();
$q=$db->prepare("SELECT c.id FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=? AND p.business_id=? AND p.type='points' AND p.active=1 LIMIT 1");$q->execute([$mid,1]);$oldChoice=(int)$q->fetchColumn();
$v=legacy_api_credit(1,'member',$code,'points',4);yes($v['response']['card_id']===$oldChoice&&(int)$db->query('SELECT points FROM cards WHERE id='.$extraCard)->fetchColumn()===0,'multiple-card member selection matches released first-matching query');
resetlocal();rule('points_lifetime',1,7);$v=legacy_api_credit(1,'card',$extraCard,'points',2);
yes($v['response']['card_id']===$extraCard&&$v['response']['points']===2&&(int)$db->query('SELECT points FROM cards WHERE id='.$pid)->fetchColumn()===7,'explicit second card keeps its own balance while award uses original first points card');
resetlocal();$db->prepare('DELETE FROM cards WHERE id=?')->execute([$extraCard]);$db->prepare('DELETE FROM programs WHERE id=?')->execute([$extraProgram]);
echo "$n legacy credit domain assertions passed\n";
'''
 (A/'test-credit.php').write_text(code)
 p=subprocess.run([PHP,'test-credit.php'],cwd=A,capture_output=True,text=True)
 print(p.stdout,end='');assert p.returncode==0,p.stderr
 check('PRIVATE-PROVIDER-TOKEN' not in p.stderr and 'PRIVATE-DATABASE-FAILURE' not in p.stderr,'follow-up diagnostics contain no provider message or secret marker')
 details=json.loads(sql("$m=db()->query('SELECT id,code FROM members WHERE business_id=1 ORDER BY id LIMIT 1')->fetch();$q=db()->prepare(\"SELECT c.id,p.type,p.id AS program FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=? AND p.business_id=1 AND p.type IN('stamps','points')\");$q->execute([$m['id']]);echo json_encode(['member'=>$m,'cards'=>$q->fetchAll(),'key'=>db()->query('SELECT api_key FROM businesses WHERE id=1')->fetchColumn()]);"))
 codeval=details['member']['code'];mid=int(details['member']['id']);cards={c['type']:c for c in details['cards']};pid=int(cards['points']['id']);sid=int(cards['stamps']['id']);sp=int(cards['stamps']['program'])
 # Real processes start from the same barrier and contend on the shared SQLite DB.
 worker=r'''<?php require 'public/index.php';db();file_put_contents('ready-'.$argv[1],'1');$end=microtime(true)+10;while(!is_file('go')){if(microtime(true)>$end)exit(3);usleep(1000);}$out=[];for($i=0;$i<(int)$argv[6];$i++)$out[]=legacy_api_credit(1,$argv[2],$argv[2]==='card'?(int)$argv[3]:$argv[3],$argv[4],(int)$argv[5]);echo json_encode($out);'''
 (A/'worker.php').write_text(worker)
 def barrier_run(args):
  for f in [*A.glob('ready-*'),A/'go']:
   if f.exists():f.unlink()
  workers=[subprocess.Popen([PHP,'worker.php',str(i),*map(str,arg)],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for i,arg in enumerate(args)]
  try:
   end=time.monotonic()+12
   while len(list(A.glob('ready-*')))!=len(workers):
    assert time.monotonic()<end,'worker barrier timeout';time.sleep(.005)
   (A/'go').write_text('1');out=[]
   for w in workers:
    s,e=w.communicate(timeout=20);assert w.returncode==0,e;out.extend(json.loads(s))
   return out
  finally:
   for w in workers:
    if w.poll() is None:w.kill();w.wait()
 sql("db()->exec(\"DELETE FROM transactions;DELETE FROM member_messages;DELETE FROM member_achievements;DELETE FROM achievements;DELETE FROM bonus_hours;UPDATE cards SET points=0,points_lifetime=0,stamps=0,pending_rewards=0;INSERT INTO achievements(business_id,name,metric,threshold,reward_points)VALUES(1,'Parallel','points_lifetime',1,7)\");")
 out=barrier_run([('member',codeval,'points',1,5)]*3+[('card',pid,'points',1,5)]*3)
 state=json.loads(sql(f"echo json_encode([db()->query('SELECT points,points_lifetime FROM cards WHERE id={pid}')->fetch(),db()->query('SELECT SUM(amount) FROM transactions WHERE card_id={pid}')->fetchColumn(),db()->query('SELECT COUNT(*) FROM member_achievements')->fetchColumn(),db()->query('SELECT COUNT(*) FROM member_messages')->fetchColumn()]);"))
 check(state==[{'points':37,'points_lifetime':37},37,1,1] and len(out)==30,'30 interleaved member/card point credits keep exact balance, journal and one award')
 check(len({x['response']['points'] for x in out})==30 and sum(len(x['events'])-1 for x in out)==1,'parallel responses are operation snapshots and emit one milestone event total')
 sql(f"db()->exec(\"DELETE FROM transactions;DELETE FROM member_messages;DELETE FROM member_achievements;DELETE FROM achievements;UPDATE cards SET points=0,points_lifetime=0,stamps=0,pending_rewards=0;UPDATE programs SET stamps_required=5 WHERE id={sp};UPDATE cards SET stamps=3,pending_rewards=2 WHERE id={sid}\");")
 out=barrier_run([('member',codeval,'stamp',1,6)]*4)
 state=json.loads(sql(f"echo json_encode([db()->query('SELECT stamps,pending_rewards FROM cards WHERE id={sid}')->fetch(),db()->query(\"SELECT COUNT(*) FROM transactions WHERE card_id={sid} AND kind='stamp_add'\")->fetchColumn(),db()->query(\"SELECT COUNT(*) FROM transactions WHERE card_id={sid} AND kind='reward_earned'\")->fetchColumn()]);"))
 check(state==[{'stamps':2,'pending_rewards':7},24,5] and sum(x['response']['reward_earned'] for x in out)==5,'24 contended stamps lose no credit and produce exact rollover/reward count')
 # Fresh lookup after the write lock, with a different writer changing eligibility first.
 (A/'lock.php').write_text("<?php require 'public/index.php';$d=db();$d->exec('BEGIN IMMEDIATE');$d->prepare('UPDATE programs SET active=0 WHERE id=?')->execute([(int)$argv[1]]);file_put_contents('locked','1');$end=microtime(true)+10;while(!is_file('release')){if(microtime(true)>$end)exit(3);usleep(1000);}$d->exec('COMMIT');")
 (A/'blocked.php').write_text("<?php require 'public/index.php';db();file_put_contents('blocked-ready','1');$end=microtime(true)+10;while(!is_file('blocked-go')){if(microtime(true)>$end)exit(3);usleep(1000);}try{legacy_api_credit(1,'member',$argv[1],'stamp');echo 'booked';}catch(LegacyApiCreditError $e){echo $e->getCode();}")
 def waitfile(name):
  end=time.monotonic()+10
  while not (A/name).exists():
   assert time.monotonic()<end,name+' timeout';time.sleep(.005)
 blocked=subprocess.Popen([PHP,'blocked.php',codeval],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True);locker=None
 try:
  waitfile('blocked-ready');locker=subprocess.Popen([PHP,'lock.php',str(sp)],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True);waitfile('locked');(A/'blocked-go').write_text('1');time.sleep(.05)
  check(blocked.poll() is None,'credit waits for current writer instead of using unlocked card snapshot')
  (A/'release').write_text('1');s,e=locker.communicate(timeout=12);assert locker.returncode==0,e;s,e=blocked.communicate(timeout=12)
  check(blocked.returncode==0 and s=='404','fresh inactive-program state is checked after writer commits')
 finally:
  for w in [blocked,locker]:
   if w is not None and w.poll() is None:w.kill();w.wait()
 sql("db()->exec('UPDATE programs SET active=1;DELETE FROM transactions;DELETE FROM member_achievements;DELETE FROM achievements;DELETE FROM member_messages;UPDATE cards SET points=0,points_lifetime=0,stamps=0,pending_rewards=0');")
 # Real HTTP with several builtin server workers. Kill the whole group to avoid orphan workers.
 env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4')
 headers={'Authorization':'Bearer '+details['key'],'Content-Type':'application/json'}
 def req(path,body=b'{}',auth=headers):
  request=urllib.request.Request('http://127.0.0.1:8099'+path,data=body,headers=auth)
  try:
   with urllib.request.urlopen(request,timeout=20) as response:return response.status,json.load(response)
  except urllib.error.HTTPError as response:return response.code,json.load(response)
 with (A/'server.log').open('w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,env=env,stdout=log,stderr=log,start_new_session=True)
  try:
   for _ in range(60):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login',timeout=1);break
    except OSError:time.sleep(.05)
   check(req('/api/v1/cards/'+str(pid)+'/points',b'{"amount":2}')[1]['points']==2,'real explicit-card HTTP response contains committed points')
   check(req('/api/v1/members/'+codeval+'/points',b'{"amount":3}')[1]['points']==5,'real member points HTTP preserves response')
   with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:responses=list(pool.map(lambda _:req('/api/v1/members/'+codeval+'/stamp'),range(20)))
   state=json.loads(sql(f"echo json_encode([db()->query('SELECT stamps,pending_rewards FROM cards WHERE id={sid}')->fetch(),db()->query(\"SELECT COUNT(*) FROM transactions WHERE card_id={sid} AND kind='stamp_add'\")->fetchColumn()]);"))
   check(all(status==200 for status,_ in responses) and state==[{'stamps':0,'pending_rewards':4},20],'20 real concurrent HTTP stamp requests preserve all credits')
   check(req('/api/v1/cards/'+str(pid)+'/points',auth={'Content-Type':'application/json'})[0]==401,'credit requires API bearer, not just an existing web session')
   check(req('/api/v1/cards/'+str(sid)+'/points')[0]==404 and req('/api/v1/members/0000000000/stamp')[0]==404,'HTTP wrong-type and missing member remain404')
   before=sql("echo json_encode([db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll()]);")
   sql("db()->exec(\"CREATE TRIGGER http_credit_failure BEFORE INSERT ON transactions BEGIN SELECT RAISE(ABORT,'PRIVATE-SQL-ERROR');END\");")
   status,result=req('/api/v1/cards/'+str(pid)+'/points',b'{"amount":6}')
   check(status==500 and result.get('error')=='internal_error' and 'PRIVATE' not in json.dumps(result),'HTTP journal failure is sanitized')
   check(sql("echo json_encode([db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll()]);")==before,'HTTP journal failure rolls back balance too')
   sql("db()->exec('DROP TRIGGER http_credit_failure');")
   # No real hook: force failure while preparing local delivery rows after the credit commits.
   sql("db()->exec(\"INSERT INTO webhooks(business_id,url,events,secret)VALUES(1,'https://no-provider.invalid','card.points','');CREATE TRIGGER http_effect_failure BEFORE INSERT ON webhook_deliveries BEGIN SELECT RAISE(ABORT,'PRIVATE-WEBHOOK-ERROR');END\");")
   status,result=req('/api/v1/cards/'+str(pid)+'/points',b'{"amount":1}')
   check(status==200 and result['points']==6 and result.get('warning')=='follow_up_incomplete','post-commit follow-up failure returns successful credit with safe warning, never false500')
   check('PRIVATE-WEBHOOK-ERROR' not in (A/'server.log').read_text(),'follow-up server diagnostic does not log raw exception message')
   sql("db()->exec('DROP TRIGGER http_effect_failure;DELETE FROM webhooks');")
   a=req('/api/v1/cards/'+str(pid)+'/points',b'{"amount":1}')[1];b=req('/api/v1/cards/'+str(pid)+'/points',b'{"amount":1}')[1]
   check(a['points']==7 and b['points']==8,'identical legacy POSTs remain separate credits, not an implicit retry receipt')
   (A/'config.php').write_text(config.replace("enforce_limits']=false","enforce_limits']=true"))
   check(req('/api/v1/cards/'+str(pid)+'/points')[0]==402,'inactive plan still denies HTTP credit')
   check(sql("try{legacy_api_credit(1,'card',"+str(pid)+",'points',1);echo 'booked';}catch(LegacyApiCreditError $e){echo $e->getCode();}")=='402','atomic helper independently rechecks current plan under its write lock')
   (A/'config.php').write_text(config)
  finally:
   os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
 print(n,'legacy credit integration assertions passed',flush=True)
 print('No real provider calls or exactly-once retry guarantees tested.',flush=True)
