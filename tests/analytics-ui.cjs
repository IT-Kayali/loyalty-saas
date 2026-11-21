const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const expected=process.argv[2],U='http://127.0.0.1:8099',out='/tmp/loyalty-analytics';fs.mkdirSync(out,{recursive:true});
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const c=await b.newContext({viewport}),p=await c.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/login');await p.locator('[name=email]').fill('demo@loyalty.local');await p.locator('[name=password]').fill('demo1234');await p.locator('button').first().click();await p.waitForURL('**/dashboard');
 const r=await p.goto(U+'/business/1/analytics');yes((r.headers()['cache-control']||'').includes('no-store'),label+': Analytics nicht gecacht');
 yes((await p.locator('[data-analytics-kpi="Einlösungen"] div').first().textContent()).trim()===expected,label+': Einlösungs-KPI enthält Code-Gutscheine');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': Gesamtansicht ohne horizontalen Überlauf');
 await p.screenshot({path:out+'/analytics-'+label+'.png',fullPage:true});
 const table=p.locator('.analytics-programs');await table.scrollIntoViewIfNeeded();
 yes(await table.evaluate(e=>e.scrollWidth<=e.clientWidth+1),label+': gesamte Programmtabelle ohne seitliches Scrollen');
 yes(await table.getByText('Lange Gutscheinaktion mit Einlösung an der Kasse',{exact:true}).isVisible(),label+': langer Programmname sichtbar');
 const row=table.locator('tbody tr').filter({hasText:'Lange Gutscheinaktion'});
 yes(await row.locator('td[data-label="Einlösungen"]').textContent()==='4',label+': vier Einlösungen in Programmzeile');
 await row.screenshot({path:out+'/analytics-program-row-'+label+'.png'});await c.close();
}
await b.close();console.log(n+' Analytics-Browserprüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
