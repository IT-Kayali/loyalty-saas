#!/usr/bin/env python3
"""MCP over HTTP, key handling, approvals. Only a disposable local server, no AI provider."""
import sys,subprocess,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,json,time
A=sys.argv[1];PHP=sys.argv[2];n=0
def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(path,data=None,c=None,headers=None,method=None):
 if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
 r=urllib.request.Request('http://127.0.0.1:8099'+path,data=data,headers=headers or {},method=method)
 try:
  with (c or client()).open(r,timeout=15) as x:return x.status,x.read().decode(),x.url
 except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url
def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c=c)[1]).group(1)
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
def login(email,pw):
 c=client();req('/login',{'csrf':csrf(c,'/login'),'email':email,'password':pw},c);return c
def mkkey(c,name,scope,days=30):
 st,b,_=req('/ai',{'csrf':csrf(c,'/ai'),'action':'create','name':name,'scope':scope,'days':days},c)
 m=re.search(r'rl_ai_[a-f0-9]{48}',b);assert m,b[:300];return m.group(0)
_id=[0]
def rpc(key,method,params=None,notify=False,headers=None):
 _id[0]+=1;body={'jsonrpc':'2.0','method':method}
 if not notify:body['id']=_id[0]
 if params is not None:body['params']=params
 h={'Authorization':'Bearer '+key,'Content-Type':'application/json'};h.update(headers or {})
 st,b,_=req('/mcp',json.dumps(body).encode(),headers=h)
 try:return st,(json.loads(b) if b.strip() else None)
 except ValueError:return st,None
def call(key,tool,args=None):
 st,r=rpc(key,'tools/call',{'name':tool,'arguments':args or {}});assert st==200,(st,r)
 return r
def data(key,tool,args=None):
 r=call(key,tool,args);assert 'result' in r and not r['result']['isError'],r;return r['result']['structuredContent']
def err(key,tool,args=None):
 r=call(key,tool,args);assert 'result' in r and r['result']['isError'],r;return r['result']['content'][0]['text']
# --- setup: second owner without businesses
sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Other Owner','other@test.local',password_hash('otherpass1',PASSWORD_DEFAULT)]);db()->prepare('INSERT INTO businesses(user_id,name)VALUES(?,?)')->execute([2,'Fremdladen']);db()->prepare(\"INSERT INTO members(business_id,name,email,code)VALUES(3,'Fremd Kunde','fremd@kunde.invalid','FFFFFFFFFF')\")->execute();")
own=login('demo@loyalty.local','demo1234');oth=login('other@test.local','otherpass1')
rk=mkkey(own,'Lesen','read');wk=mkkey(own,'Schreiben','write');ok_=mkkey(oth,'Fremd','write')
check(sql("echo db()->query('SELECT COUNT(*) FROM ai_keys WHERE key_hash LIKE \"rl_ai_%\"')->fetchColumn();")=='0','keys are stored hashed')
check(rk not in sql("echo implode('|',db()->query('SELECT name||prefix||key_hash FROM ai_keys')->fetchAll(PDO::FETCH_COLUMN));"),'plaintext key not stored')
# --- transport rules
check(req('/mcp',b'{}',headers={'Content-Type':'application/json'})[0]==401,'no key rejected')
check(req('/mcp',b'{}',headers={'Authorization':'Bearer rl_ai_'+'0'*48})[0]==401,'unknown key rejected')
check(req('/mcp',b'{}',headers={'Authorization':'Bearer '+rk,'Origin':'https://evil.invalid'})[0]==403,'browser Origin refused')
check(req('/mcp',headers={'Authorization':'Bearer '+rk})[0]==405,'GET not allowed')
st,b,_=req('/mcp',b'{not json',headers={'Authorization':'Bearer '+rk});check(st==400 and json.loads(b)['error']['code']==-32700,'invalid JSON gives -32700')
st,r=rpc(rk,'initialize',{'protocolVersion':'2025-03-26','capabilities':{},'clientInfo':{'name':'t','version':'1'}})
check(r['result']['protocolVersion']=='2025-03-26' and 'tools' in r['result']['capabilities'],'initialize negotiates version')
check(rpc(rk,'notifications/initialized',notify=True)[0]==202,'notification gets 202')
check(rpc(rk,'ping')[1]['result']=={},'ping')
check(rpc(rk,'nope')[1]['error']['code']==-32601,'unknown method -32601')
tl=lambda k:[t['name'] for t in rpc(k,'tools/list')[1]['result']['tools']]
rt,wt=tl(rk),tl(wk)
check('list_programs' in rt and 'award_points' not in rt and 'delete_member' not in rt and 'run_approved_change' not in rt,'read key sees read tools only')
check({'award_points','give_stamp','create_program','delete_member','delete_program','run_approved_change'}<=set(wt),'write key sees write tools')
check(rpc(rk,'tools/call',{'name':'award_points','arguments':{'member_code':'X','amount':1}})[1]['error']['code']==-32602,'read key cannot call write tool')
st,b,_=req('/mcp',json.dumps([{'jsonrpc':'2.0','id':1,'method':'ping'},{'jsonrpc':'2.0','id':2,'method':'ping'}]).encode(),headers={'Authorization':'Bearer '+rk});check(st==200 and len(json.loads(b))==2,'batch works')
pl=[p['name'] for p in rpc(rk,'prompts/list')[1]['result']['prompts']];check('weekly_report' in pl and 'launch_stamp_card' not in pl,'prompts follow key scope')
check('Cappuccino' in json.dumps(rpc(wk,'prompts/get',{'name':'launch_stamp_card','arguments':{'name':'Cappuccino','stamps':'8','reward':'Gratis'}})[1]),'prompt arguments filled')
# --- reads
check(len(data(rk,'list_businesses')['businesses'])==2,'list_businesses shows only own')
check('business_id' in err(rk,'list_programs'),'business_id required with two businesses')
check(len(data(rk,'list_programs',{'business_id':1})['programs'])>=2,'list_programs with business')
check(err(rk,'list_programs',{'business_id':3})=='Unternehmen nicht gefunden.','foreign business not reachable')
mem=data(rk,'list_members',{'business_id':1})['members'];code=mem[0]['code']
check(data(rk,'get_member',{'business_id':1,'member_code':code})['code']==code,'get_member')
check(err(rk,'get_member',{'business_id':1,'member_code':'FFFFFFFFFF'})=='Mitglied nicht gefunden.','member of other tenant not reachable')
check(len(data(rk,'list_members',{'business_id':1,'q':mem[0]['name'][:3]})['members'])>=1,'member search')
check(data(rk,'get_business_overview',{'business_id':1})['members']>=1,'overview')
check(len(data(rk,'list_transactions',{'business_id':1,'limit':5})['transactions'])<=5,'transaction limit')
check('Pflichtfeld' in err(rk,'get_member',{'business_id':1}),'required field enforced')
# --- writes with idempotency
pts_card=sql("echo db()->query(\"SELECT c.member_id FROM cards c JOIN programs p ON p.id=c.program_id WHERE p.business_id=1 AND p.type='points' LIMIT 1\")->fetchColumn();")
pcode=sql("echo db()->query('SELECT code FROM members WHERE id="+pts_card+"')->fetchColumn();")
def pts():return int(sql("echo db()->query(\"SELECT points FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id="+pts_card+" AND p.type='points' AND p.business_id=1\")->fetchColumn();"))
b0=pts();a={'business_id':1,'member_code':pcode,'amount':10,'idempotency_key':'award-test-0001'}
r1=data(wk,'award_points',a);r2=data(wk,'award_points',a)
check(pts()==r1['points'] and r2.get('replayed') is True,'idempotent replay credits once')
check('andere Eingaben' in err(wk,'award_points',dict(a,amount=11)),'idempotency key reuse with other input refused')
check('1 bis 10000' in err(wk,'award_points',dict(a,amount=0,idempotency_key='award-test-0002')),'amount validated')
check(err(wk,'award_points',{'business_id':1,'member_code':'FFFFFFFFFF','amount':5})=='Mitglied nicht gefunden.','cross-tenant award refused')
scode=sql("echo db()->query(\"SELECT m.code FROM cards c JOIN programs p ON p.id=c.program_id JOIN members m ON m.id=c.member_id WHERE p.business_id=1 AND p.type='stamps' AND p.active=1 LIMIT 1\")->fetchColumn();")
st1=data(wk,'give_stamp',{'business_id':1,'member_code':scode});check(st1['stamps']>=0 and st1['stamps_required']>=2 and 'reward_earned' in st1,'give_stamp advances card')
newp=data(wk,'create_program',{'business_id':1,'type':'stamps','name':'KI Karte','stamps_required':8,'reward_text':'Gratis'})
check(newp['program_id']>0,'create_program')
check('type' in err(wk,'create_program',{'business_id':1,'type':'voucher','name':'x'}),'create_program rejects unsupported type')
check(data(wk,'create_member',{'business_id':1,'name':'KI Kunde','email':'ki@kunde.invalid'})['email']=='ki@kunde.invalid','create_member')
# --- approvals
mcode=data(wk,'create_member',{'business_id':1,'name':'Zu Loeschen','email':'del@kunde.invalid'})['code']
prep=data(wk,'delete_member',{'business_id':1,'member_code':mcode});rid=prep['request_id'];nonce=prep['nonce']
check(prep['approval_required'] and 'ai/approval/'+rid in prep['review_url'],'delete is prepared, not executed')
check(sql("echo db()->query(\"SELECT COUNT(*) FROM members WHERE code='"+mcode+"'\")->fetchColumn();")=='1','member still exists before approval')
check('Noch nicht freigegeben' in err(wk,'run_approved_change',{'request_id':rid,'nonce':nonce}),'run before approval refused')
check('nonce' in err(wk,'run_approved_change',{'request_id':rid,'nonce':'0'*32}),'wrong nonce refused')
check(req('/ai/approval/'+rid,c=oth)[0]==404,'other owner gets 404 on approval page')
check(err(ok_,'get_change_status',{'request_id':rid})=='Freigabeanfrage nicht gefunden.','other key cannot see request')
page=req('/ai/approval/'+rid,c=own)[1];check('Zu Loeschen' in page and 'Freigeben' in page and '2 Min' not in page.split('noch')[0],'review page shows change')
check(data(wk,'get_change_status',{'request_id':rid})['status']=='pending','status pending')
req('/ai/approval/'+rid,{'csrf':csrf(own,'/ai/approval/'+rid),'decision':'approve'},own)
check(data(wk,'get_change_status',{'request_id':rid})['status']=='approved','owner approval recorded')
check(data(wk,'run_approved_change',{'request_id':rid,'nonce':nonce})['deleted'] is True,'approved delete runs')
check(sql("echo db()->query(\"SELECT COUNT(*) FROM members WHERE code='"+mcode+"'\")->fetchColumn();")=='0','member deleted after approval')
check('bereits ausgeführt' in err(wk,'run_approved_change',{'request_id':rid,'nonce':nonce}),'second run refused')
# reject
m2=data(wk,'create_member',{'business_id':1,'name':'Bleibt','email':'keep@kunde.invalid'})['code']
p2=data(wk,'delete_member',{'business_id':1,'member_code':m2})
req('/ai/approval/'+p2['request_id'],{'csrf':csrf(own,'/ai/approval/'+p2['request_id']),'decision':'reject'},own)
check('abgelehnt' in err(wk,'run_approved_change',{'request_id':p2['request_id'],'nonce':p2['nonce']}),'rejected change never runs')
check(sql("echo db()->query(\"SELECT COUNT(*) FROM members WHERE code='"+m2+"'\")->fetchColumn();")=='1','rejected member remains')
# expiry
p3=data(wk,'delete_member',{'business_id':1,'member_code':m2})
req('/ai/approval/'+p3['request_id'],{'csrf':csrf(own,'/ai/approval/'+p3['request_id']),'decision':'approve'},own)
sql("db()->exec(\"UPDATE ai_requests SET expires_at='2000-01-01 00:00:00' WHERE id='"+p3['request_id']+"'\");")
check('abgelaufen' in err(wk,'run_approved_change',{'request_id':p3['request_id'],'nonce':p3['nonce']}),'expired approval cannot run')
p4=data(wk,'delete_member',{'business_id':1,'member_code':m2});sql("db()->exec(\"UPDATE ai_requests SET expires_at='2000-01-01 00:00:00' WHERE id='"+p4['request_id']+"'\");")
req('/ai/approval/'+p4['request_id'],{'csrf':csrf(own,'/ai'),'decision':'approve'},own)
check(data(wk,'get_change_status',{'request_id':p4['request_id']})['status']=='expired','expired request cannot be approved')
# record changed after review
p5=data(wk,'delete_member',{'business_id':1,'member_code':m2})
req('/ai/approval/'+p5['request_id'],{'csrf':csrf(own,'/ai/approval/'+p5['request_id']),'decision':'approve'},own)
sql("db()->exec(\"UPDATE members SET name='Umbenannt' WHERE code='"+m2+"'\");")
check('geändert' in err(wk,'run_approved_change',{'request_id':p5['request_id'],'nonce':p5['nonce']}),'changed record not deleted')
check(sql("echo db()->query(\"SELECT COUNT(*) FROM members WHERE code='"+m2+"'\")->fetchColumn();")=='1' and data(wk,'get_change_status',{'request_id':p5['request_id']})['status']=='failed','changed record request closed as failed')
# delete program needs approval too
pp=data(wk,'delete_program',{'business_id':1,'program_id':newp['program_id']})
check(pp['approval_required'] and sql("echo db()->query('SELECT COUNT(*) FROM programs WHERE id="+str(newp['program_id'])+"')->fetchColumn();")=='1','delete_program only prepared')
# revocation
p6=data(wk,'delete_member',{'business_id':1,'member_code':m2})
kid=sql("echo db()->query(\"SELECT id FROM ai_keys WHERE name='Schreiben'\")->fetchColumn();")
req('/ai',{'csrf':csrf(own,'/ai'),'action':'revoke','key_id':kid},own)
check(rpc(wk,'tools/list')[0]==401,'revoked key rejected')
check(sql("echo db()->query(\"SELECT status FROM ai_requests WHERE id='"+p6['request_id']+"'\")->fetchColumn();")=='expired','revocation closes open requests')
# expiry of keys
sql("db()->exec(\"UPDATE ai_keys SET expires_at='2000-01-01 00:00:00' WHERE name='Lesen'\");")
check(rpc(rk,'ping')[0]==401,'expired key rejected')
# other owner cannot revoke foreign key
lid=sql("echo db()->query(\"SELECT id FROM ai_keys WHERE name='Lesen'\")->fetchColumn();")
req('/ai',{'csrf':csrf(oth,'/ai'),'action':'revoke','key_id':lid},oth)
check(sql("echo var_export(db()->query(\"SELECT revoked_at FROM ai_keys WHERE name='Lesen'\")->fetchColumn()===null,true);")=='true','other owner cannot revoke foreign key')
# audit
aud=sql("echo implode('|',db()->query(\"SELECT details FROM audit_logs WHERE action LIKE 'ai.%'\")->fetchAll(PDO::FETCH_COLUMN));")
check('Zu Loeschen' not in aud and 'del@kunde' not in aud and not re.search(r'rl_ai_[a-f0-9]{48}',aud),'audit has no member data or key secrets')
check(int(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action IN ('ai.change.done','ai.change.approved','ai.key.create','ai.tool')\")->fetchColumn();"))>=5,'actions are audited')
# --- feinere Scopes: Unternehmen und Werkzeuggruppen
def mkscoped(c,name,scope,groups,biz=0,expect_ok=True):
 d=[('csrf',csrf(c,'/ai')),('action','create'),('name',name),('scope',scope),('days','30'),('scoped','1'),('business_id',str(biz))]+[('groups[]',g) for g in groups]
 st,b,_=req('/ai',urllib.parse.urlencode(d).encode(),c)
 m=re.search(r'rl_ai_[a-f0-9]{48}',b)
 if expect_ok:assert m,b[:300]
 return m.group(0) if m else None
sk=mkscoped(own,'Nur Unternehmen 1','read',['reports','members','points','programs','delete'],1)
check([b['id'] for b in data(sk,'list_businesses')['businesses']]==[1],'Unternehmens-Schlüssel sieht nur sein Unternehmen')
check(len(data(sk,'list_programs')['programs'])>=2,'Unternehmens-Schlüssel braucht keine business_id')
check(err(sk,'list_programs',{'business_id':2})=='Unternehmen nicht gefunden.','anderes eigenes Unternehmen nicht erreichbar')
check(data(sk,'get_business_overview',{'business_id':1})['members']>=1,'eigenes Unternehmen erreichbar')
check(sql("echo db()->query(\"SELECT tool_groups IS NULL FROM ai_keys WHERE name='Nur Unternehmen 1'\")->fetchColumn();")=='1','alle Gruppen gewählt wird als NULL gespeichert')
rp=mkscoped(own,'Nur Auswertungen','read',['reports'])
nm=tl(rp)
check({'get_business_overview','list_transactions','list_businesses'}<=set(nm) and not ({'list_members','get_member','list_programs'}&set(nm)),'Gruppenschlüssel sieht nur seine Werkzeuge')
check(rpc(rp,'tools/call',{'name':'list_members','arguments':{'business_id':1}})[1]['error']['code']==-32602,'Werkzeug außerhalb der Gruppe abgelehnt')
check(int(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='ai.scope.denied'\")->fetchColumn();"))>=1,'Ablehnung wird protokolliert')
check(data(rp,'get_business_overview',{'business_id':1})['members']>=1,'Werkzeug in der Gruppe funktioniert')
check(rpc(rp,'prompts/list')[1]['result']['prompts']==[],'Prompts folgen den Gruppen (keine passenden)')
pk=mkscoped(own,'Nur Punkte','write',['points'],1)
pt=tl(pk)
check({'award_points','give_stamp','run_approved_change'}<=set(pt) and not ({'create_program','delete_member','delete_program','list_members','create_member'}&set(pt)),'Schreibschlüssel mit Gruppe points')
check(rpc(pk,'tools/call',{'name':'create_program','arguments':{'type':'stamps','name':'X','stamps_required':3,'reward_text':'R'}})[1]['error']['code']==-32602,'create_program ohne Gruppe abgelehnt')
check(rpc(pk,'tools/call',{'name':'delete_member','arguments':{'member_code':code}})[1]['error']['code']==-32602,'delete_member ohne Gruppe abgelehnt')
r=call(pk,'award_points',{'member_code':pcode,'amount':1});check('result' in r and not r['result']['isError'],'Punkte buchen mit Gruppe points')
check(err(pk,'award_points',{'member_code':pcode,'amount':1,'business_id':2})=='Unternehmen nicht gefunden.','Schreibzugriff nur im eigenen Unternehmen')
dk=mkscoped(own,'Nur Loeschen','write',['delete'],1)
pr=data(dk,'delete_member',{'member_code':code});check(pr.get('approval_required') is True,'Löschgruppe bereitet Freigabe vor')
check(err(dk,'delete_member',{'member_code':code,'business_id':2})=='Unternehmen nicht gefunden.','Freigabe-Vorbereitung nur im eigenen Unternehmen')
check(rpc(dk,'tools/call',{'name':'award_points','arguments':{'member_code':code,'amount':1}})[1]['error']['code']==-32602,'Löschschlüssel kann nicht buchen')
check(data(dk,'get_change_status',{'request_id':pr['request_id']})['status']=='pending','get_change_status bleibt als Meta-Werkzeug erlaubt')
wp=mkscoped(own,'Programme','write',['programs','reports'])
check(set(rpc(wp,'prompts/list')[1]['result']['prompts'][i]['name'] for i in range(2))=={'weekly_report','launch_stamp_card'},'Prompts folgen Gruppen und Stufe')
# Formularpruefung
n0=sql("echo db()->query('SELECT COUNT(*) FROM ai_keys')->fetchColumn();")
mkscoped(own,'Leer','read',[],0,False);check(sql("echo db()->query('SELECT COUNT(*) FROM ai_keys')->fetchColumn();")==n0,'Ohne Gruppe kein Schlüssel')
mkscoped(own,'Fremd','read',['reports'],3,False);check(sql("echo db()->query('SELECT COUNT(*) FROM ai_keys')->fetchColumn();")==n0,'Fremdes Unternehmen im Formular abgelehnt')
mkscoped(own,'Unbekannt','read',['nope','admin'],0,False);check(sql("echo db()->query('SELECT COUNT(*) FROM ai_keys')->fetchColumn();")==n0,'Unbekannte Gruppen ignoriert, dann abgelehnt')
check('Nur Auswertungen' in req('/ai',c=own)[1] and 'Auswertungen' in req('/ai',c=own)[1],'Schlüsselliste zeigt Umfang')
lk1=mkkey(own,'Alt Lesen','read');lk2=mkkey(own,'Alt Schreiben','write')
check(len(tl(lk1))>=5 and len(tl(lk2))>=11 and sql("echo db()->query(\"SELECT COUNT(*) FROM ai_keys WHERE name LIKE 'Alt %' AND tool_groups IS NULL AND business_id IS NULL\")->fetchColumn();")=='2','Bestandsschlüssel ohne Umfang funktionieren unverändert')
# Bestehenden Umfang aendern, ohne Geheimnis/Ablauf zu erneuern.
kid=int(sql("""echo db()->query("SELECT id FROM ai_keys WHERE name='Nur Loeschen'")->fetchColumn();"""));edit='/ai/key/'+str(kid)
before=sql("echo db()->query('SELECT key_hash||expires_at FROM ai_keys WHERE id=%d')->fetchColumn();"%kid)
req('/ai/approval/'+pr['request_id'],{'csrf':csrf(own,'/ai/approval/'+pr['request_id']),'decision':'approve'},own)
check(data(dk,'get_change_status',{'request_id':pr['request_id']})['status']=='approved','Scope-Test hat bereits freigegebene Anfrage')
# A separate pending request and the approved request must both expire.
pp2=data(dk,'delete_program',{'program_id':newp['program_id']})
def update(c,scope='read',groups=['reports'],biz=2,token=True):
 fields=[('scope',scope),('business_id',str(biz))]+[('groups[]',g) for g in groups]
 if token:fields.append(('csrf',csrf(c,'/ai' if c is oth else edit)))
 return req(edit,urllib.parse.urlencode(fields).encode(),c)
check(req(edit,c=oth)[0]==404,'Fremder Owner sieht Schlüssel-Editor nicht')
update(oth);check(sql("echo db()->query('SELECT scope FROM ai_keys WHERE id=%d')->fetchColumn();"%kid)=='write','Fremder Owner ändert Schlüssel nicht')
update(own,token=False);check(sql("echo db()->query('SELECT scope FROM ai_keys WHERE id=%d')->fetchColumn();"%kid)=='write','Scope-Änderung ohne CSRF wirkungslos')
update(own,groups=[]);check(sql("echo db()->query('SELECT scope FROM ai_keys WHERE id=%d')->fetchColumn();"%kid)=='write','Leere Werkzeuggruppen abgelehnt')
update(own,biz=3);check(sql("echo db()->query('SELECT scope FROM ai_keys WHERE id=%d')->fetchColumn();"%kid)=='write','Fremdes Unternehmen beim Ändern abgelehnt')
update(own)
check(sql("echo db()->query('SELECT scope FROM ai_keys WHERE id=%d')->fetchColumn();"%kid)=='read' and before==sql("echo db()->query('SELECT key_hash||expires_at FROM ai_keys WHERE id=%d')->fetchColumn();"%kid),'Scope geändert, Geheimnis und Ablauf unverändert')
check(all(sql("""echo db()->query("SELECT status FROM ai_requests WHERE id='%s'")->fetchColumn();"""%r)=='expired' for r in [pr['request_id'],pp2['request_id']]),'Offene und freigegebene Anfragen laufen beim Speichern ab')
check('delete_member' not in tl(dk) and 'get_business_overview' in tl(dk),'Bestehender Bearer-Schlüssel sieht geänderte Werkzeuge')
check([x['id'] for x in data(dk,'list_businesses')['businesses']]==[2],'Bestehender Schlüssel auf anderes eigenes Unternehmen begrenzt')
check(sql("""echo db()->query("SELECT COUNT(*) FROM audit_logs WHERE action='ai.key.update'")->fetchColumn();""")=='1','Scope-Änderung im Audit')
check(req('/ai/key/'+str(kid+9999),c=own)[0]==404,'Unbekannter Schlüssel nicht editierbar')
sql("db()->prepare('UPDATE ai_keys SET revoked_at=? WHERE id=?')->execute(['2026-01-01',%d]);"%kid)
check(req(edit,c=own)[0]==404,'Widerrufener Schlüssel nicht editierbar')
check(req('/ai/key/'+sql("echo db()->query(\"SELECT id FROM ai_keys WHERE name='Lesen'\")->fetchColumn();"),c=own)[0]==404,'Abgelaufener Schlüssel nicht editierbar')
# Einstellbares HTTP-Anfragelimit je Schluessel.
rateid=int(sql("""echo db()->query("SELECT id FROM ai_keys WHERE name='Alt Lesen'")->fetchColumn();"""));rateedit='/ai/key/'+str(rateid)
rpm=lambda:sql("echo db()->query('SELECT requests_per_minute FROM ai_keys WHERE id=%d')->fetchColumn();"%rateid)
def setrpm(value,c=own,token=True):
 fields=[('scope','read'),('business_id','0'),('groups[]','reports'),('requests_per_minute',str(value))]
 if token:fields.append(('csrf',csrf(c,rateedit if c is own else '/ai')))
 return req(rateedit,urllib.parse.urlencode(fields).encode(),c)
check(rpm()=='60','Anfragelimit bestehender Schlüssel bleibt 60')
setrpm(2,token=False);check(rpm()=='60','Anfragelimit ohne CSRF unverändert')
setrpm(2,c=oth);check(rpm()=='60','Fremder Owner kann Anfragelimit nicht ändern')
for invalid in [0,61,-1]:
 setrpm(invalid);check(rpm()=='60','Anfragelimit außerhalb 1 bis 60 abgelehnt')
setrpm(2);check(rpm()=='2','Anfragelimit gespeichert')
# Use deterministic fresh DB window, then test HTTP admission in this same minute.
sql("db()->prepare('DELETE FROM rate_limits WHERE key=?')->execute([hash('sha256','mcp|%d')]);"%rateid)
if time.time()%60>55:time.sleep(61-time.time()%60)
first=rpc(lk1,'ping')[0];second=rpc(lk1,'tools/list')[0];blocked=rpc(lk1,'ping')
check(first==200 and second==200 and blocked[0]==429 and blocked[1]['error']=='rate_limited','Individuelles HTTP-Limit blockiert dritte Anfrage mit JSON 429')
check(rpc(lk2,'ping')[0]==200,'Limit eines Schlüssels blockiert anderen Schlüssel nicht')
setrpm(1);check(rpc(lk1,'ping')[0]==429,'Limitänderung setzt laufenden Zähler nicht zurück')
sql("db()->prepare('UPDATE rate_limits SET window_start=0 WHERE key=?')->execute([hash('sha256','mcp|%d')]);"%rateid)
check(rpc(lk1,'ping')[0]==200,'Nächstes Zeitfenster erlaubt wieder Anfragen')
# rate limit
fk=mkkey(own,'Rate','read');codes=[rpc(fk,'ping')[0] for _ in range(70)]
check(429 in codes and codes[0]==200,'rate limit applies per key')
print(n,'AI HTTP assertions passed')
