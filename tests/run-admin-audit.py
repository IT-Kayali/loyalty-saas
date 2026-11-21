#!/usr/bin/env python3
"""Read-only admin audit index in a disposable installation. No outside services."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,html,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-admin-audit-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['admin@test.local'];return $c;")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(c):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+c],cwd=A,text=True).strip()
 sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Admin','admin@test.local',password_hash('adminpass1',PASSWORD_DEFAULT)]);$q=db()->prepare('INSERT INTO audit_logs(user_id,action,details,created_at)VALUES(?,?,?,?)');for($i=0;$i<205;$i++)$q->execute([1,'fixture.read','PRIVATE-DETAIL-MUST-NOT-APPEAR','2026-11-01 12:00:00']);$q->execute([1,'fixture.read.extra','HIDDEN','2026-11-01 12:00:00']);$q->execute([1,'fixture.boundary','HIDDEN','2026-10-31 23:59:59']);$q->execute([1,'fixture.boundary','HIDDEN','2026-11-01 00:00:00']);$q->execute([1,'fixture.boundary','HIDDEN','2026-11-01 23:59:59']);$q->execute([1,'fixture.boundary','HIDDEN','2026-11-02 00:00:00']);$q->execute([null,'fixture.deleted','HIDDEN','2026-11-01 12:00:00']);db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Literal','literal_percent%_@local.invalid','unused']);$id=(int)db()->lastInsertId();$q->execute([$id,'fixture.literal','HIDDEN','2026-11-01 12:00:00']);$q->execute([1,'fixture.literal','HIDDEN','2026-11-01 12:00:00']);$q->execute([1,'fixture.'.str_repeat('x',100),'HIDDEN','2026-11-01 12:00:00']);")
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,pw):
  c=client();req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':pw});return c
 ids=lambda h:[int(i) for i in re.findall(r'data-audit-id="(\d+)"',h)]
 with open(A/'server.log','w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
    except OSError:time.sleep(.1)
   admin=login('admin@test.local','adminpass1');owner=login('demo@loyalty.local','demo1234')
   guest=client();member=client();req('/member/login',member,{'csrf':csrf(member,'/member/login'),'email':'aylin@example.com','password':'kunde1234'})
   check('/login' in req('/admin/audit',guest)[2],'guest must sign in')
   check(req('/admin/audit',owner)[0]==403,'ordinary owner cannot read platform log')
   check('/login' in req('/admin/audit',member)[2],'member cannot read admin log')
   st,h,_,headers=req('/admin/audit',admin);check(st==200 and len(ids(h))==100,'admin reads 100 newest rows')
   check(ids(h)==sorted(ids(h),reverse=True),'rows use stable descending ID order')
   check(headers['Cache-Control']=='private, no-store','audit page private no-store')
   check('PRIVATE-DETAIL-MUST-NOT-APPEAR' not in h and 'HIDDEN' not in h,'stored detail payloads never rendered')
   check('keine lückenlose' in h.lower() and 'heutige E-Mail' in h,'limits and identity caveat visible')
   check('href="/admin/audit"' in req('/admin',admin)[1],'existing dashboard links full log')
   base='/admin/audit?'+urllib.parse.urlencode(dict(action='fixture.read',account='demo@loyalty.local',from_ignored='unused',**{'from':'2026-11-01','to':'2026-11-01'}))
   first=req(base,admin)[1];nexturl=html.unescape(re.search(r'href="([^"]+)">Ältere 100',first).group(1));q=urllib.parse.parse_qs(urllib.parse.urlparse(nexturl).query)
   check(q['action']==['fixture.read'] and q['account']==['demo@loyalty.local'] and q['from']==['2026-11-01'] and q['to']==['2026-11-01'],'older link preserves all filters')
   second=req(nexturl,admin)[1];thirdurl=html.unescape(re.search(r'href="([^"]+)">Ältere 100',second).group(1));third=req(thirdurl,admin)[1]
   a,b,c=ids(first),ids(second),ids(third)
   check(len(a)==100 and len(b)==100 and len(c)==5,'filtered pages reach all 205 matching rows')
   check(len(set(a+b+c))==205 and max(b)<min(a) and max(c)<min(b),'older pages have no overlap or skipped matching rows')
   check('Ältere 100' not in third,'last page ends pagination')
   check('fixture.read.extra' not in first+second+third,'action filter is exact not prefix')
   # Insert after first read must not disturb older cursor pages.
   sql("audit_log(1,'fixture.read',['private'=>'new']);")
   check(ids(req(nexturl,admin)[1])==b,'new inserts do not shift older cursor page')
   check(len(ids(req('/admin/audit?action=fixture.boundary&from=2026-11-01&to=2026-11-01',admin)[1]))==2,'UTC inclusive dates include both boundary seconds only')
   check(len(ids(req('/admin/audit?action=fixture.boundary&from=2026-11-02',admin)[1]))==1,'optional from bound works')
   check(len(ids(req('/admin/audit?action=fixture.boundary&to=2026-10-31',admin)[1]))==1,'optional to bound works')
   check('Konto nicht mehr vorhanden' in req('/admin/audit?action=fixture.deleted',admin)[1],'removed account is explicitly unknown')
   check(len(ids(req('/admin/audit?'+urllib.parse.urlencode({'account':'%_','action':'fixture.literal'}),admin)[1]))==1,'wildcard characters in account search are literal')
   check(len(ids(req('/admin/audit?account=DEMO%40LOYALTY.LOCAL&action=fixture.literal',admin)[1]))==1,'account search works case insensitively')
   check('Keine passenden Einträge.' in req('/admin/audit?action=fixture.missing',admin)[1],'empty result is explicit')
   for bad in ['from=2026-02-30','to=2026-13-01','from=2026-11-02&to=2026-11-01','before=0','before=-1','before=1.2','before=999999999999999999999999','action=%3Cscript%3E','action='+('a'*121),'account='+('a'*255),'account=%00','from%5B%5D=2026-11-01','account%5B%5D=x']:
    st,h,_,_=req('/admin/audit?'+bad,admin);check(st==422 and '<script>' not in h,'invalid filter safely rejected')
   before=sql("echo json_encode([db()->query('SELECT * FROM audit_logs')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM support_tickets')->fetchAll()]);")
   req(base,admin);req(nexturl,admin);req('/admin/audit?before=1',admin)
   check(before==sql("echo json_encode([db()->query('SELECT * FROM audit_logs')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM support_tickets')->fetchAll()]);"),'reading filters and cursors changes no rows or notifications')
   check(req('/admin/audit',admin,{'csrf':csrf(admin,'/admin'),'action':'settings','platform_name':'Changed'})[0]==405,'valid-CSRF POST has no mutation route')
   check(sql("echo platform_setting('platform_name','Loyalty');")=='Loyalty','POST cannot alter platform settings')
   config=A/'config.php';old=config.read_text();config.write_text(old.replace('return $c;',"$c['stripe']['enforce_limits']=true;return $c;"))
   check(req('/admin/audit',admin)[0]==200,'platform read not blocked by user paid plan')
   config.write_text(old)
   subprocess.run(['node',str(R/'tests/admin-audit-ui.cjs')],cwd=R,check=True)
  finally:server.terminate();server.wait()
print(n,'admin audit HTTP assertions passed')
