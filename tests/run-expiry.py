#!/usr/bin/env python3
"""Punkteverfall: Einstellung, Verfall per Funktion/CLI/Button, Grenzen, Lifetime, Wallet-Hinweis. Lokaler Wegwerf-Server."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-exp-') as temp:
 A=pathlib.Path(temp)
 for d in ['app','public','tools']:shutil.copytree(R/d,A/d)
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
  # frisches Punkteprogramm mit drei Karten
  pid=int(sql("$s=db()->prepare(\"INSERT INTO programs(business_id,type,name) VALUES(1,'points','Verfall-Test')\");$s->execute();echo db()->lastInsertId();"))
  mem=json.loads(sql("echo json_encode(db()->query('SELECT id FROM members WHERE business_id=1 LIMIT 3')->fetchAll(PDO::FETCH_COLUMN));"))
  cards=[]
  for m in mem:
   cards.append(int(sql("$s=db()->prepare('INSERT INTO cards(program_id,member_id,points,points_lifetime) VALUES(?,?,100,100)');$s->execute([%d,%d]);$id=db()->lastInsertId();txn((int)$id,'points_add',100,'Start');echo $id;"%(pid,m))))
  def age(cid,days):sql("db()->prepare(\"UPDATE transactions SET created_at=datetime('now',?) WHERE card_id=?\")->execute(['-%d days',%d]);db()->prepare(\"UPDATE cards SET created_at=datetime('now',?) WHERE id=?\")->execute(['-%d days',%d]);"%(days,cid,days,cid))
  pts=lambda cid:int(sql("echo db()->query('SELECT points FROM cards WHERE id=%d')->fetchColumn();"%cid))
  life=lambda cid:int(sql("echo db()->query('SELECT points_lifetime FROM cards WHERE id=%d')->fetchColumn();"%cid))
  age(cards[0],400);age(cards[1],100);age(cards[2],10)
  # aus: nichts passiert
  check(sql("echo json_encode(expire_program_points(%d));"%pid)=='[0,0]' and pts(cards[0])==100,'Verfall aus: keine Änderung')
  page=req('/program/%d'%pid,c=c)[1];check('Punkteverfall' in page,'Einstellung im Programm sichtbar')
  # Validierung
  for bad in ['-1','99999']:
   req('/program/expiry',{'csrf':csrf(c,'/program/%d'%pid),'program_id':pid,'days':bad,'action':'save'},c)
  check(sql("echo db()->query('SELECT points_expire_days FROM programs WHERE id=%d')->fetchColumn();"%pid)=='0','ungültige Tage werden abgelehnt')
  # fremder Nutzer
  o=mk();req('/program/expiry',{'program_id':pid,'days':30,'action':'save'},o)
  check(sql("echo db()->query('SELECT points_expire_days FROM programs WHERE id=%d')->fetchColumn();"%pid)=='0','ohne Login keine Änderung')
  req('/program/expiry',{'csrf':csrf(c,'/program/%d'%pid),'program_id':pid,'days':180,'action':'save'},c)
  check(sql("echo db()->query('SELECT points_expire_days FROM programs WHERE id=%d')->fetchColumn();"%pid)=='180','180 Tage gespeichert')
  w=req('/program/%d'%pid,c=c)[1]
  # Verfall per Button: nur Karte 0 (400 Tage) ist älter als 180
  st,body,_=req('/program/expiry',{'csrf':csrf(c,'/program/%d'%pid),'program_id':pid,'action':'run'},c)
  check('1 Karten' in body and pts(cards[0])==0 and pts(cards[1])==100 and pts(cards[2])==100,'Button: nur die inaktive Karte verfällt')
  check(life(cards[0])==100,'Lifetime-Punkte bleiben')
  check(sql("echo db()->query(\"SELECT amount FROM transactions WHERE card_id=%d AND kind='points_expire'\")->fetchColumn();"%cards[0])=='-100','Verfall im Verlauf gebucht (-100)')
  check(sql("echo json_encode(expire_program_points(%d));"%pid)=='[0,0]','zweiter Lauf ändert nichts (idempotent)')
  # Aktivität hält Punkte am Leben: Karte 1 (100 Tage alt) mit 100-Tage-Grenze aber neuer Buchung
  sql("db()->prepare(\"UPDATE programs SET points_expire_days=30 WHERE id=?\")->execute([%d]);"%pid)
  sql("txn(%d,'points_add',5,'neu');db()->prepare('UPDATE cards SET points=points+5 WHERE id=?')->execute([%d]);"%(cards[1],cards[1]))
  r=json.loads(sql("echo json_encode(expire_program_points(%d));"%pid))
  check(r==[0,0] and pts(cards[1])==105,'neue Buchung hält Punkte am Leben')
  # CLI
  sql("db()->prepare(\"UPDATE transactions SET created_at=datetime('now','-90 days') WHERE card_id=?\")->execute([%d]);"%cards[1])
  out=subprocess.check_output([PHP,'tools/expire-points.php'],cwd=A,text=True)
  check('Karten: 1, Punkte entfernt: 105' in out and pts(cards[1])==0,'CLI tools/expire-points.php')
  # Zeitreise-Parameter
  check(sql("echo json_encode(expire_program_points(%d, time()+86400*60));"%pid)=='[1,100]' and pts(cards[2])==0,'Prüfung mit späterem Zeitpunkt')
  # Undo nicht möglich für Verfall
  tid=int(sql("echo db()->query(\"SELECT id FROM transactions WHERE card_id=%d AND kind='points_expire'\")->fetchColumn();"%cards[0]))
  check(sql("$c=db()->query('SELECT * FROM cards WHERE id=%d')->fetch();echo var_export(undo_transaction($c,%d),true);"%(cards[0],tid))=='false' and pts(cards[0])==0,'Verfall ist nicht stornierbar')
  # Wallet/Mitgliederseite zeigt Hinweis, wenn Punkte da sind
  sql("db()->prepare('UPDATE cards SET points=50 WHERE id=?')->execute([%d]);txn(%d,'points_add',1,'x');"%(cards[2],cards[2]))
  mid=mem[2];page=req('/member/%d'%mid,c=c)[1]
  check('Punkte verfallen am' in page,'Hinweis mit Verfallsdatum auf der Karte')
  subprocess.run(['node',str(R/'tests/expiry-ui.cjs'),str(pid)],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Verfall-Prüfungen bestanden')
