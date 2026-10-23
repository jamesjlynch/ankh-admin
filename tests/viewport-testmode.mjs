import { chromium } from 'playwright';
import { mkdtempSync,mkdirSync,writeFileSync,rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join,dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync,spawn } from 'node:child_process';
import { createServer } from 'node:net';

const root=dirname(dirname(fileURLToPath(import.meta.url)));
const temp=mkdtempSync(join(tmpdir(),'ankh-viewport-'));
const db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
const sessions=join(temp,'sessions');mkdirSync(sessions,{mode:0o700});
const hash=execFileSync('php',['-r','echo password_hash("fixture-admin-only",PASSWORD_DEFAULT);'],{encoding:'utf8'}).trim();
const phpLiteral=value=>"'"+String(value).replaceAll("\\","\\\\").replaceAll("'","\\'")+"'";
writeFileSync(config,'<?php return ["password_hash"=>'+phpLiteral(hash)+',"database_path"=>'+phpLiteral(db)+'];');
const port=await new Promise((resolve,reject)=>{const s=createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
const host='http://127.0.0.1:'+port;
const server=spawn('php',['-d','session.save_path='+sessions,'-S','127.0.0.1:'+port,'-t',root],{cwd:root,env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:['ignore','pipe','pipe']});
let serverErrors='';server.stderr.on('data',d=>{serverErrors+=d.toString().slice(-1000)});
let browser;
const check=(ok,message)=>{if(!ok)throw new Error(message);};
async function ready(){for(let i=0;i<70;i++){try{const r=await fetch(host+'/index.php');if(r.ok)return;}catch{}await new Promise(r=>setTimeout(r,100));}throw new Error('PHP test server failed: '+serverErrors.slice(-1000));}
async function layout(page,label){
 const m=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));
 check(m.scrollWidth<=m.width+2,label+' dashboard horizontal overflow '+JSON.stringify(m));
 check(await page.locator('.dashboard-stat-card').count()===6,label+' original metric cards missing');
 check(await page.locator('#dashboard-actions').count()===1,label+' worklist missing');
 check(await page.locator('aside.desktop-today-panel').count()===0,label+' unrequested sidebar returned');
 await page.goto(host+'/index.php?view=customers');
 check(await page.locator('[data-customer-insight]').count()===5,label+' customer filters missing');
 const w=await page.evaluate(()=>document.documentElement.scrollWidth);
 check(w<=m.width+2,label+' customer horizontal overflow '+w);
 await page.goto(host+'/index.php?view=dashboard');
}
try{
 await ready();
 browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const context=await browser.newContext({viewport:{width:390,height:844}});
 const page=await context.newPage();
 await page.goto(host+'/index.php?view=dashboard');
 check(await page.locator('#dashboard-actions').count()===1,'Unchanged test-mode dashboard must open directly.');
 check(await page.locator('form input[name="password"]').count()===0,'Test-mode access must not require a login.');
 // Fresh fixture records test rendering and manual workflow through genuine POST forms.
 const insert='$db=new PDO("sqlite:".getenv("ANKH_TEST_DB"));$db->exec("INSERT INTO orders(customer,phone,address,notes,status,created,scheduled_delivery_date) VALUES (\'Test Customer\',\'\',\'\',\'\',\'New\',\'2026-10-15T09:00:00Z\',\'2026-10-15\'); INSERT INTO items(order_id,name,price,quantity) VALUES (1,\'Test Product\',1000,1);");';
 execFileSync('php',['-r',insert],{env:{...process.env,ANKH_TEST_DB:db},encoding:'utf8'});
 for(const [width,height] of [[390,844],[768,1024],[820,1180],[1024,768],[1180,820],[1440,900]]){
  await page.setViewportSize({width,height});await page.goto(host+'/index.php?view=dashboard');await layout(page,width+'px');
 }
 await page.setViewportSize({width:820,height:1180});
 await page.goto(host+'/index.php?view=dashboard');
 check(await page.locator('#work-order-1').count()===1,'Fixture order missing from worklist.');
 await page.locator('#work-order-1 summary').first().click();
 check(await page.locator('#work-order-1 .operations-next-button').count()===1,'Next action missing');
 await page.locator('#work-order-1').getByRole('button',{name:/Mark reconstituted/}).click();
 await page.locator('#dashboard-actions').waitFor({state:'visible'});
 await page.locator('#work-order-1 summary').first().click();
 await page.locator('#work-order-1').getByRole('button',{name:/Mark packaged/}).click();
 await page.locator('#dashboard-actions').waitFor({state:'visible'});
 await page.locator('#work-order-1 summary').first().click();
 let confirmed=false;page.once('dialog',async d=>{confirmed=true;await d.accept();});
 await page.locator('#work-order-1').getByRole('button',{name:/Mark delivered today/}).click();
 await page.locator('#dashboard-actions').waitFor({state:'visible'});
 check(confirmed,'Manual delivery must require explicit confirmation.');
 await page.goto(host+'/index.php?view=activity');
 const activity=await page.locator('.activity-list').innerText();
 check(activity.includes('Preparation updated'),'Preparation audit history was not recorded.');
 check(activity.includes('Delivery recorded'),'Manual delivery was not recorded in audit history.');
 await page.goto(host+'/index.php?view=edit&id=1');
 check(await page.locator('.operator-order-history').count()===1,'Per-order activity history missing.');
 await page.locator('.operator-order-history summary').click();
 check((await page.locator('.operator-order-history').innerText()).includes('Preparation updated'),'Per-order history has no preparation activity.');
 const pricingSeed='$db=new PDO("sqlite:".getenv("ANKH_TEST_DB"));$db->exec("INSERT INTO customers(id,name,phone,address,created,pricing_type) VALUES(99001,\'Personal fixture\',\'\',\'\',\'2026-01-01\',\'free\'),(99002,\'Friend fixture\',\'\',\'\',\'2026-01-01\',\'family_friends\'); INSERT INTO products(id,name,price,family_friends_price,cost) VALUES(99001,\'Browser fixture 5mg\',5000,3700,1200);");';
 execFileSync('php',['-r',pricingSeed],{env:{...process.env,ANKH_TEST_DB:db},encoding:'utf8'});
 await page.setViewportSize({width:390,height:844});
 await page.goto(host+'/index.php?view=customer&id=99001');
 check(await page.locator('input[name="pricing_type"][value="free"]').isChecked(),'Free customer selection not shown');
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Customer pricing overflows on iPhone');
 await page.goto(host+'/index.php?view=new&customer_id=99001');
 const freeState=await page.evaluate(()=>{
  addOrderLine({product_id:99001,quantity:2,presentation:'Pen',price:50},false);reconcilePenRows();applyCustomerPricing();
  return {prices:selectedOrderCards().map(c=>lineData(c).price),type:activeCustomerPricing(),note:document.querySelector('#customer-pricing-note')?.textContent};
 });
 check(freeState.type==='free'&&freeState.prices.length===2&&freeState.prices.every(p=>p===0),'Personal customer must zero both peptide and paired pen');
 check(freeState.note.includes('No payment required'),'Free order needs visible pricing explanation');
 const friendPrice=await page.evaluate(()=>{chooseCustomer(customerSuggestions.find(c=>Number(c.id)===99002));return lineData(selectedOrderCards().find(c=>lineData(c).product_id===99001)).price;});
 check(friendPrice===37,'Switching Free to Family & Friends must restore saved family price');
 const standardPrice=await page.evaluate(()=>{chooseCustomer({name:'New standard customer',phone:'',address:''});return lineData(selectedOrderCards().find(c=>lineData(c).product_id===99001)).price;});
 check(standardPrice===50,'Switching to a standard customer must not retain free prices');
 await page.goto(host+'/index.php?view=reports&type=personal&range=all');
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Personal-use report overflows on iPhone');
 await context.close();
 console.log('PASS: original test-mode access, real one-tap preparation/delivery, audit history and 6 iPhone/iPad/desktop widths.');
}finally{
 await browser?.close();
 server.kill('SIGTERM');
 rmSync(temp,{recursive:true,force:true});
}
