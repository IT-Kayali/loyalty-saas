const {chromium}=require('playwright'),fs=require('fs');
(async()=>{
 let n=0;const yes=(v,s)=>{if(!v)throw Error(s);console.log('PASS '+s);n++};
 const id=process.argv[2],U='http://127.0.0.1:8099',out='/tmp/loyalty-wallet-push';fs.mkdirSync(out,{recursive:true});
 const b=await chromium.launch({headless:true,args:['--no-sandbox']});
 for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
  const c=await b.newContext({viewport}),p=await c.newPage();p.on('pageerror',e=>{throw e});
  await p.goto(U+'/login');await p.locator('[name=email]').fill('demo@loyalty.local');await p.locator('[name=password]').fill('demo1234');await p.locator('button').first().click();await p.waitForURL('**/dashboard');await p.goto(U+'/organization');await p.locator('a[href="/business/1/wallet-push"]').click();
  yes(await p.locator('h1').textContent()==='Wallet-Mitteilungen',label+': message settings open through organization');
  yes((await p.locator('main').textContent()).includes('noch kein Versandweg'),label+': no-send stage is explicit');
  await p.locator('[name=extra]').fill('X'.repeat(500));await p.getByRole('button',{name:'Hinweise und Vorlagen speichern',exact:true}).click();await p.waitForLoadState();
  yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': long operator notice wraps');
  await p.locator('[name=extra]').fill('Freiwillige wichtige Informationen zur Kundenkarte. Kein Werbeversand.');await p.locator('[name=apple_text]').fill('Heute gelten geänderte Einlösebedingungen. Bitte prüfe deine Karte.');await p.locator('[name=google_header]').fill('Wichtige Karteninformation');await p.locator('[name=google_body]').fill('Heute gelten geänderte Einlösebedingungen. Details stehen auf deiner Karte.');await p.getByRole('button',{name:'Hinweise und Vorlagen speichern',exact:true}).click();await p.waitForLoadState();
  yes((await p.locator('[name=apple_text]').inputValue()).includes('Einlösebedingungen')&&(await p.locator('[name=google_body]').inputValue()).includes('Details'),label+': separate editable provider texts persist');
  yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': settings have no horizontal overflow');
  await p.screenshot({path:out+'/owner-'+label+'.png'});await p.locator('[name=apple_text]').scrollIntoViewIfNeeded();await p.evaluate(()=>scrollBy(0,document.querySelector('[name=apple_text]').getBoundingClientRect().top-180));await p.screenshot({path:out+'/texts-'+label+'.png'});await c.close();
  const mc=await b.newContext({viewport}),m=await mc.newPage();m.on('pageerror',e=>{throw e});await m.goto(U+'/member/login');await m.locator('[name=email]').fill('aylin@example.com');await m.locator('[name=password]').fill('kunde1234');await m.locator('button').first().click();await m.waitForURL('**/wallet');await m.locator('a[href="/member/wallet-push"]').click();
  const apple=m.locator('[data-member-id="'+id+'"][data-provider=apple]'),google=m.locator('[data-member-id="'+id+'"][data-provider=google]');
  yes(await apple.locator('[name=confirm]').isChecked()===false&&await google.locator('[name=confirm]').isChecked()===false,label+': provider choices not prechecked');
  await apple.locator('[name=confirm]').check();await apple.getByRole('button',{name:'Mitteilungen freiwillig einschalten',exact:true}).click();await m.waitForLoadState();
  yes((await apple.textContent()).includes('Zugestimmt für aktuellen Hinweisstand')&&!(await google.textContent()).includes('Zugestimmt für aktuellen Hinweisstand'),label+': Apple approval leaves Google separate');
  yes(await m.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': customer page has no horizontal overflow');
  await m.screenshot({path:out+'/member-'+label+'.png'});await apple.getByRole('button',{name:'Mitteilungen freiwillig einschalten',exact:true}).scrollIntoViewIfNeeded();await m.screenshot({path:out+'/choice-'+label+'.png'});
  await apple.getByRole('button',{name:'Ausschalten / widerrufen',exact:true}).click();await m.waitForLoadState();yes((await apple.textContent()).includes('Status: Ausgeschaltet'),label+': easy withdrawal works');await mc.close();
 }
 await b.close();console.log(n+' wallet message preferences browser assertions passed');
})().catch(e=>{console.error(e);process.exit(1)});
