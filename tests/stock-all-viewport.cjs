// Stock "All" overview layout on phones; isolated fixture only.
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');
const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-stock-all-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 writeFileSync(config,`<?php $_SESSION['admin']=true;$_SESSION['last']=time();return ['password_hash'=>'fixture-only','database_path'=>'${db}'];`);
 const server=spawn('php',['-d','session.save_path='+temp,'-S','127.0.0.1:18781','-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});const host='http://127.0.0.1:18781';let browser;
 const sql=code=>execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');${code}`],{encoding:'utf8'});
 const overlaps=(a,b)=>a.x<b.x+b.width&&b.x<a.x+a.width&&a.y<b.y+b.height&&b.y<a.y+a.height;
 try{
  for(let i=0;i<60;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  sql(`$db->exec("INSERT INTO products(id,name,price,cost,stock_tracking,stock_tracking_since,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,stock_qty) VALUES(99001,'Retatrutide fixture 20mg',10000,4000,1,'',3,5,102,110);");`);
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const page=await browser.newPage({viewport:{width:390,height:844}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const stockUrl=host+'/?view=stock';
  for(const width of [375,390,430]){
   await page.setViewportSize({width,height:844});await page.goto(stockUrl);await page.locator('.fridge-overview-grid').waitFor();
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),width+'px stock overview horizontal overflow');
   const cards=await page.locator('.fridge-overview-grid > .fridge-overview-card').evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return {cls:n.className,x:r.x,y:r.y,width:r.width,height:r.height}}));
   const jay=cards.find(c=>c.cls.includes('fridge-jay')),tony=cards.find(c=>c.cls.includes('fridge-tony'));
   assert(jay&&tony,'Both fridge cards must render');
   assert(Math.abs(jay.x-tony.x)<1,width+'px: Jay and Tony cards share the left edge');
   assert(tony.y>jay.y+jay.height/2,width+'px: fridge cards stack vertically below 600px');
   assert(jay.width>width-60&&tony.width>width-60,width+'px: fridge cards use the full phone width');
   for(const card of [jay,tony])assert(card.height>=44,'Fridge card tap target is at least 44px');

   assert.equal(await page.locator('.fridge-jay').getAttribute('href'),'?view=stock&who=jay');
   assert.equal(await page.locator('.fridge-tony').getAttribute('href'),'?view=stock&who=tony');
   assert.equal(await page.locator('.fridge-jay .fridge-open-chevron').count(),1,'Jay card has a chevron');
   assert.equal(await page.locator('.fridge-tony .fridge-open-chevron').count(),1,'Tony card has a chevron');
   assert.equal(await page.locator('.fridge-direct-links').count(),0,'Separate fridge links are removed');
   assert.equal(await page.locator('.fridge-legacy-note').count(),0,'Duplicate holding note is removed');
   assert.equal(await page.locator('.fridge-holding').count(),1,'Exactly one holding card remains');
   assert.match(await page.locator('.fridge-holding').innerText(),/102\s*need assigning/i);
   assert.equal(await page.locator('.fridge-holding').getAttribute('href'),'?view=stock&who=holding');
   const holdingAction=await page.locator('.fridge-holding .fridge-open-action').boundingBox();
   assert(holdingAction&&holdingAction.height>=44,'Open Holding target is at least 44px');
   assert.match(await page.locator('.combined-stock-head').innerText(),/excludes holding/i);
   assert.equal(await page.locator('.combined-stock-hero > strong').innerText(),'8','Combined total excludes holding');
   assert.match(await page.locator('.fridge-holding > strong').innerText(),/^102\b/);

   for(const selector of ['.fridge-jay .fridge-value-summary small b','.fridge-tony .fridge-value-summary small b']){
    const labels=await page.locator(selector).allInnerTexts();
    assert.deepEqual(labels,['Retail','Profit'],'Half-width fridge cards use short money labels');
   }
   assert.equal(await page.locator('.fridge-jay').getByText('Retail value available',{exact:true}).count(),0);
   assert.equal(await page.locator('.fridge-jay').getByText('Potential profit',{exact:true}).count(),0);

   const labels=await page.locator('.stock-overview-breakdown small, .combined-stock-stats small').evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect(),s=getComputedStyle(n);return {text:n.innerText.replace(/\s+/g,' ').trim(),x:r.x,y:r.y,width:r.width,height:r.height,font:parseFloat(s.fontSize),scroll:n.scrollWidth,client:n.clientWidth}}));
   assert(labels.length>=6,'Physical / Reserved / Available labels render');
   for(const label of labels){
    assert(label.font>=12,width+'px: stat label "'+label.text+'" is at least 12px');
    assert(label.scroll<=label.client+1,width+'px: stat label "'+label.text+'" does not overflow its tile');
   }
   for(let i=0;i<labels.length;i++)for(let j=i+1;j<labels.length;j++)assert(!overlaps(labels[i],labels[j]),width+'px: stat labels must not intersect: '+labels[i].text+' / '+labels[j].text);

   const money=await page.locator('.fridge-jay .fridge-value-summary small, .fridge-tony .fridge-value-summary small, .combined-stock-money small, .combined-stock-money b').evaluateAll(nodes=>nodes.map(n=>({text:n.innerText,scroll:n.scrollWidth,client:n.clientWidth,font:parseFloat(getComputedStyle(n).fontSize)})));
   for(const item of money){assert(item.font>=12,'Money text is at least 12px');assert(item.scroll<=item.client+1,'Money text is not truncated: '+item.text);}
   assert(await page.locator('body').evaluate(el=>el.scrollWidth<=innerWidth+2),width+'px: no horizontal overflow');
  }

  await page.setViewportSize({width:600,height:844});await page.goto(stockUrl);await page.locator('.fridge-overview-grid').waitFor();
  const sideBySide=await page.evaluate(()=>{const jay=document.querySelector('.fridge-jay').getBoundingClientRect(),tony=document.querySelector('.fridge-tony').getBoundingClientRect(),combined=document.querySelector('.fridge-combined').getBoundingClientRect(),holding=document.querySelector('.fridge-holding').getBoundingClientRect();return {jay:{x:jay.x,y:jay.y,width:jay.width},tony:{x:tony.x,y:tony.y,width:tony.width},combinedWidth:combined.width,holdingWidth:holding.width,container:document.querySelector('.fridge-overview-grid').getBoundingClientRect().width}});
  assert(Math.abs(sideBySide.jay.y-sideBySide.tony.y)<2,'Fridge cards sit side by side from 600px');
  assert(sideBySide.tony.x>sideBySide.jay.x&&sideBySide.jay.width>200,'Fridge cards share the row at 600px');
  assert(sideBySide.combinedWidth>sideBySide.container-30,'Combined card stays full width');
  assert(sideBySide.holdingWidth>sideBySide.container-30,'Holding card stays full width');

  await page.setViewportSize({width:390,height:844});await page.goto(stockUrl);await page.locator('.fridge-jay').click();await page.waitForLoadState('domcontentloaded');
  assert(new URL(page.url()).searchParams.get('who')==='jay','Tapping the Jay card opens the Jay tab');
  assert.equal(await page.getByRole('heading',{name:/Jay’s fridge/i}).count(),1);
  await page.goto(stockUrl);await page.locator('.fridge-holding').click();await page.waitForLoadState('domcontentloaded');
  assert(new URL(page.url()).searchParams.get('who')==='holding','Open Holding opens the Holding tab');
  assert.deepEqual(errors,[]);
  console.log('PASS: stock All overview stacks at 375/390/430px, sits side by side at 600px, keeps readable non-overlapping stats, short money labels, one Holding card and tappable fridge cards.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stdout?.toString()||e);process.exit(1)});
