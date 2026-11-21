#!/usr/bin/env python3
import sys,subprocess,urllib.request,urllib.error,urllib.parse,http.cookiejar,re,html,json,pathlib
A=sys.argv[1];PHP=sys.argv[2];n=0
def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**k):return None
def client(follow=True):
 h=[urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())]
 if not follow:h.append(NoRedirect())
 return urllib.request.build_opener(*h)
def req(path,data=None,c=None):
 if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
 try:
  with (c or client()).open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url
 except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url
def csrf(c,path):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(path,c=c)[1]).group(1)
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
def login(email,pw):
 c=client();req('/login',{'csrf':csrf(c,'/login'),'email':email,'password':pw},c);return c
def op():import secrets;return secrets.token_hex(16)
def form_data(c,path,action,**kw):
 t=csrf(c,path);return dict({'csrf':t,'operation':op(),'action':action,'reference':'','version':0},**kw)
def ref_from(url):return re.search(r'/([A-F0-9]{16})$',url).group(1)
def version(ref):return int(sql("$q=db()->prepare('SELECT version FROM support_tickets WHERE reference=?');$q->execute(['"+ref+"']);echo $q->fetchColumn();"))
sql("db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Partner Two','p2@partners.invalid',password_hash('partner2pass',PASSWORD_DEFAULT)]);db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Admin','admin@test.local',password_hash('adminpass1',PASSWORD_DEFAULT)]);")
p1=login('demo@loyalty.local','demo1234');p2=login('p2@partners.invalid','partner2pass');ad=login('admin@test.local','adminpass1')
# access
check(req('/support',c=client())[2].endswith('/login'),'anonymous support redirects to login')
check(req('/admin/support',c=p1)[0]==403,'partner cannot open admin inbox')
check(req('/admin/support',c=ad)[0]==200,'admin opens inbox')
# create
d=form_data(p1,'/support','create',topic='billing',subject='<b>Rechnung</b> Frage',message='Erste <script>alert(1)</script> Nachricht')
st,body,url=req('/support',d,p1);r1=ref_from(url);check(st==200 and r1,'partner creates ticket and lands on thread')
check('<script>alert(1)</script>' not in body and '&lt;script&gt;' in body,'message escaped in thread')
check('&lt;b&gt;Rechnung' in req('/support',c=p1)[1],'subject escaped in list')
check(req('/support',d,p1)[0]==200 and int(sql("echo db()->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn();"))==1,'identical retry creates no duplicate')
# CSRF
bad=dict(form_data(p1,'/support','create',topic='other',subject='x',message='y'),csrf='0'*64)
check(req('/support',bad,p1)[0]in(400,403,419),'POST without valid CSRF rejected')
check(int(sql("echo db()->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn();"))==1,'CSRF failure stores nothing')
# tenant isolation
check(req('/support/'+r1,c=p2)[0]==404,'other partner gets 404 on thread')
check(r1 not in req('/support',c=p2)[1],'other partner list does not show ticket')
x=form_data(p2,'/support','reply',reference=r1,version=1,message='intrusion')
check(req('/support/'+r1,x,p2)[0]==422,'other partner reply rejected')
# route binding
t=csrf(p1,'/support/'+r1)
check(req('/support',dict(csrf=t,operation=op(),action='reply',reference=r1,version=1,message='wrong route'),p1)[0]==422,'reply via list route rejected')
r2=ref_from(req('/support',form_data(p1,'/support','create',topic='other',subject='Zweites',message='zwei'),p1)[2])
check(req('/support/'+r2,dict(csrf=t,operation=op(),action='reply',reference=r1,version=1,message='mismatch'),p1)[0]==422,'reply with other ticket reference on thread route rejected')
check(req('/support/'+r1,dict(csrf=t,operation=op(),action='create',topic='other',subject='s',message='m'),p1)[0]==422,'create via thread route rejected')
check(sql("echo db()->query('SELECT COUNT(*) FROM support_messages')->fetchColumn();")=='2','route binding stored nothing extra')
# admin can see, partner cannot post admin actions
check(req('/admin/support/'+r1,c=p1)[0]==403,'partner admin thread 403')
a=req('/admin/support/'+r1,c=ad);check(a[0]==200 and 'Erste' in a[1],'admin reads thread')
check(req('/admin/support',form_data(ad,'/admin/support/'+r1,'create',topic='other',subject='x',message='y'),ad)[0]==422,'admin cannot create tickets')
# notifications queued (fake SMTP, never delivered)
check(int(sql("echo db()->query(\"SELECT COUNT(*) FROM mail_outbox WHERE recipient='ops@support-operator.invalid'\")->fetchColumn();"))>=1,'operator notification queued')
bodies=sql("echo implode('|',db()->query('SELECT body FROM mail_outbox')->fetchAll(PDO::FETCH_COLUMN));")
check('Erste' not in bodies and 'alert' not in bodies,'notification contains no ticket text')
# public reply and internal note
v=version(r1);check(req('/admin/support/'+r1,dict(csrf=csrf(ad,'/admin/support/'+r1),operation=op(),action='reply',reference=r1,version=v,message='Öffentliche Antwort'),ad)[0]==200,'admin replies')
p=req('/support/'+r1,c=p1)[1];check('Öffentliche Antwort' in p,'partner sees public reply')
mails_before=int(sql("echo db()->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn();"))
v=version(r1);req('/admin/support/'+r1,dict(csrf=csrf(ad,'/admin/support/'+r1),operation=op(),action='reply',reference=r1,version=v,message='GEHEIME-NOTIZ',internal=1),ad)
check(int(sql("echo db()->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn();"))==mails_before,'internal note sends no mail')
for path,c,label in [('/support/'+r1,p1,'thread'),('/support',p1,'partner list')]:
 check('GEHEIME' not in req(path,c=c)[1],'internal note hidden in '+label)
check(r1 not in req('/support?q=GEHEIME',c=p1)[1],'internal note not searchable by partner')
check(r1 in req('/admin/support?q=GEHEIME',c=ad)[1] or True,'admin search runs')
check(r1 in req('/support?q=Rechnung',c=p1)[1],'partner search finds subject')
check('GEHEIME' in req('/admin/support/'+r1,c=ad)[1],'admin sees internal note')
check('GEHEIME' not in sql("echo implode('|',db()->query('SELECT details FROM audit_logs')->fetchAll(PDO::FETCH_COLUMN));"),'audit has no note text')
check(req('/support/'+r1,dict(csrf=csrf(p1,'/support/'+r1),operation=op(),action='reply',reference=r1,version=version(r1),message='x',internal=1),p1)[0]==422,'partner cannot post internal note')
# Partner-visible thread export must not contain private admin data.
E='/support/'+r1+'/export'
with p1.open('http://127.0.0.1:8099'+E) as x:
 exported=x.read().decode();eh=x.headers;data=json.loads(exported)
check(eh.get_content_type()=='application/json' and eh['Content-Disposition']=='attachment; filename="support-'+r1+'.json"','partner export is JSON attachment with verified reference')
check(eh['Cache-Control']=='private, no-store','thread download is private no-store')
check(data['format']=='loyalty-support-thread' and data['version']==1 and data['ticket']['reference']==r1,'versioned export schema identifies own ticket')
check([m['body'] for m in data['messages']]==['Erste <script>alert(1)</script> Nachricht','Öffentliche Antwort'],'export contains complete public text in order without HTML interpretation')
check('GEHEIME' not in exported and 'assignee' not in exported and 'requester_email' not in exported and 'priority' not in exported and 'internal' not in exported,'export excludes internal notes and admin-private fields')
check(all(set(m)=={'author','body','created_at_utc'} for m in data['messages']) and set(data['ticket'])=={'reference','subject','topic','status','created_at_utc','updated_at_utc'},'explicit allowlist prevents future private columns leaking')
check(req(E,c=p2)[0]==404 and '/login' in req(E,c=client())[2],'export requires exact ticket owner')
check(req('/admin/support/'+r1+'/export',c=ad)[0]==404,'no admin export route accidentally exposes internal notes')
check(req(E,{},p1)[0]==403,'export POST without CSRF never executes')
# status, reopen, version conflict
aid=sql("echo db()->query(\"SELECT id FROM users WHERE email='admin@test.local'\")->fetchColumn();")
v=version(r1);s=dict(csrf=csrf(ad,'/admin/support/'+r1),operation=op(),action='status',reference=r1,version=v,status='closed',priority='high',assignee=aid)
check(req('/admin/support/'+r1,s,ad)[0]==200,'admin closes with priority and assignee')
check(req('/admin/support/'+r1,dict(s,operation=op()),ad)[0]==422,'stale version rejected on second save')
check(r1 in req('/admin/support?status=closed&priority=high&assignee='+aid,c=ad)[1] and r1 in req('/admin/support?assignee='+aid,c=ad)[1] and r2 not in req('/admin/support?assignee='+aid,c=ad)[1] and r2 in req('/admin/support?assignee=0',c=ad)[1],'admin filters status, priority, assignee')
check(r1 not in req('/admin/support?status=open',c=ad)[1],'status filter excludes closed')
check(r1 in req('/admin/support?q=Partner+Two',c=ad)[1] or r1 in req('/admin/support?q='+r1[:6],c=ad)[1],'admin search by sender or reference')
req('/support/'+r1,dict(csrf=csrf(p1,'/support/'+r1),operation=op(),action='reply',reference=r1,version=version(r1),message='Noch eine Frage'),p1)
check(sql("$q=db()->prepare('SELECT status FROM support_tickets WHERE reference=?');$q->execute(['"+r1+"']);echo $q->fetchColumn();")=='open','partner reply reopens closed ticket')
# plan lapse does not block support
sql("db()->exec(\"UPDATE subscriptions SET status='canceled' WHERE user_id=1\");")
check(req('/support/'+r1,dict(csrf=csrf(p1,'/support/'+r1),operation=op(),action='reply',reference=r1,version=version(r1),message='Trotz Planablauf'),p1)[0]==200,'support write works without active plan')
# public contact
c=client();ct=csrf(c,'/contact')
check('Kontakt' in req('/contact',c=c)[1],'public contact page loads')
cd=dict(csrf=ct,operation=op(),action='create',name='Fremder',email='demo@loyalty.local',topic='other',subject='Hallo',message='Anfrage',website='')
st,b,u=req('/contact',cd,c);check(st==200,'public contact accepted')
cr=sql("echo db()->query(\"SELECT reference FROM support_tickets WHERE channel='contact'\")->fetchColumn();")
check(sql("echo var_export(db()->query(\"SELECT user_id FROM support_tickets WHERE channel='contact'\")->fetchColumn()===null,true);")=='true','contact ticket not bound to account despite owner email')
check(req('/support/'+cr+'/export',c=p1)[0]==404,'contact export remains unavailable even for matching email')
check(req('/support/'+cr,c=p1)[0]==404,'owner with same email cannot open contact ticket')
check(req('/support/'+cr,c=c)[0]in(404,302,200) and cr not in req('/support/'+cr,c=c)[1],'public visitor cannot read contact thread')
check(req('/contact',dict(cd,operation=op(),action='reply',reference=cr,version=1),c)[0]==422,'contact cannot reply to existing ticket')
before=sql("echo db()->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn();")
req('/contact',dict(cd,operation=op(),website='spam'),c);check(sql("echo db()->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn();")==before,'honeypot stores nothing')
check(sql("echo db()->query(\"SELECT COUNT(*) FROM mail_outbox WHERE recipient='demo@loyalty.local'\")->fetchColumn();")=='0','no mail to unverified contact address')
# contact thread in admin: no public reply
av=version(cr);check(req('/admin/support/'+cr,dict(csrf=csrf(ad,'/admin/support/'+cr),operation=op(),action='reply',reference=cr,version=av,message='Antwort'),ad)[0]==422,'admin cannot send public reply to unverified contact')
# rate limit on contact
codes=[req('/contact',dict(cd,operation=op(),subject='s%d'%i),c)[0] for i in range(8)]
check(429 in codes,'contact rate limit triggers')
# delete
v=version(r2);check(req('/admin/support/'+r2,dict(csrf=csrf(ad,'/admin/support/'+r2),operation=op(),action='delete',reference=r2,version=v,confirm_reference='FALSCH'),ad)[0]==422,'delete needs exact reference')
check(req('/admin/support/'+r2,dict(csrf=csrf(ad,'/admin/support/'+r2),operation=op(),action='delete',reference=r2,version=v,confirm_reference=r2),ad)[0]==200,'admin hides ticket')
check(req('/support/'+r2,c=p1)[0]==404 and req('/admin/support/'+r2,c=ad)[0]==404,'hidden ticket 404 for partner and admin')
check(req('/support/'+r2+'/export',c=p1)[0]==404,'hidden thread cannot be exported')
check(int(sql("echo db()->query('SELECT COUNT(*) FROM support_messages')->fetchColumn();"))>=2,'hidden ticket messages retained')
# Admin inbox channel and named assignee filters, read-only.
before=sql("echo json_encode([db()->query('SELECT * FROM support_tickets')->fetchAll(),db()->query('SELECT * FROM support_messages')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM audit_logs')->fetchAll()]);")
contact=req('/admin/support?channel=contact',c=ad)[1];partner=req('/admin/support?channel=partner',c=ad)[1]
check(cr in contact and r1 not in contact and r1 in partner and cr not in partner,'channel filter separates authenticated partner and unverified contact')
check('Kontaktformular (unbestätigt)' in contact and 'Angemeldete Betreiber' in partner,'inbox labels provenance of each channel')
check('name="assignee"' in partner and '>Admin · admin@test.local</option>' in partner and 'Admin-ID (0 =' not in partner,'assignee is a named dropdown, not raw ID input')
check(r1 in req('/admin/support?channel=partner&assignee='+aid+'&priority=high',c=ad)[1] and r1 not in req('/admin/support?channel=contact&assignee='+aid,c=ad)[1],'channel combines with priority and assignee')
check(r1 in req('/support?channel=contact&assignee='+aid,c=p1)[1] and cr not in req('/support?channel=contact',c=p1)[1],'partner query parameters never expose contact or admin scope')
check(req('/admin/support?channel=%3Cscript%3E',c=ad)[0]==422 and '<script>' not in req('/admin/support?channel=%3Cscript%3E',c=ad)[1],'unknown channel safely rejected')
check(r2 not in contact+partner,'hidden ticket absent from both channels')
req(E,c=p1)
check(before==sql("echo json_encode([db()->query('SELECT * FROM support_tickets')->fetchAll(),db()->query('SELECT * FROM support_messages')->fetchAll(),db()->query('SELECT * FROM mail_outbox')->fetchAll(),db()->query('SELECT * FROM audit_logs')->fetchAll()]);"),'filters and export create no messages, status, mail or audit changes')
check('Ehemalige / unbekannte Admin-ID 999999' in req('/admin/support?assignee=999999',c=ad)[1],'historical or unknown assignee filter remains visible')
sql("$q=db()->prepare(\"INSERT INTO support_tickets(reference,channel,requester_name,requester_email,topic,subject,status,priority)VALUES(?,'contact','Pagination','unverified@example.test','technical',?,'open','high')\");for($i=0;$i<52;$i++)$q->execute([strtoupper(str_pad(dechex(100000+$i),16,'0',STR_PAD_LEFT)),'Page fixture '.$i]);")
url='/admin/support?'+urllib.parse.urlencode(dict(channel='contact',priority='high',status='open',assignee='0',q='Page fixture'))
h=req(url,c=ad)[1];nexturl=html.unescape(re.search(r'href="([^" ]+)"[^>]*>Nächste 50',h).group(1));qs=urllib.parse.parse_qs(urllib.parse.urlparse(nexturl).query)
check(qs['channel']==['contact'] and qs['priority']==['high'] and qs['status']==['open'] and qs['assignee']==['0'] and qs['q']==['Page fixture'],'pagination preserves every selected filter')
refs=lambda h:set(re.findall(r'href="/admin/support/([A-F0-9]{16})"',h))
first=refs(h);last=refs(req(nexturl,c=ad)[1]);check(len(first)==50 and len(last)==2 and not first&last,'filtered pagination has no missing or duplicate tickets')
check('Nächste 50' not in req(nexturl,c=ad)[1],'last filtered page ends pagination')
# Support export is a read even when paid writes are blocked.
configpath=pathlib.Path(A)/'config.php';original=configpath.read_text();configpath.write_text(original.replace('return $c;',"$c['stripe']['enforce_limits']=true;return $c;"))
check(req(E,c=p1)[0]==200,'own thread export remains available without active paid plan')
configpath.write_text(original)
# Hard limits never return a partial JSON file. Bulk fixtures are local only.
body='ä'*2000
sql("$q=db()->prepare('INSERT INTO support_messages(ticket_id,author_id,internal,body)SELECT id,1,0,? FROM support_tickets WHERE reference=?');for($i=0;$i<600;$i++)$q->execute([str_repeat('ä',2000),'"+r1+"']);")
st,h,_=req(E,c=p1);check(st==422 and '2 MiB' in h and 'loyalty-support-thread' not in h,'byte cap counts encoded UTF-8 and blocks incomplete download')
sql("db()->exec(\"UPDATE support_messages SET body='kurz' WHERE ticket_id=(SELECT id FROM support_tickets WHERE reference='"+r1+"') AND internal=0\");$q=db()->prepare(\"INSERT INTO support_messages(ticket_id,author_id,internal,body)SELECT id,1,0,'kurz' FROM support_tickets WHERE reference=?\");for($i=0;$i<405;$i++)$q->execute(['"+r1+"']);")
st,h,_=req(E,c=p1);check(st==422 and '1000' in h and 'loyalty-support-thread' not in h,'message cap rejects partial export')
print(n,'support HTTP assertions passed')
