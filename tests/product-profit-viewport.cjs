// Monthly product profit layout and month navigation; isolated fixture only.
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-product-profit-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 writeFileSync(config,`<?php $_SESSION['admin']=true;$_SESSION['last']=time();return ['password_hash'=>'fixture-only','database_path'=>'${db}'];`);
 const port=await new Promise((resolvePort,reject)=>{const s=require('node:net').createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolvePort(p));});});
 const host='http://127.0.0.1:'+port;
 const server=spawn('php',['-d','session.save_path='+temp,'-S','127.0.0.1:'+port,'-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});
 let browser;
 const sql=code=>execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');${code}`],{encoding:'utf8'});
 try{
  for(let i=0;i<60;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  sql(`$db->exec("INSERT INTO orders(id,customer,recipient_name,phone,address,notes,status,created,pricing_type,back_order,payment_date) VALUES(99001,'March buyer','','','','','Delivered','2025-03-05T12:00:00Z','standard',0,''),(99002,'March paid','','','','','Paid','2025-03-20T12:00:00Z','standard',0,'2025-03-20'),(99003,'April buyer','','','','','Packed','2025-04-02T12:00:00Z','standard',0,''); INSERT INTO items(order_id,name,price,quantity,cost,presentation_cost) VALUES(99001,'Fixture Alpha',1000,2,400,100),(99002,'Fixture Alpha',1000,3,400,100),(99002,'Fixture Beta',2000,1,NULL,0),(99003,'Fixture Gamma',500,4,200,0),(99003,'Fixture Alpha',1000,1,400,0);");`);
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const context=await browser.newContext({viewport:{width:390,height:844}});
  await context.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.hostname==='fonts.googleapis.com')return route.fulfill({status:200,contentType:'text/css',body:''});
   if(url.hostname==='fonts.gstatic.com')return route.fulfill({status:200,contentType:'font/woff2',body:''});
   return route.continue();
  });
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const march=host+'/?view=reports&month=2025-03';
  for(const width of [375,390,430]){
   await page.setViewportSize({width,height:844});await page.goto(march);await page.locator('[data-product-profit]').waitFor();
   assert.equal(await page.locator('[data-product-profit-row]').count(),2,width+'px: two March product rows');
   assert.equal(await page.locator('[data-product-profit-total]').count(),1,width+'px: totals row');
   assert(await page.locator('[data-product-profit]').getByText('cost missing',{exact:true}).isVisible(),width+'px: missing cost is flagged');
   const metrics=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
   assert(metrics.scrollWidth<=metrics.width+2,width+'px: no horizontal page overflow '+JSON.stringify(metrics));
   const layout=await page.evaluate(()=>{
    const table=document.querySelector('.product-profit-table'),rows=[...document.querySelectorAll('.product-profit-row')],total=document.querySelector('.product-profit-total');
    return {table:getComputedStyle(table).display,row:getComputedStyle(rows[0]).display,total:getComputedStyle(total).display,rowOverflow:rows.some(r=>r.scrollWidth>r.clientWidth+1),width:innerWidth};
   });
   assert.equal(layout.table,'block',width+'px: table uses card layout');
   assert.equal(layout.row,'block',width+'px: product rows are cards');
   assert.equal(layout.total,'block',width+'px: totals row is a card');
   assert(!layout.rowOverflow,width+'px: product cards do not overflow');
   const bars=await page.locator('.product-profit-bar').evaluateAll(nodes=>nodes.map(n=>{const i=n.querySelector('i'),r=n.getBoundingClientRect(),ir=i?.getBoundingClientRect();return {track:r.width,fill:ir?.width||0,left:r.left,right:r.right,viewport:innerWidth};}));
   assert(bars.length>=3,width+'px: bars render for each row and total');
   for(const bar of bars){assert(bar.fill<=bar.track+1,width+'px: bar fill stays inside its track');assert(bar.right<=bar.viewport+1&&bar.left>=-1,width+'px: bar stays inside viewport');}
   const arrows=await page.locator('.product-profit-month-arrow').evaluateAll(nodes=>nodes.map(n=>n.getBoundingClientRect().height));
   assert.deepEqual(arrows.map(h=>h>=44),[true,true],width+'px: month arrows are 44px tall');
   assert.equal(await page.locator('.product-profit-month-form input[name="month"]').inputValue(),'2025-03');
  }
  await page.setViewportSize({width:1024,height:900});await page.goto(march);await page.locator('[data-product-profit]').waitFor();
  const desktop=await page.evaluate(()=>({table:getComputedStyle(document.querySelector('.product-profit-table')).display,row:getComputedStyle(document.querySelector('.product-profit-row')).display,scrollWidth:document.documentElement.scrollWidth,width:innerWidth}));
  assert.equal(desktop.table,'table','Desktop keeps a real table');
  assert.equal(desktop.row,'table-row','Desktop keeps table rows');
  assert(desktop.scrollWidth<=desktop.width+2,'Desktop has no horizontal overflow');
  await page.getByRole('link',{name:/Next month/}).click();await page.waitForURL('**month=2025-04**');
  assert.equal(await page.locator('.product-profit-month-form input[name="month"]').inputValue(),'2025-04');
  assert(await page.locator('[data-product-profit]').getByText('Fixture Gamma',{exact:true}).isVisible(),'Next month shows April products');
  await page.locator('.product-profit-month-form input[name="month"]').fill('2025-03');await Promise.all([page.waitForNavigation(),page.locator('.product-profit-month-form').getByRole('button',{name:'View',exact:true}).click()]);
  assert.equal(new URL(page.url()).searchParams.get('month'),'2025-03','Month picker keeps the chosen month');
  assert(await page.locator('[data-product-profit]').getByText('Fixture Beta',{exact:true}).isVisible(),'Month picker returns to March products');
  assert.deepEqual(errors,[],'Product profit screen console errors: '+errors.join(' | '));
  console.log('PASS: monthly product profit at 375/390/430px uses non-overflowing cards, a desktop table, profit bars, missing-cost flag and prev/next month navigation.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stdout?.toString()||e);process.exit(1)});
