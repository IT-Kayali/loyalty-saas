#!/usr/bin/env python3
import os,sys,subprocess,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,json
A=sys.argv[1];PHP=sys.argv[2];n=0

def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
def req(path,data=None,c=None):
 if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
 try:
  with (c or urllib.request.build_opener()).open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url
 except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url

def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c=c)[1]).group(1)
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
def owner_login(c):req('/login',{'csrf':csrf(c,'/login'),'email':'demo@loyalty.local','password':'demo1234'},c)
def staff_login(c,pin='4321'):return req('/staff/login',{'csrf':csrf(c,'/staff/login'),'business_id':1,'name':'Lena','pin':pin},c)
owner=client();owner_login(owner);s=client();staff_login(s);tk=csrf(owner,'/business/1/team');st=csrf(s,'/staff/member/1')
base={'csrf':tk,'staff_id':1,'role':'viewer','active':1,'location_id':0}
check(req('/business/1/team',base,owner)[0]==200,'owner saves viewer rights')
for act in ['stamp','points_add','redeem','voucher_use','pass_use']:
 check(req('/staff/card/1/action',{'csrf':st,'action':act},s)[0]==403,'viewer HTTP rejects '+act)
check(req('/staff/card/1/undo',{'csrf':st,'txn_id':1},s)[0]==403,'viewer HTTP rejects reversal')
check(req('/staff/prepaid/sell',{'csrf':st,'member_id':1},s)[0]==403,'viewer HTTP rejects sale')
check(req('/business/1/voucher-counter',{'csrf':st,'action':'preview'},s)[0]==403,'viewer HTTP rejects voucher code preview')
check(req('/business/1/team',dict(base,staff_id=2),owner)[0]==404,'owner cannot modify other business staff through wrong URL')
loc=int(sql("db()->exec(\"INSERT INTO locations(business_id,name)VALUES(2,'Not mine')\");echo db()->lastInsertId();"))
check(req('/business/1/team',dict(base,location_id=loc),owner)[0]==404,'HTTP rejects other-company location')
net=int(sql("db()->exec(\"INSERT INTO users(name,email,password_hash)VALUES('Other','other@test.local','unused')\");$id=db()->lastInsertId();db()->prepare('INSERT INTO networks(user_id,name)VALUES(?,?)')->execute([$id,'Foreign']);echo db()->lastInsertId();"))
check(req('/organization',{'csrf':tk,'action':'assign','business_id':1,'network_id':net},owner)[0]==404,'HTTP rejects other-owner network')
check(req('/business/1/team',dict(base,role='supervisor',new_pin='123'),owner)[0]==200,'invalid PIN returns reviewed form')
check(sql("echo db()->query('SELECT role FROM staff WHERE id=1')->fetchColumn();")=='viewer','invalid PIN does not partly save rights')
# A staff-prefixed channel never borrows a simultaneous owner login.
owner_login(s);check(req('/staff/prepaid/sell',{'csrf':csrf(s,'/staff/member/1'),'member_id':1},s)[0]==403,'staff-prefixed sale cannot borrow owner session')
req('/business/1/team',dict(base,role='cashier',cap_award=1,cap_redeem=1,cap_sell_pass=1,new_pin='456789'),owner)
check(req('/staff',c=s)[2].endswith('/staff/login'),'PIN reset revokes existing staff session')
old=client();check(staff_login(old)[2].endswith('/staff/login'),'old PIN rejected');fresh=client();check(staff_login(fresh,'456789')[2].endswith('/staff'),'new PIN works')
# Owner actions are not falsely attributed to a lingering staff identity.
owner_login(fresh);res=req('/card/1/action',{'csrf':csrf(fresh,'/member/1'),'action':'stamp'},fresh);check(res[0]==200,'owner action with parallel staff login succeeds')
check(sql("echo db()->query(\"SELECT CASE WHEN staff_id IS NULL AND location_id IS NULL THEN 1 ELSE 0 END FROM transactions ORDER BY id DESC LIMIT 1\")->fetchColumn();")=='1','owner transaction not attributed to staff')
loc=int(sql("db()->exec(\"INSERT INTO locations(business_id,name)VALUES(1,'Enabled')\");echo db()->lastInsertId();"))
req('/business/1/team',dict(base,role='cashier',cap_award=1,location_id=loc),owner);check(req('/staff',c=fresh)[2].endswith('/staff'),'assigned active location usable')
req('/organization',{'csrf':tk,'action':'location_toggle','id':loc},owner);check(req('/staff',c=fresh)[2].endswith('/staff/login'),'disabled location revokes existing access')
print(n,'organization HTTP assertions passed')
