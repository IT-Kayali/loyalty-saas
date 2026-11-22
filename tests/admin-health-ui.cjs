const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};const out='/tmp/loyalty-admin-health';fs.mkdirSync(out,{recursive:true});const b=await chromium.launch({headless:true,args:['--no-sandbox']}),U='http://127.0.0.1:8099';
for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}],['narrow',{width:320,height:720}]]){
 const c=await b.newContext({viewport}),p=await c.newPage();p.on('pageerror',e=>{throw e});await p.goto(U+'/login');await p.locator('[name=email]').fill('admin@test.local');await p.locator('[name=password]').fill('adminpass1');await p.locator('button').first().click();await p.waitForURL('**/dashboard');await p.goto(U+'/admin');await p.getByRole('link',{name:'Versandzustand',exact:true}).click();await p.waitForLoadState();
 yes(await p.locator('h1').textContent()==='Versandzustand',label+': health page linked');yes(await p.locator('[data-health-panel]').count()===2,label+': both delivery groups visible');
 yes((await p.locator('[data-health-key="mail.total"]').textContent())==='14'&&(await p.locator('[data-health-key="webhooks.total"]').textContent())==='13',label+': exact retained totals');
 yes(!(await p.content()).includes('PRIVATE-')&&await p.locator('form').count()===0,label+': no private data or execution form');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': no page overflow');
 yes(await p.locator('.health-metrics').evaluateAll(items=>items.every(e=>e.scrollWidth<=e.clientWidth+1)),label+': labels and values fit without side scrolling');
 yes((await p.content()).includes('Kein Worker-Heartbeat')&&(await p.content()).includes('nicht blind erneut senden'),label+': uncertainty and safe retry caveat visible');
 await p.getByRole('link',{name:'Momentaufnahme erneuern',exact:true}).click();await p.waitForLoadState();yes(await p.locator('[data-health-key="mail.total"]').textContent()==='14',label+': manual reload does not consume queue');
 if(label==='desktop')await p.screenshot({path:out+'/health-desktop.png',fullPage:true});
 if(label==='mobile'){await p.evaluate(()=>scrollTo(0,0));await p.screenshot({path:out+'/health-mobile-top.png',fullPage:false});await p.screenshot({path:out+'/health-mobile.png',fullPage:true});const boxes={};for(const group of ['mail','webhooks'])boxes[group]=await p.locator('[data-health-panel="'+group+'"]').evaluate(e=>{const r=e.getBoundingClientRect();return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height}});fs.writeFileSync(out+'/mobile-boxes.json',JSON.stringify(boxes));}
 await c.close();
}
await b.close();console.log(n+' admin health browser assertions passed');})().catch(e=>{console.error(e);process.exit(1)});
