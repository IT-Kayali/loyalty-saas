const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const out=process.env.TEST_OUTPUT||'/tmp/loyalty-member-password';fs.mkdirSync(out,{recursive:true});const U='http://127.0.0.1:8099';
const b=await chromium.launch({headless:true,args:['--no-sandbox']});let password='kunde1234';
for(const [label,vp] of [['desktop',{width:1280,height:1000}],['mobile',{width:390,height:844}]]){
 const ctx=await b.newContext({viewport:vp});const p=await ctx.newPage();p.on('pageerror',e=>{throw e});
 const login=async(pw)=>{await p.goto(U+'/member/login');await p.locator('[name=email]').fill('aylin@example.com');await p.locator('[name=password]').fill(pw);await p.locator('button').first().click();await p.waitForURL('**/wallet');};
 await login(password);await p.getByRole('link',{name:'Passwort ändern',exact:true}).click();
 yes(await p.locator('[name=current_password]').getAttribute('type')==='password',label+': bisheriges Passwort maskiert');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': kein horizontaler Überlauf');
 await p.screenshot({path:`${out}/member-password-${label}.png`,fullPage:true});
 await p.locator('[name=current_password]').fill(password);const next='browser-member-'+label+'-123';await p.locator('[name=password]').fill(next);await p.locator('[name=password_confirm]').fill(next);await p.getByRole('button',{name:'Passwort ändern und abmelden'}).click();await p.waitForURL('**/member/login');
 yes((await p.content()).includes('Passwort geändert'),label+': Passwortänderung bestätigt, abgemeldet');
 await login(next);yes(p.url().endsWith('/wallet'),label+': neues Passwort öffnet Wallet');password=next;
 await ctx.close();}
await b.close();console.log(n+' Mitglieder-Passwort-Browser-Prüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
