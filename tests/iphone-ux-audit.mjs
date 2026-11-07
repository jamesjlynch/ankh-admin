// iPhone UX audit: Safari engine, isolated fixture data, every view. Writes ux-audit/report.json + screenshots.
import { webkit, devices } from 'playwright';
import { mkdtempSync,mkdirSync,writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join,dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync,spawn } from 'node:child_process';
for(const k of Object.keys(process.env))if(/proxy/i.test(k))delete process.env[k];
const root=dirname(dirname(fileURLToPath(import.meta.url)));
const out=join(root,'ux-audit');mkdirSync(out,{recursive:true});
const temp=mkdtempSync(join(tmpdir(),'ankh-ux-'));
const db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
const sessions=join(temp,'sessions');mkdirSync(sessions,{mode:0o700});
const hash=execFileSync('php',['-r','echo password_hash("2468",PASSWORD_DEFAULT);'],{encoding:'utf8'}).trim();
const lit=v=>"'"+String(v).replaceAll("\\","\\\\").replaceAll("'","\\'")+"'";
writeFileSync(config,'<?php return ["password_hash"=>'+lit(hash)+',"database_path"=>'+lit(db)+'];');
const port=18765,host='http://127.0.0.1:'+port;
const server=spawn('php',['-d','session.save_path='+sessions,'-S','127.0.0.1:'+port,'-t',root],{cwd:root,env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1',PHP_CLI_SERVER_WORKERS:'6'},stdio:['ignore','pipe','pipe']});
for(let i=0;i<80;i++){try{const r=await fetch(host+'/index.php');if(r.ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
// Seed a few realistic fake orders so lists are not empty.
try{execFileSync('php',['-r',`$db=new PDO("sqlite:".getenv("D"));
$n=["Test Customer A","Test Customer B","Test Customer C","Test Customer D","Test Customer E"];$s=["New","New","Reconstituted","Packaged","Delivered"];
foreach($n as $i=>$c){$db->exec("INSERT INTO orders(customer,phone,address,notes,status,created,scheduled_delivery_date) VALUES ('$c','07000000000','1 Test Street, Liverpool L1 1AA','Fixture order with a fairly long note to test wrapping on narrow screens','".$s[$i]."','2026-11-0".($i+1)."T09:00:00Z','2026-11-0".($i+2)."')");
$id=$db->lastInsertId();$db->exec("INSERT INTO items(order_id,name,price,quantity) VALUES ($id,'Fixture Peptide 10mg',4500,".($i+1)."),($id,'Fixture Pen Cartridge',2500,1)");}`],{env:{...process.env,D:db}});}catch(e){console.log('seed warning',String(e).slice(0,300));}
const base=['dashboard','orders','new','customers','stock','products','reorder','supplier','supplies','packing_centre','packing','shipping','reports','balances','business_alerts','enquiries','website_cms','sheets','activity','today','saved','more'];
async function signIn(page){await page.goto(host+'/index.php');const u=page.locator('input[name=username]');if(await u.count()&&await u.isVisible())await u.fill('admin');await page.locator('input[name=password]').fill('2468');await Promise.all([page.waitForLoadState('load'),page.locator('form:has(input[name=password]) button, form:has(input[name=password]) [type=submit]').first().click()]);await page.waitForTimeout(500);if(await page.locator('input[name=password]').count())console.log('LOGIN FAILED');}
const report={generated:new Date().toISOString(),devices:{}};
const browser=await webkit.launch();
// discover extra views from More/dashboard links
{const c=await browser.newContext({...devices['iPhone 15 Pro']});const p=await c.newPage();await signIn(p);
 for(const v of ['more','dashboard']){await p.goto(host+'/index.php?view='+v);const hrefs=await p.$$eval('a[href*="view="]',a=>a.map(x=>new URL(x.href).searchParams.get('view')));for(const h of hrefs)if(h&&!base.includes(h)&&!/^(edit|customer|product|document)$/.test(h))base.push(h);}
 await c.close();}
for(const dev of ['iPhone SE','iPhone 15 Pro','iPhone 15 Pro Max']){
 const ctx=await browser.newContext({...devices[dev]});let page=await ctx.newPage();await signIn(page);
 const res=[];report.devices[dev]=res;
 for(const v of base){
  if(page.isClosed()){page=await ctx.newPage();}
  try{
  const errs=[];const onC=m=>{if(m.type()==='error')errs.push(m.text().slice(0,200))};const onE=e=>errs.push('pageerror: '+String(e).slice(0,200));
  page.on('console',onC);page.on('pageerror',onE);
  let status=0;const t0=Date.now();
  try{const r=await page.goto(host+'/index.php?view='+v,{waitUntil:'load',timeout:20000});status=r?r.status():0;}catch(e){errs.push('goto: '+String(e).slice(0,150));}
  const ms=Date.now()-t0;
  const m=await page.evaluate(()=>{
   const vis=e=>{const r=e.getBoundingClientRect();const s=getComputedStyle(e);return r.width>0&&r.height>0&&s.visibility!=='hidden'&&s.display!=='none'&&s.opacity!=='0'};
   const name=e=>(e.tagName.toLowerCase()+(e.id?'#'+e.id:'')+(e.className&&typeof e.className==='string'?'.'+e.className.trim().split(/\s+/).slice(0,2).join('.'):'')+' "'+(e.innerText||e.value||e.getAttribute('aria-label')||'').trim().slice(0,30)+'"');
   const W=innerWidth;
   const small=[...document.querySelectorAll('a,button,input,select,textarea,summary,[role=button]')].filter(vis).filter(e=>{const r=e.getBoundingClientRect();return (r.height<44||r.width<44)&&!(e.type==='hidden')}).map(name);
   const zoomInputs=[...document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea')].filter(vis).filter(e=>parseFloat(getComputedStyle(e).fontSize)<16).map(name);
   const tiny=[...document.querySelectorAll('body *')].filter(e=>e.childNodes.length&&[...e.childNodes].some(n=>n.nodeType===3&&n.textContent.trim())&&vis(e)&&parseFloat(getComputedStyle(e).fontSize)<12).map(name);
   const overflow=[...document.querySelectorAll('body *')].filter(vis).filter(e=>{const r=e.getBoundingClientRect();return r.right>W+2&&getComputedStyle(e).position!=='fixed'}).filter(e=>{let p=e.parentElement;while(p){const o=getComputedStyle(p).overflowX;if(o==='auto'||o==='scroll'||o==='hidden')return false;p=p.parentElement}return true}).map(name);
   const fixed=[...document.querySelectorAll('body *')].filter(e=>{const p=getComputedStyle(e).position;return (p==='fixed'||p==='sticky')&&vis(e)}).map(e=>({n:name(e),r:e.getBoundingClientRect().toJSON()}));
   const ov=[];for(let i=0;i<fixed.length;i++)for(let j=i+1;j<fixed.length;j++){const a=fixed[i].r,b=fixed[j].r;if(a.left<b.right&&b.left<a.right&&a.top<b.bottom&&b.top<a.bottom)ov.push(fixed[i].n+' X '+fixed[j].n)}
   const fixedArea=fixed.reduce((s,f)=>s+Math.max(0,Math.min(f.r.bottom,innerHeight)-Math.max(f.r.top,0))*Math.min(f.r.width,W),0)/(W*innerHeight);
   return {title:document.title,h1:(document.querySelector('h1')||{}).innerText||'',scrollWidth:document.documentElement.scrollWidth,width:W,pageHeight:document.documentElement.scrollHeight,screens:Math.round(document.documentElement.scrollHeight/innerHeight*10)/10,interactive:document.querySelectorAll('a,button,input,select,textarea,summary').length,smallTargets:small.length,smallSample:[...new Set(small)].slice(0,8),zoomInputs:zoomInputs.length,zoomSample:zoomInputs.slice(0,5),tinyText:tiny.length,tinySample:[...new Set(tiny)].slice(0,5),overflow:overflow.slice(0,5),fixedEls:fixed.map(f=>f.n),fixedOverlaps:ov.slice(0,5),fixedCoverPct:Math.round(fixedArea*100),loginShown:!!document.querySelector('input[name=password]')};});
  page.off('console',onC);page.off('pageerror',onE);
  writeFileSync(join(out,'report.json'),JSON.stringify(report,null,1));res.push({view:v,status,ms,errors:errs.slice(0,5),...m});
  const slug=dev.replace(/\s+/g,'-');
  if(dev!=='iPhone 15 Pro Max'){await page.screenshot({path:join(out,`${slug}_${v}_top.png`),timeout:15000}).catch(e=>console.log('shot fail',v));}
  if(dev==='iPhone 15 Pro')try{for(const k of [1,2]){await page.evaluate(k=>scrollTo(0,innerHeight*k*0.9),k);await page.waitForTimeout(150);await page.screenshot({path:join(out,`${slug}_${v}_s${k}.png`),timeout:15000}).catch(()=>{});}await page.evaluate(()=>scrollTo(0,0));}catch(e){console.log('scroll shot fail',v)}
 }catch(e){console.log('view crashed',dev,v,String(e).slice(0,120));res.push({view:v,crashed:String(e).slice(0,200)});try{await page.close()}catch{}}
 }
 // menu open state
 try{const t=page.locator('button[aria-label*="enu" i], .menu-toggle, .hamburger, [aria-controls]').first();await page.goto(host+'/index.php?view=dashboard');if(await t.count()){await t.click();await page.waitForTimeout(400);await page.screenshot({path:join(out,`${dev.replace(/\s+/g,'-')}_menu-open.png`)});}}catch{}
 await ctx.close();
}
writeFileSync(join(out,'report.json'),JSON.stringify(report,null,1));
await browser.close();server.kill();
console.log('UX audit done:',base.length,'views');
