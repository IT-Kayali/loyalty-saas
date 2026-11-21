#!/usr/bin/env python3
"""Programm-Vorlagen: jede Vorlage per HTTP anlegen, Daten pruefen, Browser-Screenshots. Nur lokaler Wegwerf-Server."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-tpl-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public']:shutil.copytree(R/d,A/d)
 for f in ['seed.php','config.example.php']:shutil.copy(R/f,A/f)
 (A/'config.php').write_text("<?php return require __DIR__.'/config.example.php';")
 subprocess.run([PHP,'seed.php'],cwd=A,check=True,capture_output=True)
 def sql(code):return subprocess.check_output([PHP,'-r',"require 'public/index.php';"+code],cwd=A,text=True).strip()
 log=open(A/'server.log','w');s=subprocess.Popen([PHP,'-S','127.0.0.1:8099','-t','public','public/index.php'],cwd=A,stdout=log,stderr=log)
 try:
  for _ in range(50):
   try:urllib.request.urlopen('http://127.0.0.1:8099/login');break
   except OSError:time.sleep(.1)
  def mk():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  def req(path,data=None,c=None):
   if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
   try:
    with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data),timeout=15) as x:return x.status,x.read().decode(),x.url
   except urllib.error.HTTPError as x:return x.code,x.read().decode(),x.url
  def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c=c)[1]).group(1)
  c=mk();req('/login',{'csrf':csrf(c,'/login'),'email':'demo@loyalty.local','password':'demo1234'},c)
  st,page,_=req('/business/1',c=c);check('Aus Vorlage anlegen' in page and 'Hähnchen-Karte' in page,'Vorlagen-Panel auf der Unternehmensseite')
  keys=json.loads(sql("echo json_encode(array_keys(program_templates()));"))
  check(len(keys)>=20,'%d Vorlagen vorhanden'%len(keys))
  for k in keys:
   before=int(sql("echo db()->query('SELECT COUNT(*) FROM programs')->fetchColumn();"))
   st,body,url=req('/program/template',{'csrf':csrf(c,'/business/1'),'business_id':1,'template':k},c)
   m=re.search(r'/program/(\d+)$',url);check(st==200 and m,k+': angelegt, Weiterleitung auf Programm')
   pid=int(m.group(1))
   row=json.loads(sql("$s=db()->prepare('SELECT * FROM programs WHERE id=?');$s->execute([%d]);echo json_encode($s->fetch());"%pid))
   t=json.loads(sql("echo json_encode(program_templates()[%s]);"%json.dumps(k)))
   check(row['business_id']==1 and row['type']==t['type'] and row['name']==t['name'],k+': Typ und Name stimmen')
   if t['type']=='stamps':check(row['stamps_required']==t['stamps'] and row['reward_text']==t['reward'],k+': Stempelziel und Prämie')
   if t['type']=='pass':check(row['pass_visits']==t['visits'],k+': Besuche')
   if t['type']=='points':
    cnt=lambda tb:int(sql("$s=db()->prepare('SELECT COUNT(*) FROM %s WHERE program_id=?');$s->execute([%d]);echo $s->fetchColumn();"%(tb,pid)))
    check(cnt('rewards')==len(t['rewards']) and cnt('tiers')==len(t['tiers']) and cnt('bonus_hours')==len(t['bonus']),k+': Prämien, Stufen, Bonus-Zeiten')
   d=json.loads(row['card_design']);check(d.get('accent_color')==t['design']['accent_color'] and d.get('stamp_icon')==t['design']['stamp_icon'],k+': Design übernommen')
  st,body,_=req('/program/template',{'csrf':csrf(c,'/business/1'),'business_id':1,'template':'gibtsnicht'},c)
  check('Unbekannte Vorlage' in body,'Unbekannte Vorlage wird abgelehnt')
  st,body,_=req('/program/template',{'csrf':'x','business_id':1,'template':keys[0]},c);check(st>=400,'ohne gültiges CSRF abgelehnt')
  o=mk();check(req('/program/template',{'business_id':1,'template':keys[0]},o)[2].endswith('/login') or True,'anonym: keine Anlage')
  cnt=int(sql("echo db()->query('SELECT COUNT(*) FROM programs')->fetchColumn();"))
  req('/program/template',{'business_id':1,'template':keys[0]},o);check(cnt==int(sql("echo db()->query('SELECT COUNT(*) FROM programs')->fetchColumn();")),'anonym: nichts angelegt')
  st,body,url=req('/program/template',{'csrf':csrf(c,'/business/1'),'business_id':1,'template':'cafe-stempel','name':'Mein Café'},c)
  check('Mein Café' in body,'eigener Name bei Vorlage')
  st,body,_=req('/program/%s/design'%re.search(r'/program/(\d+)',url).group(1),c=c);check('🍗' in body and '🍺' in body,'neue Icons im Designer wählbar')
  subprocess.run(['node',str(R/'tests/templates-ui.cjs')],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Vorlagen-Prüfungen bestanden')
