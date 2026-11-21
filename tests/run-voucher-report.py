#!/usr/bin/env python3
"""Code-Gutschein-Auswertung: Summen, Währungen, Daten-/Zeitraumgrenzen, Rechte, Desktop/Mobil. Wegwerf-Server."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json,uuid,csv,io
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
  pid=int(sql("db()->exec(\"INSERT INTO programs(business_id,type,name)VALUES(1,'voucher','Gutschein-Auswertung')\");echo db()->lastInsertId();"))
  mid=int(sql("echo db()->query(\"SELECT id FROM members WHERE business_id=1 AND email='aylin@example.com'\")->fetchColumn();"))
  mid2=int(sql("echo db()->query(\"SELECT id FROM members WHERE business_id=1 AND email='ben@example.com'\")->fetchColumn();"))
  sql("$q=db()->prepare('INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot)VALUES(?,?,?,?)');$q->execute([%d,'Report','0123456789ABCDEF12345678','{}']);"%pid)
  cid=int(sql("echo db()->query(\"SELECT id FROM voucher_codes WHERE code='0123456789ABCDEF12345678'\")->fetchColumn();"))
  def add(day,typ,currency,spend,discount,member=mid,rev=None,invalid=False):
   snap='broken' if invalid else json.dumps({'rules':{'type':typ,'currency':currency,'value':75}})
   vals=[1,pid,cid,member,uuid.uuid4().hex,spend,discount,'owner',1,snap,rev,day]
   sql("$q=db()->prepare('INSERT INTO voucher_redemptions(business_id,program_id,code_id,member_id,operation,spend_cents,discount_cents,actor_kind,actor_id,snapshot,reversed_at,created_at)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute(json_decode(%s,true));"%json.dumps(json.dumps(vals)))
  add('2026-01-01 00:00:00','fixed','EUR',2000,500)
  add('2026-01-01 12:00:00','fixed','EUR',3000,700,mid2)
  add('2026-01-02 23:59:59','percent','USD',5000,1000)
  add('2026-01-02 18:00:00','product','EUR',1500,0)
  add('2026-01-02 18:00:01','points','EUR',1000,0)
  add('2026-01-02 18:00:02','fixed','EUR',9000,9000,rev='2026-01-03 00:00:00')
  add('2026-01-02 18:00:03','fixed','EUR',1000,500,invalid=True)
  add('2025-12-31 23:59:59','fixed','EUR',99999,99999)
  add('2026-01-03 00:00:00','fixed','EUR',99999,99999)
  def stats(f='2026-01-01',t='2026-01-02'):
   return json.loads(sql("echo json_encode(voucher_report_data(%d,'%s','%s'));"%(pid,f,t)))
  d=stats()
  check(d['count']==6 and d['members']==2 and d['reversed']==1,'Anzahl, eindeutige Mitglieder und ausgeschlossene Stornos')
  check(d['groups']['EUR:fixed']['discount']==1200 and d['groups']['EUR:fixed']['spend']==5000,'EUR-Summen ohne fremde Tage/Stornos')
  check(d['groups']['USD:percent']['discount']==1000 and len(d['groups'])==4,'USD/Typen getrennt ohne Währungsumrechnung')
  check(d['groups']['EUR:points']['points']==75 and d['groups']['EUR:points']['discount']==0,'Bonuspunkte getrennt von Geld')
  check(d['groups']['EUR:product']['count']==1 and d['groups']['EUR:product']['discount']==0,'Produkte gezählt, nicht monetär bewertet')
  check(d['unknown']==1 and sum(d['days'].values())==6,'Ungültiger Snapshot nur in Anzahl und Tagen, Warnzähler')
  check(d['days']=={'2026-01-01':2,'2026-01-02':4},'UTC-Tagesgrenzen inklusive letztem Tag, exklusiv Folgetag')
  P='/program/%d/voucher-report'%pid
  st,html,_=req(P+'?from=2026-01-01&to=2026-01-02',c=o)
  check(st==200 and '12,00 EUR' in html and '10,00 USD' in html and 'ohne auswertbaren' in html,'Betreiberbericht zeigt Werte und Warnung')
  C=P+'?from=2026-01-01&to=2026-01-02&format=csv'
  def export(path=C,client=o):
   with client.open('http://127.0.0.1:8099'+path,timeout=15) as x:return x.status,x.read().decode(),x.headers
  snapshot=lambda:sql("echo json_encode([db()->query('SELECT * FROM voucher_redemptions')->fetchAll(),db()->query('SELECT * FROM voucher_codes')->fetchAll(),db()->query('SELECT * FROM audit_logs')->fetchAll()]);")
  before=snapshot();status,body,headers=export();rows=list(csv.DictReader(io.StringIO(body)))
  check(status==200 and headers.get_content_type()=='text/csv' and headers['Content-Disposition']=='attachment; filename="voucher-report-2026-01-01-2026-01-02.csv"','CSV als Download mit geprüftem Datumsnamen')
  check(headers['Cache-Control']=='private, no-store','CSV nicht im geteilten Cache')
  total=rows[0];groups={(r['Waehrung'],r['Rabattart']):r for r in rows if r['Zeilentyp']=='wertgruppe'}
  check(total['Einloesungen']=='6' and total['Mitglieder']=='2' and total['Stornos_ausgeschlossen']=='1' and total['Unbekannte_Snapshots']=='1','CSV Gesamtwerte entsprechen Ansicht')
  check(groups['EUR','fixed']['Kaufbetrag_Cent']=='5000' and groups['EUR','fixed']['Geldrabatt_Cent']=='1200' and groups['USD','percent']['Geldrabatt_Cent']=='1000','CSV Centwerte getrennt nach Währung und Typ')
  check(groups['EUR','points']['Bonuspunkte']=='75' and groups['EUR','points']['Geldrabatt_Cent']=='' and groups['EUR','product']['Geldrabatt_Cent']=='','Punkte und unbewertete Produkte nicht als Geld')
  check({r['Tag_UTC']:r['Einloesungen'] for r in rows if r['Zeilentyp']=='tag'}=={'2026-01-01':'2','2026-01-02':'4'},'CSV Tageszeilen in UTC')
  check(all(len(r)==14 and None not in r for r in rows) and 'Kein Umsatz/Zahlungsnachweis' in total['Hinweis'],'Stabiles CSV-Schema und Grenzen im Download')
  check('aylin@example.com' not in body and '0123456789ABCDEF12345678' not in body and 'Aylin' not in body,'CSV ohne Mitgliedsdaten oder Codes')
  check(snapshot()==before,'Export ändert weder Einlösungen, Codes noch Audit')
  check(not any(v.lstrip().startswith(('=','+','@','-')) for r in rows for v in r.values()),'CSV enthält keine ausführbaren Formelfelder')
  status,body,_=export(P+'?from=2027-01-01&to=2027-01-02&format=csv');empty=list(csv.DictReader(io.StringIO(body)))
  check(status==200 and len(empty)==1 and empty[0]['Einloesungen']=='0','Leerer Export enthält vollständige Null-Gesamtzeile')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  check(export()[0]==200,'CSV als Lesezugriff ohne aktives Abo')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  check('aylin@example.com' not in html and '0123456789ABCDEF12345678' not in html,'Bericht ohne persönliche Codes oder Mitgliedsidentitäten')
  check(req(P,c=M)[0]==404 or '/login' in req(P,c=M)[2],'Mitglied hat keinen Zugriff')
  check('/login' in req(P,c=mk())[2],'Bericht benötigt Login')
  sql("$q=db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)');$q->execute(['Fremd','foreign@example.com',password_hash('test123456',PASSWORD_DEFAULT)]);")
  outsider=mk();req('/login',{'csrf':csrf(outsider,'/login'),'email':'foreign@example.com','password':'test123456'},outsider)
  check(req(P,c=outsider)[0]==404,'Fremder Owner bekommt 404')
  check(req(C,c=outsider)[0]==404 and '/login' in req(C,c=mk())[2] and '/login' in req(C,c=M)[2],'CSV verlangt Eigentümerlogin')
  nonvoucher=sql("echo db()->query(\"SELECT id FROM programs WHERE type!='voucher' LIMIT 1\")->fetchColumn();")
  check(req('/program/'+nonvoucher+'/voucher-report',c=o)[0]==404,'Kein Bericht für andere Programmtypen')
  for f,t in [('2026-02-30','2026-03-01'),('2026-01-02','2026-01-01'),('2025-01-01','2026-01-02'),('','2026-01-01'),('<script>','2026-01-01'),('2026-01-01\x00','2026-01-02')]:
   st,h,_=req(P+'?'+urllib.parse.urlencode({'from':f,'to':t}),c=o);check(st==422 and '<script>' not in h,'Ungültiger Zeitraum abgewiesen und sicher dargestellt')
  check(req(P+'?from=2026-01-01&to=2026-01-02&format=xml',c=o)[0]==422,'Unbekanntes Exportformat abgewiesen')
  for suffix in ['from=2026-02-30&to=2026-03-01','from=2025-01-01&to=2026-01-02','from=2026-01-02&to=2026-01-01']:
   st,h,_=req(P+'?'+suffix+'&format=csv',c=o);check(st==422 and 'Zeilentyp' not in h,'CSV-Fehler ohne unvollständigen Download')
  check(req(P+'?from=2025-01-01&to=2026-01-01',c=o)[0]==200,'366 Kalendertage erlaubt')
  st,h,_=req(P+'?from=2027-01-01&to=2027-01-02',c=o);check(st==200 and 'Noch keine Einlösungen' in h,'Leerer Bericht')
  # Populate today for default browser view, while preserving all fixed-date assertions.
  add(time.strftime('%Y-%m-%d',time.gmtime())+' 12:00:00','fixed','EUR',2000,500)
  add(time.strftime('%Y-%m-%d',time.gmtime())+' 13:00:00','percent','USD',5000,1000)
  add(time.strftime('%Y-%m-%d',time.gmtime())+' 14:00:00','product','EUR',0,0)
  add(time.strftime('%Y-%m-%d',time.gmtime())+' 15:00:00','points','EUR',0,0)
  subprocess.run(['node',str(R/'tests/voucher-report-ui.cjs'),str(pid)],cwd=R,check=True)
  # Hard row cap: never show partial totals as complete.
  sql("db()->exec(\"WITH RECURSIVE n(x) AS(SELECT 1 UNION ALL SELECT x+1 FROM n WHERE x<10001) INSERT INTO voucher_redemptions(business_id,program_id,code_id,member_id,operation,spend_cents,discount_cents,actor_kind,actor_id,snapshot,created_at)SELECT 1,%d,%d,%d,'bulk-'||x,100,10,'owner',1,'{}','2026-02-01 12:00:00' FROM n\");"%(pid,cid,mid))
  st,h,_=req(P+'?from=2026-02-01&to=2026-02-01',c=o)
  check(st==422 and '10.000' in h and 'voucher-report-summary' not in h,'Über 10.000 Datensätze: keine unvollständigen Summen')
  st,h,_=req(P+'?from=2026-02-01&to=2026-02-01&format=csv',c=o)
  check(st==422 and '10.000' in h and 'Zeilentyp' not in h,'Zeilenlimit auch im CSV, keine Teilsummen')
 finally:s.terminate();s.wait();log.close()
 print(n,'Gutschein-Auswertungsprüfungen bestanden')
