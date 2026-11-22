#!/usr/bin/env python3
"""Unternehmensname: Eigentümer, CSRF, Plan, Konflikt und keine Nebenänderungen. Wegwerf-Server."""
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
  P='/business/1/settings'
  state=lambda:json.loads(sql("echo json_encode(db()->query('SELECT * FROM businesses WHERE id=1')->fetch(PDO::FETCH_ASSOC));"))
  revision=lambda:re.search(r'name="revision" value="([a-f0-9]+)"',req(P,c=o)[1]).group(1)
  def edit(name='Foundry Neu',rev=None,c=o,token=True,extra=None):
   data={'name':name,'revision':rev if rev is not None else revision()}
   if token:data['csrf']=csrf(c,P if c==o else '/login')
   if extra:data.update(extra)
   return req(P,data,c)
  def related():return sql("echo json_encode([db()->query('SELECT * FROM businesses WHERE id!=1')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM programs')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM cards')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM members')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM staff')->fetchAll(PDO::FETCH_ASSOC)]);")
  old=state();other=related()
  check(req(P,c=o)[0]==200 and 'nicht automatisch aktualisiert' in req(P,c=o)[1],'Editor erklärt Webname und Wallet-Grenzen')
  check(edit(token=False)[0]==403 and state()==old,'Ohne CSRF nichts geändert')
  for name in ['', '   ', 'x'*101, 'Firma\x00Neu','Zeile\nZwei','Tab\tName']:
   edit(name);check(state()==old,'Ungültiger Name unverändert')
  edit('  Kaffee & Gäste  ')
  saved=state();check(saved['name']=='Kaffee & Gäste','Name getrimmt mit Umlauten gespeichert')
  check({k:v for k,v in old.items() if k not in ('name','wallet_push_notice_revision')}=={k:v for k,v in saved.items() if k not in ('name','wallet_push_notice_revision')},'Nur Name und Mitteilungshinweis-Revision geändert, übrige Felder unverändert')
  check(saved['wallet_push_notice_revision']==old['wallet_push_notice_revision']+1,'Namensänderung erneuert optionalen Wallet-Mitteilungshinweis')
  check(related()==other,'Programme/Guthaben/Mitglieder/Staff/Schlüssel/fremde Unternehmen unverändert')
  check('Kaffee &amp; Gäste' in req('/dashboard',c=o)[1] and 'Kaffee &amp; Gäste' in req('/business/1',c=o)[1],'Neuer Name in Dashboard und Unternehmensseite')
  check('Kaffee &amp; Gäste' in req('/wallet',c=M)[1],'Neue Online-Wallet liest Unternehmensname')
  rev=revision();edit('Tab Eins',rev=rev);saved=state()
  check('inzwischen geändert' in edit('Alter Tab',rev=rev)[1] and state()==saved,'Veraltetes Formular überschreibt nicht')
  edit('<script>alert(1)</script>');h=req(P,c=o)[1]
  check('<script>alert(1)</script>' not in h and '&lt;script&gt;' in h,'Name escaped statt Skript')
  edit('Ziel bleibt',extra={'business_id':2,'user_id':999,'brand_color':'#000000','api_key':'injected'})
  check(state()['name']=='Ziel bleibt' and state()['brand_color']==old['brand_color'] and related()==other,'Fremde Ziel-/Farb-/Schlüsselfelder ignoriert')
  check(sql("echo db()->query(\"SELECT details FROM audit_logs WHERE action='business.rename' ORDER BY id DESC LIMIT 1\")->fetchColumn();")==json.dumps({'business_id':1},separators=(',',':')),'Audit nur mit Unternehmens-ID')
  check('/login' in req(P,c=mk())[2] and '/login' in req(P,c=M)[2],'Gast und Mitglied ohne Betreiberzugriff')
  sql("$q=db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)');$q->execute(['Fremd','foreign@example.com',password_hash('test123456',PASSWORD_DEFAULT)]);")
  outsider=mk();req('/login',{'csrf':csrf(outsider,'/login'),'email':'foreign@example.com','password':'test123456'},outsider)
  before=state();check(req(P,c=outsider)[0]==404,'Fremder Betreiber GET 404')
  check(req(P,{'csrf':csrf(outsider,'/dashboard'),'name':'Fremd','revision':revision()},outsider)[0]==404 and state()==before,'Fremder Betreiber POST ohne Änderung')
  # Unrelated design changes should neither conflict nor be overwritten.
  rev=revision()
  sql("$q=db()->prepare('UPDATE businesses SET card_design=? WHERE id=1');$q->execute([json_encode(['header'=>'Eigene Kopfzeile'])]);")
  edit('Neue Firma',rev=rev);check(state()['name']=='Neue Firma' and json.loads(state()['card_design'])['header']=='Eigene Kopfzeile','Zwischenzeitliches Design bleibt bei Namensänderung erhalten')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  check(req(P,c=o)[0]==200,'Gesperrtes Abo kann Namen lesen')
  before=state();check(edit('Gesperrt')[0]==402 and state()==before,'Gesperrtes Abo kann Namen nicht ändern')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  subprocess.run(['node',str(R/'tests/business-settings-ui.cjs')],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Unternehmensnamen-Prüfungen bestanden')
