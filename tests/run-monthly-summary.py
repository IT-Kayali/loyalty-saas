#!/usr/bin/env python3
"""Read-only monthly summary, isolated database and HTTP/browser checks."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-monthly-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(s):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+s],cwd=A,text=True).strip()
 sql("db()->exec(\"DELETE FROM transactions;UPDATE members SET created_at='2019-01-01 00:00:00';UPDATE members SET created_at='2020-02-01 00:00:00' WHERE id=1;UPDATE members SET created_at='2020-03-01 00:00:00' WHERE id=2\");")
 ids=json.loads(sql("echo json_encode(db()->query('SELECT c.id,p.id AS pid FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=1 AND p.business_id=1 LIMIT 2')->fetchAll());"));cid=ids[0]['id'];cid2=ids[1]['id']
 foreign=int(sql("echo db()->query('SELECT c.id FROM cards c JOIN programs p ON p.id=c.program_id WHERE p.business_id=2 LIMIT 1')->fetchColumn();"))
 sql("$q=db()->prepare('INSERT INTO transactions(card_id,kind,amount,note,created_at,reversed_at)VALUES(?,?,1,?,?,?)');"+''.join("$q->execute([%d,'%s','Private journal note','%s',%s]);"%(c,k,t,"'2021-01-01'" if rev else 'null') for c,k,t,rev in [(cid,'stamp_add','2020-02-01 00:00:00',False),(cid,'reward_redeem','2020-02-29 23:59:59',False),(cid,'points_redeem','2020-02-15 12:00:00',False),(cid2,'voucher_code_use','2020-02-15 12:00:00',False),(cid2,'voucher_use','2020-02-15 12:00:00',False),(cid,'points_add','2020-02-15 12:00:00',True),(cid,'reversal','2020-02-15 12:00:00',False),(cid,'stamp_add','2020-01-31 23:59:59',False),(cid,'stamp_add','2020-03-01 00:00:00',False),(foreign,'voucher_code_use','2020-02-15 12:00:00',False)]))
 sql("db()->prepare('UPDATE programs SET name=? WHERE id=?')->execute([str_repeat('LangesProgramm',12),%d]);"%ids[0]['pid'])
 def data(bid=1,month='2020-02'):return json.loads(sql("[$s,$e]=monthly_summary_window('%s');echo json_encode(monthly_summary_data(%d,$s,$e));"%(month,bid)))
 d=data();check(d['new_members']==1,'new members use inclusive start and exclusive next-month boundary')
 check(d['actions']==5 and d['redeems']==4,'all redemption families count once, reversed/reversal/outside-month excluded')
 check(d['active_members']==1,'same member across two programs counts once overall')
 check(sum(int(p['active_members']) for p in d['programs'])==2,'program active counts stay separate, never sum into overall people')
 check(data(2)['actions']==1,'foreign business data remains separate')
 check(data(month='2020-04')['actions']==0,'empty month returns zero, not all-time totals')
 check(json.loads(sql("echo json_encode(monthly_summary_window('2020-02'));"))==['2020-02-01 00:00:00','2020-03-01 00:00:00'],'UTC leap-month calendar window computed exactly')
 def snapshot():return sql("echo json_encode([db()->query('SELECT * FROM members')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM audit_logs')->fetchAll()]);")
 before=snapshot()
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(c,p,data=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+p,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(c,p)[1]).group(1)
 def login(email,pw,path='/login'):
  c=client();req(c,path,{'csrf':csrf(c,path),'email':email,'password':pw});return c
 with open(A/'server.log','w') as log:
  s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
    except OSError:time.sleep(.1)
   o=login('demo@loyalty.local','demo1234');P='/business/1/monthly-summary?month=2020-02'
   st,h,_,headers=req(o,P);check(st==200 and 'data-monthly="actions">5<' in h,'HTTP shows exact month results')
   check('2020-03-01 00:00:00' in h and 'UTC' in h and 'keine eingefrorene Archivkopie' in h,'window and mutable-history caveat visible')
   check(headers['Cache-Control']=='private, no-store' and headers['Referrer-Policy']=='no-referrer','private no-store and no-referrer headers')
   check('Private journal note' not in h and 'aylin@example.com' not in h,'report contains no member identities or journal notes')
   check('Monatsübersicht (UTC)' in req(o,'/business/1/analytics')[1],'analytics links to monthly summary')
   check('Laufender Monat, noch unvollständig' in req(o,'/business/1/monthly-summary?month='+time.strftime('%Y-%m',time.gmtime()))[1],'current month explicitly partial')
   check('value="'+sql("echo (new DateTimeImmutable('first day of last month',new DateTimeZone('UTC')))->format('Y-m');")+'"' in req(o,'/business/1/monthly-summary')[1],'default is last complete UTC month')
   for q in ['month=2020-13','month=2020-2','month=1999-12','month=2099-12','month%5B%5D=2020-02','month=%3Cscript%3E']:
    st,h,_,_=req(o,'/business/1/monthly-summary?'+q);check(st==422 and 'data-monthly=' not in h,'invalid month rejects without partial report')
   check('/login' in req(client(),P)[2],'guest must sign in')
   check('/login' in req(login('aylin@example.com','kunde1234','/member/login'),P)[2],'member cannot read owner report')
   sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Foreign','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);")
   check(req(login('foreign@test.local','foreignpass1'),P)[0]==404,'foreign owner denied')
   check(req(o,P,{'csrf':csrf(o,'/business/1')})[0]==405,'POST is not a report or mutation endpoint')
   (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
   check(req(o,P)[0]==200,'report readable without paid write plan')
   (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
   before=snapshot();req(o,P)
   check(snapshot()==before,'report leaves members balances ledger mail and audit unchanged')
   subprocess.run(['node',str(R/'tests/monthly-summary-ui.cjs')],cwd=R,check=True)
   sql("$q=db()->prepare(\"INSERT INTO programs(business_id,type,name)VALUES(1,'points','Extra')\");$n=(int)db()->query('SELECT COUNT(*) FROM programs WHERE business_id=1')->fetchColumn();for($i=$n;$i<200;$i++)$q->execute();")
   check(req(o,P)[0]==200,'exact 200-program cap remains readable')
   sql("db()->exec(\"INSERT INTO programs(business_id,type,name)VALUES(1,'points','Over cap')\");")
   st,h,_,_=req(o,P);check(st==422 and 'data-monthly=' not in h,'program cap rejects whole report, no misleading partial totals')
  finally:s.terminate();s.wait()
print(n,'monthly summary HTTP/domain assertions passed')
