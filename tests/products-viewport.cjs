// Real authenticated HTTP fixture for the Products stock grid. No live data is used.
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
  sql(`$db->exec("INSERT INTO products(id,name,price,cost,family_friends_price,stock_tracking,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,stock_qty,low_stock_at,supplier_pack_size,active,is_accessory,admin_hidden) VALUES(99001,'Alpha Cream 5mg',5000,1000,4000,1,2,1,1,4,5,10,1,0,0),(99002,'Beta Serum 10ml',3000,800,2500,1,20,10,5,35,5,10,1,0,0),(99003,'Gamma Oil',2000,500,1500,0,0,0,0,NULL,5,10,1,0,0),(99004,'Delta Hidden 5mg',1000,200,800,0,0,0,0,NULL,5,10,1,0,1),(99005,'UltraLongProductNameWithoutAnySpacesForOverflowTesting1234567890',1200,400,900,0,0,0,0,NULL,5,10,1,0,0),(99006,'Epsilon Out 5mg',1500,400,1200,1,0,0,0,0,5,10,1,0,0),(99007,'Accessory Vial Cap',800,200,600,1,10,0,0,10,5,10,1,1,0),(99008,'Category Beaker',1800,600,1300,0,0,0,0,NULL,5,10,1,0,0);INSERT INTO customer_catalogue_visibility(product_id,visible,updated_at) VALUES(99001,1,'2026-01-01'),(99002,1,'2026-01-01'),(99003,0,'2026-01-01'),(99004,0,'2026-01-01'),(99005,1,'2026-01-01'),(99006,1,'2026-01-01'),(99007,1,'2026-01-01'),(99008,1,'2026-01-01');INSERT INTO catalogue_product_categories(product_id,category_id,updated_at) SELECT 99008,id,'2026-01-01' FROM catalogue_categories WHERE name='Petri Dishes' AND NOT EXISTS(SELECT 1 FROM catalogue_product_categories WHERE product_id=99008);");`);
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const page=await browser.newPage({viewport:{width:390,height:844},extraHTTPHeaders:{'X-Ankh-Fixture-Auth':'yes'}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const fixtureNames=['Beta Serum 10ml','Alpha Cream 5mg','Epsilon Out 5mg','Gamma Oil','UltraLongProductNameWithoutAnySpacesForOverflowTesting1234567890'];
  const hiddenFixtureName='Delta Hidden 5mg';
  const visibleNames=()=>page.locator('.products-card').evaluateAll(nodes=>nodes.filter(node=>!node.hidden).map(node=>node.querySelector('.products-card-name').textContent.trim()));
  const fixtureVisible=async()=>(await visibleNames()).filter(name=>fixtureNames.includes(name));
  await page.goto(host+'/?view=products');
  for(const name of fixtureNames)assert.equal(await page.locator('.products-card',{hasText:name}).count(),1,'Missing product card for '+name);
  assert.deepEqual(await fixtureVisible(),fixtureNames,'Cards are ordered in stock, low, out, then untracked');
  assert.equal(await page.locator('.products-card[data-status="in"]',{hasText:'Beta Serum 10ml'}).count(),1,'Healthy stock uses the in-stock state');
  assert.equal(await page.locator('.products-card[data-status="low"]',{hasText:'Alpha Cream 5mg'}).count(),1,'Low stock uses the low state');
  assert.equal(await page.locator('.products-card[data-status="out"]',{hasText:'Epsilon Out 5mg'}).count(),1,'Zero stock uses the out state');
  assert.equal(await page.locator('.products-card[data-status="untracked"]',{hasText:'Gamma Oil'}).count(),1,'Untracked stock uses the untracked state');
  assert.equal(await page.locator('.products-card',{hasText:'Accessory Vial Cap'}).count(),0,'Accessories are absent from the Products grid');
  assert.equal(await page.locator('.products-card',{hasText:'Category Beaker'}).count(),0,'Products in accessory-kind categories are absent from the Products grid');
  // Instant client-side name search.
  await page.locator('#products-search').fill('beta serum');
  assert.deepEqual(await visibleNames(),['Beta Serum 10ml'],'Search filters by product name');
  await page.locator('#products-search').fill('cream 5mg');
  assert.deepEqual(await visibleNames(),['Alpha Cream 5mg'],'Search matches words inside longer names');
  await page.locator('#products-search').fill('nothing-here');
  assert.equal((await visibleNames()).length,0,'Search can hide every card');
  assert(await page.locator('#products-no-results').isVisible(),'Empty search state is visible');
  await page.locator('#products-search').fill('');
  // Filter chips.
  await page.locator('[data-products-filter="in"]').click();
  assert.deepEqual(await fixtureVisible(),['Beta Serum 10ml'],'In stock chip shows healthy tracked products');
  assert.equal(await page.locator('[data-products-filter="in"]').getAttribute('aria-pressed'),'true','Active chip is announced');
  await page.locator('[data-products-filter="low"]').click();
  assert.deepEqual(await fixtureVisible(),['Alpha Cream 5mg'],'Low chip shows products at or below their threshold');
  await page.locator('[data-products-filter="out"]').click();
  assert.deepEqual(await fixtureVisible(),['Epsilon Out 5mg'],'Out chip shows products with no available stock');
  await page.locator('[data-products-filter="all"]').click();
  assert.deepEqual(await fixtureVisible(),fixtureNames,'All chip restores every card');
  assert.equal(await page.locator('.products-card',{hasText:hiddenFixtureName}).evaluate(node=>node.hidden),true,'Admin-hidden products are absent from All');
  // Admin-hidden products live behind the Hidden chip and are restored with Show.
  assert.equal((await page.locator('[data-products-filter="hidden"]').innerText()).trim(),'Hidden (1)','Hidden chip counts admin-hidden products');
  await page.locator('[data-products-filter="hidden"]').click();
  assert.deepEqual(await visibleNames(),[hiddenFixtureName],'Hidden chip shows only admin-hidden products');
  assert.equal(await page.locator('.products-card[data-admin-hidden="1"]',{hasText:hiddenFixtureName}).count(),1,'Hidden card is shown in the Hidden view');
  await page.goto(host+'/?view=new');
  const hiddenPickerNames=await page.evaluate(()=>{try{return JSON.parse(document.querySelector('#ankh-visual-product-data').textContent||'[]').map(item=>item.name);}catch{return [];}});
  assert(!hiddenPickerNames.includes(hiddenFixtureName),'Admin-hidden products are absent from the New order picker');
  await page.goto(host+'/?view=products');
  await page.locator('[data-products-filter="hidden"]').click();
  await Promise.all([page.waitForNavigation(),page.locator('.products-card--hidden form.products-card-show button').click()]);
  assert.match(page.url(),/view=products\b/,'Show returns to the Products grid');
  assert((await visibleNames()).includes(hiddenFixtureName),'Show restores the product to All');
  const shownFlag=execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');echo $db->query("SELECT admin_hidden FROM products WHERE id=99004")->fetchColumn();`],{encoding:'utf8'}).trim();
  assert.equal(shownFlag,'0','Show clears admin_hidden');
  // The product page toggle round trips without touching any other field.
  await page.goto(host+'/?view=product&id=99004');
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Not stocked yet? Hide from admin'}).click()]);
  assert.match(page.url(),/view=product\b/,'Product toggle returns to the product page');
  assert.match(page.url(),/[?&]id=99004\b/,'Product toggle keeps the product id');
  assert(await page.getByRole('button',{name:'Show in admin'}).isVisible(),'Product page offers Show once hidden');
  const hiddenFlag=execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');echo $db->query("SELECT admin_hidden FROM products WHERE id=99004")->fetchColumn();`],{encoding:'utf8'}).trim();
  assert.equal(hiddenFlag,'1','Product page toggle sets admin_hidden');
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Show in admin'}).click()]);
  assert(await page.getByRole('button',{name:'Not stocked yet? Hide from admin'}).isVisible(),'Product page offers Hide once shown');
  const restoredFlag=execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');echo $db->query("SELECT admin_hidden FROM products WHERE id=99004")->fetchColumn();`],{encoding:'utf8'}).trim();
  assert.equal(restoredFlag,'0','Product page toggle round trip restores admin_hidden');
  // Phone widths use two columns and never overflow.
  for(const width of [375,390,430]){
   await page.setViewportSize({width,height:844});
   await page.goto(host+'/?view=products');
   const columns=await page.locator('#products-list').evaluate(node=>getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
   assert.equal(columns,2,width+'px products grid must show two columns');
   const metrics=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
   assert(metrics.scrollWidth<=metrics.width+2,width+'px products horizontal overflow '+JSON.stringify(metrics));
   const box=await page.locator('.products-card',{hasText:'Beta Serum 10ml'}).boundingBox();assert(box&&box.height>=44,width+'px product card target under 44px');
   assert.equal(await page.locator('.products-toolbar').evaluate(node=>getComputedStyle(node).position),'sticky','Search toolbar must stay sticky');
  }
  // Desktop uses four columns.
  await page.setViewportSize({width:1366,height:900});
  await page.goto(host+'/?view=products');
  const desktopColumns=await page.locator('#products-list').evaluate(node=>getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
  assert.equal(desktopColumns,4,'Desktop products grid must show four columns');
  // Accessories are absent from the New order picker data.
  await page.setViewportSize({width:390,height:844});
  await page.goto(host+'/?view=new');
  const pickerNames=await page.evaluate(()=>{try{return JSON.parse(document.querySelector('#ankh-visual-product-data').textContent||'[]').map(item=>item.name);}catch{return [];}});
  assert(pickerNames.includes('Beta Serum 10ml'),'New order picker keeps ordinary products');
  assert(!pickerNames.some(name=>name.includes('Accessory Vial Cap')),'Accessories are absent from the New order picker');
  assert(!pickerNames.some(name=>name.includes('Category Beaker')),'Products in accessory-kind categories are absent from the New order picker');
  // A card opens the existing product detail screen.
  await page.goto(host+'/?view=products');
  await Promise.all([page.waitForNavigation(),page.locator('.products-card',{hasText:'Alpha Cream 5mg'}).click()]);
  assert.match(page.url(),/view=product\b/,'Product card opens the detail view');
  assert.match(page.url(),/[?&]id=99001\b/,'Product card passes the product id');
  assert(await page.getByRole('heading',{name:'Alpha Cream 5mg'}).first().isVisible(),'Detail view shows the selected product');
  // The product page is admin-only: no website content or catalogue visibility controls.
  await page.goto(host+'/?view=product&id=99001');
  const productBody=await page.locator('body').innerText();
  for(const removed of ['Edit website content','Hide from customer website','Show on customer website']){
   assert(!productBody.includes(removed),'Product page must not contain '+removed);
  }
  assert.equal(await page.locator('.product-v2-edit-link').count(),0,'Product page has no website-admin edit links');
  assert.equal(await page.locator('input[name="action"][value="product_catalogue_visibility"]').count(),0,'Product page has no catalogue visibility form');
  const readProduct=id=>JSON.parse(execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');echo json_encode($db->query("SELECT price,family_friends_price,cost,admin_hidden,active FROM products WHERE id=${id}")->fetch(PDO::FETCH_ASSOC));`],{encoding:'utf8'}));
  const readVisibility=id=>execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');echo $db->query("SELECT visible FROM customer_catalogue_visibility WHERE product_id=${id}")->fetchColumn();`],{encoding:'utf8'}).trim();
  const priceBefore=readProduct(99001),visibilityBefore=readVisibility(99001);
  await page.locator('.product-v2-price-toggle').click();
  let priceForm=page.locator('.product-v2-price-form');
  assert(await priceForm.isVisible(),'Edit prices reveals the inline form');
  await priceForm.locator('input[name="price"]').fill('55.50');
  await priceForm.locator('input[name="family_friends_price"]').fill('44');
  await priceForm.locator('input[name="cost"]').fill('12.25');
  await Promise.all([page.waitForNavigation(),priceForm.getByRole('button',{name:'Save prices'}).click()]);
  assert.match(page.url(),/view=product\b/,'Saving prices returns to the product page');
  assert.match(page.url(),/[?&]id=99001\b/,'Saving prices keeps the product id');
  const priceAfter=readProduct(99001);
  assert.equal(priceAfter.price,5550,'Retail price was saved');
  assert.equal(priceAfter.family_friends_price,4400,'Family & Friends price was saved');
  assert.equal(priceAfter.cost,1225,'Unit cost was saved');
  assert.equal(priceAfter.admin_hidden,priceBefore.admin_hidden,'Price save leaves admin_hidden untouched');
  assert.equal(priceAfter.active,priceBefore.active,'Price save leaves active untouched');
  assert.equal(readVisibility(99001),visibilityBefore,'Price save leaves website visibility untouched');
  await page.locator('.product-v2-price-toggle').click();
  priceForm=page.locator('.product-v2-price-form');
  await priceForm.locator('input[name="price"]').fill('50');
  await priceForm.locator('input[name="family_friends_price"]').fill('');
  await priceForm.locator('input[name="cost"]').fill('10');
  await Promise.all([page.waitForNavigation(),priceForm.getByRole('button',{name:'Save prices'}).click()]);
  const blankFriends=readProduct(99001);
  assert.equal(blankFriends.family_friends_price,null,'Blank Family & Friends price saves NULL');
  assert.equal(blankFriends.price,5000,'Blank F&F save keeps the retail price');
  assert.equal(blankFriends.cost,1000,'Blank F&F save keeps the unit cost');
  // The price editor fits 375px and 390px phones without horizontal overflow.
  for(const width of [375,390]){
   await page.setViewportSize({width,height:844});
   await page.goto(host+'/?view=product&id=99001');
   let metrics=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
   assert(metrics.scrollWidth<=metrics.width+2,width+'px product page horizontal overflow '+JSON.stringify(metrics));
   await page.locator('.product-v2-price-toggle').click();
   metrics=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
   assert(metrics.scrollWidth<=metrics.width+2,width+'px open price editor horizontal overflow '+JSON.stringify(metrics));
   const columns=await page.locator('.product-v2-price-form-grid').evaluate(node=>getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
   assert.equal(columns,1,width+'px price inputs must stack');
   for(const name of ['price','family_friends_price','cost']){
    const box=await page.locator('.product-v2-price-form input[name="'+name+'"]').boundingBox();
    assert(box&&box.x>=0&&box.x+box.width<=width+1,width+'px '+name+' input fits the viewport');
   }
  }
  await page.setViewportSize({width:390,height:844});
  // The Products header add button opens the catalogue add form.
  await page.goto(host+'/?view=products');
  await Promise.all([page.waitForNavigation(),page.locator('.products-add-button').click()]);
  assert.match(page.url(),/view=catalogue\b/,'+ Product opens the catalogue view');
  assert(await page.locator('#products-manage-add').evaluate(node=>node.open),'+ Product opens the add form');
  assert(await page.locator('.add-product-form input[name="name"]').isVisible(),'Add product form is reachable');
  // The catalogue keeps every existing website and product management control.
  await page.goto(host+'/?view=catalogue');
  assert(await page.getByRole('heading',{name:'Website & products'}).isVisible(),'Catalogue uses the Website & products heading');
  assert(await page.locator('#products-manage').evaluate(node=>node.open),'Catalogue manage sheet is open');
  assert.equal(await page.locator('#ankh-visibility-form').count(),1,'Website visibility controls are kept');
  assert.equal(await page.locator('#hidden-product-list').count(),1,'Hidden product editors are kept');
  assert((await page.locator('.product-stock-count-form').count())>=1,'Fridge count controls are kept');
  assert((await page.locator('.product-delete-button').count())>=1,'Product delete control is kept');
  assert.equal(await page.getByRole('link',{name:'Stock control →'}).count(),1,'Stock control link is kept in catalogue');
  assert.equal(await page.getByRole('link',{name:'✎ Customer website CMS'}).count(),1,'Website CMS link is kept in catalogue');
  assert((await page.locator('input[name="is_accessory"]').count())>=1,'Accessory flag is available on product forms');
  assert.equal(await page.locator('#products-manage-add input[name="is_accessory"]').count(),1,'Add product form has the accessory flag');
  assert.equal(await page.locator('#product-edit-99002 input[name="admin_hidden_present"]').count(),1,'Admin hidden flag uses the safe presence pattern on the product edit tile');
  assert.equal(await page.locator('#product-edit-99002 input[name="admin_hidden"]').count(),1,'Admin hidden checkbox is available on the product edit tile');
  // The accessory checkbox saves through the existing product handler and removes the product from the grid.
  await page.goto(host+'/?view=catalogue');
  await page.locator('#product-edit-99001').evaluate(node=>{node.open=true;});
  await page.locator('#product-edit-99001 input[name="is_accessory"]').check();
  await Promise.all([page.waitForNavigation(),page.locator('#product-edit-99001 form.product-settings-form button').first().click()]);
  const accessoryFlag=execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');echo $db->query("SELECT is_accessory FROM products WHERE id=99001")->fetchColumn();`],{encoding:'utf8'}).trim();
  assert.equal(accessoryFlag,'1','Accessory checkbox saves through the product handler');
  await page.goto(host+'/?view=products');
  assert.equal(await page.locator('.products-card',{hasText:'Alpha Cream 5mg'}).count(),0,'A newly flagged accessory leaves the Products grid');
  // A product form that does not post is_accessory_present must not reset the flag.
  await page.goto(host+'/?view=catalogue');
  const csrfToken=await page.locator('#product-edit-99001 input[name="csrf"]').first().inputValue();
  await page.evaluate(async csrf=>{
   const body=new URLSearchParams({action:'product',return:'catalogue',id:'99001',name:'Alpha Cream 5mg',price:'50',family_friends_price:'40',cost:'10',supplier_pack_size:'10',low_stock_at:'5',active:'1',csrf});
   await fetch('./?view=catalogue',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
  },csrfToken);
  const untouchedFlag=execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');echo $db->query("SELECT is_accessory FROM products WHERE id=99001")->fetchColumn();`],{encoding:'utf8'}).trim();
  assert.equal(untouchedFlag,'1','Forms without is_accessory_present leave the accessory flag untouched');
  // Unticking the accessory flag restores the product to the grid and New order picker.
  await page.goto(host+'/?view=catalogue');
  await page.locator('#product-edit-99001').evaluate(node=>{node.open=true;});
  await page.locator('#product-edit-99001 input[name="is_accessory"]').uncheck();
  await Promise.all([page.waitForNavigation(),page.locator('#product-edit-99001 form.product-settings-form button').first().click()]);
  const clearedFlag=execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');echo $db->query("SELECT is_accessory FROM products WHERE id=99001")->fetchColumn();`],{encoding:'utf8'}).trim();
  assert.equal(clearedFlag,'0','Unticking the accessory checkbox clears the flag');
  await page.goto(host+'/?view=products');
  assert.equal(await page.locator('.products-card',{hasText:'Alpha Cream 5mg'}).count(),1,'A product cleared of accessory status returns to the Products grid');
  assert.deepEqual(errors,[]);
  console.log('PASS: Products is a stock-ordered two/four-column grid, the product page is admin-only with an inline price editor and quiet admin-hidden toggle, 375-430px never overflow, accessories are excluded from the grid and New order picker, and all website settings stay in the catalogue view.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1)});
