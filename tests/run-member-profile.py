#!/usr/bin/env python3
"""Eigener Name je Unternehmen: nur eigene Datensaetze, keine Konten-/Saldoaenderung, Browser."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-member-profile-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public','tools']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 log=open(A/'server.log','w');s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  def mk():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  def req(path,data=None,c=None):
   if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
   try:
    with (c or mk()).open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode('utf-8','replace'),x.url
   except urllib.error.HTTPError as x:return x.code,x.read().decode('utf-8','replace'),x.url
  for _ in range(50):
   try:req('/member/login');break
   except OSError:time.sleep(.1)
  def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c=c)[1]).group(1)
  c=mk();req('/member/login',{'csrf':csrf(c,'/member/login'),'email':'aylin@example.com','password':'kunde1234'},c)
  mid=int(sql("echo db()->query(\"SELECT id FROM members WHERE email='aylin@example.com'\")->fetchColumn();"))
  ben=int(sql("echo db()->query(\"SELECT id FROM members WHERE email='ben@example.com'\")->fetchColumn();"))
  second=int(sql("$s=db()->prepare('INSERT INTO members(business_id,name,email,code)VALUES(2,?,?,?)');$s->execute(['Aylin privat','AYLIN@example.com','ACACACACAC']);echo db()->lastInsertId();"))
  names=lambda:json.dumps(json.loads(sql("echo json_encode(db()->query('SELECT id,name FROM members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));")),ensure_ascii=False)
  core=lambda:sql("echo json_encode(db()->query('SELECT id,email,password_hash,session_version,marketing_consent,birthday FROM members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));")
  cards=lambda:sql("echo json_encode(db()->query('SELECT * FROM cards ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));")
  txns=lambda:sql("echo json_encode(db()->query('SELECT * FROM transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));")
  P='/member/profile';before=names();cb=core();sb=cards();tb=txns()
  def save(name='Aylin Neu',id=mid,token=True,client=c):
   f={'member_id':id,'name':name,'email':'ben@example.com','points':'999999','password':'not-accepted'}
   if token:f['csrf']=csrf(client,P)
   return req(P,f,client)
  check(req(P,c=mk())[2].endswith('/member/login'),'Nicht angemeldet: kein Profileditor')
  page=req(P,c=c)[1];check(page.count('class="panel member-profile"')>=2 and 'Aylin privat' in page,'Alle eigenen Unternehmen getrennt angezeigt')
  save(token=False);check(names()==before,'Ohne CSRF keine Änderung')
  check(save(id=ben)[0]==404 and names()==before,'Fremde Mitglieds-ID abgelehnt')
  check(save(id=99999)[0]==404 and names()==before,'Unbekannte ID abgelehnt')
  for invalid in ['', ' '*3, 'x'*81, 'Name\nInjection', 'Na'+chr(0)+'me']:
   save(invalid);check(names()==before,'Ungültiger Name abgelehnt')
  save(b'\xff');check(names()==before,'Ungültiges UTF-8 abgelehnt')
  save('Ü'*80);check(('Ü'*80) in names(),'80 Mehrbyte-Zeichen erlaubt, keine Byte-Kürzung')
  save('  Aylin Öztürk  ')
  check('Aylin Öztürk' in names() and 'Aylin privat' in names(),'Name getrimmt und nur ausgewähltes Unternehmen geändert')
  save('Zweitname',id=second);check('Zweitname' in names() and 'Aylin Öztürk' in names(),'Zweites eigenes Unternehmen unabhängig änderbar')
  save('<script>alert(1)</script>')
  page=req(P,c=c)[1];check('&lt;script&gt;alert(1)&lt;/script&gt;' in page and 'value="<script>' not in page,'Name in Formular und Ansicht maskiert')
  check(core()==cb and cards()==sb and txns()==tb,'E-Mail/Hash/Sitzung/Einwilligung und alle Karten/Buchungen unverändert')
  (A/'config.php').write_text("<?php $c=require __DIR__.'/config.example.php';$c['stripe']['enforce_limits']=true;return $c;")
  check('Name für dieses Unternehmen gespeichert' in save('Planfrei')[1],'Eigener Name trotz inaktivem Betreiber-Abo änderbar')
  (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
  for i in range(21):status,_,_=save('Name '+str(i))
  check(status==429,'Kontogebundenes Rate-Limit für Profiländerungen')
  subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
  subprocess.run(['node',str(R/'tests/member-profile-ui.cjs')],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Mitglieder-Profil-Prüfungen bestanden')
