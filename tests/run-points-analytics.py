#!/usr/bin/env python3
"""Per-program points calendar analytics, isolated HTTP/Chromium and calendar oracle."""
import calendar,datetime as dt,html,http.cookiejar,json,os,pathlib,re,shutil,subprocess,tempfile,time,urllib.error,urllib.parse,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(v,label):
 global n
 assert v,label
 n+=1;print('PASS',label,flush=True)

def oracle():
 out=[]
 def shift_month(d,n):
  year,month=divmod(d.year*12+d.month-1+n,12);return d.replace(year=year,month=month+1,day=1)
 def text(d):return d.strftime('%Y-%m-%d %H:%M:%S')
 for stamp in ['2024-03-15T12:34:56+00:00','2024-03-01T00:00:00+00:00','2025-03-01T00:00:00+00:00','2026-01-01T00:00:00+00:00','2026-12-31T23:59:59+00:00','2026-01-01T00:30:00+14:00','2026-11-22T00:30:00+01:00']:
  now=dt.datetime.fromisoformat(stamp).astimezone(dt.timezone.utc);day=now.replace(hour=0,minute=0,second=0,microsecond=0);week=day-dt.timedelta(days=day.weekday());month=day.replace(day=1);year=month.replace(month=1)
  for name in ['today','yesterday','this_week','last_week','this_month','last_month','this_year','last_year']:
   if name in ['today','yesterday']:start=day-dt.timedelta(days=name=='yesterday');end=start+dt.timedelta(days=1);previous=start-dt.timedelta(days=1);bucket='hour'
   elif name.endswith('week'):start=week-dt.timedelta(days=7*(name=='last_week'));end=start+dt.timedelta(days=7);previous=start-dt.timedelta(days=7);bucket='day'
   elif name.endswith('month'):start=shift_month(month,-int(name=='last_month'));end=shift_month(start,1);previous=shift_month(start,-1);bucket='day'
   else:start=year.replace(year=year.year-int(name=='last_year'));end=start.replace(year=start.year+1);previous=start.replace(year=start.year-1);bucket='month'
   def bins(a,b):
    count=0
    while a<b and a<=now:
     count+=1;a=shift_month(a,1) if bucket=='month' else a+dt.timedelta(hours=1) if bucket=='hour' else a+dt.timedelta(days=1)
    return count
   out.append({'now':stamp,'range':name,'expected':{'range':name,'start':text(start),'end':text(end),'previous_start':text(previous),'previous_end':text(start),'as_of':text(now),'partial':end>now,'bucket':bucket},'current_bins':bins(start,end),'previous_bins':bins(previous,start)})
 return out

with tempfile.TemporaryDirectory(prefix='loyalty-points-analytics-') as temp:
 A=pathlib.Path(temp)
 for folder in ['app','public']:shutil.copytree(R/folder,A/folder)
 for name in ['seed.php','config.example.php']:shutil.copy(R/name,A/name)
 config="<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['other@points.invalid'];$c['webhook_transport']=function(){file_put_contents(__DIR__.'/unexpected-send','1');throw new RuntimeException('Unexpected transport');};return $c;"
 cfg=A/'config.php';cfg.write_text(config);subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True);shutil.copy(R/'tests/points-analytics-fixture.php',A/'fixture.php');(A/'windows.json').write_text(json.dumps(oracle()))
 subprocess.run([PHP,str(R/'tests/points-analytics.php')],cwd=A,check=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 info=json.loads(sql("require 'fixture.php';echo json_encode(points_analytics_fixture());"));pid=info['program'];base='/program/'+str(pid)+'/analytics'
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None,method=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data,method=method),timeout=15) as response:return response.status,response.read().decode(),response.url,response.headers
  except urllib.error.HTTPError as response:return response.code,response.read().decode(),response.url,response.headers
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,password):
  c=client();assert req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':password})[2].endswith('/dashboard');return c
 def state():return json.loads(sql("require 'fixture.php';echo json_encode(points_analytics_state());"))
 def metrics(body,previous=False):return {key:int(value) for key,value in re.findall(r'data-points-'+('previous' if previous else 'metric')+r'="([^"]+)"[^>]*>(\d+)',body)}
 with (A/'server.log').open('w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(60):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login',timeout=1);break
    except OSError:time.sleep(.05)
   owner=login('demo@loyalty.local','demo1234');other=login('other@points.invalid','otherpass1');member=client();staff=client()
   check(req('/member/login',member,{'csrf':csrf(member,'/member/login'),'email':'aylin@example.com','password':'kunde1234'})[2].endswith('/wallet'),'real member session')
   check(req('/staff/login',staff,{'csrf':csrf(staff,'/staff/login'),'business_id':1,'name':'Lena','pin':'4321'})[2].endswith('/staff'),'real staff session')
   for c in [client(),member,staff]:check(req(base,c)[2].endswith('/login'),'non-operator cannot read program metrics')
   for path in [base,base+'?range[]=today']:check(req(path,other)[0]==404,'foreign configured admin is not silently an owner')
   for target in [999999,int(sql("echo db()->query(\"SELECT id FROM programs WHERE type='stamps' LIMIT 1\")->fetchColumn();"))]:check(req('/program/'+str(target)+'/analytics',owner)[0]==404,'missing/wrong-type program unavailable')
   before=state();st,body,_,h=req(base+'?range=last_month',owner)
   check(st==200 and metrics(body)=={'issued':150,'redeemed':100,'claims':2},'exact selected-period metrics')
   check(metrics(body,True)=={'issued':80,'redeemed':20,'claims':1},'separate prior calendar month')
   check(h['Cache-Control']=='private, no-store' and h['Referrer-Policy']=='no-referrer','private report and URL privacy headers')
   check(all(value not in body for value in ['PRIVATE-MEMBER','PRIVATE-TRANSACTION','PRIVATE-FOREIGN','PRIVATE-SECOND','PRIVATE-OTHER']),'no member names/emails/notes/other program data in report')
   check('ganze Vorperiode' in body and 'keine eingefrorene archivkopie' in body.lower() and 'positiv gespeicherten Kosten' in body,'comparison and ledger semantics stated')
   check('aria-labelledby="points-chart-title points-chart-desc"' in body and '<caption>UTC-Abschnitte' in body,'chart has textual/table alternatives')
   check('href="'+base+'"' in req('/program/'+str(pid),owner)[1] and 'href="'+base+'"' in req('/business/1/analytics',owner)[1],'program and business analytics both link new report')
   for period in ['today','yesterday','this_week','last_week','this_month','last_month','this_year','last_year']:
    st,body,_,_=req(base+'?range='+period,owner);check(st==200 and len(metrics(body))==3,'all exact period selectors available '+period)
   check('Laufender Zeitraum' in req(base,owner)[1] and 'Stand:' in req(base,owner)[1],'default current month explicitly partial/as-of')
   for bad in ['range=all','range=','range[]=today','lang[]=en','lang=xx','from=2026-01-01','program_id=1','range=today%0A']:
    st,body,_,_=req(base+'?'+bad,owner);check(st==422 and not metrics(body),'invalid parameter has no partial metrics '+bad)
   for method in ['PUT','DELETE','OPTIONS','HEAD']:
    st,body,_,h=req(base,owner,method=method);check(st==405 and h['Allow']=='GET' and not metrics(body),'unsupported method rejected '+method)
   st,_,_,h=req(base,owner,{'csrf':csrf(owner,'/program/'+str(pid)),'action':'points_add'});check(st==405 and h['Allow']=='GET','valid-CSRF POST cannot mutate or run report actions')
   check(req(base,owner,{'action':'points_add'})[0]==403,'central POST CSRF retained')
   check(state()==before and not (A/'unexpected-send').exists(),'every application table unchanged by normal and rejected report reads; no transport')
   cfg.write_text(config.replace('return $c;',"$c['stripe']['enforce_limits']=true;return $c;"));check(req(base,owner)[0]==200,'read report independent of paid plan');cfg.write_text(config)
   sql('db()->exec("UPDATE programs SET active=0 WHERE id=%d");'%pid);st,body,_,_=req(base+'?range=last_month',owner);check(st==200 and 'Inaktives Programm' in body and metrics(body)['issued']==150,'inactive program history readable');sql('db()->exec("UPDATE programs SET active=1 WHERE id=%d");'%pid)
   target=int(sql("echo db()->query(\"SELECT id FROM transactions WHERE card_id=%d AND kind='points_add' AND amount=100\")->fetchColumn();"%info['cards'][0]))
   for assignment in ["created_at='not-a-time'","created_at='2026-02-30 12:00:00'","amount=-1","amount=1.5","amount='bad'"]:
    sql('db()->exec("UPDATE transactions SET '+assignment+' WHERE id=%d");'%target);st,body,_,_=req(base+'?range=last_month',owner);check(st==503 and not metrics(body) and 'Keine Teilkennzahlen' in body and 'PRIVATE-' not in body,'invalid retained points record refuses partial report')
    sql("db()->prepare('UPDATE transactions SET created_at=?,amount=100 WHERE id=?')->execute(["+json.dumps(info['window']['start'])+",%d]);"%target)
   sql('db()->exec("UPDATE transactions SET amount=%d WHERE id=%d");'%(2**63-1,target));sql("db()->prepare('INSERT INTO transactions(card_id,kind,amount,created_at)VALUES(?,?,?,?)')->execute([%d,'points_add',1,"%info['cards'][0]+json.dumps(info['window']['start'])+"]);");st,body,_,_=req(base+'?range=last_month',owner);check(st==503 and not metrics(body) and 'overflow' not in body.lower(),'integer overflow has safe unavailable page, not rounded/partial totals')
   info=json.loads(sql("require 'fixture.php';echo json_encode(points_analytics_fixture());"));browser_before=state();last_id=int(sql("echo (int)db()->query('SELECT MAX(id) FROM audit_logs')->fetchColumn();"))
   subprocess.run(['node',str(R/'tests/points-analytics-ui.cjs'),str(pid)],cwd=R,check=True)
   browser_after=state()
   for key in ['audit_logs','rate_limits']:browser_before.pop(key);browser_after.pop(key)
   check(browser_before==browser_after,'browser period switches alter no application data beyond ordinary login records')
   check(json.loads(sql("echo json_encode(db()->query('SELECT action FROM audit_logs WHERE id>%d')->fetchAll(PDO::FETCH_COLUMN));"%last_id))==['auth.login']*3,'only three expected browser-login audit rows')
   check(not (A/'unexpected-send').exists(),'browser never invokes provider transport')
   text=(A/'server.log').read_text();check('PHP Warning' not in text and 'PHP Fatal' not in text,'no PHP warnings or fatals')
  finally:server.terminate();server.wait(timeout=10)
 print(n,'points analytics HTTP assertions passed',flush=True)
 print('No provider, production, financial or load acceptance.',flush=True)
