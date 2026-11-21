const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const code=process.argv[2],mid=process.argv[3],U='http://127.0.0.1:8099',out='/tmp/loyalty-voucher-qr';fs.mkdirSync(out,{recursive:true});
const b=await chromium.launch({headless:true,args:['--no-sandbox']});let last;
for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const m=await b.newContext({viewport}),p=await m.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/member/login');await p.locator('[name=email]').fill('aylin@example.com');await p.locator('[name=password]').fill('kunde1234');await p.locator('button').first().click();await p.waitForURL('**/wallet');
 await p.waitForSelector('.member-vouchers canvas');await p.addScriptTag({url:U+'/assets/jsQR.js'});
 const decoded=await p.locator('.member-vouchers canvas').evaluate(c=>{const d=c.getContext('2d').getImageData(0,0,c.width,c.height);return jsQR(d.data,d.width,d.height)?.data});
 yes(decoded==='V-'+code,label+': gezeichneten QR erfolgreich dekodiert');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': Wallet ohne Überlauf');
 await p.locator('.member-vouchers').scrollIntoViewIfNeeded();await p.locator('.member-vouchers').screenshot({path:out+'/voucher-qr-wallet-'+label+'.png'});
 const c=await b.newContext({viewport}),s=await c.newPage();s.on('pageerror',e=>{throw e});
 await s.addInitScript(()=>{
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:()=>Promise.resolve({getTracks:()=>[{stop:()=>{}}]})}});
  Object.defineProperty(HTMLMediaElement.prototype,'srcObject',{set(){}});Object.defineProperty(HTMLMediaElement.prototype,'readyState',{get:()=>4});
  Object.defineProperty(HTMLVideoElement.prototype,'videoWidth',{get:()=>100});Object.defineProperty(HTMLVideoElement.prototype,'videoHeight',{get:()=>100});
  HTMLMediaElement.prototype.play=()=>Promise.resolve();CanvasRenderingContext2D.prototype.drawImage=function(){};
 });
 await s.goto(U+'/staff/login');await s.locator('[name=business_id]').fill('1');await s.locator('[name=name]').fill('Kasse');await s.locator('[name=pin]').fill('123456');await s.locator('button').first().click();await s.waitForURL('**/staff');
 await s.route('**/assets/jsQR.js',r=>r.fulfill({contentType:'application/javascript',body:'window.jsQR=()=>({data:'+JSON.stringify(decoded)+'});'}));
 await s.goto(U+'/staff/scan');await s.waitForURL('**/staff/business/1/voucher-counter?code=*');
 yes(await s.locator('[name=code]').inputValue()===code,label+': Kameraablauf übergibt nur Code');
 yes(await s.locator('[name=member_id]').inputValue()==='',label+': kein falsches Mitglied vorausgewählt');
 yes(await s.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': Kasse ohne Überlauf');
 await s.screenshot({path:out+'/voucher-qr-counter-'+label+'.png',fullPage:true});
 await s.locator('[name=member_id]').selectOption(mid);await s.locator('[name=spend]').fill('20');await s.locator('input[type=checkbox]').check();await s.getByRole('button',{name:'Einlösung prüfen'}).click();await s.waitForLoadState();
 yes(await s.getByRole('button',{name:'Verbindlich einlösen'}).count()===1,label+': ausdrückliche zweite Bestätigung');
 await s.screenshot({path:out+'/voucher-qr-preview-'+label+'.png',fullPage:true});last=s;
 await m.close();
}
await last.getByRole('button',{name:'Verbindlich einlösen'}).click();await last.waitForLoadState();yes((await last.content()).includes('Gutschein eingelöst'), 'Bestätigung löst Gutschein ein');
await b.close();console.log(n+' Gutschein-QR-Browser-Prüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
