#!/usr/bin/env python3
"""Analytics: Code-Gutschein-Einlösungen und responsive Programm-Tabelle. Wegwerf-Server."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json,uuid
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-vb-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public','tools']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 log=open(A/'server.log','w');s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  def mk():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  def req(path,data=None,c=None):
   if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
   try:
    with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode('utf-8','replace'),x.url
   except urllib.error.HTTPError as x:return x.code,x.read().decode('utf-8','replace'),x.url
  def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c=c)[1]).group(1)
  o=mk();req('/login',{'csrf':csrf(o,'/login'),'email':'demo@loyalty.local','password':'demo1234'},o)
  M=mk();req('/member/login',{'csrf':csrf(M,'/member/login'),'email':'aylin@example.com','password':'kunde1234'},M)
  pid=int(sql("db()->exec(\"INSERT INTO programs(business_id,type,name)VALUES(1,'voucher','Lange Gutscheinaktion mit Einlösung an der Kasse')\");echo db()->lastInsertId();"))
  mid=int(sql("echo db()->query(\"SELECT id FROM members WHERE business_id=1 AND email='aylin@example.com'\")->fetchColumn();"))
  sql("$r=voucher_rules([]);$r['per_member']=10;$q=db()->prepare('INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot,max_uses)VALUES(?,?,?,?,10)');$q->execute([%d,'Test','0123456789ABCDEF12345678',json_encode($r)]);"%pid)
  P='/business/1/analytics'
  def total():
   h=req(P,c=o)[1];return int(re.search(r'data-analytics-kpi="Einlösungen"[^>]*>\s*<div[^>]*>([0-9]+)',h).group(1))
  def redeem(op):return sql("echo json_encode(voucher_redeem('0123456789ABCDEF12345678',%d,2000,['kind'=>'owner','id'=>1],'%s'));"%(mid,op))
  base=total();op=uuid.uuid4().hex;redeem(op)
  check(total()==base+1,'Code-Gutschein erhöht Gesamt-Einlösungen um eins')
  h=req(P,c=o)[1];row=re.search(r'<tr><td[^>]*>Lange Gutscheinaktion.*?</tr>',h,re.S).group(0)
  check(re.findall(r'<td[^>]*>([0-9]+)</td>',row)==['1','1','1'],'Programm zeigt eine Karte, eine Aktion, eine Einlösung')
  redeem(op);check(total()==base+1,'Idempotente Wiederholung zählt nicht doppelt')
  sql("db()->exec(\"UPDATE voucher_codes SET status='cancelled' WHERE program_id=%d\");"%pid)
  check(total()==base+1,'Batchsperre entfernt keine vergangene Einlösung')
  sql("db()->exec(\"UPDATE voucher_codes SET status='active' WHERE program_id=%d\");"%pid)
  cid=int(sql("echo db()->query('SELECT id FROM cards WHERE program_id=%d')->fetchColumn();"%pid))
  sql("db()->exec(\"UPDATE transactions SET reversed_at=datetime('now') WHERE card_id=%d\");"%cid)
  check(total()==base,'Als storniert markierte Transaktion ausgeschlossen')
  sql("db()->exec(\"UPDATE transactions SET reversed_at=NULL WHERE card_id=%d\");"%cid)
  # Existing redemption families remain counted, but reversal rows themselves do not.
  for kind in ['reward_redeem','points_redeem','voucher_use']:
   sql("txn(%d,'%s',1,'Test',['kind'=>'owner','id'=>1]);"%(cid,kind))
  check(total()==base+4,'Bestehende Einlösungsarten weiter enthalten')
  sql("txn(%d,'reversal',1,'Test',['kind'=>'owner','id'=>1]);"%cid)
  check(total()==base+4,'Reversal-Ereignis nicht als Einlösung gezählt')
  foreigncard=int(sql("echo db()->query('SELECT c.id FROM cards c JOIN programs p ON p.id=c.program_id WHERE p.business_id=2 LIMIT 1')->fetchColumn();"))
  sql("txn(%d,'voucher_code_use',1,'Fremdes Unternehmen',['kind'=>'owner','id'=>1]);"%foreigncard)
  check(total()==base+4,'Anderes Unternehmen fließt nicht ein')
  check('/login' in req(P,c=mk())[2],'Analytics benötigt Betreiberlogin')
  sql("$q=db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)');$q->execute(['Fremd','foreign@example.com',password_hash('test123456',PASSWORD_DEFAULT)]);")
  outsider=mk();req('/login',{'csrf':csrf(outsider,'/login'),'email':'foreign@example.com','password':'test123456'},outsider)
  check(req(P,c=outsider)[0]==404,'Fremder Betreiber bekommt 404')
  check('voucher_code_use' not in req(P,c=M)[1] and '/login' in req(P,c=M)[2],'Mitglieder bekommen keine Analytics')
  # Old events count in all-time KPIs, the chart keeps its own 30-day window.
  sql("db()->exec(\"UPDATE transactions SET created_at='2020-01-01 00:00:00' WHERE card_id=%d AND kind='voucher_code_use'\");"%cid)
  check(total()==base+4,'Historische Einlösung weiterhin in Allzeit-Kennzahl')
  check('letzten 30 UTC-Tage' in req(P,c=o)[1],'Unterschiedliche Zeiträume ausdrücklich beschrieben')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  check(req(P,c=o)[0]==200,'Analytics bleibt mit Planprüfung lesbar')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  subprocess.run(['node',str(R/'tests/analytics-ui.cjs'),str(base+4)],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Analytics-Prüfungen bestanden')
