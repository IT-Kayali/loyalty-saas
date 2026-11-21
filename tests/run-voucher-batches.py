#!/usr/bin/env python3
"""Gutschein-Batches: Stornieren, Wiederherstellen, Eindeutigkeit des Batchnamens, Einlösung gesperrt, Rechte, Audit, Browser. Lokaler Wegwerf-Server."""
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
  pid=int(sql("$s=db()->prepare(\"INSERT INTO programs(business_id,type,name) VALUES(1,'voucher','Batch-Test')\");$s->execute();echo db()->lastInsertId();"))
  mid=int(sql("echo db()->query(\"SELECT id FROM members WHERE business_id=1 AND email='aylin@example.com'\")->fetchColumn();"))
  P='/program/%d'%pid;V=P+'/vouchers'
  post=lambda f,c=o,tok=True:req(V,dict({'csrf':csrf(o,P)} if tok else {},**f),c)
  post({'action':'rules','type':'fixed','value':'5','currency':'EUR','min_spend':'0','max_discount':'0','max_total':'0','per_member':'20','min_lifetime':'0','item':'','starts':'','ends':''})
  post({'action':'batch','name':'Sommer','count':'4'});post({'action':'batch','name':'Winter','count':'3'})
  st=lambda b:json.loads(sql("$s=db()->prepare('SELECT code,status,used FROM voucher_codes WHERE program_id=? AND batch=? ORDER BY id');$s->execute([%d,'%s']);echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));"%(pid,b)))
  check(len(st('Sommer'))==4 and len(st('Winter'))==3,'Zwei Batches erzeugt')
  post({'action':'batch','name':'Sommer','count':'2'});check(len(st('Sommer'))==4,'Doppelter Batchname wird abgelehnt')
  pg=req(P,c=o)[1];check('batch_cancel' in pg and 'Storniert' in pg and 'Offene Codes' in pg,'Batch-Tabelle mit Aktiv/Storniert und Stornieren-Button')
  def redeem(code,spend='20'):
   op=uuid.uuid4().hex
   r=req('/business/1/voucher-counter',{'csrf':csrf(o,'/business/1/voucher-counter'),'operation':op,'action':'redeem','code':code,'member_id':mid,'spend':spend},o);return r[1]
  c0=st('Sommer')[0]['code'];c1=st('Sommer')[1]['code']
  check('eingelöst' in redeem(c0) and st('Sommer')[0]['used']==1,'Vor dem Storno: Code einlösbar')
  # Storno
  post({'action':'batch_cancel','batch':'Sommer'});s2=st('Sommer')
  check([x['status'] for x in s2]==['active','cancelled','cancelled','cancelled'],'Storno betrifft nur offene Codes (eingelöster bleibt)')
  check(st('Winter')==[{'code':x['code'],'status':'active','used':0} for x in st('Winter')],'Anderer Batch unberührt')
  r=redeem(c1);check('storniert' in r and st('Sommer')[1]['used']==0,'Stornierter Code nicht einlösbar, Meldung "storniert"')
  op=uuid.uuid4().hex;pv=req('/business/1/voucher-counter',{'csrf':csrf(o,'/business/1/voucher-counter'),'operation':op,'action':'preview','code':c1,'member_id':mid,'spend':'20'},o)[1]
  check('storniert' in pv,'Vorschau an der Kasse meldet storniert')
  sql("$s=db()->prepare('UPDATE voucher_codes SET member_id=? WHERE code=?');$s->execute([%d,'%s']);"%(mid,c1))
  check(c1 not in req('/wallet',c=M)[1],'Stornierter persönlicher Code verschwindet aus der Wallet')
  post({'action':'batch_cancel','batch':'Sommer'});check(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='voucher.batch_cancel'\")->fetchColumn();")=='1','Zweites Storno ohne offene Codes: nichts, kein Audit')
  # Wiederherstellen
  post({'action':'batch_restore','batch':'Sommer'});check([x['status'] for x in st('Sommer')]==['active']*4 and st('Sommer')[0]['used']==1,'Wiederherstellen aktiviert Codes, Nutzungszähler bleibt')
  check('eingelöst' in redeem(c1) and st('Sommer')[1]['used']==1,'Wiederhergestellter Code einlösbar')
  a=json.loads(sql("echo json_encode(db()->query(\"SELECT action,details FROM audit_logs WHERE action LIKE 'voucher.batch_%' ORDER BY id\")->fetchAll(PDO::FETCH_ASSOC));"))
  check([x['action'] for x in a]==['voucher.batch_cancel','voucher.batch_restore'] and json.loads(a[0]['details'])['codes']==3,'Audit-Einträge mit Anzahl')
  # Rechte
  before=json.dumps(st('Winter'))
  post({'action':'batch_cancel','batch':'Winter'},tok=False);check(json.dumps(st('Winter'))==before,'Storno ohne CSRF wirkungslos')
  req(V,{'action':'batch_cancel','batch':'Winter','csrf':'x'},M);check(json.dumps(st('Winter'))==before,'Mitglied kann nicht stornieren')
  post({'action':'batch_cancel','batch':'<b>Nope</b>'});check(json.dumps(st('Winter'))==before,'Unbekannter Batchname: nichts passiert')
  post({'action':'batch_cancel','batch':'Winter'})
  snapshot=json.dumps(st('Winter'))
  post({'action':'batch_restore','batch':'Winter'},tok=False);check(json.dumps(st('Winter'))==snapshot,'Wiederherstellen ohne CSRF wirkungslos')
  post({'action':'batch_restore','batch':'Winter'})
  sql("$s=db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)');$s->execute(['Fremd','foreign@example.com',password_hash('test123456',PASSWORD_DEFAULT)]);")
  outsider=mk();req('/login',{'csrf':csrf(outsider,'/login'),'email':'foreign@example.com','password':'test123456'},outsider)
  status,_,_=req(V,{'csrf':csrf(outsider,'/dashboard'),'action':'batch_cancel','batch':'Winter'},outsider)
  check(status==404 and json.dumps(st('Winter'))==before,'Fremder Owner darf fremdes Programm nicht stornieren')
  # Historische gleichnamige Batches werden als eine Gruppe behandelt.
  sql("$s=db()->prepare('INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot) SELECT program_id,batch,?,rules_snapshot FROM voucher_codes WHERE program_id=? AND batch=? LIMIT 1');$s->execute(['LEGACY0001',%d,'Winter']);"%pid)
  post({'action':'batch_cancel','batch':'Winter'});check(len(st('Winter'))==4 and all(x['status']=='cancelled' for x in st('Winter')),'Historische Namensduplikate werden gemeinsam storniert')
  post({'action':'batch_restore','batch':'Winter'})
  # Fremder Batch-Name in anderem Programm
  pid2=int(sql("$s=db()->prepare(\"INSERT INTO programs(business_id,type,name) VALUES(1,'voucher','Zweit')\");$s->execute();echo db()->lastInsertId();"))
  sql("db()->exec(\"INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot) VALUES(%d,'Winter','ZZZZ0001','{}')\");"%pid2)
  post({'action':'batch_cancel','batch':'Winter'});check(sql("echo db()->query(\"SELECT status FROM voucher_codes WHERE code='ZZZZ0001'\")->fetchColumn();")=='active','Storno wirkt nur im eigenen Programm')
  # Teilweise genutzte Mehrfachcodes werden ebenfalls gesperrt, Zähler bleibt.
  sql("$s=db()->prepare('UPDATE voucher_codes SET used=1,max_uses=3 WHERE program_id=? AND batch=?');$s->execute([%d,'Winter']);"%pid)
  post({'action':'batch_restore','batch':'Winter'})
  post({'action':'batch_cancel','batch':'Winter'})
  check(all(x['used']==1 and x['status']=='cancelled' for x in st('Winter')),'Teilweise genutzte Mehrfachcodes gesperrt, Zähler bleibt')
  # Wiederherstellung umgeht keine abgelaufene Gültigkeit.
  post({'action':'batch','name':'Abgelaufen','count':'1'})
  expired=st('Abgelaufen')[0]['code']
  sql("$s=db()->prepare('UPDATE voucher_codes SET rules_snapshot=? WHERE program_id=? AND batch=?');$r=voucher_rules([]);$r['ends']='2000-01-01';$s->execute([json_encode($r),%d,'Abgelaufen']);"%pid)
  post({'action':'batch_cancel','batch':'Abgelaufen'});post({'action':'batch_restore','batch':'Abgelaufen'})
  check('Gültigkeit' in redeem(expired) and st('Abgelaufen')[0]['used']==0,'Wiederherstellen verlängert keine Gültigkeit')
  # CSV zeigt Status
  csv=req(P+'/voucher-export',c=o)[1];check('cancelled' in csv and 'Sommer' in csv,'CSV-Export enthält den Status cancelled')
  post({'action':'batch_restore','batch':'Winter'})
  subprocess.run(['node',str(R/'tests/voucher-batches-ui.cjs'),str(pid)],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Voucher-Batch-Prüfungen bestanden')
