#!/usr/bin/env python3
"""Eigenes Betreiberpasswort, Sitzungssperre, Reset-Entwertung, Integrationen unveraendert."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-owner-password-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public','tools']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 log=open(A/'server.log','w');s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  def mk():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  def req(path,data=None,c=None):
   if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
   try:
    with (c or mk()).open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode('utf-8','replace'),x.url
   except urllib.error.HTTPError as x:return x.code,x.read().decode('utf-8','replace'),x.url
  for _ in range(50):
   try:req('/login');break
   except OSError:time.sleep(.1)
  def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c=c)[1]).group(1)
  def login(email='demo@loyalty.local',pw='demo1234'):
   c=mk();req('/login',{'csrf':csrf(c,'/login'),'email':email,'password':pw},c);return c
  uid=int(sql("echo db()->query(\"SELECT id FROM users WHERE email='demo@loyalty.local'\")->fetchColumn();"))
  sql("$s=db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)');$s->execute(['Other','other@example.com',password_hash('other-owner-123',PASSWORD_DEFAULT)]);")
  c=login();other=login();foreign=login('other@example.com','other-owner-123');P='/account/password'
  snapshot=lambda:sql("echo json_encode(db()->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));")
  memberrows=lambda:sql("echo json_encode(db()->query('SELECT * FROM members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));")
  staffrows=lambda:sql("echo json_encode(db()->query('SELECT * FROM staff ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));")
  integrations=lambda:sql("echo json_encode(db()->query('SELECT id,api_key,shopify_secret FROM businesses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));")
  before=snapshot();mb=memberrows();sb=staffrows();ib=integrations()
  foreignbefore=sql("echo db()->query(\"SELECT password_hash FROM users WHERE email='other@example.com'\")->fetchColumn();")
  def change(old='demo1234',pw='new-owner-test-123',confirm=None,client=c,token=True):
   fields={'current_password':old,'password':pw,'password_confirm':pw if confirm is None else confirm,'user_id':2,'email':'other@example.com'}
   if token:fields['csrf']=csrf(client,P)
   return req(P,fields,client)
  check(req(P,c=mk())[2].endswith('/login'),'Ohne Login kein Passworteditor')
  change(token=False);check(snapshot()==before,'Ohne CSRF keine Änderung')
  for old,pw,confirm in [('wrong','new-owner-test-123',None),('demo1234','short',None),('demo1234','x'*73,None),('demo1234','ä'*37,None),('demo1234','new-owner-test-123','different'),('demo1234','Na'+chr(0)+'me-test-long',None)]:
   change(old,pw,confirm);check(snapshot()==before,'Ungültige Passwortänderung abgelehnt')
  sql("$q=db()->prepare('INSERT INTO password_resets(email,kind,token_hash,expires_at)VALUES(?,?,?,?)');$q->execute(['DEMO@loyalty.local','owner',hash('sha256','owner-test'),'2099-01-01']);$q->execute(['demo@loyalty.local','member',hash('sha256','member-test'),'2099-01-01']);")
  sql("ai_key_create(%d,'Unverändert','write',30);"%uid)
  keys=sql("echo json_encode(db()->query('SELECT * FROM ai_keys')->fetchAll(PDO::FETCH_ASSOC));")
  status,body,url=change()
  check(url.endswith('/login') and 'Passwort geändert' in body,'Eigene Änderung erfolgreich und aktuelle Sitzung abgemeldet')
  check(req('/dashboard',c=other)[2].endswith('/login'),'Andere eigene Websitzung widerrufen')
  check(req('/dashboard',c=foreign)[2].endswith('/dashboard'),'Fremder Betreiber bleibt angemeldet')
  check(foreignbefore==sql("echo db()->query(\"SELECT password_hash FROM users WHERE email='other@example.com'\")->fetchColumn();"),'Fremde ID/E-Mail aus POST ignoriert')
  check(memberrows()==mb and staffrows()==sb and integrations()==ib and keys==sql("echo json_encode(db()->query('SELECT * FROM ai_keys')->fetchAll(PDO::FETCH_ASSOC));"),'Mitglieder/Staff/API-/Agent-Schlüssel unverändert')
  check(sql("echo db()->query(\"SELECT used_at IS NOT NULL FROM password_resets WHERE kind='owner'\")->fetchColumn();")=='1' and sql("echo db()->query(\"SELECT used_at IS NULL FROM password_resets WHERE kind='member'\")->fetchColumn();")=='1','Nur eigene Betreiber-Resetlinks entwertet')
  check(req('/dashboard',c=login())[2].endswith('/login'),'Altes Passwort nicht mehr verwendbar')
  fresh=login(pw='new-owner-test-123');check(req('/dashboard',c=fresh)[2].endswith('/dashboard'),'Neues Passwort funktioniert')
  check(sql("echo db()->query(\"SELECT details FROM audit_logs WHERE action='account.password_change'\")->fetchColumn();")=='[]','Audit ohne Passwort-/Hashdaten')
  check('anderes neues Passwort' in change(old='new-owner-test-123',pw='new-owner-test-123',client=fresh)[1],'Identisches Passwort abgelehnt')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  # A fresh account has its own rate counter, security must remain available with blocked plan.
  check('Passwort geändert' in change(old='other-owner-123',pw='plan-independent-123',client=foreign)[1],'Passwortänderung trotz inaktivem Betreiber-Abo')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  for i in range(11):status,_,_=change(old='wrong',client=fresh)
  check(status==429,'Kontogebundenes Rate-Limit')
  subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
  subprocess.run(['node',str(R/'tests/owner-password-ui.cjs')],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Betreiber-Passwort-Prüfungen bestanden')
