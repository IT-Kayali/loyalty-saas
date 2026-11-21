#!/usr/bin/env python3
"""Persönliche Gutschein-QRs: Wallet, Scanner, Rechte, Mandanten, keine GET-Buchung, Bestätigung und Browser. Wegwerf-Server."""
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
  pid=int(sql("db()->exec(\"INSERT INTO programs(business_id,type,name)VALUES(1,'voucher','Sommergutschein')\");echo db()->lastInsertId();"))
  mid=int(sql("echo db()->query(\"SELECT id FROM members WHERE business_id=1 AND email='aylin@example.com'\")->fetchColumn();"))
  other=int(sql("echo db()->query(\"SELECT id FROM members WHERE business_id=1 AND email='ben@example.com'\")->fetchColumn();"))
  code='0123456789ABCDEF12345678';foreign='ABCDEF1234567890ABCDEF12'
  sql("$r=voucher_rules([]);$r['per_member']=10;$q=db()->prepare('INSERT INTO voucher_codes(program_id,batch,code,member_id,rules_snapshot)VALUES(?,?,?,?,?)');$q->execute([%d,'QR','%s',%d,json_encode($r)]);"%(pid,code,mid))
  sql("$r=voucher_rules([]);db()->exec(\"INSERT INTO programs(business_id,type,name)VALUES(2,'voucher','Fremder Gutschein')\");$id=db()->lastInsertId();$q=db()->prepare('INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot)VALUES(?,?,?,?)');$q->execute([$id,'Fremd','%s',json_encode($r)]);"%foreign)
  for name,role in [('Kasse','cashier'),('Lesen','viewer')]:
   sql("$q=db()->prepare('INSERT INTO staff(business_id,name,pin_hash,role)VALUES(?,?,?,?)');$q->execute([1,'%s',password_hash('123456',PASSWORD_DEFAULT),'%s']);"%(name,role))
  staff=mk();req('/staff/login',{'csrf':csrf(staff,'/staff/login'),'business_id':1,'name':'Kasse','pin':'123456'},staff)
  viewer=mk();req('/staff/login',{'csrf':csrf(viewer,'/staff/login'),'business_id':1,'name':'Lesen','pin':'123456'},viewer)
  C='/staff/business/1/voucher-counter'
  used=lambda:sql("echo db()->query(\"SELECT used FROM voucher_codes WHERE code='%s'\")->fetchColumn();"%code)
  wallet=req('/wallet',c=M)[1]
  check('data-qr="V-'+code+'"' in wallet,'Persönlicher QR in eigener Wallet')
  ben=mk();req('/member/login',{'csrf':csrf(ben,'/member/login'),'email':'ben@example.com','password':'kunde1234'},ben)
  check(code not in req('/wallet',c=ben)[1],'Fremdes Mitglied sieht Code nicht')
  check(code not in req('/c/'+sql("echo db()->query('SELECT code FROM members WHERE id=%d')->fetchColumn();"%mid),c=mk())[1],'Öffentliche Kundenkarte enthält keinen persönlichen Gutscheincode')
  for raw in ['V-'+code,'v-'+code.lower()]:
   st,html,url=req('/staff/find?'+urllib.parse.urlencode({'code':raw}),c=staff)
   check(st==200 and '/voucher-counter?code='+code in url and 'value="'+code+'"' in html,'Scan füllt eigenen Code vor')
  check(used()=='0','Scan bucht nichts')
  check('Mitglied wählen' in html and '<select name="member_id" required>' in html,'Mitglied muss ausdrücklich gewählt werden')
  for raw in [code,'V-'+code+'EXTRA','https://evil.example/V-'+code,'https://evil.example/?code=V-'+code,'prefix V-'+code,'V-'+code[:23],'V-<script>']:
   check('/voucher-counter' not in req('/staff/find?'+urllib.parse.urlencode({'code':raw}),c=staff)[2],'Ungültige oder externe Scan-Eingabe abgewiesen')
  check(req('/staff/find?code=V-'+foreign,c=staff)[0]==404,'Fremder Unternehmen-Code liefert 404 ohne Offenlegung')
  check(req('/staff/find?code=V-'+code,c=viewer)[0]==403,'Viewer darf keinen Gutschein-Scan übernehmen')
  check(req(C+'?code='+foreign,c=staff)[0]==404,'Direkter fremder Vorbelegungslink gesperrt')
  check(req(C+'?code=%22%3E%3Cscript%3E',c=staff)[0]==404,'Unsichere Vorbelegung gesperrt')
  check('/staff/login' in req('/staff/find?code=V-'+code,c=mk())[2],'Scan benötigt Staff-Login')
  data={'csrf':csrf(staff,C),'operation':uuid.uuid4().hex,'action':'preview','code':code,'member_id':mid,'spend':'20'}
  check('Verbindlich einlösen' in req(C,data,staff)[1] and used()=='0','Vorschau benötigt zweite Bestätigung und bucht nichts')
  bad=dict(data,member_id=other,action='redeem',operation=uuid.uuid4().hex)
  check('anderen Mitglied' in req(C,bad,staff)[1] and used()=='0','Persönliche Mitgliedsbindung bei Einlösung geprüft')
  no=dict(data,action='redeem');no.pop('csrf')
  check(req(C,no,staff)[0]==403 and used()=='0','Einlösung ohne CSRF wirkungslos')
  sql("db()->exec(\"UPDATE voucher_codes SET status='cancelled' WHERE code='%s'\");"%code)
  check(code not in req('/wallet',c=M)[1],'Stornierter QR aus Wallet entfernt')
  check('storniert' in req(C,dict(data,action='redeem'),staff)[1] and used()=='0','Storno nach Scan blockiert Bestätigung')
  sql("db()->exec(\"UPDATE voucher_codes SET status='active' WHERE code='%s'\");"%code)
  subprocess.run(['node',str(R/'tests/voucher-qr-ui.cjs'),code,str(mid)],cwd=R,check=True)
  check(used()=='1','Browser löst nur einmal ein, zweiter Viewport nur Vorschau')
 finally:s.terminate();s.wait();log.close()
 print(n,'Voucher-QR-Prüfungen bestanden')
