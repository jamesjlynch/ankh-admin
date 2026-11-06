// Real authenticated HTTP fixture; no production customer data is used.
const {chromium,webkit}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-packing-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 writeFileSync(config,`<?php if(($_SERVER['HTTP_X_ANKH_FIXTURE_AUTH']??'')==='yes'){$_SESSION['admin']=true;$_SESSION['last']=time();}return ['password_hash'=>'fixture-only','database_path'=>'${db}'];`);
 const server=spawn('php',['-d','session.save_path='+temp,'-S','127.0.0.1:18779','-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'}),host='http://127.0.0.1:18779';let browser;
 const sql=code=>execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');${code}`],{encoding:'utf8'});
 try{
  for(let i=0;i<60;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  sql(`$db->exec("INSERT INTO products(id,name,price,stock_qty,stock_tracking,stock_jay_qty) VALUES(99001,'Packing product 10mg',5000,0,1,0);INSERT INTO orders(id,customer,recipient_name,phone,address,notes,status,created,assigned_to,back_order) VALUES(99001,'Fixture Buyer','Envelope Recipient','01234567890','12 Example Street','Keep this order separate','New','2026-11-06','James',0),(99002,'Waiting Buyer','','','34 Example Street','','New','2026-11-06','Tony',1);INSERT INTO items(order_id,product_id,name,price,quantity,presentation) VALUES(99001,99001,'Packing product 10mg',5000,2,'Vial'),(99002,99001,'Packing product 10mg',5000,3,'Vial');");`);
  const type=process.env.ANKH_TEST_BROWSER==='webkit'?webkit:chromium;browser=await type.launch({headless:true,...(process.env.ANKH_CHROMIUM_PATH&&type===chromium?{executablePath:process.env.ANKH_CHROMIUM_PATH}:{}),args:type===chromium?['--no-sandbox']:[]});
  const page=await browser.newPage({viewport:{width:390,height:844},extraHTTPHeaders:{'X-Ankh-Fixture-Auth':'yes'}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const width of [375,390,768,1440]){await page.setViewportSize({width,height:844});await page.goto(host+'/?view=packing_centre');assert(await page.getByRole('heading',{name:'Packing Centre',exact:true}).isVisible());if(!(await page.locator('body').evaluate(el=>el.scrollWidth<=innerWidth+2))){console.log('Overflow',width,await page.locator('body').evaluate(()=>[...document.querySelectorAll('body *')].map(el=>({tag:el.tagName,c:el.className,w:el.getBoundingClientRect().width,right:el.getBoundingClientRect().right})).filter(e=>e.right>innerWidth+2)));await page.screenshot({path:'/tmp/ankh-packing-overflow.png',fullPage:true});throw new Error('No horizontal overflow');}}
  await page.setViewportSize({width:390,height:844});await page.goto(host+'/?view=packing_centre');
  await page.locator('#pc-source').selectOption('back');await page.locator('#pc-select-visible').click();assert.equal(await page.locator('#pc-selected').innerText(),'1 order selected');
  await page.locator('#pc-source').selectOption('active');await page.locator('#pc-select-visible').click();assert.equal(await page.locator('#pc-selected').innerText(),'2 orders selected');
  await page.locator('[name="run_name"]').fill('Friday packing');await page.locator('[name="owner"]').fill('Jay');
  await Promise.all([page.waitForNavigation(),page.locator('#pc-save').click()]);assert(page.url().includes('run=1'));assert(await page.getByRole('heading',{name:'Friday packing',exact:true}).isVisible());
  assert.equal(sql("echo $db->query('SELECT COUNT(*) FROM packing_run_claims')->fetchColumn();"),'2');
  assert.equal(sql("echo $db->query('SELECT SUM(stock_jay_qty) FROM products')->fetchColumn();"),'0');
  await page.screenshot({path:'/tmp/ankh-packing-run-'+type.name()+'.png',fullPage:true});
  // Second admin sees the saved run and cannot select the claimed orders.
  const second=await browser.newPage({extraHTTPHeaders:{'X-Ankh-Fixture-Auth':'yes'}});await second.goto(host+'/?view=packing_centre');assert.equal(await second.locator('[name="order_ids[]"]:disabled').count(),2);assert(await second.getByText('Friday packing',{exact:true}).isVisible());
  for(const kind of ['pick','slips','labels']){await second.goto(host+'/?view=packing_centre&run=1&print='+kind);assert(await second.getByRole('button',{name:'Print / Save as PDF'}).isVisible());assert(await second.getByText('Envelope Recipient',{exact:true}).isVisible());assert.equal(await second.locator('.mobile-nav').count(),0);if(kind==='slips'){assert.equal(await second.locator('.slip').count(),2);assert(await second.getByText('Keep this order separate').isVisible());}if(kind==='pick')assert(await second.getByRole('cell',{name:'5',exact:true}).isVisible());if(type===chromium){await second.emulateMedia({media:'print'});await second.pdf({path:'/tmp/ankh-packing-'+kind+'.pdf',preferCSSPageSize:true});await second.emulateMedia({media:'screen'});}}
  // Edits are visible; releasing an order does not alter its lifecycle.
  sql("$db->exec('UPDATE items SET quantity=4 WHERE order_id=99001');");await page.reload();assert(await page.getByText('Changed since this run was created.',{exact:false}).isVisible());
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Release order'}).first().click()]);assert.equal(sql("echo $db->query('SELECT COUNT(*) FROM packing_run_claims')->fetchColumn();"),'1');
  page.once('dialog',d=>d.accept());await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Finish run',exact:true}).click()]);assert.equal(sql("echo $db->query('SELECT COUNT(*) FROM packing_run_claims')->fetchColumn();"),'0');
  assert.equal(sql("echo $db->query(\"SELECT COUNT(*) FROM orders WHERE status='New' AND delivery_date='' AND reconstituted=0 AND packaged=0\")->fetchColumn();"),'2');assert.deepEqual(errors,[]);
  // Real authentication and CSRF paths: no session or bad token cannot write/print.
  const anon=await browser.newPage();await anon.goto(host+'/?view=packing_centre&run=1&print=slips');assert.equal(await anon.locator('.slip').count(),0);assert(await anon.locator('input[name="password"]').isVisible());
  const result=await page.request.post(host+'/?view=packing_centre',{form:{action:'packing_run_update',run_id:'1',run_action:'cancelled',csrf:'invalid'}});assert((await result.text()).includes('Please refresh the page and try again.'));assert.equal(sql("echo $db->query('SELECT state FROM packing_runs WHERE id=1')->fetchColumn();"),'completed');
  console.log('PASS '+type.name()+': authenticated run creation, cross-admin claims, filters, widths, all print views, stale order warning, release/finish, login/CSRF protection and no lifecycle/stock mutations.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1)});
