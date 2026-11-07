// iPhone UX audit: Safari engine, isolated fixture data, every view. New order steps.
import { webkit, devices } from 'playwright';
import { mkdtempSync,mkdirSync,writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join,dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync,spawn } from 'node:child_process';
for(const k of Object.keys(process.env))if(/proxy/i.test(k))delete process.env[k];
const root=dirname(dirname(fileURLToPath(import.meta.url)));
const out=join(root,"ux-shots");mkdirSync(out,{recursive:true});
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
async function signIn(page){await page.goto(host+'/index.php');const u=page.locator('input[name=username]');if(await u.count()&&await u.isVisible())await u.fill('admin');await page.locator('input[name=password]').fill('2468');await Promise.all([page.waitForLoadState('load'),page.locator('form:has(input[name=password]) button, form:has(input[name=password]) [type=submit]').first().click()]);await page.waitForTimeout(500);}
try{execFileSync('php',['-r',`$db=new PDO("sqlite:".getenv("D"));foreach([["Retatrutide 5mg",9000],["Retatrutide 10mg",14000],["Retatrutide 20mg",22000],["Retatrutide 30mg",29000],["BPC-157 10mg",4500],["Tirzepatide 15mg",12000]] as $p){$st=$db->prepare("INSERT INTO products(name,price,active) VALUES (?,?,1)");$st->execute($p);}`],{env:{...process.env,D:db}});}catch(e){console.log('seed warning',String(e).slice(0,300));}
const browser=await webkit.launch();const log=[];
async function shoot(p,tag,name){await p.waitForTimeout(450);await p.screenshot({path:join(out,`no_${tag}_${name}.png`)});const s=await p.evaluate(()=>({sw:document.documentElement.scrollWidth,w:innerWidth}));if(s.sw>s.w)log.push(`${tag} ${name} horizontal overflow ${s.sw}>${s.w}`);}
async function flow(ctxOpts,tag){
 const ctx=await browser.newContext(ctxOpts);const p=await ctx.newPage();p.on('pageerror',e=>log.push(tag+' pageerror: '+e.message));
 await signIn(p);await p.goto(host+'/index.php?view=new');await shoot(p,tag,'1_customer');
 await p.fill('#customer-search','Test Shot Customer');await p.fill('#customer-address','1 Test Street, Liverpool L1 1AA').catch(()=>{});
 await p.locator('[data-wizard-next="2"]').click();await shoot(p,tag,'2_empty');
 await p.fill('#product-search','Reta');await shoot(p,tag,'3_results');
 await p.locator('#product-results [role=option], #product-results button, #product-results li').first().click().catch(e=>log.push(tag+' no result '+String(e).slice(0,120)));
 await shoot(p,tag,'4_strength');
 await p.locator('#strength-options button, #strength-options [role=option]').first().click().catch(e=>log.push(tag+' no strength '+String(e).slice(0,120)));
 await shoot(p,tag,'5_after_pick');
 const fmt=p.locator('.selected-order-lines button:visible, .format-options button:visible').first();
 await shoot(p,tag,'6_line');
 await p.evaluate(()=>{const m=document.querySelector('#ankh-main')||document.scrollingElement;m.scrollTo(0,m.scrollHeight);scrollTo(0,document.body.scrollHeight)});await shoot(p,tag,'7_bottom');
 await p.locator('[data-wizard-next="3"]').click().catch(e=>log.push(tag+' next3 '+String(e).slice(0,120)));await shoot(p,tag,'8_check');
 log.push(tag+' lines='+await p.locator('#selected-order-lines > *').count());
 await ctx.close();
}
try{
 await flow({...devices['iPhone SE']},'375');
 await flow({...devices['iPhone 15 Pro']},'393');
 await flow({...devices['iPhone 15 Pro Max']},'430');
 await flow({viewport:{width:1366,height:900}},'1366');
}catch(e){log.push('error '+String(e).slice(0,300));}
finally{writeFileSync(join(out,'no_log.txt'),log.join('\n')+'\n');await browser.close();server.kill();}
console.log(log.join('\n'));process.exit(0);
