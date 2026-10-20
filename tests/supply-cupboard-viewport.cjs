// Browser workflow against isolated fixture data, never the live app.
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-cupboard-ui-')),config=join(temp,'config.php');
 const hash=execFileSync('php',['-r','echo password_hash("fixture-only",PASSWORD_DEFAULT);'],{encoding:'utf8'}).trim();
 writeFileSync(config,`<?php return ['password_hash'=>'${hash}','database_path'=>'${join(temp,'fixture.sqlite')}'];`);
 const server=spawn('php',['-S','127.0.0.1:18766','-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});const host='http://127.0.0.1:18766';let browser;
 try{
  for(let i=0;i<50;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const submit=async locator=>{await Promise.all([page.waitForNavigation(),locator.click()]);};
  await page.goto(host+'/?view=supplies&new_item=1');await page.getByLabel('Supply name',{exact:true}).fill('Thermal labels fixture');await page.getByLabel('Stock level',{exact:true}).selectOption('Out');await page.getByLabel('Exact size / model / specification').fill('100 × 150mm waterproof');await page.getByLabel('Preferred supplier',{exact:true}).fill('Fixture label shop');await page.getByLabel('Product / reorder link',{exact:true}).fill('https://example.com/labels');await page.getByLabel('Usual quantity / packs',{exact:true}).fill('3');await page.getByLabel('Usual items total (£)').fill('12.34');await submit(page.getByRole('button',{name:'Save supply',exact:true}));
  const card=page.locator('#cupboard-1');assert(await card.isVisible());await submit(card.getByRole('button',{name:'Jay will order',exact:true}));assert(await card.getByText('Jay is handling this',{exact:true}).isVisible());
  for(const width of [375,390,768,1440]){
   await page.setViewportSize({width,height:844});for(const route of ['?view=supplies','?view=supplies&section=shopping','?view=supplies&section=purchases','?view=supplies&new_item=1']){await page.goto(host+'/'+route);assert(await page.locator('body').evaluate(el=>el.scrollWidth<=innerWidth+1),'Supplies screens must fit the viewport');assert.equal(await page.locator('.supply-section-tabs a').count(),3);}
  }
  await page.setViewportSize({width:390,height:844});await page.goto(host+'/?view=supplies&section=shopping');await card.getByRole('link',{name:'Record purchase',exact:true}).click();
  assert.equal(await page.locator('#supply-form input[name="quantity"]').inputValue(),'3');assert.equal(await page.locator('#supply-form input[name="item_cost"]').inputValue(),'12.34');await page.locator('#supply-form input[name="item_cost"]').fill('9.99');await page.locator('#supply-form input[name="shipping"]').fill('1.00');await page.locator('#supply-form select[name="payment_status"]').selectOption('Paid');await submit(page.getByRole('button',{name:'Save purchase',exact:true}));
  assert.equal(await page.locator('.supply-card').count(),1);await page.locator('.supply-receive summary').click();await page.locator('.supply-receive input[name="received_quantity"]').fill('1');await page.locator('.supply-receive select[name="stock_level"]').selectOption('Running low');await submit(page.getByRole('button',{name:'Save delivery check-in',exact:true}));assert(await page.getByText('1 of 3 quantity / packs received',{exact:true}).isVisible());
  await page.locator('.supply-receive summary').click();await page.locator('.supply-receive select[name="stock_level"]').selectOption('Enough');await submit(page.getByRole('button',{name:'Save delivery check-in',exact:true}));assert.equal(await page.locator('.supply-receive').count(),0);
  await page.goto(host+'/?view=supplies&section=shopping');assert.equal(await page.locator('.cupboard-card').count(),0);await page.goto(host+'/?view=supplies');assert(await card.getByText('Enough',{exact:true}).first().isVisible());assert.equal(await card.locator('.cupboard-owner').count(),0);
  await page.goto(host+'/?view=reports&type=outgoings&range=all');assert(await page.getByText('£10.99',{exact:true}).count());assert.deepEqual(errors,[]);console.log('PASS: responsive cupboard, creation, claims, prefilled purchases, payment totals, partial/final delivery and shopping-list completion.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stdout?.toString()||e);process.exit(1)});
