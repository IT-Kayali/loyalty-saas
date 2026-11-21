#!/usr/bin/env python3
"""Segment-Editor: Einzelregeln, Rechte, Konflikte, kein Versand, Plan, Desktop/Mobil. Wegwerf-Server."""
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
  sid=int(sql("db()->exec(\"INSERT INTO segments(business_id,name,rule,param)VALUES(1,'Stammkunden','all',30)\");echo db()->lastInsertId();"))
  cid=int(sql("db()->exec(\"INSERT INTO campaigns(business_id,segment_id,name,subject,body)VALUES(1,%d,'Entwurf','Betreff','Inhalt')\");echo db()->lastInsertId();"%sid))
  sentid=int(sql("db()->exec(\"INSERT INTO campaigns(business_id,segment_id,name,subject,body,status,sent_at)VALUES(1,%d,'Gesendet','Alt','Alter Inhalt','sent','2026-01-01 12:00:00')\");echo db()->lastInsertId();"%sid))
  sql("db()->exec(\"INSERT INTO campaign_sends(campaign_id,member_id)SELECT %d,id FROM members WHERE business_id=1 LIMIT 1\");"%sentid)
  history=lambda:sql("echo json_encode([db()->query('SELECT * FROM campaigns WHERE id=%d')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM campaign_sends WHERE campaign_id=%d')->fetchAll(PDO::FETCH_ASSOC)]);"%(sentid,sentid))
  sentbefore=history()
  P='/segment/%d/edit'%sid
  state=lambda:json.loads(sql("echo json_encode(db()->query('SELECT * FROM segments WHERE id=%d')->fetch(PDO::FETCH_ASSOC));"%sid))
  revision=lambda:re.search(r'name="revision" value="([a-f0-9]+)"',req(P,c=o)[1]).group(1)
  def edit(name='Neu',rule='min_points',param='100',rev=None,c=o,token=True,action='save'):
   data={'name':name,'rule':rule,'param':param,'action':action,'revision':rev if rev is not None else revision()}
   if token:data['csrf']=csrf(c,P if c==o else '/login')
   return req(P,data,c)
  old=state();check(req(P,c=o)[0]==200 and '1 noch nicht gesendete' in req(P,c=o)[1],'Editor mit aktueller Anzahl und Entwurfswarnung')
  check(edit(token=False)[0]==403 and state()==old,'Speichern ohne CSRF wirkungslos')
  for name,rule,param in [('', 'all','0'),('x'*81,'all','0'),('Test','unknown','1'),('Test','all','-1'),('Test','all','1.5'),('Test','all','100001'),('Test','all','abc')]:
   edit(name,rule,param);check(state()==old,'Ungültiger Name/Regel/Wert abgewiesen')
  def effects():return sql("echo json_encode([db()->query('SELECT * FROM campaigns')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM campaign_sends')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM member_messages')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM mail_outbox')->fetchAll(PDO::FETCH_ASSOC),db()->query('SELECT * FROM audit_logs')->fetchAll(PDO::FETCH_ASSOC)]);")
  baseline=effects();rev=revision()
  for rule in ['all','new_days','active_days','inactive_days','min_points','min_stamps']:
   st,h,_=edit('Vorschau '+rule,rule,'100000',action='preview')
   check(st==200 and 'Ungespeicherte Vorschau' in h and state()==old and effects()==baseline,'Vorschau ohne Speichern/Versand/Audit: '+rule)
   check(re.search(r'name="revision" value="([a-f0-9]+)"',h).group(1)==rev,'Vorschau behält Revision des gespeicherten Stands')
  h=edit('Niemand','min_points','100000',action='preview')[1]
  check('>0</strong> Mitglieder bei der letzten Prüfung' in h and '>3</strong> Mitglieder passen zur aktuell gespeicherten' in h,'Ungespeicherte und gespeicherte Anzahl getrennt korrekt')
  check(edit(action='preview',token=False)[0]==403 and state()==old,'Vorschau braucht CSRF')
  check(edit(action='unknown')[0]==422 and state()==old,'Unbekannte Aktion schreibt nicht')
  for rule in ['all','new_days','active_days','inactive_days','min_points','min_stamps']:
   r=edit('Zielgruppe '+rule,rule,'1');check(state()['rule']==rule and state()['param']==1,'Vorhandene Einzelregel editierbar: '+rule)
  beforecounts=sql("echo json_encode([db()->query('SELECT COUNT(*) FROM campaign_sends')->fetchColumn(),db()->query('SELECT COUNT(*) FROM member_messages')->fetchColumn(),db()->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn()]);")
  rev=revision();edit('Erster Tab','all','0',rev=rev);saved=state()
  check('inzwischen geändert' in edit('Veralteter Tab','min_stamps','12',rev=rev)[1] and state()==saved,'Veraltetes Formular überschreibt keine neuere Änderung')
  check('inzwischen geändert' in edit('Alte Vorschau','min_stamps','12',rev=rev,action='preview')[1] and state()==saved,'Veraltete Vorschau erbt keine neue Revision')
  check(beforecounts==sql("echo json_encode([db()->query('SELECT COUNT(*) FROM campaign_sends')->fetchColumn(),db()->query('SELECT COUNT(*) FROM member_messages')->fetchColumn(),db()->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn()]);"),'Editieren erzeugt keine Versand-/Inbox-/Outbox-Einträge')
  check(history()==sentbefore,'Bereits gesendete Kampagne und Versandprotokoll unverändert')
  check(sql("echo db()->query('SELECT segment_id FROM campaigns WHERE id=%d')->fetchColumn();"%cid)==str(sid),'Kampagnenverknüpfung bleibt erhalten')
  edit('<script>alert(1)</script>','min_points','100');html=req(P,c=o)[1]
  check('<script>alert(1)</script>' not in html and '&lt;script&gt;' in html,'Name sicher escaped')
  check(sql("echo db()->query(\"SELECT details FROM audit_logs WHERE action='segment.update' ORDER BY id DESC LIMIT 1\")->fetchColumn();")==json.dumps({'segment_id':sid,'business_id':1},separators=(',',':')),'Audit nur mit IDs, ohne Namen/Regeln/Empfänger')
  check('/login' in req(P,c=mk())[2] and '/login' in req(P,c=M)[2],'Gast und Mitglied ohne Editorzugriff')
  sql("$q=db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)');$q->execute(['Fremd','foreign@example.com',password_hash('test123456',PASSWORD_DEFAULT)]);")
  outsider=mk();req('/login',{'csrf':csrf(outsider,'/login'),'email':'foreign@example.com','password':'test123456'},outsider)
  before=state();check(req(P,c=outsider)[0]==404,'Fremder Betreiber GET 404')
  check(req(P,{'csrf':csrf(outsider,'/dashboard'),'name':'Fremd','rule':'all','param':'0','revision':revision(),'business_id':1},outsider)[0]==404 and state()==before,'Fremder Betreiber POST 404, keine Änderung')
  # Client cannot move the segment or target another business by posted IDs.
  rev=revision();req(P,{'csrf':csrf(o,P),'name':'Fixiert','rule':'all','param':'0','revision':rev,'business_id':2,'segment_id':999},o)
  check(state()['business_id']==1 and state()['name']=='Fixiert','Business und Segment aus URL/Ownership, nicht aus Formularparametern')
  # Dynamic target follows saved rule, never freezes member IDs.
  edit('Dynamisch','min_points','100000');check('>0</strong> Mitglieder' in req(P,c=o)[1],'Neue dynamische Anzahl nach Speichern')
  edit('Alle','all','0');check('>3</strong> Mitglieder' in req(P,c=o)[1],'Alle-Regel ignoriert Wert und zeigt aktuelle Anzahl')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  check(req(P,c=o)[0]==200,'Gesperrtes Abo kann Segment lesen')
  before=state();check(edit('Gesperrt')[0]==402 and state()==before,'Gesperrtes Abo kann Segment nicht ändern')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  subprocess.run(['node',str(R/'tests/segments-ui.cjs'),str(sid)],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Segment-Editor-Prüfungen bestanden')
