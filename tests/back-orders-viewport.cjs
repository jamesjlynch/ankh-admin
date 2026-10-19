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
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const width of [375,390,768,1440]){
   await page.setViewportSize({width,height:844});await page.goto(host+'/?view=orders&section=backorders');
   assert(await page.getByRole('heading',{name:'Waiting customer fixture'}).count());
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),width+'px horizontal overflow');
  }
  await page.setViewportSize({width:390,height:844});
  await page.locator('.order-list .order > summary').click();
  const release=page.getByRole('button',{name:'Move to active orders'});assert(await release.isDisabled());
  await page.screenshot({path:'/tmp/ankh-backorders-mobile.png',fullPage:true});
  sql(`$db->exec('UPDATE products SET stock_jay_qty=3,stock_qty=8 WHERE id=99001');`);
  await page.reload();await page.locator('.order-list .order > summary').click();assert(await release.isEnabled());
  page.once('dialog',d=>d.accept());await release.click();await page.waitForURL('**/?view=orders');
  assert(await page.getByRole('heading',{name:'Waiting customer fixture'}).count());
  assert.equal(sql("echo $db->query('SELECT stock_jay_qty FROM products WHERE id=99001')->fetchColumn();").toString(),'1');
  await page.goto(host+'/?view=orders&section=backorders');assert.equal(await page.locator('.order-list .order').count(),0);
  await page.goto(host+'/?view=new');const checkbox=page.locator('input[name="back_order"]');assert(!(await checkbox.isChecked()));
  assert.deepEqual(errors,[]);console.log('PASS: mobile/desktop waiting list, no overflow, assigned-stock guard, real activation form, no duplicate orders, active default.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stdout?.toString()||e);process.exit(1)});
