#!/usr/bin/env python3
"""Policy assertions and real HTTP coverage in a throw-away install."""
import os,pathlib,tempfile,shutil,subprocess,json,time,urllib.request,urllib.error,http.cookiejar,re,hmac,hashlib,base64
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');count=0

def call(code):
    return subprocess.check_output([PHP,'-r',code],cwd=A,text=True)

def req(path,data=None,client=None,headers=None):
    if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
    q=urllib.request.Request('http://127.0.0.1:8099'+path,data=data,headers=headers or {})
    try:
        with (client or urllib.request.build_opener()).open(q,timeout=15) as x:return x.status,x.read().decode()
    except urllib.error.HTTPError as x:return x.code,x.read().decode()

def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def csrf(c,path):
    code,body=req(path,client=c);return re.search(r'name="csrf" value="([a-f0-9]+)"',body).group(1)
def check(ok,name):
    global count
    assert ok,name;count+=1;print('PASS',name)

with tempfile.TemporaryDirectory(prefix='loyalty-policy-') as temp:
    A=pathlib.Path(temp)
    for n in ['app','public']:shutil.copytree(R/n,A/n)
    for n in ['seed.php','config.example.php']:shutil.copy2(R/n,A/n)
    config="<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['demo@loyalty.local'];$c['stripe']['enforce_limits']=false;$c['stripe']['plans']['starter']['limits']=['businesses'=>2,'programs'=>5,'members'=>4,'staff'=>2];return $c;"
    (A/'config.php').write_text(config)
    subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
    (A/'config.php').write_text(config.replace("enforce_limits']=false","enforce_limits']=true"))
    subprocess.run([PHP,str(R/'tests/policy.php')],cwd=A,check=True)
    (A/'config.php').write_text(config)
    subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
    subprocess.run([PHP,str(R/'tests/transactions.php')],cwd=A,check=True)
    subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
    details=json.loads(call("require 'public/index.php';db()->exec(\"UPDATE businesses SET shopify_secret='local-test-only' WHERE id=1\");echo json_encode(['key'=>db()->query('SELECT api_key FROM businesses WHERE id=1')->fetchColumn(),'join'=>db()->query('SELECT join_code FROM businesses WHERE id=1')->fetchColumn()]);"))
    (A/'config.php').write_text(config.replace("enforce_limits']=false","enforce_limits']=true"))
    logfile=open(A/'server.log','w');server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=logfile,stderr=logfile)
    try:
        for i in range(50):
            try:req('/login');break
            except OSError:time.sleep(.1)
        subprocess.run(['node',str(R/'tests/policy-ui.cjs')],cwd=R,check=True)
        owner=client();token=csrf(owner,'/login');check(req('/login',{'email':'demo@loyalty.local','password':'demo1234','csrf':token},owner)[0]==200,'login available with inactive subscription')
        token=csrf(owner,'/billing')
        paths=['/business/create','/program/create','/member/create','/staff/create','/reward/create','/card/issue','/card/1/action','/card/1/undo','/tier/create','/bonus/create','/achievement/create','/segment/create','/campaign/create','/campaign/send','/business/referral','/apikey/regenerate','/webhook/create','/integration/save','/business/1/design']
        for path in paths:check(req(path,{'csrf':token,'business_id':1},owner)[0]==402,'inactive owner write denied '+path)
        check(req('/billing',{'csrf':token,'action':'checkout','plan':'starter'},owner)[0]==200,'billing remains reachable')
        check(req('/business/1',client=owner)[0]==200,'read business remains reachable')
        staff=client();tokenS=csrf(staff,'/staff/login');check(req('/staff/login',{'csrf':tokenS,'business_id':1,'name':'Lena','pin':'4321'},staff)[0]==200,'staff login remains reachable')
        for path in ['/staff/card/1/action','/staff/card/1/undo']:check(req(path,{'csrf':tokenS,'action':'stamp'},staff)[0]==402,'inactive staff write denied '+path)
        tokenJ=csrf(owner,'/join/'+details['join']);check(req('/join/'+details['join'],{'csrf':tokenJ,'name':'Denied','email':'x@test.local','password':'test-only-pass'},owner)[0]==402,'inactive join denied')
        headers={'Authorization':'Bearer '+details['key'],'Content-Type':'application/json'}
        for path in ['/api/v1/members','/api/v1/cards/1/points','/api/v1/integrations/order']:
            check(req(path,b'{}',headers=headers)[0]==402,'inactive API write denied '+path)
        check(req('/api/v1/members',headers=headers)[0]==200,'API read allowed')
        raw=b'{"id":1,"email":"api@test.local","total_price":"10.00"}'
        sig=base64.b64encode(hmac.new(b'local-test-only',raw,hashlib.sha256).digest()).decode()
        sh={'X-Shopify-Hmac-Sha256':sig,'X-Shopify-Topic':'orders/paid','Content-Type':'application/json'}
        check(req('/integrations/shopify/webhook/1',raw,headers=sh)[0]==402,'inactive signed Shopify denied')
        call("require 'public/index.php';db()->exec(\"INSERT INTO subscriptions(user_id,status,plan_key)VALUES(1,'active','starter') ON CONFLICT(user_id) DO UPDATE SET status='active',plan_key='starter'\");")
        check(req('/api/v1/members',b'{}',headers=headers)[0]==402,'active Starter still denies disabled API')
        check(req('/integrations/shopify/webhook/1',raw,headers=sh)[0]==402,'active Starter denies integration')
        check(req('/business/create',{'csrf':token,'name':'Over cap'},owner)[0]==402,'DB quantity cap appears as safe HTTP 402')
        call("require 'public/index.php';db()->exec(\"UPDATE subscriptions SET plan_key='pro' WHERE user_id=1\");")
        check(req('/business/create',{'csrf':token,'name':'After upgrade'},owner)[0]==200,'upgrade unlocks owner write')
        check(req('/api/v1/members',b'{"name":"API permitted","email":"ok@test.local"}',headers=headers)[0]==201,'upgrade unlocks API write')
        check(req('/integrations/shopify/webhook/1',raw,headers=sh)[0]==200,'upgrade unlocks Shopify')
        bad=client();tk=csrf(bad,'/login');last=0
        for i in range(11):last=req('/login',{'csrf':tk,'email':'missing@test.local','password':'wrong'},bad)[0]
        check(last==429,'eleventh targeted auth attempt rate limited')
        existing=int(call("require 'public/index.php';echo db()->query('SELECT COUNT(*) FROM businesses WHERE user_id=1')->fetchColumn();"))
        (A/'config.php').write_text(config.replace("enforce_limits']=false","enforce_limits']=true").replace("'businesses'=>2","'businesses'=>"+str(existing+1)))
        call("require 'public/index.php';db()->exec(\"UPDATE subscriptions SET plan_key='starter' WHERE user_id=1\");")
        children=[subprocess.Popen([PHP,'-r',"require 'public/index.php';db()->exec(\"INSERT INTO businesses(user_id,name)VALUES(1,'Race')\");"],cwd=A,stdout=subprocess.PIPE,stderr=subprocess.PIPE) for _ in range(4)]
        for child in children:child.communicate()
        check(sum(c.returncode==0 for c in children)==1,'concurrent inserts cannot exceed the remaining one-company slot')
        print(count,'HTTP/concurrency assertions passed')
    finally:
        server.terminate();server.wait();logfile.close()
