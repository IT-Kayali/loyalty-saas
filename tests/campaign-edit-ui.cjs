const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const cid=process.argv[2],sent=process.argv[3],U='http://127.0.0.1:8099',out='/tmp/loyalty-campaign-edit';fs.mkdirSync(out,{recursive:true});
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const c=await b.newContext({viewport}),p=await c.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/login');await p.locator('[name=email]').fill('demo@loyalty.local');await p.locator('[name=password]').fill('demo1234');await p.locator('button').first().click();await p.waitForURL('**/dashboard');
 await p.goto(U+'/business/1');await p.locator('a[href="/campaign/'+cid+'/edit"]').click();await p.waitForLoadState();
 yes((await p.content()).includes('Hier wird nichts versendet'),label+': Editor ausdrücklich ohne Versand');
 await p.locator('[name=name]').fill('Sommeraktion '+label);await p.locator('[name=subject]').fill('Neue Prämien');await p.locator('[name=body]').fill('Text zum Prüfen '+label+'\nMit zweiter Zeile.');await p.getByRole('button',{name:'Entwurf speichern',exact:true}).click();await p.waitForLoadState();
 yes((await p.content()).includes('Keine Nachrichten versendet'),label+': Speicherbestätigung');
 yes((await p.locator('.campaign-review').textContent()).includes('Mit zweiter Zeile.'),label+': vollständiger Text lesbar');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': kein horizontaler Überlauf');
 await p.screenshot({path:out+'/campaign-edit-'+label+'.png',fullPage:true});
 const old=await c.newPage();await old.goto(U+'/campaign/'+cid+'/edit');await p.locator('[name=body]').fill('Neuester Text '+label);await p.getByRole('button',{name:'Entwurf speichern',exact:true}).click();await p.waitForLoadState();
 await old.locator('[name=body]').fill('Veraltet');await old.getByRole('button',{name:'Entwurf speichern',exact:true}).click();await old.waitForLoadState();
 yes((await old.content()).includes('inzwischen geändert') && await old.locator('[name=body]').inputValue()==='Neuester Text '+label,label+': Zwei-Tab-Konflikt');
 await p.goto(U+'/campaign/'+sent+'/edit');yes(await p.locator('textarea').count()===0 && (await p.content()).includes('Nur lesen'),label+': abgeschlossener Inhalt ohne Editor');
 await p.screenshot({path:out+'/campaign-readonly-'+label+'.png',fullPage:true});await c.close();
}
await b.close();console.log(n+' Kampagnen-Editor-Browserprüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
