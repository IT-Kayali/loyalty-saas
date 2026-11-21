#!/usr/bin/env python3
"""Own location name/address only, disposable PHP install."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-location-edit-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(c):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+c],cwd=A,text=True).strip()
 id=int(sql("db()->exec(\"INSERT INTO locations(business_id,name,address)VALUES(1,'Filiale','Alte Straße 1')\");echo db()->lastInsertId();"));P='/location/%d/edit'%id
 sql("db()->exec('UPDATE staff SET location_id=%d WHERE business_id=1');db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Fremd','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);"%id)
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,pw):
  c=client();req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':pw});return c
 def revision(c):return re.search(r'name="revision" value="([a-f0-9]+)"',req(P,c)[1]).group(1)
 def state():return json.loads(sql('echo json_encode(db()->query("SELECT * FROM locations WHERE id=%d")->fetch());'%id))
 def effect():return sql("echo json_encode([db()->query('SELECT * FROM businesses')->fetchAll(),db()->query('SELECT * FROM members')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM programs')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM staff')->fetchAll(),db()->query('SELECT * FROM member_messages')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll()]);")
 with open(A/'server.log','w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
    except OSError:time.sleep(.1)
   owner=login('demo@loyalty.local','demo1234');foreign=login('foreign@test.local','foreignpass1')
   def edit(extra=None,token=True):
    d={'name':'Neue Filiale','address':'Neue Straße 2','revision':revision(owner)}
    if token:d['csrf']=csrf(owner,P)
    if extra:d.update(extra)
    return req(P,owner,d)
   check('/login' in req(P,client())[2],'guest cannot read editor')
   check(req(P,foreign)[0]==404,'foreign owner receives 404')
   check('href="'+P+'"' in req('/organization',owner)[1],'own location has edit link')
   check(req(P,owner)[3]['Cache-Control']=='private, no-store','editor private no-store')
   old=state();before=effect();check(edit(token=False)[0]==403 and state()==old,'CSRF required')
   for bad in [{'name':''},{'name':'x'*81},{'name':'Name\x00N'},{'address':'a'*201},{'address':'Straße\nOrt'},{'address':'Ort\x00Code'}]:
    check(edit(bad)[0]==200 and state()==old and effect()==before,'invalid fields leave all data unchanged')
   for key in ['name','address','revision']:
    d={'csrf':csrf(owner,P),'name':'Array','address':'Ort','revision':revision(owner)};d[key+'[]']=d.pop(key)
    check(req(P,owner,d)[0]==200 and state()==old,'array field rejected without server error')
   check(sql("try{location_edit(1,%d,['name'=>'Valid','address'=>chr(255),'revision'=>'x']);}catch(InvalidArgumentException $e){echo 'blocked';}"%id)=='blocked','invalid UTF-8 rejected')
   stale=revision(owner);res=edit({'name':'  Neue Filiale  ','address':'  Straße 3  ','business_id':2,'id':99999,'active':0});new=state()
   check(res[0]==200 and new['name']=='Neue Filiale' and new['address']=='Straße 3' and new['active']==old['active'] and new['business_id']==old['business_id'],'only trimmed name address change, target active and business ignored')
   check(effect()==before,'assignments staff balances messages and mail unchanged')
   check('inzwischen geändert' in edit({'revision':stale})[1] and state()==new,'stale fields cannot overwrite current data')
   check('Neue Filiale' in req('/organization',owner)[1] and 'Straße 3' in req('/organization',owner)[1],'organization uses updated labels')
   check('Neue Filiale' in req('/business/1/team',owner)[1],'staff location options use current name')
   edit({'name':'ä'*80,'address':'ü'*200});check(state()['name']=='ä'*80 and state()['address']=='ü'*200,'exact Unicode name and address boundaries accepted')
   edit({'name':'<b>Text</b>','address':'<script>Address</script>'});check('<script>Address</script>' not in req(P,owner)[1] and '&lt;script&gt;Address' in req('/organization',owner)[1],'name address escaped in editor and list')
   edit({'address':''});check(state()['address']=='','optional address may be cleared')
   # Active state is independent from the text revision and must never be overwritten.
   rev=revision(owner);sql('db()->exec("UPDATE locations SET active=0 WHERE id=%d");'%id)
   check(edit({'revision':rev,'name':'Inaktiv editiert'})[0]==200 and state()['active']==0 and state()['name']=='Inaktiv editiert','concurrent deactivation remains intact while text edit succeeds')
   check('Inaktiv' in req(P,owner)[1],'inactive location still editable with current state shown')
   (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
   saved=state();check(req(P,owner)[0]==200 and edit()[0]==402 and state()==saved,'plan blocks save not read')
   check(sql("try{location_edit(1,%d,['name'=>'Blocked','address'=>'','revision'=>'x']);}catch(InvalidArgumentException $e){echo 'blocked';}"%id)=='blocked' and state()==saved,'direct mutation also checks plan')
   (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
   check(sql("try{location_edit(99999,%d,['name'=>'Foreign','address'=>'','revision'=>'x']);}catch(InvalidArgumentException $e){echo 'blocked';}"%id)=='blocked' and state()==saved,'direct mutation checks owner')
   check(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='location.edit' AND details LIKE '%Filiale%'\")->fetchColumn();")=='0','audit contains IDs no field text')
   sql("db()->exec(\"CREATE TRIGGER location_audit_fail BEFORE INSERT ON audit_logs WHEN NEW.action='location.edit' BEGIN SELECT RAISE(ABORT,'TEST_FAIL'); END\");")
   check(sql("$l=db()->query('SELECT * FROM locations WHERE id=%d')->fetch();try{location_edit(1,%d,['name'=>'Rollback','address'=>'Rollback','revision'=>location_revision($l)]);}catch(Throwable $e){echo 'rolled';}"%(id,id))=='rolled' and state()==saved and effect()==before,'audit failure rolls back both text fields')
   sql("db()->exec('DROP TRIGGER location_audit_fail');")
   rev=revision(owner);cmd="try{location_edit(1,%d,['name'=>'Parallel','address'=>'Winner','revision'=>'%s']);echo 'saved';}catch(InvalidArgumentException $e){echo 'stale';}"%(id,rev)
   jobs=[subprocess.Popen([PHP,'-r',"require 'public/index.php';"+cmd],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(2)];out=[j.communicate(timeout=15)[0] for j in jobs]
   check(sorted(out)==['saved','stale'] and all(j.returncode==0 for j in jobs),'parallel edits have one winner on old revision')
   check(effect()==before,'parallel edit has no unrelated side effects')
   subprocess.run(['node',str(R/'tests/location-edit-ui.cjs'),str(id)],cwd=R,check=True)
  finally:server.terminate();server.wait()
print(n,'location edit HTTP/domain assertions passed')
