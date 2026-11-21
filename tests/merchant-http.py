#!/usr/bin/env python3
import pathlib,sys,subprocess,json,urllib.request,urllib.error
A=pathlib.Path(sys.argv[1]);PHP=sys.argv[2];n=0

def sql(s):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+s],cwd=A,text=True).strip()
keys=json.loads(sql("echo json_encode(db()->query('SELECT api_key FROM businesses ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));"))
def req(path,body=None,key=keys[0]):
 headers={'Content-Type':'application/json','Authorization':'Bearer '+key}
 try:
  with urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8099/api/v1/merchant'+path,data=json.dumps(body).encode() if body is not None else None,headers=headers)) as r:return r.status,json.loads(r.read())
 except urllib.error.HTTPError as r:return r.code,json.loads(r.read())
def yes(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
yes(req('/summary',key='bad')[0]==401,'bad key rejected');code,summary=req('/summary');yes(code==200 and summary['business']['id']==1,'summary bound to business key')
code,members=req('/members');yes(code==200 and len(members['members'])>0,'member list served');code,other=req('/members',key=keys[1]);yes(not ({m['id'] for m in members['members']} & {m['id'] for m in other['members']}),'tenant lists isolated')
m=members['members'][0];yes(req('/members/'+m['code'],key=keys[1])[0]==404,'other tenant cannot read member code')
for q in ['%25','_','%5C','%27']:
 yes(req('/members?q='+q)[0]==200,'literal search wildcard safe '+q)
new={'operation':'e'*32,'name':'Browser Customer','email':'browser@example.test'};code,r=req('/members',new);yes(code==200 and r['ok'],'create API');yes(req('/members',new)[1]==r,'create HTTP replay stable');mid=r['member']['id'];mc=r['member']['code'];cards=req('/members/'+mc)[1]['cards'];cid=next(c['id'] for c in cards if c['type']=='points')
a={'operation':'f'*32,'action':'points_add','amount':5};yes(req('/cards/'+str(cid)+'/action',a)[0]==200,'card action HTTP');first=req('/members/'+mc)[1];req('/cards/'+str(cid)+'/action',a);yes(req('/members/'+mc)[1]==first,'card replay no change');yes(req('/cards/'+str(cid)+'/action',dict(a,amount=6))[0]==422,'conflicting replay HTTP rejected');yes(req('/cards/'+str(cid)+'/action',dict(a,operation='1'*32),key=keys[1])[0]==422,'other business write rejected');yes(req('/members/'+str(mid)+'/edit',{'operation':'2'*32,'name':'New','email':'changed@example.test'})[0]==422,'email immutable over HTTP')
yes(req('/members/0/edit',dict(new,operation='4'*32))[0]==404,'zero edit ID never creates a member')
# More than one page and cursor stability.
sql("$s=db()->prepare('INSERT INTO members(business_id,name,email,code)VALUES(1,?,?,?)');for($i=0;$i<55;$i++)$s->execute(['Page member '.$i,'page'.$i.'@example.test',strtoupper(substr(hash('sha256','page'.$i),0,10))]);")
p1=req('/members?q=Page')[1];p2=req('/members?q=Page&after='+str(p1['next_after']))[1];yes(len(p1['members'])==50 and len(p2['members'])==5 and p2['next_after'] is None,'cursor 50 plus 5');yes(not ({m['id'] for m in p1['members']} & {m['id'] for m in p2['members']}),'pages do not repeat members')
# Independent PHP processes race the same operation against SQLite.
worker=pathlib.Path(__file__).with_name('merchant-concurrent.php');before=int(sql("echo db()->query('SELECT COUNT(*) FROM transactions')->fetchColumn();"));workers=[subprocess.Popen([PHP,str(worker)],cwd=A,stdout=subprocess.PIPE,text=True) for _ in range(2)];results=[p.communicate()[0] for p in workers];yes(all(p.returncode==0 for p in workers) and json.loads(results[0])==json.loads(results[1]),'concurrent workers return one saved outcome');yes(int(sql("echo db()->query('SELECT COUNT(*) FROM transactions')->fetchColumn();"))==before+1,'concurrent operation creates one journal entry')
# Plan enforced on write, not read.
cfg="<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;";(A/'config.php').write_text(cfg)
yes(req('/members',dict(new,operation='3'*32,email='denied@example.test'))[0]==402,'inactive plan blocks merchant write');yes(req('/summary')[0]==200,'inactive plan still permits existing reads');(A/'config.php').unlink()
sql("db()->exec(\"DELETE FROM members WHERE email LIKE 'page%@example.test'\");")
print(n,'merchant HTTP assertions passed')
