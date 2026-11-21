const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const out=process.env.TEST_OUTPUT||'/tmp/loyalty-webhooks';fs.mkdirSync(out,{recursive:true});const U='http://127.0.0.1:8099';
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,vp] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 for(const [who,email,pw,path,marker] of [['owner','demo@loyalty.local','demo1234','/business/1','Erneut senden'],['admin','admin@test.local','adminpass1','/admin','Wiederholen']]){
  const ctx=await b.newContext({viewport:vp});const p=await ctx.newPage();p.on('pageerror',e=>{throw e});
  await p.goto(U+'/login');await p.locator('[name=email]').fill(email);await p.locator('[name=password]').fill(pw);await p.locator('button').first().click();await p.waitForLoadState();
  await p.goto(U+path);
  yes((await p.content()).includes(marker),`${label} ${who}: Wiederholen-Button sichtbar`);
  yes(await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),`${label} ${who}: kein horizontaler Überlauf`);
  if(who==='owner') {
   const form=p.locator('form[action="/webhook/notifications"]'),box=form.locator('[name=webhook_owner_mail]');
   yes(await box.isChecked(),`${label}: Owner-Mail standardmäßig eingeschaltet`);
   await box.uncheck();await form.getByRole('button').click();await p.waitForLoadState();
   yes(!(await p.locator('[name=webhook_owner_mail]').isChecked()),`${label}: Mail-Opt-out gespeichert`);
   yes(await p.locator('[role=alert]').count()>0,`${label}: App-Hinweis unabhängig von Mail`);
   const saved=p.locator('form[action="/webhook/notifications"]');
   await saved.evaluate(e=>e.scrollIntoView({block:'center'}));
   await saved.screenshot({path:`${out}/webhooks-mail-settings-${label}.png`});
   await saved.locator('[name=webhook_owner_mail]').check();await saved.getByRole('button').click();await p.waitForLoadState();
   yes(await p.locator('[name=webhook_owner_mail]').isChecked(),`${label}: Owner-Mail wieder einschaltbar`);
  }
  if(who==='owner'){yes((await p.content()).includes('endgültig fehlgeschlagen'),`${label}: Owner-Banner sichtbar`);await p.locator('[role=alert]').first().scrollIntoViewIfNeeded();await p.screenshot({path:`${out}/webhooks-owner-banner-${label}.png`});}
  if(who==='admin'){yes((await p.content()).includes('endgültig fehlgeschlagen'),`${label}: Banner sichtbar`);await p.evaluate(()=>window.scrollTo(0,0));await p.screenshot({path:`${out}/webhooks-banner-${label}.png`});}
  await p.getByRole('button',{name:marker}).first().scrollIntoViewIfNeeded();await p.screenshot({path:`${out}/webhooks-${who}-${label}.png`});
  await p.getByRole('button',{name:marker}).first().click();await p.waitForLoadState();
  yes((await p.content()).includes('weiter fehlgeschlagen'),`${label} ${who}: Klick meldet Ergebnis`);
  await ctx.close();}}
await b.close();console.log(n+' Webhook-Browser-Prüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
