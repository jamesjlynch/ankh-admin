// Full-app mobile audit: every whitelisted view, key detail pages and interactive states.
// Fixture seeding and sign-in mirror tests/upgrades-shots.mjs. ONE PHP server, ONE iPhone context.
import { webkit,chromium } from 'playwright';
import { mkdtempSync,mkdirSync,writeFileSync,readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join,dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync,spawn } from 'node:child_process';
for(const k of Object.keys(process.env))if(/proxy/i.test(k))delete process.env[k];
const root=dirname(dirname(fileURLToPath(import.meta.url)));
const out=join(root,'ux-shots','full');mkdirSync(out,{recursive:true});
const temp=mkdtempSync(join(tmpdir(),'ankh-fullapp-'));
const db=join(temp,'fixture.sqlite'),config=join(temp,'config.php'),seedFile=join(temp,'seed.php'),infoFile=join(temp,'info.php');
const sessions=join(temp,'sessions');mkdirSync(sessions,{mode:0o700});
const hash=execFileSync('php',['-r','echo password_hash("2468",PASSWORD_DEFAULT);'],{encoding:'utf8'}).trim();
const lit=v=>"'"+String(v).replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
writeFileSync(config,'<?php return ["password_hash"=>'+lit(hash)+',"database_path"=>'+lit(db)+'];');
const port=18771,host='http://127.0.0.1:'+port;
const server=spawn('php',['-d','session.save_path='+sessions,'-S','127.0.0.1:'+port,'-t',root],{cwd:root,env:{...process.env,ANKH_ADMIN_CONFIG:config,ANKH_TEST_ALLOW_HTTP:'1',PHP_CLI_SERVER_WORKERS:'4'},stdio:['ignore','pipe','pipe']});
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

writeFileSync(infoFile,`<?php
$db=new PDO('sqlite:'.getenv('D'));
$product=(int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$order=(int)$db->query("SELECT id FROM orders WHERE status<>'Cancelled' ORDER BY id LIMIT 1")->fetchColumn();
$unpaid=(int)$db->query("SELECT id FROM orders WHERE status NOT IN ('Cancelled','Delivered') ORDER BY id LIMIT 1")->fetchColumn();
echo json_encode(['product'=>$product,'order'=>$order,'unpaid'=>$unpaid]);
`,{encoding:'utf8'});
const info=JSON.parse(execFileSync('php',[infoFile],{env:{...process.env,D:db},encoding:'utf8'})||'{}');

const source=readFileSync(join(root,'index.php'),'utf8');
const whitelistMatch=source.match(/in_array\(\$_GET\['view'\][^\[]*\[([^\]]+)\]/);
const fallback=['enquiries','supplies','activity','dashboard','orders','packing','packing_centre','new','edit','products','product','supplier','website_cms','stock','fridge_jay','fridge_tony','customers','customer','balances','reta','sheets','reports','reorder','shipping','more','run','business_alerts','saved','stockboard','reportsboard'];
const whitelist=whitelistMatch?whitelistMatch[1].split(',').map(v=>v.trim().replace(/^['"]|['"]$/g,'')).filter(Boolean):fallback;
const smoke=process.env.FULL_SMOKE==='1';
const views=smoke?['dashboard','orders','more']:whitelist;

const log=[];
const report={generated:new Date().toISOString(),viewport:{width:390,height:844,deviceScaleFactor:2},smoke,views:{}};
let browser;
try{browser=await webkit.launch();}catch(e){log.push('webkit launch failed, chromium fallback: '+String(e).slice(0,300));browser=await chromium.launch();}
const context=await browser.newContext({viewport:{width:390,height:844},deviceScaleFactor:2,isMobile:true,hasTouch:true});
const page=await context.newPage();
let errors=[];
page.on('pageerror',e=>errors.push('pageerror: '+e.message));
page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});

async function nav(path){
 errors=[];
 const started=Date.now();
 try{
  await page.goto(host+'/index.php'+path,{waitUntil:'domcontentloaded',timeout:20000});
 }catch(e){
  log.push(`${path||'login'} navigation: ${String(e.message||e).slice(0,300)}`);
 }
 const loadMs=Date.now()-started;
 await page.waitForTimeout(450);
 return loadMs;
}
async function unlockFullPage(){
 await page.evaluate(()=>{
  if(!document.getElementById('ankh-shot-fullpage')){
   const style=document.createElement('style');style.id='ankh-shot-fullpage';
   style.textContent='html,body,.admin-app-shell{height:auto!important;min-height:0!important;overflow:visible!important}#ankh-main{height:auto!important;max-height:none!important;overflow:visible!important}.mobile-nav,.ankh-assistant-button{display:none!important}.admin-app-shell .wizard-actions,.admin-app-shell .add-another-product,.admin-app-shell .edit-total{position:static!important;bottom:auto!important;box-shadow:none!important;backdrop-filter:none!important}';
   document.head.append(style);
  }
  document.getElementById('ankh-main')?.scrollTo(0,0);scrollTo(0,0);
 });
 await page.waitForTimeout(100);
}
async function capture(key,path,loadMs){
 const metrics=await page.evaluate(()=>{
  const visible=el=>{
   if(!el||!el.getBoundingClientRect)return false;
   const style=getComputedStyle(el);
   if(style.display==='none'||style.visibility==='hidden'||style.opacity==='0')return false;
   const rect=el.getBoundingClientRect();
   return rect.width>0&&rect.height>0;
  };
  const targets=[...document.querySelectorAll('a,button,input,select,summary')].filter(visible);
  const undersized=targets.filter(el=>{const rect=el.getBoundingClientRect();return rect.width<44||rect.height<44;}).length;
  let smallText=0;
  const walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT,{acceptNode(node){
   if(!node.nodeValue||!node.nodeValue.trim())return NodeFilter.FILTER_REJECT;
   const parent=node.parentElement;
   if(!parent||/^(SCRIPT|STYLE|NOSCRIPT)$/.test(parent.tagName))return NodeFilter.FILTER_REJECT;
   return visible(parent)?NodeFilter.FILTER_ACCEPT:NodeFilter.FILTER_REJECT;
  }});
  while(walker.nextNode()){
   const parent=walker.currentNode.parentElement;
   if(parseFloat(getComputedStyle(parent).fontSize)<12)smallText++;
  }
  const main=document.getElementById('ankh-main');
  const overflowPx=Math.max(0,Math.max(document.documentElement.scrollWidth,main?main.scrollWidth:0)-innerWidth);
  const heading=[...document.querySelectorAll('h1')].find(visible);
  return {title:heading?heading.textContent.trim():'',overflowPx,tapTargetsUnder44:undersized,smallTextNodes:smallText};
 });
 report.views[key]={...metrics,consoleErrors:errors.slice(),loadMs,path};
 await page.evaluate(()=>{scrollTo(0,0);document.getElementById('ankh-main')?.scrollTo(0,0);});
 await page.screenshot({path:join(out,key+'_top.png')});
 try{
  await unlockFullPage();
  await page.screenshot({path:join(out,key+'_full.png'),fullPage:true});
 }catch(e){log.push(`${key} full-page screenshot: ${String(e.message||e).slice(0,200)}`);}
 log.push(`${key}: ${metrics.title||'(no h1)'} · overflow ${metrics.overflowPx}px · ${metrics.tapTargetsUnder44} tap<44 · ${metrics.smallTextNodes} text<12 · ${loadMs}ms${errors.length?' · '+errors.length+' console errors':''}`);
}

async function signIn(){
 const user=page.locator('input[name=username]');
 if(await user.count()&&await user.isVisible())await user.fill('admin');
 await page.locator('input[name=password]').fill('2468');
 await Promise.all([
  page.waitForNavigation({waitUntil:'domcontentloaded',timeout:20000}).catch(()=>{}),
  page.locator('form:has(input[name=password]) button, form:has(input[name=password]) [type=submit]').first().click()
 ]);
 await page.waitForTimeout(500);
}

try{
 // Login screen before authenticating.
 const loginMs=await nav('');
 await capture('login','',loginMs);
 await signIn();

 for(const view of views){
  const path='?view='+encodeURIComponent(view);
  const loadMs=await nav(path);
  await capture(view,path,loadMs);
 }

 if(!smoke){
  const detailPages=[
   ['edit_order','?view=edit&id='+encodeURIComponent(info.order||1)],
   ['customer_detail','?view=customer&id=1'],
   ['product_detail','?view=product&id='+encodeURIComponent(info.product||1)],
   ['supplier_detail','?view=supplier']
  ];
  for(const [key,path] of detailPages){
   const loadMs=await nav(path);
   await capture(key,path,loadMs);
  }

  // Bottom nav More menu (mobile tab).
  let loadMs=await nav('?view=dashboard');
  try{
   await page.locator('nav.mobile-nav a[href="?view=more"]').first().click();
   await page.waitForNavigation({waitUntil:'domcontentloaded',timeout:20000}).catch(()=>{});
   await page.waitForTimeout(400);
  }catch(e){log.push('more menu: '+String(e.message||e).slice(0,200));}
  await capture('state_more_menu','?view=more',loadMs);

  // Search overlay.
  loadMs=await nav('?view=dashboard');
  try{
   await page.keyboard.press('/');
   await page.waitForSelector('#ankh-search-sheet:not([hidden])',{timeout:5000});
   await page.fill('#ankh-search-input','Alice');
   await page.waitForSelector('.ankh-search-result',{timeout:5000});
   await page.waitForTimeout(400);
  }catch(e){log.push('search overlay: '+String(e.message||e).slice(0,200));}
  await capture('state_search','?view=dashboard',loadMs);

  // New order steps 1-3.
  loadMs=await nav('?view=new&customer_id=1');
  await capture('state_new_step1','?view=new&customer_id=1',loadMs);
  loadMs=Date.now();
  try{
   await page.locator('[data-wizard-next="2"]').click();
   await page.waitForSelector('[data-wizard-step="2"]:not([hidden])',{timeout:8000});
   await page.fill('#product-search','Reta');
   await page.waitForSelector('#product-results .product-result',{timeout:5000});
   await page.locator('#product-results .product-result').first().click();
   await page.waitForSelector('#strength-options button',{timeout:5000});
   const ten=page.locator('#strength-options button',{hasText:'10mg'}).first();
   if(await ten.count())await ten.click();else await page.locator('#strength-options button').first().click();
   await page.waitForSelector('.order-line-card',{timeout:5000});
   await page.waitForTimeout(400);
  }catch(e){log.push('new order step 2: '+String(e.message||e).slice(0,200));}
  await capture('state_new_step2','?view=new&customer_id=1',Date.now()-loadMs);
  loadMs=Date.now();
  try{
   await page.locator('[data-wizard-next="3"]').click();
   await page.waitForSelector('[data-wizard-step="3"]:not([hidden])',{timeout:8000});
   await page.waitForTimeout(400);
  }catch(e){log.push('new order step 3: '+String(e.message||e).slice(0,200));}
  await capture('state_new_step3','?view=new&customer_id=1',Date.now()-loadMs);

  // Record payment sheet on the first unpaid order.
  loadMs=await nav('?view=orders');
  try{
   await page.waitForSelector('[data-order-card]',{timeout:8000});
   await page.evaluate(()=>{
    const card=[...document.querySelectorAll('details[data-order-card]')].find(el=>el.dataset.orderPaid==='0');
    if(card){card.open=true;const payment=card.querySelector('details.add-payment');if(payment)payment.open=true;}
   });
   await page.waitForSelector('details.add-payment[open]',{timeout:3000});
   await page.waitForTimeout(300);
  }catch(e){log.push('record payment: '+String(e.message||e).slice(0,200));}
  await capture('state_record_payment','?view=orders',loadMs);

  // Reminder sheet.
  loadMs=await nav('?view=balances');
  try{
   await page.waitForSelector('[data-balance-reminder]',{timeout:8000});
   await page.locator('[data-balance-reminder]').first().click();
   await page.waitForSelector('#balance-reminder-sheet:not([hidden])',{timeout:5000});
   await page.waitForTimeout(300);
  }catch(e){log.push('reminder sheet: '+String(e.message||e).slice(0,200));}
  await capture('state_reminder','?view=balances',loadMs);

  // Expanded details on Stock.
  loadMs=await nav('?view=stock');
  try{
   await page.waitForSelector('#stock-add-stock',{timeout:8000});
   await page.evaluate(()=>{const first=document.querySelector('#stock-add-stock');if(first)first.open=true;});
   await page.waitForTimeout(300);
  }catch(e){log.push('stock details: '+String(e.message||e).slice(0,200));}
  await capture('state_stock_details','?view=stock',loadMs);
 }
}catch(e){log.push('run error '+String(e).slice(0,800));}
finally{
 writeFileSync(join(out,'report.json'),JSON.stringify(report,null,2)+'\n');
 writeFileSync(join(out,'run.log'),log.join('\n')+'\n');
 await context.close();
 await browser.close();
 server.kill('SIGTERM');
}
console.log(log.join('\n'));
process.exit(0);
