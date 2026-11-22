const {chromium}=require('playwright');
const fs=require('fs');
(async()=>{
 let n=0;
 const check=(value,label)=>{if(!value)throw Error(label);n++;console.log('PASS '+label)};
 const out='/tmp/loyalty-member-join-identity';fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const base='http://127.0.0.1:8099',join=process.env.JOIN_TEST_PATH;
 if(!join||!join.startsWith('/join/'))throw Error('Missing local fixture join path');
 try{
  for(const [label,viewport] of [['desktop',{width:1280,height:1000}],['mobile',{width:390,height:844}],['narrow',{width:320,height:720}]]){
   const context=await browser.newContext({viewport}),page=await context.newPage(),errors=[];
   page.on('pageerror',error=>errors.push(error.message));
   await page.goto(base+join);
   await page.locator('[name=name]').fill('UI-JOIN-TEST');
   await page.locator('[name=email]').fill('aylin@example.com');
   await page.locator('[name=password]').fill('local-join-ui-test');
   await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Jetzt beitreten',exact:true}).click()]);
   await page.getByText('Beitritt nicht möglich. Nutze bitte den Wallet-Login oder die Passwort-Hilfe.',{exact:true}).waitFor({state:'visible'});
   check(page.url()===base+join,label+': rejected join does not enter wallet');
   check(!(await page.locator('body').textContent()).includes('PRIVATE-EXISTING-MEMBER'),label+': existing synthetic member name stays absent');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': no sideways overflow');
   check(await page.locator('input[type=email]').isVisible()&&await page.locator('input[type=password]').isVisible(),label+': form remains usable after rejection');
   await page.screenshot({path:out+'/member-join-identity-'+label+'.png',fullPage:true});
   await page.getByRole('link',{name:'Zur Wallet-Anmeldung',exact:true}).click();
   check(page.url()===base+'/member/login',label+': visible login link reaches member login');
   await page.goto(base+'/wallet');
   check(page.url()===base+'/member/login',label+': same browser has no member session');
   check(errors.length===0,label+': no page errors');
   await context.close();
  }
 }finally{await browser.close()}
 console.log(n+' join identity browser assertions passed');
})().catch(error=>{console.error(error);process.exit(1)});
