const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const out=process.env.TEST_OUTPUT||'/tmp/loyalty-lifecycle';fs.mkdirSync(out,{recursive:true});const U='http://127.0.0.1:8099';
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,vp] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const ctx=await b.newContext({viewport:vp});const p=await ctx.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/login');await p.locator('[name=email]').fill('demo@loyalty.local');await p.locator('[name=password]').fill('demo1234');await p.locator('button').first().click();await p.waitForURL('**/dashboard');
 await p.goto(U+'/business/1/mail');
 yes((await p.content()).includes('Persönlichen Gutschein beilegen'),label+': Gutschein-Auswahl sichtbar');
 await p.locator('[name=event]').selectOption('birthday');
 await p.locator('[name=voucher_program_id]').selectOption({label:'Geburtstagsgutschein'});await p.locator('[name=voucher_days]').fill('21');
 await p.locator('[name=subject]').fill('Alles Gute {name}');await p.locator('[name=body]').fill('Dein Geschenk: {code}, gültig bis {valid_until}');
 await p.screenshot({path:`${out}/lifecycle-form-${label}.png`,fullPage:true});
 await p.getByRole('button',{name:'Automation speichern'}).click();await p.waitForLoadState();
 yes((await p.content()).includes('Alles Gute {name}')&&(await p.content()).includes('Gutschein: Geburtstagsgutschein'),label+': Automation mit Gutschein gelistet');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),label+': kein horizontaler Überlauf');
 await p.screenshot({path:`${out}/lifecycle-list-${label}.png`,fullPage:true});
 await ctx.close();}
await b.close();console.log(n+' Lifecycle-Browser-Prüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
