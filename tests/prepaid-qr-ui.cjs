const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const code=process.argv[2],id=process.argv[3],U='http://127.0.0.1:8099',out='/tmp/loyalty-prepaid-qr';fs.mkdirSync(out,{recursive:true});
const b=await chromium.launch({headless:true,args:['--no-sandbox']});let last;
for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const m=await b.newContext({viewport}),p=await m.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/member/login');await p.locator('[name=email]').fill('aylin@example.com');await p.locator('[name=password]').fill('kunde1234');await p.locator('button').first().click();await p.waitForURL('**/wallet');
 const item=p.locator('.pass-item').filter({has:p.locator('[data-qr="P-'+code+'"]')});await item.locator('canvas').waitFor();await p.addScriptTag({url:U+'/assets/jsQR.js'});
 const decoded=await item.locator('canvas').evaluate(c=>{const d=c.getContext('2d').getImageData(0,0,c.width,c.height);return jsQR(d.data,d.width,d.height)?.data});
 yes(decoded==='P-'+code,label+': tatsächlich gezeichneten Pass-QR dekodiert');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': Wallet ohne Überlauf');
 await item.scrollIntoViewIfNeeded();await item.screenshot({path:out+'/prepaid-qr-wallet-'+label+'.png'});
 const c=await b.newContext({viewport}),s=await c.newPage();s.on('pageerror',e=>{throw e});
 await s.addInitScript(()=>{
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:()=>Promise.resolve({getTracks:()=>[{stop:()=>{}}]})}});
  Object.defineProperty(HTMLMediaElement.prototype,'srcObject',{set(){}});Object.defineProperty(HTMLMediaElement.prototype,'readyState',{get:()=>4});
  Object.defineProperty(HTMLVideoElement.prototype,'videoWidth',{get:()=>100});Object.defineProperty(HTMLVideoElement.prototype,'videoHeight',{get:()=>100});
  HTMLMediaElement.prototype.play=()=>Promise.resolve();CanvasRenderingContext2D.prototype.drawImage=function(){};
 });
 await s.goto(U+'/staff/login');await s.locator('[name=business_id]').fill('1');await s.locator('[name=name]').fill('Kasse');await s.locator('[name=pin]').fill('123456');await s.locator('button').first().click();await s.waitForURL('**/staff');
 await s.route('**/assets/jsQR.js',r=>r.fulfill({contentType:'application/javascript',body:'window.jsQR=()=>({data:'+JSON.stringify(decoded)+'});'}));
 await s.goto(U+'/staff/scan');await s.waitForURL('**/staff/prepaid/scan/'+code);
 yes((await s.content()).includes('10 / 10 Besuche') && (await s.content()).includes('Aylin'),label+': Scan zeigt richtigen Pass und Mitglied');
 yes(await s.locator('form[action="/staff/prepaid/'+id+'/use"]').count()===1,label+': getrennte Bestätigung vorhanden');
 yes(await s.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': Passprüfung ohne Überlauf');
 await s.screenshot({path:out+'/prepaid-qr-preview-'+label+'.png',fullPage:true});last=s;await m.close();
}
await last.locator('input[type=checkbox]').check();await last.getByRole('button',{name:'Besuch bestätigen',exact:true}).click();await last.waitForLoadState();yes((await last.content()).includes('Besuch dokumentiert'), 'Bestätigung dokumentiert genau einen Besuch');
await b.close();console.log(n+' Prepaid-QR-Browserprüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
