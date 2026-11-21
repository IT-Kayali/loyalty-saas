#!/usr/bin/env python3
"""Einloesecodes fuer Praemien: Opt-in, Erzeugen, Limits, Einloesen genau einmal, Ablauf, Rechte, Mandanten, Parallelitaet, Browser. Lokaler Wegwerf-Server."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-claims-') as temp:
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
    with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url
   except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url
  def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c=c)[1]).group(1)
  o=mk();req('/login',{'csrf':csrf(o,'/login'),'email':'demo@loyalty.local','password':'demo1234'},o)
  def member(email,pw):
   c=mk();req('/member/login',{'csrf':csrf(c,'/member/login'),'email':email,'password':pw},c);return c
  M=member('aylin@example.com','kunde1234');B=member('ben@example.com','kunde1234')
  pid=int(sql("$s=db()->prepare(\"INSERT INTO programs(business_id,type,name) VALUES(1,'points','Claim-Test')\");$s->execute();echo db()->lastInsertId();"))
  rid=[int(sql("$s=db()->prepare('INSERT INTO rewards(program_id,name,points_cost) VALUES(?,?,?)');$s->execute([%d,'%s',%d]);echo db()->lastInsertId();"%(pid,nm,c))) for nm,c in [('Kaffee',50),('Kuchen',80),('Tasse',100),('Beutel',120),('Luxus',5000)]]
  mem=lambda e:int(sql("$s=db()->prepare('SELECT id FROM members WHERE business_id=1 AND email=?');$s->execute(['%s']);echo $s->fetchColumn();"%e))
  ca=int(sql("$s=db()->prepare('INSERT INTO cards(program_id,member_id,points,points_lifetime) VALUES(?,?,300,300)');$s->execute([%d,%d]);echo db()->lastInsertId();"%(pid,mem('aylin@example.com'))))
  cb=int(sql("$s=db()->prepare('INSERT INTO cards(program_id,member_id,points,points_lifetime) VALUES(?,?,300,300)');$s->execute([%d,%d]);echo db()->lastInsertId();"%(pid,mem('ben@example.com'))))
  pts=lambda c:int(sql("echo db()->query('SELECT points FROM cards WHERE id=%d')->fetchColumn();"%c))
  claims=lambda:json.loads(sql("echo json_encode(db()->query('SELECT * FROM reward_claims ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));"))
  create=lambda c,card,rew:req('/wallet/claim',{'csrf':csrf(c,'/wallet'),'action':'create','card_id':card,'reward_id':rew},c)
  # 1) aus
  check('Code für' not in req('/wallet',c=M)[1],'Aus: kein Bereich in der Wallet')
  create(M,ca,rid[0]);check(claims()==[],'Aus: Erzeugen wird abgelehnt')
  # 2) Einstellung
  prog=lambda m:req('/program/claims',{'csrf':csrf(o,'/program/%d'%pid),'program_id':pid,'minutes':m},o)
  prog(-1);prog(5000);check(sql("echo db()->query('SELECT claim_minutes FROM programs WHERE id=%d')->fetchColumn();"%pid)=='0','Ungültige Minuten abgelehnt')
  prog(15);check(sql("echo db()->query('SELECT claim_minutes FROM programs WHERE id=%d')->fetchColumn();"%pid)=='15','15 Minuten gespeichert')
  check('Einlösecodes' in req('/program/%d'%pid,c=o)[1] and sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='claims.set'\")->fetchColumn();")=='1','Einstellung sichtbar und protokolliert')
  # 3) Erzeugen
  w=req('/wallet',c=M)[1];check('Code für „Kaffee“' in w and 'Code für „Luxus“' not in w,'Buttons nur für bezahlbare Prämien')
  create(M,ca,rid[0]);cl=claims()
  secs=int(sql("echo strtotime(db()->query('SELECT expires_at FROM reward_claims')->fetchColumn().' UTC')-time();"))
  check(len(cl)==1 and re.fullmatch(r'[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}',cl[0]['code']) and 840<=secs<=900 and cl[0]['points_cost']==50,'Code 8 Zeichen ohne Verwechslungen, 15 Minuten, Kosten gemerkt')
  w=req('/wallet',c=M)[1];code=cl[0]['code']
  check(code[:4]+'-'+code[4:] in w and 'data-qr="http://127.0.0.1:8099/claim/'+code+'"' in w and pts(ca)==300,'Wallet zeigt Code und QR, keine Abbuchung')
  create(M,ca,rid[0]);check(len(claims())==1,'Zweiter Code für dieselbe Prämie abgelehnt')
  create(M,ca,rid[4]);check(len(claims())==1,'Zu teure Prämie abgelehnt')
  create(M,ca,rid[1]);create(M,ca,rid[2]);create(M,ca,rid[3]);check(len(claims())==3,'Höchstens 3 gültige Codes je Karte')
  # 4) fremde Karte
  create(B,ca,rid[3]);check(len(claims())==3,'Anderes Mitglied kann nicht auf fremder Karte erzeugen')
  req('/wallet/claim',{'csrf':csrf(B,'/wallet'),'action':'cancel','claim_id':cl[0]['id']},B);check(claims()[0]['status']=='active','Fremdes Mitglied kann Code nicht zurückziehen')
  req('/wallet/claim',{'action':'create','card_id':ca,'reward_id':rid[0]},M);check(len(claims())==3,'Ohne CSRF nichts')
  anon=mk();check(req('/wallet/claim',{'action':'create','card_id':ca,'reward_id':rid[0]},anon)[0] in (200,302,403) and len(claims())==3,'Ohne Anmeldung nichts')
  # 5) Zurückziehen
  cid=[x for x in claims() if x['reward_id']==rid[2]][0]
  req('/wallet/claim',{'csrf':csrf(M,'/wallet'),'action':'cancel','claim_id':cid['id']},M)
  check([x for x in claims() if x['id']==cid['id']][0]['status']=='cancelled','Mitglied zieht Code zurück')
  # 6) Kasse (Owner)
  base='/business/1/claim-counter'
  st,pg,_=req(base+'?code='+code[:4]+'-'+code[4:].lower(),c=o)
  check(st==200 and 'Kaffee' in pg and 'Aylin' in pg and 'Einlösen und 50 Punkte' in pg,'Kasse zeigt Vorschau mit Mitglied, Prämie, Kosten (Eingabe mit Bindestrich/klein)')
  check(req(base+'?code=ZZZZZZZZ',c=o)[1].count('Code nicht gefunden')>=1,'Unbekannter Code: nicht gefunden')
  check(sql("echo var_export(claim_load(2,'%s')===null,true);"%code)=='true','Code eines anderen Unternehmens nicht ladbar')
  # abgelaufen
  sql("db()->exec(\"UPDATE reward_claims SET expires_at='2000-01-01 00:00:00' WHERE reward_id=%d\");"%rid[1])
  c2=[x for x in claims() if x['reward_id']==rid[1]][0]
  st,b2,_=req(base,{'csrf':csrf(o,base),'action':'redeem','code':c2['code']},o)
  check('abgelaufen' in b2 and pts(ca)==300,'Abgelaufener Code: kein Einlösen, keine Abbuchung')
  check('Abgelaufen' in req(base+'?code='+c2['code'],c=o)[1] and 'Einlösen und' not in req(base+'?code='+c2['code'],c=o)[1],'Vorschau zeigt abgelaufen ohne Button')
  # zurückgezogen
  req(base,{'csrf':csrf(o,base),'action':'redeem','code':cid['code']},o);check(pts(ca)==300,'Zurückgezogener Code nicht einlösbar')
  # zu wenig Punkte
  sql("db()->exec('UPDATE cards SET points=40 WHERE id=%d');"%ca)
  st,b2,_=req(base,{'csrf':csrf(o,base),'action':'redeem','code':code},o)
  check('nicht mehr genug Punkte' in b2 and pts(ca)==40 and claims()[0]['status']=='active','Zu wenig Punkte: nichts abgebucht, Code bleibt gültig')
  sql("db()->exec('UPDATE cards SET points=300 WHERE id=%d');"%ca)
  # Einlösen
  st,b2,_=req(base,{'csrf':csrf(o,base),'action':'redeem','code':code},o)
  P1=pts(ca);check('Prämie &quot;Kaffee&quot; für' in b2 and P1 in (250,275) and claims()[0]['status']=='used','Einlösen bucht 50 Punkte ab und verbraucht den Code')
  t=json.loads(sql("echo json_encode(db()->query(\"SELECT kind,amount,note FROM transactions WHERE card_id=%d AND kind='points_redeem'\")->fetchAll(PDO::FETCH_ASSOC));"%ca))
  check(len(t)==1 and t[0]['amount']==50 and code[:4]+'-'+code[4:] in t[0]['note'],'Buchung im Verlauf mit Code')
  req(base,{'csrf':csrf(o,base),'action':'redeem','code':code},o);check(pts(ca)==P1,'Zweites Einlösen: keine Doppelabbuchung')
  check(sql("echo db()->query('SELECT used_by FROM reward_claims WHERE id=%d')->fetchColumn();"%claims()[0]['id'])[:6]=='owner:','Einlösender wird festgehalten')
  create(M,ca,rid[3]);cl3=[x for x in claims() if x['reward_id']==rid[3]][0]
  req(base,{'action':'redeem','code':cl3['code']},o);check(pts(ca)==P1 and [x for x in claims() if x['id']==cl3['id']][0]['status']=='active','Einlösen ohne CSRF-Token wirkungslos')
  # Parallel
  ps=[subprocess.Popen([PHP,'-r',"require 'public/index.php';try{claim_redeem(1,'%s',['kind'=>'owner','id'=>1]);echo 'ok';}catch(Throwable $e){echo 'no';}"%cl3['code']],cwd=A,stdout=subprocess.PIPE,text=True) for _ in range(4)]
  res=[p.communicate()[0].strip() for p in ps]
  check(res.count('ok')==1 and pts(ca) in (P1-120,P1-120+25,P1-120+50),'Vier parallele Einlösungen: genau eine, genau einmal abgebucht')
  # 7) QR-Ziel
  an=mk();st,pg,u=req('/claim/'+cl3['code'],c=an)
  check(st==200 and 'Aylin' not in pg and 'Gutschein' not in pg and 'claim-counter' not in u,'QR-Ziel ohne Anmeldung zeigt nichts Persönliches')
  fresh=sql("$s=db()->prepare('INSERT INTO reward_claims(card_id,reward_id,reward_name,points_cost,code,expires_at) VALUES(?,?,?,?,?,?)');$s->execute([%d,%d,'Kaffee',50,'QRTEST22',gmdate('Y-m-d H:i:s',time()+600)]);echo 'QRTEST22';"%(cb,rid[0]))
  st,pg,u=req('/claim/QRTEST22',c=o);check('claim-counter?code=QRTEST22' in u and 'Einlösen und 50' in pg,'QR-Ziel leitet Owner zur Kasse mit Code')
  # Team
  sql("$h=password_hash('1234',PASSWORD_DEFAULT);$s=db()->prepare('INSERT INTO staff(business_id,name,pin_hash,role) VALUES(1,?,?,?)');$s->execute(['Kasse',$h,'cashier']);$s->execute(['Nur Sehen',$h,'viewer']);")
  def staff(nm):
   c=mk();req('/staff/login',{'csrf':csrf(c,'/staff/login'),'business_id':1,'name':nm,'pin':'1234'},c);return c
  S=staff('Kasse');st,pg,u=req('/claim/QRTEST22',c=S)
  check('staff/business/1/claim-counter?code=QRTEST22' in u and 'Einlösen und 50' in pg,'QR-Ziel leitet Team zur Team-Kasse')
  st,b2,_=req('/staff/business/1/claim-counter',{'csrf':csrf(S,'/staff/business/1/claim-counter'),'action':'redeem','code':'QRTEST22'},S)
  check(pts(cb)==250 and sql("echo db()->query(\"SELECT used_by FROM reward_claims WHERE code='QRTEST22'\")->fetchColumn();")[:6]=='staff:','Team löst ein, Mitarbeiter wird festgehalten')
  check(sql("echo db()->query(\"SELECT staff_id IS NOT NULL FROM transactions WHERE card_id=%d AND kind='points_redeem'\")->fetchColumn();"%cb)=='1','Buchung trägt die Mitarbeiter-ID')
  # Scanner: exakte eigene Links/Codeformate, keine externen Ziele oder Teiltreffer.
  mc=sql("""echo db()->query("SELECT code FROM members WHERE business_id=1 AND email='aylin@example.com'")->fetchColumn();""")
  for raw in ['QRTE-ST22','http://127.0.0.1:8099/claim/QRTEST22']:
   st,pg,url=req('/staff/find?'+urllib.parse.urlencode({'code':raw}),c=S)
   check('/staff/business/1/claim-counter?code=QRTEST22' in url and 'Eingelöst' in pg,'Scanner öffnet Prämien-Vorschau, keine automatische Einlösung')
  st,pg,url=req('/staff/find?'+urllib.parse.urlencode({'code':'http://127.0.0.1:8099/c/'+mc}),c=S)
  check('/staff/member/' in url,'Kundenkarten-QR öffnet weiterhin Mitglied')
  for raw in ['https://evil.invalid/c/'+mc,'prefix'+mc+'suffix','javascript:alert(1)','http://127.0.0.1:8099/claim/QRTEST22?next=external']:
   st,pg,url=req('/staff/find?'+urllib.parse.urlencode({'code':raw}),c=S)
   check(url.endswith('/staff/scan') and 'Kein gültiger' in pg,'Scanner verwirft fremde oder ungenaue QR-Daten')
  V=staff('Nur Sehen');check(req('/staff/find?code=QRTE-ST22',c=V)[0]==403,'Scanner: Viewer darf Prämien nicht öffnen');check(req('/staff/business/1/claim-counter',c=V)[0]==403,'Team ohne Einlöse-Recht: 403')
  check(req('/staff/business/1/claim-counter',c=mk())[0] in (404,302,403),'Ohne Anmeldung kein Zugriff')
  check(req('/business/1/claim-counter',c=M)[0]==404,'Mitglied hat keinen Zugriff auf die Kasse')
  check(req('/staff/business/2/claim-counter',c=S)[0]==404,'Team nicht in fremdem Unternehmen')
  # Liste
  pg=req(base,c=o)[1];check('Letzte Codes' in pg and 'Eingelöst' in pg and 'Zurückgezogen' in pg,'Kasse listet letzte Codes mit Status')
  # Wallet nach Einlösung
  w=req('/wallet',c=M)[1];check(code[:4]+'-'+code[4:] not in w,'Eingelöster Code verschwindet aus der Wallet')
  # Aus schalten: Altcodes nicht mehr erzeugbar, bestehende bleiben einlösbar
  prog(0);create(M,ca,rid[0]);check(len([x for x in claims() if x['reward_id']==rid[0] and x['card_id']==ca])==1,'Nach Abschalten keine neuen Codes')
  # Zeitfenster je Prämie
  prog(30);P2=lambda:pts(cb)
  def win(rw,f,u):return req('/reward/window',{'csrf':csrf(o,'/program/%d'%pid),'reward_id':rw,'valid_from':f,'valid_until':u},o)
  rwrow=lambda:json.loads(sql("$s=db()->prepare('SELECT valid_from,valid_until FROM rewards WHERE id=?');$s->execute([%d]);echo json_encode($s->fetch(PDO::FETCH_ASSOC));"%rid[1]))
  fmt=lambda d:time.strftime('%Y-%m-%dT%H:%M',time.gmtime(time.time()+d*60))
  check('Zeitfenster für Prämien' in req('/program/%d'%pid,c=o)[1],'Programmseite zeigt Zeitfenster-Formulare')
  win(rid[1],'quatsch','');win(rid[1],fmt(60),fmt(30));check(rwrow()=={'valid_from':None,'valid_until':None},'Ungültiges Datum und Ende vor Start abgelehnt')
  req('/reward/window',{'reward_id':rid[1],'valid_from':fmt(60),'valid_until':''},o);check(rwrow()['valid_from'] is None,'Zeitfenster ohne CSRF nichts')
  win(rid[1],fmt(60),fmt(120));check(rwrow()['valid_from'] is not None,'Fenster in der Zukunft gespeichert')
  before=len(claims());create(B,cb,rid[1]);check(len(claims())==before,'Vor dem Fenster: kein Code')
  w=req('/wallet',c=B)[1];check('Code für „Kuchen“' not in w and 'ab ' in w,'Wallet zeigt Prämie als noch nicht verfügbar')
  win(rid[1],fmt(-60),fmt(-30));create(B,cb,rid[1]);check(len(claims())==before,'Nach dem Fenster: kein Code')
  check('Code für „Kuchen“' not in req('/wallet',c=B)[1],'Wallet blendet abgelaufene Prämie aus')
  win(rid[1],fmt(-5),fmt(10));create(B,cb,rid[1]);nc=claims()[-1]
  secs=int(sql("echo strtotime(db()->query('SELECT expires_at FROM reward_claims ORDER BY id DESC LIMIT 1')->fetchColumn().' UTC')-time();"))
  check(len(claims())==before+1 and 540<=secs<=600,'Im Fenster: Code läuft spätestens zum Fensterende ab (nicht 30 Minuten)')
  sql("db()->exec(\"UPDATE rewards SET valid_until='2000-01-01 00:00:00' WHERE id=%d\");"%rid[1])
  p0=P2();st,b2,_=req(base,{'csrf':csrf(o,base),'action':'redeem','code':nc['code']},o)
  check('nicht mehr verfügbar' in b2 and P2()==p0 and claims()[-1]['status']=='active','Fenster vorbei: Einlösen blockiert, nichts abgebucht')
  pgx=req(base+'?code='+nc['code'],c=o)[1];check('Außerhalb des Zeitfensters' in pgx and 'Einlösen und' not in pgx,'Kasse zeigt Fenster-Hinweis ohne Einlösen-Button')
  q=req('/card/%d/action'%cb,{'csrf':csrf(o,'/program/%d'%pid),'action':'redeem_reward:%d'%rid[1]},o);check(P2()==p0,'Direkte Einlösung (Kartenaktion) außerhalb des Fensters blockiert')
  sql("db()->exec(\"UPDATE rewards SET valid_from='2000-01-01 00:00:00',valid_until=NULL WHERE id=%d\");"%rid[1])
  p0=P2();st,b2,_=req(base,{'csrf':csrf(o,base),'action':'redeem','code':nc['code']},o)
  check('eingelöst' in b2 and P2()<p0 and claims()[-1]['status']=='used','Fenster ohne Ende offen: Einlösen klappt')
  win(rid[1],'','');check(rwrow()=={'valid_from':None,'valid_until':None},'Fenster entfernen')
  check(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='reward.window'\")->fetchColumn();")=='4','Fenster-Änderungen im Audit-Log')
  # Zeitzone je Unternehmen
  btz=lambda:sql("echo db()->query('SELECT timezone FROM businesses WHERE id=1')->fetchColumn();")
  settz=lambda z,c=o:req('/business/timezone',{'csrf':csrf(o,'/business/1'),'business_id':1,'timezone':z},c)
  check(btz()=='UTC' and 'name="timezone"' in req('/business/1',c=o)[1],'Standard UTC, Formular auf der Unternehmensseite')
  settz('Mars/Olympus');settz('../../etc');check(btz()=='UTC','Ungültige Zeitzone abgelehnt')
  req('/business/timezone',{'business_id':1,'timezone':'Europe/Berlin'},o);check(btz()=='UTC','Zeitzone ohne CSRF nichts')
  settz('Europe/Berlin',M);check(btz()=='UTC','Mitglied kann Zeitzone nicht ändern')
  settz('Europe/Berlin');check(btz()=='Europe/Berlin' and sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='business.timezone'\")->fetchColumn();")=='1','Zeitzone gespeichert und protokolliert')
  win(rid[1],'2027-01-15T12:00','2027-07-01T12:00');check(rwrow()=={'valid_from':'2027-01-15 11:00:00','valid_until':'2027-07-01 10:00:00'},'Eingabe in Berlin wird als UTC gespeichert (Winter +1, Sommer +2)')
  pg=req('/program/%d'%pid,c=o)[1];check('value="2027-01-15T12:00"' in pg and 'value="2027-07-01T12:00"' in pg and 'Europe/Berlin' in pg,'Formular zeigt Berliner Zeit zurück')
  w=req('/wallet',c=B)[1];check('15.01.2027 12:00 CET' in w and '01.07.2027 12:00 CEST' in w,'Wallet zeigt Zeitfenster in Ortszeit mit Kürzel')
  win(rid[1],'2027-02-30T10:00','');check(rwrow()['valid_from']=='2027-01-15 11:00:00','Ungültiger Kalendertag abgelehnt')
  win(rid[1],'','');settz('UTC');check(btz()=='UTC' and rwrow()=={'valid_from':None,'valid_until':None},'Zurück auf UTC, Fenster entfernt')
  # Browser
  sql("db()->exec('UPDATE cards SET points=300 WHERE id=%d');"%ca);prog(30);settz('Europe/Berlin');win(rid[1],fmt(600),fmt(900))
  subprocess.run(['node',str(R/'tests/claims-ui.cjs'),str(ca),str(rid[0]),str(pid)],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Einlösecode-Prüfungen bestanden')
