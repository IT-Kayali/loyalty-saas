const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const U='http://127.0.0.1:8099',out='/tmp/loyalty-business-settings';fs.mkdirSync(out,{recursive:true});
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const c=await b.newContext({viewport}),p=await c.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/login');await p.locator('[name=email]').fill('demo@loyalty.local');await p.locator('[name=password]').fill('demo1234');await p.locator('button').first().click();await p.waitForURL('**/dashboard');
 await p.goto(U+'/business/1');await p.getByRole('link',{name:'Unternehmensname',exact:true}).click();await p.waitForLoadState();
 yes((await p.content()).includes('nicht automatisch aktualisiert'),label+': Wallet-Grenzen sichtbar');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': Editor ohne horizontalen Überlauf');
 await p.locator('[name=name]').fill('Kaffeehaus am Markt '+label);await p.getByRole('button',{name:'Namen speichern',exact:true}).click();await p.waitForLoadState();
 yes((await p.content()).includes('Unternehmensname gespeichert') && await p.locator('[name=name]').inputValue()==='Kaffeehaus am Markt '+label,label+': neuer Name gespeichert');
 await p.screenshot({path:out+'/business-name-'+label+'.png',fullPage:true});
 const old=await c.newPage();await old.goto(U+'/business/1/settings');
 await p.locator('[name=name]').fill('Aktueller Name '+label);await p.getByRole('button',{name:'Namen speichern',exact:true}).click();await p.waitForLoadState();
 await old.locator('[name=name]').fill('Alter Name');await old.getByRole('button',{name:'Namen speichern',exact:true}).click();await old.waitForLoadState();
 yes((await old.content()).includes('inzwischen geändert') && await old.locator('[name=name]').inputValue()==='Aktueller Name '+label,label+': Zwei-Tab-Konflikt schützt aktuelle Änderung');
 await old.screenshot({path:out+'/business-name-conflict-'+label+'.png',fullPage:true});
 await p.goto(U+'/business/1');yes((await p.locator('h1').textContent()).trim()==='Aktueller Name '+label,label+': Unternehmensübersicht zeigt neuen Namen');
 await p.goto(U+'/business/1/design');yes(await p.locator('[name=header]').inputValue()==='Eigene Kopfzeile',label+': eigener Designertext unverändert');await c.close();
}
await b.close();console.log(n+' Unternehmensnamen-Browserprüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
