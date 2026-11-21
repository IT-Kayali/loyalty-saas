const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const out=process.env.TEST_OUTPUT||'/tmp/loyalty-vb';fs.mkdirSync(out,{recursive:true});const U='http://127.0.0.1:8099';
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,vp] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const o=await b.newContext({viewport:vp});const q=await o.newPage();q.on('pageerror',e=>{throw e});
 let accept=true; q.on('dialog',d=>accept?d.accept():d.dismiss());
 await q.goto(U+'/login');await q.locator('[name=email]').fill('demo@loyalty.local');await q.locator('[name=password]').fill('demo1234');await q.locator('button').first().click();await q.waitForURL('**/dashboard');
 await q.goto(U+'/program/'+process.argv[2]);
 const row=q.locator('tr',{hasText:'Winter'});
 await row.getByRole('button',{name:'Stornieren'}).scrollIntoViewIfNeeded();
 await q.screenshot({path:`${out}/vb-before-${label}.png`});
 accept=false;await row.getByRole('button',{name:'Stornieren'}).click();
 yes(await row.getByRole('button',{name:'Stornieren'}).count()===1,label+': Abbruch der Bestätigung ändert nichts');
 accept=true;await row.getByRole('button',{name:'Stornieren'}).click();await q.waitForLoadState();
 yes((await q.content()).includes('Codes storniert'),label+': Storno per Klick mit Bestätigung');
 const r2=q.locator('tr',{hasText:'Winter'});yes(await r2.getByRole('button',{name:'Wiederherstellen'}).count()===1&&await r2.getByRole('button',{name:'Stornieren'}).count()===0,label+': Button wechselt zu Wiederherstellen');
 yes(await q.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),label+': Seite ohne horizontalen Überlauf');
 yes(await q.locator('.voucher-batches').evaluate(e=>e.scrollWidth<=e.clientWidth+1),label+': Batch-Tabelle ohne versteckte Spalten');
 await r2.getByRole('button',{name:'Wiederherstellen'}).scrollIntoViewIfNeeded();await q.screenshot({path:`${out}/vb-after-${label}.png`});if(label==='mobile') { await r2.evaluate(e=>e.scrollIntoView({block:'center'}));await r2.screenshot({path:`${out}/vb-table-${label}.png`}); }
 else await q.locator('.voucher-batches').screenshot({path:`${out}/vb-table-${label}.png`});
 await r2.getByRole('button',{name:'Wiederherstellen'}).click();await q.waitForLoadState();
 yes((await q.content()).includes('Codes wieder aktiviert'),label+': Wiederherstellen per Klick');
 await o.close();}
await b.close();console.log(n+' Voucher-Batch-Browser-Prüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
