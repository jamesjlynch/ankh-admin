// Authenticated order-detail fixture only; never creates or changes production orders.
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-order-detail-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 writeFileSync(config,`<?php $_SESSION['admin']=true;$_SESSION['last']=time();return ['password_hash'=>'fixture-only','database_path'=>'${db}'];`);
 const server=spawn('php',['-d','session.save_path='+temp,'-S','127.0.0.1:18769','-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});const host='http://127.0.0.1:18769';let browser;
 const sql=code=>execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');${code}`],{encoding:'utf8'});
 try{
  for(let i=0;i<60;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  sql(`$db->exec("INSERT INTO products(id,name,price,cost,stock_tracking,stock_tracking_since,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,stock_qty) VALUES(99101,'Detail fixture product',5000,1000,1,'',6,6,0,12);INSERT INTO orders(id,customer,phone,address,notes,status,created,assigned_to,delivery_charge,delivery_method) VALUES(99101,'Order detail fixture','07700900000','12 Test Street\\nLondon','Leave with neighbour','New','2026-10-02','James',500,'Local Delivery'),(99102,'Backorder detail fixture','07700900001','','','New','2026-10-01','James',0,'Local Delivery');UPDATE orders SET back_order=1,back_order_since='2026-10-01' WHERE id=99102;INSERT INTO items(order_id,product_id,name,price,base_price,quantity,presentation) VALUES(99101,99101,'Detail fixture product',5000,6000,2,'Vial'),(99102,99101,'Detail fixture product',5000,5000,1,'Vial');");`);
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const activeCard=page.locator('.order-list .order').filter({has:page.getByRole('heading',{name:'Order detail fixture',exact:true})});
  const waitingCard=page.locator('.order-list .order').filter({has:page.getByRole('heading',{name:'Backorder detail fixture',exact:true})});
  await page.setViewportSize({width:390,height:844});await page.goto(host+'/?view=orders');
  await activeCard.locator(':scope > summary').click();
  assert(await activeCard.locator('.od-primary-payment > summary').isVisible(),'Record payment must be a visible primary action');
  assert(await activeCard.locator('.od-primary-deliver > summary').isVisible(),'Mark delivered must be a visible primary action');
  assert.equal(await activeCard.locator('.od-backorder-move button').isVisible(),false,'Move to back orders must stay inside More actions');
  assert(await activeCard.locator('.od-items').getByText('£105.00',{exact:true}).first().isVisible(),'Order total should include delivery');
  await activeCard.locator('.od-header').scrollIntoViewIfNeeded();
  await page.screenshot({path:'/tmp/od-390.png'});
  await activeCard.locator('.od-more > summary').click();
  assert(await activeCard.getByRole('button',{name:'Move to back orders',exact:true}).isVisible(),'More actions must reveal Move to back orders');
  await activeCard.locator('.od-more').scrollIntoViewIfNeeded();
  await page.screenshot({path:'/tmp/od-more-390.png'});
  for(const width of [375,390,430]){
   await page.setViewportSize({width,height:844});await page.goto(host+'/?view=orders');
   await activeCard.locator(':scope > summary').click();
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),width+'px order detail horizontal overflow');
   const steps=activeCard.locator('.od-steps');
   assert.equal(await steps.count(),0,width+'px Orders page must not show quick steps (Today only, James 2026-11-08)');
   continue;
   assert.equal(await steps.locator('.od-step').count(),4,width+'px order quick steps need 4 tiles');
   assert.equal(await steps.locator('svg').count(),4,width+'px order quick steps need 4 icons');
   assert.equal(await steps.locator('.od-step-hint').count(),0,width+'px order quick steps must not show helper text');
   assert.equal((await steps.innerText()).includes('Reconstitute first'),false,width+'px order quick steps must not show helper text');
   const stepMetrics=await steps.evaluate(root=>[...root.querySelectorAll('.od-step')].map(tile=>{
    const label=tile.querySelector('.od-step-label'),style=getComputedStyle(tile),labelStyle=label?getComputedStyle(label):null,box=tile.getBoundingClientRect(),labelBox=label?label.getBoundingClientRect():null;
    return {done:tile.classList.contains('done'),backgroundImage:style.backgroundImage,borderStyle:style.borderTopStyle,width:box.width,height:box.height,top:box.top,disabled:tile.disabled,ariaDisabled:tile.getAttribute('aria-disabled'),opacity:parseFloat(style.opacity),labelOverflow:label?label.scrollWidth-label.clientWidth:0,labelFontSize:labelStyle?parseFloat(labelStyle.fontSize):0,labelLeft:labelBox?labelBox.left:0,labelRight:labelBox?labelBox.right:0,tileLeft:box.left,tileRight:box.right};
   }));
   for(const metric of stepMetrics){
    assert(metric.labelOverflow<=0,width+'px order quick step label overflows its tile');
    assert(metric.labelLeft>=metric.tileLeft-.5&&metric.labelRight<=metric.tileRight+.5,width+'px order quick step label leaves its tile');
    assert(metric.labelFontSize>=11.5,width+'px order quick step label below 11.5px');
    assert(metric.width>=44&&metric.height>=44,width+'px order quick step tap target below 44px');
    assert.equal(metric.borderStyle,'solid',width+'px order quick step border missing');
    if(!metric.done)assert.equal(metric.backgroundImage,'none',width+'px not-done quick step must not use a gradient');
   }
   assert(stepMetrics.every(metric=>Math.abs(metric.top-stepMetrics[0].top)<1),width+'px order quick steps must stay on one row');
   const lockedStep=stepMetrics.find(metric=>metric.disabled);
   assert(lockedStep,width+'px packaged quick step must stay locked before reconstituting');
   assert.equal(lockedStep.ariaDisabled,'true',width+'px locked quick step needs aria-disabled');
   assert(lockedStep.opacity<=.5,width+'px locked quick step must be dimmed');
   if(width===375){await activeCard.locator('.od-header').scrollIntoViewIfNeeded();await page.screenshot({path:'/tmp/od-375.png'});}
  }
  // Today opens the same order detail in a bottom sheet outside #ankh-main: the tiles must keep their styling there.
  for(const width of [375,390,430]){
   await page.setViewportSize({width,height:844});await page.goto(host+'/?view=dashboard');
   const card=page.locator('.focus-worklist details.focus-order').first();
   if(!(await card.count()))throw new Error('Today worklist fixture order missing');
   assert(await card.locator('.worklist-stage').isVisible(),width+'px Today card must show its stage');
   assert.equal(await card.locator('.worklist-stage-track li').count(),3,width+'px stage track needs 3 steps');
   const stageBox=await card.locator('.worklist-stage').boundingBox(),cardBox2=await card.boundingBox();
   assert(stageBox.x+stageBox.width<=cardBox2.x+cardBox2.width+.5,width+'px stage track leaves the card');
   await card.locator(':scope > summary').click();
   const sheet=page.locator('#focus-action-sheet');
   await sheet.locator('.od-steps').waitFor({state:'visible',timeout:5000});
   const m=await sheet.evaluate(d=>{const r=d.getBoundingClientRect();return {sheet:{left:r.left,right:r.right},tiles:[...d.querySelectorAll('.od-steps .od-step')].map(t=>{const b=t.getBoundingClientRect(),svg=t.querySelector('svg').getBoundingClientRect(),st=getComputedStyle(t);return {left:b.left,right:b.right,top:b.top,height:b.height,svgW:svg.width,border:st.borderTopStyle}})};});
   assert.equal(m.tiles.length,4,width+'px sheet needs 4 step tiles');
   for(const t of m.tiles){
    assert(t.svgW<=24,width+'px sheet step icon is unstyled ('+t.svgW+'px)');
    assert.equal(t.border,'solid',width+'px sheet step tile border missing');
    assert(t.left>=m.sheet.left-.5&&t.right<=m.sheet.right+.5,width+'px sheet step tile leaves the sheet');
    assert(t.height<=90,width+'px sheet step tile too tall');
   }
   assert(m.tiles.every(t=>Math.abs(t.top-m.tiles[0].top)<1),width+'px sheet steps must stay on one row');
  }
  // After an in-place step in the sheet, closing and opening another order must show that order, not the last one.
  {
   await page.setViewportSize({width:390,height:844});await page.goto(host+'/?view=dashboard');
   const cards=page.locator('.focus-worklist details.focus-order');
   const first=cards.first();const firstId=await first.getAttribute('data-order-id');
   await first.locator(':scope > summary').click();
   const sheet=page.locator('#focus-action-sheet');
   await sheet.locator('.od-steps').waitFor({state:'visible',timeout:5000});
   const stepBtn=sheet.locator('.od-steps form button:not([disabled])').first();
   if(await stepBtn.count()){
    const done=page.waitForResponse(r=>r.request().method()==='POST',{timeout:10000}).catch(()=>null);
    await stepBtn.click();await done;await page.waitForTimeout(600);
   }
   assert(await sheet.evaluate(d=>d.open),'sheet should stay open after an in-place step');
   await page.locator('#focus-sheet-close').click();
   await page.waitForFunction(()=>!document.getElementById('focus-action-sheet').open);
   assert.equal(await page.locator('#focus-sheet-body > *').count(),0,'sheet body must be empty after closing');
   const counts=await page.evaluate(()=>[...document.querySelectorAll('.focus-worklist details.focus-order')].map(c=>c.querySelectorAll(':scope > .focus-sheet-content').length));
   assert(counts.every(n=>n===1),'every worklist card must own exactly one sheet content after an in-place step: '+JSON.stringify(counts));
   if(await cards.count()>1){
    const second=cards.nth(1);const secondId=await second.getAttribute('data-order-id');
    await second.locator(':scope > summary').click();
    await sheet.locator('.focus-sheet-content').first().waitFor({state:'visible',timeout:5000});
    assert.equal(await page.locator('#focus-sheet-body > .focus-sheet-content').count(),1,'sheet must hold one order only');
    const shownIds=await page.locator('#focus-sheet-body input[name="id"]').evaluateAll(els=>[...new Set(els.map(e=>e.value))]);
    assert.deepEqual(shownIds,[secondId],'sheet shows the wrong order (expected '+secondId+', first was '+firstId+')');
    await page.locator('#focus-sheet-close').click();
   }
  }
  await page.setViewportSize({width:390,height:844});await page.goto(host+'/?view=orders&section=backorders');
  await waitingCard.locator(':scope > summary').click();
  const banner=waitingCard.locator('.od-backorder-banner');
  assert(await banner.isVisible(),'A waiting order must show its back-order banner');
  const bannerText=await banner.innerText();
  assert(bannerText.includes('Back order ·')&&/day(?:s)? waiting/.test(bannerText),'Banner must show the waiting time');
  assert(/Stock available|stock count needed|available \/|No products recorded/.test(bannerText),'Banner must show stock availability or issues');
  assert(await waitingCard.locator('.od-backorder-activate > summary').isVisible(),'Move to active must be visible');
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'390px back-order detail horizontal overflow');
  await banner.scrollIntoViewIfNeeded();
  await page.screenshot({path:'/tmp/od-backorder-390.png'});
  await waitingCard.locator('.od-backorder-activate > summary').click();
  assert(await page.locator('select[name="activation_assigned_to"]').isVisible(),'Move to active must open the existing location form');
  assert.deepEqual(errors,[]);
  console.log('PASS: order detail primary actions, collapsed back-order action, back-order banner, phone widths and real activation form.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stdout?.toString()||e);process.exit(1)});
