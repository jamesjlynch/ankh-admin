// Real product-row navigation against an isolated database.
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-product-report-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 const hash=execFileSync('php',['-r','echo password_hash("fixture-only",PASSWORD_DEFAULT);'],{encoding:'utf8'}).trim();writeFileSync(config,`<?php return ['password_hash'=>'${hash}','database_path'=>'${db}'];`);
 const server=spawn('php',['-S','127.0.0.1:18767','-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});const host='http://127.0.0.1:18767';let browser;
 try{
  for(let i=0;i<50;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');$db->exec("INSERT INTO orders(id,customer,recipient_name,phone,address,notes,status,created) VALUES(99001,'March buyer','March recipient','','','','New','2025-03-10T12:00:00Z'),(99002,'Another buyer','','','','','New','2025-03-31T12:00:00Z'),(99003,'Older buyer','','','','','New','2025-02-15T12:00:00Z'); INSERT INTO items(order_id,name,price,quantity) VALUES(99001,'Fixture & Labels',1000,2),(99001,'Fixture & Labels',1000,1),(99002,'Fixture & Labels',1000,4),(99003,'Fixture & Labels',1000,5),(99001,'Fixture & Labels Extra',200,1);");`]);
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const params='view=reports&type=products&range=custom&from=2025-03-01&to=2025-03-31';
  for(const width of [375,390,768,1440]){
   await page.setViewportSize({width,height:844});await page.goto(host+'/?'+params);
   const row=page.locator('.report-product-row').filter({has:page.getByRole('link',{name:'View orders for Fixture & Labels',exact:true})});await row.locator('td').nth(2).click();
   await page.waitForURL('**/*type=productorders*');const url=new URL(page.url());assert.equal(url.searchParams.get('product'),'Fixture & Labels');assert.equal(url.searchParams.get('from'),'2025-03-01');assert.equal(url.searchParams.get('to'),'2025-03-31');assert.equal(await page.locator('.report-product-order').count(),2);assert(await page.locator('.report-product-order-list').getByText('March recipient',{exact:true}).isVisible());assert(await page.locator('.report-product-order-list').getByText('Ordered by March buyer',{exact:true}).isVisible());assert(await page.locator('body').evaluate(el=>el.scrollWidth<=innerWidth+1));
   await page.getByRole('link',{name:'← Back to products',exact:true}).click();assert.equal(new URL(page.url()).searchParams.get('range'),'custom');assert.equal(new URL(page.url()).searchParams.get('from'),'2025-03-01');
  }
  await page.getByRole('link',{name:'View orders for Fixture & Labels',exact:true}).press('Enter');await page.waitForURL('**/*type=productorders*');await page.locator('#report-range').selectOption('all');await Promise.all([page.waitForNavigation(),page.locator('.report-filter-bar').getByRole('button',{name:'Apply',exact:true}).click()]);assert.equal(await page.locator('.report-product-order').count(),3);assert.equal(new URL(page.url()).searchParams.get('product'),'Fixture & Labels');
  await page.goto(host+'/?view=reports&type=overview&range=custom&from=2025-03-01&to=2025-03-31');await page.getByRole('link',{name:'View orders for Fixture & Labels',exact:true}).click();await page.getByRole('link',{name:'← Back to overview',exact:true}).click();assert.equal(new URL(page.url()).searchParams.get('from'),'2025-03-01');
  await page.goto(host+'/?view=reports&type=productorders&product=Fixture+%26+Labels&range=all');await page.locator('.report-product-order').first().click();await page.waitForURL('**/*view=edit*');assert.equal(new URL(page.url()).searchParams.get('id'),'99002');assert.deepEqual(errors,[]);console.log('PASS: whole-row and keyboard navigation, matching orders, recipients, dates, range changes, back links and responsive layouts.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stdout?.toString()||e);process.exit(1)});
