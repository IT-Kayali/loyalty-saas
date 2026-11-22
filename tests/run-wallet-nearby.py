#!/usr/bin/env python3
"""Wallet proximity configuration and unsigned provider payloads, no external requests."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,json,concurrent.futures
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-wallet-nearby-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(c):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+c],cwd=A,text=True).strip()
 def loc(name='Store',bid=1):return int(sql("db()->prepare('INSERT INTO locations(business_id,name,address)VALUES(?,?,?)')->execute([%d,'%s','Example street']);echo db()->lastInsertId();"%(bid,name)))
 id=loc();P='/location/%d/wallet-nearby'%id
 sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Foreign','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);")
 def state():return sql('echo json_encode(db()->query("SELECT * FROM locations WHERE id=%d")->fetch());'%id)
 def effects():return sql("echo json_encode([db()->query('SELECT * FROM members')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM member_messages')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM pass_devices')->fetchAll()]);")
 def payload(bid=1):return json.loads(sql("$q=db()->prepare('SELECT code FROM members WHERE business_id=? LIMIT 1');$q->execute([%d]);$m=pass_member($q->fetchColumn());echo json_encode(['apple'=>apple_pass_json($m),'google'=>google_pass_payload($m)]);"%bid))
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(p,c,d=None):
  if isinstance(d,dict):d=urllib.parse.urlencode(d).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+p,data=d),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c)[1]).group(1)
 def login(e,pw,path='/login'):
  c=client();req(path,c,{'csrf':csrf(c,path),'email':e,'password':pw});return c
 def audits():return int(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='location.wallet_nearby'\")->fetchColumn();"))
 with open(A/'server.log','w') as log:
  s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
    except OSError:time.sleep(.1)
   o=login('demo@loyalty.local','demo1234');f=login('foreign@test.local','foreignpass1');m=login('aylin@example.com','kunde1234','/member/login')
   def revision():return re.search(r'name="revision" value="([a-f0-9]+)"',req(P,o)[1]).group(1)
   def save(extra=None,token=True):
    d={'latitude':'51.5000000','longitude':'7.2500000','enabled':'1','confirm_location':'1','revision':revision()}
    if token:d['csrf']=csrf(o,P)
    if extra:d.update(extra)
    return req(P,o,d)
   check(json.loads(state())['wallet_nearby']==0,'existing/new locations default off')
   d=payload();check(d['apple']['locations']==[] and d['google']['object']['merchantLocations']==[],'no coordinates exported without opt-in')
   before=state();a=audits();other=effects()
   check('/login' in req(P,client())[2] and '/login' in req(P,m)[2],'guest/member cannot read owner configuration')
   check(req(P,f)[0]==404,'foreign owner cannot read coordinates');check(req('/location/999999/wallet-nearby',o)[0]==404,'unknown location unavailable')
   st,h,_,headers=req(P,o);check(headers['Cache-Control']=='private, no-store' and headers['Referrer-Policy']=='no-referrer','private no-store no-referrer editor')
   check('href="'+P+'"' in req('/organization',o)[1],'organization links fixed location editor')
   check('kein echter Gerätetest' in h and 'nicht eingerichtet' in h and 'Keine Kundenortung' in h,'setup and tracking limits visible without credentials')
   check(state()==before and audits()==a,'GET no configuration or audit writes')
   save(token=False);check(state()==before,'CSRF required')
   req(P,f,{'csrf':csrf(f,'/dashboard'),'latitude':'0','longitude':'0','enabled':'1','confirm_location':'1','revision':revision()});check(state()==before,'foreign POST cannot change location')
   for changes in [{'latitude':'91'},{'latitude':'-90.1'},{'longitude':'181'},{'longitude':'-180.1'},{'latitude':'51,5'},{'latitude':'1e1'},{'longitude':'NaN'},{'latitude':'1.12345678'},{'latitude':'01'},{'latitude':''},{'enabled':'yes'},{'confirm_location':'0'},{'revision':'bad'}]:
    save(changes);check(state()==before and audits()==a,'invalid coordinate/selection/confirmation rejected')
   for key in ['latitude','longitude','enabled','revision','confirm_location']:
    d={'latitude':'51','longitude':'7','enabled':'1','confirm_location':'1','revision':revision(),'csrf':csrf(o,P)};del d[key];d[key+'[]']='x';req(P,o,d);check(state()==before,'array rejected for '+key)
   stale=revision();save();check(json.loads(state())['wallet_nearby']==1 and audits()==a+1,'explicit opt-in saved and audited once')
   p=payload();expected=[{'latitude':51.5,'longitude':7.25}]
   check(p['apple']['locations']==expected and p['google']['object']['merchantLocations']==expected,'Apple locations and Google merchantLocations contain exact numeric coordinates')
   check('storeCard' in p['apple'] and 'relevantDate' not in p['apple'] and 'relevantDates' not in p['apple'],'storeCard has no fabricated or unsupported relevance date')
   check('merchantLocations' not in p['google']['class'] and 'locations' not in p['google']['object'],'coordinates only on tenant-specific Google object, not shared class or deprecated field')
   sql("db()->exec(\"INSERT INTO networks(user_id,name)VALUES(1,'Shared group')\");$nid=(int)db()->lastInsertId();db()->exec('UPDATE businesses SET network_id='.$nid.' WHERE id IN (1,2)');")
   check(payload(2)['apple']['locations']==[] and payload(2)['google']['object']['merchantLocations']==[],'different business cannot inherit points even under same owner')
   now=state();save({'latitude':'52','revision':stale});check(state()==now,'old editor cannot overwrite coordinates')
   # Independent text editor revision still works, only updates its own fields.
   sql("$l=db()->query('SELECT * FROM locations WHERE id=%d')->fetch();location_edit(1,%d,['name'=>'Renamed Store','address'=>'New text address','revision'=>location_revision($l)]);"%(id,id))
   check(payload()['apple']['locations']==expected,'name/address change does not geocode or move wallet location')
   sql("db()->exec('UPDATE locations SET active=0 WHERE id=%d');"%id);check(payload()['apple']['locations']==[],'inactive location excluded from new payload');now=state();save();check(state()==now,'cannot enable inactive location')
   sql("db()->exec('UPDATE locations SET active=1 WHERE id=%d');"%id);check(payload()['apple']['locations']==expected,'reactivation restores explicitly selected location')
   save({'enabled':'0','latitude':'','longitude':''});check(payload()['apple']['locations']==[] and payload()['google']['object']['merchantLocations']==[],'off with empty fields removes coordinates from future payloads')
   save({'latitude':'-90','longitude':'180'});check(payload()['apple']['locations']==[{'latitude':-90,'longitude':180}],'inclusive coordinate boundaries accepted')
   sql("db()->exec(\"CREATE TRIGGER fail_nearby BEFORE INSERT ON audit_logs WHEN NEW.action='location.wallet_nearby' BEGIN SELECT RAISE(ABORT,'forced audit failure'); END\");");now=state();save({'latitude':'0','longitude':'0'});check(state()==now,'audit failure rolls back all nearby values');sql('db()->exec("DROP TRIGGER fail_nearby");')
   (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;");check(req(P,o)[0]==200,'unpaid plan can read settings');now=state();save();check(state()==now,'unpaid plan cannot modify');(A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
   # Ten selected rows include inactive ones, cap checked under lock.
   sql("db()->exec('UPDATE locations SET wallet_nearby=0');$q=db()->prepare(\"INSERT INTO locations(business_id,name,wallet_latitude,wallet_longitude,wallet_nearby,active)VALUES(1,'Selected',1,2,1,0)\");for($i=0;$i<9;$i++)$q->execute();")
   save();check(json.loads(state())['wallet_nearby']==1,'tenth selection allowed even with nine paused selections')
   extra=loc('Eleventh');rev=sql("echo wallet_location_revision(db()->query('SELECT * FROM locations WHERE id=%d')->fetch());"%extra)
   result=sql("try{wallet_location_save(1,%d,['latitude'=>'1','longitude'=>'2','enabled'=>'1','confirm_location'=>'1','revision'=>'%s']);echo 'bad';}catch(InvalidArgumentException){echo 'blocked';}"%(extra,rev));check(result=='blocked','eleventh selection rejected without silent truncation')
   sql("db()->exec('UPDATE locations SET wallet_nearby=0 WHERE id=%d');"%id)
   def competing(target):
    rev=sql("echo wallet_location_revision(db()->query('SELECT * FROM locations WHERE id=%d')->fetch());"%target)
    return sql("try{wallet_location_save(1,%d,['latitude'=>'1','longitude'=>'2','enabled'=>'1','confirm_location'=>'1','revision'=>'%s']);echo 'ok';}catch(InvalidArgumentException){echo 'blocked';}"%(target,rev))
   with concurrent.futures.ThreadPoolExecutor(2) as pool:r=list(pool.map(competing,[id,extra]))
   check(sorted(r)==['blocked','ok'],'two simultaneous selections cannot exceed cap')
   sql("db()->exec('UPDATE locations SET active=1');")
   check(len(payload()['apple']['locations'])==10 and len(payload()['google']['object']['merchantLocations'])==10,'exact ten selected active points accepted in both provider payloads')
   # Manually corrupted oversized or invalid data never produces >10 or invalid payloads.
   sql("db()->exec('UPDATE locations SET active=1,wallet_nearby=1');");check(payload()['apple']['locations']==[],'historic over-cap fails closed rather than exporting arbitrary first ten')
   sql("db()->exec('UPDATE locations SET wallet_nearby=0');db()->exec(\"UPDATE locations SET wallet_nearby=1,wallet_latitude='bad',wallet_longitude=7 WHERE id=%d\");"%id);check(payload()['apple']['locations']==[],'corrupt coordinates skipped')
   check(effects()==other,'no member balances journals mail or device registration changed')
   sql('db()->exec("UPDATE locations SET wallet_nearby=0");')
   subprocess.run(['node',str(R/'tests/wallet-nearby-ui.cjs'),str(id)],cwd=R,check=True)
  finally:s.terminate();s.wait()
print(n,'wallet nearby HTTP/domain assertions passed')
