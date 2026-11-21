#!/usr/bin/env python3
"""Eigenes Shared-Wallet-Passwort: Auth, CSRF, Validierung, Sitzungssperre, Plan-Ausnahme und Browser."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-member-password-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public','tools']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';return $c;")
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
   try:req('/member/login');break
   except OSError:time.sleep(.1)
  def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c=c)[1]).group(1)
  def member(email='aylin@example.com',pw='kunde1234'):
   c=mk();req('/member/login',{'csrf':csrf(c,'/member/login'),'email':email,'password':pw},c);return c
  c=member();other=member();ben=member('ben@example.com')
  # Shared Wallet: second business record with distinct pre-existing password and higher version.
  sql("$s=db()->prepare('INSERT INTO members(business_id,name,email,code,password_hash,session_version)VALUES(2,?,?,?,?,7)');$s->execute(['Aylin zweite Karte','AYLIN@example.com','ABABABABAB',password_hash('other-shared-pass',PASSWORD_DEFAULT)]);")
  c=member();other=member()
  hashes=lambda:sql("echo json_encode(db()->query(\"SELECT id,password_hash,session_version FROM members ORDER BY id\")->fetchAll(PDO::FETCH_ASSOC));")
  benhash=sql("echo db()->query(\"SELECT password_hash FROM members WHERE email='ben@example.com'\")->fetchColumn();")
  before=hashes();P='/member/password'
  def change(old='kunde1234',pw='new-member-test-123',confirm=None,client=c,token=True):
   f={'current_password':old,'password':pw,'password_confirm':pw if confirm is None else confirm,'email':'ben@example.com','member_id':'2'}
   if token:f['csrf']=csrf(client,P)
   return req(P,f,client)
  check(req(P,c=mk())[2].endswith('/member/login'),'Ohne Mitgliedslogin nicht erreichbar')
  change(token=False);check(hashes()==before,'Ohne CSRF keine Änderung')
  change(old='wrong');check(hashes()==before,'Falsches bisheriges Passwort wirkungslos')
  change(pw='short');check(hashes()==before,'Zu kurzes neues Passwort abgelehnt')
  change(pw='x'*73);check(hashes()==before,'Über 72 Byte abgelehnt, kein stilles bcrypt-Abschneiden')
  change(confirm='does-not-match');check(hashes()==before,'Unterschiedliche Wiederholung abgelehnt')
  change(pw='kunde1234');check(hashes()==before,'Bisheriges zu kurzes Passwort wird nicht erneut akzeptiert')
  change(pw='ä'*37);check(hashes()==before,'Mehrbyte-Passwort über 72 Byte abgelehnt')
  change(pw='nul-byte-test'+chr(0));check(hashes()==before,'NUL-Byte im neuen Passwort abgelehnt')
  # Pending resets must not survive a password change.
  sql("db()->prepare('INSERT INTO password_resets(email,kind,token_hash,expires_at)VALUES(?,?,?,?)')->execute(['AYLIN@example.com','member',hash('sha256',str_repeat('a',64)),'2099-01-01 00:00:00']);")
  status,body,url=change()
  check('Passwort geändert' in body and url.endswith('/member/login'),'Erfolgreich geändert und aktuelle Sitzung abgemeldet')
  check(req('/wallet',c=other)[2].endswith('/member/login'),'Andere Sitzung widerrufen')
  check(benhash==sql("echo db()->query(\"SELECT password_hash FROM members WHERE email='ben@example.com'\")->fetchColumn();"),'Übergebene fremde E-Mail/ID wird ignoriert')
  check(req('/wallet',c=ben)[2].endswith('/wallet'),'Anderes Mitglied bleibt angemeldet')
  check(sql("echo db()->query(\"SELECT COUNT(DISTINCT password_hash)||':'||MIN(session_version)||':'||MAX(session_version) FROM members WHERE lower(email)='aylin@example.com'\")->fetchColumn();")=='1:8:8','Alle eigenen Shared-Wallet-Zeilen vereinheitlicht, Versionsmaximum erhöht')
  check(sql("echo db()->query(\"SELECT used_at IS NOT NULL FROM password_resets WHERE lower(email)='aylin@example.com'\")->fetchColumn();")=='1','Offene Reset-Links entwertet')
  check(req('/wallet',c=member())[2].endswith('/member/login'),'Altes Passwort nicht mehr verwendbar')
  fresh=member(pw='new-member-test-123');check(req('/wallet',c=fresh)[2].endswith('/wallet'),'Neues Passwort meldet wieder an')
  check(req('/wallet',c=member(pw='other-shared-pass'))[2].endswith('/member/login'),'Altes anderes Shared-Wallet-Passwort ebenfalls ungültig')
  # Account security must not be a paid tenant feature.
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  check('Passwort geändert' in change(old='new-member-test-123',pw='paid-plan-not-needed',client=fresh)[1],'Passwortänderung trotz inaktivem Betreiber-Abo möglich')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  for i in range(11):
   status,_,_=change(old='wrong',client=ben)
  check(status==429,'Nach zehn Versuchen greift kontogebundenes Rate-Limit')
  # Reset seed for repeatable browser tests.
  subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
  subprocess.run(['node',str(R/'tests/member-password-ui.cjs')],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Mitglieder-Passwort-Prüfungen bestanden')
