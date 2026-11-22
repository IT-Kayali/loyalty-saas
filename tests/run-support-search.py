#!/usr/bin/env python3
"""Localized inbox metadata search with stable cross-tab pagination/export language."""
import csv,html,http.cookiejar,io,json,os,pathlib,re,shutil,subprocess,tempfile,time,urllib.error,urllib.parse,urllib.request
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0

def check(v,label):
 global n
 assert v,label
 n+=1;print('PASS',label,flush=True)

with tempfile.TemporaryDirectory(prefix='loyalty-support-search-') as temp:
 A=pathlib.Path(temp)
 for folder in ['app','public']:shutil.copytree(R/folder,A/folder)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['platform_admin_emails']=['admin@test.local'];return $c;")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True);subprocess.run([PHP,str(R/'tests/support-search.php')],cwd=A,check=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 fixture=r'''<?php
$d=db();$d->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Hoch','admin@test.local',password_hash('adminpass1',PASSWORD_DEFAULT)]);$admin=(int)$d->lastInsertId();
$d->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Other Account','other@partner.invalid',password_hash('otherpass1',PASSWORD_DEFAULT)]);$other=(int)$d->lastInsertId();$owner=(int)$d->query("SELECT id FROM users WHERE email='demo@loyalty.local'")->fetchColumn();
$q=$d->prepare('INSERT INTO support_tickets(reference,user_id,channel,requester_name,requester_email,topic,subject,status,priority,assignee,deleted_at,updated_at)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$i=0;
$d->exec('BEGIN');foreach(['open','awaiting','closed'] as $status)foreach(['low','normal','high'] as $priority)foreach(['partner','contact'] as $channel){$i++;$q->execute([sprintf('%016X',1000+$i),$channel==='partner'?$owner:null,$channel,'Projektperson '.$i,'PRIVATE-REQUESTER@case.invalid','billing','Projektfall '.$i,$status,$priority,$i%3===0?$admin:null,null,'2026-11-22 12:30:00']);}
for($j=1;$j<=52;$j++)$q->execute([sprintf('%016X',2000+$j),$owner,'partner','Seitenperson '.$j,'PRIVATE-REQUESTER@case.invalid','billing','Mehrseitiger Vorgang '.$j,'awaiting','high',$admin,null,'2026-11-22 12:30:00']);
$q->execute(['ABCDEF0123456789',$owner,'partner','Offen','PRIVATE-LITERAL@case.invalid','technical','Literal closed, %_! and Offen','closed','low',$admin,null,'2026-11-22 12:30:00']);
$q->execute(['BBBBBBBBBBBBBBBB',$other,'partner','Fremde Person','PRIVATE-FOREIGN@case.invalid','billing','Fremder Projektfall','awaiting','high',null,null,'2026-11-22 12:30:00']);
$q->execute(['CCCCCCCCCCCCCCCC',$owner,'partner','Hidden','PRIVATE-HIDDEN@case.invalid','billing','PRIVATE-HIDDEN-ONLY','awaiting','high',null,'2026-11-22 12:30:00','2026-11-22 12:30:00']);
$d->exec("INSERT INTO support_messages(ticket_id,internal,body)SELECT id,1,'PRIVATE-INTERNAL-BODY' FROM support_tickets ORDER BY id LIMIT 1;INSERT INTO support_messages(ticket_id,internal,body)SELECT id,0,'PUBLIC-BODY-ONLY' FROM support_tickets ORDER BY id LIMIT 1");$d->exec('COMMIT');
'''
 (A/'fixture.php').write_text(fixture);sql("require 'fixture.php';")
 records=json.loads(sql("echo json_encode(db()->query('SELECT t.*,a.name AS assigned_name FROM support_tickets t LEFT JOIN users a ON a.id=t.assignee ORDER BY t.id')->fetchAll(PDO::FETCH_ASSOC));"))
 owner_id=int(sql("echo db()->query(\"SELECT id FROM users WHERE email='demo@loyalty.local'\")->fetchColumn();"))
 labels={'de':{'status':{'open':'Offen','awaiting':'Wartet auf Antwort','closed':'Geschlossen'},'priority':{'low':'Niedrig','normal':'Normal','high':'Hoch'},'channel':{'partner':'Angemeldete Betreiber','contact':'Kontaktformular (unbestätigt)'},'unassigned':'Nicht zugewiesen'},'en':{'status':{'open':'Open','awaiting':'Awaiting reply','closed':'Closed'},'priority':{'low':'Low','normal':'Normal','high':'High'},'channel':{'partner':'Signed-in operators','contact':'Contact form (unverified)'},'unassigned':'Unassigned'}}
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def req(path,c,data=None):
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  try:
   with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url,x.headers
  except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url,x.headers
 def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c)[1]).group(1)
 def login(email,pw):
  c=client();req('/login',c,{'csrf':csrf(c,'/login'),'email':email,'password':pw});return c
 def query(f):return urllib.parse.urlencode(f)
 def expected(f,partner=False):
  out=[];locale=f.get('lang','de');q=f.get('q','').strip().casefold()
  for row in records:
   if row['deleted_at'] is not None or (partner and (row['user_id']!=owner_id or row['channel']!='partner')):continue
   if any(f.get(k) and f[k]!=row[k] for k in ['status','topic']+([] if partner else ['priority','channel'])):continue
   if not partner and f.get('assignee','')!='' and f['assignee']!=str(row['assignee'] or 0):continue
   text=[row['reference'],row['subject']]+([] if partner else [row['requester_name'],row['requester_email'],row['assigned_name'] or 'Nicht zugewiesen'])
   hit=not q or any(q in value.casefold() for value in text)
   if not partner and q:
    hit=hit or any(q in labels[locale][key][row[key]].casefold() for key in ['status','priority','channel']) or (row['assigned_name'] is None and q in labels[locale]['unassigned'].casefold())
   if hit:out.append(row['reference'])
  return out
 def list_all(f,c,base='/admin/support'):
  nexturl=base+'?'+query(f);out=[];locale=f.get('lang','de')
  for _ in range(5):
   status,body,_,_=req(nexturl,c);assert status==200,(status,nexturl)
   out+=re.findall('href="'+base+r'/([A-F0-9]{16})"',body)
   m=re.search(r'href="([^"]+)">'+('Next 50' if locale=='en' else 'Nächste 50')+'</a>',body)
   if not m:return out
   nexturl=html.unescape(m.group(1))
  raise AssertionError('Unexpected pagination')
 def exported(f,c,fmt='json'):
  st,body,url,h=req('/admin/support/export?'+query(dict(f,format=fmt)),c)
  return st,body,h
 def state():return sql("$all=[];foreach(['support_tickets','support_messages','support_operations','mail_outbox','audit_logs','platform_settings'] as $t)$all[$t]=db()->query('SELECT * FROM '.$t.' ORDER BY rowid')->fetchAll();echo json_encode($all);")
 with (A/'server.log').open('w') as log:
  server=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
  try:
   for _ in range(60):
    try:urllib.request.urlopen('http://127.0.0.1:8099/login',timeout=1);break
    except OSError:time.sleep(.05)
   admin=login('admin@test.local','adminpass1');owner=login('demo@loyalty.local','demo1234');guest=client();before=state()
   check('/login' in req('/admin/support?lang=en',guest)[2] and req('/admin/support?lang=en',owner)[0]==403 and exported({'lang':'en'},owner)[0]==403,'language never grants platform access')
   for locale in ['de','en']:
    f={'lang':locale};status,body,_,_=req('/admin/support?'+query(f),admin)
    check(status==200 and ('<h1>Support inbox</h1>' if locale=='en' else '<h1>Support-Inbox</h1>') in body and f'name="lang" value="{locale}"' in body,locale+' heading and hidden form language')
    check(all(value in body for key in ['status','priority','channel'] for value in labels[locale][key].values()) and labels[locale]['unassigned'] in body,locale+' visible enum and unassigned labels')
    terms=[v for key in ['status','priority','channel'] for v in labels[locale][key].values()]+[labels[locale]['unassigned'],'WAITING' if locale=='en' else 'BESTÄTIGT','Offen','closed','Hoch','%_!','PRIVATE-LITERAL@case.invalid','PRIVATE-INTERNAL-BODY','PUBLIC-BODY-ONLY','PRIVATE-HIDDEN-ONLY','Technical','o',"x' OR 1=1 --",'AllesNichtVorhanden']
    for i,term in enumerate(terms):
     f={'lang':locale,'q':term};st,b,_=exported(f,admin);refs=[row['reference'] for row in json.loads(b)['tickets']]
     check(st==200 and refs==expected(f) and list_all(f,admin)==expected(f),locale+' independent label/text list and export parity '+str(i))
    combinations=[{'q':labels[locale]['status']['awaiting'],'topic':'billing','priority':'high','channel':'partner'},{'q':labels[locale]['priority']['high'],'priority':'low'},{'q':labels[locale]['channel']['contact'],'channel':'partner'},{'q':labels[locale]['unassigned'],'status':'closed','channel':'contact'},{'q':'Hoch','priority':'low','assignee':str(next(row['assignee'] for row in records if row['assignee']))}]
    for i,f in enumerate(combinations):
     f=dict(f,lang=locale);st,b,_=exported(f,admin);check(st==200 and [r['reference'] for r in json.loads(b)['tickets']]==expected(f) and list_all(f,admin)==expected(f),locale+' label OR remains inside all surrounding filters '+str(i))
    f={'lang':locale,'q':labels[locale]['status']['awaiting'],'priority':'high','topic':'billing','channel':'partner'}
    st,b,_=exported(f,admin);canonical=json.loads(b)['tickets'];lookup={row['reference']:row for row in records}
    check(len(canonical)>50 and all(all(row[k]==lookup[row['reference']][k] for k in ['status','priority','channel','topic','subject','requester_name','assigned_name']) for row in canonical),locale+' original codes names subject in JSON beyond50 rows')
    for fmt in ['csv','tsv']:
     st,b,h=exported(f,admin,fmt);rows=list(csv.DictReader(io.StringIO(b),delimiter='\t' if fmt=='tsv' else ','));check(st==200 and [r['reference'] for r in rows]==expected(f) and all(r['status']=='awaiting' and r['priority']=='high' and r['channel']=='partner' for r in rows) and h['Cache-Control']=='private, no-store',locale+' '+fmt+' localized query still exports original codes')
    partner={'lang':locale,'q':labels[locale]['status']['awaiting'],'channel':'contact','priority':'wrong','assignee':'-1'}
    check(list_all(partner,owner,'/support')==expected(partner,True) and 'support-search-hint' not in req('/support?'+query(partner),owner)[1],locale+' partner search unchanged exact owner/reference/subject only')
    partner={'lang':locale,'q':'Literal','status':'closed'};check(list_all(partner,owner,'/support')==expected(partner,True),locale+' partner literal search and status filter remain usable')
   f={'lang':'de','q':'Wartet','status':'awaiting','priority':'high','channel':'partner','topic':'billing'};body=req('/admin/support?'+query(f),admin)[1]
   olddownload=html.unescape(re.search(r'href="([^"]+)">Liste als JSON</a>',body).group(1));oldnext=html.unescape(re.search(r'href="([^"]+)">Nächste 50</a>',body).group(1))
   check(urllib.parse.parse_qs(urllib.parse.urlparse(olddownload).query)['lang']==['de'] and urllib.parse.parse_qs(urllib.parse.urlparse(oldnext).query)['lang']==['de'],'old language is explicit in both download and next-page links')
   req('/admin/support?lang=en',admin);st,b,_,_=req(olddownload,admin);check(st==200 and [r['reference'] for r in json.loads(b)['tickets']]==expected(f),'another tab language switch cannot alter old German download selection')
   req('/admin/support?lang=en',admin);st,b,_,_=req(oldnext,admin);refs=re.findall(r'href="/admin/support/([A-F0-9]{16})"',b)
   check(st==200 and refs==expected(f)[50:] and '<h1>Support-Inbox</h1>' in b,'another tab language switch cannot alter old German next page')
   for params in ['lang[]=en','lang=fr','lang=EN','lang=','lang=%FF','lang=en%0A']:
    responses=[req(base+'?'+params,admin) for base in ['/admin/support','/admin/support/export']];check(all(r[0]==422 and not r[3].get('Content-Disposition') for r in responses),'invalid locale fails closed both paths '+params)
   check(state()==before,'all localized requests leave tickets messages operations audit settings and mail unchanged')
   subprocess.run(['node',str(R/'tests/support-search-ui.cjs')],cwd=R,check=True)
   after_browser=json.loads(state());original=json.loads(before);old_audit=original['audit_logs'];added=after_browser['audit_logs'][len(old_audit):]
   check(after_browser['audit_logs'][:len(old_audit)]==old_audit and len(added)==3 and all(row['action']=='auth.login' and row['user_id']==next(r['assignee'] for r in records if r['assignee']) for row in added),'browser fixture adds only its three expected admin login audit events')
   after_browser['audit_logs']=old_audit;check(after_browser==original,'browser language searches and downloads leave all business rows and non-login audit unchanged')
   logtext=(A/'server.log').read_text();check('PHP Warning' not in logtext and 'PHP Fatal' not in logtext and 'PRIVATE-INTERNAL-BODY' not in logtext,'no warnings fatals or internal text in logs')
  finally:server.terminate();server.wait(timeout=10)
 print(n,'support search HTTP assertions passed',flush=True)
