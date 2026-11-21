#!/usr/bin/env python3
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='loyalty-demo-') as temp:
 A=pathlib.Path(temp)
 for name in ['app','public']:shutil.copytree(R/name,A/name)
 for name in ['seed.php','config.example.php']:shutil.copy(R/name,A/name)
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 for mode in ['off','env','config']:
  enabled=mode!='off'
  if mode=='config':(A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['demo_banner']=true;return $c;")
  env=dict(os.environ);env.pop('DEMO_MODE',None)
  if mode=='env':env['DEMO_MODE']='1'
  with open(A/'server.log','w') as log:
   server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,env=env,stdout=log,stderr=log)
   try:
    for _ in range(50):
     try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
     except OSError:time.sleep(.1)
    if enabled:subprocess.run(['node',str(R/'tests/demo-ui.cjs')],cwd=R,env=env,check=True)
    else:
     for path in ['/login','/staff/login','/member/login']:
      text=urllib.request.urlopen('http://127.0.0.1:8099'+path).read().decode();assert 'demo-hint' not in text and 'demo1234' not in text,path
     print('PASS all login pages hide demo credentials by default')
   finally:server.terminate();server.wait()
