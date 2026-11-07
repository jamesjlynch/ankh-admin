// iPhone/desktop audit for the 10 UX upgrades. Isolated fixture data, WebKit.
import { webkit,chromium } from 'playwright';
import { mkdtempSync,mkdirSync,writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join,dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync,spawn } from 'node:child_process';
for(const k of Object.keys(process.env))if(/proxy/i.test(k))delete process.env[k];
const root=dirname(dirname(fileURLToPath(import.meta.url)));
const out=join(root,'ux-shots');mkdirSync(out,{recursive:true});
const temp=mkdtempSync(join(tmpdir(),'ankh-upgrades-'));
const db=join(temp,'fixture.sqlite'),config=join(temp,'config.php'),seedFile=join(temp,'seed.php');
const sessions=join(temp,'sessions');mkdirSync(sessions,{mode:0o700});
const hash=execFileSync('php',['-r','echo password_hash("2468",PASSWORD_DEFAULT);'],{encoding:'utf8'}).trim();
const lit=v=>"'"+String(v).replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
writeFileSync(config,'<?php return ["password_hash"=>'+lit(hash)+',"database_path"=>'+lit(db)+'];');
const port=18767,host='http://127.0.0.1:'+port;
const server=spawn('php',['-d','session.save_path='+sessions,'-S','127.0.0.1:'+port,'-t',root],{cwd:root,env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1',PHP_CLI_SERVER_WORKERS:'6'},stdio:['ignore','pipe','pipe']});
for(let i=0;i<80;i++){try{const r=await fetch(host+'/index.php');if(r.ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}

writeFileSync(seedFile,`<?php
$db=new PDO('sqlite:'.getenv('D'));
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$tz=new DateTimeZone('Europe/London');
$today=new DateTimeImmutable('today',$tz);
$day=function(int $offset) use ($today): string { return $today->modify(($offset>=0?'+':'').$offset.' days')->format('Y-m-d'); };
$stamp=function(int $offset,int $hour=10) use ($today): string { return $today->modify(($offset>=0?'+':'').$offset.' days')->setTime($hour,0)->format('c'); };
$customers=[
 [1,'Alice Hart','07700900001','12 Oak Lane, Liverpool L1 2AB'],
 [2,'Ben Carter','07700900002','48 King Street, Manchester M2 4WQ'],
 [3,'Chloe Bennett','07700900003','7 Elm Road, Leeds LS1 4AP'],
 [4,'Daniel Osei','07700900004','23 Queen Avenue, Birmingham B1 1TT'],
 [5,'Emma Walsh','07700900005','91 Church Walk, Bristol BS1 5TR'],
 [6,'Farah Khan','07700900006','5 Mill Crescent, Sheffield S1 2GH']
];
$insCustomer=$db->prepare('INSERT INTO customers(id,name,phone,address,created) VALUES (?,?,?,?,?)');
foreach($customers as $index=>$customer)$insCustomer->execute([$customer[0],$customer[1],$customer[2],$customer[3],$stamp(-70+$index*3)]);
$customerById=[];foreach($customers as $customer)$customerById[$customer[0]]=$customer;

$setStock=$db->prepare('UPDATE products SET stock_tracking=1,stock_tracking_since=?,stock_jay_qty=?,stock_tony_qty=?,low_stock_at=? WHERE name=?');
$stockRows=[
 ['Retatrutide 10mg',3,0,5],
 ['BPC-157 10mg',1,1,5],
 ['GHK-CU 50mg',-2,0,5],
 ['Retatrutide 20mg',15,5,5]
];
foreach($stockRows as $stockRow)$setStock->execute([$day(-60),$stockRow[1],$stockRow[2],$stockRow[3],$stockRow[0]]);

$productByName=$db->prepare('SELECT id,cost FROM products WHERE name=? LIMIT 1');
$insOrder=$db->prepare('INSERT INTO orders(customer,recipient_name,phone,address,notes,status,created,payment_date,delivery_date,payment_method,delivery_method,tracking_reference,delivery_charge,postage_cost,payment_fee,assigned_to,scheduled_delivery_date,referrer,presentation,pricing_type,back_order,reconstituted,packaged) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$insItem=$db->prepare('INSERT INTO items(order_id,product_id,name,price,cost,presentation,presentation_cost,base_price,discount,quantity,recurring,cycle_weeks) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$insPayment=$db->prepare('INSERT INTO payments(order_id,amount,method,note,created) VALUES (?,?,?,?,?)');
$addOrder=function(int $customerId,array $spec) use ($db,$customerById,$productByName,$insOrder,$insItem,$insPayment,$day,$stamp){
 $customer=$customerById[$customerId];
 $total=0;foreach($spec['items'] as $line)$total+=(int)$line[1]*(int)$line[2];
 $created=$stamp($spec['created'],9);
 $paymentDate=isset($spec['payment'])&&$spec['payment']!==null?$day($spec['payment']):'';
 $deliveryDate=isset($spec['delivery'])&&$spec['delivery']!==null?$day($spec['delivery']):'';
 $scheduled=isset($spec['scheduled'])&&$spec['scheduled']!==null?$day($spec['scheduled']):'';
 $insOrder->execute([
  $customer[1],'', $customer[2], $customer[3], $spec['notes']??'Fixture order', $spec['status'], $created, $paymentDate, $deliveryDate,
  $spec['method']??'Bank Transfer', 'Local Delivery', '', 0, 0, 0, $spec['assigned']??'James', $scheduled, '',
  'Vial', 'standard', (int)($spec['back']??0), (int)($spec['recon']??0), (int)($spec['packaged']??0)
 ]);
 $orderId=(int)$db->lastInsertId();
 foreach($spec['items'] as $line){
  $productByName->execute([$line[0]]);$product=$productByName->fetch(PDO::FETCH_ASSOC);
  if(!$product)continue;
  $insItem->execute([$orderId,(int)$product['id'],$line[0],(int)$line[1],$product['cost'],$line[3]??'Vial',0,(int)$line[1],0,(int)$line[2],(int)($line[4]??0),(int)($line[5]??0)]);
 }
 $fraction=(float)($spec['paid_fraction']??0);
 if($fraction>0){
  $amount=max(1,(int)round($total*$fraction));
  $paymentStamp=$stamp(isset($spec['payment'])&&$spec['payment']!==null?$spec['payment']:$spec['created'],11);
  $insPayment->execute([$orderId,$amount,$spec['method']??'Bank Transfer','Fixture payment',$paymentStamp]);
 }
 return $orderId;
};

$alicePaid=$addOrder(1,['status'=>'Delivered','created'=>-50,'payment'=>-50,'delivery'=>-49,'paid_fraction'=>1,'items'=>[['Retatrutide 10mg',4500,1]]]);
$benPaid=$addOrder(2,['status'=>'Delivered','created'=>-35,'payment'=>-35,'delivery'=>-34,'paid_fraction'=>1,'items'=>[['Retatrutide 20mg',9000,1]]]);
$chloePart=$addOrder(3,['status'=>'Packed','created'=>-21,'payment'=>-20,'delivery'=>-20,'paid_fraction'=>0.4,'items'=>[['Retatrutide 10mg',4500,1]]]);
$danielDue=$addOrder(4,['status'=>'New','created'=>-10,'scheduled'=>0,'items'=>[['BPC-157 10mg',2699,1]]]);
$emmaDue=$addOrder(5,['status'=>'Packed','created'=>-4,'payment'=>-3,'scheduled'=>0,'paid_fraction'=>0.34,'items'=>[['Retatrutide 10mg',4500,1]]]);
$farahDue=$addOrder(6,['status'=>'New','created'=>-1,'scheduled'=>0,'items'=>[['Tesamorelin 10mg',4499,1]]]);
$aliceOverdue=$addOrder(1,['status'=>'New','created'=>-15,'scheduled'=>-2,'items'=>[['Retatrutide 20mg',9000,1]]]);
$benBack=$addOrder(2,['status'=>'New','created'=>-20,'scheduled'=>0,'back'=>1,'items'=>[['Retatrutide 10mg',4500,2]]]);
$chloeCancelled=$addOrder(3,['status'=>'Cancelled','created'=>-6,'scheduled'=>0,'items'=>[['BPC-157 10mg',2699,1]]]);
$danielPaid=$addOrder(4,['status'=>'Delivered','created'=>-2,'payment'=>0,'delivery'=>-1,'paid_fraction'=>1,'items'=>[['Retatrutide 10mg',4500,1]]]);
$emmaPaid=$addOrder(5,['status'=>'Delivered','created'=>-6,'payment'=>-5,'delivery'=>-5,'paid_fraction'=>1,'items'=>[['BPC-157 10mg',2699,1]]]);
$farahPaid=$addOrder(6,['status'=>'Delivered','created'=>-40,'payment'=>-40,'delivery'=>-39,'paid_fraction'=>1,'items'=>[['GHK-CU 50mg',2250,1]]]);

$insCycle=$db->prepare('INSERT INTO reta_cycles(customer_name,recipient_name,phone,source_order_id,last_order_id,last_order_date,last_delivery_date,next_due_date,expected_value,expected_cost,expected_profit,cost_missing,product_summary,product_name,presentation,quantity,cycle_days,cycle_weeks,active,created,updated) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)');
$now=$stamp(0,8);
$insCycle->execute(['Alice Hart','','07700900001',$alicePaid,$alicePaid,$day(-50),$day(-49),$day(2),4500,790,3710,0,'1 × Retatrutide 10mg','Retatrutide 10mg','Vial',1,28,4,$now,$now]);
$insCycle->execute(['Emma Walsh','','07700900005',$emmaPaid,$emmaPaid,$day(-6),$day(-5),$day(5),2699,484,2215,0,'1 × BPC-157 10mg','BPC-157 10mg','Vial',1,28,4,$now,$now]);
`,{encoding:'utf8'});
try{execFileSync('php',[seedFile],{env:{...process.env,D:db},stdio:'inherit'});}catch(e){console.log('seed warning',String(e).slice(0,500));}

const browser=await (process.env.SHOT_BROWSER==='chromium'?chromium:webkit).launch();const log=[];
async function signIn(page){
 await page.goto(host+'/index.php');
 const u=page.locator('input[name=username]');
 if(await u.count()&&await u.isVisible())await u.fill('admin');
 await page.locator('input[name=password]').fill('2468');
 await Promise.all([page.waitForLoadState('load'),page.locator('form:has(input[name=password]) button, form:has(input[name=password]) [type=submit]').first().click()]);
 await page.waitForTimeout(500);
}
async function goto(p,path,wait='load'){await p.goto(host+'/index.php'+path,{waitUntil:wait});}
async function unlockFullPage(p){
 await p.evaluate(()=>{
  if(!document.getElementById('ankh-shot-fullpage')){
   const style=document.createElement('style');style.id='ankh-shot-fullpage';
   style.textContent='html,body,.admin-app-shell{height:auto!important;min-height:0!important;overflow:visible!important}#ankh-main{height:auto!important;max-height:none!important;overflow:visible!important}.mobile-nav,.ankh-assistant-button{display:none!important}.admin-app-shell .wizard-actions,.admin-app-shell .add-another-product,.admin-app-shell .edit-total{position:static!important;bottom:auto!important;box-shadow:none!important;backdrop-filter:none!important}';
   document.head.append(style);
  }
  document.getElementById('ankh-main')?.scrollTo(0,0);scrollTo(0,0);
 });
 await p.waitForTimeout(100);
}
async function overflow(p,tag,name){
 const s=await p.evaluate(()=>{const main=document.getElementById('ankh-main');return {sw:document.documentElement.scrollWidth,msw:main?main.scrollWidth:0,w:innerWidth};});
 const widest=Math.max(s.sw,s.msw);
 if(widest>s.w)log.push(`${tag} ${name} horizontal overflow ${widest}>${s.w}`);
}
async function shoot(p,tag,name,{delay=650,fullPage=true}={}){
 await p.waitForTimeout(delay);
 if(fullPage)await unlockFullPage(p);
 await p.screenshot({path:join(out,`up_${tag}_${name}.png`),fullPage});
 await overflow(p,tag,name);
}
function mobile(width){
 const height=width===375?812:width===390?844:932;
 return {viewport:{width,height},deviceScaleFactor:3,isMobile:true,hasTouch:true};
}
async function runMobile(width){
 const tag=String(width);const ctx=await browser.newContext(mobile(width));const p=await ctx.newPage();
 p.on('pageerror',e=>log.push(tag+' pageerror: '+e.message));
 p.on('console',m=>{if(m.type()==='error')log.push(tag+' console: '+m.text())});
 try{
  await signIn(p);

  await goto(p,'?view=dashboard');await p.waitForSelector('.today-hero, .reorder-due-card, .today-low-stock',{timeout:10000});
  await shoot(p,tag,'today',{delay:1300});

  const toasted=await p.evaluate(()=>{if(typeof window.ankhToast!=='function')return false;window.ankhToast('Payment recorded · Daniel Osei',{timeout:20000});return true;});
  await p.waitForSelector('.ankh-toast',{timeout:3000});
  await shoot(p,tag,'toast',{delay:250});

  await goto(p,'?view=dashboard','domcontentloaded');
  let skeleton=false;
  try{await p.waitForSelector('.ankh-skeleton',{timeout:1500});skeleton=true;}catch{}
  if(skeleton){await unlockFullPage(p);await p.screenshot({path:join(out,`up_${tag}_skeleton.png`),fullPage:true});await overflow(p,tag,'skeleton');}
  else log.push(`${tag} skeleton skipped (not visible)`);

  await goto(p,'?view=orders');await p.waitForSelector('[data-order-card]',{timeout:10000});
  await p.waitForSelector('[data-order-card] .ankh-order-actions',{timeout:5000});
  await p.evaluate(()=>{
   const card=document.querySelector('[data-order-card][data-order-paid="0"][data-order-cancelled="0"][data-order-back-order="0"]');
   const summary=card?.querySelector('summary');if(!summary)return;
   const r=summary.getBoundingClientRect(),y=r.top+Math.min(46,Math.max(20,r.height/2));
   const base={bubbles:true,cancelable:true,pointerType:'touch',isPrimary:true,pointerId:7,clientY:y};
   summary.dispatchEvent(new PointerEvent('pointerdown',{...base,clientX:r.right-28}));
   summary.dispatchEvent(new PointerEvent('pointermove',{...base,clientX:r.right-170}));
  });
  await p.waitForTimeout(250);
  await shoot(p,tag,'orders_swipe',{delay:300});

  await goto(p,'?view=dashboard');await p.waitForTimeout(500);await p.keyboard.press('/');
  await p.waitForSelector('#ankh-search-sheet:not([hidden])',{timeout:3000});
  await p.fill('#ankh-search-input','Alice');
  await p.waitForSelector('.ankh-search-result',{timeout:5000});
  await shoot(p,tag,'search',{delay:500});

  await goto(p,'?view=balances');await p.waitForSelector('.balance-account-card',{timeout:10000});
  await p.waitForSelector('.balance-age-chip',{timeout:5000});
  await shoot(p,tag,'balances',{delay:800});

  await p.locator('[data-balance-reminder]').first().click();
  await p.waitForSelector('#balance-reminder-sheet:not([hidden])',{timeout:3000});
  await p.waitForSelector('#balance-reminder-whatsapp:not([hidden])',{timeout:3000});
  await shoot(p,tag,'reminder',{delay:450});

  await goto(p,'?view=customer&id=1');await p.waitForSelector('.customer-profile',{timeout:10000});
  await p.waitForSelector('.customer-recent-order',{timeout:5000});
  const repeatHref=await p.locator('.customer-repeat-button, a[href*="repeat_order="]').first().getAttribute('href');
  await shoot(p,tag,'client',{delay:700});

  await p.goto(repeatHref?new URL(repeatHref,host+'/index.php').toString():host+'/index.php?view=new&repeat_order=7');
  await p.waitForSelector('#order-wizard',{timeout:10000});
  await p.locator('[data-wizard-next="2"]').click();
  await p.waitForSelector('.order-line-card',{timeout:8000});
  await shoot(p,tag,'repeat',{delay:700});

  await goto(p,'?view=reports');await p.waitForSelector('.takings-hero',{timeout:10000});
  await shoot(p,tag,'reports',{delay:900});
  await p.locator('[data-takings-tab="year"]').click();
  await p.waitForSelector('[data-takings-panel="year"]:not([hidden])',{timeout:3000});
  await shoot(p,tag,'reports_year',{delay:500});

  await goto(p,'?view=stock&filter=low');await p.waitForSelector('.stock-low-banner:not([hidden])',{timeout:10000});
  await shoot(p,tag,'stock',{delay:800});

  await goto(p,'?view=new&customer_id=1');await p.waitForSelector('#order-wizard',{timeout:10000});
  await p.locator('[data-wizard-next="2"]').click();
  await p.waitForSelector('#product-search',{timeout:5000});
  await p.fill('#product-search','Reta');
  await p.waitForSelector('#product-results .product-result',{timeout:5000});
  await p.locator('#product-results .product-result').first().click();
  await p.waitForSelector('#strength-options button',{timeout:5000});
  const ten=p.locator('#strength-options button',{hasText:'10mg'}).first();
  if(await ten.count())await ten.click();else await p.locator('#strength-options button').first().click();
  await p.waitForSelector('.order-line-card',{timeout:5000});
  await p.locator('.order-line-card [data-line-format="Vial"]').first().click().catch(()=>{});
  await p.locator('[data-line-quantity]').first().fill('4');
  await p.waitForSelector('.line-stock-warning:not([hidden])',{timeout:5000});
  await shoot(p,tag,'new_order_step2',{delay:600});

  await goto(p,'?view=run');await p.waitForSelector('[data-delivery-run]',{timeout:10000});
  await p.waitForSelector('.delivery-run-route',{timeout:5000});
  await shoot(p,tag,'delivery_run',{delay:800});
 }catch(e){log.push(tag+' error '+String(e).slice(0,600));}
 finally{await ctx.close();}
}
async function runDesktop(){
 const tag='1366';const ctx=await browser.newContext({viewport:{width:1366,height:900}});const p=await ctx.newPage();
 p.on('pageerror',e=>log.push(tag+' pageerror: '+e.message));
 p.on('console',m=>{if(m.type()==='error')log.push(tag+' console: '+m.text())});
 try{
  await signIn(p);

  await goto(p,'?view=dashboard');await p.waitForSelector('.today-hero, .reorder-due-card, .today-low-stock',{timeout:10000});
  await shoot(p,tag,'today',{delay:1300});

  await p.evaluate(()=>{if(typeof window.ankhToast==='function')window.ankhToast('Payment recorded · Daniel Osei',{timeout:20000});});
  await p.waitForSelector('.ankh-toast',{timeout:3000});
  await shoot(p,tag,'toast',{delay:250});

  await goto(p,'?view=dashboard','domcontentloaded');
  let skeleton=false;
  try{await p.waitForSelector('.ankh-skeleton',{timeout:1500});skeleton=true;}catch{}
  if(skeleton){await unlockFullPage(p);await p.screenshot({path:join(out,`up_${tag}_skeleton.png`),fullPage:true});await overflow(p,tag,'skeleton');}
  else log.push(`${tag} skeleton skipped (not visible)`);

  await goto(p,'?view=orders');await p.waitForSelector('[data-order-card]',{timeout:10000});
  await p.waitForSelector('[data-order-card] .ankh-order-actions',{timeout:5000});
  await p.locator('[data-order-card]').first().hover();
  await shoot(p,tag,'orders_swipe',{delay:500});

  await goto(p,'?view=dashboard');await p.waitForTimeout(500);await p.keyboard.press('/');
  await p.waitForSelector('#ankh-search-sheet:not([hidden])',{timeout:3000});
  await p.fill('#ankh-search-input','Alice');
  await p.waitForSelector('.ankh-search-result',{timeout:5000});
  await shoot(p,tag,'search',{delay:500});

  await goto(p,'?view=balances');await p.waitForSelector('.balance-account-card',{timeout:10000});
  await p.waitForSelector('.balance-age-chip',{timeout:5000});
  await shoot(p,tag,'balances',{delay:800});

  await p.locator('[data-balance-reminder]').first().click();
  await p.waitForSelector('#balance-reminder-sheet:not([hidden])',{timeout:3000});
  await p.waitForSelector('#balance-reminder-whatsapp:not([hidden])',{timeout:3000});
  await shoot(p,tag,'reminder',{delay:450});

  await goto(p,'?view=customer&id=1');await p.waitForSelector('.customer-profile',{timeout:10000});
  await p.waitForSelector('.customer-recent-order',{timeout:5000});
  const repeatHref=await p.locator('.customer-repeat-button, a[href*="repeat_order="]').first().getAttribute('href');
  await shoot(p,tag,'client',{delay:700});

  await p.goto(repeatHref?new URL(repeatHref,host+'/index.php').toString():host+'/index.php?view=new&repeat_order=7');
  await p.waitForSelector('#order-wizard',{timeout:10000});
  await p.locator('[data-wizard-next="2"]').click();
  await p.waitForSelector('.order-line-card',{timeout:8000});
  await shoot(p,tag,'repeat',{delay:700});

  await goto(p,'?view=reports');await p.waitForSelector('.takings-hero',{timeout:10000});
  await shoot(p,tag,'reports',{delay:900});
  await p.locator('[data-takings-tab="year"]').click();
  await p.waitForSelector('[data-takings-panel="year"]:not([hidden])',{timeout:3000});
  await shoot(p,tag,'reports_year',{delay:500});

  await goto(p,'?view=stock&filter=low');await p.waitForSelector('.stock-low-banner:not([hidden])',{timeout:10000});
  await shoot(p,tag,'stock',{delay:800});

  await goto(p,'?view=new&customer_id=1');await p.waitForSelector('#order-wizard',{timeout:10000});
  await p.locator('[data-wizard-next="2"]').click();
  await p.waitForSelector('#product-search',{timeout:5000});
  await p.fill('#product-search','Reta');
  await p.waitForSelector('#product-results .product-result',{timeout:5000});
  await p.locator('#product-results .product-result').first().click();
  await p.waitForSelector('#strength-options button',{timeout:5000});
  const ten=p.locator('#strength-options button',{hasText:'10mg'}).first();
  if(await ten.count())await ten.click();else await p.locator('#strength-options button').first().click();
  await p.waitForSelector('.order-line-card',{timeout:5000});
  await p.locator('.order-line-card [data-line-format="Vial"]').first().click().catch(()=>{});
  await p.locator('[data-line-quantity]').first().fill('4');
  await p.waitForSelector('.line-stock-warning:not([hidden])',{timeout:5000});
  await shoot(p,tag,'new_order_step2',{delay:600});

  await goto(p,'?view=run');await p.waitForSelector('[data-delivery-run]',{timeout:10000});
  await p.waitForSelector('.delivery-run-route',{timeout:5000});
  await shoot(p,tag,'delivery_run',{delay:800});
 }catch(e){log.push(tag+' error '+String(e).slice(0,600));}
 finally{await ctx.close();}
}
try{
 const only=(process.env.SHOTS_ONLY||'').split(',').map(v=>v.trim()).filter(Boolean);
 const widths=[375,390,430,1366].filter(w=>!only.length||only.includes(String(w)));
 for(const width of widths){
  if(width===1366)await runDesktop();else await runMobile(width);
 }
}catch(e){log.push('error '+String(e).slice(0,800));}
finally{
 writeFileSync(join(out,'up_log.txt'),log.join('\n')+'\n');
 await browser.close();
 server.kill('SIGTERM');
}
console.log(log.join('\n'));process.exit(0);
