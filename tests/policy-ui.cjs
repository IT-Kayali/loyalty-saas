const {chromium}=require('playwright');
const fs=require('fs');
(async()=>{
 const out=process.env.TEST_OUTPUT || '/tmp/loyalty-policy-results';fs.mkdirSync(out,{recursive:true});
 const b=await chromium.launch({headless:true,args:['--no-sandbox']});const p=await b.newPage({viewport:{width:1440,height:1050}});
 await p.goto('http://127.0.0.1:8099/login');await p.locator('[name=email]').fill('demo@loyalty.local');await p.locator('[name=password]').fill('demo1234');await p.locator('button').first().click();await p.waitForURL('**/dashboard');
 await p.goto('http://127.0.0.1:8099/billing');await p.screenshot({path:out+'/policy-billing-desktop.png',fullPage:true});
 await p.setViewportSize({width:390,height:844});await p.screenshot({path:out+'/policy-billing-mobile.png',fullPage:true});
 await p.goto('http://127.0.0.1:8099/business/1/design');await p.locator('button').filter({hasText:'Speichern'}).click();await p.waitForLoadState();
 if(await p.locator('h1').textContent()!=='Plan prüfen')throw new Error('Missing policy block');
 if(!await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth))throw new Error('Mobile overflow');
 await p.screenshot({path:out+'/policy-block-mobile.png',fullPage:true});await b.close();
})().catch(e=>{console.error(e);process.exit(1)});
