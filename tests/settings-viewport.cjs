// Real authenticated HTTP fixture for the merged Settings and Notifications screens. No live data is used.
const {chromium}=require('playwright');
const {mkdtempSync,writeFileSync,rmSync}=require('node:fs');
const {tmpdir}=require('node:os');const {join,resolve}=require('node:path');
const {spawn,execFileSync}=require('node:child_process');const assert=require('node:assert/strict');
(async()=>{
 const root=resolve(__dirname,'..'),temp=mkdtempSync(join(tmpdir(),'ankh-settings-ui-')),db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
 writeFileSync(config,`<?php if(($_SERVER['HTTP_X_ANKH_FIXTURE_AUTH']??'')==='yes'){$_SESSION['admin']=true;$_SESSION['last']=time();}return ['password_hash'=>'fixture-only','database_path'=>'${db}'];`);
 const port=await new Promise((resolvePort,reject)=>{const s=require('node:net').createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolvePort(p));});});
 const host='http://127.0.0.1:'+port;
 const server=spawn('php',['-d','session.save_path='+temp,'-S','127.0.0.1:'+port,'-t',root],{env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:'ignore'});
 let browser;
 try{
  for(let i=0;i<60;i++){try{if((await fetch(host)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const page=await browser.newPage({viewport:{width:390,height:844},extraHTTPHeaders:{'X-Ankh-Fixture-Auth':'yes'}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const markers={sheets:'ANKH Admin Orders',website:'Customer website CMS',alerts:'Email recipients'};
  for(const width of [375,390,430]){
   await page.setViewportSize({width,height:844});
   for(const [tab,marker] of Object.entries(markers)){
    await page.goto(host+'/?view=settings&tab='+tab);
    assert.equal(await page.locator('.settings-switch').count(),1,'Settings switch missing on '+tab);
    assert.equal(await page.locator('.settings-switch a').count(),3,'Settings switch needs three tabs');
    assert.equal((await page.locator('.settings-switch a[aria-current="page"]').innerText()).trim(),tab[0].toUpperCase()+tab.slice(1),'Wrong active Settings tab');
    assert.equal(await page.locator('main').getAttribute('data-view'),'settings','Settings screen must expose data-view="settings"');
    assert((await page.locator('main').innerText()).toLowerCase().includes(marker.toLowerCase()),'Missing '+tab+' settings content');
    for(const link of await page.locator('.settings-switch a').all()){
     const box=await link.boundingBox();assert(box&&box.height>=44,width+'px '+tab+' tab target under 44px');
    }
    const m=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
    assert(m.scrollWidth<=m.width+2,width+'px '+tab+' horizontal overflow '+JSON.stringify(m));
   }
  }
  // Legacy routes must 302 to the matching tab and retain their other filters.
  const redirect=async url=>{const res=await page.request.get(host+url,{maxRedirects:0});assert.equal(res.status(),302,'Legacy route must redirect: '+url);return res.headers()['location'];};
  const sheetsRedirect=await redirect('/?view=sheets&section=low');
  assert.match(sheetsRedirect,/view=settings/);assert.match(sheetsRedirect,/tab=sheets/);assert.match(sheetsRedirect,/section=low/);
  const websiteRedirect=await redirect('/?view=website_cms&id=7');
  assert.match(websiteRedirect,/view=settings/);assert.match(websiteRedirect,/tab=website/);assert.match(websiteRedirect,/id=7/);
  const alertsRedirect=await redirect('/?view=business_alerts&saved=1');
  assert.match(alertsRedirect,/view=settings/);assert.match(alertsRedirect,/tab=alerts/);assert.match(alertsRedirect,/saved=1/);
  const activityRedirect=await redirect('/?view=activity&limit=20');
  assert.match(activityRedirect,/view=notifications/);assert.match(activityRedirect,/limit=20/);
  await page.goto(host+'/?view=sheets&section=low');
  assert.match(page.url(),/[?&]view=settings\b/);assert.match(page.url(),/[?&]tab=sheets\b/);assert.match(page.url(),/[?&]section=low\b/);
  // A real POST return must land on the matching Settings tab.
  await page.goto(host+'/?view=settings&tab=sheets');
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Save Google connection',exact:true}).click()]);
  assert.match(page.url(),/[?&]view=settings\b/);assert.match(page.url(),/[?&]tab=sheets\b/);
  assert(await page.getByText('Google Sheets connection saved.').isVisible(),'Sheets POST did not return to the Settings Sheets tab');
  await page.goto(host+'/?view=settings&tab=alerts');
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Save alert preferences',exact:true}).click()]);
  assert.match(page.url(),/[?&]view=settings\b/);assert.match(page.url(),/[?&]tab=alerts\b/);
  assert(await page.getByText('Alert preferences saved.').isVisible(),'Alerts POST did not return to the Settings Alerts tab');
  // Seed two activity records and confirm one merged notifications list is newest first.
  const seed='$db=new PDO("sqlite:".getenv("ANKH_TEST_DB"));$q=$db->prepare("INSERT INTO activity_events(entity_type,entity_id,action,actor_id,actor_name,details_json,created) VALUES(?,?,?,?,?,?,?)");$q->execute(["order",1,"Older fixture","0","Fixture",json_encode([]),"2026-01-01T10:00:00Z"]);$q->execute(["order",2,"Newer fixture","0","Fixture",json_encode([]),"2026-02-01T10:00:00Z"]);';
  execFileSync('php',['-r',seed],{env:{...process.env,ANKH_TEST_DB:db},encoding:'utf8'});
  await page.setViewportSize({width:390,height:844});
  await page.goto(host+'/?view=notifications');
  assert.equal(await page.locator('.activity-list#ankh-notifications').count(),1,'Merged notifications list missing');
  const activityText=await page.locator('.notification-activity').allInnerTexts();
  assert(activityText.length>=2,'Activity items missing from Notifications');
  assert(activityText[0].includes('Newer fixture')&&activityText[1].includes('Older fixture'),'Notifications must be newest first');
  for(const button of await page.locator('.notification-list button').all()){
   const box=await button.boundingBox();assert(box&&box.height>=44,'Notification action target under 44px');
  }
  const n=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
  assert(n.scrollWidth<=n.width+2,'390px notifications horizontal overflow '+JSON.stringify(n));
  // The bell must link to the merged Notifications screen.
  await page.goto(host+'/?view=dashboard');
  assert.match(await page.locator('#ankh-notification-button').getAttribute('href'),/view=notifications/,'Desktop bell must link to Notifications');
  await page.goto(host+'/?view=more');
  assert.equal(await page.locator('.more-card',{hasText:'Settings'}).count(),1,'More needs one Settings entry');
  assert.equal(await page.locator('.more-card',{hasText:'Notifications'}).count(),1,'More needs one Notifications entry');
  assert.equal(await page.getByRole('button',{name:'Sign out'}).count(),1,'More must keep Sign out');
  assert.deepEqual(errors,[]);
  console.log('PASS: Settings has Sheets/Website/Alerts at 375/390/430px with no overflow; legacy routes redirect and POSTs return to the matching tab; Notifications merges activity newest-first with 44px targets and the bell links there.');
 }finally{if(browser)await browser.close();server.kill();rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1)});
