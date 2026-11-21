#!/usr/bin/env python3
"""Mitglieder-Punkteanfragen: Opt-in, Links, Uebertragung, Replay, Grenzen, Browser. Lokaler Wegwerf-Server."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-xfer-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 def val(q):return sql("echo db()->query(%s)->fetchColumn();"%json.dumps(q))
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
  def member(email,pw):
   c=mk();req('/member/login',{'csrf':csrf(c,'/member/login'),'email':email,'password':pw},c);return c
  # Daten: Programm, zwei Mitglieder mit Karten
  pid=int(sql("db()->exec(\"INSERT INTO programs(business_id,type,name) VALUES(1,'points','Transfer-Test')\");echo db()->lastInsertId();"))
  sql("db()->prepare('INSERT INTO members(business_id,name,email,code) VALUES(1,?,?,?)')->execute(['Cora Test','cora@example.com',member_code()]);")
  ids=json.loads(sql("echo json_encode(db()->query(\"SELECT id FROM members WHERE business_id=1 AND email<>'' ORDER BY (email='aylin@example.com') DESC,id LIMIT 3\")->fetchAll(PDO::FETCH_COLUMN));"))
  a,b,c3=ids
  sql("$h=password_hash('pw-test-123',PASSWORD_DEFAULT);foreach([%d,%d,%d] as $i)db()->prepare('UPDATE members SET password_hash=? WHERE id=?')->execute([$h,$i]);"%(a,b,c3))
  sql("$h=password_hash('pw-test-123',PASSWORD_DEFAULT);db()->prepare('UPDATE members SET password_hash=? WHERE lower(email) IN (SELECT lower(email) FROM members WHERE id IN (%d,%d,%d))')->execute([$h]);"%(a,b,c3))
  emails={i:val("SELECT email FROM members WHERE id=%d"%i) for i in ids}
  card={}
  for i,pts in [(a,10),(b,100),(c3,0)]:
   card[i]=int(sql("db()->prepare('INSERT INTO cards(program_id,member_id,points,points_lifetime) VALUES(?,?,?,?)')->execute([%d,%d,%d,%d]);echo db()->lastInsertId();"%(pid,i,pts,pts)))
  pts=lambda i:int(val("SELECT points FROM cards WHERE id=%d"%card[i]));life=lambda i:int(val("SELECT points_lifetime FROM cards WHERE id=%d"%card[i]))
  A_=member(emails[a],'pw-test-123');B_=member(emails[b],'pw-test-123')
  check('Deine Gutscheine' in req('/wallet',c=A_)[1] or 'MEIN WALLET' in req('/wallet',c=A_)[1].upper(),'Mitglied A angemeldet')
  check('Punkte anfordern' in req('/wallet',c=A_)[1],'Wallet verlinkt Punkte anfordern')
  # Aus: keine Links
  req('/wallet/request',{'csrf':csrf(A_,'/member/preferences'),'action':'create','program_id':pid},A_)
  check(int(val("SELECT COUNT(*) FROM point_request_links"))==0,'Programm ohne Opt-in: kein Link')
  # Opt-in durch Betreiber
  o=mk();req('/login',{'csrf':csrf(o,'/login'),'email':'demo@loyalty.local','password':'demo1234'},o)
  check('Punkteanfragen zwischen Mitgliedern' in req('/program/%d'%pid,c=o)[1],'Einstellung im Programm sichtbar')
  x=mk();req('/program/transfer',{'program_id':pid,'enabled':1},x);check(val("SELECT points_transfer FROM programs WHERE id=%d"%pid)=='0','ohne Login keine Einstellung')
  req('/program/transfer',{'csrf':csrf(o,'/program/%d'%pid),'program_id':pid,'enabled':1},o);check(val("SELECT points_transfer FROM programs WHERE id=%d"%pid)=='1','Betreiber aktiviert Programm')
  # Link
  req('/wallet/request',{'csrf':csrf(A_,'/wallet/request'),'action':'create','program_id':pid},A_)
  tok=val("SELECT token FROM point_request_links");check(re.fullmatch(r'[a-f0-9]{32}',tok) is not None,'Link mit zufälligem Token erstellt')
  check('/send/'+tok in req('/wallet/request',c=A_)[1],'Link in der Liste')
  for _ in range(6):req('/wallet/request',{'csrf':csrf(A_,'/wallet/request'),'action':'create','program_id':pid},A_)
  check(int(val("SELECT COUNT(*) FROM point_request_links"))==5,'höchstens 5 Links')
  # Senden
  L='/send/'+tok
  anon=mk();check(req(L,c=anon)[2].endswith('/member/login'),'ohne Anmeldung: Weiterleitung zum Login')
  check('Das ist dein eigener Link' in req(L,c=A_)[1],'eigener Link: kein Sendeformular')
  page=req(L,c=B_)[1];check('Dein Guthaben' in page and '100 Punkte' in page,'Sendeformular zeigt Guthaben')
  op=re.search(r'name="op" value="([a-f0-9]+)"',page).group(1)
  def send(c,amount,op_,confirm=True):
   d={'csrf':csrf(c,L),'op':op_,'amount':amount}
   if confirm:d['confirm']=1
   return req(L,d,c)
  send(B_,30,op,False);check(pts(b)==100,'ohne Bestätigung nichts übertragen')
  for bad in [0,-5,101,10001]:send(B_,bad,op)
  check(pts(b)==100 and pts(a)==10,'ungültige Beträge abgelehnt (0, negativ, über Guthaben, über Limit)')
  send(B_,30,op);check(pts(b)==70 and pts(a)==40,'30 Punkte übertragen (70 / 40)')
  check(life(b)==100 and life(a)==10,'Lifetime-Punkte bleiben unverändert')
  send(B_,30,op);check(pts(b)==70 and pts(a)==40,'Replay mit gleicher Operation überträgt nicht doppelt')
  check(int(val("SELECT COUNT(*) FROM point_transfers"))==1 and val("SELECT uses FROM point_request_links WHERE token='%s'"%tok)=='1','genau eine Übertragung protokolliert')
  check(val("SELECT amount FROM transactions WHERE card_id=%d AND kind='points_transfer_out'"%card[b])=='-30' and val("SELECT amount FROM transactions WHERE card_id=%d AND kind='points_transfer_in'"%card[a])=='30','Verlauf bei beiden Karten gebucht')
  check('Punkte von einem Mitglied erhalten' in val("SELECT text FROM member_messages WHERE member_id=%d AND kind='transfer'"%a),'Empfänger bekommt Wallet-Nachricht')
  op2=re.search(r'name="op" value="([a-f0-9]+)"',req(L,c=B_)[1]).group(1)
  send(B_,70,op2);check(pts(b)==0 and pts(a)==110,'gesamtes Guthaben übertragbar')
  op3=re.search(r'name="op" value="([a-f0-9]+)"',req(L,c=B_)[1]).group(1) if 'name="op"' in req(L,c=B_)[1] else 'a'*32
  send(B_,1,op3);check(pts(b)==0 and pts(a)==110,'leeres Guthaben: nichts mehr übertragbar')
  # Kein Undo
  tid=int(val("SELECT id FROM transactions WHERE card_id=%d AND kind='points_transfer_in' LIMIT 1"%card[a]))
  check(sql("$c=db()->query('SELECT * FROM cards WHERE id=%d')->fetch();echo var_export(undo_transaction($c,%d),true);"%(card[a],tid))=='false' and pts(a)==110,'Übertragung ist nicht stornierbar')
  # Kein Konto in anderem Programm / ohne Karte
  C_=member(emails[c3],'pw-test-123');sql("db()->exec('DELETE FROM cards WHERE id=%d');"%card[c3])
  check('keine Karte' in req(L,c=C_)[1],'ohne Karte im Programm kein Sendeformular')
  # Link löschen: fremdes Mitglied darf nicht
  lid=val("SELECT id FROM point_request_links WHERE token='%s'"%tok)
  req('/wallet/request',{'csrf':csrf(B_,'/wallet/request'),'action':'delete','link_id':lid},B_)
  check(val("SELECT COUNT(*) FROM point_request_links WHERE id=%s"%lid)=='1','fremdes Mitglied kann Link nicht löschen')
  req('/wallet/request',{'csrf':csrf(A_,'/wallet/request'),'action':'delete','link_id':lid},A_)
  check(val("SELECT COUNT(*) FROM point_request_links WHERE id=%s"%lid)=='0','Besitzer löscht Link')
  check(req(L,c=B_)[0]==404,'gelöschter Link: 404')
  # Opt-out macht Links unwirksam
  tok2=val("SELECT token FROM point_request_links LIMIT 1")
  req('/program/transfer',{'csrf':csrf(o,'/program/%d'%pid),'program_id':pid},o)
  check(req('/send/'+tok2,c=B_)[0]==404,'Programm ohne Opt-in: Links liefern 404')
  check(req('/send/zzzz',c=B_)[0]==404,'ungültiger Token: 404')
  # Browser
  req('/program/transfer',{'csrf':csrf(o,'/program/%d'%pid),'program_id':pid,'enabled':1},o)
  sql("db()->exec('UPDATE cards SET points=80 WHERE id=%d');"%card[b]);sql("db()->prepare('INSERT INTO cards(program_id,member_id,points,points_lifetime) VALUES(?,?,0,0)')->execute([%d,%d]);"%(pid,c3))
  subprocess.run(['node',str(R/'tests/transfers-ui.cjs'),emails[a],emails[b]],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Transfer-Prüfungen bestanden')
