#!/usr/bin/env python3
"""CLI invocation receipts: actual local commands, fencing, private read-only admin view."""
import fcntl,http.cookiejar,json,os,pathlib,re,shutil,subprocess,tempfile,time,urllib.error,urllib.parse,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(value,label):
 global n
 assert value,label
 n+=1;print('PASS',label,flush=True)

with tempfile.TemporaryDirectory(prefix='loyalty-maintenance-') as temp:
 A=pathlib.Path(temp)
 for folder in ['app','public','tools']:shutil.copytree(R/folder,A/folder)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 config="<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['admin@maintenance.invalid'];$c['webhook_transport']=function(){file_put_contents(__DIR__.'/unexpected-send','1');throw new RuntimeException('Unexpected transport');};return $c;"
 cfg=A/'config.php';cfg.write_text(config);subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 subprocess.run([PHP,str(R/'tests/maintenance.php')],cwd=A,check=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 def state():return json.loads(sql("$out=[];foreach(db()->query(\"SELECT name FROM sqlite_master WHERE type='table' AND name!='maintenance_status' ORDER BY name\")->fetchAll() as $r){$name=$r['name'];$out[$name]=db()->query('SELECT * FROM \"'.$name.'\"')->fetchAll();}echo json_encode($out);"))
 def receipts():return json.loads(sql("echo json_encode(db()->query('SELECT * FROM maintenance_status ORDER BY task')->fetchAll());"))
 sql("db()->exec('DELETE FROM maintenance_status');")
 (A/'closed-stderr.php').write_text("""<?php require 'public/index.php';fclose(STDERR);$d=db();$d->exec('BEGIN IMMEDIATE');echo maintenance_observe('mail_worker',fn()=>42);$d->exec('ROLLBACK');echo maintenance_observe('mail_worker',function()use($d){$d->exec('BEGIN IMMEDIATE');return 43;});$d->exec('ROLLBACK');$problem=new RuntimeException('private');try{maintenance_observe('mail_worker',function()use($d,$problem){$d->exec('BEGIN IMMEDIATE');throw $problem;});}catch(Throwable $e){if($e===$problem)echo 'preserved';}$d->exec('ROLLBACK');""")
 p=subprocess.run([PHP,'closed-stderr.php'],cwd=A,text=True,capture_output=True);check(p.returncode==0 and p.stdout=='4243preserved','closed STDERR plus failed receipt writes preserve task results and original exception')
 sql("db()->exec('DELETE FROM maintenance_status');")
 before=state()
 for task,file,expected in [('mail_worker','mail-worker.php','disabled, no emails sent'),('points_expiry','expire-points.php','Karten: 0, Punkte entfernt: 0'),('webhook_retry','webhook-retry.php','Versucht: 0, zugestellt: 0')]:
  p=subprocess.run([PHP,'tools/'+file],cwd=A,text=True,capture_output=True)
  check(p.returncode==0 and expected in p.stdout,'actual CLI command preserves output/result '+task)
  r=next(r for r in receipts() if r['task']==task);check(r['state']=='completed' and r['finished_at'] is not None,'actual CLI invocation recorded '+task)
 check(state()==before,'empty local worker runs only add receipts, no other application changes')
 for file in ['mail-worker','webhook-retry']:
  before=receipts()
  with (A/'data'/ (file+'.lock')).open('w') as lock:
   fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB);p=subprocess.run([PHP,'tools/'+file+'.php'],cwd=A,text=True,capture_output=True)
   check(p.returncode==1 and receipts()==before,'existing busy lock prevents work and false receipt '+file)
 # Actual overlapping processes: older process finishes while newer process is still waiting.
 program="""<?php require 'public/index.php';maintenance_observe('points_expiry',function()use($argv){file_put_contents($argv[1].'-ready','1');while(!is_file($argv[1].'-release'))usleep(1000);});"""
 (A/'overlap.php').write_text(program)
 def waitfile(name):
  until=time.monotonic()+10
  while not (A/name).exists():
   assert time.monotonic()<until,name+' timeout';time.sleep(.005)
 first=subprocess.Popen([PHP,'overlap.php','first'],cwd=A);second=None
 try:
  waitfile('first-ready');first_token=next(r for r in receipts() if r['task']=='points_expiry')['run_token'];second=subprocess.Popen([PHP,'overlap.php','second'],cwd=A);waitfile('second-ready');second_token=next(r for r in receipts() if r['task']=='points_expiry')['run_token'];check(first_token!=second_token,'overlapping real processes obtain distinct receipt tokens')
  (A/'first-release').touch();first.wait(timeout=10);check(first.returncode==0 and next(r for r in receipts() if r['task']=='points_expiry')['state']=='started','older real process cannot finish newer invocation receipt')
  (A/'second-release').touch();second.wait(timeout=10);r=next(r for r in receipts() if r['task']=='points_expiry');check(second.returncode==0 and r['state']=='completed' and r['run_token']==second_token,'newer real process closes its own receipt')
 finally:
  for p in [first,second]:
   if p and p.poll() is None:p.kill();p.wait()
 sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Admin','admin@maintenance.invalid',password_hash('adminpass1',PASSWORD_DEFAULT)]);db()->exec(\"UPDATE maintenance_status SET started_at=datetime('now','-10 minutes'),finished_at=datetime('now','-9 minutes'),state='completed' WHERE task='mail_worker';UPDATE maintenance_status SET started_at=datetime('now','-5 minutes'),finished_at=NULL,state='started' WHERE task='points_expiry';UPDATE maintenance_status SET started_at=datetime('now','-3 minutes'),finished_at=datetime('now','-2 minutes'),state='failed' WHERE task='webhook_retry'\");")
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None,method=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data,method=method),timeout=15) as r:return r.status,r.read().decode(),r.url,r.headers
  except urllib.error.HTTPError as r:return r.code,r.read().decode(),r.url,r.headers
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,pw):
  c=client();assert req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':pw})[2].endswith('/dashboard');return c
 with (A/'server.log').open('w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(60):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login',timeout=1);break
    except OSError:time.sleep(.05)
   base='/admin/maintenance';admin=login('admin@maintenance.invalid','adminpass1');owner=login('demo@loyalty.local','demo1234');member=client();staff=client()
   check(req('/member/login',member,{'csrf':csrf(member,'/member/login'),'email':'aylin@example.com','password':'kunde1234'})[2].endswith('/wallet'),'real member sign-in')
   check(req('/staff/login',staff,{'csrf':csrf(staff,'/staff/login'),'business_id':1,'name':'Lena','pin':'4321'})[2].endswith('/staff'),'real staff sign-in')
   for c in [client(),member,staff]:check(req(base,c)[2].endswith('/login'),'guest/member/staff not platform owner')
   check(req(base,owner)[0]==403,'ordinary operator cannot inspect global task receipts')
   st,body,_,h=req(base,admin);check(st==200 and '<h1>CLI-Laufstatus</h1>' in body,'platform administrator reads separate receipt page')
   check(h['Cache-Control']=='private, no-store' and h['Referrer-Policy']=='no-referrer','private page and referrer headers')
   check(all('data-maintenance-state="'+s+'"' in body for s in ['started','completed','failed']),'exact stored states represented without fabricated health score')
   check('Kein Abschluss gespeichert' in body and 'Kein Beweis für eingerichtete Cronjobs' in body and 'nicht unterscheidbar' in body,'incomplete invocation and unknown scheduler provenance are explicit')
   check('einzelne unklare Mailergebnisse' in body and 'fehlgeschlagene Webhook-Versuche' in body,'process completion not confused with delivery success')
   check('href="'+base+'"' in req('/admin/health',admin)[1],'existing delivery page links separate CLI receipts')
   check('<form' not in body and 'run_token' not in body and not any(r['run_token'] in body for r in receipts()),'no execution form, token or per-run identity disclosed')
   before=state();runs=receipts()
   for suffix in ['', '?action=webhook_run_due&run=mail_worker','?task[]=points_expiry&now=0&retry=1']:
    check(req(base+suffix,admin)[0]==200,'query never selects work or changes snapshot clock')
   for method in ['HEAD','OPTIONS','PUT','DELETE']:
    st,_,_,h=req(base,admin,method=method);check(st==405 and h['Allow']=='GET','unsupported method rejected '+method)
   st,_,_,h=req(base,admin,{'csrf':csrf(admin,'/admin'),'action':'webhook_run_due'});check(st==405 and h['Allow']=='GET','valid-CSRF POST cannot invoke worker')
   check(req(base,admin,{'action':'webhook_run_due'})[0]==403,'central CSRF protection retained')
   check(state()==before and receipts()==runs and not (A/'unexpected-send').exists(),'all GETs/rejected methods leave task and application rows unchanged, no provider calls')
   cfg.write_text(config.replace("['admin@maintenance.invalid']","[]"));check(req(base,admin)[0]==403,'removing current platform allowlist revokes read access')
   cfg.write_text(config.replace('return $c;',"$c['stripe']['enforce_limits']=true;return $c;"));check(req(base,admin)[0]==200,'platform read remains independent of tenant paid plan');cfg.write_text(config)
   ui_before=state();ui_runs=receipts()
   subprocess.run(['node',str(R/'tests/maintenance-ui.cjs')],cwd=R,check=True)
   ui_after=state();check(receipts()==ui_runs and all(ui_before[k]==ui_after[k] for k in ui_before if k not in ['audit_logs','rate_limits','sqlite_sequence']),'browser links and refresh change no task or business rows')
   check(len(ui_after['audit_logs'])==len(ui_before['audit_logs'])+3,'only three expected browser login audit rows added')
   old_seq={r['name']:r['seq'] for r in ui_before['sqlite_sequence']};new_seq={r['name']:r['seq'] for r in ui_after['sqlite_sequence']};check(new_seq.get('audit_logs')==old_seq.get('audit_logs',0)+3 and {k:v for k,v in old_seq.items() if k!='audit_logs'}=={k:v for k,v in new_seq.items() if k!='audit_logs'},'only expected login audit sequence advances')
   sql("db()->exec(\"UPDATE maintenance_status SET started_at='PRIVATE-BAD-TIME' WHERE task='mail_worker';DELETE FROM maintenance_status WHERE task='points_expiry'\");")
   st,body,_,_=req(base,admin);check(st==200 and 'Gespeicherte Daten prüfen' in body and 'PRIVATE-BAD-TIME' not in body,'bad timestamp yields safe invalid label without raw data')
   check('Kein Lauf erfasst' in body and 'bedeutet nicht, dass die Aufgabe nie lief' in body,'missing receipt does not assert no historical invocation')
   sql("db()->exec('DROP TABLE maintenance_status;CREATE TABLE maintenance_status(task TEXT)');")
   st,body,_,h=req(base,admin);check(st==503 and 'nicht verfügbar' in body and 'no such' not in body and 'SQLSTATE' not in body and h['Cache-Control']=='private, no-store','schema/read failure yields generic private 503, no partial receipts')
  finally:server.terminate();server.wait(timeout=10)
 print(n,'maintenance HTTP/integration assertions passed',flush=True)
 print('No external provider calls or new task scheduling.',flush=True)
