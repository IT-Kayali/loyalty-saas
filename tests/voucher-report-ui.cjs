const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const pid=process.argv[2],U='http://127.0.0.1:8099',out='/tmp/loyalty-voucher-report';fs.mkdirSync(out,{recursive:true});
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const c=await b.newContext({viewport}),p=await c.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/login');await p.locator('[name=email]').fill('demo@loyalty.local');await p.locator('[name=password]').fill('demo1234');await p.locator('button').first().click();await p.waitForURL('**/dashboard');
 await p.goto(U+'/program/'+pid);await p.getByRole('link',{name:'Code-Gutschein-Auswertung',exact:true}).click();await p.waitForLoadState();
 yes(await p.locator('.voucher-report-summary').count()===1,label+': Bericht verlinkt und geladen');
 yes(await p.locator('.voucher-report-table tbody tr').count()===4,label+': Währung/Typ in vier Gruppen');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': Bericht ohne horizontalen Überlauf');
 yes(await p.locator('.voucher-report-table').evaluate(e=>e.scrollWidth<=e.clientWidth+1),label+': gesamte Wertgruppentabelle ohne Scrollen');
 await p.screenshot({path:out+'/voucher-report-'+label+'.png',fullPage:true});
 await p.locator('[name=from]').fill('2026-01-01');await p.locator('[name=to]').fill('2026-01-02');await p.getByRole('button',{name:'Auswerten',exact:true}).click();await p.waitForLoadState();
 yes((await p.content()).includes('12,00 EUR'),label+': Zeitraumfilter zeigt historische Summe');
 yes(await p.locator('.voucher-report-table tbody tr').first().isVisible(),label+': Ergebnisse nach Filter sichtbar');
 await p.screenshot({path:out+'/voucher-report-filter-'+label+'.png',fullPage:true});await c.close();
}
await b.close();console.log(n+' Gutschein-Auswertungs-Browserprüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
