const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const sid=process.argv[2],U='http://127.0.0.1:8099',out='/tmp/loyalty-segments';fs.mkdirSync(out,{recursive:true});
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const c=await b.newContext({viewport}),p=await c.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/login');await p.locator('[name=email]').fill('demo@loyalty.local');await p.locator('[name=password]').fill('demo1234');await p.locator('button').first().click();await p.waitForURL('**/dashboard');
 await p.goto(U+'/business/1');await p.locator('a[href="/segment/'+sid+'/edit"]').click();await p.waitForLoadState();
 yes(await p.locator('[name=rule] option').count()===6,label+': sechs bestehende Regeln verfügbar');
 yes((await p.content()).includes('Speichern sendet keine Nachrichten'),label+': Folgen für Kampagnen sichtbar');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': Editor ohne horizontalen Überlauf');
 await p.locator('[name=name]').fill('Treue Gäste '+label);await p.locator('[name=rule]').selectOption('min_stamps');await p.locator('[name=param]').fill('5');
 await p.getByRole('button',{name:'Vorschau prüfen',exact:true}).click();await p.waitForLoadState();
 yes(await p.locator('.segment-preview').isVisible(),label+': ungespeicherte Vorschau getrennt sichtbar');
 yes((await p.locator('.segment-preview').textContent()).includes('keine Nachrichten versendet'),label+': Vorschau sagt kein Versand');
 yes(await p.locator('[name=param]').inputValue()==='5' && await p.locator('[name=rule]').inputValue()==='min_stamps',label+': Vorschau erhält Formulareingaben');
 await p.screenshot({path:out+'/segment-preview-'+label+'.png',fullPage:true});
 await p.getByRole('button',{name:'Segment speichern',exact:true}).click();await p.waitForLoadState();
 yes((await p.content()).includes('keine Nachrichten versendet'),label+': Speicherergebnis ohne Versand');
 yes(await p.locator('[name=name]').inputValue()==='Treue Gäste '+label && await p.locator('[name=param]').inputValue()==='5',label+': Name und Wert gespeichert');
 await p.screenshot({path:out+'/segment-edit-'+label+'.png',fullPage:true});
 // Stale tab cannot overwrite later changes.
 const old=await c.newPage();await old.goto(U+'/segment/'+sid+'/edit');await old.getByRole('button',{name:'Vorschau prüfen',exact:true}).click();await old.waitForLoadState();
 await p.locator('[name=name]').fill('Neuester Stand '+label);await p.getByRole('button',{name:'Segment speichern',exact:true}).click();await p.waitForLoadState();
 await old.locator('[name=name]').fill('Alter Stand');await old.getByRole('button',{name:'Segment speichern',exact:true}).click();await old.waitForLoadState();
 yes((await old.content()).includes('inzwischen geändert') && await old.locator('[name=name]').inputValue()==='Neuester Stand '+label,label+': Konflikt erklärt und aktueller Stand geladen');
 await old.screenshot({path:out+'/segment-conflict-'+label+'.png',fullPage:true});await c.close();
}
await b.close();console.log(n+' Segment-Editor-Browserprüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
