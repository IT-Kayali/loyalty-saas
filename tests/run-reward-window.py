#!/usr/bin/env python3
"""Atomic reward windows bind current UTC window and effective company input timezone."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json,concurrent.futures
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-reward-window-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 pid=int(sql("echo db()->query(\"SELECT id FROM programs WHERE business_id=1 AND type='points' LIMIT 1\")->fetchColumn();"))
 rid=int(sql("db()->exec(\"INSERT INTO rewards(program_id,name,points_cost)VALUES(%d,'Fensterprämie',20)\");echo db()->lastInsertId();"%pid));P='/program/%d'%pid
 sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Foreign','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);")
 def row():return json.loads(sql('echo json_encode(db()->query("SELECT * FROM rewards WHERE id=%d")->fetch());'%rid))
 def effects():return sql("echo json_encode([db()->query('SELECT * FROM members')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM reward_claims')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM pass_devices')->fetchAll()]);")
 def audits():return int(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='reward.window'\")->fetchColumn();"))
 def mk():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c)[1]).group(1)
 def login(e,pw,p='/login'):
  c=mk();req(p,c,{'csrf':csrf(c,p),'email':e,'password':pw});return c
 log=open(A/'server.log','w');s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  o=login('demo@loyalty.local','demo1234');f=login('foreign@test.local','foreignpass1');m=login('aylin@example.com','kunde1234','/member/login')
  def rev():return re.search(r'name="reward_id" value="'+str(rid)+r'"><input type="hidden" name="window_revision" value="([a-f0-9]+)"',req(P,o)[1]).group(1)
  def data():return {'csrf':csrf(o,P),'reward_id':rid,'valid_from':'2027-01-15T12:00','valid_until':'2027-07-01T12:00','window_revision':rev()}
  def save(changes=None):
   d=data()
   if changes:d.update(changes)
   return req('/reward/window',o,d)
  before=row();other=effects();a=audits();req(P,o);check(row()==before and audits()==a,'reading current forms has no writes or audit')
  d=data();del d['csrf'];check(req('/reward/window',o,d)[0]==403 and row()==before,'CSRF required')
  d=data();del d['window_revision'];req('/reward/window',o,d);check(row()==before and audits()==a,'legacy blind form rejected')
  d=data();d['csrf']=csrf(f,'/dashboard');check(req('/reward/window',f,d)[0]==404 and row()==before,'foreign owner cannot update target')
  d=data();d['csrf']=csrf(m,'/wallet');check(req('/reward/window',m,d)[2].endswith('/login') and row()==before,'member cannot update owner window')
  check(save({'reward_id':'999999'})[0]==404 and row()==before,'unknown reward unavailable')
  for missing in ['valid_from','valid_until']:
   d=data();del d[missing];req('/reward/window',o,d);check(row()==before,'missing field does not silently clear interval: '+missing)
  for changes in [{'valid_from':'bad'},{'valid_until':'2027-01-15T11:00'},{'valid_from':'2027-02-30T12:00'},{'window_revision':'old'}]:
   save(changes);check(row()==before and audits()==a,'invalid date/order/revision rejected')
  for key in ['valid_from','valid_until','window_revision','reward_id']:
   d=data();del d[key];d[key+'[]']='array';req('/reward/window',o,d);check(row()==before,'array input rejected: '+key)
  old=data();save();r=row();check(r['valid_from']=='2027-01-15 12:00:00' and r['valid_until']=='2027-07-01 12:00:00' and audits()==a+1,'UTC interval saved and audited once')
  req('/reward/window',o,dict(old,valid_from='2027-01-16T12:00'));check(row()==r and audits()==a+1,'old tab cannot overwrite changed window')
  old=data();sql("db()->exec(\"UPDATE businesses SET timezone='Europe/Berlin' WHERE id=1\");");req('/reward/window',o,old);check(row()==r and audits()==a+1,'stale timezone cannot silently reinterpret local input')
  save();check(row()['valid_from']=='2027-01-15 11:00:00' and row()['valid_until']=='2027-07-01 10:00:00','fresh Berlin form preserves existing winter/summer conversion')
  d=data();sql("db()->exec(\"UPDATE rewards SET name='Separater Name',points_cost=40 WHERE id=%d\");"%rid);req('/reward/window',o,dict(d,valid_from='2027-01-16T12:00'));check(row()['name']=='Separater Name' and row()['points_cost']==40 and row()['valid_from']=='2027-01-16 11:00:00','independent name/cost edits are preserved')
  sql("db()->exec(\"CREATE TRIGGER fail_window_audit BEFORE INSERT ON audit_logs WHEN NEW.action='reward.window' BEGIN SELECT RAISE(ABORT,'test failure'); END\");");r=row();a=audits();save({'valid_from':''});check(row()==r and audits()==a,'audit failure rolls interval back');sql('db()->exec("DROP TRIGGER fail_window_audit");')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  check(req(P,o)[0]==200,'unpaid plan can inspect window');save({'valid_from':''});check(row()==r and audits()==a,'unpaid plan cannot change window');(A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  rr=rev()
  def writer(day):return sql("try{reward_window_save(1,%d,['valid_from'=>'2027-02-%sT10:00','valid_until'=>'2027-08-01T10:00','window_revision'=>'%s']);echo 'ok';}catch(InvalidArgumentException){echo 'blocked';}"%(rid,day,rr))
  with concurrent.futures.ThreadPoolExecutor(2) as pool:out=list(pool.map(writer,['10','11']))
  check(sorted(out)==['blocked','ok'] and audits()==a+1,'parallel window updates have one current-state winner and one audit')
  save({'valid_from':'','valid_until':''});check(row()['valid_from'] is None and row()['valid_until'] is None,'current form can explicitly remove window')
  check(effects()==other,'window editing does not rewrite codes, balances, journals, emails or registrations')
  subprocess.run(['node',str(R/'tests/reward-window-ui.cjs'),str(pid),str(rid)],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
print(n,'reward window HTTP/domain assertions passed')
