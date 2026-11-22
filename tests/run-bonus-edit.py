#!/usr/bin/env python3
"""Bonus-hour editor, shared creation validation and unchanged multiplier semantics."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,json,concurrent.futures
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-bonus-edit-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(c):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+c],cwd=A,text=True).strip()
 pid=int(sql("echo db()->query(\"SELECT id FROM programs WHERE business_id=1 AND type='points' LIMIT 1\")->fetchColumn();"))
 bid=int(sql("db()->exec(\"DELETE FROM bonus_hours WHERE program_id=%d;INSERT INTO bonus_hours(program_id,weekday,start_hour,end_hour,multiplier)VALUES(%d,1,14,16,2)\");echo db()->lastInsertId();"%(pid,pid)));P='/bonus/%d/edit'%bid
 sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Foreign','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);")
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(p,c,d=None):
  if isinstance(d,dict):d=urllib.parse.urlencode(d).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+p,data=d),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c)[1]).group(1)
 def login(e,pw,path='/login'):
  c=client();req(path,c,{'csrf':csrf(c,path),'email':e,'password':pw});return c
 def revision(c):return re.search(r'name="revision" value="([a-f0-9]+)"',req(P,c)[1]).group(1)
 def state():return sql('echo json_encode(db()->query("SELECT * FROM bonus_hours WHERE id=%d")->fetch());'%bid)
 def effect():return sql("echo json_encode([db()->query('SELECT * FROM programs')->fetchAll(),db()->query('SELECT * FROM members')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM rewards')->fetchAll(),db()->query('SELECT * FROM member_messages')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll()]);")
 def audit():return int(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action IN ('bonus.edit','bonus.create')\")->fetchColumn();"))
 with open(A/'server.log','w') as log:
  s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
    except OSError:time.sleep(.1)
   o=login('demo@loyalty.local','demo1234');f=login('foreign@test.local','foreignpass1');m=login('aylin@example.com','kunde1234','/member/login')
   check('/login' in req(P,client())[2],'guest requires login');check(req(P,f)[0]==404,'foreign owner cannot read');check('/login' in req(P,m)[2],'member cannot edit');check(req('/bonus/999999/edit',o)[0]==404,'unknown bonus cannot edit')
   check('href="'+P+'"' in req('/program/%d'%pid,o)[1],'program links exact bonus editor')
   check('Serverzeitzone:' in req(P,o)[1] and 'Unternehmenszeitzone gilt hier nicht' in req('/program/%d'%pid,o)[1],'server timezone semantics stated in editor and program')
   before=state();other=effect();a=audit();check(req(P,o)[3]['Cache-Control']=='private, no-store','private no-store editor');check(state()==before and audit()==a,'GET no writes or audit')
   def edit(fields=None,token=True):
    d={'weekday':'2','start_hour':'10','end_hour':'18','multiplier':'3','revision':revision(o)}
    if token:d['csrf']=csrf(o,P)
    if fields:d.update(fields)
    return req(P,o,d)
   edit(token=False);check(state()==before,'CSRF enforced')
   req(P,f,{'csrf':csrf(f,'/dashboard'),'weekday':'2','start_hour':'10','end_hour':'18','multiplier':'3','revision':revision(o)});check(state()==before,'foreign POST denied')
   for fields in [{'weekday':'7'},{'weekday':'-1'},{'start_hour':'24'},{'start_hour':'-1'},{'end_hour':'0'},{'end_hour':'25'},{'end_hour':'10'},{'multiplier':'1'},{'multiplier':'11'},{'multiplier':'2.5'},{'multiplier':'2e0'},{'weekday':'01'},{'revision':'bad'}]:
    edit(fields);check(state()==before and audit()==a,'invalid values rejected, no clamp or write')
   for key in ['weekday','start_hour','end_hour','multiplier','revision']:
    d={'weekday':'2','start_hour':'10','end_hour':'18','multiplier':'3','revision':revision(o),'csrf':csrf(o,P)};del d[key];d[key+'[]']='x';req(P,o,d);check(state()==before,'array rejected for '+key)
   def count():return int(sql('echo db()->query("SELECT COUNT(*) FROM bonus_hours WHERE program_id=%d")->fetchColumn();'%pid))
   old=count();base={'csrf':csrf(o,'/program/%d'%pid),'program_id':str(pid),'weekday':'2','start_hour':'0','end_hour':'24','multiplier':'10'}
   req('/bonus/create',o,{**base,'weekday':'99'});check(count()==old,'create shares validation, no clamping')
   req('/bonus/create',o,base);check(count()==old+1 and audit()==a+1,'create accepts 0/24 and multiplier 10 with audit')
   stale=revision(o);edit();check(json.loads(state())['weekday']==2 and json.loads(state())['multiplier']==3,'valid edit changes exact stored schedule')
   now=state();edit({'revision':stale,'multiplier':'4'});check(state()==now,'stale form cannot overwrite schedule')
   check(effect()==other,'no recalculation of points ledger programs rewards or mail')
   sql("db()->exec(\"CREATE TRIGGER fail_bonus_audit BEFORE INSERT ON audit_logs WHEN NEW.action IN ('bonus.edit','bonus.create') BEGIN SELECT RAISE(ABORT,'forced audit failure'); END\");")
   edit({'multiplier':'4'});check(state()==now,'edit rolls back if audit fails');old=count();req('/bonus/create',o,base);check(count()==old,'create rolls back if audit fails');sql('db()->exec("DROP TRIGGER fail_bonus_audit");')
   (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
   check(req(P,o)[0]==200,'read available without write plan');edit({'multiplier':'4'});check(state()==now,'write plan enforced')
   (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
   rev=revision(o)
   def parallel(mult):return sql("try{bonus_edit(1,%d,['weekday'=>'2','start_hour'=>'10','end_hour'=>'18','multiplier'=>'%d','revision'=>'%s']);echo 'ok';}catch(InvalidArgumentException){echo 'stale';}"%(bid,mult,rev))
   with concurrent.futures.ThreadPoolExecutor(2) as pool:r=list(pool.map(parallel,[4,5]))
   check(sorted(r)==['ok','stale'],'parallel writers yield one winner and one stale rejection')
   # Test existing engine with rules around current server hour, no clock dependence.
   sql("db()->exec('DELETE FROM bonus_hours WHERE program_id=%d');"%pid+"$q=db()->prepare('INSERT INTO bonus_hours(program_id,weekday,start_hour,end_hour,multiplier)VALUES(?,?,?,?,?)');$h=(int)date('G');$w=(int)date('w');$q->execute([%d,$w,$h,$h+1,2]);$q->execute([%d,$w,$h,$h+1,4]);$q->execute([%d,($w+1)%%7,$h,$h+1,10]);"%(pid,pid,pid))
   check(sql('echo points_multiplier(%d);'%pid)=='4','overlapping windows use highest multiplier, not product or other weekday')
   sql("db()->exec('DELETE FROM bonus_hours WHERE program_id=%d');$h=(int)date('G');$w=(int)date('w');$q=db()->prepare('INSERT INTO bonus_hours(program_id,weekday,start_hour,end_hour,multiplier)VALUES(?,?,?,?,?)');if($h>0)$q->execute([%d,$w,0,$h,10]);else $q->execute([%d,$w,1,24,10]);"%(pid,pid,pid))
   check(sql('echo points_multiplier(%d);'%pid)=='1','exclusive end and future start do not activate bonus')
   # Recreate editor fixture after engine checks.
   bid=int(sql("db()->exec(\"INSERT INTO bonus_hours(program_id,weekday,start_hour,end_hour,multiplier)VALUES(%d,1,14,16,2)\");echo db()->lastInsertId();"%pid))
   subprocess.run(['node',str(R/'tests/bonus-edit-ui.cjs'),str(pid),str(bid)],cwd=R,check=True)
  finally:s.terminate();s.wait()
print(n,'bonus editor HTTP/domain assertions passed')
