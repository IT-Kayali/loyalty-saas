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
  # New untouched draft for UI.
  ui=int(sql("db()->exec(\"INSERT INTO achievements(business_id,name,metric,threshold,reward_points)VALUES(1,'Browser','visits_total',100000,0)\");echo db()->lastInsertId();"))
  subprocess.run(['node',str(R/'tests/achievement-edit-ui.cjs'),str(ui),str(aid)],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Meilenstein-Editor-Prüfungen bestanden')
