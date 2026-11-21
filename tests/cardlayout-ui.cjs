const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const out=process.env.TEST_OUTPUT||'/tmp/loyalty-cardlayout';fs.mkdirSync(out,{recursive:true});const U='http://127.0.0.1:8099';
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,vp] of [['desktop',{width:1280,height:1000}],['mobile',{width:390,height:844}]]){
 const o=await b.newContext({viewport:vp});const q=await o.newPage();q.on('pageerror',e=>{throw e});
 await q.goto(U+'/login');await q.locator('[name=email]').fill('demo@loyalty.local');await q.locator('[name=password]').fill('demo1234');await q.locator('button').first().click();await q.waitForURL('**/dashboard');
 await q.goto(U+'/business/1/design');
 yes(await q.locator('#layoutPreview .cl-face').count()===2,label+': Vorschau mit zwei Seiten');
 await q.locator('[name=lay_on]').check();
 // Live-Update: Überschrift, Position, Overlay
 await q.locator('[name=lay_front_title_text]').fill('Live Titel');
 yes((await q.locator('#layoutPreview .cl-face-front .cl-title').textContent())==='Live Titel',label+': Titel live in der Vorschau');
 await q.locator('[name=lay_front_title_x]').fill('40');await q.locator('[name=lay_front_title_x]').dispatchEvent('input');
 yes((await q.locator('#layoutPreview .cl-face-front .cl-title').evaluate(e=>e.style.left))==='40%',label+': Position live');
 await q.locator('[name=lay_front_qr_show]').check();
 yes(await q.locator('#layoutPreview .cl-face-front .cl-qr').isVisible(),label+': QR-Code einblendbar');
 await q.locator('[name=lay_front_stamps_size]').fill('9');await q.locator('[name=lay_front_stamps_size]').dispatchEvent('input');
 // Ziehen (nur Desktop zuverlässig, Mobil per Touch-Emulation ebenfalls Maus)
 const el=q.locator('#layoutPreview .cl-face-front .cl-reward');await el.scrollIntoViewIfNeeded();
 const bb=await el.boundingBox();const fb=await q.locator('#layoutPreview .cl-face-front').boundingBox();
 const x0=+await q.locator('[name=lay_front_reward_x]').inputValue();
 await q.mouse.move(bb.x+5,bb.y+5);await q.mouse.down();await q.mouse.move(bb.x+5+fb.width*0.2,bb.y+5-fb.height*0.1,{steps:5});await q.mouse.up();
 const x1=+await q.locator('[name=lay_front_reward_x]').inputValue();
 yes(x1>x0+15,label+': Element lässt sich ziehen und schreibt Position ins Formular ('+x0+' zu '+x1+')');
 await q.locator('[name=lay_front_title_x]').fill('6');await q.locator('[name=lay_front_title_x]').dispatchEvent('input');await q.locator('[name=lay_front_reward_x]').fill('6');await q.locator('[name=lay_front_reward_x]').dispatchEvent('input');await q.locator('[name=lay_front_reward_y]').fill('84');await q.locator('[name=lay_front_reward_y]').dispatchEvent('input');await q.locator('[name=lay_front_qr_show]').uncheck();await q.locator('[name=lay_front_qr_show]').dispatchEvent('input');
 await q.locator('.cl-tab[data-side=back]').click();
 yes(await q.locator('#layoutPreview .cl-face-back').isVisible()&&!(await q.locator('#layoutPreview .cl-face-front').isVisible()),label+': Umschalter zeigt Rückseite');
 yes(await q.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),label+': Designer ohne horizontalen Überlauf');
 await q.locator('#layoutPreview').scrollIntoViewIfNeeded();await q.screenshot({path:`${out}/layout-designer-back-${label}.png`});
 await q.locator('.cl-tab[data-side=front]').click();await q.screenshot({path:`${out}/layout-designer-front-${label}.png`});
 await q.locator('.designer-grid form .btn').last().click();await q.waitForLoadState();
 yes((await q.content()).includes('Karten-Design gespeichert'),label+': Speichern klappt');
 yes((await q.locator('[name=lay_front_title_text]').inputValue())==='Live Titel','Gespeicherter Titel wieder im Formular');
 // Kundenansicht
 const m=await b.newContext({viewport:vp});const p=await m.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/member/login');await p.locator('[name=email]').fill('aylin@example.com');await p.locator('[name=password]').fill('kunde1234');await p.locator('button').first().click();await p.waitForURL('**/wallet');
 yes(await p.locator('.cl-face').count()>=2,label+': Wallet zeigt gestaltete Karte');
 const vis=p.locator('.cl-wrap').first();await vis.scrollIntoViewIfNeeded();
 await p.waitForFunction(()=>document.querySelector('.cl-face-back .cl-qr canvas, .cl-face-back .cl-qr img'),null,{timeout:5000}).catch(()=>{});
 yes(await p.locator('.cl-face-back .cl-qr canvas, .cl-face-back .cl-qr img').count()>=1,label+': QR-Code auf der Karte gezeichnet');
 const ar=await p.locator('.cl-face-front').first().boundingBox();yes(Math.abs(ar.width/ar.height-1.586)<0.03,label+': Kartenformat 1,586');
 await p.screenshot({path:`${out}/layout-wallet-front-${label}.png`});
 await p.locator('.cl-wrap').first().locator('.cl-tab[data-side=back]').click();
 yes(await p.locator('.cl-wrap').first().locator('.cl-face-back').isVisible(),label+': Rückseite im Wallet umschaltbar');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),label+': Wallet ohne horizontalen Überlauf');
 await p.screenshot({path:`${out}/layout-wallet-back-${label}.png`});
 await o.close();await m.close();
 // Zurücksetzen für den zweiten Durchlauf bleibt bewusst aus: gleicher Zustand
}
await b.close();console.log(n+' Kartenlayout-Browser-Prüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
