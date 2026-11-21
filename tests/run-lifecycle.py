#!/usr/bin/env python3
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php')
def req(path,data=None,c=None):
 if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
 q=urllib.request.Request('http://127.0.0.1:8099'+path,data=data)
 try:
  with(c or urllib.request.build_opener()).open(q,timeout=15)as x:return x.status,x.read().decode()
 except urllib.error.HTTPError as e:return e.code,e.read().decode()
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c=c)[1]).group(1)
with tempfile.TemporaryDirectory(prefix='loyalty-life-') as temp:
 A=pathlib.Path(temp)
 for n in ['app','public']:shutil.copytree(R/n,A/n)
 for n in ['seed.php','config.example.php']:shutil.copy(R/n,A/n)
 # No transport runs against this fake server. PHP assertions use an in-process adapter.
 (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['smtp']['enabled']=true;$c['smtp']['host']='smtp.example.test';$c['smtp']['from']='sender@example.test';return $c;")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 subprocess.run([PHP,str(R/'tests/lifecycle.php')],cwd=A,check=True)
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 shutil.copytree(R/'tools',A/'tools')
 setup="require 'public/index.php';$db=db();$a=(int)$db->query(\"SELECT id FROM members WHERE email='aylin@example.com' AND business_id=1\")->fetchColumn();$db->prepare('UPDATE members SET marketing_consent=1,birthday=? WHERE id=?')->execute(['1990-'.gmdate('m-d'),$a]);$db->exec(\"INSERT INTO mail_automations(business_id,event,subject,body,channel)VALUES(1,'birthday','CLI','Hallo {name}','inbox')\");"
 subprocess.run([PHP,'-r',setup],cwd=A,check=True)
 out=subprocess.run([PHP,'tools/mail-worker.php'],cwd=A,check=True,capture_output=True,text=True).stdout
 assert '"automation_delivered":1' in out,out
 assert subprocess.run([PHP,'tools/mail-worker.php'],cwd=A,check=True,capture_output=True,text=True).stdout.count('"automation_delivered":0')==1
 print('PASS Worker ohne SMTP liefert Inbox-Automation genau einmal')
 subprocess.run([PHP,'-r',"require 'public/index.php';foreach(['automation_runs','member_messages','mail_automations'] as $t)db()->exec('DELETE FROM '.$t);"],cwd=A,check=True)
 subprocess.run([PHP,str(R/'tests/lifecycle-inbox.php')],cwd=A,check=True)
 log=open(A/'server.log','w');server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:req('/login');break
   except OSError:time.sleep(.1)
  subprocess.run(['node',str(R/'tests/lifecycle-ui.cjs')],cwd=R,check=True)
 finally:server.terminate();server.wait();log.close()
