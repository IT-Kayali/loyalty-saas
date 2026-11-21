const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const out=process.env.TEST_OUTPUT||'/tmp/loyalty-claims';fs.mkdirSync(out,{recursive:true});const U='http://127.0.0.1:8099';
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,vp] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const m=await b.newContext({viewport:vp});const p=await m.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/member/login');await p.locator('[name=email]').fill('aylin@example.com');await p.locator('[name=password]').fill('kunde1234');await p.locator('button').first().click();await p.waitForURL('**/wallet');
 const btn=p.getByRole('button',{name:/Code für „Kaffee“/});yes(await btn.count()===1,label+': Code-Button sichtbar');
 await btn.click();await p.waitForLoadState();
 yes(await p.locator('.claim-code').count()===1,label+': Code angezeigt');
 await p.waitForFunction(()=>document.querySelector('.claim-active .qr-box canvas, .claim-active .qr-box img, .claim-active .qr-box svg'),null,{timeout:5000}).catch(()=>{});
 yes(await p.locator('.claim-active .qr-box').first().evaluate(e=>e.children.length>0),label+': QR gezeichnet');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),label+': Wallet ohne horizontalen Überlauf');
 await p.locator('.claim-box').first().scrollIntoViewIfNeeded();await p.screenshot({path:`${out}/claims-wallet-${label}.png`});
 const code=(await p.locator('.claim-code').first().textContent()).trim();
 // Exercise camera control flow with a decoded claim payload and fake camera frames.
 const staff=await b.newContext({viewport:vp});const scan=await staff.newPage();
 await scan.addInitScript(()=>{
   Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:()=>location.search.includes('denied')?Promise.reject(new Error('denied')):Promise.resolve({getTracks:()=>[{stop:()=>{}}]})}});
   Object.defineProperty(HTMLMediaElement.prototype,'srcObject',{set(){}});
   Object.defineProperty(HTMLMediaElement.prototype,'readyState',{get:()=>4});
   Object.defineProperty(HTMLVideoElement.prototype,'videoWidth',{get:()=>100});Object.defineProperty(HTMLVideoElement.prototype,'videoHeight',{get:()=>100});
   HTMLMediaElement.prototype.play=()=>Promise.resolve();
   CanvasRenderingContext2D.prototype.drawImage=function(){};
 });
 await scan.goto(U+'/staff/login');await scan.locator('[name=business_id]').fill('1');await scan.locator('[name=name]').fill('Kasse');await scan.locator('[name=pin]').fill('1234');await scan.locator('button').first().click();await scan.waitForURL('**/staff');
 await scan.route('**/assets/jsQR.js',route=>route.fulfill({contentType:'application/javascript',body:'window.jsQR=function(){return {data:'+JSON.stringify(U+'/claim/'+code.replace('-',''))+'}};'}));
 await scan.goto(U+'/staff/scan');await scan.waitForURL('**/staff/business/1/claim-counter?code=*');
 yes((await scan.content()).includes('Einlösen und 50 Punkte'),label+': erkannter Prämien-QR öffnet Team-Vorschau');
 await scan.screenshot({path:`${out}/claims-scan-preview-${label}.png`,fullPage:true});
 await p.reload();yes(await p.locator('.claim-code').count()===1,label+': Scan bucht noch keine Punkte, Code bleibt aktiv');
 // Fallback entry and denied camera must remain usable.
 await scan.unroute('**/assets/jsQR.js');
 await scan.goto(U+'/staff/scan?denied=1');await scan.waitForFunction(()=>document.getElementById('scan-status').textContent.includes('verweigert'));
 await scan.locator('summary').click();await scan.locator('[name=code]').fill(code);
 await scan.screenshot({path:`${out}/claims-scan-fallback-${label}.png`,fullPage:true});
 await scan.getByRole('button',{name:'Code prüfen'}).click();await scan.waitForURL('**/staff/business/1/claim-counter?code=*');
 yes((await scan.content()).includes('Einlösen und 50 Punkte'),label+': manueller Prämien-Code nach Kameraablehnung');
 await staff.close();
 const o=await b.newContext({viewport:vp});const q=await o.newPage();q.on('pageerror',e=>{throw e});
 await q.goto(U+'/login');await q.locator('[name=email]').fill('demo@loyalty.local');await q.locator('[name=password]').fill('demo1234');await q.locator('button').first().click();await q.waitForURL('**/dashboard');
 await q.goto(U+'/business/1/claim-counter');await q.locator('[name=code]').fill(code);await q.getByRole('button',{name:'Prüfen'}).click();await q.waitForLoadState();
 yes((await q.content()).includes('Einlösen und 50 Punkte'),label+': Kasse zeigt Vorschau');
 yes(await q.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),label+': Kasse ohne horizontalen Überlauf');
 await q.screenshot({path:`${out}/claims-counter-${label}.png`,fullPage:true});
 await q.getByRole('button',{name:/Einlösen und 50/}).click();await q.waitForLoadState();
 yes((await q.content()).includes('eingelöst'),label+': Einlösung bestätigt');
 await p.reload();yes((await p.locator('.claim-code').count())===0,label+': Code verschwindet aus der Wallet');
 await p.goto(U+'/wallet');yes((await p.locator('.claim-soon').count())>=1,label+': Wallet zeigt Prämie mit Fenster als noch nicht verfügbar');
 await p.locator('.claim-soon').first().scrollIntoViewIfNeeded();await p.screenshot({path:`${out}/claims-window-wallet-${label}.png`});
 await q.goto(U+'/program/'+process.argv[4]);yes((await q.locator('.reward-window').count())>=2,label+': Zeitfenster-Formulare je Prämie');
 yes(await q.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),label+': Programmseite ohne horizontalen Überlauf');
 await q.locator('.reward-window').first().scrollIntoViewIfNeeded();await q.screenshot({path:`${out}/claims-window-program-${label}.png`});
 await q.goto(U+'/business/1');yes((await q.locator('select[name=timezone]').inputValue())==='Europe/Berlin',label+': Zeitzone-Auswahl zeigt Europe/Berlin');
 yes(await q.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),label+': Unternehmensseite ohne horizontalen Überlauf');
 await q.locator('select[name=timezone]').scrollIntoViewIfNeeded();await q.screenshot({path:`${out}/claims-timezone-${label}.png`});
 await m.close();await o.close();}
await b.close();console.log(n+' Einlösecode-Browser-Prüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
