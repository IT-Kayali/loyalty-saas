#!/usr/bin/env python3
"""Tier create/edit validation, ownership and conflict safety, disposable install."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,json,concurrent.futures
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-tier-edit-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(c):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+c],cwd=A,text=True).strip()
 pid=int(sql("echo db()->query(\"SELECT id FROM programs WHERE business_id=1 AND type='points' LIMIT 1\")->fetchColumn();"))
 tid=int(sql("db()->exec(\"INSERT INTO tiers(program_id,name,min_points,benefit)VALUES(%d,'Editor-Stufe',500,'Alter Vorteil')\");echo db()->lastInsertId();"%pid));P='/tier/%d/edit'%tid
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
 def state():return sql('echo json_encode(db()->query("SELECT * FROM tiers WHERE id=%d")->fetch());'%tid)
 def effect():return sql("echo json_encode([db()->query('SELECT * FROM programs')->fetchAll(),db()->query('SELECT * FROM members')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM rewards')->fetchAll(),db()->query('SELECT * FROM member_messages')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll()]);")
 def audit():return int(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action IN ('tier.edit','tier.create')\")->fetchColumn();"))
 with open(A/'server.log','w') as log:
  s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
    except OSError:time.sleep(.1)
   o=login('demo@loyalty.local','demo1234');f=login('foreign@test.local','foreignpass1');m=login('aylin@example.com','kunde1234','/member/login')
   check('/login' in req(P,client())[2],'guest needs login');check(req(P,f)[0]==404,'foreign owner cannot read editor');check('/login' in req(P,m)[2],'member cannot edit tier');check(req('/tier/999999/edit',o)[0]==404,'missing tier not available')
   check('href="'+P+'"' in req('/program/%d'%pid,o)[1],'program links exact tier editor')
   before=state();other=effect();a=audit();check(req(P,o)[3]['Cache-Control']=='private, no-store','editor private no-store');check(state()==before and audit()==a,'GET never writes or audits')
   def edit(fields=None,token=True):
    d={'name':'Neue Stufe','min_points':'750','benefit':'Neuer Vorteil','revision':revision(o)}
    if token:d['csrf']=csrf(o,P)
    if fields:d.update(fields)
    return req(P,o,d)
   edit(token=False);check(state()==before,'CSRF required')
   req(P,f,{'csrf':csrf(f,'/dashboard'),'name':'Foreign','min_points':'0','benefit':'','revision':revision(o)});check(state()==before,'foreign POST cannot mutate')
   invalid=[{'name':''},{'name':'x'*41},{'name':'a\nb'},{'name[]':'x','name':None},{'benefit':'x'*121},{'benefit':'a\tb'},{'min_points':'-1'},{'min_points':'1000001'},{'min_points':'1.5'},{'min_points':'1e3'},{'min_points':'01'},{'min_points':' 5 '},{'revision':'broken'}]
   for d in invalid:
    if d.get('name','x') is None:
     payload={'name[]':'x','min_points':'1','benefit':'','revision':revision(o),'csrf':csrf(o,P)};req(P,o,payload)
    else:edit(d)
    check(state()==before and audit()==a,'invalid field rejected without write or audit')
   for key in ['benefit','min_points','revision']:
    payload={'name':'Bad','min_points':'1','benefit':'','revision':revision(o),'csrf':csrf(o,P)};del payload[key];payload[key+'[]']='x';req(P,o,payload)
    check(state()==before and audit()==a,'array parameter rejected for '+key)
   check(sql("try{tier_fields(['name'=>chr(255),'min_points'=>'1','benefit'=>'']);echo 'bad';}catch(InvalidArgumentException){echo 'ok';}")=='ok','invalid UTF-8 rejected before storage')
   # Shared validation also rejects creation rather than clamping/truncating.
   def count():return int(sql('echo db()->query("SELECT COUNT(*) FROM tiers WHERE program_id=%d")->fetchColumn();'%pid))
   old=count();req('/tier/create',o,{'csrf':csrf(o,'/program/%d'%pid),'program_id':str(pid),'name':'Bad','min_points':'-9','benefit':''});check(count()==old,'create rejects negative threshold, no silent zero')
   req('/tier/create',o,{'csrf':csrf(o,'/program/%d'%pid),'program_id':str(pid),'name':'x'*41,'min_points':'1','benefit':''});check(count()==old,'create shares name limit')
   req('/tier/create',o,{'csrf':csrf(o,'/program/%d'%pid),'program_id':str(pid),'name':' Neue ','min_points':'0','benefit':''});check(count()==old+1 and audit()==a+1,'create accepts lower bound and writes atomic audit')
   # Tier changes affect dynamic display, not balances or journal.
   sql('db()->exec("DELETE FROM tiers WHERE program_id=%d AND id<>%d");'% (pid,tid))
   check(sql("echo current_tier(%d,600)['name'] ?? 'none';"%pid)=='Editor-Stufe','old threshold gives tier at 600 lifetime points')
   stale=revision(o);edit();check(json.loads(state())['min_points']==750 and json.loads(state())['name']=='Neue Stufe','valid edit changes exact tier fields')
   check(sql("echo current_tier(%d,600)['name'] ?? 'none';"%pid)=='none','higher threshold changes computed tier without changing points')
   now=state();edit({'revision':stale,'name':'Stale'});check(state()==now,'stale revision cannot overwrite')
   edit({'name':'Ä'*40,'min_points':'1000000','benefit':'Ö'*120});check(json.loads(state())['name']=='Ä'*40,'upper limits and Unicode accepted')
   check(effect()==other,'tier edit changes no programs balances journal rewards or mail')
   # Transaction audit failure must undo the edit and creation.
   sql("db()->exec(\"CREATE TRIGGER fail_tier_audit BEFORE INSERT ON audit_logs WHEN NEW.action IN ('tier.edit','tier.create') BEGIN SELECT RAISE(ABORT,'forced audit failure'); END\");")
   now=state();edit({'name':'Rollback'});check(state()==now,'audit failure rolls back editor')
   old=count();req('/tier/create',o,{'csrf':csrf(o,'/program/%d'%pid),'program_id':str(pid),'name':'Rollback','min_points':'1','benefit':''});check(count()==old,'audit failure rolls back creation')
   sql('db()->exec("DROP TRIGGER fail_tier_audit");')
   (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
   now=state();check(req(P,o)[0]==200,'unpaid plan can read editor');edit();check(state()==now,'unpaid plan cannot edit')
   (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
   # Two real PHP writers with one revision must not both win.
   rev=revision(o)
   def parallel(name):
    code="try{tier_edit(1,%d,['name'=>'%s','min_points'=>'500','benefit'=>'','revision'=>'%s']);echo 'ok';}catch(InvalidArgumentException){echo 'stale';}"%(tid,name,rev)
    return sql(code)
   with concurrent.futures.ThreadPoolExecutor(2) as pool:r=list(pool.map(parallel,['Race A','Race B']))
   check(sorted(r)==['ok','stale'],'two concurrent writes yield one winner and one stale rejection')
   subprocess.run(['node',str(R/'tests/tier-edit-ui.cjs'),str(pid),str(tid)],cwd=R,check=True)
  finally:s.terminate();s.wait()
print(n,'tier editor HTTP/domain assertions passed')
