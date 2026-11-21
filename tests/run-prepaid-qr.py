#!/usr/bin/env python3
"""Gekaufte Pass-QRs: Wallet, Scanner, Rechte, Datum, Version und ausdrückliche Bestätigung. Wegwerf-Server."""
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
  pid=int(sql("db()->exec(\"INSERT INTO programs(business_id,type,name,pass_visits)VALUES(1,'pass','Zehnerkarte',10)\");echo db()->lastInsertId();"))
  mid=int(sql("echo db()->query(\"SELECT id FROM members WHERE business_id=1 AND email='aylin@example.com'\")->fetchColumn();"))
  issue=int(sql("echo pass_sale(%d,%d,['kind'=>'owner','id'=>1],'QR-TEST',2000,bin2hex(random_bytes(16)));"%(pid,mid)))
  code=sql("echo db()->query('SELECT code FROM prepaid_issues WHERE id=%d')->fetchColumn();"%issue)
  foreign='ABCDEF1234567890ABCD'
  sql("db()->exec(\"INSERT INTO programs(business_id,type,name,pass_visits)VALUES(2,'pass','Fremd',10)\");$pid=db()->lastInsertId();$mid=db()->query('SELECT id FROM members WHERE business_id=2 LIMIT 1')->fetchColumn();pass_sale($pid,(int)$mid,['kind'=>'owner','id'=>1],'FREMD-QR',2000,bin2hex(random_bytes(16)));db()->exec(\"UPDATE prepaid_issues SET code='%s' WHERE program_id=$pid\");"%foreign)
  for name,role in [('Kasse','cashier'),('Lesen','viewer')]:
   sql("$q=db()->prepare('INSERT INTO staff(business_id,name,pin_hash,role)VALUES(?,?,?,?)');$q->execute([1,'%s',password_hash('123456',PASSWORD_DEFAULT),'%s']);"%(name,role))
  staff=mk();req('/staff/login',{'csrf':csrf(staff,'/staff/login'),'business_id':1,'name':'Kasse','pin':'123456'},staff)
  viewer=mk();req('/staff/login',{'csrf':csrf(viewer,'/staff/login'),'business_id':1,'name':'Lesen','pin':'123456'},viewer)
  C='/staff/prepaid/scan/'+code
  state=lambda:sql("echo json_encode([db()->query('SELECT * FROM prepaid_issues')->fetchAll(),db()->query('SELECT * FROM prepaid_ledger')->fetchAll()]);")
  wallet=req('/wallet',c=M)[1];check('data-qr="P-'+code+'"' in wallet,'Gekaufter eigener Pass als Wallet-QR')
  ben=mk();req('/member/login',{'csrf':csrf(ben,'/member/login'),'email':'ben@example.com','password':'kunde1234'},ben)
  check(code not in req('/wallet',c=ben)[1],'Keine fremden Passcodes in Wallet')
  check(code not in req('/c/'+sql("echo db()->query('SELECT code FROM members WHERE id=%d')->fetchColumn();"%mid),c=mk())[1],'Öffentliche Karte ohne persönlichen Pass-QR')
  before=state()
  for raw in ['P-'+code,'p-'+code.lower()]:
   st,h,url=req('/staff/find?'+urllib.parse.urlencode({'code':raw}),c=staff);check(st==200 and url.endswith(C) and 'Besuch bestätigen' in h,'Pass-Scan öffnet konkrete Vorschau')
  check(state()==before and 'Aylin' in h and '10 / 10 Besuche' in h,'Vorschau nennt Mitglied und Restmenge ohne Buchung')
  for raw in [code,'P-'+code+'EXTRA','P-'+code[:19],'prefix P-'+code,'https://evil.example/P-'+code,'P-<script>']:
   check('/prepaid/scan/' not in req('/staff/find?'+urllib.parse.urlencode({'code':raw}),c=staff)[2],'Ungültiger oder externer Pass-Scan abgewiesen')
  check(req('/staff/find?code=P-'+foreign,c=staff)[0]==404 and req('/staff/prepaid/scan/'+foreign,c=staff)[0]==404,'Fremder Pass an Scanner und direkter Route gesperrt')
  check(req(C,c=viewer)[0]==403 and req('/staff/find?code=P-'+code,c=viewer)[0]==403,'Viewer ohne Einlöserecht gesperrt')
  check('/staff/login' in req(C,c=mk())[2] and '/staff/login' in req(C,c=M)[2],'Route benötigt Staff-Sitzung')
  check(req(C,{},staff)[0]==403 and state()==before,'POST ohne CSRF bucht nichts')
  data={'csrf':csrf(staff,C),'version':0,'operation':uuid.uuid4().hex,'quantity':1}
  check(req('/staff/prepaid/%d/use'%issue,dict(data,csrf='wrong'),staff)[0]==403 and state()==before,'Besuchsbestätigung benötigt CSRF')
  sql("db()->exec(\"UPDATE prepaid_issues SET expires_at='2020-01-01 00:00:00' WHERE id=%d\");"%issue)
  check('Besuch bestätigen' not in req(C,c=staff)[1] and code not in req('/wallet',c=M)[1],'Abgelaufener Pass ohne QR oder Buchungsformular')
  before=state();check('abgelaufen' in req('/staff/prepaid/%d/use'%issue,data,staff)[1] and state()==before,'Ablauf nach Scan serverseitig wirksam')
  sql("db()->exec('UPDATE prepaid_issues SET expires_at=NULL,remaining=0 WHERE id=%d');"%issue)
  check('Besuch bestätigen' not in req(C,c=staff)[1] and code not in req('/wallet',c=M)[1],'Aufgebrauchter Pass ohne QR oder Formular')
  sql("db()->exec('UPDATE prepaid_issues SET remaining=10 WHERE id=%d');"%issue)
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  before=state();check(req(C,c=staff)[0]==200 and req('/staff/prepaid/%d/use'%issue,data,staff)[0]==402 and state()==before,'Plan erlaubt Vorschau, blockiert Buchung')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  sql("db()->exec(\"UPDATE prepaid_issues SET kind='unlimited',remaining=NULL,expires_at='2099-01-01 00:00:00' WHERE id=%d\");"%issue)
  check('data-qr="P-'+code+'"' in req('/wallet',c=M)[1] and 'max="1"' in req(C,c=staff)[1],'Gültiger unbegrenzter Zeitpass als QR, jeweils ein Besuch')
  before=state();check('jeweils einen Besuch' in req('/staff/prepaid/%d/use'%issue,dict(data,quantity=2),staff)[1] and state()==before,'Unbegrenzter Pass lehnt Mengen größer eins ab')
  sql("db()->exec(\"UPDATE prepaid_issues SET kind='visits',remaining=10,expires_at=NULL WHERE id=%d\");"%issue)
  sql("db()->exec(\"UPDATE staff SET role='viewer' WHERE name='Kasse'\");")
  check(req(C,c=staff)[0]==403,'Entzogenes Einlöserecht wirkt beim erneuten Scan')
  sql("db()->exec(\"UPDATE staff SET role='cashier' WHERE name='Kasse'\");")
  subprocess.run(['node',str(R/'tests/prepaid-qr-ui.cjs'),code,str(issue)],cwd=R,check=True)
  check(sql("echo db()->query('SELECT remaining FROM prepaid_issues WHERE id=%d')->fetchColumn();"%issue)=='9','Nur eine ausdrückliche Browserbestätigung verbraucht Besuch')
  data['csrf']=csrf(staff,C);before=state();check('inzwischen geändert' in req('/staff/prepaid/%d/use'%issue,data,staff)[1] and state()==before,'Veraltete Scan-Version bucht nicht erneut')
 finally:s.terminate();s.wait();log.close()
 print(n,'Prepaid-QR-Prüfungen bestanden')
