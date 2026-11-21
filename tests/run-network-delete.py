#!/usr/bin/env python3
"""Only unassigned own grouping, confirmed and serialized. Disposable install."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-network-delete-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(c):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+c],cwd=A,text=True).strip()
 def fixture(name='Delete me'):
  return int(sql("db()->prepare('INSERT INTO networks(user_id,name)VALUES(1,?)')->execute(["+json.dumps(name,ensure_ascii=False)+"]);echo db()->lastInsertId();"))
 id=fixture();linked=fixture('Assigned');sql("db()->exec('UPDATE businesses SET network_id=%d WHERE id=1');db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Foreign','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);"%linked)
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,pw):
  c=client();req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':pw});return c
 def revision(id):return sql('echo network_revision(db()->query("SELECT * FROM networks WHERE id=%d")->fetch());'%id)
 def exists(id):return sql('echo db()->query("SELECT COUNT(*) FROM networks WHERE id=%d")->fetchColumn();'%id)=='1'
 def effects():return sql("echo json_encode([db()->query('SELECT * FROM businesses')->fetchAll(),db()->query('SELECT * FROM members')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM programs')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM staff')->fetchAll(),db()->query('SELECT * FROM locations')->fetchAll(),db()->query('SELECT * FROM member_messages')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll()]);")
 with open(A/'server.log','w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
    except OSError:time.sleep(.1)
   owner=login('demo@loyalty.local','demo1234');foreign=login('foreign@test.local','foreignpass1');P='/network/%d/delete'%id
   def form(id,name='Delete me',**extra):
    d={'csrf':csrf(owner,'/organization'),'confirm_name':name,'revision':revision(id)};d.update(extra);return d
   check('/login' in req(P,client())[2],'guest must sign in')
   check(req(P,foreign)[0]==404,'foreign owner 404')
   before=effects();audit=sql("echo db()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();");h=req(P,owner)
   check(h[0]==200 and 'Delete me' in h[1] and 'endgültig löschen' in h[1],'explicit confirmation preview identifies unassigned network')
   check(h[3]['Cache-Control']=='private, no-store','preview private no-store')
   check(effects()==before and sql("echo db()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();")==audit and exists(id),'preview changes no data or audit')
   check(req(P,owner,form(id,csrf='0'*64))[0]==403 and exists(id),'CSRF required')
   check('exakt bestätigen' in req(P,owner,form(id,'Wrong'))[1] and exists(id),'exact name required')
   check(req('/network/%d/delete'%linked,owner)[0]==200 and 'Gesperrt' in req('/network/%d/delete'%linked,owner)[1] and 'Unzugeordnetes Netzwerk endgültig löschen</button>' not in req('/network/%d/delete'%linked,owner)[1],'assigned grouping preview blocks submit')
   check('Noch Unternehmen zugeordnet' in req('/network/%d/delete'%linked,owner,form(linked,'Assigned'))[1] and exists(linked),'direct post cannot detach linked company')
   d=form(id);sql("db()->exec(\"UPDATE networks SET name='Changed' WHERE id=%d\");"%id)
   check('inzwischen geändert' in req(P,owner,d)[1] and exists(id),'renamed grouping cannot be deleted by old preview')
   d=form(id,'Changed');sql('db()->exec("UPDATE businesses SET network_id=%d WHERE id=2");'%id)
   check('Noch Unternehmen zugeordnet' in req(P,owner,d)[1] and exists(id),'new assignment after preview blocks deletion')
   sql('db()->exec("UPDATE businesses SET network_id=NULL WHERE id=2");');before=effects()
   (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
   check(req(P,owner)[0]==200 and req(P,owner,form(id,'Changed'))[0]==402 and exists(id),'plan blocks delete not preview')
   check(sql("try{network_delete(1,%d,['confirm_name'=>'Changed','revision'=>'x']);}catch(InvalidArgumentException $e){echo 'blocked';}"%id)=='blocked' and exists(id),'domain delete checks plan')
   (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
   check(sql("try{network_delete(99999,%d,['confirm_name'=>'Changed','revision'=>'x']);}catch(InvalidArgumentException $e){echo 'blocked';}"%id)=='blocked' and exists(id),'domain delete checks ownership')
   sql("db()->exec(\"CREATE TRIGGER delete_audit_fail BEFORE INSERT ON audit_logs WHEN NEW.action='network.delete' BEGIN SELECT RAISE(ABORT,'TEST_FAIL'); END\");")
   check(sql("$n=db()->query('SELECT * FROM networks WHERE id=%d')->fetch();try{network_delete(1,%d,['confirm_name'=>'Changed','revision'=>network_revision($n)]);}catch(Throwable $e){echo 'rolled';}"%(id,id))=='rolled' and exists(id) and effects()==before,'audit failure restores network with no side effects')
   sql("db()->exec('DROP TRIGGER delete_audit_fail');")
   check(req(P,owner,dict(form(id,'Changed'),id=linked,user_id=99999))[2].endswith('/organization') and not exists(id) and exists(linked),'confirmed own unassigned deletion ignores target overrides')
   check(effects()==before,'deletion changes no companies members balances staff or mail')
   check(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='network.delete'\")->fetchColumn();")=='1','exactly one successful ID audit')
   check(req(P,owner)[0]==404,'deleted preview unavailable')
   # Real race: assignment and deletion serialize, never delete linked company or leave dangling reference.
   race=fixture('Race');rev=revision(race)
   delete="try{network_delete(1,%d,['confirm_name'=>'Race','revision'=>'%s']);echo 'deleted';}catch(InvalidArgumentException $e){echo 'linked';}"%(race,rev)
   assign="try{db()->exec('BEGIN IMMEDIATE');db()->exec('UPDATE businesses SET network_id=%d WHERE id=2');db()->exec('COMMIT');echo 'assigned';}catch(Throwable $e){try{db()->exec('ROLLBACK');}catch(Throwable $x){}echo 'blocked';}"%race
   jobs=[subprocess.Popen([PHP,'-r',"require 'public/index.php';"+c],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for c in [delete,assign]];out=[j.communicate(timeout=15)[0] for j in jobs]
   check(all(j.returncode==0 for j in jobs) and out in [['deleted','blocked'],['linked','assigned']],'concurrent assignment and deletion have only safe outcomes')
   check(sql('echo db()->query("SELECT COUNT(*) FROM businesses b LEFT JOIN networks n ON n.id=b.network_id WHERE b.network_id IS NOT NULL AND n.id IS NULL")->fetchColumn();')=='0','race leaves no dangling company association')
   ui=fixture('Browser grouping');ui2=fixture('Browser grouping');subprocess.run(['node',str(R/'tests/network-delete-ui.cjs'),str(ui),str(ui2),str(linked)],cwd=R,check=True)
  finally:server.terminate();server.wait()
print(n,'network delete HTTP/domain assertions passed')
