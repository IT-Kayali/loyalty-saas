const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};const out='/tmp/loyalty-admin-health-json';fs.mkdirSync(out,{recursive:true});const b=await chromium.launch({headless:true,args:['--no-sandbox']}),U='http://127.0.0.1:8099';
for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}],['narrow',{width:320,height:720}]]){
 const c=await b.newContext({viewport}),p=await c.newPage();p.on('pageerror',e=>{throw e});await p.goto(U+'/login');await p.locator('[name=email]').fill('admin@test.local');await p.locator('[name=password]').fill('adminpass1');await p.locator('button').first().click();await p.waitForURL('**/dashboard');await p.goto(U+'/admin/health');await p.waitForLoadState();
 yes(await p.locator('[data-health-panel]').count()===2,label+': original queue panels remain');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': no page overflow with added JSON link');
 const link=p.getByRole('link',{name:'JSON-Momentaufnahme',exact:true});yes(await link.count()===1&&await link.isVisible(),label+': explicit JSON link visible');
 yes(await link.evaluate(e=>{const r=e.getBoundingClientRect();return r.x>=0&&r.right<=innerWidth&&r.width>100&&r.height>=40}),label+': readable touch-sized JSON link fits');
 yes(await p.locator('.health-actions a').evaluateAll(es=>{const [a,b]=es.map(e=>e.getBoundingClientRect());return b.y>=a.bottom?b.y-a.bottom>=7:b.x-a.right>=7}),label+': buttons keep a visible gap when wrapping');
 yes(!(await p.content()).includes('PRIVATE-')&&await p.locator('form').count()===0,label+': no private content or mutation form');
 await p.screenshot({path:out+'/health-json-'+label+'.png',fullPage:label==='desktop'});
 if(label!=='desktop')await p.screenshot({path:out+'/health-json-'+label+'-full.png',fullPage:true});
 const response=await Promise.all([p.waitForResponse(r=>r.url()===U+'/admin/health/json'),link.click()]).then(v=>v[0]);const d=await response.json();
 yes(response.status()===200&&response.headers()['cache-control']==='private, no-store',label+': real browser link reaches private JSON');
 yes(d.success===true&&d.data.mail.total===14&&d.data.webhooks.total===13&&d.data.not_checked.includes('worker_heartbeat'),label+': JSON retains scope limits and exact totals');
 await c.close();
}
await b.close();console.log(n+' admin health JSON browser assertions passed');})().catch(e=>{console.error(e);process.exit(1)});
