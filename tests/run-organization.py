#!/usr/bin/env python3
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='loyalty-org-') as temp:
 A=pathlib.Path(temp)
 for n in ['app','public']:shutil.copytree(R/n,A/n)
 for n in ['seed.php','config.example.php']:shutil.copy(R/n,A/n)
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 subprocess.run([PHP,str(R/'tests/organization.php')],cwd=A,check=True)
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 log=open(A/'server.log','w');server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  subprocess.run(['node',str(R/'tests/organization-ui.cjs')],cwd=R,check=True)
  values=subprocess.check_output([PHP,'-r',"require 'public/index.php';$r=db()->query(\"SELECT staff_id,location_id FROM transactions WHERE kind='stamp_add' ORDER BY id DESC LIMIT 1\")->fetch();if(!$r['staff_id']||!$r['location_id'])throw new Exception('Missing attribution');echo 'PASS transaction attribution to staff and location'.PHP_EOL;"],cwd=A,text=True)
  print(values.strip())
  subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
  subprocess.run(['python',str(R/'tests/organization-http.py'),str(A),PHP],cwd=R,check=True)
 finally:server.terminate();server.wait();log.close()
