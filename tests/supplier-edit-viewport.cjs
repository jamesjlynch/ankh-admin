const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-supplier-edit-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 const hash=execFileSync('php',['-r','echo password_hash("fixture-only",PASSWORD_DEFAULT);'],{encoding:'utf8'}).trim();
 writeFileSync(config,`<?php return ['password_hash'=>'${hash}','database_path'=>'${db}'];`);
 const server=spawn('php',['-S','127.0.0.1:18765','-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});const host='http://127.0.0.1:18765';let browser;
 const sql=code=>execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');${code}`],{encoding:'utf8'});
 try{
  for(let i=0;i<50;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  sql(`$db->exec("INSERT INTO products(id,name,price,cost,supplier_pack_size,stock_tracking,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,stock_qty) VALUES(99001,'Original fixture 10mg',1000,500,10,1,5,0,0,5),(99002,'Missed fixture 5mg',1000,300,5,1,0,0,0,0); INSERT INTO supplier_orders(id,supplier,expected_date,status,created) VALUES(99001,'Fixture supplier','','Part received','2026-10-20'); INSERT INTO supplier_order_items(id,supplier_order_id,product_id,packs,pack_size,quantity,unit_cost,received_qty) VALUES(99001,99001,99001,2,10,20,500,5);");`);
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const width of [375,390,768,1440]){
   await page.setViewportSize({width,height:844});await page.goto(host+'/?view=stock&section=incoming&edit_supplier=99001#supplier-edit-99001');
   assert(await page.getByRole('button',{name:'Save incoming changes'}).isVisible(),'Direct edit link must open both disclosures');
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),width+'px overflow');
  }
  await page.setViewportSize({width:390,height:844});
  const editor=page.locator('#supplier-edit-99001');assert(await editor.locator('select').first().isDisabled(),'Received product identity is locked');
  const extra=editor.locator('[data-supplier-edit-line]').last();await extra.locator('select').selectOption('99002');await extra.locator('input[name$="[packs]"]').fill('2');
  await editor.getByRole('button',{name:'+ Add another product',exact:true}).click();assert.equal(await editor.locator('[data-supplier-edit-line]').count(),3,'Add more than one missed product');
  await editor.locator('[data-supplier-edit-line]').last().getByRole('button',{name:'Remove row'}).click();
  await editor.locator('input[name="note"]').fill('Missing product corrected');
  await editor.screenshot({path:'/tmp/ankh-supplier-edit-mobile.png'});
  await editor.getByRole('button',{name:'Save incoming changes'}).click();
  if(await page.locator('.error').count())throw new Error(await page.locator('.error').innerText());
  await page.waitForURL(url=>url.searchParams.get('view')==='stock'&&url.searchParams.get('section')==='incoming'&&!url.searchParams.has('edit_supplier'));
  assert.equal(sql("echo $db->query('SELECT SUM(quantity-received_qty) FROM supplier_order_items WHERE supplier_order_id=99001')->fetchColumn();"),'25');
  assert.equal(sql("echo $db->query('SELECT received_qty FROM supplier_order_items WHERE id=99001')->fetchColumn();"),'5');
  assert.equal(sql("echo $db->query('SELECT stock_jay_qty FROM products WHERE id=99001')->fetchColumn();"),'5');
  await page.locator('#incoming-by-product > summary').click();
  await page.locator('.incoming-by-product-row').filter({hasText:'Missed fixture 5mg'}).getByRole('link',{name:'Edit incoming order',exact:false}).click();
  assert(await page.getByRole('button',{name:'Save incoming changes'}).isVisible(),'Grouped product link returns to the same order editor');
  assert.deepEqual(errors,[]);console.log('PASS: incoming editor at mobile/desktop widths, direct links, dynamic product rows, real save, receipts and physical stock preservation.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stdout||e);process.exit(1)});
