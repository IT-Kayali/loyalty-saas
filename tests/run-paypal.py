#!/usr/bin/env python3
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php')
subprocess.run([PHP,str(R/'tests/paypal.php')],cwd=R,check=True)
with tempfile.TemporaryDirectory(prefix='loyalty-paypal-') as temp:
 A=pathlib.Path(temp)
 for n in ['app','public']:shutil.copytree(R/n,A/n)
 for n in ['seed.php','config.example.php']:shutil.copy(R/n,A/n)
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 log=open(A/'server.log','w');server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  subprocess.run(['node',str(R/'tests/paypal-ui.cjs')],cwd=R,check=True)
  for method,status in [('GET',405),('POST',503)]:
   try:urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8099/paypal/webhook',data=b'{}' if method=='POST' else None));raise Exception('Wrong webhook status')
   except urllib.error.HTTPError as e:assert e.code==status,(method,e.code)
  print('PASS webhook method and missing-configuration restrictions')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['paypal']['client_id']='local-test';$c['paypal']['secret']='local-test-only';$c['paypal']['webhook_id']='local-hook';$c['paypal']['plans']['starter']['plan_id']='P-AAAAAAAAAAAAAAAAAAAAAAAA';$c['paypal']['plans']['pro']['plan_id']='P-BBBBBBBBBBBBBBBBBBBBBBBB';return $c;")
  (A/'provider.json').write_text('{"calls":[],"requests":{},"subs":{}}')
  shutil.copy(R/'tests/paypal-adapter.php',A/'test-provider.php')
  (A/'tools').mkdir(exist_ok=True);shutil.copy(R/'tools/paypal-reconcile.php',A/'tools/paypal-reconcile.php')
  paypal=A/'app/inc/paypal.php';paypal.write_text(paypal.read_text().replace('function paypal_request(', 'function paypal_request_network_unused('))
  index=A/'public/index.php';index.write_text(index.read_text().replace("require_once ROOT_DIR . '/app/inc/paypal.php';","require_once ROOT_DIR . '/test-provider.php';require_once ROOT_DIR . '/app/inc/paypal.php';"))
  subprocess.run(['python',str(R/'tests/paypal-http.py'),str(A),PHP],cwd=R,check=True)
  subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
  (A/'provider.json').write_text('{"calls":[],"requests":{},"subs":{},"auto_approve":true}')
  subprocess.run(['node',str(R/'tests/paypal-sdk.cjs')],cwd=R,check=True)

 finally:server.terminate();server.wait();log.close()
