#!/usr/bin/env python3
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='loyalty-support-') as temp:
 A=pathlib.Path(temp)
 for n in ['app','public']:shutil.copytree(R/n,A/n)
 for n in ['seed.php','config.example.php']:shutil.copy(R/n,A/n)
 (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['admin@test.local'];return $c;")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 subprocess.run([PHP,str(R/'tests/support.php')],cwd=A,check=True)
 # Fake SMTP only queues rows in the outbox for inspection. No worker or transport runs.
 (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['admin@test.local'];$c['support_email']='ops@support-operator.invalid';$c['smtp']=['enabled'=>true,'host'=>'smtp.invalid','port'=>587,'from'=>'noreply@platform.invalid']+$c['smtp'];return $c;")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 log=open(A/'server.log','w');server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  subprocess.run(['python3',str(R/'tests/support-http.py'),str(A),PHP],cwd=R,check=True)
  subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
  subprocess.run([PHP,'-r',"require 'public/index.php';db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Admin','admin@test.local',password_hash('adminpass1',PASSWORD_DEFAULT)]);"],cwd=A,check=True)
  subprocess.run(['node',str(R/'tests/support-ui.cjs')],cwd=R,check=True)
 finally:server.terminate();server.wait();log.close()
