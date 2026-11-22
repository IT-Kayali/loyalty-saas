#!/usr/bin/env python3
"""Reward name/cost editing, current prices versus existing claim snapshots, no real providers."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json,concurrent.futures
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-reward-edit-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 pid=int(sql("echo db()->query(\"SELECT id FROM programs WHERE business_id=1 AND type='points' LIMIT 1\")->fetchColumn();"))
 rid=int(sql("db()->exec(\"INSERT INTO rewards(program_id,name,points_cost)VALUES(%d,'Alter Name',20)\");echo db()->lastInsertId();"%pid));P='/reward/%d/edit'%rid
 mid=int(sql("echo db()->query(\"SELECT id FROM members WHERE business_id=1 AND email='aylin@example.com'\")->fetchColumn();"))
 card=int(sql("db()->exec('UPDATE programs SET claim_minutes=30 WHERE id=%d');db()->exec('INSERT INTO cards(member_id,program_id,points,points_lifetime)VALUES(%d,%d,500,500)');echo db()->lastInsertId();"%(pid,mid,pid)))
 claim=json.loads(sql("echo json_encode(claim_create([%d],%d,%d));"%(mid,card,rid)))
 sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Foreign','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);")
 def row():return json.loads(sql('echo json_encode(db()->query("SELECT * FROM rewards WHERE id=%d")->fetch());'%rid))
 def effects():return sql("echo json_encode([db()->query('SELECT * FROM members')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM reward_claims')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM pass_devices')->fetchAll()]);")
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
  def rev():return re.search(r'name="revision" value="([a-f0-9]+)"',req(P,o)[1]).group(1)
  def save(changes=None,token=True):
   d={'name':'Neue Prämie','points_cost':'50','revision':rev()}
   if token:d['csrf']=csrf(o,P)
   if changes:d.update(changes)
   return req(P,o,d)
  before=row();other=effects();st,h,_,headers=req(P,o)
  check(st==200 and headers['Cache-Control']=='private, no-store','private current reward editor')
  check('href="'+P+'"' in req('/program/%d'%pid,o)[1],'program links fixed reward editor')
  check('Bereits ausgestellte Codes' in h and 'Zeitfenster' in h,'snapshot and validity effects visible')
  check(req(P,mk())[2].endswith('/login') and req(P,m)[2].endswith('/login'),'guest/member cannot edit operator reward')
  check(req(P,f)[0]==404 and req('/reward/99999/edit',o)[0]==404,'foreign and unknown targets unavailable')
  check(row()==before and effects()==other,'GET does not mutate reward or customer data')
  save(token=False);check(row()==before,'CSRF required')
  for d in [{'name':''},{'name':'X'*121},{'name':'bad\nname'},{'points_cost':'0'},{'points_cost':'-1'},{'points_cost':'1.5'},{'points_cost':'1e2'},{'points_cost':'100001'},{'points_cost':'01'},{'revision':'bad'}]:
   save(d);check(row()==before,'invalid fields or stale revision rejected')
  for key in ['name','points_cost','revision']:
   d={'csrf':csrf(o,P),'name':'Other','points_cost':'40','revision':rev()};del d[key];d[key+'[]']='array';req(P,o,d);check(row()==before,'array input rejected for '+key)
  stale=rev();save({'program_id':'9999','reward_id':'9999','valid_from':'2999-01-01 00:00:00','name':'  Neue Prämie  '});r=row()
  check(r['name']=='Neue Prämie' and r['points_cost']==50 and r['program_id']==pid and r['valid_from']==before['valid_from'],'trimmed name/cost only, fixed target and validity unchanged')
  now=row();save({'name':'stale','revision':stale});check(row()==now,'stale tab cannot overwrite current name/cost')
  save({'name':'Ü'*120,'points_cost':'100000'});check(row()['name']=='Ü'*120 and row()['points_cost']==100000,'Unicode name and inclusive upper bound accepted')
  save({'points_cost':'1'});check(row()['points_cost']==1,'inclusive lower bound accepted')
  sql("db()->exec(\"CREATE TRIGGER fail_reward_audit BEFORE INSERT ON audit_logs WHEN NEW.action='reward.edit' BEGIN SELECT RAISE(ABORT,'test failure'); END\");");now=row();save();check(row()==now,'audit failure rolls fields back');sql('db()->exec("DROP TRIGGER fail_reward_audit");')
  save({'name':'<script>alert(1)</script>'});check('&lt;script&gt;' in req(P,o)[1] and 'value="<script>' not in req(P,o)[1],'reward name escaped')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  check(req(P,o)[0]==200,'unpaid owner can read reward');now=row();save();check(row()==now,'unpaid owner cannot edit reward');(A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  rr=rev()
  def writer(name):return sql("try{reward_edit(1,%d,['name'=>'%s','points_cost'=>'50','revision'=>'%s']);echo 'ok';}catch(InvalidArgumentException){echo 'blocked';}"%(rid,name,rr))
  with concurrent.futures.ThreadPoolExecutor(2) as pool:result=list(pool.map(writer,['Winner A','Winner B']))
  check(sorted(result)==['blocked','ok'],'two real writers have one current-state winner')
  check(effects()==other,'edit leaves all balances journal claims email and device records untouched')
  # Existing code charges its snapshot, newly created code sees the changed reward.
  used=json.loads(sql("echo json_encode(claim_redeem(1,'%s',['kind'=>'owner','id'=>1]));"%claim['code']))
  check(used['points_cost']==20 and used['reward_name']=='Alter Name','old code redeems original name/cost snapshot after reward edit')
  check(int(sql("echo db()->query(\"SELECT amount FROM transactions WHERE card_id=%d AND kind='points_redeem' ORDER BY id DESC LIMIT 1\")->fetchColumn();"%card))==20,'redemption ledger records original twenty points, not new fifty')
  fresh=json.loads(sql('echo json_encode(claim_create([%d],%d,%d));'%(mid,card,rid)));check(fresh['reward']==row()['name'],'new code takes current reward name')
  # Shared strict create validation, no silent clamp/coercion.
  count=lambda:int(sql('echo db()->query("SELECT COUNT(*) FROM rewards")->fetchColumn();'))
  total=count()
  for cost in ['0','-1','1.5','100001','1e2']:
   req('/reward/create',o,{'csrf':csrf(o,P),'program_id':pid,'name':'Invalid','points_cost':cost});check(count()==total,'create shares strict cost validation')
  req('/reward/create',o,{'csrf':csrf(o,P),'program_id':pid,'name':'Created','points_cost':'75'});check(count()==total+1,'valid reward create still supported')
  subprocess.run(['node',str(R/'tests/reward-editor-ui.cjs'),str(pid),str(rid)],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
print(n,'reward editor HTTP/domain assertions passed')
