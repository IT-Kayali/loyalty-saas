#!/usr/bin/env python3
"""Explicit location activation, no blind toggle. Disposable data, no providers."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-location-state-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(c):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+c],cwd=A,text=True).strip()
 id=int(sql("db()->exec(\"INSERT INTO locations(business_id,name,address)VALUES(1,'State fixture','Example address')\");echo db()->lastInsertId();"))
 sql("db()->exec('UPDATE staff SET location_id=%d WHERE business_id=1');db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Foreign','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);"%id)
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,pw):
  c=client();req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':pw});return c
 def rev():return sql('echo location_state_revision(db()->query("SELECT * FROM locations WHERE id=%d")->fetch());'%id)
 def active():return int(sql('echo db()->query("SELECT active FROM locations WHERE id=%d")->fetchColumn();'%id))
 def effects():return sql("echo json_encode([db()->query('SELECT * FROM businesses')->fetchAll(),db()->query('SELECT * FROM staff')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM member_messages')->fetchAll()]);")
 with open(A/'server.log','w') as log:
  s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
    except OSError:time.sleep(.1)
   o=login('demo@loyalty.local','demo1234');f=login('foreign@test.local','foreignpass1')
   def form(target='0',**extra):
    d={'csrf':csrf(o,'/organization'),'action':'location_toggle','id':id,'active':target,'state_revision':rev()};d.update(extra);return d
   before=effects();d=form();check(req('/organization',o,dict(d,csrf='0'*64))[0]==403 and active()==1,'CSRF required')
   check(req('/organization',f,dict(d,csrf=csrf(f,'/organization')))[0]==200 and active()==1,'foreign owner cannot change location')
   for changes in [{'active':'2'},{'active':'-1'},{'active':'1.0'},{'state_revision':''}]:
    check(req('/organization',o,form(**changes))[0]==200 and active()==1,'invalid state fields do not change activation')
   check('Prüfstand' in req('/organization',o,{'csrf':csrf(o,'/organization'),'action':'location_toggle','id':id})[1] and active()==1,'legacy blind toggle rejected safely')
   check('bereits' in req('/organization',o,form('1'))[1] and active()==1,'same target does not write or toggle')
   d=form();check(req('/organization',o,d)[0]==200 and active()==0 and effects()==before,'explicit deactivate changes only active state')
   check('inzwischen geändert' in req('/organization',o,d)[1] and active()==0,'repeated old form never reactivates')
   check(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='location.state'\")->fetchColumn();")=='1','invalid and repeated state forms create no extra audit')
   check('Standort aktivieren' in req('/organization',o)[1],'button names next action explicitly')
   # Text rename after state form: no overwrite of either independent field set.
   d=form('1');sql("db()->exec(\"UPDATE locations SET name='New label',address='New address' WHERE id=%d\");"%id)
   check(req('/organization',o,d)[0]==200 and active()==1 and sql('echo db()->query("SELECT name FROM locations WHERE id=%d")->fetchColumn();'%id)=='New label','state form preserves concurrent text edit')
   (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
   check(req('/organization',o,form())[0]==402 and active()==1,'plan blocks state change')
   check(sql("try{location_set_active(1,%d,['active'=>'0','state_revision'=>'x']);}catch(InvalidArgumentException $e){echo 'blocked';}"%id)=='blocked' and active()==1,'domain checks plan')
   (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
   check(sql("try{location_set_active(99999,%d,['active'=>'0','state_revision'=>'x']);}catch(InvalidArgumentException $e){echo 'blocked';}"%id)=='blocked' and active()==1,'domain checks owner')
   sql("db()->exec(\"CREATE TRIGGER state_audit_fail BEFORE INSERT ON audit_logs WHEN NEW.action='location.state' BEGIN SELECT RAISE(ABORT,'TEST_FAIL'); END\");")
   check(sql("$l=db()->query('SELECT * FROM locations WHERE id=%d')->fetch();try{location_set_active(1,%d,['active'=>'0','state_revision'=>location_state_revision($l)]);}catch(Throwable $e){echo 'rolled';}"%(id,id))=='rolled' and active()==1,'audit failure rolls active state back')
   sql("db()->exec('DROP TRIGGER state_audit_fail');")
   r=rev();cmd="try{location_set_active(1,%d,['active'=>'0','state_revision'=>'%s']);echo 'saved';}catch(InvalidArgumentException $e){echo 'stale';}"%(id,r)
   jobs=[subprocess.Popen([PHP,'-r',"require 'public/index.php';"+cmd],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(2)];out=[j.communicate(timeout=15)[0] for j in jobs]
   check(all(j.returncode==0 for j in jobs) and sorted(out)==['saved','stale'] and active()==0,'parallel deactivation wins once and stays deactivated')
   check(effects()==before,'all state changes preserve unrelated data and mail')
   subprocess.run(['node',str(R/'tests/location-state-ui.cjs'),str(id)],cwd=R,check=True)
  finally:s.terminate();s.wait()
print(n,'location state HTTP/domain assertions passed')
