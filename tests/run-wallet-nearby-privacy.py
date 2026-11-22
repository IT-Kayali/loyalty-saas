#!/usr/bin/env python3
"""Separate proximity approval, notice settings, export and local deletion. Never contacts providers."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-nearby-privacy-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 mid=int(sql("echo db()->query(\"SELECT id FROM members WHERE email='aylin@example.com'\")->fetchColumn();"))
 ben=int(sql("echo db()->query(\"SELECT id FROM members WHERE email='ben@example.com'\")->fetchColumn();"))
 second=int(sql("db()->exec(\"UPDATE members SET email='AYLIN@example.com' WHERE business_id=2 AND lower(email)='aylin@example.com'\");echo db()->query(\"SELECT id FROM members WHERE business_id=2 AND lower(email)='aylin@example.com'\")->fetchColumn();"))
 sql("db()->exec(\"INSERT INTO locations(business_id,name,wallet_latitude,wallet_longitude,wallet_nearby)VALUES(1,'Store',51.5,7.25,1)\");db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Foreign','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);")
 def row(id=mid):return json.loads(sql('echo json_encode(db()->query("SELECT * FROM members WHERE id=%d")->fetch());'%id))
 def payload():return json.loads(sql("$m=pass_member(db()->query(\"SELECT code FROM members WHERE id=%d\")->fetchColumn());echo json_encode([apple_pass_json($m)['locations'],google_pass_payload($m)['object']['merchantLocations']]);"%mid))
 def core():return sql("echo json_encode([db()->query('SELECT id,name,email,marketing_consent,consent_at,consent_source,birthday,password_hash,session_version FROM members ORDER BY id')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM pass_devices')->fetchAll()]);")
 def mk():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode('utf-8','replace'),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c)[1]).group(1)
 def login(e,pw,p='/login'):
  c=mk();req(p,c,{'csrf':csrf(c,p),'email':e,'password':pw});return c
 log=open(A/'server.log','w');s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  o=login('demo@loyalty.local','demo1234');f=login('foreign@test.local','foreignpass1');m=login('aylin@example.com','kunde1234','/member/login');P='/member/wallet-nearby';O='/business/1/wallet-nearby-notice'
  def settings(changes=None,token=True):
   html=req(O,o)[1];d={'controller':'Demo GmbH, Beispielweg 1','contact':'privacy@example.test','privacy_url':'https://example.test/privacy','extra':'Nur die freiwillige Nähe-Funktion.','revision':re.search(r'name="revision" value="([a-f0-9]+)"',html).group(1)}
   if token:d['csrf']=csrf(o,O)
   if changes:d.update(changes)
   return req(O,o,d)
  def consent(on='1',id=mid,changes=None,token=True):
   html=req(P,m)[1];d={'member_id':id,'enabled':on,'confirm':'1','notice_hash':re.search(r'name="notice_hash" value="([a-f0-9]+)"',html).group(1)}
   if token:d['csrf']=csrf(m,P)
   if changes:d.update(changes)
   return req(P,m,d)
  before=core()
  check(payload()==[[],[]] and row()['wallet_nearby_consent']==0,'migration default off despite merchant selection')
  check(req(P,mk())[2].endswith('/member/login'),'guest cannot read member privacy')
  check(req(O,f)[0]==404 and req(O,m)[2].endswith('/login'),'foreign owner and member cannot read business notice editor')
  check(req('/business/999999/wallet-nearby-notice',o)[0]==404,'unknown business unavailable')
  st,html,_,headers=req(P,m);check(headers['Cache-Control']=='private, no-store' and headers['Referrer-Policy']=='no-referrer','member privacy page private/no-store/no-referrer')
  check(html.count('class="panel member-nearby"')==2 and 'Betreiberangaben fehlen' in html,'own memberships separated and missing operator shown')
  check('name="confirm" value="1" type="checkbox" checked' not in html,'affirmative checkbox never preselected')
  consent();check(row()['wallet_nearby_consent']==0,'cannot approve without completed operator notice')
  check('href="'+O+'"' in req('/organization',o)[1],'organization has business privacy editor link')
  orig=sql('echo db()->query("SELECT wallet_nearby_notice FROM businesses WHERE id=1")->fetchColumn();')
  settings(token=False);check(sql('echo db()->query("SELECT wallet_nearby_notice FROM businesses WHERE id=1")->fetchColumn();')==orig,'owner save requires CSRF')
  for d in [{'privacy_url':'javascript:alert(1)'},{'privacy_url':'http://example.test/privacy'},{'privacy_url':'https://user:password@example.test/'},{'controller':'x'*201},{'contact[]':'array','contact':None}]:
   if d.get('contact') is None and 'contact[]' in d:d['contact']='x'*301
   settings(d);check(sql('echo db()->query("SELECT wallet_nearby_notice FROM businesses WHERE id=1")->fetchColumn();')==orig,'invalid operator input rejected')
  stale=re.search(r'name="revision" value="([a-f0-9]+)"',req(O,o)[1]).group(1)
  settings();valid=sql('echo db()->query("SELECT wallet_nearby_notice FROM businesses WHERE id=1")->fetchColumn();')
  check(valid!=orig,'manual operator notice saved')
  settings({'revision':stale,'extra':'stale overwrite'});check(sql('echo db()->query("SELECT wallet_nearby_notice FROM businesses WHERE id=1")->fetchColumn();')==valid,'stale owner editor cannot overwrite')
  check(payload()==[[],[]],'operator save never opts customer in')
  check(req(P,o,{'csrf':csrf(o,O),'member_id':mid,'enabled':'1','confirm':'1','notice_hash':'forged'})[2].endswith('/member/login') and row()['wallet_nearby_consent']==0,'owner login cannot approve for customer')
  stable=valid
  for key in ['controller','contact','privacy_url','extra','revision']:
   h=req(O,o)[1];d={'controller':'Other','contact':'privacy@example.test','privacy_url':'https://example.test/privacy','extra':'Text','revision':re.search(r'name="revision" value="([a-f0-9]+)"',h).group(1),'csrf':csrf(o,O)};del d[key];d[key+'[]']='array';req(O,o,d)
   check(sql('echo db()->query("SELECT wallet_nearby_notice FROM businesses WHERE id=1")->fetchColumn();')==stable,'array operator field rejected: '+key)
  sql("db()->exec(\"CREATE TRIGGER fail_privacy_audit BEFORE INSERT ON audit_logs WHEN NEW.action='business.wallet_nearby_notice' BEGIN SELECT RAISE(ABORT,'test failure'); END\");")
  settings({'extra':'rollback me'});check(sql('echo db()->query("SELECT wallet_nearby_notice FROM businesses WHERE id=1")->fetchColumn();')==stable,'owner audit failure rolls notice back')
  sql('db()->exec("DROP TRIGGER fail_privacy_audit");')

  consent(token=False);check(row()['wallet_nearby_consent']==0,'member save requires CSRF')
  check(consent(id=ben)[0]==404 and row(ben)['wallet_nearby_consent']==0,'foreign member ID cannot be changed')
  check(consent(id=999999)[0]==404,'unknown member cannot be changed')
  for d in [{'confirm':'0'},{'notice_hash':'stale'},{'enabled':'yes'},{'confirm[]':'1','confirm':'0'}]:
   consent(changes=d);check(row()['wallet_nearby_consent']==0,'explicit current notice approval required')
  consent();r=row();check(r['wallet_nearby_consent']==1 and r['wallet_nearby_granted_at'] and r['wallet_nearby_notice_hash'] and json.loads(r['wallet_nearby_snapshot'])['core'],'approval stores time and exact notice snapshot')
  check(payload()==[[{'latitude':51.5,'longitude':7.25}]]*2,'both provider payloads include points only with current member choice')
  check(row(second)['wallet_nearby_consent']==0 and row(ben)['wallet_nearby_consent']==0,'same email other company and other customer stay off')
  e=json.loads(req('/member/export',m)[1]);record=next(x for x in e['konten'] if x['code']==r['code'])
  check(record['wallet_naehe']['bestaetigter_hinweis']['operator']['controller']=='Demo GmbH, Beispielweg 1' and record['wallet_naehe']['wirksam_fuer_neue_passdaten'],'export includes matching notice and active technical choice')
  check('marketing' in record and 'geburtstag' in record and 'push_token' not in json.dumps(e) and 'password_hash' not in json.dumps(e),'export includes other preferences without auth/device tokens')
  old_hash=r['wallet_nearby_notice_hash'];settings({'extra':'Ein ergänzter Betreiberhinweis.'});check(payload()==[[],[]] and 'Neue Bestätigung nötig' in req(P,m)[1],'changed notice stops new proximity payloads pending renewed approval')
  consent(changes={'notice_hash':old_hash});check(payload()==[[],[]],'old notice tab cannot approve changed content')
  settings();check(bool(payload()[0]),'exactly restored notice reuses non-withdrawn matching approval')
  settings({'extra':'Ein ergänzter Betreiberhinweis.'});consent();check(bool(payload()[0]),'renewed current notice works')
  sql("db()->exec('UPDATE locations SET wallet_latitude=52 WHERE business_id=1');");check(payload()==[[],[]],'changed shared coordinates require renewed notice approval')
  consent();check(payload()[0][0]['latitude']==52,'explicit approval binds new coordinates')
  consent('0',changes={'notice_hash':'old'});check(payload()==[[],[]] and row()['wallet_nearby_withdrawn_at'],'withdrawal works even from old notice without extra checkbox')
  check(sql("$m=pass_member(db()->query(\"SELECT code FROM members WHERE id=%d\")->fetchColumn());$m['wallet_nearby_consent']=1;echo json_encode(wallet_member_locations($m));"%mid)=='[]','stale member object cannot revive revoked preference')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  consent();check(row()['wallet_nearby_consent']==1,'voluntary member choice independent of owner subscription')
  consent('0');check(row()['wallet_nearby_consent']==0,'withdrawal possible with inactive owner subscription')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  check(core()==before,'privacy settings leave balances, journals, email consent, login and providers untouched')
  settings({'extra':'<script>alert(1)</script>'});check('&lt;script&gt;' in req(P,m)[1] and '<script>alert(1)</script>' not in req(P,m)[1],'operator free text escaped in customer view')
  settings();subprocess.run(['node',str(R/'tests/wallet-nearby-privacy-ui.cjs'),str(mid)],cwd=R,check=True)
  # A local deletion must remove registrations, not pretend to revoke installed copies.
  sql("$q=db()->prepare('INSERT INTO pass_devices(device_library_id,pass_type_id,serial,push_token)VALUES(?,?,?,?)');foreach(db()->query('SELECT id,code FROM members')->fetchAll() as $x)$q->execute(['device'.$x['id'],'pass.test',$x['code'],'test-token']);")
  devices=int(sql('echo db()->query("SELECT COUNT(*) FROM pass_devices")->fetchColumn();'))
  sql("db()->exec(\"CREATE TRIGGER fail_member_delete BEFORE DELETE ON members BEGIN SELECT RAISE(ABORT,'test failure');END\");")
  req('/member/delete',m,{'csrf':csrf(m,P)});check(int(sql('echo db()->query("SELECT COUNT(*) FROM pass_devices")->fetchColumn();'))==devices and row(),'delete failure rolls local registrations back')
  sql('db()->exec("DROP TRIGGER fail_member_delete");')
  req('/member/delete',m,{'csrf':csrf(m,P)})
  check(sql("echo db()->query(\"SELECT COUNT(*) FROM members WHERE lower(email)='aylin@example.com'\")->fetchColumn();")=='0','delete removes all own case-insensitive memberships')
  check(int(sql('echo db()->query("SELECT COUNT(*) FROM pass_devices")->fetchColumn();'))==devices-2,'delete removes only own Apple device registrations')
  check(bool(row(ben)) and req(P,m)[2].endswith('/member/login'),'foreign membership survives and deleted session has no access')
 finally:s.terminate();s.wait();log.close()
print(n,'wallet proximity privacy HTTP/domain assertions passed')
