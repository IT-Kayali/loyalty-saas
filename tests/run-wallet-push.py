#!/usr/bin/env python3
"""Optional Wallet issuer-message choices and editable templates. No provider requests."""
import base64,tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-wallet-push-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 mid=int(sql("echo db()->query(\"SELECT id FROM members WHERE business_id=1 AND email='aylin@example.com'\")->fetchColumn();"));ben=int(sql("echo db()->query(\"SELECT id FROM members WHERE business_id=1 AND email='ben@example.com'\")->fetchColumn();"))
 sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Foreign','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);")
 def choice(p='apple',id=mid):return json.loads(sql("echo json_encode(wallet_push_choice(%d,'%s'));"%(id,p)))
 def allowed(p='apple',id=mid):return sql("echo wallet_push_allowed(%d,'%s')?'yes':'no';"%(id,p))=='yes'
 def core():return sql("echo json_encode([db()->query('SELECT * FROM members')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM pass_devices')->fetchAll()]);")
 def templates():return sql('echo db()->query("SELECT wallet_push_settings FROM businesses WHERE id=1")->fetchColumn();')
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
  o=login('demo@loyalty.local','demo1234');f=login('foreign@test.local','foreignpass1');m=login('aylin@example.com','kunde1234','/member/login');P='/member/wallet-push';O='/business/1/wallet-push'
  def settings(changes=None,token=True):
   h=req(O,o)[1];d={'controller':'Demo GmbH, Beispielweg 1','contact':'privacy@example.test','privacy_url':'https://example.test/privacy','extra':'Nur wichtige Änderungen zu deiner Kundenkarte.','apple_text':'Heute geänderte Einlösebedingungen. Bitte prüfe deine Karte.','google_header':'Geänderte Einlösebedingungen','google_body':'Heute gelten andere Einlösebedingungen. Details findest du auf deiner Karte.','revision':re.search(r'name="revision" value="([a-f0-9]+)"',h).group(1)}
   if token:d['csrf']=csrf(o,O)
   if changes:d.update(changes)
   return req(O,o,d)
  def consent_data(p='apple',id=mid):
   notice=json.loads(sql("echo json_encode(wallet_push_notice(1,'%s'));"%p));digest=sql("echo wallet_push_hash(wallet_push_notice(1,'%s'));"%p)
   return {'csrf':csrf(m,P),'member_id':str(id),'provider':p,'enabled':'1','notice_hash':digest,'choice_revision':str(choice(p,id)['revision']),'confirm':'1'}
  def consent(p='apple',changes=None,token=True):
   d=consent_data(p)
   if not token:del d['csrf']
   if changes:d.update(changes)
   return req(P,m,d)
  sql("db()->exec(\"UPDATE members SET marketing_consent=1,wallet_nearby_consent=1 WHERE id=%d\");"%mid)
  original_name=sql('echo db()->query("SELECT name FROM businesses WHERE id=1")->fetchColumn();')
  before=core()
  check(choice()['enabled']==0 and not allowed() and not allowed('google'),'both optional providers default off despite existing marketing/nearby choices')
  check(sql('echo db()->query("SELECT COUNT(*) FROM wallet_push_choices")->fetchColumn();')=='0','read paths never create consent rows')
  check(req(P,mk())[2].endswith('/member/login') and req(O,mk())[2].endswith('/login'),'guest routes require correct authentication')
  check(req(O,f)[0]==404 and req(O,m)[2].endswith('/login'),'foreign owner and member cannot read operator settings')
  check(req('/business/999999/wallet-push',o)[0]==404,'unknown business unavailable')
  for path,c in [(O,o),(P,m)]:
   st,h,_,hh=req(path,c);check(st==200 and hh['Cache-Control']=='private, no-store' and hh['Referrer-Policy']=='no-referrer','settings are private/no-store/no-referrer')
  html=req(P,m)[1];check(html.count('class="panel member-push"')==4 and 'Betreiberangaben fehlen' in html,'two own memberships have separate Apple/Google panels')
  check('type="checkbox" name="confirm" value="1" checked' not in html,'approval never prechecked')
  consent();check(not allowed(),'missing operator information prevents approval')
  check('href="'+O+'"' in req('/organization',o)[1] and 'href="'+P+'"' in req('/wallet',m)[1],'owner and customer navigation includes message settings')
  orig=templates();settings(token=False);check(templates()==orig,'operator save requires CSRF')
  for bad in [{'privacy_url':'javascript:alert(1)'},{'privacy_url':'http://example.test/privacy'},{'privacy_url':'https://user:pass@example.test/privacy'},{'controller':'x'*201},{'apple_text':''},{'apple_text':'x'*241},{'google_header':'x'*81},{'google_body':'x'*501},{'google_body':'<b>HTML</b>'},{'google_header':'bad\x00value'}]:
   settings(bad);check(templates()==orig,'invalid operator field does not persist')
  for key in ['controller','contact','privacy_url','extra','apple_text','google_header','google_body','revision']:
   h=req(O,o)[1];d={'controller':'Demo','contact':'x','privacy_url':'https://example.test/privacy','extra':'','apple_text':'a','google_header':'b','google_body':'c','revision':re.search(r'name="revision" value="([a-f0-9]+)"',h).group(1),'csrf':csrf(o,O)};del d[key];d[key+'[]']='array';req(O,o,d);check(templates()==orig,'array rejected for '+key)
  stale=re.search(r'name="revision" value="([a-f0-9]+)"',req(O,o)[1]).group(1);settings();valid=templates();check(valid!=orig,'manual settings and separate provider texts saved')
  settings({'revision':stale,'google_body':'stale'});check(templates()==valid,'stale settings cannot overwrite current content')
  check(not allowed() and not allowed('google'),'operator save cannot opt customers in')
  consent(token=False);check(not allowed(),'customer approval requires CSRF')
  check(consent(changes={'member_id':ben})[0]==404 and not allowed(id=ben),'foreign member ID cannot be changed')
  check(consent(changes={'member_id':'999999'})[0]==404,'unknown member cannot be changed')
  check(req(P,o,{**consent_data(),'csrf':csrf(o,O)})[2].endswith('/member/login') and not allowed(),'owner login cannot approve for a customer')
  for bad in [{'provider':'other'},{'enabled':'yes'},{'confirm':'0'},{'notice_hash':'stale'},{'choice_revision':'999'},{'enabled[]':'1','enabled':'0'},{'provider[]':'apple','provider':'other'}]:
   consent(changes=bad);check(not allowed(),'invalid or stale customer approval rejected')
  replay=consent_data();consent();check(allowed() and not allowed('google') and not allowed(id=ben),'Apple approval is provider and member specific')
  c=choice();notice=json.loads(c['snapshot']);check(c['granted_at'] and c['notice_hash'] and notice['provider']=='apple' and notice['operator']['controller'].startswith('Demo'),'last approval has timestamp and full immutable core/operator snapshot')
  consent('google');check(allowed('google') and allowed(),'Google separately enabled')
  settings({'apple_text':'Nur die Vorlage wurde geändert.','google_body':'Andere Vorlage.'});check(allowed() and allowed('google'),'text-only edits keep existing purpose-specific choices')
  # Settings helper otherwise restores original operator fields, so both calls intentionally change its notice.
  settings({'extra':'Neuer Betreiberhinweis'});check(not allowed() and not allowed('google'),'changed operator notice invalidates both current choices')
  settings();check(not allowed() and not allowed('google'),'restoring an old notice does not revive approval')
  consent();req(P,m,{'csrf':csrf(m,P),'member_id':mid,'provider':'apple','enabled':'0'});check(not allowed() and allowed('google')==False and choice()['withdrawn_at'],'withdrawal immediate in fresh eligibility check')
  req(P,m,replay);check(not allowed(),'old approval form cannot revive a withdrawn choice')
  consent();check(allowed(),'fresh affirmative approval after withdrawal works')
  sql('db()->exec("UPDATE businesses SET name=\'Other name\' WHERE id=1");');check(not allowed(),'business name changes invalidate notice')
  sql("db()->prepare('UPDATE businesses SET name=? WHERE id=1')->execute([base64_decode('%s')]);"%base64.b64encode(original_name.encode()).decode());check(not allowed(),'name restoration cannot revive old approval')
  consent();check(allowed(),'current business name can be approved')
  old=templates();sql("db()->exec(\"CREATE TRIGGER fail_push_audit BEFORE INSERT ON audit_logs WHEN NEW.action='business.wallet_push_settings' BEGIN SELECT RAISE(ABORT,'test failure'); END\");");settings({'extra':'rollback'});check(templates()==old and allowed(),'audit failure rolls operator edit and notice revision back');sql('db()->exec("DROP TRIGGER fail_push_audit");')
  sql("db()->exec(\"CREATE TRIGGER fail_push_choice BEFORE UPDATE ON wallet_push_choices WHEN NEW.provider='apple' BEGIN SELECT RAISE(ABORT,'test failure'); END\");");oldc=choice();consent(changes={'enabled':'0'});check(choice()==oldc,'choice write failure rolls back');sql('db()->exec("DROP TRIGGER fail_push_choice");')
  check(sql("try{db()->exec(\"UPDATE wallet_push_choices SET enabled=1 WHERE provider='google'\");echo 'bad';}catch(Throwable $e){echo 'blocked';}")=='blocked','application SQL cannot bypass authenticated choice helper')
  member_code=sql('echo db()->query("SELECT code FROM members WHERE id=%d")->fetchColumn();'%mid)
  exp=json.loads(req('/member/export',m)[1]);ac=next(x for x in exp['konten'] if x['code']==member_code);check(ac['wallet_mitteilungen']['apple']['aktuell_zulaessig'] and ac['wallet_mitteilungen']['apple']['bestaetigter_hinweis']['provider']=='apple','member export includes purpose and current per-provider choice')
  check(core()==before,'preferences/settings never modify balances members journal mail or device registrations')
  for table in ['wallet_push_choices']:
   check('push_token' not in json.dumps(exp['konten']) and 'authenticationToken' not in json.dumps(exp['konten']),'no provider authentication material in member export')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;");consent(changes={'enabled':'0'});check(not allowed() and choice()['withdrawn_at'],'withdrawal works without an active paid plan');(A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  # Prepare friendly, fully entered fixtures for desktop/mobile checks.
  settings();subprocess.run(['node',str(R/'tests/wallet-push-ui.cjs'),str(mid)],cwd=R,check=True)
  consent();check(allowed(),'reapprove before account deletion fixture')
  req('/member/delete',m,{'csrf':csrf(m,'/member/preferences'),'confirm':'DELETE'});check(sql('echo db()->query("SELECT COUNT(*) FROM wallet_push_choices WHERE member_id=%d")->fetchColumn();'%mid)=='0','authenticated local member deletion cascades message choices')
 finally:s.terminate();s.wait();log.close()
print(n,'wallet message preferences HTTP/domain assertions passed')
