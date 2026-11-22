#!/usr/bin/env python3
"""Tier/bonus deletion requires reviewed current state, owner and explicit confirmation."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,json,concurrent.futures
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s,flush=True)
with tempfile.TemporaryDirectory(prefix='loyalty-rule-delete-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(c):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+c],cwd=A,text=True).strip()
 pid=int(sql("echo db()->query(\"SELECT id FROM programs WHERE business_id=1 AND type='points' LIMIT 1\")->fetchColumn();"))
 sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Foreign','foreign@test.local',password_hash('foreignpass1',PASSWORD_DEFAULT)]);")
 def fixture(kind):
  q="INSERT INTO tiers(program_id,name,min_points,benefit)VALUES(%d,'Sichere Stufe',500,'Vorteil')"%pid if kind=='tier' else "INSERT INTO bonus_hours(program_id,weekday,start_hour,end_hour,multiplier)VALUES(%d,1,14,16,2)"%pid
  return int(sql('db()->exec("'+q+'");echo db()->lastInsertId();'))
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(p,c,d=None):
  if isinstance(d,dict):d=urllib.parse.urlencode(d).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+p,data=d),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c)[1]).group(1)
 def login(e,pw,path='/login'):
  c=client();req(path,c,{'csrf':csrf(c,path),'email':e,'password':pw});return c
 def effect():return sql("echo json_encode([db()->query('SELECT * FROM programs')->fetchAll(),db()->query('SELECT * FROM members')->fetchAll(),db()->query('SELECT * FROM cards')->fetchAll(),db()->query('SELECT * FROM transactions')->fetchAll(),db()->query('SELECT * FROM rewards')->fetchAll(),db()->query('SELECT * FROM member_messages')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll()]);")
 with open(A/'server.log','w') as log:
  s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(50):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
    except OSError:time.sleep(.1)
   o=login('demo@loyalty.local','demo1234');f=login('foreign@test.local','foreignpass1');m=login('aylin@example.com','kunde1234','/member/login');other=effect()
   ui=[]
   for kind,table,key in [('tier','tiers','tier_id'),('bonus','bonus_hours','bonus_id')]:
    id=fixture(kind);P='/%s/%d/delete'%(kind,id)
    def exists():return sql('echo db()->query("SELECT COUNT(*) FROM %s WHERE id=%d")->fetchColumn();'%(table,id))=='1'
    def audits():return int(sql("echo db()->query(\"SELECT COUNT(*) FROM audit_logs WHERE action='%s.delete'\")->fetchColumn();"%kind))
    def revision():return re.search(r'name="revision" value="([a-f0-9]+)"',req(P,o)[1]).group(1)
    def payload():return {'csrf':csrf(o,P),'revision':revision(),'confirm_delete':'1','name_confirm':'Sichere Stufe'}
    st,h,_,headers=req(P,o);a=audits()
    check(st==200 and headers['Cache-Control']=='private, no-store',kind+': private confirmation GET')
    check('endgültig löschen' in h and 'Keine bestehenden Punkte' in h,kind+': consequence and explicit action stated')
    check(exists() and audits()==a,kind+': review never deletes or audits')
    check('href="'+P+'"' in req('/program/%d'%pid,o)[1],kind+': list links review, no direct cross delete')
    check('/login' in req(P,client())[2] and '/login' in req(P,m)[2],kind+': guest/member denied')
    check(req(P,f)[0]==404,kind+': foreign owner cannot inspect')
    d=payload();d['csrf']=csrf(f,'/dashboard');req(P,f,d);check(exists(),kind+': foreign POST denied')
    d=payload();del d['csrf'];req(P,o,d);check(exists(),kind+': CSRF required')
    req('/'+kind+'/delete',o,{'csrf':csrf(o,P),'program_id':str(pid),key:str(id)});check(exists() and audits()==a,kind+': legacy unreviewed POST no longer deletes')
    for change in [{'confirm_delete':'0'},{'confirm_delete[]':'1','confirm_delete':None},{'revision':'bad'},{'revision[]':'x','revision':None}]+([{'name_confirm':'Wrong'},{'name_confirm[]':'Sichere Stufe','name_confirm':None}] if kind=='tier' else []):
     d=payload();d.update(change);d={k:v for k,v in d.items() if v is not None};req(P,o,d);check(exists() and audits()==a,kind+': incomplete or malformed confirmation cannot delete')
    old=payload();sql('db()->exec("UPDATE %s SET %s WHERE id=%d");'%(table,"name='Geänderte Stufe'" if kind=='tier' else 'multiplier=3',id));req(P,o,old);check(exists() and audits()==a,kind+': changed rule blocks stale deletion')
    if kind=='tier':sql('db()->exec("UPDATE tiers SET name=\'Sichere Stufe\' WHERE id=%d");'%id)
    sql("db()->exec(\"CREATE TRIGGER fail_rule_delete BEFORE INSERT ON audit_logs WHEN NEW.action='%s.delete' BEGIN SELECT RAISE(ABORT,'forced audit failure'); END\");"%kind)
    req(P,o,payload());check(exists() and audits()==a,kind+': audit failure rolls back deletion');sql('db()->exec("DROP TRIGGER fail_rule_delete");')
    d=payload();(A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
    check(req(P,o)[0]==200,kind+': unpaid plan can review');req(P,o,d);check(exists(),kind+': unpaid plan cannot delete');(A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
    d=payload();d['program_id']='99999';d[key]='99999';req(P,o,d);check(not exists() and audits()==a+1,kind+': deletion follows fixed URL target and audits once')
    req(P,o,d);check(audits()==a+1 and req(P,o)[0]==404,kind+': repeated POST and old GET cannot delete again')
    check(effect()==other,kind+': points ledger programs rewards and mail unchanged')
    # Two real writers cannot remove/audit one rule twice.
    id=fixture(kind);P='/%s/%d/delete'%(kind,id);r=revision()
    def delete_once(_):return sql("try{program_rule_delete(1,'%s',%d,['revision'=>'%s','confirm_delete'=>'1','name_confirm'=>'Sichere Stufe']);echo 'ok';}catch(InvalidArgumentException){echo 'blocked';}"%(kind,id,r))
    a=audits()
    with concurrent.futures.ThreadPoolExecutor(2) as pool:results=list(pool.map(delete_once,[0,1]))
    check(sorted(results)==['blocked','ok'] and audits()==a+1,kind+': parallel deletes one winner one audit')
    # Edit/delete race: edit wins blocks stale delete, delete wins blocks edit.
    id=fixture(kind);P='/%s/%d/delete'%(kind,id);r=revision();a=audits()
    def race(op):
     if op=='delete':return delete_once(0)
     fields="'name'=>'Race changed','min_points'=>'600','benefit'=>''" if kind=='tier' else "'weekday'=>'1','start_hour'=>'14','end_hour'=>'16','multiplier'=>'4'"
     return sql("try{%s_edit(1,%d,[%s,'revision'=>'%s']);echo 'ok';}catch(InvalidArgumentException){echo 'blocked';}"%(kind,id,fields,r))
    with concurrent.futures.ThreadPoolExecutor(2) as pool:results=list(pool.map(race,['delete','edit']))
    check(sorted(results)==['blocked','ok'],kind+': edit-delete race permits only one reviewed change')
    ui.extend([fixture(kind),fixture(kind)])
   subprocess.run(['node',str(R/'tests/rule-delete-ui.cjs'),str(pid),*[str(i) for i in ui]],cwd=R,check=True)
  finally:s.terminate();s.wait()
print(n,'rule deletion HTTP/domain assertions passed')
