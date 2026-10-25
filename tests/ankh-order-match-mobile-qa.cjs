'use strict';
/** Runs only against fictional staff matching fixtures, never production logins. */
const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
const {pathToFileURL}=require('node:url');
const fixture=process.env.ANKH_MATCH_RENDER_FILE;
if(!fixture||!fs.existsSync(fixture))throw Error('Fictional ANKH staff matching fixture is required.');
const directory=process.env.ANKH_QA_SCREENSHOTS||'ankh-mobile-qa-screenshots';
fs.mkdirSync(directory,{recursive:true});
const widths=[320,375,390,430,768],failures=[];
function check(value,message){console.log((value?'PASS ':'FAIL ')+message);if(!value)failures.push(message);}
(async()=>{
const browser=await chromium.launch({headless:true});
try{
 for(const width of widths){
  const context=await browser.newContext({viewport:{width,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true,colorScheme:'dark',reducedMotion:'reduce'});
  const page=await context.newPage(),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.goto(pathToFileURL(path.resolve(fixture)).href,{waitUntil:'domcontentloaded'});
  check(await page.locator('.pc-request-match').count()===1,width+'px protected order matching section appears');
  check(await page.locator('.pc-request-match-card').count()===2,width+'px two secure candidate cards visible');
  check(await page.locator('input[name="match_proof"]').count()===2,width+'px signed proof present for every candidate');
  check(await page.locator('input[name="confirm_link"][required]').count()===2,width+'px explicit confirmation required');
  check((await page.locator('.pc-match-different').count())===1,width+'px order-quantity mismatch clearly signposted');
  const rects=await page.evaluate(()=>({
    scroll:document.documentElement.scrollWidth,
    width:innerWidth,
    cards:[...document.querySelectorAll('.pc-request-match-card')].map(x=>{const r=x.getBoundingClientRect();return {left:r.left,right:r.right};}),
    buttons:[...document.querySelectorAll('.pc-request-match-confirm>button')].map(x=>{const r=x.getBoundingClientRect();return {width:r.width,height:r.height};})
  }));
  check(rects.scroll<=width+2,width+'px no horizontal overflow '+JSON.stringify(rects).slice(0,180));
  check(rects.cards.every(x=>x.left>=-2&&x.right<=width+2),width+'px candidate cards remain within viewport');
  check(rects.buttons.every(x=>x.height>=44),width+'px large staff confirmation touch targets');
  check(errors.length===0,width+'px no Javascript errors');
  await page.screenshot({path:path.join(directory,'ankh-staff-match-'+width+'.png'),fullPage:true,animations:'disabled'});
  await context.close();
 }
}finally{await browser.close();}
if(failures.length){console.error('ANKH STAFF MOBILE QA FAILED: '+failures.join(' | '));process.exitCode=1;}
else console.log('SUCCESS: Staff matching cards have accessible controls and no overflow across five mobile/tablet sizes.');
})().catch(e=>{console.error(e);process.exitCode=1;});
