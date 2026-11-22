#!/usr/bin/env python3
"""Own-business read-only delivery metadata list and filtered bounded CSV."""
import csv,html,http.cookiejar,io,json,os,pathlib,re,shutil,subprocess,tempfile,time,urllib.error,urllib.parse,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(v,label):
 global n
 assert v,label
 n+=1;print('PASS',label,flush=True)

with tempfile.TemporaryDirectory(prefix='loyalty-webhook-list-') as temp:
 A=pathlib.Path(temp)
 for folder in ['app','public']:shutil.copytree(R/folder,A/folder)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 config="<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['other@metadata.invalid'];$c['webhook_transport']=function(){file_put_contents(__DIR__.'/unexpected-send','1');throw new RuntimeException('Unexpected transport');};return $c;"
 cfg=A/'config.php';cfg.write_text(config);subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True);shutil.copy(R/'tests/webhook-list-fixture.php',A/'fixture.php')
 subprocess.run([PHP,str(R/'tests/webhook-list.php')],cwd=A,check=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 info=json.loads(sql("require 'fixture.php';echo json_encode(webhook_list_fixture());"));base='/business/1/webhook-deliveries'
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None,method=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data,method=method),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,pw):
  c=client();assert req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':pw})[2].endswith('/dashboard');return c
 def state():return json.loads(sql("require 'fixture.php';echo json_encode(webhook_list_state());"))
 def exported(f=None,c=None,path=base):return req(path+'/export?'+urllib.parse.urlencode(f or {}),c or owner)
 with (A/'server.log').open('w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(60):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login',timeout=1);break
    except OSError:time.sleep(.05)
   owner=login('demo@loyalty.local','demo1234');other=login('other@metadata.invalid','otherpass1');member=client();staff=client()
   check(req('/member/login',member,{'csrf':csrf(member,'/member/login'),'email':'aylin@example.com','password':'kunde1234'})[2].endswith('/wallet'),'actual member login')
   check(req('/staff/login',staff,{'csrf':csrf(staff,'/staff/login'),'business_id':1,'name':'Lena','pin':'4321'})[2].endswith('/staff'),'actual staff login')
   for c in [client(),member,staff]:
    for path in [base,base+'/export']:check(req(path,c)[2].endswith('/login'),'non-owner session must use operator login')
   for path in [base,base+'/export']:
    check(req(path,other)[0]==404,'foreign configured admin is not silently a business owner')
    check(req(path.replace('/business/1/','/business/999999/'),owner)[0]==404,'unknown business unavailable')
   st,body,_,h=req(base,owner);check(st==200 and 'Webhook-Zustellliste' in body and h['Cache-Control']=='private, no-store','owner reads private metadata view')
   check('href="'+base+'"' in req('/business/1',owner)[1],'business webhook area links new list')
   check(all(x not in body for x in ['PRIVATE-PAYLOAD','PRIVATE-RESPONSE','PRIVATE-HOOK-SECRET','PRIVATE-URL','PRIVATE-FOREIGN','PRIVATE-SECOND']),'HTML excludes private columns and other businesses')
   check('Ungültig gespeichert' in body,'invalid stored times are visibly marked, not presented as real dates')
   ids=[];nexturl=base
   for _ in range(3):
    st,b,_,_=req(nexturl,owner);ids += [int(v) for v in re.findall(r'<tr><td data-label="Zustellung / Webhook">(\d+) / ',b)];m=re.search(r'href="([^"]+)">Ältere 50</a>',b)
    if not m:break
    nexturl=html.unescape(m.group(1))
   check(len(ids)==62 and ids==sorted(set(ids),reverse=True),'real two-page list has every row exactly once in descending order')
   st,raw,_,h=exported();rows=list(csv.DictReader(io.StringIO(raw)));check(st==200 and len(rows)==62 and [int(r['delivery_id']) for r in rows]==ids,'CSV exports all matching rows beyond current50')
   fields=['delivery_id','webhook_id','event','status','attempts','last_attempt_at_utc','next_attempt_at_utc'];check(list(rows[0])==fields,'fixed seven metadata columns')
   check(h['Content-Type']=='text/csv; charset=utf-8' and h['Cache-Control']=='private, no-store' and h['X-Content-Type-Options']=='nosniff' and h['Content-Disposition'].endswith('.csv"'),'CSV attachment content and privacy headers')
   check(all(x not in raw for x in ['PRIVATE-PAYLOAD','PRIVATE-RESPONSE','PRIVATE-HOOK-SECRET','PRIVATE-URL','PRIVATE-FOREIGN','PRIVATE-SECOND']),'download excludes private columns and foreign scopes')
   for f,count in [({'event':'%_!'},1),({'event':'card.points','status':'failed','hook':str(info['hooks'][0])},56),({'event':'card.points','from':'2026-11-22','to':'2026-11-22'},2),({'hook':str(info['hooks'][3])},0),({'status':'pending'},1),({'event':"' OR 1=1 --"},0)]:
    st,raw,_,_=exported(f);rr=list(csv.DictReader(io.StringIO(raw)));st2,view,_,_=req(base+'?'+urllib.parse.urlencode(f),owner);check(st==200 and st2==200 and len(rr)==count,'same exact filtered subset for CSV '+str(f));check(view.count('<tr><td data-label="Zustellung / Webhook">')==min(count,50),'HTML filtered subset has matching page count')
   st,raw,_,_=exported(path='/business/2/webhook-deliveries');rr=list(csv.DictReader(io.StringIO(raw)));check(st==200 and len(rr)==1 and rr[0]['event']=='PRIVATE-SECOND-BUSINESS','same owner other business isolated, available through its own route')
   before=state()
   for suffix in ['?status=bad','?hook=-1','?before=0','?event%5B%5D=x','?from=2026-02-30','?from=2026-11-23&to=2026-11-22','?action=retry']:
    st,_,_,h=req(base+suffix,owner);check(st==422 and 'Content-Disposition' not in h,'bad list filter is rejected without a file')
   for f in [{'before':'1'},{'business_id':'2'},{'format':'json'},{'status':'bad'},{'lang':['en']},{'callback':'x'}]:
    # urlencode normally stringifies lists, still an invalid language rather than silently broadening.
    st,_,_,h=exported(f);check(st==422 and 'Content-Disposition' not in h,'bad export selector is rejected without attachment')
   for path in [base,base+'/export']:
    st,_,_,h=req(path,owner,{'csrf':csrf(owner,'/business/1'),'action':'webhook_run_due'});check(st==405 and h['Allow']=='GET','valid-CSRF POST cannot run delivery actions')
    check(req(path,owner,{'action':'retry'})[0]==403,'central CSRF preserved')
    for method in ['PUT','DELETE','OPTIONS','HEAD']:check(req(path,owner,method=method)[0]==405,'other method rejected '+method)
   check(state()==before and not (A/'unexpected-send').exists(),'read and rejected-action requests leave all fixture state unchanged, never call transport')
   cfg.write_text(config.replace('return $c;',"$c['stripe']['enforce_limits']=true;return $c;"));check(req(base,owner)[0]==200 and exported()[0]==200,'existing read access remains available after tenant plan lapses');cfg.write_text(config)
   # Invalid UTF8 and oversize metadata must fail before any attachment headers.
   target=int(rows[0]['delivery_id']);original=sql('echo json_encode(db()->query("SELECT event FROM webhook_deliveries WHERE id=%d")->fetchColumn());'%target)
   sql("db()->exec(\"UPDATE webhook_deliveries SET event=CAST(X'FF' AS TEXT) WHERE id=%d\");"%target)
   for path in [base,base+'/export']:st,_,_,h=req(path,owner);check(st==422 and 'Content-Disposition' not in h,'stored invalid UTF8 fails closed')
   sql("db()->prepare('UPDATE webhook_deliveries SET event=? WHERE id=?')->execute([str_repeat('x',65537),%d]);"%target)
   for path in [base,base+'/export']:st,_,_,h=req(path,owner);check(st==422 and 'Content-Disposition' not in h,'oversized metadata fails closed before fetch/allocation')
   sql("require 'fixture.php';webhook_list_fixture(4992);");st,raw,_,_=exported();check(st==200 and len(list(csv.DictReader(io.StringIO(raw))))==5000,'exact5000 rows are accepted')
   sql("require 'fixture.php';webhook_list_fixture(4993);");st,_,_,h=exported();check(st==422 and 'Content-Disposition' not in h,'exact5001 rows produce no partial attachment')
   # A query with no matches remains a real header-only CSV, not a row-limit failure.
   st,raw,_,_=exported({'event':'no-match'});check(st==200 and list(csv.DictReader(io.StringIO(raw)))==[],'empty filtered export is header-only')
   sql("require 'fixture.php';webhook_list_fixture(200);db()->exec(\"UPDATE webhook_deliveries SET event=replace(hex(zeroblob(11000)),'0','x') WHERE webhook_id=(SELECT MIN(id) FROM webhooks)\");")
   st,_,_,h=exported();check(st==422 and 'Content-Disposition' not in h,'encoded4MiB limit returns no partial attachment')
   info=json.loads(sql("require 'fixture.php';echo json_encode(webhook_list_fixture());"));browser_before=state()
   subprocess.run(['node',str(R/'tests/webhook-list-ui.cjs')],cwd=R,check=True)
   browser_after=state();old=browser_before.pop('audit_logs');new=browser_after.pop('audit_logs');check(browser_after==browser_before and new[:len(old)]==old and len(new)==len(old)+3 and all(r['action']=='auth.login' for r in new[len(old):]),'browser filtering, paging and downloads only add three expected login audit rows')
   check(not (A/'unexpected-send').exists(),'no browser path invokes webhook transport')
   logtext=(A/'server.log').read_text();check('PHP Warning' not in logtext and 'PHP Fatal' not in logtext,'no PHP warnings or fatals')
  finally:server.terminate();server.wait(timeout=10)
 print(n,'webhook list HTTP assertions passed',flush=True)
 print('No actual webhook endpoint, SMTP, spreadsheet-client or production acceptance.',flush=True)
