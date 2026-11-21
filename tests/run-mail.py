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
with tempfile.TemporaryDirectory(prefix='loyalty-mail-') as temp:
 A=pathlib.Path(temp)
 for n in ['app','public']:shutil.copytree(R/n,A/n)
 for n in ['seed.php','config.example.php']:shutil.copy(R/n,A/n)
 # No transport runs against this fake server. PHP assertions use an in-process adapter.
 (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['smtp']['enabled']=true;$c['smtp']['host']='smtp.example.test';$c['smtp']['from']='sender@example.test';return $c;")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 subprocess.run([PHP,str(R/'tests/mail.php')],cwd=A,check=True)
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 log=open(A/'server.log','w');server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:req('/login');break
   except OSError:time.sleep(.1)
  subprocess.run(['node',str(R/'tests/mail-ui.cjs')],cwd=R,check=True)
  old=client();req('/login',{'csrf':csrf(old,'/login'),'email':'demo@loyalty.local','password':'demo1234'},old)
  c=client();t=csrf(c,'/forgot-password');req('/forgot-password',{'csrf':t,'kind':'owner','email':'demo@loyalty.local'},c)
  raw=subprocess.check_output([PHP,'-r',"require 'public/index.php';echo db()->query(\"SELECT body FROM mail_outbox WHERE category='transactional' ORDER BY id DESC LIMIT 1\")->fetchColumn();"],cwd=A,text=True)
  token=re.search(r'token=([a-f0-9]{64})',raw).group(1)
  tk=csrf(c,'/reset-password?token='+token)
  status,body=req('/reset-password',{'csrf':tk,'token':token,'password':'new-test-password-123'},c)
  assert status==200 and 'Passwort geändert' in body
  assert 'Link ungültig' in req('/reset-password?token='+token,c=c)[1]
  req('/login',{'csrf':csrf(c,'/login'),'email':'demo@loyalty.local','password':'new-test-password-123'},c)
  assert 'Deine Unternehmen' in req('/dashboard',c=c)[1]
  assert 'Passwort' in req('/dashboard',c=old)[1] and 'Deine Unternehmen' not in req('/dashboard',c=old)[1]
  unsub=subprocess.check_output([PHP,'-r',"require 'public/index.php';echo unsubscribe_token(1);"],cwd=A,text=True)
  path='/unsubscribe/1/'+unsub
  before=subprocess.check_output([PHP,'-r',"require 'public/index.php';echo db()->query('SELECT marketing_consent FROM members WHERE id=1')->fetchColumn();"],cwd=A,text=True)
  assert before=='1'
  tk=csrf(c,path)
  afterget=subprocess.check_output([PHP,'-r',"require 'public/index.php';echo db()->query('SELECT marketing_consent FROM members WHERE id=1')->fetchColumn();"],cwd=A,text=True)
  assert afterget=='1','GET must not unsubscribe automatically'
  assert req(path,{'csrf':tk},c)[0]==200
  afterpost=subprocess.check_output([PHP,'-r',"require 'public/index.php';echo db()->query('SELECT marketing_consent FROM members WHERE id=1')->fetchColumn();"],cwd=A,text=True)
  assert afterpost=='0'
  print('PASS old session revoked, unsubscribe GET safe and confirmed POST applied')
  print('PASS reset HTTP flow, one-time token and new-password sign-in; no mail sent externally')
 finally:server.terminate();server.wait();log.close()
