#!/usr/bin/env python3
"""Filtered private admin support metadata downloads, no worker or external transport."""
import csv,html,http.cookiejar,io,json,os,pathlib,re,shutil,subprocess,tempfile,time,urllib.error,urllib.parse,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(v,label):
 global n
 assert v,label
 n+=1;print('PASS',label,flush=True)

with tempfile.TemporaryDirectory(prefix='loyalty-support-list-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 config="<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['admin@test.local'];return $c;"
 (A/'config.php').write_text(config);subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 subprocess.run([PHP,str(R/'tests/support-list.php')],cwd=A,check=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 fixture=r'''<?php
function list_fixture(string $mode='normal'):void {
 $d=db();$d->exec('DELETE FROM support_messages;DELETE FROM support_operations;DELETE FROM support_tickets');
 $owner=(int)$d->query("SELECT id FROM users WHERE email='demo@loyalty.local'")->fetchColumn();$admin=(int)$d->query("SELECT id FROM users WHERE email='admin@test.local'")->fetchColumn();$other=(int)$d->query("SELECT id FROM users WHERE email='other@partners.invalid'")->fetchColumn();
 $q=$d->prepare('INSERT INTO support_tickets(reference,user_id,channel,requester_name,requester_email,topic,subject,status,priority,assignee,deleted_at,updated_at)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
 $d->exec('BEGIN');$count=$mode==='normal'?52:5001;
 for($i=1;$i<=$count;$i++)$q->execute([sprintf('%016X',1000+$i),$owner,'partner','Büro Betreiber','PRIVATE-ADDRESS@contact.invalid','billing',($mode==='normal'?'Filterbasis %_! Bügel ':'Mengentest ').$i,'open','high',$admin,null,'2026-11-22 12:30:00']);
 if($mode==='normal'){
  $base=[null,$owner,'partner','Andere Person','PRIVATE-ADDRESS@contact.invalid','billing','Filterbasis %_! Zusatz','open','high',$admin,null,'2026-11-22 12:30:00'];
  foreach([['status','closed'],['topic','technical'],['priority','low'],['channel','contact'],['assignee',null],['assignee',999999],['deleted_at','2026-11-22 12:30:00'],['user_id',$other]] as $i=>$change){$row=$base;$row[0]=sprintf('%016X',2000+$i);$row[array_search($change[0],['reference','user_id','channel','requester_name','requester_email','topic','subject','status','priority','assignee','deleted_at','updated_at'],true)]=$change[1];if($change[0]==='user_id')$row[6]='Fremder Partner';$q->execute($row);}
  $q->execute(['ABCDEF0123456789',null,'contact',"\u{2003}＝1+1",'PRIVATE-SPECIAL@contact.invalid','other',"=1+2,\"quoted\";\r\nzweite Zeile <script>bad()</script>",'open','normal',null,null,'2026-11-22 12:31:00']);
  $d->exec("INSERT INTO support_messages(ticket_id,internal,body)SELECT id,1,'PRIVATE-INTERNAL-BODY' FROM support_tickets ORDER BY id LIMIT 1");
 }
 $d->exec('COMMIT');
}
function list_state():string {
 $all=[];foreach(['support_tickets','support_messages','support_operations','mail_outbox','audit_logs','wallet_push_deliveries','webhook_deliveries'] as $t)$all[$t]=db()->query('SELECT * FROM '.$t.' ORDER BY rowid')->fetchAll();return json_encode($all);
}
'''
 (A/'fixture.php').write_text(fixture)
 sql("db()->exec(\"INSERT INTO users(id,name,email,password_hash)VALUES(999999,'Former Admin','former@test.local','not-a-password')\");db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Admin Support','admin@test.local',password_hash('adminpass1',PASSWORD_DEFAULT)]);db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Other Partner','other@partners.invalid',password_hash('otherpass1',PASSWORD_DEFAULT)]);require 'fixture.php';list_fixture();")
 records=json.loads(sql("echo json_encode(db()->query('SELECT t.*,a.name AS assigned_name FROM support_tickets t LEFT JOIN users a ON a.id=t.assignee ORDER BY t.id')->fetchAll(PDO::FETCH_ASSOC));"))
 owner_id=int(sql("echo db()->query(\"SELECT id FROM users WHERE email='demo@loyalty.local'\")->fetchColumn();"));admin_id=str(next(r['assignee'] for r in records if r['assignee']))
 fields=['reference','subject','topic','status','priority','updated_at_utc','channel','requester_name','assigned_name']
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None,method=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data,method=method),timeout=20) as r:return r.status,r.read().decode(),r.url,r.headers
  except urllib.error.HTTPError as r:return r.code,r.read().decode(),r.url,r.headers
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,pw):
  c=client();req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':pw});return c
 def query(f):return urllib.parse.urlencode(f)
 def matching(f,partner=False):
  out=[]
  for r in records:
   if r['deleted_at'] is not None or (partner and (r['channel']!='partner' or r['user_id']!=owner_id)):continue
   if any(f.get(k) and r[k]!=f[k] for k in ['status','topic']+([] if partner else ['priority','channel'])):continue
   if not partner and f.get('assignee','')!='' and str(r['assignee'] or 0)!=f['assignee']:continue
   columns=[r['reference'],r['subject']]+([] if partner else [r['requester_name'],r['requester_email'],r['assigned_name'] or 'Nicht zugewiesen'])
   if f.get('q') and not any(f['q'].lower() in v.lower() for v in columns):continue
   out.append(r['reference'])
  return out
 def visible_refs(body,base='/admin/support'):
  return re.findall('href="'+base+r'/([A-F0-9]{16})"',body)
 def all_list_refs(f,c,base='/admin/support'):
  url=base+'?'+query(f);refs=[]
  for _ in range(5):
   status,body,_,_=req(url,c);assert status==200
   refs+=visible_refs(body,base);m=re.search(r'href="([^"]+)">Nächste 50</a>',body)
   if not m:return refs
   url=html.unescape(m.group(1))
  raise AssertionError('unexpected pagination depth')
 def export(f,c,fmt='json'):
  return req('/admin/support/export?'+query(dict(f,format=fmt)),c)
 with (A/'server.log').open('w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(60):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login',timeout=1);break
    except OSError:time.sleep(.05)
   admin=login('admin@test.local','adminpass1');owner=login('demo@loyalty.local','demo1234');guest=client();member=client();staff=client()
   assert req('/member/login',member,{'csrf':csrf(member,'/member/login'),'email':'aylin@example.com','password':'kunde1234'})[2].endswith('/wallet')
   assert req('/staff/login',staff,{'csrf':csrf(staff,'/staff/login'),'business_id':1,'name':'Lena','pin':'4321'})[2].endswith('/staff')
   check('/login' in export({},guest)[2] and export({},owner)[0]==403,'guest sign-in and owner platform access checked live')
   check('/login' in export({},member)[2] and '/login' in export({},staff)[2],'member and staff sessions do not grant export')
   before=sql("require 'fixture.php';echo list_state();")
   combined={'q':'%_!','status':'open','topic':'billing','priority':'high','channel':'partner','assignee':admin_id}
   page=req('/admin/support?'+query(combined),admin)[1];first=visible_refs(page)
   check(len(first)==50 and len(matching(combined))==52,'fixture proves matching set is larger than visible page')
   links=dict((fmt.lower(),html.unescape(url)) for url,fmt in re.findall(r'href="([^"]+)">Liste als (CSV|TSV|JSON)</a>',page))
   check(set(links)=={'csv','tsv','json'},'all formats offered on admin list only')
   check(all({k:v[0] for k,v in urllib.parse.parse_qs(urllib.parse.urlparse(u).query).items() if k!='format'}==dict(combined,lang='de') for u in links.values()),'download links retain exactly active filters without cursor')
   for fmt in ['csv','tsv','json']:
    status,body,_,headers=req(links[fmt],admin)
    check(status==200 and headers['Cache-Control']=='private, no-store' and headers['X-Content-Type-Options']=='nosniff','private nosniff successful '+fmt)
    check(re.fullmatch(r'attachment; filename="support-inbox-\d{4}-\d{2}-\d{2}\.'+fmt+'"',headers['Content-Disposition']) is not None and headers.get_content_type()=={'csv':'text/csv','tsv':'text/tab-separated-values','json':'application/json'}[fmt],'fixed safe filename and type '+fmt)
    data=json.loads(body)['tickets'] if fmt=='json' else list(csv.DictReader(io.StringIO(body),delimiter='\t' if fmt=='tsv' else ','))
    check([r['reference'] for r in data]==matching(combined),'all52 filtered rows in stable order '+fmt)
    check(all(list(r)==fields for r in data) and 'PRIVATE-' not in body and 'requester_email' not in body and 'internal' not in body,'fixed metadata whitelist without private columns '+fmt)
   second_url=html.unescape(re.search(r'href="([^"]+)">Nächste 50</a>',page).group(1));page2=req(second_url,admin)[1]
   check(len(visible_refs(page2))==2 and all_list_refs(combined,admin)==matching(combined),'list paging shares exact filters without overlap')
   check('after=' not in re.search(r'href="([^"]+)">Liste als JSON</a>',page2).group(1) and json.loads(req(html.unescape(re.search(r'href="([^"]+)">Liste als JSON</a>',page2).group(1)),admin)[1])['row_count']==52,'second-page export covers whole filter, not cursor suffix')
   cases=[{},combined,{'q':'%_!'}, {'q':'PRIVATE-SPECIAL@contact.invalid'},{'q':'Admin Support'},{'q':'Nicht zugewiesen'},{'status':'closed'},{'topic':'technical'},{'priority':'low'},{'channel':'contact'},{'assignee':'0'},{'assignee':'999999'},{'q':'no matches'},{'topic':'billing','channel':'contact'}]
   for i,f in enumerate(cases):
    expected=matching(f);status,body,_,_=export(f,admin);check(status==200 and [r['reference'] for r in json.loads(body)['tickets']]==expected and all_list_refs(f,admin)==expected,'independent fixture, list and export filter parity '+str(i))
   original=json.loads(export({'q':'PRIVATE-SPECIAL'},admin)[1])['tickets'][0]
   check(original['subject']==next(r['subject'] for r in records if r['reference']=='ABCDEF0123456789') and original['assigned_name'] is None,'JSON keeps hostile text as literal data and nullable assignment')
   for fmt in ['csv','tsv']:
    _,body,_,_=export({'q':'PRIVATE-SPECIAL'},admin,fmt);rows=list(csv.DictReader(io.StringIO(body),delimiter='\t' if fmt=='tsv' else ','))
    check(len(rows)==1 and rows[0]['subject']=="'"+original['subject'] and rows[0]['requester_name']=="'"+original['requester_name'],'spreadsheet quotes contain separators CRLF HTML and neutralize fullwidth prefixes '+fmt)
   bad=['q[]=x','status[]=open','topic[]=billing','priority[]=high','channel[]=contact','assignee[]=0','q=%FF','q=%0A','q=%E2%80%8B','q='+('x'*101),'status=wrong','topic=wrong','priority=wrong','channel=wrong','assignee=-1','assignee=1.5','assignee=99999999999999999999999']
   for i,params in enumerate(bad):
    responses=[req(path+'?'+params,admin) for path in ['/admin/support','/admin/support/export']]
    check(all(r[0]==422 and not r[3].get('Content-Disposition') for r in responses),'invalid applicable filter fails closed list/export '+str(i))
   for params in ['after=0','after=1','after[]=2','format[]=json','format=html','deleted=1','action=delete','user_id=1']:
    st,body,_,h=req('/admin/support/export?'+params,admin);check(st==422 and not h.get('Content-Disposition') and '<table' not in body,'unsupported export scope or format rejected '+params)
   for params in ['after=-1','after=1x','after[]=1','after=999999999999999999999999']:
    check(req('/admin/support?'+params,admin)[0]==422,'invalid list cursor not silently reset '+params)
   check('Liste als CSV' not in req('/support',owner)[1] and all_list_refs({'q':'%_!','channel':'contact','priority':'bad','assignee':'-1'},owner,'/support')==matching({'q':'%_!'},True),'partner still private and admin-only selectors cannot widen access')
   status,body,_,headers=req('/admin/support?q='+urllib.parse.quote('<script>alert(1)</script>'),admin)
   check(status==200 and '<script>alert(1)</script>' not in body,'search value HTML escaped in form and export links')
   for method in ['HEAD','OPTIONS','DELETE']:
    status,_,_,headers=req('/admin/support/export',admin,method=method);check(status==405 and headers['Allow']=='GET','read-only method gate '+method)
   status,_,_,headers=req('/admin/support/export',admin,{'csrf':csrf(admin,'/admin'),'action':'delete'})
   check(status==405 and headers['Allow']=='GET','valid-CSRF POST cannot change tickets through export')
   check(sql("require 'fixture.php';echo list_state();")==before,'all successful reads malformed filters and action attempts leave tickets messages audit outboxes untouched')
   (A/'config.php').write_text(config.replace("['admin@test.local']","[]"));check(export({},admin)[0]==403,'revoked platform-admin allowlist immediately denies export');(A/'config.php').write_text(config)
   (A/'config.php').write_text(config.replace('return $c;',"$c['stripe']['enforce_limits']=true;return $c;"));check(export({},admin)[0]==200,'platform list export independent of tenant paid plan');(A/'config.php').write_text(config)
   subprocess.run(['node',str(R/'tests/support-list-ui.cjs')],cwd=R,check=True)
   sql("require 'fixture.php';list_fixture('large');")
   for fmt in ['csv','tsv','json']:
    st,body,_,h=export({},admin,fmt);check(st==422 and '5000' in body and not h.get('Content-Disposition'),'row5001 no partial download '+fmt)
   sql("db()->exec('DELETE FROM support_tickets WHERE id=(SELECT MAX(id) FROM support_tickets)');")
   for fmt in ['csv','tsv','json']:
    st,body,_,h=export({},admin,fmt);rows=json.loads(body)['tickets'] if fmt=='json' else list(csv.DictReader(io.StringIO(body),delimiter='\t' if fmt=='tsv' else ','));check(st==200 and len(rows)==5000,'exact5000 HTTP rows retained '+fmt)
   sql("db()->prepare('UPDATE support_tickets SET subject=?,requester_name=?')->execute([str_repeat('🧪',160),str_repeat('🧪',120)]);db()->prepare(\"UPDATE users SET name=? WHERE email='admin@test.local'\")->execute([str_repeat('🧪',120)]);")
   for fmt in ['csv','tsv','json']:
    st,body,_,h=export({},admin,fmt);check(st==422 and '4 MiB' in body and not h.get('Content-Disposition'),'encoded UTF-8 byte cap no partial HTTP '+fmt)
   sql("db()->exec(\"DELETE FROM support_tickets WHERE id<>(SELECT MIN(id) FROM support_tickets);UPDATE support_tickets SET subject=CAST(X'FF' AS TEXT)\");")
   for fmt in ['csv','tsv','json']:
    st,body,_,h=export({},admin,fmt);check(st==422 and 'UTF-8' in body and not h.get('Content-Disposition'),'malformed stored UTF-8 readable error without replacement '+fmt)
   logtext=(A/'server.log').read_text();check('PHP Warning' not in logtext and 'PHP Fatal' not in logtext and 'PRIVATE-INTERNAL-BODY' not in logtext,'no runtime warnings fatals or internal text in logs')
  finally:server.terminate();server.wait(timeout=10)
 print(n,'support list HTTP assertions passed',flush=True)
