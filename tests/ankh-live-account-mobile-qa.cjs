'use strict';
/** Read-only mobile browser QA against ANKH's fictional public customer preview.
 * Never logs in, alters records, accesses private customer data or submits forms.
 */
const { chromium }=require('playwright');
const path=require('node:path');
const fs=require('node:fs');
const assert=require('node:assert/strict');
const root=process.env.ANKH_LIVE_CATALOGUE||'https://order.ankhpeptides.com';
const screenshots=process.env.ANKH_QA_SCREENSHOTS||'ankh-mobile-qa-screenshots';
fs.mkdirSync(screenshots,{recursive:true});
const devices=[
  {name:'compact-320',width:320,height:568},
  {name:'iphone-375',width:375,height:667},
  {name:'iphone-390',width:390,height:844},
  {name:'iphone-430',width:430,height:932},
  {name:'tablet-768',width:768,height:1024}
];
const failures=[];
function verify(condition,message){console.log((condition?'PASS ':'FAIL ')+message);if(!condition)failures.push(message);}
(async()=>{
  const browser=await chromium.launch({headless:true});
  try{
    for(const d of devices){
      const context=await browser.newContext({
        viewport:{width:d.width,height:d.height},deviceScaleFactor:2,
        isMobile:true,hasTouch:true,colorScheme:'dark',reducedMotion:'reduce'
      });
      const page=await context.newPage(),errors=[];
      page.on('pageerror',err=>errors.push(err.message));
      let response;
      try{response=await page.goto(root+'/preview.php?screen=account&tab=home',{
        waitUntil:'domcontentloaded',timeout:35000});
        await page.locator('.pc-connect-dashboard').waitFor({timeout:10000});
      }catch(error){verify(false,d.name+' account preview load: '+String(error));await context.close();continue;}
      verify(response.status()===200,d.name+' account preview HTTP 200');
      verify(await page.locator('.pc-connect-dashboard').count()===1,d.name+' personal dashboard visible');
      verify(await page.getByText('Your ANKH, connected').count()>=1,d.name+' account heading visible');
      verify(await page.locator('.pc-connect-grid a').count()===3,d.name+' three real-data account cards');
      const view=await page.evaluate(()=>({
        innerWidth:window.innerWidth,
        width:document.documentElement.scrollWidth,
        main:document.querySelector('.pc-connect-dashboard')?.getBoundingClientRect().width,
        navs:document.querySelectorAll('.pc-bottom-nav').length
      }));
      verify(view.width<=d.width+2,d.name+' no horizontal overflow '+JSON.stringify(view));
      verify(view.navs===1,d.name+' exactly one mobile navigation');
      await page.screenshot({path:path.join(screenshots,'connected-'+d.name+'.png'),fullPage:true,animations:'disabled'});
      if(d.width===390){
        const requestNav=page.locator('.pc-connect-dashboard .pc-connect-card-primary');
        await requestNav.click();
        await page.waitForURL(/tab=requests/,{timeout:12000});
        verify((await page.locator('h1').allInnerTexts()).some(text=>text.includes('My Requests')),
          'iPhone dashboard request card opens tracking page');
        verify((await page.locator('.pc-tracking-claim').count())===0,
          'fictional public preview does not expose guest receipt claim action');
        await page.goBack({waitUntil:'domcontentloaded'});
        await page.locator('.pc-connect-dashboard').waitFor();
        await page.locator('.pc-connect-card').nth(1).click();
        await page.waitForURL(/tab=orders/,{timeout:12000});
        verify((await page.locator('h1').allInnerTexts()).some(text=>text.toLowerCase().includes('orders')),
          'iPhone active-orders card opens recorded order history');
      }
      verify(errors.length===0,d.name+' no JavaScript errors '+errors.join('; ').slice(0,130));
      await context.close();
    }
  }finally{await browser.close();}
  if(failures.length){console.error('ANKH CUSTOMER ACCOUNT QA FAILED: '+failures.join(' | '));process.exitCode=1;}
  else console.log('SUCCESS: Connected account responsive layout, private request navigation and order history work across all five mobile/tablet widths.');
})().catch(error=>{console.error(error);process.exitCode=1;});
