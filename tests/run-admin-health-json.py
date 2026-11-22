#!/usr/bin/env python3
"""Session-authenticated private queue JSON, exact scope, failures and unchanged UI."""
import http.cookiejar,json,os,pathlib,re,shutil,subprocess,tempfile,time,urllib.error,urllib.parse,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(v,label):
 global n
 assert v,label
 n+=1;print('PASS',label,flush=True)

with tempfile.TemporaryDirectory(prefix='loyalty-admin-health-json-') as temp:
 A=pathlib.Path(temp)
 for folder in ['app','public']:shutil.copytree(R/folder,A/folder)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 config="<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['admin@test.local'];return $c;"
 cfg=A/'config.php';cfg.write_text(config);subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 shutil.copy(R/'tests/admin-health-fixture.php',A/'fixture.php')
 subprocess.run([PHP,str(R/'tests/admin-health-json.php')],cwd=A,check=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 sql("require 'fixture.php';health_fixture(time()-3600);db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Admin','admin@test.local',password_hash('adminpass1',PASSWORD_DEFAULT)]);")
 # Keep live HTTP reads far from clock-dependent lock/age boundaries. Exact boundaries remain in the injected-clock domain tests.
 check(sql("$t=time();$a=admin_queue_snapshot($t);$b=admin_queue_snapshot($t+120);echo $a['mail']===$b['mail']&&$a['webhooks']===$b['webhooks']?'stable':'crosses-boundary';")=='stable','HTTP fixture remains stable across a two-minute clock window')
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None,method=None,headers=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data,method=method,headers=headers or {}),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,pw):
  c=client();assert req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':pw})[2].endswith('/dashboard');return c
 def doc(c= None,suffix='',**kw):
  st,body,url,h=req('/admin/health/json'+suffix,c or admin,**kw)
  check(h.get('Content-Type')=='application/json; charset=utf-8' and h.get('Cache-Control')=='private, no-store' and h.get('X-Content-Type-Options')=='nosniff','JSON response privacy and content headers')
  check('Access-Control-Allow-Origin' not in h and 'Content-Disposition' not in h,'no cross-origin grant or unsolicited attachment')
  return st,json.loads(body),url,h
 def state():return sql("require 'fixture.php';echo health_state();")
 with (A/'server.log').open('w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(60):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login',timeout=1);break
    except OSError:time.sleep(.05)
   admin=login('admin@test.local','adminpass1');owner=login('demo@loyalty.local','demo1234');member=client();staff=client()
   check(req('/member/login',member,{'csrf':csrf(member,'/member/login'),'email':'aylin@example.com','password':'kunde1234'})[2].endswith('/wallet'),'member fixture actually signed in')
   check(req('/staff/login',staff,{'csrf':csrf(staff,'/staff/login'),'business_id':1,'name':'Lena','pin':'4321'})[2].endswith('/staff'),'staff fixture actually signed in')
   for c,code,error in [(client(),401,'authentication_required'),(member,401,'authentication_required'),(staff,401,'authentication_required'),(owner,403,'platform_access_required')]:
    st,d,url,_=doc(c);check(st==code and d=={'success':False,'error':error} and url.endswith('/admin/health/json'),'unprivileged request has stable JSON error, no login redirect or aggregate')
   st,d,_,_=doc(client(),headers={'Authorization':'Bearer PRIVATE-UNRELATED-API-TOKEN'});check(st==401 and 'data' not in d,'an API bearer without the admin session grants no monitoring access')
   st,d,_,_=doc();v=d['data'];check(st==200 and d['success'] is True and v['scope']=='retained_delivery_queues' and v['schema_version']==1,'configured session admin gets versioned local document')
   check(v['mail']['total']==14 and v['webhooks']['total']==13,'JSON contains exact retained totals')
   check('PRIVATE-' not in json.dumps(d) and not any(k in v for k in ['checks','summary','status']),'no private columns or fabricated overall health status')
   check(v['not_checked']==['worker_heartbeat','cron_execution','smtp_connectivity','inbox_delivery','wallet_delivery','device_display','environment','migrations','storage','billing'],'unsupported checks travel with machine-readable result')
   body=req('/admin/health',admin)[1];html_values={k:int(value) for k,value in re.findall(r'data-health-key="([^"]+)">(\d+)</dd>',body)}
   check(all(html_values[g+'.'+k]==value for g in ['mail','webhooks'] for k,value in v[g].items() if isinstance(value,int)),'HTML and JSON report identical retained aggregate values')
   check('href="/admin/health/json"' in body,'HTML offers explicit JSON link, no automatic polling')
   before=state()
   for suffix in ['', '?run_due=1&retry=1&action=webhook_ack', '?now=0&business_id=2', '?format=csv&callback=PRIVATE-CALLBACK', '?action%5B%5D=webhook_retry&lang%5B%5D=en']:
    st,d,_,_=doc(suffix=suffix);check(st==200 and d['data']['mail']==v['mail'] and d['data']['webhooks']==v['webhooks'],'query parameters cannot mutate, tenant-filter, change clock or wrap JSONP')
   st,d,_,h=doc(data={'csrf':csrf(admin,'/admin'),'action':'webhook_run_due'});check(st==405 and h['Allow']=='GET' and d=={'success':False,'error':'method_not_allowed'},'valid-CSRF POST cannot dispatch an admin action')
   st,body,_,_=req('/admin/health/json',admin,data={'action':'webhook_run_due'});check(st==403 and 'Sicherheitsprüfung fehlgeschlagen' in body,'central CSRF remains intact and may produce HTML before JSON route')
   for method in ['PUT','PATCH','DELETE','OPTIONS']:
    st,d,_,h=doc(method=method);check(st==405 and h['Allow']=='GET' and d=={'success':False,'error':'method_not_allowed'},method+' is read-only rejected')
   st,body,_,h=req('/admin/health/json',admin,method='HEAD');check(st==405 and body=='' and h['Allow']=='GET' and h['Cache-Control']=='private, no-store','HEAD has standard empty-body method rejection')
   check(state()==before,'all GETs, ignored action parameters and rejected methods leave queues, audits, settings and devices unchanged')
   cfg.write_text(config.replace("['admin@test.local']","[]"));st,d,_,_=doc();check(st==403 and 'data' not in d,'allowlist removal immediately revokes JSON access');cfg.write_text(config)
   sql("db()->exec(\"UPDATE users SET session_version=session_version+1 WHERE email='admin@test.local'\");");st,d,_,_=doc();check(st==401 and 'data' not in d,'session version revocation immediately applies')
   admin=login('admin@test.local','adminpass1');cfg.write_text(config.replace("['admin@test.local']","['ADMIN@TEST.LOCAL']"));check(doc()[0]==200,'same case-insensitive configured admin semantics as HTML')
   cfg.write_text(config.replace('return $c;',"$c['stripe']['enforce_limits']=true;return $c;"));check(doc()[0]==200,'platform monitoring independent of tenant plan');cfg.write_text(config)
   cfg.write_text(config.replace('return $c;',"$c['smtp']['enabled']=true;$c['smtp']['host']='smtp.invalid';$c['smtp']['from']='noreply@platform.invalid';$c['smtp']['password']='PRIVATE-SMTP-SECRET';return $c;"));st,d,_,_=doc();check(st==200 and d['data']['smtp_configured'] is True and 'PRIVATE-' not in json.dumps(d),'configuration-only flag does not connect to invalid SMTP or expose secret');cfg.write_text(config)
   # Break only a diagnostic query in the copied fixture. No production failure injection hook.
   sql("db()->exec('ALTER TABLE mail_outbox RENAME COLUMN status TO private_broken_status');")
   st,d,_,_=doc();check(st==503 and d=={'success':False,'error':'snapshot_unavailable'},'query failure is generic JSON without partial counts, SQL, paths or exception text')
   sql("db()->exec('ALTER TABLE mail_outbox RENAME COLUMN private_broken_status TO status');")
   check(doc()[0]==200,'subsequent valid snapshot recovers after a failed transaction')
   browser_before=json.loads(state())
   subprocess.run(['node',str(R/'tests/admin-health-json-ui.cjs')],cwd=R,check=True)
   browser_after=json.loads(state());old_audit=browser_before.pop('audit_logs');new_audit=browser_after.pop('audit_logs')
   check(browser_after==browser_before and new_audit[:len(old_audit)]==old_audit and len(new_audit)==len(old_audit)+3 and all(row['action']=='auth.login' for row in new_audit[len(old_audit):]),'browser reads leave all fixture state unchanged apart from exactly three expected login audit rows')
   check('PRIVATE-' not in (A/'server.log').read_text() and 'PHP Warning' not in (A/'server.log').read_text() and 'PHP Fatal' not in (A/'server.log').read_text(),'no private fixture data or PHP warnings leaked in request logs')
  finally:server.terminate();server.wait(timeout=10)
 print(n,'admin health JSON HTTP assertions passed',flush=True)
 print('No provider connection, worker run, deployment or monitoring subscription.',flush=True)
