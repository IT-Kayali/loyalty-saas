#!/usr/bin/env python3
"""Own network name only, disposable PHP install, no outside services."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-network-edit-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(c):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+c],cwd=A,text=True).strip()
 id=int(sql("db()->exec(\"INSERT INTO networks(user_id,name)VALUES(1,'Eigene Gruppe')\");echo db()->lastInsertId();"));P='/network/%d/edit'%id
 sql("db()->exec('UPDATE businesses SET network_id=%d WHERE id=1');db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Fremd','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);db()->exec(\"INSERT INTO networks(user_id,name)SELECT id,'FOREIGN-NETWORK' FROM users WHERE email='foreign@test.local'\");"%id)
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
 def name():return sql('echo db()->query("SELECT name FROM networks WHERE id=%d")->fetchColumn();'%id)
 def effect():return sql("echo json_encode([db()->query('SELECT * FROM businesses')->fetchAll(),db()->query('SELECT * FROM members')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM programs')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM staff')->fetchAll(),db()->query('SELECT * FROM locations')->fetchAll(),db()->query('SELECT * FROM member_messages')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll()]);")
 with open(A/'server.log','w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
    except OSError:time.sleep(.1)
   owner=login('demo@loyalty.local','demo1234');foreign=login('foreign@test.local','foreignpass1')
   def edit(value,extra=None,token=True):
    d={'name':value,'revision':revision(owner)}
    if token:d['csrf']=csrf(owner,P)
    if extra:d.update(extra)
    return req(P,owner,d)
   check('/login' in req(P,client())[2],'guest cannot read editor')
   check(req(P,foreign)[0]==404,'foreign owner receives 404')
   check('FOREIGN-NETWORK' not in req('/organization',owner)[1],'list never shows foreign networks')
   check('href="'+P+'"' in req('/organization',owner)[1],'own network has edit link')
   check('Zugeordnete eigene Unternehmen: 1.' in req(P,owner)[1],'editor shows own assigned company count')
   check(req(P,owner)[3]['Cache-Control']=='private, no-store','editor private no-store')
   check(edit('No CSRF',token=False)[0]==403 and name()=='Eigene Gruppe','CSRF required')
   before=effect()
   for bad in ['', 'x'*81,'Name\x00N','Name\nN']:
    check(edit(bad)[0]==200 and name()=='Eigene Gruppe' and effect()==before,'invalid name leaves all data unchanged')
   d={'csrf':csrf(owner,P),'name[]':'Array','revision':revision(owner)}
   check(req(P,owner,d)[0]==200 and name()=='Eigene Gruppe','array name rejected without server error')
   check(sql("try{network_rename(1,%d,['name'=>chr(255),'revision'=>'x']);}catch(InvalidArgumentException $e){echo 'blocked';}"%id)=='blocked','invalid UTF-8 rejected')
   stale=revision(owner);check('Zuordnungen und Salden unverändert' in edit('  Neue Gruppe  ',{'id':'99999','user_id':'99999','network_id':'99999'})[1] and name()=='Neue Gruppe' and effect()==before,'rename trims only name and ignores target overrides')
   check(edit('Lost update',{'revision':stale})[0]==200 and name()=='Neue Gruppe' and 'inzwischen geändert' in edit('Lost update',{'revision':stale})[1],'stale name form cannot overwrite current name')
   check('Neue Gruppe' in req('/organization',owner)[1],'organization and assignment options use current name')
   edit('ä'*80);check(name()=='ä'*80,'exact 80 Unicode characters accepted')
   edit('<script>as text</script>');check('<script>as text</script>' not in req(P,owner)[1] and '&lt;script&gt;as text' in req(P,owner)[1],'HTML name escaped in editor')
   check('&lt;script&gt;as text' in req('/organization',owner)[1],'HTML name escaped in list and options')
   check(effect()==before,'success never changes membership assignments balances or mail')
   check(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='network.rename'\")->fetchColumn();")=='3','only successful saves audited')
   check(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='network.rename' AND details LIKE '%as text%'\")->fetchColumn();")=='0','audit contains IDs no name text')
   (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
   saved=name();check(req(P,owner)[0]==200 and edit('Blocked')[0]==402 and name()==saved,'paid plan blocks save not read')
   check(sql("try{network_rename(1,%d,['name'=>'Blocked','revision'=>'x']);}catch(InvalidArgumentException $e){echo 'blocked';}"%id)=='blocked' and name()==saved,'direct mutation also checks plan')
   (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
   check(sql("try{network_rename(99999,%d,['name'=>'Foreign','revision'=>'x']);}catch(InvalidArgumentException $e){echo 'blocked';}"%id)=='blocked' and name()==saved,'direct mutation checks owner')
   sql("db()->exec(\"CREATE TRIGGER network_audit_fail BEFORE INSERT ON audit_logs WHEN NEW.action='network.rename' BEGIN SELECT RAISE(ABORT,'TEST_FAIL'); END\");")
   check(sql("$n=db()->query('SELECT * FROM networks WHERE id=%d')->fetch();try{network_rename(1,%d,['name'=>'Rollback','revision'=>network_revision($n)]);}catch(Throwable $e){echo 'rolled';}"%(id,id))=='rolled' and name()==saved and effect()==before,'audit failure rolls back name completely')
   sql("db()->exec('DROP TRIGGER network_audit_fail');")
   # Two writers on same old revision cannot both win.
   rev=revision(owner);cmd="try{network_rename(1,%d,['name'=>'Parallel','revision'=>'%s']);echo 'saved';}catch(InvalidArgumentException $e){echo 'stale';}"%(id,rev)
   jobs=[subprocess.Popen([PHP,'-r',"require 'public/index.php';"+cmd],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(2)];out=[j.communicate(timeout=15)[0] for j in jobs]
   check(sorted(out)==['saved','stale'] and all(j.returncode==0 for j in jobs),'parallel saves serialize and only one old revision wins')
   check(effect()==before,'parallel rename leaves all unrelated data intact')
   subprocess.run(['node',str(R/'tests/network-edit-ui.cjs'),str(id)],cwd=R,check=True)
  finally:server.terminate();server.wait()
print(n,'network edit HTTP/domain assertions passed')
