const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};const [ea,eb]=process.argv.slice(2);
const out=process.env.TEST_OUTPUT||'/tmp/loyalty-transfers';fs.mkdirSync(out,{recursive:true});const U='http://127.0.0.1:8099';
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
const login=async(p,e)=>{await p.context().clearCookies();await p.goto(U+'/member/login');await p.locator('[name=email]').fill(e);await p.locator('[name=password]').fill('pw-test-123');await p.locator('button').first().click();await p.waitForURL('**/wallet');};
for(const [label,vp] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const ctx=await b.newContext({viewport:vp});const p=await ctx.newPage();p.on('pageerror',e=>{throw e});
 await login(p,ea);await p.goto(U+'/wallet/request');
 await p.locator('select[name=program_id]').selectOption({index:0});
 const opts=await p.locator('select[name=program_id] option').allTextContents();
 const idx=opts.findIndex(t=>t.includes('Transfer-Test'));await p.locator('select[name=program_id]').selectOption({index:idx});
 await p.getByRole('button',{name:'Link erstellen'}).click();await p.waitForLoadState();
 yes((await p.content()).includes('/send/'),label+': Link erstellt');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),label+': Anfrageseite ohne Überlauf');
 await p.screenshot({path:`${out}/request-links-${label}.png`,fullPage:true});
 const url=(await p.locator('code').first().textContent()).trim();
 await login(p,eb);await p.goto(url);
 yes((await p.content()).includes('Dein Guthaben'),label+': Sendeformular');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),label+': Sendeseite ohne Überlauf');
 await p.locator('[name=amount]').fill('5');await p.screenshot({path:`${out}/send-points-${label}.png`,fullPage:true});
 await p.locator('[name=confirm]').check();await p.getByRole('button',{name:'Punkte senden'}).click();await p.waitForURL('**/wallet');
 yes((await p.content()).includes('Punkte gesendet'),label+': Senden bestätigt');
 await ctx.close();}
await b.close();console.log(n+' Transfer-Browser-Prüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
