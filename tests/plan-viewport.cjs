// Real authenticated HTTP fixture for the merged Plan screen. No live data is used.
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');const {join,resolve}=require('node:path');
const {spawn}=require('node:child_process');const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-plan-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 writeFileSync(config,`<?php if(($_SERVER['HTTP_X_ANKH_FIXTURE_AUTH']??'')==='yes'){$_SESSION['admin']=true;$_SESSION['last']=time();}return ['password_hash'=>'fixture-only','database_path'=>'${db}'];`);
 const port=await new Promise((resolvePort,reject)=>{const s=require('node:net').createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolvePort(p));});});
 const host='http://127.0.0.1:'+port;
 const server=spawn('php',['-d','session.save_path='+temp,'-S','127.0.0.1:'+port,'-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});
 let browser;
 try{
  for(let i=0;i<60;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const page=await browser.newPage({viewport:{width:390,height:844},extraHTTPHeaders:{'X-Ankh-Fixture-Auth':'yes'}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const tabs={reorder:'.report-filter-bar.reorder-window-filter',cycles:'.cycle-horizon-tabs',shipping:'.ship-view-tabs'};
  await page.goto(host+'/?view=plan');
  assert.equal((await page.locator('.plan-switch a[aria-current="page"]').innerText()).trim(),'Reorder','Plan must default to the Reorder tab');
  for(const width of [375,390,430]){
   await page.setViewportSize({width,height:844});
   for(const [tab,selector] of Object.entries(tabs)){
    await page.goto(host+'/?view=plan&tab='+tab);
    assert.equal(await page.locator('.plan-switch').count(),1,'Plan switch missing on '+tab);
    assert.equal(await page.locator('.plan-switch a').count(),3,'Plan switch needs three tabs');
    assert.equal((await page.locator('.plan-switch a[aria-current="page"]').innerText()).trim(),tab[0].toUpperCase()+tab.slice(1),'Wrong active Plan tab');
    assert.equal(await page.locator('main').getAttribute('data-view'),'plan','Plan screen must expose data-view="plan"');
    assert(await page.locator(selector).first().isVisible(),'Missing '+tab+' content');
    const m=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
    assert(m.scrollWidth<=m.width+2,width+'px '+tab+' horizontal overflow '+JSON.stringify(m));
   }
  }
  // Legacy routes must 302 to the matching tab and retain their other filters.
  const redirect=async url=>{const res=await page.request.get(host+url,{maxRedirects:0});assert.equal(res.status(),302,'Legacy route must redirect: '+url);return res.headers()['location'];};
  assert.match(await redirect('/?view=reorder&section=low'),/view=plan/);
  assert.match(await redirect('/?view=reorder&section=low'),/tab=reorder/);
  assert.match(await redirect('/?view=reorder&section=low'),/section=low/);
  assert.match(await redirect('/?view=reta&weeks=4'),/tab=cycles/);
  assert.match(await redirect('/?view=reta&weeks=4'),/weeks=4/);
  assert.match(await redirect('/?view=shipping&tab=stock&show=all'),/tab=shipping/);
  assert.match(await redirect('/?view=shipping&tab=stock&show=all'),/ship_tab=stock/);
  assert.match(await redirect('/?view=shipping&tab=stock&show=all'),/show=all/);
  await page.goto(host+'/?view=reorder&demand_days=60');
  assert.match(page.url(),/[?&]view=plan\b/);assert.match(page.url(),/[?&]tab=reorder\b/);assert.match(page.url(),/[?&]demand_days=60\b/);
  await page.goto(host+'/?view=reta&weeks=4');
  assert.match(page.url(),/[?&]view=plan\b/);assert.match(page.url(),/[?&]tab=cycles\b/);assert.match(page.url(),/[?&]weeks=4\b/);
  await page.goto(host+'/?view=shipping&tab=stock');
  assert.match(page.url(),/[?&]view=plan\b/);assert.match(page.url(),/[?&]tab=shipping\b/);assert.match(page.url(),/[?&]ship_tab=stock\b/);
  assert(await page.getByText('Stock protection forecast').isVisible(),'Shipping stock filter was not preserved');
  // A real POST return from the legacy shipping route must land on the Plan shipping tab.
  await page.goto(host+'/?view=shipping&mode=manage');
  const editor=page.locator('details.ship-editor').first();
  await editor.locator('summary').click();
  await editor.locator('input[name="name"]').fill('Fixture closure');
  await editor.locator('input[name="date"]').fill('2026-12-01');
  await editor.locator('input[name="end"]').fill('2026-12-02');
  await Promise.all([page.waitForNavigation(),editor.getByRole('button',{name:'Save new event'}).click()]);
  assert.match(page.url(),/[?&]view=plan\b/);assert.match(page.url(),/[?&]tab=shipping\b/);assert.match(page.url(),/[?&]calendar=saved\b/);
  assert(await page.getByText('Event changes saved successfully.').isVisible(),'Shipping POST did not return to its Plan tab');
  assert.deepEqual(errors,[]);
  console.log('PASS: Plan renders Reorder, Cycles and Shipping at 375/390/430px with no overflow; legacy routes redirect to the matching tab and keep their filters, including POST returns.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1)});
