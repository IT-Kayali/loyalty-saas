#!/usr/bin/env python3
import sys,pathlib,subprocess,json,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,hmac,hashlib
A=pathlib.Path(sys.argv[1]);PHP=sys.argv[2];n=0

def req(path,data=None,c=None,headers=None):
 if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
 try:
  with (c or urllib.request.build_opener()).open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data,headers=headers or {}),timeout=15) as x:return x.status,x.read().decode()
 except urllib.error.HTTPError as x:return x.code,x.read().decode()
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c=c)[1]).group(1)
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
def sql(s):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+s],cwd=A,text=True).strip()
def state():return json.loads((A/'provider.json').read_text())
def update(fn):
 s=state();fn(s);(A/'provider.json').write_text(json.dumps(s))
def event(e,mutate=False,headers_override=None):
 raw=json.dumps(e,separators=(',',':')).encode();headers={'PayPal-Transmission-Id':'local','PayPal-Transmission-Time':'2026-11-21T15:00:00Z','PayPal-Cert-Url':'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-LOCAL','PayPal-Auth-Algo':'SHA256withRSA','PayPal-Transmission-Sig':hmac.new(b'local-adapter-only',raw,hashlib.sha256).hexdigest(),'Content-Type':'application/json'}
 if headers_override:headers.update(headers_override)
 return req('/paypal/webhook',raw+(b' ' if mutate else b''),headers=headers)
owner=client();req('/login',{'csrf':csrf(owner,'/login'),'email':'demo@loyalty.local','password':'demo1234'},owner);tk=csrf(owner,'/billing')
check(req('/billing/paypal',{'action':'create','plan':'starter'},owner)[0]==403,'create CSRF protected')
code,body=req('/billing/paypal',{'csrf':tk,'action':'create','plan':'starter'},owner);check(code==200,'create HTTP success');id=json.loads(body)['subscriptionId'];check(sql("echo db()->query('SELECT status FROM subscriptions WHERE user_id=1')->fetchColumn();")=='incomplete','create does not grant access')
check(json.loads(req('/billing/paypal',{'csrf':tk,'action':'create','plan':'starter'},owner)[1])['subscriptionId']==id and len(state()['requests'])==1,'repeat create deduplicated')
check(req('/billing/paypal',{'csrf':tk,'action':'create','plan':'pro'},owner)[0]==409,'different plan does not silently replace pending')
update(lambda s:s['subs'][id].update(status='ACTIVE'))
e={'id':'WH-ACTIVE','event_type':'BILLING.SUBSCRIPTION.ACTIVATED','resource':{'id':id}}
check(event(e,True)[0]==403,'tampered original bytes rejected');check(event(e,headers_override={'PayPal-Cert-Url':'https://evil.invalid/cert'})[0]==403,'foreign cert rejected');check(event(e,headers_override={'PayPal-Transmission-Sig':''})[0]==403,'missing signature rejected')
check(event(e)[0]==200,'verified lifecycle accepted');check(sql("echo db()->query('SELECT status FROM subscriptions WHERE user_id=1')->fetchColumn();")=='active','authoritative lifecycle grants active')
calls=len(state()['calls']);check(event(e)[0]==200 and len(state()['calls'])==calls+1,'duplicate verifies signature but skips provider state sync');check(sql("echo db()->query('SELECT COUNT(*) FROM paypal_events')->fetchColumn();")=='1','duplicate event only one receipt')
check(req('/billing/paypal',{'csrf':tk,'action':'cancel'},owner)[0]==200 and state()['subs'][id]['status']=='ACTIVE','cancel requires explicit confirmation')
check(req('/billing/paypal',{'csrf':tk,'action':'create','plan':'pro'},owner)[0]==409 and state()['subs'][id]['status']=='ACTIVE','plan change never auto cancels')
early={'id':'WH-EARLY','event_type':'PAYMENT.SALE.REFUNDED','resource':{'id':'REFUND1','sale_id':'SALE1'}}
check(event(early)[0]==500,'unknown refund asks provider to retry');check(sql("echo db()->query(\"SELECT COUNT(*) FROM paypal_events WHERE id='WH-EARLY'\")->fetchColumn();")=='0','early refund not falsely marked processed')
check(event({'id':'WH-SALE','event_type':'PAYMENT.SALE.COMPLETED','resource':{'id':'SALE1','billing_agreement_id':id}})[0]==200,'verified sale mapping recorded');check(event(early)[0]==200,'retried refund resolves sale ID');check(sql("echo db()->query('SELECT paypal_payment_hold FROM subscriptions WHERE user_id=1')->fetchColumn();")=='1','refund hold persisted')
check(event(dict(e,id='WH-ACTIVE-LATER'))[0]==200 and sql("echo db()->query('SELECT status FROM subscriptions WHERE user_id=1')->fetchColumn();")=='past_due','later ACTIVE cannot clear refund hold')
check(req('/billing/paypal',{'csrf':tk,'action':'create','plan':'starter'},owner)[0]==409,'unresolved refund prevents new signup')
proc=subprocess.run([PHP,'tools/paypal-reconcile.php','--user=1','--release-hold'],cwd=A,capture_output=True,text=True);check(proc.returncode!=0 and sql("echo db()->query('SELECT paypal_payment_hold FROM subscriptions WHERE user_id=1')->fetchColumn();")=='1','operator release without case reason denied')
update(lambda s:s['subs'][id].update(status='SUSPENDED'))
proc=subprocess.run([PHP,'tools/paypal-reconcile.php','--user=1','--release-hold','--reason=LOCAL-CASE-RESOLVED'],cwd=A,capture_output=True,text=True);check(proc.returncode!=0 and sql("echo db()->query('SELECT paypal_payment_hold FROM subscriptions WHERE user_id=1')->fetchColumn();")=='1','operator release rolls back when provider not active')
update(lambda s:s['subs'][id].update(status='ACTIVE'))
proc=subprocess.run([PHP,'tools/paypal-reconcile.php','--user=1','--release-hold','--reason=LOCAL-CASE-RESOLVED'],cwd=A,capture_output=True,text=True);check(proc.returncode==0 and sql("echo db()->query('SELECT paypal_payment_hold FROM subscriptions WHERE user_id=1')->fetchColumn();")=='0','operator release reconciles active provider and records case')
check(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='paypal.hold.operator_release'\")->fetchColumn();")=='1','operator release auditable')
check(req('/billing/paypal',{'csrf':tk,'action':'cancel','confirm_cancel':'1'},owner)[0]==200 and state()['subs'][id]['status']=='CANCELLED','explicit cancellation reaches provider');check(sql("echo db()->query('SELECT status FROM subscriptions WHERE user_id=1')->fetchColumn();")=='canceled','cancellation reconciles state')
# No actual HTTP transport ever contacted PayPal in this suite.
check(all('paypal.com' not in call[1] for call in state()['calls']),'adapter used fixed local test transport only')
print(n,'PayPal HTTP assertions passed')
