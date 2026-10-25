'use strict';
/**
 * Live, read-only iPhone product-page checks. Public product browsing only.
 * No customer login, orders, payments or Admin mutations.
 */
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const root = process.env.ANKH_LIVE_CATALOGUE || 'https://order.ankhpeptides.com';
const out = path.resolve(process.env.ANKH_QA_SCREENSHOTS || 'ankh-mobile-qa-screenshots');
fs.mkdirSync(out, {recursive:true});
const devices = [
  {name:'compact-320',width:320,height:568},
  {name:'iphone-375',width:375,height:667},
  {name:'iphone-390',width:390,height:844},
  {name:'iphone-large-430',width:430,height:932},
  {name:'tablet-768',width:768,height:1024}
];
const failures=[];
const report=(name,ok,note='')=>{
  process.stdout.write((ok?'PASS':'FAIL')+' '+name+(note?' — '+note:'')+'\n');
  if(!ok)failures.push(name+(note?': '+note:''));
};
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));

(async()=>{
  const browser=await chromium.launch({headless:true});
  try {
    for (const d of devices) {
      const context=await browser.newContext({
        viewport:{width:d.width,height:d.height},
        deviceScaleFactor:2,isMobile:true,hasTouch:true,
        locale:'en-GB',colorScheme:'dark',reducedMotion:'reduce'
      });
      const page=await context.newPage();
      const jsErrors=[];
      page.on('pageerror',err=>jsErrors.push(err.message));
      let response;
      try {
        response=await page.goto(root+'/?product=101',{waitUntil:'domcontentloaded',timeout:45000});
        await page.locator('.sf-product-gallery').waitFor({timeout:20000});
        await page.locator('#sf-product-documents').waitFor({timeout:10000});
      } catch(err) {
        report(d.name+' loads product',false,String(err));
        await context.close();
        continue;
      }
      report(d.name+' product returns 200',response.status()===200,'HTTP '+response.status());
      const title=await page.title();
      report(d.name+' correct product',title.includes('BPC-157'));
      const metrics=await page.evaluate(()=>{
        const gallery=document.querySelector('.sf-product-gallery');
        const doc=document.querySelector('#sf-product-documents');
        const main=document.querySelector('#main');
        return {
          viewport:window.innerWidth, scrollWidth:document.documentElement.scrollWidth,
          h1:[...document.querySelectorAll('h1')].filter(x=>getComputedStyle(x).display!=='none').map(x=>x.textContent.trim()),
          galleryWidth:gallery?.getBoundingClientRect().width,
          documentsWidth:doc?.getBoundingClientRect().width,
          mainWidth:main?.getBoundingClientRect().width,
          hasSticky:!!document.querySelector('[data-product-sticky-action]'),
          supportsZoom:!!document.querySelector('[data-gallery-zoom]')
        };
      });
      report(d.name+' no page-level horizontal overflow',metrics.scrollWidth<=d.width+2,JSON.stringify({deviceWidth:d.width,innerWidth:metrics.viewport,scrollWidth:metrics.scrollWidth}));
      if(metrics.scrollWidth>d.width+2){
        const offenders=await page.evaluate(width=>[...document.querySelectorAll('body *')].map(el=>({el,r:el.getBoundingClientRect()}))
          .filter(x=>x.r.width>0&&x.r.right>width+2&&getComputedStyle(x.el).position!=='absolute')
          .sort((a,b)=>b.r.right-a.r.right).slice(0,16)
          .map(x=>({tag:x.el.tagName,cls:typeof x.el.className==='string'?x.el.className.slice(0,90):'',right:Math.round(x.r.right),width:Math.round(x.r.width)})),d.width);
        console.log('OVERFLOW-CANDIDATES '+d.name+' '+JSON.stringify(offenders));
      }
      report(d.name+' product heading visible',metrics.h1.some(x=>x.includes('BPC-157')));
      report(d.name+' gallery fits',metrics.galleryWidth<=metrics.viewport+1&&metrics.galleryWidth>180);
      report(d.name+' document panel fits',metrics.documentsWidth<=metrics.viewport+1&&metrics.documentsWidth>180);
      report(d.name+' gallery and zoom accessible',metrics.supportsZoom);
      report(d.name+' uses exactly one real photo plus CMS specification panel',
        (await page.locator('.sf-gallery-photo img').count())===1
         && (await page.locator('.sf-gallery-specification').count())===1
         && (await page.locator('.sf-gallery-spec-header').innerText()).includes('Product profile'));
      report(d.name+' premium photo framing present',
        (await page.locator('.sf-studio-brand').count())===1);
      const docStatus=await page.locator('.sf-product-document-status').innerText();
      report(d.name+' honest SDS status',docStatus.includes('Unverified safety draft'));
      const sheet=page.locator('.sf-product-doc-sds').first();
      report(d.name+' safety sheet available',await sheet.count()===1);
      const sheetUrl=await sheet.getAttribute('href');
      report(d.name+' correct SDS document linked',sheetUrl?.includes('product-safety-document.php?id=1'));
      report(d.name+' live interaction controller loaded',await page.evaluate(()=>document.body.classList.contains('sf-detail-interactions-ready')));
      report(d.name+' no JS crashes',jsErrors.length===0,jsErrors.join('; ').slice(0,250));
      await page.screenshot({path:path.join(out,d.name+'-product.png'),fullPage:true,animations:'disabled'});
      if(d.width===390){
        await page.evaluate(()=>window.scrollTo({top:0,behavior:'instant'}));
        await wait(100);
        report('iPhone sticky action initially hidden',await page.locator('[data-product-sticky-action]').evaluate(el=>!el.classList.contains('is-visible')));
        const selectors=page.locator('.sf-gallery-select [data-gallery-go]');
        await selectors.nth(1).click();
        report('gallery controls work',await selectors.nth(1).getAttribute('aria-pressed')==='true');
        await selectors.nth(0).click();
        const sdsReference=page.locator('.sf-product-doc-sds').first();
        await sdsReference.click();
        const viewer=page.locator('#sf-doc-viewer');
        report('premium document viewer opens in-page',await viewer.evaluate(el=>el.open));
        report('unverified SDS warning preserved in viewer',
          await page.locator('#sf-doc-viewer-warning').evaluate(el=>!el.hidden));
        report('document viewer uses exact published safety PDF',
          (await page.locator('#sf-doc-viewer-frame').getAttribute('src'))?.includes('product-safety-document.php?id=1'));
        report('PDF download remains available',
          (await page.locator('#sf-doc-viewer-download').getAttribute('href'))?.includes('product-safety-document.php?id=1'));
        await page.screenshot({path:path.join(out,'iphone-390-document-viewer.png'),animations:'disabled'});
        await page.locator('#sf-doc-viewer [data-doc-close]').first().click();
        report('PDF viewer closes and frees frame',await viewer.evaluate(el=>!el.open)
          && !(await page.locator('#sf-doc-viewer-frame').getAttribute('src')));
        const originalAction=page.locator('.sf-shop-inline [data-cart-id]');
        if(await originalAction.count()){
          await originalAction.click();
          report('premium add-to-basket peek appears',await page.locator('#sf-cart-peek').evaluate(el=>!el.hidden&&el.classList.contains('is-visible')));
          report('research-only preview clearly labelled', (await page.locator('#sf-peek-status').innerText()).includes('DEMO'));
          report('adding a product does not unexpectedly open basket',await page.locator('#sf-cart-drawer').evaluate(el=>!el.open));
          await page.locator('[data-cart-peek-view]').click();
          report('peek opens full basket after deliberate tap',await page.locator('#sf-cart-drawer').evaluate(el=>el.open));
          report('basket clearly separates demo from eligible requests', (await page.locator('#sf-cart-demo-count').innerText()).includes('demo'));
          const mobileSheetFits=await page.locator('#sf-cart-drawer').evaluate(el=>{
            const rect=el.getBoundingClientRect();
            return rect.left>=-2&&rect.right<=innerWidth+2&&rect.bottom<=innerHeight+2&&rect.top>=-2;
          });
          report('iPhone basket sheet fits mobile viewport',mobileSheetFits);
          await page.locator('#sf-cart-drawer [data-close-cart]').first().click();
          await originalAction.scrollIntoViewIfNeeded();
          await page.evaluate(()=>{
            const el=document.querySelector('.sf-shop-inline [data-cart-id]');
            if(!el)return;
            window.scrollTo({top:window.scrollY+el.getBoundingClientRect().bottom+120,behavior:'instant'});
          });
          await wait(450);
          const bar=page.locator('[data-product-sticky-action]');
          const visible=await bar.evaluate(el=>el.classList.contains('is-visible')&&getComputedStyle(el).visibility==='visible');
          const barDebug=await page.evaluate(()=>{
            const bar=document.querySelector('[data-product-sticky-action]');
            const primary=document.querySelector('.sf-shop-inline [data-cart-id]');
            const footer=document.querySelector('.sf-footer');
            return {scrollY:scrollY,bodyClass:document.body.className,barClass:bar?.className,barDisplay:getComputedStyle(bar).display,
              barInert:bar?.inert,primaryBottom:primary?.getBoundingClientRect().bottom,footerTop:footer?.getBoundingClientRect().top};
          });
          report('iPhone action rail appears after primary CTA',visible,JSON.stringify(barDebug));
          if(visible){
            const bottomSafe=await page.evaluate(()=>{
              const bar=document.querySelector('[data-product-sticky-action]');
              const nav=document.querySelector('.sf-bottom-menu');
              const a=bar.getBoundingClientRect(),b=nav.getBoundingClientRect();
              return a.bottom <= b.top+2;
            });
            report('action rail does not overlap bottom navigation',bottomSafe);
          }
          await page.locator('.sf-bottom-menu [data-open-cart]').click();
          report('basket drawer opens',await page.locator('#sf-cart-drawer').evaluate(el=>el.open));
          report('sticky rail hides behind basket',await bar.evaluate(el=>!el.classList.contains('is-visible')));
          await page.locator('#sf-cart-drawer [data-close-cart]').first().click();
        }
      }
      await context.close();
    }
    // Test a second real product route and source-of-truth variant navigation
    // only when Order Admin currently approves that product for publication.
    const siblingContext=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});
    const sibling=await siblingContext.request.get(root+'/?product=73',{timeout:30000});
    if(sibling.status()===200){
      const product=await siblingContext.newPage();
      await product.goto(root+'/?product=101',{waitUntil:'domcontentloaded'});
      report('approved BPC-157 strengths exposed as a real variant selector',
        (await product.locator('.sf-variants-premium').count())===1);
      const variant=product.locator('.sf-variant-tile[href*="product=73"]');
      report('10mg strength routes to its actual Order Admin product ID',await variant.count()===1);
      if(await variant.count()){
        await variant.first().click();
        report('variant opens correct published product page',
          (await product.title()).includes('BPC-157 10mg'));
      }
    } else {
      report('hidden/unpublished BPC-157 10mg remains unavailable',sibling.status()===404);
    }
    await siblingContext.close();
    // Validate actual public document delivery (not only a link in a card).
    const context=await browser.newContext();
    const response=await context.request.get(root+'/product-safety-document.php?id=1',{timeout:30000});
    report('SDS PDF link returns 200',response.ok(),'HTTP '+response.status());
    const bytes=await response.body();
    const headers=response.headers();
    report('SDS remains an unverified draft',headers['x-ankh-document-status']==='unverified-draft-not-approved-sds');
    report('SDS response is a PDF',headers['content-type']?.includes('application/pdf')&&bytes.subarray(0,5).toString()==='%PDF-');
    await context.close();
  } finally {await browser.close();}
  if(failures.length){console.error('LIVE MOBILE QA FAILURES: '+failures.join(' | '));process.exitCode=1;}
  else console.log('SUCCESS: iPhone product-page smoke tests, navigation, SDS downloads and modal stacking passed.');
})().catch(e=>{console.error(e);process.exitCode=1;});
