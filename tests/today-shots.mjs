// iPhone UX audit: Safari engine, isolated fixture data, every view. Writes ux-shots/ screenshots of Today and Stock.
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
const browser=await webkit.launch();const log=[];
try{
 const phone=await browser.newContext({...devices['iPhone 15 Pro']});const p=await phone.newPage();p.on('console',m=>{if(m.type()==='error')log.push('phone: '+m.text())});p.on('pageerror',e=>log.push('phone pageerror: '+e.message));
 await signIn(p);
 await p.goto(host+'/index.php?view=dashboard',{waitUntil:'domcontentloaded'});await p.waitForTimeout(150);await p.screenshot({path:join(out,'phone_today_motion.png')});
 await p.waitForTimeout(2200);await p.screenshot({path:join(out,'phone_today_top.png')});
 const js=await p.evaluate(()=>({motion:document.documentElement.classList.contains('ankh-motion'),hero:!!document.querySelector('.today-hero'),spark:!!document.querySelector('.is-drawn'),scrollW:document.documentElement.scrollWidth,w:innerWidth}));log.push('phone state '+JSON.stringify(js));
 await p.evaluate(()=>scrollTo(0,innerHeight*0.85));await p.waitForTimeout(400);await p.screenshot({path:join(out,'phone_today_s1.png')});
 await p.goto(host+'/index.php?view=stock');await p.waitForTimeout(1200);await p.screenshot({path:join(out,'phone_stock.png')});
 const desk=await browser.newContext({viewport:{width:1366,height:900}});const d=await desk.newPage();d.on('pageerror',e=>log.push('desk pageerror: '+e.message));
 await signIn(d);await d.goto(host+'/index.php?view=dashboard');await d.waitForTimeout(2200);await d.screenshot({path:join(out,'desk_today.png'),fullPage:true});
 await d.goto(host+'/index.php?view=stock');await d.waitForTimeout(1200);await d.screenshot({path:join(out,'desk_stock.png')});
}catch(e){log.push('error '+String(e).slice(0,300));}
finally{writeFileSync(join(out,'log.txt'),log.join('\n')+'\n');await browser.close();server.kill();}
console.log(log.join('\n'));process.exit(0);
