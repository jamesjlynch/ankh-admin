// Real authenticated phone fixture for the New order sticky step footer.
// Verifies the footer clears the bottom nav and date inputs stay in their card.
const {chromium,webkit}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-neworder-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 writeFileSync(config,`<?php if(($_SERVER['HTTP_X_ANKH_FIXTURE_AUTH']??'')==='yes'){$_SESSION['admin']=true;$_SESSION['last']=time();}return ['password_hash'=>'fixture-only','database_path'=>'${db}'];`);
 const port=await new Promise((resolvePort,reject)=>{const s=require('node:net').createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolvePort(p));});});
 const host='http://127.0.0.1:'+port;
 const server=spawn('php',['-d','session.save_path='+temp,'-S','127.0.0.1:'+port,'-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});
 let browser;
 try{
  for(let i=0;i<60;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  const browserType=process.env.ANKH_TEST_BROWSER==='webkit'?webkit:chromium;
  browser=await browserType.launch({headless:true,args:browserType===chromium?['--no-sandbox']:[]});
  const page=await browser.newPage({viewport:{width:390,height:844},extraHTTPHeaders:{'X-Ankh-Fixture-Auth':'yes'}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  const overlap=(a,b)=>a.left<b.right&&a.right>b.left&&a.top<b.bottom&&a.bottom>b.top;
  for(const width of [375,390,430]){
   await page.setViewportSize({width,height:844});
   await page.goto(host+'/?view=new');
   const view=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
   assert(view.scrollWidth<=view.width+2,width+'px New order horizontal overflow '+JSON.stringify(view));
   for(const step of [1,2,3]){
    await page.evaluate(step=>showWizardStep(step),step);
    await page.waitForTimeout(260);
    const scrollInfo=await page.evaluate(()=>{const main=document.querySelector('#ankh-main');return {max:Math.max(0,main.scrollHeight-main.clientHeight)};});
    for(const top of [0,Math.round(scrollInfo.max/2),scrollInfo.max]){
     const boxes=await page.evaluate(({step,top})=>{
      const main=document.querySelector('#ankh-main');main.scrollTop=top;
      const rect=el=>{const r=el.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height};};
      const section=document.querySelector('[data-wizard-step="'+step+'"]');
      const footer=section.querySelector('.wizard-actions');
      const button=footer.querySelector('button[data-wizard-next],button.save-order');
      const nav=document.querySelector('nav.mobile-nav');
      const navNew=nav.querySelector('.nav-new');
      return {innerWidth,innerHeight,footer:rect(footer),button:rect(button),nav:rect(nav),navNew:rect(navNew),position:getComputedStyle(footer).position};
     },{step,top});
     const label=width+'px step '+step+' scroll '+top;
     assert.equal(boxes.position,'sticky',label+' footer must stay sticky');
     assert(boxes.button.top>=-1&&boxes.button.bottom<=boxes.innerHeight+1,label+' Next button is not fully in the viewport '+JSON.stringify(boxes));
     assert(boxes.button.left>=-1&&boxes.button.right<=boxes.innerWidth+1,label+' Next button overflows sideways');
     assert(!overlap(boxes.button,boxes.nav),label+' Next button intersects the bottom nav '+JSON.stringify(boxes));
     assert(!overlap(boxes.footer,boxes.nav),label+' step footer intersects the bottom nav '+JSON.stringify(boxes));
     assert(boxes.button.bottom<=boxes.navNew.top+1,label+' raised + button covers the Next button '+JSON.stringify(boxes));
    }
   }
   // Every date picker on step 1 must stay inside its own card.
   await page.evaluate(()=>showWizardStep(1));
   await page.waitForTimeout(220);
   const dates=await page.evaluate(()=>{
    const main=document.querySelector('#ankh-main');main.scrollTop=0;
    return [...document.querySelectorAll('[data-wizard-step="1"] input[type="date"], [data-wizard-step="1"] input[type="time"]')].map(input=>{
     const r=input.getBoundingClientRect(),parent=input.closest('label').getBoundingClientRect(),card=input.closest('.panel').getBoundingClientRect();
     if(r.width<1)return null;
     return {input:{left:r.left,right:r.right,width:r.width},parent:{left:parent.left,right:parent.right,width:parent.width},card:{left:card.left,right:card.right},display:getComputedStyle(input).display,appearance:getComputedStyle(input).webkitAppearance};
    }).filter(Boolean);
   });
   assert(dates.length>=1,width+'px New order date input missing');
   for(const date of dates){
    assert(date.input.left>=date.parent.left-1&&date.input.right<=date.parent.right+1,width+'px date input wider than its field '+JSON.stringify(date));
    assert(date.parent.left>=date.card.left-1&&date.parent.right<=date.card.right+1,width+'px date field overflows the card '+JSON.stringify(date));
    assert(Math.abs(date.input.width-date.parent.width)<=2,width+'px date input is not full width '+JSON.stringify(date));
    assert.equal(date.appearance,'none',width+'px date input keeps native sizing '+JSON.stringify(date));
   }
  }
  // Edit order shares the same sticky action pattern and must clear the nav too.
  const sql=code=>execFileSync('php',['-r',`$db=new PDO('sqlite:${db}');${code}`],{encoding:'utf8'});
  sql(`$db->exec("INSERT INTO orders(id,customer,phone,address,notes,status,created) VALUES(99101,'Footer fixture','','','','New','2026-10-01');");`);
  await page.setViewportSize({width:390,height:844});
  await page.goto(host+'/?view=edit&id=99101');
  const editScroll=await page.evaluate(()=>{const main=document.querySelector('#ankh-main');return Math.max(0,main.scrollHeight-main.clientHeight);});
  for(const top of [0,Math.round(editScroll/2),editScroll]){
   const edit=await page.evaluate(top=>{
    const main=document.querySelector('#ankh-main');main.scrollTop=top;
    const rect=el=>{const r=el.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom};};
    return {total:rect(document.querySelector('.edit-total')),nav:rect(document.querySelector('nav.mobile-nav')),position:getComputedStyle(document.querySelector('.edit-total')).position};
   },top);
   assert.equal(edit.position,'sticky','Edit order total bar must stay sticky');
   assert(!overlap(edit.total,edit.nav),'Edit order total bar intersects the bottom nav at scroll '+top+' '+JSON.stringify(edit));
  }
  assert.deepEqual(errors,[]);
  console.log('PASS: New order step footers clear the bottom nav and remain in view at 375/390/430px on every step and scroll position; date inputs stay inside their card; Edit order total bar clears the nav.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1)});
