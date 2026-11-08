// Real authenticated HTTP fixture for the merged Packing screen. No live data is used.
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-packing-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 writeFileSync(config,`<?php if(($_SERVER['HTTP_X_ANKH_FIXTURE_AUTH']??'')==='yes'){$_SESSION['admin']=true;$_SESSION['last']=time();}return ['password_hash'=>'fixture-only','database_path'=>'${db}'];`);
 const port=await new Promise((resolvePort,reject)=>{const s=require('node:net').createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolvePort(p));});});
 const host='http://127.0.0.1:'+port;
 const server=spawn('php',['-d','session.save_path='+temp,'-S','127.0.0.1:'+port,'-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});
 let browser;
 try{
  for(let i=0;i<60;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');$db->exec("INSERT INTO products(id,name,price,stock_qty,stock_tracking,stock_jay_qty) VALUES(99001,'Fixture packing product 10mg',5000,0,1,0);INSERT INTO orders(id,customer,recipient_name,phone,address,notes,status,created,assigned_to,back_order) VALUES(99001,'Fixture Buyer','Envelope Recipient','01234567890','12 Example Street','Keep this order separate','New','2026-11-06','James',0);INSERT INTO items(order_id,product_id,name,price,quantity,presentation) VALUES(99001,99001,'Fixture packing product 10mg',5000,2,'Pen');");`],{encoding:'utf8'});
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const page=await browser.newPage({viewport:{width:390,height:844},extraHTTPHeaders:{'X-Ankh-Fixture-Auth':'yes'}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(host+'/?view=packing');
  assert.equal(await page.locator('.packing-switch').count(),1,'Packing switch missing');
  assert.equal(await page.locator('.packing-switch a').count(),2,'Packing switch needs two tabs');
  assert.equal((await page.locator('.packing-switch a[aria-current="page"]').innerText()).trim(),'Packing runs','Packing must default to the runs tab');
  assert(await page.locator('#pc-source').isVisible(),'Packing Centre body missing');
  for(const width of [375,390,430]){
   await page.setViewportSize({width,height:844});
   for(const [tab,selector] of Object.entries({runs:'#pc-source',pick:'.packing-v2-workspace','pick&id=99001':'.packing-v2-line-list'})){
    await page.goto(host+'/?view=packing&tab='+tab);
    assert.equal(await page.locator('.packing-switch').count(),1,'Packing switch missing on '+tab);
    assert.equal((await page.locator('.packing-switch a[aria-current="page"]').innerText()).trim(),tab==='runs'?'Packing runs':'Pick & prepare','Wrong active Packing tab');
    assert(await page.locator(selector).first().isVisible(),'Missing '+tab+' content');
    const m=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
    assert(m.scrollWidth<=m.width+2,width+'px '+tab+' horizontal overflow '+JSON.stringify(m));
    const small=await page.evaluate(()=>[...document.querySelectorAll('#ankh-main .packing-switch a, #ankh-main .pc-tabs a, #ankh-main .page-header a, #ankh-main .page-header button, #ankh-main .pc-workspace button, #ankh-main .packing-v2-workspace a, #ankh-main .packing-v2-workspace button')].filter(el=>{const s=getComputedStyle(el),r=el.getBoundingClientRect();return s.display!=='none'&&s.visibility!=='hidden'&&r.width>0&&r.height>0&&r.height<43.5;}).map(el=>({tag:el.tagName,text:(el.innerText||el.getAttribute('aria-label')||'').trim().slice(0,40),height:el.getBoundingClientRect().height})));
    assert.deepEqual(small,[],width+'px '+tab+' has tap targets under 44px: '+JSON.stringify(small));
   }
  }
  // Legacy routes must 302 to the canonical Packing screen and keep their filters.
  const redirect=async url=>{const res=await page.request.get(host+url,{maxRedirects:0});assert.equal(res.status(),302,'Legacy route must redirect: '+url);return res.headers()['location'];};
  const centreRedirect=await redirect('/?view=packing_centre&run=7&print=slips&foo=bar');
  assert.match(centreRedirect,/view=packing/);assert.match(centreRedirect,/run=7/);assert.match(centreRedirect,/print=slips/);assert.match(centreRedirect,/foo=bar/);
  const plainRedirect=await redirect('/?view=packing_centre');
  assert.match(plainRedirect,/view=packing/);assert.doesNotMatch(plainRedirect,/packing_centre/);
  const centreFileRedirect=await redirect('/packing-centre.php?run=3');
  assert.match(centreFileRedirect,/view=packing/);assert.match(centreFileRedirect,/run=3/);
  const pickFileRedirect=await redirect('/packing-v2.php?id=9');
  assert.match(pickFileRedirect,/view=packing/);assert.match(pickFileRedirect,/tab=pick/);assert.match(pickFileRedirect,/id=9/);
  await page.goto(host+'/?view=packing_centre&tab=pick');
  assert.match(page.url(),/[?&]view=packing\b/);assert.match(page.url(),/[?&]tab=pick\b/);assert(await page.locator('.packing-v2-workspace').isVisible(),'Legacy Packing Centre route lost the pick tab');
  // One Packing entry from Today and More, with no stale Packing Centre route.
  await page.goto(host+'/?view=dashboard');
  assert.equal(await page.locator('a[href="?view=packing"]').filter({hasText:'Packing'}).count(),1,'Today needs one Packing quick action');
  await page.goto(host+'/?view=more');
  assert.equal(await page.locator('.more-card[href="?view=packing"]').count(),1,'More needs one Packing entry');
  assert.equal(await page.locator('[href*="packing_centre"]').count(),0,'No stale Packing Centre links');
  assert.deepEqual(errors,[]);
  console.log('PASS: one Packing screen with runs and pick tabs at 375/390/430px, no overflow, 44px tap targets, legacy redirects keeping params, and single Today/More entries.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1)});
