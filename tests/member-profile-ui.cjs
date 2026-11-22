const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const out=process.env.TEST_OUTPUT||'/tmp/loyalty-member-profile';fs.mkdirSync(out,{recursive:true});const U='http://127.0.0.1:8099';
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,vp] of [['desktop',{width:1280,height:1000}],['mobile',{width:390,height:844}]]){
 const ctx=await b.newContext({viewport:vp});const p=await ctx.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/member/login');await p.locator('[name=email]').fill('aylin@example.com');await p.locator('[name=password]').fill('kunde1234');await p.locator('button').first().click();await p.waitForURL('**/wallet');
 await p.getByRole('link',{name:'Meine Namen',exact:true}).click();
 await p.waitForURL(U+'/member/profile');await p.locator('.member-profile form').first().waitFor({state:'visible'});
 yes(await p.locator('.member-profile').count()>=1,label+': eigene Namensformulare');
 const form=p.locator('.member-profile form').first();await form.locator('[name=name]').fill(label+' Aylin');await form.getByRole('button',{name:'Name speichern'}).click();await p.waitForLoadState();
 yes(await p.locator('.member-profile [name=name]').first().inputValue()===label+' Aylin',label+': Name gespeichert und wieder angezeigt');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': kein horizontaler Überlauf');
 await p.evaluate(()=>window.scrollTo(0,0));await p.screenshot({path:`${out}/member-profile-${label}.png`,fullPage:true});
 await p.goto(U+'/wallet');yes((await p.locator('h1').textContent()).includes(label),label+': Wallet zeigt eigenen aktuellen Namen');
 await ctx.close();}
await b.close();console.log(n+' Mitglieder-Profil-Browser-Prüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
