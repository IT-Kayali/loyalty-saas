#!/usr/bin/env python3
"""Private aggregate CSV of the selected UTC month, without identities or report mutations."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json,csv,io
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-monthly-csv-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 sql("db()->exec(\"DELETE FROM transactions;UPDATE members SET created_at='2019-01-01 00:00:00';UPDATE members SET created_at='2020-02-01 00:00:00' WHERE id=1\");")
 ids=json.loads(sql("echo json_encode(db()->query('SELECT c.id,p.id AS pid FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=1 AND p.business_id=1 LIMIT 2')->fetchAll());"));cid=ids[0]['id'];cid2=ids[1]['id']
 sql("$q=db()->prepare('INSERT INTO transactions(card_id,kind,amount,note,created_at,reversed_at)VALUES(?,?,1,?,?,?)');"+''.join("$q->execute([%d,'%s','Private journal note','%s',%s]);"%(c,k,t,"'2021-01-01'" if rev else 'null') for c,k,t,rev in [(cid,'stamp_add','2020-02-01 00:00:00',False),(cid,'reward_redeem','2020-02-29 23:59:59',False),(cid,'points_redeem','2020-02-15 12:00:00',False),(cid2,'voucher_code_use','2020-02-15 12:00:00',False),(cid2,'voucher_use','2020-02-15 12:00:00',False),(cid,'points_add','2020-02-15 12:00:00',True),(cid,'reversal','2020-02-15 12:00:00',False),(cid,'stamp_add','2020-03-01 00:00:00',False)]))
 sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Foreign','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);")
 def snapshot():return sql("echo json_encode([db()->query('SELECT * FROM members')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM audit_logs')->fetchAll()]);")
 def mk():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c)[1]).group(1)
 def login(e,pw,p='/login'):
  c=mk();req(p,c,{'csrf':csrf(c,p),'email':e,'password':pw});return c
 log=open(A/'server.log','w');s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  o=login('demo@loyalty.local','demo1234');f=login('foreign@test.local','foreignpass1');m=login('aylin@example.com','kunde1234','/member/login');P='/business/1/monthly-summary';C=P+'.csv?month=2020-02';before=snapshot()
  status,text,_,headers=req(C,o);rows=list(csv.DictReader(io.StringIO(text)));total=rows[0];programs=rows[1:]
  check(status==200 and headers.get_content_type()=='text/csv' and headers.get_content_charset()=='utf-8','UTF-8 CSV response')
  check(headers['Content-Disposition']=='attachment; filename="monthly-summary-1-2020-02.csv"','fixed safe filename contains selected business/month')
  check(headers['Cache-Control']=='private, no-store' and headers['Referrer-Policy']=='no-referrer','private no-store/no-referrer export')
  check(total['Zeilentyp']=='gesamt' and total['Monat_UTC']=='2020-02' and total['Von_UTC']=='2020-02-01 00:00:00' and total['Bis_exklusiv_UTC']=='2020-03-01 00:00:00','explicit selected leap-month UTC boundaries')
  check(total['Neue_Mitgliedseintraege']=='1' and total['Aktive_Mitglieder']=='1' and total['Journal_Aktionen']=='5' and total['Einloesungen']=='4','exact aggregate counts, excluded reversal/storno/outside month')
  check(sum(int(x['Aktive_Mitglieder']) for x in programs)==2 and all(x['Neue_Mitgliedseintraege']=='' for x in programs),'program people not summed into total, undefined program registrations blank')
  check(all(x['Stand_UTC']==total['Stand_UTC'] and x['Laufender_Monat']=='nein' for x in rows),'one generated-at value and historic-month flag per file')
  check('Archivkopie' in total['Hinweis'] and all('nicht zur Gesamtzahl addieren' in x['Hinweis'] for x in programs),'mutable history and people warning in CSV')
  check('Private journal note' not in text and 'aylin@example.com' not in text and 'Aylin Demir' not in text and 'password' not in text,'no member identity, contact, notes or auth data')
  check('monthly-summary.csv?month=2020-02' in req(P+'?month=2020-02',o)[1],'page download link retains selected month')
  empty=list(csv.DictReader(io.StringIO(req(P+'.csv?month=2020-04',o)[1])))[0];check(empty['Journal_Aktionen']=='0' and empty['Einloesungen']=='0','empty month remains zero rather than all-time')
  current=list(csv.DictReader(io.StringIO(req(P+'.csv?month='+time.strftime('%Y-%m',time.gmtime()),o)[1])))[0];check(current['Laufender_Monat']=='ja','current month explicitly partial in CSV')
  for query in ['', '?month=2020-13','?month=2020-2','?month=1999-12','?month=2099-12','?month%5B%5D=2020-02','?month=%3Cscript%3E']:
   st,t,_,hh=req(P+'.csv'+query,o);check(st==422 and hh.get_content_type()!='text/csv' and 'Content-Disposition' not in hh,'invalid month never downloads partial CSV')
  check(req(C,mk())[2].endswith('/login') and req(C,m)[2].endswith('/login'),'guest/member excluded from owner CSV')
  check(req(C,f)[0]==404 and req('/business/99999/monthly-summary.csv?month=2020-02',o)[0]==404,'foreign owner and unknown business unavailable')
  st,_,_,hh=req(C,o,{'csrf':csrf(o,'/business/1')});check(st==405 and hh['Allow']=='GET','CSV endpoint is GET only')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;");check(req(C,o)[0]==200,'aggregate export readable without paid write plan');(A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  check(snapshot()==before,'CSV requests never change balances journal mail or audit')
  # Formula-looking names are quoted as text; CSV quoting preserves separators, quotes and newlines.
  for name in ['=SUM(1,2)','  +bonus','\t@sample','-100','\u00a0=SUM(2,3)','Kaffee, "groß"\nZeile']:
   program=ids[0]['pid'];sql("db()->prepare('UPDATE programs SET name=? WHERE id=?')->execute([json_decode('%s'),%d]);"%(json.dumps(name).replace("'","\\u0027"),program))
   exported=list(csv.DictReader(io.StringIO(req(C,o)[1])));got=next(x['Programmname'] for x in exported[1:] if x['Programm_ID']==str(program));wanted=name if name.startswith('Kaffee') else "'"+name;check(got==wanted,'spreadsheet text safety and lossless CSV quoting')
  sql("db()->prepare('UPDATE programs SET name=? WHERE id=?')->execute([hex2bin('ff'),%d]);"%ids[0]['pid']);st,t,_,hh=req(C,o);check(st==422 and hh.get_content_type()!='text/csv' and 'Content-Disposition' not in hh,'invalid UTF-8 program text rejects before download headers')
  sql("db()->prepare('UPDATE programs SET name=? WHERE id=?')->execute(['Kaffee nach Wahl',%d]);"%ids[0]['pid'])
  subprocess.run(['node',str(R/'tests/monthly-csv-ui.cjs')],cwd=R,check=True)
  sql("$q=db()->prepare(\"INSERT INTO programs(business_id,type,name)VALUES(1,'points','Extra')\");$n=(int)db()->query('SELECT COUNT(*) FROM programs WHERE business_id=1')->fetchColumn();for($i=$n;$i<200;$i++)$q->execute();")
  check(len(list(csv.DictReader(io.StringIO(req(C,o)[1]))))==201,'exact cap exports total plus 200 programs')
  sql("db()->exec(\"INSERT INTO programs(business_id,type,name)VALUES(1,'points','Over cap')\");");st,t,_,hh=req(C,o);check(st==422 and hh.get_content_type()!='text/csv' and 'Content-Disposition' not in hh,'over-cap report fails before any CSV attachment headers')
 finally:s.terminate();s.wait();log.close()
print(n,'monthly CSV HTTP/domain assertions passed')
