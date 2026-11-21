#!/usr/bin/env python3
"""Webhook-Retries: Backoff, Dead-Status, Cron-CLI, Owner- und Admin-Wiederholung, Mandantentrennung. Lokaler Wegwerf-Server, Versand ueber Testnaht webhook_transport."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-wh-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public','tools']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("""<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['admin@test.local'];
$c['support_email']='ops@support-operator.invalid';$c['smtp']=['enabled'=>true,'host'=>'smtp.invalid','port'=>587,'from'=>'noreply@platform.invalid']+$c['smtp'];
$c['webhook_transport']=function($url,$body,$secret,$h){$m=trim((string)@file_get_contents(__DIR__.'/mode.txt'));if($m==='slow'){usleep(700000);}file_put_contents(__DIR__.'/hooks.log',json_encode([$url,$h,$secret!=='',$m])."\\n",FILE_APPEND);return ($m==='ok'||$m==='slow')?['delivered','HTTP 200']:['failed','HTTP 500'];};return $c;""")
 (A/'mode.txt').write_text('fail')
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 subprocess.run([PHP,'-r',"require 'public/index.php';db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Admin','admin@test.local',password_hash('adminpass1',PASSWORD_DEFAULT)]);"],cwd=A,check=True)
 log=open(A/'server.log','w');s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  def mk():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  def req(path,data=None,c=None):
   if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
   try:
    with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url
   except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url
  def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c=c)[1]).group(1)
  def login(e,p):
   c=mk();req('/login',{'csrf':csrf(c,'/login'),'email':e,'password':p},c);return c
  def mode(m):(A/'mode.txt').write_text(m)
  def calls():return [json.loads(l) for l in (A/'hooks.log').read_text().splitlines()] if (A/'hooks.log').exists() else []
  row=lambda i:json.loads(sql("echo json_encode(db()->query('SELECT status,attempts,next_attempt_at,response FROM webhook_deliveries WHERE id=%d')->fetch(PDO::FETCH_ASSOC));"%i))
  due=lambda i:sql("db()->prepare(\"UPDATE webhook_deliveries SET next_attempt_at='2000-01-01 00:00:00' WHERE id=?\")->execute([%d]);"%i)
  c=login('demo@loyalty.local','demo1234')
  sql("db()->prepare(\"INSERT INTO webhooks(business_id,url,events,secret) VALUES(1,'https://93.184.216.34/hook','card.points','geheim')\")->execute();")
  fire=lambda:int(sql("fire_webhook(1,'card.points',['amount'=>5]);echo db()->query('SELECT MAX(id) FROM webhook_deliveries')->fetchColumn();"))
  # 1) Fehler beim ersten Versuch
  d1=fire();r=row(d1)
  check(r['status']=='failed' and r['attempts']==1 and r['next_attempt_at'],'Erster Fehler: failed, 1 Versuch, nächster Versuch geplant')
  secs=int(sql("echo strtotime(db()->query('SELECT next_attempt_at FROM webhook_deliveries WHERE id=%d')->fetchColumn().' UTC')-time();"%d1))
  check(40<=secs<=70,'Erster Backoff etwa 1 Minute')
  check(json.loads(sql("echo json_encode(webhook_retry_due());"))==[0,0],'Noch nicht fällig: kein Versuch')
  # 2) fällig, wieder Fehler -> 5 Minuten
  due(d1);check(json.loads(sql("echo json_encode(webhook_retry_due());"))==[1,0],'Fällig: ein Versuch, weiter Fehler')
  r=row(d1);secs=int(sql("echo strtotime(db()->query('SELECT next_attempt_at FROM webhook_deliveries WHERE id=%d')->fetchColumn().' UTC')-time();"%d1))
  check(r['attempts']==2 and r['status']=='failed' and 250<=secs<=320,'Zweiter Backoff etwa 5 Minuten')
  last=calls()[-1]
  check(any(h==('X-Webhook-Delivery: %d'%d1) for h in last[1]) and 'X-Webhook-Attempt: 2' in last[1] and last[2],'Header Delivery-ID und Versuchsnummer, Secret vorhanden')
  # 3) Erfolg beim Retry
  mode('ok');due(d1);check(json.loads(sql("echo json_encode(webhook_retry_due());"))==[1,1],'Retry erfolgreich')
  r=row(d1);check(r['status']=='delivered' and r['attempts']==3 and r['next_attempt_at'] is None,'delivered, kein weiterer Termin')
  n_calls=len(calls());sql("webhook_retry_due();")
  check(len(calls())==n_calls,'Zugestellte werden nicht erneut gesendet')
  # 4) dead nach 6 Versuchen
  mode('fail');d2=fire()
  for _ in range(5):due(d2);sql("webhook_retry_due();")
  r=row(d2);check(r['status']=='dead' and r['attempts']==6 and r['next_attempt_at'] is None,'Nach 6 Versuchen: dead, kein Termin')
  n_calls=len(calls());sql("webhook_retry_due();")
  check(len(calls())==n_calls,'dead wird nicht automatisch wiederholt')
  # 5) Owner-Seite + Wiederholung
  page=req('/business/1',c=c)[1]
  check('Erneut senden' in page and 'dead' in page and '6 Versuch' in page,'Owner sieht dead mit Versuchen und Button')
  tok=lambda:csrf(c,'/business/1')
  mode('fail');req('/webhook/retry',{'csrf':tok(),'business_id':1,'delivery_id':d2},c)
  check(row(d2)['status']=='dead' and row(d2)['attempts']==7,'Manueller Versuch ohne Erfolg bleibt dead')
  mode('ok');st,body,_=req('/webhook/retry',{'csrf':tok(),'business_id':1,'delivery_id':d2},c)
  check(row(d2)['status']=='delivered' and 'Webhook zugestellt' in body,'Owner-Wiederholung stellt zu')
  n_calls=len(calls());req('/webhook/retry',{'csrf':tok(),'business_id':1,'delivery_id':d2},c)
  check(len(calls())==n_calls,'Zugestellte lassen sich nicht erneut senden')
  # 6) Mandantentrennung
  sql("db()->prepare(\"INSERT INTO webhooks(business_id,url,events) VALUES(2,'https://93.184.216.34/other','card.points')\")->execute();")
  mode('fail');d3=int(sql("fire_webhook(2,'card.points',['amount'=>1]);echo db()->query('SELECT MAX(id) FROM webhook_deliveries')->fetchColumn();"))
  mode('ok');n_calls=len(calls());req('/webhook/retry',{'csrf':tok(),'business_id':1,'delivery_id':d3},c)
  check(row(d3)['status']=='failed' and len(calls())==n_calls,'Fremde Zustellung über eigene business_id nicht wiederholbar')
  req('/webhook/retry',{'business_id':1,'delivery_id':d3},c);check(row(d3)['attempts']==1,'Ohne CSRF kein Versuch')
  # 7) deaktivierter Webhook
  sql("db()->exec('UPDATE webhooks SET active=0 WHERE business_id=2');");due(d3)
  sql("webhook_retry_due();");r=row(d3)
  check(r['status']=='dead' and 'deaktiviert' in r['response'],'Deaktivierter Webhook: dead mit Hinweis')
  # 8) Admin
  mode('fail');d4=fire()
  a=login('admin@test.local','adminpass1');page=req('/admin',c=a)[1]
  check('Webhook-Zustellungen zur Prüfung' in page and 'Wiederholen' in page and 'card.points' in page,'Admin sieht Zustellungen mit Button')
  mode('ok');st,body,_=req('/admin',{'csrf':csrf(a,'/admin'),'action':'webhook_retry','delivery_id':d4},a)
  check(row(d4)['status']=='delivered' and 'Webhook zugestellt' in body,'Admin-Wiederholung stellt zu')
  check(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='webhook.retry'\")->fetchColumn();")=='1','Admin-Wiederholung im Aktivitätsprotokoll')
  mode('fail');d5=fire();due(d5);mode('ok')
  st,body,_=req('/admin',{'csrf':csrf(a,'/admin'),'action':'webhook_run_due'},a)
  check(row(d5)['status']=='delivered' and '1 versucht, 1 zugestellt' in body,'Admin-Button führt fällige Wiederholungen aus')
  # 8b) Sperre gegen parallele Laeufe
  mode('fail');d7=fire();due(d7);mode('slow');n0=len(calls())
  ps=[subprocess.Popen([PHP,'-r',"require 'public/index.php';echo json_encode(webhook_retry_due());"],cwd=A,stdout=subprocess.PIPE,text=True) for _ in range(3)]
  outs=[p.communicate()[0] for p in ps];res=[json.loads(o.strip().splitlines()[-1]) for o in outs]
  check(sum(r[0] for r in res)==1 and len(calls())==n0+1 and row(d7)['status']=='delivered','Drei parallele Läufe: genau ein Versand')
  mode('fail');d8=fire();due(d8);mode('ok');n0=len(calls())
  sql("db()->prepare(\"UPDATE webhook_deliveries SET lock_until=? WHERE id=?\")->execute([gmdate('Y-m-d H:i:s',time()+100),%d]);"%d8)
  check(json.loads(sql("echo json_encode(webhook_retry_due());"))==[0,0] and len(calls())==n0,'Gesperrte Zustellung wird vom Worker übersprungen')
  st,body,_=req('/webhook/retry',{'csrf':tok(),'business_id':1,'delivery_id':d8},c)
  check('wird gerade gesendet' in body and len(calls())==n0,'Manueller Versuch bei Sperre: Hinweis, kein Versand')
  sql("db()->prepare(\"UPDATE webhook_deliveries SET lock_until=? WHERE id=?\")->execute(['2000-01-01 00:00:00',%d]);"%d8)
  check(json.loads(sql("echo json_encode(webhook_retry_due());"))==[1,1] and row(d8)['status']=='delivered','Abgelaufene Sperre blockiert nicht')
  import fcntl
  (A/'data').mkdir(exist_ok=True);lk=open(A/'data'/'webhook-retry.lock','w');fcntl.flock(lk,fcntl.LOCK_EX)
  r=subprocess.run([PHP,'tools/webhook-retry.php'],cwd=A,capture_output=True,text=True)
  check(r.returncode==1 and 'laeuft bereits' in r.stderr,'CLI: zweiter Worker bricht ab');fcntl.flock(lk,fcntl.LOCK_UN);lk.close()
  # 8c) Hinweis bei dead
  sql("db()->exec(\"UPDATE webhook_deliveries SET dead_ack=1 WHERE status='dead'\");")
  page=req('/admin',c=a)[1];check('endgültig fehlgeschlagen' not in page,'Kein Banner, wenn alles gesehen')
  mode('fail');d9=fire()
  for _ in range(5):due(d9);sql("webhook_retry_due();")
  check(row(d9)['status']=='dead','neue Zustellung endet dead')
  page=req('/admin',c=a)[1];check('1 Webhook-Zustellung(en) endgültig fehlgeschlagen' in page,'Admin-Banner zeigt neue dead-Zustellung')
  mails=json.loads(sql("echo json_encode(db()->query(\"SELECT recipient,subject,body FROM mail_outbox WHERE subject LIKE '%Webhook%'\")->fetchAll(PDO::FETCH_ASSOC));"))
  check(len(mails)>=1 and mails[-1]['recipient']=='ops@support-operator.invalid' and 'https://93.184' not in mails[-1]['body'] and 'geheim' not in mails[-1]['body'],'Hinweismail an Betreiber ohne URL und Secret')
  nm=len(mails);sql("webhook_notify_dead(%d);"%d9)
  check(int(sql("echo db()->query(\"SELECT COUNT(*) FROM mail_outbox WHERE subject LIKE '%%Webhook%%'\")->fetchColumn();"))==nm,'Hinweismail wird nicht doppelt angelegt')
  req('/admin',{'csrf':csrf(a,'/admin'),'action':'webhook_ack'},a)
  check('endgültig fehlgeschlagen' not in req('/admin',c=a)[1],'Als gesehen markieren blendet Banner aus')
  # 9) CLI
  mode('fail');d6=fire();due(d6);mode('ok')
  out=subprocess.check_output([PHP,'tools/webhook-retry.php'],cwd=A,text=True)
  check('Versucht: 1, zugestellt: 1' in out and row(d6)['status']=='delivered','CLI tools/webhook-retry.php')
  r=subprocess.run([PHP,'-r',"require 'public/index.php';echo json_encode(array_column(db()->query('PRAGMA table_info(webhook_deliveries)')->fetchAll(),'name'));"],cwd=A,capture_output=True,text=True)
  check('attempts' in r.stdout and 'next_attempt_at' in r.stdout,'Migration: neue Spalten vorhanden')
  # Browser
  mode('fail');fire();fire();sql("db()->exec(\"UPDATE webhook_deliveries SET status='dead',dead_ack=0 WHERE id=%d\");"%d9)
  subprocess.run(['node',str(R/'tests/webhooks-ui.cjs')],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Webhook-Prüfungen bestanden')
