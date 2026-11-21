const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const sid=process.argv[2],cid=process.argv[3],linked=process.argv[4],U='http://127.0.0.1:8099',out='/tmp/loyalty-marketing-delete';fs.mkdirSync(out,{recursive:true});const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const c=await b.newContext({viewport}),p=await c.newPage();p.on('pageerror',e=>{throw e});await p.goto(U+'/login');await p.locator('[name=email]').fill('demo@loyalty.local');await p.locator('[name=password]').fill('demo1234');await p.locator('button').first().click();await p.waitForURL('**/dashboard');
 await p.goto(U+'/business/1');let form=p.locator('form[action="/campaign/delete"]').filter({has:p.locator('[name=campaign_id][value="'+cid+'"]')});await form.locator('button').click();await p.waitForLoadState();
 yes((await p.content()).includes('Kampagnenentwurf löschen?') && (await p.content()).includes('Testkampagne'),label+': konkrete Bestätigung statt Sofortlöschung');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': Bestätigung ohne Überlauf');await p.screenshot({path:out+'/campaign-delete-'+label+'.png',fullPage:true});
 await p.getByRole('link',{name:'Abbrechen, nichts löschen'}).click();await p.waitForLoadState();yes(await p.locator('a[href="/campaign/'+cid+'/edit"]').count()===1,label+': Abbrechen behält Entwurf');
 await p.locator('form[action="/segment/delete"]').filter({has:p.locator('[name=segment_id][value="'+linked+'"]')}).locator('button').click();await p.waitForLoadState();yes((await p.content()).includes('verknüpft und wird nicht gelöscht'),label+': verknüpftes Segment blockiert');
 if(label==='mobile'){
  await p.locator('form[action="/campaign/delete"]').filter({has:p.locator('[name=campaign_id][value="'+cid+'"]')}).locator('button').click();await p.waitForLoadState();await p.getByRole('button',{name:'Endgültig löschen'}).click();await p.waitForLoadState();yes(await p.locator('a[href="/campaign/'+cid+'/edit"]').count()===0,'Bestätigung entfernt nur Entwurf');
  await p.locator('form[action="/segment/delete"]').filter({has:p.locator('[name=segment_id][value="'+sid+'"]')}).locator('button').click();await p.waitForLoadState();await p.screenshot({path:out+'/segment-delete-mobile.png',fullPage:true});yes((await p.content()).includes('Segment löschen?'),'Unverknüpftes Segment separat bestätigen');
  await p.getByRole('button',{name:'Endgültig löschen'}).click();await p.waitForLoadState();yes(await p.locator('a[href="/segment/'+sid+'/edit"]').count()===0,'Bestätigung entfernt unverknüpftes Segment');
 }await c.close();
}
await b.close();console.log(n+' Marketing-Lösch-Browserprüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
