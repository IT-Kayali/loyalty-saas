#!/usr/bin/env python3
"""Disposable PHP server and Chromium online/offline checks. No provider accounts."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='loyalty-pwa-') as temp:
 A=pathlib.Path(temp)
 for name in ['app','public']:shutil.copytree(R/name,A/name)
 for name in ['seed.php','config.example.php']:shutil.copy(R/name,A/name)
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 with open(A/'server.log','w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
    except OSError:time.sleep(.1)
   subprocess.run(['node',str(R/'tests/pwa-ui.cjs')],cwd=R,check=True)
  finally:server.terminate();server.wait()
