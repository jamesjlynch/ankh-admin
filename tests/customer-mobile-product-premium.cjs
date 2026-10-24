'use strict';
/* Browser-level viewport QA: pixel boxes + working taps on a local isolated PHP fixture. */
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,mkdirSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const path=require('node:path');
const {spawn,execFileSync}=require('node:child_process');
const {promisify}=require('node:util');
const assert=require('node:assert/strict');
const net=require('node:net');
const http=require('node:http');
const root=path.resolve(__dirname,'..');
const temp=mkdtempSync(path.join(tmpdir(),'ankh-atelier-'));
const out=path.join(root,'atelier-viewport-screenshots');
mkdirSync(out,{recursive:true});
const fixturePython=`
import sys,sqlite3,base64,json
db=sys.argv[1];portal=sys.argv[2]
im=base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=")
with sqlite3.connect(db) as c:
 c.executescript("""
 CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,description TEXT,price INTEGER,active INTEGER);
 CREATE TABLE customer_catalogue_visibility(product_id INTEGER PRIMARY KEY,visible INTEGER);
 CREATE TABLE customer_catalogue_content(product_id INTEGER PRIMARY KEY,description TEXT,image_data BLOB,image_mime TEXT,image_width INTEGER,image_height INTEGER);
 CREATE TABLE customer_catalogue_information(product_id INTEGER PRIMARY KEY,information TEXT);
 INSERT INTO products VALUES(1,'BPC-157 5mg','A laboratory reference profile',1299,1),
 (2,'BPC-157 10mg','A technical research material',2299,1),
 (3,'Research pen case','Laboratory accessory case',1999,1);
 INSERT INTO customer_catalogue_visibility VALUES(1,1),(2,1),(3,1);
 """)
 for i in (1,2,3):
  c.execute("INSERT INTO customer_catalogue_content VALUES(?,?,?,?,?,?)",(i,"Research reference",im,"image/png",1,1))
  c.execute("INSERT INTO customer_catalogue_information VALUES(?,?)",(i,json.dumps({'strength':str(5*i)+'mg','presentation':'Vial','appearance':'Powder','peptide_type':'Research compound','amino_length':'15 residues','formula':'C62H98N16O22','weight':'1419 g/mol','sequence':'GEPPPGKPADDAGLV','cas':'137525-51-0','purity':'Unverified manufacturer listing','storage':'Use label conditions'})))
with sqlite3.connect(portal) as c:c.execute('CREATE TABLE portal_accounts(id INTEGER PRIMARY KEY,customer_id INTEGER,phone_e164 TEXT,active INTEGER)')
`;
execFileSync('python3',['-c',fixturePython,path.join(temp,'admin.sqlite'),path.join(temp,'portal.sqlite')]);
const escapePHP=s=>s.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
writeFileSync(path.join(temp,'config.php'),`<?php return ['admin_database_path'=>'${escapePHP(path.join(temp,'admin.sqlite'))}','portal_database_path'=>'${escapePHP(path.join(temp,'portal.sqlite'))}','host'=>'order.ankhpeptides.com'];`);
writeFileSync(path.join(temp,'router.php'),"<?php $_SERVER['SERVER_ADDR']='127.0.0.1'; return false;");
const freePort=()=>new Promise((resolve,reject)=>{
 const server=net.createServer();
 server.once('error',reject);server.listen(0,'127.0.0.1',()=>{const port=server.address().port;server.close(()=>resolve(port));});
});
const checkServer=url=>new Promise(resolve=>http.get(url,res=>{res.resume();resolve(res.statusCode===200);}).on('error',()=>resolve(false)));
(async()=>{
 let browser,server;
 try{
   const port=await freePort(),origin='http://127.0.0.1:'+port;
   server=spawn('php',['-S','127.0.0.1:'+port,'-t',path.join(root,'customer-portal'),path.join(temp,'router.php')],{
    env:{...process.env,ANKH_PORTAL_CONFIG:path.join(temp,'config.php'),ANKH_TEST_ALLOW_HTTP:'1'},
    stdio:['ignore','pipe','pipe']
   });
   for(let i=0;i<60;i++){if(await checkServer(origin+'/'))break;await new Promise(r=>setTimeout(r,100));if(i===59)throw Error('Local PHP storefront fixture unavailable');}
   browser=await chromium.launch({headless:true,channel:'chrome'});
   const widths=[320,375,390,430];
   for(const width of widths){
    const context=await browser.newContext({viewport:{width,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true,reducedMotion:'reduce'});
    const page=await context.newPage();
    const failures=[];
    page.on('pageerror',e=>failures.push(e.message));
    await page.goto(origin+'/',{waitUntil:'networkidle'});
    const first=page.locator('.sf-card-v3').first();await first.waitFor();
    assert((await page.locator('.sf-card-v3').first().innerText()).includes('£12.99'),'Retail preview not restored to published product card');
    assert.equal(await page.locator('.sf-card-v3').count(),3,'all published fixture products must have cards');
    const actions=await first.locator('.sf-card-bottom button').all();
    assert.equal(actions.length,2,'two card footer actions expected');
    const boxes=await Promise.all(actions.map(x=>x.boundingBox()));
    const cardBox=await first.boundingBox();
    assert(boxes[0].x+boxes[0].width<=boxes[1].x+1,`Save/detail overlap at ${width}px`);
    for(const b of boxes){
      assert(b.x>=cardBox.x-1&&b.x+b.width<=cardBox.x+cardBox.width+1,`Footer button escapes card at ${width}px`);
      assert(b.height>=40,`Button too small at ${width}px`);
    }
    const pageOverflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);
    assert(pageOverflow<=3,`Horizontal overflow ${pageOverflow}px on ${width}px home`);
    const cartButton=first.locator('.sf-add-cart');
    await cartButton.evaluate(el=>el.scrollIntoView({block:'center',inline:'nearest',behavior:'instant'}));
    await page.waitForTimeout(130);
    await page.screenshot({path:path.join(out,'home-before-click-'+width+'.png'),fullPage:false});
    const hit=await cartButton.evaluate(el=>{
      const r=el.getBoundingClientRect(),x=r.left+r.width/2,y=r.top+r.height/2;
      const top=document.elementFromPoint(x,y);
      return {button:{x:r.x,y:r.y,width:r.width,height:r.height},hit:top?.className,tag:top?.tagName,isSelf:top===el||el.contains(top)};
    });
    console.log('Button hit test',width,JSON.stringify(hit));
    assert(hit.isSelf,'Product Add button tap is blocked at '+width+'px: '+JSON.stringify(hit));
    await page.touchscreen.tap(hit.button.x+hit.button.width/2,hit.button.y+hit.button.height/2);
    assert.equal(await page.locator('.sf-cart-drawer').evaluate(el=>el.open),false,'Add must not open basket');
    assert((await first.locator('.sf-add-cart').innerText()).includes('Added'),'Animated added state missing');
    assert((await page.locator('.sf-cart-feedback').innerText()).includes('added'),'Toast confirmation missing');
    await page.screenshot({path:path.join(out,'home-'+width+'.png'),fullPage:true});
    await page.goto(origin+'/?product=1',{waitUntil:'networkidle'});
    assert(await page.locator('.sf-mobile-hero-intro').isVisible(), 'Mobile summary is not visible above the photo');
    const firstScreen=await page.evaluate(()=>({intro:document.querySelector('.sf-mobile-hero-intro').getBoundingClientRect().top, gallery:document.querySelector('.sf-product-gallery').getBoundingClientRect().top}));
    assert(firstScreen.intro<firstScreen.gallery, 'Product information must precede the photo on mobile');
    assert((await page.locator('.sf-mobile-hero-price').innerText()).includes('£12.99'), 'First-screen price preview missing');
    await page.screenshot({path:path.join(out,'premium-top-'+width+'.png'),fullPage:false});
    await page.locator('[data-gallery-go="1"]').click();
    await page.waitForTimeout(100);
    const fullIdentity=page.locator('#sf-gallery-specification');
    assert((await fullIdentity.innerText()).includes('Molecular formula'),'Full identity absent from gallery slide');
    assert((await fullIdentity.innerText()).includes('Amino-acid sequence'),'Sequence absent from gallery slide');
    const specs=await fullIdentity.evaluate(el=>({client:el.clientHeight,scroll:el.scrollHeight,overflow:getComputedStyle(el).overflowY}));
    assert(specs.scroll>specs.client,'Full specification list should be vertically scrollable rather than clipped');
    assert(specs.overflow==='auto'||specs.overflow==='scroll','Gallery identity overflow does not allow scrolling');
    assert((await page.locator('.sf-product-price').textContent()).includes('£12.99'),'Product-detail price preview missing');
    const scroll=await page.locator('.sf-gallery-track').evaluate(el=>el.scrollLeft);
    assert(scroll>10,'Details slide not navigable');
    await page.locator('[data-gallery-go="0"]').click();
    await page.locator('[data-gallery-zoom]').click();
    assert.equal(await page.locator('#sf-product-zoom').evaluate(el=>el.open),true,'Zoom dialog failed');
    await page.locator('[data-close-zoom]').click();
    assert.equal(await page.locator('#sf-product-zoom').evaluate(el=>el.open),false,'Zoom dialog did not close');
    await page.locator('.sf-mobile-hero-links a[href="#sf-product-documents"]').click();
    await page.waitForTimeout(50);
    const docs=await page.locator('#sf-product-documents').boundingBox();
    assert(docs && docs.width>=280 && docs.width<=width, 'Product documents are not accessible in mobile viewport');
    const docCard=await page.locator('.sf-product-doc-reference').boundingBox();
    assert(docCard && docCard.height>=75, 'Document download card is not touch friendly');
    await page.screenshot({path:path.join(out,'premium-docs-'+width+'.png'),fullPage:false});
    const bars=await page.evaluate(()=>{
      const a=document.querySelector('.sf-mobile-product-action').getBoundingClientRect();
      const b=document.querySelector('.sf-bottom-menu').getBoundingClientRect();
      return {a:{top:a.top,bottom:a.bottom,width:a.width},b:{top:b.top,bottom:b.bottom}};
    });
    assert(bars.a.width>0&&bars.a.bottom<=bars.b.top+4,`Mobile CTA overlaps nav at ${width}px: ${JSON.stringify(bars)}`);
    assert(await page.locator('.sf-mobile-product-action .sf-add-cart').isVisible(),'Mobile sticky add missing');
    const detailOverflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);
    assert(detailOverflow<=3,`Horizontal detail overflow ${detailOverflow}px at ${width}px`);
    await page.screenshot({path:path.join(out,'product-'+width+'.png'),fullPage:true});
    assert.deepEqual(failures,[],`Page JavaScript errors at ${width}px`);
    console.log(`PASS premium ANKH iPhone-style ${width}px: product summary before gallery, accessible documents, no overlap or overflow`);
    await context.close();
   }
 }finally{
  if(browser)await browser.close();
  if(server)server.kill('SIGTERM');
  rmSync(temp,{recursive:true,force:true});
 }
})().catch(err=>{console.error(err);process.exitCode=1;});
