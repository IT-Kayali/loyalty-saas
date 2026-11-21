#!/usr/bin/env python3
"""Kampagnenentwurf: Editor ohne Versand, Konflikt und unveränderbare abgeschlossene Inhalte. Wegwerf-Server."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json,uuid
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-campaign-edit-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public','tools']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 log=open(A/'server.log','w');s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  def mk():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  def req(path,data=None,c=None):
   if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
   try:
    with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode('utf-8','replace'),x.url
   except urllib.error.HTTPError as x:return x.code,x.read().decode('utf-8','replace'),x.url
  def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c=c)[1]).group(1)
  o=mk();req('/login',{'csrf':csrf(o,'/login'),'email':'demo@loyalty.local','password':'demo1234'},o)
  M=mk();req('/member/login',{'csrf':csrf(M,'/member/login'),'email':'aylin@example.com','password':'kunde1234'},M)
  sid=int(sql("db()->exec(\"INSERT INTO segments(business_id,name,rule,param)VALUES(1,'Alle','all',0)\");echo db()->lastInsertId();"))
  cid=int(sql("db()->exec(\"INSERT INTO campaigns(business_id,segment_id,name,subject,body)VALUES(1,%d,'Alt','Betreff alt','Text alt')\");echo db()->lastInsertId();"%sid))
  P='/campaign/%d/edit'%cid
  state=lambda:json.loads(sql("echo json_encode(db()->query('SELECT * FROM campaigns WHERE id=%d')->fetch(PDO::FETCH_ASSOC));"%cid))
  rev=lambda:re.search(r'name="revision" value="([a-f0-9]+)"',req(P,c=o)[1]).group(1)
  def edit(extra=None,token=True):
   d={'name':'Neu','subject':'Betreff neu','body':'Text neu','segment_id':sid,'revision':rev()}
   if token:d['csrf']=csrf(o,P)
   if extra:d.update(extra)
   return req(P,d,o)
  def effects():return sql("echo json_encode([db()->query('SELECT * FROM campaign_sends')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM member_messages')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM mail_outbox')->fetchAll(PDO::FETCH_ASSOC)]);")
  old=state();before=effects()
  check(req(P,c=o)[0]==200 and 'Text alt' in req(P,c=o)[1],'Volltext und Editor lesbar')
  check(edit(token=False)[0]==403 and state()==old,'CSRF erforderlich')
  for bad in [{'name':''},{'name':'x'*81},{'subject':''},{'subject':'x'*121},{'body':''},{'body':'x'*2001},{'body':'Text\x00Test'},{'segment_id':99999}]:
   edit(bad);check(state()==old,'Ungültige Entwurfsfelder unverändert')
  edit();check(state()['body']=='Text neu' and state()['name']=='Neu' and effects()==before,'Speichern ändert Entwurf ohne Versand')
  stale=rev();edit({'body':'Neuester Text'});saved=state()
  check('inzwischen geändert' in edit({'revision':stale,'body':'Veraltet'})[1] and state()==saved,'Veralteter Tab überschreibt nicht')
  edit({'body':'<script>alert(1)</script>\nZweite Zeile'});h=req(P,c=o)[1]
  check('<script>alert(1)</script>' not in h and '&lt;script&gt;' in h,'Gespeicherte Textansicht escaped')
  check('/login' in req(P,c=mk())[2] and '/login' in req(P,c=M)[2],'Gast/Mitglied ohne Zugriff')
  sql("$q=db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)');$q->execute(['Fremd','foreign@example.com',password_hash('test123456',PASSWORD_DEFAULT)]);")
  outsider=mk();req('/login',{'csrf':csrf(outsider,'/login'),'email':'foreign@example.com','password':'test123456'},outsider)
  check(req(P,c=outsider)[0]==404,'Fremder Betreiber bekommt 404')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  saved=state();check(edit()[0]==402 and state()==saved,'Plan blockiert Änderung')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  # Edit-to-send race: captured editor then send, edit must fail unchanged.
  stale=rev();sql("echo json_encode(campaign_dispatch(1,1,%d));"%cid);saved=state();before=effects()
  d={'name':'Nach Versand','subject':'Neu','body':'Neu','segment_id':sid,'revision':stale,'csrf':csrf(o,'/business/1')}
  h=req(P,d,o)[1];check('Nicht editierbar' in h and state()==saved and effects()==before,'Nach Versand gewonnener Schreiblock schützt Inhalt und Belege')
  check('Entwurf speichern' not in req(P,c=o)[1] and 'Nur lesen' in req(P,c=o)[1],'Gesendete Kampagne nur lesbar')
  # Historical partial draft stays locked.
  partial=int(sql("db()->exec(\"INSERT INTO campaigns(business_id,segment_id,name,subject,body)VALUES(1,%d,'Teilversand','Alt','Alt')\");echo db()->lastInsertId();"%sid))
  sql("db()->exec('INSERT INTO campaign_sends(campaign_id,member_id)SELECT %d,id FROM members WHERE business_id=1 LIMIT 1');"%partial)
  check('Entwurf speichern' not in req('/campaign/%d/edit'%partial,c=o)[1],'Historischer Teilversand nicht editierbar')
  outonly=int(sql("db()->exec(\"INSERT INTO campaigns(business_id,segment_id,name,subject,body)VALUES(1,%d,'Outbox','Alt','Alt')\");echo db()->lastInsertId();"%sid))
  sql("$q=db()->prepare('INSERT INTO mail_outbox(business_id,recipient,subject,body,category,dedup_key)VALUES(?,?,?,?,?,?)');$q->execute([1,'local@example.test','Alt','Alt','marketing','campaign:%d:999']);"%outonly)
  check('Entwurf speichern' not in req('/campaign/%d/edit'%outonly,c=o)[1],'Historische Outbox allein blockiert Änderung')
  foreign=int(sql("db()->exec(\"INSERT INTO segments(business_id,name,rule,param)VALUES(2,'Fremd','all',0)\");echo db()->lastInsertId();"))

  # Both writes compete for the SQLite lock, outcome may follow either order.
  for attempt in range(4):
   race=int(sql("db()->exec(\"INSERT INTO campaigns(business_id,segment_id,name,subject,body)VALUES(1,%d,'Race','Alt','Alt')\");echo db()->lastInsertId();"%sid))
   revision=sql("echo campaign_revision(db()->query('SELECT * FROM campaigns WHERE id=%d')->fetch());"%race)
   update="require 'public/index.php';try{campaign_update(1,%d,['name'=>'Race','subject'=>'Neu','body'=>'Neu','segment_id'=>%d,'revision'=>'%s']);echo 'edited';}catch(InvalidArgumentException $e){echo 'locked';}"%(race,sid,revision)
   send="require 'public/index.php';campaign_dispatch(1,1,%d);echo 'sent';"%race
   workers=[subprocess.Popen([PHP,'-r',cmd],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for cmd in [update,send]]
   results=[w.communicate(timeout=15) for w in workers]
   check(all(w.returncode==0 for w in workers) and results[0][0] in ['edited','locked'] and results[1][0]=='sent','Konkurrenzprozesse ohne DB-Fehler')
   expected='Neu' if results[0][0]=='edited' else 'Alt'
   messages=json.loads(sql("echo json_encode(db()->query(\"SELECT text FROM member_messages WHERE kind='campaign' ORDER BY id DESC LIMIT 3\")->fetchAll(PDO::FETCH_COLUMN));"))
   check(len(messages)==3 and all(x.endswith(' - '+expected) for x in messages),'Inbox enthält denselben festgeschriebenen Text')
   check(sql("echo db()->query('SELECT body FROM campaigns WHERE id=%d')->fetchColumn();"%race)==expected and sql("echo db()->query('SELECT COUNT(*) FROM campaign_sends WHERE campaign_id=%d')->fetchColumn();"%race)=='3','Paralleländerung vor Versand oder unverändert abgewiesen')
  check(sql("try{campaign_update(1,%d,['name'=>'X','subject'=>'X','body'=>chr(255),'segment_id'=>%d]);}catch(InvalidArgumentException $e){echo 'rejected';}"%(cid,sid))=='rejected','Ungültiges UTF-8 abgewiesen')
  # New draft for browser and send-after-edit content test.
  fresh=int(sql("db()->exec(\"INSERT INTO campaigns(business_id,segment_id,name,subject,body)VALUES(1,%d,'Browserentwurf','Betreff','Text')\");echo db()->lastInsertId();"%sid))
  sentid=cid;cid=fresh;P='/campaign/%d/edit'%cid;cid_before=state();check('Segment gehört' in edit({'segment_id':foreign})[1] and state()==cid_before,'Fremdes Unternehmenssegment abgewiesen')
  subprocess.run(['node',str(R/'tests/campaign-edit-ui.cjs'),str(fresh),str(sentid)],cwd=R,check=True)
  sql("campaign_dispatch(1,1,%d);"%fresh)
  check('Neuester Text mobile' in sql("echo json_encode(db()->query(\"SELECT text FROM member_messages WHERE kind='campaign' ORDER BY id DESC LIMIT 3\")->fetchAll());"),'Versand liest zuletzt gespeicherten Entwurf')
 finally:s.terminate();s.wait();log.close()
 print(n,'Kampagnen-Editor-Prüfungen bestanden')
