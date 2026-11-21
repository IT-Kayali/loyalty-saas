const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const out=process.env.TEST_OUTPUT||'/tmp/loyalty-templates';fs.mkdirSync(out,{recursive:true});const U='http://127.0.0.1:8099';
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,vp] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const ctx=await b.newContext({viewport:vp});const p=await ctx.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/login');await p.locator('[name=email]').fill('demo@loyalty.local');await p.locator('[name=password]').fill('demo1234');await p.locator('button').first().click();await p.waitForURL('**/dashboard');
 await p.goto(U+'/business/1');await p.locator('summary',{hasText:'Aus Vorlage anlegen'}).click();
 yes(await p.locator('.template-card').count()>=20,label+': Vorlagen sichtbar');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),label+': kein horizontaler Überlauf');
 await p.locator('.template-card').first().scrollIntoViewIfNeeded();await p.screenshot({path:`${out}/templates-panel-${label}.png`});
 await p.locator('.template-card',{hasText:'Hähnchen-Karte'}).getByRole('button',{name:'Übernehmen'}).click();await p.waitForURL(/\/program\/\d+$/);
 yes((await p.content()).includes('Hähnchen-Karte')&&(await p.content()).includes('Halbes Hähnchen gratis'),label+': Programm aus Vorlage geöffnet');
 await p.screenshot({path:`${out}/template-result-${label}.png`,fullPage:true});
 await p.goto(U+'/business/1');await p.locator('summary',{hasText:'Aus Vorlage anlegen'}).click();
 await p.locator('.template-card',{hasText:'Genuss-Punkte'}).getByRole('button',{name:'Übernehmen'}).click();await p.waitForURL(/\/program\/\d+$/);
 yes((await p.content()).includes('Genuss-Punkte')&&(await p.content()).includes('Menü für zwei'),label+': Punkte-Vorlage mit Prämienleiter');
 await p.screenshot({path:`${out}/template-points-${label}.png`,fullPage:true});
 await ctx.close();}
await b.close();console.log(n+' Vorlagen-Browser-Prüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
