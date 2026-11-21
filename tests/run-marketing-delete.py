#!/usr/bin/env python3
"""Sicheres Segment-/Entwurfslöschen, Historie, Konflikte und Konkurrenz. Wegwerf-Server."""
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
  def seg(bid=1):return int(sql("db()->exec(\"INSERT INTO segments(business_id,name,rule,param)VALUES(%d,'Testsegment','all',0)\");echo db()->lastInsertId();"%bid))
  def camp(sid):return int(sql("db()->exec(\"INSERT INTO campaigns(business_id,segment_id,name,subject,body)SELECT business_id,id,'Testkampagne','Betreff','Text' FROM segments WHERE id=%d\");echo db()->lastInsertId();"%sid))
  def snap():return sql("echo json_encode([db()->query('SELECT * FROM segments')->fetchAll(),db()->query('SELECT * FROM campaigns')->fetchAll(),db()->query('SELECT * FROM campaign_sends')->fetchAll(),db()->query('SELECT * FROM member_messages')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll()]);")
  def post(kind,id,extra=None,client=o):
   d={'business_id':1,kind+'_id':id,'csrf':csrf(client,'/business/1')}
   if extra:d.update(extra)
   return req('/'+kind+'/delete',d,client)
  def revision(kind,id):return sql("echo %s_revision(db()->query('SELECT * FROM %ss WHERE id=%d')->fetch());"%(kind,kind,id))
  for kind in ['segment','campaign']:
   sid=seg();id=sid if kind=='segment' else camp(sid);before=snap()
   st,h,_=post(kind,id);check(st==200 and 'Endgültig löschen' in h and snap()==before,kind+': erster POST ist reine Bestätigungsansicht')
   token=re.search(r'name="revision" value="([a-f0-9]+)"',h).group(1)
   check(req('/'+kind+'/delete',{'business_id':1,kind+'_id':id,'confirm_delete':1,'revision':token},o)[0]==403 and snap()==before,kind+': CSRF vor endgültiger Löschung')
   sql("db()->exec(\"UPDATE %ss SET name='Geändert' WHERE id=%d\");"%(kind,id));before=snap()
   check('inzwischen geändert' in post(kind,id,{'confirm_delete':1,'revision':token})[1] and snap()==before,kind+': veraltete Prüfung blockiert')
   token=revision(kind,id);post(kind,id,{'confirm_delete':1,'revision':token})
   check(sql("echo db()->query('SELECT COUNT(*) FROM %ss WHERE id=%d')->fetchColumn();"%(kind,id))=='0',kind+': bestätigte Löschung entfernt nur zulässiges Objekt')
   check(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='%s.delete'\")->fetchColumn();"%kind)=='1',kind+': Audit ohne Inhalte')
  sid=seg();cid=camp(sid);before=snap()
  check('verknüpft' in post('segment',sid)[1] and snap()==before,'Segment mit Entwurf nicht per Kaskade löschbar')
  check('verknüpft' in post('segment',sid,{'confirm_delete':1,'revision':revision('segment',sid)})[1] and snap()==before,'Direktbestätigung umgeht Verknüpfung nicht')
  stale=revision('campaign',cid);sql('campaign_dispatch(1,1,%d);'%cid);before=snap()
  check('Nicht löschbar' in post('campaign',cid,{'confirm_delete':1,'revision':stale})[1] and snap()==before,'Versand seit Löschprüfung schützt Text und Historie')
  check('verknüpft' in post('segment',sid)[1] and snap()==before,'Segment mit abgeschlossener Kampagne bleibt erhalten')
  for typ in ['send','outbox']:
   partial=camp(sid)
   if typ=='send':sql('db()->exec(\"INSERT INTO campaign_sends(campaign_id,member_id)SELECT %d,id FROM members WHERE business_id=1 LIMIT 1\");'%partial)
   else:sql("$q=db()->prepare('INSERT INTO mail_outbox(business_id,recipient,subject,body,category,dedup_key)VALUES(?,?,?,?,?,?)');$q->execute([1,'local@example.test','Alt','Alt','marketing','campaign:%d:999']);"%partial)
   before=snap();check('Nicht löschbar' in post('campaign',partial)[1] and snap()==before,'Historischer Teilversand '+typ+' bleibt erhalten')
  unlinked=seg();token=revision('segment',unlinked);camp(unlinked);before=snap()
  check('verknüpft' in post('segment',unlinked,{'confirm_delete':1,'revision':token})[1] and snap()==before,'Neue Verknüpfung nach Löschprüfung blockiert')
  foreign=seg(2);check(post('segment',foreign)[0]==404,'Fremdes Unternehmenssegment nicht verfügbar')
  for kind,id in [('segment',sid),('campaign',cid)]:
   check('/login' in req('/'+kind+'/delete',{'business_id':1,kind+'_id':id},mk())[2] or req('/'+kind+'/delete',{},mk())[0]==403,kind+': Gast ohne Wirkung')
  fresh=seg();before=snap();(A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  check(post('segment',fresh)[0]==402 and snap()==before,'Plan blockiert Löschung')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  # True simultaneous dispatch/delete: only one mutation can win.
  race=camp(sid);rev=revision('campaign',race)
  jobs=["try{marketing_delete(1,1,%d,'campaign','%s');echo 'deleted';}catch(InvalidArgumentException $e){echo 'blocked';}"%(race,rev),"try{campaign_dispatch(1,1,%d);echo 'sent';}catch(InvalidArgumentException $e){echo 'blocked';}"%race]
  workers=[subprocess.Popen([PHP,'-r',"require 'public/index.php';"+cmd],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for cmd in jobs];results=[w.communicate(timeout=15) for w in workers]
  check(all(w.returncode==0 for w in workers),'Konkurrierende Prozesse ohne DB-Fehler')
  check([x[0] for x in results] in [['deleted','blocked'],['blocked','sent']],'Löschen/Versand: genau eine Wirkung')
  ui=seg();draft=camp(ui);subprocess.run(['node',str(R/'tests/marketing-delete-ui.cjs'),str(ui),str(draft),str(sid)],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Marketing-Löschprüfungen bestanden')
