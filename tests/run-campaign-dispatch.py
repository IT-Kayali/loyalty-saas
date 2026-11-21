#!/usr/bin/env python3
"""Kampagnenversand: atomare Vorbereitung, Rollback, Wiederholung und Parallelität. Keine SMTP-Zustellung."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json,uuid
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-vb-') as temp:
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
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['smtp']['enabled']=true;$c['smtp']['host']='smtp.example.test';$c['smtp']['from']='sender@example.test';return $c;")
  sid=int(sql("db()->exec(\"INSERT INTO segments(business_id,name,rule,param)VALUES(1,'Alle','all',0)\");echo db()->lastInsertId();"))
  def campaign():return int(sql("db()->exec(\"INSERT INTO campaigns(business_id,segment_id,name,subject,body)VALUES(1,%d,'Atomarer Test','Lokale Nachricht','Keine echte Mail')\");echo db()->lastInsertId();"%sid))
  def dispatch(cid,uid=1,bid=1):return json.loads(sql("echo json_encode(campaign_dispatch(%d,%d,%d));"%(uid,bid,cid)))
  def snapshot():return sql("echo json_encode([db()->query('SELECT * FROM campaigns')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM campaign_sends')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM member_messages')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM mail_outbox')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM audit_logs')->fetchAll(PDO::FETCH_ASSOC)]);")
  sql("db()->exec(\"UPDATE members SET marketing_consent=CASE WHEN email='ben@example.com' THEN 0 ELSE 1 END WHERE business_id=1\");")
  cid=campaign();r=dispatch(cid)
  check(r['members']==3 and not r['already'],'Alle drei Mitglieder genau einmal in Inbox')
  check(r['email']==1,'Nur Mitglieder mit gültiger E-Mail und Marketingeinwilligung in Mail-Outbox')
  check(sql("echo db()->query('SELECT COUNT(*) FROM campaign_sends WHERE campaign_id=%d')->fetchColumn();"%cid)=='3','Drei Versandbelege')
  before=snapshot();check(dispatch(cid)['already'] and snapshot()==before,'Wiederholung nach Commit schreibt nichts')
  # Force a deterministic DB failure after earlier recipients were written.
  fail=campaign();mid=sql("echo db()->query('SELECT id FROM members WHERE business_id=1 ORDER BY id LIMIT 1 OFFSET 1')->fetchColumn();")
  sql("db()->exec(\"CREATE TRIGGER fail_campaign BEFORE INSERT ON campaign_sends WHEN NEW.campaign_id=%d AND NEW.member_id=%s BEGIN SELECT RAISE(ABORT,'INJECTED_TEST_FAILURE'); END\");"%(fail,mid))
  before=snapshot();result=sql("try{campaign_dispatch(1,1,%d);}catch(Throwable $e){echo 'rolled-back';}"%fail)
  check(result=='rolled-back' and snapshot()==before,'Fehler beim zweiten Mitglied rollt Status, Inbox, Belege, Outbox und Audit zurück')
  sql("db()->exec('DROP TRIGGER fail_campaign');")
  check(dispatch(fail)['members']==3,'Nach Rollback derselbe Entwurf sauber erneut vorbereitbar')
  # Legacy partial sends must not be blindly retried.
  legacy=campaign();sql("db()->exec('INSERT INTO campaign_sends(campaign_id,member_id)SELECT %d,id FROM members WHERE business_id=1 LIMIT 1');"%legacy)
  before=snapshot();text=sql("try{campaign_dispatch(1,1,%d);}catch(InvalidArgumentException $e){echo $e->getMessage();}"%legacy)
  check('Teilversand' in text and snapshot()==before,'Historischer Teilversand bleibt unverändert und blockiert')
  outlegacy=campaign()
  sql("$q=db()->prepare('INSERT INTO mail_outbox(business_id,recipient,subject,body,category,dedup_key)VALUES(?,?,?,?,?,?)');$q->execute([1,'local@example.test','Alt','Alt','marketing','campaign:%d:999']);"%outlegacy)
  before=snapshot();text=sql("try{campaign_dispatch(1,1,%d);}catch(InvalidArgumentException $e){echo $e->getMessage();}"%outlegacy)
  check('Teilversand' in text and snapshot()==before,'Historische Outbox ohne Versandbeleg blockiert ebenfalls')
  empty=campaign();sql("db()->exec(\"INSERT INTO segments(business_id,name,rule,param)VALUES(1,'Leer','min_points',999999)\");$s=db()->lastInsertId();db()->exec('UPDATE campaigns SET segment_id='.$s.' WHERE id=%d');"%empty)
  check(dispatch(empty)['members']==0 and dispatch(empty)['already'],'Leere Zielgruppe wird einmal abgeschlossen')
  parallel=campaign();cmd=[PHP,'-r',"require 'public/index.php';echo json_encode(campaign_dispatch(1,1,%d));"%parallel]
  workers=[subprocess.Popen(cmd,cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(2)]
  responses=[]
  for w in workers:
   out,err=w.communicate(timeout=15);check(w.returncode==0,'Parallelprozess ohne DB-Fehler');responses.append(json.loads(out))
  check(sorted(x['already'] for x in responses)==[False,True] and sum(x['members'] for x in responses)==3,'Zwei gleichzeitige Aufrufe: genau einer schreibt')
  fresh=campaign();before=snapshot();text=sql("try{campaign_dispatch(999,1,%d);}catch(InvalidArgumentException $e){echo $e->getMessage();}"%fresh)
  check('nicht verfügbar' in text and snapshot()==before,'Domänenfunktion prüft Eigentümer')
  # Same owner but wrong business segment must not leak recipients.
  foreign=campaign();sql("db()->exec(\"INSERT INTO segments(business_id,name,rule,param)VALUES(2,'Fremd','all',0)\");$s=db()->lastInsertId();db()->exec('UPDATE campaigns SET segment_id='.$s.' WHERE id=%d');"%foreign)
  before=snapshot();text=sql("try{campaign_dispatch(1,1,%d);}catch(InvalidArgumentException $e){echo $e->getMessage();}"%foreign)
  check('nicht zu diesem Unternehmen' in text and snapshot()==before,'Fremdes Segment blockiert ohne Teilzustellung')
  # HTTP wrappers, no transport is ever run.
  P='/business/1';post=lambda cid,tok=True:req('/campaign/send',dict({'csrf':csrf(o,P)} if tok else {},business_id=1,campaign_id=cid),o)
  hcid=campaign();before=snapshot();check(post(hcid,False)[0]==403 and snapshot()==before,'HTTP ohne CSRF wirkungslos')
  check('noch nicht zugestellt' in post(hcid)[1],'HTTP nennt Queue statt SMTP-Zustellung')
  before=snapshot();check('Keine erneute Zustellung' in post(hcid)[1] and snapshot()==before,'HTTP Doppelklick sicher')
  check('Teilversand' in post(legacy)[1],'HTTP zeigt Abgleichwarnung für Altlasten')
  # A rollback must be visible in the browser, not claim success.
  rollback=campaign();sql("db()->exec(\"CREATE TRIGGER fail_http BEFORE INSERT ON campaign_sends WHEN NEW.campaign_id=%d BEGIN SELECT RAISE(ABORT,'INJECTED_HTTP_FAILURE'); END\");"%rollback)
  before=snapshot();check('Neue Änderungen wurden zurückgenommen' in post(rollback)[1] and snapshot()==before,'HTTP Fehler erklärt zurückgenommenen neuen Versuch')
  sql("db()->exec('DROP TRIGGER fail_http');")
  blocked=campaign();(A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  before=snapshot();check(post(blocked)[0]==402 and snapshot()==before,'Plan blockiert neue Zustellung')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  browserid=campaign();subprocess.run(['node',str(R/'tests/campaign-dispatch-ui.cjs'),str(browserid),str(legacy)],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Kampagnen-Atomaritätsprüfungen bestanden')
