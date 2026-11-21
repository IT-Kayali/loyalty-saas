const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};const pid=process.argv[2];
const out=process.env.TEST_OUTPUT||'/tmp/loyalty-expiry';fs.mkdirSync(out,{recursive:true});const U='http://127.0.0.1:8099';
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,vp] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const ctx=await b.newContext({viewport:vp});const p=await ctx.newPage();p.on('pageerror',e=>{throw e});p.on('dialog',d=>d.accept());
 await p.goto(U+'/login');await p.locator('[name=email]').fill('demo@loyalty.local');await p.locator('[name=password]').fill('demo1234');await p.locator('button').first().click();await p.waitForURL('**/dashboard');
 await p.goto(U+'/program/'+pid);
 await p.locator('[name=days]').fill(label==='desktop'?'30':'45');await p.getByRole('button',{name:'Speichern'}).click();await p.waitForLoadState();
 yes((await p.content()).includes('Punkteverfall nach'),label+': Einstellung gespeichert');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),label+': kein horizontaler Überlauf');
 await p.locator('h2',{hasText:'Punkteverfall'}).scrollIntoViewIfNeeded();await p.screenshot({path:`${out}/expiry-settings-${label}.png`});
 await p.getByRole('button',{name:'Jetzt prüfen'}).click();await p.waitForLoadState();
 yes((await p.content()).includes('Punkte entfernt'),label+': manueller Lauf meldet Ergebnis');
 await ctx.close();}
await b.close();console.log(n+' Verfall-Browser-Prüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
