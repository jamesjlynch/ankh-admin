import { chromium } from 'playwright';
import { mkdtempSync,writeFileSync,rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join,dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync,spawn } from 'node:child_process';
import { createServer } from 'node:net';

const root=dirname(dirname(fileURLToPath(import.meta.url)));
const temp=mkdtempSync(join(tmpdir(),'ankh-viewport-'));
const db=join(temp,'fixture.sqlite'),config=join(temp,'config.php');
const hash=execFileSync('php',['-r','echo password_hash("fixture-admin-only",PASSWORD_DEFAULT);'],{encoding:'utf8'}).trim();
const phpLiteral=value=>"'"+String(value).replaceAll("\\","\\\\").replaceAll("'","\\'")+"'";
writeFileSync(config,'<?php return ["password_hash"=>'+phpLiteral(hash)+',"database_path"=>'+phpLiteral(db)+'];');
const port=await new Promise((resolve,reject)=>{const s=createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
const host='http://127.0.0.1:'+port;
const server=spawn('php',['-S','127.0.0.1:'+port,'-t',root],{cwd:root,env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1'},stdio:['ignore','pipe','pipe']});
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
 check(await page.locator('form input[name=password]').count()===1,'Public browser must see login.');
 check(await page.locator('#dashboard-actions').count()===0,'Public browser saw private orders.');
 await page.locator('input[name=password]').fill('fixture-admin-only');
 await page.getByRole('button',{name:/Sign in/}).click();
 await page.waitForLoadState('domcontentloaded');
 check(await page.locator('#dashboard-actions').count()===1,'Fixture login failed: URL='+page.url()+' BODY='+((await page.locator('body').innerText()).slice(0,900)));
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
 await page.goto(host+'/index.php?view=more');
 await page.locator('input[name=legacy_password]').fill('fixture-admin-only');
 await page.locator('input[name=jay_password]').fill('jay-fixture-password-2026');
 await page.locator('input[name=tony_password]').fill('tony-fixture-password-2026');
 await page.getByRole('button',{name:/Activate individual accounts/}).click();
 await page.locator('.operator-security-panel').waitFor({state:'visible'});
 check((await page.locator('.operator-security-panel').innerText()).includes('Signed in as Jay'),'Jay account activation failed');
 await page.locator('aside form input[value=logout]').first().evaluate(el=>el.form.submit());
 await page.goto(host+'/index.php?view=dashboard');
 check(await page.locator('#dashboard-actions').count()===0,'Logout must hide private dashboard');
 await page.locator('select[name=operator_name]').selectOption('Tony');
 await page.locator('input[name=password]').fill('tony-fixture-password-2026');
 await page.getByRole('button',{name:/Sign in/}).click();
 await page.locator('#dashboard-actions').waitFor({state:'visible'});
 check((await page.locator('main.focus-app>header').innerText()).includes('Tony'),'Tony account attribution missing');
 await context.close();
 console.log('PASS: real browser sign-in, one-tap workflow, account activation, and 6 iPhone/iPad/desktop widths.');
}finally{
 await browser?.close();
 server.kill('SIGTERM');
 rmSync(temp,{recursive:true,force:true});
}
