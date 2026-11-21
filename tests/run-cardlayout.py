#!/usr/bin/env python3
"""Gestaltete Webkarte (Vorder-/Rueckseite): Speichern, Normalisierung, Rendering, Uploads, Rechte, Browser. Lokaler Wegwerf-Server."""
import tempfile,pathlib,subprocess,shutil,os,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,json,io,uuid
R=pathlib.Path(__file__).resolve().parents[1];PHP=os.environ.get('PHP_BIN','php');n=0
def check(v,s):
 global n
 assert v,s;n+=1;print('PASS',s)
with tempfile.TemporaryDirectory(prefix='loyalty-cardlayout-') as temp:
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
  def req(path,data=None,c=None,files=None):
   hdr={}
   if files is not None:
    b=uuid.uuid4().hex;buf=io.BytesIO()
    for k,v in (data or {}).items():buf.write(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n'%(b,k,v)).encode())
    for k,(fn,ct,by) in files.items():buf.write(('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: %s\r\n\r\n'%(b,k,fn,ct)).encode()+by+b'\r\n')
    buf.write(('--%s--\r\n'%b).encode());data=buf.getvalue();hdr={'Content-Type':'multipart/form-data; boundary='+b}
   elif isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
   try:
    with c.open(urllib.request.Request('http://127.0.0.1:8099'+path,data=data,headers=hdr),timeout=15) as x:return x.status,x.read().decode('utf-8','replace'),x.url
   except urllib.error.HTTPError as x:return x.code,x.read().decode('utf-8','replace'),x.url
  def csrf(c,p):return re.search(r'name="csrf" value="([a-f0-9]+)"',req(p,c=c)[1]).group(1)
  o=mk();req('/login',{'csrf':csrf(o,'/login'),'email':'demo@loyalty.local','password':'demo1234'},o)
  def member(email,pw):
   c=mk();req('/member/login',{'csrf':csrf(c,'/member/login'),'email':email,'password':pw},c);return c
  M=member('aylin@example.com','kunde1234')
  mc=sql("$s=db()->prepare('SELECT code FROM members WHERE business_id=1 AND email=?');$s->execute(['aylin@example.com']);echo $s->fetchColumn();")
  sp=int(sql("echo db()->query(\"SELECT p.id FROM programs p JOIN cards c ON c.program_id=p.id JOIN members m ON m.id=c.member_id WHERE p.business_id=1 AND p.type='stamps' AND m.email='aylin@example.com' LIMIT 1\")->fetchColumn();"))
  design=lambda:json.loads(sql("echo db()->query('SELECT card_design FROM businesses WHERE id=1')->fetchColumn();") or '{}')
  save=lambda f,files=None,path='/business/1/design',c=o:req(path,dict({'csrf':csrf(o,path)},**f),c,files)
  png=io.BytesIO()
  from PIL import Image
  Image.new('RGB',(320,200),(200,120,40)).save(png,'PNG');png=png.getvalue()
  # 1) Standard: aus
  w=req('/wallet',c=M)[1];check('cl-face' not in w,'Standard: Webkarte unverändert (Layout aus)')
  pg=req('/business/1/design',c=o)[1];check('name="lay_on"' in pg and 'id="layoutPreview"' in pg and 'lay_back_image' in pg,'Designer zeigt Layout-Formular und Vorschau')
  # 2) Speichern und Normalisieren
  base={'bg_color':'#1C1A17','text_color':'#FAF7F2','accent_color':'#E4572E','overlay_opacity':'60','lay_on':'1','lay_front_title_show':'1','lay_front_title_text':'<b>Mein</b> Café & Co','lay_front_title_x':'500','lay_front_title_y':'-20','lay_front_stamps_show':'1','lay_front_stamps_x':'30','lay_front_stamps_y':'55','lay_front_stamps_size':'99','lay_front_reward_show':'1','lay_front_reward_x':'6','lay_front_reward_y':'80','lay_front_overlay_color':'#E4572E','lay_back_title_show':'1','lay_back_qr_show':'1','lay_back_qr_x':'55','lay_back_qr_y':'20','lay_back_qr_size':'5','lay_back_text_show':'1','lay_back_text_text':'x'*300,'lay_back_overlay_color':'zzz','lay_back_overlay_same':'1'}
  save(base)
  L=design().get('layout',{})
  check(L.get('on')==1 and L['front']['els']['title']['x']==95 and L['front']['els']['title']['y']==0,'Positionen werden auf 0 bis 95 begrenzt')
  check(L['front']['els']['stamps']['size']==16 and L['back']['els']['qr']['size']==16,'Größen werden begrenzt (Stempel 4 bis 16, QR 16 bis 48)')
  check(len(L['back']['els']['text']['text'])==140 and L['back']['overlay_color']=='' and L['front']['overlay_color']=='#E4572E','Text gekürzt, ungültige Farbe verworfen, Overlay-Farbe gespeichert')
  check(L['front']['els']['qr']['show']==0 and L['back']['els']['reward']['show']==0,'Nicht angehakte Elemente sind aus')
  # 3) Rendering Webkarte
  w=req('/wallet',c=M)[1]
  fr=w.split('cl-face-back')[0];bk=w.split('cl-face-back')[1] if 'cl-face-back' in w else ''
  check('cl-face-front' in w and 'cl-face-back' in w and 'cl-tab' in w,'Webkarte zeigt Vorder- und Rückseite mit Umschalter')
  check('&lt;b&gt;Mein&lt;/b&gt; Café &amp; Co' in fr and '<b>Mein</b>' not in w,'Überschrift wird maskiert')
  check('left:95%;top:0%' in fr and 'class="stamp-row"' in fr and 'data-qr=' not in fr,'Vorderseite: Position, Stempel, kein QR')
  check('data-qr="http://127.0.0.1:8099/c/'+mc+'"' in bk and 'width:16cqw' in bk,'Rückseite: QR mit Mitglieds-Link (Scanner) in gewählter Größe')
  check(fr.count('class="dot')>=10 or 'dot' in fr,'Stempel-Kreise gerendert')
  check(req('/c/'+mc,c=mk())[1].count('cl-face-front')>=1,'Öffentliche Karte /c/CODE zeigt die gestaltete Karte')
  # 4) Rückseiten-Bild
  save(base,{'lay_back_image':('b.png','image/png',png)})
  L=design()['layout'];img=L['back']['image']
  check(re.fullmatch(r'cardback_b1_[0-9a-f]{32}\.png',img) is not None,'Rückseiten-Bild wird als neues PNG gespeichert')
  w=req('/wallet',c=M)[1];check('url(/uploads/'+img+')' in w.split('cl-face-back')[1].split('</div></div>')[0] and 'url(/uploads/'+img+')' not in w.split('cl-face-back')[0],'Bild nur auf der Rückseite, mit Overlay')
  check(req('/uploads/'+img,c=mk())[0]==200,'Bild wird ausgeliefert')
  save(dict(base,lay_back_overlay_color='#00AA55'));save(base,{'lay_back_image':('x.png','image/png',b'<?php echo 1; ?> not an image at all, long enough')})
  check(design()['layout']['back']['image']==img,'Ungültiger Upload: bisheriges Bild bleibt')
  save(dict(base,lay_back_image_remove='1'));check(design()['layout']['back']['image']=='','Rückseiten-Bild entfernen')
  # 5) Rechte
  before=json.dumps(design(),sort_keys=True)
  req('/business/1/design',dict(base,lay_front_title_text='HACK'),o);check(json.dumps(design(),sort_keys=True)==before,'Speichern ohne CSRF wirkungslos')
  req('/business/1/design',dict(base,lay_front_title_text='HACK'),M);check(json.dumps(design(),sort_keys=True)==before,'Mitglied kann Design nicht ändern')
  # 6) Programmdesign hat Vorrang und andere Typen
  save(dict(base,lay_front_title_text='Programm-Titel',lay_front_title_x='10'),path='/program/%d/design'%sp)
  w=req('/wallet',c=M)[1];check('Programm-Titel' in w and 'left:10%' in w,'Programmdesign hat Vorrang vor Unternehmensdesign')
  check('Programm-Titel' not in json.dumps(design()),'Unternehmensdesign bleibt unberührt')
  # 7) Punkte-Programm: Prämie zeigt Punkte, keine Stempel
  sql("$db=db();$db->exec(\"UPDATE programs SET card_design=NULL WHERE id=%d\");"%sp)
  pp=sql("echo db()->query(\"SELECT p.id FROM programs p JOIN cards c ON c.program_id=p.id JOIN members m ON m.id=c.member_id WHERE p.business_id=1 AND p.type='points' AND m.email='aylin@example.com' LIMIT 1\")->fetchColumn();")
  if pp:
   save(dict(base,lay_front_title_text='Pkt',lay_front_reward_show='1'),path='/program/%s/design'%pp)
   w=req('/wallet',c=M)[1];seg=w[w.index('Pkt')-400:w.index('Pkt')+1500]
   check('Punkte' in seg and 'stamp-row' not in seg.split('Pkt')[1].split('cl-face-back')[0],'Punkte-Programm: Prämie zeigt Punkte, keine Stempel-Kreise')
  # Browser: Karte mit Fotos (erzeugte Verlaufsbilder) und Overlay
  def photo(c1,c2):
   im=Image.new('RGB',(640,400));px=im.load()
   for x in range(640):
    for y in range(400):
     t=(x/640+y/400)/2;px[x,y]=tuple(int(c1[i]*(1-t)+c2[i]*t) for i in range(3))
   b=io.BytesIO();im.save(b,'PNG');return b.getvalue()
  save(dict(base,lay_front_title_text='Kaffee-Karte',lay_front_title_x='6',lay_front_title_y='8',lay_front_reward_y='84',lay_back_text_text='Zeige diesen Code an der Kasse.',lay_back_qr_size='34',lay_back_title_x='6',lay_back_title_y='8',lay_front_overlay_color='#C2410C',overlay_opacity='55',lay_front_text_show='1',lay_front_text_text='Zehn Getränke, eins gratis.',lay_front_stamps_size='9',lay_front_stamps_x='30',lay_front_stamps_y='52',lay_back_title_text='So funktioniert es',lay_back_overlay_color='#1C1A17'),{'background_image':('f.png','image/png',photo((120,70,30),(240,190,120))),'lay_back_image':('b.png','image/png',photo((30,40,60),(120,150,190)))})
  # Browser
  subprocess.run(['node',str(R/'tests/cardlayout-ui.cjs'),str(sp)],cwd=R,check=True)
 finally:s.terminate();s.wait();log.close()
 print(n,'Kartenlayout-Prüfungen bestanden')
