// Real authenticated HTTP fixture for the merged Buying screen. No live data is used.
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');const {join,resolve}=require('node:path');
const {spawn}=require('node:child_process');const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-buying-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 writeFileSync(config,`<?php if(($_SERVER['HTTP_X_ANKH_FIXTURE_AUTH']??'')==='yes'){$_SESSION['admin']=true;$_SESSION['last']=time();}return ['password_hash'=>'fixture-only','database_path'=>'${db}'];`);
 const port=await new Promise((resolvePort,reject)=>{const s=require('node:net').createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolvePort(p));});});
 const host='http://127.0.0.1:'+port;
 const server=spawn('php',['-d','session.save_path='+temp,'-S','127.0.0.1:'+port,'-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});
 let browser;
 try{
  for(let i=0;i<60;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const page=await browser.newPage({viewport:{width:390,height:844},extraHTTPHeaders:{'X-Ankh-Fixture-Auth':'yes'}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const tabs={supplier:'.sc-tabs',supplies:'.supply-section-tabs'};
  await page.goto(host+'/?view=buying');
  assert.equal((await page.locator('.buying-switch a[aria-current="page"]').innerText()).trim(),'Supplier','Buying must default to the Supplier tab');
  for(const width of [375,390,430]){
   await page.setViewportSize({width,height:844});
   for(const [tab,selector] of Object.entries(tabs)){
    await page.goto(host+'/?view=buying&tab='+tab);
    assert.equal(await page.locator('.buying-switch').count(),1,'Buying switch missing on '+tab);
    assert.equal(await page.locator('.buying-switch a').count(),2,'Buying switch needs two tabs');
    assert.equal((await page.locator('.buying-switch a[aria-current="page"]').innerText()).trim(),tab[0].toUpperCase()+tab.slice(1),'Wrong active Buying tab');
    assert.equal(await page.locator('main').getAttribute('data-view'),'buying','Buying screen must expose data-view="buying"');
    assert(await page.locator(selector).first().isVisible(),'Missing '+tab+' content');
    const m=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
    assert(m.scrollWidth<=m.width+2,width+'px '+tab+' horizontal overflow '+JSON.stringify(m));
   }
  }
  // Legacy routes must 302 to the matching tab and retain their other filters.
  const redirect=async url=>{const res=await page.request.get(host+url,{maxRedirects:0});assert.equal(res.status(),302,'Legacy route must redirect: '+url);return res.headers()['location'];};
  const supplierRedirect=await redirect('/?view=supplier&tab=order&section=low');
  assert.match(supplierRedirect,/view=buying/);assert.match(supplierRedirect,/tab=supplier/);assert.match(supplierRedirect,/supplier_tab=order/);assert.match(supplierRedirect,/section=low/);
  const suppliesRedirect=await redirect('/?view=supplies&section=purchases&status=all');
  assert.match(suppliesRedirect,/view=buying/);assert.match(suppliesRedirect,/tab=supplies/);assert.match(suppliesRedirect,/section=purchases/);assert.match(suppliesRedirect,/status=all/);
  await page.goto(host+'/?view=supplier&tab=order&section=low');
  assert.match(page.url(),/[?&]view=buying\b/);assert.match(page.url(),/[?&]tab=supplier\b/);assert.match(page.url(),/[?&]supplier_tab=order\b/);assert.match(page.url(),/[?&]section=low\b/);
  await page.goto(host+'/?view=supplies&section=shopping');
  assert.match(page.url(),/[?&]view=buying\b/);assert.match(page.url(),/[?&]tab=supplies\b/);assert.match(page.url(),/[?&]section=shopping\b/);
  // A real POST return from the legacy supplier route must land on the Buying supplier tab.
  await page.goto(host+'/?view=supplier&tab=products');
  const config=page.locator('.sc-config-form');
  await config.locator('input[name="usd_gbp_rate"]').fill('0.78');
  await Promise.all([page.waitForNavigation(),config.getByRole('button',{name:'Save conversion settings'}).click()]);
  assert.match(page.url(),/[?&]view=buying\b/);assert.match(page.url(),/[?&]tab=supplier\b/);assert.match(page.url(),/[?&]supplier_tab=profit\b/);
  assert(await page.getByText('Exchange rate and fixed USD shipping per supplier order saved.').isVisible(),'Supplier POST did not return to the Buying supplier tab');
  // A real POST return from the legacy supplies route must land on the Buying supplies tab.
  await page.goto(host+'/?view=supplies&new_item=1');
  await page.getByLabel('Supply name',{exact:true}).fill('Fixture labels');
  await page.getByLabel('Usual quantity / packs',{exact:true}).fill('4');
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Save supply',exact:true}).click()]);
  assert.match(page.url(),/[?&]view=buying\b/);assert.match(page.url(),/[?&]tab=supplies\b/);assert.match(page.url(),/[?&]section=cupboard\b/);
  assert(await page.getByText('Fixture labels',{exact:true}).isVisible(),'Supplies POST did not return to the Buying supplies tab');
  assert.deepEqual(errors,[]);
  console.log('PASS: Buying renders Supplier and Supplies at 375/390/430px with no overflow; legacy routes redirect to the matching tab and keep their filters, including POST returns.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1)});
