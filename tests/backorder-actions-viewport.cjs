// Authenticated fixture only; never creates or changes production orders.
const {chromium,webkit}=require('playwright');
const browserType=process.env.ANKH_TEST_BROWSER==='webkit'?webkit:chromium;
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-back-actions-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 writeFileSync(config,`<?php $_SESSION['admin']=true;$_SESSION['last']=time();return ['password_hash'=>'fixture-only','database_path'=>'${db}'];`);
 const server=spawn('php',['-d','session.save_path='+temp,'-S','127.0.0.1:18768','-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});const host='http://127.0.0.1:18768';let browser;
 const sql=code=>execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');${code}`],{encoding:'utf8'});
 try{
  for(let i=0;i<60;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  sql(`$db->exec("INSERT INTO products(id,name,price,cost,stock_tracking,stock_tracking_since,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,stock_qty) VALUES(99001,'Workflow browser product',5000,1000,1,'',0,5,0,5);INSERT INTO orders(id,customer,phone,address,notes,status,created,assigned_to,back_order,back_order_since) VALUES(99001,'Workflow browser waiting','','','','New','2026-10-01','James',1,'2026-10-01'),(99002,'Delete browser waiting','','','','New','2026-10-02','James',1,'2026-10-02');INSERT INTO items(order_id,product_id,name,price,quantity) VALUES(99001,99001,'Workflow browser product',5000,2),(99002,99001,'Workflow browser product',5000,1);");`);
  browser=await browserType.launch({headless:true,args:browserType===chromium?['--no-sandbox']:[]});const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const route=host+'/?view=orders&section=backorders';const card=page.locator('.order-list .order').filter({has:page.getByRole('heading',{name:'Workflow browser waiting',exact:true})});
  for(const width of [320,375,390,430,768,1440]){
   await page.setViewportSize({width,height:844});await page.goto(route);await card.locator(':scope > summary').click();assert(await card.getByRole('button',{name:'Move to active orders',exact:true}).isEnabled());assert.equal(await card.locator('select[name="activation_assigned_to"]').inputValue(),'James');assert(await card.locator('.backorder-payment>summary').isVisible());assert(await page.locator('body').evaluate(el=>el.scrollWidth<=innerWidth+2));
  }
  // Page tabs must fit their own grid and never inherit app-bar positioning.
  await page.setViewportSize({width:390,height:844});
  for(const view of ['dashboard','orders','customers','products','stock','reports','more','packing_centre','new']){
   await page.goto(host+'/?view='+view);
   assert(await page.locator('#ankh-main nav').evaluateAll(nodes=>nodes.every(n=>getComputedStyle(n).position!=='fixed')),'Page navigation stays in the scrolling content: '+view);
   const navBox=await page.locator('.mobile-nav').boundingBox();
   for(const label of await page.locator('.mobile-nav .nav-label').all()){
    const rect=await label.boundingBox();assert(rect.x>=-1&&rect.x+rect.width<=391&&rect.y+rect.height<=navBox.y+navBox.height+1,'Bottom menu labels fit inside the bar: '+view);
   }
   if(view==='orders'){
    assert(await page.locator('.order-view-nav').evaluate(nav=>[...nav.children].every(link=>{const a=link.getBoundingClientRect(),b=nav.getBoundingClientRect();return a.left>=b.left-1&&a.right<=b.right+1&&link.scrollWidth<=link.clientWidth+1;})),'Every Orders tab and Packing Centre link must fit');
    await page.screenshot({path:'/tmp/ankh-navigation-orders-'+browserType.name()+'.png'});
   }
   if(view==='dashboard')assert.equal(await page.locator('.ux-mobile-actions').isVisible(),false,'No duplicate dashboard menu');
  }
  // The bottom row stays at the viewport edge while only main scrolls, including resize.
  for(const height of [844,600,844]){
   await page.setViewportSize({width:390,height});await page.goto(route);
   for(const top of [0,900,100]){
    await page.locator('#ankh-main').evaluate((el,top)=>el.scrollTop=top,top);
    const box=await page.locator('.mobile-nav').boundingBox();const main=await page.locator('#ankh-main').boundingBox();
    const gap=height-(box.y+box.height);assert(gap>-2&&gap<12,'Menu must stay at the bottom after scrolling/resizing (floating bar sits 10px above the edge)');
    assert(main.y+main.height<=box.y+1,'Content must stop above the menu');
   }
  }
  await card.locator(':scope > summary').click();
  await card.getByRole('button',{name:'Move to active orders',exact:true}).scrollIntoViewIfNeeded();
  await page.screenshot({path:'/tmp/ankh-manual-back-orders-'+browserType.name()+'.png'});
  assert.equal(await card.locator('select[name="activation_assigned_to"] option:disabled').count(),0,'All locations remain selectable at zero stock');
  // Unpaid and zero-stock activation uses the real form, then reverses without duplication.
  page.once('dialog',d=>d.accept());await Promise.all([page.waitForNavigation(),card.getByRole('button',{name:'Move to active orders',exact:true}).click()]);
  assert.equal(sql("echo $db->query('SELECT stock_jay_qty FROM products WHERE id=99001')->fetchColumn();"),'-2');
  await page.goto(host+'/?view=edit&id=99001');page.once('dialog',d=>d.accept());await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Move to back orders',exact:true}).click()]);
  assert.equal(sql("echo $db->query('SELECT stock_jay_qty FROM products WHERE id=99001')->fetchColumn();"),'0');
  await card.locator(':scope > summary').click();
  await page.setViewportSize({width:390,height:844});await card.locator('.backorder-payment>summary').click();await card.locator('.backorder-payment select[name="method"]').selectOption('Bank Transfer');await Promise.all([page.waitForNavigation(),card.getByRole('button',{name:'Save payment',exact:true}).click()]);
  assert.equal(sql("echo $db->query('SELECT SUM(amount) FROM payments WHERE order_id=99001')->fetchColumn();"),'10000');assert.equal(sql("echo $db->query('SELECT back_order FROM orders WHERE id=99001')->fetchColumn();"),'1');assert.equal(sql("echo $db->query('SELECT stock_tony_qty FROM products WHERE id=99001')->fetchColumn();"),'5');
  await card.locator(':scope > summary').click();assert(await card.getByText('Paid in full · on back orders',{exact:true}).isVisible());assert.equal(await card.getByRole('button',{name:'Delete back order',exact:true}).count(),0);
  await card.locator('select[name="activation_assigned_to"]').selectOption('Tony');page.once('dialog',d=>d.accept());await Promise.all([page.waitForNavigation(),card.getByRole('button',{name:'Move to active orders',exact:true}).click()]);assert.equal(sql("echo $db->query('SELECT assigned_to FROM orders WHERE id=99001')->fetchColumn();"),'Tony');assert.equal(sql("echo $db->query('SELECT stock_tony_qty FROM products WHERE id=99001')->fetchColumn();"),'3');
  await page.goto(host+'/?view=edit&id=99001');page.once('dialog',d=>d.accept());await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Move to back orders',exact:true}).click()]);await card.locator(':scope > summary').click();await card.locator('.backorder-removal>summary').click();page.once('dialog',d=>d.accept());await Promise.all([page.waitForNavigation(),card.getByRole('button',{name:'Cancel back order',exact:true}).click()]);assert.equal(await card.count(),0);assert.equal(sql("echo $db->query('SELECT SUM(amount) FROM payments WHERE order_id=99001')->fetchColumn();"),'10000');
  const unpaid=page.locator('.order-list .order').filter({has:page.getByRole('heading',{name:'Delete browser waiting',exact:true})});await unpaid.locator(':scope > summary').click();await unpaid.locator('.backorder-removal>summary').click();page.once('dialog',d=>d.accept());await Promise.all([page.waitForNavigation(),unpaid.getByRole('button',{name:'Delete back order',exact:true}).click()]);assert.equal(sql("echo $db->query('SELECT COUNT(*) FROM orders WHERE id=99002')->fetchColumn();"),'0');
  await page.goto(host+'/?view=new');const requestKey=await page.locator('[name="order_request_key"]').inputValue();
  await page.evaluate(key=>localStorage.setItem('ankh-order-draft-v2',JSON.stringify({step:3,customer:'New waiting draft fixture',order_date:new Date().toISOString().slice(0,10),delivery_method:'Local Delivery',assigned_to:'James',order_request_key:key,lines:[{product_id:99001,quantity:1,presentation:'Vial',base_price:50,price:50,recurring:false}]})),requestKey);
  await page.reload();assert.equal(await page.locator('#customer-search').inputValue(),'New waiting draft fixture');assert.equal(await page.locator('[name="order_request_key"]').inputValue(),requestKey);await page.locator('.back-order-option>summary').click();await page.locator('input[name="back_order"]').check();await Promise.all([page.waitForNavigation(),page.locator('#order-wizard .save-order').click()]);
  assert.equal(new URL(page.url()).searchParams.get('section'),'backorders');assert.equal(await page.evaluate(()=>localStorage.getItem('ankh-order-draft-v2')),null,'Saving to back orders must clear the completed draft');await page.goto(host+'/?view=new');assert.equal(await page.locator('#customer-search').inputValue(),'','Next order must not restore the previous saved back order');assert.notEqual(await page.locator('[name="order_request_key"]').inputValue(),requestKey);assert.deepEqual(errors,[]);console.log('PASS: authenticated mobile/desktop waiting actions, stable bottom navigation, zero-stock unpaid/manual activation, payments, cancellation/deletion and clearing saved back-order drafts.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stdout?.toString()||e);process.exit(1)});
