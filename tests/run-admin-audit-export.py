#!/usr/bin/env python3
"""Private platform-admin filtered audit metadata CSV, no outside services."""
import csv,html,http.cookiejar,io,json,os,pathlib,re,shutil,subprocess,tempfile,time,urllib.error,urllib.parse,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(value,label):
 global n
 assert value,label
 n+=1;print('PASS',label,flush=True)

with tempfile.TemporaryDirectory(prefix='loyalty-admin-audit-export-') as temp:
 A=pathlib.Path(temp)
 for folder in ['app','public']:shutil.copytree(R/folder,A/folder)
 for name in ['seed.php','config.example.php']:shutil.copy(R/name,A/name)
 config="<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['admin@audit.invalid'];$c['webhook_transport']=function(){file_put_contents(__DIR__.'/unexpected-send','1');throw new RuntimeException('Unexpected transport');};return $c;"
 cfg=A/'config.php';cfg.write_text(config);subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True);shutil.copy(R/'tests/admin-audit-export-fixture.php',A/'fixture.php')
 subprocess.run([PHP,str(R/'tests/admin-audit-export.php')],cwd=A,check=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 info=json.loads(sql("require 'fixture.php';echo json_encode(admin_audit_export_fixture());"));base='/admin/audit'
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None,method=None,headers=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data,method=method,headers=headers or {}),timeout=15) as response:return response.status,response.read().decode(),response.url,response.headers
  except urllib.error.HTTPError as response:return response.code,response.read().decode(),response.url,response.headers
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,password):
  c=client();assert req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':password})[2].endswith('/dashboard');return c
 def state():return json.loads(sql("require 'fixture.php';echo json_encode(admin_audit_export_state());"))
 def exported(filters=None,c=None):return req(base+'/export?'+urllib.parse.urlencode(filters or {}),c or admin)
 def rows(raw):return list(csv.DictReader(io.StringIO(raw)))
 def ids(body):return [int(value) for value in re.findall(r'data-audit-id="(\d+)"',body)]
 with (A/'server.log').open('w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(60):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login',timeout=1);break
    except OSError:time.sleep(.05)
   admin=login('admin@audit.invalid','adminpass1');owner=login('demo@loyalty.local','demo1234');member=client();staff=client();guest=client()
   check(req('/member/login',member,{'csrf':csrf(member,'/member/login'),'email':'aylin@example.com','password':'kunde1234'})[2].endswith('/wallet'),'real member session')
   check(req('/staff/login',staff,{'csrf':csrf(staff,'/staff/login'),'business_id':1,'name':'Lena','pin':'4321'})[2].endswith('/staff'),'real staff session')
   for c in [guest,member,staff]:
    for path in [base,base+'/export']:
     st,body,url,h=req(path,c);check(url.endswith('/login') and 'Content-Disposition' not in h and 'fixture.export' not in body,'non-operator session cannot download platform metadata')
   for path in [base,base+'/export']:
    st,body,_,h=req(path,owner);check(st==403 and 'Content-Disposition' not in h and 'fixture.export' not in body,'ordinary owner has no platform export rights')
   check(req(base+'/export',client(),headers={'Authorization':'Bearer not-a-session'})[2].endswith('/login'),'API-style bearer is not a replacement admin session')
   before=state();st,body,_,h=req(base,admin)
   check(st==200 and len(ids(body))==100 and 'admin-audit-export' in body,'existing list gains visible metadata export panel')
   check('heutige Konto-E-Mail' in body and 'private Konto-E-Mails' in body and 'keine universelle' in body,'identity, privacy and expandable spreadsheet caveats available')
   st,raw,_,h=exported();allrows=rows(raw)
   check(st==200 and len(allrows)>100,'all filtered rows exported beyond current100')
   check(list(allrows[0])==['audit_id','created_at_utc','account_email','action'],'exact fixed four-field CSV schema')
   check(h['Content-Type']=='text/csv; charset=utf-8' and h['Cache-Control']=='private, no-store' and h['X-Content-Type-Options']=='nosniff' and re.fullmatch(r'attachment; filename="admin-audit-\d{4}-\d{2}-\d{2}\.csv"',h['Content-Disposition']),'private attachment content and date-only safe filename')
   check(raw.startswith('"audit_id","created_at_utc","account_email","action"\r\n') and raw.endswith('\r\n'),'all CSV headers quoted with CRLF records')
   check(not any(value in body+raw for value in ['PRIVATE-JSON-DETAILS','PRIVATE-LARGE-DETAILS','password_hash','user_id','oversized']),'no stored details, account internals or SQL guard flag in HTML/CSV')
   exported_ids=[int(row['audit_id']) for row in allrows];check(exported_ids==sorted(exported_ids,reverse=True) and exported_ids[:100]==ids(body),'list and full export share descending record order')
   check(next(row for row in allrows if row['action']=='fixture.missing_actor')['account_email']=='','unavailable actor exports empty value, not invented identity')
   check(any(row['action'].startswith("'=1+2,") and '\r\nsecond line' in row['action'] for row in allrows),'real CSV parser handles protected multiline legacy action')
   check(next(row for row in allrows if row['action']=='fixture.account_formula')['account_email']=="'=SUM(1,2)@audit.invalid",'risky current email also protected')
   check({row['created_at_utc'] for row in allrows if row['action']=='fixture.raw_time'}=={'2026-11-01T12:00:00','not-a-time'},'stored noncanonical times not misrepresented as repaired UTC dates')
   for f,count in [({'action':'fixture.export','account':'DEMO@LOYALTY.LOCAL','from':'2026-11-01','to':'2026-11-01'},205),({'action':'fixture.boundary','from':'2026-11-01','to':'2026-11-01'},2),({'action':'fixture.literal','account':'%_!'},1),({'action':'fixture.literal','account':"' OR 1=1 --"},0),({'action':'fixture.missing'},0),({'action':'fixture.raw_time','from':'2026-11-01'},2)]:
    st,raw,_,_=exported(f);st2,view,_,_=req(base+'?'+urllib.parse.urlencode(f),admin);rr=rows(raw)
    check(st==200 and st2==200 and len(rr)==count and ids(view)==[int(row['audit_id']) for row in rr[:100]],'shared list/CSV subset '+str(f))
   f={'action':'fixture.export','from':'2026-11-01','to':'2026-11-01','account':'demo@loyalty.local'}
   first=req(base+'?'+urllib.parse.urlencode(f),admin)[1];older=html.unescape(re.search(r'href="([^"]+)">Ältere 100',first).group(1));second=req(older,admin)[1];link=html.unescape(re.search(r'href="([^"]+)">CSV herunterladen',second).group(1));query=urllib.parse.parse_qs(urllib.parse.urlparse(link).query)
   check('before' not in query and all(query[k]==[v] for k,v in f.items()),'older-page export URL keeps all effective filters, discards cursor')
   check(len(rows(req(link,admin)[1]))==205,'older-page CSV exports full filtered205, not remaining105 or current100')
   check(exported({'action':'fixture.export','lang':'de'})[1]==exported({'action':'fixture.export','lang':'en'})[1],'machine fields and action codes independent of UI locale')
   for suffix in ['before=1','before=','format=json','business_id=1','user_id=1','q=ignored','action%5B%5D=x','account%5B%5D=x','from%5B%5D=x','lang%5B%5D=en','lang=xx','from=2026-02-30','from=2026-11-02&to=2026-11-01','account=%FF','account=%00','action=%3Cscript%3E','action='+('a'*121),'account='+('a'*255)]:
    st,body,_,h=req(base+'/export?'+suffix,admin);check(st==422 and 'Content-Disposition' not in h and '<script>' not in body,'invalid export filter rejected before attachment '+suffix[:32])
   for path in [base,base+'/export']:
    st,_,_,h=req(path,admin,{'csrf':csrf(admin,'/admin'),'action':'settings','platform_name':'Unexpected'});check(st==405 and h['Allow']=='GET' and 'Content-Disposition' not in h,'valid-CSRF POST has no mutation/export route')
    check(req(path,admin,{'action':'export'})[0]==403,'central POST CSRF guard retained')
    for method in ['PUT','DELETE','OPTIONS','HEAD']:
     st,_,_,h=req(path,admin,method=method);check(st==405 and h['Allow']=='GET' and 'Content-Disposition' not in h,'other method has no attachment '+method)
   sql("db()->exec(\"UPDATE audit_logs SET details=CAST(X'FF' AS TEXT) WHERE action='fixture.huge_details'\");");check(req(base,admin)[0]==200 and exported()[0]==200,'unselected private payload encoding cannot affect metadata read');sql("db()->prepare('UPDATE audit_logs SET details=? WHERE action=?')->execute([str_repeat('PRIVATE-LARGE-DETAILS',60000),'fixture.huge_details']);")
   check(state()==before and not (A/'unexpected-send').exists(),'normal reads and rejected actions leave every application table unchanged; no transport')
   cfg.write_text(config.replace("['admin@audit.invalid']","[]"));st,body,_,h=exported();check(st==403 and 'Content-Disposition' not in h and 'fixture.export' not in body,'current allowlist revocation immediately blocks existing session');cfg.write_text(config)
   cfg.write_text(config.replace('return $c;',"$c['stripe']['enforce_limits']=true;return $c;"));check(exported()[0]==200,'platform-admin read remains independent of paid tenant plan');cfg.write_text(config)
   target=exported_ids[0]
   for column in ['action','created_at']:
    sql("db()->exec(\"UPDATE audit_logs SET "+column+"=CAST(X'FF' AS TEXT) WHERE id=%d\");"%target)
    for path in [base,base+'/export']:
     st,_,_,h=req(path,admin);check(st==422 and 'Content-Disposition' not in h,'invalid stored UTF8 fails closed '+column)
    sql("db()->prepare('UPDATE audit_logs SET "+column+"=? WHERE id=?')->execute(['fixture.restored',%d]);"%target)
   sql("db()->exec(\"UPDATE users SET email=CAST(X'FF' AS TEXT) WHERE id=%d\");"%info['owner']);st,_,_,h=exported();check(st==422 and 'Content-Disposition' not in h,'invalid current-account UTF8 also fails closed');sql("db()->prepare('UPDATE users SET email=? WHERE id=?')->execute(['demo@loyalty.local',%d]);"%info['owner'])
   sql("db()->prepare('UPDATE audit_logs SET action=? WHERE id=?')->execute([str_repeat('ä',32768),%d]);"%target);check(exported()[0]==200,'exact64KiB UTF8 metadata cell accepted over HTTP')
   for column,table,rowid in [('action','audit_logs',target),('created_at','audit_logs',target),('email','users',info['owner'])]:
    sql("db()->prepare('UPDATE "+table+" SET "+column+"=? WHERE id=?')->execute([str_repeat('x',65537),%d]);"%rowid)
    for path in [base,base+'/export']:
     st,_,_,h=req(path,admin);check(st==422 and 'Content-Disposition' not in h,'SQL bounds oversized cell before fetch '+column)
    restored='demo@loyalty.local' if column=='email' else '2026-11-01 12:00:00' if column=='created_at' else 'fixture.restored'
    sql("db()->prepare('UPDATE "+table+" SET "+column+"=? WHERE id=?')->execute(["+json.dumps(restored)+",%d]);"%rowid)
   sql("require 'fixture.php';admin_audit_export_fixture(4987);");st,raw,_,_=exported();check(st==200 and len(rows(raw))==5000,'exact5000 rows accepted over HTTP')
   sql("require 'fixture.php';admin_audit_export_fixture(4988);");st,_,_,h=exported();check(st==422 and 'Content-Disposition' not in h,'exact5001 returns error, no partial attachment')
   st,raw,_,_=exported({'action':'fixture.missing'});check(st==200 and len(rows(raw))==0,'filtered empty set remains header-only even with more than5000 total rows')
   sql("require 'fixture.php';admin_audit_export_fixture(100);db()->exec(\"UPDATE audit_logs SET action=replace(hex(zeroblob(22000)),'0','x')\");");st,_,_,h=exported();check(st==422 and 'Content-Disposition' not in h,'encoded4MiB ceiling rejects whole HTTP export')
   sql("require 'fixture.php';admin_audit_export_fixture();");browser_before=state();last_id=int(sql("echo db()->query('SELECT MAX(id) FROM audit_logs')->fetchColumn();"));old_hash=sql("echo hash('sha256',json_encode(db()->query('SELECT * FROM audit_logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));" )
   subprocess.run(['node',str(R/'tests/admin-audit-export-ui.cjs')],cwd=R,check=True)
   browser_after=state()
   for key in ['audit_logs','rate_limits']:browser_before.pop(key);browser_after.pop(key)
   check(browser_after==browser_before,'browser filters/paging/downloads change no application data outside normal login audit/rate records')
   check(old_hash==sql("echo hash('sha256',json_encode(db()->query('SELECT * FROM audit_logs WHERE id<=%d ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));"%last_id) and json.loads(sql("echo json_encode(db()->query('SELECT action FROM audit_logs WHERE id>%d')->fetchAll(PDO::FETCH_COLUMN));"%last_id))==['auth.login']*3,'all prior audit rows unchanged; only three expected browser-login entries appended')
   check(not (A/'unexpected-send').exists(),'browser paths never invoke transport')
   text=(A/'server.log').read_text();check('PHP Warning' not in text and 'PHP Fatal' not in text,'no PHP warnings or fatals')
  finally:server.terminate();server.wait(timeout=10)
 print(n,'admin audit export HTTP assertions passed',flush=True)
 print('No real spreadsheet-client, provider or production acceptance.',flush=True)
