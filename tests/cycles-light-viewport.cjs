// Real authenticated phone fixture for the light Cycles tab. No live data is used.
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const {join,resolve}=require('node:path');
const {spawn,spawnSync}=require('node:child_process');
const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-cycles-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 writeFileSync(config,`<?php if(($_SERVER['HTTP_X_ANKH_FIXTURE_AUTH']??'')==='yes'){$_SESSION['admin']=true;$_SESSION['last']=time();}return ['password_hash'=>'fixture-only','database_path'=>'${db}'];`);
 const port=await new Promise((resolvePort,reject)=>{const s=require('node:net').createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolvePort(p));});});
 const host='http://127.0.0.1:'+port;
 const server=spawn('php',['-d','session.save_path='+temp,'-S','127.0.0.1:'+port,'-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});
 let browser;
 try{
  for(let i=0;i<60;i++){try{if((await fetch(host,{headers:{'X-Ankh-Fixture-Auth':'yes'}})).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  const seed=join(temp,'seed.php');
  writeFileSync(seed,`<?php
$db=new PDO('sqlite:${db}');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$now=gmdate('c');
$insert=$db->prepare('INSERT INTO reta_cycles(customer_name,recipient_name,phone,last_order_id,last_order_date,last_delivery_date,next_due_date,expected_value,expected_cost,expected_profit,cost_missing,product_summary,product_name,presentation,quantity,cycle_days,cycle_weeks,active,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)');
for($i=1;$i<=40;$i++){$insert->execute(['Customer '.$i,'Recipient '.$i,'0700000'.str_pad((string)$i,3,'0',STR_PAD_LEFT),0,date('Y-m-d'),date('Y-m-d'),date('Y-m-d'),24000,9000,15000,0,'Retatrutide 10mg × 1','Retatrutide 10mg','10mg',1,28,4,$now,$now]);}
`);
  const seeded=spawnSync(process.env.PHP||'php',[seed],{encoding:'utf8'});
  assert.equal(seeded.status,0,'Could not seed recurring cycles: '+(seeded.stderr||seeded.stdout));
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const context=await browser.newContext({viewport:{width:390,height:844}});
  await context.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.hostname==='127.0.0.1'||url.hostname==='localhost')return route.continue({headers:{...route.request().headers(),'x-ankh-fixture-auth':'yes'}});
   if(url.hostname==='fonts.googleapis.com')return route.fulfill({status:200,contentType:'text/css',body:''});
   if(url.hostname==='fonts.gstatic.com')return route.fulfill({status:200,contentType:'font/woff2',body:''});
   return route.continue();
  });
  const page=await context.newPage(),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  page.on('console',m=>{if(m.type()==='error')errors.push('console: '+m.text());});
  const response=await page.goto(host+'/?view=plan&tab=cycles',{waitUntil:'load'});
  assert(response&&response.ok(),'Cycles tab did not load with a 200 response');
  assert(await page.locator('.cycle-light').isVisible(),'The light Cycles layout is missing');
  assert.equal(await page.locator('[data-cycle-row]').count(),40,'Every active cycle needs a compact row');
  assert(await page.locator('.cycle-horizon-tabs').isVisible(),'The forecast horizon tabs must stay visible');
  assert.equal(await page.evaluate(()=>document.querySelector('.cycle-forecast-details')?.open),false,'Forecast and stock must start collapsed');
  assert(await page.locator('.cycle-calendar-card').count()<=30,'Occurrence cards must be capped at 30');
  const metrics=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
  assert(metrics.scrollWidth<=metrics.width+2,'390px Cycles tab has horizontal overflow '+JSON.stringify(metrics));
  const offenders=await page.evaluate(()=>{
   const shell=new Set(['ankh-notification-mobile-button','ankh-search-mobile-button','ankh-notification-button','ankh-search-button','ankh-search-backdrop','ankh-search-panel','ankh-assistant-overlay']);
   const main=document.getElementById('ankh-main'),out=[];
   main.querySelectorAll('*').forEach(el=>{
    if(el.closest('header,nav'))return;
    if(el.closest('.ankh-search-sheet'))return;
    if([...el.classList].some(name=>shell.has(name)))return;
    const rect=el.getBoundingClientRect();
    if(rect.width<1||rect.height<1)return;
    const style=getComputedStyle(el),filter=style.backdropFilter||style.webkitBackdropFilter||'none';
    if(filter&&filter!=='none')out.push(el.tagName.toLowerCase()+'.'+String(el.className));
   });
   return out;
  });
  assert.deepEqual(offenders,[],'Repeated Cycles content must not use backdrop-filter: '+offenders.join(', '));
  assert.deepEqual(errors,[],'Cycles tab console errors: '+errors.join(' | '));
  console.log('PASS: Plan > Cycles loads at 390px with 40 compact rows, a collapsed forecast and no backdrop-filter layers or console errors.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1)});
