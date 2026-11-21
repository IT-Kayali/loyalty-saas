#!/usr/bin/env python3
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='loyalty-program-') as temp:
 A=pathlib.Path(temp)
 for n in ['app','public']:shutil.copytree(R/n,A/n)
 for n in ['seed.php','config.example.php']:shutil.copy(R/n,A/n)
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 subprocess.run([PHP,str(R/'tests/programs.php')],cwd=A,check=True)
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 log=open(A/'server.log','w');server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  subprocess.run(['node',str(R/'tests/program-ui.cjs')],cwd=R,check=True)
 finally:server.terminate();server.wait();log.close()
