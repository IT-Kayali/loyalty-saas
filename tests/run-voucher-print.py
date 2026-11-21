#!/usr/bin/env python3
"""Private read-only batch print/QR fixture, no external services."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-voucher-print-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(c):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+c],cwd=A,text=True).strip()
 pid=int(sql("echo db()->query(\"SELECT id FROM programs WHERE business_id=1 AND type='voucher' LIMIT 1\")->fetchColumn();"))
 sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Foreign','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);$r=voucher_rules([]);$q=db()->prepare('INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot)VALUES(?,?,?,?)');for($i=1;$i<=8;$i++)$q->execute([%d,'Print & Test',strtoupper(str_pad(dechex($i),24,'0',STR_PAD_LEFT)),json_encode($r)]);$q->execute([%d,'Print & Test','LEGACY','{}']);"%(pid,pid))
 # Rows that must never become a transferable QR.
 sql("$r=voucher_rules([]);$q=db()->prepare('INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot,member_id,status,used)VALUES(?,?,?,?,?,?,?)');$q->execute([%d,'Print & Test',str_repeat('A',24),json_encode($r),1,'active',0]);$q->execute([%d,'Print & Test',str_repeat('B',24),json_encode($r),null,'cancelled',0]);$q->execute([%d,'Print & Test',str_repeat('C',24),json_encode($r),null,'active',1]);$r['ends']='2000-01-01';$q->execute([%d,'Print & Test',str_repeat('D',24),json_encode($r),null,'active',0]);$r['ends']=null;$r['starts']='2099-01-01';$q->execute([%d,'Print & Test',str_repeat('E',24),json_encode($r),null,'active',0]);"%((pid,)*5))
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,pw):
  c=client();req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':pw});return c
 def effects():return sql("echo json_encode([db()->query('SELECT * FROM voucher_codes')->fetchAll(),db()->query('SELECT * FROM voucher_redemptions')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM member_messages')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM audit_logs')->fetchAll()]);")
 P='/program/%d/voucher-print?batch=Print+%%26+Test'%pid
 with open(A/'server.log','w') as log:
  s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
    except OSError:time.sleep(.1)
   o=login('demo@loyalty.local','demo1234');f=login('foreign@test.local','foreignpass1');m=client();req('/member/login',m,{'csrf':csrf(m,'/member/login'),'email':'aylin@example.com','password':'kunde1234'})
   check('/login' in req(P,client())[2],'guest must sign in')
   check(req(P,f)[0]==404,'foreign owner cannot export')
   check('/login' in req(P,m)[2],'member cannot export batch')
   before=effects();st,h,_,headers=req(P,o)
   check(st==200 and h.count('class="voucher-sheet-card"')==8 and '8 von 14' in h,'only eight eligible untouched unbound rows rendered')
   for code in [str(c)*24 for c in 'ABCDE']:
    check(code not in h,'personal cancelled used expired future code absent')
   check('aylin@example.com' not in h and 'Aylin' not in h and 'member_id' not in h,'no member identifiers in printable output')
   check(headers['Cache-Control']=='private, no-store' and headers['Referrer-Policy']=='no-referrer','private response and no referrer')
   check("connect-src 'none'" in headers['Content-Security-Policy'] and 'http' not in ''.join(re.findall(r'(?:src|href)="([^"]+)"',h)),'QR assets are local and network connections disallowed')
   check('Print &amp; Test' in h,'exact batch name escaped')
   check('voucher-print?batch=Print+%26+Test' in req('/program/%d'%pid,o)[1],'program batch links exact encoded batch')
   for bad in ['','?batch=unknown','?batch%5B%5D=x','?batch='+('a'*81),'?batch=%00']:
    check(req('/program/%d/voucher-print'%pid+bad,o)[0]in(404,422),'missing unknown or invalid batch rejected')
   check(req(P,o,{'csrf':csrf(o,'/program/%d'%pid)})[0]==405,'POST cannot print or mutate')
   sql('db()->exec("UPDATE programs SET active=0 WHERE id=%d");'%pid)
   check('class="voucher-sheet-card"' not in req(P,o)[1] and 'Keine verteilbaren Codes' in req(P,o)[1],'inactive program produces no distributable QR')
   sql('db()->exec("UPDATE programs SET active=1 WHERE id=%d");'%pid)
   check(effects()==before,'print reads change no codes redemption balances mail audit')
   (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
   check(req(P,o)[0]==200,'existing codes readable without paid write plan')
   (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
   sql("$r=json_encode(voucher_rules([]));$q=db()->prepare('INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot)VALUES(?,?,?,?)');for($i=1000;$i<1501;$i++)$q->execute([%d,'Too large',strtoupper(str_pad(dechex($i),24,'0',STR_PAD_LEFT)),$r]);"%pid)
   st,h,_,_=req('/program/%d/voucher-print?batch=Too+large'%pid,o);check(st==422 and 'class="voucher-sheet-card"' not in h,'501 rows rejected without partial print')
   sql("db()->exec(\"DELETE FROM voucher_codes WHERE code='0000000000000000000005DC'\");") # 1500 decimal, last fixture
   check(req('/program/%d/voucher-print?batch=Too+large'%pid,o)[1].count('class="voucher-sheet-card"')==500,'exact 500 cap accepted')
   sql("$r=voucher_rules([]);$q=db()->prepare('INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot)VALUES(?,?,?,?)');$bad=$r;$bad['starts']=['invalid'];$q->execute([%d,'Broken rules',str_repeat('F',24),json_encode($bad)]);$bad=$r;$bad['value']='999';$q->execute([%d,'Broken rules',str_repeat('9',24),json_encode($bad)]);$bad=$r;$bad['ends']='2026-02-30';$q->execute([%d,'Broken rules',str_repeat('8',24),json_encode($bad)]);"%((pid,)*3))
   check('class="voucher-sheet-card"' not in req('/program/%d/voucher-print?batch=Broken+rules'%pid,o)[1],'malformed snapshot types and dates fail closed without QR')
   sql("$r=voucher_rules([]);$r['type']='product';$r['value']=0;$r['item']='Kaffee';$q=db()->prepare('INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot)VALUES(?,?,?,?)');$q->execute([%d,'Product',str_repeat('7',24),json_encode($r)]);"%pid)
   check(req('/program/%d/voucher-print?batch=Product'%pid,o)[1].count('class="voucher-sheet-card"')==1,'valid product with zero numeric value remains printable')
   subprocess.run(['node',str(R/'tests/voucher-print-ui.cjs'),str(pid)],cwd=R,check=True)
  finally:s.terminate();s.wait()
print(n,'voucher print HTTP/domain assertions passed')
