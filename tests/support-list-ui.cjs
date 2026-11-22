const {chromium}=require('playwright'),fs=require('fs');
(async()=>{let n=0;const yes=(v,s)=>{if(!v)throw Error(s);n++;console.log('PASS '+s)};
const out=process.env.TEST_OUTPUT||'/tmp/loyalty-support-list';fs.mkdirSync(out,{recursive:true});
const b=await chromium.launch({headless:true,args:['--no-sandbox']}),U='http://127.0.0.1:8099';
for(const [label,viewport] of [['desktop',{width:1280,height:900}],['mobile',{width:390,height:844}],['narrow',{width:320,height:720}]]){
 const c=await b.newContext({viewport,acceptDownloads:true}),p=await c.newPage();p.on('pageerror',e=>{throw e});
 await p.goto(U+'/login');await p.locator('[name=email]').fill('admin@test.local');await p.locator('[name=password]').fill('adminpass1');await p.locator('button').first().click();await p.waitForURL('**/dashboard');await p.goto(U+'/admin/support');
 yes(await p.locator('.support-list-export a').count()===3,label+': three explicit format links');
 await p.locator('[name=q]').fill('%_!');await p.locator('[name=status]').selectOption('open');await p.locator('[name=topic]').selectOption('billing');await p.locator('[name=priority]').selectOption('high');await p.locator('[name=channel]').selectOption('partner');await p.locator('[name=assignee]').selectOption({label:'Admin Support · admin@test.local'});await p.getByRole('button',{name:'Suchen',exact:true}).click();await p.waitForLoadState();
 yes(await p.locator('.support-inbox-table tbody tr').count()===50,label+': combined filters show first50');
 const href=await p.getByRole('link',{name:'Liste als JSON',exact:true}).getAttribute('href'),params=new URL(href,U).searchParams;
 yes(params.get('q')==='%_!' && params.get('topic')==='billing' && params.get('channel')==='partner' && params.get('status')==='open' && params.get('priority')==='high' && !!params.get('assignee') && !params.has('after'),label+': all six filters reach download without cursor');
 for(const fmt of ['CSV','TSV','JSON']){
  const [d]=await Promise.all([p.waitForEvent('download'),p.getByRole('link',{name:'Liste als '+fmt,exact:true}).click()]);const file=out+'/support-list-'+label+'.'+fmt.toLowerCase();await d.saveAs(file);const text=fs.readFileSync(file,'utf8');
  yes(new RegExp('^support-inbox-\\d{4}-\\d{2}-\\d{2}\\.'+fmt.toLowerCase()+'$').test(d.suggestedFilename()),label+': actual '+fmt+' attachment filename');
  yes(fmt==='JSON'?JSON.parse(text).row_count===52 && JSON.parse(text).tickets.length===52:(text.match(/0000000000000[0-9A-F]{3}/g)||[]).length===52,label+': '+fmt+' actual download includes52 not50');
 }
 await p.getByRole('link',{name:'Nächste 50',exact:true}).click();await p.waitForLoadState();yes(await p.locator('.support-inbox-table tbody tr').count()===2,label+': second page only two visible rows');
 const second=new URL(await p.getByRole('link',{name:'Liste als JSON',exact:true}).getAttribute('href'),U);yes(new URL(p.url()).searchParams.has('after') && !second.searchParams.has('after'),label+': second-page download deliberately drops cursor');
 yes(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': no page horizontal overflow');
 yes(await p.locator('.support-list-export').evaluate(e=>e.scrollWidth<=e.clientWidth+1),label+': export panel fits viewport');
 yes(await p.locator('.support-list-export a').evaluateAll(a=>a.every(e=>{const p=e.closest('section').getBoundingClientRect(),r=e.getBoundingClientRect();return r.x>=p.x && r.right<=p.right+1 && r.height>=40})),label+': download targets readable and contained');
 const text=await p.locator('.support-list-export').textContent();yes(text.includes('5000')&&text.includes('4 MiB')&&text.includes('Apostroph')&&text.includes('private Namen'),label+': limits privacy and data-change warning visible');
 await p.evaluate(()=>scrollTo(0,0));await p.screenshot({path:out+'/support-list-'+label+'.png',fullPage:true});
 if(label==='mobile')await p.screenshot({path:out+'/support-list-mobile-top.png',fullPage:false});
 const box=await p.locator('.support-list-export').evaluate(e=>{const r=e.getBoundingClientRect();return{x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height}});fs.writeFileSync(out+'/support-list-'+label+'-box.json',JSON.stringify(box));
 await c.close();
}
await b.close();console.log(n+' support list browser assertions passed');})().catch(e=>{console.error(e);process.exit(1)});
