#!/usr/bin/env python3
"""Read-only admin delivery health: exact boundaries, privacy, HTTP and responsive UI."""
import http.cookiejar,json,os,pathlib,re,shutil,subprocess,tempfile,time,urllib.error,urllib.parse,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(v,label):
 global n
 assert v,label
 n+=1;print('PASS',label,flush=True)

with tempfile.TemporaryDirectory(prefix='loyalty-admin-health-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 config="<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['admin@test.local'];return $c;"
 (A/'config.php').write_text(config);subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 fixture=r'''<?php
function health_fixture(int $now):void {
 $d=db();$d->exec('DELETE FROM mail_outbox;DELETE FROM webhook_deliveries;DELETE FROM webhooks');
 $date=fn($offset)=>gmdate('Y-m-d H:i:s',$now+$offset);
 $rows=[['queued',$date(-1200),null],['queued',$date(-60),null],['queued',$date(60),null],['queued','not-a-time',null],['queued','',null],['sending',$date(-1200),$date(-601)],['sending',$date(-1200),$date(-600)],['sending',$date(-1200),null],['sending',$date(-1200),$date(60)],['sending',$date(-1200),'invalid-claim'],['unknown',$date(-1200),null],['sent',$date(-1200),null],['skipped',$date(-1200),null],['PRIVATE-STATUS<script>',$date(-1200),null]];
 $q=$d->prepare("INSERT INTO mail_outbox(business_id,recipient,subject,body,category,dedup_key,status,created_at,claimed_at,last_error)VALUES(1,'PRIVATE-RECIPIENT@local.invalid','PRIVATE-SUBJECT','PRIVATE-BODY','transactional',?,?,?,?, 'PRIVATE-MAIL-ERROR')");
 foreach($rows as $i=>$r)$q->execute(['health-fixture-'.$i,$r[0],$r[1],$r[2]]);
 $d->exec("INSERT INTO webhooks(business_id,url,events,secret)VALUES(1,'https://PRIVATE-URL.invalid','member.joined','PRIVATE-SECRET')");$id=(int)$d->lastInsertId();
 $rows=[['pending',null,null],['pending',null,$date(-1)],['failed',$date(0),null],['failed',$date(0),$date(-1)],['failed',$date(0),$date(0)],['failed',$date(-60),$date(60)],['failed',$date(60),null],['failed',null,null],['failed','invalid-next',null],['failed',$date(0),'invalid-lock'],['dead',null,null],['delivered',null,$date(-1)],['PRIVATE-STATUS<script>',null,null]];
 $q=$d->prepare("INSERT INTO webhook_deliveries(webhook_id,event,payload,response,status,next_attempt_at,lock_until,delivered_at)VALUES(?,'PRIVATE-EVENT','PRIVATE-PAYLOAD','PRIVATE-RESPONSE',?,?,?,?)");
 foreach($rows as $r)$q->execute([$id,$r[0],$r[1],$r[2],$date(-1200)]);
}
function health_state():string {
 $all=[];foreach(['mail_outbox','webhook_deliveries','wallet_push_deliveries','audit_logs','platform_settings','pass_devices'] as $table)$all[$table]=db()->query('SELECT * FROM '.$table)->fetchAll();return json_encode($all);
}
'''
 (A/'fixture.php').write_text(fixture)
 domain=r'''<?php require 'public/index.php';require 'fixture.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$n=0;function yes($v,$s){global $n;if(!$v)throw new RuntimeException($s);$n++;echo "PASS $s\n";}
$d=db();$d->exec('DELETE FROM mail_outbox;DELETE FROM webhook_deliveries');$now=strtotime('2026-11-22 12:00:00 UTC');$s=admin_queue_snapshot($now);
yes($s['at']==='2026-11-22 12:00:00'&&!$s['smtp_configured'],'snapshot uses injected UTC clock and configuration only');
foreach(['mail','webhooks'] as $group){yes($s[$group]['total']===0,'empty '.$group.' returns integer zero');yes(array_filter($s[$group],fn($v,$k)=>str_starts_with($k,'oldest_')&&$v!==null,ARRAY_FILTER_USE_BOTH)===[],'empty '.$group.' has no fabricated oldest timestamp');}
health_fixture($now);$before=health_state();$s=admin_queue_snapshot($now);$m=$s['mail'];$w=$s['webhooks'];
yes($m['total']===14&&$m['queued']===5&&$m['sending']===5,'mail total and active state counts exact');
yes($m['unknown']===1&&$m['sent']===1&&$m['skipped']===1&&$m['other']===1,'mail terminal and unrecognized states exact');
yes($m['stale_sending']===1,'sending strictly older than ten minutes, not equal boundary');
yes($m['bad_open_time']===6,'missing malformed and future active mail times are visible');
yes($m['oldest_queued']==='2026-11-22 11:40:00','oldest queued excludes malformed and future timestamps');
yes($w['total']===13&&$w['pending']===2&&$w['failed']===8&&$w['dead']===1&&$w['delivered']===1&&$w['other']===1,'webhook retained states sum correctly');
yes($w['due_unlocked']===2,'due-at-now with no lock or expired lock is counted');
yes($w['due_locked']===2,'lock equal to now remains active, as in worker');
yes($w['unscheduled']===1&&$w['bad_open_time']===2,'missing schedule and malformed schedule or lock are separate diagnostics');
yes($w['expired_locks']===2,'expired non-delivered locks exclude delivered rows');
yes($w['oldest_due']==='2026-11-22 11:59:00','oldest due includes due-but-locked work, never last-attempt completion');
yes(health_state()===$before,'snapshot leaves every delivery and audit row unchanged');
yes(!str_contains(json_encode($s),'PRIVATE-'),'snapshot includes no private columns or raw status strings');
$second=new PDO('sqlite:'.config('db_path'));$second->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$second->exec('PRAGMA busy_timeout=500;BEGIN IMMEDIATE;CREATE TABLE health_writer_probe(id INTEGER);COMMIT');
yes(true,'all snapshot read cursors and transaction released before return');
// Canonical comparison must reject normalized calendar mistakes and offset/ISO variants.
$d->exec("UPDATE mail_outbox SET created_at='2026-02-30 00:00:00' WHERE status='queued';UPDATE webhook_deliveries SET next_attempt_at='2026-11-22T11:00:00' WHERE status='failed'");$s=admin_queue_snapshot($now);
yes($s['mail']['oldest_queued']===null&&$s['mail']['bad_open_time']===8,'nonexistent calendar dates are not displayed as valid queue age');
yes($s['webhooks']['due_unlocked']===0&&$s['webhooks']['due_locked']===0&&$s['webhooks']['bad_open_time']===8,'noncanonical schedule strings are flagged instead of counted due');
echo "$n health domain assertions passed\n";
'''
 (A/'domain.php').write_text(domain);p=subprocess.run([PHP,'domain.php'],cwd=A,capture_output=True,text=True);print(p.stdout,end='');assert p.returncode==0,p.stderr
 sql("db()->exec('DELETE FROM mail_outbox;DELETE FROM webhook_deliveries');")
 (A/'clock-reader.php').write_text("<?php require 'public/index.php';db();file_put_contents('reader-ready','1');while(!is_file('reader-go'))usleep(1000);echo json_encode(admin_queue_snapshot());")
 (A/'clock-writer.php').write_text("<?php require 'public/index.php';$d=db();$d->exec('BEGIN EXCLUSIVE');file_put_contents('writer-locked','1');while(!is_file('writer-release'))usleep(1000);$d->prepare(\"INSERT INTO mail_outbox(recipient,subject,body,category,dedup_key,created_at)VALUES('clock@local.invalid','','','transactional','clock-test',?)\")->execute([gmdate('Y-m-d H:i:s')]);$d->exec('COMMIT');")
 def waitfile(name):
  end=time.monotonic()+12
  while not (A/name).exists():
   assert time.monotonic()<end,name+' timeout';time.sleep(.005)
 reader=subprocess.Popen([PHP,'clock-reader.php'],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True);writer=None
 try:
  waitfile('reader-ready');writer=subprocess.Popen([PHP,'clock-writer.php'],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True);waitfile('writer-locked');(A/'reader-go').write_text('1');time.sleep(1.1)
  check(reader.poll() is None,'snapshot waits for exclusive writer before taking its live clock')
  (A/'writer-release').write_text('1');_,e=writer.communicate(timeout=12);assert writer.returncode==0,e;s,e=reader.communicate(timeout=12);assert reader.returncode==0,e;clock=json.loads(s)
  check(clock['mail']['queued']==1 and clock['mail']['bad_open_time']==0 and clock['mail']['oldest_queued']<=clock['at'],'newly committed row is not falsely classified as future after writer wait')
 finally:
  for p in [reader,writer]:
   if p is not None and p.poll() is None:p.kill();p.wait()
 sql("require 'fixture.php';health_fixture(time());db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Admin','admin@test.local',password_hash('adminpass1',PASSWORD_DEFAULT)]);")
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None,method=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data,method=method),timeout=15) as r:return r.status,r.read().decode(),r.url,r.headers
  except urllib.error.HTTPError as r:return r.code,r.read().decode(),r.url,r.headers
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,pw):
  c=client();req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':pw});return c
 with (A/'server.log').open('w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(60):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login',timeout=1);break
    except OSError:time.sleep(.05)
   route='/admin/health';admin=login('admin@test.local','adminpass1');owner=login('demo@loyalty.local','demo1234');guest=client();member=client();staff=client()
   assert req('/member/login',member,{'csrf':csrf(member,'/member/login'),'email':'aylin@example.com','password':'kunde1234'})[2].endswith('/wallet'),'member fixture did not sign in'
   assert req('/staff/login',staff,{'csrf':csrf(staff,'/staff/login'),'business_id':1,'name':'Lena','pin':'4321'})[2].endswith('/staff'),'staff fixture did not sign in'
   check('/login' in req(route,guest)[2],'guest must sign in')
   check(req(route,owner)[0]==403,'ordinary owner cannot read platform delivery counts')
   check('/login' in req(route,member)[2] and '/login' in req(route,staff)[2],'member and staff sessions do not grant platform access')
   status,body,_,headers=req(route,admin)
   check(status==200 and '<h1>Versandzustand</h1>' in body,'configured platform admin reads snapshot')
   check(headers['Cache-Control']=='private, no-store','snapshot private no-store')
   check('PRIVATE-' not in body and '<script>' not in body,'no recipients bodies subjects secrets payloads raw errors or raw states rendered')
   check('href="/admin/health"' in req('/admin',admin)[1],'dashboard links separate health page')
   values={k:int(v) for k,v in re.findall(r'data-health-key="([^"]+)">(\d+)</dd>',body)}
   check(values['mail.total']==14 and values['webhooks.total']==13 and values['mail.unknown']==1 and values['webhooks.bad_open_time']==2,'HTTP counters come from real retained fixtures')
   check('Kein Worker-Heartbeat' in body and 'kein Nachweis der Ankunft im Postfach' in body and 'kein Nachweis einer Anzeige auf dem Gerät' in body,'worker inbox and device-display limits visible')
   check('Anlagezeitpunkt ist nicht gespeichert' in body and 'nicht automatisch übernommen' in body,'pending webhook limitation visible rather than invented age or retry')
   check('SMTP nicht konfiguriert' in body and 'Bei gültiger SMTP-Konfiguration markiert' in body,'SMTP configuration requirement is explicit, not represented as worker health')
   check('<form' not in body and 'name="action"' not in body,'no delivery or mutation form exists on snapshot')
   before=sql("require 'fixture.php';echo health_state();")
   for suffix in ['', '?run_due=1&retry=1&action=webhook_ack','?now=0&business_id=2','?action%5B%5D=webhook_retry']:
    check(req(route+suffix,admin)[0]==200,'query parameters cannot select an execution mode')
   status,_,_,headers=req(route,admin,{'csrf':csrf(admin,'/admin'),'action':'webhook_run_due'})
   check(status==405 and headers['Allow']=='GET','valid-CSRF POST cannot run existing admin action here')
   check(req(route,admin,method='HEAD')[0]==405 and req(route,admin,method='OPTIONS')[0]==405,'other methods have no snapshot action')
   check(sql("require 'fixture.php';echo health_state();")==before,'repeated reads and attempted actions change no queue audit settings or device rows')
   cfg=A/'config.php';cfg.write_text(config.replace("['admin@test.local']","[]"));check(req(route,admin)[0]==403,'removing allowlist entry immediately removes access')
   cfg.write_text(config.replace('return $c;',"$c['stripe']['enforce_limits']=true;return $c;"));check(req(route,admin)[0]==200,'read-only platform diagnostics do not require a tenant paid plan')
   cfg.write_text(config.replace('return $c;',"$c['smtp']['enabled']=true;$c['smtp']['host']='smtp.invalid';$c['smtp']['from']='noreply@platform.invalid';return $c;"));check('SMTP-Konfiguration vorhanden' in req(route,admin)[1],'configured flag does not make a connection or prove reachability')
   cfg.write_text(config)
   subprocess.run(['node',str(R/'tests/admin-health-ui.cjs')],cwd=R,check=True)
   check('PRIVATE-' not in (A/'server.log').read_text(),'snapshot logs contain no fixture private data')
  finally:server.terminate();server.wait(timeout=10)
 print(n,'admin health HTTP assertions passed',flush=True)
 print('No provider calls, worker execution or delivery proof.',flush=True)
