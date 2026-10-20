// Real mobile forms against an isolated database; never contacts the live site.
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-backorder-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 const hash=execFileSync('php',['-r','echo password_hash("fixture-only",PASSWORD_DEFAULT);'],{encoding:'utf8'}).trim();
 writeFileSync(config,`<?php return ['password_hash'=>'${hash}','database_path'=>'${db}'];`);
 const server=spawn('php',['-S','127.0.0.1:18764','-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});
 const host='http://127.0.0.1:18764';let browser;
 const sql=code=>execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');${code}`]);
 try{
  for(let i=0;i<50;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  sql(`$db->exec("INSERT INTO products(id,name,price,cost,stock_tracking,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,stock_qty) VALUES(99001,'Retatrutide fixture 20mg',10000,1200,1,0,5,0,5); INSERT INTO orders(id,customer,phone,address,notes,created,assigned_to,back_order,back_order_since) VALUES(99001,'Waiting customer fixture','','','','2026-10-19T10:00:00Z','James',1,'2026-10-19T10:00:00Z'); INSERT INTO items(order_id,product_id,name,price,quantity) VALUES(99001,99001,'Retatrutide fixture 20mg',10000,2);");`);
  sql(`$db->exec("UPDATE items SET presentation='Pen' WHERE order_id=99001; INSERT INTO products(id,name,price,cost,stock_tracking,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,stock_qty) VALUES(99002,'ARA-290 10mg',5000,1200,1,0,0,0,0),(99003,'Pen',1500,300,1,0,0,0,0); INSERT INTO items(order_id,product_id,name,price,quantity,presentation) VALUES(99001,99002,'ARA-290 10mg',5000,1,'Vial'),(99001,99003,'Pen',1500,3,''); INSERT INTO supplier_orders(id,supplier,expected_date,status,created) VALUES(99001,'Fixture supplier','2026-10-25','Part received','2026-10-19'); INSERT INTO supplier_order_items(supplier_order_id,product_id,quantity,received_qty) VALUES(99001,99001,10,2),(99001,99002,1,0),(99001,99003,1,0);");`);
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const width of [375,390,768,1440]){
   await page.setViewportSize({width,height:844});await page.goto(host+'/?view=orders&section=backorders');
   assert(await page.getByRole('heading',{name:'Waiting customer fixture'}).count());
   assert.equal(await page.locator('.order-view-nav a').count(),6);
   assert.equal(await page.locator('.backorder-income strong').innerText(),'£295.00');
   assert(await page.locator('.backorder-income strong').evaluate(el=>el.scrollWidth<=el.clientWidth),'Expected income must fit on mobile');
   assert.equal(await page.locator('.order-view-nav').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length),3,'Order navigation must have three clear columns');
   assert.equal(await page.locator('.order-view-nav').evaluate(el=>getComputedStyle(el).position),'static','Filters must sit at the top, not over the bottom app bar');
   assert(await page.locator('.order-view-nav').evaluate(el=>el.getBoundingClientRect().bottom<400),'Order views must be visible near the top');
   assert(await page.locator('.backorder-product').first().isVisible(),'Product totals must be visible without a disclosure');
   assert.equal(await page.locator('[data-backorder-product="99001"]').getAttribute('data-needed'),'2');

   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),width+'px horizontal overflow');
  }
  await page.goto(host+'/?view=stock&section=incoming');
  assert.equal(await page.locator('.incoming-backorder-demand').count(),3,'Incoming stock must link back to actual waiting demand');
  await page.goto(host+'/?view=orders&section=backorders');
  await page.setViewportSize({width:390,height:844});
  await page.screenshot({path:'/tmp/ankh-backorders-redesign.png',fullPage:true});
  await page.locator('.order-list .order > summary').click();
  const release=page.getByRole('button',{name:'Move to active orders'});assert(await release.isDisabled());
  await page.screenshot({path:'/tmp/ankh-backorders-mobile.png',fullPage:true});
  sql(`$db->exec('UPDATE products SET stock_jay_qty=3,stock_qty=8 WHERE id=99001; UPDATE products SET stock_jay_qty=1,stock_qty=1 WHERE id=99002; UPDATE products SET stock_jay_qty=3,stock_qty=3 WHERE id=99003');`);
  await page.reload();await page.locator('.order-list .order > summary').click();assert(await release.isEnabled());
  page.once('dialog',d=>d.accept());await release.click();await page.waitForURL('**/?view=orders');
  assert(await page.getByRole('heading',{name:'Waiting customer fixture'}).count());
  assert.equal(sql("echo $db->query('SELECT stock_jay_qty FROM products WHERE id=99001')->fetchColumn();").toString(),'1');
  await page.goto(host+'/?view=orders&section=backorders');assert.equal(await page.locator('.order-list .order').count(),0);
  await page.goto(host+'/?view=edit&id=99001');
  assert(await page.getByRole('button',{name:'Move to back orders'}).isVisible(),'Edit order must expose the back-order action');
  assert.equal(await page.locator('#edit-order-form input[name="action"][value="order_backorder"]').count(),0,'Move action must have its own form');
  await page.goto(host+'/?view=dashboard');await page.locator('#work-order-99001 > summary').click();
  const dashboardMove=page.locator('#focus-sheet-body').getByRole('button',{name:'Move to back orders'});
  await dashboardMove.waitFor({state:'visible'});page.once('dialog',d=>d.accept());await dashboardMove.click();
  await page.waitForURL('**/?view=orders&section=backorders');
  assert.equal(sql("echo $db->query('SELECT back_order FROM orders WHERE id=99001')->fetchColumn();").toString(),'1');
  assert.equal(sql("echo $db->query('SELECT stock_jay_qty FROM products WHERE id=99001')->fetchColumn();").toString(),'3');
  sql(`$db->exec("INSERT INTO orders(id,customer,phone,address,notes,status,created,delivery_date) VALUES(99004,'Paid value layout fixture','','','','Delivered','2026-10-19','2026-10-19'); INSERT INTO items(order_id,product_id,name,price,quantity) VALUES(99004,99001,'Retatrutide fixture 20mg',549694,1);");`);
  await page.goto(host+'/?view=orders');
  assert(await page.locator('.orders-summary-stats article:nth-child(2) strong').evaluate(el=>el.scrollWidth<=el.clientWidth),'Paid value must stay on one line without overflow');
  await page.screenshot({path:'/tmp/ankh-orders-redesign.png'});
  await page.goto(host+'/?view=new');const checkbox=page.locator('input[name="back_order"]');assert(!(await checkbox.isChecked()));
  assert.deepEqual(errors,[]);console.log('PASS: mobile/desktop waiting list, no overflow, assigned-stock guard, real activation form, no duplicate orders, active default.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stdout?.toString()||e);process.exit(1)});
