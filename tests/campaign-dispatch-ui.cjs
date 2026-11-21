const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const cid=process.argv[2],legacy=process.argv[3],U='http://127.0.0.1:8099',out='/tmp/loyalty-campaign-dispatch';fs.mkdirSync(out,{recursive:true});
const b=await chromium.launch({headless:true,args:['--no-sandbox']});
for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}]]){
 const c=await b.newContext({viewport}),p=await c.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/login');await p.locator('[name=email]').fill('demo@loyalty.local');await p.locator('[name=password]').fill('demo1234');await p.locator('button').first().click();await p.waitForURL('**/dashboard');
 await p.goto(U+'/business/1');
 const send=async id=>{const form=p.locator('form[action="/campaign/send"]').filter({has:p.locator('input[name=campaign_id][value="'+id+'"]')});await form.getByRole('button',{name:'Senden',exact:true}).click();await p.waitForLoadState();};
 if(label==='desktop')await send(cid);
 else await Promise.all([p.waitForNavigation(),p.evaluate(async id=>{const token=document.querySelector('input[name=csrf]').value;const f=document.createElement('form');f.method='post';f.action='/campaign/send';for(const [name,value]of Object.entries({csrf:token,business_id:1,campaign_id:id})){const i=document.createElement('input');i.name=name;i.value=value;f.append(i)}document.body.append(f);f.submit()},cid)]);
 await p.waitForLoadState();
 yes((await p.content()).includes(label==='desktop'?'E-Mails in der Warteschlange':'Keine erneute Zustellung'),label+': Ergebnis erklärt Vorbereitung oder sichere Wiederholung');
 await p.screenshot({path:out+'/campaign-dispatch-'+label+'.png'});
 const f=p.locator('form[action="/campaign/send"]').filter({has:p.locator('input[name=campaign_id][value="'+cid+'"]')});
 yes(await f.locator('button').count()===0,label+': abgeschlossene Kampagne ohne Senden-Button');
 await send(legacy);yes((await p.content()).includes('Ungeklärter früherer Teilversand'),label+': historischer Teilversand sichtbar blockiert');
 await p.screenshot({path:out+'/campaign-partial-'+label+'.png'});await c.close();
}
await b.close();console.log(n+' Kampagnen-Browserprüfungen bestanden');})().catch(e=>{console.error(e);process.exit(1)});
