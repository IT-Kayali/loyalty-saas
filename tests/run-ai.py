#!/usr/bin/env python3
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php')
def serve(A):
 log=open(A/'server.log','a');s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 for _ in range(50):
  try:urllib.request.urlopen('http://127.0.0.1:8099/login');return s
  except OSError:time.sleep(.1)
 raise SystemExit('server did not start')
CFG="<?php $c=require __DIR__.'/config.example.php';$c['ai_enabled']=%s;return $c;"
with tempfile.TemporaryDirectory(prefix='loyalty-ai-') as temp:
 A=pathlib.Path(temp)
 for n in ['app','public']:shutil.copytree(R/n,A/n)
 for n in ['seed.php','config.example.php']:shutil.copy(R/n,A/n)
 (A/'config.php').write_text(CFG%'false');subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 s=serve(A)
 try:
  try:urllib.request.urlopen('http://127.0.0.1:8099/mcp');raise SystemExit('mcp must be off by default')
  except urllib.error.HTTPError as e:assert e.code==404,e.code
  print('PASS mcp endpoint is off by default')
 finally:s.terminate();s.wait()
 (A/'config.php').write_text(CFG%'true');subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 s=serve(A)
 try:
  subprocess.run(['python3',str(R/'tests/ai-http.py'),str(A),PHP],cwd=R,check=True)
  subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
  subprocess.run(['node',str(R/'tests/ai-ui.cjs')],cwd=R,check=True)
 finally:s.terminate();s.wait()
