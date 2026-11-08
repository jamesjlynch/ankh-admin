// Real authenticated HTTP fixture for the rebuilt Products list. No live data is used.
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-products-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 writeFileSync(config,`<?php if(($_SERVER['HTTP_X_ANKH_FIXTURE_AUTH']??'')==='yes'){$_SESSION['admin']=true;$_SESSION['last']=time();}return ['password_hash'=>'fixture-only','database_path'=>'${db}'];`);
 const port=await new Promise((resolvePort,reject)=>{const s=require('node:net').createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolvePort(p));});});
 const host='http://127.0.0.1:'+port;
 const server=spawn('php',['-d','session.save_path='+temp,'-S','127.0.0.1:'+port,'-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});
 let browser;
 const sql=code=>execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');${code}`],{encoding:'utf8'});
 try{
  for(let i=0;i<60;i++){try{if((await fetch(host+'/?view=products',{headers:{'X-Ankh-Fixture-Auth':'yes'}})).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  sql(`$db->exec("INSERT INTO products(id,name,price,cost,family_friends_price,stock_tracking,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,stock_qty,low_stock_at,supplier_pack_size,active) VALUES(99001,'Alpha Cream 5mg',5000,1000,4000,1,2,1,1,4,5,10,1),(99002,'Beta Serum 10ml',3000,800,2500,1,20,10,5,35,5,10,1),(99003,'Gamma Oil',2000,500,1500,0,0,0,0,NULL,5,10,1),(99004,'Delta Hidden 5mg',1000,200,800,0,0,0,0,NULL,5,10,0),(99005,'UltraLongProductNameWithoutAnySpacesForOverflowTesting1234567890',1200,400,900,0,0,0,0,NULL,5,10,1);INSERT INTO customer_catalogue_visibility(product_id,visible,updated_at) VALUES(99001,1,'2026-01-01'),(99002,1,'2026-01-01'),(99003,0,'2026-01-01'),(99004,0,'2026-01-01'),(99005,1,'2026-01-01');");`);
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const page=await browser.newPage({viewport:{width:390,height:844},extraHTTPHeaders:{'X-Ankh-Fixture-Auth':'yes'}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const fixtureNames=['Alpha Cream 5mg','Beta Serum 10ml','Gamma Oil','Delta Hidden 5mg','UltraLongProductNameWithoutAnySpacesForOverflowTesting1234567890'];
  const visibleRows=()=>page.locator('.products-row').evaluateAll(nodes=>nodes.filter(node=>!node.hidden).map(node=>node.querySelector('.products-row-title').textContent.trim()));
  const visibleFixtures=async()=>{const out=[];for(const name of fixtureNames){const row=page.locator('.products-row',{hasText:name});if(!(await row.evaluate(node=>node.hidden)))out.push(name);}return out;};
  await page.goto(host+'/?view=products');
  for(const name of fixtureNames)assert.equal(await page.locator('.products-row',{hasText:name}).count(),1,'Missing list row for '+name);
  assert.deepEqual(await visibleFixtures(),fixtureNames,'All chip shows every product');
  // Instant client-side name search.
  await page.locator('#products-search').fill('beta serum');
  assert.deepEqual(await visibleFixtures(),['Beta Serum 10ml'],'Search filters by product name');
  await page.locator('#products-search').fill('cream 5mg');
  assert.deepEqual(await visibleFixtures(),['Alpha Cream 5mg'],'Search matches words inside longer names');
  await page.locator('#products-search').fill('nothing-here');
  assert.equal((await visibleRows()).length,0,'Search can hide every row');
  assert(await page.locator('#products-no-results').isVisible(),'Empty search state is visible');
  await page.locator('#products-search').fill('');
  // Filter chips.
  await page.locator('[data-products-filter="low"]').click();
  assert.deepEqual(await visibleFixtures(),['Alpha Cream 5mg'],'Low-stock chip shows only products at or below their threshold');
  assert.equal(await page.locator('[data-products-filter="low"]').getAttribute('aria-pressed'),'true','Active chip is announced');
  await page.locator('[data-products-filter="hidden"]').click();
  assert.deepEqual(await visibleFixtures(),['Gamma Oil','Delta Hidden 5mg'],'Hidden chip shows products not published to the website');
  await page.locator('[data-products-filter="all"]').click();
  assert.deepEqual(await visibleFixtures(),fixtureNames,'All chip restores every row');
  // Row targets, readable text and layout at phone widths.
  for(const width of [375,390,430]){
   await page.setViewportSize({width,height:844});
   await page.goto(host+'/?view=products');
   for(const name of fixtureNames){
    const box=await page.locator('.products-row',{hasText:name}).boundingBox();assert(box&&box.height>=44,width+'px product row target under 44px');
   }
   const fontSizes=await page.locator('.products-row-title, .products-row-price, .products-row-stock, .products-row-sub').evaluateAll(nodes=>nodes.map(node=>parseFloat(getComputedStyle(node).fontSize)));
   assert(fontSizes.every(size=>size>=12),width+'px product list text under 12px');
   assert.equal(await page.locator('.products-toolbar').evaluate(node=>getComputedStyle(node).position),'sticky','Search toolbar must stay sticky');
   const metrics=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
   assert(metrics.scrollWidth<=metrics.width+2,width+'px products horizontal overflow '+JSON.stringify(metrics));
  }
  // Desktop uses two columns.
  await page.setViewportSize({width:1366,height:900});
  await page.goto(host+'/?view=products');
  const columns=await page.locator('#products-list').evaluate(node=>getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
  assert.equal(columns,2,'Desktop products list must show two columns');
  // Every existing management action stays reachable in the single Manage sheet.
  await page.setViewportSize({width:390,height:844});
  await page.goto(host+'/?view=products');
  await page.locator('.products-add-button').click();
  assert(await page.locator('#products-manage').evaluate(node=>node.open),'+ Product opens the Manage sheet');
  assert(await page.locator('.add-product-form input[name="name"]').isVisible(),'Add product form is reachable');
  assert.equal(await page.locator('#ankh-visibility-form').count(),1,'Website visibility controls are kept');
  assert.equal(await page.locator('#hidden-product-list').count(),1,'Hidden product editors are kept');
  assert((await page.locator('.product-stock-count-form').count())>=1,'Fridge count controls are kept');
  assert((await page.locator('.product-delete-button').count())>=1,'Product delete control is kept');
  assert.equal(await page.getByRole('link',{name:'Stock control →'}).count(),1,'Stock control link is kept in Manage');
  assert.equal(await page.getByRole('link',{name:'✎ Customer website CMS'}).count(),1,'Website CMS link is kept in Manage');
  // A row opens the existing product detail/edit screen.
  await page.goto(host+'/?view=products');
  await Promise.all([page.waitForNavigation(),page.locator('.products-row',{hasText:'Alpha Cream 5mg'}).click()]);
  assert.match(page.url(),/view=product\b/,'Product row opens the detail view');
  assert.match(page.url(),/[?&]id=99001\b/,'Product row passes the product id');
  assert(await page.getByRole('heading',{name:'Alpha Cream 5mg'}).first().isVisible(),'Detail view shows the selected product');
  assert.deepEqual(errors,[]);
  console.log('PASS: Products list searches instantly, filters All/Low stock/Hidden from website, keeps 44px rows and 12px text with no 375-430px overflow, uses two desktop columns, opens product details, and keeps every management action in the Manage sheet.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1)});
