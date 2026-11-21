#!/usr/bin/env python3
"""Disposable test install. Never changes a developer's config.php or database.
Requires PHP with gd/pdo_sqlite, Node + playwright and Chromium.
Usage: PHP_BIN=/path/to/php python tests/run-browser.py
"""
import os, pathlib, tempfile, shutil, subprocess, time, urllib.request, urllib.error, json, hmac, hashlib
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php')
OUT=pathlib.Path(os.environ.get('TEST_OUTPUT',str(pathlib.Path(tempfile.gettempdir())/'loyalty-browser-results')))
OUT.mkdir(parents=True,exist_ok=True)

def request(path,data=None,headers=None):
    req=urllib.request.Request('http://127.0.0.1:8099'+path,data=data,headers=headers or {})
    try:
        with urllib.request.urlopen(req,timeout=10) as r:return r.status,r.read()
    except urllib.error.HTTPError as e:return e.code,e.read()

with tempfile.TemporaryDirectory(prefix='loyalty-test-') as temp:
    app=pathlib.Path(temp)
    for name in ['app','public'] :shutil.copytree(ROOT/name,app/name)
    for name in ['seed.php','config.example.php']:shutil.copy2(ROOT/name,app/name)
    cfg="<?php $c=require __DIR__.'/config.example.php'; $c['platform_admin_emails']=['demo@loyalty.local']; return $c;"
    (app/'config.php').write_text(cfg)
    subprocess.run([PHP,str(app/'seed.php')],cwd=app,check=True,capture_output=True)
    setup=r'''require 'public/index.php'; $m=db()->query("SELECT code FROM members WHERE name='Aylin Demir' LIMIT 1")->fetch();file_put_contents(getenv('TEST_OUTPUT').'/test-member.json',json_encode($m));$im=imagecreatetruecolor(128,128);imagesavealpha($im,true);imagefill($im,0,0,imagecolorallocate($im,228,87,46));$white=imagecolorallocate($im,255,247,231);imagefilledellipse($im,60,59,60,60,$white);imagearc($im,88,59,33,32,260,100,$white);imagefilledrectangle($im,30,98,96,106,$white);imagepng($im,getenv('TEST_OUTPUT').'/stamp-test.png');imagedestroy($im);'''
    env={**os.environ,'TEST_OUTPUT':str(OUT)}
    subprocess.run([PHP,'-r',setup],cwd=app,env=env,check=True)
    log=open(OUT/'server.log','w')
    server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=app,stdout=log,stderr=log)
    try:
        for _ in range(50):
            try:request('/login');break
            except (OSError,urllib.error.URLError):time.sleep(.1)
        subprocess.run(['node',str(ROOT/'tests/browser.cjs')],cwd=ROOT,env=env,check=True)
        # Enable fake nonempty keys only for local signature/transport tests.
        # These events do not trigger any network call to Stripe.
        (app/'config.php').write_text(cfg.replace('return $c;',"$c['stripe']['secret_key']='test-only-not-a-real-key';$c['stripe']['webhook_secret']='transport-test-secret';return $c;"))
        raw=json.dumps({'id':'evt_transportunit','type':'unit.ignored','data':{'object':{}}},separators=(',',':')).encode()
        ts=int(time.time());sig=hmac.new(b'transport-test-secret',str(ts).encode()+b'.'+raw,hashlib.sha256).hexdigest()
        header={'Stripe-Signature':f't={ts},v1={sig}','Content-Type':'application/json'}
        assert request('/stripe/webhook',raw,header)[0]==200
        assert request('/stripe/webhook',raw,header)[0]==200
        assert request('/stripe/webhook',raw+b' ',header)[0]==400
        assert request('/stripe/webhook',raw,{})[0]==400
        assert request('/stripe/webhook')[0]==405
        count=subprocess.check_output([PHP,'-r',"require 'app/bootstrap.php';echo db()->query('SELECT COUNT(*) FROM stripe_events')->fetchColumn();"],cwd=app,text=True)
        assert count=='1','Duplicate event must be persisted only once'
        print('PASS webhook HTTP signature, duplicate, tamper, missing signature, method restriction')
        # Render precisely the raster image included in Apple and Google payloads.
        subprocess.run([PHP,'-r',"require 'public/index.php';$code=db()->query(\"SELECT code FROM members WHERE name='Aylin Demir' LIMIT 1\")->fetchColumn();$m=pass_member($code);file_put_contents(getenv('TEST_OUTPUT').'/wallet-stamp-strip.png',stamp_strip_png($m));if(empty(google_pass_payload($m)['object']['heroImage']))throw new Exception('Missing heroImage');"],cwd=app,env=env,check=True)
    finally:
        server.terminate();server.wait(timeout=10);log.close()
print('Results:',OUT)
