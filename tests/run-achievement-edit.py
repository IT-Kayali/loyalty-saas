#!/usr/bin/env python3
"""Meilenstein-Regeln vor Freischaltung bearbeiten, atomare Vergabe und Historienerhalt. Wegwerf-Server."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json,uuid
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-campaign-edit-') as temp:
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
  aid=int(sql("db()->exec(\"INSERT INTO achievements(business_id,name,metric,threshold,reward_points)VALUES(1,'Neu','points_lifetime',100000,10)\");echo db()->lastInsertId();"))
  mid=int(sql("echo db()->query(\"SELECT id FROM members WHERE business_id=1 AND email='ben@example.com'\")->fetchColumn();"))
  P='/achievement/%d/edit'%aid
  state=lambda:json.loads(sql("echo json_encode(db()->query('SELECT * FROM achievements WHERE id=%d')->fetch(PDO::FETCH_ASSOC));"%aid))
  rev=lambda:re.search(r'name="revision" value="([a-f0-9]+)"',req(P,c=o)[1]).group(1)
  def edit(extra=None,token=True):
   d={'name':'Neu bearbeitet','metric':'stamps_total','threshold':'2','reward_points':'25','revision':rev()}
   if token:d['csrf']=csrf(o,P)
   if extra:d.update(extra)
   return req(P,d,o)
  def effects():return sql("echo json_encode([db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM member_achievements')->fetchAll(),db()->query('SELECT * FROM member_messages')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll()]);")
  old=state();before=effects();check(req(P,c=o)[0]==200 and 'Meilenstein speichern' in req(P,c=o)[1],'Eigener noch nicht erreichter Meilenstein editierbar')
  check(edit(token=False)[0]==403 and state()==old,'CSRF erforderlich')
  for bad in [{'name':''},{'name':'x'*81},{'name':'Test\x00Name'},{'metric':'wrong'},{'threshold':'0'},{'threshold':'100001'},{'threshold':'1.5'},{'reward_points':'-1'},{'reward_points':'100001'}]:
   edit(bad);check(state()==old,'Ungültige Regelfelder unverändert')
  stale=rev();edit();check(state()['metric']=='stamps_total' and state()['reward_points']==25 and effects()==before,'Änderung speichert Regel ohne Buchungen oder Nachrichten')
  saved=state();check('inzwischen geändert' in edit({'revision':stale})[1] and state()==saved,'Alter Tab überschreibt nicht')
  edit({'name':'<script>alert(1)</script>','threshold':'100000'});check('<script>alert(1)</script>' not in req(P,c=o)[1] and '&lt;script&gt;' in req(P,c=o)[1],'Name escaped')
  check('/login' in req(P,c=mk())[2] and '/login' in req(P,c=M)[2],'Gast und Mitglied ohne Eigentümerzugriff')
  sql("$q=db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)');$q->execute(['Fremd','foreign@example.com',password_hash('test123456',PASSWORD_DEFAULT)]);")
  outsider=mk();req('/login',{'csrf':csrf(outsider,'/login'),'email':'foreign@example.com','password':'test123456'},outsider)
  check(req(P,c=outsider)[0]==404,'Fremder Betreiber 404')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  saved=state();check(edit()[0]==402 and state()==saved,'Plan blockiert Änderung')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  sql("db()->exec('UPDATE cards SET points_lifetime=100 WHERE member_id=%d AND program_id IN (SELECT id FROM programs WHERE type=\"points\")');"%mid)
  # Concurrent rule edits and award checks must serialize.
  for i in range(3):
   fresh=int(sql("db()->exec(\"INSERT INTO achievements(business_id,name,metric,threshold,reward_points)VALUES(1,'Race','points_lifetime',1,7)\");echo db()->lastInsertId();"))
   revision=sql("echo achievement_revision(db()->query('SELECT * FROM achievements WHERE id=%d')->fetch());"%fresh)
   cmd="try{achievement_change(1,%d,['name'=>'Race','metric'=>'points_lifetime','threshold'=>'100000','reward_points'=>'9','revision'=>'%s']);echo 'updated';}catch(InvalidArgumentException $e){echo 'locked';}"%(fresh,revision)
   workers=[subprocess.Popen([PHP,'-r',"require 'public/index.php';"+c],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for c in [cmd,'check_achievements(%d,1);echo \'checked\';'%mid]]
   out=[w.communicate(timeout=15) for w in workers];check(all(w.returncode==0 for w in workers),'Konkurrenzprozesse ohne DB-Fehler')
   awarded=sql('echo (int)achievement_has_awards(%d);'%fresh)
   check((out[0][0]=='updated' and awarded=='0') or (out[0][0]=='locked' and awarded=='1'),'Vergabe liest alte Regel oder Änderung gewinnt ohne Vergabe')
  # Known award freezes edit and existing delete action.
  edit({'metric':'points_lifetime','threshold':'1','reward_points':'13','name':'Treuebonus'});stale=rev();before=effects()
  sql('check_achievements(%d,1);'%mid);check(sql('echo (int)achievement_has_awards(%d);'%aid)=='1','Unterstützte Prüfung vergibt passenden Meilenstein')
  after=effects();sql('check_achievements(%d,1);'%mid);check(effects()==after,'Wiederholte Prüfung vergibt nicht erneut')
  d={'csrf':csrf(o,'/business/1'),'revision':stale,'name':'Veraltet','metric':'points_lifetime','threshold':2,'reward_points':999}
  check('Bereits erreicht' in req(P,d,o)[1] and effects()==after and state()['reward_points']==13,'Nach Freischaltung unveränderbar trotz altem Formular')
  req('/achievement/delete',{'csrf':csrf(o,'/business/1'),'business_id':1,'achievement_id':aid},o)
  check(effects()==after and state()['name']=='Treuebonus','Löschen erreichtes Exemplar behält Bonus und Vergabebeleg')
  check('Meilenstein speichern' not in req(P,c=o)[1] and 'Nur lesen' in req(P,c=o)[1],'Erreichte Regel nur lesbar')
  # injected failure rolls back proof and award together.
  failure=int(sql("db()->exec(\"INSERT INTO achievements(business_id,name,metric,threshold,reward_points)VALUES(1,'Fehler','points_lifetime',1,17)\");echo db()->lastInsertId();"))
  sql("db()->exec(\"CREATE TRIGGER achievement_fail BEFORE INSERT ON member_messages WHEN NEW.kind='achievement' BEGIN SELECT RAISE(ABORT,'TEST_FAIL'); END\");")
  before=effects();check(sql('try{check_achievements(%d,1);}catch(Throwable $e){echo \'rollback\';}'%mid)=='rollback' and effects()==before,'Fehler rollt Punkte, Transaktion, Vergabebeleg und Inbox zusammen zurück')
  sql("db()->exec('DROP TRIGGER achievement_fail');")
  before=effects();check(sql('try{check_achievements(%d,2);}catch(InvalidArgumentException $e){echo \'blocked\';}'%mid)=='blocked' and effects()==before,'Fremdes Unternehmensmitglied ohne Vergabe')
  # Two concurrent checks must award exactly once.
  parallel=int(sql("db()->exec(\"INSERT INTO achievements(business_id,name,metric,threshold,reward_points)VALUES(1,'Parallel','points_lifetime',1,19)\");echo db()->lastInsertId();"))
  workers=[subprocess.Popen([PHP,'-r',"require 'public/index.php';check_achievements(%d,1);"%mid],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(2)];[w.communicate(timeout=15) for w in workers]
  check(all(w.returncode==0 for w in workers) and sql('echo db()->query(\"SELECT COUNT(*) FROM member_achievements WHERE achievement_id=%d\")->fetchColumn();'%parallel)=='1','Zwei Prüfungen vergeben höchstens einmal ohne DB-Fehler')
  check(sql("echo db()->query(\"SELECT COUNT(*) FROM transactions WHERE note LIKE '%%Parallel%%'\")->fetchColumn();")=='1','Genau eine Bonusbuchung bei konkurrierender Freischaltung')
  untouched=int(sql("db()->exec(\"INSERT INTO achievements(business_id,name,metric,threshold,reward_points)VALUES(1,'Löschen','visits_total',100000,0)\");echo db()->lastInsertId();"))
  check(req('/achievement/delete',{'csrf':csrf(o,'/business/1'),'business_id':2,'achievement_id':untouched},o)[0]==404,'Löschziel muss zum angegebenen Unternehmen gehören')
  req('/achievement/delete',{'csrf':csrf(o,'/business/1'),'business_id':1,'achievement_id':untouched},o)
  check(sql('echo db()->query(\"SELECT COUNT(*) FROM achievements WHERE id=%d\")->fetchColumn();'%untouched)=='0','Noch nie erreichter Meilenstein weiterhin löschbar')
  # Pause/resume leaves earned rule and old awards intact.
  def setstate(id,active,extra=None,token=True):
   d={'action':'state','active':str(active),'revision':sql("echo achievement_revision(db()->query('SELECT * FROM achievements WHERE id=%d')->fetch());"%id)}
   if token:d['csrf']=csrf(o,'/business/1')
   if extra:d.update(extra)
   return req('/achievement/%d/edit'%id,d,o)
  check(req('/achievement/%d/edit'%aid,{'csrf':csrf(outsider,'/dashboard'),'action':'state','active':'0','revision':rev()},outsider)[0]==404,'Statuswechsel fremder Betreiber gesperrt')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  saved=state();check(setstate(aid,0)[0]==402 and state()==saved,'Plan blockiert Statuswechsel')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  before=effects();saved=state();check(setstate(aid,0,token=False)[0]==403 and state()==saved,'Pause benötigt CSRF')
  check('pausiert' in setstate(aid,0)[1] and state()['active']==0 and effects()==before,'Erreichte Regel pausierbar ohne Entzug oder Nachricht')
  benc=mk();req('/member/login',{'csrf':csrf(benc,'/member/login'),'email':'ben@example.com','password':'kunde1234'},benc)
  check('Treuebonus' in req('/wallet',c=benc)[1] and 'erreicht' in req('/wallet',c=benc)[1],'Pausierter erreichter Meilenstein bleibt in Wallet')
  check('Treuebonus' in req('/business/1',c=o)[1] and 'Pausiert' in req('/business/1',c=o)[1],'Betreiberliste zeigt pausierte Regeln')
  check('Keine rückwirkende Vergabe' in setstate(aid,1)[1] and effects()==before,'Fortsetzen vergibt nichts rückwirkend')
  oldrev=sql('echo achievement_revision(db()->query(\"SELECT * FROM achievements WHERE id=%d\")->fetch());'%aid);setstate(aid,0);saved=state()
  check('inzwischen geändert' in setstate(aid,1,{'revision':oldrev})[1] and state()==saved,'Veralteter Statusschalter überschreibt nicht')
  check('Ungültiger Aktivstatus' in setstate(aid,'2')[1] and state()==saved,'Nur Status null oder eins erlaubt')
  paused=int(sql("db()->exec(\"INSERT INTO achievements(business_id,name,metric,threshold,reward_points,active)VALUES(1,'Pause ohne Vergabe','points_lifetime',1,23,0)\");echo db()->lastInsertId();"))
  before=effects();sql('check_achievements(%d,1);'%mid)
  check(not sql('echo (int)achievement_has_awards(%d);'%paused)=='1' and effects()==before,'Pausierte passende Regel löst nichts aus')
  check('Pause ohne Vergabe' not in req('/wallet',c=benc)[1],'Nicht erreichte pausierte Regel nicht als Ziel in Wallet')
  setstate(paused,1);check(effects()==before,'Fortsetzen passender Regel ohne automatische Freischaltung')
  sql('check_achievements(%d,1);'%mid);check(sql('echo (int)achievement_has_awards(%d);'%paused)=='1','Nächste unterstützte Prüfung vergibt fortgesetzte Regel')
  # Pause and check compete for same lock; once paused, subsequent checks never award.
  race=int(sql("db()->exec(\"INSERT INTO achievements(business_id,name,metric,threshold,reward_points)VALUES(1,'Pausenrennen','points_lifetime',1,29)\");echo db()->lastInsertId();"))
  revision=sql('echo achievement_revision(db()->query(\"SELECT * FROM achievements WHERE id=%d\")->fetch());'%race)
  jobs=["achievement_change(1,%d,['active'=>'0','revision'=>'%s'],'state');"%(race,revision),'check_achievements(%d,1);'%mid]
  workers=[subprocess.Popen([PHP,'-r',"require 'public/index.php';"+c],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for c in jobs];[w.communicate(timeout=15) for w in workers]
  check(all(w.returncode==0 for w in workers) and sql('echo db()->query(\"SELECT active FROM achievements WHERE id=%d\")->fetchColumn();'%race)=='0','Pause und Vergabe ohne Wettlauffehler')
  before=effects();sql('check_achievements(%d,1);'%mid);check(effects()==before,'Nach Pause kein späterer Bonus bei Wiederholung')
  check(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='achievement.state'\")->fetchColumn();")=='5','Statusaktionen mit ID-Audit protokolliert')
  # Legacy creation now uses the editor's strict value boundary and own locked checks.
  def count():return int(sql("echo db()->query('SELECT COUNT(*) FROM achievements')->fetchColumn();"))
  def create(extra=None,token=True,c=None):
   c=c or o;d={'business_id':'1','name':'Neue Regel','metric':'visits_total','threshold':'10','reward_points':'0'}
   if token:d['csrf']=csrf(c,'/business/1' if c is o else '/dashboard')
   if extra:d.update(extra)
   return req('/achievement/create',d,c)
  before=effects();total=count()
  invalid=[{'name':''},{'name':'x'*81},{'name':'Test\x00Name'},{'name':'Test\nName'},{'metric':'not_valid'},{'threshold':'0'},{'threshold':'-1'},{'threshold':'100001'},{'threshold':'1.5'},{'threshold':'1e3'},{'threshold':' 1'},{'reward_points':'-1'},{'reward_points':'100001'},{'reward_points':'2.9'},{'reward_points':'99999999999999999999999999'},{'metric[]':'visits_total','metric':None}]
  for bad in invalid:
   if bad.get('metric','') is None:
    d={'csrf':csrf(o,'/business/1'),'business_id':1,'name':'Array','metric[]':'visits_total','threshold':1,'reward_points':0};res=req('/achievement/create',d,o)
   else:res=create(bad)
   check(res[0]==200 and count()==total and effects()==before,'Anlage lehnt ungültige Regelfelder ohne Nebenwirkung ab')
  for key in ['name','threshold','reward_points']:
   d={'csrf':csrf(o,'/business/1'),'business_id':1,'name':'Array','metric':'visits_total','threshold':1,'reward_points':0};d[key+'[]']=d.pop(key)
   check(req('/achievement/create',d,o)[0]==200 and count()==total and effects()==before,'Anlage lehnt Arrayfeld ohne Serverfehler ab')
  check(create(token=False)[0]==403 and count()==total,'Anlage benötigt CSRF')
  check(create({'business_id':999999})[0]==404 and count()==total,'Unbekanntes Anlageziel abgelehnt')
  check(create(c=outsider)[0]==404 and count()==total,'Fremder Betreiber kann keinen Meilenstein anlegen')
  check('/login' in req('/achievement/create',{'business_id':1},mk())[2] or req('/achievement/create',{'business_id':1},mk())[0]==403,'Gast kann keinen Meilenstein anlegen')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  check(create()[0]==402 and count()==total,'Plan blockiert Anlage')
  check(sql("try{achievement_create(1,1,['name'=>'Domain','metric'=>'visits_total','threshold'=>1,'reward_points'=>0]);}catch(InvalidArgumentException $e){echo 'blocked';}")=='blocked' and count()==total,'Direkte Anlagefunktion prüft Plan ebenfalls')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  check(sql("try{achievement_create(2,1,['name'=>'Domain','metric'=>'visits_total','threshold'=>1,'reward_points'=>0]);}catch(InvalidArgumentException $e){echo 'blocked';}")=='blocked' and count()==total,'Direkte Anlagefunktion prüft Eigentum ebenfalls')
  check(sql("try{achievement_fields(['name'=>chr(255),'metric'=>'visits_total','threshold'=>1,'reward_points'=>0]);}catch(InvalidArgumentException $e){echo 'blocked';}")=='blocked','Ungültiges UTF-8 abgelehnt')
  check(sql("try{achievement_fields(['name'=>'Domain','metric'=>'visits_total','threshold'=>1.5,'reward_points'=>0]);}catch(InvalidArgumentException $e){echo 'blocked';}")=='blocked','Direkte Floatwerte werden nicht still gekürzt')
  for fields in [{'name':'  '+'ä'*80+'  ','threshold':'100000','reward_points':'100000'},{'name':'<script>nur Text</script>','threshold':'1','reward_points':'0'}]:
   res=create(fields);total+=1;last=json.loads(sql("echo json_encode(db()->query('SELECT * FROM achievements ORDER BY id DESC LIMIT 1')->fetch());"))
   check(res[0]==200 and count()==total and last['name']==fields['name'].strip() and last['threshold']==int(fields['threshold']) and last['reward_points']==int(fields['reward_points']) and effects()==before,'Anlage speichert exakte Grenzen ohne Punkte oder Inbox')
  check('<script>nur Text</script>' not in req('/business/1',c=o)[1] and '&lt;script&gt;'+ 'nur Text' in req('/business/1',c=o)[1],'Angelegter Name wird escaped')
  check(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='achievement.create'\")->fetchColumn();")=='2','Erfolgreiche Anlagen mit ID-Audit ohne Regeltexte')
  sql("db()->exec(\"CREATE TRIGGER fail_achievement_audit BEFORE INSERT ON audit_logs WHEN NEW.action='achievement.create' BEGIN SELECT RAISE(ABORT,'TEST_FAIL'); END\");")
  check(sql("try{achievement_create(1,1,['name'=>'Rollback','metric'=>'visits_total','threshold'=>1,'reward_points'=>0]);}catch(Throwable $e){echo 'rolled';}")=='rolled' and count()==total and effects()==before,'Auditfehler rollt Anlage vollständig zurück')
  sql("db()->exec('DROP TRIGGER fail_achievement_audit');")
  # New untouched draft for UI.
  ui=int(sql("db()->exec(\"INSERT INTO achievements(business_id,name,metric,threshold,reward_points)VALUES(1,'Browser','visits_total',100000,0)\");echo db()->lastInsertId();"))
  subprocess.run(['node',str(R/'tests/achievement-edit-ui.cjs'),str(ui),str(aid)],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Meilenstein-Editor-Prüfungen bestanden')
