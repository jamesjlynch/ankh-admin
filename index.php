<?php
declare(strict_types=1);
// Allow ordinary HTTP only for an explicitly enabled localhost browser-test fixture.
$localTestHttp=PHP_SAPI==='cli-server' && getenv('ANKH_TEST_ALLOW_HTTP')==='1'
 && in_array((string)($_SERVER['SERVER_ADDR']??''),['127.0.0.1','::1'],true);
session_set_cookie_params(['httponly'=>true,'secure'=>!$localTestHttp,'samesite'=>'Strict']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
$configFile = getenv('ANKH_ADMIN_CONFIG') ?: dirname(__DIR__,2).'/ankh-admin-config.php';
$config = is_file($configFile) ? require $configFile : [];
function e($s){return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$syncError = null;
$savedOrderId = 0;
if (!$config || empty($config['password_hash']) || empty($config['database_path'])) {
 http_response_code(503);
 exit('<!doctype html><meta name="viewport" content="width=device-width"><title>ANKH Admin setup</title><body style="background:#101112;color:#ead9ac;font:18px system-ui;padding:10vw"><h1>☥ ANKH Admin</h1><p>The app is installed. Private access and database configuration need to be completed before orders can be managed.</p>');
}
$db = new PDO('sqlite:'.$config['database_path'], null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
require_once __DIR__.'/customer-catalogue.php';
require_once __DIR__.'/public-catalogue-core.php';
require_once __DIR__.'/packing-v2-support.php';
$db->exec('CREATE TABLE IF NOT EXISTS attempts (ip TEXT PRIMARY KEY, failures INTEGER NOT NULL, started INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, price INTEGER NOT NULL CHECK(price>=0), active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY, customer TEXT NOT NULL, phone TEXT NOT NULL, address TEXT NOT NULL, notes TEXT NOT NULL, status TEXT NOT NULL DEFAULT "New", created TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL REFERENCES orders(id), name TEXT NOT NULL, price INTEGER NOT NULL, quantity INTEGER NOT NULL CHECK(quantity>0));
CREATE TABLE IF NOT EXISTS customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL COLLATE NOCASE, phone TEXT NOT NULL DEFAULT "", address TEXT NOT NULL DEFAULT "", created TEXT NOT NULL, UNIQUE(name,phone));
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS passkeys (id INTEGER PRIMARY KEY, credential_id TEXT NOT NULL UNIQUE, public_key_pem TEXT NOT NULL, sign_count INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL DEFAULT "Passkey", created TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS payments (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE, amount INTEGER NOT NULL CHECK(amount>0), method TEXT NOT NULL DEFAULT "", note TEXT NOT NULL DEFAULT "", created TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS business_supply_items (
 id INTEGER PRIMARY KEY, name TEXT NOT NULL, category TEXT NOT NULL, specification TEXT NOT NULL DEFAULT "",
 supplier TEXT NOT NULL DEFAULT "", product_url TEXT NOT NULL DEFAULT "", backup_supplier TEXT NOT NULL DEFAULT "", backup_url TEXT NOT NULL DEFAULT "",
 usual_quantity INTEGER NOT NULL DEFAULT 1 CHECK(usual_quantity>0), usual_cost INTEGER DEFAULT NULL CHECK(usual_cost>=0),
 stock_level TEXT NOT NULL DEFAULT "Enough", stock_note TEXT NOT NULL DEFAULT "", requested_by TEXT NOT NULL DEFAULT "", owner TEXT NOT NULL DEFAULT "",
 notes TEXT NOT NULL DEFAULT "", active INTEGER NOT NULL DEFAULT 1, revision INTEGER NOT NULL DEFAULT 1,
 request_key TEXT NOT NULL UNIQUE, created TEXT NOT NULL, updated TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS business_supplies (
 id INTEGER PRIMARY KEY, item TEXT NOT NULL, category TEXT NOT NULL, quantity INTEGER NOT NULL CHECK(quantity>0),
 supplier TEXT NOT NULL, reference TEXT NOT NULL DEFAULT "", ordered_date TEXT NOT NULL, expected_date TEXT NOT NULL DEFAULT "",
 status TEXT NOT NULL DEFAULT "Ordered", item_cost INTEGER NOT NULL CHECK(item_cost>=0), shipping INTEGER NOT NULL CHECK(shipping>=0),
 payment_status TEXT NOT NULL DEFAULT "Unpaid", paid_date TEXT NOT NULL DEFAULT "", refunded_date TEXT NOT NULL DEFAULT "",
 product_url TEXT NOT NULL DEFAULT "", tracking_reference TEXT NOT NULL DEFAULT "", tracking_url TEXT NOT NULL DEFAULT "",
 notes TEXT NOT NULL DEFAULT "", revision INTEGER NOT NULL DEFAULT 1, request_key TEXT NOT NULL UNIQUE, created TEXT NOT NULL, updated TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS business_supplies_ordered ON business_supplies(ordered_date);
CREATE TABLE IF NOT EXISTS reta_cycles (
 id INTEGER PRIMARY KEY,
 customer_name TEXT NOT NULL,
 phone TEXT NOT NULL DEFAULT "",
 source_order_id INTEGER DEFAULT NULL,
 last_order_id INTEGER DEFAULT NULL,
 last_order_date TEXT NOT NULL,
 last_delivery_date TEXT NOT NULL DEFAULT "",
 next_due_date TEXT NOT NULL,
 expected_value INTEGER NOT NULL DEFAULT 0,
 expected_cost INTEGER NOT NULL DEFAULT 0,
 expected_profit INTEGER NOT NULL DEFAULT 0,
 cost_missing INTEGER NOT NULL DEFAULT 0,
 product_summary TEXT NOT NULL DEFAULT "",
 cycle_days INTEGER NOT NULL DEFAULT 28,
 active INTEGER NOT NULL DEFAULT 1,
 created TEXT NOT NULL,
 updated TEXT NOT NULL,
 ended_at TEXT NOT NULL DEFAULT "",
 end_reason TEXT NOT NULL DEFAULT ""
);');
$supplyColumns=array_column($db->query('PRAGMA table_info(business_supplies)')->fetchAll(PDO::FETCH_ASSOC),'name');
if(!in_array('cupboard_id',$supplyColumns,true))$db->exec('ALTER TABLE business_supplies ADD COLUMN cupboard_id INTEGER DEFAULT NULL REFERENCES business_supply_items(id)');
if(!in_array('received_quantity',$supplyColumns,true)){
 $db->exec('ALTER TABLE business_supplies ADD COLUMN received_quantity INTEGER NOT NULL DEFAULT 0');
 $db->exec("UPDATE business_supplies SET received_quantity=quantity WHERE status='Received'");
}
$db->exec('CREATE INDEX IF NOT EXISTS business_supplies_cupboard ON business_supplies(cupboard_id,status)');
$orderColumns=$db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('referrer',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN referrer TEXT NOT NULL DEFAULT ''");
if(!in_array('presentation',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN presentation TEXT NOT NULL DEFAULT ''");
if(!in_array('payment_date',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN payment_date TEXT NOT NULL DEFAULT ''");
if(!in_array('delivery_date',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN delivery_date TEXT NOT NULL DEFAULT ''");
if(!in_array('payment_method',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN payment_method TEXT NOT NULL DEFAULT ''");
if(!in_array('delivery_method',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN delivery_method TEXT NOT NULL DEFAULT ''");
if(!in_array('tracking_reference',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN tracking_reference TEXT NOT NULL DEFAULT ''");
if(!in_array('delivery_charge',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN delivery_charge INTEGER NOT NULL DEFAULT 0");
if(!in_array('postage_cost',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN postage_cost INTEGER NOT NULL DEFAULT 0");
if(!in_array('payment_fee',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN payment_fee INTEGER NOT NULL DEFAULT 0");
if(!in_array('assigned_to',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN assigned_to TEXT NOT NULL DEFAULT ''");
if(!in_array('recipient_name',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN recipient_name TEXT NOT NULL DEFAULT ''");
if(!in_array('reconstituted',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN reconstituted INTEGER NOT NULL DEFAULT 0");
if(!in_array('packaged',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN packaged INTEGER NOT NULL DEFAULT 0");
if(!in_array('scheduled_delivery_date',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN scheduled_delivery_date TEXT NOT NULL DEFAULT ''");
if(!in_array('delivery_section_override',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN delivery_section_override TEXT NOT NULL DEFAULT ''");
$itemColumns=$db->query('PRAGMA table_info(items)')->fetchAll(PDO::FETCH_ASSOC);
foreach(['back_order'=>"INTEGER NOT NULL DEFAULT 0",'back_order_since'=>"TEXT NOT NULL DEFAULT ''",'activated_at'=>"TEXT NOT NULL DEFAULT ''"] as $column=>$definition){
 if(!in_array($column,array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN {$column} {$definition}");
}
if(!in_array('pricing_type',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN pricing_type TEXT NOT NULL DEFAULT 'standard'");
if(!in_array('cost',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN cost INTEGER DEFAULT NULL");
if(!in_array('presentation',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN presentation TEXT NOT NULL DEFAULT ''");
if(!in_array('presentation_cost',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN presentation_cost INTEGER NOT NULL DEFAULT 0");
if(!in_array('base_price',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN base_price INTEGER DEFAULT NULL");
if(!in_array('discount',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN discount INTEGER NOT NULL DEFAULT 0");
if(!in_array('recurring',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN recurring INTEGER NOT NULL DEFAULT 0");
if(!in_array('cycle_weeks',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN cycle_weeks INTEGER NOT NULL DEFAULT 0");
$productColumns=$db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('cost',array_column($productColumns,'name'),true))$db->exec("ALTER TABLE products ADD COLUMN cost INTEGER DEFAULT NULL");
if(!in_array('stock_qty',array_column($productColumns,'name'),true))$db->exec("ALTER TABLE products ADD COLUMN stock_qty INTEGER DEFAULT NULL");
if(!in_array('low_stock_at',array_column($productColumns,'name'),true))$db->exec("ALTER TABLE products ADD COLUMN low_stock_at INTEGER NOT NULL DEFAULT 5");
// Set every existing product to the requested default once; later product-specific edits remain intact.
$stockAlertMigrationKey='migration_low_stock_alert_default_5';
$q=$db->prepare('SELECT value FROM settings WHERE key=?');$q->execute([$stockAlertMigrationKey]);
if($q->fetchColumn()===false){
 $db->beginTransaction();
 $db->exec('UPDATE products SET low_stock_at=5');
 $db->prepare('INSERT INTO settings(key,value) VALUES(?,?)')->execute([$stockAlertMigrationKey,'done']);
 $db->commit();
}
if(!in_array('stock_tracking',array_column($productColumns,'name'),true))$db->exec("ALTER TABLE products ADD COLUMN stock_tracking INTEGER NOT NULL DEFAULT 0");
if(!in_array('stock_tracking_since',array_column($productColumns,'name'),true))$db->exec("ALTER TABLE products ADD COLUMN stock_tracking_since TEXT NOT NULL DEFAULT ''");
if(!in_array('family_friends_price',array_column($productColumns,'name'),true))$db->exec("ALTER TABLE products ADD COLUMN family_friends_price INTEGER DEFAULT NULL");
foreach(['stock_jay_qty','stock_tony_qty','stock_unallocated_qty'] as $stockColumn){if(!in_array($stockColumn,array_column($productColumns,'name'),true))$db->exec("ALTER TABLE products ADD COLUMN {$stockColumn} INTEGER NOT NULL DEFAULT 0");}

if(!in_array('product_id',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN product_id INTEGER DEFAULT NULL");
// Additive private checklist; no existing inventory or delivery fields are modified.
ankhPackingEnsureSchema($db);

/*
 * Hidden live stock board.
 * There is deliberately no navigation link to this view. The long key in the
 * bookmarked URL is the access credential for phone/TV display mode.
 */
$stockBoardKey='6f3d92a1c8b547e0a64f2d9b71ce8453';
$stockBoardRequest=(string)($_GET['stockboard']??'');
if($stockBoardRequest!==''){
 if(!hash_equals($stockBoardKey,$stockBoardRequest)){http_response_code(404);exit('Not found');}
 $stockBoardRows=function()use($db):array{
  $rows=$db->query("SELECT id,name,low_stock_at,stock_jay_qty,stock_tony_qty,stock_unallocated_qty
   FROM products
   WHERE active=1 AND stock_tracking=1
   ORDER BY name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
  $out=[];
  foreach($rows as $row){
   $jay=(int)($row['stock_jay_qty']??0);$tony=(int)($row['stock_tony_qty']??0);$holding=(int)($row['stock_unallocated_qty']??0);
   $available=$jay+$tony+$holding;
   if($available<=0)continue;
   $out[]=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'available'=>$available,'low_at'=>(int)($row['low_stock_at']??5)];
  }
  return $out;
 };
 $boardRows=$stockBoardRows();
 if((string)($_GET['format']??'')==='json'){
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, max-age=0');
  header('X-Robots-Tag: noindex, nofollow');
  echo json_encode(['ok'=>true,'updated'=>time(),'products'=>$boardRows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
 }
 header('Content-Type: text/html; charset=utf-8');
 header('Cache-Control: no-store, max-age=0');
 header('X-Robots-Tag: noindex, nofollow');
 $boardUpdated=(new DateTimeImmutable('now',new DateTimeZone('Europe/London')))->format('H:i:s');
 $boardUnits=array_sum(array_column($boardRows,'available'));
 ?>
 <!doctype html>
 <html lang="en">
 <head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#090a0b">
  <meta name="robots" content="noindex,nofollow">
  <meta name="referrer" content="no-referrer">
  <title>ANKH • Live stock</title>
  <style>
   :root{color-scheme:dark;--bg:#08090a;--panel:#121310;--panel2:#171814;--line:#38352b;--gold:#e3bf57;--gold2:#ffe58d;--text:#f6f2e7;--muted:#9b9c94;--good:#7ee7b7;--warn:#ffcc74}
   *{box-sizing:border-box}
   html,body{margin:0;min-height:100%;background:radial-gradient(circle at 20% -10%,#342d18 0,transparent 31%),var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
   body{min-height:100dvh;padding:max(20px,env(safe-area-inset-top)) max(18px,env(safe-area-inset-right)) max(22px,env(safe-area-inset-bottom)) max(18px,env(safe-area-inset-left))}
   .board{width:min(1700px,100%);margin:0 auto}
   header{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;padding:10px 2px 24px;border-bottom:1px solid var(--line)}
   .brand{display:flex;gap:15px;align-items:center}.mark{width:54px;height:54px;display:grid;place-items:center;border:1px solid #766332;border-radius:16px;color:var(--gold2);font:700 28px Georgia,serif;background:#15140f}.brand p{margin:0;color:var(--gold);font-size:11px;font-weight:900;letter-spacing:.18em}.brand h1{margin:5px 0 0;font-size:clamp(28px,4vw,52px);line-height:.95;letter-spacing:-.04em}
   .live{display:flex;align-items:center;gap:9px;color:#c4c5bd;font-size:12px;font-weight:800;white-space:nowrap}.dot{width:9px;height:9px;border-radius:50%;background:var(--good);box-shadow:0 0 0 5px rgba(126,231,183,.08)}
   .summary{display:flex;gap:10px;flex-wrap:wrap;padding:18px 0 14px}.pill{padding:9px 12px;border:1px solid var(--line);border-radius:999px;background:#11120f;color:var(--muted);font-size:11px}.pill b{color:var(--text);font-size:13px}
   .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
   .stock-card{min-height:132px;padding:17px 17px 15px;border:1px solid var(--line);border-radius:18px;background:linear-gradient(145deg,var(--panel2),#10110e);display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 12px 35px rgba(0,0,0,.16)}
   .stock-card.low{border-color:#6e5b2e;background:linear-gradient(145deg,#211d12,#12110d)}
   .stock-name{font-size:clamp(17px,2vw,25px);font-weight:900;line-height:1.05;letter-spacing:-.025em;overflow-wrap:anywhere}
   .stock-count{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-top:18px}.stock-count strong{font-size:clamp(30px,4vw,48px);line-height:.85;color:var(--gold2)}.stock-count span{color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.stock-card.low .stock-count strong{color:var(--warn)}
   .empty{min-height:50vh;display:grid;place-items:center;text-align:center;color:var(--muted);border:1px dashed var(--line);border-radius:22px}.empty strong{display:block;color:var(--text);font-size:28px;margin-bottom:8px}
   footer{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:22px 2px 0;color:#6f716b;font-size:10px}
   @media(max-width:520px){body{padding-left:13px;padding-right:13px}header{align-items:flex-start}.mark{width:46px;height:46px}.brand h1{font-size:30px}.live{font-size:10px}.grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.stock-card{min-height:116px;padding:14px}.stock-name{font-size:16px}.stock-count strong{font-size:30px}.stock-count span{font-size:8px}}
   @media(min-width:1100px){body{padding:30px 38px}.grid{grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:16px}.stock-card{min-height:170px;padding:23px}.stock-name{font-size:28px}}
   @media(min-width:1500px){.grid{grid-template-columns:repeat(5,minmax(0,1fr))}.stock-card{min-height:190px}.stock-name{font-size:32px}.stock-count strong{font-size:58px}}
  </style>
 </head>
 <body>
 <main class="board">
  <header>
   <div class="brand"><div class="mark">☥</div><div><p>ANKH PEPTIDES</p><h1>Live stock</h1></div></div>
   <div class="live"><i class="dot"></i><span id="live-label">LIVE · updated <?=$boardUpdated?></span></div>
  </header>
  <div class="summary"><span class="pill"><b id="product-count"><?=count($boardRows)?></b> products available</span><span class="pill"><b id="unit-count"><?=$boardUnits?></b> total units</span></div>
  <section class="grid" id="stock-grid" aria-live="polite">
   <?php foreach($boardRows as $boardItem):?>
    <article class="stock-card <?=(int)$boardItem['available']<=(int)$boardItem['low_at']?'low':''?>">
     <div class="stock-name"><?=e($boardItem['name'])?></div>
     <div class="stock-count"><strong><?=(int)$boardItem['available']?></strong><span>available</span></div>
    </article>
   <?php endforeach;?>
  </section>
  <section class="empty" id="stock-empty" <?=$boardRows?'hidden':''?>><div><strong>No stock available</strong><span>Products will appear here automatically when stock is added.</span></div></section>
  <footer><span>ANKH live availability</span><span>Refreshes automatically every 15 seconds</span></footer>
 </main>
 <script>
 (()=>{const key=<?=json_encode($stockBoardKey)?>,grid=document.getElementById('stock-grid'),empty=document.getElementById('stock-empty'),productCount=document.getElementById('product-count'),unitCount=document.getElementById('unit-count'),live=document.getElementById('live-label');
 const esc=value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 function draw(products,updated){const items=Array.isArray(products)?products:[];grid.innerHTML=items.map(p=>'<article class="stock-card '+(Number(p.available)<=Number(p.low_at)?'low':'')+'"><div class="stock-name">'+esc(p.name)+'</div><div class="stock-count"><strong>'+Number(p.available||0)+'</strong><span>available</span></div></article>').join('');empty.hidden=items.length>0;grid.hidden=items.length===0;productCount.textContent=items.length;unitCount.textContent=items.reduce((n,p)=>n+Number(p.available||0),0);const d=new Date((Number(updated)||Math.floor(Date.now()/1000))*1000);live.textContent='LIVE · updated '+d.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'})}
 async function refresh(){try{const r=await fetch('?stockboard='+encodeURIComponent(key)+'&format=json&t='+Date.now(),{cache:'no-store',credentials:'omit'});if(!r.ok)throw new Error('refresh failed');const data=await r.json();if(data.ok)draw(data.products,data.updated)}catch(e){live.textContent='LIVE · reconnecting…'}}
 refresh();setInterval(refresh,15000);})();
 </script>
 </body></html>
 <?php
 exit;
}


/*
 * Hidden live monthly reports board.
 * Direct-link only: deliberately omitted from all ANKH navigation.
 */
$reportBoardKey='91c7e54a3b2d49f6a8e1c3b57d2460af';
$reportBoardRequest=(string)($_GET['reportboard']??'');
// Read-only office overview, using the existing reports display credential.
function todayOverviewData(PDO $db, ?DateTimeImmutable $now=null):array{
 $tz=new DateTimeZone('Europe/London');$now=($now??new DateTimeImmutable('now',$tz))->setTimezone($tz);
 $today=$now->format('Y-m-d');$weekStart=$now->modify('monday this week')->setTime(0,0);$days=[];
 for($n=0;$n<7;$n++){$day=$weekStart->modify('+'.$n.' days');$days[$day->format('Y-m-d')]=['date'=>$day->format('Y-m-d'),'label'=>$day->format('D'),'sales'=>0];}
 $data=['ok'=>true,'updated'=>$now->format(DATE_ATOM),'today_sales'=>0,'today_orders'=>0,'unpaid'=>0,'unpaid_orders'=>0,'to_deliver'=>0,'overdue'=>0,'delivered_unpaid'=>0,'week_sales'=>0,'days'=>[],'preparation'=>['pending'=>0,'reconstituted'=>0,'packaged'=>0],'due_today'=>0,'next_delivery'=>'','workload'=>['Jay'=>0,'Tony'=>0,'Unassigned'=>0]];
 $rows=$db->query("SELECT o.id,o.created,o.status,o.payment_date,o.delivery_date,o.scheduled_delivery_date,o.delivery_section_override,o.assigned_to,o.reconstituted,o.packaged,
 COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0) total,
 COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0) paid_amount FROM orders o WHERE o.back_order=0 AND o.status<>'Cancelled'");
 foreach($rows as $row){
  $created=(new DateTimeImmutable((string)$row['created'],$tz))->setTimezone($tz)->format('Y-m-d');
  if($created===$today)$data['today_orders']++;
  $balance=max(0,(int)$row['total']-(int)$row['paid_amount']);$data['unpaid']+=$balance;if($balance>0)$data['unpaid_orders']++;
  $delivered=$row['status']==='Delivered'||trim((string)$row['delivery_date'])!=='';
  if($delivered)$data['delivered_unpaid']+=$balance;
  $stage=orderPreparationState($row);if($stage!=='closed')$data['preparation'][$stage]++;
  if(deliveryBoardSection($row,$today)!==''){
   $data['to_deliver']++;$scheduled=trim((string)$row['scheduled_delivery_date']);
   if($scheduled!==''&&$scheduled<$today)$data['overdue']++;
   if($scheduled===$today)$data['due_today']++;
   if($scheduled>$today&&($data['next_delivery']===''||$scheduled<$data['next_delivery']))$data['next_delivery']=$scheduled;
   $assignee=trim((string)$row['assigned_to']);$owner=in_array($assignee,['James','Jay'],true)?'Jay':($assignee==='Tony'?'Tony':'Unassigned');$data['workload'][$owner]++;
  }
  if(!in_array($row['status'],['Paid','Packed','Dispatched','Delivered'],true))continue;
  $saleDate=trim((string)$row['payment_date'])?:$created;
  if($saleDate===$today)$data['today_sales']+=(int)$row['total'];
  if(isset($days[$saleDate])&&$saleDate<=$today){$days[$saleDate]['sales']+=(int)$row['total'];$data['week_sales']+=(int)$row['total'];}
 }
 $data['days']=array_values($days);return $data;
}
function preparationBoardData(PDO $db,?DateTimeImmutable $now=null):array{
 $tz=new DateTimeZone('Europe/London');$now=($now??new DateTimeImmutable('now',$tz))->setTimezone($tz);$today=$now->format('Y-m-d');
 $data=['ok'=>true,'updated'=>$now->format(DATE_ATOM),'columns'=>['pending'=>[],'reconstituted'=>[],'packaged'=>[]],'due_today'=>0,'overdue'=>0,'unpaid_orders'=>0];
 $orders=$db->query("SELECT o.id,o.customer,o.recipient_name,o.delivery_method,o.status,o.created,o.delivery_date,o.scheduled_delivery_date,o.reconstituted,o.packaged,o.assigned_to,
 COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0) total,
 COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0) paid_amount FROM orders o
 WHERE o.back_order=0 AND o.status NOT IN ('Cancelled','Delivered') AND trim(COALESCE(o.delivery_date,''))='' ")->fetchAll(PDO::FETCH_ASSOC);
 $items=[];foreach($db->query("SELECT i.order_id,i.name,i.quantity FROM items i JOIN orders o ON o.id=i.order_id WHERE o.back_order=0 AND o.status NOT IN ('Cancelled','Delivered') AND trim(COALESCE(o.delivery_date,''))='' ORDER BY i.id") as $line)$items[(int)$line['order_id']][]=['name'=>(string)$line['name'],'quantity'=>(int)$line['quantity']];
 foreach($orders as $order){
  $stage=orderPreparationState($order);if($stage==='closed')continue;
  $scheduled=trim((string)$order['scheduled_delivery_date']);$due=$scheduled===''?'undated':($scheduled<$today?'overdue':($scheduled===$today?'today':'future'));
  if($due==='overdue')$data['overdue']++;if($due==='today')$data['due_today']++;
  $balance=max(0,(int)$order['total']-(int)$order['paid_amount']);if($balance>0)$data['unpaid_orders']++;
  $assignee=trim((string)$order['assigned_to']);$owner=in_array($assignee,['James','Jay'],true)?'Jay':($assignee==='Tony'?'Tony':'Unassigned');
  $data['columns'][$stage][]=['id'=>(int)$order['id'],'customer'=>(string)$order['customer'],'recipient'=>(string)$order['recipient_name'],'delivery_method'=>(string)$order['delivery_method'],'total'=>(int)$order['total'],'order_date'=>(new DateTimeImmutable((string)$order['created'],$tz))->setTimezone($tz)->format('Y-m-d'),'created'=>(string)$order['created'],'scheduled'=>$scheduled,'due'=>$due,'countdown'=>$scheduled!==''?deliveryCountdown($scheduled,$now):'Date not arranged','owner'=>$owner,'balance'=>$balance,'items'=>$items[(int)$order['id']]??[]];
 }
 foreach($data['columns'] as &$column)usort($column,fn($a,$b)=>(['overdue'=>0,'today'=>1,'future'=>2,'undated'=>3][$a['due']]<=>['overdue'=>0,'today'=>1,'future'=>2,'undated'=>3][$b['due']])?:strcmp($a['scheduled'],$b['scheduled'])?:strcmp($a['created'],$b['created'])?:($a['id']<=>$b['id']));unset($column);
 return $data;
}
if($reportBoardRequest!==''&&(string)($_GET['screen']??'')==='preparation'){
 if(!hash_equals($reportBoardKey,$reportBoardRequest)){http_response_code(404);exit('Not found');}
 header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');$preparation=preparationBoardData($db);
 if((string)($_GET['format']??'')==='json'){header('Content-Type: application/json; charset=utf-8');echo json_encode($preparation,JSON_THROW_ON_ERROR);exit;}
 ?>
 <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><meta name="theme-color" content="#080909"><title>ANKH · Order preparation</title>
 <style>
 :root{color-scheme:dark;font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#080909;color:#f6f1e5}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 10% 0,#342b1655,transparent 60%)}main{height:100dvh;display:grid;grid-template-rows:auto auto minmax(0,1fr) auto;gap:16px;max-width:2000px;margin:auto;padding:24px 32px}header,.brand,.summary,footer,.column-head,.card-head,.card-foot{display:flex;align-items:center;justify-content:space-between;gap:14px}.brand{justify-content:flex-start}.mark{border:1px solid #7e6734;border-radius:16px;padding:5px 15px;color:#e9c46b;font:40px Georgia}.eyebrow{color:#d7b65e;text-transform:uppercase;letter-spacing:.18em;font-size:12px;font-weight:800}h1{margin:4px 0;font-size:clamp(26px,3vw,48px);letter-spacing:-.04em}.clock{text-align:right;color:#a9b09f;font-size:13px}.clock strong{display:block;color:#f8f1df;font-size:26px}.summary{justify-content:flex-start;flex-wrap:wrap;font-size:14px;color:#b4bcaa}.summary span{border:1px solid #393b2f;border-radius:999px;padding:7px 12px}.summary b{color:#f5dfa0}.summary small{margin-left:auto;color:#a2aa98}.board{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;min-height:0}.column{border:1px solid #37392e;border-radius:22px;background:#10120f;min-width:0;min-height:0;display:grid;grid-template-rows:auto minmax(0,1fr) auto;padding:16px;gap:12px}.column-head h2{margin:0;font-size:clamp(20px,2vw,30px);letter-spacing:-.03em}.count{border:1px solid #69572d;background:#2d2617;border-radius:12px;color:#ffe3a1;font-weight:800;padding:6px 12px;font-size:22px}.cards{display:flex;flex-direction:column;gap:12px;min-height:0}.card{height:310px;flex:0 0 310px;border:1px solid #3d4132;border-radius:15px;background:linear-gradient(140deg,#1b1e17,#131510);padding:14px;display:flex;flex-direction:column;gap:8px;min-width:0}.customer-name{font-size:18px;font-weight:800;color:#fff3d0;line-height:1.15;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.order-meta{display:flex;justify-content:space-between;gap:8px;color:#a9b59b;font-size:11px}.recipient{color:#c3cbb9;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.card.overdue{border-color:#a56a41;background:linear-gradient(140deg,#302016,#17140f)}.card.today{border-color:#9c8242}.card-head strong{font-size:20px;color:#f8e4aa}.owner{font-size:12px;background:#282d22;border-radius:8px;padding:5px 8px;color:#d5ddc9}.owner.unassigned{color:#edc87c}.due{font-size:12px;color:#b7c2a8}.overdue .due{color:#ffb585}.today .due{color:#ffe3a1}.items{list-style:none;padding:0;margin:0;display:grid;gap:5px}.items li{display:flex;gap:9px;align-items:baseline;font-size:14px;line-height:1.25}.items b{color:#ffe3a1;white-space:nowrap}.items span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.card-foot{margin-top:auto;font-size:11px;color:#9fa995}.unpaid{color:#ffd09b;font-weight:800}.paid{color:#a6d5a6}.page-label{color:#a6b099;font-size:12px;text-align:center;min-height:16px}.empty{color:#a4af97;border:1px dashed #3d4431;border-radius:14px;padding:32px 15px;text-align:center}.column.ready .column-head h2{color:#c4dfb4}footer{color:#9da68f;font-size:12px}#live{color:#9dddab}button{border:1px solid #64542e;background:#222216;color:#f1dca1;padding:9px 13px;border-radius:10px;cursor:pointer}.stale #live{color:#efbb80}.stale .board{opacity:.65}@media(max-width:800px){main{height:auto;min-height:100dvh;padding:18px 12px}.board{grid-template-columns:1fr}.cards{min-height:310px}.summary small{margin-left:0}.clock strong{font-size:20px}h1{font-size:27px}.mark{font-size:30px;padding:6px 10px}footer{flex-wrap:wrap}.column{min-height:390px}}@media(min-width:801px) and (max-height:750px){main{padding:14px 22px;gap:10px}.column{padding:12px;gap:8px}.mark{font-size:28px}h1{font-size:30px}.summary span{padding:5px 9px}.column-head h2{font-size:22px}.count{font-size:18px;padding:4px 10px}}
 </style></head><body><main><header><div class="brand"><div class="mark">☥</div><div><div class="eyebrow">Ankh Peptides · Office live</div><h1>Order preparation</h1></div></div><div class="clock"><strong id="clock"></strong><span id="date"></span></div></header>
 <div class="summary"><span><b id="open">—</b> open orders</span><span><b id="today">—</b> due today</span><span><b id="overdue">—</b> overdue</span><span><b id="unpaid-count">—</b> unpaid</span><small>All open orders · urgent deliveries first</small></div>
 <section class="board" aria-label="Order preparation stages">
 <?php foreach(['pending'=>'To prepare','reconstituted'=>'To package','packaged'=>'Ready for delivery'] as $stage=>$label):?><article class="column<?=$stage==='packaged'?' ready':''?>"><div class="column-head"><h2><?=e($label)?></h2><span class="count" id="count-<?=e($stage)?>">—</span></div><div class="cards" id="cards-<?=e($stage)?>"></div><div class="page-label" id="page-<?=e($stage)?>"></div></article><?php endforeach;?>
 </section><footer><span id="live" role="status">Connecting…</span><span id="updated"></span><button type="button" id="fullscreen">Full screen</button></footer></main>
 <script>
 (()=>{const el=id=>document.getElementById(id),money=n=>new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP'}).format(n/100),stages=['pending','reconstituted','packaged'];let latest=null,page=0,busy=false,lastSuccess=0;
 const clock=new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/London',hour:'2-digit',minute:'2-digit',second:'2-digit'}),date=new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/London',weekday:'short',day:'numeric',month:'short'});
 function text(tag,value,className=''){const node=document.createElement(tag);node.textContent=value;if(className)node.className=className;return node;}
 function card(order,lines,part,parts){const node=document.createElement('article');node.className='card '+order.due;const head=text('div','','card-head');head.append(text('strong','#'+order.id),text('span',order.owner,'owner'+(order.owner==='Unassigned'?' unassigned':'')));const customer=text('div',order.customer||'Unnamed customer','customer-name');customer.title=order.customer||'';const details=text('div','','order-meta');const orderDate=new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/London',day:'numeric',month:'short',year:'numeric'}).format(new Date(order.order_date+'T12:00:00Z'));details.append(text('span','Ordered '+orderDate),text('span',order.delivery_method||'Method not set'));const recipient=order.recipient&&order.recipient.trim().toLowerCase()!==(order.customer||'').trim().toLowerCase()?text('div','For: '+order.recipient,'recipient'):null;if(recipient)recipient.title=order.recipient;const due=text('div',order.scheduled?'Delivery: '+date.format(new Date(order.scheduled+'T12:00:00Z'))+' · '+order.countdown:'Delivery date not arranged','due');const list=document.createElement('ul');list.className='items';lines.forEach(item=>{const li=document.createElement('li');const name=text('span',item.name);name.title=item.name;li.append(text('b',item.quantity+' ×'),name);list.append(li);});if(!lines.length)list.append(text('li','No product lines recorded'));const foot=text('div','','card-foot');foot.append(text('span',order.balance>0?'Unpaid '+money(order.balance):'Paid',order.balance>0?'unpaid':'paid'),text('span',money(order.total)+' total'+(parts>1?' · Items '+(part+1)+' of '+parts:'')));node.append(head,customer,details);if(recipient)node.append(recipient);node.append(due,list,foot);return node;}
 function render(){if(!latest)return;let total=0;stages.forEach(stage=>{const orders=latest.columns[stage]||[];total+=orders.length;el('count-'+stage).textContent=orders.length;const container=el('cards-'+stage);const perPage=innerWidth<=800?2:Math.max(1,Math.floor((container.clientHeight+12)/322));const cards=[];orders.forEach(order=>{const parts=Math.max(1,Math.ceil(order.items.length/4));for(let part=0;part<parts;part++)cards.push({order,lines:order.items.slice(part*4,part*4+4),part,parts});});const pages=Math.max(1,Math.ceil(cards.length/perPage)),current=page%pages;container.replaceChildren();cards.slice(current*perPage,(current+1)*perPage).forEach(c=>container.append(card(c.order,c.lines,c.part,c.parts)));if(!cards.length)container.append(text('div','No orders in this stage','empty'));el('page-'+stage).textContent=pages>1?'Page '+(current+1)+' of '+pages+' · rotates every 15 seconds':(cards.length?'All orders shown':'');});el('open').textContent=total;el('today').textContent=latest.due_today;el('overdue').textContent=latest.overdue;el('unpaid-count').textContent=latest.unpaid_orders;}
 function accept(data){latest=data;lastSuccess=Date.parse(data.updated);document.body.classList.remove('stale');el('live').textContent='● Live · refreshes every 30 seconds';el('updated').textContent='Updated '+clock.format(new Date(data.updated))+' · UK time';render();}
 function stale(message){document.body.classList.add('stale');el('live').textContent=message;}
 async function refresh(){if(busy)return;busy=true;try{const url=new URL(location.href);url.searchParams.set('format','json');url.searchParams.set('t',Date.now());const response=await fetch(url,{cache:'no-store',credentials:'omit',signal:AbortSignal.timeout(12000)});if(!response.ok)throw new Error();const data=await response.json();if(data.ok!==true||!data.columns)throw new Error();accept(data);}catch{stale(navigator.onLine?'Updates unavailable · reconnecting…':'Offline · showing last update');}finally{busy=false;}}
 accept(<?=json_encode($preparation,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?>);function tick(){el('clock').textContent=clock.format(new Date());el('date').textContent=date.format(new Date());if(Date.now()-lastSuccess>65000)stale('Updates delayed · reconnecting…');}tick();setInterval(tick,1000);setInterval(refresh,30000);setInterval(()=>{page++;render();},15000);window.addEventListener('resize',render);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});window.addEventListener('online',refresh);window.addEventListener('offline',()=>stale('Offline · showing last update'));el('fullscreen').addEventListener('click',async()=>{try{if(document.fullscreenElement)await document.exitFullscreen();else await document.documentElement.requestFullscreen();}catch{el('fullscreen').textContent='Use browser full screen';}});
 })();
 </script></body></html>
 <?php exit;
}
if($reportBoardRequest!==''&&(string)($_GET['screen']??'')==='today'){
 if(!hash_equals($reportBoardKey,$reportBoardRequest)){http_response_code(404);exit('Not found');}
 header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
 $overview=todayOverviewData($db);
 if((string)($_GET['format']??'')==='json'){header('Content-Type: application/json; charset=utf-8');echo json_encode($overview,JSON_THROW_ON_ERROR);exit;}
 ?>
 <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><meta name="theme-color" content="#080909"><title>ANKH · Today’s overview</title>
 <style>
 :root{color-scheme:dark;font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#080909;color:#f7f4eb}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 10% 0,#342b164d,transparent 60%);min-height:100vh}.today-board{max-width:1900px;margin:auto;padding:clamp(20px,3vw,56px)}header,.brand,footer,.chart-head{display:flex;align-items:center;justify-content:space-between;gap:20px}.brand{justify-content:flex-start}.mark{font:48px Georgia;color:#e4bf62;border:1px solid #7d6533;border-radius:20px;padding:8px 18px}.eyebrow{color:#d8b658;text-transform:uppercase;letter-spacing:.2em;font-size:clamp(11px,1vw,17px);font-weight:800}h1{font-size:clamp(28px,3.2vw,58px);letter-spacing:-.04em;margin:5px 0}header{padding-bottom:24px;border-bottom:1px solid #363127}.meta{text-align:right;color:#aeb0a8}.meta strong{display:block;font-size:clamp(18px,2vw,32px);color:#f7f4eb}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;margin:28px 0}.metric,.chart{border:1px solid #38362d;border-radius:24px;background:linear-gradient(140deg,#1a1b16,#10120f);padding:clamp(18px,2vw,36px)}.metric:first-child{border-color:#8c713a;background:linear-gradient(140deg,#302715,#14140e)}.metric span{font-size:clamp(13px,1.2vw,21px);color:#b4b6ab}.metric strong{display:block;font-size:clamp(28px,3.5vw,66px);letter-spacing:-.04em;margin:16px 0;color:#fff1c6;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}.metric small{color:#93988e;font-size:clamp(11px,1vw,17px)}h2{margin:6px 0;font-size:clamp(23px,2.3vw,40px)}.week-total{color:#f5dc94;font-size:clamp(25px,3vw,52px);font-weight:800}.bars{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:clamp(8px,2vw,36px);height:clamp(180px,30vh,380px);margin-top:24px}.day{display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:10px;min-width:0}.day-value{font-size:clamp(11px,1.3vw,23px);color:#f7e7b7;white-space:nowrap}.bar-slot{height:100%;width:100%;display:flex;align-items:flex-end;border-bottom:1px solid #4b4531}.bar{width:100%;height:0;border-radius:10px 10px 0 0;background:linear-gradient(#e7c46b,#745e2f)}.day.current .bar{background:linear-gradient(#fff0b6,#b78b33)}.day-label{font-size:clamp(12px,1.2vw,21px);color:#a8ad9f}.day.current .day-label{color:#ffe59a;font-weight:800}.actions{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-top:24px;padding-top:20px;border-top:1px solid #37372b;color:#c8c9bb;font-size:clamp(13px,1.2vw,21px)}footer{margin-top:22px;color:#92998c;font-size:clamp(11px,1vw,16px)}button{background:#1c1e17;color:#e5cf95;border:1px solid #574b2d;border-radius:10px;padding:9px 14px;cursor:pointer}#live{color:#9de1ab}body.stale #live{color:#f0b56a}body.stale .chart,body.stale .metrics{opacity:.65}@media(max-width:800px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.metric{border-radius:16px}.brand{gap:10px}.mark{font-size:28px;padding:8px 12px}header{align-items:flex-start}.meta{font-size:11px}.bars{gap:8px}footer{flex-wrap:wrap}.day-value{font-size:10px}.chart{padding:16px}}

 .operations{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}.operations article{background:linear-gradient(140deg,#191b16,#10120f);border:1px solid #38362d;border-radius:20px;padding:18px 24px}.operation-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px}.operation-head small{color:#969c8f;font-size:12px}.operation-counts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.operation-counts div{min-width:0}.operation-counts strong{display:block;color:#ffe4a1;font-size:clamp(28px,2.5vw,46px);line-height:1.1;font-variant-numeric:tabular-nums}.operation-counts span{display:block;color:#b9beaf;font-size:clamp(12px,1vw,19px);margin-top:6px}.delivery-summary{display:flex;gap:20px;flex-wrap:wrap;color:#adb5a4;font-size:clamp(12px,1vw,18px);padding-top:14px;margin-top:14px;border-top:1px solid #35382d}.delivery-summary b{color:#efdfad}.delivery-summary .urgent b{color:#f0a578}.metric strong,.operation-counts strong{transition:color .4s}.changed{animation:metric-update 1.2s ease-out}@keyframes metric-update{0%{color:#fff9de;text-shadow:0 0 22px #e7c46b99}100%{text-shadow:none}}body.stale .operations{opacity:.65}
 @media(min-width:801px){html,body{height:100%;min-height:0}.today-board{height:100dvh;display:grid;grid-template-rows:auto auto auto minmax(0,1fr) auto;gap:clamp(10px,1.8vh,22px);padding:clamp(14px,2.4vh,30px) clamp(20px,3vw,56px)}header{padding-bottom:12px}.mark{font-size:clamp(28px,4vh,46px);padding:4px 14px}h1{font-size:clamp(26px,4.3vh,52px)}.metrics{margin:0;gap:16px}.metric{padding:clamp(12px,1.8vh,24px)}.metric strong{font-size:clamp(28px,5.2vh,62px);margin:10px 0}.metric span{font-size:clamp(12px,1.7vh,20px)}.metric small{font-size:clamp(10px,1.45vh,16px)}.operations{margin:0}.operations article{padding:clamp(12px,1.5vh,20px) 22px}.operation-head{margin-bottom:10px}.operation-counts strong{font-size:clamp(24px,3.8vh,44px)}.chart{min-height:0;display:grid;grid-template-rows:auto minmax(0,1fr) auto;padding:clamp(12px,1.7vh,24px) 24px}.chart h2{font-size:clamp(22px,3vh,36px)}.week-total{font-size:clamp(28px,4vh,48px)}.bars{height:auto;min-height:0;margin-top:12px;gap:22px}.day{min-height:0;gap:7px}.bar-slot{min-height:0;flex:1}.day-value{font-size:clamp(11px,1.8vh,21px)}.day-label{font-size:clamp(12px,1.7vh,19px)}.actions{margin-top:12px;padding-top:12px;font-size:clamp(11px,1.6vh,18px)}footer{margin:0;font-size:clamp(10px,1.5vh,16px)}.eyebrow{font-size:clamp(10px,1.5vh,16px)}}
 @media(max-width:800px){.operations{grid-template-columns:1fr;gap:10px}.operations article{padding:16px}.operation-head small{font-size:10px}.operation-counts strong{font-size:30px}.delivery-summary{gap:12px}.metrics{margin:18px 0}.bars{height:180px}}@media(prefers-reduced-motion:reduce){.changed{animation:none}.metric strong,.operation-counts strong{transition:none}}
 </style></head><body><main class="today-board">
 <header><div class="brand"><div class="mark">☥</div><div><div class="eyebrow">Ankh Peptides · Office live</div><h1>Today’s overview</h1></div></div><div class="meta"><strong id="clock"></strong><span id="date"></span></div></header>
 <section class="metrics" aria-label="Today and outstanding work">
 <article class="metric"><span>Today’s sales</span><strong id="sales">—</strong><small>Paid orders · payment date</small></article>
 <article class="metric"><span>Orders placed today</span><strong id="orders">—</strong><small>All non-cancelled orders</small></article>
 <article class="metric"><span>Awaiting payment</span><strong id="unpaid">—</strong><small id="unpaid-count"></small></article>
 <article class="metric"><span>Awaiting delivery</span><strong id="delivery">—</strong><small>Includes future agreed deliveries</small></article>
 </section>
 <section class="operations" aria-label="Preparation and delivery workload">
 <article><div class="operation-head"><div class="eyebrow">Preparation pipeline</div><small>All open orders · includes unpaid</small></div><div class="operation-counts"><div><strong id="prep-pending">—</strong><span>To prepare →</span></div><div><strong id="prep-package">—</strong><span>To package →</span></div><div><strong id="prep-ready">—</strong><span>Ready for delivery</span></div></div></article>
 <article><div class="operation-head"><div class="eyebrow">Delivery workload</div><small>All pending deliveries</small></div><div class="operation-counts"><div><strong id="work-jay">—</strong><span>Jay</span></div><div><strong id="work-tony">—</strong><span>Tony</span></div><div><strong id="work-unassigned">—</strong><span>Unassigned</span></div></div><div class="delivery-summary"><span><b id="due-today">—</b> due today</span><span id="overdue-summary"><b id="due-overdue">—</b> overdue</span><span>Next: <b id="next-delivery">—</b></span></div></article>
 </section><section class="chart" aria-label="Sales this week"><div class="chart-head"><div><div class="eyebrow">Monday to Sunday</div><h2>Sales this week</h2></div><div class="week-total" id="week-total">—</div></div><div class="bars" id="bars"></div><div class="actions"><span id="overdue"></span><span id="delivered-unpaid"></span></div></section>
 <footer><span id="live" role="status">Connecting…</span><span id="updated"></span><button type="button" id="fullscreen">Full screen</button></footer>
 </main><script>
 (()=>{
 const money=n=>new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP',maximumFractionDigits:2}).format(n/100),el=id=>document.getElementById(id);let lastSuccess=0,busy=false;
 const london=new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/London',hour:'2-digit',minute:'2-digit',second:'2-digit'}),date=new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/London',weekday:'long',day:'numeric',month:'long'});
 function tick(){el('clock').textContent=london.format(new Date());el('date').textContent=date.format(new Date());if(lastSuccess&&Date.now()-lastSuccess>65000)stale('Updates delayed · reconnecting…');}
 function stale(message){document.body.classList.add('stale');el('live').textContent=message;}
 function update(id,value){const node=el(id),next=String(value);if(node.textContent!==next&&node.textContent!=='—'){node.classList.remove('changed');void node.offsetWidth;node.classList.add('changed');}node.textContent=next;}
 function render(d){
 const prep=d.preparation||{},work=d.workload||{};
 [['prep-pending',prep.pending||0],['prep-package',prep.reconstituted||0],['prep-ready',prep.packaged||0],['work-jay',work.Jay||0],['work-tony',work.Tony||0],['work-unassigned',work.Unassigned||0],['due-today',d.due_today||0],['due-overdue',d.overdue||0]].forEach(([id,value])=>update(id,value));
 el('overdue-summary').classList.toggle('urgent',d.overdue>0);el('next-delivery').textContent=d.next_delivery?new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/London',day:'numeric',month:'short'}).format(new Date(d.next_delivery+'T12:00:00Z')):'None arranged';
 update('sales',money(d.today_sales));update('orders',d.today_orders);update('unpaid',money(d.unpaid));el('unpaid-count').textContent=d.unpaid_orders+' orders · all outstanding balances';update('delivery',d.to_deliver);el('week-total').textContent=money(d.week_sales);el('overdue').textContent=d.overdue+' deliveries overdue';el('delivered-unpaid').textContent=money(d.delivered_unpaid)+' delivered but unpaid';
 const max=Math.max(1,...d.days.map(x=>x.sales));el('bars').replaceChildren();d.days.forEach(day=>{const item=document.createElement('div');item.className='day'+(day.date===d.updated.slice(0,10)?' current':'');const value=document.createElement('span');value.className='day-value';value.textContent=money(day.sales);const slot=document.createElement('div');slot.className='bar-slot';const bar=document.createElement('div');bar.className='bar';bar.style.height=(day.sales/max*100)+'%';slot.append(bar);const label=document.createElement('span');label.className='day-label';label.textContent=day.label;item.append(value,slot,label);el('bars').append(item);});
 lastSuccess=Date.parse(d.updated);document.body.classList.remove('stale');el('live').textContent='● Live · refreshes every 30 seconds';el('updated').textContent='Updated '+london.format(new Date(d.updated))+' · UK time';
 }
 async function refresh(){if(busy)return;busy=true;try{const url=new URL(location.href);url.searchParams.set('format','json');url.searchParams.set('t',Date.now());const r=await fetch(url,{cache:'no-store',credentials:'omit',signal:AbortSignal.timeout(12000)});if(!r.ok)throw new Error();const d=await r.json();if(d.ok!==true||!Array.isArray(d.days))throw new Error();render(d);}catch{stale(navigator.onLine?'Connection lost · reconnecting…':'Offline · showing last update');}finally{busy=false;}}
 render(<?=json_encode($overview,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?>);tick();setInterval(tick,1000);setInterval(refresh,30000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});window.addEventListener('online',refresh);window.addEventListener('offline',()=>stale('Offline · showing last update'));
 el('fullscreen').addEventListener('click',async()=>{try{if(document.fullscreenElement)await document.exitFullscreen();else await document.documentElement.requestFullscreen();}catch{el('fullscreen').textContent='Use your browser’s full-screen option';}});
 })();
 </script></body></html>
 <?php exit;
}
if($reportBoardRequest!==''){
 if(!hash_equals($reportBoardKey,$reportBoardRequest)){http_response_code(404);exit('Not found');}
 $buildReportBoard=function()use($db):array{
  $tz=new DateTimeZone('Europe/London');
  $now=new DateTimeImmutable('now',$tz);
  $monthStart=$now->modify('first day of this month')->setTime(0,0,0);
  $nextMonth=$monthStart->modify('+1 month');
  $from=$monthStart->format('Y-m-d H:i:s');$to=$nextMonth->format('Y-m-d H:i:s');

  $orderSql="SELECT o.id,o.customer,o.phone,o.created,o.payment_date,o.delivery_charge,o.status,
   COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) AS total
   FROM orders o
   LEFT JOIN items i ON i.order_id=o.id
   WHERE o.status IN ('Paid','Packed','Dispatched','Delivered') AND o.pricing_type<>'free'
     AND datetime(CASE WHEN trim(COALESCE(o.payment_date,''))<>'' THEN o.payment_date||' 12:00:00' ELSE o.created END)>=datetime(?)
     AND datetime(CASE WHEN trim(COALESCE(o.payment_date,''))<>'' THEN o.payment_date||' 12:00:00' ELSE o.created END)<datetime(?)
   GROUP BY o.id
   ORDER BY datetime(CASE WHEN trim(COALESCE(o.payment_date,''))<>'' THEN o.payment_date||' 12:00:00' ELSE o.created END) DESC,o.id DESC";
  $q=$db->prepare($orderSql);$q->execute([$from,$to]);$orders=$q->fetchAll(PDO::FETCH_ASSOC);
  $orderIds=array_map('intval',array_column($orders,'id'));
  $orderTotals=[];foreach($orders as $o)$orderTotals[(int)$o['id']]=(int)$o['total'];

  $productRows=[];
  if($orderIds){
   $ph=implode(',',array_fill(0,count($orderIds),'?'));
   $q=$db->prepare("SELECT i.name,SUM(i.quantity) AS units,SUM(i.price*i.quantity) AS revenue
    FROM items i WHERE i.order_id IN ($ph) GROUP BY i.name ORDER BY revenue DESC,units DESC,i.name COLLATE NOCASE");
   $q->execute($orderIds);
   foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
    if(strtolower(trim((string)$row['name']))==='pen' && (int)$row['revenue']===0)continue;
    $productRows[]=['name'=>(string)$row['name'],'units'=>(int)$row['units'],'revenue'=>(int)$row['revenue']];
   }
  }

  $customers=[];
  foreach($orders as $o){
   $key=strtolower(trim((string)$o['customer'])).'|'.trim((string)$o['phone']);
   if(!isset($customers[$key]))$customers[$key]=['name'=>(string)$o['customer'],'orders'=>0,'sales'=>0];
   $customers[$key]['orders']++;$customers[$key]['sales']+=(int)$o['total'];
  }
  $customerRows=array_values($customers);
  usort($customerRows,fn($a,$b)=>$b['sales']<=>$a['sales'] ?: $b['orders']<=>$a['orders'] ?: strcasecmp((string)$a['name'],(string)$b['name']));

  return [
   'month'=>$now->format('F Y'),
   'sales'=>array_sum($orderTotals),
   'orders'=>count($orders),
   'customers'=>count($customerRows),
   'products'=>$productRows,
   'customer_rows'=>$customerRows,
   'units'=>array_sum(array_column($productRows,'units')),
   'updated'=>time()
  ];
 };
 $reportData=$buildReportBoard();
 if((string)($_GET['format']??'')==='json'){
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, max-age=0');
  header('X-Robots-Tag: noindex, nofollow');
  echo json_encode(['ok'=>true]+$reportData,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
 }
 header('Content-Type: text/html; charset=utf-8');
 header('Cache-Control: no-store, max-age=0');
 header('X-Robots-Tag: noindex, nofollow');
 ?>
 <!doctype html>
 <html lang="en">
 <head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#08090a">
  <meta name="robots" content="noindex,nofollow">
  <meta name="referrer" content="no-referrer">
  <title>ANKH • Live monthly reports</title>
  <style>
   :root{color-scheme:dark;--bg:#070809;--panel:#121310;--panel2:#171814;--line:#3b372c;--gold:#d8b658;--gold2:#ffe690;--text:#f8f3e6;--muted:#989b94;--cyan:#79dff3}
   *{box-sizing:border-box}html,body{margin:0;min-height:100%;background:radial-gradient(circle at 18% -12%,#342d18 0,transparent 30%),var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
   body{min-height:100dvh;padding:max(18px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(22px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left))}
   .wrap{width:min(1800px,100%);margin:0 auto}
   header{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;padding:8px 2px 20px;border-bottom:1px solid var(--line)}
   .brand{display:flex;align-items:center;gap:14px}.mark{width:52px;height:52px;display:grid;place-items:center;border:1px solid #776634;border-radius:15px;background:#15140f;color:var(--gold2);font:700 27px Georgia,serif}
   .brand p{margin:0;color:var(--gold);font-size:10px;font-weight:900;letter-spacing:.18em}.brand h1{margin:4px 0 0;font-size:clamp(28px,3.5vw,50px);line-height:.96;letter-spacing:-.04em}
   .live{display:flex;align-items:center;gap:8px;color:#c1c3ba;font-size:11px;font-weight:800;white-space:nowrap}.dot{width:9px;height:9px;border-radius:50%;background:#73e7ad;box-shadow:0 0 0 5px rgba(115,231,173,.08)}
   .hero{display:grid;grid-template-columns:2fr repeat(3,1fr);gap:12px;padding:16px 0}.metric{padding:18px;border:1px solid var(--line);border-radius:17px;background:linear-gradient(145deg,#171814,#10110e)}.metric.primary{background:linear-gradient(145deg,#221d10,#12110d);border-color:#6c5a2d}.metric span{display:block;color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.metric strong{display:block;margin-top:9px;font-size:clamp(24px,3vw,43px);line-height:.95;color:var(--text)}.metric.primary strong{color:var(--gold2)}.metric small{display:block;margin-top:6px;color:#74776f;font-size:10px}
   .panels{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}.panel{border:1px solid var(--line);border-radius:20px;background:linear-gradient(145deg,#151612,#0f100e);padding:18px;min-width:0}.panel-head{display:flex;align-items:end;justify-content:space-between;gap:12px;margin-bottom:12px}.eyebrow{margin:0;color:var(--gold);font-size:9px;font-weight:900;letter-spacing:.18em;text-transform:uppercase}.panel h2{margin:4px 0 0;font-size:clamp(22px,2.4vw,34px);letter-spacing:-.035em}.panel-head small{color:#777a73;font-size:10px}
   .list{display:grid;gap:7px}.row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:12px;padding:11px 12px;border:1px solid #2b2c27;border-radius:12px;background:#11120f}.row strong{font-size:13px;overflow:hidden;text-overflow:ellipsis}.row .count{color:var(--muted);font-size:10px;white-space:nowrap}.row .money{color:var(--gold2);font-size:13px;font-weight:900;white-space:nowrap}.rank{display:inline-grid;place-items:center;width:25px;height:25px;margin-right:9px;border-radius:8px;background:#1d1b13;border:1px solid #4f4528;color:#d7bd71;font-size:10px}
   .empty{padding:34px;text-align:center;border:1px dashed #34362f;border-radius:14px;color:var(--muted)}
   footer{display:flex;justify-content:space-between;gap:12px;padding:18px 2px 0;color:#696c65;font-size:9px}
   @media(max-width:800px){.hero{grid-template-columns:repeat(2,minmax(0,1fr))}.metric.primary{grid-column:1/-1}.panels{grid-template-columns:1fr}.row{grid-template-columns:minmax(0,1fr) auto}.row .count{grid-row:2;grid-column:1}.brand h1{font-size:30px}}
   @media(max-width:470px){body{padding-left:12px;padding-right:12px}header{align-items:flex-start}.live{font-size:9px}.hero{gap:8px}.metric{padding:14px}.metric strong{font-size:26px}.panel{padding:13px}.row{padding:10px}.row strong{font-size:12px}}
   @media(min-width:1200px){body{padding:28px 34px}.hero{gap:15px}.panels{gap:16px}.panel{padding:22px}.list{grid-template-columns:1fr}.row{padding:13px 14px}.row strong,.row .money{font-size:15px}}
   @media(min-width:1600px){.panels{grid-template-columns:1.15fr .85fr}.row{padding:15px}.row strong,.row .money{font-size:17px}}
  </style>
 </head>
 <body>
 <main class="wrap">
  <header>
   <div class="brand"><div class="mark">☥</div><div><p>ANKH PEPTIDES</p><h1>Monthly performance</h1></div></div>
   <div class="live"><i class="dot"></i><span id="live-label">LIVE · <?=$reportData['month']?></span></div>
  </header>
  <section class="hero">
   <article class="metric primary"><span>Sales this month</span><strong id="total-sales">£<?=number_format($reportData['sales']/100,2)?></strong><small id="month-label"><?=e($reportData['month'])?></small></article>
   <article class="metric"><span>Completed orders</span><strong id="order-count"><?=$reportData['orders']?></strong><small>Paid / packed / dispatched / delivered</small></article>
   <article class="metric"><span>Customers</span><strong id="customer-count"><?=$reportData['customers']?></strong><small>Customers with completed sales</small></article>
   <article class="metric"><span>Units sold</span><strong id="unit-count"><?=$reportData['units']?></strong><small>Across all products</small></article>
  </section>
  <section class="panels">
   <article class="panel"><div class="panel-head"><div><p class="eyebrow">SALES BY PRODUCT</p><h2>Products this month</h2></div><small>Highest revenue first</small></div><div class="list" id="product-list">
    <?php foreach($reportData['products'] as $i=>$row):?><div class="row"><div><span class="rank"><?=$i+1?></span><strong><?=e($row['name'])?></strong></div><span class="count"><?=$row['units']?> unit<?=$row['units']===1?'':'s'?></span><span class="money">£<?=number_format($row['revenue']/100,2)?></span></div><?php endforeach;?>
   </div><div class="empty" id="product-empty" <?=$reportData['products']?'hidden':''?>>No completed product sales this month.</div></article>
   <article class="panel"><div class="panel-head"><div><p class="eyebrow">SALES BY CUSTOMER</p><h2>Customers this month</h2></div><small>Highest spend first</small></div><div class="list" id="customer-list">
    <?php foreach($reportData['customer_rows'] as $i=>$row):?><div class="row"><div><span class="rank"><?=$i+1?></span><strong><?=e($row['name'])?></strong></div><span class="count"><?=$row['orders']?> order<?=$row['orders']===1?'':'s'?></span><span class="money">£<?=number_format($row['sales']/100,2)?></span></div><?php endforeach;?>
   </div><div class="empty" id="customer-empty" <?=$reportData['customer_rows']?'hidden':''?>>No completed customer sales this month.</div></article>
  </section>
  <footer><span>ANKH live monthly reporting</span><span>Refreshes automatically every 20 seconds</span></footer>
 </main>
 <script>
 (()=>{const key=<?=json_encode($reportBoardKey)?>,money=n=>new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP'}).format(Number(n||0)/100),esc=v=>String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 const els={sales:document.getElementById('total-sales'),orders:document.getElementById('order-count'),customers:document.getElementById('customer-count'),units:document.getElementById('unit-count'),month:document.getElementById('month-label'),live:document.getElementById('live-label'),products:document.getElementById('product-list'),customersList:document.getElementById('customer-list'),productEmpty:document.getElementById('product-empty'),customerEmpty:document.getElementById('customer-empty')};
 function render(data){els.sales.textContent=money(data.sales);els.orders.textContent=Number(data.orders||0);els.customers.textContent=Number(data.customers||0);els.units.textContent=Number(data.units||0);els.month.textContent=data.month||'';els.live.textContent='LIVE · '+(data.month||'')+' · '+new Date((Number(data.updated)||Date.now()/1000)*1000).toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
 const products=Array.isArray(data.products)?data.products:[];els.products.innerHTML=products.map((r,i)=>'<div class="row"><div><span class="rank">'+(i+1)+'</span><strong>'+esc(r.name)+'</strong></div><span class="count">'+Number(r.units||0)+' unit'+(Number(r.units||0)===1?'':'s')+'</span><span class="money">'+money(r.revenue)+'</span></div>').join('');els.productEmpty.hidden=products.length>0;
 const customers=Array.isArray(data.customer_rows)?data.customer_rows:[];els.customersList.innerHTML=customers.map((r,i)=>'<div class="row"><div><span class="rank">'+(i+1)+'</span><strong>'+esc(r.name)+'</strong></div><span class="count">'+Number(r.orders||0)+' order'+(Number(r.orders||0)===1?'':'s')+'</span><span class="money">'+money(r.sales)+'</span></div>').join('');els.customerEmpty.hidden=customers.length>0;}
 async function refresh(){try{const r=await fetch('?reportboard='+encodeURIComponent(key)+'&format=json&t='+Date.now(),{cache:'no-store',credentials:'omit'});if(!r.ok)throw new Error('refresh failed');const data=await r.json();if(data.ok)render(data)}catch(e){els.live.textContent='LIVE · reconnecting…'}}
 refresh();setInterval(refresh,20000);})();
 </script>
 </body></html>
 <?php
 exit;
}

$db->exec('CREATE TABLE IF NOT EXISTS stock_movements (
 id INTEGER PRIMARY KEY,
 product_id INTEGER NOT NULL REFERENCES products(id),
 movement_type TEXT NOT NULL,
 quantity_change INTEGER NOT NULL,
 order_id INTEGER DEFAULT NULL,
 location TEXT NOT NULL DEFAULT "Unallocated",
 supplier TEXT NOT NULL DEFAULT "",
 batch_reference TEXT NOT NULL DEFAULT "",
 expiry_date TEXT NOT NULL DEFAULT "",
 unit_cost INTEGER DEFAULT NULL,
 note TEXT NOT NULL DEFAULT "",
 created TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS stock_delivery_events (
 order_id INTEGER PRIMARY KEY REFERENCES orders(id) ON DELETE CASCADE,
 recorded_at TEXT NOT NULL,
 delivery_date TEXT NOT NULL DEFAULT ""
);
CREATE TABLE IF NOT EXISTS stock_fulfilments (
 order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
 product_id INTEGER NOT NULL REFERENCES products(id),
 quantity INTEGER NOT NULL,
 location TEXT NOT NULL DEFAULT "Unallocated",
 PRIMARY KEY(order_id,product_id)
);
CREATE INDEX IF NOT EXISTS idx_stock_moves_product ON stock_movements(product_id,created);
CREATE INDEX IF NOT EXISTS idx_stock_moves_created ON stock_movements(created);');
$db->exec('CREATE TABLE IF NOT EXISTS supplier_orders (
 id INTEGER PRIMARY KEY,
 supplier TEXT NOT NULL DEFAULT "",
 expected_date TEXT NOT NULL DEFAULT "",
 status TEXT NOT NULL DEFAULT "Ordered",
 note TEXT NOT NULL DEFAULT "",
 created TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS supplier_order_items (
 id INTEGER PRIMARY KEY,
 supplier_order_id INTEGER NOT NULL REFERENCES supplier_orders(id) ON DELETE CASCADE,
 product_id INTEGER NOT NULL REFERENCES products(id),
 packs INTEGER NOT NULL DEFAULT 1,
 pack_size INTEGER NOT NULL DEFAULT 10,
 quantity INTEGER NOT NULL,
 unit_cost INTEGER DEFAULT NULL,
 received_qty INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_supplier_orders_status ON supplier_orders(status,expected_date);
CREATE INDEX IF NOT EXISTS idx_supplier_order_items_order ON supplier_order_items(supplier_order_id);');
// BEGIN SELF-CONTAINED SUPPLIER CENTRE — included in index.php so legacy Krystal deployments work.
/**
 * ANKH Supplier & Profit Centre.
 * Supplier prices are uploaded by an authenticated admin and stored ONLY in SQLite.
 * NEVER publish the supplier price CSV or its contents in the public repository.
 */
function sc_setup(PDO $db):void{
 $db->exec("CREATE TABLE IF NOT EXISTS supplier_catalogue (
  sku TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  strength TEXT NOT NULL,
  pack_size INTEGER NOT NULL DEFAULT 10 CHECK(pack_size>0),
  box_usd_cents INTEGER NOT NULL CHECK(box_usd_cents>=0),
  product_id INTEGER UNIQUE REFERENCES products(id) ON DELETE SET NULL,
  updated TEXT NOT NULL DEFAULT ''
 );
 CREATE TABLE IF NOT EXISTS supplier_basket_drafts (
  id INTEGER PRIMARY KEY CHECK(id=1),
  boxes_json TEXT NOT NULL DEFAULT '{}',
  shipping_gbp TEXT NOT NULL DEFAULT '0',
  expected_date TEXT NOT NULL DEFAULT '',
  updated TEXT NOT NULL
 );
 CREATE TABLE IF NOT EXISTS supplier_price_history (
  id INTEGER PRIMARY KEY,
  sku TEXT NOT NULL,
  old_usd_cents INTEGER NOT NULL,
  new_usd_cents INTEGER NOT NULL,
  changed TEXT NOT NULL
 );
 CREATE TABLE IF NOT EXISTS supplier_purchase_snapshots (
  supplier_order_id INTEGER PRIMARY KEY REFERENCES supplier_orders(id) ON DELETE CASCADE,
  usd_total_cents INTEGER NOT NULL,
  fx_rate REAL NOT NULL,
  shipping_gbp_pence INTEGER NOT NULL DEFAULT 0,
  estimated_gbp_pence INTEGER NOT NULL,
  order_message TEXT NOT NULL,
  created TEXT NOT NULL
 );
 CREATE TABLE IF NOT EXISTS supplier_purchase_lines (
  supplier_order_id INTEGER NOT NULL REFERENCES supplier_orders(id) ON DELETE CASCADE,
  sku TEXT NOT NULL,
  product_name TEXT NOT NULL,
  strength TEXT NOT NULL,
  packs INTEGER NOT NULL,
  units_per_pack INTEGER NOT NULL,
  box_usd_cents INTEGER NOT NULL,
  PRIMARY KEY(supplier_order_id,sku)
 );");
 // Idempotent optional columns; older drafts and completed purchases remain intact.
 $draftColumns=array_column($db->query('PRAGMA table_info(supplier_basket_drafts)')->fetchAll(PDO::FETCH_ASSOC),'name');
 if(!in_array('shipping_usd_cents',$draftColumns,true))$db->exec('ALTER TABLE supplier_basket_drafts ADD COLUMN shipping_usd_cents INTEGER DEFAULT NULL');
 if(!in_array('discount_usd_cents',$draftColumns,true))$db->exec('ALTER TABLE supplier_basket_drafts ADD COLUMN discount_usd_cents INTEGER NOT NULL DEFAULT 0');
 $snapshotColumns=array_column($db->query('PRAGMA table_info(supplier_purchase_snapshots)')->fetchAll(PDO::FETCH_ASSOC),'name');
 if(!in_array('shipping_usd_cents',$snapshotColumns,true))$db->exec('ALTER TABLE supplier_purchase_snapshots ADD COLUMN shipping_usd_cents INTEGER DEFAULT NULL');
 if(!in_array('discount_usd_cents',$snapshotColumns,true))$db->exec('ALTER TABLE supplier_purchase_snapshots ADD COLUMN discount_usd_cents INTEGER NOT NULL DEFAULT 0');
 if(!in_array('discount_gbp_pence',$snapshotColumns,true))$db->exec('ALTER TABLE supplier_purchase_snapshots ADD COLUMN discount_gbp_pence INTEGER NOT NULL DEFAULT 0');
}
function sc_money_usd(int $cents):string{return '$'.number_format($cents/100,2);}
function sc_normal_name(string $name):string{return preg_replace('/[^a-z0-9]/','',strtolower($name));}
function sc_match_products(PDO $db):void{
 $products=$db->query("SELECT id,name FROM products WHERE active=1")->fetchAll(PDO::FETCH_ASSOC);
 $byName=[];
 foreach($products as $product){$key=sc_normal_name((string)$product['name']);$byName[$key][]=(int)$product['id'];}
 $rows=$db->query('SELECT sku,name,strength FROM supplier_catalogue WHERE product_id IS NULL')->fetchAll(PDO::FETCH_ASSOC);
 $countById=[];
 $suggestions=[];
 foreach($rows as $row){
  $key=sc_normal_name($row['name'].' '.$row['strength']);$matches=$byName[$key]??[];
  if(count($matches)!==1)continue;
  $id=$matches[0];$countById[$id]=($countById[$id]??0)+1;
  $suggestions[$row['sku']]=$id;
 }
 $save=$db->prepare('UPDATE supplier_catalogue SET product_id=? WHERE sku=? AND product_id IS NULL');
 foreach($suggestions as $sku=>$id){
  // Never assign two supplier SKUs to one product, or overwrite a manual mapping.
  if($countById[$id]!==1)continue;
  $q=$db->prepare('SELECT count(*) FROM supplier_catalogue WHERE product_id=?');$q->execute([$id]);
  if((int)$q->fetchColumn()>0)continue;
  $save->execute([$id,$sku]);
 }
}
function sc_fx(PDO $db):?float{
 $q=$db->query("SELECT value FROM settings WHERE key='supplier_usd_gbp_rate'");
 $value=trim((string)($q->fetchColumn()?:''));
 if($value===''||!is_numeric($value))return null;
 $fx=(float)$value;
 return $fx>0&&$fx<10?$fx:null;
}
function sc_shipping_order_usd_cents(PDO $db):int{
 $value=$db->query("SELECT value FROM settings WHERE key='supplier_shipping_order_usd_cents'")->fetchColumn();
 if($value===false)return 7000; // Quoted $70 shipping fee per entire MJT order.
 return max(0,min(10000000,(int)$value));
}
function sc_shipping_draft_usd_cents(array $draft,?float $fx,int $default):int{
 if(isset($draft['shipping_usd_cents']))return max(0,(int)$draft['shipping_usd_cents']);
 // Preserve older GBP draft shipping estimates until reviewed in the USD field.
 $legacy=(string)($draft['shipping_gbp']??'0');
 if(is_numeric($legacy)&&(float)$legacy>0&&$fx!==null)return (int)round((float)$legacy*100/$fx);
 return $default;
}
function sc_valid_shipping_usd(string $input):int{
 if(!preg_match('/^\d+(?:\.\d{1,2})?$/',$input)||(float)$input>100000)throw new Exception('Enter valid supplier order shipping in USD.');
 return (int)round((float)$input*100);
}
function sc_valid_discount_usd(string $value):int{
 if(!preg_match('/^\d+(?:\.\d{1,2})?$/',$value)||(float)$value>100000)throw new Exception('Enter a valid supplier discount in USD.');
 return (int)round((float)$value*100);
}
function sc_discount_within_products(int $discountCents,int $productUsdCents):void{
 if($discountCents>$productUsdCents)throw new Exception('Supplier discount cannot exceed the selected products total.');
}
function sc_save_setting(PDO $db,string $key,string $value):void{
 $db->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute([$key,$value]);
}
/**
 * Build transparent buying suggestions. Product's available stock is already AFTER
 * reservations (including negative balances for order shortages), so do not deduct
 * reservations again. Positive spare stock across fridges/holding can cover the
 * other fridge's shortage via a MANUAL transfer before buying.
 */
function sc_smart_suggestions(array $catalogue,array $reservedByProduct,array $incomingByProduct,array $deliveredByProduct,array $recurringByName,int $lookbackDays=30):array{
 if(!in_array($lookbackDays,[30,60,90],true))$lookbackDays=30;
 $forecast=[];foreach($recurringByName as $name=>$quantity){
  $key=strtolower(trim((string)$name));if($key==='pen')continue;
  $forecast[$key]=($forecast[$key]??0)+max(0,(int)$quantity);
 }
 $out=[];
 foreach($catalogue as $item){
  $id=isset($item['product_id'])?(int)$item['product_id']:0;
  if($id<1)continue;
  $tracked=(int)($item['stock_tracking']??0)===1;
  $jay=(int)($item['stock_jay_qty']??0);$tony=(int)($item['stock_tony_qty']??0);$holding=(int)($item['stock_unallocated_qty']??0);
  $rawAvailable=$jay+$tony+$holding;
  $available=max(0,$rawAvailable);$incoming=max(0,(int)($incomingByProduct[$id]??0));
  $reserved=array_sum(array_map('intval',$reservedByProduct[$id]??[]));
  $delivered=max(0,(int)($deliveredByProduct[$id]??0));
  $leadDemand=(int)ceil($delivered*7/$lookbackDays);
  $recurring=max(0,(int)($forecast[strtolower(trim((string)($item['ankh_name']??'')))]??0));
  $threshold=max(0,(int)($item['low_stock_at']??5));
  $pack=max(1,(int)($item['pack_size']??10));
  // Shortage is NOT a second deduction: negative raw available already reflects it.
  $urgent=max(0,-$rawAvailable-$incoming);
  $target=$threshold+$leadDemand+$recurring;
  $gap=$tracked?max(0,$target-$rawAvailable-$incoming):0;
  $boxes=$tracked&&$gap>0?(int)ceil($gap/$pack):0;
  $transferJamesToTony=min(max(0,-$tony),max(0,$jay));
  $transferTonyToJames=min(max(0,-$jay),max(0,$tony));
  $transferHolding=min(max(0,-$jay)+max(0,-$tony),max(0,$holding));
  $status=!$tracked?'untracked':($urgent>0?'shortage':($boxes>0?'reorder':'covered'));
  $out[]=[
   'sku'=>(string)$item['sku'],'name'=>(string)$item['name'].' '.(string)$item['strength'],
   'product_id'=>$id,'pack'=>$pack,'box_usd_cents'=>(int)$item['box_usd_cents'],'tracked'=>$tracked,
   'jay_available'=>max(0,$jay),'tony_available'=>max(0,$tony),'holding_available'=>max(0,$holding),
   'jay_short'=>max(0,-$jay),'tony_short'=>max(0,-$tony),'available_raw'=>$rawAvailable,
   'available'=>$available,'reserved'=>$reserved,'incoming'=>$incoming,
   'recent_delivered'=>$delivered,'lead_demand'=>$leadDemand,'recurring30'=>$recurring,'low_at'=>$threshold,
   'target'=>$target,'gap'=>$gap,'urgent'=>$urgent,'boxes'=>$boxes,'units'=>$boxes*$pack,'status'=>$status,
   'transfer_jay_to_tony'=>$transferJamesToTony,'transfer_tony_to_jay'=>$transferTonyToJames,'transfer_holding'=>$transferHolding
  ];
 }
 $rank=['shortage'=>0,'reorder'=>1,'untracked'=>2,'covered'=>3];
 usort($out,static fn($a,$b)=>$rank[$a['status']]<=>$rank[$b['status']] ?: $b['urgent']<=>$a['urgent'] ?: $b['gap']<=>$a['gap'] ?: strcmp($a['name'],$b['name']));
 return $out;
}
function sc_recent_delivered(PDO $db,int $days):array{
 if(!in_array($days,[30,60,90],true))$days=30;
 // Only actually delivered non-recurring units; don't infer future demand from unpaid or open orders.
 $local=new DateTimeImmutable('today',new DateTimeZone('Europe/London'));
 $start=$local->modify('-'.($days-1).' days')->format('Y-m-d');
 $stmt=$db->prepare("SELECT p.id,SUM(i.quantity) AS qty
  FROM stock_delivery_events e JOIN orders o ON o.id=e.order_id
  JOIN items i ON i.order_id=e.order_id
  JOIN products p ON p.id=COALESCE(i.product_id,(SELECT p2.id FROM products p2 WHERE lower(trim(p2.name))=lower(trim(i.name)) ORDER BY p2.id LIMIT 1))
  WHERE o.status<>'Cancelled' AND COALESCE(i.recurring,0)=0
   AND date(e.delivery_date)>=date(?) AND date(e.delivery_date)<=date(?)
   AND (trim(COALESCE(p.stock_tracking_since,''))='' OR date(e.delivery_date)>=date(p.stock_tracking_since))
  GROUP BY p.id");
 $stmt->execute([$start,$local->format('Y-m-d')]);
 return array_map('intval',$stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}
function sc_post(PDO $db,string $action):void{
 if($action==='supplier_centre_smart_apply'){
  $boxes=$_POST['boxes']??[];
  if(!is_array($boxes)||count($boxes)>500)throw new Exception('Choose valid box quantities.');
  $chosen=[];
  foreach($boxes as $sku=>$count){
   // HTML form field boxes[375] becomes integer key 375 in PHP; normalise before validating.
   $sku=(string)$sku;
   if(!preg_match('/^[A-Z0-9-]{1,20}$/',$sku))throw new Exception('Invalid supplier product code.');
   $n=filter_var($count,FILTER_VALIDATE_INT);
   if($n===false||$n<0||$n>100)throw new Exception('Choose between 0 and 100 boxes.');
   if($n>0)$chosen[$sku]=(int)$n;
  }
  if(!$chosen)throw new Exception('Tick at least one suggested product to add to the shared basket.');
  $catalogue=$db->prepare('SELECT s.product_id,p.stock_tracking FROM supplier_catalogue s JOIN products p ON p.id=s.product_id WHERE s.sku=?');
  foreach($chosen as $sku=>$n){
   $catalogue->execute([$sku]);$row=$catalogue->fetch(PDO::FETCH_ASSOC);
   if(!$row||(int)$row['stock_tracking']!==1)throw new Exception('Match and track the stock for '.$sku.' before adding it.');
  }
  $db->beginTransaction();
  try{
   $row=$db->query('SELECT boxes_json,shipping_gbp,shipping_usd_cents,discount_usd_cents,expected_date FROM supplier_basket_drafts WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];
   $draft=json_decode((string)($row['boxes_json']??'{}'),true);if(!is_array($draft))$draft=[];
   foreach($chosen as $sku=>$n)$draft[$sku]=max((int)($draft[$sku]??0),$n);
   $draft=array_filter($draft,static fn($n)=>(int)$n>0);
   $db->prepare("INSERT INTO supplier_basket_drafts(id,boxes_json,shipping_gbp,shipping_usd_cents,discount_usd_cents,expected_date,updated)
    VALUES (1,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET boxes_json=excluded.boxes_json,updated=excluded.updated")
    ->execute([json_encode($draft,JSON_THROW_ON_ERROR),(string)($row['shipping_gbp']??'0'),$row['shipping_usd_cents']===null?null:(int)$row['shipping_usd_cents'],(int)($row['discount_usd_cents']??0),(string)($row['expected_date']??''),gmdate('c')]);
   $db->commit();
  }catch(Throwable $err){if($db->inTransaction())$db->rollBack();throw $err;}
  $_SESSION['flash']='Selected boxes added to your shared supplier basket for review. Nothing has been ordered.';
  header('Location: ./?view=supplier&tab=order');exit;
 }
 if($action==='supplier_centre_import'){
  $file=$_FILES['supplier_csv']??null;
  if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))throw new Exception('Choose the supplier CSV file and try again.');
  if((int)($file['size']??0)<10||(int)$file['size']>300000)throw new Exception('Supplier file must be a CSV under 300 KB.');
  $handle=fopen($file['tmp_name'],'rb');if(!$handle)throw new Exception('Supplier CSV could not be read.');
  try{
   $columns=fgetcsv($handle);if(!$columns)throw new Exception('Supplier CSV is empty.');
   $columns=array_map(static fn($v)=>strtolower(trim(preg_replace('/^\xEF\xBB\xBF/','',(string)$v))),$columns);
   if($columns!==['sku','name','strength','pack_size','box_usd'])throw new Exception('Use the supplied CSV with columns sku,name,strength,pack_size,box_usd.');
   $rows=[];$seen=[];
   while(($row=fgetcsv($handle))!==false){
    if(count($row)===1&&trim((string)$row[0])==='')continue;
    if(count($row)!==5)throw new Exception('A supplier CSV row has the wrong number of columns.');
    [$sku,$name,$strength,$pack,$usd]=array_map('trim',$row);$sku=strtoupper($sku);
    if(!preg_match('/^[A-Z0-9-]{1,20}$/',$sku)||$name===''||strlen($name)>500||!preg_match('/^\d+(?:\.\d+)?(?:mg|ml|iu)$/i',$strength))throw new Exception('Invalid supplier product code, name or strength on row '.(count($rows)+2).'.');
    if(isset($seen[$sku]))throw new Exception('Duplicate supplier code '.$sku.'.');
    $packQty=filter_var($pack,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>500]]);
    if($packQty===false||!preg_match('/^\d+(?:\.\d{1,2})?$/',$usd)||floatval($usd)>100000)throw new Exception('Invalid pack size or box price for '.$sku.'.');
    $rows[]=[$sku,$name,strtolower($strength),(int)$packQty,(int)round(((float)$usd)*100)];$seen[$sku]=true;
    if(count($rows)>500)throw new Exception('The supplier file contains too many rows.');
   }
   if(!$rows)throw new Exception('No supplier products found.');
  }finally{fclose($handle);}
  $db->beginTransaction();
  try{
   $old=$db->prepare('SELECT box_usd_cents FROM supplier_catalogue WHERE sku=?');
   $upsert=$db->prepare('INSERT INTO supplier_catalogue(sku,name,strength,pack_size,box_usd_cents,updated) VALUES (?,?,?,?,?,?) ON CONFLICT(sku) DO UPDATE SET name=excluded.name,strength=excluded.strength,pack_size=excluded.pack_size,box_usd_cents=excluded.box_usd_cents,updated=excluded.updated');
   $history=$db->prepare('INSERT INTO supplier_price_history(sku,old_usd_cents,new_usd_cents,changed) VALUES (?,?,?,?)');
   $now=gmdate('c');
   foreach($rows as [$sku,$name,$strength,$pack,$usdCents]){
    $old->execute([$sku]);$previous=$old->fetchColumn();
    if($previous!==false && (int)$previous!==$usdCents)$history->execute([$sku,(int)$previous,$usdCents,$now]);
    $upsert->execute([$sku,$name,$strength,$pack,$usdCents,$now]);
   }
   sc_match_products($db);
   sc_save_setting($db,'supplier_import_last',$now);
   $db->commit();
  }catch(Throwable $err){if($db->inTransaction())$db->rollBack();throw $err;}
  $_SESSION['flash']=count($rows).' MJT supplier prices imported; existing product links preserved.';
  header('Location: ./?view=supplier&tab=profit');exit;
 }
 if($action==='supplier_centre_config'){
  $rate=trim((string)($_POST['usd_gbp_rate']??''));
  $shipping=trim((string)($_POST['shipping_per_order_usd']??'70'));
  if($rate!==''&&(!is_numeric($rate)||(float)$rate<=0||(float)$rate>=10))throw new Exception('Enter GBP per 1 USD, such as 0.78.');
  $shippingUsdCents=sc_valid_shipping_usd($shipping);
  sc_save_setting($db,'supplier_usd_gbp_rate',$rate);
  sc_save_setting($db,'supplier_shipping_order_usd_cents',(string)$shippingUsdCents);
  $_SESSION['flash']='Exchange rate and fixed USD shipping per supplier order saved. Existing purchases are unchanged.';
  header('Location: ./?view=supplier&tab=profit');exit;
 }
 if($action==='supplier_centre_map'){
  $sku=strtoupper(trim((string)($_POST['sku']??'')));
  $raw=trim((string)($_POST['product_id']??''));
  if(!preg_match('/^[A-Z0-9-]{1,20}$/',$sku))throw new Exception('Unknown supplier product.');
  $id=$raw===''?null:filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if($id===false)throw new Exception('Choose an ANKH product or leave it unmapped.');
  $q=$db->prepare('SELECT count(*) FROM supplier_catalogue WHERE sku=?');$q->execute([$sku]);if(!(int)$q->fetchColumn())throw new Exception('Supplier SKU not found.');
  if($id!==null){
   $q=$db->prepare('SELECT count(*) FROM products WHERE id=?');$q->execute([$id]);if(!(int)$q->fetchColumn())throw new Exception('ANKH product not found.');
   $q=$db->prepare('SELECT sku FROM supplier_catalogue WHERE product_id=? AND sku<>?');$q->execute([$id,$sku]);
   if($q->fetchColumn()!==false)throw new Exception('That ANKH product is already linked to another supplier SKU. Remove the old link first.');
  }
  $db->prepare('UPDATE supplier_catalogue SET product_id=? WHERE sku=?')->execute([$id,$sku]);
  $_SESSION['flash']='Supplier product mapping saved.';
  header('Location: ./?view=supplier&tab=products');exit;
 }
 if($action==='supplier_centre_price'){
  $sku=strtoupper(trim((string)($_POST['sku']??'')));$box=trim((string)($_POST['box_usd']??''));
  if(!preg_match('/^[A-Z0-9-]{1,20}$/',$sku)||!preg_match('/^\d+(?:\.\d{1,2})?$/',$box)||(float)$box>100000)throw new Exception('Enter a valid USD box price.');
  $newCents=(int)round((float)$box*100);
  $q=$db->prepare('SELECT box_usd_cents FROM supplier_catalogue WHERE sku=?');$q->execute([$sku]);$old=$q->fetchColumn();
  if($old===false)throw new Exception('Supplier product does not exist.');
  $db->beginTransaction();
  try{
   if((int)$old!==$newCents)$db->prepare('INSERT INTO supplier_price_history(sku,old_usd_cents,new_usd_cents,changed) VALUES (?,?,?,?)')->execute([$sku,(int)$old,$newCents,gmdate('c')]);
   $db->prepare('UPDATE supplier_catalogue SET box_usd_cents=?,updated=? WHERE sku=?')->execute([$newCents,gmdate('c'),$sku]);$db->commit();
  }catch(Throwable $err){if($db->inTransaction())$db->rollBack();throw $err;}
  $_SESSION['flash']='Supplier USD box price updated. Past orders are unchanged.';
  header('Location: ./?view=supplier&tab=products');exit;
 }
 if($action==='supplier_centre_place'){
  $boxes=$_POST['boxes']??[];if(!is_array($boxes))throw new Exception('Choose supplier boxes.');
  $requested=[];foreach($boxes as $sku=>$quantity){
   // MJT supplies numeric-only codes (e.g. LL37 = 375). PHP turns boxes[375] into an integer key.
   $sku=(string)$sku;
   if(!preg_match('/^[A-Z0-9-]{1,20}$/',$sku))throw new Exception('Invalid supplier code.');
   $qty=filter_var($quantity,FILTER_VALIDATE_INT);
   if($qty===false||$qty<0||$qty>100)throw new Exception('Select 0–100 full boxes per product.');
   if($qty>0)$requested[$sku]=(int)$qty;
  }
  if(($_POST['supplier_intent']??'')==='draft'){
   $shipDraft=trim((string)($_POST['shipping_usd']??number_format(sc_shipping_order_usd_cents($db)/100,2,'.','')));
   $expectedDraft=trim((string)($_POST['expected_date']??''));
   $shipDraftCents=sc_valid_shipping_usd($shipDraft);
   $discountDraftCents=sc_valid_discount_usd(trim((string)($_POST['discount_usd']??'0')));
   if($expectedDraft!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expectedDraft)||!checkdate((int)substr($expectedDraft,5,2),(int)substr($expectedDraft,8,2),(int)substr($expectedDraft,0,4))))throw new Exception('Enter a valid expected date.');
   $draftFx=sc_fx($db);
   $legacyGbp=$draftFx===null?'0':number_format($shipDraftCents*$draftFx/100,2,'.','');
   $draftUsd=0;
   if($requested){
    $prices=$db->prepare('SELECT box_usd_cents FROM supplier_catalogue WHERE sku=?');
    foreach($requested as $sku=>$count){$prices->execute([$sku]);$price=$prices->fetchColumn();if($price===false)throw new Exception('Unknown supplier code '.$sku.'.');$draftUsd+=(int)$price*$count;}
   }
   sc_discount_within_products($discountDraftCents,$draftUsd);
   $db->prepare("INSERT INTO supplier_basket_drafts(id,boxes_json,shipping_gbp,shipping_usd_cents,discount_usd_cents,expected_date,updated) VALUES (1,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET boxes_json=excluded.boxes_json,shipping_gbp=excluded.shipping_gbp,shipping_usd_cents=excluded.shipping_usd_cents,discount_usd_cents=excluded.discount_usd_cents,expected_date=excluded.expected_date,updated=excluded.updated")->execute([json_encode($requested,JSON_THROW_ON_ERROR),$legacyGbp,$shipDraftCents,$discountDraftCents,$expectedDraft,gmdate('c')]);
   $_SESSION['flash']='Shared supplier basket draft saved for James and Tony.';
   header('Location: ./?view=supplier&tab=order');exit;
  }
  if(!$requested||count($requested)>100)throw new Exception('Select at least one mapped supplier product.');
  $fx=sc_fx($db);
  if($fx===null)throw new Exception('Save an exchange rate first, so stock costs can be recorded in GBP.');
  $ship=trim((string)($_POST['shipping_usd']??number_format(sc_shipping_order_usd_cents($db)/100,2,'.','')));
  $shippingUsdCents=sc_valid_shipping_usd($ship);
  $shippingPence=(int)round($shippingUsdCents*$fx);
  $discountUsdCents=sc_valid_discount_usd(trim((string)($_POST['discount_usd']??'0')));
  $expected=trim((string)($_POST['expected_date']??''));
  if($expected!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expected)||!checkdate((int)substr($expected,5,2),(int)substr($expected,8,2),(int)substr($expected,0,4))))throw new Exception('Enter a valid expected delivery date.');
  $fetch=$db->prepare('SELECT s.*,p.id AS mapped_id,p.name AS mapped_name FROM supplier_catalogue s LEFT JOIN products p ON p.id=s.product_id WHERE s.sku=?');
  $lines=[];$totalUsd=0;$totalUnits=0;
  foreach($requested as $sku=>$qty){
   $fetch->execute([$sku]);$item=$fetch->fetch(PDO::FETCH_ASSOC);
   if(!$item||!$item['mapped_id'])throw new Exception('Map '.$sku.' to an ANKH product before recording a purchase.');
   $cost=(int)$item['box_usd_cents']*$qty;
   $totalUsd+=$cost;$totalUnits+=(int)$item['pack_size']*$qty;
   $lines[]=['sku'=>$sku,'name'=>$item['name'],'strength'=>$item['strength'],'product_id'=>(int)$item['mapped_id'],'packs'=>$qty,'pack_size'=>(int)$item['pack_size'],'box_usd_cents'=>(int)$item['box_usd_cents'],'usd_total'=>$cost];
  }
  sc_discount_within_products($discountUsdCents,$totalUsd);
  $discountGbpPence=(int)round($discountUsdCents*$fx);
  $message=sc_supplier_message($lines);
  $db->beginTransaction();
  try{
   $db->prepare("INSERT INTO supplier_orders(supplier,expected_date,status,note,created) VALUES (?,?, 'Ordered', ?, ?)")->execute(['MJT',$expected,'Combined James + Tony order from Supplier Centre',gmdate('c')]);
   $orderId=(int)$db->lastInsertId();
   $lineInsert=$db->prepare('INSERT INTO supplier_order_items(supplier_order_id,product_id,packs,pack_size,quantity,unit_cost,received_qty) VALUES (?,?,?,?,?,?,0)');
   $snap=$db->prepare('INSERT INTO supplier_purchase_lines(supplier_order_id,sku,product_name,strength,packs,units_per_pack,box_usd_cents) VALUES (?,?,?,?,?,?,?)');
   foreach($lines as $line){
    $units=$line['packs']*$line['pack_size'];
    // Allocate the one-off shipping and supplier discount by the wholesale value of each line.
    $lineShipping=$totalUsd>0?(int)round($shippingPence*$line['usd_total']/$totalUsd):(int)round($shippingPence*$units/$totalUnits);
    $lineDiscount=$totalUsd>0?(int)round($discountGbpPence*$line['usd_total']/$totalUsd):0;
    $unitCost=max(0,(int)round(($line['usd_total']*$fx+$lineShipping-$lineDiscount)/$units));
    $lineInsert->execute([$orderId,$line['product_id'],$line['packs'],$line['pack_size'],$units,$unitCost]);
    $snap->execute([$orderId,$line['sku'],$line['name'],$line['strength'],$line['packs'],$line['pack_size'],$line['box_usd_cents']]);
   }
   $estimate=(int)round(($totalUsd-$discountUsdCents+$shippingUsdCents)*$fx);
   $db->prepare('INSERT INTO supplier_purchase_snapshots(supplier_order_id,usd_total_cents,fx_rate,shipping_gbp_pence,shipping_usd_cents,discount_usd_cents,discount_gbp_pence,estimated_gbp_pence,order_message,created) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$orderId,$totalUsd,$fx,$shippingPence,$shippingUsdCents,$discountUsdCents,$discountGbpPence,$estimate,$message,gmdate('c')]);
   $db->prepare('DELETE FROM supplier_basket_drafts WHERE id=1')->execute();
   $db->commit();
  }catch(Throwable $err){if($db->inTransaction())$db->rollBack();throw $err;}
  $_SESSION['flash']='MJT purchase recorded in Incoming Stock; no inventory has been added yet.';
  header('Location: ./?view=stock&section=incoming');exit;
 }
 throw new Exception('Unknown supplier action.');
}
/**
 * The message to Jessica is a request for full supplier boxes, not a cost invoice.
 * Keep labels presentational; retain the imported SKU and underlying product unchanged.
 */
function sc_supplier_item_label(string $name,string $strength):string{
 $base=trim($name);
 $key=strtolower(preg_replace('/\s+/','',$base));
 $aliases=['bpc157'=>'BPC-157','kisspeptin-10'=>'Kisspeptin-10','ara290'=>'ARA-290'];
 $base=$aliases[$key]??$base;
 return trim($base.' '.trim($strength));
}
function sc_supplier_message(array $lines):string{
 $parts=[];
 foreach($lines as $line){
  $boxes=(int)$line['packs'];
  if($boxes<1)continue;
  $parts[]='X'.$boxes.' '.sc_supplier_item_label((string)$line['name'],(string)$line['strength']).' ('.$line['sku'].')';
 }
 return "Hi Jessica,\nCan we place an order please for the following?\n\n".implode("\n",$parts)."\n\nThanks";
}
/**
 * Prioritise catalogue entries ANKH has actually purchased from suppliers,
 * then those physically stocked, then products mapped into ANKH's range.
 * Never infer a historical supplier purchase merely from a retail sale.
 */
function sc_previously_purchased_skus(PDO $db):array{
 $skus=[];
 // New purchases preserve the exact supplier SKU even if product mapping later changes.
 $direct=$db->query("SELECT DISTINCT l.sku FROM supplier_purchase_lines l JOIN supplier_orders o ON o.id=l.supplier_order_id WHERE o.status<>'Cancelled'")->fetchAll(PDO::FETCH_COLUMN);
 foreach($direct as $sku)$skus[(string)$sku]=true;
 // Historical supplier orders created before the price-list importer stored product IDs only.
 $legacy=$db->query("SELECT DISTINCT s.sku FROM supplier_order_items i JOIN supplier_orders o ON o.id=i.supplier_order_id JOIN supplier_catalogue s ON s.product_id=i.product_id WHERE o.status<>'Cancelled'")->fetchAll(PDO::FETCH_COLUMN);
 foreach($legacy as $sku)$skus[(string)$sku]=true;
 return $skus;
}
function sc_catalogue_group(array $row,array $purchased):string{
 if(isset($purchased[(string)$row['sku']]))return 'purchased';
 if(((int)($row['jay_physical']??0)+(int)($row['tony_physical']??0))>0)return 'stocked';
 if($row['product_id']!==null)return 'range';
 return 'other';
}
function sc_group_definitions():array{
 return [
  'purchased'=>['Previously purchased','Supplier products with recorded ANKH purchase history'],
  'stocked'=>['Currently stocked','Products held in James’s or Tony’s fridge'],
  'range'=>['Other ANKH products','Matched to your ANKH catalogue but not currently stocked or previously purchased'],
  'other'=>['Other MJT products','Supplier-only products you have not yet added to ANKH']
 ];
}
/**
 * Potential gross profit IF every selected vial is sold at its current ANKH retail
 * price. This is a purchasing projection, not realised sales profit, and excludes
 * tax, payment charges, packaging and other overheads. Never invent revenue for
 * an unmapped or zero-price product.
 */
function sc_basket_profit_summary(array $rows,array $boxes,?float $fx,int $shippingUsdCents,int $discountUsdCents=0):array{
 $boxCount=0;$vials=0;$supplierUsd=0;$revenue=0;$unpriced=[];
 foreach($rows as $row){
  $sku=(string)($row['sku']??'');
  $count=max(0,min(100,(int)($boxes[$sku]??0)));
  if($count===0)continue;
  $boxCount+=$count;
  $units=$count*max(1,(int)($row['pack']??10));
  $vials+=$units;
  $supplierUsd+=$count*(int)($row['usd']??0);
  $price=$row['retail']??null;
  if($row['product_id']===null||$price===null||(int)$price<=0){
   $unpriced[]=(string)($row['name']??$sku).' '.(string)($row['strength']??'').' ('.$sku.')';
  }else{
   $revenue+=$units*(int)$price;
  }
 }
 $shipping=$boxCount>0?max(0,$shippingUsdCents):0;
 $discount=max(0,$discountUsdCents);
 $discountValid=$discount<=$supplierUsd;
 $landed=$fx===null||$boxCount===0||!$discountValid?null:(int)round(($supplierUsd-$discount+$shipping)*$fx);
 return [
  'boxes'=>$boxCount,'vials'=>$vials,'supplier_usd_cents'=>$supplierUsd,
  'shipping_usd_cents'=>$shipping,'discount_usd_cents'=>$discount,'discount_valid'=>$discountValid,'landed_gbp_pence'=>$landed,
  'priced_revenue_gbp_pence'=>$revenue,'unpriced'=>$unpriced,
  'profit_gbp_pence'=>$boxCount>0&&$landed!==null&&!$unpriced?$revenue-$landed:null,
  'margin_percent'=>$boxCount>0&&$landed!==null&&!$unpriced&&$revenue>0?($revenue-$landed)/$revenue*100:null
 ];
}
function sc_render(PDO $db,array $products,array $reservedByProduct,array $incomingByProduct,array $recurringForecast=[]):void{
 $tab=(string)($_GET['tab']??'profit');if(!in_array($tab,['profit','smart','order','products','incoming'],true))$tab='profit';
 $catalogue=$db->query("SELECT s.*,p.name AS ankh_name,p.price AS retail,p.family_friends_price AS friends,p.stock_tracking,p.stock_jay_qty,p.stock_tony_qty,p.stock_unallocated_qty,p.low_stock_at FROM supplier_catalogue s LEFT JOIN products p ON p.id=s.product_id ORDER BY s.name COLLATE NOCASE,s.strength COLLATE NOCASE,s.sku")->fetchAll(PDO::FETCH_ASSOC);
 $allProducts=$db->query('SELECT id,name,active FROM products ORDER BY active DESC,name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
 $fx=sc_fx($db);
 $orderShippingUsdCents=sc_shipping_order_usd_cents($db);
 $lastImport=(string)($db->query("SELECT value FROM settings WHERE key='supplier_import_last'")->fetchColumn()?:'');
 $draftRow=$db->query('SELECT boxes_json,shipping_gbp,shipping_usd_cents,discount_usd_cents,expected_date FROM supplier_basket_drafts WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];
 $draft=json_decode((string)($draftRow['boxes_json']??'{}'),true);
 if(!is_array($draft))$draft=[];
 $draftShippingUsdCents=sc_shipping_draft_usd_cents($draftRow,$fx,$orderShippingUsdCents);
 $draftDiscountUsdCents=max(0,(int)($draftRow['discount_usd_cents']??0));
 $draftTotalUsd=0;$draftTotalVials=0;
 foreach($catalogue as $row){
  $boxes=max(0,min(100,(int)($draft[$row['sku']]??0)));
  $draftTotalUsd+=$boxes*(int)$row['box_usd_cents'];
  $draftTotalVials+=$boxes*(int)$row['pack_size'];
 }
 $mapped=count(array_filter($catalogue,fn($r)=>$r['product_id']!==null));
 $orderUnits=$db->query("SELECT i.product_id,COALESCE(SUM(i.quantity),0) qty FROM items i JOIN orders o ON o.id=i.order_id WHERE i.product_id IS NOT NULL AND o.status IN ('Paid','Packed','Dispatched','Delivered') AND datetime(o.created)>=datetime('now','-30 days') GROUP BY i.product_id")->fetchAll(PDO::FETCH_KEY_PAIR);
 $rows=[];
 foreach($catalogue as $item){
  $pack=(int)$item['pack_size'];$usd=(int)$item['box_usd_cents'];
  $mappedId=$item['product_id']===null?null:(int)$item['product_id'];
  // Shipping is a flat order expense: only allocate it where the current shared basket gives
  // a real order composition. Other products show price-before-shipping, not fictitious fees.
  $basketPacks=max(0,min(100,(int)($draft[$item['sku']]??0)));
  $shippingAllocated=$basketPacks>0&&$draftTotalVials>0;
  $lineUsd=$usd*$basketPacks;
  $allocatedShipping=$shippingAllocated&&$fx!==null
    ?($draftTotalUsd>0?(int)round($draftShippingUsdCents*$fx*$lineUsd/$draftTotalUsd):(int)round($draftShippingUsdCents*$fx*($pack*$basketPacks)/$draftTotalVials)):0;
  $allocatedDiscount=$shippingAllocated&&$fx!==null&&$draftTotalUsd>0&&$draftDiscountUsdCents<=$draftTotalUsd
    ?(int)round($draftDiscountUsdCents*$fx*$lineUsd/$draftTotalUsd):0;
  $boxGbp=$fx===null?null:(int)round($usd*$fx); // Whole-box cost before shipping, rounded once to GBP pence.
  $estimate=$fx===null?null:($shippingAllocated
    ?max(0,((int)round($lineUsd*$fx)+$allocatedShipping-$allocatedDiscount)/($pack*$basketPacks))
    :($usd*$fx)/$pack);
  $price=$item['retail']===null?null:(int)$item['retail'];
  $friends=$price===null?null:($item['friends']===null?max(0,$price-500):(int)$item['friends']);
  $profit=$estimate===null||$price===null?null:$price-$estimate;
  $margin=$profit===null||$price<=0?null:$profit/$price*100;
  $jay=(int)($item['stock_jay_qty']??0);$tony=(int)($item['stock_tony_qty']??0);
  // These source quantities are already NET of customer reservations.
  // Count both fridges plus unallocated holding as one combined available-vial figure.
  // Do not sum independently clamped locations: doing so overstates availability if one is negative.
  $combinedAvailable=$mappedId!==null&&(int)($item['stock_tracking']??0)===1
    ?max(0,$jay+$tony+(int)($item['stock_unallocated_qty']??0)):null;
  $jayReserved=$mappedId===null?0:(int)($reservedByProduct[$mappedId]['jay']??0);
  $tonyReserved=$mappedId===null?0:(int)($reservedByProduct[$mappedId]['tony']??0);
  $rows[]=['sku'=>(string)$item['sku'],'name'=>(string)$item['name'],'strength'=>(string)$item['strength'],'pack'=>$pack,'usd'=>$usd,'product_id'=>$mappedId,
   'ankh_name'=>(string)($item['ankh_name']??''),'retail'=>$price,'friends'=>$friends,'box_gbp'=>$boxGbp,'estimate'=>$estimate,'shipping_allocated'=>$shippingAllocated,'discount_allocated'=>$allocatedDiscount>0,'profit'=>$profit,'margin'=>$margin,
   'jay_physical'=>$jay+$jayReserved,'tony_physical'=>$tony+$tonyReserved,'jay_available'=>max(0,$jay),'tony_available'=>max(0,$tony),'combined_available'=>$combinedAvailable,
   'reserved'=>$jayReserved+$tonyReserved,'incoming'=>$mappedId===null?0:(int)($incomingByProduct[$mappedId]??0),
   'sold30'=>$mappedId===null?0:(int)($orderUnits[$mappedId]??0)];
 }
 $lookbackDays=in_array((int)($_GET['lookback_days']??30),[30,60,90],true)?(int)($_GET['lookback_days']??30):30;
 $smartRows=$tab==='smart'?sc_smart_suggestions($catalogue,$reservedByProduct,$incomingByProduct,sc_recent_delivered($db,$lookbackDays),$recurringForecast,$lookbackDays):[];
 $smartSuggested=array_values(array_filter($smartRows,fn($row)=>$row['boxes']>0));
 $matchedRows=array_values(array_filter($rows,fn($r)=>$r['product_id']!==null));
 $purchasedSkus=sc_previously_purchased_skus($db);
 $groupDefinitions=sc_group_definitions();
 $groupedRows=array_fill_keys(array_keys($groupDefinitions),[]);
 foreach($rows as $row)$groupedRows[sc_catalogue_group($row,$purchasedSkus)][]=$row;
 $profitRows=$rows;
 usort($profitRows,fn($a,$b)=>(($b['margin']??-999999)<=>($a['margin']??-999999)) ?: strcasecmp($a['name'].' '.$a['strength'],$b['name'].' '.$b['strength']));
 $initialBasketProfit=sc_basket_profit_summary($rows,$draft,$fx,$draftShippingUsdCents,$draftDiscountUsdCents);
 $groupedProfitRows=array_fill_keys(array_keys($groupDefinitions),[]);
 foreach($profitRows as $row)$groupedProfitRows[sc_catalogue_group($row,$purchasedSkus)][]=$row;
 $groupedSmart=['attention'=>[],'covered'=>[]];
 foreach($smartRows as $row)$groupedSmart[$row['boxes']>0?'attention':'covered'][]=$row;
 ?>
<div class="heading sc-heading"><div><p class="eyebrow">ANKH / SUPPLIER CENTRE</p><h1>Supplier &amp; Profit</h1><p class="muted page-description">Compare costs, build combined MJT orders and split stock after delivery.</p></div></div>
<div class="sc-tabs" role="navigation" aria-label="Supplier Centre sections">
 <a href="?view=supplier&amp;tab=profit" <?=$tab==='profit'?'aria-current="page"':''?>>Profit</a>
 <a href="?view=supplier&amp;tab=smart" <?=$tab==='smart'?'aria-current="page"':''?>>Smart buying</a>
 <a href="?view=supplier&amp;tab=order" <?=$tab==='order'?'aria-current="page"':''?>>Build order</a>
 <a href="?view=supplier&amp;tab=products" <?=$tab==='products'?'aria-current="page"':''?>>Prices &amp; matching</a>
 <a href="?view=supplier&amp;tab=incoming" <?=$tab==='incoming'?'aria-current="page"':''?>>Incoming</a>
</div>
<div class="sc-overview">
 <div><small>Supplier products</small><strong><?=count($rows)?></strong></div>
 <div><small>Matched to ANKH</small><strong><?=$mapped?></strong></div>
 <div><small>Awaiting matching</small><strong><?=count($rows)-$mapped?></strong></div>
</div>
<?php if($tab==='profit'):?>
<?php if(!$catalogue):?><section class="panel sc-empty"><h2>Start with the MJT supplier price import</h2><p>Upload the August 2026 supplier CSV through Prices &amp; matching. Wholesale costs are stored privately in ANKH Admin, never in the public code repository.</p><a class="quick-action" href="?view=supplier&amp;tab=products">Import supplier prices →</a></section><?php else:?>
<section class="panel sc-panel"><div class="sc-panel-heading"><div><p class="eyebrow">MARGIN ANALYSIS</p><h2>Profit explorer</h2><p>Estimates use your supplier box prices and saved exchange rate. Products in your shared basket include their allocated share of the entire $<?=number_format($draftShippingUsdCents/100,2)?> order shipping charge and any saved order discount. Products not in the basket show costs and gross profits <strong>before shipping</strong> until you add them to an order.</p></div></div>
<?php if($fx===null):?><p class="sc-notice">Set your GBP per USD exchange rate in Prices &amp; matching to calculate estimated GBP margins. Supplier USD costs are available now.</p><?php endif;?>
<div class="sc-filters"><input type="search" id="sc-profit-search" placeholder="Search product or supplier code…" aria-label="Search supplier products"><select id="sc-profit-sort" aria-label="Sort supplier products"><option value="margin">Highest margin %</option><option value="profit">Highest profit / vial</option><option value="sold">Most ordered (30d)</option><option value="stock">Most available</option><option value="name">Product name</option></select></div>
<div class="sc-group-container" id="sc-profit-list">
<?php foreach($groupDefinitions as $groupKey=>$groupDescription):$groupItems=$groupedProfitRows[$groupKey];if(!$groupItems)continue;?>
<details class="sc-catalogue-group" data-sc-group="<?=e($groupKey)?>" data-default-open="<?=$groupKey==='other'?'0':'1'?>" <?=$groupKey==='other'?'':'open'?>>
<summary class="sc-catalogue-heading"><span><strong><?=e($groupDescription[0])?></strong><small><?=e($groupDescription[1])?></small></span><b><?=count($groupItems)?></b></summary>
<div class="sc-product-list">
<?php foreach($groupItems as $item):$availability=$item['jay_available']+$item['tony_available'];?>
<details class="sc-profit-card" data-search="<?=e(strtolower($item['sku'].' '.$item['name'].' '.$item['strength'].' '.$item['ankh_name']))?>" data-margin="<?=e((string)($item['margin']??-999999))?>" data-profit="<?=e((string)($item['profit']??-999999))?>" data-sold="<?=$item['sold30']?>" data-stock="<?=$availability?>" data-name="<?=e(strtolower($item['name'].' '.$item['strength']))?>">
 <summary class="sc-profit-head"><div><small><?=e($item['sku'])?> · <?=e($item['pack'])?> vials/box · <?=$item['box_gbp']===null?'FX required':money($item['box_gbp'])?> per box</small><h3><?=e($item['name'].' '.$item['strength'])?></h3></div><span class="sc-margin-label <?=$item['margin']!==null&&$item['margin']<0?'loss':''?>"><?=$item['margin']===null?'Not mapped':number_format($item['margin'],1).'% margin'?></span></summary>
<div class="sc-profit-expanded">
 <div class="sc-profit-metrics">
  <div><span>Supplier box (<?=$item['pack']?> vials)</span><strong><?=$item['box_gbp']===null?'Set exchange rate':money($item['box_gbp'])?></strong><small class="sc-profit-subvalue"><?=sc_money_usd($item['usd'])?> USD · before shipping</small></div>
  <div><span><?=$item['shipping_allocated']?'Landed cost / vial':'Cost / vial'?></span><strong><?=$item['estimate']===null?'—':'£'.number_format($item['estimate']/100,2)?></strong><small class="sc-profit-subvalue"><?=$item['shipping_allocated']?($item['discount_allocated']?'Shipping and discount allocated':'Includes allocated order shipping'):'Before shipping'?></small></div>
  <div><span>ANKH retail / vial</span><strong><?=$item['retail']===null?'—':money($item['retail'])?></strong></div>
  <div><span>Gross profit / vial</span><strong><?=$item['profit']===null?'—':'£'.number_format($item['profit']/100,2)?></strong><small class="sc-profit-subvalue"><?=$item['shipping_allocated']?($item['discount_allocated']?'After shipping and supplier discount':'After estimated supplier shipping'):'Before shipping and overheads'?></small></div>
 </div>
 <div class="sc-profit-bottom"><span><?=!empty($item['shipping_allocated'])?(!empty($item['discount_allocated'])?'Includes proportional order shipping and discount':'Includes proportional order shipping'):'Before shipping · add to basket for landed cost'?></span><span>Supplier / vial: <?=sc_money_usd((int)round($item['usd']/$item['pack']))?></span><span>F&amp;F: <?=$item['friends']===null?'—':money($item['friends'])?></span><span>Physical: James <?=max(0,$item['jay_physical'])?> · Tony <?=max(0,$item['tony_physical'])?></span><span>Available: James <?=$item['jay_available']?> · Tony <?=$item['tony_available']?></span><span><?=$item['reserved']?> reserved · <?=$item['incoming']?> incoming</span><span><?=$item['sold30']?> units on paid orders (30d)</span></div>
 <?php if($item['product_id']===null):?><a class="sc-map-link" href="?view=supplier&amp;tab=products">Match to ANKH product →</a><?php endif;?>
</div></details>
<?php endforeach;?></div></details>
<?php endforeach;?></div></section>
<?php endif;?>
<?php elseif($tab==='smart'):?>
<section class="panel sc-panel sc-buying-panel">
 <div class="sc-panel-heading"><div><p class="eyebrow">SMART BUYING ASSISTANT</p><h2>What should we reorder?</h2><p>Checks each fridge, existing reservations, incoming supplier purchases, recent delivered sales and possible upcoming recurring orders.</p></div></div>
 <?php if(!$catalogue):?>
 <div class="sc-notice">Import the MJT price list in Prices &amp; matching before using Smart Buying. Your wholesale costs stay private.</div>
 <a class="quick-action" href="?view=supplier&amp;tab=products">Import &amp; match products →</a>
 <?php else:?>
 <form method="get" class="sc-smart-period"><input type="hidden" name="view" value="supplier"><input type="hidden" name="tab" value="smart"><label>Sales lookback<select name="lookback_days" onchange="this.form.submit()"><option value="30" <?=$lookbackDays===30?'selected':''?>>Last 30 days</option><option value="60" <?=$lookbackDays===60?'selected':''?>>Last 60 days</option><option value="90" <?=$lookbackDays===90?'selected':''?>>Last 90 days</option></select></label><small>Average delivered non-recurring demand over <?=$lookbackDays?> days is projected across the usual 7-day supplier lead time. Possible recurring orders over the next 30 days are additional estimates, not confirmed reservations.</small></form>
 <div class="sc-smart-stats"><div><small>Products to consider</small><strong><?=count($smartSuggested)?></strong></div><div><small>Suggested boxes</small><strong><?=array_sum(array_column($smartSuggested,'boxes'))?></strong></div><div><small>Product total (USD)</small><strong><?=sc_money_usd(array_sum(array_map(fn($r)=>$r['boxes']*$r['box_usd_cents'],$smartSuggested)))?></strong></div></div>
 <?php if($fx===null):?><p class="sc-notice">Set the GBP-per-USD rate in Prices &amp; matching to use the purchasing budget calculator. You can still review suggested quantities in USD.</p><?php else:?><p class="sc-notice">Budgets include the <strong>one-off <?=sc_money_usd($orderShippingUsdCents)?> USD order shipping charge</strong> (approximately £<?=number_format($orderShippingUsdCents*$fx/100,2)?>), not a fee for every box.</p><?php endif;?>
 <div class="sc-smart-explainer"><span class="sc-smart-info-icon" aria-hidden="true">ⓘ</span><p><strong>How it decides:</strong> Stock available <em>already excludes</em> reserved customer orders. Suggestions use available + incoming, then allow for your low-stock threshold, estimated 7-day sales and possible recurring orders. Spare stock in James’s or Tony’s fridge is counted before recommending more purchases, but transfers must be done manually.</p></div>
 <div class="sc-smart-budget">
  <div class="sc-smart-budget-heading"><span><strong>Set a buying budget</strong><small>Optional, in GBP · selects affordable boxes in priority order</small></span><span class="sc-smart-budget-symbol" aria-hidden="true">£</span></div>
  <div class="sc-budget-buttons" role="group" aria-label="Supplier purchasing budget">
   <button type="button" data-sc-budget="all">All suggested</button><button type="button" data-sc-budget="250" <?=$fx===null?'disabled':''?>>£250</button><button type="button" data-sc-budget="500" <?=$fx===null?'disabled':''?>>£500</button><button type="button" data-sc-budget="1000" <?=$fx===null?'disabled':''?>>£1,000</button>
  </div>
  <div class="sc-smart-custom"><label>Or enter a budget (£)<input type="number" min="1" max="100000" step="1" id="sc-smart-budget-input" placeholder="e.g. 750" <?=$fx===null?'disabled':''?>></label><button type="button" id="sc-smart-apply-budget" class="quiet" <?=$fx===null?'disabled':''?>>Apply budget</button></div>
  <p id="sc-smart-budget-explain" class="sc-smart-budget-explain" aria-live="polite">Select products below, or apply a suggested budget. Nothing is purchased automatically.</p>
 </div>
 <form method="post" id="sc-smart-form"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_smart_apply"><input type="hidden" name="return" value="supplier">
 <div class="sc-filters"><input type="search" id="sc-smart-search" placeholder="Find one of your ANKH products…" aria-label="Search Smart Buying products"></div>
 <div class="sc-smart-groups" id="sc-smart-list">
 <?php foreach(['attention'=>['Reorder recommendations','Products where buying more may be useful'],'covered'=>['Covered / no order needed','Adequate stock or stock that has not yet been counted']] as $smartGroupKey=>$smartGroupLabel):$smartGroupRows=$groupedSmart[$smartGroupKey];if(!$smartGroupRows)continue;?>
 <details class="sc-catalogue-group" data-sc-group="<?=e($smartGroupKey)?>" data-default-open="<?=$smartGroupKey==='attention'?'1':'0'?>" <?=$smartGroupKey==='attention'?'open':''?>>
 <summary class="sc-catalogue-heading"><span><strong><?=e($smartGroupLabel[0])?></strong><small><?=e($smartGroupLabel[1])?></small></span><b><?=count($smartGroupRows)?></b></summary>
 <div class="sc-smart-list">
 <?php foreach($smartGroupRows as $row):
  $hasTransfer=$row['transfer_jay_to_tony']+$row['transfer_tony_to_jay']+$row['transfer_holding']>0;
  $label=$row['status']==='shortage'?'Urgent shortage':($row['status']==='reorder'?'Consider reordering':($row['status']==='untracked'?'Stock not tracked':'Covered'));
 ?>
 <article class="sc-smart-card status-<?=e($row['status'])?>" data-search="<?=e(strtolower($row['sku'].' '.$row['name']))?>" data-smart-sku="<?=e($row['sku'])?>" data-smart-boxes="<?=$row['boxes']?>" data-smart-price="<?=$row['box_usd_cents']?>" data-smart-pack="<?=$row['pack']?>">
  <div class="sc-smart-card-head">
   <span class="sc-smart-product-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9 3h6m-5 0v4l-3 3v9a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-9l-3-3V3M8 12h8"/></svg></span>
   <div class="sc-smart-product-name"><small><?=e($row['sku'])?> · <?=$row['pack']?> vials/box</small><h3><?=e($row['name'])?></h3></div>
   <span class="sc-smart-status"><?=e($label)?></span>
  </div>
  <div class="sc-smart-metrics"><span><small>Available</small><strong><?=$row['available']?></strong></span><span><small>Reserved</small><strong><?=$row['reserved']?></strong></span><span><small>Incoming</small><strong><?=$row['incoming']?></strong></span><span><small>Target</small><strong><?=$row['target']?></strong></span></div>
  <?php if($row['status']==='untracked'):?><p class="sc-smart-context">Confirm a physical stock count before the assistant recommends buying this product.</p>
  <?php else:?><p class="sc-smart-context">7-day sales estimate: <b><?=$row['lead_demand']?></b> · Potential recurring (30d): <b><?=$row['recurring30']?></b> · Low-stock threshold: <b><?=$row['low_at']?></b></p>
  <?php if($row['jay_short']>0||$row['tony_short']>0):?>
  <div class="sc-smart-transfer"><span aria-hidden="true">⇄</span><div><?php if($row['jay_short']>0):?>James’s fridge is <?=$row['jay_short']?> short. Tony has <?=$row['tony_available']?> available; up to <?=$row['transfer_tony_to_jay']?> could be transferred.<?php endif;?>
  <?php if($row['tony_short']>0):?>Tony’s fridge is <?=$row['tony_short']?> short. James has <?=$row['jay_available']?> available; up to <?=$row['transfer_jay_to_tony']?> could be transferred.<?php endif;?>
  <?php if($row['transfer_holding']>0):?> Holding stock could cover up to <?=$row['transfer_holding']?> after manual allocation.<?php endif;?></div></div>
  <?php endif;?>
  <?php endif;?>
  <div class="sc-smart-choice">
   <label class="sc-smart-tick"><input type="checkbox" data-sc-smart-check <?=$row['boxes']<1?'disabled':''?>><span><b><?=$row['boxes']?> box<?=$row['boxes']===1?'':'es'?> suggested</b><small><?=sc_money_usd($row['box_usd_cents'])?> per box · <?=$row['units']?> vials suggested</small></span></label>
   <div class="sc-stepper"><button type="button" data-sc-step="-1" aria-label="Remove a box for <?=e($row['name'])?>" <?=$row['tracked']?'':'disabled'?>>−</button><input type="number" name="boxes[<?=e($row['sku'])?>]" value="0" min="0" max="100" step="1" inputmode="numeric" data-sc-smart-qty aria-label="Boxes of <?=e($row['name'])?>" <?=$row['tracked']?'':'disabled'?>> <button type="button" data-sc-step="1" aria-label="Add a box for <?=e($row['name'])?>" <?=$row['tracked']?'':'disabled'?>>+</button></div>
  </div>
  <?php if($row['boxes']>0):?><div class="sc-smart-recommendation"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M5 14l7 7 7-7"/></svg><span><?=$row['gap']?> units needed to reach target, after <?=$row['incoming']?> incoming → <strong><?=$row['boxes']?> box<?=$row['boxes']===1?'':'es'?> recommended</strong></span></div><?php endif;?>
 </article>
 <?php endforeach;?>
 </div></details>
 <?php endforeach;?>
 </div>
 <div class="sc-smart-footer">
  <div class="sc-smart-footer-stats"><div><small>Selected boxes</small><strong id="sc-smart-selected-boxes">0</strong></div><div><small>Supplier cost</small><strong id="sc-smart-selected-usd">$0.00</strong></div><div><small>Estimated GBP incl. shipping</small><strong id="sc-smart-selected-gbp"><?=$fx===null?'Set FX rate':'£0.00'?></strong></div></div>
  <button type="submit" class="sc-smart-add-button" id="sc-smart-add-button" disabled>Add selected boxes to shared basket →</button>
  <p>Adding products <strong>never places an order</strong>. Your existing basket quantities are preserved; adding a suggestion again will not multiply them.</p>
 </div>
 </form>
 <script>
 (() => {
  const cards=[...document.querySelectorAll('[data-smart-sku]')];
  const fx=<?=json_encode($fx)?>,shippingUsd=<?=json_encode($orderShippingUsdCents)?>;
  const selector=document.querySelector('#sc-smart-budget-input'),explain=document.querySelector('#sc-smart-budget-explain'),submit=document.querySelector('#sc-smart-add-button');
  const dollar=n=>'$'+(n/100).toFixed(2),pound=n=>'£'+(n/100).toFixed(2);
  function quantity(card,n){
   const input=card.querySelector('[data-sc-smart-qty]');
   input.value=Math.max(0,Math.min(100,Math.trunc(n||0)));
   card.querySelector('[data-sc-smart-check]').checked=Number(input.value)>0;
  }
  function update(){
   const selected=cards.map(card=>({boxes:Number(card.querySelector('[data-sc-smart-qty]').value)||0,price:Number(card.dataset.smartPrice)})).filter(r=>r.boxes>0);
   const boxes=selected.reduce((sum,r)=>sum+r.boxes,0),usd=selected.reduce((sum,r)=>sum+r.boxes*r.price,0);
   document.querySelector('#sc-smart-selected-boxes').textContent=String(boxes);
   document.querySelector('#sc-smart-selected-usd').textContent=dollar(usd);
   document.querySelector('#sc-smart-selected-gbp').textContent=fx?pound(Math.round((usd+(boxes>0?shippingUsd:0))*fx)):'Set FX rate';
   submit.disabled=boxes===0;
  }
  function applyBudget(limit){
   cards.forEach(card=>quantity(card,0));
   let totalUsd=0,boxes=0;
   for(const card of cards){
    const suggested=Number(card.dataset.smartBoxes)||0,price=Number(card.dataset.smartPrice)||0;
    if(suggested<1)continue;
    let count=0;
    // Budget includes the single shipment fee once if at least one box is selected.
    while(count<suggested){
     const nextUsd=totalUsd+price;
     const nextCost=fx?Math.round((nextUsd+shippingUsd)*fx):0;
     if(limit!==null&&(!fx||nextCost>limit))break;
     totalUsd=nextUsd;count++;boxes++;
    }
    if(count>0)quantity(card,count);
   }
   const cost=fx&&boxes?Math.round((totalUsd+shippingUsd)*fx):0;
   explain.textContent=limit===null?boxes+' suggested boxes selected. Review before saving.':boxes+' boxes selected for about '+pound(cost)+' against a '+pound(limit)+' estimated budget. Review before saving.';
   update();
  }
  cards.forEach(card=>{
   const input=card.querySelector('[data-sc-smart-qty]'),check=card.querySelector('[data-sc-smart-check]');
   check.addEventListener('change',()=>{quantity(card,check.checked?(Number(card.dataset.smartBoxes)||1):0);update()});
   input.addEventListener('input',()=>{quantity(card,Number(input.value));update()});
   card.querySelectorAll('[data-sc-step]').forEach(button=>button.addEventListener('click',()=>{quantity(card,Number(input.value)+Number(button.dataset.scStep));update()}));
  });
  document.querySelectorAll('[data-sc-budget]').forEach(button=>button.addEventListener('click',()=>applyBudget(button.dataset.scBudget==='all'?null:Math.round(Number(button.dataset.scBudget)*100))));
  document.querySelector('#sc-smart-apply-budget').addEventListener('click',()=>{const value=Number(selector.value);if(value>0&&fx)applyBudget(Math.round(value*100))});
  update();
 })();
 </script>
 <?php endif;?>
</section>
<?php elseif($tab==='order'):?>
<section class="panel sc-panel"><div class="sc-panel-heading"><div><p class="eyebrow">ONE COMBINED BASKET</p><h2>Build an MJT order</h2><p>Choose whole boxes. James and Tony split received vials later using existing Incoming Stock.</p></div></div>
<?php if(!$rows):?><p class="sc-notice">Import the August supplier CSV first in Prices &amp; matching.</p><?php else:?>
<p class="sc-notice">Only products matched to your ANKH catalogue can be saved as Incoming Stock. You can still prepare and copy a supplier message for any SKU. A few specialty products have different supplier box sizes; these are shown explicitly.</p>
<div class="sc-filters sc-order-filters"><input type="search" id="sc-order-search" placeholder="Search all supplier products…" aria-label="Search all supplier products"></div><p class="sc-find-help">Previously purchased and currently stocked products appear first. Unstocked products stay closed until you open them. Search finds items in every section.</p><p class="sc-stock-legend" aria-label="Combined available vial stock colour key"><span class="sc-stock-dot is-good" aria-hidden="true"></span>6+ vials <span class="sc-stock-dot is-low" aria-hidden="true"></span>0–4 vials <span class="sc-stock-dot is-edge" aria-hidden="true"></span>5 vials <span class="sc-stock-dot is-unknown" aria-hidden="true"></span>Not tracked</p>
<form method="post" id="sc-order-form"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_place"><input type="hidden" name="return" value="supplier">
<div class="sc-order-quick" id="sc-order-quick" role="status" aria-live="polite"><div><small>YOUR SUPPLIER BASKET</small><strong><span id="sc-quick-boxes">0</span> boxes · <span id="sc-quick-usd">$0.00</span></strong><small id="sc-quick-gbp">Add boxes to see estimated GBP total</small><small id="sc-quick-discount" class="sc-quick-discount" hidden></small><small id="sc-quick-profit" class="sc-quick-profit" hidden></small></div><button type="button" id="sc-quick-review" aria-controls="sc-order-checkout">Review basket <span aria-hidden="true">→</span></button></div>
<div class="sc-order-groups" id="sc-order-items">
<?php foreach($groupDefinitions as $groupKey=>$groupDescription):$groupItems=$groupedRows[$groupKey];if(!$groupItems)continue;
$groupHasDraft=false;foreach($groupItems as $groupEntry){if((int)($draft[$groupEntry['sku']]??0)>0){$groupHasDraft=true;break;}}
$groupOpen=in_array($groupKey,['purchased','stocked'],true)||$groupHasDraft;?>
<details class="sc-catalogue-group" data-sc-group="<?=e($groupKey)?>" data-default-open="<?=$groupOpen?'1':'0'?>" <?=$groupOpen?'open':''?>>
<summary class="sc-catalogue-heading"><span><strong><?=e($groupDescription[0])?></strong><small><?=e($groupDescription[1])?></small></span><b><?=count($groupItems)?></b></summary>
<div class="sc-order-items">
<?php foreach($groupItems as $item):?><div class="sc-order-item" data-search="<?=e(strtolower($item['sku'].' '.$item['name'].' '.$item['strength']))?>">
 <?php $available=$item['combined_available'];$stockLevel=$available===null?'unknown':($available>5?'good':($available<5?'low':'edge')); ?>
 <span class="sc-order-stock-badge is-<?=$stockLevel?>" role="img" aria-label="<?=$available===null?'Combined stock not tracked':'Combined available stock: '.$available.' vial'.($available===1?'':'s')?> " title="<?=$available===null?'Stock not tracked for this product':$available.' available vials across both fridges and holding, after reservations'?>"><?=$available===null?'—':$available?></span>
 <div class="sc-order-name"><small><?=e($item['sku'])?> · <?=$item['pack']?> vials/box</small><strong><?=e($item['name'].' '.$item['strength'])?></strong><span class="sc-order-unit-price"><?=sc_money_usd($item['usd'])?> / box<?=$item['box_gbp']===null?'':' · '.money($item['box_gbp'])?></span><?php if($item['product_id']===null):?><em>Supplier-only · match to receive stock</em><?php endif;?></div>
 <div class="sc-order-pick"><div class="sc-stepper"><button type="button" data-step="-1" aria-label="Remove one box of <?=e($item['sku'])?>">−</button><input type="number" min="0" max="100" step="1" inputmode="numeric" name="boxes[<?=e($item['sku'])?>]" value="<?=max(0,min(100,(int)($draft[$item['sku']]??0)))?>" aria-label="Boxes of <?=e($item['sku'])?>" data-box-sku="<?=e($item['sku'])?>"><button type="button" data-step="1" aria-label="Add one box of <?=e($item['sku'])?>">+</button></div><small class="sc-order-line-total" data-sc-line-total aria-live="polite"></small></div>
</div><?php endforeach;?></div></details>
<?php endforeach;?></div>
<div class="sc-order-checkout" id="sc-order-checkout"><h3 class="sc-checkout-title">Review your basket</h3><div class="sc-basket-selected" id="sc-basket-selected" aria-live="polite"><p>No boxes selected yet. Add products above to prepare an order.</p></div><div class="sc-order-summary"><div><small>Boxes selected</small><strong id="sc-total-boxes">0</strong></div><div><small>Vials incoming</small><strong id="sc-total-vials">0</strong></div><div><small>Supplier total</small><strong id="sc-total-usd">$0.00</strong></div></div>
<label>Expected delivery date (optional)<input type="date" name="expected_date" value="<?=e((string)($draftRow['expected_date']??''))?>"></label>
<div class="sc-order-adjustments">
<label>Supplier discount (USD $)<input type="number" min="0" max="100000" step=".01" inputmode="decimal" name="discount_usd" value="<?=e(number_format($draftDiscountUsdCents/100,2,'.',''))?>" id="sc-basket-discount"><small>Enter an agreed discount, e.g. $60.00 for an order over $1,000. Applied once to the product subtotal.</small></label>
<label>Supplier shipping per entire order (USD $)<input type="number" min="0" max="100000" step=".01" inputmode="decimal" name="shipping_usd" value="<?=e(number_format($draftShippingUsdCents/100,2,'.',''))?>" id="sc-basket-shipping"><small>Flat $70.00 USD by default. Change if this order has a different quoted fee.</small></label>
</div>
<div class="sc-discount-summary" id="sc-discount-summary" aria-live="polite"><span>Supplier discount</span><strong id="sc-discount-usd">−$0.00</strong><span>Product total after discount</span><strong id="sc-discounted-products">$0.00</strong></div>
<div class="sc-order-cost"><span>Estimated landed cost in GBP including order shipping</span><strong id="sc-total-gbp"><?=$fx===null?'Set exchange rate':'£0.00'?></strong><small id="sc-shipping-breakdown">Discount is deducted from the product subtotal, then shipping is added once. Rates and actual invoices may differ.</small></div>
<section class="sc-basket-profit" aria-label="Projected supplier basket profit">
 <div class="sc-basket-profit-heading"><div><small>IF ALL SELECTED VIALS SELL AT FULL ANKH RETAIL</small><h4>Projected gross profit after shipping</h4></div><span class="sc-profit-spark" aria-hidden="true">↗</span></div>
 <strong class="sc-basket-profit-value" id="sc-basket-profit-value"><?=$initialBasketProfit['profit_gbp_pence']===null?'—':money($initialBasketProfit['profit_gbp_pence'])?></strong>
 <div class="sc-basket-profit-breakdown">
  <div><span>Potential retail sales</span><b id="sc-basket-revenue"><?=$initialBasketProfit['boxes']>0&&!$initialBasketProfit['unpriced']?money($initialBasketProfit['priced_revenue_gbp_pence']):'—'?></b></div>
  <div><span>Cost incl. shipping &amp; discount</span><b id="sc-basket-landed"><?=$initialBasketProfit['landed_gbp_pence']===null?'—':money($initialBasketProfit['landed_gbp_pence'])?></b></div>
  <div><span>Projected margin</span><b id="sc-basket-margin"><?=$initialBasketProfit['margin_percent']===null?'—':number_format($initialBasketProfit['margin_percent'],1).'%'?></b></div>
 </div>
 <p class="sc-basket-discount-info" id="sc-basket-discount-info" hidden></p>
 <p class="sc-basket-profit-note" id="sc-basket-profit-note" aria-live="polite">Figures update as you add boxes. An estimate of future retail sales, not profit already earned. Excludes VAT/taxes, packaging, payment fees and other overheads.</p>
</section>
<label>Message ready for WhatsApp or supplier<input type="text" readonly value="Supplier order preview" class="sc-visually-hidden" aria-hidden="true"></label><textarea id="sc-supplier-message" readonly rows="10" aria-label="Generated supplier message"></textarea><small class="muted">The message lists full boxes and supplier codes. Costs and shipping are calculated above for your ANKH records, not included in Jessica’s message.</small>
<div class="sc-order-actions"><button type="button" id="sc-copy-message" class="sc-copy-button">Copy supplier message</button><button type="submit" name="supplier_intent" value="draft" class="sc-draft-button">Save shared draft</button><button type="submit" id="sc-place-order">Mark as ordered &amp; track incoming</button></div><p id="sc-order-warning" class="sc-order-warning" role="status"></p>
</div></form>
<script>
(function(){
 const rows=<?=json_encode(array_map(fn($r)=>['sku'=>$r['sku'],'name'=>$r['name'],'strength'=>$r['strength'],'message_label'=>sc_supplier_item_label($r['name'],$r['strength']),'pack'=>$r['pack'],'usd'=>$r['usd'],'retail'=>$r['retail'],'mapped'=>$r['product_id']!==null],$rows),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const fx=<?=json_encode($fx)?>;
 const inputs=[...document.querySelectorAll('[data-box-sku]')],lookup=Object.fromEntries(rows.map(r=>[r.sku,r])),form=document.querySelector('#sc-order-form'),copy=document.querySelector('#sc-copy-message'),save=document.querySelector('#sc-place-order'),preview=document.querySelector('#sc-supplier-message'),warning=document.querySelector('#sc-order-warning');
 function update(){
  const selected=inputs.map(i=>({...lookup[i.dataset.boxSku],boxes:Math.max(0,Math.min(100,Math.trunc(Number(i.value)||0)))})).filter(r=>r.boxes>0);
  const boxCount=selected.reduce((n,r)=>n+r.boxes,0),vials=selected.reduce((n,r)=>n+r.boxes*r.pack,0),usd=selected.reduce((n,r)=>n+r.boxes*r.usd,0);
  const dollar=(n)=>'$'+(n/100).toFixed(2),gbp=(n)=>'£'+(n/100).toFixed(2);
  document.querySelector('#sc-total-boxes').textContent=String(boxCount);
  document.querySelector('#sc-total-vials').textContent=String(vials);
  document.querySelector('#sc-total-usd').textContent=dollar(usd);
  document.querySelector('#sc-quick-boxes').textContent=String(boxCount);
  document.querySelector('#sc-quick-usd').textContent=dollar(usd);
  const shippingUsd=Math.round(Math.max(0,Number(document.querySelector('#sc-basket-shipping').value)||0)*100);
  const discountUsd=Math.round(Math.max(0,Number(document.querySelector('#sc-basket-discount').value)||0)*100);
  const discountValid=discountUsd<=usd;
  const discountedProducts=usd-Math.min(discountUsd,usd);
  const landedCost=fx&&boxCount>0&&discountValid?Math.round((usd-discountUsd+shippingUsd)*fx):null;
  document.querySelector('#sc-quick-gbp').textContent=!discountValid?'Discount exceeds product total':landedCost!==null?'Approx. '+gbp(landedCost)+' incl. shipping & discount':(boxCount>0?'Set exchange rate for GBP cost':'Add boxes to see estimated GBP total');
  const quickDiscount=document.querySelector('#sc-quick-discount');
  quickDiscount.hidden=discountUsd===0;
  quickDiscount.textContent=discountUsd>0?'Supplier discount: −'+dollar(discountUsd)+(discountValid?' · After discount: '+dollar(discountedProducts):' · exceeds products total'):'';
  document.querySelector('#sc-discount-usd').textContent='−'+dollar(discountUsd);
  document.querySelector('#sc-discounted-products').textContent=discountValid?dollar(discountedProducts):'Discount exceeds products total';
  document.querySelector('#sc-discount-summary').classList.toggle('is-invalid',!discountValid);
  document.querySelector('#sc-order-quick').classList.toggle('has-items',boxCount>0);
  const selectedList=document.querySelector('#sc-basket-selected');
  selectedList.replaceChildren();
  if(selected.length===0){const empty=document.createElement('p');empty.textContent='No boxes selected yet. Add products above to prepare an order.';selectedList.append(empty);}
  selected.forEach(r=>{
   const entry=document.createElement('div'),name=document.createElement('span'),cost=document.createElement('strong');
   name.textContent='X'+r.boxes+' '+r.name+' '+r.strength+' ('+r.sku+')';
   cost.textContent=dollar(r.boxes*r.usd);entry.append(name,cost);selectedList.append(entry);
  });
  inputs.forEach(i=>{
   const qty=Math.max(0,Math.min(100,Math.trunc(Number(i.value)||0)));
   const row=i.closest('.sc-order-item'),subtotal=row.querySelector('[data-sc-line-total]');
   row.classList.toggle('is-selected',qty>0);
   subtotal.textContent=qty>0?'Line: '+dollar(qty*lookup[i.dataset.boxSku].usd):'';
  });
  document.querySelector('#sc-total-gbp').textContent=!discountValid?'Check discount':landedCost!==null?gbp(landedCost):'Set exchange rate';
  document.querySelector('#sc-shipping-breakdown').textContent='Products '+dollar(usd)+' − discount '+dollar(discountUsd)+' + shipping '+dollar(boxCount>0?shippingUsd:0)+' = '+(discountValid?dollar(discountedProducts+(boxCount>0?shippingUsd:0)):'Check discount')+' USD'+(landedCost!==null?' ≈ '+gbp(landedCost):'')+'. Supplier discount and shipping are allocated across products by supplier value.';
  // Retail value represents all vials potentially sold, not immediate income from the purchase.
  const unpriced=selected.filter(r=>!r.mapped||r.retail===null||Number(r.retail)<=0);
  const potentialRevenue=selected.reduce((sum,r)=>sum+((r.mapped&&r.retail!==null&&Number(r.retail)>0)?r.boxes*r.pack*Number(r.retail):0),0);
  const complete=boxCount>0&&landedCost!==null&&unpriced.length===0;
  const profit=complete?potentialRevenue-landedCost:null;
  const profitPct=complete&&potentialRevenue>0?100*profit/potentialRevenue:null;
  document.querySelector('#sc-basket-revenue').textContent=boxCount>0&&!unpriced.length?gbp(potentialRevenue):'—';
  document.querySelector('#sc-basket-landed').textContent=landedCost===null?'—':gbp(landedCost);
  document.querySelector('#sc-basket-profit-value').textContent=profit===null?'—':gbp(profit);
  document.querySelector('#sc-basket-margin').textContent=profitPct===null?'—':profitPct.toFixed(1)+'%';
  const profitNote=document.querySelector('#sc-basket-profit-note'),quickProfit=document.querySelector('#sc-quick-profit');
  const discountInfo=document.querySelector('#sc-basket-discount-info');
  discountInfo.hidden=discountUsd===0||!discountValid||!fx;
  discountInfo.textContent=discountUsd>0&&fx?'Supplier discount increases projected gross profit by '+gbp(Math.round(discountUsd*fx))+' compared with the same order without the discount.':'';
  if(boxCount===0)profitNote.textContent='Select supplier boxes above to calculate projected profit after shipping.';
  else if(!discountValid)profitNote.textContent='Supplier discount cannot exceed the selected products total. Reduce the discount or add more products.';
  else if(!fx)profitNote.textContent='Save a GBP per USD exchange rate in Prices & matching to calculate this basket’s profit.';
  else if(unpriced.length)profitNote.textContent='Cannot calculate whole-basket profit until these products have ANKH retail prices: '+unpriced.map(r=>r.name+' '+r.strength+' ('+r.sku+')').join(', ')+'. Match products in Prices & matching and set their selling prices.';
  else profitNote.textContent='Assumes all '+vials+' vials sell at current full ANKH retail prices. Shipping is included once and the supplier discount is deducted once. This is projected gross profit, not realised profit; taxes, packaging, fees and other overheads are excluded.';
  quickProfit.hidden=profit===null;
  quickProfit.textContent=profit===null?'':'Potential profit after shipping: '+gbp(profit);
  preview.value=selected.length?'Hi Jessica,\nCan we place an order please for the following?\n\n'+selected.map(r=>'X'+r.boxes+' '+r.message_label+' ('+r.sku+')').join('\n')+'\n\nThanks':'Select boxes to generate a supplier message.';
  const missing=selected.filter(r=>!r.mapped);
  warning.textContent=!discountValid?'Supplier discount cannot exceed product total.':missing.length?'Match '+missing.map(r=>r.sku).join(', ')+' to ANKH products before marking this basket as ordered.':(!fx?'Save an exchange rate in Prices & matching before recording a purchase.':'');
  save.disabled=!selected.length||!discountValid||missing.length>0||!fx;copy.disabled=!selected.length;
 }
 inputs.forEach(i=>{i.addEventListener('input',update);i.closest('.sc-stepper').querySelectorAll('[data-step]').forEach(btn=>btn.addEventListener('click',()=>{i.value=Math.max(0,Math.min(100,(Number(i.value)||0)+Number(btn.dataset.step)));update()}))});
 document.querySelector('#sc-basket-shipping').addEventListener('input',update);
 document.querySelector('#sc-basket-discount').addEventListener('input',update);
 copy.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(preview.value);copy.textContent='Copied ✓';setTimeout(()=>copy.textContent='Copy supplier message',1600)}catch(err){preview.select();document.execCommand('copy');copy.textContent='Message selected — copy it'}})
 const search=document.querySelector('#sc-order-search');
 search.addEventListener('input',()=>window.scFilterSupplierGroups&&window.scFilterSupplierGroups('sc-order-items','.sc-order-item',search.value));
 document.querySelector('#sc-quick-review').addEventListener('click',()=>{
  document.querySelector('#sc-order-checkout').scrollIntoView({behavior:'smooth',block:'start'});
 });
 form.addEventListener('submit',e=>{update();if(e.submitter&&e.submitter.value==='draft')return;if(save.disabled)e.preventDefault();else if(!confirm('Record this combined MJT supplier order in Incoming Stock? This does not add physical stock yet.'))e.preventDefault()});
 update();
})();
</script>
<?php endif;?></section>
<?php elseif($tab==='products'):?>
<section class="panel sc-panel"><div class="sc-panel-heading"><div><p class="eyebrow">PRIVATE PRICE IMPORT</p><h2>MJT supplier catalogue</h2><p>Upload wholesale costs directly into the authenticated ANKH database. An import updates current supplier prices but keeps order history and product matches.</p></div></div>
<form method="post" enctype="multipart/form-data" class="sc-import-form"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_import"><input type="hidden" name="return" value="supplier"><label>August MJT supplier CSV<input type="file" name="supplier_csv" accept=".csv,text/csv" required></label><button>Import / update supplier prices</button><small>Most recent import: <?=$lastImport!==''?e(date('d M Y H:i',strtotime($lastImport))):'Not imported yet'?></small></form>
<form method="post" class="sc-config-form"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_config"><input type="hidden" name="return" value="supplier"><h3>Currency &amp; cost estimates</h3><div class="sc-config-grid"><label>GBP per 1 USD <input name="usd_gbp_rate" type="number" min="0.0001" max="9.99" step=".0001" placeholder="e.g. 0.7572" value="<?=$fx===null?'':e((string)$fx)?>"></label><label>Supplier shipping per order (USD $)<input name="shipping_per_order_usd" type="number" min="0" max="100000" step=".01" value="<?=e(number_format($orderShippingUsdCents/100,2,'.',''))?>"></label></div><button class="quiet">Save conversion settings</button><p class="muted">MJT currently quotes $70.00 for the whole order, regardless of boxes. The fixed USD shipping amount is converted at your manually saved exchange rate and allocated to products when you build an order. No historical costs are overwritten.</p></form>
<?php if($rows):?><div class="sc-filters"><input id="sc-map-search" type="search" placeholder="Search all supplier SKUs, strengths or products…" aria-label="Filter supplier price mappings"></div><p class="sc-find-help">Your existing products come first. Tap any row to edit its mapping or supplier price. The wider MJT catalogue is collapsed below.</p><div class="sc-map-groups" id="sc-map-list">
<?php foreach($groupDefinitions as $groupKey=>$groupDescription):$groupItems=$groupedRows[$groupKey];if(!$groupItems)continue;?>
<details class="sc-catalogue-group" data-sc-group="<?=e($groupKey)?>" data-default-open="<?=$groupKey==='other'?'0':'1'?>" <?=$groupKey==='other'?'':'open'?>>
<summary class="sc-catalogue-heading"><span><strong><?=e($groupDescription[0])?></strong><small><?=e($groupDescription[1])?></small></span><b><?=count($groupItems)?></b></summary>
<div class="sc-map-list">
<?php foreach($groupItems as $item):?><details class="sc-map-row" data-search="<?=e(strtolower($item['sku'].' '.$item['name'].' '.$item['strength'].' '.$item['ankh_name']))?>"><summary><div><small><?=e($item['sku'])?> · <?=$item['pack']?>/box</small><strong><?=e($item['name'].' '.$item['strength'])?></strong><em><?=e($item['ankh_name']?:'Unmapped to ANKH')?></em></div><span><?=sc_money_usd($item['usd'])?> ▾</span></summary><div class="sc-map-edit"><form method="post"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_map"><input type="hidden" name="return" value="supplier"><input type="hidden" name="sku" value="<?=e($item['sku'])?>"><label>Link to ANKH product<select name="product_id"><option value="">Not linked</option><?php foreach($allProducts as $p):?><option value="<?=(int)$p['id']?>" <?=$item['product_id']!==null&&(int)$item['product_id']===(int)$p['id']?'selected':''?>><?=e($p['name'])?><?=(int)$p['active']===0?' (hidden)':''?></option><?php endforeach;?></select></label><button>Save product match</button></form><form method="post"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_price"><input type="hidden" name="return" value="supplier"><input type="hidden" name="sku" value="<?=e($item['sku'])?>"><label>Supplier box cost (USD)<input type="number" min="0" max="100000" step=".01" name="box_usd" value="<?=e(number_format($item['usd']/100,2,'.',''))?>"></label><button class="quiet">Update box price</button></form></div></details><?php endforeach;?></div></details>
<?php endforeach;?></div><?php endif;?>
</section>
<?php elseif($tab==='incoming'):?>
<section class="panel sc-panel"><div class="sc-panel-heading"><div><p class="eyebrow">EXISTING INVENTORY</p><h2>Incoming &amp; receiving</h2><p>Placed orders go directly into your existing Incoming Stock system. When parcels arrive, confirm exactly how many units were received and allocate them to James’s or Tony’s fridge.</p></div></div>
<div class="sc-incoming-links"><a class="quick-action" href="?view=stock&amp;section=incoming">Open Incoming Stock →</a><a class="quick-action quiet" href="?view=reorder">Reorder suggestions →</a><a class="quick-action quiet" href="?view=stock">Combined fridge stock →</a></div>
<p class="sc-notice">Buying stock never adds it to a fridge automatically. Your original purchases, exchange rates, supplier messages and unit-cost snapshots remain saved.</p>
</section>
<?php endif;?>
<script>
(function(){
 function filterGroups(listId,cardSelector,search){
  const list=document.getElementById(listId);if(!list)return;
  const term=(search||'').trim().toLowerCase();
  list.querySelectorAll('.sc-catalogue-group').forEach(group=>{
   const cards=[...group.querySelectorAll(cardSelector)];
   let visible=0;
   cards.forEach(card=>{
    // Selected basket products remain visible even when filtering by search.
    const quantityInput=card.querySelector('[data-box-sku]');
    const selected=quantityInput&&Number(quantityInput.value)>0;
    const match=!term||card.dataset.search.includes(term)||selected;
    card.hidden=!match;if(match)visible++;
   });
   group.hidden=visible===0;
   if(term&&visible)group.open=true;
   else if(!term)group.open=group.dataset.defaultOpen==='1'||cards.some(card=>Number(card.querySelector('[data-box-sku]')?.value||0)>0);
  });
 }
 window.scFilterSupplierGroups=filterGroups;
 [['sc-profit-search','sc-profit-list','.sc-profit-card'],['sc-map-search','sc-map-list','.sc-map-row'],['sc-smart-search','sc-smart-list','.sc-smart-card']].forEach(([inputId,listId,cardSelector])=>{
  const input=document.getElementById(inputId);
  if(input)input.addEventListener('input',()=>filterGroups(listId,cardSelector,input.value));
 });
 const sort=document.getElementById('sc-profit-sort'),list=document.getElementById('sc-profit-list');
 if(sort&&list)sort.addEventListener('change',()=>{
  const metric=sort.value;
  list.querySelectorAll('.sc-product-list').forEach(inner=>{
   const nodes=[...inner.children].filter(el=>el.classList.contains('sc-profit-card'));
   nodes.sort((a,b)=>metric==='name'?a.dataset.name.localeCompare(b.dataset.name):Number(b.dataset[metric])-Number(a.dataset[metric]));
   nodes.forEach(node=>inner.appendChild(node));
  });
 });
})();
</script>
<?php
}
// END SELF-CONTAINED SUPPLIER CENTRE
sc_setup($db);
if(!in_array('supplier_pack_size',array_column($productColumns,'name'),true))$db->exec("ALTER TABLE products ADD COLUMN supplier_pack_size INTEGER NOT NULL DEFAULT 10");
$stockMovementColumns=$db->query('PRAGMA table_info(stock_movements)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('location',array_column($stockMovementColumns,'name'),true))$db->exec("ALTER TABLE stock_movements ADD COLUMN location TEXT NOT NULL DEFAULT 'Unallocated'");
$stockFulfilmentColumns=$db->query('PRAGMA table_info(stock_fulfilments)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('location',array_column($stockFulfilmentColumns,'name'),true))$db->exec("ALTER TABLE stock_fulfilments ADD COLUMN location TEXT NOT NULL DEFAULT 'Unallocated'");
$stockMigrationQuery=$db->prepare("SELECT value FROM settings WHERE key='fridge_stock_migrated'");$stockMigrationQuery->execute();$stockMigrationDone=$stockMigrationQuery->fetchColumn();
if($stockMigrationDone===false){
 $db->beginTransaction();
 try{
  $legacyStock=$db->query('SELECT id,stock_qty FROM products WHERE stock_tracking=1 AND stock_qty IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
  $saveLegacyStock=$db->prepare('UPDATE products SET stock_unallocated_qty=? WHERE id=?');
  foreach($legacyStock as $legacyRow)$saveLegacyStock->execute([(int)$legacyRow['stock_qty'],(int)$legacyRow['id']]);
  $db->exec("UPDATE stock_movements SET location='Unallocated' WHERE location IS NULL OR trim(location)=''");
  $db->exec("UPDATE stock_fulfilments SET location='Unallocated' WHERE location IS NULL OR trim(location)=''");
  $db->prepare("INSERT OR REPLACE INTO settings(key,value) VALUES ('fridge_stock_migrated',?)")->execute([gmdate('c')]);
  $db->commit();
 }catch(Throwable $migrationError){if($db->inTransaction())$db->rollBack();throw $migrationError;}
}
// Keep the legacy aggregate stock_qty in sync for dashboard/report code and repair any rows
// left one save behind by the previous single-statement fridge update.
$db->exec("UPDATE products SET stock_qty=COALESCE(stock_jay_qty,0)+COALESCE(stock_tony_qty,0)+COALESCE(stock_unallocated_qty,0) WHERE stock_tracking=1 AND (stock_qty IS NULL OR stock_qty<>(COALESCE(stock_jay_qty,0)+COALESCE(stock_tony_qty,0)+COALESCE(stock_unallocated_qty,0)))");
$customerColumns=$db->query('PRAGMA table_info(customers)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('pricing_type',array_column($customerColumns,'name'),true))$db->exec("ALTER TABLE customers ADD COLUMN pricing_type TEXT NOT NULL DEFAULT 'standard'");
function customerPricingType(PDO $db,string $name,string $phone):string{
 $q=$db->prepare('SELECT pricing_type FROM customers WHERE name=? COLLATE NOCASE AND phone=? AND archived=0');$q->execute([trim($name),trim($phone)]);
 return (string)($q->fetchColumn()?:'standard');
}
function customerPricingSelect(string $selected='standard'):void{
 $types=['standard'=>'Standard','family_friends'=>'Family & Friends','free'=>'Free'];
 ?><label>Customer pricing<select name="pricing_type" required><?php foreach($types as $value=>$label):?><option value="<?=e($value)?>" <?=$selected===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label><p class="muted">Standard uses retail prices. Family &amp; Friends uses saved product family prices (retail if unavailable). Free is for personal stock use with no charge. Changes affect new orders only.</p><?php
}
function customerPricingOptions(string $selected='standard'):void{
 ?><fieldset><legend>Customer pricing</legend><?php foreach(['standard'=>'Standard','family_friends'=>'Family & Friends','free'=>'Free'] as $value=>$label):?><label class="check"><input type="radio" name="pricing_type" value="<?=e($value)?>" <?=$selected===$value?'checked':''?>><?=e($label)?></label><?php endforeach;?><p class="muted">Applies to new orders only. Family &amp; Friends uses saved product prices (retail if none is set). Free records personal stock use, including pens, with no payment required.</p></fieldset><?php
}
function personalStockUsage(PDO $db,string $from='',string $to=''):array{
 $q=$db->prepare("SELECT o.id,o.customer,o.assigned_to,o.delivery_date,i.name,i.presentation,i.quantity,i.cost,i.presentation_cost FROM orders o JOIN items i ON i.order_id=o.id WHERE o.pricing_type='free' AND o.status<>'Cancelled' AND trim(o.delivery_date)<>'' AND (?='' OR o.delivery_date>=?) AND (?='' OR o.delivery_date<=?) ORDER BY o.delivery_date DESC,o.id DESC,i.id");
 $q->execute([$from,$from,$to,$to]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
if(!in_array('archived',array_column($customerColumns,'name'),true))$db->exec("ALTER TABLE customers ADD COLUMN archived INTEGER NOT NULL DEFAULT 0");
$db->exec('CREATE INDEX IF NOT EXISTS idx_payments_order ON payments(order_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_reta_cycles_due ON reta_cycles(active,next_due_date)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_reta_cycles_customer ON reta_cycles(customer_name,phone)');
$retaColumns=$db->query('PRAGMA table_info(reta_cycles)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('recipient_name',array_column($retaColumns,'name'),true))$db->exec("ALTER TABLE reta_cycles ADD COLUMN recipient_name TEXT NOT NULL DEFAULT ''");
if(!in_array('last_delivery_date',array_column($retaColumns,'name'),true))$db->exec("ALTER TABLE reta_cycles ADD COLUMN last_delivery_date TEXT NOT NULL DEFAULT ''");
if(!in_array('expected_cost',array_column($retaColumns,'name'),true))$db->exec("ALTER TABLE reta_cycles ADD COLUMN expected_cost INTEGER NOT NULL DEFAULT 0");
if(!in_array('expected_profit',array_column($retaColumns,'name'),true))$db->exec("ALTER TABLE reta_cycles ADD COLUMN expected_profit INTEGER NOT NULL DEFAULT 0");
if(!in_array('cost_missing',array_column($retaColumns,'name'),true))$db->exec("ALTER TABLE reta_cycles ADD COLUMN cost_missing INTEGER NOT NULL DEFAULT 0");
if(!in_array('product_name',array_column($retaColumns,'name'),true))$db->exec("ALTER TABLE reta_cycles ADD COLUMN product_name TEXT NOT NULL DEFAULT ''");
if(!in_array('presentation',array_column($retaColumns,'name'),true))$db->exec("ALTER TABLE reta_cycles ADD COLUMN presentation TEXT NOT NULL DEFAULT ''");
if(!in_array('quantity',array_column($retaColumns,'name'),true))$db->exec("ALTER TABLE reta_cycles ADD COLUMN quantity INTEGER NOT NULL DEFAULT 1");
if(!in_array('cycle_weeks',array_column($retaColumns,'name'),true))$db->exec("ALTER TABLE reta_cycles ADD COLUMN cycle_weeks INTEGER NOT NULL DEFAULT 4");


$db->exec("INSERT INTO payments(order_id,amount,method,note,created)
 SELECT o.id,
  COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0),
  COALESCE(o.payment_method,''),'Imported from existing paid order',
  CASE WHEN trim(COALESCE(o.payment_date,''))<>'' THEN o.payment_date ELSE o.created END
 FROM orders o
 WHERE o.status IN ('Paid','Packed','Dispatched','Delivered')
 AND (COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0))>0
 AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id=o.id)");


// Bring existing order customers into the standalone customer list, newest details first.
$existingOrderCustomers=$db->query("SELECT customer,phone,address,created FROM orders WHERE trim(customer)<>'' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$seedCustomer=$db->prepare('INSERT OR IGNORE INTO customers(name,phone,address,created) VALUES (?,?,?,?)');
foreach($existingOrderCustomers as $existingCustomer){
 $seedCustomer->execute([$existingCustomer['customer'],$existingCustomer['phone'],$existingCustomer['address'],$existingCustomer['created']]);
}
$statuses=['New','Awaiting payment','Paid','Packed','Dispatched','Delivered','Cancelled'];
$paymentMethods=['Cash','Bank Transfer','Card','PayPal','Other'];
$deliveryMethods=['Collection','Local Delivery','Postage'];
$deliveryAssignees=['James','Tony'];
$db->exec("UPDATE orders SET assigned_to='James' WHERE assigned_to='Jay'");

// Keep the live order catalogue complete without overwriting manually managed prices.
function setting(PDO $db,string $key,string $default=''):string{
 $q=$db->prepare('SELECT value FROM settings WHERE key=?');$q->execute([$key]);$v=$q->fetchColumn();
 return $v===false?$default:(string)$v;
}

function saveSetting(PDO $db,string $key,string $value):void{
 $db->prepare('INSERT OR REPLACE INTO settings(key,value) VALUES (?,?)')->execute([$key,$value]);
}


// Additive operations audit: never modify historical orders or payments.
$db->exec("CREATE TABLE IF NOT EXISTS activity_events (
 id INTEGER PRIMARY KEY, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL,
 action TEXT NOT NULL, actor_id INTEGER DEFAULT NULL, actor_name TEXT NOT NULL,
 details_json TEXT NOT NULL DEFAULT '{}', created TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ankh_activity_entity ON activity_events(entity_type,entity_id,id DESC);
CREATE INDEX IF NOT EXISTS idx_ankh_activity_created ON activity_events(created DESC);");
function recordActivity(PDO $db,string $entity,int $entityId,string $action,array $details=[]):void{
 if($entity!=='order'||$entityId<1)throw new InvalidArgumentException('Invalid audit event target.');
 $q=$db->prepare('INSERT INTO activity_events(entity_type,entity_id,action,actor_id,actor_name,details_json,created) VALUES (?,?,?,?,?,?,?)');
 $actorId=(int)($_SESSION['admin_user_id']??0);
 $actorName='System';
 if($actorId>0){$aq=$db->prepare('SELECT username FROM admin_users WHERE id=?');$aq->execute([$actorId]);$username=strtolower((string)($aq->fetchColumn()?:''));$actorName=match($username){'jay','james'=>'James','tony','anthony'=>'Tony',default=>$username!==''?ucfirst($username):'System'};}
 $q->execute([$entity,$entityId,$action,$actorId?:null,$actorName,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),gmdate('c')]);
}
function activityForOrder(PDO $db,int $orderId,int $limit=40):array{
 $q=$db->prepare('SELECT actor_name,action,details_json,created FROM activity_events WHERE entity_type=? AND entity_id=? ORDER BY id DESC LIMIT ?');
 $q->bindValue(1,'order');$q->bindValue(2,$orderId,PDO::PARAM_INT);
 $q->bindValue(3,max(1,min(100,$limit)),PDO::PARAM_INT);$q->execute();return $q->fetchAll(PDO::FETCH_ASSOC);
}
function activitySummary(array $metadata):string{
 $changes=$metadata['changes']??[];$labels=[
 'status'=>'Status','payment_date'=>'Paid date','delivery_date'=>'Delivered',
 'scheduled_delivery_date'=>'Planned delivery','reconstituted'=>'Reconstituted',
 'packaged'=>'Packaged','assigned_to'=>'Assigned','payment_method'=>'Method',
 'amount_paid_pence'=>'Total paid','delivery_charge'=>'Delivery charge',
 'postage_cost'=>'Postage cost','payment_fee'=>'Payment fee',
 'item_lines'=>'Product lines','item_units'=>'Units','item_fingerprint'=>'Products'
 ];
 $parts=[];
 foreach($changes as $field=>$values){
  if(!array_key_exists($field,$labels)||!is_array($values))continue;
  $show=static function($value)use($field):string{
   if($value===null||$value==='')return '—';
   if(in_array($field,['amount_paid_pence','delivery_charge','postage_cost','payment_fee'],true))return '£'.number_format(((int)$value)/100,2);
   if(in_array($field,['reconstituted','packaged'],true))return (int)$value===1?'Yes':'No';
   return (string)$value;
  };
  $parts[]=$field==='item_fingerprint'?'Products changed':$labels[$field].': '.$show($values['from']??null).' → '.$show($values['to']??null);
  if(count($parts)>=4)break;
 }
 if(isset($metadata['removed_payment'])){
  $removed=$metadata['removed_payment'];$parts[]='Removed £'.number_format(((int)($removed['amount_pence']??0))/100,2).' payment';
 }
 return implode(' · ',$parts);
}


// Current retail catalogue from the ANKH price-list artwork supplied 22 Sep 2026.
// Exact penny prices from the detailed Gym & Performance list take precedence
// where the two supplied posters show rounded versions of the same price.
$currentRetailCatalog=[
 'BPC-157 5mg'=>1499,
 'BPC-157 10mg'=>2699,
 'TB-500 2mg'=>1399,
 'TB-500 5mg'=>2799,
 'GHK-CU 50mg'=>2250,
 'SS-31 10mg'=>4499,
 'CJC-1295 without DAC 2mg'=>1199,
 'CJC-1295 without DAC 5mg'=>2099,
 'CJC-1295 with DAC 2mg'=>1799,
 'CJC-1295 with DAC 5mg'=>3599,
 'CJC-1295 MOD without DAC 5mg'=>2099,
 'Ipamorelin 5mg'=>1699,
 'GHRP-2 5mg'=>799,
 'GHRP-2 10mg'=>1599,
 'IGF-1 LR3 1mg'=>4899,
 'MGF 2mg'=>1199,
 'PEG-MGF 2mg'=>1799,
 'MOTS-C 10mg'=>2499,
 'NAD+ 500mg'=>5499,
 'Tesamorelin 2mg'=>2299,
 'Tesamorelin 10mg'=>4499,
 'Retatrutide 10mg'=>4500,
 'Retatrutide 20mg'=>9000,
 'Melanotan 1 10mg'=>2499,
 'KissPeptin-10 5mg'=>2499,
 'DSIP 5mg'=>1100,
 'Epitalon 10mg'=>1600,
 'Selank 5mg'=>1500,
 'Semax 5mg'=>1500,
 'SNAP-8 10mg'=>1800,
 'Glow Stack'=>8000,
 'Wolverine Stack'=>4000,
 'BAC Water 3ml'=>300,
 'BAC Water 10ml'=>399,
 'Acetic Acid 0.6% 10ml'=>499,
 'Pen'=>2000
];
$currentSupplierCosts=[
 'BPC-157 5mg'=>283,'BPC-157 10mg'=>484,
 'TB-500 2mg'=>343,'TB-500 5mg'=>634,
 'GHK-CU 50mg'=>224,'SS-31 10mg'=>723,
 'CJC-1295 without DAC 2mg'=>283,'CJC-1295 without DAC 5mg'=>634,
 'CJC-1295 with DAC 2mg'=>581,'CJC-1295 with DAC 5mg'=>1342,
 'CJC-1295 MOD without DAC 5mg'=>634,
 'Ipamorelin 5mg'=>283,'GHRP-2 5mg'=>179,'GHRP-2 10mg'=>380,
 'IGF-1 LR3 1mg'=>1468,'MGF 2mg'=>462,'PEG-MGF 2mg'=>723,
 'MOTS-C 10mg'=>484,'NAD+ 500mg'=>686,
 'Tesamorelin 2mg'=>425,'Tesamorelin 10mg'=>1453,
 'Retatrutide 10mg'=>790,'Retatrutide 20mg'=>991,
 'Melanotan 1 10mg'=>484,'KissPeptin-10 5mg'=>484,
 'DSIP 5mg'=>320,'Epitalon 10mg'=>261,'Selank 5mg'=>343,
 'Semax 5mg'=>343,'SNAP-8 10mg'=>358,
 'Glow Stack'=>1535,'Wolverine Stack'=>812,
 'BAC Water 3ml'=>75,'BAC Water 10ml'=>82,'Acetic Acid 0.6% 10ml'=>60
];
$configuredStandalonePenCost=setting($db,'pen_cost_pence','');
if($configuredStandalonePenCost!=='' && ctype_digit($configuredStandalonePenCost))$currentSupplierCosts['Pen']=(int)$configuredStandalonePenCost;
$catalogVersion='retail-posters-2026-09-23-v5';
$findProduct=$db->prepare('SELECT id,cost FROM products WHERE lower(trim(name))=lower(trim(?)) ORDER BY id LIMIT 1');
$insertProduct=$db->prepare('INSERT INTO products(name,price,cost,active) VALUES (?,?,?,1)');
$fillProductCost=$db->prepare('UPDATE products SET cost=? WHERE id=? AND cost IS NULL');

// Keep every current retail product available in the database without undoing manual edits.
// If a never-used catalogue product is deliberately deleted, remember that choice so it is not re-seeded.
$deletedCatalogProducts=json_decode(setting($db,'deleted_catalog_products','[]'),true);if(!is_array($deletedCatalogProducts))$deletedCatalogProducts=[];
foreach($currentRetailCatalog as $retailName=>$retailPrice){
 if(in_array($retailName,$deletedCatalogProducts,true))continue;
 $findProduct->execute([$retailName]);$existing=$findProduct->fetch(PDO::FETCH_ASSOC);
 if(!$existing){
  $insertProduct->execute([$retailName,$retailPrice,$currentSupplierCosts[$retailName]??null]);$existingId=(int)$db->lastInsertId();
 }else{
  $existingId=(int)$existing['id'];
  if(isset($currentSupplierCosts[$retailName]))$fillProductCost->execute([$currentSupplierCosts[$retailName],$existingId]);
 }
}
// Standalone Pen list price is £20. Migrate the previously seeded £15 price only.
$db->exec("UPDATE products SET price=2000 WHERE lower(trim(name))='pen' AND price=1500");

// Only deactivate products outside the current retail range when the catalogue changes.
if(setting($db,'retail_catalog_version')!==$catalogVersion){
 $activeNames=array_keys($currentRetailCatalog);
 $allProducts=$db->query('SELECT id,name FROM products')->fetchAll(PDO::FETCH_ASSOC);
 $hideProduct=$db->prepare('UPDATE products SET active=0 WHERE id=?');
 foreach($allProducts as $catalogProduct){
  if(!in_array($catalogProduct['name'],$activeNames,true))$hideProduct->execute([(int)$catalogProduct['id']]);
 }
 saveSetting($db,'retail_catalog_version',$catalogVersion);
}

// Link legacy order lines to products for accurate stock movements.
$db->exec("UPDATE items SET product_id=(SELECT p.id FROM products p WHERE lower(trim(p.name))=lower(trim(items.name)) ORDER BY p.id LIMIT 1) WHERE product_id IS NULL");
// One-time legacy snapshot migration. After this has run, product/pen cost changes are NEVER pushed back into old orders.
if(setting($db,'historical_item_snapshot_migration_v2')!=='done'){
 $db->exec("UPDATE items SET cost=(SELECT p.cost FROM products p WHERE lower(trim(p.name))=lower(trim(items.name)) LIMIT 1) WHERE cost IS NULL AND lower(trim(name))<>'pen'");
 $db->exec("UPDATE items SET base_price=price WHERE base_price IS NULL AND lower(trim(name))<>'pen'");
 $penCostSetting=setting($db,'pen_cost_pence','');
 if($penCostSetting!=='' && ctype_digit($penCostSetting))$db->prepare("UPDATE items SET cost=? WHERE cost IS NULL AND lower(trim(name))='pen'")->execute([(int)$penCostSetting]);
 saveSetting($db,'historical_item_snapshot_migration_v2','done');
}
function sheetsWebhookValid(string $url):bool{
 $p=parse_url($url);$host=strtolower((string)($p['host']??''));
 return (($p['scheme']??'')==='https') && ($host==='script.google.com' || str_ends_with($host,'.googleusercontent.com'));
}
function orderForSheet(PDO $db,int $id):?array{
 $q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$id]);$o=$q->fetch(PDO::FETCH_ASSOC);if(!$o)return null;
 $q=$db->prepare('SELECT name,price,cost,presentation,presentation_cost,base_price,discount,quantity FROM items WHERE order_id=? ORDER BY id');$q->execute([$id]);$items=$q->fetchAll(PDO::FETCH_ASSOC);
 $total=(int)($o['delivery_charge']??0);$out=[];
 foreach($items as $i){
  $line=(int)$i['price']*(int)$i['quantity'];$total+=$line;
  $out[]=['name'=>$i['name'],'presentation'=>$i['presentation']??'','unit_price_pence'=>(int)$i['price'],'base_price_pence'=>$i['base_price']===null?null:(int)$i['base_price'],'discount_pence'=>(int)($i['discount']??0),'unit_cost_pence'=>$i['cost']===null?null:(int)$i['cost'],'presentation_cost_pence'=>(int)($i['presentation_cost']??0),'quantity'=>(int)$i['quantity']];
 }
 $q=$db->prepare('SELECT id,amount,method,note,created FROM payments WHERE order_id=? ORDER BY datetime(created),id');$q->execute([$id]);$paymentRows=$q->fetchAll(PDO::FETCH_ASSOC);$paymentOut=[];$paidAmount=0;
 foreach($paymentRows as $p){$paidAmount+=(int)$p['amount'];$paymentOut[]=['id'=>(int)$p['id'],'amount_pence'=>(int)$p['amount'],'method'=>(string)$p['method'],'note'=>(string)$p['note'],'created'=>(string)$p['created']];}
 return [
  'id'=>(int)$o['id'],'reference'=>'ANK-'.str_pad((string)$o['id'],4,'0',STR_PAD_LEFT),'created'=>$o['created'],
  'customer'=>$o['customer'],'phone'=>$o['phone'],'referrer'=>$o['referrer']??'','presentation'=>$o['presentation']??'',
  'payment_method'=>$o['payment_method']??'','delivery_method'=>$o['delivery_method']??'','assigned_to'=>$o['assigned_to']??'','tracking_reference'=>$o['tracking_reference']??'',
  'delivery_charge_pence'=>(int)($o['delivery_charge']??0),'postage_cost_pence'=>(int)($o['postage_cost']??0),'payment_fee_pence'=>(int)($o['payment_fee']??0),
  'payment_date'=>$o['payment_date']??'','delivery_date'=>$o['delivery_date']??'','address'=>$o['address'],'notes'=>$o['notes'],
  'status'=>$o['status'],'total_pence'=>$total,'paid_amount_pence'=>$paidAmount,'balance_due_pence'=>max(0,$total-$paidAmount),'payments'=>$paymentOut,'items'=>$out
 ];
}
function syncOrderToSheet(PDO $db,int $orderId):?string{
 $url=setting($db,'sheets_webhook');if($url==='')return null;
 if(!sheetsWebhookValid($url))return 'The saved Google Sheets webhook URL is invalid.';
 $order=orderForSheet($db,$orderId);if(!$order)return 'The order could not be found for syncing.';
 $payload=['secret'=>setting($db,'sheets_secret'),'event'=>'order_upsert','spreadsheetId'=>setting($db,'sheets_sheet_id'),'order'=>$order];
 $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 if($json===false)return 'The order could not be prepared for Google Sheets.';
 $body=false;$http=0;$transport='';
 if(function_exists('curl_init')){
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>4,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_USERAGENT=>'ANKH-Admin/1.0']);
  if(defined('CURLOPT_POSTREDIR')&&defined('CURL_REDIR_POST_ALL'))curl_setopt($ch,CURLOPT_POSTREDIR,CURL_REDIR_POST_ALL);
  $body=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$transport=curl_error($ch);curl_close($ch);
 }else{
  $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: ANKH-Admin/1.0\r\n",'content'=>$json,'timeout'=>10,'ignore_errors'=>true]]);
  $body=@file_get_contents($url,false,$ctx);
  if(isset($http_response_header[0])&&preg_match('/\\s(\\d{3})\\s/',$http_response_header[0],$m))$http=(int)$m[1];
 }
 $reply=is_string($body)?json_decode($body,true):null;
 if($http>=200&&$http<300&&is_array($reply)&&!empty($reply['ok'])){
  saveSetting($db,'sheets_last_sync',gmdate('c'));saveSetting($db,'sheets_last_error','');return '';
 }
 $message=$transport?:((is_array($reply)&&!empty($reply['error']))?(string)$reply['error']:'Google did not confirm the sync.');
 saveSetting($db,'sheets_last_error',substr($message,0,500));return $message;
}

function deleteOrderFromSheet(PDO $db,string $reference):?string{
 $url=setting($db,'sheets_webhook');if($url==='')return null;
 if(!sheetsWebhookValid($url))return 'The saved Google Sheets webhook URL is invalid.';
 $payload=['secret'=>setting($db,'sheets_secret'),'event'=>'order_delete','spreadsheetId'=>setting($db,'sheets_sheet_id'),'reference'=>$reference];
 $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false)return 'The delete could not be prepared for Google Sheets.';
 $body=false;$http=0;$transport='';
 if(function_exists('curl_init')){
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>4,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_USERAGENT=>'ANKH-Admin/1.0']);
  if(defined('CURLOPT_POSTREDIR')&&defined('CURL_REDIR_POST_ALL'))curl_setopt($ch,CURLOPT_POSTREDIR,CURL_REDIR_POST_ALL);
  $body=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$transport=curl_error($ch);curl_close($ch);
 }else{
  $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: ANKH-Admin/1.0\r\n",'content'=>$json,'timeout'=>10,'ignore_errors'=>true]]);
  $body=@file_get_contents($url,false,$ctx);
  if(isset($http_response_header[0])&&preg_match('/\\s(\\d{3})\\s/',$http_response_header[0],$m))$http=(int)$m[1];
 }
 $reply=is_string($body)?json_decode($body,true):null;
 if($http>=200&&$http<300&&is_array($reply)&&!empty($reply['ok']))return '';
 return $transport?:((is_array($reply)&&!empty($reply['error']))?(string)$reply['error']:'Google did not confirm the delete.');
}
if(setting($db,'sheets_sheet_id')==='')saveSetting($db,'sheets_sheet_id','1j9ucRgbGcB56olVTGiBJDJNMxgggB1jg4KUWZuslUwA');
if(setting($db,'sheets_secret')==='')saveSetting($db,'sheets_secret',bin2hex(random_bytes(24)));

function openAiCurlJson(string $url,array $headers,$body,bool $multipart=false):array{
 if(!function_exists('curl_init'))throw new Exception('Voice ordering needs the PHP cURL extension enabled.');
 $ch=curl_init($url);$baseHeaders=['Authorization: Bearer '.($GLOBALS['openAiKey']??'')];
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>array_merge($baseHeaders,$headers),CURLOPT_POSTFIELDS=>$body]);
 $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
 if($raw===false||$err!=='')throw new Exception('Could not reach OpenAI. Please try again.');
 $json=json_decode((string)$raw,true);
 if($http<200||$http>=300){
  $message=is_array($json)?(string)($json['error']['message']??''):'';
  throw new Exception($message!==''?'OpenAI: '.$message:'OpenAI could not process the voice order.');
 }
 if(!is_array($json))throw new Exception('OpenAI returned an unreadable response.');
 return $json;
}
function responseOutputText(array $response):string{
 if(isset($response['output_text'])&&is_string($response['output_text']))return $response['output_text'];
 foreach(($response['output']??[]) as $item){
  foreach(($item['content']??[]) as $content){
   if(($content['type']??'')==='output_text'&&isset($content['text']))return (string)$content['text'];
  }
 }
 return '';
}
function voiceJson(array $data,int $status=200):never{
 http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}


function pdfTextValue(string $value):string{
 $value=preg_replace('/\s+/',' ',trim($value))??trim($value);
 if(function_exists('iconv')){$converted=@iconv('UTF-8','Windows-1252//TRANSLIT',$value);if($converted!==false)$value=$converted;}
 return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$value);
}
function pdfTextCmd(float $x,float $y,float $size,string $text,string $font='F1'):string{
 return "BT /".$font." ".number_format($size,1,'.','')." Tf ".number_format($x,1,'.','')." ".number_format($y,1,'.','')." Td (".pdfTextValue($text).") Tj ET\n";
}
function pdfMoney(int $pence):string{return '£'.number_format($pence/100,2);}
function outputOrderPdf(array $order,string $type):never{
 $type=$type==='receipt'?'receipt':'invoice';$title=strtoupper($type);$ref=(string)$order['reference'];$ops="1 1 1 rg 0 0 595 842 re f\n";
 $ops.="0.063 0.067 0.071 rg 0 742 595 100 re f\n0.875 0.714 0.400 rg\n";
 $ops.=pdfTextCmd(42,785,28,'ANKH','F2').pdfTextCmd(42,758,9,'PEPTIDES / ORDER DESK','F1');
 $ops.="1 1 1 rg\n".pdfTextCmd(425,782,19,$title,'F2').pdfTextCmd(425,760,10,$ref,'F1');
 $ops.="0.09 0.09 0.09 rg\n".pdfTextCmd(42,710,11,'Customer','F2').pdfTextCmd(42,692,13,(string)$order['customer'],'F1');
 $y=674;
 if(trim((string)$order['phone'])!==''){$ops.=pdfTextCmd(42,$y,9,(string)$order['phone'],'F1');$y-=14;}
 if(trim((string)$order['address'])!==''){
  foreach(array_slice(explode("\n",wordwrap(preg_replace('/\s+/',' ',(string)$order['address'])??'',58,"\n")),0,4) as $line){$ops.=pdfTextCmd(42,$y,9,$line,'F1');$y-=13;}
 }
 $ops.=pdfTextCmd(355,710,11,'Order details','F2').pdfTextCmd(355,692,9,'Order date: '.date('d M Y',strtotime((string)$order['created'])),'F1');
 if(trim((string)$order['payment_method'])!=='')$ops.=pdfTextCmd(355,676,9,'Payment: '.(string)$order['payment_method'],'F1');
 if(trim((string)$order['delivery_method'])!=='')$ops.=pdfTextCmd(355,660,9,'Delivery: '.(string)$order['delivery_method'],'F1');
 $ops.=pdfTextCmd(355,644,9,'Status: '.(string)$order['status'],'F1');
 $tableY=min($y-18,610);$ops.="0.92 0.92 0.92 rg 42 ".($tableY-8)." 511 25 re f\n0.12 0.12 0.12 rg\n";
 $ops.=pdfTextCmd(50,$tableY,9,'ITEM','F2').pdfTextCmd(390,$tableY,9,'QTY','F2').pdfTextCmd(445,$tableY,9,'EACH','F2').pdfTextCmd(510,$tableY,9,'TOTAL','F2');
 $rowY=$tableY-28;$items=array_slice($order['items']??[],0,20);
 foreach($items as $item){
  $label=(string)$item['name'];if(!empty($item['presentation']))$label.=' · '.(string)$item['presentation'];if((int)($item['discount_pence']??0)>0)$label.=' · F&F';
  if(strlen($label)>55)$label=substr($label,0,52).'...';
  $qty=(int)$item['quantity'];$unit=(int)$item['unit_price_pence'];$ops.=pdfTextCmd(50,$rowY,9,$label,'F1').pdfTextCmd(398,$rowY,9,(string)$qty,'F1').pdfTextCmd(445,$rowY,9,pdfMoney($unit),'F1').pdfTextCmd(510,$rowY,9,pdfMoney($unit*$qty),'F1');$rowY-=20;
 }
 if(count($order['items']??[])>20){$ops.=pdfTextCmd(50,$rowY,8,'Additional items are recorded in ANKH Admin.','F1');$rowY-=18;}
 if((int)($order['delivery_charge_pence']??0)>0){$ops.=pdfTextCmd(50,$rowY,9,'Postage / delivery charge','F1').pdfTextCmd(510,$rowY,9,pdfMoney((int)$order['delivery_charge_pence']),'F1');$rowY-=20;}
 $rowY-=7;$ops.="0.875 0.714 0.400 RG 0.8 w 355 ".$rowY." m 553 ".$rowY." l S\n0.09 0.09 0.09 rg\n";$rowY-=25;
 $ops.=pdfTextCmd(405,$rowY,11,'TOTAL','F2').pdfTextCmd(500,$rowY,14,pdfMoney((int)$order['total_pence']),'F2');
 $rowY-=38;$paidAmount=(int)($order['paid_amount_pence']??0);$balanceDue=(int)($order['balance_due_pence']??max(0,(int)$order['total_pence']-$paidAmount));
 $statusText=$balanceDue<=0?'PAID IN FULL':'PAID '.pdfMoney($paidAmount).' · BALANCE '.pdfMoney($balanceDue);
 $ops.=pdfTextCmd(42,max(90,$rowY),10,$statusText,'F2');
 if(trim((string)($order['notes']??''))!==''){$noteY=max(66,$rowY-24);$ops.=pdfTextCmd(42,$noteY,8,'Notes: '.substr(preg_replace('/\s+/',' ',(string)$order['notes'])??'',0,110),'F1');}
 $ops.="0.45 0.45 0.45 rg\n".pdfTextCmd(42,36,8,'Generated by ANKH Admin · '.$ref,'F1');
 $objects=[];
 $objects[1]="<< /Type /Catalog /Pages 2 0 R >>";
 $objects[2]="<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
 $objects[3]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>";
 $objects[4]="<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
 $objects[5]="<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
 $objects[6]="<< /Length ".strlen($ops)." >>\nstream\n".$ops."endstream";
 $pdf="%PDF-1.4\n";$offsets=[0];
 for($i=1;$i<=6;$i++){$offsets[$i]=strlen($pdf);$pdf.=$i." 0 obj\n".$objects[$i]."\nendobj\n";}
 $xref=strlen($pdf);$pdf.="xref\n0 7\n0000000000 65535 f \n";for($i=1;$i<=6;$i++)$pdf.=str_pad((string)$offsets[$i],10,'0',STR_PAD_LEFT)." 00000 n \n";
 $pdf.="trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
 header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="ANKH-'.$ref.'-'.$type.'.pdf"');header('Content-Length: '.strlen($pdf));echo $pdf;exit;
}

function b64urlEncode(string $data):string{return rtrim(strtr(base64_encode($data),'+/','-_'),'=');}
function b64urlDecode(string $value):string{
 $pad=strlen($value)%4;if($pad)$value.=str_repeat('=',4-$pad);
 $decoded=base64_decode(strtr($value,'-_','+/'),true);if($decoded===false)throw new Exception('Invalid passkey data.');
 return $decoded;
}
function cborReadLength(string $data,int &$offset,int $additional):int{
 if($additional<24)return $additional;
 if($additional===24){if($offset>=strlen($data))throw new Exception('Invalid passkey CBOR.');return ord($data[$offset++]);}
 if($additional===25){if($offset+2>strlen($data))throw new Exception('Invalid passkey CBOR.');$v=unpack('n',substr($data,$offset,2))[1];$offset+=2;return (int)$v;}
 if($additional===26){if($offset+4>strlen($data))throw new Exception('Invalid passkey CBOR.');$v=unpack('N',substr($data,$offset,4))[1];$offset+=4;return (int)$v;}
 throw new Exception('Unsupported passkey CBOR length.');
}
function cborRead(string $data,int &$offset){
 if($offset>=strlen($data))throw new Exception('Invalid passkey CBOR.');
 $first=ord($data[$offset++]);$major=$first>>5;$additional=$first&31;
 if($major===7){
  if($additional===20)return false;if($additional===21)return true;if($additional===22||$additional===23)return null;
  throw new Exception('Unsupported passkey CBOR value.');
 }
 $length=cborReadLength($data,$offset,$additional);
 if($major===0)return $length;
 if($major===1)return -1-$length;
 if($major===2){if($offset+$length>strlen($data))throw new Exception('Invalid passkey CBOR bytes.');$v=substr($data,$offset,$length);$offset+=$length;return $v;}
 if($major===3){if($offset+$length>strlen($data))throw new Exception('Invalid passkey CBOR text.');$v=substr($data,$offset,$length);$offset+=$length;return $v;}
 if($major===4){$arr=[];for($i=0;$i<$length;$i++)$arr[]=cborRead($data,$offset);return $arr;}
 if($major===5){$map=[];for($i=0;$i<$length;$i++){$k=cborRead($data,$offset);$map[$k]=cborRead($data,$offset);}return $map;}
 if($major===6)return cborRead($data,$offset);
 throw new Exception('Unsupported passkey CBOR type.');
}
function webauthnRpId():string{
 $host=strtolower((string)($_SERVER['HTTP_HOST']??''));$host=preg_replace('/:\d+$/','',$host)??$host;
 if($host==='')throw new Exception('Passkey host could not be determined.');return $host;
}
function webauthnOrigin():string{
 $https=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
 return ($https?'https':'http').'://'.strtolower((string)($_SERVER['HTTP_HOST']??''));
}
function webauthnClientData(string $encoded,string $type,string $challenge):array{
 $raw=b64urlDecode($encoded);$data=json_decode($raw,true);
 if(!is_array($data) || (string)($data['type']??'')!==$type || !hash_equals($challenge,(string)($data['challenge']??'')))throw new Exception('Passkey challenge did not match.');
 if((string)($data['origin']??'')!==webauthnOrigin())throw new Exception('Passkey origin did not match this app.');
 if(!empty($data['crossOrigin']))throw new Exception('Cross-origin passkeys are not allowed.');
 return ['raw'=>$raw,'json'=>$data];
}
function webauthnAuthData(string $authData,bool $registration=false):array{
 if(strlen($authData)<37)throw new Exception('Passkey authenticator data is incomplete.');
 $rpHash=substr($authData,0,32);$flags=ord($authData[32]);$count=(int)unpack('N',substr($authData,33,4))[1];
 if(!hash_equals(hash('sha256',webauthnRpId(),true),$rpHash))throw new Exception('Passkey is for a different site.');
 if(($flags&0x01)!==0x01 || ($flags&0x04)!==0x04)throw new Exception('Face ID, Touch ID or device verification is required.');
 $out=['flags'=>$flags,'count'=>$count];
 if($registration){
  if(($flags&0x40)!==0x40 || strlen($authData)<55)throw new Exception('Passkey registration data is incomplete.');
  $offset=53;$credentialLength=(int)unpack('n',substr($authData,$offset,2))[1];$offset+=2;
  if($credentialLength<1 || $offset+$credentialLength>strlen($authData))throw new Exception('Passkey credential ID is invalid.');
  $credentialId=substr($authData,$offset,$credentialLength);$offset+=$credentialLength;
  $coseOffset=$offset;$cose=cborRead($authData,$coseOffset);
  if(!is_array($cose))throw new Exception('Passkey public key is invalid.');
  $out['credential_id']=$credentialId;$out['cose']=$cose;
 }
 return $out;
}
function webauthnCoseToPem(array $cose):string{
 if((int)($cose[1]??0)!==2 || (int)($cose[3]??0)!==-7 || (int)($cose[-1]??0)!==1)throw new Exception('This passkey algorithm is not supported.');
 $x=$cose[-2]??'';$y=$cose[-3]??'';if(!is_string($x)||!is_string($y)||strlen($x)!==32||strlen($y)!==32)throw new Exception('Passkey public key coordinates are invalid.');
 $der=hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004').$x.$y;
 return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";
}
function passkeyChallengeValid(string $kind):string{
 $challenge=(string)($_SESSION['passkey_challenge']??'');$challengeKind=(string)($_SESSION['passkey_kind']??'');$created=(int)($_SESSION['passkey_created']??0);
 if($challenge===''||$challengeKind!==$kind||time()-$created>300)throw new Exception('Passkey request expired. Please try again.');
 return $challenge;
}

// One-time 4-digit PIN setup. Only a SHA-256 hash of the setup token is stored here.
$pinSetupTokenHash='64a1ea8550f3bb078a15088090d021b056d8bdad6ee24d1346e7603693b1cbea';
$savedAdminPinHash=setting($db,'admin_pin_hash','');
$pinSetupComplete=$savedAdminPinHash!=='';
// TEMPORARY PUBLIC TEST MODE: set false before the app is used with real data.
$testingNoAuth=false;
require_once __DIR__.'/auth-users.php';
ankhAuthSchema($db);
$passkeyCols=array_column($db->query('PRAGMA table_info(passkeys)')->fetchAll(PDO::FETCH_ASSOC),'name');
if(!in_array('user_id',$passkeyCols,true))$db->exec('ALTER TABLE passkeys ADD COLUMN user_id INTEGER DEFAULT NULL REFERENCES admin_users(id)');
$hasIndividualLogins=ankhAuthUserCount($db)>0;
if($hasIndividualLogins){
 if(empty($_SESSION['admin_user_id']))unset($_SESSION['admin'],$_SESSION['last']);
 ankhAuthRestore($db);
}
$adminPasswordHash=$pinSetupComplete?$savedAdminPinHash:(string)$config['password_hash'];
$openAiKey=trim((string)($config['openai_api_key']??(getenv('OPENAI_API_KEY')?:'')));
$voiceOrderReady=$pinSetupComplete && $openAiKey!=='';

$pinSetupToken=(string)($_GET['setup_pin']??$_POST['setup_pin']??'');
$pinSetupAuthorized=!$pinSetupComplete && $pinSetupToken!=='' && hash_equals($pinSetupTokenHash,hash('sha256',$pinSetupToken));

if($testingNoAuth){$_SESSION['admin']=true;$_SESSION['last']=time();}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='create_admin_pin'){
 try{
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new Exception('Please refresh the page and try again.');
  if(!$pinSetupAuthorized)throw new Exception('This PIN setup link is invalid or has already been used.');
  $pin=(string)($_POST['pin']??'');$confirm=(string)($_POST['confirm_pin']??'');
  if(!preg_match('/^\\d{4}$/',$pin))throw new Exception('Choose exactly 4 numbers.');
  if($pin!==$confirm)throw new Exception('The two PINs do not match.');
  saveSetting($db,'admin_pin_hash',password_hash($pin,PASSWORD_DEFAULT));
  saveSetting($db,'pin_setup_completed_at',gmdate('c'));
  session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['last']=time();
  header('Location: ./?view=dashboard&pin_created=1');exit;
 }catch(Throwable $ex){$error=$ex->getMessage();}
}

function backOrderStockPlan(PDO $db,array $incoming):array{
 $products=[];foreach($db->query('SELECT * FROM products') as $p)$products[(int)$p['id']]=$p;
 $q=$db->query("SELECT COALESCE(i.product_id,(SELECT p.id FROM products p WHERE lower(trim(p.name))=lower(trim(i.name)) ORDER BY p.id LIMIT 1)) product_id,i.name,i.presentation,SUM(i.quantity) quantity FROM items i JOIN orders o ON o.id=i.order_id WHERE o.back_order=1 AND o.status NOT IN ('Cancelled','Delivered','Dispatched') AND trim(COALESCE(o.delivery_date,''))='' GROUP BY product_id,lower(trim(i.name)),i.presentation");
 $rows=[];
 foreach($q as $line){
  $id=(int)($line['product_id']??0);$key=$id>0?'id:'.$id:'name:'.strtolower(trim((string)$line['name']));$qty=(int)$line['quantity'];
  if(!isset($rows[$key])){$p=$products[$id]??null;$tracked=$p&&(int)$p['stock_tracking']===1;$raw=$tracked?(int)$p['stock_qty']:null;
   $rows[$key]=['id'=>$id,'name'=>(string)($p['name']??$line['name']),'quantity'=>0,'formats'=>[],'available'=>$raw===null?null:max(0,$raw),'raw_available'=>$raw,'incoming'=>max(0,(int)($incoming[$id]['due']??0)),'expected_date'=>$incoming[$id]['expected_date']??null];
  }
  $rows[$key]['quantity']+=$qty;$format=trim((string)$line['presentation']);if($format!=='')$rows[$key]['formats'][$format]=($rows[$key]['formats'][$format]??0)+$qty;
 }
 foreach($rows as &$row){$raw=$row['raw_available'];$row['shortfall']=$raw===null?null:max(0,$row['quantity']-$raw-$row['incoming']);$row['active_shortage']=$raw===null?0:max(0,-$raw);ksort($row['formats']);}unset($row);
 $rows=array_values($rows);usort($rows,fn($a,$b)=>strcasecmp($a['name'],$b['name']));return $rows;
}
function renderBackOrderStockPlan(array $rows,int $waitingCount,int $expectedIncome):void{
 ?><section class="backorder-workspace" aria-labelledby="backorder-stock-title">
 <div class="backorder-income"><span>Expected money in</span><strong><?=money($expectedIncome)?></strong><small>Still owed after payments already received.</small></div>
 <div class="backorder-overview"><div><strong><?=$waitingCount?></strong><span>Orders waiting</span></div><div><strong><?=array_sum(array_column($rows,'quantity'))?></strong><span>Units needed</span></div><a href="?view=stock&amp;section=incoming">Incoming stock <span aria-hidden="true">↗</span></a></div>
 <div class="backorder-section-heading"><div><h2 id="backorder-stock-title">What’s back ordered</h2><p>Product totals across all waiting orders.</p></div></div>
 <?php if(!$rows):?><p class="empty">No products waiting for stock.</p><?php else:?><div class="backorder-product-grid">
 <?php foreach($rows as $row):$status=$row['available']===null?'Count stock first':($row['available']>=$row['quantity']?'Stock available':($row['shortfall']===0?'Covered on arrival':$row['shortfall'].' still needed'));$state=$row['available']===null?'unknown':($row['shortfall']>0?'short':'covered');?>
 <article class="backorder-product" data-backorder-product="<?=(int)$row['id']?>" data-needed="<?=(int)$row['quantity']?>" data-incoming="<?=(int)$row['incoming']?>" data-shortfall="<?=$row['shortfall']===null?'unknown':(int)$row['shortfall']?>">
 <div class="backorder-product-head"><div><h3><?=e($row['name'])?></h3><?php if($row['formats']):?><p><?php $parts=[];foreach($row['formats'] as $format=>$qty)$parts[]=$qty.' × '.$format;echo e(implode(' · ',$parts));?></p><?php else:?><p><?=strtolower(trim($row['name']))==='pen'?'Accessory':'Format not recorded'?></p><?php endif;?></div><div class="backorder-needed"><strong><?=(int)$row['quantity']?></strong><span>needed</span></div></div>
 <dl class="backorder-stock-metrics"><div><dt>Available</dt><dd><?=$row['available']===null?'—':(int)$row['available']?></dd></div><div><dt>Incoming</dt><dd><?=(int)$row['incoming']?></dd></div><div><dt>Still short</dt><dd><?=$row['shortfall']===null?'—':(int)$row['shortfall']?></dd></div></dl>
 <div class="backorder-product-footer"><span class="backorder-stock-status status-<?=e($state)?>"><?=e($status)?></span><?php if($row['incoming']>0&&$row['expected_date']):?><small>Next arrival <?=e(date('d M',strtotime($row['expected_date'])))?></small><?php endif;?></div>
 <?php if($row['active_shortage']>0):?><p class="backorder-stock-note">Incoming stock must also cover <?=$row['active_shortage']?> units short on active orders.</p><?php endif;?></article>
 <?php endforeach;?></div><?php endif;?>
 <p class="backorder-plan-note">Combined stock, after active reservations. Incoming units must be received before use; activation checks the assigned fridge.</p>
 </section><?php
}
function backOrderAvailability(PDO $db,array $order):array{
 $location=stockLocationForAssignee((string)($order['assigned_to']??''));$column=stockLocationColumn($location);
 $q=$db->prepare("SELECT COALESCE(i.product_id,(SELECT p.id FROM products p WHERE lower(trim(p.name))=lower(trim(i.name)) ORDER BY p.id LIMIT 1)) product_id,MIN(i.name) name,SUM(i.quantity) quantity FROM items i WHERE i.order_id=? GROUP BY product_id");$q->execute([(int)$order['id']]);
 $lines=$q->fetchAll(PDO::FETCH_ASSOC);$issues=[];
 foreach($lines as $line){
  $q=$db->prepare("SELECT stock_tracking,{$column} available FROM products WHERE id=?");$q->execute([(int)$line['product_id']]);$product=$q->fetch(PDO::FETCH_ASSOC);
  if(!$product||(int)$product['stock_tracking']!==1){$issues[]=$line['name'].': stock count needed';continue;}
  if((int)$product['available']<(int)$line['quantity'])$issues[]=$line['name'].': '.max(0,(int)$product['available']).' available / '.(int)$line['quantity'].' needed';
 }
 if(!$lines)$issues[]='No products recorded';
 return ['ready'=>!$issues,'issues'=>$issues,'location'=>$location];
}
function setOrderBackOrder(PDO $db,int $id,bool $waiting):void{
 // A write lock makes the availability check and reservation atomic across both operators.
 $db->beginTransaction();
 try{
  $db->exec('UPDATE orders SET back_order=back_order WHERE id='.(int)$id);
  $q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$id]);$order=$q->fetch(PDO::FETCH_ASSOC);
  if(!$order)throw new Exception('Order could not be found.');
  if(in_array((string)$order['status'],['Cancelled','Dispatched','Delivered'],true)||trim((string)$order['delivery_date'])!=='')throw new Exception('Only open, undelivered orders can move between active and back orders.');
  if(isBackOrder($order)===$waiting){$db->commit();return;}
  if(!$waiting){$stock=backOrderAvailability($db,$order);if(!$stock['ready'])throw new Exception('Cannot activate yet. '.implode('; ',$stock['issues']).'. Check the assigned fridge or add stock first.');}
  $now=gmdate('c');
  $db->prepare("UPDATE orders SET back_order=?,back_order_since=CASE WHEN CAST(? AS INTEGER)=1 THEN ? ELSE back_order_since END,activated_at=CASE WHEN CAST(? AS INTEGER)=0 THEN ? ELSE activated_at END,reconstituted=0,packaged=0 WHERE id=?")->execute([$waiting?1:0,$waiting?1:0,$now,$waiting?1:0,$now,$id]);
  reconcileDeliveredStock($db,$id,false);
  $db->commit();
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
function backOrderControls(PDO $db,array $order):void{
 if(in_array((string)$order['status'],['Cancelled','Dispatched','Delivered'],true)||trim((string)$order['delivery_date'])!=='')return;
 $waiting=isBackOrder($order);$stock=$waiting?backOrderAvailability($db,$order):null;
 ?><section class="back-order-controls">
 <?php if($waiting):$since=(string)($order['back_order_since']?:$order['created']);$days=(int)(new DateTimeImmutable($since))->diff(new DateTimeImmutable('now'))->format('%a');?>
 <strong>Awaiting stock · <?=$days?> day<?=$days===1?'':'s'?> waiting</strong>
 <p class="<?=$stock['ready']?'stock-ready':'muted'?>"><?=$stock['ready']?'Stock available':e(implode(' · ',$stock['issues']))?> · <?=e($stock['location']==='Unallocated'?'Holding stock':$stock['location'].'’s fridge')?></p>
 <?php else:?><strong>Waiting for stock?</strong><p class="muted">Move this order to Back orders until stock arrives. Its details and payments stay with it.</p><?php endif;?>
 <form method="post" onsubmit="return confirm('<?=$waiting?'Move this order to active and reserve stock?':'Move this order to the waiting list? Stock reservations will be released and preparation reset.'?>')"><?php csrf();?><input type="hidden" name="action" value="order_backorder"><input type="hidden" name="id" value="<?=(int)$order['id']?>"><input type="hidden" name="back_order" value="<?=$waiting?'0':'1'?>"><input type="hidden" name="return" value="<?=$waiting?'orders':'backorders'?>"><button class="quiet" <?=$waiting&&!$stock['ready']?'disabled':''?>><?=$waiting?'Move to active orders':'Move to back orders'?></button></form></section><?php
}
function supplierEditRevision(array $order,array $lines):string{
 usort($lines,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
 $state=[array_intersect_key($order,array_flip(['id','supplier','expected_date','status','note']))];
 foreach($lines as $line)$state[]=array_intersect_key($line,array_flip(['id','product_id','packs','pack_size','quantity','unit_cost','received_qty']));
 return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));
}
function editIncomingSupplierOrder(PDO $db,int $id,array $post):void{
 $supplier=trim((string)($post['supplier']??''));$expected=trim((string)($post['expected_date']??''));$note=trim((string)($post['note']??''));$posted=$post['lines']??[];
 if($id<1||$supplier===''||strlen($supplier)>160||strlen($note)>500)throw new Exception('Check the supplier name and notes.');
 if($expected!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expected)||!checkdate((int)substr($expected,5,2),(int)substr($expected,8,2),(int)substr($expected,0,4))))throw new Exception('Enter a valid expected delivery date.');
 if(!is_array($posted)||count($posted)>200)throw new Exception('Check the products on this supplier order.');
 $db->beginTransaction();
 try{
  $db->prepare('UPDATE supplier_orders SET id=id WHERE id=?')->execute([$id]);
  $q=$db->prepare('SELECT * FROM supplier_orders WHERE id=?');$q->execute([$id]);$order=$q->fetch(PDO::FETCH_ASSOC);
  if(!$order||in_array($order['status'],['Received','Cancelled'],true))throw new Exception('Only open incoming supplier orders can be edited.');
  $q=$db->prepare('SELECT * FROM supplier_order_items WHERE supplier_order_id=? ORDER BY id');$q->execute([$id]);$existing=$q->fetchAll(PDO::FETCH_ASSOC);$byId=array_column($existing,null,'id');
  if(!hash_equals(supplierEditRevision($order,$existing),(string)($post['revision']??'')))throw new Exception('This supplier order changed while you were editing it. Reload Incoming stock and try again.');
  $seen=[];$changes=[];$new=[];
  foreach($posted as $input){
   if(!is_array($input))throw new Exception('Check the product rows.');
   $lineId=(int)($input['id']??0);$old=$lineId>0?($byId[$lineId]??null):null;
   if($lineId>0&&(!$old||isset($seen[$lineId])))throw new Exception('A product row does not belong to this supplier order.');
   if($old)$seen[$lineId]=true;
   $received=(int)($old['received_qty']??0);
   if(($input['remove']??'')==='1'){
    if($received>0)throw new Exception('A product with received stock cannot be removed.');
    if($old)$changes[]=['old'=>$old,'remove'=>true];continue;
   }
   $pid=(int)($input['product_id']??0);if(!$old&&$pid===0)continue;
   $packs=filter_var($input['packs']??'',FILTER_VALIDATE_INT);
   if($pid<1||$packs===false||$packs<1||$packs>999)throw new Exception('Choose a product and between 1 and 999 packs for each row.');
   $q=$db->prepare('SELECT id,supplier_pack_size,cost FROM products WHERE id=?');$q->execute([$pid]);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)throw new Exception('A selected product no longer exists.');
   if($received>0&&$pid!==(int)$old['product_id'])throw new Exception('A product with received stock cannot be replaced.');
   $packSize=$old&&$pid===(int)$old['product_id']?(int)$old['pack_size']:max(1,(int)$p['supplier_pack_size']);$quantity=(int)$packs*$packSize;
   if($quantity<$received)throw new Exception('The ordered quantity cannot be lower than the '.$received.' units already received.');
   $raw=trim((string)($input['unit_cost']??''));$cost=$raw===''?($old&&$pid===(int)$old['product_id']?$old['unit_cost']:$p['cost']):postedMoneyPence($raw,'unit cost');
   if($received>0&&$cost!==null&&(int)$cost!==(int)$old['unit_cost'])throw new Exception('Keep the original unit cost for a product with received stock.');
   if($received>0)$cost=$old['unit_cost'];
   $line=['product_id'=>$pid,'packs'=>(int)$packs,'pack_size'=>$packSize,'quantity'=>$quantity,'unit_cost'=>$cost];
   if($old)$changes[]=['old'=>$old,'remove'=>false,'line'=>$line];else$new[]=$line;
  }
  if(count($seen)!==count($existing))throw new Exception('Some existing product rows are missing. Reload the order before saving.');
  if(!count($new)&&!count(array_filter($changes,fn($change)=>!$change['remove'])))throw new Exception('Keep at least one product on the supplier order.');
  foreach($changes as $change){
   if($change['remove'])$db->prepare('DELETE FROM supplier_order_items WHERE id=? AND supplier_order_id=? AND received_qty=0')->execute([(int)$change['old']['id'],$id]);
   else{$line=$change['line'];$db->prepare('UPDATE supplier_order_items SET product_id=?,packs=?,pack_size=?,quantity=?,unit_cost=? WHERE id=? AND supplier_order_id=?')->execute([$line['product_id'],$line['packs'],$line['pack_size'],$line['quantity'],$line['unit_cost'],(int)$change['old']['id'],$id]);}
  }
  $q=$db->prepare('INSERT INTO supplier_order_items(supplier_order_id,product_id,packs,pack_size,quantity,unit_cost,received_qty) VALUES(?,?,?,?,?,?,0)');
  foreach($new as $line)$q->execute([$id,$line['product_id'],$line['packs'],$line['pack_size'],$line['quantity'],$line['unit_cost']]);
  $q=$db->prepare('SELECT SUM(quantity) quantity,SUM(received_qty) received FROM supplier_order_items WHERE supplier_order_id=?');$q->execute([$id]);$totals=$q->fetch(PDO::FETCH_ASSOC);
  $status=(int)$totals['received']>=(int)$totals['quantity']?'Received':((int)$totals['received']>0?'Part received':'Ordered');
  $db->prepare('UPDATE supplier_orders SET supplier=?,expected_date=?,note=?,status=? WHERE id=?')->execute([$supplier,$expected,$note,$status,$id]);
  $db->commit();
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
function renderSupplierOrderEditor(PDO $db,array $order,array $lines,array $products):void{
 $renderLine=static function(string $key,?array $line)use($products):void{
  $received=(int)($line['received_qty']??0);$pid=(int)($line['product_id']??0);$prefix='lines['.$key.']';
  ?><div class="supplier-edit-line" data-supplier-edit-line><input type="hidden" name="<?=e($prefix)?>[id]" value="<?=(int)($line['id']??0)?>">
  <label>Product<select name="<?=e($prefix)?>[product_id]" <?=$received>0?'disabled':''?>><option value="">Choose a product to add…</option><?php foreach($products as $p):if(!(int)$p['active']&&(int)$p['id']!==$pid)continue;?><option value="<?=(int)$p['id']?>" <?=(int)$p['id']===$pid?'selected':''?>><?=e($p['name'])?> (<?=(int)$p['supplier_pack_size']?> / pack)</option><?php endforeach;?></select></label>
  <?php if($received>0):?><input type="hidden" name="<?=e($prefix)?>[product_id]" value="<?=$pid?>"><?php endif;?>
  <div class="supplier-edit-numbers"><label>Packs<?=$line?' ('.(int)$line['pack_size'].' units each)':''?><input name="<?=e($prefix)?>[packs]" type="number" inputmode="numeric" min="1" max="999" step="1" value="<?=(int)($line['packs']??1)?>" required></label><label>Cost per unit (£)<input name="<?=e($prefix)?>[unit_cost]" type="number" inputmode="decimal" min="0" max="100000" step=".01" value="<?=isset($line['unit_cost'])?e(number_format((int)$line['unit_cost']/100,2,'.','')):''?>" <?=$received>0?'readonly':''?> placeholder="Use product cost"></label></div>
  <?php if($received>0):?><small class="supplier-edit-received"><?=$received?> units already received · receipt history retained</small><?php elseif($line):?><label class="check supplier-edit-remove"><input type="checkbox" name="<?=e($prefix)?>[remove]" value="1"> Remove this product</label><?php else:?><button type="button" class="quiet" data-remove-edit-line>Remove row</button><?php endif;?></div><?php
 };
 ?><details class="supplier-order-editor" id="supplier-edit-<?=(int)$order['id']?>" <?=((int)($_GET['edit_supplier']??0)===(int)$order['id'])?'open':''?>><summary>Edit incoming order <span aria-hidden="true">✎</span></summary>
 <form method="post" data-supplier-edit-form><?php csrf();?><input type="hidden" name="action" value="supplier_order_edit"><input type="hidden" name="supplier_order_id" value="<?=(int)$order['id']?>"><input type="hidden" name="revision" value="<?=e(supplierEditRevision($order,$lines))?>"><input type="hidden" name="return" value="stock"><input type="hidden" name="return_section" value="incoming">
 <p>Add missed products or correct the packs on this delivery. Saving updates incoming quantities; use Receive / allocate when stock arrives.</p>
 <div class="supplier-edit-numbers"><label>Supplier<input name="supplier" maxlength="160" required value="<?=e($order['supplier'])?>"></label><label>Expected arrival<input name="expected_date" type="date" value="<?=e($order['expected_date'])?>"></label></div>
 <div data-supplier-edit-lines><?php foreach($lines as $line)$renderLine('existing_'.(int)$line['id'],$line);$renderLine('new_initial',null);?></div>
 <template data-supplier-edit-template><?php $renderLine('NEW_KEY',null);?></template><button type="button" class="quick-action" data-add-edit-line>+ Add another product</button>
 <label>Notes<input name="note" maxlength="500" value="<?=e($order['note'])?>"></label>
 <?php $q=$db->prepare('SELECT COUNT(*) FROM supplier_purchase_snapshots WHERE supplier_order_id=?');$q->execute([(int)$order['id']]);if((int)$q->fetchColumn()>0):?><p class="muted">The original supplier quote and currency totals stay as recorded. These corrections update the incoming stock list.</p><?php endif;?>
 <button class="stock-primary-action">Save incoming changes</button></form></details><?php
}
function plannedDeliveryDate(string $value,string $existing='',?DateTimeImmutable $today=null,bool $allowToday=false):string{
 $value=trim($value);if($value==='')return '';
 $tz=new DateTimeZone('Europe/London');$today??=new DateTimeImmutable('today',$tz);
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$tz);
 if(!$date||$date->format('Y-m-d')!==$value)throw new Exception('Choose a valid planned delivery date.');
 $today=$today->setTime(0,0);
 if(($date<$today || (!$allowToday && $date==$today)) && $value!==$existing)throw new Exception($allowToday?'Choose today or a future delivery date.':'Choose a delivery date after today.');
 return $value;
}
function isBackOrder(array $order):bool{return (int)($order['back_order']??0)===1;}
function assertOrderActive(PDO $db,int $id):void{
 $q=$db->prepare('SELECT back_order FROM orders WHERE id=?');$q->execute([$id]);
 if((int)$q->fetchColumn()===1)throw new Exception('Move this back order to active orders before preparing or delivering it.');
}
function deliveryBoardSection(array $order,string $today):string{
 if(isBackOrder($order))return '';
 if(in_array((string)($order['status']??''),['Cancelled','Delivered'],true) || trim((string)($order['delivery_date']??''))!=='')return '';
 $scheduled=trim((string)($order['scheduled_delivery_date']??''));
 // Delivery is independent of payment and preparation. Every active, undated
 // order remains in To deliver until delivery is explicitly confirmed.
 if($scheduled==='')return 'awaiting';
 $tz=new DateTimeZone('Europe/London');$scheduledDate=DateTimeImmutable::createFromFormat('!Y-m-d',$scheduled,$tz);
 $todayDate=DateTimeImmutable::createFromFormat('!Y-m-d',$today,$tz);
 if(!$scheduledDate||$scheduledDate->format('Y-m-d')!==$scheduled||!$todayDate)return '';
 // Today/overdue orders cannot be manually hidden in Future.
 if($scheduledDate<=$todayDate)return 'awaiting';
 $override=(string)($order['delivery_section_override']??'');
 if(in_array($override,['awaiting','future'],true))return $override;
 return (int)$todayDate->diff($scheduledDate)->format('%r%a')<5?'awaiting':'future';
}
function isFutureDelivery(array $order,string $today):bool{
 return deliveryBoardSection($order,$today)==='future';
}
function deliveryCountdown(string $value,?DateTimeImmutable $today=null):string{
 $tz=new DateTimeZone('Europe/London');$today=($today??new DateTimeImmutable('today',$tz))->setTimezone($tz)->setTime(0,0);
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$tz);
 if(!$date||$date->format('Y-m-d')!==$value)return '';
 $days=(int)$today->diff($date)->format('%r%a');
 if($days===0)return 'Today';
 return abs($days).' '.(abs($days)===1?'day':'days').($days<0?' overdue':'');
}
function orderPreparationState(array $order):string{
 if(isBackOrder($order))return 'closed';
 if(in_array((string)($order['status']??''),['Cancelled','Delivered'],true)||trim((string)($order['delivery_date']??''))!=='')return 'closed';
 if((int)($order['packaged']??0)===1)return 'packaged';
 return (int)($order['reconstituted']??0)===1?'reconstituted':'pending';
}
function setOrderPreparation(PDO $db,int $id,string $stage,bool $complete):void{
 assertOrderActive($db,$id);
 if($id<1||!in_array($stage,['reconstituted','packaged'],true))throw new Exception('Choose a valid preparation stage.');
 $db->beginTransaction();
 try{
  $q=$db->prepare('SELECT status,delivery_date,reconstituted,packaged FROM orders WHERE id=?');$q->execute([$id]);$order=$q->fetch(PDO::FETCH_ASSOC);
  if(!$order)throw new Exception('Order could not be found.');
  $state=orderPreparationState($order);
  if($state==='closed')throw new Exception('Preparation cannot be changed on a delivered or cancelled order.');
  if($stage==='packaged'&&$state==='pending')throw new Exception('Mark the order reconstituted before packaging.');
  $packaged=$stage==='packaged'&&$complete?1:0;
  $reconstituted=$packaged?0:($stage==='packaged'?1:($complete?1:0));
  $db->prepare('UPDATE orders SET reconstituted=?,packaged=? WHERE id=?')->execute([$reconstituted,$packaged,$id]);
  $db->commit();
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
function orderActionDate(string $value,string $label):string{
 $value=trim($value);if($value==='')return '';
 $tz=new DateTimeZone('Europe/London');$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$tz);$today=new DateTimeImmutable('today',$tz);
 if(!$date || $date->format('Y-m-d')!==$value)throw new Exception('Choose a valid '.$label.' date.');
 if($date>$today)throw new Exception(ucfirst($label).' date cannot be in the future.');
 return $value;
}
function postedMoneyPence($value,string $label):int{
 $raw=trim((string)$value);if($raw==='')return 0;
 $number=filter_var($raw,FILTER_VALIDATE_FLOAT);
 if($number===false||$number<0||$number>100000)throw new Exception('Check the '.$label.' amount.');
 return (int)round($number*100);
}
function supplyCategories():array{return ['Labels','Printer ink','Envelopes & packaging','Equipment','Other supplies'];}
function productOrdersReportUrl(string $product,string $range,string $from,string $to,string $source='products'):string{
 return '?'.http_build_query(['view'=>'reports','type'=>'productorders','product'=>$product,'range'=>$range,'from'=>$from,'to'=>$to,'source'=>$source==='overview'?'overview':'products']);
}
function supplyDate(string $value,string $label,bool $required=false,bool $future=false):string{
 $value=trim($value);if($value===''&&!$required)return '';
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Europe/London'));
 if(!$date||$date->format('Y-m-d')!==$value)throw new Exception('Enter a valid '.$label.'.');
 if(!$future&&$value>(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d'))throw new Exception(ucfirst($label).' cannot be in the future.');
 return $value;
}
function supplyUrl(string $value):string{
 $value=trim($value);if($value==='')return '';
 if(strlen($value)>2000||!filter_var($value,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($value,PHP_URL_SCHEME)),['http','https'],true))throw new Exception('Use a complete http or https link for the product or tracking.');
 return $value;
}
function saveSupplyCupboard(PDO $db,array $input):int{
 $text=static function(string $key,int $limit=200)use($input):string{$v=trim((string)($input[$key]??''));if(strlen($v)>$limit)throw new Exception('Check the length of '.str_replace('_',' ',$key).'.');return $v;};
 $name=$text('name');$category=$text('category');$level=$text('stock_level');$who=$text('requested_by');
 if($name===''||!in_array($category,supplyCategories(),true)||!in_array($level,['Enough','Running low','Out'],true)||!in_array($who,['','Jay','Tony'],true))throw new Exception('Enter an item name and choose a valid category, stock level and partner.');
 $quantity=filter_var($input['usual_quantity']??1,FILTER_VALIDATE_INT);if($quantity===false||$quantity<1||$quantity>100000)throw new Exception('Enter a usual quantity between 1 and 100,000.');
 $raw=$text('usual_cost');if($raw!==''&&!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/',$raw))throw new Exception('Enter the usual items total in pounds and pence.');
 $cost=$raw===''?null:postedMoneyPence($raw,'usual items total');
 $values=[$name,$category,$text('specification',1000),$text('supplier'),supplyUrl($text('product_url',2000)),$text('backup_supplier'),supplyUrl($text('backup_url',2000)),$quantity,$cost,$level,$text('stock_note',500),$who,$text('notes',4000)];
 $id=(int)($input['id']??0);$now=date('c');
 if($id>0){
  $q=$db->prepare('UPDATE business_supply_items SET name=?,category=?,specification=?,supplier=?,product_url=?,backup_supplier=?,backup_url=?,usual_quantity=?,usual_cost=?,stock_level=?,stock_note=?,requested_by=?,notes=?,updated=?,revision=revision+1 WHERE id=? AND revision=? AND active=1');$q->execute([...$values,$now,$id,(int)($input['revision']??0)]);
  if($q->rowCount()!==1)throw new Exception('This supply changed while you were editing. Refresh and reopen it.');
 }else{
  $key=$text('request_key');if(!preg_match('/^[a-f0-9]{32}$/',$key))throw new Exception('Refresh the supply form and try again.');
  $q=$db->prepare('INSERT INTO business_supply_items(name,category,specification,supplier,product_url,backup_supplier,backup_url,usual_quantity,usual_cost,stock_level,stock_note,requested_by,notes,request_key,created,updated) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(request_key) DO NOTHING');$q->execute([...$values,$key,$now,$now]);
  $q=$db->prepare('SELECT id FROM business_supply_items WHERE request_key=?');$q->execute([$key]);$id=(int)$q->fetchColumn();
 }
 return $id;
}
function changeSupplyCupboard(PDO $db,array $input):void{
 $id=(int)($input['cupboard_id']??0);$revision=(int)($input['cupboard_revision']??0);$operation=(string)($input['operation']??'');
 $q=$db->prepare('SELECT * FROM business_supply_items WHERE id=?');$q->execute([$id]);$item=$q->fetch(PDO::FETCH_ASSOC);
 if(!$item||(int)$item['revision']!==$revision)throw new Exception('This supply changed. Refresh before updating it.');
 if(!(int)$item['active']&&$operation!=='restore')throw new Exception('Restore this supply before updating it.');
 $owner=$item['owner'];$level=$item['stock_level'];$active=1;
 if($operation==='level'){$level=(string)($input['stock_level']??'');if(!in_array($level,['Enough','Running low','Out'],true))throw new Exception('Choose a valid stock level.');}
 elseif($operation==='claim'){
  $owner=(string)($input['owner']??'');if(!in_array($owner,['Jay','Tony'],true))throw new Exception('Choose who is ordering.');
  if($item['owner']!=='')throw new Exception($item['owner'].' is already handling this. Release their claim before assigning it again.');
  $q=$db->prepare("SELECT COUNT(*) FROM business_supplies WHERE cupboard_id=? AND status IN ('Ordered','Dispatched')");$q->execute([$id]);if((int)$q->fetchColumn()>0)throw new Exception('This supply is already on the way. Check its purchase first.');
 }
 elseif($operation==='release')$owner='';
 elseif($operation==='archive'){
  $q=$db->prepare("SELECT COUNT(*) FROM business_supplies WHERE cupboard_id=? AND status IN ('Ordered','Dispatched')");$q->execute([$id]);if((int)$q->fetchColumn()>0||$owner!=='')throw new Exception('Finish or cancel its purchase and release its claim before archiving.');$active=0;
 }elseif($operation==='restore')$active=1;
 else throw new Exception('Choose a valid supply action.');
 $q=$db->prepare("UPDATE business_supply_items SET stock_level=?,owner=?,active=?,stock_note=CASE WHEN ?='level' THEN '' ELSE stock_note END,revision=revision+1,updated=? WHERE id=? AND revision=?");$q->execute([$level,$owner,$active,$operation,date('c'),$id,$revision]);if($q->rowCount()!==1)throw new Exception('This supply changed. Refresh before updating it.');
}
function receiveBusinessSupply(PDO $db,array $input):void{
 $db->beginTransaction();try{
  $q=$db->prepare('SELECT * FROM business_supplies WHERE id=?');$q->execute([(int)($input['purchase_id']??0)]);$p=$q->fetch(PDO::FETCH_ASSOC);
  if(!$p||(int)$p['revision']!==(int)($input['purchase_revision']??0)||!in_array($p['status'],['Ordered','Dispatched'],true))throw new Exception('This purchase changed. Refresh before checking it in.');
  $received=filter_var($input['received_quantity']??'',FILTER_VALIDATE_INT);$remaining=(int)$p['quantity']-(int)$p['received_quantity'];
  if($received===false||$received<1||$received>$remaining)throw new Exception('Check the quantity received against the amount still due.');
  $total=(int)$p['received_quantity']+$received;$status=$total===(int)$p['quantity']?'Received':'Dispatched';$now=date('c');
  $db->prepare('UPDATE business_supplies SET received_quantity=?,status=?,revision=revision+1,updated=? WHERE id=?')->execute([$total,$status,$now,(int)$p['id']]);
  if($p['cupboard_id']){
   $level=(string)($input['stock_level']??'');$note=trim((string)($input['stock_note']??''));if(!in_array($level,['Enough','Running low','Out'],true)||strlen($note)>500)throw new Exception('Choose the cupboard level after this delivery and a short stock note.');
   $q=$db->prepare("SELECT COUNT(*) FROM business_supplies WHERE cupboard_id=? AND status IN ('Ordered','Dispatched')");$q->execute([(int)$p['cupboard_id']]);$open=(int)$q->fetchColumn();
   $q=$db->prepare('UPDATE business_supply_items SET stock_level=?,stock_note=?,owner=CASE WHEN CAST(? AS INTEGER)=0 THEN \'\' ELSE owner END,revision=revision+1,updated=? WHERE id=? AND revision=?');$q->execute([$level,$note,$open,$now,(int)$p['cupboard_id'],(int)($input['cupboard_revision']??0)]);
   if($q->rowCount()!==1)throw new Exception('The cupboard entry changed. Refresh before checking in this delivery.');
  }
  $db->commit();
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
function persistBusinessSupply(PDO $db,array $input):int{
 $text=static function(string $key,int $limit=200)use($input):string{$value=trim((string)($input[$key]??''));if(strlen($value)>$limit)throw new Exception('The '.str_replace('_',' ',$key).' is too long.');return $value;};
 $item=$text('item');$supplier=$text('supplier');$category=$text('category');$status=$text('status');$payment=$text('payment_status');
 if($item===''||$supplier==='')throw new Exception('Enter what you ordered and the supplier.');
 if(!in_array($category,supplyCategories(),true)||!in_array($status,['Ordered','Dispatched','Received','Cancelled'],true)||!in_array($payment,['Unpaid','Paid','Refunded'],true))throw new Exception('Choose a valid category, delivery status and payment status.');
 $quantity=filter_var($input['quantity']??'',FILTER_VALIDATE_INT);if($quantity===false||$quantity<1||$quantity>100000)throw new Exception('Enter a quantity between 1 and 100,000.');
 foreach(['item_cost','shipping'] as $field)if(!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/',trim((string)($input[$field]??''))))throw new Exception('Enter the '.str_replace('_',' ',$field).' in pounds and pence.');
 $cost=postedMoneyPence($input['item_cost'],'items total');$shipping=postedMoneyPence($input['shipping'],'delivery');
 $ordered=supplyDate($text('ordered_date'),'order date',true);$expected=supplyDate($text('expected_date'),'expected delivery date',false,true);
 $paid=$payment==='Unpaid'?'':supplyDate($text('paid_date'),'payment date',true);
 $refunded=$payment==='Refunded'?supplyDate($text('refunded_date'),'refund date',true):'';
 if($refunded!==''&&$refunded<$paid)throw new Exception('Refund date cannot be before payment date.');
 $values=[$item,$category,$quantity,$supplier,$text('reference'),$ordered,$expected,$status,$cost,$shipping,$payment,$paid,$refunded,supplyUrl($text('product_url',2000)),$text('tracking_reference'),supplyUrl($text('tracking_url',2000)),$text('notes',4000)];
 $id=(int)($input['id']??0);$now=date('c');
 if($id>0){
  $q=$db->prepare('UPDATE business_supplies SET item=?,category=?,quantity=?,supplier=?,reference=?,ordered_date=?,expected_date=?,status=?,item_cost=?,shipping=?,payment_status=?,paid_date=?,refunded_date=?,product_url=?,tracking_reference=?,tracking_url=?,notes=?,updated=?,revision=revision+1 WHERE id=? AND revision=?');
  $q->execute([...$values,$now,$id,(int)($input['revision']??0)]);
  if($q->rowCount()!==1)throw new Exception('This purchase changed while you were editing. Refresh and reopen it before saving.');
 }else{
  $key=(string)($input['request_key']??'');if(!preg_match('/^[a-f0-9]{32}$/',$key))throw new Exception('Please refresh the purchase form and try again.');
  $q=$db->prepare('INSERT INTO business_supplies(item,category,quantity,supplier,reference,ordered_date,expected_date,status,item_cost,shipping,payment_status,paid_date,refunded_date,product_url,tracking_reference,tracking_url,notes,request_key,created,updated) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(request_key) DO NOTHING');
  $q->execute([...$values,$key,$now,$now]);
  $q=$db->prepare('SELECT id FROM business_supplies WHERE request_key=?');$q->execute([$key]);$id=(int)$q->fetchColumn();
 }
 return $id;
}
function saveBusinessSupply(PDO $db,array $input):int{
 $db->beginTransaction();try{
  $id=(int)($input['id']??0);$existing=null;$duplicate=false;
  if($id>0){$q=$db->prepare('SELECT * FROM business_supplies WHERE id=?');$q->execute([$id]);$existing=$q->fetch(PDO::FETCH_ASSOC);if(!$existing)throw new Exception('Purchase not found.');}
  else{$q=$db->prepare('SELECT * FROM business_supplies WHERE request_key=?');$q->execute([(string)($input['request_key']??'')]);$duplicate=$q->fetch(PDO::FETCH_ASSOC);if($duplicate){$db->commit();return (int)$duplicate['id'];}}
  $cupboardId=(int)($existing['cupboard_id']??($input['cupboard_id']??0));
  if($existing&&(int)($input['quantity']??0)<(int)$existing['received_quantity'])throw new Exception('Quantity cannot be less than the amount already received.');
  if($existing&&(int)$existing['received_quantity']>0&&(int)($input['quantity']??0)===(int)$existing['received_quantity']&&in_array($input['status']??'',['Ordered','Dispatched'],true))throw new Exception('All of this purchase has arrived. Keep it Received or increase the ordered quantity.');
  $cupboard=null;
  if($cupboardId>0){
   $q=$db->prepare('SELECT * FROM business_supply_items WHERE id=?');$q->execute([$cupboardId]);$cupboard=$q->fetch(PDO::FETCH_ASSOC);if(!$cupboard||(!$existing&&!(int)$cupboard['active']))throw new Exception('This cupboard item is unavailable.');
   if(!$existing){
    if((int)$cupboard['revision']!==(int)($input['cupboard_revision']??0))throw new Exception('This supply changed. Refresh and reopen Record purchase.');
    $q=$db->prepare("SELECT COUNT(*) FROM business_supplies WHERE cupboard_id=? AND status IN ('Ordered','Dispatched')");$q->execute([$cupboardId]);if((int)$q->fetchColumn()>0)throw new Exception('This supply is already on the way. Open its existing purchase before ordering again.');
   }
  }
  $id=persistBusinessSupply($db,$input);
  $status=(string)$input['status'];$received=$status==='Received'?(int)$input['quantity']:(int)($existing['received_quantity']??0);
  $db->prepare('UPDATE business_supplies SET cupboard_id=?,received_quantity=? WHERE id=?')->execute([$cupboardId?:null,$received,$id]);
  if($cupboard){
   $q=$db->prepare("SELECT COUNT(*) FROM business_supplies WHERE cupboard_id=? AND status IN ('Ordered','Dispatched')");$q->execute([$cupboardId]);$open=(int)$q->fetchColumn();
   $db->prepare("UPDATE business_supply_items SET owner=CASE WHEN CAST(? AS INTEGER)=0 THEN '' ELSE owner END,revision=revision+1,updated=? WHERE id=?")->execute([$open,date('c'),$cupboardId]);
  }
  $db->commit();return $id;
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
function saveSupplyItemWithSource(PDO $db,array $input):int{
 $db->beginTransaction();try{
  $source=(int)($input['source_purchase_id']??0);$purchase=null;
  if($source>0){$q=$db->prepare('SELECT * FROM business_supplies WHERE id=?');$q->execute([$source]);$purchase=$q->fetch(PDO::FETCH_ASSOC);if(!$purchase||$purchase['cupboard_id']||(int)$purchase['revision']!==(int)($input['source_revision']??0))throw new Exception('This purchase changed or already has a cupboard item. Refresh before saving it.');}
  $id=saveSupplyCupboard($db,$input);
  if($purchase)$db->prepare('UPDATE business_supplies SET cupboard_id=?,revision=revision+1,updated=? WHERE id=?')->execute([$id,date('c'),$source]);
  $db->commit();return $id;
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
function supplySpending(array $rows,string $from='',string $to=''):array{
 $result=['paid'=>0,'refunded'=>0,'net'=>0,'unpaid'=>0,'ordered'=>0,'events'=>[]];
 $inRange=static fn(string $date):bool=>$date!==''&&($from===''||$date>=$from)&&($to===''||$date<=$to);
 foreach($rows as $row){
  $total=(int)$row['item_cost']+(int)$row['shipping'];
  if($row['status']!=='Cancelled'&&$inRange($row['ordered_date'])){$result['ordered']+=$total;if($row['payment_status']==='Unpaid')$result['unpaid']+=$total;}
  // A cancelled purchase is still a cash outflow until a refund is recorded.
  if($row['payment_status']!=='Unpaid'&&$inRange($row['paid_date'])){$result['paid']+=$total;$result['events'][]=$row+['event'=>'Payment','event_date'=>$row['paid_date'],'amount'=>$total];}
  if($row['payment_status']==='Refunded'&&$inRange($row['refunded_date'])){$result['refunded']+=$total;$result['events'][]=$row+['event'=>'Refund','event_date'=>$row['refunded_date'],'amount'=>-$total];}
 }
 $result['net']=$result['paid']-$result['refunded'];
 usort($result['events'],static fn($a,$b)=>strcmp($b['event_date'],$a['event_date'])?:$b['id']<=>$a['id']);return $result;
}
function orderTotalPence(PDO $db,int $orderId):int{
 $q=$db->prepare("SELECT COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0) FROM orders o WHERE o.id=?");$q->execute([$orderId]);$v=$q->fetchColumn();
 if($v===false)throw new Exception('Order could not be found.');return (int)$v;
}
function orderPaidPence(PDO $db,int $orderId):int{
 $q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=?');$q->execute([$orderId]);return (int)$q->fetchColumn();
}
function orderBalancePence(PDO $db,int $orderId):int{return max(0,orderTotalPence($db,$orderId)-orderPaidPence($db,$orderId));}
function refreshOrderPaymentState(PDO $db,int $orderId):void{
 $total=orderTotalPence($db,$orderId);$paid=orderPaidPence($db,$orderId);$balance=max(0,$total-$paid);
 $q=$db->prepare('SELECT status FROM orders WHERE id=?');$q->execute([$orderId]);$status=(string)$q->fetchColumn();
 if($balance<=0 && in_array($status,['New','Awaiting payment'],true)){
  $last=$db->prepare('SELECT method,created FROM payments WHERE order_id=? ORDER BY datetime(created) DESC,id DESC LIMIT 1');$last->execute([$orderId]);$p=$last->fetch(PDO::FETCH_ASSOC)?:[];
  $date=!empty($p['created'])?date('Y-m-d',strtotime((string)$p['created'])):date('Y-m-d');
  $db->prepare("UPDATE orders SET status='Paid',payment_date=?,payment_method=CASE WHEN ?<>'' THEN ? ELSE payment_method END WHERE id=?")->execute([$date,(string)($p['method']??''),(string)($p['method']??''),$orderId]);
 }elseif($balance>0 && $status==='Paid'){
  $db->prepare("UPDATE orders SET status='Awaiting payment',payment_date='' WHERE id=?")->execute([$orderId]);
 }elseif($balance>0 && $status==='New'){
  $db->prepare("UPDATE orders SET status='Awaiting payment' WHERE id=?")->execute([$orderId]);
 }
}
function stockLocationName(string $location):string{
 $key=strtolower(trim($location));
 if(in_array($key,['jay','james',"jay's fridge"],true))return 'Jay';
 if(in_array($key,['tony',"tony's fridge"],true))return 'Tony';
 return 'Unallocated';
}
function stockLocationForAssignee(string $assignee):string{
 $key=strtolower(trim($assignee));
 if(in_array($key,['jay','james'],true))return 'Jay';
 if($key==='tony')return 'Tony';
 return 'Unallocated';
}
function stockLocationColumn(string $location):string{
 return match(stockLocationName($location)){'Jay'=>'stock_jay_qty','Tony'=>'stock_tony_qty',default=>'stock_unallocated_qty'};
}
function stockReservationShortages(array $products,array $reservedByProduct,array $reservationDetails):array{
 $shortages=[];
 foreach($products as $product){
  if((int)($product['stock_tracking']??0)!==1)continue;
  $productId=(int)$product['id'];
  foreach(['Jay'=>'stock_jay_qty','Tony'=>'stock_tony_qty','Unallocated'=>'stock_unallocated_qty'] as $location=>$column){
   $reserved=max(0,(int)($reservedByProduct[$productId][strtolower($location)]??0));
   if($reserved===0)continue;
   // Available = physical minus reserved; a negative balance describes a shortage, not extra stock.
   $physical=max(0,(int)($product[$column]??0)+$reserved);
   if($physical>=$reserved)continue;
   $affected=array_values(array_filter($reservationDetails[$productId]??[],fn($order)=>$order['location']===$location));
   $otherLocation=$location==='Jay'?'Tony':($location==='Tony'?'Jay':null);
   $otherColumn=$otherLocation===null?null:stockLocationColumn($otherLocation);
   // Available balances have already excluded the OTHER fridge's own reserved orders.
   $otherAvailable=$otherColumn===null?0:max(0,(int)($product[$otherColumn]??0));
   $missing=$reserved-$physical;
   $shortages[]=[
    'product_id'=>$productId,'name'=>(string)$product['name'],'location'=>$location,
    'location_owner'=>$location==='Jay'?'James':($location==='Tony'?'Tony':'Holding stock'),
    'physical'=>$physical,'reserved'=>$reserved,'missing'=>$missing,'orders'=>$affected,
    'other_location'=>$otherLocation,'other_owner'=>$otherLocation==='Jay'?'James':($otherLocation==='Tony'?'Tony':null),
    'other_available'=>$otherAvailable,'transferable'=>min($missing,$otherAvailable),
    'remaining_after_transfer'=>max(0,$missing-$otherAvailable)
   ];
  }
 }
 usort($shortages,fn($a,$b)=>$b['missing']<=>$a['missing'] ?: strcasecmp($a['name'],$b['name']) ?: strcmp($a['location'],$b['location']));
 return $shortages;
}
function activeReservedStock(PDO $db,int $productId,string $location):int{
 $q=$db->prepare("SELECT COALESCE(SUM(sf.quantity),0) FROM stock_fulfilments sf JOIN orders o ON o.id=sf.order_id WHERE sf.product_id=? AND sf.location=? AND o.back_order=0 AND o.status NOT IN ('Cancelled','Dispatched','Delivered') AND trim(COALESCE(o.delivery_date,''))=''");
 $q->execute([$productId,stockLocationName($location)]);return max(0,(int)$q->fetchColumn());
}
function setProductStockAtLocation(PDO $db,int $productId,string $location,int $quantity):void{
 $column=stockLocationColumn($location);
 // SQLite evaluates all SET expressions from the row's pre-update values. Updating the
 // fridge column and legacy total in one statement therefore made stock_qty lag one save behind.
 $db->prepare("UPDATE products SET {$column}=? WHERE id=?")->execute([$quantity,$productId]);
 $db->prepare("UPDATE products SET stock_qty=COALESCE(stock_jay_qty,0)+COALESCE(stock_tony_qty,0)+COALESCE(stock_unallocated_qty,0) WHERE id=?")->execute([$productId]);
}
function adjustProductStockAtLocation(PDO $db,int $productId,string $location,int $delta):void{
 $column=stockLocationColumn($location);$q=$db->prepare("SELECT {$column} FROM products WHERE id=?");$q->execute([$productId]);$current=$q->fetchColumn();
 if($current===false)throw new Exception('Product could not be found while updating stock.');
 setProductStockAtLocation($db,$productId,$location,(int)$current+$delta);
}
function addStockMovement(PDO $db,int $productId,string $type,int $delta,string $note='',?int $orderId=null,string $supplier='',string $batch='',string $expiry='',?int $unitCost=null,string $location='Unallocated'):void{
 if($delta===0&&$type!=='opening')return;
 $q=$db->prepare('INSERT INTO stock_movements(product_id,movement_type,quantity_change,order_id,location,supplier,batch_reference,expiry_date,unit_cost,note,created) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
 $q->execute([$productId,$type,$delta,$orderId,stockLocationName($location),$supplier,$batch,$expiry,$unitCost,$note,gmdate('c')]);
}
function rememberStockUndo(int $productId,array $changes,array $movementIds,bool $trackingBefore,string $movementType,string $label):void{
 $_SESSION['stock_undo']=['product_id'=>$productId,'changes'=>$changes,'movement_ids'=>$movementIds,'tracking_before'=>$trackingBefore,'movement_type'=>$movementType,'label'=>$label,'expires'=>time()+120];
}
function reconcileDeliveredStock(PDO $db,int $orderId,bool $createEvent=false):void{
 $ownsTransaction=!$db->inTransaction();if($ownsTransaction)$db->beginTransaction();
 try{
  if($createEvent){
   $q=$db->prepare('SELECT delivery_date,status FROM orders WHERE id=?');$q->execute([$orderId]);$eventOrder=$q->fetch(PDO::FETCH_ASSOC);
   if(!$eventOrder)throw new Exception('Order could not be found for stock update.');
   if($eventOrder['status']==='Cancelled')throw new Exception('A cancelled order cannot reduce stock.');
   $deliveryDate=(string)($eventOrder['delivery_date']?:date('Y-m-d'));
   $db->prepare('INSERT OR IGNORE INTO stock_delivery_events(order_id,recorded_at,delivery_date) VALUES (?,?,?)')->execute([$orderId,gmdate('c'),$deliveryDate]);
  }
  $q=$db->prepare('SELECT status,assigned_to,created,back_order,activated_at FROM orders WHERE id=?');$q->execute([$orderId]);$order=$q->fetch(PDO::FETCH_ASSOC);
  if(!$order)throw new Exception('Order could not be found for stock update.');
  $orderLocation=stockLocationForAssignee((string)($order['assigned_to']??''));
  $desired=[];
  if((string)$order['status']!=='Cancelled'&&!isBackOrder($order)){
   $q=$db->prepare("SELECT COALESCE(i.product_id,(SELECT p.id FROM products p WHERE lower(trim(p.name))=lower(trim(i.name)) ORDER BY p.id LIMIT 1)) AS product_id,SUM(i.quantity) AS quantity FROM items i WHERE i.order_id=? GROUP BY product_id");$q->execute([$orderId]);
   foreach($q->fetchAll(PDO::FETCH_ASSOC) as $line){
    $productId=(int)($line['product_id']??0);if($productId<1)continue;
    $p=$db->prepare('SELECT stock_tracking,stock_tracking_since FROM products WHERE id=?');$p->execute([$productId]);$product=$p->fetch(PDO::FETCH_ASSOC);
    if(!$product||(int)$product['stock_tracking']!==1)continue;
    $since=substr((string)$product['stock_tracking_since'],0,10);$orderDay=(new DateTimeImmutable((string)($order['activated_at']?:$order['created'])))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d');
    if($since!==''&&$orderDay!==''&&$orderDay<$since)continue;
    $desired[$productId]=['quantity'=>(int)$line['quantity'],'location'=>$orderLocation];
   }
  }
  $q=$db->prepare('SELECT product_id,quantity,location FROM stock_fulfilments WHERE order_id=?');$q->execute([$orderId]);$previous=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$previous[(int)$row['product_id']]=['quantity'=>(int)$row['quantity'],'location'=>stockLocationName((string)($row['location']??'Unallocated'))];
  foreach(array_unique(array_merge(array_keys($previous),array_keys($desired))) as $productId){
   $before=$previous[$productId]['quantity']??0;$after=$desired[$productId]['quantity']??0;
   $beforeLocation=$previous[$productId]['location']??$orderLocation;$afterLocation=$desired[$productId]['location']??$beforeLocation;
   $deltas=[];
   if($before>0)$deltas[$beforeLocation]=($deltas[$beforeLocation]??0)+$before;
   if($after>0)$deltas[$afterLocation]=($deltas[$afterLocation]??0)-$after;
   foreach($deltas as $location=>$delta){
    if($delta===0)continue;
    adjustProductStockAtLocation($db,(int)$productId,$location,$delta);
    $movementType=$before===0?'order_reserved':($after===0?'order_release':'order_edit');
    $movementNote=$before===0?'Order saved; stock reserved':($after===0?'Order reservation released':'Order stock reservation updated');
    addStockMovement($db,(int)$productId,$movementType,$delta,$movementNote,$orderId,'','','',null,$location);
   }
   if($after>0)$db->prepare('INSERT INTO stock_fulfilments(order_id,product_id,quantity,location) VALUES (?,?,?,?) ON CONFLICT(order_id,product_id) DO UPDATE SET quantity=excluded.quantity,location=excluded.location')->execute([$orderId,$productId,$after,$afterLocation]);
   else$db->prepare('DELETE FROM stock_fulfilments WHERE order_id=? AND product_id=?')->execute([$orderId,$productId]);
  }
  if($ownsTransaction)$db->commit();
 }catch(Throwable $ex){if($ownsTransaction&&$db->inTransaction())$db->rollBack();throw $ex;}
}
function isRetaProductName(string $name):bool{return str_starts_with(strtolower(trim($name)),'retatrutide');}
function defaultCycleWeeksForProduct(string $name):int{
 $name=strtolower(trim($name));if(str_starts_with($name,'retatrutide'))return 4;if(str_starts_with($name,'ghk-cu')||str_starts_with($name,'ghk cu'))return 8;return 4;
}
function cycleOrderLines(PDO $db,int $orderId):array{
 $q=$db->prepare('SELECT id,name,price,cost,presentation,presentation_cost,base_price,discount,quantity,recurring,cycle_weeks FROM items WHERE order_id=? ORDER BY id');$q->execute([$orderId]);
 return $q->fetchAll(PDO::FETCH_ASSOC);
}
function cycleRecipientName(array $cycle):string{
 $recipient=trim((string)($cycle['recipient_name']??''));return $recipient!==''?$recipient:(string)($cycle['customer_name']??'');
}
function syncProductCyclesFromDeliveredOrder(PDO $db,int $orderId,bool $forceHistorical=false):void{
 $q=$db->prepare('SELECT customer,recipient_name,phone,created,delivery_date,status FROM orders WHERE id=?');$q->execute([$orderId]);$order=$q->fetch(PDO::FETCH_ASSOC);if(!$order)return;
 $deliveryDate=trim((string)($order['delivery_date']??''));$now=gmdate('c');
 if($deliveryDate===''){
  $db->prepare("UPDATE reta_cycles SET active=0,ended_at=?,end_reason='Waiting for delivery date',updated=? WHERE active=1 AND last_order_id=?")->execute([$now,$now,$orderId]);return;
 }
 $lines=cycleOrderLines($db,$orderId);$recurring=[];
 foreach($lines as $line){
  $name=(string)$line['name'];if(strtolower(trim($name))==='pen')continue;
  $isRecurring=(int)($line['recurring']??0)===1;
  if(!$isRecurring)continue;
  $weeks=(int)($line['cycle_weeks']??0);if($weeks<1||$weeks>52)$weeks=defaultCycleWeeksForProduct($name);
  $line['cycle_weeks']=$weeks;$recurring[]=$line;
 }
 $currentIds=[];$orderDate=date('Y-m-d',strtotime((string)$order['created']));$tz=new DateTimeZone('Europe/London');
 $penRows=array_values(array_filter($lines,fn($l)=>strtolower(trim((string)$l['name']))==='pen'));
 $penUnits=array_sum(array_map(fn($l)=>(int)$l['quantity'],$penRows));$penRevenue=array_sum(array_map(fn($l)=>(int)$l['price']*(int)$l['quantity'],$penRows));
 $penCost=0;$penCostMissing=false;foreach($penRows as $pen){if($pen['cost']===null)$penCostMissing=true;else$penCost+=(int)$pen['cost']*(int)$pen['quantity'];}
 $avgPenRevenue=$penUnits>0?(int)round($penRevenue/$penUnits):0;$avgPenCost=$penUnits>0?(int)round($penCost/$penUnits):0;

 foreach($recurring as $line){
  $name=(string)$line['name'];$qty=max(1,(int)$line['quantity']);$presentation=(string)$line['presentation'];$weeks=(int)$line['cycle_weeks'];$days=$weeks*7;
  $value=(int)$line['price']*$qty;$cost=0;$costMissing=false;
  if($line['cost']===null)$costMissing=true;else$cost+=(int)$line['cost']*$qty;
  if((int)($line['presentation_cost']??0)>0)$cost+=(int)$line['presentation_cost']*$qty;
  $summary=$qty.' × '.$name.($presentation!==''?' · '.$presentation:'');
  if($presentation==='Pen'){
   $value+=$avgPenRevenue*$qty;
   if($penUnits>0){$cost+=$avgPenCost*$qty;if($penCostMissing)$costMissing=true;$summary.=' + '.$qty.' × Pen';}
  }
  $profit=$value-$cost;$due=(new DateTimeImmutable($deliveryDate,$tz))->modify('+'.$weeks.' weeks')->format('Y-m-d');
  $recipient=trim((string)($order['recipient_name']??''));
  $q=$db->prepare("SELECT * FROM reta_cycles WHERE active=1 AND lower(trim(customer_name))=lower(trim(?)) AND trim(phone)=trim(?) AND lower(trim(product_name))=lower(trim(?)) AND lower(trim(CASE WHEN trim(COALESCE(recipient_name,''))='' THEN customer_name ELSE recipient_name END))=lower(trim(?)) ORDER BY id DESC LIMIT 1");
  $q->execute([(string)$order['customer'],(string)$order['phone'],$name,$recipient!==''?$recipient:(string)$order['customer']]);$existing=$q->fetch(PDO::FETCH_ASSOC);
  if($existing){
   $existingDelivery=trim((string)($existing['last_delivery_date']??''));if($existingDelivery!==''&&$existingDelivery>$deliveryDate){$currentIds[]=(int)$existing['id'];continue;}
   $db->prepare("UPDATE reta_cycles SET customer_name=?,recipient_name=?,phone=?,last_order_id=?,last_order_date=?,last_delivery_date=?,next_due_date=?,expected_value=?,expected_cost=?,expected_profit=?,cost_missing=?,product_summary=?,product_name=?,presentation=?,quantity=?,cycle_days=?,cycle_weeks=?,updated=?,ended_at='',end_reason='' WHERE id=?")
    ->execute([(string)$order['customer'],$recipient,(string)$order['phone'],$orderId,$orderDate,$deliveryDate,$due,$value,$cost,$profit,$costMissing?1:0,$summary,$name,$presentation,$qty,$days,$weeks,$now,(int)$existing['id']]);
   $currentIds[]=(int)$existing['id'];
  }else{
   $db->prepare("INSERT INTO reta_cycles(customer_name,recipient_name,phone,source_order_id,last_order_id,last_order_date,last_delivery_date,next_due_date,expected_value,expected_cost,expected_profit,cost_missing,product_summary,product_name,presentation,quantity,cycle_days,cycle_weeks,active,created,updated) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)")
    ->execute([(string)$order['customer'],$recipient,(string)$order['phone'],$orderId,$orderId,$orderDate,$deliveryDate,$due,$value,$cost,$profit,$costMissing?1:0,$summary,$name,$presentation,$qty,$days,$weeks,$now,$now]);
   $currentIds[]=(int)$db->lastInsertId();
  }
 }
 $q=$db->prepare("SELECT id FROM reta_cycles WHERE active=1 AND last_order_id=?");$q->execute([$orderId]);
 foreach($q->fetchAll(PDO::FETCH_COLUMN) as $cycleId){
  if(in_array((int)$cycleId,$currentIds,true))continue;
  $db->prepare("UPDATE reta_cycles SET active=0,ended_at=?,end_reason='Recurring option removed from order',updated=? WHERE id=?")->execute([$now,$now,(int)$cycleId]);
 }
}
function syncRetaCycleFromDeliveredOrder(PDO $db,int $orderId,bool $forceHistorical=false):void{syncProductCyclesFromDeliveredOrder($db,$orderId,$forceHistorical);}
function retaProjection(array $cycles,DateTimeImmutable $today,int $days):array{
 $end=$today->modify('+'.max(0,$days-1).' days');$orders=0;$revenue=0;$profit=0;$costMissing=0;$stock=[];
 foreach($cycles as $cycle){
  $due=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycle['next_due_date'],$today->getTimezone());if(!$due)continue;
  $interval=max(7,(int)($cycle['cycle_days']??((int)($cycle['cycle_weeks']??4)*7)));
  if($due<$today)$due=$today;
  while($due<=$end){
   $orders++;$revenue+=(int)$cycle['expected_value'];$profit+=(int)$cycle['expected_profit'];if((int)$cycle['cost_missing'])$costMissing++;
   $name=(string)($cycle['product_name']?:$cycle['product_summary']);$qty=max(1,(int)($cycle['quantity']??1));$stock[$name]=($stock[$name]??0)+$qty;
   if((string)($cycle['presentation']??'')==='Pen')$stock['Pen']=($stock['Pen']??0)+$qty;
   $due=$due->modify('+'.$interval.' days');
  }
 }
 return ['orders'=>$orders,'revenue'=>$revenue,'profit'=>$profit,'cost_missing'=>$costMissing,'stock'=>$stock];
}
function retaProjectionRange(array $cycles,DateTimeImmutable $start,DateTimeImmutable $end,bool $includeOverdue=false):array{
 $start=$start->setTime(0,0);$end=$end->setTime(0,0);$orders=0;$revenue=0;$profit=0;$costMissing=0;$stock=[];$occurrences=[];
 if($end<$start)return ['orders'=>0,'revenue'=>0,'profit'=>0,'cost_missing'=>0,'stock'=>[],'occurrences'=>[]];
 foreach($cycles as $cycle){
  $due=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycle['next_due_date'],$start->getTimezone());if(!$due)continue;$originalDue=$due;
  $interval=max(7,(int)($cycle['cycle_days']??((int)($cycle['cycle_weeks']??4)*7)));$wasOverdue=$due<$start;
  if($wasOverdue && $includeOverdue){$due=$start;}else{while($due<$start)$due=$due->modify('+'.$interval.' days');}
  $occurrenceIndex=0;
  while($due<=$end && $occurrenceIndex<60){
   $orders++;$revenue+=(int)$cycle['expected_value'];$profit+=(int)$cycle['expected_profit'];if((int)$cycle['cost_missing'])$costMissing++;
   $name=(string)($cycle['product_name']?:$cycle['product_summary']);$qty=max(1,(int)($cycle['quantity']??1));$stock[$name]=($stock[$name]??0)+$qty;
   if((string)($cycle['presentation']??'')==='Pen')$stock['Pen']=($stock['Pen']??0)+$qty;
   $copy=$cycle;$copy['occurrence_date']=$due->format('Y-m-d');$copy['occurrence_index']=$occurrenceIndex;$copy['was_overdue']=$wasOverdue&&$includeOverdue&&$occurrenceIndex===0;$copy['is_next_occurrence']=($due->format('Y-m-d')===$originalDue->format('Y-m-d'))||($wasOverdue&&$includeOverdue&&$occurrenceIndex===0);$occurrences[]=$copy;
   $due=$due->modify('+'.$interval.' days');$occurrenceIndex++;
  }
 }
 usort($occurrences,fn($a,$b)=>strcmp((string)$a['occurrence_date'],(string)$b['occurrence_date'])?:strcmp((string)$a['customer_name'],(string)$b['customer_name']));
 return ['orders'=>$orders,'revenue'=>$revenue,'profit'=>$profit,'cost_missing'=>$costMissing,'stock'=>$stock,'occurrences'=>$occurrences];
}
function forecastDateRange(DateTimeImmutable $today,string $range,string $from='',string $to='',array $quick=[7,14,30]):array{
 $range=strtolower(trim($range));$days=(int)$range;
 if(in_array($days,$quick,true))return ['mode'=>(string)$days,'start'=>$today->setTime(0,0),'end'=>$today->setTime(0,0)->modify('+'.max(0,$days-1).' days'),'label'=>'Next '.$days.' days','include_overdue'=>true];
 if($range==='custom'){
  $tz=$today->getTimezone();$start=DateTimeImmutable::createFromFormat('!Y-m-d',$from,$tz);$end=DateTimeImmutable::createFromFormat('!Y-m-d',$to,$tz);
  if($start&&$end&&$start->format('Y-m-d')===$from&&$end->format('Y-m-d')===$to&&$end>=$start&&$end<=$start->modify('+365 days'))return ['mode'=>'custom','start'=>$start,'end'=>$end,'label'=>$start->format('j M').' – '.$end->format('j M Y'),'include_overdue'=>$start->format('Y-m-d')===$today->format('Y-m-d')];
 }
 $default=in_array(30,$quick,true)?30:$quick[0];return ['mode'=>(string)$default,'start'=>$today->setTime(0,0),'end'=>$today->setTime(0,0)->modify('+'.($default-1).' days'),'label'=>'Next '.$default.' days','include_overdue'=>true];
}
function cycleOccurrences(array $cycles,DateTimeImmutable $today,int $weeks):array{
 $end=$today->modify('+'.max(1,$weeks).' weeks');$rows=[];
 foreach($cycles as $cycle){
  $due=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycle['next_due_date'],$today->getTimezone());if(!$due)continue;
  $interval=max(7,(int)($cycle['cycle_days']??((int)($cycle['cycle_weeks']??4)*7)));$guard=0;
  while($due<$today && $guard++<60)$due=$due->modify('+'.$interval.' days');
  while($due<=$end && $guard++<100){$copy=$cycle;$copy['occurrence_date']=$due->format('Y-m-d');$rows[]=$copy;$due=$due->modify('+'.$interval.' days');}
 }
 usort($rows,fn($a,$b)=>strcmp((string)$a['occurrence_date'],(string)$b['occurrence_date'])?:strcmp((string)$a['customer_name'],(string)$b['customer_name']));
 return $rows;
}



if(setting($db,'cycle_planner_migration_v1')!=='done'){
 $legacy=$db->query("SELECT * FROM reta_cycles WHERE last_order_id IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
 foreach($legacy as $cycle){
  $orderId=(int)($cycle['last_order_id']??0);if($orderId<1)continue;
  $weeks=max(1,(int)round(max(7,(int)($cycle['cycle_days']??28))/7));
  $q=$db->prepare("SELECT id,name,presentation,quantity FROM items WHERE order_id=? AND lower(name) LIKE 'retatrutide%' ORDER BY id");$q->execute([$orderId]);$retaItems=$q->fetchAll(PDO::FETCH_ASSOC);
  foreach($retaItems as $idx=>$item){
   $db->prepare('UPDATE items SET recurring=1,cycle_weeks=? WHERE id=?')->execute([$weeks,(int)$item['id']]);
   if($idx===0 && trim((string)($cycle['product_name']??''))==='')$db->prepare('UPDATE reta_cycles SET product_name=?,presentation=?,quantity=?,cycle_weeks=? WHERE id=?')->execute([(string)$item['name'],(string)$item['presentation'],(int)$item['quantity'],$weeks,(int)$cycle['id']]);
  }
  $q=$db->prepare('SELECT delivery_date FROM orders WHERE id=?');$q->execute([$orderId]);$delivery=(string)($q->fetchColumn()?:'');if($delivery!=='')syncProductCyclesFromDeliveredOrder($db,$orderId,true);
 }
 saveSetting($db,'cycle_planner_migration_v1','done');
}


if($_SERVER['REQUEST_METHOD']==='GET' && ($_GET['api']??'')==='catalogue-image'){
 if(empty($_SESSION['admin']) || time()-($_SESSION['last']??0)>3600){http_response_code(401);exit;}
 ankhCatalogueOutputImage($db,(int)($_GET['id']??0),false);
}

if($_SERVER['REQUEST_METHOD']==='GET' && ($_GET['api']??'')==='order-pdf'){
 if(empty($_SESSION['admin']) || time()-($_SESSION['last']??0)>3600){http_response_code(401);exit('Please sign in again.');}
 $orderId=(int)($_GET['id']??0);$order=orderForSheet($db,$orderId);if(!$order){http_response_code(404);exit('Order not found.');}
 outputOrderPdf($order,(string)($_GET['type']??'invoice'));
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_GET['api']??'')==='passkey-options'){
 try{
  $body=json_decode((string)file_get_contents('php://input'),true);if(!is_array($body))$body=$_POST;
  if(!hash_equals($_SESSION['csrf'],(string)($body['csrf']??'')))voiceJson(['ok'=>false,'error'=>'Please refresh the page and try again.'],403);
  $kind=(string)($body['kind']??'login');$register=$kind==='register';
  if($testingNoAuth && $register)voiceJson(['ok'=>false,'error'=>'Passkey registration is disabled while public test mode is active.'],403);
  if($register && (empty($_SESSION['admin']) || time()-($_SESSION['last']??0)>3600))voiceJson(['ok'=>false,'error'=>'Please sign in with your PIN first.'],401);
  $challenge=b64urlEncode(random_bytes(32));$_SESSION['passkey_challenge']=$challenge;$_SESSION['passkey_kind']=$register?'register':'login';$_SESSION['passkey_created']=time();
  if($register){
   $userId=setting($db,'passkey_user_id','');if($userId===''){$userId=b64urlEncode(random_bytes(32));saveSetting($db,'passkey_user_id',$userId);}
   voiceJson(['ok'=>true,'publicKey'=>[
    'challenge'=>$challenge,'rp'=>['name'=>'ANKH Admin','id'=>webauthnRpId()],'user'=>['id'=>$userId,'name'=>'ankh-admin','displayName'=>'ANKH Admin'],
    'pubKeyCredParams'=>[['type'=>'public-key','alg'=>-7]],'timeout'=>60000,'attestation'=>'none',
    'authenticatorSelection'=>['authenticatorAttachment'=>'platform','residentKey'=>'preferred','userVerification'=>'required']
   ]]);
  }
  $rows=$db->query($hasIndividualLogins?'SELECT credential_id FROM passkeys WHERE user_id IS NOT NULL ORDER BY id':'SELECT credential_id FROM passkeys ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
  if(!$rows)voiceJson(['ok'=>false,'error'=>'No passkey has been set up yet. Use your PIN, then add Face ID / Passkey from More.'],404);
  voiceJson(['ok'=>true,'publicKey'=>[
   'challenge'=>$challenge,'rpId'=>webauthnRpId(),'timeout'=>60000,'userVerification'=>'required',
   'allowCredentials'=>array_map(fn($id)=>['type'=>'public-key','id'=>(string)$id],$rows)
  ]]);
 }catch(Throwable $ex){voiceJson(['ok'=>false,'error'=>$ex->getMessage()?:'Passkey could not start.'],500);}
}
if($_SERVER['REQUEST_METHOD']==='POST' && ($_GET['api']??'')==='passkey-register'){
 try{
  if($testingNoAuth)voiceJson(['ok'=>false,'error'=>'Passkey registration is disabled while public test mode is active.'],403);
  if(empty($_SESSION['admin']) || time()-($_SESSION['last']??0)>3600)voiceJson(['ok'=>false,'error'=>'Please sign in again.'],401);
  $body=json_decode((string)file_get_contents('php://input'),true);if(!is_array($body))voiceJson(['ok'=>false,'error'=>'Invalid passkey response.'],400);
  if(!hash_equals($_SESSION['csrf'],(string)($body['csrf']??'')))voiceJson(['ok'=>false,'error'=>'Please refresh the page and try again.'],403);
  $challenge=passkeyChallengeValid('register');$client=webauthnClientData((string)($body['clientDataJSON']??''),'webauthn.create',$challenge);
  $attestation=b64urlDecode((string)($body['attestationObject']??''));$offset=0;$decoded=cborRead($attestation,$offset);
  if(!is_array($decoded)||!isset($decoded['authData'])||!is_string($decoded['authData']))throw new Exception('Passkey attestation was invalid.');
  $auth=webauthnAuthData($decoded['authData'],true);$rawId=b64urlDecode((string)($body['rawId']??''));
  if(!hash_equals($auth['credential_id'],$rawId))throw new Exception('Passkey credential did not match.');
  $pem=webauthnCoseToPem($auth['cose']);$credential=b64urlEncode($rawId);$name=trim((string)($body['name']??'iPhone / Face ID'))?:'iPhone / Face ID';
  $q=$db->prepare('INSERT OR REPLACE INTO passkeys(credential_id,public_key_pem,sign_count,name,created,user_id) VALUES (?,?,?,?,?,?)');$q->execute([$credential,$pem,(int)$auth['count'],substr($name,0,80),gmdate('c'),$hasIndividualLogins?(int)$_SESSION['admin_user_id']:null]);
  unset($_SESSION['passkey_challenge'],$_SESSION['passkey_kind'],$_SESSION['passkey_created']);voiceJson(['ok'=>true]);
 }catch(Throwable $ex){voiceJson(['ok'=>false,'error'=>$ex->getMessage()?:'Passkey could not be saved.'],500);}
}
if($_SERVER['REQUEST_METHOD']==='POST' && ($_GET['api']??'')==='passkey-login'){
 try{
  $body=json_decode((string)file_get_contents('php://input'),true);if(!is_array($body))voiceJson(['ok'=>false,'error'=>'Invalid passkey response.'],400);
  if(!hash_equals($_SESSION['csrf'],(string)($body['csrf']??'')))voiceJson(['ok'=>false,'error'=>'Please refresh the page and try again.'],403);
  $challenge=passkeyChallengeValid('login');$client=webauthnClientData((string)($body['clientDataJSON']??''),'webauthn.get',$challenge);
  $credential=(string)($body['rawId']??'');$q=$db->prepare('SELECT * FROM passkeys WHERE credential_id=?');$q->execute([$credential]);$passkey=$q->fetch(PDO::FETCH_ASSOC);if(!$passkey)throw new Exception('This passkey is not registered with ANKH.');
  $authData=b64urlDecode((string)($body['authenticatorData']??''));$auth=webauthnAuthData($authData,false);$signature=b64urlDecode((string)($body['signature']??''));
  $signed=$authData.hash('sha256',$client['raw'],true);$verified=openssl_verify($signed,$signature,(string)$passkey['public_key_pem'],OPENSSL_ALGO_SHA256);
  if($verified!==1)throw new Exception('Passkey signature could not be verified.');
  $oldCount=(int)$passkey['sign_count'];$newCount=(int)$auth['count'];if($oldCount>0&&$newCount>0&&$newCount<=$oldCount)throw new Exception('Passkey counter check failed.');
  if($hasIndividualLogins && empty($passkey['user_id']))throw new Exception('Re-register this passkey from your own ANKH account.');
   $db->prepare('UPDATE passkeys SET sign_count=? WHERE id=?')->execute([$newCount,(int)$passkey['id']]);
  unset($_SESSION['passkey_challenge'],$_SESSION['passkey_kind'],$_SESSION['passkey_created']);session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['last']=time();if($hasIndividualLogins)$_SESSION['admin_user_id']=(int)$passkey['user_id'];
  voiceJson(['ok'=>true]);
 }catch(Throwable $ex){voiceJson(['ok'=>false,'error'=>$ex->getMessage()?:'Passkey sign-in failed.'],500);}
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_GET['api']??'')==='voice-order'){
 try{
  if(empty($_SESSION['admin']) || time()-($_SESSION['last']??0)>3600)voiceJson(['ok'=>false,'error'=>'Please sign in again.'],401);
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))voiceJson(['ok'=>false,'error'=>'Please refresh the page and try again.'],403);
  if($testingNoAuth)voiceJson(['ok'=>false,'error'=>'Voice ordering is locked while temporary password-disabled test mode is active.'],403);
  if($openAiKey==='')voiceJson(['ok'=>false,'error'=>'OpenAI is not connected yet. Add OPENAI_API_KEY to the private server configuration.'],503);
  if(!isset($_FILES['audio']) || !is_uploaded_file($_FILES['audio']['tmp_name']))voiceJson(['ok'=>false,'error'=>'No voice recording was received.'],400);
  if((int)($_FILES['audio']['size']??0)<100 || (int)($_FILES['audio']['size']??0)>12*1024*1024)voiceJson(['ok'=>false,'error'=>'Keep the voice order under about one minute and try again.'],400);

  $activeProducts=$db->query('SELECT id,name,price FROM products WHERE active=1 ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
  if(!$activeProducts)voiceJson(['ok'=>false,'error'=>'Add products before using Voice Order.'],400);
  $voiceCustomers=$db->query('SELECT id,name FROM customers WHERE archived=0 ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);

  $tmp=(string)$_FILES['audio']['tmp_name'];$mime=(string)($_FILES['audio']['type']??'audio/mp4');$filename=(string)($_FILES['audio']['name']??'voice-order.m4a');
  $productNames=array_values(array_map(fn($p)=>(string)$p['name'],$activeProducts));
  $transcription=openAiCurlJson('https://api.openai.com/v1/audio/transcriptions',[],[
   'file'=>new CURLFile($tmp,$mime,$filename),
   'model'=>'gpt-4o-mini-transcribe',
   'language'=>'en',
   'prompt'=>'ANKH order entry. IMPORTANT: when the speaker says "Reta", "Reeta", "Rita" or "Rayta" as a product name, transcribe it as Retatrutide. Product names may include: '.implode(', ',$productNames).'. Formats: Pen, Cartridge, Vial. Delivery people: James, Tony.'
  ],true);
  $transcript=trim((string)($transcription['text']??''));
  if($transcript==='')voiceJson(['ok'=>false,'error'=>'I could not hear an order. Please try again.'],422);
  // Product speech alias: customers commonly say "Reta" for Retatrutide.
  // Only normalise common mishearings when they appear in a product-like context.
  $parserTranscript=preg_replace(
   '/\\b(?:reta|rita|reeta|rayta)\\b(?=\\s*[,.-]?\\s*(?:(?:10|20)\\s*(?:mg|milligrams?)|(?:pen|vial|cartridge)\\b))/i',
   'Retatrutide',
   $transcript
  )??$transcript;

  $customerList=array_map(fn($c)=>['id'=>(int)$c['id'],'name'=>(string)$c['name']],$voiceCustomers);
  $schema=[
   'type'=>'object','additionalProperties'=>false,
   'properties'=>[
    'customer_id'=>['type'=>['integer','null']],
    'customer_name'=>['type'=>'string'],
    'recipient_name'=>['type'=>'string'],
    'phone'=>['type'=>'string'],
    'address'=>['type'=>'string'],
    'referrer'=>['type'=>'string'],
    'delivery_method'=>['type'=>'string','enum'=>['Collection','Local Delivery','Postage']],
    'assigned_to'=>['type'=>'string','enum'=>['','James','Tony']],
    'payment_method'=>['type'=>'string','enum'=>['','Cash','Bank Transfer','Card','PayPal','Other']],
    'notes'=>['type'=>'string'],
    'lines'=>['type'=>'array','items'=>[
      'type'=>'object','additionalProperties'=>false,
      'properties'=>[
       'product_name'=>['type'=>'string','enum'=>$productNames],
       'quantity'=>['type'=>'integer','minimum'=>1,'maximum'=>99],
       'presentation'=>['type'=>'string','enum'=>['','Pen','Cartridge','Vial']],
       'family_friends'=>['type'=>'boolean']
      ],
      'required'=>['product_name','quantity','presentation','family_friends']
    ]],
    'questions'=>['type'=>'array','items'=>['type'=>'string']],
    'needs_review'=>['type'=>'boolean']
   ],
   'required'=>['customer_id','customer_name','recipient_name','phone','address','referrer','delivery_method','assigned_to','payment_method','notes','lines','questions','needs_review']
  ];
  $instructions="Convert a spoken ANKH order into a draft. The customer_name is the person placing the order; recipient_name is the person it is for. If it is for the person ordering, leave recipient_name empty. Capture a different recipient only when clearly stated. ANKH speech alias rule: 'Reta' means Retatrutide. If the transcript contains Reta, Rita, Reeta or Rayta in a clear product context, especially next to 10mg, 20mg, pen, vial or cartridge, interpret it as Retatrutide rather than a person's name. Never invent customer contact details, products, strengths, quantities, formats, payment methods or delivery people. Use an existing customer_id only when the spoken customer clearly matches the supplied customer list. If an existing customer is chosen, leave phone and address blank because the app fills them from its database. Product_name must be an exact supplied catalogue value. If a product is mentioned without enough strength information to choose exactly, do not guess: omit that line and add a short question. The standalone catalogue product named Pen is an accessory sold on its own: for that product use presentation='' and do not ask for Pen/Cartridge/Vial. If a peptide is requested in a Pen, return the peptide with presentation='Pen' only; do not also return a Pen product because the app automatically creates a separate editable Pen row. For every other product, if Pen/Cartridge/Vial was not said, use an empty presentation and ask which format. 'family and friends', 'friends and family', or 'F&F' means family_friends=true. Delivery defaults to Local Delivery only if no delivery method was said. Assignment is only James or Tony when explicitly stated. Return a draft only; never imply it has been saved.";
  $input=json_encode(['transcript'=>$parserTranscript,'customers'=>$customerList,'products'=>$productNames],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  $response=openAiCurlJson('https://api.openai.com/v1/responses',['Content-Type: application/json'],json_encode([
   'model'=>'gpt-5.6-luna','store'=>false,'reasoning'=>['effort'=>'none'],'instructions'=>$instructions,'input'=>$input,
   'text'=>['format'=>['type'=>'json_schema','name'=>'ankh_voice_order','strict'=>true,'schema'=>$schema]]
  ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $parsedText=responseOutputText($response);$draft=json_decode($parsedText,true);
  if(!is_array($draft))voiceJson(['ok'=>false,'error'=>'I heard the order but could not turn it into a draft. Please try again.'],422);

  $customer=null;$customerId=(int)($draft['customer_id']??0);
  if($customerId>0){$q=$db->prepare('SELECT id,name,phone,address FROM customers WHERE id=? AND archived=0');$q->execute([$customerId]);$customer=$q->fetch(PDO::FETCH_ASSOC)?:null;}
  if($customer){
   $draft['customer_name']=(string)$customer['name'];$draft['phone']=(string)$customer['phone'];$draft['address']=(string)$customer['address'];
  }

  $productByName=[];foreach($activeProducts as $p)$productByName[(string)$p['name']]=$p;
  $penProduct=$productByName['Pen']??null;$lines=[];
  foreach(($draft['lines']??[]) as $line){
   $name=(string)($line['product_name']??'');if(!isset($productByName[$name]))continue;$p=$productByName[$name];
   $isStandalonePen=strtolower(trim($name))==='pen';
   $qty=max(1,min(99,(int)($line['quantity']??1)));$presentation=$isStandalonePen?'':(in_array(($line['presentation']??''),['Pen','Cartridge','Vial'],true)?(string)$line['presentation']:'');
   $base=(int)$p['price']/100;$canDiscount=!$isStandalonePen&&(bool)preg_match('/\d+(?:\.\d+)?\s*mg$/i',$name);$discount=$canDiscount&&!empty($line['family_friends']);$familyPrice=$p['family_friends_price']===null?max(0,$base-5):(int)$p['family_friends_price']/100;
   $price=$discount?min($base,$familyPrice):$base;
   $pairId='voice_'.count($lines).'_'.substr(hash('sha256',$name.'|'.$qty.'|'.count($lines)),0,10);
   $lines[]=['product_id'=>(int)$p['id'],'name'=>$name,'quantity'=>$qty,'presentation'=>$presentation,'base_price'=>$base,'discount'=>$discount,'price'=>$price,'pair_id'=>$pairId,'paired_pen'=>false,'recurring'=>isRetaProductName($name),'cycle_weeks'=>isRetaProductName($name)?4:defaultCycleWeeksForProduct($name)];
   if(!$isStandalonePen && $presentation==='Pen' && $penProduct){
    $penBase=(int)$penProduct['price']/100;
    $lines[]=['product_id'=>(int)$penProduct['id'],'name'=>'Pen','quantity'=>$qty,'presentation'=>'','base_price'=>$penBase,'discount'=>false,'price'=>$penBase,'pair_id'=>$pairId,'paired_pen'=>true,'paired_with_name'=>$name,'recurring'=>false,'cycle_weeks'=>0];
   }
  }
  $draft['lines']=$lines;
  voiceJson(['ok'=>true,'transcript'=>$transcript,'draft'=>$draft]);
 }catch(Throwable $ex){
  voiceJson(['ok'=>false,'error'=>$ex->getMessage()?:'Voice ordering failed. Please try again.'],500);
 }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_GET['api']??'')==='realtime-token'){
 try{
  if(empty($_SESSION['admin']) || time()-($_SESSION['last']??0)>3600)voiceJson(['ok'=>false,'error'=>'Please sign in again.'],401);
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))voiceJson(['ok'=>false,'error'=>'Please refresh the page and try again.'],403);
  if($testingNoAuth)voiceJson(['ok'=>false,'error'=>'ANKH Live Voice is locked until the app PIN has been set.'],403);
  if($openAiKey==='')voiceJson(['ok'=>false,'error'=>'OpenAI is not connected yet.'],503);

  $tools=[
   [
    'type'=>'function','name'=>'lookup_product',
    'description'=>'Look up current ANKH product retail prices, pen price, supplier cost and stock. Use this for any question about how much a product is, how much it costs ANKH, how much it is in a pen, or how many are in stock. Reta means Retatrutide.',
    'parameters'=>[
     'type'=>'object','additionalProperties'=>false,
     'properties'=>['query'=>['type'=>'string','description'=>'Product name and strength if known, e.g. Reta 10mg or BPC-157 10mg.']],
     'required'=>['query']
    ]
   ],
   [
    'type'=>'function','name'=>'get_business_metrics',
    'description'=>'Get ANKH sales and gross profit for a time period. Gross profit is sales minus saved product costs, pen costs and postage.',
    'parameters'=>[
     'type'=>'object','additionalProperties'=>false,
     'properties'=>[
      'period'=>['type'=>'string','enum'=>['today','week','month','months','days','all']],
      'months'=>['type'=>'integer','minimum'=>1,'maximum'=>36],
      'days'=>['type'=>'integer','minimum'=>1,'maximum'=>365]
     ],
     'required'=>['period']
    ]
   ],
   [
    'type'=>'function','name'=>'get_outstanding_payments',
    'description'=>'List current ANKH orders with outstanding payment and the total amount outstanding.',
    'parameters'=>['type'=>'object','additionalProperties'=>false,'properties'=>(object)[]]
   ],
   [
    'type'=>'function','name'=>'get_customer_balance',
    'description'=>'Find a customer and return exactly how much they still owe across all non-cancelled orders, including partial payments.',
    'parameters'=>[
     'type'=>'object','additionalProperties'=>false,
     'properties'=>['customer_name'=>['type'=>'string']],
     'required'=>['customer_name']
    ]
   ],
   [
    'type'=>'function','name'=>'get_deliveries',
    'description'=>'List current paid, packed or dispatched orders still needing delivery. Can filter to James or Tony.',
    'parameters'=>[
     'type'=>'object','additionalProperties'=>false,
     'properties'=>['assignee'=>['type'=>'string','enum'=>['','James','Tony']]]
    ]
   ],
   [
    'type'=>'function','name'=>'get_low_stock',
    'description'=>'List active products currently at or below their configured low-stock level.',
    'parameters'=>['type'=>'object','additionalProperties'=>false,'properties'=>(object)[]]
   ],
   [
    'type'=>'function','name'=>'get_cycle_forecast',
    'description'=>'Get ANKH Cycle Planner recurring product reminders, projected orders, revenue, profit and stock demand for the next 4, 5, 8 or 12 weeks. Includes Retatrutide, GHK and any other product marked recurring.',
    'parameters'=>[
     'type'=>'object','additionalProperties'=>false,
     'properties'=>['weeks'=>['type'=>'integer','enum'=>[4,5,8,12]]],
     'required'=>['weeks']
    ]
   ],
   [
    'type'=>'function','name'=>'lookup_customer_last_order',
    'description'=>'Find a customer and return their most recent ANKH order. Ask for clarification if multiple customer names match.',
    'parameters'=>[
     'type'=>'object','additionalProperties'=>false,
     'properties'=>['customer_name'=>['type'=>'string']],
     'required'=>['customer_name']
    ]
   ],
   [
    'type'=>'function','name'=>'lookup_order',
    'description'=>'Look up an ANKH order by ANK reference, order number, or customer name.',
    'parameters'=>[
     'type'=>'object','additionalProperties'=>false,
     'properties'=>['query'=>['type'=>'string']],
     'required'=>['query']
    ]
   ]
  ];

  $instructions="You are ANKH Assistant, a conversational voice assistant inside the private ANKH Peptides admin app. Speak naturally in concise British English, like a helpful colleague. This is a live conversation: remember the product, customer, timeframe and topic from previous turns so follow-up questions such as 'what about in a pen?', 'what did it cost us?', 'what about last month?' and 'how many are left?' make sense without the user repeating everything. Reta, Reeta, Rita or Rayta said as a product means Retatrutide. For factual ANKH prices, supplier costs, stock, orders, sales, gross profit, payment history, customer balances or deliveries, ALWAYS use the appropriate tool rather than guessing. If asked how much a named customer owes, use get_customer_balance and report the actual remaining balance after partial payments. For recurring-order questions such as who is due, what is projected in the next 4, 5, 8 or 12 weeks, projected cycle profit, or stock needed for expected repeat orders, use get_cycle_forecast. Retatrutide defaults to 4 weeks but individual customers may have a different saved cycle. Product retail price means the current catalogue selling price. The standalone Pen catalogue price is £20, but individual Pen order lines may be manually discounted, so an order lookup may show a lower actual price charged. 'Cost us', 'our cost', 'supplier cost' or similar means the saved supplier cost. If the user simply asks 'what does it cost?' and context is unclear, briefly give both retail and supplier cost or ask which they mean. If a product lookup returns multiple strengths, ask which strength rather than choosing one. Monetary values are GBP. Gross profit means completed sales minus saved product cost, pen cost and postage; mention when missing saved costs make the result incomplete. This assistant is read-only: never claim you changed an order, payment, delivery, stock or customer. Do not provide peptide dosing, administration or medical advice; say this assistant is for ANKH business/admin information. Keep spoken answers short enough to feel conversational, but include the exact figure or names the user asked for.";

  $sessionConfig=[
   'session'=>[
    'type'=>'realtime',
    'model'=>'gpt-realtime-2.1',
    'output_modalities'=>['audio'],
    'instructions'=>$instructions,
    'audio'=>[
     'input'=>['turn_detection'=>['type'=>'semantic_vad']],
     'output'=>['voice'=>'marin']
    ],
    'tools'=>$tools,
    'tool_choice'=>'auto'
   ]
  ];
  $safetyId=hash('sha256','ankh-admin|'.session_id());
  $secret=openAiCurlJson(
   'https://api.openai.com/v1/realtime/client_secrets',
   ['Content-Type: application/json','OpenAI-Safety-Identifier: '.$safetyId],
   json_encode($sessionConfig,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
  );
  $value=(string)($secret['value']??'');
  if($value==='')throw new Exception('OpenAI did not return a Realtime client secret.');
  voiceJson(['ok'=>true,'value'=>$value,'expires_at'=>$secret['expires_at']??null]);
 }catch(Throwable $ex){
  voiceJson(['ok'=>false,'error'=>$ex->getMessage()?:'Could not start ANKH Live Voice.'],500);
 }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_GET['api']??'')==='assistant-tool'){
 try{
  if(empty($_SESSION['admin']) || time()-($_SESSION['last']??0)>3600)voiceJson(['ok'=>false,'error'=>'Please sign in again.'],401);
  $payload=json_decode((string)file_get_contents('php://input'),true);
  if(!is_array($payload))voiceJson(['ok'=>false,'error'=>'Invalid assistant request.'],400);
  if(!hash_equals($_SESSION['csrf'],(string)($payload['csrf']??'')))voiceJson(['ok'=>false,'error'=>'Please refresh the page and try again.'],403);
  if($testingNoAuth)voiceJson(['ok'=>false,'error'=>'ANKH Live Voice is locked until the app PIN has been set.'],403);

  $tool=(string)($payload['name']??'');$args=is_array($payload['args']??null)?$payload['args']:[];
  $moneyTool=fn(int $pence)=>'£'.number_format($pence/100,2);
  $aliasProduct=function(string $value):string{
   return preg_replace('/\\b(?:reta|reeta|rita|rayta)\\b/i','Retatrutide',$value)??$value;
  };
  $normProduct=function(string $value)use($aliasProduct):string{
   return preg_replace('/[^a-z0-9]+/','',strtolower($aliasProduct($value)))??'';
  };
  $result=[];

  if($tool==='lookup_product'){
   $query=trim((string)($args['query']??''));if($query==='')throw new Exception('Tell me which product to look up.');
   $query=$aliasProduct($query);$qNorm=$normProduct($query);$spokenStrength=null;
   if(preg_match('/(\\d+(?:\\.\\d+)?)\\s*(?:mg|milligrams?)/i',$query,$strengthMatch))$spokenStrength=(float)$strengthMatch[1];
   $rows=$db->query('SELECT id,name,price,cost,stock_qty,low_stock_at,stock_tracking,active FROM products WHERE active=1 ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
   $ranked=[];
   foreach($rows as $row){
    $name=(string)$row['name'];$nNorm=$normProduct($name);$score=0;
    if($nNorm===$qNorm)$score=10000;
    elseif($qNorm!==''&&str_contains($nNorm,$qNorm))$score=8000-abs(strlen($nNorm)-strlen($qNorm));
    elseif($nNorm!==''&&str_contains($qNorm,$nNorm))$score=7000-abs(strlen($nNorm)-strlen($qNorm));
    else{$distance=levenshtein($qNorm,$nNorm);$score=4000-($distance*100);}
    if($spokenStrength!==null){
     if(preg_match('/(\\d+(?:\\.\\d+)?)\\s*mg/i',$name,$nameStrength)){
      $score+=(abs((float)$nameStrength[1]-$spokenStrength)<0.0001)?3500:-5000;
     }else{$score-=2500;}
    }
    $ranked[]=['score'=>$score,'row'=>$row];
   }
   usort($ranked,fn($a,$b)=>$b['score']<=>$a['score']);
   $bestScore=(int)($ranked[0]['score']??-9999);$matches=[];
   foreach(array_slice($ranked,0,6) as $entry){
    if($entry['score']<max(2000,$bestScore-900))continue;
    $row=$entry['row'];$base=(int)$row['price'];$cost=$row['cost']===null?null:(int)$row['cost'];$penCostRaw=setting($db,'pen_cost_pence','');$penUnitCost=$penCostRaw===''?null:(int)$penCostRaw;
    $canFf=(bool)preg_match('/\\d+(?:\\.\\d+)?\\s*mg$/i',(string)$row['name']);
    $standalonePenProduct=strtolower(trim((string)$row['name']))==='pen';
    $matches[]=[
     'name'=>(string)$row['name'],
     'standalone_product'=>$standalonePenProduct,
     'retail_price'=>$moneyTool($base),
     'retail_vial'=>$moneyTool($base),
     'retail_cartridge'=>$moneyTool($base),
     'retail_pen'=>$standalonePenProduct?$moneyTool($base):$moneyTool($base+2000),
     'family_friends_vial'=>$canFf?$moneyTool(max(0,$base-500)):null,
     'family_friends_pen'=>$canFf?$moneyTool(max(0,$base-500)+2000):null,
     'supplier_product_cost'=>$cost===null?null:$moneyTool($cost),
     'pen_unit_cost'=>$penUnitCost===null?null:$moneyTool($penUnitCost),
     'supplier_cost_with_pen'=>($cost!==null&&$penUnitCost!==null)?$moneyTool($cost+$penUnitCost):null,
     'stock_qty'=>(int)($row['stock_tracking']??0)===1&&$row['stock_qty']!==null?(int)$row['stock_qty']:null,
     'low_stock_at'=>(int)($row['stock_tracking']??0)===1&&$row['stock_qty']!==null?(int)$row['low_stock_at']:null
    ];
   }
   if(!$matches)$result=['found'=>false,'query'=>$query,'message'=>'No matching active ANKH product was found.'];
   else$result=['found'=>true,'query'=>$query,'ambiguous'=>count($matches)>1,'matches'=>$matches];
  }elseif($tool==='get_business_metrics'){
   $period=(string)($args['period']??'month');$months=max(1,min(36,(int)($args['months']??1)));$days=max(1,min(365,(int)($args['days']??1)));
   $tzTool=new DateTimeZone('Europe/London');$nowTool=new DateTimeImmutable('now',$tzTool);$start=null;$label='all time';
   if($period==='today'){$start=$nowTool->setTime(0,0);$label='today';}
   elseif($period==='week'){$start=$nowTool->modify('monday this week')->setTime(0,0);$label='this week';}
   elseif($period==='month'){$start=$nowTool->modify('first day of this month')->setTime(0,0);$label='this month';}
   elseif($period==='months'){$start=$nowTool->modify('-'.$months.' months');$label='the last '.$months.' month'.($months===1?'':'s');}
   elseif($period==='days'){$start=$nowTool->modify('-'.$days.' days');$label='the last '.$days.' day'.($days===1?'':'s');}
   $rows=$db->query("SELECT o.id,o.created,o.payment_date,o.postage_cost,
    COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0) total,
    COALESCE((SELECT SUM((COALESCE(i.cost,0)+COALESCE(i.presentation_cost,0))*i.quantity) FROM items i WHERE i.order_id=o.id),0) cogs,
    COALESCE((SELECT COUNT(*) FROM items i WHERE i.order_id=o.id AND i.cost IS NULL),0) missing_cost
    FROM orders o WHERE o.status IN ('Paid','Packed','Dispatched','Delivered')")->fetchAll(PDO::FETCH_ASSOC);
   $sales=0;$profit=0;$count=0;$missing=0;
   foreach($rows as $row){
    $dateText=(string)(($row['payment_date']??'')?:$row['created']);$ts=strtotime($dateText)?:0;
    if($start&&$ts<$start->getTimestamp())continue;
    $revenue=(int)$row['total'];$sales+=$revenue;$profit+=$revenue-(int)$row['cogs']-(int)($row['postage_cost']??0);$count++;if((int)$row['missing_cost']>0)$missing++;
   }
   $result=['period'=>$label,'sales'=>$moneyTool($sales),'gross_profit'=>$moneyTool($profit),'completed_orders'=>$count,'orders_with_missing_cost'=>$missing];
  }elseif($tool==='get_outstanding_payments'){
   $rows=$db->query("SELECT o.id,o.customer,o.created,
    COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0) total,
    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0) paid
    FROM orders o WHERE o.status<>'Cancelled'
    AND (COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0))>COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0)
    ORDER BY datetime(o.created) ASC,o.id ASC")->fetchAll(PDO::FETCH_ASSOC);
   $total=0;$orders=[];foreach($rows as $row){$balance=max(0,(int)$row['total']-(int)$row['paid']);$total+=$balance;$orders[]=['reference'=>'ANK-'.str_pad((string)$row['id'],4,'0',STR_PAD_LEFT),'customer'=>(string)$row['customer'],'order_total'=>$moneyTool((int)$row['total']),'paid'=>$moneyTool((int)$row['paid']),'balance'=>$moneyTool($balance),'created'=>(string)$row['created']];}
   $result=['count'=>count($orders),'total_outstanding'=>$moneyTool($total),'orders'=>$orders];
  }elseif($tool==='get_customer_balance'){
   $customer=trim((string)($args['customer_name']??''));if($customer==='')throw new Exception('Tell me the customer name.');
   $q=$db->prepare("SELECT DISTINCT customer FROM orders WHERE lower(customer) LIKE lower(?) ORDER BY customer COLLATE NOCASE LIMIT 8");$q->execute(['%'.$customer.'%']);$names=$q->fetchAll(PDO::FETCH_COLUMN);
   if(!$names)$result=['found'=>false,'customer_query'=>$customer];
   else{
    $exact=array_values(array_filter($names,fn($n)=>strtolower(trim((string)$n))===strtolower($customer)));
    if(count($names)>1&&!$exact)$result=['found'=>true,'ambiguous'=>true,'customer_query'=>$customer,'customer_matches'=>array_values($names)];
    else{
     $chosen=(string)($exact[0]??$names[0]);$q=$db->prepare("SELECT o.id,
      COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0) total,
      COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0) paid
      FROM orders o WHERE o.customer=? AND o.status<>'Cancelled' ORDER BY o.id");$q->execute([$chosen]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
     $owed=0;$open=[];foreach($rows as $row){$balance=max(0,(int)$row['total']-(int)$row['paid']);if($balance<=0)continue;$owed+=$balance;$open[]=['reference'=>'ANK-'.str_pad((string)$row['id'],4,'0',STR_PAD_LEFT),'balance'=>$moneyTool($balance),'paid'=>$moneyTool((int)$row['paid']),'total'=>$moneyTool((int)$row['total'])];}
     $result=['found'=>true,'ambiguous'=>false,'customer'=>$chosen,'total_owed'=>$moneyTool($owed),'outstanding_orders'=>$open,'count'=>count($open)];
    }
   }
  }elseif($tool==='get_deliveries'){
   $assignee=(string)($args['assignee']??'');$sql="SELECT id,customer,status,assigned_to,delivery_method,created FROM orders WHERE status IN ('Paid','Packed','Dispatched')";$params=[];
   if(in_array($assignee,['James','Tony'],true)){$sql.=" AND assigned_to=?";$params[]=$assignee;}
   $sql.=" ORDER BY datetime(created) ASC,id ASC";$q=$db->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$orders=[];
   foreach($rows as $row)$orders[]=['reference'=>'ANK-'.str_pad((string)$row['id'],4,'0',STR_PAD_LEFT),'customer'=>(string)$row['customer'],'status'=>(string)$row['status'],'assigned_to'=>(string)($row['assigned_to']?:'Unassigned'),'delivery_method'=>(string)($row['delivery_method']?:'Not set'),'created'=>(string)$row['created']];
   $result=['assignee'=>$assignee?:'All','count'=>count($orders),'orders'=>$orders];
  }elseif($tool==='get_low_stock'){
   $rows=$db->query("SELECT name,stock_qty,low_stock_at FROM products WHERE active=1 AND stock_tracking=1 AND stock_qty IS NOT NULL AND stock_qty<=low_stock_at ORDER BY stock_qty ASC,name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
   $products=[];foreach($rows as $row)$products[]=['name'=>(string)$row['name'],'stock_qty'=>(int)$row['stock_qty'],'low_stock_at'=>(int)$row['low_stock_at']];
   $result=['count'=>count($products),'products'=>$products];
  }elseif($tool==='get_cycle_forecast'){
   $weeks=(int)($args['weeks']??4);if(!in_array($weeks,[4,5,8,12],true))$weeks=4;
   $tzForecast=new DateTimeZone('Europe/London');$todayForecast=new DateTimeImmutable('today',$tzForecast);
   $cycles=$db->query("SELECT * FROM reta_cycles WHERE active=1 ORDER BY date(next_due_date),customer_name COLLATE NOCASE,product_name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
   $forecast=retaProjection($cycles,$todayForecast,$weeks*7);$occurrences=cycleOccurrences($cycles,$todayForecast,$weeks);$upcoming=[];
   foreach(array_slice($occurrences,0,30) as $cycle){$due=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycle['occurrence_date'],$tzForecast);$days=$due?(int)$todayForecast->diff($due)->format('%r%a'):0;$upcoming[]=['ordered_by'=>(string)$cycle['customer_name'],'for'=>cycleRecipientName($cycle),'customer'=>(string)$cycle['customer_name'],'next_due'=>(string)$cycle['occurrence_date'],'days_from_today'=>$days,'product'=>(string)($cycle['product_name']?:$cycle['product_summary']),'presentation'=>(string)($cycle['presentation']??''),'quantity'=>(int)($cycle['quantity']??1),'cycle_weeks'=>(int)($cycle['cycle_weeks']??4),'projected_value'=>$moneyTool((int)$cycle['expected_value']),'projected_profit'=>$moneyTool((int)$cycle['expected_profit']),'cost_missing'=>(bool)$cycle['cost_missing']];}
   $stock=[];foreach(($forecast['stock']??[]) as $name=>$qty)$stock[]=['product'=>$name,'units'=>(int)$qty];
   $result=['weeks'=>$weeks,'active_cycles'=>count($cycles),'projected_orders'=>$forecast['orders'],'projected_revenue'=>$moneyTool($forecast['revenue']),'projected_profit'=>$moneyTool($forecast['profit']),'cost_missing_occurrences'=>$forecast['cost_missing'],'stock_needed'=>$stock,'upcoming'=>$upcoming];
  }elseif($tool==='lookup_customer_last_order'){
   $customer=trim((string)($args['customer_name']??''));if($customer==='')throw new Exception('Tell me the customer name.');
   $q=$db->prepare("SELECT DISTINCT customer FROM orders WHERE lower(customer) LIKE lower(?) ORDER BY customer COLLATE NOCASE LIMIT 8");$q->execute(['%'.$customer.'%']);$names=$q->fetchAll(PDO::FETCH_COLUMN);
   if(!$names)$result=['found'=>false,'customer_query'=>$customer];
   else{
    $exact=array_values(array_filter($names,fn($n)=>strtolower(trim((string)$n))===strtolower($customer)));
    if(count($names)>1&&!$exact)$result=['found'=>true,'ambiguous'=>true,'customer_query'=>$customer,'customer_matches'=>array_values($names)];
    else{
     $chosen=(string)($exact[0]??$names[0]);$q=$db->prepare("SELECT o.*,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) total,COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0) paid FROM orders o LEFT JOIN items i ON i.order_id=o.id WHERE o.customer=? GROUP BY o.id ORDER BY datetime(o.created) DESC,o.id DESC LIMIT 1");$q->execute([$chosen]);$row=$q->fetch(PDO::FETCH_ASSOC);
     if(!$row)$result=['found'=>false,'customer_query'=>$customer];
     else{$iq=$db->prepare('SELECT name,quantity,presentation FROM items WHERE order_id=? ORDER BY id');$iq->execute([(int)$row['id']]);$items=[];foreach($iq->fetchAll(PDO::FETCH_ASSOC) as $it){if(strtolower(trim((string)$it['name']))==='pen')continue;$items[]=['name'=>(string)$it['name'],'quantity'=>(int)$it['quantity'],'presentation'=>(string)$it['presentation']];}
      $result=['found'=>true,'ambiguous'=>false,'customer'=>(string)$row['customer'],'reference'=>'ANK-'.str_pad((string)$row['id'],4,'0',STR_PAD_LEFT),'created'=>(string)$row['created'],'status'=>(string)$row['status'],'total'=>$moneyTool((int)$row['total']),'paid'=>$moneyTool((int)$row['paid']),'balance'=>$moneyTool(max(0,(int)$row['total']-(int)$row['paid'])),'delivery_method'=>(string)($row['delivery_method']??''),'assigned_to'=>(string)($row['assigned_to']??''),'items'=>$items];
     }
    }
   }
  }elseif($tool==='lookup_order'){
   $query=trim((string)($args['query']??''));if($query==='')throw new Exception('Tell me which order to look up.');
   $digits=preg_replace('/\\D+/','',$query);$rows=[];
   if($digits!==''){$q=$db->prepare("SELECT o.*,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) total,COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0) paid FROM orders o LEFT JOIN items i ON i.order_id=o.id WHERE o.id=? GROUP BY o.id LIMIT 1");$q->execute([(int)$digits]);$one=$q->fetch(PDO::FETCH_ASSOC);if($one)$rows=[$one];}
   if(!$rows){$q=$db->prepare("SELECT o.*,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) total,COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0) paid FROM orders o LEFT JOIN items i ON i.order_id=o.id WHERE lower(o.customer) LIKE lower(?) GROUP BY o.id ORDER BY datetime(o.created) DESC,o.id DESC LIMIT 5");$q->execute(['%'.$query.'%']);$rows=$q->fetchAll(PDO::FETCH_ASSOC);}
   $orders=[];foreach($rows as $row){$orders[]=['reference'=>'ANK-'.str_pad((string)$row['id'],4,'0',STR_PAD_LEFT),'customer'=>(string)$row['customer'],'status'=>(string)$row['status'],'total'=>$moneyTool((int)$row['total']),'paid'=>$moneyTool((int)($row['paid']??0)),'balance'=>$moneyTool(max(0,(int)$row['total']-(int)($row['paid']??0))),'created'=>(string)$row['created'],'assigned_to'=>(string)($row['assigned_to']??''),'delivery_method'=>(string)($row['delivery_method']??'')];}
   $result=['found'=>count($orders)>0,'query'=>$query,'orders'=>$orders];
  }else{
   voiceJson(['ok'=>false,'error'=>'Unknown ANKH Assistant tool.'],400);
  }

  voiceJson(['ok'=>true,'result'=>$result]);
 }catch(Throwable $ex){
  voiceJson(['ok'=>false,'error'=>$ex->getMessage()?:'Could not read ANKH data.'],500);
 }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_GET['api']??'')==='voice-assistant'){
 try{
  if(empty($_SESSION['admin']) || time()-($_SESSION['last']??0)>3600)voiceJson(['ok'=>false,'error'=>'Please sign in again.'],401);
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))voiceJson(['ok'=>false,'error'=>'Please refresh the page and try again.'],403);
  if($testingNoAuth)voiceJson(['ok'=>false,'error'=>'ANKH Assistant is locked until the app PIN has been set.'],403);
  if($openAiKey==='')voiceJson(['ok'=>false,'error'=>'OpenAI is not connected yet.'],503);
  if(!isset($_FILES['audio']) || !is_uploaded_file($_FILES['audio']['tmp_name']))voiceJson(['ok'=>false,'error'=>'No voice recording was received.'],400);
  if((int)($_FILES['audio']['size']??0)<100 || (int)($_FILES['audio']['size']??0)>12*1024*1024)voiceJson(['ok'=>false,'error'=>'Keep the question under about one minute and try again.'],400);

  $tmp=(string)$_FILES['audio']['tmp_name'];$mime=(string)($_FILES['audio']['type']??'audio/mp4');$filename=(string)($_FILES['audio']['name']??'ankh-question.m4a');
  $transcription=openAiCurlJson('https://api.openai.com/v1/audio/transcriptions',[],[
   'file'=>new CURLFile($tmp,$mime,$filename),
   'model'=>'gpt-4o-mini-transcribe',
   'language'=>'en',
   'prompt'=>'ANKH Peptides business admin question. Names may include James and Tony. Reta means Retatrutide. Common questions are profit, sales, outstanding payments, deliveries, stock and customer orders.'
  ],true);
  $transcript=trim((string)($transcription['text']??''));
  if($transcript==='')voiceJson(['ok'=>false,'error'=>'I could not hear a question. Please try again.'],422);

  $schema=[
   'type'=>'object','additionalProperties'=>false,
   'properties'=>[
    'intent'=>['type'=>'string','enum'=>['profit','sales','outstanding_payments','delivery_needed','assigned_delivery','low_stock','summary','customer_last_order','open_new_order','help']],
    'period'=>['type'=>'string','enum'=>['today','week','month','months','all']],
    'months'=>['type'=>'integer','minimum'=>0,'maximum'=>36],
    'assignee'=>['type'=>'string','enum'=>['','James','Tony']],
    'customer_name'=>['type'=>'string']
   ],
   'required'=>['intent','period','months','assignee','customer_name']
  ];
  $assistantInstructions="Classify an ANKH business-admin voice question. Read-only questions only. Profit means gross profit after saved product costs, pen costs and postage. If the user says 'last X months', use intent profit or sales as appropriate, period months and months=X. 'This month' means period month. 'This week' means week. 'Today' means today. Outstanding/unpaid money means outstanding_payments. Orders needing delivery means delivery_needed. If they ask what James or Tony has to deliver, use assigned_delivery and that assignee. Stock running low means low_stock. 'Give me a summary' means summary. Questions about a named customer's most recent order mean customer_last_order. Requests to add/create a new order mean open_new_order. Otherwise use help.";
  $classified=openAiCurlJson('https://api.openai.com/v1/responses',['Content-Type: application/json'],json_encode([
   'model'=>'gpt-5.6-luna','store'=>false,'reasoning'=>['effort'=>'none'],'instructions'=>$assistantInstructions,'input'=>$transcript,
   'text'=>['format'=>['type'=>'json_schema','name'=>'ankh_assistant_intent','strict'=>true,'schema'=>$schema]]
  ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $intent=json_decode(responseOutputText($classified),true);
  if(!is_array($intent))voiceJson(['ok'=>false,'error'=>'I heard you, but I could not understand the business question.'],422);

  $kind=(string)($intent['intent']??'help');$period=(string)($intent['period']??'month');$months=max(0,min(36,(int)($intent['months']??0)));
  $assignee=(string)($intent['assignee']??'');$customerName=trim((string)($intent['customer_name']??''));
  $tz=new DateTimeZone('Europe/London');$nowAssistant=new DateTimeImmutable('now',$tz);
  $start=null;$periodLabel='all time';
  if($period==='today'){$start=$nowAssistant->setTime(0,0);$periodLabel='today';}
  elseif($period==='week'){$start=$nowAssistant->modify('monday this week')->setTime(0,0);$periodLabel='this week';}
  elseif($period==='month'){$start=$nowAssistant->modify('first day of this month')->setTime(0,0);$periodLabel='this month';}
  elseif($period==='months'){$months=$months?:1;$start=$nowAssistant->modify('-'.$months.' months')->setTime(0,0);$periodLabel='the last '.$months.' month'.($months===1?'':'s');}

  $moneySpeak=function(int $pence):string{return '£'.number_format($pence/100,2);};
  $answer='';$items=[];$navigate='';
  $paidForAssistant=['Paid','Packed','Dispatched','Delivered'];

  if(in_array($kind,['profit','sales','summary'],true)){
   $rows=$db->query("SELECT o.*,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) total FROM orders o LEFT JOIN items i ON i.order_id=o.id GROUP BY o.id ORDER BY o.id DESC")->fetchAll(PDO::FETCH_ASSOC);
   $itemCosts=[];$missingCosts=[];
   $costRows=$db->query("SELECT i.order_id,i.name,i.cost,i.presentation_cost,i.quantity FROM items i JOIN orders o ON o.id=i.order_id WHERE o.status IN ('Paid','Packed','Dispatched','Delivered')")->fetchAll(PDO::FETCH_ASSOC);
   foreach($costRows as $cr){
    $oid=(int)$cr['order_id'];$qty=(int)$cr['quantity'];$cost=$cr['cost']===null?null:(int)$cr['cost'];$presentationCost=(int)($cr['presentation_cost']??0);
    if($cost===null){$missingCosts[$oid]=true;$cost=0;}
    $itemCosts[$oid]=($itemCosts[$oid]??0)+(($cost+$presentationCost)*$qty);
   }
   $periodRevenue=0;$periodProfit=0;$periodOrders=0;$periodMissing=false;
   foreach($rows as $row){
    if(!in_array((string)$row['status'],$paidForAssistant,true))continue;
    $dateText=(string)(($row['payment_date']??'')?:$row['created']);$ts=strtotime($dateText)?:0;
    if($start && $ts<$start->getTimestamp())continue;
    $revenue=(int)$row['total'];$profit=$revenue-(int)($itemCosts[(int)$row['id']]??0)-(int)($row['postage_cost']??0);
    $periodRevenue+=$revenue;$periodProfit+=$profit;$periodOrders++;if(!empty($missingCosts[(int)$row['id']]))$periodMissing=true;
   }
   if($kind==='profit'){
    $answer='Gross profit for '.$periodLabel.' is '.$moneySpeak($periodProfit).' from '.$moneySpeak($periodRevenue).' in sales across '.$periodOrders.' completed order'.($periodOrders===1?'':'s').'.';
    if($periodMissing)$answer.=' Some sold items have no saved cost, so the profit figure may be overstated.';
   }elseif($kind==='sales'){
    $answer='Sales for '.$periodLabel.' are '.$moneySpeak($periodRevenue).' across '.$periodOrders.' completed order'.($periodOrders===1?'':'s').'.';
   }else{
    $todayStartAssistant=$nowAssistant->setTime(0,0)->getTimestamp();$todayRevenue=0;$todayProfit=0;$todayOrders=0;
    foreach($rows as $row){
     if(!in_array((string)$row['status'],$paidForAssistant,true))continue;$ts=strtotime((string)(($row['payment_date']??'')?:$row['created']))?:0;if($ts<$todayStartAssistant)continue;
     $rev=(int)$row['total'];$todayRevenue+=$rev;$todayProfit+=$rev-(int)($itemCosts[(int)$row['id']]??0)-(int)($row['postage_cost']??0);$todayOrders++;
    }
    $unpaidRows=$db->query("SELECT o.id,o.customer,COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0) total,COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0) paid FROM orders o WHERE o.status<>'Cancelled' AND (COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0))>COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0)")->fetchAll(PDO::FETCH_ASSOC);
    foreach($unpaidRows as &$ur)$ur['outstanding']=max(0,(int)$ur['total']-(int)$ur['paid']);unset($ur);
    $deliveryRows=$db->query("SELECT id FROM orders WHERE status IN ('Paid','Packed','Dispatched')")->fetchAll(PDO::FETCH_ASSOC);
    $lowRows=$db->query("SELECT id FROM products WHERE active=1 AND stock_tracking=1 AND stock_qty IS NOT NULL AND stock_qty<=low_stock_at")->fetchAll(PDO::FETCH_ASSOC);
    $unpaidTotal=array_sum(array_map(fn($r)=>(int)$r['outstanding'],$unpaidRows));
    $answer='Today you have '.$moneySpeak($todayRevenue).' in sales and '.$moneySpeak($todayProfit).' gross profit from '.$todayOrders.' completed order'.($todayOrders===1?'':'s').'. There are '.count($unpaidRows).' outstanding payment'.(count($unpaidRows)===1?'':'s').' worth '.$moneySpeak($unpaidTotal).', '.count($deliveryRows).' order'.(count($deliveryRows)===1?'':'s').' still to deliver, and '.count($lowRows).' low-stock product'.(count($lowRows)===1?'':'s').'.';
   }
  }elseif($kind==='outstanding_payments'){
   $rows=$db->query("SELECT o.id,o.customer,o.created,COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0) total,COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0) paid FROM orders o WHERE o.status<>'Cancelled' AND (COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0))>COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0) ORDER BY datetime(o.created) ASC,o.id ASC")->fetchAll(PDO::FETCH_ASSOC);
   foreach($rows as &$row)$row['outstanding']=max(0,(int)$row['total']-(int)$row['paid']);unset($row);
   $total=array_sum(array_map(fn($r)=>(int)$r['outstanding'],$rows));
   foreach($rows as $r)$items[]=['reference'=>'ANK-'.str_pad((string)$r['id'],4,'0',STR_PAD_LEFT),'title'=>(string)$r['customer'],'detail'=>$moneySpeak((int)$r['outstanding']).' outstanding'];
   if(!$rows)$answer='There are no outstanding payments.';
   else{$names=array_slice(array_map(fn($r)=>(string)$r['customer'],$rows),0,5);$answer='There are '.count($rows).' outstanding payment'.(count($rows)===1?'':'s').' worth '.$moneySpeak($total).'. '.implode(', ',$names).(count($rows)>5?' and '.(count($rows)-5).' more.':'.');}
  }elseif(in_array($kind,['delivery_needed','assigned_delivery'],true)){
   $sql="SELECT o.id,o.customer,o.status,o.assigned_to,o.delivery_method,o.created FROM orders o WHERE o.status IN ('Paid','Packed','Dispatched')";
   $params=[];if($kind==='assigned_delivery'&&in_array($assignee,['James','Tony'],true)){$sql.=" AND o.assigned_to=?";$params[]=$assignee;}
   $sql.=" ORDER BY datetime(o.created) ASC,o.id ASC";$q=$db->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
   foreach($rows as $r)$items[]=['reference'=>'ANK-'.str_pad((string)$r['id'],4,'0',STR_PAD_LEFT),'title'=>(string)$r['customer'],'detail'=>trim(((string)$r['assigned_to']?:'Unassigned').' · '.((string)$r['delivery_method']?:'Delivery not set'))];
   $label=$kind==='assigned_delivery'&&$assignee!==''?$assignee.' has':'There are';
   if(!$rows)$answer=$kind==='assigned_delivery'&&$assignee!==''?$assignee.' has no orders waiting for delivery.':'There are no orders waiting for delivery.';
   else{$names=array_slice(array_map(fn($r)=>(string)$r['customer'],$rows),0,5);$answer=$label.' '.count($rows).' order'.(count($rows)===1?'':'s').' waiting for delivery: '.implode(', ',$names).(count($rows)>5?' and '.(count($rows)-5).' more.':'.');}
  }elseif($kind==='low_stock'){
   $rows=$db->query("SELECT name,stock_qty,low_stock_at FROM products WHERE active=1 AND stock_tracking=1 AND stock_qty IS NOT NULL AND stock_qty<=low_stock_at ORDER BY stock_qty ASC,name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
   foreach($rows as $r)$items[]=['reference'=>'Stock','title'=>(string)$r['name'],'detail'=>(int)$r['stock_qty'].' left'];
   if(!$rows)$answer='Nothing currently tracked is at or below its low-stock level.';
   else{$names=array_slice(array_map(fn($r)=>(string)$r['name'].' with '.(int)$r['stock_qty'].' left',$rows),0,5);$answer=count($rows).' product'.(count($rows)===1?' is':'s are').' low on stock: '.implode(', ',$names).(count($rows)>5?' and '.(count($rows)-5).' more.':'.');}
  }elseif($kind==='customer_last_order'){
   if($customerName===''){$answer='Tell me the customer name and ask for their last order.';}
   else{
    $q=$db->prepare("SELECT o.*,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) total FROM orders o LEFT JOIN items i ON i.order_id=o.id WHERE lower(o.customer) LIKE lower(?) GROUP BY o.id ORDER BY datetime(o.created) DESC,o.id DESC LIMIT 1");$q->execute(['%'.$customerName.'%']);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)$answer='I could not find an order for '.$customerName.'.';
    else{
     $iq=$db->prepare('SELECT name,quantity,presentation FROM items WHERE order_id=? ORDER BY id');$iq->execute([(int)$row['id']]);$parts=[];foreach($iq->fetchAll(PDO::FETCH_ASSOC) as $it){if(strtolower(trim((string)$it['name']))==='pen')continue;$parts[]=(int)$it['quantity'].' '.$it['name'].(!empty($it['presentation'])?' '.$it['presentation']:'');}
     $ref='ANK-'.str_pad((string)$row['id'],4,'0',STR_PAD_LEFT);$answer=$row['customer']."'s last order was ".$ref.' for '.implode(', ',$parts).', total '.$moneySpeak((int)$row['total']).'. Its status is '.$row['status'].'.';
     $items[]=['reference'=>$ref,'title'=>(string)$row['customer'],'detail'=>implode(' · ',$parts)];
    }
   }
  }elseif($kind==='open_new_order'){
   $answer='Opening New Order. Use the Voice Order button there to dictate the customer and products.';$navigate='?view=new';
  }else{
   $answer='You can ask me about profit or sales over a time period, outstanding payments, orders needing delivery, what James or Tony has to deliver, low stock, a customer’s last order, or ask for today’s summary.';
  }

  voiceJson(['ok'=>true,'transcript'=>$transcript,'answer'=>$answer,'items'=>$items,'navigate'=>$navigate,'intent'=>$kind]);
 }catch(Throwable $ex){
  voiceJson(['ok'=>false,'error'=>$ex->getMessage()?:'ANKH Assistant could not answer that. Please try again.'],500);
 }
}

// Shipping calendar edits: authenticated, CSRF-protected and stored in private settings.
if((string)($_GET['view']??'')==='shipping' && $_SERVER['REQUEST_METHOD']==='POST' && in_array((string)($_POST['action']??''),['ship_save','ship_delete','ship_reset'],true)){
 try {
  if(empty($_SESSION['admin']))throw new Exception('Please sign in to manage the calendar.');
  if(!hash_equals((string)$_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new Exception('Your session expired. Refresh and try again.');
  $raw=$db->query("SELECT value FROM settings WHERE key='shipping_calendar_edits'")->fetchColumn();
  $edits=is_string($raw)?json_decode($raw,true):[];
  if(!is_array($edits))$edits=[];
  $action=(string)$_POST['action'];
  $id=trim((string)($_POST['event_id']??''));
  if($action==='ship_reset')$edits=[];
  else {
   if($id==='new')$id='custom-'.bin2hex(random_bytes(8));
   if(!preg_match('/^(default-[0-9]{1,2}|custom-[a-f0-9]{16})$/',$id))throw new Exception('Invalid calendar entry.');
   if($action==='ship_delete')$edits[$id]=['deleted'=>true];
   else {
    $start=trim((string)($_POST['date']??''));$end=trim((string)($_POST['end']??''));
    $validDate=static function(string $d):bool{$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$d);return $dt!==false && $dt->format('Y-m-d')===$d;};
    if(!$validDate($start)||!$validDate($end)||$end<$start)throw new Exception('Enter valid dates, with the end on or after the start.');
    $name=trim((string)($_POST['name']??''));$detail=trim((string)($_POST['detail']??''));
    if($name===''||mb_strlen($name)>100||mb_strlen($detail)>5000)throw new Exception('Use a name up to 100 characters and description up to 5000 characters.');
    $risk=(string)($_POST['risk']??'medium');if(!in_array($risk,['low','medium','high','critical'],true))throw new Exception('Choose a valid risk level.');
    $edits[$id]=['date'=>$start,'end'=>$end,'name'=>$name,'detail'=>$detail,'risk'=>$risk,'status'=>'manually managed'];
   }
  }
  if(count($edits)>150)throw new Exception('Calendar limit reached.');
  saveSetting($db,'shipping_calendar_edits',json_encode($edits,JSON_THROW_ON_ERROR));
  header('Location: ./?view=shipping&mode=manage&calendar=saved');exit;
 }catch(Throwable $ex){$error=$ex->getMessage();}
}

if(!empty($_SESSION['admin']) && time()-(int)($_SESSION['last']??0)<=3600){try{ankhCatalogueLinkIdentities($db);}catch(Throwable $linkError){error_log('ANKH identity link update: '.$linkError->getMessage());}}

if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
 if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) throw new Exception('Please refresh the page and try again.');
 $action=$_POST['action']??'';
 // Snapshot only operational fields; do not store customer contact details in audit records.
 $auditedActions=['packing_pick','order_backorder','order','order_edit','order_delete','status','order_dates','delivery_mark','order_schedule','order_delivery_section','order_preparation','order_reconstituted','payment_add','payment_delete'];
 $auditTrack=in_array((string)$action,$auditedActions,true);
 $auditOrderId=$auditTrack?(int)($_POST['id']??0):0;
 $auditPaymentBefore=null;
 if($action==='payment_delete'){
  $aq=$db->prepare('SELECT id,order_id,amount,method FROM payments WHERE id=?');$aq->execute([(int)($_POST['payment_id']??0)]);$auditPaymentBefore=$aq->fetch(PDO::FETCH_ASSOC)?:null;
  if($auditPaymentBefore)$auditOrderId=(int)$auditPaymentBefore['order_id'];
 }
 $auditColumns='id,back_order,back_order_since,activated_at,status,payment_date,delivery_date,scheduled_delivery_date,delivery_section_override,reconstituted,packaged,assigned_to,payment_method,delivery_charge,postage_cost,payment_fee';
 $auditSnapshot=static function(int $id)use($db,$auditColumns):?array{
  if($id<1)return null;
  $q=$db->prepare('SELECT '.$auditColumns.' FROM orders WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row)return null;
  $q=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=?');$q->execute([$id]);$row['amount_paid_pence']=(int)$q->fetchColumn();
  $q=$db->prepare('SELECT product_id,name,quantity,price,presentation FROM items WHERE order_id=? ORDER BY id');$q->execute([$id]);$itemRows=$q->fetchAll(PDO::FETCH_ASSOC);
  $row['item_lines']=count($itemRows);
  $row['item_units']=array_sum(array_map(static fn(array $item)=>(int)$item['quantity'],$itemRows));
  $row['item_fingerprint']=hash('sha256',json_encode($itemRows,JSON_THROW_ON_ERROR));
  return $row;
 };
 $auditBefore=$auditTrack?$auditSnapshot($auditOrderId):null;
 if ($action==='login') {
  $ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');$q=$db->prepare('SELECT * FROM attempts WHERE ip=?');$q->execute([$ip]);$a=$q->fetch(PDO::FETCH_ASSOC);
  if($a && $a['failures']>=5 && time()-(int)$a['started']<900) throw new Exception('Too many attempts. Please wait 15 minutes.');
  if($hasIndividualLogins ? !ankhAuthCredentialsValid($db,(string)($_POST['username']??''),(string)($_POST['password']??'')) : !password_verify((string)($_POST['password']??''),$adminPasswordHash)){
   if(!$a || time()-(int)$a['started']>=900){$q=$db->prepare('INSERT OR REPLACE INTO attempts VALUES (?,1,?)');$q->execute([$ip,time()]);}
   else{$q=$db->prepare('UPDATE attempts SET failures=failures+1 WHERE ip=?');$q->execute([$ip]);}
   throw new Exception('Password not recognised.');
  }
  $db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]);
  if($hasIndividualLogins)ankhAuthPasswordLogin($db,(string)($_POST['username']??''),(string)($_POST['password']??''),!empty($_POST['remember']));
  else{session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['last']=time();}
 } else {
  if(empty($_SESSION['admin']) || time()-($_SESSION['last']??0)>3600) throw new Exception('Please sign in again.');
  if($action==='logout'){if($hasIndividualLogins)ankhAuthClearRemember($db);$_SESSION=[];session_destroy();header('Location: ./');exit;}
  if($action==='business_alert_save'){
    require_once __DIR__.'/business-alerts.php';
    $prefs=[];foreach(['daily','new_order','stock','payment','delivery','recurring'] as $key)$prefs[$key]=isset($_POST['alert_'.$key]);
    // All notifications use the fixed shared business inbox.
    saveSetting($db,'business_alert_prefs',json_encode($prefs,JSON_THROW_ON_ERROR));
    header('Location: ./?view=business_alerts&saved=1');exit;
   }
   if($action==='business_alert_test'){
    require_once __DIR__.'/business-alerts.php';
    $result=ankhAlertDispatch($db,true);
    if(!empty($result['errors']))throw new Exception('Email failed: '.implode('; ',$result['errors']));
    header('Location: ./?view=business_alerts&sent=1');exit;
   }
   if($action==='business_alert_retry_order'){
    require_once __DIR__.'/business-alerts.php';
    $retryId=filter_var($_POST['id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($retryId===false)throw new Exception('Choose a valid order.');
    $retryResult=ankhSendNewOrderNotice($db,(int)$retryId,true);
    if($retryResult!=='accepted')throw new Exception('Google SMTP did not accept the email.');
    $_SESSION['flash']='Google accepted the ANK-'.str_pad((string)$retryId,4,'0',STR_PAD_LEFT).' order notification for info@ankhpeptides.com.';
    header('Location: ./?view=business_alerts');exit;
   }
   if($action==='business_alert_preview_order'){
    require_once __DIR__.'/business-alerts.php';
    $lastOrderId=(int)$db->query('SELECT id FROM orders ORDER BY id DESC LIMIT 1')->fetchColumn();
    if($lastOrderId<1)throw new Exception('No existing order is available for an email preview.');
    [$previewSubject,$previewText,$previewHtml]=ankhNewOrderBody($db,$lastOrderId);
    ankhSmtpSend('info@ankhpeptides.com','PREVIEW — '.$previewSubject,$previewText,$previewHtml);
    header('Location: ./?view=business_alerts&order_preview_sent=1');exit;
   }
   if($action==='business_supply_save')saveBusinessSupply($db,$_POST);
  if($action==='supply_item_save')saveSupplyItemWithSource($db,$_POST);
  if($action==='supply_item_change')changeSupplyCupboard($db,$_POST);
  if($action==='supply_delivery_receive')receiveBusinessSupply($db,$_POST);
  if($action==='notification_state'){
   $notificationId=preg_replace('/[^a-zA-Z0-9:_\-.]/','',substr((string)($_POST['notification_id']??''),0,180));
   $stateAction=(string)($_POST['state_action']??'');$days=(int)($_POST['days']??1);
   if($notificationId===''||!in_array($stateAction,['dismiss','snooze'],true))throw new Exception('Choose a valid notification action.');
   $rawState=json_decode(setting($db,'notification_state','{}'),true);$savedState=is_array($rawState)?$rawState:[];
   if($stateAction==='dismiss')$savedState[$notificationId]=['dismissed'=>true,'updated'=>time()];
   else{$days=in_array($days,[1,3,7],true)?$days:1;$savedState[$notificationId]=['until'=>time()+($days*86400),'updated'=>time()];}
   if(count($savedState)>120){uasort($savedState,fn($a,$b)=>(int)($b['updated']??0)<=>(int)($a['updated']??0));$savedState=array_slice($savedState,0,120,true);}
   saveSetting($db,'notification_state',json_encode($savedState,JSON_UNESCAPED_SLASHES));
   $return=in_array((string)($_POST['return']??'dashboard'),['dashboard','orders','new','edit','products','supplier','stock','fridge_jay','fridge_tony','customers','customer','balances','reta','sheets','reports','reorder','more','saved'],true)?(string)$_POST['return']:'dashboard';
   $suffix=$return==='customer'&&isset($_POST['customer_id'])?'&id='.(int)$_POST['customer_id']:'';
   header('Location: ./?view='.rawurlencode($return).$suffix);exit;
  }
  if(str_starts_with($action,'supplier_centre_'))sc_post($db,$action);
  if($action==='stock_open'){
   $productId=(int)($_POST['product_id']??0);$opening=filter_var($_POST['opening_qty']??'',FILTER_VALIDATE_INT);$location=stockLocationName((string)($_POST['location']??''));
   if($productId<1||$opening===false||$opening<0||$opening>999999||!in_array($location,['Jay','Tony'],true)||($_POST['confirm_opening']??'')!=='1')throw new Exception('Enter and confirm a physical count for Jay or Tony’s fridge.');
   $q=$db->prepare('SELECT stock_tracking,stock_jay_qty,stock_tony_qty FROM products WHERE id=?');$q->execute([$productId]);$product=$q->fetch(PDO::FETCH_ASSOC);if(!$product)throw new Exception('Product could not be found.');
   $wasTracked=(int)$product['stock_tracking']===1;$column=stockLocationColumn($location);$before=(int)$product[$column];$reserved=$wasTracked?activeReservedStock($db,$productId,$location):0;
   $available=(int)$opening-$reserved;$delta=$available-$before;
   $db->beginTransaction();
   if(!$wasTracked)$db->prepare('UPDATE products SET stock_tracking=1,stock_tracking_since=? WHERE id=?')->execute([(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d'),$productId]);
   setProductStockAtLocation($db,$productId,$location,$available);
   addStockMovement($db,$productId,$wasTracked?'count_adjustment':'opening',$delta,$wasTracked?'Physical count reconciled; reservations preserved':'Confirmed opening fridge count',null,'','','',null,$location);
   $db->commit();
  }
  if($action==='stock_receive'){
   $productId=(int)($_POST['product_id']??0);$quantity=filter_var($_POST['quantity']??'',FILTER_VALIDATE_INT);$location=stockLocationName((string)($_POST['location']??''));$supplier=trim((string)($_POST['supplier']??''));$batch=trim((string)($_POST['batch_reference']??''));$expiry=trim((string)($_POST['expiry_date']??''));$note=trim((string)($_POST['note']??''));$unitCostRaw=trim((string)($_POST['unit_cost']??''));$unitCost=$unitCostRaw===''?null:postedMoneyPence($unitCostRaw,'unit cost');
   if($productId<1||$quantity===false||$quantity<1||$quantity>999999||!in_array($location,['Jay','Tony','Unallocated'],true)||strlen($supplier)>160||strlen($batch)>160||strlen($note)>500)throw new Exception('Enter valid stock receipt details and choose where the stock is going.');
   if($expiry!==''&&(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$expiry)||!checkdate((int)substr($expiry,5,2),(int)substr($expiry,8,2),(int)substr($expiry,0,4))))throw new Exception('Enter a valid expiry date.');
   $q=$db->prepare('SELECT stock_tracking FROM products WHERE id=?');$q->execute([$productId]);$tracked=$q->fetchColumn();if($tracked===false)throw new Exception('Product could not be found.');
   $db->beginTransaction();if((int)$tracked!==1)$db->prepare('UPDATE products SET stock_tracking=1,stock_tracking_since=? WHERE id=?')->execute([(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d'),$productId]);adjustProductStockAtLocation($db,$productId,$location,(int)$quantity);addStockMovement($db,$productId,'received',(int)$quantity,$note!==''?$note:'Stock added',null,$supplier,$batch,$expiry,$unitCost,$location);$movementId=(int)$db->lastInsertId();$db->commit();rememberStockUndo($productId,[$location=>-(int)$quantity],[$location=>$movementId],(int)$tracked===1,'received','Stock receipt');
  }
  if($action==='stock_quick_adjust'){
   $productId=(int)($_POST['product_id']??0);$delta=filter_var($_POST['delta']??'',FILTER_VALIDATE_INT);$location=stockLocationName((string)($_POST['location']??''));
   if($productId<1||$delta===false||!in_array((int)$delta,[-10,-1,1,10],true)||!in_array($location,['Jay','Tony'],true))throw new Exception('Choose a valid quick stock adjustment.');
   $column=stockLocationColumn($location);$q=$db->prepare("SELECT stock_tracking,{$column} AS available FROM products WHERE id=?");$q->execute([$productId]);$product=$q->fetch(PDO::FETCH_ASSOC);
   if(!$product)throw new Exception('Product could not be found.');if((int)$product['stock_tracking']!==1)throw new Exception('Confirm an opening fridge count first.');
   $before=(int)$product['available'];if((int)$delta<0&&$before<abs((int)$delta))throw new Exception('There is not enough available stock to remove that quantity. Orders already reserved are protected.');
   $db->beginTransaction();adjustProductStockAtLocation($db,$productId,$location,(int)$delta);
   $movementType=(int)$delta>0?'manual_add':'manual_remove';$movementNote=(int)$delta>0?'Quick stock added to '.$location.'’s fridge':'Quick stock removed from '.$location.'’s fridge';
   addStockMovement($db,$productId,$movementType,(int)$delta,$movementNote,null,'','','',null,$location);$movementId=(int)$db->lastInsertId();$db->commit();rememberStockUndo($productId,[$location=>-(int)$delta],[$location=>$movementId],true,$movementType,'Quick stock change');
  }
  if($action==='stock_fridge_counts'){
   $productId=filter_var($_POST['product_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   $counts=[];$expected=[];
   foreach(['Jay','Tony'] as $person){$key=strtolower($person);$counts[$person]=filter_var($_POST[$key.'_physical']??'',FILTER_VALIDATE_INT);$expected[$person]=filter_var($_POST['expected_'.$key.'_physical']??'',FILTER_VALIDATE_INT);
    if($productId===false||$counts[$person]===false||$counts[$person]<0||$counts[$person]>999999||$expected[$person]===false||$expected[$person]<0)throw new Exception('Enter valid physical counts for both fridges.');}
   $db->beginTransaction();
   $q=$db->prepare('SELECT stock_tracking,stock_jay_qty,stock_tony_qty FROM products WHERE id=?');$q->execute([$productId]);$product=$q->fetch(PDO::FETCH_ASSOC);
   if(!$product)throw new Exception('Product could not be found.');if((int)$product['stock_tracking']!==1)throw new Exception('Set up stock tracking on the product first.');
   $changes=[];$movementIds=[];$updates=[];
   foreach(['Jay','Tony'] as $person){
    $before=(int)$product[stockLocationColumn($person)];$reserved=activeReservedStock($db,(int)$productId,$person);$physical=$before+$reserved;
    if($physical!==$expected[$person])throw new Exception($person.'’s stock changed since this page was opened. Refresh and check the latest count before saving.');
    $updates[$person]=['available'=>$counts[$person]-$reserved,'delta'=>$counts[$person]-$physical,'physical'=>$physical];
   }
   foreach($updates as $person=>$update){if($update['delta']===0)continue;
    setProductStockAtLocation($db,(int)$productId,$person,$update['available']);
    addStockMovement($db,(int)$productId,'count_adjustment',$update['delta'],'Physical count corrected from '.$update['physical'].' to '.$counts[$person].' on Stock Control; reservations preserved',null,'','','',null,$person);
    $changes[$person]=-$update['delta'];$movementIds[$person]=(int)$db->lastInsertId();
   }
   $db->commit();if($changes)rememberStockUndo((int)$productId,$changes,$movementIds,true,'count_adjustment','Fridge counts');
  }
  if($action==='stock_count'){
   $productId=(int)($_POST['product_id']??0);$counted=filter_var($_POST['counted_qty']??'',FILTER_VALIDATE_INT);$location=stockLocationName((string)($_POST['location']??''));$note=trim((string)($_POST['note']??''));
   if($productId<1||$counted===false||$counted<0||$counted>999999||!in_array($location,['Jay','Tony'],true)||strlen($note)>500)throw new Exception('Enter a valid fridge stock count.');
   $q=$db->prepare('SELECT stock_tracking,stock_jay_qty,stock_tony_qty FROM products WHERE id=?');$q->execute([$productId]);$product=$q->fetch(PDO::FETCH_ASSOC);if(!$product)throw new Exception('Product could not be found.');if((int)$product['stock_tracking']!==1)throw new Exception('Confirm an opening fridge count first.');
   $column=stockLocationColumn($location);$before=(int)$product[$column];$reserved=activeReservedStock($db,$productId,$location);$beforePhysical=$before+$reserved;
   $available=(int)$counted-$reserved;$delta=$available-$before;
   if($delta!==0){$countNote='Physical count corrected from '.$beforePhysical.' to '.(int)$counted.'; reservations preserved'.($note!==''?' · '.$note:'');$db->beginTransaction();setProductStockAtLocation($db,$productId,$location,$available);addStockMovement($db,$productId,'count_adjustment',$delta,$countNote,null,'','','',null,$location);$movementId=(int)$db->lastInsertId();$db->commit();rememberStockUndo($productId,[$location=>-$delta],[$location=>$movementId],true,'count_adjustment','Physical stock count');}
  }
  if($action==='stock_transfer'){
   $productId=(int)($_POST['product_id']??0);$quantity=filter_var($_POST['quantity']??'',FILTER_VALIDATE_INT);$from=stockLocationName((string)($_POST['from_location']??''));$to=stockLocationName((string)($_POST['to_location']??''));$note=trim((string)($_POST['note']??''));
   if($productId<1||$quantity===false||$quantity<1||$quantity>999999||!in_array($from,['Jay','Tony','Unallocated'],true)||!in_array($to,['Jay','Tony','Unallocated'],true)||$from===$to||strlen($note)>500)throw new Exception('Choose a valid source and destination fridge, and enter a quantity.');
   $column=stockLocationColumn($from);$q=$db->prepare("SELECT stock_tracking,{$column} AS available FROM products WHERE id=?");$q->execute([$productId]);$product=$q->fetch(PDO::FETCH_ASSOC);if(!$product)throw new Exception('Product could not be found.');if((int)$product['stock_tracking']!==1)throw new Exception('Confirm an opening fridge count first.');if((int)$product['available']<(int)$quantity)throw new Exception('There is not enough stock in that location to transfer.');
   $db->beginTransaction();adjustProductStockAtLocation($db,$productId,$from,-(int)$quantity);adjustProductStockAtLocation($db,$productId,$to,(int)$quantity);$transferNote=$note!==''?$note:'Stock transferred between locations';addStockMovement($db,$productId,'transfer_out',-(int)$quantity,$transferNote,null,'','','',null,$from);$fromMovementId=(int)$db->lastInsertId();addStockMovement($db,$productId,'transfer_in',(int)$quantity,$transferNote,null,'','','',null,$to);$toMovementId=(int)$db->lastInsertId();$db->commit();rememberStockUndo($productId,[$from=>(int)$quantity,$to=>-(int)$quantity],[$from=>$fromMovementId,$to=>$toMovementId],true,'transfer','Stock transfer');
  }
  if($action==='stock_undo'){
   $undo=$_SESSION['stock_undo']??null;
   if(!is_array($undo)||time()>(int)($undo['expires']??0))throw new Exception('The undo window has expired. No stock was changed.');
   $productId=(int)($undo['product_id']??0);$changes=is_array($undo['changes']??null)?$undo['changes']:[];$movementIds=is_array($undo['movement_ids']??null)?$undo['movement_ids']:[];
   if($productId<1||!$changes||count($changes)!==count($movementIds))throw new Exception('This stock change cannot be undone.');
   $q=$db->prepare('SELECT stock_tracking,stock_jay_qty,stock_tony_qty,stock_unallocated_qty FROM products WHERE id=?');$q->execute([$productId]);$product=$q->fetch(PDO::FETCH_ASSOC);if(!$product)throw new Exception('Product could not be found.');
   $nextByLocation=[];
   foreach($changes as $location=>$change){$location=stockLocationName((string)$location);if(!in_array($location,['Jay','Tony','Unallocated'],true))throw new Exception('This stock location cannot be undone.');
    $latest=$db->prepare('SELECT id FROM stock_movements WHERE product_id=? AND location=? ORDER BY id DESC LIMIT 1');$latest->execute([$productId,$location]);
    if((int)$latest->fetchColumn()!==(int)($movementIds[$location]??0))throw new Exception('A newer stock movement exists for this product. The old change can no longer be undone safely.');
    $column=stockLocationColumn($location);$next=(int)$product[$column]+(int)$change;if($next+activeReservedStock($db,$productId,$location)<0||$next>999999)throw new Exception('Stock has since been committed or changed, so this action can no longer be undone safely.');
    $nextByLocation[$location]=$next;
   }
   $db->beginTransaction();
   foreach($changes as $location=>$change)adjustProductStockAtLocation($db,$productId,(string)$location,(int)$change);
   if(($undo['movement_type']??'')==='transfer'){
    foreach($changes as $location=>$change)if((int)$change<0)addStockMovement($db,$productId,'transfer_out',(int)$change,'Undo of recent stock transfer',null,'','','',null,(string)$location);
    foreach($changes as $location=>$change)if((int)$change>0)addStockMovement($db,$productId,'transfer_in',(int)$change,'Undo of recent stock transfer',null,'','','',null,(string)$location);
   }else{
    $inverseType=($undo['movement_type']??'')==='count_adjustment'?'count_adjustment':((int)reset($changes)>0?'manual_add':'manual_remove');
    foreach($changes as $location=>$change)addStockMovement($db,$productId,$inverseType,(int)$change,'Undo of recent '.(string)($undo['label']??'stock change'),null,'','','',null,(string)$location);
   }
   if(empty($undo['tracking_before']) && array_sum($nextByLocation)===0 && activeReservedStock($db,$productId,'Jay')===0 && activeReservedStock($db,$productId,'Tony')===0)$db->prepare("UPDATE products SET stock_tracking=0,stock_tracking_since='' WHERE id=?")->execute([$productId]);
   $db->commit();unset($_SESSION['stock_undo']);$_SESSION['flash']='Stock change undone.';header('Location: ./?view=stock');exit;
  }
  if($action==='supplier_order_edit'){
   editIncomingSupplierOrder($db,(int)($_POST['supplier_order_id']??0),$_POST);
  }
  if($action==='supplier_order_create'){
   $supplier=trim((string)($_POST['supplier']??''));$expected=trim((string)($_POST['expected_date']??''));$note=trim((string)($_POST['note']??''));$productIds=$_POST['product_id']??[];$packs=$_POST['packs']??[];$costs=$_POST['unit_cost']??[];
   if($supplier===''||strlen($supplier)>160||strlen($note)>500)throw new Exception('Enter a supplier name.');
   if($expected!==''&&(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$expected)||!checkdate((int)substr($expected,5,2),(int)substr($expected,8,2),(int)substr($expected,0,4))))throw new Exception('Enter a valid expected delivery date.');
   if(!is_array($productIds)||!is_array($packs)||!count($productIds))throw new Exception('Add at least one product to the supplier order.');
   $lines=[];foreach($productIds as $idx=>$rawProductId){$pid=(int)$rawProductId;$packCount=filter_var($packs[$idx]??'',FILTER_VALIDATE_INT);if($pid<1||$packCount===false||$packCount<1||$packCount>999)continue;$q=$db->prepare('SELECT id,supplier_pack_size,cost FROM products WHERE id=?');$q->execute([$pid]);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)continue;$packSize=max(1,(int)($p['supplier_pack_size']??10));$costRaw=trim((string)($costs[$idx]??''));$unitCost=$costRaw===''?($p['cost']===null?null:(int)$p['cost']):postedMoneyPence($costRaw,'unit cost');$lines[]=['product_id'=>$pid,'packs'=>(int)$packCount,'pack_size'=>$packSize,'quantity'=>(int)$packCount*$packSize,'unit_cost'=>$unitCost];}
   if(!$lines)throw new Exception('Add at least one valid product and pack quantity.');
   $db->beginTransaction();$db->prepare('INSERT INTO supplier_orders(supplier,expected_date,status,note,created) VALUES (?,?,?,?,?)')->execute([$supplier,$expected,'Ordered',$note,gmdate('c')]);$supplierOrderId=(int)$db->lastInsertId();$ins=$db->prepare('INSERT INTO supplier_order_items(supplier_order_id,product_id,packs,pack_size,quantity,unit_cost,received_qty) VALUES (?,?,?,?,?,?,0)');foreach($lines as $line)$ins->execute([$supplierOrderId,$line['product_id'],$line['packs'],$line['pack_size'],$line['quantity'],$line['unit_cost']]);$db->commit();
  }
  if($action==='supplier_stock_receive'){
   $itemId=(int)($_POST['supplier_item_id']??0);$jay=filter_var($_POST['jay_qty']??0,FILTER_VALIDATE_INT);$tony=filter_var($_POST['tony_qty']??0,FILTER_VALIDATE_INT);$holding=filter_var($_POST['unallocated_qty']??0,FILTER_VALIDATE_INT);
   if($itemId<1||$jay===false||$tony===false||$holding===false||$jay<0||$tony<0||$holding<0)throw new Exception('Enter valid received quantities.');
   $receiveTotal=(int)$jay+(int)$tony+(int)$holding;if($receiveTotal<1)throw new Exception('Enter how many units arrived and where they are going.');
   $db->beginTransaction();$db->prepare('UPDATE supplier_order_items SET received_qty=received_qty WHERE id=?')->execute([$itemId]);
   $q=$db->prepare('SELECT soi.*,so.supplier,so.id supplier_order_id FROM supplier_order_items soi JOIN supplier_orders so ON so.id=soi.supplier_order_id WHERE soi.id=? AND so.status<>"Cancelled"');$q->execute([$itemId]);$line=$q->fetch(PDO::FETCH_ASSOC);if(!$line)throw new Exception('Incoming stock line could not be found.');$remaining=(int)$line['quantity']-(int)$line['received_qty'];if($receiveTotal>$remaining)throw new Exception('Only '.$remaining.' units remain outstanding on this supplier order.');
   $q=$db->prepare('SELECT stock_tracking FROM products WHERE id=?');$q->execute([(int)$line['product_id']]);$tracked=(int)$q->fetchColumn();if($tracked!==1)$db->prepare('UPDATE products SET stock_tracking=1,stock_tracking_since=? WHERE id=?')->execute([(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d'),(int)$line['product_id']]);
   foreach(['Jay'=>(int)$jay,'Tony'=>(int)$tony,'Unallocated'=>(int)$holding] as $receiveLocation=>$receiveQty){if($receiveQty<1)continue;adjustProductStockAtLocation($db,(int)$line['product_id'],$receiveLocation,$receiveQty);addStockMovement($db,(int)$line['product_id'],'supplier_received',$receiveQty,'Received from supplier order #'.(int)$line['supplier_order_id'],null,(string)$line['supplier'],'','',null,$receiveLocation);}
   $db->prepare('UPDATE supplier_order_items SET received_qty=received_qty+? WHERE id=?')->execute([$receiveTotal,$itemId]);$orderId=(int)$line['supplier_order_id'];$q=$db->prepare('SELECT SUM(quantity) total_qty,SUM(received_qty) received_qty FROM supplier_order_items WHERE supplier_order_id=?');$q->execute([$orderId]);$totals=$q->fetch(PDO::FETCH_ASSOC);$status=(int)$totals['received_qty']>=(int)$totals['total_qty']?'Received':'Part received';$db->prepare('UPDATE supplier_orders SET status=? WHERE id=?')->execute([$status,$orderId]);$db->commit();
  }
  if($action==='supplier_order_cancel'){
   $supplierOrderId=(int)($_POST['supplier_order_id']??0);$q=$db->prepare('SELECT COALESCE(SUM(received_qty),0) FROM supplier_order_items WHERE supplier_order_id=?');$q->execute([$supplierOrderId]);if((int)$q->fetchColumn()>0)throw new Exception('This supplier order has already been partly received and cannot be cancelled.');$db->prepare("UPDATE supplier_orders SET status='Cancelled' WHERE id=?")->execute([$supplierOrderId]);
  }
  if($action==='reminder_request_review'){
   if(!is_string($_POST['decision']??null)||!is_string($_POST['admin_note']??''))throw new Exception('Check the reminder decision.');
   ankhReviewReminderRequest($db,ankhCustomerInteractionStore(),(int)($_POST['request_id']??0),$_POST['decision'],(string)($_POST['admin_note']??''));
   header('Location: ./?view=enquiries#reminder-requests',true,303);exit;
  }
  if($action==='catalogue_enquiry_reply'){
   if(!is_string($_POST['reply']??null)||!is_string($_POST['enquiry_status']??null))throw new Exception('Check the reply and status.');
   ankhCustomerReply(ankhCustomerInteractionStore(),(int)($_POST['enquiry_id']??0),$_POST['reply'],$_POST['enquiry_status']);
   header('Location: ./?view=enquiries',true,303);exit;
  }
  if($action==='public_catalogue_review'){
   $id=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$id)throw new Exception('Select a valid public catalogue product.');
   ankhPublicReviewSave($db,(int)$id,(string)($_POST['review_decision']??''),$_POST);
  }
  if($action==='product_catalogue_content'){
   $catalogueProductId=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if(!$catalogueProductId || !is_string($_POST['catalogue_description']??''))throw new Exception('Check the catalogue product details.');
   $cataloguePhoto=ankhCataloguePhotoUpload($_FILES['catalogue_photo']??[]);
   ankhCatalogueSaveContent($db,(int)$catalogueProductId,(string)($_POST['catalogue_description']??''),$cataloguePhoto,($_POST['remove_catalogue_photo']??'')==='1',($_POST['catalogue_visible']??'')==='1',($_POST['catalogue_editor']??'')==='unified'?[]:(isset($_POST['catalogue_information'])?(is_array($_POST['catalogue_information'])?$_POST['catalogue_information']:throw new Exception('Check product information.')):null));
  }
  if($action==='product_catalogue_visibility'){
   $catalogueProductId=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if($catalogueProductId===false || $catalogueProductId===null)throw new Exception('Choose a valid product.');
   ankhCatalogueSetVisibility($db,(int)$catalogueProductId,($_POST['catalogue_visible']??'')==='1');
  }
  if($action==='product'){
   $name=trim($_POST['name']??'');$price=filter_var($_POST['price']??'',FILTER_VALIDATE_FLOAT);
   $costRaw=trim((string)($_POST['cost']??''));$ffRaw=trim((string)($_POST['family_friends_price']??''));$stockRaw=trim((string)($_POST['stock_qty']??''));$lowRaw=trim((string)($_POST['low_stock_at']??'5'));$packRaw=trim((string)($_POST['supplier_pack_size']??'10'));
   $cost=$costRaw===''?null:filter_var($costRaw,FILTER_VALIDATE_FLOAT);$familyFriendsPrice=$ffRaw===''?null:filter_var($ffRaw,FILTER_VALIDATE_FLOAT);$stock=$stockRaw===''?null:filter_var($stockRaw,FILTER_VALIDATE_INT);$low=filter_var($lowRaw,FILTER_VALIDATE_INT);$packSize=filter_var($packRaw,FILTER_VALIDATE_INT);
   if(!$name || strlen($name)>160 || $price===false || $price<0 || $price>100000 || ($costRaw!==''&&($cost===false||$cost<0||$cost>100000)) || ($ffRaw!==''&&($familyFriendsPrice===false||$familyFriendsPrice<0||$familyFriendsPrice>100000)) || ($stockRaw!==''&&($stock===false||$stock<0||$stock>999999)) || $low===false || $low<0 || $low>999999 || $packSize===false || $packSize<1 || $packSize>999999)throw new Exception('Enter valid product, price, stock and supplier pack details.');
   $id=(int)($_POST['id']??0);$costPence=$cost===null?null:(int)round($cost*100);
   if($id){$q=$db->prepare('SELECT stock_tracking,stock_qty FROM products WHERE id=?');$q->execute([$id]);$existingProduct=$q->fetch(PDO::FETCH_ASSOC);if(!$existingProduct)throw new Exception('Product could not be found.');if((int)$existingProduct['stock_tracking']===1)$stock=$existingProduct['stock_qty']===null?0:(int)$existingProduct['stock_qty'];$q=$db->prepare('UPDATE products SET name=?,price=?,family_friends_price=?,cost=?,stock_qty=?,low_stock_at=?,supplier_pack_size=?,active=? WHERE id=?');$q->execute([$name,(int)round($price*100),$familyFriendsPrice===null?null:(int)round($familyFriendsPrice*100),$costPence,$stock,$low,$packSize,isset($_POST['active'])?1:0,$id]);}
   else{$q=$db->prepare('INSERT INTO products(name,price,family_friends_price,cost,stock_qty,low_stock_at,supplier_pack_size) VALUES (?,?,?,?,?,?,?)');$q->execute([$name,(int)round($price*100),$familyFriendsPrice===null?null:(int)round($familyFriendsPrice*100),$costPence,$stock,$low,$packSize]);
    if(in_array($name,$deletedCatalogProducts,true)){$deletedCatalogProducts=array_values(array_filter($deletedCatalogProducts,fn($deletedName)=>$deletedName!==$name));saveSetting($db,'deleted_catalog_products',json_encode($deletedCatalogProducts));}
   }
  }
  if($action==='stock_untrack'){
   $productId=(int)($_POST['product_id']??0);$q=$db->prepare('SELECT id,name,stock_tracking,stock_jay_qty,stock_tony_qty,stock_unallocated_qty FROM products WHERE id=?');$q->execute([$productId]);$product=$q->fetch(PDO::FETCH_ASSOC);
   if(!$product)throw new Exception('Product could not be found.');
   if((int)$product['stock_tracking']===1){
    $db->beginTransaction();
    foreach(['Jay'=>'stock_jay_qty','Tony'=>'stock_tony_qty','Unallocated'=>'stock_unallocated_qty'] as $location=>$column){$qty=(int)($product[$column]??0);if($qty>0)addStockMovement($db,$productId,'tracking_stopped',-$qty,'Removed from Stock Control',null,'','','',null,$location);}
    $db->prepare('DELETE FROM stock_fulfilments WHERE product_id=?')->execute([$productId]);
    $db->prepare("UPDATE products SET stock_tracking=0,stock_tracking_since='',stock_qty=NULL,stock_jay_qty=0,stock_tony_qty=0,stock_unallocated_qty=0 WHERE id=?")->execute([$productId]);
    $db->commit();
   }
  }
  if($action==='product_delete'){
   $productId=(int)($_POST['product_id']??0);$q=$db->prepare('SELECT id,name FROM products WHERE id=?');$q->execute([$productId]);$product=$q->fetch(PDO::FETCH_ASSOC);
   if(!$product)throw new Exception('Product could not be found.');
   $q=$db->prepare("SELECT COUNT(DISTINCT order_id) FROM items WHERE product_id=? OR (product_id IS NULL AND lower(trim(name))=lower(trim(?)))");$q->execute([$productId,(string)$product['name']]);$usedOrders=(int)$q->fetchColumn();
   if($usedOrders>0)throw new Exception('This product has order history, so it cannot be permanently deleted. Hide it from new orders and remove it from Stock Control instead; its historical prices and costs must remain available for reports.');
   $db->beginTransaction();
   $db->prepare('DELETE FROM stock_fulfilments WHERE product_id=?')->execute([$productId]);
   $db->prepare('DELETE FROM stock_movements WHERE product_id=?')->execute([$productId]);
   $db->prepare('DELETE FROM products WHERE id=?')->execute([$productId]);
   $productName=(string)$product['name'];
   if(array_key_exists($productName,$currentRetailCatalog) && !in_array($productName,$deletedCatalogProducts,true)){$deletedCatalogProducts[]=$productName;saveSetting($db,'deleted_catalog_products',json_encode(array_values($deletedCatalogProducts)));}
   $db->commit();
  }
  if($action==='customer'){
   $customerId=(int)($_POST['id']??0);
   $customerName=trim($_POST['name']??'');$customerPhone=trim($_POST['phone']??'');$customerAddress=trim($_POST['address']??'');
   if(!$customerName || strlen($customerName)>160 || strlen($customerPhone)>40 || strlen($customerAddress)>2000)throw new Exception('Check the customer details and try again.');
   $postedPricingType=array_key_exists('pricing_type',$_POST)?trim((string)$_POST['pricing_type']):null;
   if($postedPricingType!==null&&!in_array($postedPricingType,['standard','family_friends','free'],true))throw new Exception('Choose a valid customer pricing option.');
   if($customerId){
    $q=$db->prepare('SELECT * FROM customers WHERE id=?');$q->execute([$customerId]);$oldCustomer=$q->fetch(PDO::FETCH_ASSOC);
    if(!$oldCustomer)throw new Exception('Customer could not be found.');
    $q=$db->prepare('SELECT id FROM customers WHERE lower(trim(name))=lower(trim(?)) AND trim(phone)=trim(?) AND id<>? LIMIT 1');
    $q->execute([$customerName,$customerPhone,$customerId]);
    if($q->fetchColumn())throw new Exception('A customer with that name and phone already exists.');
    $db->beginTransaction();
    $db->prepare('UPDATE customers SET name=?,phone=?,address=?,archived=0,pricing_type=COALESCE(?,pricing_type) WHERE id=?')->execute([$customerName,$customerPhone,$customerAddress,$postedPricingType,$customerId]);
    $db->prepare('UPDATE orders SET customer=?,phone=? WHERE lower(trim(customer))=lower(trim(?)) AND trim(phone)=trim(?)')->execute([$customerName,$customerPhone,$oldCustomer['name'],$oldCustomer['phone']]);
    $db->commit();
   }else{
    $q=$db->prepare("INSERT INTO customers(name,phone,address,created,archived,pricing_type) VALUES (?,?,?,?,0,?) ON CONFLICT(name,phone) DO UPDATE SET address=CASE WHEN excluded.address<>'' THEN excluded.address ELSE customers.address END, archived=0, pricing_type=CASE WHEN ?=1 THEN excluded.pricing_type ELSE customers.pricing_type END");
    $q->execute([$customerName,$customerPhone,$customerAddress,gmdate('c'),$postedPricingType??'standard',$postedPricingType!==null?1:0]);
   }
  }
  if($action==='customer_pricing'){
   $customerId=(int)($_POST['customer_id']??0);$pricingType=(string)($_POST['pricing_type']??'');
   if(!in_array($pricingType,['standard','family_friends','free'],true))throw new Exception('Choose a valid customer pricing option.');
   $q=$db->prepare('UPDATE customers SET pricing_type=? WHERE id=?');$q->execute([$pricingType,$customerId]);
   if(!$q->rowCount())throw new Exception('Customer could not be found.');
  }
  if($action==='customer_archive'){
   $customerId=(int)($_POST['id']??0);$archive=($_POST['archive']??'1')==='1'?1:0;
   $q=$db->prepare('UPDATE customers SET archived=? WHERE id=?');$q->execute([$archive,$customerId]);
   if(!$q->rowCount())throw new Exception('Customer could not be found.');
  }
  if($action==='payment_add'){
   $orderId=(int)($_POST['id']??0);$method=trim((string)($_POST['method']??''));$note=trim((string)($_POST['note']??''));$date=orderActionDate((string)($_POST['payment_date']??''),'payment');$paymentType=(string)($_POST['payment_type']??'partial');
   $balance=orderBalancePence($db,$orderId);if($balance<=0)throw new Exception('This order has no outstanding balance.');
   if(!in_array($paymentType,['full','partial'],true))throw new Exception('Choose paid in full or part payment.');
   $amount=$paymentType==='full'?$balance:postedMoneyPence($_POST['amount']??'','payment');
   if($amount<=0)throw new Exception('Enter a payment amount greater than zero.');if(!in_array($method,$paymentMethods,true))throw new Exception('Choose how the payment was received.');if(strlen($note)>500)throw new Exception('Payment note is too long.');if($amount>$balance)throw new Exception('Payment is more than the outstanding balance of '.money($balance).'.');
   $created=(new DateTimeImmutable($date.' 12:00:00',new DateTimeZone('Europe/London')))->setTimezone(new DateTimeZone('UTC'))->format('c');
   $savedNote=$note!==''?$note:($paymentType==='full'?'Paid in full':'Part payment');
   $db->prepare('INSERT INTO payments(order_id,amount,method,note,created) VALUES (?,?,?,?,?)')->execute([$orderId,$amount,$method,$savedNote,$created]);refreshOrderPaymentState($db,$orderId);$syncError=syncOrderToSheet($db,$orderId);
  }
  if($action==='payment_delete'){
   $paymentId=(int)($_POST['payment_id']??0);$q=$db->prepare('SELECT order_id FROM payments WHERE id=?');$q->execute([$paymentId]);$orderId=(int)$q->fetchColumn();if($orderId<1)throw new Exception('Payment could not be found.');
   $db->prepare('DELETE FROM payments WHERE id=?')->execute([$paymentId]);refreshOrderPaymentState($db,$orderId);$syncError=syncOrderToSheet($db,$orderId);
  }
  if($action==='reta_cycle_stop'){
   $cycleId=(int)($_POST['cycle_id']??0);$reason=trim((string)($_POST['reason']??'Stopped manually'))?:'Stopped manually';if(strlen($reason)>240)$reason=substr($reason,0,240);
   $nowCycle=gmdate('c');$q=$db->prepare('UPDATE reta_cycles SET active=0,ended_at=?,end_reason=?,updated=? WHERE id=?');$q->execute([$nowCycle,$reason,$nowCycle,$cycleId]);
   if(!$q->rowCount())throw new Exception('Cycle could not be found.');
  }
  if($action==='reta_cycle_resume'){
   $cycleId=(int)($_POST['cycle_id']??0);$due=trim((string)($_POST['next_due_date']??''));$tzCycle=new DateTimeZone('Europe/London');$date=DateTimeImmutable::createFromFormat('!Y-m-d',$due,$tzCycle);
   if(!$date||$date->format('Y-m-d')!==$due)throw new Exception('Choose a valid next expected date.');
   $nowCycle=gmdate('c');$q=$db->prepare("UPDATE reta_cycles SET active=1,next_due_date=?,ended_at='',end_reason='',updated=? WHERE id=?");$q->execute([$due,$nowCycle,$cycleId]);
   if(!$q->rowCount())throw new Exception('Cycle could not be found.');
  }
  if($action==='cycle_amend'){
   $cycleId=(int)($_POST['cycle_id']??0);$due=trim((string)($_POST['next_due_date']??''));$weeks=(int)($_POST['cycle_weeks']??0);
   $tzCycle=new DateTimeZone('Europe/London');$date=DateTimeImmutable::createFromFormat('!Y-m-d',$due,$tzCycle);
   if(!$date||$date->format('Y-m-d')!==$due)throw new Exception('Choose a valid next expected date.');
   if($weeks<1||$weeks>52)throw new Exception('Choose a cycle between 1 and 52 weeks.');
   $nowCycle=gmdate('c');$q=$db->prepare("UPDATE reta_cycles SET next_due_date=?,cycle_weeks=?,cycle_days=?,updated=? WHERE id=? AND active=1");$q->execute([$due,$weeks,$weeks*7,$nowCycle,$cycleId]);
   if(!$q->rowCount())throw new Exception('Cycle could not be found.');
  }
  if($action==='reta_cycle_skip'){
   $cycleId=(int)($_POST['cycle_id']??0);$q=$db->prepare('SELECT next_due_date,cycle_days,cycle_weeks FROM reta_cycles WHERE id=? AND active=1');$q->execute([$cycleId]);$cycle=$q->fetch(PDO::FETCH_ASSOC);
   if(!$cycle)throw new Exception('Cycle could not be found.');
   $tzCycle=new DateTimeZone('Europe/London');$due=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycle['next_due_date'],$tzCycle);if(!$due)throw new Exception('The expected date is invalid.');
   $days=max(7,(int)($cycle['cycle_days']??((int)($cycle['cycle_weeks']??4)*7)));$next=$due->modify('+'.$days.' days')->format('Y-m-d');$nowCycle=gmdate('c');
   $db->prepare('UPDATE reta_cycles SET next_due_date=?,updated=? WHERE id=?')->execute([$next,$nowCycle,$cycleId]);
  }
  if($action==='reta_cycle_start_order'){
   $orderId=(int)($_POST['id']??0);$q=$db->prepare('SELECT delivery_date FROM orders WHERE id=?');$q->execute([$orderId]);$delivery=(string)($q->fetchColumn()?:'');if($delivery==='')throw new Exception('Add the delivery date first. The recurring reminder starts from the delivery date.');
   $q=$db->prepare('SELECT COUNT(*) FROM items WHERE order_id=? AND recurring=1');$q->execute([$orderId]);if((int)$q->fetchColumn()<1)throw new Exception('That order does not have any recurring products selected.');
   syncProductCyclesFromDeliveredOrder($db,$orderId,true);
  }
  if($action==='order_backorder'){
   $flag=(string)($_POST['back_order']??'');if(!in_array($flag,['0','1'],true))throw new Exception('Choose an order list.');
   setOrderBackOrder($db,(int)($_POST['id']??0),$flag==='1');$syncError=syncOrderToSheet($db,(int)$_POST['id']);
  }
  if($action==='delivery_mark'){
   assertOrderActive($db,(int)($_POST['id']??0));
   $orderId=(int)($_POST['id']??0);$deliveryDate=orderActionDate((string)($_POST['delivery_date']??''),'delivery');
   if($deliveryDate==='')throw new Exception('Choose a delivery date.');
   $q=$db->prepare('SELECT status FROM orders WHERE id=?');$q->execute([$orderId]);$currentStatus=$q->fetchColumn();
   if($currentStatus===false)throw new Exception('Order could not be found.');
   if($currentStatus==='Cancelled')throw new Exception('A cancelled order cannot be marked delivered.');
   $db->prepare('UPDATE orders SET delivery_date=? WHERE id=?')->execute([$deliveryDate,$orderId]);
   reconcileDeliveredStock($db,$orderId,true);syncProductCyclesFromDeliveredOrder($db,$orderId);$syncError=syncOrderToSheet($db,$orderId);
  }
  if($action==='order_schedule'){
   $orderId=filter_var($_POST['id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if($orderId===false)throw new Exception('Choose a valid order.');
   $db->beginTransaction();
   try{
    $q=$db->prepare('SELECT status,delivery_date,scheduled_delivery_date FROM orders WHERE id=?');$q->execute([$orderId]);$current=$q->fetch(PDO::FETCH_ASSOC);
    if(!$current)throw new Exception('Order could not be found.');
    if(in_array((string)$current['status'],['Cancelled','Delivered'],true)||trim((string)$current['delivery_date'])!=='')throw new Exception('Only an undelivered, active order can have delivery arranged.');
    $clear=(string)($_POST['schedule_clear']??'')==='1';
    $date=$clear?'':plannedDeliveryDate((string)($_POST['scheduled_delivery_date']??''),'',null,true);
    if(!$clear&&$date==='')throw new Exception('Choose a planned delivery date.');
    $db->prepare("UPDATE orders SET delivery_section_override=CASE WHEN scheduled_delivery_date=? THEN delivery_section_override ELSE '' END,scheduled_delivery_date=? WHERE id=?")->execute([$date,$date,$orderId]);
    $db->commit();
   }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
  }
  if($action==='order_delivery_section'){
   $orderId=filter_var($_POST['id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   $section=(string)($_POST['delivery_section']??'');
   if($orderId===false||$orderId===null||!in_array($section,['awaiting','future','auto'],true))throw new Exception('Choose a valid delivery section.');
   $db->beginTransaction();
   try{
    $q=$db->prepare('SELECT status,delivery_date,scheduled_delivery_date FROM orders WHERE id=?');$q->execute([$orderId]);$order=$q->fetch(PDO::FETCH_ASSOC);
    if(!$order||in_array((string)$order['status'],['Delivered','Cancelled'],true)||trim((string)$order['delivery_date'])!=='')throw new Exception('Only active undelivered orders can be moved.');
    $today=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d');
    if($section==='future' && trim((string)$order['scheduled_delivery_date'])<=$today)throw new Exception('Choose a future delivery date before moving this order to Future deliveries.');
    $db->prepare('UPDATE orders SET delivery_section_override=? WHERE id=?')->execute([$section==='auto'?'':$section,$orderId]);
    $db->commit();
   }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
  }
  if($action==='order_reconstituted'){
   $orderId=filter_var($_POST['id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   $complete=(string)($_POST['reconstituted']??'0');
   if($orderId===false||!in_array($complete,['0','1'],true))throw new Exception('Choose a valid preparation state.');
   setOrderPreparation($db,$orderId,'reconstituted',$complete==='1');
  }
  if($action==='packing_pick'){
   if(empty($_SESSION['ankh_premium_v2_preview']))throw new Exception('Enable Premium V2 to use packing checks.');
   $packingOrderId=filter_var($_POST['id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   $packingItemId=filter_var($_POST['item_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   $packingPicked=(string)($_POST['picked']??'');
   if($packingOrderId===false||$packingItemId===false||!in_array($packingPicked,['0','1'],true))throw new Exception('Choose a valid order line and pick state.');
   ankhPackingToggle($db,(int)$packingOrderId,(int)$packingItemId,$packingPicked==='1',(int)($_SESSION['admin_user_id']??0));
   recordActivity($db,'order',(int)$packingOrderId,$packingPicked==='1'?'Item picked':'Item unchecked',['item_id'=>(int)$packingItemId]);
  }
  if($action==='order_preparation'){
   $orderId=filter_var($_POST['id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   $complete=(string)($_POST['complete']??'0');
   if($orderId===false||!in_array($complete,['0','1'],true))throw new Exception('Choose a valid preparation state.');
   setOrderPreparation($db,$orderId,(string)($_POST['stage']??''),$complete==='1');
  }
  if($action==='status'){
   $newStatus=(string)($_POST['status']??'');
   if(!in_array($newStatus,$statuses,true))throw new Exception('Choose a valid status.');
   $orderId=(int)$_POST['id'];$todayAction=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d');
   $paymentDate=array_key_exists('payment_date',$_POST)?orderActionDate((string)$_POST['payment_date'],'payment'):'';
   $deliveryDate=array_key_exists('delivery_date',$_POST)?orderActionDate((string)$_POST['delivery_date'],'delivery'):'';
   $paymentMethod=trim((string)($_POST['payment_method']??''));
   if($paymentMethod!==''&&!in_array($paymentMethod,$paymentMethods,true))throw new Exception('Choose a valid payment method.');
   $q=$db->prepare('SELECT status,payment_date,delivery_date,payment_method FROM orders WHERE id=?');$q->execute([$orderId]);$existingDates=$q->fetch(PDO::FETCH_ASSOC);
   if(!$existingDates)throw new Exception('Order could not be found.');
   if(in_array($newStatus,['Packed','Dispatched','Delivered'],true))assertOrderActive($db,$orderId);
   if($newStatus==='Paid'){
    if($paymentDate==='')$paymentDate=(string)($existingDates['payment_date']?:$todayAction);
    if($paymentMethod==='' && (string)$existingDates['payment_method']==='')throw new Exception('Choose how the payment was received.');
    $useMethod=$paymentMethod!==''?$paymentMethod:(string)$existingDates['payment_method'];$balance=orderBalancePence($db,$orderId);
    if($balance>0){$created=(new DateTimeImmutable($paymentDate.' 12:00:00',new DateTimeZone('Europe/London')))->setTimezone(new DateTimeZone('UTC'))->format('c');$db->prepare('INSERT INTO payments(order_id,amount,method,note,created) VALUES (?,?,?,?,?)')->execute([$orderId,$balance,$useMethod,'Balance marked paid',$created]);}
   }
   if($newStatus==='Delivered' && $deliveryDate==='')$deliveryDate=(string)($existingDates['delivery_date']?:$todayAction);
   $db->prepare("UPDATE orders SET status=?,payment_date=CASE WHEN ?<>'' THEN ? ELSE payment_date END,delivery_date=CASE WHEN ?<>'' THEN ? ELSE delivery_date END,payment_method=CASE WHEN ?<>'' THEN ? ELSE payment_method END WHERE id=?")->execute([$newStatus,$paymentDate,$paymentDate,$deliveryDate,$deliveryDate,$paymentMethod,$paymentMethod,$orderId]);
   reconcileDeliveredStock($db,$orderId,$newStatus==='Delivered'&&(string)$existingDates['status']!=='Delivered');
   if($newStatus==='Delivered')syncProductCyclesFromDeliveredOrder($db,$orderId);
   if($newStatus==='Cancelled'){$db->prepare('UPDATE orders SET back_order=0 WHERE id=?')->execute([$orderId]);$nowCycle=gmdate('c');$db->prepare("UPDATE reta_cycles SET active=0,ended_at=?,end_reason='Latest recurring order cancelled',updated=? WHERE active=1 AND last_order_id=?")->execute([$nowCycle,$nowCycle,$orderId]);}
   $syncError=syncOrderToSheet($db,$orderId);
  }
  if($action==='order_dates'){
   $orderId=(int)($_POST['id']??0);$paymentDate=orderActionDate((string)($_POST['payment_date']??''),'payment');$deliveryDate=orderActionDate((string)($_POST['delivery_date']??''),'delivery');
   if($deliveryDate!=='')assertOrderActive($db,$orderId);
   $q=$db->prepare('UPDATE orders SET payment_date=?,delivery_date=? WHERE id=?');$q->execute([$paymentDate,$deliveryDate,$orderId]);
   if(!$q->rowCount()){$check=$db->prepare('SELECT id FROM orders WHERE id=?');$check->execute([$orderId]);if(!$check->fetchColumn())throw new Exception('Order could not be found.');}
   syncProductCyclesFromDeliveredOrder($db,$orderId);
   $syncError=syncOrderToSheet($db,$orderId);
  }
  if($action==='order_delete'){
   $orderId=(int)($_POST['id']??0);
   $q=$db->prepare('SELECT id FROM orders WHERE id=?');$q->execute([$orderId]);
   if(!$q->fetchColumn())throw new Exception('Order could not be found.');
   $reference='ANK-'.str_pad((string)$orderId,4,'0',STR_PAD_LEFT);
   $db->beginTransaction();
   $db->prepare("UPDATE orders SET status='Cancelled' WHERE id=?")->execute([$orderId]);reconcileDeliveredStock($db,$orderId,false);
   $nowCycle=gmdate('c');$db->prepare("UPDATE reta_cycles SET active=0,ended_at=?,end_reason='Source order deleted',updated=? WHERE active=1 AND last_order_id=?")->execute([$nowCycle,$nowCycle,$orderId]);
   $db->prepare('DELETE FROM payments WHERE order_id=?')->execute([$orderId]);
   $db->prepare('DELETE FROM items WHERE order_id=?')->execute([$orderId]);
   $db->prepare('DELETE FROM orders WHERE id=?')->execute([$orderId]);
   $db->commit();
   $syncError=deleteOrderFromSheet($db,$reference);
  }
  if($action==='order' || $action==='order_edit'){
   $isEdit=$action==='order_edit';$orderId=$isEdit?(int)($_POST['id']??0):0;$existingOrder=null;$existingItemCosts=[];
   if($isEdit){
    $q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$orderId]);$existingOrder=$q->fetch(PDO::FETCH_ASSOC);if(!$existingOrder)throw new Exception('Order could not be found.');
    $q=$db->prepare('SELECT product_id,name,cost,presentation_cost,base_price FROM items WHERE order_id=?');$q->execute([$orderId]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $existingItem){
     $snapshot=['cost'=>$existingItem['cost']===null?null:(int)$existingItem['cost'],'presentation_cost'=>(int)($existingItem['presentation_cost']??0),'base_price'=>$existingItem['base_price']===null?null:(int)$existingItem['base_price']];
     if((int)($existingItem['product_id']??0)>0)$existingItemCosts['id:'.(int)$existingItem['product_id']]=$snapshot;
     $existingItemCosts['name:'.strtolower(trim((string)$existingItem['name']))]=$snapshot;
    }
   }
   $name=trim($_POST['customer']??'');$recipientName=trim((string)($_POST['recipient_name']??''));$phone=trim($_POST['phone']??'');$referrer=trim($_POST['referrer']??'');$address=trim($_POST['address']??'');$notes=trim($_POST['notes']??'');$orderDate=trim($_POST['order_date']??'');
   $paymentMethod=trim((string)($_POST['payment_method']??''));$deliveryMethod=trim((string)($_POST['delivery_method']??''));$assignedTo=trim((string)($_POST['assigned_to']??''));if($assignedTo==='Jay')$assignedTo='James';$trackingReference=trim((string)($_POST['tracking_reference']??''));
   $deliveryCharge=postedMoneyPence($_POST['delivery_charge']??'','postage charge');$postageCost=postedMoneyPence($_POST['postage_cost']??'','postage cost');$paymentFee=$isEdit?(int)($existingOrder['payment_fee']??0):0;
   if(!$name || strlen($name)>160 || strlen($recipientName)>160 || strlen($phone)>40 || strlen($referrer)>160 || strlen($address)>2000 || strlen($notes)>4000 || strlen($trackingReference)>200)throw new Exception('Check the order details and try again.');
   if($paymentMethod!==''&&!in_array($paymentMethod,$paymentMethods,true))throw new Exception('Choose a valid payment method.');
   if(!in_array($deliveryMethod,$deliveryMethods,true))throw new Exception('Choose Collection, Local Delivery or Postage.');
   if($assignedTo!==''&&!in_array($assignedTo,$deliveryAssignees,true))throw new Exception('Choose James, Tony or Unassigned.');
   if($deliveryMethod!=='Postage'){$trackingReference='';$deliveryCharge=0;$postageCost=0;}
   $orderTz=new DateTimeZone('Europe/London');$todayLocal=new DateTimeImmutable('today',$orderTz);if($isEdit && (string)($_POST['change_order_date']??'')!=='1')$orderDate=(new DateTimeImmutable((string)$existingOrder['created']))->setTimezone($orderTz)->format('Y-m-d');$chosenDate=DateTimeImmutable::createFromFormat('!Y-m-d',$orderDate,$orderTz);
   if(!$chosenDate || $chosenDate->format('Y-m-d')!==$orderDate || $chosenDate>$todayLocal)throw new Exception('Choose a valid order date.');

   // Snapshot the classification. Customer changes never silently reprice old orders.
   $pricingType=$isEdit?(string)($existingOrder['pricing_type']??'standard'):customerPricingType($db,$name,$phone);
   if($pricingType==='free'){$deliveryCharge=0;$paymentMethod='Other';}
   $scheduleFieldsPresent=array_key_exists('schedule_fields_present',$_POST);
   $scheduleEnabled=(string)($_POST['future_delivery']??'')==='1';
   $scheduledDeliveryDate=$isEdit&&!$scheduleFieldsPresent?(string)($existingOrder['scheduled_delivery_date']??''):($scheduleEnabled?trim((string)($_POST['scheduled_delivery_date']??'')):'');
   if($scheduleEnabled && $scheduledDeliveryDate==='')throw new Exception('Choose the future delivery date.');
   $scheduledDeliveryDate=plannedDeliveryDate($scheduledDeliveryDate,$isEdit?(string)($existingOrder['scheduled_delivery_date']??''):'',$todayLocal);
   if($isEdit && $scheduledDeliveryDate>$todayLocal->format('Y-m-d') && $scheduledDeliveryDate!==(string)($existingOrder['scheduled_delivery_date']??'') && (in_array((string)$existingOrder['status'],['Delivered','Cancelled'],true)||trim((string)($existingOrder['delivery_date']??''))!==''))throw new Exception('Delivered or cancelled orders cannot be scheduled for a future delivery.');

   $lines=[];$presentations=[];
   foreach(($_POST['lines']??[]) as $lineKey=>$line){
    if(!is_array($line))continue;
    $productId=(int)($line['product_id']??0);$qty=filter_var($line['quantity']??0,FILTER_VALIDATE_INT);$format=trim((string)($line['presentation']??''));$discountFlag=((string)($line['discount']??'0'))==='1';$recurringFlag=((string)($line['recurring']??''))==='1';$cycleWeeks=(int)($line['cycle_weeks']??0);
    if($productId<1||$qty===false||$qty<1||$qty>999)throw new Exception('Check the product quantities.');
    $q=$db->prepare($isEdit?'SELECT * FROM products WHERE id=?':'SELECT * FROM products WHERE id=? AND active=1');$q->execute([$productId]);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)throw new Exception('A selected product is unavailable.');
    $isStandalonePen=strtolower(trim((string)$p['name']))==='pen';
    $isReta=isRetaProductName((string)$p['name']);
    if($isStandalonePen){$format='';$recurringFlag=false;$cycleWeeks=0;}
    elseif(!in_array($format,['Pen','Cartridge','Vial'],true))throw new Exception('Choose Pen, Cartridge or Vial for every peptide.');
    if($isReta && !$isEdit && !array_key_exists('recurring',$line)){$recurringFlag=true;if($cycleWeeks<1||$cycleWeeks>52)$cycleWeeks=4;}
    if($recurringFlag){if($cycleWeeks<1||$cycleWeeks>52)$cycleWeeks=defaultCycleWeeksForProduct((string)$p['name']);}else$cycleWeeks=0;
    $basePrice=postedMoneyPence($line['base_price']??number_format((int)$p['price']/100,2,'.',''),'base price');
    if($basePrice<0)$basePrice=(int)$p['price'];
    $familyFriendsPrice=$p['family_friends_price']===null?max(0,$basePrice-500):(int)$p['family_friends_price'];$discount=(!$isStandalonePen&&$discountFlag)?max(0,$basePrice-$familyFriendsPrice):0;$formatCharge=0;
    $calculatedPrice=max(0,$basePrice-$discount);
    $postedPrice=postedMoneyPence($line['price']??number_format($calculatedPrice/100,2,'.',''),'line price');
    if($pricingType==='free'){$postedPrice=0;$discount=0;$recurringFlag=false;$cycleWeeks=0;}
    elseif($pricingType==='family_friends'&&!$isEdit){
     $basePrice=(int)$p['price'];$postedPrice=$p['family_friends_price']===null?$basePrice:(int)$p['family_friends_price'];$discount=max(0,$basePrice-$postedPrice);
    }
    $costKey='id:'.(int)$p['id'];$saved=$existingItemCosts[$costKey]??($existingItemCosts['name:'.strtolower(trim((string)$p['name']))]??null);
    $productCost=$saved&&array_key_exists('cost',$saved)?$saved['cost']:($p['cost']===null?null:(int)$p['cost']);
    $presentationCost=0;
    $lines[]=['product_id'=>(int)$p['id'],'product'=>$p,'qty'=>(int)$qty,'presentation'=>$format,'base_price'=>$basePrice,'discount'=>$discount,'price'=>$postedPrice,'cost'=>$productCost,'presentation_cost'=>$presentationCost,'recurring'=>$recurringFlag?1:0,'cycle_weeks'=>$cycleWeeks];
    if($format!=='')$presentations[$format]=true;
   }
   if(!$lines)throw new Exception('Add at least one product.');
   $orderPresentation=count($presentations)===1?(string)array_key_first($presentations):(count($presentations)>1?'Mixed':'');

   if($isEdit){
    $originalLocal=(new DateTimeImmutable((string)$existingOrder['created']))->setTimezone($orderTz);
    $created=(new DateTimeImmutable($orderDate.' '.$originalLocal->format('H:i:s'),$orderTz))->setTimezone(new DateTimeZone('UTC'))->format('c');
   }else{
    $created=$chosenDate->format('Y-m-d')===$todayLocal->format('Y-m-d')?gmdate('c'):(new DateTimeImmutable($orderDate.' 12:00:00',$orderTz))->setTimezone(new DateTimeZone('UTC'))->format('c');
   }

   $db->beginTransaction();
   if($isEdit){
    $db->prepare('UPDATE orders SET customer=?,recipient_name=?,phone=?,address=?,notes=?,created=?,referrer=?,presentation=?,payment_method=?,delivery_method=?,assigned_to=?,tracking_reference=?,delivery_charge=?,postage_cost=?,payment_fee=?,scheduled_delivery_date=? WHERE id=?')->execute([$name,$recipientName,$phone,$address,$notes,$created,$referrer,$orderPresentation,$paymentMethod,$deliveryMethod,$assignedTo,$trackingReference,$deliveryCharge,$postageCost,$paymentFee,$scheduledDeliveryDate,$orderId]);
    if($scheduledDeliveryDate!==(string)($existingOrder['scheduled_delivery_date']??''))$db->prepare("UPDATE orders SET delivery_section_override='' WHERE id=?")->execute([$orderId]);
    $db->prepare('DELETE FROM items WHERE order_id=?')->execute([$orderId]);
   }else{
    $db->prepare('INSERT INTO orders(customer,recipient_name,phone,address,notes,created,referrer,presentation,payment_method,delivery_method,assigned_to,tracking_reference,delivery_charge,postage_cost,payment_fee,scheduled_delivery_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$name,$recipientName,$phone,$address,$notes,$created,$referrer,$orderPresentation,$paymentMethod,$deliveryMethod,$assignedTo,$trackingReference,$deliveryCharge,$postageCost,$paymentFee,$scheduledDeliveryDate]);$orderId=(int)$db->lastInsertId();$savedOrderId=$orderId;
   }
   $saveCustomer=$db->prepare("INSERT INTO customers(name,phone,address,created,archived) VALUES (?,?,?,?,0) ON CONFLICT(name,phone) DO UPDATE SET address=CASE WHEN excluded.address<>'' THEN excluded.address ELSE customers.address END, archived=0");$saveCustomer->execute([$name,$phone,$address,$created]);
   $db->prepare('UPDATE orders SET pricing_type=? WHERE id=?')->execute([$pricingType,$orderId]);
   $insertItem=$db->prepare('INSERT INTO items(order_id,product_id,name,price,cost,presentation,presentation_cost,base_price,discount,quantity,recurring,cycle_weeks) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
   foreach($lines as $line)$insertItem->execute([$orderId,$line['product_id'],$line['product']['name'],$line['price'],$line['cost'],$line['presentation'],$line['presentation_cost'],$line['base_price'],$line['discount'],$line['qty'],$line['recurring'],$line['cycle_weeks']]);
   if(!$isEdit && (string)($_POST['back_order']??'')==='1')$db->prepare('UPDATE orders SET back_order=1,back_order_since=? WHERE id=?')->execute([gmdate('c'),$orderId]);
   reconcileDeliveredStock($db,$orderId,false);
   if($isEdit)syncProductCyclesFromDeliveredOrder($db,$orderId);
   $db->commit();$syncError=syncOrderToSheet($db,$orderId);
  }

  if($action==='profit_settings'){
   $penCost=postedMoneyPence($_POST['pen_cost']??'','pen cost');saveSetting($db,'pen_cost_pence',(string)$penCost);
   // Future orders use the new Pen cost. Existing item cost snapshots are intentionally immutable.
   $db->prepare("UPDATE products SET cost=? WHERE lower(trim(name))='pen'")->execute([$penCost]);
  }
  if($action==='sheets_settings'){
   $url=trim((string)($_POST['webhook']??''));
   if($url!==''&&!sheetsWebhookValid($url))throw new Exception('Paste the Google Apps Script Web App URL ending in /exec.');
   saveSetting($db,'sheets_webhook',$url);
  }
  if($action==='sheets_sync_all'){
   if(setting($db,'sheets_webhook')==='')throw new Exception('Connect the Google Apps Script Web App first.');
   $ids=$db->query('SELECT id FROM orders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);$ok=0;$failed=0;
   foreach($ids as $id){$sync=syncOrderToSheet($db,(int)$id);if($sync===null||$sync==='')$ok++;else$failed++;}
   $_SESSION['flash']=$failed?"{$ok} orders synced; {$failed} could not be synced. Check the connection details below.":"{$ok} orders synced to Google Sheets.";
   header('Location: ./?view=sheets');exit;
  }
 }
 $flash=match($action){
  'reta_cycle_stop'=>'Recurring cycle stopped.',
  'reta_cycle_resume'=>'Recurring cycle restarted.',
  'cycle_amend'=>'Recurring cycle updated.',
  'reta_cycle_skip'=>'This expected order was skipped and the cycle moved forward.',
  'reta_cycle_start_order'=>'Recurring cycles started.',
  'payment_add'=>'Payment recorded.',
  'payment_delete'=>'Payment removed.',
  'order'=>'Order saved.',
  'order_backorder'=>(($_POST['back_order']??'')==='1')?'Order moved to back orders; stock released.':'Order moved to active orders; stock reserved.',
  'order_edit'=>'Order updated.',
  'business_supply_save'=>'Business supply purchase saved.',
  'supply_item_save'=>'Supply saved to the shared cupboard.',
  'supply_item_change'=>'Shared supply updated.',
  'supply_delivery_receive'=>'Delivery checked in and cupboard updated.',
  'profit_settings'=>'Profit settings saved.',
  'order_delete'=>'Order deleted.',
  'order_dates'=>'Order dates updated.',
  'stock_open'=>'Opening stock count saved; stock tracking is now active.',
  'stock_receive'=>'Stock added and recorded in the audit trail.',
  'stock_quick_adjust'=>'Stock balance updated.',
  'stock_transfer'=>'Stock moved and recorded in both locations.',
  'supplier_order_create'=>'Supplier order added to Incoming Stock.',
  'supplier_order_edit'=>'Incoming supplier order updated. Received stock was preserved.',
  'supplier_stock_receive'=>'Incoming stock received and allocated.',
  'supplier_order_cancel'=>'Supplier order cancelled.',
  'stock_fridge_counts'=>'Fridge totals saved; reserved orders were preserved.',
  'stock_count'=>'Physical stock count reconciled.',
  'stock_untrack'=>'Product removed from Stock Control. Historical movements were kept.',
  'product_delete'=>'Product deleted.',
  'delivery_mark'=>orderBalancePence($db,(int)($_POST['id']??0))>0?'Delivery recorded; balance remains outstanding.':'Delivery recorded; no payment outstanding.',
  'customer_pricing'=>'Customer pricing saved. Existing orders are unchanged.',
  'order_schedule'=>(($_POST['schedule_clear']??'')==='1')?'Planned delivery date cleared.':'Delivery date arranged.',
  'order_delivery_section'=>(($_POST['delivery_section']??'')==='auto')?'Automatic delivery timing restored.':'Order moved to '.(($_POST['delivery_section']??'')==='future'?'Future deliveries.':'Awaiting delivery.'),
  'packing_pick'=>(($_POST['picked']??'')==='1')?'Item checked for packing.':'Packing check cleared.',
  'order_preparation'=>'Preparation stage updated.',
  'order_reconstituted'=>(($_POST['reconstituted']??'0')==='1')?'Order marked as reconstituted.':'Reconstituted mark removed.',
  'status'=>'Order status updated.',
  'product'=>'Product saved.',
  'customer'=>(int)($_POST['id']??0)?'Customer updated.':'Customer added.',
  'customer_archive'=>(($_POST['archive']??'1')==='1')?'Customer archived.':'Customer restored.',
  'sheets_settings'=>'Google Sheets connection saved.',
  default=>''
 };
 if($flash!=='' && is_string($syncError) && $syncError!=='')$flash.=' Google Sheets sync failed — open the Google Sheets page to retry.';
 if($flash!=='')$_SESSION['flash']=$flash;
 if($auditTrack){
  if($action==='order')$auditOrderId=(int)$savedOrderId;
  $auditAfter=$auditSnapshot($auditOrderId);
  $changes=[];
  foreach(array_unique(array_merge(array_keys($auditBefore??[]),array_keys($auditAfter??[]))) as $key){
   if($key==='id')continue;
   $previous=$auditBefore[$key]??null;$next=$auditAfter[$key]??null;
   if($previous!==$next)$changes[$key]=['from'=>$previous,'to'=>$next];
  }
  $labels=['packing_pick'=>'Packing checklist updated','order_backorder'=>'Order waiting list changed','order'=>'Order created','order_edit'=>'Order edited','order_delete'=>'Order deleted','status'=>'Order status changed','order_dates'=>'Order dates updated','delivery_mark'=>'Delivery recorded','order_schedule'=>'Delivery arranged','order_delivery_section'=>'Delivery queue changed','order_preparation'=>'Preparation updated','order_reconstituted'=>'Preparation updated','payment_add'=>'Payment recorded','payment_delete'=>'Payment removed'];
  if($auditOrderId>0&&($changes||in_array($action,['order','order_edit','order_delete','payment_add','payment_delete'],true))){
   $meta=['changes'=>$changes];
   if($action==='payment_delete'&&$auditPaymentBefore)$meta['removed_payment']=['amount_pence'=>(int)$auditPaymentBefore['amount'],'method'=>(string)$auditPaymentBefore['method']];
   recordActivity($db,'order',$auditOrderId,$labels[$action],$meta);
  }
 }
 if($action==='order' && $savedOrderId>0){
  try{
   require_once __DIR__.'/business-alerts.php';
   $emailStatus=ankhSendNewOrderNotice($db,(int)$savedOrderId);
   if($emailStatus==='disabled')$_SESSION['flash'].=' New-order email is switched off in More → Business Alerts.';
  }catch(Throwable $mailError){
   error_log('ANKH order notification failed #'.(int)$savedOrderId.': '.$mailError->getMessage());
   $_SESSION['flash'].=' The order was saved, but the email notification failed. Open More → Business Alerts for its send status and a safe retry.';
  }
 }
 try{if(!empty($_SESSION['admin']))ankhCatalogueLinkIdentities($db);}catch(Throwable $linkError){error_log('ANKH identity link update: '.$linkError->getMessage());}
 if($action==='order' && $savedOrderId>0 && (string)($_POST['back_order']??'')==='1'){header('Location: ./?view=orders&section=backorders');exit;}
 if($action==='order' && $savedOrderId>0){header('Location: ./?view=saved&id='.$savedOrderId);exit;}
 $returnView=(string)($_POST['return']??'orders');$returnSection=(string)($_POST['return_section']??'');
 if($returnView==='supplies'&&in_array($returnSection,['cupboard','shopping','purchases'],true)){header('Location: ./?view=supplies&section='.$returnSection);exit;}
 if($action==='stock_fridge_counts'){
  $returnFilter=(($_POST['return_filter']??'')==='low')?'&filter=low':'';
  $returnFridge=in_array($_POST['return_fridge']??'combined',['combined','jay','tony'],true)?(string)($_POST['return_fridge']??'combined'):'combined';
  header('Location: ./?view=stock'.$returnFilter.'&fridge='.urlencode($returnFridge).'#stock-product-'.(int)($_POST['product_id']??0));exit;
 }
 if($returnView==='customer' && (int)($_POST['customer_id']??0)>0)header('Location: ./?view=customer&id='.(int)$_POST['customer_id']);
 else if($returnView==='dashboard'&&in_array($_POST['return_queue']??'',['all','payment','delivery','future'],true))header('Location: ./?view=dashboard&queue='.urlencode((string)$_POST['return_queue']).'#dashboard-actions');
 else if($returnView==='packing' && (int)($_POST['id']??0)>0)header('Location: ./?view=packing&id='.(int)$_POST['id'].'#packing-lines');
 else if($returnView==='backorders')header('Location: ./?view=orders&section=backorders');
 else if($returnView==='recurring')header('Location: ./?view=orders&section=recurring');
 else if($returnView==='stock' && in_array($returnSection,['incoming','forecast','history'],true))header('Location: ./?view=stock&section='.urlencode($returnSection));
 else header('Location: ./?view='.urlencode($returnView));
 exit;
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();$error=$ex instanceof PDOException?'Could not save. Please try again.':$ex->getMessage();}
}
$passkeyCount=(int)$db->query($hasIndividualLogins?'SELECT COUNT(*) FROM passkeys WHERE user_id IS NOT NULL':'SELECT COUNT(*) FROM passkeys')->fetchColumn();
$auth=!empty($_SESSION['admin']) && time()-($_SESSION['last']??0)<=3600 && (!$hasIndividualLogins||!empty($_SESSION['admin_user_id']));
if($auth)$_SESSION['last']=time();
function csrf(){echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';}
function orderPreparationBadge(array $order):void{
 $state=orderPreparationState($order);
 // Pending is the default preparation state; only show a badge once preparation has advanced.
 if(!in_array($state,['reconstituted','packaged'],true))return;
 $label=$state==='packaged'?'Packaged · Ready for delivery':'Reconstituted';
 ?><span class="order-preparation-badge preparation-<?=e($state)?>"><?=e($label)?></span><?php
}
function dashboardPreparationControls(array $order,string $returnView='dashboard'):void{
 $state=orderPreparationState($order);if($state==='closed')return;
 ?><div class="preparation-stage-controls"><?php foreach(['reconstituted'=>'Reconstituted','packaged'=>'Packaged'] as $stage=>$label):$checked=$state===$stage;$disabled=$stage==='packaged'&&$state==='pending';?>
 <form method="post" class="order-preparation-form"><?php csrf();?><input type="hidden" name="action" value="order_preparation"><input type="hidden" name="id" value="<?=(int)$order['id']?>"><input type="hidden" name="return" value="<?=e($returnView)?>"><input type="hidden" name="return_queue" value="<?=e($_SESSION['dashboard_queue']??'all')?>"><input type="hidden" name="stage" value="<?=e($stage)?>">
 <label class="order-preparation-toggle"><input type="checkbox" name="complete" value="1" <?=$checked?'checked':''?> <?=$disabled?'disabled':''?>><span><strong><?=e($label)?></strong><small><?=$stage==='packaged'?'Ready for delivery':'Ready to package'?></small></span></label><button type="submit" class="order-preparation-save" <?=$disabled?'disabled':''?>>Save</button></form>
 <?php endforeach;?></div><?php
}
function dashboardNextActionLabel(array $order):string{
 $state=orderPreparationState($order);
 if($state==='closed')return 'Follow up payment';
 if($state==='pending')return 'Next: reconstitute';
 if($state==='reconstituted')return 'Next: package';
 if($state==='packaged')return 'Next: deliver';
 return 'Review order';
}
function dashboardOneTapActions(array $order,?DateTimeImmutable $today=null):void{
 $state=orderPreparationState($order);
 if($state==='closed')return;
 $today??=new DateTimeImmutable('today',new DateTimeZone('Europe/London'));
 $todayValue=$today->format('Y-m-d');
 $planned=trim((string)($order['scheduled_delivery_date']??''));
 $stage=$state==='pending'?'reconstituted':($state==='reconstituted'?'packaged':'');
 if($stage!==''){?>
 <form method="post" class="operations-next-form"><?php csrf();?>
  <input type="hidden" name="action" value="order_preparation"><input type="hidden" name="id" value="<?=(int)$order['id']?>">
  <input type="hidden" name="return" value="dashboard"><input type="hidden" name="return_queue" value="<?=e($_SESSION['dashboard_queue']??'all')?>">
  <input type="hidden" name="stage" value="<?=e($stage)?>"><input type="hidden" name="complete" value="1">
  <button class="operations-next-button" type="submit"><?=$stage==='reconstituted'?'✓ Mark reconstituted':'✓ Mark packaged'?></button>
 </form><?php
 }
 if(isBackOrder($order)){?><p class="operations-planned-note">This order is awaiting stock. Move it to Active orders before recording delivery.</p><?php return;}
 if($planned!==''&&$planned>$todayValue){?><p class="operations-planned-note">Delivery planned for <?=e(date('D d M',strtotime($planned)))?>. Only mark delivered if it was actually handed over.</p><?php }
 ?>
 <form method="post" class="operations-next-form" onsubmit="return confirm('Confirm this order was actually delivered today? Any unpaid balance stays outstanding.');"><?php csrf();?>
  <input type="hidden" name="action" value="delivery_mark"><input type="hidden" name="id" value="<?=(int)$order['id']?>">
  <input type="hidden" name="delivery_date" value="<?=e($todayValue)?>"><input type="hidden" name="return" value="dashboard">
  <input type="hidden" name="return_queue" value="<?=e($_SESSION['dashboard_queue']??'all')?>">
  <button class="operations-next-button" type="submit">✓ Mark delivered today</button>
 </form><?php
}

function futureDeliveryPaymentLabel(array $order):string{
 $total=max(0,(int)($order['total']??0));$paid=max(0,(int)($order['paid_amount']??0));
 return $total===0?'No payment required':($paid>=$total?'Paid':($paid>0?'Part paid':'Unpaid'));
}
function orderDisplayName(array $order):string{
 $recipient=trim((string)($order['recipient_name']??''));
 return $recipient!==''?$recipient:trim((string)($order['customer']??''));
}
function orderBuyerCaption(array $order):void{
 $buyer=trim((string)($order['customer']??''));
 if($buyer!==''&&strcasecmp(orderDisplayName($order),$buyer)!==0){?><p class="order-buyer-caption">Ordered by <?=e($buyer)?></p><?php }
}
function dashboardQueueView(array $counts,string $requested='',string $remembered=''):string{
 foreach([$requested,$remembered] as $queue)if(in_array($queue,['all','payment','delivery','future'],true))return $queue;
 return 'all';
}
function dashboardWorklist(array $payment,array $delivery,array $future):array{
 $unique=[];
 foreach(array_merge($payment,$delivery,$future) as $order)$unique[(int)$order['id']]=$order;
 return array_values($unique);
}
function dashboardWorkGroup(array $order,string $today):string{
 if(orderPreparationState($order)==='closed')return 'Payment follow-up';
 $date=trim((string)($order['scheduled_delivery_date']??''));
 if($date==='')return 'To arrange';
 if($date<$today)return 'Overdue';
 return $date===$today?'Today':'Coming up';
}
function dashboardWorklistSort(array $orders,string $today):array{
 $priority=['Overdue'=>0,'Today'=>1,'Coming up'=>2,'To arrange'=>3,'Payment follow-up'=>4];
 usort($orders,static function($a,$b)use($priority,$today){
  return $priority[dashboardWorkGroup($a,$today)]<=>$priority[dashboardWorkGroup($b,$today)]
   ?: strcmp((string)($a['scheduled_delivery_date']??''),(string)($b['scheduled_delivery_date']??''))
   ?: (int)$b['id']<=>(int)$a['id'];
 });return $orders;
}
function orderFocusStatuses(array $order):void{
 $balance=max(0,(int)($order['total']??0)-(int)($order['paid_amount']??0));
 $state=orderPreparationState($order);$cancelled=($order['status']??'')==='Cancelled';
 ?><div class="focus-statuses"><span class="focus-status <?=$balance>0?'focus-unpaid':'focus-paid'?>"><?=$balance>0?e(money($balance).' '.((int)($order['paid_amount']??0)>0?'remaining':'unpaid')):((int)($order['total']??0)===0?'No payment required':'Paid')?></span>
 <?php if($cancelled):?><span class="focus-status">Cancelled</span><?php elseif(isBackOrder($order)):?><span class="focus-status">Awaiting stock</span><?php elseif($state==='closed'):?><span class="focus-status focus-paid">Delivered</span><?php else:?><span class="focus-status <?=$state==='packaged'?'focus-ready':'focus-preparing'?>"><?=$state==='packaged'?'Packaged · Ready':($state==='reconstituted'?'Reconstituted':'To prepare')?></span><span class="focus-status"><?=($order['status']??'')==='Dispatched'?'Dispatched':'To deliver'?></span><?php endif;?></div><?php
}
function deliveryScheduleTags(array $order,?DateTimeImmutable $today=null):void{
 $date=trim((string)($order['scheduled_delivery_date']??''));
 $active=!in_array((string)($order['status']??''),['Cancelled','Delivered'],true)&&trim((string)($order['delivery_date']??''))==='';
 ?><div class="delivery-schedule-tags"><span class="delivery-assignee <?=!empty($order['assigned_to'])?'assigned '.e(assigneeClass($order['assigned_to'])):'assignee-unassigned'?>"><?=e($order['assigned_to']?:'Unassigned')?></span><?php if($active&&$date!==''):?><span class="delivery-plan-date" title="Planned delivery <?=e($date)?>">▦ <?=e((new DateTimeImmutable($date))->format('D d M'))?> · <?=e(deliveryCountdown($date,$today))?></span><?php endif;?></div><?php
}
function dashboardPlannedDateTile(array $order):void{
 $scheduled=trim((string)($order['scheduled_delivery_date']??''));
 if($scheduled==='')return;
 $tz=new DateTimeZone('Europe/London');
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',$scheduled,$tz);
 if(!$date||$date->format('Y-m-d')!==$scheduled)return;
 ?><div class="future-order-date" aria-label="Planned delivery <?=e($date->format('j F Y'))?>"><span><?=e($date->format('M'))?></span><strong><?=e($date->format('d'))?></strong><small><?=e($date->format('D'))?></small></div><?php
}
function dashboardDeliveryScheduleForm(array $order):void{
 if(in_array((string)($order['status']??''),['Cancelled','Delivered'],true)||trim((string)($order['delivery_date']??''))!=='')return;
 $date=(string)($order['scheduled_delivery_date']??'');$today=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d');
 ?><details class="delivery-arrange"><summary><?= $date!==''?'Change delivery date':'Arrange delivery'?> <span aria-hidden="true">▦</span></summary>
 <form method="post" class="delivery-arrange-form"><?php csrf();?><input type="hidden" name="action" value="order_schedule"><input type="hidden" name="id" value="<?=(int)$order['id']?>"><input type="hidden" name="return" value="dashboard"><input type="hidden" name="return_queue" value="<?=e($_SESSION['dashboard_queue']??'all')?>">
 <label>Planned delivery date<input type="date" name="scheduled_delivery_date" min="<?=e($today)?>" value="<?=e($date>=$today?$date:'')?>" required></label>
 <small>Arranges delivery only — does not mark the order delivered.</small><div class="delivery-arrange-actions"><button type="submit">Save date</button><?php if($date!==''):?><button type="submit" name="schedule_clear" value="1" formnovalidate class="secondary">Clear date</button><?php endif;?></div></form></details><?php
}
function dashboardDeliveryMoveControls(array $order,string $destination):void{
 if(!in_array($destination,['awaiting','future'],true))return;
 $today=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d');
 $scheduled=trim((string)($order['scheduled_delivery_date']??''));$override=(string)($order['delivery_section_override']??'');
 if($destination==='future' && $scheduled<=$today){
  ?><p class="delivery-move-hint">Set a future delivery date before moving this order to Future deliveries.</p><?php
 }else{
  ?><form method="post" class="delivery-move-form"><?php csrf();?><input type="hidden" name="action" value="order_delivery_section"><input type="hidden" name="id" value="<?=(int)$order['id']?>"><input type="hidden" name="return" value="dashboard"><input type="hidden" name="return_queue" value="<?=e($_SESSION['dashboard_queue']??'all')?>"><input type="hidden" name="delivery_section" value="<?=e($destination)?>"><button type="submit" class="delivery-move-button">⇄ Move to <?=$destination==='future'?'Future deliveries':'Awaiting delivery'?></button></form><?php
 }
 if($override!==''){
  ?><form method="post" class="delivery-move-form delivery-move-auto"><?php csrf();?><input type="hidden" name="action" value="order_delivery_section"><input type="hidden" name="id" value="<?=(int)$order['id']?>"><input type="hidden" name="return" value="dashboard"><input type="hidden" name="return_queue" value="<?=e($_SESSION['dashboard_queue']??'all')?>"><input type="hidden" name="delivery_section" value="auto"><button type="submit" class="delivery-move-button">Use automatic timing</button></form><?php
 }
}
function futureDeliveryFields(array $order=[]):void{
 $submitted=array_key_exists('schedule_fields_present',$_POST);
 $value=$submitted?(string)($_POST['scheduled_delivery_date']??''):(string)($order['scheduled_delivery_date']??'');
 $enabled=$submitted?((string)($_POST['future_delivery']??'')==='1'):$value!=='';
 $tomorrow=(new DateTimeImmutable('tomorrow',new DateTimeZone('Europe/London')))->format('Y-m-d');
 $min=$enabled&&$value!==''&&$value<$tomorrow?$value:$tomorrow;
 ?>
 <section class="future-delivery-control">
 <input type="hidden" name="schedule_fields_present" value="1">
 <label class="future-delivery-toggle"><input id="future-delivery" name="future_delivery" type="checkbox" value="1" <?=$enabled?'checked':''?> aria-controls="future-delivery-date-wrap"><span><strong>Future delivery</strong><small>Choose a planned delivery date</small></span></label>
 <div id="future-delivery-date-wrap" class="future-delivery-date-wrap" <?=$enabled?'':'hidden'?>><label>Deliver on<input id="scheduled-delivery-date" name="scheduled_delivery_date" type="date" min="<?=e($min)?>" value="<?=e($value)?>" <?=$enabled?'required':'disabled'?>></label><small>This is the planned date, not confirmation of delivery.</small></div>
 </section>
 <?php
}
function money($n){return '£'.number_format((float)$n/100,2);}
function statusClass(string $status):string{return preg_replace('/[^a-z0-9]+/','-',strtolower(trim($status)));}
function assigneeClass(string $name):string{return in_array($name,['James','Tony'],true)?'assignee-'.strtolower($name):'assignee-unassigned';}
$view=in_array($_GET['view']??'', ['enquiries','supplies','activity','dashboard','orders','packing','new','edit','products','product','supplier','stock','fridge_jay','fridge_tony','customers','customer','balances','reta','sheets','reports','reorder','shipping','more','business_alerts','saved','stockboard','reportsboard'],true)?$_GET['view']:'dashboard';
$businessSupplies=in_array($view,['supplies','reports'],true)?$db->query('SELECT * FROM business_supplies ORDER BY ordered_date DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC):[];
// Use committed CSS assets, with a source fallback for older deployment hooks.
$stylesheet=$view==='supplier'?'style.min.css':'style.core.min.css';
if(!is_file(__DIR__.'/'.$stylesheet))$stylesheet='style.css';
$stylesheetVersion=(string)(@filemtime(__DIR__.'/'.$stylesheet) ?: @filemtime(__DIR__.'/style.css') ?: 0);
// Reports are calculated below the shared header. Keep downloads free of that HTML.
$reportDownloadBuffer=in_array($view,['reports','reorder'],true)&&trim((string)($_GET['export']??''))!=='';
if($reportDownloadBuffer)ob_start();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#101112"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="ANKH"><meta name="mobile-web-app-capable" content="yes"><title>ANKH • Order desk</title><link rel="manifest" href="manifest.webmanifest"><link rel="icon" href="icon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="icon.svg"><link rel="apple-touch-startup-image" href="splash.svg"><link rel="stylesheet" href="<?=e($stylesheet)?>?v=<?=e($stylesheetVersion)?>"><style id="ankh-desktop-intelligence-v1">
.ankh-notification-popover,.desktop-today-panel,.ankh-notification-button,.ankh-notification-mobile-button{display:none}
.ankh-header-tools{display:contents}
.customer-intelligence-filters{display:flex;gap:7px;overflow-x:auto;overscroll-behavior-inline:contain;padding:2px 0 12px;scrollbar-width:none}
.customer-intelligence-filters::-webkit-scrollbar{display:none}
.customer-intelligence-filters button{flex:0 0 auto;white-space:nowrap;min-height:36px;padding:0 11px;border-radius:999px}
.customer-intelligence-filters button.selected{background:#d8b65c;color:#111;border-color:#d8b65c}
.customer-intelligence-badges{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}
.customer-intelligence-badges span{padding:5px 8px;border:1px solid #343638;border-radius:999px;background:#151716;color:#9fa19b;font-size:9px;font-weight:800;letter-spacing:.2px}
.customer-intelligence-badges .money{border-color:#64512c;color:#e6c56c;background:#211b10}
.customer-intelligence-badges .cycle{border-color:#35543d;color:#9ac9a5;background:#132018}
.ankh-notification-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:4px 2px 12px}
.ankh-notification-head h2{margin:0;font-size:18px}.ankh-notification-head small{color:#96988f}
.ankh-notification-item{padding:13px;border:1px solid #30322e;border-radius:14px;background:#1b1d1a;margin-top:9px}
.ankh-notification-item>a{display:block;color:inherit;text-decoration:none}
.ankh-notification-item strong{display:block;font-size:14px;margin-bottom:4px}.ankh-notification-item p{margin:0;color:#a8aaa0;font-size:12px;line-height:1.4}
.ankh-notification-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.ankh-notification-actions form{margin:0}.ankh-notification-actions button{padding:6px 9px;min-height:30px;border-radius:9px;font-size:10px}
.ankh-notification-empty{padding:22px 10px;text-align:center;color:#9a9d95}
.ankh-notification-item.tone-money{border-color:#5c472b}.ankh-notification-item.tone-reorder{border-color:#694036;background:#1d1715}.ankh-notification-item.tone-cycle{border-color:#34513e}
@media (max-width:760px){
 .app-brand-row .brand{margin-right:auto}
 .ankh-notification-mobile-button{display:grid;place-items:center;position:relative;flex:0 0 44px;width:44px;height:44px;min-height:44px;margin:0;padding:0;border:1px solid #474236;border-radius:14px;background:#181a18;color:#f1d679}
 .ankh-notification-mobile-button svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8}
 .ankh-notification-mobile-button b{position:absolute;right:-4px;top:-5px;min-width:19px;height:19px;padding:0 4px;border-radius:999px;background:#e8c45d;color:#111;font-size:10px;line-height:19px;text-align:center}
 .ankh-notification-popover{position:fixed;display:block;z-index:10050;left:10px;right:10px;bottom:calc(82px + env(safe-area-inset-bottom));top:auto;width:auto;max-height:min(70dvh,620px);overflow:auto;padding:14px;border:1px solid #4a4435;border-radius:20px;background:#151614;box-shadow:0 24px 80px #000b}
 .ankh-notification-popover[hidden]{display:none!important}
 .ankh-notification-head{position:sticky;top:-14px;z-index:2;margin:-14px -14px 4px;padding:16px 16px 12px;background:#151614eF;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid #2d2e2a}
 .ankh-notification-actions button{min-height:44px;padding:9px 12px;font-size:12px}
 .ankh-notification-popover{overscroll-behavior:contain;-webkit-overflow-scrolling:touch}
 #ankh-notification-close{min-height:44px;min-width:60px}
 .customer-intelligence-filters{margin:0 -2px 8px;padding-bottom:8px}
 .customer-intelligence-filters button{min-height:44px;padding:0 13px}
 .customer-account-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
 .customer-account-stats>article{min-width:0}
 .customer-account-stats strong{overflow-wrap:anywhere;font-size:clamp(18px,5vw,25px)}
}
@media (min-width:761px){
 main.focus-app>header{position:relative;display:flex;align-items:center;justify-content:space-between;gap:18px}
 .ankh-header-tools{display:flex;align-items:center;gap:12px}
 .ankh-notification-button{position:relative;width:42px;height:42px;border:1px solid #474236;border-radius:13px;background:#1a1a18;color:#efe1b2;display:grid;place-items:center;cursor:pointer}
 .ankh-notification-button svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:1.8}
 .ankh-notification-button b{position:absolute;right:-5px;top:-5px;min-width:18px;height:18px;padding:0 4px;border-radius:999px;background:#e8c45d;color:#111;font-size:10px;line-height:18px;text-align:center}
 .ankh-notification-popover{position:fixed;z-index:10040;top:76px;right:28px;width:min(390px,calc(100vw - 320px));max-height:calc(100vh - 110px);overflow:auto;padding:14px;border:1px solid #4a4435;border-radius:18px;background:#151614;box-shadow:0 22px 70px rgba(0,0,0,.45)}
 .ankh-notification-popover[hidden]{display:none!important}.ankh-notification-popover:not([hidden]){display:block}
 .ankh-notification-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:4px 2px 12px}.ankh-notification-head h2{margin:0;font-size:18px}.ankh-notification-head small{color:#96988f}
 .ankh-notification-item{padding:13px;border:1px solid #30322e;border-radius:14px;background:#1b1d1a;margin-top:9px}.ankh-notification-item>a{display:block;color:inherit;text-decoration:none}.ankh-notification-item strong{display:block;font-size:14px;margin-bottom:4px}.ankh-notification-item p{margin:0;color:#a8aaa0;font-size:12px;line-height:1.4}
 .ankh-notification-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.ankh-notification-actions form{margin:0}.ankh-notification-actions button{padding:6px 9px;min-height:30px;border-radius:9px;font-size:10px}.ankh-notification-empty{padding:22px 10px;text-align:center;color:#9a9d95}
 .desktop-today-panel{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:14px 0 20px}.desktop-today-panel>a{padding:14px;border:1px solid #34352f;border-radius:14px;background:#181a17;color:inherit;text-decoration:none}.desktop-today-panel span{display:block;color:#aaa99d;font-size:11px}.desktop-today-panel strong{display:block;margin-top:7px;font-size:19px}.desktop-today-panel small{display:block;margin-top:4px;color:#81847e;font-size:10px}
 .customer-account-stats{grid-template-columns:repeat(4,minmax(0,1fr))}
 .customer-account-grid{grid-template-columns:minmax(0,1.05fr) minmax(0,.95fr)}
 .profit-table thead th,.report-table thead th{position:sticky;top:0;z-index:2}
 .profit-table tbody tr:hover,.report-table tbody tr:hover{background:rgba(218,185,91,.045)}
}
/* Tablet-only layout repair: preserve the established dashboard and navigation. */
@media (min-width:761px) and (max-width:1199px){
 main.focus-app{min-width:0;padding-left:clamp(20px,3vw,36px);padding-right:clamp(20px,3vw,36px)}
 main.focus-app .focus-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;border:0;padding:0;margin:0 0 24px}
 main.focus-app .focus-summary .dashboard-stat-card,
 main.focus-app .focus-summary .dashboard-stat-card:first-child,
 main.focus-app .focus-summary .dashboard-stat-card:last-child{min-width:0;padding:16px;background:var(--focus-panel,#191c21);border:1px solid var(--border);border-radius:16px}
 main.focus-app .focus-summary .dashboard-stat-card+.dashboard-stat-card{border-left:1px solid var(--border)}
 main.focus-app .focus-summary .dashboard-stat-card>strong{overflow-wrap:anywhere}
 main.focus-app .focus-worklist{grid-template-columns:minmax(0,1fr)}
 main.focus-app .dashboard-heading,main.focus-app .focus-work-toolbar{flex-wrap:wrap}
 main.focus-app .dashboard-queue-tabs{flex-wrap:wrap}
 .ankh-notification-popover{width:min(390px,calc(100vw - 260px));right:16px}
 .customer-account-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
 #customer-list.customer-intelligence-grid{grid-template-columns:minmax(0,1fr)}
}
/* ANKH operations upgrade: compact, tap-friendly actions without new dashboard columns. */
.operations-step-hint{font-size:11px;font-weight:600;color:#e3c57d;margin:3px 0 10px}
.operations-next-form{margin:12px 0}
.operations-next-form .operations-next-button{min-height:44px;width:100%;border-radius:12px;background:#d8b65c;color:#17150e;text-align:center}
.operations-planned-note{font-size:12px;color:#e3c57d;padding:10px;border:1px solid #534527;border-radius:10px}
.activity-list{display:grid;gap:0}.activity-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 4px;border-bottom:1px solid var(--border)}
.activity-row:last-child{border-bottom:0}.activity-row strong,.activity-row small{display:block}.activity-row small{font-size:11px;color:var(--muted);margin-top:4px}.activity-row a{font-size:12px}
@media(max-width:760px){.activity-row{align-items:start}.operations-next-form .operations-next-button{min-height:50px}}
@media (min-width:1000px){
 #customer-list.customer-intelligence-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-items:start}#customer-list.customer-intelligence-grid .customer-card{margin:0}
}
</style><link rel="stylesheet" href="admin-visuals.css?v=<?=e((string)(@filemtime(__DIR__.'/admin-visuals.css') ?: 0))?>"><link rel="stylesheet" href="premium-v2.css?v=<?=e((string)(@filemtime(__DIR__.'/premium-v2.css') ?: 0))?>"><link rel="stylesheet" href="product-v2.css?v=<?=e((string)(@filemtime(__DIR__.'/product-v2.css') ?: 0))?>"><link rel="stylesheet" href="packing-v2.css?v=<?=e((string)(@filemtime(__DIR__.'/packing-v2.css') ?: 0))?>"><script>if('serviceWorker'in navigator)addEventListener('load',()=>navigator.serviceWorker.register('./service-worker.js').catch(()=>{}));</script></head>
<?php
// Premium V2 remains opt-in per authenticated session until the full visual QA is complete.
// It modifies presentation only; core order/stock logic is untouched.
if (!empty($auth) && isset($_GET['premium']) && in_array((string)$_GET['premium'], ['1','0'], true)) {
 $_SESSION['ankh_premium_v2_preview']=((string)$_GET['premium']==='1');
}
$premiumV2Preview=!empty($auth) && !empty($_SESSION['ankh_premium_v2_preview']);
$productV2Editing=$premiumV2Preview && ($view??'')==='products' && (int)($_GET['edit_product']??0)>0;
?>
<body class="<?=$premiumV2Preview?'ankh-premium-v2':''?><?=$productV2Editing?' premium-v2-editing':''?>">
<?php if($pinSetupAuthorized): ?>
<main class="login"><div class="mark">☥</div><p class="eyebrow">ANKH / SECURE SETUP</p><h1>Create your 4-digit PIN.</h1><p class="muted">This PIN will protect ANKH Admin. Once saved, this setup link stops working and Voice Order can activate.</p><?php if($error):?><p role="alert" class="error"><?=e($error)?></p><?php endif;?>
<form method="post" action="?setup_pin=<?=e($pinSetupToken)?>"><?php csrf();?><input type="hidden" name="action" value="create_admin_pin"><input type="hidden" name="setup_pin" value="<?=e($pinSetupToken)?>"><label>New 4-digit PIN<input type="password" name="pin" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" required autocomplete="new-password"></label><label>Confirm PIN<input type="password" name="confirm_pin" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" required autocomplete="new-password"></label><button>Save PIN &amp; secure app →</button></form></main>
<?php elseif(!$auth): ?>
<main class="login"><div class="mark">☥</div><p class="eyebrow">ANKH / PRIVATE ACCESS</p><h1><?= $hasIndividualLogins ? 'Welcome back.' : 'Enter your PIN.' ?></h1><p class="muted"><?= $hasIndividualLogins ? 'Sign in to your ANKH account.' : 'Use your existing ANKH Admin PIN.' ?></p><?php if($error):?><p role="alert" class="error"><?=e($error)?></p><?php endif;?>
<form method="post"><?php csrf();?><input type="hidden" name="action" value="login">
<?php if($hasIndividualLogins):?>
<label>Username or email<input type="text" name="username" autocomplete="username" maxlength="32" required></label>
<label>Password<input type="password" name="password" autocomplete="current-password" required></label>
<label style="display:flex;align-items:center;gap:10px"><input type="checkbox" name="remember" value="1" style="width:auto"> Remember this device for 30 days</label>
<?php else:?>
<label>4-digit PIN<input type="password" name="password" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" required autocomplete="current-password"></label>
<?php endif;?><button>Sign in →</button></form>
<?php if($passkeyCount>0):?><div class="login-divider"><span>or</span></div><button type="button" id="passkey-login" class="passkey-login">◎ Use Face ID / Passkey</button><p id="passkey-login-status" class="muted passkey-status"></p><?php endif;?>
<script>
function ankhB64ToBuffer(value){value=value.replace(/-/g,'+').replace(/_/g,'/');while(value.length%4)value+='=';const raw=atob(value),out=new Uint8Array(raw.length);for(let i=0;i<raw.length;i++)out[i]=raw.charCodeAt(i);return out.buffer}
function ankhBufferToB64(buffer){const bytes=new Uint8Array(buffer);let raw='';bytes.forEach(b=>raw+=String.fromCharCode(b));return btoa(raw).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'')}
document.querySelector('#passkey-login')?.addEventListener('click',async event=>{
 const button=event.currentTarget,status=document.querySelector('#passkey-login-status');button.disabled=true;if(status)status.textContent='Waiting for Face ID / passkey…';
 try{
  const start=await fetch('?api=passkey-options',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:<?=json_encode($_SESSION['csrf'])?>,kind:'login'})});const payload=await start.json();if(!start.ok||!payload.ok)throw new Error(payload.error||'Passkey could not start.');
  const opts=payload.publicKey;opts.challenge=ankhB64ToBuffer(opts.challenge);opts.allowCredentials=(opts.allowCredentials||[]).map(c=>({...c,id:ankhB64ToBuffer(c.id)}));
  const cred=await navigator.credentials.get({publicKey:opts});if(!cred)throw new Error('Passkey was cancelled.');
  const finish=await fetch('?api=passkey-login',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:<?=json_encode($_SESSION['csrf'])?>,rawId:ankhBufferToB64(cred.rawId),clientDataJSON:ankhBufferToB64(cred.response.clientDataJSON),authenticatorData:ankhBufferToB64(cred.response.authenticatorData),signature:ankhBufferToB64(cred.response.signature)})});const done=await finish.json();if(!finish.ok||!done.ok)throw new Error(done.error||'Passkey sign-in failed.');location.href='./?view=dashboard';
 }catch(error){if(status)status.textContent=error?.message||'Passkey sign-in failed.'}
 finally{button.disabled=false}
});
</script></main>
<?php else:
$products=$db->query('SELECT * FROM products ORDER BY active DESC,name')->fetchAll(PDO::FETCH_ASSOC);
$stockProducts=$db->query('SELECT * FROM products ORDER BY active DESC,name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$fridgeTotals=$db->query('SELECT COALESCE(SUM(stock_jay_qty),0) AS jay,COALESCE(SUM(stock_tony_qty),0) AS tony,COALESCE(SUM(stock_unallocated_qty),0) AS unallocated FROM products WHERE stock_tracking=1')->fetch(PDO::FETCH_ASSOC);
$reservedStockByProduct=[];$reservationDetailsByProduct=[];$fridgeReservedTotals=['jay'=>0,'tony'=>0,'unallocated'=>0];
$reservationRows=$db->query("SELECT sf.product_id,sf.order_id,sf.quantity,sf.location,o.customer,o.phone,o.status,o.created,o.assigned_to FROM stock_fulfilments sf JOIN orders o ON o.id=sf.order_id WHERE o.back_order=0 AND o.status NOT IN ('Cancelled','Dispatched','Delivered') AND trim(COALESCE(o.delivery_date,''))='' ORDER BY datetime(o.created) DESC,o.id DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach($reservationRows as $reservedRow){$locName=stockLocationName((string)$reservedRow['location']);$loc=strtolower($locName);$qty=max(0,(int)$reservedRow['quantity']);$productId=(int)$reservedRow['product_id'];$reservedStockByProduct[$productId][$loc]=($reservedStockByProduct[$productId][$loc]??0)+$qty;if(isset($fridgeReservedTotals[$loc]))$fridgeReservedTotals[$loc]+=$qty;$reservationDetailsByProduct[$productId][]=['order_id'=>(int)$reservedRow['order_id'],'quantity'=>$qty,'location'=>$locName,'customer'=>(string)$reservedRow['customer'],'phone'=>(string)$reservedRow['phone'],'status'=>(string)$reservedRow['status'],'created'=>(string)$reservedRow['created'],'assigned_to'=>(string)($reservedRow['assigned_to']??'')];}
$fridgePhysicalTotals=['jay'=>(int)($fridgeTotals['jay']??0)+(int)$fridgeReservedTotals['jay'],'tony'=>(int)($fridgeTotals['tony']??0)+(int)$fridgeReservedTotals['tony'],'unallocated'=>(int)($fridgeTotals['unallocated']??0)+(int)$fridgeReservedTotals['unallocated']];
$stockShortages=stockReservationShortages($stockProducts,$reservedStockByProduct,$reservationDetailsByProduct);
$stockShortageUnits=array_sum(array_column($stockShortages,'missing'));
$fridgeValueTotals=['jay'=>['retail'=>0,'cost'=>0,'profit'=>0,'missing_cost'=>0],'tony'=>['retail'=>0,'cost'=>0,'profit'=>0,'missing_cost'=>0],'combined'=>['retail'=>0,'cost'=>0,'profit'=>0,'missing_cost'=>0]];
foreach($stockProducts as $fridgeProduct){
 if((int)($fridgeProduct['stock_tracking']??0)!==1)continue;
 $retailUnit=(int)($fridgeProduct['price']??0);$costUnit=$fridgeProduct['cost']===null?null:(int)$fridgeProduct['cost'];
 foreach(['jay'=>(int)($fridgeProduct['stock_jay_qty']??0),'tony'=>(int)($fridgeProduct['stock_tony_qty']??0)] as $owner=>$qty){
  $qty=max(0,$qty);$fridgeValueTotals[$owner]['retail']+=$qty*$retailUnit;$fridgeValueTotals['combined']['retail']+=$qty*$retailUnit;
  if($costUnit===null){if($qty>0){$fridgeValueTotals[$owner]['missing_cost']++;$fridgeValueTotals['combined']['missing_cost']++;}continue;}
  $costValue=$qty*$costUnit;$profitValue=$qty*($retailUnit-$costUnit);
  $fridgeValueTotals[$owner]['cost']+=$costValue;$fridgeValueTotals[$owner]['profit']+=$profitValue;
  $fridgeValueTotals['combined']['cost']+=$costValue;$fridgeValueTotals['combined']['profit']+=$profitValue;
 }
}
$recentStockMovements=$db->query('SELECT sm.*,p.name AS product_name FROM stock_movements sm JOIN products p ON p.id=sm.product_id ORDER BY datetime(sm.created) DESC,sm.id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
$stockMovementsByLocation=['Jay'=>[],'Tony'=>[],'Unallocated'=>[]];foreach($recentStockMovements as $stockMove){$moveLocation=stockLocationName((string)$stockMove['location']);if(isset($stockMovementsByLocation[$moveLocation]))$stockMovementsByLocation[$moveLocation][]=$stockMove;}
/**
 * Outstanding supplier stock grouped by product across all still-open purchases.
 * Received and cancelled deliveries must not contribute, and part-received lines
 * contribute ONLY the remaining units. Does not change physical inventory.
 */
function stockIncomingProductBreakdown(array $supplierLines):array{
 $byProduct=[];
 foreach($supplierLines as $line){
  if(in_array((string)$line['status'],['Received','Cancelled'],true))continue;
  $due=max(0,(int)$line['quantity']-(int)$line['received_qty']);
  if($due===0)continue;
  $id=(int)$line['product_id'];if($id<1)continue;
  if(!isset($byProduct[$id]))$byProduct[$id]=[
   'product_id'=>$id,'name'=>(string)$line['product_name'],
   'due'=>0,'orders'=>[],'expected_date'=>null
  ];
  $byProduct[$id]['due']+=$due;
  $byProduct[$id]['orders'][(int)$line['supplier_order_id']]=true;
  $date=(string)($line['expected_date']??'');
  if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
   if($byProduct[$id]['expected_date']===null||$date<$byProduct[$id]['expected_date'])
    $byProduct[$id]['expected_date']=$date;
  }
 }
 $result=[];
 foreach($byProduct as $entry){
  $entry['order_ids']=array_keys($entry['orders']);
  $entry['orders']=count($entry['orders']);
  $result[]=$entry;
 }
 usort($result,static fn($a,$b)=>($b['due']<=>$a['due']) ?: strcasecmp($a['name'],$b['name']));
 return $result;
}
$supplierOrders=$db->query("SELECT so.*,COALESCE(SUM(soi.quantity),0) total_qty,COALESCE(SUM(soi.received_qty),0) received_qty,COALESCE(SUM(CASE WHEN soi.unit_cost IS NULL THEN 0 ELSE soi.quantity*soi.unit_cost END),0) total_cost,SUM(CASE WHEN soi.unit_cost IS NULL THEN 1 ELSE 0 END) missing_cost FROM supplier_orders so LEFT JOIN supplier_order_items soi ON soi.supplier_order_id=so.id GROUP BY so.id ORDER BY CASE so.status WHEN 'Ordered' THEN 0 WHEN 'Part received' THEN 1 WHEN 'Received' THEN 2 ELSE 3 END,date(so.expected_date),so.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$supplierOrderItems=[];$incomingByProduct=[];$supplierItemRows=$db->query("SELECT soi.*,p.name product_name,so.supplier,so.expected_date,so.status FROM supplier_order_items soi JOIN products p ON p.id=soi.product_id JOIN supplier_orders so ON so.id=soi.supplier_order_id ORDER BY soi.supplier_order_id DESC,p.name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);foreach($supplierItemRows as $supplierItem){$supplierOrderItems[(int)$supplierItem['supplier_order_id']][]=$supplierItem;if(!in_array((string)$supplierItem['status'],['Received','Cancelled'],true))$incomingByProduct[(int)$supplierItem['product_id']]=($incomingByProduct[(int)$supplierItem['product_id']]??0)+max(0,(int)$supplierItem['quantity']-(int)$supplierItem['received_qty']);}
$totalIncomingUnits=array_sum($incomingByProduct);
$incomingProductBreakdown=stockIncomingProductBreakdown($supplierItemRows);
$orders=$db->query("SELECT o.*,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) AS total,COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id=o.id),0) AS paid_amount FROM orders o LEFT JOIN items i ON i.order_id=o.id GROUP BY o.id ORDER BY datetime(o.created) DESC,o.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$paidStatuses=['Paid','Packed','Dispatched','Delivered'];
$open=count(array_filter($orders,fn($o)=>!in_array($o['status'],['Dispatched','Delivered','Cancelled'],true)));
$paid=array_sum(array_map(fn($o)=>in_array($o['status'],$paidStatuses,true)?$o['total']:0,$orders));

$storedCustomers=$db->query('SELECT * FROM customers ORDER BY archived ASC,name COLLATE NOCASE,id')->fetchAll(PDO::FETCH_ASSOC);
$customerSuggestions=array_values(array_map(fn($customer)=>[
 'id'=>(int)$customer['id'],
 'name'=>(string)$customer['name'],
 'phone'=>(string)$customer['phone'],
 'address'=>(string)$customer['address'],
 'pricing_type'=>(string)$customer['pricing_type']
],array_filter($storedCustomers,fn($customer)=>(int)$customer['archived']===0)));
$customerOrderHistory=[];
foreach($orders as $customerOrder){
 $customerKey=strtolower(trim((string)$customerOrder['customer'])).'|'.trim((string)$customerOrder['phone']);
 $customerOrderHistory[$customerKey][]=$customerOrder;
}
$customerIdByKey=[];foreach($storedCustomers as $storedCustomer){$customerIdByKey[strtolower(trim((string)$storedCustomer['name'])).'|'.trim((string)$storedCustomer['phone'])]=(int)$storedCustomer['id'];}
$paymentsByOrder=[];$allPayments=$db->query('SELECT * FROM payments ORDER BY datetime(created) DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC);
foreach($allPayments as $ledgerPayment)$paymentsByOrder[(int)$ledgerPayment['order_id']][]=$ledgerPayment;
$balanceAccounts=[];
foreach($customerOrderHistory as $balanceKey=>$balanceHistory){
 $openBalanceOrders=[];$openPaid=0;$openOutstanding=0;$oldestUnpaid=null;$hasPartPaid=false;$hasUnpaid=false;$hasOverdue=false;
 foreach($balanceHistory as $balanceOrder){
  if((string)$balanceOrder['status']==='Cancelled')continue;
  $orderPaid=(int)($balanceOrder['paid_amount']??0);$orderTotal=(int)$balanceOrder['total'];$orderDue=max(0,$orderTotal-$orderPaid);
  if($orderDue<=0)continue;
  $orderCopy=$balanceOrder;$orderCopy['balance_due']=$orderDue;$orderCopy['payments']=$paymentsByOrder[(int)$balanceOrder['id']]??[];
  $openBalanceOrders[]=$orderCopy;$openPaid+=$orderPaid;$openOutstanding+=$orderDue;
  if($orderPaid>0)$hasPartPaid=true;else$hasUnpaid=true;
  if(!empty($balanceOrder['delivery_date']))$hasOverdue=true;
  if($oldestUnpaid===null || strtotime((string)$balanceOrder['created'])<strtotime((string)$oldestUnpaid['created']))$oldestUnpaid=$orderCopy;
 }
 if(!$openBalanceOrders)continue;
 $first=$openBalanceOrders[0];$balanceAccounts[]=[
  'key'=>$balanceKey,'customer'=>(string)$first['customer'],'phone'=>(string)$first['phone'],'customer_id'=>$customerIdByKey[$balanceKey]??0,
  'total_orders'=>count(array_filter($balanceHistory,fn($o)=>(string)$o['status']!=='Cancelled')),
  'open_orders'=>count($openBalanceOrders),'paid'=>$openPaid,'outstanding'=>$openOutstanding,'oldest'=>$oldestUnpaid,
  'part_paid'=>$hasPartPaid,'unpaid'=>$hasUnpaid,'overdue'=>$hasOverdue,'orders'=>$openBalanceOrders
 ];
}
usort($balanceAccounts,fn($a,$b)=>$b['outstanding']<=>$a['outstanding']);
$balancesTotalOutstanding=array_sum(array_column($balanceAccounts,'outstanding'));
$balancesTotalPaid=array_sum(array_column($balanceAccounts,'paid'));
$balancesOverdueCount=count(array_filter($balanceAccounts,fn($a)=>$a['overdue']));
$customerProductCounts=[];
$customerProducts=$db->query("SELECT o.customer,o.phone,i.name,SUM(i.quantity) qty FROM orders o JOIN items i ON i.order_id=o.id WHERE o.status<>'Cancelled' AND lower(trim(i.name))<>'pen' GROUP BY lower(trim(o.customer)),trim(o.phone),i.name ORDER BY qty DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach($customerProducts as $customerProduct){
 $key=strtolower(trim((string)$customerProduct['customer'])).'|'.trim((string)$customerProduct['phone']);
 $customerProductCounts[$key][]=['name'=>(string)$customerProduct['name'],'qty'=>(int)$customerProduct['qty']];
}

$newOrderCustomer=null;$repeatOrderData=null;$repeatPaymentMethod='';$repeatDeliveryMethod='';$repeatTrackingReference='';$repeatDeliveryCharge=0;$repeatPostageCost=0;$repeatPaymentFee=0;$repeatLines=[];
$editOrder=null;$editLines=[];

$productCatalogForJs=[];
foreach($products as $catalogProduct){
 $baseName=(string)$catalogProduct['name'];$strength='';
 if(preg_match('/\s+(\d+(?:\.\d+)?\s*(?:mg|ml|iu))$/i',$baseName,$pm)){$strength=$pm[1];$baseName=trim(substr($baseName,0,-strlen($pm[0])));}
 $productCatalogForJs[]=['id'=>(int)$catalogProduct['id'],'name'=>(string)$catalogProduct['name'],'base'=>$baseName,'strength'=>$strength,'price'=>(int)$catalogProduct['price']/100,'family_price'=>$catalogProduct['family_friends_price']===null?max(0,((int)$catalogProduct['price']-500)/100):(int)$catalogProduct['family_friends_price']/100,'active'=>(int)$catalogProduct['active']===1];
}

foreach($productCatalogForJs as &$jsProduct){foreach($products as $p){if((int)$p['id']===$jsProduct['id']){$jsProduct['saved_family_price']=$p['family_friends_price']===null?null:(int)$p['family_friends_price']/100;break;}}}unset($jsProduct);
if($view==='new' && (int)($_GET['customer_id']??0)>0){
 $q=$db->prepare('SELECT * FROM customers WHERE id=? AND archived=0');$q->execute([(int)$_GET['customer_id']]);$newOrderCustomer=$q->fetch(PDO::FETCH_ASSOC)?:null;
}
if($view==='new' && (int)($_GET['repeat_order']??0)>0){
 $repeatId=(int)$_GET['repeat_order'];$q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$repeatId]);$repeatOrder=$q->fetch(PDO::FETCH_ASSOC);
 if($repeatOrder){
  $repeatOrderData=$repeatOrder;$newOrderCustomer=['name'=>$repeatOrder['customer'],'phone'=>$repeatOrder['phone'],'address'=>$repeatOrder['address']];
  $repeatPaymentMethod=(string)($repeatOrder['payment_method']??'');$repeatDeliveryMethod=(string)($repeatOrder['delivery_method']??'');$repeatTrackingReference=(string)($repeatOrder['tracking_reference']??'');$repeatDeliveryCharge=(int)($repeatOrder['delivery_charge']??0);$repeatPostageCost=(int)($repeatOrder['postage_cost']??0);$repeatPaymentFee=(int)($repeatOrder['payment_fee']??0);
  $nameToProduct=[];foreach($products as $rp)if((int)$rp['active']===1)$nameToProduct[strtolower(trim((string)$rp['name']))]=$rp;
  $q=$db->prepare('SELECT name,price,base_price,discount,presentation,quantity,recurring,cycle_weeks FROM items WHERE order_id=? ORDER BY id');$q->execute([$repeatId]);
  $repeatItems=$q->fetchAll(PDO::FETCH_ASSOC);$repeatPairCounter=0;$repeatPenProduct=$nameToProduct['pen']??null;
  foreach($repeatItems as $ri){
   $isStandalonePen=strtolower(trim((string)$ri['name']))==='pen';
   $p=$nameToProduct[strtolower(trim((string)$ri['name']))]??null;if(!$p)continue;
   $format=$isStandalonePen?'':(in_array((string)$ri['presentation'],['Pen','Cartridge','Vial'],true)?(string)$ri['presentation']:(in_array((string)($repeatOrder['presentation']??''),['Pen','Cartridge','Vial'],true)?(string)$repeatOrder['presentation']:'Vial'));
   $base=$ri['base_price']===null?(int)$p['price']:(int)$ri['base_price'];$discount=!$isStandalonePen&&(int)($ri['discount']??0)>0;$pairId='repeat_'.(++$repeatPairCounter);
   $linePrice=$isStandalonePen?(int)$ri['price']:max(0,$base-($discount?500:0));
   $repeatLines[]=['product_id'=>(int)$p['id'],'name'=>(string)$p['name'],'quantity'=>(int)$ri['quantity'],'presentation'=>$format,'base_price'=>$isStandalonePen?(int)$p['price']/100:$base/100,'discount'=>$discount,'price'=>$linePrice/100,'pair_id'=>$pairId,'paired_pen'=>false,'from_repeat'=>true,'recurring'=>(int)($ri['recurring']??0)===1,'cycle_weeks'=>(int)($ri['cycle_weeks']??0)];
  }
 }
}
if($view==='edit' && (int)($_GET['id']??0)>0){
 $editId=(int)$_GET['id'];$q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$editId]);$editOrder=$q->fetch(PDO::FETCH_ASSOC)?:null;
 if($editOrder){
  $nameToProduct=[];foreach($products as $ep)$nameToProduct[strtolower(trim((string)$ep['name']))]=$ep;
  $q=$db->prepare('SELECT name,price,base_price,discount,presentation,quantity,recurring,cycle_weeks FROM items WHERE order_id=? ORDER BY id');$q->execute([$editId]);
  $editItems=$q->fetchAll(PDO::FETCH_ASSOC);$editPairCounter=0;
  foreach($editItems as $ei){
   $isStandalonePen=strtolower(trim((string)$ei['name']))==='pen';
   $p=$nameToProduct[strtolower(trim((string)$ei['name']))]??null;if(!$p)continue;
   $hasLineFormat=!$isStandalonePen&&in_array((string)$ei['presentation'],['Pen','Cartridge','Vial'],true);
   $format=$isStandalonePen?'':($hasLineFormat?(string)$ei['presentation']:(in_array((string)($editOrder['presentation']??''),['Pen','Cartridge','Vial'],true)?(string)$editOrder['presentation']:'Vial'));
   $base=$ei['base_price']===null?(int)$p['price']:(int)$ei['base_price'];$discount=!$isStandalonePen&&(int)($ei['discount']??0)>0;$pairId='edit_'.(++$editPairCounter);
   $editPrice=(int)$ei['price'];
   $editLines[]=['product_id'=>(int)$p['id'],'name'=>(string)$p['name'],'quantity'=>(int)$ei['quantity'],'presentation'=>$format,'base_price'=>$isStandalonePen?(int)$p['price']/100:$base/100,'discount'=>$discount,'price'=>$editPrice/100,'pair_id'=>$pairId,'paired_pen'=>false];
  }
 }
}
$recentCustomers=[];$recentSeen=[];
foreach($orders as $recentOrder){
 $key=strtolower(trim((string)$recentOrder['customer'])).'|'.trim((string)$recentOrder['phone']);if(isset($recentSeen[$key]))continue;
 foreach($customerSuggestions as $candidate){
  if(strtolower(trim($candidate['name'])).'|'.trim($candidate['phone'])===$key){$recentCustomers[]=$candidate;$recentSeen[$key]=true;break;}
 }
 if(count($recentCustomers)>=5)break;
}
$savedOrder=null;
if($view==='saved' && (int)($_GET['id']??0)>0)$savedOrder=orderForSheet($db,(int)$_GET['id']);
$customerAccount=null;
if($view==='customer' && (int)($_GET['id']??0)>0){$q=$db->prepare('SELECT * FROM customers WHERE id=?');$q->execute([(int)$_GET['id']]);$customerAccount=$q->fetch(PDO::FETCH_ASSOC)?:null;}


// Dashboard figures use paid/packed/dispatched/delivered orders as completed sales.
$tz=new DateTimeZone('Europe/London');$now=new DateTimeImmutable('now',$tz);
$retaToday=$now->setTime(0,0);
$retaCyclesActive=$db->query("SELECT * FROM reta_cycles WHERE active=1 ORDER BY date(next_due_date),customer_name COLLATE NOCASE,product_name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
$retaCyclesStopped=$db->query("SELECT * FROM reta_cycles WHERE active=0 ORDER BY datetime(updated) DESC,id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$cyclePendingOrderById=[];$cycleOpenOrderRows=$db->query("SELECT o.id,o.customer,o.recipient_name,o.phone,o.created,o.status,i.name,i.quantity FROM orders o JOIN items i ON i.order_id=o.id WHERE o.status<>'Cancelled' ORDER BY o.id DESC,i.id")->fetchAll(PDO::FETCH_ASSOC);
$cycleOpenByKey=[];
foreach($cycleOpenOrderRows as $cycleOpenRow){$orderFor=trim((string)($cycleOpenRow['recipient_name']??''))?:trim((string)$cycleOpenRow['customer']);$cycleOpenKey=strtolower(trim((string)$cycleOpenRow['customer'])).'|'.trim((string)$cycleOpenRow['phone']).'|'.strtolower(trim((string)$cycleOpenRow['name'])).'|'.strtolower($orderFor);$cycleOpenByKey[$cycleOpenKey][]=$cycleOpenRow;}
foreach($retaCyclesActive as $cycleCheck){$cycleKey=strtolower(trim((string)$cycleCheck['customer_name'])).'|'.trim((string)$cycleCheck['phone']).'|'.strtolower(trim((string)($cycleCheck['product_name']?:$cycleCheck['product_summary']))).'|'.strtolower(trim(cycleRecipientName($cycleCheck)));foreach(($cycleOpenByKey[$cycleKey]??[]) as $candidate){if((int)$candidate['id']>(int)($cycleCheck['last_order_id']??0)){$cyclePendingOrderById[(int)$cycleCheck['id']]=$candidate;break;}}}
$retaForecastCycles=array_values(array_filter($retaCyclesActive,fn($cycle)=>!isset($cyclePendingOrderById[(int)$cycle['id']])));
$recurringForecast30=retaProjection($retaForecastCycles,$retaToday,30);$recurringForecast60=retaProjection($retaForecastCycles,$retaToday,60);$recurringForecast90=retaProjection($retaForecastCycles,$retaToday,90);
$recurringNext30=array_values(array_filter($retaForecastCycles,function($cycle)use($retaToday){$due=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycle['next_due_date'],$retaToday->getTimezone());return $due && $due<=$retaToday->modify('+30 days');}));
$recurringPotentialValue30=(int)($recurringForecast30['revenue']??0);
$recurringPotentialOrders30=(int)($recurringForecast30['orders']??0);$recurringUnits30=0;foreach(($recurringForecast30['stock']??[]) as $forecastUnitName=>$forecastUnitQty)if(strtolower(trim((string)$forecastUnitName))!=='pen')$recurringUnits30+=(int)$forecastUnitQty;
$recurringGroups=['overdue'=>[],'week'=>[],'month'=>[],'later'=>[],'created'=>[]];
foreach($retaCyclesActive as $cycleGroup){$pending=$cyclePendingOrderById[(int)$cycleGroup['id']]??null;if($pending){$cycleGroup['_pending']=$pending;$recurringGroups['created'][]=$cycleGroup;continue;}$due=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycleGroup['next_due_date'],$retaToday->getTimezone());if(!$due)continue;$days=(int)$retaToday->diff($due)->format('%r%a');if($days<0)$recurringGroups['overdue'][]=$cycleGroup;elseif($days<=7)$recurringGroups['week'][]=$cycleGroup;elseif($days<=30)$recurringGroups['month'][]=$cycleGroup;else$recurringGroups['later'][]=$cycleGroup;}

$retaForecast4=retaProjection($retaCyclesActive,$retaToday,28);$retaForecast5=retaProjection($retaCyclesActive,$retaToday,35);$retaForecast8=retaProjection($retaCyclesActive,$retaToday,56);$retaForecast12=retaProjection($retaCyclesActive,$retaToday,84);
$retaDue7=array_values(array_filter($retaCyclesActive,function($cycle)use($retaToday){$due=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycle['next_due_date'],$retaToday->getTimezone());return $due && $due<=$retaToday->modify('+7 days');}));
$retaOverdue=array_values(array_filter($retaCyclesActive,function($cycle)use($retaToday){$due=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycle['next_due_date'],$retaToday->getTimezone());return $due && $due<$retaToday;}));
$cycleHorizonWeeks=in_array((int)($_GET['weeks']??8),[4,5,8,12],true)?(int)($_GET['weeks']??8):8;
$cycleForecast=retaProjection($retaCyclesActive,$retaToday,$cycleHorizonWeeks*7);
$cycleOccurrences=cycleOccurrences($retaCyclesActive,$retaToday,$cycleHorizonWeeks);
$productStockMap=[];foreach($products as $stockProduct)$productStockMap[strtolower(trim((string)$stockProduct['name']))]=$stockProduct;
$recurringStockRiskCount=0;$recurringStock30=[];
foreach(($recurringForecast30['stock']??[]) as $forecastName=>$forecastQty){$forecastProduct=$productStockMap[strtolower(trim((string)$forecastName))]??null;$available=$forecastProduct&&(int)($forecastProduct['stock_tracking']??0)===1?(int)($forecastProduct['stock_qty']??0):null;$shortfall=$available===null?null:max(0,(int)$forecastQty-$available);if($shortfall!==null&&$shortfall>0)$recurringStockRiskCount++;$recurringStock30[]=['name'=>$forecastName,'expected'=>(int)$forecastQty,'available'=>$available,'shortfall'=>$shortfall];}
usort($recurringStock30,fn($a,$b)=>(($b['shortfall']??-1)<=>($a['shortfall']??-1))?:($b['expected']<=>$a['expected']));
$cycleStockRows=[];foreach(($cycleForecast['stock']??[]) as $productName=>$needed){$stockProduct=$productStockMap[strtolower(trim((string)$productName))]??null;$current=$stockProduct&&(int)($stockProduct['stock_tracking']??0)===1&&$stockProduct['stock_qty']!==null?(int)$stockProduct['stock_qty']:null;$cycleStockRows[]=['name'=>$productName,'needed'=>(int)$needed,'current'=>$current,'shortfall'=>$current===null?null:max(0,(int)$needed-$current)];}
usort($cycleStockRows,fn($a,$b)=>(($b['shortfall']??-1)<=>($a['shortfall']??-1))?:strcmp((string)$a['name'],(string)$b['name']));
$todayStart=$now->setTime(0,0)->getTimestamp();$weekStart=$now->modify('monday this week')->setTime(0,0)->getTimestamp();$monthStart=$now->modify('first day of this month')->setTime(0,0)->getTimestamp();
$todaySales=0;$monthSales=0;$unpaidBalance=0;
foreach($orders as $dashboardOrder){
 $salesTs=strtotime((string)($dashboardOrder['payment_date']?:$dashboardOrder['created']))?:0;
 if(in_array($dashboardOrder['status'],$paidStatuses,true)){
  if($salesTs>=$todayStart)$todaySales+=(int)$dashboardOrder['total'];
  if($salesTs>=$monthStart)$monthSales+=(int)$dashboardOrder['total'];
 }
 if(!isBackOrder($dashboardOrder)&&$dashboardOrder['status']!=='Cancelled')$unpaidBalance+=max(0,(int)$dashboardOrder['total']-(int)($dashboardOrder['paid_amount']??0));
}
$awaitingPayment=array_values(array_filter($orders,fn($o)=>!isBackOrder($o)&&$o['status']!=='Cancelled' && max(0,(int)$o['total']-(int)($o['paid_amount']??0))>0));
$futureDeliveries=array_values(array_filter($orders,fn($o)=>deliveryBoardSection($o,$now->format('Y-m-d'))==='future'));
usort($futureDeliveries,fn($a,$b)=>strcmp((string)$a['scheduled_delivery_date'],(string)$b['scheduled_delivery_date']) ?: (int)$a['id']<=>(int)$b['id']);
$awaitingDelivery=array_values(array_filter($orders,fn($o)=>deliveryBoardSection($o,$now->format('Y-m-d'))==='awaiting'));
usort($awaitingPayment,fn($a,$b)=>strcmp((string)$a['created'],(string)$b['created']));
usort($awaitingDelivery,fn($a,$b)=>strcmp((string)($a['scheduled_delivery_date']?:'9999-12-31'),(string)($b['scheduled_delivery_date']?:'9999-12-31')) ?: strcmp((string)$a['created'],(string)$b['created']));
$todoOrderIds=array_map('intval',array_merge(array_column($awaitingPayment,'id'),array_column($awaitingDelivery,'id'),array_column($futureDeliveries,'id')));
$todoItems=[];
if($todoOrderIds){
 $placeholders=implode(',',array_fill(0,count($todoOrderIds),'?'));
 $q=$db->prepare("SELECT order_id,name,quantity,price,presentation,discount FROM items WHERE order_id IN ($placeholders) ORDER BY id");
 $q->execute($todoOrderIds);
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $todoItem)$todoItems[(int)$todoItem['order_id']][]=$todoItem;
}
$grossProfit=0;$uncostedSales=0;$topSelling=[];$orderCostById=[];$orderPenCostById=[];$orderMissingCost=[];$productProfit=[];$penCostAll=0;
$dashboardItems=$db->query("SELECT i.order_id,i.name,i.price,i.cost,i.presentation,i.presentation_cost,i.base_price,i.discount,i.quantity,o.status FROM items i JOIN orders o ON o.id=i.order_id WHERE o.status IN ('Paid','Packed','Dispatched','Delivered')")->fetchAll(PDO::FETCH_ASSOC);
$ordersWithPenRows=[];foreach($dashboardItems as $itemForPenCheck)if(strtolower(trim((string)$itemForPenCheck['name']))==='pen')$ordersWithPenRows[(int)$itemForPenCheck['order_id']]=true;
$personalOrderIds=[];foreach($orders as $po)if(($po['pricing_type']??'standard')==='free')$personalOrderIds[(int)$po['id']]=true;
foreach($dashboardItems as $dashboardItem){
 if(isset($personalOrderIds[(int)$dashboardItem['order_id']]))continue;
 $orderId=(int)$dashboardItem['order_id'];$qty=(int)$dashboardItem['quantity'];$line=(int)$dashboardItem['price']*$qty;$cost=$dashboardItem['cost']===null?null:(int)$dashboardItem['cost'];$presentationCost=(int)($dashboardItem['presentation_cost']??0);$name=(string)$dashboardItem['name'];
 if(strtolower(trim($name))==='pen'){
  if($cost!==null){$standalonePenCost=$cost*$qty;$orderCostById[$orderId]=($orderCostById[$orderId]??0)+$standalonePenCost;$orderPenCostById[$orderId]=($orderPenCostById[$orderId]??0)+$standalonePenCost;$penCostAll+=$standalonePenCost;}else{$uncostedSales+=$line;$orderMissingCost[$orderId]=true;}
  $topSelling['Pen']??=['qty'=>0,'revenue'=>0];$topSelling['Pen']['qty']+=$qty;$topSelling['Pen']['revenue']+=$line;
  $productProfit['Pen']??=['units'=>0,'revenue'=>0,'cogs'=>0,'list_revenue'=>0,'discount_total'=>0,'prices'=>[],'missing_cost'=>false];
  $penListUnit=$dashboardItem['base_price']===null?(int)$dashboardItem['price']:(int)$dashboardItem['base_price'];$penActualUnit=(int)$dashboardItem['price'];
  $productProfit['Pen']['units']+=$qty;$productProfit['Pen']['revenue']+=$line;$productProfit['Pen']['list_revenue']+=$penListUnit*$qty;$productProfit['Pen']['discount_total']+=max(0,($penListUnit-$penActualUnit)*$qty);
  $productProfit['Pen']['prices'][$penActualUnit]=($productProfit['Pen']['prices'][$penActualUnit]??0)+$qty;
  if($cost!==null)$productProfit['Pen']['cogs']+=$cost*$qty;else$productProfit['Pen']['missing_cost']=true;
  continue;
 }
 if($cost!==null)$orderCostById[$orderId]=($orderCostById[$orderId]??0)+$cost*$qty;else{$uncostedSales+=$line;$orderMissingCost[$orderId]=true;}
 $penRevenue=0;
 if((string)$dashboardItem['presentation']==='Pen' && empty($ordersWithPenRows[$orderId])){
  $baseSnapshot=$dashboardItem['base_price']===null?null:(int)$dashboardItem['base_price'];$discountSnapshot=(int)($dashboardItem['discount']??0);
  $expectedPeptideUnit=$baseSnapshot===null?max(0,(int)$dashboardItem['price']-2000):max(0,$baseSnapshot-$discountSnapshot);
  $penRevenue=max(0,((int)$dashboardItem['price']-$expectedPeptideUnit)*$qty);
  if($penRevenue>0){
   $topSelling['Pen']??=['qty'=>0,'revenue'=>0];$topSelling['Pen']['qty']+=$qty;$topSelling['Pen']['revenue']+=$penRevenue;
   $productProfit['Pen']??=['units'=>0,'revenue'=>0,'cogs'=>0,'list_revenue'=>0,'discount_total'=>0,'prices'=>[],'missing_cost'=>false];$productProfit['Pen']['units']+=$qty;$productProfit['Pen']['revenue']+=$penRevenue;$productProfit['Pen']['list_revenue']+=$penRevenue;$embeddedUnit=$qty>0?(int)round($penRevenue/$qty):0;$productProfit['Pen']['prices'][$embeddedUnit]=($productProfit['Pen']['prices'][$embeddedUnit]??0)+$qty;
   if($presentationCost>0){
    $penCostLine=$presentationCost*$qty;$orderCostById[$orderId]=($orderCostById[$orderId]??0)+$penCostLine;$orderPenCostById[$orderId]=($orderPenCostById[$orderId]??0)+$penCostLine;$penCostAll+=$penCostLine;$productProfit['Pen']['cogs']+=$penCostLine;
   }else{
    $productProfit['Pen']['missing_cost']=true;$orderMissingCost[$orderId]=true;$uncostedSales+=$penRevenue;
   }
  }
 }
 $peptideRevenue=max(0,$line-$penRevenue);
 $topSelling[$name]??=['qty'=>0,'revenue'=>0];$topSelling[$name]['qty']+=$qty;$topSelling[$name]['revenue']+=$peptideRevenue;
 $productProfit[$name]??=['units'=>0,'revenue'=>0,'cogs'=>0,'list_revenue'=>0,'discount_total'=>0,'prices'=>[],'missing_cost'=>false];
 $productProfit[$name]['units']+=$qty;$productProfit[$name]['revenue']+=$peptideRevenue;
 $productListUnit=$dashboardItem['base_price']===null?($qty>0?(int)round($peptideRevenue/$qty):0):(int)$dashboardItem['base_price'];
 $productProfit[$name]['list_revenue']+=$productListUnit*$qty;$productProfit[$name]['discount_total']+=max(0,$productListUnit*$qty-$peptideRevenue);
 $actualProductUnit=$qty>0?(int)round($peptideRevenue/$qty):0;$productProfit[$name]['prices'][$actualProductUnit]=($productProfit[$name]['prices'][$actualProductUnit]??0)+$qty;
 if($cost!==null)$productProfit[$name]['cogs']+=$cost*$qty;else$productProfit[$name]['missing_cost']=true;
}
uasort($topSelling,fn($a,$b)=>$b['qty']<=>$a['qty'] ?: $b['revenue']<=>$a['revenue']);$topSelling=array_slice($topSelling,0,5,true);
uasort($productProfit,fn($a,$b)=>$b['revenue']<=>$a['revenue']);

$reportPeriods=[
 'today'=>['label'=>'Today','start'=>$todayStart,'revenue'=>0,'cogs'=>0,'pen'=>0,'postage'=>0,'profit'=>0,'orders'=>0],
 'week'=>['label'=>'This week','start'=>$weekStart,'revenue'=>0,'cogs'=>0,'pen'=>0,'postage'=>0,'profit'=>0,'orders'=>0],
 'month'=>['label'=>'This month','start'=>$monthStart,'revenue'=>0,'cogs'=>0,'pen'=>0,'postage'=>0,'profit'=>0,'orders'=>0],
 'all'=>['label'=>'All time','start'=>0,'revenue'=>0,'cogs'=>0,'pen'=>0,'postage'=>0,'profit'=>0,'orders'=>0]
];
$reportPeriodKey=in_array($_GET['period']??'week',['today','week','month','all'],true)?($_GET['period']??'week'):'week';
$reportOrderRows=[];$paymentBreakdown=[];
foreach($orders as $reportOrder){
 if(($reportOrder['pricing_type']??'standard')==='free')continue;
 if(!in_array($reportOrder['status'],$paidStatuses,true))continue;
 $orderId=(int)$reportOrder['id'];$dateText=(string)($reportOrder['payment_date']?:$reportOrder['created']);$reportTs=strtotime($dateText)?:0;
 $revenue=(int)$reportOrder['total'];$cogs=(int)($orderCostById[$orderId]??0);$penCogs=(int)($orderPenCostById[$orderId]??0);$postage=(int)($reportOrder['postage_cost']??0);$profit=$revenue-$cogs-$postage;
 $reportOrderRows[]=[
  'id'=>$orderId,'customer'=>(string)$reportOrder['customer'],'status'=>(string)$reportOrder['status'],'assigned_to'=>(string)($reportOrder['assigned_to']??''),
  'date'=>$dateText,'ts'=>$reportTs,'revenue'=>$revenue,'cogs'=>$cogs,'product_cost'=>max(0,$cogs-$penCogs),'pen_cost'=>$penCogs,'postage'=>$postage,'profit'=>$profit,
  'missing_cost'=>!empty($orderMissingCost[$orderId])
 ];
 foreach($reportPeriods as $key=>&$period){if($reportTs>=$period['start']){$period['revenue']+=$revenue;$period['cogs']+=$cogs;$period['pen']+=$penCogs;$period['postage']+=$postage;$period['profit']+=$profit;$period['orders']++;}}unset($period);
 $method=trim((string)($reportOrder['payment_method']??''))?:'Not recorded';$paymentBreakdown[$method]=($paymentBreakdown[$method]??0)+$revenue;
}
usort($reportOrderRows,fn($a,$b)=>$b['ts']<=>$a['ts'] ?: $b['id']<=>$a['id']);
$selectedReportOrders=array_values(array_filter($reportOrderRows,fn($row)=>$row['ts']>=$reportPeriods[$reportPeriodKey]['start']));
$grossProfit=$reportPeriods['all']['profit'];
$trackedStock=array_values(array_filter($products,fn($p)=>(int)$p['active']===1 && (int)($p['stock_tracking']??0)===1 && $p['stock_qty']!==null));
// Low stock follows the Combined fridge view (Jay + Tony), not holding stock.
$lowStock=array_values(array_filter(array_map(function($p){$p['fridge_available']=(int)($p['stock_jay_qty']??0)+(int)($p['stock_tony_qty']??0);return $p;},$trackedStock),fn($p)=>$p['fridge_available']<=(int)$p['low_stock_at']));
usort($lowStock,fn($a,$b)=>$a['fridge_available']<=>$b['fridge_available'] ?: strcasecmp((string)$a['name'],(string)$b['name']));
$penUnitCost=setting($db,'pen_cost_pence','');
$referrers=$db->query("SELECT DISTINCT referrer FROM orders WHERE referrer<>'' ORDER BY referrer COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN);

// Live notification centre. IDs include the current condition so resolved issues can alert again if they recur.
$notificationStateRaw=json_decode(setting($db,'notification_state','{}'),true);$notificationState=is_array($notificationStateRaw)?$notificationStateRaw:[];
$ankhNotifications=[];$notificationNow=time();$todayDate=$retaToday->format('Y-m-d');$tomorrowDate=$retaToday->modify('+1 day')->format('Y-m-d');

$tomorrowDeliveries=array_values(array_filter($orders,fn($o)=>(string)($o['scheduled_delivery_date']??'')===$tomorrowDate && trim((string)($o['delivery_date']??''))==='' && !in_array((string)$o['status'],['Cancelled','Delivered'],true)));
if($tomorrowDeliveries){
 $count=count($tomorrowDeliveries);
 $ankhNotifications[]=['id'=>'delivery:'.$tomorrowDate.':'.$count,'tone'=>'delivery','title'=>$count.' deliver'.($count===1?'y':'ies').' due tomorrow','body'=>'Check preparation, packaging and who each order is assigned to.','href'=>'?view=orders&scope=to_deliver','priority'=>10];
}

$overdueUnpaid=array_values(array_filter($awaitingPayment,function($o)use($todayDate){
 if((string)($o['status']??'')==='Delivered'||trim((string)($o['delivery_date']??''))!=='')return true;
 $scheduled=trim((string)($o['scheduled_delivery_date']??''));
 return $scheduled!==''&&$scheduled<$todayDate;
}));
// The dashboard summary needs delivered-only balances, not the wider overdue alert list.
$deliveredUnpaid=array_values(array_filter($awaitingPayment,fn($o)=>(string)($o['status']??'')==='Delivered'||trim((string)($o['delivery_date']??''))!==''));
if($overdueUnpaid){
 $count=count($overdueUnpaid);$amount=array_sum(array_map(fn($o)=>max(0,(int)$o['total']-(int)($o['paid_amount']??0)),$overdueUnpaid));
 $ankhNotifications[]=['id'=>'unpaid-overdue:'.$count.':'.$amount,'tone'=>'money','title'=>$count.' unpaid order'.($count===1?' is':'s are').' overdue','body'=>money($amount).' is still outstanding on delivered or overdue orders.','href'=>'?view=balances','priority'=>20];
}

// Reorder alerts use the same principles as the planner: real available stock, 7-day demand,
// active recurring demand due in 30 days, incoming supplier stock and supplier pack size.
$notificationRecentDelivered=sc_recent_delivered($db,30);$notificationRecurringByName=[];
foreach(($recurringForecast30['stock']??[]) as $forecastName=>$forecastQty)$notificationRecurringByName[strtolower(trim((string)$forecastName))]=(int)$forecastQty;
$notificationReorderProductIds=[];
foreach($trackedStock as $stockAlert){
 $productId=(int)$stockAlert['id'];$name=(string)$stockAlert['name'];$available=max(0,(int)($stockAlert['stock_qty']??0));$threshold=max(0,(int)($stockAlert['low_stock_at']??0));
 $recent=max(0,(int)($notificationRecentDelivered[$productId]??0));$leadDemand=(int)ceil($recent*7/30);$recurring30=max(0,(int)($notificationRecurringByName[strtolower(trim($name))]??0));
 $incoming=max(0,(int)($incomingByProduct[$productId]??0));$target=$threshold+$leadDemand+$recurring30;$gap=max(0,$target-($available+$incoming));$packSize=max(1,(int)($stockAlert['supplier_pack_size']??10));
 if($gap>0){
  $packs=(int)ceil($gap/$packSize);$notificationReorderProductIds[$productId]=true;
  $body=$available.' available'.($incoming>0?' + '.$incoming.' incoming':'').' · target '.$target.' · '.$packs.' supplier pack'.($packs===1?'':'s').' suggested.';
  $ankhNotifications[]=['id'=>'reorder:'.$productId.':'.$available.':'.$incoming.':'.$target,'tone'=>'reorder','title'=>$name.' reorder needed','body'=>$body,'href'=>'?view=reorder','priority'=>30+$gap];
 }
}
foreach($lowStock as $stockAlert){
 $productId=(int)$stockAlert['id'];if(isset($notificationReorderProductIds[$productId]))continue;
 $qty=(int)$stockAlert['fridge_available'];$threshold=(int)$stockAlert['low_stock_at'];
 $ankhNotifications[]=['id'=>'low-stock:'.$productId.':'.$qty.':'.$threshold,'tone'=>'stock','title'=>(string)$stockAlert['name'].' below stock threshold','body'=>$qty.' available across Jay and Tony · alert level '.$threshold.'.','href'=>'?view=stock&filter=low','priority'=>25];
}

if($retaDue7){
 $count=count($retaDue7);$weekKey=$retaToday->format('o-W');
 $ankhNotifications[]=['id'=>'recurring-week:'.$weekKey.':'.$count,'tone'=>'cycle','title'=>$count.' recurring customer'.($count===1?' is':'s are').' due within 7 days','body'=>'Review upcoming repeat orders and the stock they are expected to need.','href'=>'?view=reta&weeks=4','priority'=>15];
}

usort($ankhNotifications,fn($a,$b)=>(int)($b['priority']??0)<=>(int)($a['priority']??0));
$visibleNotifications=[];
foreach($ankhNotifications as $notice){
 $saved=$notificationState[$notice['id']]??null;
 if(is_array($saved)){if(!empty($saved['dismissed']))continue;if((int)($saved['until']??0)>$notificationNow)continue;}
 $visibleNotifications[]=$notice;
}
$notificationCount=count($visibleNotifications);

if(in_array($view,['reports','reorder'],true)){
// Shared report filters and datasets. Sales, product and customer views use order date;
// cash received uses payment date; recurring and stock views use their own event dates.
$reportTypes=['overview','sales','profit','outgoings','products','productorders','customers','recurring','stock','personal'];
$reportType=in_array($view,['reorder','shipping'],true)?'reorder':(in_array($_GET['type']??'overview',$reportTypes,true)?(string)($_GET['type']??'overview'):'overview');
$reorderWindowDays=in_array((int)($_GET['demand_days']??30),[30,60,90],true)?(int)($_GET['demand_days']??30):30;
$reportRanges=['today','week','month','quarter','all','custom','next4w','next8w','next12w'];
$requestedReportRange=(string)($_GET['range']??'');$defaultReportRange=$reportType==='recurring'?'next8w':'month';
$reportRange=in_array($requestedReportRange,$reportRanges,true)?$requestedReportRange:$defaultReportRange;
if($reportType==='recurring'&&!in_array($reportRange,['next4w','next8w','next12w','custom'],true))$reportRange='next8w';
if($reportType==='reorder')$reportRange='month';
$reportToday=$retaToday;
$reportFrom=$reportToday->modify('first day of this month')->format('Y-m-d');
$reportTo=$reportToday->format('Y-m-d');
if($reportRange==='today')$reportFrom=$reportTo;
elseif($reportRange==='week')$reportFrom=$reportToday->modify('monday this week')->format('Y-m-d');
elseif($reportRange==='quarter'){$quarterMonth=(int)(floor(((int)$reportToday->format('n')-1)/3)*3+1);$reportFrom=$reportToday->setDate((int)$reportToday->format('Y'),$quarterMonth,1)->format('Y-m-d');}
elseif(in_array($reportRange,['next4w','next8w','next12w'],true)){$reportFrom=$reportTo;$reportTo=$reportToday->modify('+'.(int)substr($reportRange,4,-1).' weeks')->format('Y-m-d');}
elseif($reportRange==='all'){$reportFrom='';$reportTo='';}
elseif($reportRange==='custom'){
 $rawFrom=(string)($_GET['from']??'');$rawTo=(string)($_GET['to']??'');
 foreach(['from'=>$rawFrom,'to'=>$rawTo] as $dateField=>$dateValue){
  if($dateValue!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateValue)||!checkdate((int)substr($dateValue,5,2),(int)substr($dateValue,8,2),(int)substr($dateValue,0,4))))$dateValue='';
  if($dateField==='from')$reportFrom=$dateValue;
  else$reportTo=$dateValue;
 }
 if($reportFrom!==''&&$reportTo!==''&&$reportFrom>$reportTo){$swap=$reportFrom;$reportFrom=$reportTo;$reportTo=$swap;}
}
$reportRangeError=$reportRange==='custom'&&($reportFrom===''||$reportTo==='');
$reportSupplies=supplySpending($businessSupplies,$reportFrom,$reportTo);
$reportFromTs=$reportFrom!==''?(new DateTimeImmutable($reportFrom.' 00:00:00',$tz))->getTimestamp():null;
$reportToTs=$reportTo!==''?(new DateTimeImmutable($reportTo.' 23:59:59',$tz))->getTimestamp():null;
$reportOrdersFiltered=[];$reportOrderIds=[];$reportOrderCustomerKeys=[];$reportCustomerStats=[];$reportOrderValue=0;$reportPaidRecorded=0;$reportOutstanding=0;$reportStatusTotals=[];$reportTrend=[];
foreach($orders as $analyticsOrder){
 if(($analyticsOrder['pricing_type']??'standard')==='free')continue;
 if((string)$analyticsOrder['status']==='Cancelled')continue;
 $createdTs=strtotime((string)$analyticsOrder['created'])?:0;
 if($reportFromTs!==null&&$createdTs<$reportFromTs)continue;
 if($reportToTs!==null&&$createdTs>$reportToTs)continue;
 $orderId=(int)$analyticsOrder['id'];$orderValue=(int)$analyticsOrder['total'];$paidRecorded=min($orderValue,max(0,(int)$analyticsOrder['paid_amount']));$balance=max(0,$orderValue-$paidRecorded);
 $analyticsOrder['_report_ts']=$createdTs;$analyticsOrder['_order_value']=$orderValue;$analyticsOrder['_paid_recorded']=$paidRecorded;$analyticsOrder['_balance_due']=$balance;
 $reportOrdersFiltered[]=$analyticsOrder;$reportOrderIds[$orderId]=true;
 $reportOrderValue+=$orderValue;$reportPaidRecorded+=$paidRecorded;$reportOutstanding+=$balance;
 $status=(string)$analyticsOrder['status'];$reportStatusTotals[$status]??=['orders'=>0,'value'=>0];$reportStatusTotals[$status]['orders']++;$reportStatusTotals[$status]['value']+=$orderValue;
 $customerName=trim((string)$analyticsOrder['customer']);$phone=(string)$analyticsOrder['phone'];$customerKey=strtolower($customerName).'|'.preg_replace('/\D+/','',$phone);
 if(!isset($reportCustomerStats[$customerKey]))$reportCustomerStats[$customerKey]=['name'=>$customerName,'phone'=>$phone,'orders'=>0,'order_value'=>0,'paid_recorded'=>0,'balance_due'=>0,'last_order'=>''];
 $reportOrderCustomerKeys[$orderId]=$customerKey;$reportCustomerStats[$customerKey]['orders']++;$reportCustomerStats[$customerKey]['order_value']+=$orderValue;$reportCustomerStats[$customerKey]['paid_recorded']+=$paidRecorded;$reportCustomerStats[$customerKey]['balance_due']+=$balance;
 $lastOrderDate=date('Y-m-d',$createdTs);if($lastOrderDate>$reportCustomerStats[$customerKey]['last_order'])$reportCustomerStats[$customerKey]['last_order']=$lastOrderDate;
 $trendKey=($reportRange==='all'||($reportFromTs!==null&&$reportToTs!==null&&$reportToTs-$reportFromTs>60*86400))?date('Y-m',$createdTs):date('Y-m-d',$createdTs);
 $reportTrend[$trendKey]??=['orders'=>0,'order_value'=>0,'paid_recorded'=>0];$reportTrend[$trendKey]['orders']++;$reportTrend[$trendKey]['order_value']+=$orderValue;$reportTrend[$trendKey]['paid_recorded']+=$paidRecorded;
}
$reportAverageOrder=$reportOrdersFiltered?intdiv($reportOrderValue,count($reportOrdersFiltered)):0;
uasort($reportCustomerStats,fn($a,$b)=>$b['paid_recorded']<=>$a['paid_recorded']?:$b['order_value']<=>$a['order_value']?:strcmp($a['name'],$b['name']));
$reportProducts=[];$reportCustomerProductStats=[];$reportProductOrderLines=[];
$reportProductRows=$db->query("SELECT i.order_id,i.name,i.price,i.cost,i.quantity FROM items i JOIN orders o ON o.id=i.order_id WHERE o.status<>'Cancelled' ORDER BY i.name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
foreach($reportProductRows as $productRow){
 $productOrderId=(int)$productRow['order_id'];if(!isset($reportOrderIds[$productOrderId]))continue;
 $productName=trim((string)$productRow['name']);$productKey=strtolower($productName);$qty=(int)$productRow['quantity'];$lineValue=(int)$productRow['price']*$qty;
 if(!isset($reportProducts[$productKey]))$reportProducts[$productKey]=['name'=>$productName,'units'=>0,'line_value'=>0,'orders'=>[]];
 $reportProducts[$productKey]['units']+=$qty;$reportProducts[$productKey]['line_value']+=$lineValue;$reportProducts[$productKey]['orders'][$productOrderId]=true;
 if($reportType==='productorders'){
  $reportProductOrderLines[$productKey][$productOrderId]??=['units'=>0,'line_value'=>0];
  $reportProductOrderLines[$productKey][$productOrderId]['units']+=$qty;$reportProductOrderLines[$productKey][$productOrderId]['line_value']+=$lineValue;
 }
 $customerProductKey=$reportOrderCustomerKeys[$productOrderId]??'';if($customerProductKey!=='')$reportCustomerProductStats[$customerProductKey][$productKey]=($reportCustomerProductStats[$customerProductKey][$productKey]??0)+$qty;
}
foreach($reportProducts as &$productStats){$productStats['orders']=count($productStats['orders']);$productStats['average_unit_value']=$productStats['units']?intdiv((int)$productStats['line_value'],(int)$productStats['units']):0;}unset($productStats);
foreach($reportCustomerStats as $customerKey=>&$customerStats){$purchases=$reportCustomerProductStats[$customerKey]??[];arsort($purchases,SORT_NUMERIC);$topKey=array_key_first($purchases);$customerStats['top_product']=$topKey!==null?(string)($reportProducts[$topKey]['name']??$topKey):'';$customerStats['top_product_units']=$topKey!==null?(int)$purchases[$topKey]:0;}unset($customerStats);
uasort($reportProducts,fn($a,$b)=>$b['units']<=>$a['units']?:$b['line_value']<=>$a['line_value']?:strcmp($a['name'],$b['name']));
$reportProductName=trim((string)($_GET['product']??''));$reportProductKey=strtolower($reportProductName);$reportProductSource=($_GET['source']??'products')==='overview'?'overview':'products';
$reportProductOrders=[];$reportSelectedProduct=$reportProducts[$reportProductKey]??['name'=>$reportProductName,'units'=>0,'line_value'=>0,'orders'=>0];
if($reportType==='productorders'){
 foreach($reportOrdersFiltered as $o){$line=$reportProductOrderLines[$reportProductKey][(int)$o['id']]??null;if($line)$reportProductOrders[]=$o+['_product_units'=>$line['units'],'_product_value'=>$line['line_value']];}
 usort($reportProductOrders,static fn($a,$b)=>$b['_report_ts']<=>$a['_report_ts']?:(int)$b['id']<=>(int)$a['id']);
}
$reportCycles=[];$reportCycleFrom=$reportFrom!==''?$reportFrom:$reportToday->format('Y-m-d');$reportCycleTo=$reportTo!==''?$reportTo:$reportToday->modify('+12 weeks')->format('Y-m-d');
foreach($retaCyclesActive as $cycle){
 $dueDate=(string)($cycle['next_due_date']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dueDate))continue;
 if($dueDate<$reportCycleFrom||$dueDate>$reportCycleTo)continue;
 $reportCycles[]=$cycle;
}
$reportTrackedStock=array_values(array_filter($products,fn($p)=>(int)($p['active']??0)===1&&(int)($p['stock_tracking']??0)===1&&$p['stock_qty']!==null));
$reportTrackedUnits=array_sum(array_map(fn($p)=>(int)$p['stock_qty'],$reportTrackedStock));
$reportStockCostValue=0;$reportStockRetailValue=0;$reportStockPotentialProfit=0;$reportStockCostMissing=0;$reportStockProfitUnits=0;
foreach($reportTrackedStock as $stockProduct){
 $stockQty=max(0,(int)$stockProduct['stock_qty']);$retailUnit=(int)$stockProduct['price'];$reportStockRetailValue+=$stockQty*$retailUnit;
 if($stockProduct['cost']===null){$reportStockCostMissing++;continue;}
 $costUnit=(int)$stockProduct['cost'];$reportStockCostValue+=$stockQty*$costUnit;$reportStockPotentialProfit+=$stockQty*($retailUnit-$costUnit);$reportStockProfitUnits+=$stockQty;
}
$reportLowStock=array_values(array_filter($reportTrackedStock,fn($p)=>(int)$p['stock_qty']<=(int)$p['low_stock_at']));
if($reportType==='reorder'){
$supplierLeadTimeDays=7;
// Estimate demand during the supplier's usual 7-day delivery lead time.
// Divide by the full selected lookback window; never scale up short tracking histories.
// Stock quantities already exclude reservations, so this is the true available balance.
$reorderToday=$reportToday;
$reorderStart=$reorderToday->modify('-'.($reorderWindowDays-1).' days')->format('Y-m-d');
$reorderRecurringByProduct=[];$reorderProductIdByName=[];
foreach($reportTrackedStock as $stockProduct)$reorderProductIdByName[strtolower(trim((string)$stockProduct['name']))]=(int)$stockProduct['id'];
foreach(($recurringForecast30['stock']??[]) as $forecastProductName=>$forecastUnits){$forecastProductId=$reorderProductIdByName[strtolower(trim((string)$forecastProductName))]??0;if($forecastProductId>0)$reorderRecurringByProduct[$forecastProductId]=($reorderRecurringByProduct[$forecastProductId]??0)+(int)$forecastUnits;}
$reorderSalesByProduct=[];
$reorderSalesSql="SELECT p.id AS product_id,SUM(CASE WHEN COALESCE(i.recurring,0)=0 THEN i.quantity ELSE 0 END) AS units FROM stock_delivery_events e JOIN orders o ON o.id=e.order_id JOIN items i ON i.order_id=o.id JOIN products p ON p.id=COALESCE(i.product_id,(SELECT p2.id FROM products p2 WHERE lower(trim(p2.name))=lower(trim(i.name)) ORDER BY p2.id LIMIT 1)) WHERE o.status<>'Cancelled' AND date(e.delivery_date)>=date(?) AND date(e.delivery_date)<=date(?) AND p.active=1 AND p.stock_tracking=1 AND (trim(COALESCE(p.stock_tracking_since,''))='' OR date(e.delivery_date)>=date(p.stock_tracking_since)) GROUP BY p.id";
$reorderSalesStmt=$db->prepare($reorderSalesSql);$reorderSalesStmt->execute([$reorderStart,$reorderToday->format('Y-m-d')]);
foreach($reorderSalesStmt->fetchAll(PDO::FETCH_ASSOC) as $saleRow)$reorderSalesByProduct[(int)$saleRow['product_id']]=(int)$saleRow['units'];
$reorderRows=[];$reorderNeedNow=0;$reorderPackTotal=0;$reorderUnitTotal=0;
foreach($reportTrackedStock as $stockProduct){
 $productId=(int)$stockProduct['id'];$available=max(0,(int)$stockProduct['stock_qty']);$threshold=max(0,(int)$stockProduct['low_stock_at']);$packSize=max(1,(int)($stockProduct['supplier_pack_size']??10));$recentUnits=max(0,(int)($reorderSalesByProduct[$productId]??0));
 $leadTimeDemand=(int)ceil($recentUnits*$supplierLeadTimeDays/$reorderWindowDays);$repeatDemand30=max(0,(int)($reorderRecurringByProduct[$productId]??0));$planningDemand=$leadTimeDemand+$repeatDemand30;$target=$threshold+$planningDemand;$gap=max(0,$target-$available);$packs=$gap>0?(int)ceil($gap/$packSize):0;$suggestedUnits=$packs*$packSize;
 $priority=$suggestedUnits<=0?2:($available<=$threshold?0:1);if($priority===0)$reorderNeedNow++;$reorderPackTotal+=$packs;$reorderUnitTotal+=$suggestedUnits;
 $reservedByLocation=$reservedStockByProduct[$productId]??[];$reservedUnits=array_sum(array_map('intval',$reservedByLocation));
 $reorderRows[]=['id'=>$productId,'name'=>(string)$stockProduct['name'],'jay'=>max(0,(int)($stockProduct['stock_jay_qty']??0)),'tony'=>max(0,(int)($stockProduct['stock_tony_qty']??0)),'unallocated'=>max(0,(int)($stockProduct['stock_unallocated_qty']??0)),'reserved'=>$reservedUnits,'available'=>$available,'threshold'=>$threshold,'recent_units'=>$recentUnits,'lead_time_demand'=>$leadTimeDemand,'repeat_30d'=>$repeatDemand30,'target'=>$target,'pack_size'=>$packSize,'packs'=>$packs,'suggested_units'=>$suggestedUnits,'priority'=>$priority];
}
usort($reorderRows,fn($a,$b)=>$a['priority']<=>$b['priority']?:$a['available']<=>$b['available']?:strcmp($a['name'],$b['name']));
}
$movementSql='SELECT sm.*,p.name AS product_name FROM stock_movements sm JOIN products p ON p.id=sm.product_id';$movementParams=[];$movementWhere=[];
if($reportFrom!==''){$movementWhere[]='date(sm.created)>=date(?)';$movementParams[]=$reportFrom;}
if($reportTo!==''){$movementWhere[]='date(sm.created)<=date(?)';$movementParams[]=$reportTo;}
if($movementWhere)$movementSql.=' WHERE '.implode(' AND ',$movementWhere);
$movementSql.=' ORDER BY datetime(sm.created) DESC,sm.id DESC';
$reportMovementsStmt=$db->prepare($movementSql);$reportMovementsStmt->execute($movementParams);$reportStockMovements=$reportMovementsStmt->fetchAll(PDO::FETCH_ASSOC);
$reportPaymentsByMethod=[];$paymentDateSql='SELECT COALESCE(NULLIF(method,\'\'),\'Not recorded\') AS method,SUM(amount) AS total,COUNT(*) AS entries FROM payments';$paymentWhere=[];$paymentParams=[];
if($reportFrom!==''){$paymentWhere[]='date(created)>=date(?)';$paymentParams[]=$reportFrom;}
if($reportTo!==''){$paymentWhere[]='date(created)<=date(?)';$paymentParams[]=$reportTo;}
if($paymentWhere)$paymentDateSql.=' WHERE '.implode(' AND ',$paymentWhere);
$paymentDateSql.=' GROUP BY COALESCE(NULLIF(method,\'\'),\'Not recorded\') ORDER BY total DESC';
$paymentMethodStmt=$db->prepare($paymentDateSql);$paymentMethodStmt->execute($paymentParams);$reportPaymentsByMethod=$paymentMethodStmt->fetchAll(PDO::FETCH_ASSOC);$reportCashReceived=array_sum(array_map(fn($p)=>(int)$p['total'],$reportPaymentsByMethod));
$personalUsage=($view==='reports'&&$reportType==='personal')?personalStockUsage($db,$reportFrom,$reportTo):[];
$reportExport=(string)($_GET['export']??'');
if(in_array($view,['reports','reorder'],true)&&$reportExport!==''&&!$reportRangeError){
 $exportType=$reportType;$exportRows=[];$exportHeaders=[];
 if($reportType==='personal'){
  $exportHeaders=['Delivered','Order','Customer','Assigned to','Product','Presentation','Quantity','Stock cost (£)','Cost missing'];
  foreach($personalUsage as $r)$exportRows[]=[$r['delivery_date'],'ANK-'.$r['id'],$r['customer'],$r['assigned_to'],$r['name'],$r['presentation'],(int)$r['quantity'],$r['cost']===null?'':number_format(((int)$r['cost']+(int)$r['presentation_cost'])*(int)$r['quantity']/100,2,'.',''),$r['cost']===null?'Yes':'No'];
 }
 if($reportExport==='stock-movements'){$exportType='stock-movements';$exportHeaders=['Date','Product','Location','Movement','Change','Supplier','Batch / lot','Expiry','Unit cost (£)','Note','Order ID'];foreach($reportStockMovements as $m)$exportRows[]=[(string)$m['created'],(string)$m['product_name'],(string)($m['location']??'Unallocated'),(string)$m['movement_type'],(int)$m['quantity_change'],(string)$m['supplier'],(string)$m['batch_reference'],(string)$m['expiry_date'],$m['unit_cost']===null?'':number_format((int)$m['unit_cost']/100,2,'.',''),(string)$m['note'],$m['order_id']===null?'':(int)$m['order_id']];}
 elseif($reportType==='outgoings'){
  $exportHeaders=['Date','Type','Purchase ID','Item / purchase','Category','Supplier','Reference','Quantity','Items total (£)','Delivery (£)','Outflow / refund (£)','Delivery status','Product link','Tracking reference','Tracking link','Notes'];
  foreach($reportSupplies['events'] as $r)$exportRows[]=[$r['event_date'],$r['event'],'SUP-'.$r['id'],$r['item'],$r['category'],$r['supplier'],$r['reference'],(int)$r['quantity'],number_format((int)$r['item_cost']/100,2,'.',''),number_format((int)$r['shipping']/100,2,'.',''),number_format((int)$r['amount']/100,2,'.',''),$r['status'],$r['product_url'],$r['tracking_reference'],$r['tracking_url'],$r['notes']];
 }
 elseif($reportType==='profit'){$exportHeaders=['Order','Customer','Status','Payment date','Sales (£)','Product cost (£)','Pen cost (£)','Postage (£)','Gross profit (£)','Cost missing'];foreach($selectedReportOrders as $r)$exportRows[]=['ANK-'.str_pad((string)$r['id'],4,'0',STR_PAD_LEFT),(string)$r['customer'],(string)$r['status'],(string)$r['date'],number_format((int)$r['revenue']/100,2,'.',''),number_format((int)$r['product_cost']/100,2,'.',''),number_format((int)$r['pen_cost']/100,2,'.',''),number_format((int)$r['postage']/100,2,'.',''),number_format((int)$r['profit']/100,2,'.',''),!empty($r['missing_cost'])?'Yes':'No'];}
 elseif(in_array($reportType,['sales','overview'],true)){$exportHeaders=['Order','Order date','Customer','Phone','Status','Assigned to','Order value (£)','Paid recorded (£)','Balance due (£)'];foreach($reportOrdersFiltered as $o)$exportRows[]=['ANK-'.str_pad((string)$o['id'],4,'0',STR_PAD_LEFT),date('Y-m-d',(int)$o['_report_ts']),(string)$o['customer'],(string)$o['phone'],(string)$o['status'],(string)($o['assigned_to']??''),number_format((int)$o['_order_value']/100,2,'.',''),number_format((int)$o['_paid_recorded']/100,2,'.',''),number_format((int)$o['_balance_due']/100,2,'.','')];}
 elseif($reportType==='products'){$exportHeaders=['Product','Units','Orders','Average unit value (£)','Line value (£)'];foreach($reportProducts as $r)$exportRows[]=[(string)$r['name'],(int)$r['units'],(int)$r['orders'],number_format((int)$r['average_unit_value']/100,2,'.',''),number_format((int)$r['line_value']/100,2,'.','')];}
 elseif($reportType==='productorders'){$exportHeaders=['Product','Order','Order date','Ordered by','For','Status','Back order','Units of product','Product line value (£)'];foreach($reportProductOrders as $o)$exportRows[]=[$reportSelectedProduct['name'],'ANK-'.str_pad((string)$o['id'],4,'0',STR_PAD_LEFT),date('Y-m-d',(int)$o['_report_ts']),(string)$o['customer'],orderDisplayName($o),(string)$o['status'],!empty($o['back_order'])?'Yes':'No',(int)$o['_product_units'],number_format((int)$o['_product_value']/100,2,'.','')];}
 elseif($reportType==='customers'){$exportHeaders=['Customer','Phone','Orders','Order value (£)','Paid recorded (£)','Balance due (£)','Most purchased product','Units of top product','Last order'];foreach($reportCustomerStats as $r)$exportRows[]= [(string)$r['name'],(string)$r['phone'],(int)$r['orders'],number_format((int)$r['order_value']/100,2,'.',''),number_format((int)$r['paid_recorded']/100,2,'.',''),number_format((int)$r['balance_due']/100,2,'.',''),(string)$r['top_product'],(int)$r['top_product_units'],(string)$r['last_order']];}
 elseif($reportType==='recurring'){$exportHeaders=['Next due date','Ordered by','For','Phone','Products','Expected value (£)','Expected profit (£)','Cycle days'];foreach($reportCycles as $r)$exportRows[]=[(string)$r['next_due_date'],(string)$r['customer_name'],cycleRecipientName($r),(string)$r['phone'],(string)$r['product_summary'],number_format((int)$r['expected_value']/100,2,'.',''),number_format((int)$r['expected_profit']/100,2,'.',''),(int)$r['cycle_days']];}
 elseif($reportType==='stock'){$exportHeaders=['Product','On hand','Low-stock threshold','Unit retail (£)','Retail stock value (£)','Unit cost (£)','Stock cost value (£)','Potential gross profit (£)','Status'];foreach($reportTrackedStock as $r){$qty=(int)$r['stock_qty'];$retail=(int)$r['price'];$cost=$r['cost']===null?null:(int)$r['cost'];$isLow=$qty<=(int)$r['low_stock_at'];$exportRows[]=[(string)$r['name'],$qty,(int)$r['low_stock_at'],number_format($retail/100,2,'.',''),number_format($qty*$retail/100,2,'.',''),$cost===null?'':number_format($cost/100,2,'.',''),$cost===null?'':number_format($qty*$cost/100,2,'.',''),$cost===null?'':number_format($qty*($retail-$cost)/100,2,'.',''),$isLow?'Low stock':'In stock'];}}
 elseif($reportType==='reorder'){$exportHeaders=['Product','Jay available','Tony available','Unallocated available','Reserved on current orders','Combined available','Alert at','Non-recurring units delivered in selected period','Estimated non-recurring demand during lead time','Potential recurring units due next 30 days','Target stock incl. projected demand','Supplier pack size','Packs suggested','Units suggested','Status'];foreach($reorderRows as $r){$status=$r['suggested_units']<=0?'Enough for target':($r['available']<=$r['threshold']?'Reorder now':'Plan ahead');$exportRows[]=[(string)$r['name'],$r['jay'],$r['tony'],$r['unallocated'],$r['reserved'],$r['available'],$r['threshold'],$r['recent_units'],$r['lead_time_demand'],$r['repeat_30d'],$r['target'],$r['pack_size'],$r['packs'],$r['suggested_units'],$status];}}
 if($exportRows||$exportHeaders){
  if($reportDownloadBuffer)ob_end_clean();
  $fileType=preg_replace('/[^a-z-]/','',$exportType);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="ankh-'.$fileType.'-report-'.date('Y-m-d').'.csv"');header('Cache-Control: no-store, no-cache, must-revalidate');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$exportHeaders);
  foreach($exportRows as $csvRow){$safeRow=array_map(static function($value){if(is_string($value)&&preg_match('/^[=+@-]/',ltrim($value))&&!preg_match('/^-?\d+(?:\.\d+)?$/',$value))return "'".$value;return $value;},$csvRow);fputcsv($out,$safeRow);}
  fclose($out);exit;
 }
}
}
$sheetWebhook=setting($db,'sheets_webhook');$sheetId=setting($db,'sheets_sheet_id');$sheetSecret=setting($db,'sheets_secret');$sheetLastSync=setting($db,'sheets_last_sync');$sheetLastError=setting($db,'sheets_last_error');

if($view==='stockboard'){
 $stockboardRows=[];
 foreach($stockProducts as $boardProduct){
  if((int)($boardProduct['active']??0)!==1 || (int)($boardProduct['stock_tracking']??0)!==1)continue;
  $jay=max(0,(int)($boardProduct['stock_jay_qty']??0));
  $tony=max(0,(int)($boardProduct['stock_tony_qty']??0));
  $unallocated=max(0,(int)($boardProduct['stock_unallocated_qty']??0));
  $total=$jay+$tony+$unallocated;
  if($total<=0)continue;
  $stockboardRows[]=[
   'id'=>(int)$boardProduct['id'],
   'name'=>(string)$boardProduct['name'],
   'jay'=>$jay,'tony'=>$tony,'unallocated'=>$unallocated,'total'=>$total,
   'low_at'=>(int)($boardProduct['low_stock_at']??5)
  ];
 }
 usort($stockboardRows,fn($a,$b)=>strcasecmp((string)$a['name'],(string)$b['name']));
 $stockboardUnits=array_sum(array_column($stockboardRows,'total'));
 ?>
 <style id="ankh-stockboard-style">
 *{box-sizing:border-box}
 html,body{margin:0;min-height:100%;background:#090a0a;color:#f7f2df;font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
 body{background:
   radial-gradient(circle at 15% -10%,rgba(217,181,83,.12),transparent 28rem),
   radial-gradient(circle at 85% 0,rgba(217,181,83,.06),transparent 24rem),
   #090a0a}
 .stockboard{width:min(1680px,100%);margin:0 auto;padding:28px clamp(18px,3vw,52px) 44px}
 .stockboard-head{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;padding-bottom:22px;border-bottom:1px solid rgba(220,185,88,.22)}
 .stockboard-brand{display:flex;align-items:center;gap:15px}.stockboard-mark{width:54px;height:54px;border:1px solid #d9b553;border-radius:17px;display:grid;place-items:center;color:#e6c664;font:34px Georgia,serif;box-shadow:inset 0 0 26px rgba(218,181,82,.08)}
 .stockboard-brand small,.stockboard-eyebrow{display:block;color:#d9b553;font-size:11px;font-weight:900;letter-spacing:.18em;text-transform:uppercase}
 .stockboard-brand h1{margin:3px 0 0;font-size:clamp(24px,3vw,46px);letter-spacing:-.035em}
 .stockboard-meta{text-align:right}.stockboard-meta strong{display:block;font-size:15px}.stockboard-meta span{display:block;margin-top:5px;color:#92958d;font-size:12px}
 .stockboard-summary{display:flex;gap:10px;flex-wrap:wrap;margin:20px 0}.stockboard-pill{padding:9px 13px;border-radius:999px;border:1px solid #353730;background:#151713;color:#c8c9c2;font-size:12px}.stockboard-pill b{color:#f5df96}
 .stockboard-search{display:flex;gap:10px;margin:0 0 18px}.stockboard-search input{width:min(460px,100%);min-height:46px;border:1px solid #3a3d36;border-radius:14px;background:#111310;color:#f5f2e7;padding:0 15px;font-size:16px;outline:none}.stockboard-search input:focus{border-color:#b8953e;box-shadow:0 0 0 3px rgba(217,181,83,.1)}
 .stockboard-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}
 .stockboard-card{position:relative;min-height:155px;padding:20px;border:1px solid #30322d;border-radius:20px;background:linear-gradient(155deg,#181a17 0%,#111310 100%);box-shadow:0 12px 34px rgba(0,0,0,.18)}
 .stockboard-card.low{border-color:#705b29;background:linear-gradient(155deg,#211d13,#12130f)}
 .stockboard-card h2{margin:0;padding-right:78px;font-size:clamp(18px,1.5vw,25px);line-height:1.12;letter-spacing:-.025em}
 .stockboard-count{margin-top:20px;display:flex;align-items:flex-end;gap:8px}.stockboard-count strong{font-size:clamp(34px,4vw,58px);line-height:.9;color:#f4dda0}.stockboard-count span{padding-bottom:4px;color:#a2a59c;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em}
 .stockboard-state{position:absolute;right:15px;top:15px;border:1px solid #405044;border-radius:999px;padding:6px 8px;color:#a9d7ad;background:#132017;font-size:9px;font-weight:900;letter-spacing:.1em;text-transform:uppercase}.stockboard-card.low .stockboard-state{border-color:#725a25;background:#251d0e;color:#f0c75b}
 .stockboard-locations{display:flex;gap:7px;flex-wrap:wrap;margin-top:17px}.stockboard-locations span{padding:6px 8px;border-radius:9px;background:#0d0f0d;border:1px solid #292b27;color:#959a91;font-size:10px}.stockboard-locations b{color:#e9e9df}
 .stockboard-empty{padding:54px 20px;text-align:center;border:1px dashed #363833;border-radius:22px;color:#92958d}
 .stockboard-foot{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-top:22px;padding-top:16px;border-top:1px solid #252722;color:#7e8279;font-size:10px}.stockboard-live{display:inline-flex;align-items:center;gap:7px}.stockboard-live i{width:7px;height:7px;border-radius:50%;background:#78d283;box-shadow:0 0 0 4px rgba(120,210,131,.09)}
 [hidden]{display:none!important}
 @media(max-width:640px){
  .stockboard{padding:20px 14px 34px}.stockboard-head{align-items:flex-start}.stockboard-mark{width:46px;height:46px;border-radius:14px;font-size:29px}.stockboard-meta strong{font-size:12px}.stockboard-meta span{font-size:9px}.stockboard-grid{grid-template-columns:1fr 1fr;gap:9px}.stockboard-card{min-height:135px;padding:15px;border-radius:17px}.stockboard-card h2{padding-right:0;font-size:15px}.stockboard-state{position:static;display:inline-block;margin-top:10px}.stockboard-count{margin-top:13px}.stockboard-count strong{font-size:34px}.stockboard-locations{gap:5px;margin-top:12px}.stockboard-locations span{font-size:9px;padding:5px 6px}
 }
 @media(min-width:1200px){
  .stockboard-grid{grid-template-columns:repeat(5,minmax(0,1fr));gap:16px}.stockboard-card{min-height:180px;padding:23px}.stockboard-card h2{font-size:25px}.stockboard-locations span{font-size:11px}
 }
 @media(min-width:1700px){.stockboard-grid{grid-template-columns:repeat(6,minmax(0,1fr))}}
 </style>
 <main class="stockboard">
  <header class="stockboard-head">
   <div class="stockboard-brand"><div class="stockboard-mark">☥</div><div><small>ANKH PEPTIDES</small><h1>Live stock</h1></div></div>
   <div class="stockboard-meta"><strong><?=e((new DateTimeImmutable('now',new DateTimeZone('Europe/London')))->format('D j M · H:i'))?></strong><span>Refreshes automatically every 30 seconds</span></div>
  </header>
  <div class="stockboard-summary"><span class="stockboard-pill"><b><?=count($stockboardRows)?></b> products available</span><span class="stockboard-pill"><b><?=$stockboardUnits?></b> total units available</span></div>
  <div class="stockboard-search"><input id="stockboard-search" type="search" autocomplete="off" placeholder="Search in-stock products…" aria-label="Search in-stock products"></div>
  <?php if($stockboardRows):?><section class="stockboard-grid" id="stockboard-grid">
   <?php foreach($stockboardRows as $row):$isLow=$row['total']<=$row['low_at'];?>
   <article class="stockboard-card<?=$isLow?' low':''?>" data-stock-search="<?=e(strtolower($row['name']))?>">
    <h2><?=e($row['name'])?></h2><span class="stockboard-state"><?=$isLow?'Low stock':'In stock'?></span>
    <div class="stockboard-count"><strong><?=$row['total']?></strong><span>available</span></div>
    <div class="stockboard-locations"><span>Jay <b><?=$row['jay']?></b></span><span>Tony <b><?=$row['tony']?></b></span><?php if($row['unallocated']>0):?><span>Holding <b><?=$row['unallocated']?></b></span><?php endif;?></div>
   </article>
   <?php endforeach;?>
  </section><?php else:?><div class="stockboard-empty">No tracked products currently have available stock.</div><?php endif;?>
  <footer class="stockboard-foot"><span class="stockboard-live"><i></i>Live from ANKH stock control</span><span>Available stock only · reserved order stock is already excluded</span></footer>
 </main>
 <script>
 (()=>{document.title='ANKH · Live stock';const input=document.getElementById('stockboard-search'),cards=[...document.querySelectorAll('[data-stock-search]')];input?.addEventListener('input',()=>{const q=input.value.trim().toLowerCase();cards.forEach(card=>card.hidden=!!q&&!card.dataset.stockSearch.includes(q))});setTimeout(()=>{if(!document.hidden&&!input?.matches(':focus'))location.reload()},30000)})();
 </script>
 </body></html>
 <?php exit;
}

if($view==='reportsboard'){
 $boardTz=new DateTimeZone('Europe/London');$boardNow=new DateTimeImmutable('now',$boardTz);
 $boardMonthStart=$boardNow->modify('first day of this month')->setTime(0,0,0);
 $boardNextMonth=$boardMonthStart->modify('+1 month');
 $boardMonthLabel=$boardMonthStart->format('F Y');$boardStart=$boardMonthStart->format('Y-m-d');$boardEnd=$boardNextMonth->format('Y-m-d');
 $boardPaidStatuses=['Paid','Packed','Dispatched','Delivered'];
 $boardOrders=$db->query("SELECT o.*,COALESCE((SELECT SUM(i.price*i.quantity) FROM items i WHERE i.order_id=o.id),0)+COALESCE(o.delivery_charge,0) AS board_total FROM orders o WHERE o.status IN ('Paid','Packed','Dispatched','Delivered') ORDER BY datetime(o.created) DESC,o.id DESC")->fetchAll(PDO::FETCH_ASSOC);
 $boardMonthOrders=[];$boardSales=0;$boardUnits=0;$boardProductMap=[];$boardCustomerMap=[];
 foreach($boardOrders as $boardOrder){
  $saleDate=trim((string)($boardOrder['payment_date']??''));if($saleDate==='')$saleDate=substr((string)$boardOrder['created'],0,10);
  if($saleDate<$boardStart||$saleDate>=$boardEnd)continue;
  $orderId=(int)$boardOrder['id'];$total=(int)$boardOrder['board_total'];$boardMonthOrders[]=$boardOrder;$boardSales+=$total;
  $itemsQ=$db->prepare('SELECT name,quantity,price FROM items WHERE order_id=? ORDER BY id');$itemsQ->execute([$orderId]);
  foreach($itemsQ->fetchAll(PDO::FETCH_ASSOC) as $line){
   $name=trim((string)$line['name'])?:'Unnamed product';$qty=max(0,(int)$line['quantity']);$revenue=max(0,(int)$line['price'])*$qty;$boardUnits+=$qty;
   $key=strtolower($name);if(!isset($boardProductMap[$key]))$boardProductMap[$key]=['name'=>$name,'units'=>0,'revenue'=>0,'orders'=>[]];
   $boardProductMap[$key]['units']+=$qty;$boardProductMap[$key]['revenue']+=$revenue;$boardProductMap[$key]['orders'][$orderId]=true;
  }
  $customer=trim((string)$boardOrder['customer'])?:'Unnamed customer';$phone=trim((string)($boardOrder['phone']??''));$customerKey=strtolower($customer).'|'.$phone;
  if(!isset($boardCustomerMap[$customerKey]))$boardCustomerMap[$customerKey]=['name'=>$customer,'revenue'=>0,'orders'=>0,'last'=>''];
  $boardCustomerMap[$customerKey]['revenue']+=$total;$boardCustomerMap[$customerKey]['orders']++;if($saleDate>$boardCustomerMap[$customerKey]['last'])$boardCustomerMap[$customerKey]['last']=$saleDate;
 }
 $boardProducts=array_values($boardProductMap);foreach($boardProducts as &$row)$row['orders']=count($row['orders']);unset($row);
 usort($boardProducts,fn($a,$b)=>$b['revenue']<=>$a['revenue'] ?: $b['units']<=>$a['units'] ?: strcasecmp($a['name'],$b['name']));
 $boardCustomers=array_values($boardCustomerMap);usort($boardCustomers,fn($a,$b)=>$b['revenue']<=>$a['revenue'] ?: $b['orders']<=>$a['orders'] ?: strcasecmp($a['name'],$b['name']));
 $boardOrderCount=count($boardMonthOrders);$boardAverage=$boardOrderCount?(int)round($boardSales/$boardOrderCount):0;
 $maxProductRevenue=max(1,...array_map(fn($r)=>(int)$r['revenue'],$boardProducts?:[['revenue'=>1]]));
 $maxCustomerRevenue=max(1,...array_map(fn($r)=>(int)$r['revenue'],$boardCustomers?:[['revenue'=>1]]));
 ?>
 <style id="ankh-reportsboard-style">
 *{box-sizing:border-box}
 html,body{margin:0;min-height:100%;background:#070809;color:#f7f4e9;font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
 body{background:radial-gradient(circle at 18% -5%,rgba(230,190,78,.14),transparent 34rem),radial-gradient(circle at 90% 8%,rgba(79,201,234,.07),transparent 30rem),#070809}
 .reportboard{width:min(1800px,100%);margin:0 auto;padding:28px clamp(18px,3vw,52px) 40px}
 .reportboard-head{display:flex;align-items:center;justify-content:space-between;gap:24px;padding-bottom:20px;border-bottom:1px solid rgba(224,188,79,.24)}
 .reportboard-brand{display:flex;align-items:center;gap:16px}.reportboard-mark{width:56px;height:56px;border:1px solid #dfbb55;border-radius:17px;display:grid;place-items:center;color:#f1cd68;font:35px Georgia,serif;box-shadow:inset 0 0 28px rgba(229,188,72,.09)}
 .reportboard-brand small{display:block;color:#dabb5c;font-size:11px;font-weight:900;letter-spacing:.18em;text-transform:uppercase}.reportboard-brand h1{margin:3px 0 0;font-size:clamp(27px,3vw,48px);letter-spacing:-.04em}
 .reportboard-meta{text-align:right}.reportboard-meta strong{display:block;font-size:17px}.reportboard-meta span{display:block;margin-top:4px;color:#898d88;font-size:11px}
 .reportboard-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:20px 0}
 .reportboard-kpi{padding:17px 19px;border:1px solid #33362f;border-radius:18px;background:linear-gradient(150deg,#181a17,#10120f);min-height:104px}.reportboard-kpi span{display:block;color:#9ca097;font-size:10px;font-weight:800;letter-spacing:.11em;text-transform:uppercase}.reportboard-kpi strong{display:block;margin-top:9px;color:#f4dda0;font-size:clamp(25px,2.8vw,44px);line-height:1}.reportboard-kpi small{display:block;margin-top:8px;color:#767b75;font-size:10px}
 .reportboard-columns{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}.reportboard-panel{border:1px solid #30332d;border-radius:22px;background:linear-gradient(160deg,#141714,#0e100e);overflow:hidden}
 .reportboard-panel-head{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;padding:19px 20px;border-bottom:1px solid #292c27}.reportboard-panel-head span{display:block;color:#d9b553;font-size:9px;font-weight:900;letter-spacing:.16em;text-transform:uppercase}.reportboard-panel-head h2{margin:4px 0 0;font-size:24px;letter-spacing:-.03em}.reportboard-panel-head small{color:#777c76;font-size:10px;text-align:right}
 .reportboard-list{display:grid}.reportboard-row{position:relative;display:grid;grid-template-columns:34px minmax(0,1fr) auto;gap:12px;align-items:center;padding:13px 18px;border-bottom:1px solid #232620;min-height:68px}.reportboard-row:last-child{border-bottom:0}.reportboard-rank{width:28px;height:28px;border-radius:9px;background:#20231f;color:#d7bb6c;display:grid;place-items:center;font-size:11px;font-weight:900}
 .reportboard-main{min-width:0}.reportboard-main strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:14px}.reportboard-main small{display:block;margin-top:5px;color:#858a83;font-size:10px}.reportboard-value{text-align:right}.reportboard-value strong{display:block;color:#f6e2a9;font-size:15px}.reportboard-value small{display:block;margin-top:4px;color:#777c75;font-size:9px}
 .reportboard-bar{position:absolute;left:64px;right:18px;bottom:7px;height:2px;background:#22251f;border-radius:999px;overflow:hidden}.reportboard-bar i{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#80651f,#e5c15d)}
 .reportboard-panel.customer .reportboard-bar i{background:linear-gradient(90deg,#174f5d,#59cde8)}.reportboard-panel.customer .reportboard-rank{color:#8be6f5;background:#132126}
 .reportboard-empty{padding:48px 20px;text-align:center;color:#858980}
 .reportboard-foot{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-top:18px;padding-top:15px;border-top:1px solid #232620;color:#73776f;font-size:10px}.reportboard-live{display:inline-flex;align-items:center;gap:7px}.reportboard-live i{width:7px;height:7px;border-radius:50%;background:#75d67f;box-shadow:0 0 0 4px rgba(117,214,127,.09)}
 @media(max-width:760px){.reportboard{padding:18px 12px 30px}.reportboard-head{align-items:flex-start}.reportboard-mark{width:46px;height:46px;border-radius:14px;font-size:29px}.reportboard-brand h1{font-size:27px}.reportboard-meta strong{font-size:12px}.reportboard-meta span{font-size:8px}.reportboard-kpis{grid-template-columns:1fr 1fr;gap:8px;margin:14px 0}.reportboard-kpi{min-height:91px;padding:14px}.reportboard-kpi strong{font-size:26px}.reportboard-columns{grid-template-columns:1fr;gap:12px}.reportboard-panel-head{padding:16px}.reportboard-row{padding:12px 14px;grid-template-columns:30px minmax(0,1fr) auto}.reportboard-bar{left:56px;right:14px}.reportboard-main strong{font-size:13px}.reportboard-value strong{font-size:14px}.reportboard-foot{align-items:flex-start;flex-direction:column}}
 @media(min-width:1200px){.reportboard{padding-top:32px}.reportboard-panel-head h2{font-size:28px}.reportboard-row{min-height:70px}.reportboard-main strong{font-size:15px}.reportboard-value strong{font-size:16px}}
 </style>
 <main class="reportboard">
  <header class="reportboard-head">
   <div class="reportboard-brand"><div class="reportboard-mark">☥</div><div><small>ANKH PEPTIDES</small><h1>Monthly sales</h1></div></div>
   <div class="reportboard-meta"><strong><?=e($boardMonthLabel)?></strong><span>Live report · refreshes every 30 seconds</span></div>
  </header>
  <section class="reportboard-kpis">
   <article class="reportboard-kpi"><span>Monthly sales</span><strong><?=money($boardSales)?></strong><small>Completed paid sales</small></article>
   <article class="reportboard-kpi"><span>Orders</span><strong><?=$boardOrderCount?></strong><small>Paid / packed / dispatched / delivered</small></article>
   <article class="reportboard-kpi"><span>Units sold</span><strong><?=$boardUnits?></strong><small>Across all product lines</small></article>
   <article class="reportboard-kpi"><span>Average order</span><strong><?=money($boardAverage)?></strong><small>Monthly sales ÷ orders</small></article>
  </section>
  <section class="reportboard-columns">
   <article class="reportboard-panel product"><div class="reportboard-panel-head"><div><span>Sales by product</span><h2>Products this month</h2></div><small><?=count($boardProducts)?> product<?=count($boardProducts)===1?'':'s'?></small></div>
    <?php if($boardProducts):?><div class="reportboard-list"><?php foreach($boardProducts as $index=>$row):$pct=max(3,min(100,round(((int)$row['revenue']/$maxProductRevenue)*100)));?><div class="reportboard-row"><span class="reportboard-rank"><?=$index+1?></span><div class="reportboard-main"><strong><?=e($row['name'])?></strong><small><?=$row['units']?> unit<?=$row['units']===1?'':'s'?> · <?=$row['orders']?> order<?=$row['orders']===1?'':'s'?></small></div><div class="reportboard-value"><strong><?=money($row['revenue'])?></strong><small>product sales</small></div><span class="reportboard-bar"><i style="width:<?=$pct?>%"></i></span></div><?php endforeach;?></div><?php else:?><div class="reportboard-empty">No completed product sales yet this month.</div><?php endif;?>
   </article>
   <article class="reportboard-panel customer"><div class="reportboard-panel-head"><div><span>Sales by customer</span><h2>Customers this month</h2></div><small><?=count($boardCustomers)?> customer<?=count($boardCustomers)===1?'':'s'?></small></div>
    <?php if($boardCustomers):?><div class="reportboard-list"><?php foreach($boardCustomers as $index=>$row):$pct=max(3,min(100,round(((int)$row['revenue']/$maxCustomerRevenue)*100)));?><div class="reportboard-row"><span class="reportboard-rank"><?=$index+1?></span><div class="reportboard-main"><strong><?=e($row['name'])?></strong><small><?=$row['orders']?> order<?=$row['orders']===1?'':'s'?> this month</small></div><div class="reportboard-value"><strong><?=money($row['revenue'])?></strong><small>customer sales</small></div><span class="reportboard-bar"><i style="width:<?=$pct?>%"></i></span></div><?php endforeach;?></div><?php else:?><div class="reportboard-empty">No completed customer sales yet this month.</div><?php endif;?>
   </article>
  </section>
  <footer class="reportboard-foot"><span class="reportboard-live"><i></i>Live from ANKH Order Desk</span><span>Monthly sales use completed paid orders · product totals exclude delivery charges</span></footer>
 </main>
 <script>(()=>{document.title='ANKH · Monthly sales';setTimeout(()=>{if(!document.hidden)location.reload()},30000)})();</script>
 </body></html>
 <?php exit;
}
?>
<a class="ux-skip-link" href="#ankh-main">Skip to main content</a>
<div class="ios-status-bar" aria-hidden="true"></div>
<aside><div class="app-brand-row"><a class="brand" href="?view=dashboard"><span>☥</span> ANKH<small>ORDER DESK</small></a><button type="button" id="ankh-assistant-button" class="ankh-assistant-button" aria-label="Talk to ANKH Assistant">
 <span class="assistant-button-ring" aria-hidden="true"></span><span class="assistant-button-icon" aria-hidden="true">🎙</span><span class="assistant-button-label">Talk to ANKH</span>
</button></div>
<nav class="desktop-nav">
<a class="<?=$view==='dashboard'?'selected':''?>" href="?view=dashboard"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 13h6V4H4zM14 20h6v-9h-6zM4 20h6v-3H4zM14 7h6V4h-6z"/></svg></span><span class="nav-label">Dashboard</span></a>
<a class="<?=in_array($view,['orders','packing'],true)?'selected':''?>" href="?view=orders"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5.5h16v13H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg></span><span class="nav-label">Orders</span></a>
<a class="<?=$view==='new'?'selected':''?>" href="?view=new"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span><span class="nav-label">New</span></a>
<a class="<?=in_array($view,['customers','customer'],true)?'selected':''?>" href="?view=customers"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.5 18c.8-3 2.7-4.5 5.5-4.5S13.7 15 14.5 18"/><circle cx="17" cy="9" r="2"/><path d="M15.5 14c2.7.2 4.3 1.5 5 4"/></svg></span><span class="nav-label">Customers</span></a>
<a class="<?=$view==='balances'?'selected':''?>" href="?view=balances"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 7h14v11H5z"/><path d="M8 11h8M8 15h5"/><circle cx="17" cy="15" r="1"/></svg></span><span class="nav-label">Balances</span></a>
<a class="<?=in_array($view,['products','product'],true)?'selected':''?>" href="?view=products"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 4h12v16H6z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg></span><span class="nav-label">Products</span></a>
<a class="<?=$view==='reports'?'selected':''?>" href="?view=reports"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 19V9M12 19V5M19 19v-7"/><path d="M3 19h18"/></svg></span><span class="nav-label">Reports</span></a>
<a class="<?=$view==='sheets'?'selected':''?>" href="?view=sheets"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4h14v16H5z"/><path d="M5 9h14M10 9v11M15 9v11M5 14h14"/></svg></span><span class="nav-label">Sheets</span></a>
<a class="<?=in_array($view,['enquiries','supplies','more','supplier','reorder','shipping','stock','fridge_jay','fridge_tony','reta'],true)?'selected':''?>" href="?view=more"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg></span><span class="nav-label">More</span></a>
</nav>
<nav class="mobile-nav">
<a class="<?=$view==='dashboard'?'selected':''?>" href="?view=dashboard"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 13h6V4H4zM14 20h6v-9h-6zM4 20h6v-3H4zM14 7h6V4h-6z"/></svg></span><span class="nav-label">Dashboard</span></a>
<a class="<?=in_array($view,['orders','packing'],true)?'selected':''?>" href="?view=orders"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5.5h16v13H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg></span><span class="nav-label">Orders</span></a>
<a class="nav-new <?=$view==='new'?'selected':''?>" href="?view=new"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span><span class="nav-label">New</span></a>
<a class="<?=in_array($view,['customers','customer','balances'],true)?'selected':''?>" href="?view=customers"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.5 18c.8-3 2.7-4.5 5.5-4.5S13.7 15 14.5 18"/><circle cx="17" cy="9" r="2"/><path d="M15.5 14c2.7.2 4.3 1.5 5 4"/></svg></span><span class="nav-label">Customers</span></a>
<a class="<?=in_array($view,['enquiries','supplies','more','products','product','reports','reorder','shipping','sheets','supplier','stock','fridge_jay','fridge_tony','reta'],true)?'selected':''?>" href="?view=more"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg></span><span class="nav-label">More</span></a>
</nav><?php if(!$testingNoAuth):?><form method="post"><?php csrf();?><input type="hidden" name="action" value="logout"><button class="quiet">Sign out</button></form><?php endif;?></aside>
<main id="ankh-main" tabindex="-1" class="focus-app" data-view="<?=e($view)?>"><header><p class="eyebrow">ANKH PEPTIDES / ADMIN</p><div class="ankh-header-tools"><span class="muted"><?=date('d M Y')?></span><button type="button" class="ankh-notification-button" id="ankh-notification-button" data-ankh-notification-toggle aria-label="Notifications" aria-expanded="false" aria-controls="ankh-notifications"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><?php if($notificationCount):?><b><?=$notificationCount>99?'99+':$notificationCount?></b><?php endif;?></button></div></header>
<?php if($premiumV2Preview):?><div class="premium-v2-preview-banner"><span>Premium V2 · design preview</span><a class="premium-v2-preview-chip" href="?view=dashboard&amp;premium=0">Return to original design <span aria-hidden="true">↗</span></a></div><?php endif;?>
<section class="ankh-notification-popover" id="ankh-notifications" hidden aria-label="Notifications"><div class="ankh-notification-head"><div><h2>Notifications</h2><small><?=$notificationCount?> active</small></div><button type="button" class="quiet" id="ankh-notification-close">Done</button></div>
<?php if($visibleNotifications):foreach($visibleNotifications as $notice):?><article class="ankh-notification-item tone-<?=e($notice['tone'])?>"><a href="<?=e($notice['href'])?>"><strong><?=e($notice['title'])?></strong><p><?=e($notice['body'])?></p></a><div class="ankh-notification-actions"><form method="post"><?php csrf();?><input type="hidden" name="action" value="notification_state"><input type="hidden" name="notification_id" value="<?=e($notice['id'])?>"><input type="hidden" name="state_action" value="snooze"><input type="hidden" name="days" value="1"><input type="hidden" name="return" value="<?=e($view)?>"><?php if($view==='customer'&&$customerAccount):?><input type="hidden" name="customer_id" value="<?=(int)$customerAccount['id']?>"><?php endif;?><button class="quiet">Snooze 1 day</button></form><form method="post"><?php csrf();?><input type="hidden" name="action" value="notification_state"><input type="hidden" name="notification_id" value="<?=e($notice['id'])?>"><input type="hidden" name="state_action" value="snooze"><input type="hidden" name="days" value="7"><input type="hidden" name="return" value="<?=e($view)?>"><?php if($view==='customer'&&$customerAccount):?><input type="hidden" name="customer_id" value="<?=(int)$customerAccount['id']?>"><?php endif;?><button class="quiet">7 days</button></form><form method="post"><?php csrf();?><input type="hidden" name="action" value="notification_state"><input type="hidden" name="notification_id" value="<?=e($notice['id'])?>"><input type="hidden" name="state_action" value="dismiss"><input type="hidden" name="return" value="<?=e($view)?>"><?php if($view==='customer'&&$customerAccount):?><input type="hidden" name="customer_id" value="<?=(int)$customerAccount['id']?>"><?php endif;?><button class="quiet">Dismiss</button></form></div></article><?php endforeach;else:?><div class="ankh-notification-empty">You’re all caught up.</div><?php endif;?></section>
<button type="button" class="ankh-notification-mobile-button" id="ankh-notification-mobile-button" data-ankh-notification-toggle aria-label="Notifications" aria-expanded="false" aria-controls="ankh-notifications"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><?php if($notificationCount):?><b><?=$notificationCount>99?'99+':$notificationCount?></b><?php endif;?></button>
<script>(()=>{
 const buttons=[...document.querySelectorAll('[data-ankh-notification-toggle]')],p=document.getElementById('ankh-notifications'),c=document.getElementById('ankh-notification-close'),mobile=document.getElementById('ankh-notification-mobile-button'),brand=document.querySelector('.app-brand-row');
 if(!buttons.length||!p)return;
 if(mobile&&brand)brand.insertBefore(mobile,document.getElementById('ankh-assistant-button'));
 p.setAttribute('role','dialog');p.setAttribute('tabindex','-1');
 let trigger=null;
 const set=(open,restore=true)=>{p.hidden=!open;buttons.forEach(b=>b.setAttribute('aria-expanded',String(open)));if(open){p.scrollTop=0;c?.focus();}else if(restore&&trigger?.isConnected)trigger.focus();};
 buttons.forEach(b=>b.addEventListener('click',e=>{e.stopPropagation();trigger=b;set(p.hidden)}));
 c?.addEventListener('click',()=>set(false));
 document.addEventListener('click',e=>{if(!p.hidden&&!p.contains(e.target)&&!e.target.closest('[data-ankh-notification-toggle]'))set(false,false)});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!p.hidden){e.preventDefault();set(false)}});
 document.addEventListener('focusin',e=>{if(!p.hidden&&!p.contains(e.target)&&!buttons.includes(e.target))set(false,false)});
})();</script>
<?php if($error):?><p role="alert" class="error"><?=e($error)?></p><?php endif;?>
<?php if(!empty($_SESSION['flash'])):?><div class="flash-confirmation"><p class="success" role="status"><?=e($_SESSION['flash'])?></p><?php if(is_array($_SESSION['stock_undo']??null)&&time()<=(int)($_SESSION['stock_undo']['expires']??0)):?><form method="post"><?php csrf();?><input type="hidden" name="action" value="stock_undo"><button type="submit" class="quiet stock-undo-button">Undo stock change</button></form><?php endif;?><?php unset($_SESSION['flash']);?></div><?php endif;?>
<?php if($view==='dashboard'):
if(empty($_SESSION['dashboard_focus_ready'])){unset($_SESSION['dashboard_queue']);$_SESSION['dashboard_focus_ready']=true;}
$dashboardAll=dashboardWorklist($awaitingPayment,$awaitingDelivery,$futureDeliveries);
$dashboardDeliveries=dashboardWorklist([],$awaitingDelivery,$futureDeliveries);
$dashboardQueueCounts=['all'=>count($dashboardAll),'payment'=>count($awaitingPayment),'delivery'=>count($dashboardDeliveries),'future'=>count($futureDeliveries)];
$dashboardQueue=dashboardQueueView($dashboardQueueCounts,is_string($_GET['queue']??null)?$_GET['queue']:'',(string)($_SESSION['dashboard_queue']??''));
$_SESSION['dashboard_queue']=$dashboardQueue;
$requestedLayout=is_string($_GET['layout']??null)?$_GET['layout']:'';
$dashboardLayout=in_array($requestedLayout,['cards','dispatch'],true)?$requestedLayout:(string)($_SESSION['dashboard_layout']??'cards');
if(!in_array($dashboardLayout,['cards','dispatch'],true))$dashboardLayout='cards';
$_SESSION['dashboard_layout']=$dashboardLayout;
$dashboardWork=dashboardWorklistSort(match($dashboardQueue){'payment'=>$awaitingPayment,'delivery'=>$dashboardDeliveries,'future'=>$futureDeliveries,default=>$dashboardAll},$now->format('Y-m-d'));
?>
<div class="heading dashboard-heading"><div><p class="focus-date"><?=e($now->format('l, j F'))?></p><h1>Today, in focus.</h1><p class="muted page-description">Your orders. Your next steps.</p></div><a class="button page-action" href="?view=new">+ New order</a></div>
<nav class="ux-mobile-actions" aria-label="Quick actions">
 <?php if($premiumV2Preview):?><a href="?view=packing"><span aria-hidden="true">▣</span><strong>Packing station</strong></a><?php endif;?>
 <a class="ux-action-primary" href="?view=new"><span aria-hidden="true">＋</span><strong>New order</strong></a>
 <a href="?view=orders&amp;scope=to_deliver"><span aria-hidden="true">↗</span><strong>Deliveries</strong></a>
 <a href="?view=stock"><span aria-hidden="true">▣</span><strong>Stock</strong></a>
 <a href="?view=supplier&amp;tab=order"><span aria-hidden="true">◎</span><strong>Buy stock</strong></a>
 <a href="?view=reports"><span aria-hidden="true">↗</span><strong>Reports</strong></a>
</nav>
<div class="dashboard-stats">
<a class="dashboard-stat-card" href="?view=orders&amp;scope=today_sales"><span>Today's sales</span><strong><?=money($todaySales)?></strong><small>Completed sales</small></a>
<a class="dashboard-stat-card" href="?view=orders&amp;scope=month_sales"><span>This month's sales</span><strong><?=money($monthSales)?></strong><small><?=e($now->format('F Y'))?></small></a>
<a class="dashboard-stat-card" href="?view=reports&amp;type=profit&amp;range=all"><span>All-time gross profit</span><strong><?=money($grossProfit)?></strong><small>After product &amp; delivery costs</small></a>
<a class="dashboard-stat-card" href="?view=orders&amp;scope=unpaid"><span>Unpaid</span><strong><?=money($unpaidBalance)?></strong><small><?=count($awaitingPayment)?> orders</small></a>
<a class="dashboard-stat-card" href="?view=orders&amp;scope=to_deliver"><span>To deliver</span><strong><?=count($awaitingDelivery)+count($futureDeliveries)?></strong><small>Includes future deliveries</small></a>
<a class="dashboard-stat-card" href="?view=stock&amp;filter=low"><span>Low stock</span><strong><?=count($lowStock)?></strong><small><?=count($trackedStock)?> tracked</small></a>
</div>
<section class="todo-board dashboard-action-queue" id="dashboard-actions">
<?php $opsNeedsPrep=count(array_filter($dashboardWork,fn($o)=>orderPreparationState($o)==='pending'));$opsReady=count(array_filter($dashboardWork,fn($o)=>orderPreparationState($o)==='packaged'));$opsOverdue=count(array_filter($dashboardWork,fn($o)=>orderPreparationState($o)!=='closed' && trim((string)($o['scheduled_delivery_date']??''))!=='' && (string)$o['scheduled_delivery_date']<$now->format('Y-m-d')));?>
<div class="todo-board-head"><div><h2>Your worklist</h2><p class="muted focus-work-count"><?=count($dashboardWork)?> order<?=count($dashboardWork)===1?'':'s'?> · <?=$opsNeedsPrep?> to prepare · <?=$opsReady?> ready<?=$opsOverdue?' · '.$opsOverdue.' overdue':''?></p></div><?php if($stockShortages):?><a class="queue-stock-warning" href="#dashboard-stock-shortage"><?=$stockShortageUnits?> stock short ↓</a><?php endif;?></div>
<div class="focus-work-toolbar"><div class="dashboard-queue-tabs" role="navigation" aria-label="Orders needing action">
<?php foreach(['all'=>'All','payment'=>'Unpaid','delivery'=>'To deliver','future'=>'Future'] as $queueKey=>$queueLabel):?><a href="?view=dashboard&amp;queue=<?=e($queueKey)?>#dashboard-actions" <?=$dashboardQueue===$queueKey?'aria-current="page"':''?>><span><?=e($queueLabel)?></span><b><?=$dashboardQueueCounts[$queueKey]?></b></a><?php endforeach;?>
</div><div class="focus-layout-switch" role="navigation" aria-label="Worklist layout"><?php foreach(['cards'=>'Cards','dispatch'=>'List'] as $layoutKey=>$layoutLabel):?><a href="?view=dashboard&amp;queue=<?=e($dashboardQueue)?>&amp;layout=<?=e($layoutKey)?>#dashboard-actions" <?=$dashboardLayout===$layoutKey?'aria-current="true"':''?>><?=e($layoutLabel)?></a><?php endforeach;?></div></div>
<div class="focus-worklist layout-<?=e($dashboardLayout)?>">
<?php $lastGroup='';foreach($dashboardWork as $todo):$items=$todoItems[(int)$todo['id']]??[];$todoPaid=(int)($todo['paid_amount']??0);$todoBalance=max(0,(int)$todo['total']-$todoPaid);$workGroup=dashboardWorkGroup($todo,$now->format('Y-m-d'));$delivered=orderPreparationState($todo)==='closed';?>
<?php if($workGroup!==$lastGroup):?><h3 class="focus-group-heading"><?=e($workGroup)?></h3><?php $lastGroup=$workGroup;endif;?>
<details class="todo-card todo-accordion focus-order" id="work-order-<?=(int)$todo['id']?>" data-focus-sheet>
<summary class="todo-accordion-summary">
<div class="focus-card-top"><span class="focus-order-ref">ANK-<?=str_pad((string)$todo['id'],4,'0',STR_PAD_LEFT)?> · <?=e($workGroup)?></span><strong><?=money($todo['total'])?></strong></div>
<p class="operations-step-hint"><?=e(dashboardNextActionLabel($todo))?></p>
<div class="todo-accordion-main"><h3><?=e(orderDisplayName($todo))?></h3><?php orderBuyerCaption($todo);?><div class="todo-accordion-products"><?php foreach($items as $item):?><span data-ankh-product-name="<?=e($item['name'])?>"><?=e($item['quantity'].' × '.$item['name'].(!empty($item['presentation'])?' · '.$item['presentation']:'').((int)($item['discount']??0)>0?' · F&F':''))?></span><?php endforeach;?></div><?php orderFocusStatuses($todo);?></div>
<div class="focus-card-footer"><?php deliveryScheduleTags($todo,$now);?><span class="focus-order-open">View order <span aria-hidden="true">↗</span></span></div>
</summary>
<div class="todo-accordion-body focus-sheet-content">
<?php backOrderControls($db,$todo);?>
<div class="order-quick-actions"><a class="quick-action edit-action" href="?view=edit&amp;id=<?=(int)$todo['id']?>">Edit order</a><?php if(trim((string)($todo['phone']??''))!==''):?><a class="quick-action" href="tel:<?=e(preg_replace('/[^0-9+]/','',(string)$todo['phone']))?>">Call</a><?php endif;?></div>
<div class="todo-detail-strip"><span>Order total <?=money($todo['total'])?></span><span>Paid <?=money($todoPaid)?></span><span>Due <?=money($todoBalance)?></span></div>
<?php dashboardOneTapActions($todo,$now);?>
<?php if($todoBalance>0):?>
<form method="post" class="todo-action dashboard-payment-form"><?php csrf();?><input type="hidden" name="action" value="payment_add"><input type="hidden" name="id" value="<?=$todo['id']?>"><input type="hidden" name="return" value="dashboard"><input type="hidden" name="return_queue" value="<?=e($_SESSION['dashboard_queue']??'all')?>">
<div class="dashboard-payment-heading"><strong>Record payment</strong><small><?=money($todoBalance)?> outstanding</small></div>
<div class="dashboard-payment-choice" role="group" aria-label="Payment amount type"><label><input type="radio" name="payment_type" value="full" checked><span><b>Paid in full</b><small>Record <?=money($todoBalance)?></small></span></label><label><input type="radio" name="payment_type" value="partial"><span><b>Part payment</b><small>Enter amount received</small></span></label></div>
<label class="dashboard-payment-field dashboard-partial-amount"><span>Amount received</span><div class="money-input"><i>£</i><input name="amount" type="number" min=".01" max="<?=e(number_format($todoBalance/100,2,'.',''))?>" step=".01" inputmode="decimal" value="<?=e(number_format($todoBalance/100,2,'.',''))?>"></div><small>Remaining balance updates automatically.</small></label>
<div class="dashboard-payment-meta"><label class="dashboard-payment-field"><span>Payment method</span><select name="method" required><option value="">Choose method</option><?php foreach($paymentMethods as $method):?><option value="<?=e($method)?>" <?=$todo['payment_method']===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label><label class="dashboard-payment-field"><span>Payment date</span><input type="date" name="payment_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($now->format('Y-m-d'))?>" required></label></div><button class="dashboard-payment-save">✓ Save payment</button></form>
<?php endif;?>
<?php if(!$delivered):?>
<details class="focus-action-group"><summary>Preparation</summary><?php dashboardPreparationControls($todo);?></details>
<details class="focus-action-group"><summary>Delivery &amp; scheduling</summary>
<?php dashboardDeliveryScheduleForm($todo);dashboardDeliveryMoveControls($todo,deliveryBoardSection($todo,$now->format('Y-m-d'))==='future'?'awaiting':'future');?>
<?php if(trim((string)($todo['address']??''))!==''):?><p class="todo-address"><?=nl2br(e($todo['address']))?></p><?php endif;?><?php if(!empty($todo['tracking_reference'])):?><p class="todo-tracking">Tracking: <?=e($todo['tracking_reference'])?></p><?php endif;?>
<form method="post" class="todo-action"><?php csrf();?><input type="hidden" name="action" value="<?=$todoBalance>0?'delivery_mark':'status'?>"><input type="hidden" name="status" value="Delivered"><input type="hidden" name="id" value="<?=(int)$todo['id']?>"><input type="hidden" name="return" value="dashboard"><input type="hidden" name="return_queue" value="<?=e($_SESSION['dashboard_queue']??'all')?>"><label class="todo-date">Actual delivery date<input type="date" name="delivery_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($now->format('Y-m-d'))?>" required></label><button>Mark delivered</button></form>
</details>
<?php endif;?>
<details class="focus-action-group"><summary>Documents &amp; sharing</summary><div class="order-quick-actions"><a class="quick-action" href="?api=order-pdf&amp;id=<?=(int)$todo['id']?>&amp;type=invoice">Invoice PDF</a><a class="quick-action" href="?api=order-pdf&amp;id=<?=(int)$todo['id']?>&amp;type=receipt">Receipt PDF</a><button type="button" class="quick-action quiet" data-share-pdf="<?=(int)$todo['id']?>" data-document-type="receipt">Share receipt</button></div></details>
</div></details>
<?php endforeach;?>
<?php if(!$dashboardWork):?><p class="queue-empty" role="status">No orders need action in this view.</p><?php endif;?>
</div></section>
<!-- dashboard-worklist-end -->
<?php if($stockShortages):?>
<details class="dashboard-stock-shortage dashboard-secondary" id="dashboard-stock-shortage">
 <summary class="stock-shortage-header">
  <span class="stock-shortage-alert-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 2.5 20h19L12 3.5Z"/><path d="M12 9v4.8M12 17h.01"/></svg></span>
  <div class="stock-shortage-heading-copy"><p class="eyebrow">STOCK ALERT</p><h2 id="stock-shortage-title">Orders need stock</h2><p>Reserved orders exceed the stock available in their assigned fridge.</p></div>
  <span class="stock-shortage-total"><b><?=$stockShortageUnits?></b><small>unit<?=$stockShortageUnits===1?'':'s'?> short</small></span>
 </summary>
 <div class="stock-shortage-list">
 <?php foreach($stockShortages as $shortage):$owner=$shortage['location_owner'];$otherOwner=$shortage['other_owner'];$otherAvailable=$shortage['other_available'];$transferable=$shortage['transferable'];$remaining=$shortage['remaining_after_transfer'];?>
 <article class="stock-shortage-row">
  <div class="stock-shortage-row-top">
   <span class="stock-shortage-product-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6m-5 0v4l-3 3v9a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-9l-3-3V3M8 12h8"/></svg></span>
   <div class="stock-shortage-product-info"><h3><?=e($shortage['name'])?></h3><p><?=e($shortage['location']==='Unallocated'?'Holding / unallocated':$owner.' · '.$shortage['location'].'’s fridge')?></p></div>
   <span class="stock-shortage-pill"><?=$shortage['missing']?> short</span>
  </div>
  <p class="stock-shortage-message"><?php if($shortage['location']==='Unallocated'):?>There isn’t enough holding stock allocated to cover these reservations.<?php else:?><strong><?=e($owner)?></strong> doesn’t have enough stock in <?=e($shortage['location'])?>’s fridge to fulfil these orders.<?php endif;?></p>
  <div class="stock-shortage-metrics" aria-label="Current stock balance"><div><span>In fridge</span><strong><?=$shortage['physical']?></strong></div><div><span>Reserved</span><strong><?=$shortage['reserved']?></strong></div><div class="is-short"><span>Still needed</span><strong><?=$shortage['missing']?></strong></div></div>
  <?php if($otherOwner!==null):?>
  <div class="stock-shortage-opportunity <?=$transferable>0?'has-stock':'no-stock'?>">
   <span class="stock-shortage-opportunity-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16m-4-4 4 4-4 4M20 16H4m4-4-4 4 4 4"/></svg></span>
   <div><strong><?=e($otherOwner)?> has <?=$otherAvailable?> available unit<?=$otherAvailable===1?'':'s'?> of this product.</strong>
    <?php if($transferable===$shortage['missing']):?><p>Transferring <?=$transferable?> unit<?=$transferable===1?'':'s'?> from <?=e($otherOwner)?> would cover the shortage.</p>
    <?php elseif($transferable>0):?><p>A transfer could cover <?=$transferable?> of the <?=$shortage['missing']?> missing; <?=$remaining?> would still be needed.</p>
    <?php else:?><p>There is no spare stock in <?=e($otherOwner)?>’s fridge to transfer. Replenishment is needed.</p><?php endif;?>
   </div>
  </div>
  <?php else:?>
  <div class="stock-shortage-opportunity no-stock"><span class="stock-shortage-opportunity-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16m-4-4 4 4-4 4M20 16H4m4-4-4 4 4 4"/></svg></span><div><strong>Holding stock is short.</strong><p>Check the order allocation or replenish the missing quantity.</p></div></div>
  <?php endif;?>
  <?php if($shortage['orders']):?>
  <div class="stock-shortage-orders"><div class="stock-shortage-orders-title"><span class="stock-shortage-orders-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h8M8 3h8v3H8zM6 5H5a2 2 0 0 0-2 2v13h18V7a2 2 0 0 0-2-2h-1M7 11h10M7 15h10"/></svg></span><strong>Orders reserving this stock</strong></div><div class="stock-shortage-order-links"><?php foreach(array_slice($shortage['orders'],0,4) as $affected):?><a href="?view=edit&amp;id=<?=(int)$affected['order_id']?>"><span>ANK-<?=str_pad((string)$affected['order_id'],4,'0',STR_PAD_LEFT)?></span><?=e($affected['customer'])?></a><?php endforeach;?><?php if(count($shortage['orders'])>4):?><small>+<?=count($shortage['orders'])-4?> more orders</small><?php endif;?></div></div>
  <?php endif;?>
  <a class="stock-shortage-stock-link" href="?view=stock#stock-product-<?=(int)$shortage['product_id']?>">View stock &amp; transfer <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></a>
 </article>
 <?php endforeach;?>
 </div>
 <p class="stock-shortage-footnote">Nothing has been moved automatically. Reservations stay in place until stock is added, transferred or the orders are changed.</p>
</details>
<?php endif;?>

<?php if($topSelling):?><details class="dashboard-secondary dashboard-insight"><summary><strong>Best-selling products</strong><span>View ranking ⌄</span></summary><div class="dashboard-primary-panel"><section class="panel dashboard-panel"><div class="dashboard-panel-head"><div><p class="eyebrow">TOP SELLERS</p><h2>Best-selling products</h2></div></div>
<?php $rank=0;foreach($topSelling as $productName=>$seller):$rank++;?><div class="dashboard-row"><span><b><?=$rank?></b><?=e($productName)?></span><strong><?=$seller['qty']?> sold</strong></div><?php endforeach;?>
</section></div></details><?php endif;?>

<details class="dashboard-secondary dashboard-insight"><summary><strong>Recurring orders</strong><span><?=count($retaDue7)?> due in 7 days ⌄</span></summary><section class="panel dashboard-reta-panel cycle-preview-card">
<div class="cycle-preview-head"><div><p class="eyebrow">UPCOMING CYCLES</p><h2>Next 7 days</h2></div><a class="cycle-preview-link" href="?view=orders&amp;section=recurring">Recurring <span>→</span></a></div>
<?php if($retaDue7):?><div class="cycle-preview-list">
<?php foreach(array_slice($retaDue7,0,4) as $cycle):
 $dueObj=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycle['next_due_date'],$tz);$daysAway=$dueObj?(int)$retaToday->diff($dueObj)->format('%r%a'):0;$dueTone=$daysAway<0?'overdue':($daysAway<=2?'soon':'future');?>
<a class="cycle-preview-row <?=$dueTone?>" href="?view=customer&amp;id=<?=urlencode((string)($customerIdByKey[strtolower(trim((string)$cycle['customer_name'])).'|'.trim((string)$cycle['phone'])]??''))?>">
 <div class="cycle-preview-copy"><strong><?=e(cycleRecipientName($cycle))?></strong><span><?=e($cycle['product_name']?:$cycle['product_summary'])?><?php if((int)($cycle['quantity']??1)>1):?> × <?=(int)$cycle['quantity']?><?php endif;?> · Ordered by <?=e($cycle['customer_name'])?></span></div>
 <div class="cycle-preview-date"><strong><?=$daysAway<0?'Overdue':($daysAway===0?'Today':($daysAway===1?'Tomorrow':e(date('D d',strtotime($cycle['next_due_date'])))))?></strong><small><?=max(1,(int)($cycle['cycle_weeks']??4))?> week cycle</small></div>
</a>
<?php endforeach;?></div><?php if(count($retaDue7)>4):?><a class="cycle-preview-more" href="?view=orders&amp;section=recurring">+ <?=count($retaDue7)-4?> more due soon</a><?php endif;?>
<?php else:?><div class="cycle-preview-clear"><span>✓</span><div><strong>Nothing due in the next 7 days</strong><small><?=count($retaCyclesActive)?> active recurring cycle<?=count($retaCyclesActive)===1?'':'s'?></small></div></div><?php endif;?>
</section></details>



<?php if($lowStock):?><div class="dashboard-primary-panel"><section class="panel dashboard-panel"><div class="dashboard-panel-head"><div><p class="eyebrow">STOCK</p><h2>Low-stock products</h2></div><a href="?view=stock&amp;filter=low">View all →</a></div>
<?php foreach(array_slice($lowStock,0,6) as $stockProduct):?><a class="dashboard-row dashboard-stock-link" href="?view=stock&amp;filter=low#stock-product-<?=(int)$stockProduct['id']?>"><span><?=e($stockProduct['name'])?></span><strong><?=max(0,$stockProduct['fridge_available'])?> left <i aria-hidden="true">›</i></strong></a><?php endforeach;?>
</section></div><?php endif;?>
<?php if($uncostedSales>0):?><p class="muted dashboard-note">Gross profit excludes costs that have not been mapped yet.</p><?php endif;?>
<?php elseif($view==='packing'):require __DIR__.'/packing-v2.php';?>
<?php elseif($view==='orders'): ?>
<?php
$ordersSection=in_array($_GET['section']??'', ['recurring','backorders'],true)?(string)$_GET['section']:'all';
$backOrders=array_values(array_filter($orders,fn($o)=>isBackOrder($o)));
$backOrderDemand=backOrderStockPlan($db,array_column($incomingProductBreakdown,null,'product_id'));
usort($backOrders,fn($a,$b)=>strcmp((string)$a['back_order_since'],(string)$b['back_order_since']) ?: (int)$a['id']<=>(int)$b['id']);
$orderScopes=['today_sales'=>'Today’s sales','month_sales'=>'This month’s sales','unpaid'=>'Unpaid orders','to_deliver'=>'Orders to deliver'];
$activeOrderList=array_values(array_filter($orders,fn($o)=>!isBackOrder($o)));
$orderScope=$ordersSection==='all'&&in_array((string)($_GET['scope']??''),array_keys($orderScopes),true)?(string)$_GET['scope']:'';
$listedOrders=$ordersSection==='backorders'?$backOrders:$activeOrderList;
if($ordersSection==='all' && $orderScope!==''){
 $listedOrders=array_values(array_filter($activeOrderList,function($o)use($orderScope,$paidStatuses,$todayStart,$monthStart,$now){
  if($orderScope==='unpaid')return $o['status']!=='Cancelled' && max(0,(int)$o['total']-(int)($o['paid_amount']??0))>0;
  if($orderScope==='to_deliver')return in_array(deliveryBoardSection($o,$now->format('Y-m-d')),['awaiting','future'],true);
  $salesTs=strtotime((string)($o['payment_date']?:$o['created']))?:0;
  return in_array($o['status'],$paidStatuses,true) && $salesTs>=($orderScope==='today_sales'?$todayStart:$monthStart);
 }));
}
$listedOpen=count(array_filter($listedOrders,fn($o)=>!in_array($o['status'],['Dispatched','Delivered','Cancelled'],true)));
$listedPaid=array_sum(array_map(fn($o)=>in_array($o['status'],$paidStatuses,true)?(int)$o['total']:0,$listedOrders));
$listedValue=array_sum(array_map(fn($o)=>$orderScope==='unpaid'?max(0,(int)$o['total']-(int)($o['paid_amount']??0)):(int)$o['total'],$listedOrders));
?>
<div class="heading orders-page-heading"><div><h1><?=$ordersSection==='backorders'?'Back orders':'Orders'?></h1><p class="muted page-description"><?=$ordersSection==='backorders'?'Stock needed for your waiting orders.':'Search, update or repeat any order.'?></p></div><a class="button page-action" href="?view=new">+ New order</a></div>
<nav class="ux-mobile-scopes order-view-nav" aria-label="Order views">
 <?php if($premiumV2Preview):?><a class="packing-v2-orders-link" href="?view=packing">Visual packing station ↗</a><?php endif;?>
 <a href="?view=orders" <?=$ordersSection==='all'&&$orderScope===''?'aria-current="page"':''?>>All orders</a>
 <a href="?view=orders&amp;section=backorders" <?=$ordersSection==='backorders'?'aria-current="page"':''?>>Back orders <b><?=count($backOrders)?></b></a>
 <a href="?view=orders&amp;section=recurring" <?=$ordersSection==='recurring'?'aria-current="page"':''?>>Recurring</a>
 <a href="?view=orders&amp;scope=unpaid" <?=$orderScope==='unpaid'?'aria-current="page"':''?>>Unpaid</a>
 <a href="?view=orders&amp;scope=to_deliver" <?=$orderScope==='to_deliver'?'aria-current="page"':''?>>To deliver</a>
 <a href="?view=orders&amp;scope=today_sales" <?=$orderScope==='today_sales'?'aria-current="page"':''?>>Today</a>
</nav>
<?php if($ordersSection==='recurring'):?>
<?php
$recurringRange=forecastDateRange($retaToday,(string)($_GET['range']??'30'),(string)($_GET['from']??''),(string)($_GET['to']??''),[7,14,30]);
$recurringSelected=retaProjectionRange($retaForecastCycles,$recurringRange['start'],$recurringRange['end'],(bool)$recurringRange['include_overdue']);
$recurringOccurrences=$recurringSelected['occurrences'];$recurringSelectedUnits=0;foreach(($recurringSelected['stock']??[]) as $unitName=>$unitQty)if(strtolower(trim((string)$unitName))!=='pen')$recurringSelectedUnits+=(int)$unitQty;
$recurringSelectedCustomers=[];foreach($recurringOccurrences as $occurrence)$recurringSelectedCustomers[strtolower(trim((string)$occurrence['customer_name'])).'|'.trim((string)$occurrence['phone'])]=true;
$recurringSelectedRiskCount=0;foreach(($recurringSelected['stock']??[]) as $riskName=>$riskQty){if(strtolower(trim((string)$riskName))==='pen')continue;$riskProduct=$productStockMap[strtolower(trim((string)$riskName))]??null;$riskAvailable=$riskProduct&&(int)($riskProduct['stock_tracking']??0)===1?(int)($riskProduct['stock_qty']??0):null;if($riskAvailable!==null&&$riskAvailable<(int)$riskQty)$recurringSelectedRiskCount++;}
$recurringDisplayGroups=[];
foreach($recurringOccurrences as $occurrence){
 if(!empty($occurrence['was_overdue'])){$groupKey='overdue';$groupTitle='DUE / OVERDUE';$groupSub='Needs checking now';}
 elseif($recurringRange['mode']==='custom'){$groupKey='month-'.substr((string)$occurrence['occurrence_date'],0,7);$groupTitle=strtoupper(date('F Y',strtotime((string)$occurrence['occurrence_date'])));$groupSub='Expected during this month';}
 else{$offset=(int)$retaToday->diff(DateTimeImmutable::createFromFormat('!Y-m-d',(string)$occurrence['occurrence_date'],$tz))->format('%r%a');if($offset<=6){$groupKey='next7';$groupTitle='NEXT 7 DAYS';$groupSub='Likely to need attention soon';}elseif($offset<=13){$groupKey='days8-14';$groupTitle='8–14 DAYS';$groupSub='Coming up after the next week';}else{$groupKey='days15-30';$groupTitle='15–30 DAYS';$groupSub='Later in the selected forecast';}}
 if(!isset($recurringDisplayGroups[$groupKey]))$recurringDisplayGroups[$groupKey]=['title'=>$groupTitle,'sub'=>$groupSub,'rows'=>[]];$recurringDisplayGroups[$groupKey]['rows'][]=$occurrence;
}
$recurringCreatedCycles=[];foreach($retaCyclesActive as $createdCycle)if(isset($cyclePendingOrderById[(int)$createdCycle['id']])){$createdCycle['_pending']=$cyclePendingOrderById[(int)$createdCycle['id']];$recurringCreatedCycles[]=$createdCycle;}
?>
<section class="recurring-workspace">
 <div class="recurring-range-panel">
  <div class="recurring-range-head"><div><p class="eyebrow">FORECAST RANGE</p><h2><?=e($recurringRange['label'])?></h2></div><span><?=e($recurringRange['start']->format('d M'))?> → <?=e($recurringRange['end']->format('d M Y'))?></span></div>
  <div class="recurring-range-tabs">
   <?php foreach([7,14,30] as $rangeDays):?><a href="?view=orders&amp;section=recurring&amp;range=<?=$rangeDays?>" class="<?=$recurringRange['mode']===(string)$rangeDays?'selected':''?>"><?=$rangeDays?> days</a><?php endforeach;?>
   <button type="button" class="<?=$recurringRange['mode']==='custom'?'selected':''?>" data-recurring-custom-toggle>Custom</button>
  </div>
  <form method="get" class="recurring-custom-range <?=$recurringRange['mode']==='custom'?'open':''?>" data-recurring-custom-form>
   <input type="hidden" name="view" value="orders"><input type="hidden" name="section" value="recurring"><input type="hidden" name="range" value="custom">
   <label>From<input type="date" name="from" value="<?=e($recurringRange['mode']==='custom'?$recurringRange['start']->format('Y-m-d'):$retaToday->format('Y-m-d'))?>" required></label>
   <label>To<input type="date" name="to" value="<?=e($recurringRange['mode']==='custom'?$recurringRange['end']->format('Y-m-d'):$retaToday->modify('+29 days')->format('Y-m-d'))?>" required></label>
   <button>Apply dates</button>
  </form>
 </div>
 <div class="recurring-summary-grid">
  <article><span>Potential orders</span><strong><?=(int)$recurringSelected['orders']?></strong><small><?=money((int)$recurringSelected['revenue'])?> potential value</small></article>
  <article><span>Expected units</span><strong><?=$recurringSelectedUnits?></strong><small>Not reserved yet</small></article>
  <article class="<?=$recurringSelectedRiskCount?'attention':''?>"><span>Stock risks</span><strong><?=$recurringSelectedRiskCount?></strong><small><?=$recurringSelectedRiskCount?'Potential shortages':'Forecast covered'?></small></article>
  <article><span>Customers due</span><strong><?=count($recurringSelectedCustomers)?></strong><small><?=count($recurringOccurrences)?> expected occurrence<?=count($recurringOccurrences)===1?'':'s'?></small></article>
 </div>
 <div class="recurring-explainer"><span>↻</span><div><strong>Expected ≠ Reserved</strong><p>These are possible repeat orders for the selected dates. Stock is only reserved when you create the real order.</p></div></div>
 <?php foreach($recurringDisplayGroups as $groupKey=>$group):?>
 <section class="recurring-group">
  <header><div><p class="eyebrow"><?=e($group['title'])?></p><h2><?=e($group['sub'])?></h2></div><b><?=count($group['rows'])?></b></header>
  <div class="recurring-card-list">
  <?php foreach($group['rows'] as $cycle):$cycleDate=(string)$cycle['occurrence_date'];$cycleDueObj=DateTimeImmutable::createFromFormat('!Y-m-d',$cycleDate,$tz);$cycleDays=$cycleDueObj?(int)$retaToday->diff($cycleDueObj)->format('%r%a'):0;$cycleProduct=(string)($cycle['product_name']?:$cycle['product_summary']);$cycleQty=max(1,(int)($cycle['quantity']??1));$cycleProductStock=$productStockMap[strtolower(trim($cycleProduct))]??null;$cycleAvailable=$cycleProductStock&&(int)($cycleProductStock['stock_tracking']??0)===1?(int)($cycleProductStock['stock_qty']??0):null;$isActionable=!empty($cycle['is_next_occurrence']);?>
   <article class="recurring-order-card <?=!empty($cycle['was_overdue'])?'overdue':''?>">
    <div class="recurring-order-main"><div class="recurring-cycle-icon">↻</div><div><h3><?=e(cycleRecipientName($cycle))?></h3><p><?=$cycleQty?> × <?=e($cycleProduct)?><?=trim((string)($cycle['presentation']??''))!==''?' · '.e($cycle['presentation']):''?></p><small>Ordered by <?=e($cycle['customer_name'])?> · For <?=e(cycleRecipientName($cycle))?> · Every <?=max(1,(int)($cycle['cycle_weeks']??4))?> weeks · <?=money($cycle['expected_value'])?> potential</small></div></div>
    <div class="recurring-order-status"><span><?=!empty($cycle['was_overdue'])?'Overdue':'Expected'?></span><strong><?=!empty($cycle['was_overdue'])?'Due now':e(date('D d M',strtotime($cycleDate)))?></strong><small class="<?=$cycleAvailable!==null&&$cycleAvailable<$cycleQty?'stock-risk':''?>"><?=$cycleAvailable===null?'Stock not tracked':($cycleAvailable>=$cycleQty?'Stock available ✓':'Only '.$cycleAvailable.' available ⚠')?></small></div>
    <div class="recurring-order-actions">
     <?php if($isActionable):?><a class="button recurring-create-order" href="?view=new&amp;repeat_order=<?=$cycle['last_order_id']?>">Create order</a>
     <form method="post" onsubmit="return confirm('Skip this expected order and move to the next cycle?');"><?php csrf();?><input type="hidden" name="action" value="reta_cycle_skip"><input type="hidden" name="cycle_id" value="<?=$cycle['id']?>"><input type="hidden" name="return" value="recurring"><button class="quick-action quiet">Skip cycle</button></form>
     <details class="recurring-reschedule"><summary>Reschedule</summary><form method="post"><?php csrf();?><input type="hidden" name="action" value="cycle_amend"><input type="hidden" name="cycle_id" value="<?=$cycle['id']?>"><input type="hidden" name="return" value="recurring"><label>Next expected<input type="date" name="next_due_date" value="<?=e($cycle['next_due_date'])?>" required></label><label>Every <span class="cycle-weeks-field"><input type="number" name="cycle_weeks" min="1" max="52" value="<?=max(1,(int)($cycle['cycle_weeks']??4))?>" required><i>weeks</i></span></label><button>Save</button></form></details>
     <?php else:?><span class="recurring-forecast-only">Forecast occurrence</span><?php endif;?>
    </div>
   </article>
  <?php endforeach;?>
  </div>
 </section>
 <?php endforeach;?>
 <?php if($recurringCreatedCycles):?><section class="recurring-group recurring-created-group"><header><div><p class="eyebrow">ORDER CREATED</p><h2>Already moved from expected into real orders</h2></div><b><?=count($recurringCreatedCycles)?></b></header><div class="recurring-card-list">
 <?php foreach($recurringCreatedCycles as $cycle):$pending=$cycle['_pending'];$cycleProduct=(string)($cycle['product_name']?:$cycle['product_summary']);?><article class="recurring-order-card converted"><div class="recurring-order-main"><div class="recurring-cycle-icon">✓</div><div><h3><?=e(cycleRecipientName($cycle))?></h3><p><?=max(1,(int)($cycle['quantity']??1))?> × <?=e($cycleProduct)?></p><small>Ordered by <?=e($cycle['customer_name'])?> · For <?=e(cycleRecipientName($cycle))?> · Recurring order created</small></div></div><div class="recurring-order-status"><span class="recurring-created-pill">ORDER ANK-<?=str_pad((string)$pending['id'],4,'0',STR_PAD_LEFT)?></span><strong><?=e($pending['status'])?></strong></div><div class="recurring-order-actions"><a class="quick-action" href="?view=edit&amp;id=<?=$pending['id']?>">Open order</a></div></article><?php endforeach;?>
 </div></section><?php endif;?>
 <?php if(!$recurringOccurrences && !$recurringCreatedCycles):?><div class="panel stock-empty-state"><strong>No recurring orders in this range</strong><p>Try 14 or 30 days, or choose your own dates with Custom.</p></div><?php endif;?>
</section>
<script>(()=>{const button=document.querySelector('[data-recurring-custom-toggle]'),form=document.querySelector('[data-recurring-custom-form]');if(button&&form)button.addEventListener('click',()=>form.classList.toggle('open'))})();</script>
<?php else:?>
<?php if($ordersSection==='backorders'):?>
<?php $backOrderExpectedIncome=array_sum(array_map(fn($order)=>max(0,(int)$order['total']-(int)($order['paid_amount']??0)),$backOrders));renderBackOrderStockPlan($backOrderDemand,count($backOrders),$backOrderExpectedIncome);?>
<div class="backorder-section-heading waiting-orders-heading"><div><h2>Waiting orders</h2><p>Waiting for stock · oldest first</p></div></div>
<?php endif;?>
<?php if($orderScope!==''):?><div class="order-scope-banner"><div><strong><?=e($orderScopes[$orderScope])?></strong><small><?=count($listedOrders)?> orders · <?=money($listedValue)?><?=$orderScope==='unpaid'?' outstanding':''?></small></div><a class="quick-action quiet" href="?view=orders">All orders →</a></div><?php endif;?>
<?php if($ordersSection!=='backorders'):?><div class="stats compact-stats orders-summary-stats"><article><span>Open</span><strong><?=$listedOpen?></strong></article><article><span>Paid value</span><strong><?=money($listedPaid)?></strong></article><article><span>Total</span><strong><?=count($listedOrders)?></strong></article></div><?php endif;?>
<div class="filters ux-order-filters <?=$ordersSection==='backorders'?'backorder-search':''?>"><label><?=$ordersSection==='backorders'?'Search waiting orders':'Search orders'?><input id="search" type="search" placeholder="Name, phone or order number" value="<?=e(is_scalar($_GET['search']??null)?(string)$_GET['search']:'')?>"></label><label <?=$ordersSection==='backorders'?'hidden':''?>>Status<select id="filter"><option value="">All statuses</option><?php foreach($statuses as $statusOption):?><option><?=e($statusOption)?></option><?php endforeach;?></select></label></div>
<p class="ux-result-count" id="ux-order-visible" role="status" aria-live="polite"><?=count($listedOrders)?> orders</p>
<div class="order-list">
<?php foreach($listedOrders as $o):?><details class="order compact-order" data-search="<?=e(strtolower($o['customer'].' '.($o['recipient_name']??'').' '.$o['phone'].' '.($o['referrer']??'').' '.($o['assigned_to']??'').' ANK-'.$o['id']))?>" data-status="<?=e($o['status'])?>"><summary><div><div class="order-ref-row"><?php if(!empty($o['assigned_to'])):?><span class="delivery-assignee assigned <?=e(assigneeClass($o['assigned_to']))?>"><?=e($o['assigned_to'])?></span><?php else:?><span class="delivery-assignee assignee-unassigned">Unassigned</span><?php endif;?><span class="ref">ANK-<?=str_pad((string)$o['id'],4,'0',STR_PAD_LEFT)?></span></div><h2><?=e(orderDisplayName($o))?></h2><?php orderBuyerCaption($o);?><?php if(isBackOrder($o)):$lines=$db->prepare('SELECT name,quantity,presentation FROM items WHERE order_id=? ORDER BY id');$lines->execute([(int)$o['id']]);?><p class="backorder-order-products"><?php $parts=[];foreach($lines as $line)$parts[]=(int)$line['quantity'].' × '.$line['name'].($line['presentation']?' · '.$line['presentation']:'');echo e(implode(' / ',$parts));?></p><?php endif;?><span class="muted"><?=e(date('d M Y',strtotime($o['created'])))?></span></div><div class="order-right"><div class="order-summary-badges"><?php orderFocusStatuses($o);?><?php if(trim((string)($o['scheduled_delivery_date']??''))!=='' && $o['status']!=='Cancelled' && trim((string)($o['delivery_date']??''))===''):?><span class="scheduled-delivery-badge">Planned <?=e((new DateTimeImmutable($o['scheduled_delivery_date'],$tz))->format('d M'))?></span><?php endif;?></div><strong><?=money($o['total'])?></strong><?php $orderBalance=max(0,(int)$o['total']-(int)($o['paid_amount']??0));?><small><?=$orderBalance>0?'Balance '.money($orderBalance):((int)$o['total']===0?'No payment required':'Paid in full')?></small></div></summary><div class="detail">
<?php backOrderControls($db,$o);?>
<?php if(isBackOrder($o)):?><p class="muted" style="margin:8px 0 14px">Delivery confirmation is unavailable while this order is in the waiting-for-stock list. Move it to active orders first using the control above.</p><?php elseif(in_array((string)($o['status']??''),['Delivered','Cancelled'],true)||trim((string)($o['delivery_date']??''))!==''):?><p class="muted" style="margin:8px 0 14px"><?=($o['status']==='Cancelled')?'This order is cancelled.':(trim((string)($o['delivery_date']??''))!==''?'Delivery was already recorded.':'Marked Delivered but the actual date has not been entered.')?></p><?php endif;?>
<div class="order-quick-actions"><a class="quick-action edit-action" href="?view=edit&amp;id=<?=$o['id']?>">Edit order</a><?php if(trim((string)$o['phone'])!==''):?><a class="quick-action" href="tel:<?=e(preg_replace('/[^0-9+]/','',(string)$o['phone']))?>">Call</a><?php endif;?><?php if(trim((string)$o['address'])!==''):?><button type="button" class="quick-action quiet" data-copy-text="<?=e($o['address'])?>">Copy address</button><?php endif;?></div>
<?php if(orderPreparationState($o)!=='closed'||((string)($o['status']??'')==='Delivered'&&!isBackOrder($o)&&trim((string)($o['delivery_date']??''))==='')):?>
<section class="order-fulfilment" aria-label="Preparation and delivery">
<h3>Preparation &amp; delivery</h3>
<?php if((int)$o['total']===0):?><p class="muted">Free order · No payment required. Preparation and delivery can be recorded normally.</p><?php endif;?>
<?php dashboardPreparationControls($o,'orders');?>
<?php if((string)($o['status']??'')==='Delivered' && trim((string)($o['delivery_date']??''))===''):?><p class="muted">This order is marked Delivered, but no actual delivery date is recorded. Select the date below to complete the record.</p><?php endif;?>
<form method="post" class="operations-next-form" onsubmit="return confirm('Confirm this order was actually delivered on the selected date? Any unpaid balance will stay outstanding.');"><?php csrf();?>
<input type="hidden" name="action" value="delivery_mark"><input type="hidden" name="id" value="<?=(int)$o['id']?>"><input type="hidden" name="return" value="orders">
<label class="todo-date">Actual delivery date <input type="date" name="delivery_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($now->format('Y-m-d'))?>" required></label>
<button type="submit" class="operations-next-button">✓ Confirm delivered</button>
<p class="muted" style="font-size:12px;margin:4px 0 0">Delivery does not require payment or packaging to be recorded. If it was delivered earlier, choose the actual date.</p>
</form></section>
<?php endif;?>
<details class="order-disclosure order-documents" data-focus-sheet><summary>Documents &amp; sharing <span aria-hidden="true">⌄</span></summary><div class="focus-sheet-content"><div class="order-quick-actions"><a class="quick-action" href="?api=order-pdf&amp;id=<?=$o['id']?>&amp;type=invoice">Invoice PDF</a><a class="quick-action" href="?api=order-pdf&amp;id=<?=$o['id']?>&amp;type=receipt">Receipt PDF</a><button type="button" class="quick-action quiet" data-share-pdf="<?=$o['id']?>" data-document-type="receipt">Share receipt</button></div></div></details>
<details class="order-disclosure order-information"><summary>Order details <span aria-hidden="true">⌄</span></summary>
<div class="order-meta"><span><b>Ordered by</b><em><?=e($o['customer'])?></em></span><?php if(trim((string)($o['recipient_name']??''))!==''):?><span><b>For</b><em><?=e($o['recipient_name'])?></em></span><?php endif;?><span><b>Order</b><em><?=e(date('d M Y',strtotime($o['created'])))?></em></span><?php if(trim((string)($o['scheduled_delivery_date']??''))!==''):?><span><b>Planned delivery</b><em><?=e((new DateTimeImmutable($o['scheduled_delivery_date'],$tz))->format('d M Y'))?></em></span><?php endif;?><?php if(!empty($o['activated_at'])):?><span><b>Moved to active</b><em><?=e(date('d M Y',strtotime($o['activated_at'])))?></em></span><?php endif;?><?php if($o['payment_date']):?><span><b>Paid</b><em><?=e(date('d M Y',strtotime($o['payment_date'])))?></em></span><?php endif;?><?php if($o['delivery_date']):?><span><b>Delivered</b><em><?=e(date('d M Y',strtotime($o['delivery_date'])))?></em></span><?php endif;?><?php if($o['payment_method']):?><span><b>Payment</b><em><?=e($o['payment_method'])?></em></span><?php endif;?><?php if($o['delivery_method']):?><span><b>Delivery</b><em><?=e($o['delivery_method'])?></em></span><?php endif;?><?php if($o['assigned_to']):?><span><b>Assigned</b><em><?=e($o['assigned_to'])?></em></span><?php endif;?></div>
<?php if($o['address']):?><p class="address"><?=nl2br(e($o['address']))?></p><?php endif;?><?php if($o['tracking_reference']):?><p class="note"><strong>Tracking:</strong> <?=e($o['tracking_reference'])?></p><?php endif;?>
</details>
<?php $q=$db->prepare('SELECT * FROM items WHERE order_id=?');$q->execute([$o['id']]);foreach($q as $i):?><div class="line" data-ankh-product-name="<?=e($i['name'])?>"><span><?=e($i['quantity'].' × '.$i['name'].(!empty($i['presentation'])?' · '.$i['presentation']:'').((int)($i['discount']??0)>0?' · Family & Friends':'').' @ '.money($i['price']))?></span><strong><?=money($i['price']*$i['quantity'])?></strong></div><?php endforeach;?>
<?php if((int)$o['delivery_charge']>0):?><div class="line"><span>Postage / delivery charge</span><strong><?=money($o['delivery_charge'])?></strong></div><?php endif;?><?php if($o['notes']):?><p class="note"><?=nl2br(e($o['notes']))?></p><?php endif;?>
<?php $orderPaid=(int)($o['paid_amount']??0);$orderBalance=max(0,(int)$o['total']-$orderPaid);$pq=$db->prepare('SELECT * FROM payments WHERE order_id=? ORDER BY datetime(created) DESC,id DESC');$pq->execute([(int)$o['id']]);$orderPayments=$pq->fetchAll(PDO::FETCH_ASSOC);?>
<section class="payment-ledger"><div class="payment-ledger-head"><div><span>Payments received</span><strong><?=money($orderPaid)?></strong></div><div><span>Balance due</span><strong class="<?=$orderBalance>0?'balance-due':''?>"><?=money($orderBalance)?></strong></div></div>
<?php if($orderPayments):?><div class="payment-history"><?php foreach($orderPayments as $pay):?><div class="payment-history-row"><div><strong><?=money($pay['amount'])?></strong><small><?=e(date('d M Y',strtotime($pay['created'])))?> · <?=e($pay['method']?:'Not recorded')?><?php if($pay['note']):?> · <?=e($pay['note'])?><?php endif;?></small></div><form method="post" onsubmit="return confirm('Remove this payment?');"><?php csrf();?><input type="hidden" name="action" value="payment_delete"><input type="hidden" name="payment_id" value="<?=$pay['id']?>"><input type="hidden" name="return" value="<?=isBackOrder($o)?'backorders':'orders'?>"><button class="quiet payment-remove" aria-label="Remove payment">×</button></form></div><?php endforeach;?></div><?php endif;?>
<?php if($orderBalance>0 && $o['status']!=='Cancelled'):?><details class="add-payment"><summary>+ Record payment</summary><form method="post"><?php csrf();?><input type="hidden" name="action" value="payment_add"><input type="hidden" name="id" value="<?=$o['id']?>"><input type="hidden" name="return" value="<?=isBackOrder($o)?'backorders':'orders'?>"><div class="two"><label>Amount (£)<input name="amount" type="number" min=".01" max="<?=e(number_format($orderBalance/100,2,'.',''))?>" step=".01" inputmode="decimal" value="<?=e(number_format($orderBalance/100,2,'.',''))?>" required></label><label>Method<select name="method" required><option value="">Choose…</option><?php foreach($paymentMethods as $method):?><option value="<?=e($method)?>"><?=e($method)?></option><?php endforeach;?></select></label></div><div class="two"><label>Payment date<input type="date" name="payment_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($now->format('Y-m-d'))?>" required></label><label>Note <span class="muted">(optional)</span><input name="note" maxlength="500" placeholder="Deposit, balance, reference…"></label></div><button>Save payment</button></form></details><?php endif;?>
</section>
<details class="order-disclosure order-secondary-actions" data-focus-sheet><summary>More actions <span aria-hidden="true">⌄</span></summary><div class="focus-sheet-content"><div class="order-quick-actions"><a class="quick-action" href="?view=new&amp;repeat_order=<?=$o['id']?>">Repeat</a></div>
<details class="order-date-editor"><summary>Edit payment / delivery dates</summary><form method="post" class="order-dates-form"><?php csrf();?><input type="hidden" name="action" value="order_dates"><input type="hidden" name="return" value="<?=isBackOrder($o)?'backorders':'orders'?>"><input type="hidden" name="id" value="<?=$o['id']?>"><div class="two"><label>Payment date<input type="date" name="payment_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($o['payment_date']??'')?>"></label><label>Delivery date<input type="date" name="delivery_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($o['delivery_date']??'')?>"></label></div><button>Save dates</button></form></details>
<form method="post" class="status-form compact-status-form"><?php csrf();?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$o['id']?>"><div class="status-fields"><label>Status<select name="status"><?php foreach($statuses as $statusOption):?><option <?=$statusOption===$o['status']?'selected':''?>><?=e($statusOption)?></option><?php endforeach;?></select></label><label>Payment method<select name="payment_method"><option value="">Not set</option><?php foreach($paymentMethods as $method):?><option value="<?=e($method)?>" <?=$o['payment_method']===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label></div><button>Save</button></form>
<form method="post" class="delete-order-form" onsubmit="return confirm('Delete ANK-<?=str_pad((string)$o['id'],4,'0',STR_PAD_LEFT)?>? This permanently removes the order and its items.');"><?php csrf();?><input type="hidden" name="action" value="order_delete"><input type="hidden" name="id" value="<?=$o['id']?>"><input type="hidden" name="return" value="<?=isBackOrder($o)?'backorders':'orders'?>"><button class="quiet danger-button">Delete order</button></form></div></details></div></details><?php endforeach;?></div>
<p id="empty" class="empty" <?=count($listedOrders)?'hidden':''?>>No orders to show.</p>
<?php endif;?>
<?php elseif($view==='new'):?>
<div class="new-order-head"><div><h1><?=$repeatOrderData?'Repeat order':'New order'?></h1><?php if($repeatOrderData):?><p class="muted page-description">Based on ANK-<?=str_pad((string)$repeatOrderData['id'],4,'0',STR_PAD_LEFT)?>. Check anything that has changed.</p><div class="repeat-order-note"><strong>Current repeat only</strong><span>Remove any product they no longer want or take. The previous order stays unchanged.</span></div><?php endif;?></div><div class="new-order-tools"><button type="button" id="clear-draft" class="quiet draft-clear" hidden>Clear draft</button></div><div class="wizard-progress" aria-label="Order progress"><span class="active" data-progress-step="1">1<span>Customer</span></span><i></i><span data-progress-step="2">2<span>Products</span></span><i></i><span data-progress-step="3">3<span>Save</span></span></div></div>
<section class="voice-order-launch">
 <div class="voice-order-launch-copy">
  <span class="voice-order-launch-kicker">AI VOICE ORDER</span>
  <h2>Add your order with your voice</h2>
  <p>Tap the microphone and say the customer’s name, product and strength, quantity, Pen / Cartridge / Vial, delivery method and James or Tony if it’s assigned.</p>
  <div class="voice-order-example"><span>Try saying</span><q>Sarah Jones, two Reta 10mg pens, local delivery, assign to James.</q></div><div class="voice-order-alias">You can say <strong>“Reta”</strong> for Retatrutide.</div>
 </div>
 <button type="button" id="voice-order-button" class="voice-order-launch-button" data-ready="<?=$voiceOrderReady?'1':'0'?>">
  <span class="voice-launch-rings" aria-hidden="true"></span>
  <span class="voice-launch-mic" aria-hidden="true">🎙</span>
  <strong>Start voice order</strong>
 </button>
</section>
<section id="voice-order-panel" class="voice-order-panel" hidden>
<div class="voice-order-state"><span id="voice-order-pulse" class="voice-order-pulse" aria-hidden="true"></span><div><strong id="voice-order-title">Voice order</strong><p id="voice-order-message">Speak the order and I’ll prepare a draft for you to check.</p></div></div>
<div id="voice-order-transcript" class="voice-order-transcript" hidden></div>
<div id="voice-order-questions" class="voice-order-questions" hidden></div>
</section>
<div id="voice-order-overlay" class="voice-order-overlay" hidden>
 <div class="voice-order-overlay-card" role="dialog" aria-modal="true" aria-labelledby="voice-overlay-title">
  <div class="voice-order-tip">Speak naturally — I’ll turn it into an order</div>
  <button type="button" id="voice-order-orb" class="voice-order-orb" aria-label="Stop recording and build order draft">
   <span class="voice-order-ring ring-one" aria-hidden="true"></span>
   <span class="voice-order-ring ring-two" aria-hidden="true"></span>
   <span class="voice-order-ankh" aria-hidden="true">☥</span>
  </button>
  <h2 id="voice-overlay-title">Listening…</h2>
  <p id="voice-overlay-message">Say the customer, products, quantities, format, delivery and who it’s assigned to.</p>
  <div id="voice-order-timer" class="voice-order-timer">00:00</div>
  <button type="button" id="voice-order-stop" class="voice-order-stop"><span aria-hidden="true">■</span> STOP &amp; BUILD DRAFT</button>
  <small id="voice-overlay-help">Tap STOP when you’ve finished speaking</small>
 </div>
</div>
<form method="post" class="panel order-wizard" id="order-wizard" data-draft-enabled="<?=(!$newOrderCustomer&&!$repeatOrderData)?'1':'0'?>"><?php csrf();?><input type="hidden" name="action" value="order">

<section class="wizard-step" data-wizard-step="1">
<div class="wizard-step-head"><span class="wizard-kicker">STEP 1 OF 3</span><h2>Customer</h2><p class="muted">Choose the customer and delivery details.</p></div>
<?php if($recentCustomers && !$newOrderCustomer):?><div class="recent-customers"><span class="order-check-label">Recent customers</span><div class="recent-customer-chips"><?php foreach($recentCustomers as $recent):?><button type="button" class="recent-customer" data-recent-customer="<?=$recent['id']?>"><?=e($recent['name'])?></button><?php endforeach;?></div></div><?php endif;?>
<div class="two"><div class="customer-search-wrap"><label>Ordered by<input id="customer-search" name="customer" maxlength="160" required autocomplete="off" placeholder="Start typing name or phone…" value="<?=e($_POST['customer']??($newOrderCustomer['name']??''))?>"></label><div id="customer-results" class="customer-results" role="listbox" hidden></div></div><label>Phone<input id="customer-phone" name="phone" maxlength="40" type="tel" autocomplete="tel" value="<?=e($_POST['phone']??($newOrderCustomer['phone']??''))?>"></label></div>
<label>Order is for <span class="muted">(optional)</span><input id="recipient-name" name="recipient_name" maxlength="160" list="recipient-customer-list" autocomplete="off" placeholder="Leave blank if it’s for the person ordering" value="<?=e($_POST['recipient_name']??($repeatOrderData['recipient_name']??''))?>"><small class="muted">Choose an existing customer or type a name. Leave blank when it’s for the person ordering.</small></label><datalist id="recipient-customer-list"><?php foreach($customerSuggestions as $recipientCustomer):?><option value="<?=e($recipientCustomer['name'])?>"><?php endforeach;?></datalist>
<label>Referrer <span class="muted">(optional)</span><input id="customer-referrer" name="referrer" maxlength="160" list="referrer-list" placeholder="Who sent them to us?" value="<?=e($_POST['referrer']??'')?>"></label><datalist id="referrer-list"><?php foreach($referrers as $r):?><option value="<?=e($r)?>"><?php endforeach;?></datalist>
<label>Delivery address<textarea id="customer-address" name="address" maxlength="2000" autocomplete="street-address"><?=e($_POST['address']??($newOrderCustomer['address']??''))?></textarea></label>
<label class="order-date-field">Order date<input id="order-date" name="order_date" type="date" required max="<?=e($now->format('Y-m-d'))?>" value="<?=e($_POST['order_date']??$now->format('Y-m-d'))?>"></label>
<?php futureDeliveryFields();?>
<div class="order-subsection compact-delivery"><span class="order-check-label">Delivery</span>
<label>Delivery method<select id="delivery-method" name="delivery_method" required><?php $selectedDelivery=$_POST['delivery_method']??($repeatDeliveryMethod?:'Local Delivery');foreach($deliveryMethods as $method):?><option value="<?=e($method)?>" <?=$selectedDelivery===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label>
<label>Assigned to <span class="muted">(optional)</span><select id="assigned-to" name="assigned_to"><option value="">Unassigned</option><?php $selectedAssignee=$_POST['assigned_to']??'';foreach($deliveryAssignees as $assignee):?><option value="<?=e($assignee)?>" <?=$selectedAssignee===$assignee?'selected':''?>><?=e($assignee)?></option><?php endforeach;?></select></label>
<div id="postage-fields" class="postage-fields" hidden><label>Tracking / reference <span class="muted">(optional)</span><input id="tracking-reference" name="tracking_reference" maxlength="200" value="<?=e($_POST['tracking_reference']??$repeatTrackingReference)?>" placeholder="Royal Mail / courier reference"></label><div class="two"><label>Postage charged (£)<input id="delivery-charge" name="delivery_charge" type="number" min="0" max="100000" step=".01" inputmode="decimal" value="<?=e($_POST['delivery_charge']??number_format($repeatDeliveryCharge/100,2,'.',''))?>"></label><label>Actual postage cost (£)<input id="postage-cost" name="postage_cost" type="number" min="0" max="100000" step=".01" inputmode="decimal" value="<?=e($_POST['postage_cost']??number_format($repeatPostageCost/100,2,'.',''))?>"></label></div></div>
</div>
<div class="wizard-actions wizard-actions-next"><button type="button" data-wizard-next="2">Next · Add products →</button></div>
</section>

<section class="wizard-step" data-wizard-step="2" hidden>
<div class="wizard-step-head"><span class="wizard-kicker">STEP 2 OF 3</span><h2>Products</h2><p class="muted">Choose a product and strength where needed, then choose its format.</p></div>
<div class="product-search-wrap"><label for="product-search">Add product<input id="product-search" type="search" placeholder="Search products…" autocomplete="off" aria-autocomplete="list" aria-controls="product-results"></label><div id="product-results" class="product-results" role="listbox" hidden></div></div>
<div id="strength-picker" class="strength-picker" hidden><div class="strength-picker-head"><div><span class="muted">Choose strength</span><strong id="strength-product-name"></strong></div><button type="button" id="close-strength-picker" class="strength-close" aria-label="Close strength choices">×</button></div><div id="strength-options" class="strength-options"></div></div>
<div id="selected-order-lines" class="selected-order-lines"></div>
<p id="selected-empty" class="selected-empty">No products added yet.</p>
<button type="button" class="add-another-product" id="add-another-product" hidden><span class="add-another-plus">+</span><span><strong>Add another product</strong><small>Search peptides again</small></span></button>
<div class="wizard-actions"><button type="button" class="quiet wizard-back" data-wizard-back="1">← Back</button><button type="button" data-wizard-next="3">Next · Check order →</button></div>
</section>

<section class="wizard-step" data-wizard-step="3" hidden>
<div class="wizard-step-head"><span class="wizard-kicker">STEP 3 OF 3</span><h2>Check & save</h2><p class="muted">Check the order, then save.</p></div>
<div class="order-check">
<div class="order-check-section"><span class="order-check-label">Ordered by</span><strong id="check-customer">—</strong><small id="check-customer-detail"></small></div>
<div class="order-check-section"><span class="order-check-label">Order for</span><strong id="check-recipient">Same person</strong></div>
<div class="order-check-section"><span class="order-check-label">Order date</span><strong id="check-order-date">—</strong></div>
<div class="order-check-section"><span class="order-check-label">Delivery</span><strong id="check-delivery">—</strong><small id="check-delivery-detail"></small></div>
<div class="order-check-section"><span class="order-check-label">Products</span><div id="check-products"></div></div>
<div class="line order-check-total"><strong>Total</strong><strong id="subtotal">£0.00</strong></div>
</div>
<details class="back-order-option"><summary>Out of stock? Add to waiting list</summary><label class="check"><input type="checkbox" name="back_order" value="1" <?=($_POST['back_order']??'')==='1'?'checked':''?>> Save as back order — awaiting stock</label><p class="muted">Optional. Normal orders stay active by default. Back orders stay off the dashboard and reserve no stock.</p></details>
<div class="order-subsection"><span class="order-check-label">Payment</span><label>Payment method <span class="muted">(optional until paid)</span><select id="payment-method" name="payment_method"><option value="">Not set yet</option><?php $selectedPayment=$_POST['payment_method']??$repeatPaymentMethod;foreach($paymentMethods as $method):?><option value="<?=e($method)?>" <?=$selectedPayment===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label></div>
<label>Notes <span class="muted">(optional)</span><textarea name="notes" maxlength="4000" placeholder="Delivery instructions, payment reference…"><?=e($_POST['notes']??'')?></textarea></label>
<div class="wizard-actions"><button type="button" class="quiet wizard-back" data-wizard-back="2">← Back</button><button class="save-order">Save order</button></div>
</section>
</form>

<?php elseif($view==='edit'):?>
<?php if(!$editOrder):?><div class="heading"><div><h1>Edit order</h1></div><a class="quick-action" href="?view=orders">← Orders</a></div><p class="error">That order could not be found.</p>
<?php else:?>
<div class="heading"><div><h1>Edit ANK-<?=str_pad((string)$editOrder['id'],4,'0',STR_PAD_LEFT)?></h1><p class="muted page-description">Correct the customer, products, formats, prices, payment or delivery details.</p></div><a class="quick-action" href="?view=orders">Cancel</a></div>
<?php backOrderControls($db,$editOrder);?>
<?php if(isBackOrder($editOrder)):?><p class="note">Back order — editing keeps this order on the waiting list. Activate it from Back orders when stock arrives.</p><?php endif;?>
<form method="post" class="panel edit-order-form" id="edit-order-form"><?php csrf();?><input type="hidden" name="action" value="order_edit"><input type="hidden" name="return" value="orders"><input type="hidden" name="id" value="<?=$editOrder['id']?>">
<div class="edit-section"><span class="wizard-kicker">CUSTOMER & DATE</span><div class="two"><div class="customer-search-wrap"><label>Ordered by<input id="customer-search" name="customer" maxlength="160" required autocomplete="off" value="<?=e($editOrder['customer'])?>"></label><div id="customer-results" class="customer-results" role="listbox" hidden></div></div><label>Phone<input id="customer-phone" name="phone" maxlength="40" type="tel" value="<?=e($editOrder['phone'])?>"></label></div><label>Order is for <span class="muted">(optional)</span><input id="recipient-name" name="recipient_name" maxlength="160" list="recipient-customer-list" autocomplete="off" placeholder="Leave blank if it’s for the person ordering" value="<?=e($_POST['recipient_name']??($editOrder['recipient_name']??''))?>"><small class="muted">Choose an existing customer or type a name. Leave blank when it’s for the person ordering.</small></label><datalist id="recipient-customer-list"><?php foreach($customerSuggestions as $recipientCustomer):?><option value="<?=e($recipientCustomer['name'])?>"><?php endforeach;?></datalist><label>Referrer <span class="muted">(optional)</span><input id="customer-referrer" name="referrer" maxlength="160" value="<?=e($editOrder['referrer']??'')?>"></label><label>Address<textarea id="customer-address" name="address" maxlength="2000"><?=e($editOrder['address'])?></textarea></label><div class="edit-order-date-control"><label>Order date<input id="order-date" name="order_date" type="date" required max="<?=e($now->format('Y-m-d'))?>" value="<?=e(($_POST['change_order_date']??'')==='1'?($_POST['order_date']??''):(new DateTimeImmutable((string)$editOrder['created']))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d'))?>" <?=($_POST['change_order_date']??'')==='1'?'':'disabled'?>></label><label class="edit-order-date-toggle"><input type="checkbox" id="edit-order-date-toggle" name="change_order_date" value="1" <?=($_POST['change_order_date']??'')==='1'?'checked':''?>> Allow date change</label><small>Order date stays unchanged unless you enable this.</small></div></div>

<div class="edit-section"><span class="wizard-kicker">PRODUCTS</span><div class="product-search-wrap"><label for="product-search">Add product<input id="product-search" type="search" placeholder="Search products…" autocomplete="off" aria-autocomplete="list" aria-controls="product-results"></label><div id="product-results" class="product-results" role="listbox" hidden></div></div><div id="strength-picker" class="strength-picker" hidden><div class="strength-picker-head"><div><span class="muted">Choose strength</span><strong id="strength-product-name"></strong></div><button type="button" id="close-strength-picker" class="strength-close" aria-label="Close strength choices">×</button></div><div id="strength-options" class="strength-options"></div></div><div id="selected-order-lines" class="selected-order-lines"></div><p id="selected-empty" class="selected-empty">No products added yet.</p></div>

<div class="edit-section"><span class="wizard-kicker">DELIVERY</span><?php futureDeliveryFields($editOrder);?><label>Delivery method<select id="delivery-method" name="delivery_method" required><?php $editDeliveryMethod=$editOrder['delivery_method']?:'Local Delivery';foreach($deliveryMethods as $method):?><option value="<?=e($method)?>" <?=$editDeliveryMethod===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label><label>Assigned to<select id="assigned-to" name="assigned_to"><option value="">Unassigned</option><?php foreach($deliveryAssignees as $assignee):?><option value="<?=e($assignee)?>" <?=$editOrder['assigned_to']===$assignee?'selected':''?>><?=e($assignee)?></option><?php endforeach;?></select></label><div id="postage-fields" class="postage-fields" hidden><label>Tracking / reference<input id="tracking-reference" name="tracking_reference" maxlength="200" value="<?=e($editOrder['tracking_reference']??'')?>"></label><div class="two"><label>Postage charged (£)<input id="delivery-charge" name="delivery_charge" type="number" min="0" step=".01" value="<?=e(number_format((int)$editOrder['delivery_charge']/100,2,'.',''))?>"></label><label>Actual postage cost (£)<input id="postage-cost" name="postage_cost" type="number" min="0" step=".01" value="<?=e(number_format((int)$editOrder['postage_cost']/100,2,'.',''))?>"></label></div></div></div>

<div class="edit-section"><span class="wizard-kicker">PAYMENT & NOTES</span><label>Payment method<select id="payment-method" name="payment_method"><option value="">Not set</option><?php foreach($paymentMethods as $method):?><option value="<?=e($method)?>" <?=$editOrder['payment_method']===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label><label>Notes<textarea name="notes" maxlength="4000"><?=e($editOrder['notes'])?></textarea></label></div>
<div class="edit-total"><span>Order total</span><strong id="subtotal">£0.00</strong></div><button class="save-order">Save changes</button>
</form>
<details class="panel operator-order-history"><summary>Order activity history</summary>
<?php $history=activityForOrder($db,(int)$editOrder['id']);?>
<div class="activity-list"><?php foreach($history as $event):?><article class="activity-row"><div>
 <strong><?=e($event['action'])?></strong><small><?=e($event['actor_name'])?> · <?=e(date('d M Y, H:i',strtotime((string)$event['created'])))?></small><?php if($eventSummary=activitySummary(json_decode((string)$event['details_json'],true)?:[])):?><small><?=e($eventSummary)?></small><?php endif;?>
 </div></article><?php endforeach;?>
 <?php if(!$history):?><p class="muted">No changes have been recorded since activity tracking was enabled.</p><?php endif;?>
</div></details>
<?php endif;?>

<?php elseif($view==='enquiries'):ankhCustomerInbox($db);?>
<?php elseif($view==='product'):require __DIR__.'/product-v2.php';?>
<?php elseif($view==='products'): ankhCatalogueInit($db);ankhPublicReviewInit($db);$customerCatalogueVisibility=ankhCatalogueVisibility($db);$customerCatalogueContent=ankhCatalogueContent($db);$publicCatalogueReviews=ankhPublicReviewRows($db);?>
<script src="catalogue-admin.js?v=unified-2" defer></script>
<div class="heading"><div><h1>Products</h1><p class="muted">Retail prices, supplier costs and optional stock tracking.</p></div><a class="quick-action" href="?view=stock">Stock control →</a></div>
<div class="product-history-note"><strong>Historical prices are protected</strong><span>Changing retail, Family &amp; Friends or cost prices here only affects future orders. Reports keep the selling price and cost saved on each original order.</span></div>
<label class="product-search"><span>Find a product</span><input id="product-filter" type="search" placeholder="Search by name or strength" autocomplete="off" aria-label="Search products by name or strength"><span class="product-search-icon" aria-hidden="true">⌕</span></label>
<details class="add-product-disclosure"><summary><span class="add-product-plus" aria-hidden="true">＋</span><span><strong>Add new product</strong><small>Set up a product and its prices</small></span><span class="add-product-chevron" aria-hidden="true">＋</span></summary>
<form method="post" class="panel add-product-form"><?php csrf();?><input type="hidden" name="action" value="product"><input type="hidden" name="return" value="products"><h2>Product details</h2>
<div class="product-admin-grid"><label>Name and strength<input name="name" required maxlength="160" placeholder="Product name · 5mg"></label><label>Retail price (£)<input name="price" type="number" min="0" max="100000" step=".01" required></label><label>Family &amp; Friends price (£) <span class="muted">(optional)</span><input name="family_friends_price" type="number" min="0" max="100000" step=".01" placeholder="Blank = retail price less £5"></label><label>Cost (£) <span class="muted">(optional)</span><input name="cost" type="number" min="0" max="100000" step=".01"></label><label>Supplier pack size <span class="muted">(units)</span><input name="supplier_pack_size" type="number" min="1" max="999999" step="1" value="10" required></label><label>Low-stock alert<input name="low_stock_at" type="number" min="0" max="999999" step="1" value="5"></label></div>
<button>Add product</button></form></details>
<?php if($productV2Editing):?><div class="product-v2-edit-intro"><div><strong>Editing product #<?=(int)($_GET['edit_product']??0)?></strong><span class="muted">The original editing controls are retained to protect order and stock workflows.</span></div><a href="?view=products">← Back to premium catalogue</a></div><?php endif;?>
<section class="product-catalog-section"><div class="product-catalog-heading"><div><p class="eyebrow">AVAILABLE PRODUCTS</p><h2><?=$productV2Editing?'Edit product':'Active products'?></h2></div><span><?=$premiumV2Preview?'Tap a product to see full details':'Tap a vial to manage price &amp; stock'?></span></div>
<?php if($premiumV2Preview && !$productV2Editing):?>
<div class="product-v2-catalogue">
 <div class="product-v2-product-heading"><h2>Explore your catalogue</h2><small><?=count(array_filter($products,static fn($p)=>(int)$p['active']===1))?> active products</small></div>
 <div class="product-v2-catalogue-grid" id="product-v2-catalogue-grid">
 <?php foreach($products as $v2p):if((int)$v2p['active']!==1)continue;
 $v2id=(int)$v2p['id'];$v2name=(string)$v2p['name'];$v2meta=$customerCatalogueContent[$v2id]??[];
 $v2photo=(int)($v2meta['image_bytes']??0)>0;
 $v2tracked=(int)($v2p['stock_tracking']??0)===1;
 $v2stock=(int)($v2p['stock_jay_qty']??0)+(int)($v2p['stock_tony_qty']??0)+(int)($v2p['stock_unallocated_qty']??0);
 $v2low=$v2tracked&&$v2stock<=(int)($v2p['low_stock_at']??5);
 $v2strength='';if(preg_match('/\s+(\d+(?:\.\d+)?\s*(?:mg|ml|iu))$/i',$v2name,$v2match))$v2strength=trim($v2match[1]);?>
  <a class="product-v2-tile" href="?view=product&amp;id=<?=$v2id?>" aria-label="View <?=e($v2name)?> details">
   <span class="product-v2-tile-image">
    <?php if($v2photo):?><img src="?api=catalogue-image&amp;id=<?=$v2id?>" alt="" loading="lazy" decoding="async" data-product-v2-photo><?php endif;?>
    <span class="product-v2-tile-placeholder" <?=$v2photo?'hidden':''?> aria-hidden="true">☥</span>
    <span class="product-v2-tile-badge <?=$v2tracked?($v2low?'is-low':''):'is-untracked'?>"><?=$v2tracked?($v2low?'Low stock':'In stock'):'Not tracked'?></span>
   </span>
   <span class="product-v2-tile-kicker"><?=e($v2strength?:'Product')?></span>
   <strong class="product-v2-tile-name"><?=e($v2name)?></strong>
   <span class="product-v2-tile-foot"><strong class="product-v2-tile-price"><?=money((int)$v2p['price'])?></strong><small><b><?=$v2tracked?$v2stock:'—'?></b><?=$v2tracked?' available':'not tracked'?></small></span>
  </a>
 <?php endforeach;?>
 </div>
</div>
<?php endif;?>
<div class="product-catalog-grid">
<?php foreach($products as $p):if(!(int)$p['active'])continue;$costMapped=isset($currentSupplierCosts[$p['name']]);$productStrength='';if(preg_match('/\s+(\d+(?:\.\d+)?\s*(?:mg|ml|iu))$/i',(string)$p['name'],$strengthMatch))$productStrength=$strengthMatch[1];?>
<details class="product-tile <?=$productV2Editing && (int)($_GET['edit_product']??0)===(int)$p['id']?'product-v2-current-edit':''?>" <?=$productV2Editing && (int)($_GET['edit_product']??0)===(int)$p['id']?'open':''?>><summary><span class="product-vial-icon" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="M12 4h8v4l2 2v15a3 3 0 0 1-3 3h-6a3 3 0 0 1-3-3V10l2-2V4Z" stroke="currentColor" stroke-width="1.8"/><path d="M12 14h8M13 7h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M12 20h8" stroke="currentColor" stroke-width="3" opacity=".35"/></svg></span><strong class="product-tile-name"><?=e($productStrength?trim(substr((string)$p['name'],0,-strlen($productStrength))):(string)$p['name'])?></strong><span class="product-tile-strength"><?=e($productStrength?:'Product')?></span><span class="product-tile-price"><?=money($p['price'])?></span><span class="product-tile-open">Manage ›</span></summary><div class="product-edit-panel"><form method="post" class="detail product-settings-form"><?php csrf();?><input type="hidden" name="action" value="product"><input type="hidden" name="return" value="products"><input type="hidden" name="id" value="<?=$p['id']?>"><label>Product name<input name="name" required maxlength="160" value="<?=e($p['name'])?>"></label><div class="product-admin-grid"><label>Retail price (£)<input name="price" type="number" min="0" max="100000" step=".01" required value="<?=e(number_format($p['price']/100,2,'.',''))?>"></label><label>Family &amp; Friends price (£)<input name="family_friends_price" type="number" min="0" max="100000" step=".01" value="<?=$p['family_friends_price']===null?'':e(number_format($p['family_friends_price']/100,2,'.',''))?>" placeholder="Blank = retail price less £5"></label><label>Cost (£)<?php if($costMapped):?> <span class="muted">Supplier list</span><?php endif;?><input name="cost" type="number" min="0" max="100000" step=".01" value="<?=$p['cost']===null?'':e(number_format($p['cost']/100,2,'.',''))?>" ></label><label>Supplier pack size <span class="muted">(units)</span><input name="supplier_pack_size" type="number" min="1" max="999999" step="1" value="<?=e(max(1,(int)($p['supplier_pack_size']??10)))?>" required></label><label>Low-stock alert<input name="low_stock_at" type="number" min="0" max="999999" step="1" value="<?=e($p['low_stock_at'])?>"></label></div><label class="check"><input type="checkbox" name="active" checked> Available for new orders</label><button>Save product details</button></form><?php ankhCatalogueAdminControl($p,$customerCatalogueVisibility,$customerCatalogueContent[(int)$p['id']]??[],$publicCatalogueReviews);?><section class="product-stock-editor"><div class="product-stock-heading"><span class="eyebrow">FRIDGE COUNTS</span><span>Exact physical count</span></div><div class="product-stock-locations">
<?php foreach(['Jay'=>'stock_jay_qty','Tony'=>'stock_tony_qty'] as $person=>$stockColumn):$currentQty=(int)($p[$stockColumn]??0);$reservedQty=(int)($reservedStockByProduct[(int)$p['id']][strtolower($person)]??0);$physicalQty=$currentQty+$reservedQty;?><form method="post" class="product-stock-count-form"><?php csrf();?><input type="hidden" name="action" value="stock_open"><input type="hidden" name="return" value="products"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="location" value="<?=e($person)?>"><strong><?=e($person)?>’s fridge</strong><label>Count<input name="opening_qty" type="number" inputmode="numeric" min="0" max="999999" step="1" required value="<?=$physicalQty?>"></label><small class="product-stock-breakdown"><?=$reservedQty?> reserved · <?=max(0,$currentQty)?> available<?php if($currentQty<0):?> · <strong><?=abs($currentQty)?> short</strong><?php endif;?></small><label class="check stock-count-confirm"><input type="checkbox" name="confirm_opening" value="1" required> Physically counted</label><button class="quiet">Save count</button></form><?php endforeach;?></div></section><div class="product-admin-actions">
<?php if((int)($p['stock_tracking']??0)===1):?><form method="post" onsubmit="return confirm('Remove <?=e(addslashes((string)$p['name']))?> from Stock Control? Its current fridge counts will be cleared, but its movement history will be kept.');"><?php csrf();?><input type="hidden" name="action" value="stock_untrack"><input type="hidden" name="return" value="products"><input type="hidden" name="product_id" value="<?=$p['id']?>"><button type="submit" class="quiet stock-untrack-button">Remove from Stock Control</button></form><?php endif;?>
<form method="post" onsubmit="return confirm('Permanently delete <?=e(addslashes((string)$p['name']))?>? Only use this if the product was created by mistake. Products already used on orders are protected and cannot be deleted.');"><?php csrf();?><input type="hidden" name="action" value="product_delete"><input type="hidden" name="return" value="products"><input type="hidden" name="product_id" value="<?=$p['id']?>"><button type="submit" class="quiet danger-button product-delete-button">Delete product</button></form>
</div></div></details><?php endforeach;?></div><p id="product-no-results" class="product-no-results" role="status" aria-live="polite" hidden>No products match that search.</p>
<details id="hidden-product-list" class="hidden-product-list" <?=$productV2Editing?'open':''?>><summary>Hidden products <span><?=count(array_filter($products,fn($item)=>(int)$item['active']===0))?></span></summary><div class="hidden-product-grid"><?php foreach($products as $p):if((int)$p['active'])continue;$costMapped=isset($currentSupplierCosts[$p['name']]);?><details class="order product-admin-card <?=$productV2Editing && (int)($_GET['edit_product']??0)===(int)$p['id']?'product-v2-current-edit':''?>" <?=$productV2Editing && (int)($_GET['edit_product']??0)===(int)$p['id']?'open':''?>><summary><div><h2><?=e($p['name'])?></h2><span class="muted"><?=money($p['price'])?> retail</span></div><div class="product-admin-summary"><span>Hidden</span><small>Tap to edit</small></div></summary><form method="post" class="detail product-settings-form"><?php csrf();?><input type="hidden" name="action" value="product"><input type="hidden" name="return" value="products"><input type="hidden" name="id" value="<?=$p['id']?>"><label>Name<input name="name" required maxlength="160" value="<?=e($p['name'])?>"></label><div class="product-admin-grid"><label>Retail price (£)<input name="price" type="number" min="0" max="100000" step=".01" required value="<?=e(number_format($p['price']/100,2,'.',''))?>"></label><label>Family &amp; Friends price (£)<input name="family_friends_price" type="number" min="0" max="100000" step=".01" value="<?=$p['family_friends_price']===null?'':e(number_format($p['family_friends_price']/100,2,'.',''))?>"></label><label>Cost (£)<?php if($costMapped):?> <span class="muted">Supplier list</span><?php endif;?><input name="cost" type="number" min="0" max="100000" step=".01" value="<?=$p['cost']===null?'':e(number_format($p['cost']/100,2,'.',''))?>" ></label></div><label class="check"><input type="checkbox" name="active"> Available for new orders</label><button>Save product details</button></form><?php ankhCatalogueAdminControl($p,$customerCatalogueVisibility,$customerCatalogueContent[(int)$p['id']]??[],$publicCatalogueReviews);?><section class="product-stock-editor"><div class="product-stock-heading"><span class="eyebrow">FRIDGE COUNTS</span><span>Exact physical count</span></div><div class="product-stock-locations"><?php foreach(['Jay'=>'stock_jay_qty','Tony'=>'stock_tony_qty'] as $person=>$stockColumn):$currentQty=(int)($p[$stockColumn]??0);$reservedQty=(int)($reservedStockByProduct[(int)$p['id']][strtolower($person)]??0);$physicalQty=$currentQty+$reservedQty;?><form method="post" class="product-stock-count-form"><?php csrf();?><input type="hidden" name="action" value="stock_open"><input type="hidden" name="return" value="products"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="location" value="<?=e($person)?>"><strong><?=e($person)?>’s fridge</strong><label>Count<input name="opening_qty" type="number" inputmode="numeric" min="0" max="999999" step="1" required value="<?=$physicalQty?>"></label><small class="product-stock-breakdown"><?=$reservedQty?> reserved · <?=max(0,$currentQty)?> available<?php if($currentQty<0):?> · <strong><?=abs($currentQty)?> short</strong><?php endif;?></small><label class="check stock-count-confirm"><input type="checkbox" name="confirm_opening" value="1" required> Physically counted</label><button class="quiet">Save count</button></form><?php endforeach;?></div></section><div class="product-admin-actions">
<?php if((int)($p['stock_tracking']??0)===1):?><form method="post" onsubmit="return confirm('Remove <?=e(addslashes((string)$p['name']))?> from Stock Control? Its current fridge counts will be cleared, but its movement history will be kept.');"><?php csrf();?><input type="hidden" name="action" value="stock_untrack"><input type="hidden" name="return" value="products"><input type="hidden" name="product_id" value="<?=$p['id']?>"><button type="submit" class="quiet stock-untrack-button">Remove from Stock Control</button></form><?php endif;?>
<form method="post" onsubmit="return confirm('Permanently delete <?=e(addslashes((string)$p['name']))?>? Only use this if the product was created by mistake. Products already used on orders are protected and cannot be deleted.');"><?php csrf();?><input type="hidden" name="action" value="product_delete"><input type="hidden" name="return" value="products"><input type="hidden" name="product_id" value="<?=$p['id']?>"><button type="submit" class="quiet danger-button product-delete-button">Delete product</button></form>
</div></details><?php endforeach;?></div></details></section>
<script>(()=>{const input=document.querySelector('#product-filter');if(!input)return;const active=[...document.querySelectorAll('.product-catalog-grid .product-tile')],hidden=[...document.querySelectorAll('.hidden-product-grid .product-admin-card')],hiddenList=document.querySelector('#hidden-product-list'),empty=document.querySelector('#product-no-results');input.addEventListener('input',()=>{const query=input.value.trim().toLocaleLowerCase();let visible=0;active.forEach(card=>{const match=!query||card.textContent.toLocaleLowerCase().includes(query);card.hidden=!match;if(match)visible++});const v2cards=[...document.querySelectorAll('.product-v2-tile')];if(v2cards.length)visible=0;v2cards.forEach(card=>{const match=!query||card.textContent.toLocaleLowerCase().includes(query);card.hidden=!match;if(match)visible++});let hiddenVisible=0;hidden.forEach(card=>{const match=!query||card.textContent.toLocaleLowerCase().includes(query);card.hidden=!match;if(match)hiddenVisible++});if(query&&hiddenVisible)hiddenList.open=true;empty.hidden=visible+hiddenVisible>0;});})();</script>
<script>
document.querySelectorAll('[data-product-v2-photo]').forEach(img=>img.addEventListener('error',()=>{img.hidden=true;const fallback=img.parentElement?.querySelector('.product-v2-tile-placeholder');if(fallback)fallback.hidden=false},{once:true}));
</script>
<?php elseif($view==='stock'):?>
<?php $stockLowOnly=(($_GET['filter']??'')==='low');$stockInitialView=in_array($_GET['fridge']??'combined',['combined','jay','tony'],true)?(string)($_GET['fridge']??'combined'):'combined';
$trackedProducts=array_values(array_filter($stockProducts,fn($p)=>(int)($p['stock_tracking']??0)===1));
if($stockLowOnly){$initialQty=fn($p)=>$stockInitialView==='combined'?(int)($p['stock_jay_qty']??0)+(int)($p['stock_tony_qty']??0):(int)($p[$stockInitialView==='jay'?'stock_jay_qty':'stock_tony_qty']??0);usort($trackedProducts,fn($a,$b)=>$initialQty($a)<=>$initialQty($b) ?: strcasecmp((string)$a['name'],(string)$b['name']));}
$stockSection=in_array((string)($_GET['section']??'overview'),['overview','incoming','forecast','history'],true)?(string)($_GET['section']??'overview'):'overview';?>
<div class="heading stock-heading"><div><p class="eyebrow">INVENTORY</p><h1>Stock control</h1><p class="muted page-description"><?=$stockSection==='overview'?'Your stock at a glance. Add, move and check balances without a long admin screen':($stockSection==='incoming'?'Supplier orders and stock that is on the way':($stockSection==='forecast'?'Plan ahead using available, incoming and expected recurring demand':'A complete recent audit of stock changes'))?>.</p></div><a class="quick-action stock-manage-link" href="?view=products"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h2M10 17h10M14 4v6M6 14v6"/></svg><span>Manage stock</span><b>›</b></a></div>
<nav class="stock-workspace-nav stock-workspace-dock" aria-label="Stock sections">
 <a href="?view=stock" class="<?=$stockSection==='overview'?'selected':''?>">
  <span class="stock-nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1.5"/><rect x="14" y="4" width="6" height="6" rx="1.5"/><rect x="4" y="14" width="6" height="6" rx="1.5"/><rect x="14" y="14" width="6" height="6" rx="1.5"/></svg></span><span class="stock-nav-copy"><strong>Overview</strong><small>Stock now</small></span>
 </a>
 <a href="?view=stock&amp;section=incoming" class="<?=$stockSection==='incoming'?'selected':''?>">
  <span class="stock-nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11M8 10l4 4 4-4"/><path d="M5 17v3h14v-3"/></svg><?php if($totalIncomingUnits>0):?><i><?=$totalIncomingUnits?></i><?php endif;?></span><span class="stock-nav-copy"><strong>Incoming</strong><small><?=$totalIncomingUnits>0?$totalIncomingUnits.' units due':'Nothing due'?></small></span>
 </a>
 <a href="?view=stock&amp;section=forecast" class="<?=$stockSection==='forecast'?'selected':''?>">
  <span class="stock-nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 18l5-5 4 3 7-9"/><path d="M15 7h5v5"/></svg></span><span class="stock-nav-copy"><strong>Forecast</strong><small>Plan ahead</small></span>
 </a>
 <a href="?view=stock&amp;section=history" class="<?=$stockSection==='history'?'selected':''?>">
  <span class="stock-nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/><circle cx="7" cy="6" r="1"/><circle cx="14" cy="12" r="1"/><circle cx="10" cy="18" r="1"/></svg></span><span class="stock-nav-copy"><strong>History</strong><small>Audit trail</small></span>
 </a>
</nav>
<script>document.addEventListener('DOMContentLoaded',()=>{
 let sequence=0;
 document.querySelectorAll('[data-supplier-edit-form]').forEach(form=>{
  const lines=form.querySelector('[data-supplier-edit-lines]'),template=form.querySelector('[data-supplier-edit-template]');
  form.addEventListener('click',event=>{const remove=event.target.closest('[data-remove-edit-line]');if(remove){remove.closest('[data-supplier-edit-line]').remove();return;}if(!event.target.closest('[data-add-edit-line]'))return;
   const fragment=template.content.cloneNode(true),key='new_'+Date.now()+'_'+(++sequence);fragment.querySelectorAll('[name]').forEach(input=>input.name=input.name.replace('NEW_KEY',key));lines.append(fragment);lines.lastElementChild.querySelector('select').focus();
  });
 });
 const selected=document.querySelector('.supplier-order-editor[open]');if(selected)selected.closest('.supplier-order-card').open=true;
});</script>
<?php if($stockSection==='overview'):?>
<section class="stock-transaction-centre">
 <div class="stock-transaction-head"><div><p class="eyebrow">STOCK ACTIONS</p><h2>Move stock without editing products</h2><p>Supplier packs are 10 units. Use the quick pack buttons or enter any quantity.</p></div></div>
 <div class="stock-transaction-grid">
  <details class="stock-action-card"><summary><span class="stock-action-icon">＋</span><div><strong>Add stock</strong><small>Receive packs into holding, Jay or Tony</small></div></summary>
   <form method="post" class="stock-central-form" data-pack-form><?php csrf();?><input type="hidden" name="action" value="stock_receive"><input type="hidden" name="return" value="stock">
    <label>Product<select name="product_id" required><option value="">Choose product</option><?php foreach($stockProducts as $p):if(!(int)$p['active'])continue;?><option value="<?=$p['id']?>"><?=e($p['name'])?></option><?php endforeach;?></select></label>
    <label>Put it in<select name="location"><option value="Unallocated">Holding stock</option><option value="Jay">Jay’s fridge</option><option value="Tony">Tony’s fridge</option></select></label>
    <div class="stock-pack-picker"><span>Quick add</span><div><button type="button" data-pack-qty="10">+10</button><button type="button" data-pack-qty="20">+20</button><button type="button" data-pack-qty="30">+30</button><button type="button" data-pack-qty="40">+40</button></div></div>
    <label>Quantity<input name="quantity" type="number" min="1" max="999999" step="1" value="10" required data-pack-quantity></label>
    <label class="stock-form-wide">Note <span>(optional)</span><input name="note" maxlength="500" placeholder="e.g. Supplier delivery"></label>
    <button class="stock-primary-action">Add stock</button>
   </form>
  </details>
  <details class="stock-action-card"><summary><span class="stock-action-icon">⇄</span><div><strong>Move stock</strong><small>Jay ↔ Tony ↔ Holding</small></div></summary>
   <form method="post" class="stock-central-form" data-pack-form><?php csrf();?><input type="hidden" name="action" value="stock_transfer"><input type="hidden" name="return" value="stock">
    <label>Product<select name="product_id" required><option value="">Choose product</option><?php foreach($trackedProducts??[] as $p):?><option value="<?=$p['id']?>"><?=e($p['name'])?></option><?php endforeach;?></select></label>
    <label>From<select name="from_location"><option value="Jay">Jay’s fridge</option><option value="Tony">Tony’s fridge</option><option value="Unallocated">Holding stock</option></select></label>
    <label>To<select name="to_location"><option value="Tony">Tony’s fridge</option><option value="Jay">Jay’s fridge</option><option value="Unallocated">Holding stock</option></select></label>
    <div class="stock-pack-picker"><span>Quick move</span><div><button type="button" data-pack-qty="10">10</button><button type="button" data-pack-qty="20">20</button><button type="button" data-pack-qty="30">30</button></div></div>
    <label>Quantity<input name="quantity" type="number" min="1" max="999999" step="1" value="10" required data-pack-quantity></label>
    <label class="stock-form-wide">Reason <span>(optional)</span><input name="note" maxlength="500" placeholder="e.g. Jay running low"></label>
    <button class="stock-primary-action">Move stock</button>
   </form>
  </details>
 </div>
</section>
<div class="fridge-overview-grid" role="group" aria-label="Choose which fridge stock to view">
<?php $combinedAvailable=(int)($fridgeTotals['jay']??0)+(int)($fridgeTotals['tony']??0);$combinedReserved=(int)$fridgeReservedTotals['jay']+(int)$fridgeReservedTotals['tony'];$combinedPhysical=$combinedAvailable+$combinedReserved;?>
<button class="fridge-overview-card fridge-combined stock-location-filter combined-stock-v2" type="button" data-stock-view="combined" aria-pressed="true">
 <div class="combined-stock-head"><span class="combined-stock-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7.5 12 4l7 3.5v9L12 20l-7-3.5z"/><path d="M5 7.5 12 11l7-3.5M12 11v9"/></svg></span><span><b>Combined stock</b><small>Jay + Tony’s fridges</small></span><i>Live balance</i></div>
 <div class="combined-stock-hero"><strong><?=max(0,$combinedAvailable)?></strong><span>units available</span></div>
 <div class="combined-stock-stats">
  <span><i class="stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8h10v12H7zM9 4h6v4H9z"/></svg></i><small>Physical</small><b><?=max(0,$combinedPhysical)?></b></span>
  <span><i class="stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 11V8a5 5 0 0 1 10 0v3M6 11h12v9H6z"/></svg></i><small>Reserved</small><b><?=e($combinedReserved)?></b></span>
  <span class="available"><i class="stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 12 4 4 8-9"/></svg></i><small>Available</small><b><?=max(0,$combinedAvailable)?></b></span>
 </div>
 <div class="combined-stock-money">
  <span><i class="money-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14v11H5zM8 7V5h8v2"/><path d="M15.5 10.5c-.6-.5-1.3-.7-2.1-.7-1.1 0-1.9.5-1.9 1.2 0 1.8 4.2.8 4.2 3 0 .8-.8 1.4-2.1 1.4-.9 0-1.8-.3-2.5-.9"/></svg></i><span><small>Retail value</small><b><?=money($fridgeValueTotals['combined']['retail'])?></b></span></span>
  <span><i class="money-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 18 10 13l3 3 6-8"/><path d="M15 8h4v4"/></svg></i><span><small>Potential profit</small><b><?=$fridgeValueTotals['combined']['missing_cost']?'~':''?><?=money($fridgeValueTotals['combined']['profit'])?></b></span></span>
 </div>
</button>
<button class="fridge-overview-card fridge-jay stock-location-filter" type="button" data-stock-view="jay" aria-pressed="false"><span>JAY’S FRIDGE</span><strong><?=max(0,(int)($fridgeTotals['jay']??0))?> <em>available</em></strong><div class="stock-overview-breakdown"><small><b><?=max(0,(int)$fridgePhysicalTotals['jay'])?></b>Physical</small><small><b><?=e($fridgeReservedTotals['jay'])?></b>Reserved</small><small class="available"><b><?=max(0,(int)($fridgeTotals['jay']??0))?></b>Available</small></div><div class="fridge-value-summary"><small><b>Retail value available</b><?=money($fridgeValueTotals['jay']['retail'])?></small><small><b>Potential profit</b><?=$fridgeValueTotals['jay']['missing_cost']?'Known costs · ':''?><?=money($fridgeValueTotals['jay']['profit'])?></small></div></button>
<button class="fridge-overview-card fridge-tony stock-location-filter" type="button" data-stock-view="tony" aria-pressed="false"><span>TONY’S FRIDGE</span><strong><?=max(0,(int)($fridgeTotals['tony']??0))?> <em>available</em></strong><div class="stock-overview-breakdown"><small><b><?=max(0,(int)$fridgePhysicalTotals['tony'])?></b>Physical</small><small><b><?=e($fridgeReservedTotals['tony'])?></b>Reserved</small><small class="available"><b><?=max(0,(int)($fridgeTotals['tony']??0))?></b>Available</small></div><div class="fridge-value-summary"><small><b>Retail value available</b><?=money($fridgeValueTotals['tony']['retail'])?></small><small><b>Potential profit</b><?=$fridgeValueTotals['tony']['missing_cost']?'Known costs · ':''?><?=money($fridgeValueTotals['tony']['profit'])?></small></div></button>
</div>
<?php if($incomingProductBreakdown):?>
<details class="stock-on-way" open>
 <summary><span class="stock-on-way-heading"><b>Stock on the way</b><small><?=count($incomingProductBreakdown)?> product<?=count($incomingProductBreakdown)===1?'':'s'?> across open supplier orders</small></span><span class="stock-on-way-total"><?=$totalIncomingUnits?> <small>vials due</small></span></summary>
 <div class="stock-on-way-rows">
 <?php foreach(array_slice($incomingProductBreakdown,0,4) as $upcoming):?>
 <div class="stock-on-way-row"><span><?=e($upcoming['name'])?></span><strong>+<?=$upcoming['due']?> <small>vials</small></strong></div>
 <?php endforeach;?>
 <?php if(count($incomingProductBreakdown)>4):?><a class="stock-on-way-more" href="?view=stock&amp;section=incoming#incoming-by-product">View all <?=count($incomingProductBreakdown)?> incoming products →</a>
 <?php else:?><a class="stock-on-way-more" href="?view=stock&amp;section=incoming#incoming-by-product">View deliveries and allocate stock →</a><?php endif;?>
 </div>
</details>
<?php endif;?>
<div class="fridge-direct-links"><a href="?view=fridge_jay">Open Jay’s fridge <span>→</span></a><a href="?view=fridge_tony">Open Tony’s fridge <span>→</span></a><div><strong><?=max(0,(int)($fridgeTotals['unallocated']??0))?></strong><span>Holding stock</span></div></div>
<?php if((int)($fridgeTotals['unallocated']??0)>0):?><div class="panel fridge-legacy-note"><strong><?=e($fridgeTotals['unallocated'])?> units need assigning</strong><span>These existing counts aren’t assigned to a fridge yet. Use Products to assign them to Jay or Tony.</span></div><?php endif;?>
<?php endif;?>
<?php if($stockSection==='incoming'):?>
<div class="stock-subpage-heading"><a href="?view=stock">← Stock overview</a><div><p class="eyebrow">SUPPLIER STOCK</p><h2>Incoming stock</h2><p>Keep supplier orders separate from the stock you already have.</p></div></div>
<?php $backOrderIncomingByProduct=array_column(backOrderStockPlan($db,array_column($incomingProductBreakdown,null,'product_id')),null,'id');?>
<section class="incoming-stock-section">
 <div class="incoming-stock-head"><div><p class="eyebrow">INCOMING STOCK</p><h2>Supplier orders</h2><p class="muted">Orders are entered in packs. One pack = 10 units.</p></div><div class="incoming-stock-total"><strong><?=$totalIncomingUnits?></strong><span>units incoming</span></div></div>
<?php if($incomingProductBreakdown):?>
<details class="incoming-by-product ux-incoming-products" id="incoming-by-product" aria-labelledby="incoming-by-product-title">
 <summary class="incoming-by-product-head"><span><small class="eyebrow">WHAT'S ON THE WAY</small><strong id="incoming-by-product-title">Incoming by product</strong><small><?=count($incomingProductBreakdown)?> products with outstanding deliveries · <?=$totalIncomingUnits?> units to receive</small></span><b class="ux-incoming-disclosure-icon" aria-hidden="true">⌄</b></summary><div class="ux-incoming-product-content">
 <div class="incoming-by-product-list">
 <?php foreach($incomingProductBreakdown as $dueItem):?>
 <article class="incoming-by-product-row">
  <div><strong><?=e($dueItem['name'])?></strong><small><?=$dueItem['orders']?> supplier order<?=$dueItem['orders']===1?'':'s'?><?php if($dueItem['expected_date']):?> · Earliest expected <?=e(date('d M Y',strtotime($dueItem['expected_date'])))?><?php else:?> · Date not set<?php endif;?></small><?php $waitingDemand=$backOrderIncomingByProduct[(int)$dueItem['product_id']]??null;if($waitingDemand):?><a class="incoming-backorder-demand" href="?view=orders&amp;section=backorders"><?=(int)$waitingDemand['quantity']?> back ordered<?php if($waitingDemand['shortfall']!==null):?> · <?=$waitingDemand['shortfall']>0?(int)$waitingDemand['shortfall'].' still short':'covered with stock + incoming'?><?php endif;?> →</a><?php endif;?><a class="incoming-edit-link" href="<?=$dueItem['orders']===1?'?view=stock&amp;section=incoming&amp;edit_supplier='.(int)$dueItem['order_ids'][0].'#supplier-edit-'.(int)$dueItem['order_ids'][0]:'?view=stock&amp;section=incoming#incoming-supplier-orders'?>"><?=$dueItem['orders']===1?'Edit incoming order':'View / edit supplier orders'?> →</a></div>
  <span class="incoming-by-product-due"><b>+<?=$dueItem['due']?></b><small>units due</small></span>
 </article>
 <?php endforeach;?>
 </div>
 <p class="incoming-by-product-note">Only outstanding quantities are shown. Stock already received, including partial deliveries, is excluded. Use the supplier orders below to receive and allocate each delivery.</p></div>
</details>
<?php endif;?>
 <details class="supplier-order-create"><summary>+ Add supplier order</summary>
  <form method="post" class="supplier-order-form" data-supplier-order-form><?php csrf();?><input type="hidden" name="action" value="supplier_order_create"><input type="hidden" name="return" value="stock"><input type="hidden" name="return_section" value="incoming">
   <div class="supplier-order-meta"><label>Supplier<input name="supplier" maxlength="160" placeholder="Supplier name" required></label><label>Expected date<input name="expected_date" type="date"></label></div>
   <div class="supplier-lines" data-supplier-lines>
    <div class="supplier-line">
     <label>Product<select name="product_id[]" required><option value="">Choose product</option><?php foreach($stockProducts as $p):if(!(int)$p['active'])continue;?><option value="<?=$p['id']?>" data-cost="<?=$p['cost']===null?'':number_format((int)$p['cost']/100,2,'.','')?>"><?=e($p['name'])?></option><?php endforeach;?></select></label>
     <label>Packs of 10<input name="packs[]" type="number" min="1" max="999" step="1" value="1" required></label>
     <label>Cost each (£)<input name="unit_cost[]" type="number" min="0" step=".01" placeholder="Optional"></label>
     <button type="button" class="supplier-line-remove" data-remove-supplier-line aria-label="Remove line">×</button>
    </div>
   </div>
   <button type="button" class="quick-action supplier-add-line" data-add-supplier-line>+ Add another product</button>
   <label>Note <span>(optional)</span><input name="note" maxlength="500"></label><button class="stock-primary-action">Save incoming order</button>
  </form>
 </details>
 <?php $openSupplierOrders=array_values(array_filter($supplierOrders,fn($so)=>!in_array((string)$so['status'],['Received','Cancelled'],true)));if($openSupplierOrders):?><div class="supplier-order-list" id="incoming-supplier-orders">
 <?php foreach($openSupplierOrders as $supplierOrder):?><details class="supplier-order-card ux-incoming-order">
  <summary class="ux-incoming-order-summary"><header><div><span>SUPPLIER #<?=$supplierOrder['id']?> · <?=e(strtoupper($supplierOrder['status']))?></span><h3><?=e($supplierOrder['supplier'])?></h3><small><?=$supplierOrder['expected_date']!==''?'Expected '.e(date('d M Y',strtotime($supplierOrder['expected_date']))):'No expected date'?></small></div><div><strong><?=max(0,(int)$supplierOrder['total_qty']-(int)$supplierOrder['received_qty'])?></strong><span>remaining</span></div></header><b class="ux-incoming-disclosure-icon" aria-hidden="true">⌄</b></summary><div class="ux-incoming-order-content">
  <?php renderSupplierOrderEditor($db,$supplierOrder,$supplierOrderItems[(int)$supplierOrder['id']]??[],$stockProducts);?>
  <div class="supplier-order-products"><?php foreach(($supplierOrderItems[(int)$supplierOrder['id']]??[]) as $incomingItem):$remaining=max(0,(int)$incomingItem['quantity']-(int)$incomingItem['received_qty']);?>
   <div class="supplier-product-row"><div><strong><?=e($incomingItem['product_name'])?></strong><small><?=$incomingItem['packs']?> pack<?=$incomingItem['packs']==1?'':'s'?> × <?=$incomingItem['pack_size']?> = <?=$incomingItem['quantity']?> units<?=(int)$incomingItem['received_qty']>0?' · '.(int)$incomingItem['received_qty'].' received':''?></small></div><b><?=$remaining?> due</b></div>
   <?php if($remaining>0):?><details class="supplier-receive"><summary>Receive / allocate</summary><form method="post"><?php csrf();?><input type="hidden" name="action" value="supplier_stock_receive"><input type="hidden" name="return" value="stock"><input type="hidden" name="return_section" value="incoming"><input type="hidden" name="supplier_item_id" value="<?=$incomingItem['id']?>"><p>Allocate up to <strong><?=$remaining?></strong> units received.</p><div class="supplier-allocation-grid"><label>Jay<input name="jay_qty" type="number" min="0" max="<?=$remaining?>" value="0"></label><label>Tony<input name="tony_qty" type="number" min="0" max="<?=$remaining?>" value="0"></label><label>Holding<input name="unallocated_qty" type="number" min="0" max="<?=$remaining?>" value="<?=$remaining?>"></label></div><button>Receive stock</button></form></details><?php endif;?>
  <?php endforeach;?></div>
  <?php if((int)$supplierOrder['received_qty']===0):?><form method="post" class="supplier-cancel-form" onsubmit="return confirm('Cancel this incoming supplier order?');"><?php csrf();?><input type="hidden" name="action" value="supplier_order_cancel"><input type="hidden" name="return" value="stock"><input type="hidden" name="return_section" value="incoming"><input type="hidden" name="supplier_order_id" value="<?=$supplierOrder['id']?>"><button class="quiet">Cancel order</button></form><?php endif;?>
 </div></details><?php endforeach;?></div>
 <?php else:?><div class="incoming-empty"><span>✓</span><div><strong>No supplier stock currently incoming</strong><small>Add an order when you place it with the supplier.</small></div></div><?php endif;?>
</section>
<script>(()=>{document.querySelectorAll('[data-pack-form]').forEach(form=>form.querySelectorAll('[data-pack-qty]').forEach(button=>button.addEventListener('click',()=>{const input=form.querySelector('[data-pack-quantity]');if(input)input.value=button.dataset.packQty})));const form=document.querySelector('[data-supplier-order-form]'),lines=form?.querySelector('[data-supplier-lines]'),add=form?.querySelector('[data-add-supplier-line]');if(form&&lines&&add){const bind=line=>{const remove=line.querySelector('[data-remove-supplier-line]'),select=line.querySelector('select'),cost=line.querySelector('input[name="unit_cost[]"]');remove?.addEventListener('click',()=>{if(lines.children.length>1)line.remove()});select?.addEventListener('change',()=>{const option=select.selectedOptions[0];if(cost&&option?.dataset.cost&&cost.value==='')cost.value=option.dataset.cost})};[...lines.children].forEach(bind);add.addEventListener('click',()=>{const clone=lines.firstElementChild.cloneNode(true);clone.querySelectorAll('input').forEach(input=>{if(input.name==='packs[]')input.value='1';else input.value=''});clone.querySelector('select').value='';lines.appendChild(clone);bind(clone)})}})();</script>
<?php endif;?>
<?php if($stockSection==='overview'):?>
<section class="stock-overview-links" aria-label="More stock tools">
 <a href="?view=stock&amp;section=incoming"><span class="stock-tool-icon">↧</span><div><strong>Incoming stock</strong><small><?=$totalIncomingUnits?> units currently due</small></div><b>→</b></a>
 <a href="?view=stock&amp;section=forecast"><span class="stock-tool-icon">↗</span><div><strong>Potential stock needed</strong><small>Expected recurring demand</small></div><b>→</b></a>
 <a href="?view=stock&amp;section=history"><span class="stock-tool-icon">≡</span><div><strong>Movement history</strong><small>Stock audit trail</small></div><b>→</b></a>
</section>
<section class="stock-products-section"><header class="stock-products-heading"><p class="eyebrow">TRACKED PRODUCTS</p><h2 id="stock-view-title"><?=$stockLowOnly?'Low-stock products':'Combined fridge stock'?></h2></header><div class="stock-filter-bar"><button class="stock-low-filter" type="button" aria-pressed="<?=$stockLowOnly?'true':'false'?>" data-low-stock-filter>Low stock only</button><span class="stock-low-summary" data-low-stock-summary aria-live="polite"></span></div><div class="stock-product-grid" data-fridge-view="combined">
<?php if($trackedProducts):foreach($trackedProducts as $sp):
 $jayAvailable=(int)($sp['stock_jay_qty']??0);$tonyAvailable=(int)($sp['stock_tony_qty']??0);$combinedAvailable=$jayAvailable+$tonyAvailable;
 $jayReserved=(int)($reservedStockByProduct[(int)$sp['id']]['jay']??0);$tonyReserved=(int)($reservedStockByProduct[(int)$sp['id']]['tony']??0);$combinedReserved=$jayReserved+$tonyReserved;
 $jayPhysical=$jayAvailable+$jayReserved;$tonyPhysical=$tonyAvailable+$tonyReserved;$combinedPhysical=$combinedAvailable+$combinedReserved;
 $retailUnit=(int)$sp['price'];$costUnit=$sp['cost']===null?null:(int)$sp['cost'];
 $jayRetail=max(0,$jayAvailable)*$retailUnit;$tonyRetail=max(0,$tonyAvailable)*$retailUnit;$combinedRetail=max(0,$combinedAvailable)*$retailUnit;
 $jayProfit=$costUnit===null?null:max(0,$jayAvailable)*($retailUnit-$costUnit);$tonyProfit=$costUnit===null?null:max(0,$tonyAvailable)*($retailUnit-$costUnit);$combinedProfit=$costUnit===null?null:max(0,$combinedAvailable)*($retailUnit-$costUnit);
$initialStockQty=$stockInitialView==='jay'?$jayAvailable:($stockInitialView==='tony'?$tonyAvailable:$combinedAvailable);$stockCardIsLow=(int)$sp['active']===1 && $initialStockQty<=(int)$sp['low_stock_at'];
?><article class="stock-product-card" id="stock-product-<?=(int)$sp['id']?>" data-stock-active="<?=(int)$sp['active']?>" <?=$stockLowOnly&&!$stockCardIsLow?'hidden':''?> data-jay-qty="<?=$jayAvailable?>" data-tony-qty="<?=$tonyAvailable?>" data-combined-qty="<?=$combinedAvailable?>" data-low-threshold="<?=e((int)($sp['low_stock_at']??2))?>">
<div class="stock-product-title"><span class="product-vial-icon" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="M12 4h8v4l2 2v15a3 3 0 0 1-3 3h-6a3 3 0 0 1-3-3V10l2-2V4Z" stroke="currentColor" stroke-width="1.8"/><path d="M12 14h8M13 7h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M12 20h8" stroke="currentColor" stroke-width="3" opacity=".35"/></svg></span><div><h3><?=e($sp['name'])?></h3><small><?=money($retailUnit)?> each<?=$costUnit===null?' · cost not set':' · '.money($costUnit).' cost'?></small><?php $productIncoming=(int)($incomingByProduct[(int)$sp['id']]??0);if($productIncoming>0):?><em class="incoming-stock-pill">+<?=$productIncoming?> incoming</em><?php endif;?></div></div>
<div class="stock-fridge-totals" aria-label="Physical fridge totals">
 <div class="stock-fridge-total jay"><span>Jay’s fridge</span><strong><?=$jayPhysical?></strong><small>total units</small></div>
 <div class="stock-fridge-total tony"><span>Tony’s fridge</span><strong><?=$tonyPhysical?></strong><small>total units</small></div>
</div>
<details class="stock-count-editor"><summary><span>Edit fridge counts</span><span aria-hidden="true">✎</span></summary>
 <form method="post" class="stock-fridge-count-form"><?php csrf();?><input type="hidden" name="action" value="stock_fridge_counts"><input type="hidden" name="return" value="stock"><input type="hidden" name="product_id" value="<?=(int)$sp['id']?>">
 <input type="hidden" name="expected_jay_physical" value="<?=$jayPhysical?>"><input type="hidden" name="expected_tony_physical" value="<?=$tonyPhysical?>">
 <input type="hidden" name="return_filter" value="<?=$stockLowOnly?'low':''?>"><input type="hidden" name="return_fridge" value="<?=e($stockInitialView)?>">
 <p>Enter the total physically in each fridge, including reserved units.</p>
 <div class="stock-count-inputs"><label>Jay’s fridge<input type="number" inputmode="numeric" name="jay_physical" min="0" max="999999" step="1" value="<?=$jayPhysical?>" required><small><?=$jayReserved?> reserved</small></label><label>Tony’s fridge<input type="number" inputmode="numeric" name="tony_physical" min="0" max="999999" step="1" value="<?=$tonyPhysical?>" required><small><?=$tonyReserved?> reserved</small></label></div>
 <button type="submit">Save fridge counts</button>
 </form>
</details>
<div class="stock-product-counts">
<?php foreach([
 'jay'=>['label'=>'Jay’s fridge','physical'=>$jayPhysical,'reserved'=>$jayReserved,'available'=>$jayAvailable,'retail'=>$jayRetail,'profit'=>$jayProfit],
 'tony'=>['label'=>'Tony’s fridge','physical'=>$tonyPhysical,'reserved'=>$tonyReserved,'available'=>$tonyAvailable,'retail'=>$tonyRetail,'profit'=>$tonyProfit],
 'combined'=>['label'=>'Combined','physical'=>$combinedPhysical,'reserved'=>$combinedReserved,'available'=>$combinedAvailable,'retail'=>$combinedRetail,'profit'=>$combinedProfit]
] as $stockKey=>$stockView):$stockReservations=array_values(array_filter($reservationDetailsByProduct[(int)$sp['id']]??[],fn($reservation)=>$stockKey==='combined'||strtolower((string)$reservation['location'])===$stockKey));$reservationDialogId='reserved-'.(int)$sp['id'].'-'.$stockKey;?>
<div class="<?=$stockKey==='combined'?'stock-product-combined ':''?>stock-availability-panel" data-stock-count="<?=$stockKey?>" <?=$stockKey!==$stockInitialView?'hidden':''?>>
 <div class="stock-availability-three"><span><small>Physical</small><b><?=$stockView['physical']?></b></span><?php if($stockView['reserved']>0):?><button type="button" class="stock-reserved-trigger" data-reservation-open="<?=e($reservationDialogId)?>"><small>Reserved</small><b class="has-reserved"><?=$stockView['reserved']?></b><em>Tap to view</em></button><?php else:?><span><small>Reserved</small><b>0</b></span><?php endif;?><span class="available"><small>Available</small><b><?=max(0,$stockView['available'])?></b></span></div>
 <?php if($stockView['available']<0):?><p class="stock-card-shortage">! <?=abs($stockView['available'])?> unit<?=abs($stockView['available'])===1?'':'s'?> short for reserved orders</p><?php endif;?>
 <div class="stock-value-lines"><small><b>Retail value available</b><?=money($stockView['retail'])?></small><small><b>Potential profit</b><?=$stockView['profit']===null?'—':money($stockView['profit'])?></small></div>
 <?php if($stockReservations):?><dialog class="stock-reservation-dialog" id="<?=e($reservationDialogId)?>"><div class="reservation-sheet"><header><div><p class="eyebrow">RESERVED STOCK</p><h3><?=e($sp['name'])?></h3><small><?=$stockView['reserved']?> unit<?=$stockView['reserved']===1?'':'s'?> reserved · <?=e($stockView['label'])?></small></div><button type="button" class="reservation-close" data-reservation-close aria-label="Close">×</button></header><div class="reservation-order-list"><?php foreach($stockReservations as $reservation):?><article><div class="reservation-order-icon">#</div><div class="reservation-order-copy"><strong><?=e($reservation['customer'])?></strong><span>ANK-<?=str_pad((string)$reservation['order_id'],4,'0',STR_PAD_LEFT)?> · <?=e($reservation['status'])?></span><small><?=e($reservation['location']==='Unallocated'?'Holding / unallocated':$reservation['location'].'’s fridge')?><?php if(trim($reservation['assigned_to'])!==''):?> · Assigned to <?=e($reservation['assigned_to'])?><?php endif;?></small></div><b><?=$reservation['quantity']?> unit<?=$reservation['quantity']===1?'':'s'?></b></article><?php endforeach;?></div><footer><span>Reserved stock is already removed from Available.</span><button type="button" data-reservation-close>Done</button></footer></div></dialog><?php endif;?>
</div>
<?php endforeach;?>
</div></article>
<?php endforeach;else:?><div class="panel stock-empty-state"><span class="product-vial-icon" aria-hidden="true">＋</span><strong>No tracked products yet</strong><p>Open a product in Products and add a physical count for Jay’s or Tony’s fridge.</p><a class="quick-action" href="?view=products">Set up stock →</a></div><?php endif;?></div></section>
<script>(()=>{
 const grid=document.querySelector('.stock-product-grid'),buttons=[...document.querySelectorAll('.stock-location-filter')],title=document.querySelector('#stock-view-title'),lowButton=document.querySelector('[data-low-stock-filter]'),lowSummary=document.querySelector('[data-low-stock-summary]');
 if(!grid||!buttons.length)return;
 const names={combined:'Combined fridge stock',jay:'Jay’s fridge',tony:'Tony’s fridge'},params=new URLSearchParams(location.search);
 let view=['combined','jay','tony'].includes(params.get('fridge'))?params.get('fridge'):'combined',lowOnly=params.get('filter')==='low';
 const cards=[...grid.querySelectorAll('.stock-product-card')],originalCards=[...cards];
 function refresh(){
  grid.dataset.fridgeView=view;if(title)title.textContent=lowOnly?'Low stock · '+names[view]:names[view];
  buttons.forEach(button=>button.setAttribute('aria-pressed',button.dataset.stockView===view?'true':'false'));
  grid.querySelectorAll('[data-stock-count]').forEach(count=>{count.hidden=count.dataset.stockCount!==view});
  let lowCount=0;
  cards.forEach(card=>{const quantity=Number(card.dataset[view+'Qty']||0),threshold=Number(card.dataset.lowThreshold||0),isLow=card.dataset.stockActive==='1' && quantity<=threshold;if(isLow)lowCount++;card.hidden=lowOnly&&!isLow});
  const ordered=lowOnly?[...cards].sort((a,b)=>Number(a.dataset[view+'Qty']||0)-Number(b.dataset[view+'Qty']||0)||a.querySelector('h3').textContent.localeCompare(b.querySelector('h3').textContent)):originalCards;
  ordered.forEach(card=>grid.appendChild(card));
  if(lowButton)lowButton.setAttribute('aria-pressed',lowOnly?'true':'false');
  if(lowSummary)lowSummary.textContent=lowCount?lowCount+' product'+(lowCount===1?'':'s')+' at or below alert level':'No products at or below alert level';
  grid.querySelectorAll('input[name="return_filter"]').forEach(input=>{input.value=lowOnly?'low':''});
  grid.querySelectorAll('input[name="return_fridge"]').forEach(input=>{input.value=view});
 }
 function keepFilters(){const url=new URL(location.href);if(lowOnly)url.searchParams.set('filter','low');else url.searchParams.delete('filter');url.searchParams.set('fridge',view);history.replaceState(null,'',url);}
 buttons.forEach(button=>button.addEventListener('click',()=>{view=button.dataset.stockView;refresh();keepFilters()}));
 if(lowButton)lowButton.addEventListener('click',()=>{lowOnly=!lowOnly;refresh();keepFilters()});
 refresh();
 if(location.hash.startsWith('#stock-product-')){const target=document.getElementById(location.hash.slice(1));if(target&&!target.hidden)requestAnimationFrame(()=>target.scrollIntoView({block:'start'}));}
})();</script>
<?php endif;?>
<?php if($stockSection==='forecast'):?>
<div class="stock-subpage-heading"><a href="?view=stock">← Stock overview</a><div><p class="eyebrow">STOCK PLANNING</p><h2>Potential stock needed</h2><p>Available + incoming stock compared with expected recurring demand.</p></div></div>
<?php
$stockForecastRange=forecastDateRange($retaToday,(string)($_GET['forecast']??'30'),(string)($_GET['from']??''),(string)($_GET['to']??''),[7,14,30,60,90]);
$stockExpectedForecast=retaProjectionRange($retaForecastCycles,$stockForecastRange['start'],$stockForecastRange['end'],(bool)$stockForecastRange['include_overdue']);$stockExpectedRows=[];$stockExpectedRiskCount=0;
foreach(($stockExpectedForecast['stock']??[]) as $expectedName=>$expectedQty){if(strtolower(trim((string)$expectedName))==='pen')continue;$expectedProduct=$productStockMap[strtolower(trim((string)$expectedName))]??null;$expectedAvailable=$expectedProduct&&(int)($expectedProduct['stock_tracking']??0)===1?(int)($expectedProduct['stock_qty']??0):null;$expectedIncoming=$expectedProduct?(int)($incomingByProduct[(int)$expectedProduct['id']]??0):0;$projected=$expectedAvailable===null?null:$expectedAvailable+$expectedIncoming-(int)$expectedQty;$risk=$projected!==null&&$projected<0;if($risk)$stockExpectedRiskCount++;$stockExpectedRows[]=['name'=>$expectedName,'expected'=>(int)$expectedQty,'available'=>$expectedAvailable,'incoming'=>$expectedIncoming,'projected'=>$projected,'risk'=>$risk];}
usort($stockExpectedRows,fn($a,$b)=>(($b['risk']?1:0)<=>($a['risk']?1:0))?:($b['expected']<=>$a['expected']));
?>
<section class="panel stock-demand-forecast forecast-v2">
 <div class="forecast-hero">
  <div><p class="eyebrow">EXPECTED RECURRING DEMAND</p><h2>Will we have enough stock?</h2><p>See what could be left if every expected recurring order in this period happens.</p></div>
  <div class="forecast-health <?=$stockExpectedRiskCount?'warning':'clear'?>"><span><?=$stockExpectedRiskCount?'!':'✓'?></span><div><strong><?=$stockExpectedRiskCount?$stockExpectedRiskCount.' potential shortage'.($stockExpectedRiskCount===1?'':'s'):'Expected demand covered'?></strong><small><?=$stockExpectedRiskCount?'Worth reviewing before the next supplier order':'Based on stock recorded today'?></small></div></div>
 </div>
 <div class="forecast-range-shell">
  <div class="stock-demand-tabs stock-demand-tabs-flexible"><?php foreach([7,14,30,60,90] as $forecastDays):?><a href="?view=stock&amp;section=forecast&amp;forecast=<?=$forecastDays?>" class="<?=$stockForecastRange['mode']===(string)$forecastDays?'selected':''?>"><?=$forecastDays?> days</a><?php endforeach;?><button type="button" class="<?=$stockForecastRange['mode']==='custom'?'selected':''?>" data-stock-custom-toggle>Custom</button></div>
  <form method="get" class="stock-custom-range <?=$stockForecastRange['mode']==='custom'?'open':''?>" data-stock-custom-form><input type="hidden" name="view" value="stock"><input type="hidden" name="section" value="forecast"><input type="hidden" name="forecast" value="custom"><label>From<input type="date" name="from" value="<?=e($stockForecastRange['mode']==='custom'?$stockForecastRange['start']->format('Y-m-d'):$retaToday->format('Y-m-d'))?>" required></label><label>To<input type="date" name="to" value="<?=e($stockForecastRange['mode']==='custom'?$stockForecastRange['end']->format('Y-m-d'):$retaToday->modify('+29 days')->format('Y-m-d'))?>" required></label><button>Apply dates</button></form>
  <div class="forecast-period"><div><small>FORECAST PERIOD</small><strong><?=e($stockForecastRange['start']->format('d M'))?> <i>→</i> <?=e($stockForecastRange['end']->format('d M Y'))?></strong></div><div><small>POTENTIAL ORDERS</small><strong><?=(int)$stockExpectedForecast['orders']?></strong></div></div>
 </div>
 <?php if($stockExpectedRows):?><div class="forecast-product-list">
 <?php foreach($stockExpectedRows as $expectedRow):$supply=$expectedRow['available']===null?null:(int)$expectedRow['available']+(int)$expectedRow['incoming'];$coverage=$supply===null?0:($expectedRow['expected']>0?min(100,(int)round(($supply/$expectedRow['expected'])*100)):100);$left=$expectedRow['projected'];?>
  <article class="forecast-product-card <?=$expectedRow['risk']?'risk':'covered'?>" style="--coverage:<?=$coverage?>%">
   <header><div><h3><?=e($expectedRow['name'])?></h3><p><?=$expectedRow['expected']?> unit<?=$expectedRow['expected']===1?'':'s'?> could be needed</p></div><span class="forecast-state"><?=$expectedRow['risk']?'Potential shortage':'Enough stock ✓'?></span></header>
   <div class="forecast-flow">
    <div><small>Available now</small><strong><?=$expectedRow['available']===null?'—':$expectedRow['available']?></strong></div><i>+</i>
    <div class="incoming"><small>Coming in</small><strong><?=$expectedRow['incoming']?></strong></div><i>−</i>
    <div><small>Potentially needed</small><strong><?=$expectedRow['expected']?></strong></div><i>=</i>
    <div class="result"><small><?=$expectedRow['risk']?'Potential shortage':'Estimated left'?></small><strong><?=$left===null?'—':abs((int)$left)?></strong></div>
   </div>
   <div class="forecast-meter"><span></span></div>
   <p class="forecast-explanation"><?php if($expectedRow['available']===null):?>Stock is not being tracked for this product yet.<?php elseif($expectedRow['risk']):?>You could be <strong><?=abs((int)$left)?> unit<?=abs((int)$left)===1?'':'s'?> short</strong> if all expected recurring orders happen.<?php else:?>You could have <strong><?=(int)$left?> unit<?=(int)$left===1?'':'s'?> left</strong> after covering all expected recurring orders in this period.<?php endif;?></p>
  </article>
 <?php endforeach;?></div>
 <?php else:?><div class="stock-demand-empty forecast-empty"><span>✓</span><div><strong>No recurring demand in this range</strong><small>There are no expected recurring orders to plan stock for during these dates.</small></div></div><?php endif;?>
 <div class="forecast-footnote"><span><b>How it works</b> Available now + Coming in − Potentially needed = Estimated stock left.</span><a href="?view=orders&amp;section=recurring">Manage recurring orders →</a></div>
</section>
<script>(()=>{const button=document.querySelector('[data-stock-custom-toggle]'),form=document.querySelector('[data-stock-custom-form]');if(button&&form)button.addEventListener('click',()=>form.classList.toggle('open'))})();</script>
<?php endif;?>
<?php if($stockSection==='history'):?>
<div class="stock-subpage-heading"><a href="?view=stock">← Stock overview</a><div><p class="eyebrow">STOCK AUDIT</p><h2>Movement history</h2><p>Every addition, transfer, correction, reservation and release in one place.</p></div></div>
<section class="panel stock-history"><div class="dashboard-panel-head"><div><p class="eyebrow">MOVEMENT HISTORY</p><h2>Recent stock changes</h2></div></div>
<?php if($recentStockMovements):?><div class="stock-movement-list"><?php foreach($recentStockMovements as $move):
 $moveLabel=match($move['movement_type']){'opening'=>'Opening count','received'=>'Stock added','manual_add'=>'Quick stock added','manual_remove'=>'Quick stock removed','supplier_received'=>'Supplier stock received','count_adjustment'=>'Count correction','transfer_out'=>'Stock transferred out','transfer_in'=>'Stock transferred in','order_reserved'=>'Order reserved','order_release'=>'Reservation released','order_delivery'=>'Delivered order','order_edit'=>'Order allocation edited',default=>'Stock movement'};
 $delta=(int)$move['quantity_change'];$moveLocation=stockLocationName((string)($move['location']??'Unallocated'));$moveLocationLabel=$moveLocation==='Unallocated'?'Unallocated':$moveLocation.'’s fridge';?>
<article class="stock-movement-row"><div><strong><?=e($move['product_name'])?></strong><small><?=e(date('d M Y H:i',strtotime($move['created'])))?> · <?=e($moveLabel)?> · <?=e($moveLocationLabel)?><?php if($move['supplier']!==''):?> · <?=e($move['supplier'])?><?php endif;?><?php if($move['batch_reference']!==''):?> · Batch <?=e($move['batch_reference'])?><?php endif;?><?php if($move['expiry_date']!==''):?> · Exp <?=e($move['expiry_date'])?><?php endif;?><?php if($move['note']!==''):?> · <?=e($move['note'])?><?php endif;?></small></div><b class="<?=$delta<0?'stock-negative':'stock-positive'?>"><?=$delta>0?'+':''?><?=$delta?></b></article>
<?php endforeach;?></div><?php else:?><p class="muted">No stock movements yet. Count each fridge to start.</p><?php endif;?>
</section>
<?php endif;?>
<?php elseif($view==='fridge_jay'||$view==='fridge_tony'):
 $fridgePerson=$view==='fridge_jay'?'Jay':'Tony';$otherPerson=$fridgePerson==='Jay'?'Tony':'Jay';$fridgeKey=strtolower($fridgePerson);$fridgeColumn=stockLocationColumn($fridgePerson);$personAvailable=(int)($fridgeTotals[$fridgeKey]??0);$personReserved=(int)($fridgeReservedTotals[$fridgeKey]??0);$personPhysical=$personAvailable+$personReserved;$fridgeProducts=array_values(array_filter($stockProducts,fn($p)=>(int)($p['stock_tracking']??0)===1));$untrackedFridgeProducts=array_values(array_filter($stockProducts,fn($p)=>(int)($p['stock_tracking']??0)!==1&&(int)($p['active']??0)===1));$personMovements=array_slice($stockMovementsByLocation[$fridgePerson]??[],0,30);?>
<div class="heading fridge-page-heading"><div><a class="fridge-back-link" href="?view=stock">Back to Stock control</a><p class="eyebrow"><?=$fridgePerson==='Jay'?'JAY’S INVENTORY':'TONY’S INVENTORY'?></p><h1><?=$fridgePerson?>’s fridge</h1><p class="muted page-description">Balances, transfers and a complete recent audit for this fridge.</p></div><a class="quick-action" href="?view=fridge_<?=strtolower($otherPerson)?>">View <?=e($otherPerson)?>’s fridge</a></div>
<div class="fridge-person-hero fridge-person-<?=strtolower($fridgePerson)?>"><div class="fridge-hero-main"><span>PHYSICAL STOCK</span><strong><?=max(0,$personPhysical)?></strong><small>units physically in this fridge</small></div><div class="fridge-hero-breakdown"><span><b><?=$personReserved?></b>Reserved</span><span><b><?=max(0,$personAvailable)?></b>Available</span></div><p>Saved orders are already included in Physical but removed from Available.</p></div>
<div class="fridge-page-actions"><a href="?view=stock" class="button">+ Add stock</a><a href="?view=stock" class="quick-action">Move between fridges</a></div>
<div class="fridge-screen-list fridge-balance-list">
<?php foreach($fridgeProducts as $sp):$availableRaw=(int)($sp[$fridgeColumn]??0);$reserved=(int)($reservedStockByProduct[(int)$sp['id']][$fridgeKey]??0);$physicalRaw=$availableRaw+$reserved;$shortfall=max(0,-$availableRaw);$available=max(0,$availableRaw);$physical=max(0,$physicalRaw);$otherColumn=stockLocationColumn($otherPerson);$otherAvailable=(int)($sp[$otherColumn]??0);?>
<article class="fridge-item-card fridge-balance-card">
 <div class="fridge-item-head"><div><h2><?=e($sp['name'])?></h2><small><?=money($sp['price'])?> retail<?=(int)($incomingByProduct[(int)$sp['id']]??0)>0?' · +'.(int)$incomingByProduct[(int)$sp['id']].' incoming':''?></small></div><div class="fridge-item-quantity"><strong><?=$physical?></strong><span>physical</span></div></div>
 <?php $fridgeReservations=array_values(array_filter($reservationDetailsByProduct[(int)$sp['id']]??[],fn($reservation)=>strtolower((string)$reservation['location'])===$fridgeKey));$fridgeReservationDialogId='fridge-reserved-'.(int)$sp['id'].'-'.$fridgeKey;?>
 <div class="fridge-balance-three"><span><small>Physical</small><b><?=$physical?></b></span><?php if($reserved>0):?><button type="button" class="stock-reserved-trigger" data-reservation-open="<?=e($fridgeReservationDialogId)?>"><small>Reserved</small><b class="has-reserved"><?=$reserved?></b><em>Tap to view</em></button><?php else:?><span><small>Reserved</small><b>0</b></span><?php endif;?><span class="available"><small>Available</small><b><?=$available?></b></span></div>
 <?php if($fridgeReservations):?><dialog class="stock-reservation-dialog" id="<?=e($fridgeReservationDialogId)?>"><div class="reservation-sheet"><header><div><p class="eyebrow">RESERVED STOCK</p><h3><?=e($sp['name'])?></h3><small><?=$reserved?> unit<?=$reserved===1?'':'s'?> reserved from <?=e($fridgePerson)?>’s fridge</small></div><button type="button" class="reservation-close" data-reservation-close aria-label="Close">×</button></header><div class="reservation-order-list"><?php foreach($fridgeReservations as $reservation):?><article><div class="reservation-order-icon">#</div><div class="reservation-order-copy"><strong><?=e($reservation['customer'])?></strong><span>ANK-<?=str_pad((string)$reservation['order_id'],4,'0',STR_PAD_LEFT)?> · <?=e($reservation['status'])?></span><small><?=e($reservation['location'])?>’s fridge<?php if(trim($reservation['assigned_to'])!==''):?> · Assigned to <?=e($reservation['assigned_to'])?><?php endif;?></small></div><b><?=$reservation['quantity']?> unit<?=$reservation['quantity']===1?'':'s'?></b></article><?php endforeach;?></div><footer><span>This stock is committed to these orders.</span><button type="button" data-reservation-close>Done</button></footer></div></dialog><?php endif;?>
 <?php if($shortfall>0):?><div class="fridge-stock-shortfall"><span>!</span><div><strong><?=$shortfall?> unit<?=$shortfall===1?'':'s'?> short</strong><small>More units are reserved on orders than physically in this fridge. Add stock to cover the missing units or correct the physical count if it is wrong.</small></div></div><?php endif;?>
 <form method="post" class="fridge-quick-adjust"><?php csrf();?><input type="hidden" name="action" value="stock_quick_adjust"><input type="hidden" name="return" value="<?=$view?>"><input type="hidden" name="product_id" value="<?=$sp['id']?>"><input type="hidden" name="location" value="<?=e($fridgePerson)?>"><span>Quick stock</span><div><button type="submit" name="delta" value="-1" <?=$available<1?'disabled':''?>>−1</button><button type="submit" name="delta" value="1" class="add">+1</button><button type="submit" name="delta" value="10" class="pack">+10</button></div></form>
 <details class="fridge-compact-actions"><summary>Transfer or correct count</summary><div class="fridge-compact-action-grid">
  <form method="post" class="fridge-mini-form" data-pack-form><?php csrf();?><input type="hidden" name="action" value="stock_transfer"><input type="hidden" name="return" value="<?=$view?>"><input type="hidden" name="product_id" value="<?=$sp['id']?>"><input type="hidden" name="from_location" value="<?=e($fridgePerson)?>"><h3>Move from <?=e($fridgePerson)?></h3><label>To<select name="to_location"><option value="<?=e($otherPerson)?>"><?=e($otherPerson)?>’s fridge</option><option value="Unallocated">Holding stock</option></select></label><div class="stock-pack-picker compact"><span>Quick</span><div><button type="button" data-pack-qty="10">10</button><button type="button" data-pack-qty="20">20</button></div></div><label>Quantity<input name="quantity" type="number" min="1" max="<?=$available?>" value="<?=min(10,max(1,$available))?>" data-pack-quantity <?=$available<1?'disabled':''?>></label><button <?=$available<1?'disabled':''?>>Move stock</button>
  </form>
  <form method="post" class="fridge-mini-form"><?php csrf();?><input type="hidden" name="action" value="stock_count"><input type="hidden" name="return" value="<?=$view?>"><input type="hidden" name="product_id" value="<?=$sp['id']?>"><input type="hidden" name="location" value="<?=e($fridgePerson)?>"><h3>Correct physical count</h3><p>Only use this after physically counting the fridge.</p><label>Physical count<input name="counted_qty" type="number" min="0" max="999999" value="<?=$physical?>" required></label><label>Reason<input name="note" maxlength="500" placeholder="Count correction"></label><button class="quiet">Save correction</button>
  </form>
 </div></details>
</article>
<?php endforeach;?>
<?php if(!$fridgeProducts):?><div class="panel fridge-empty-state"><h2>No stock counted yet</h2><p>Use Stock Control to add your first stock.</p></div><?php endif;?>
</div>
<?php if($untrackedFridgeProducts):?><details class="fridge-add-stock"><summary>Initial physical count for a new product</summary><form method="post" class="fridge-item-form"><?php csrf();?><input type="hidden" name="action" value="stock_open"><input type="hidden" name="return" value="<?=$view?>"><input type="hidden" name="location" value="<?=e($fridgePerson)?>"><label>Product<select name="product_id" required><option value="">Choose product</option><?php foreach($untrackedFridgeProducts as $untracked):?><option value="<?=$untracked['id']?>"><?=e($untracked['name'])?></option><?php endforeach;?></select></label><label>Physical count<input name="opening_qty" type="number" min="0" max="999999" step="1" required value="0"></label><label class="stock-confirm"><input type="checkbox" name="confirm_opening" value="1" required> I’ve physically counted this fridge</label><button>Save opening count</button></form></details><?php endif;?>
<section class="panel fridge-audit"><div class="dashboard-panel-head"><div><p class="eyebrow">AUDIT TRAIL</p><h2><?=e($fridgePerson)?>’s recent stock movements</h2></div><a href="?view=stock">All movements →</a></div>
<?php if($personMovements):?><div class="stock-movement-list"><?php foreach($personMovements as $move):$moveLabel=match($move['movement_type']){'opening'=>'Opening count','received'=>'Stock added','manual_add'=>'Quick stock added','manual_remove'=>'Quick stock removed','supplier_received'=>'Supplier stock received','count_adjustment'=>'Count correction','transfer_out'=>'Moved out','transfer_in'=>'Moved in','order_reserved'=>'Reserved for order','order_release'=>'Reservation released','order_delivery'=>'Delivered order','order_edit'=>'Order allocation edited',default=>'Stock movement'};$delta=(int)$move['quantity_change'];?>
<article class="stock-movement-row"><div><strong><?=e($move['product_name'])?></strong><small><?=e(date('d M Y H:i',strtotime($move['created'])))?> · <?=e($moveLabel)?><?php if($move['supplier']!==''):?> · <?=e($move['supplier'])?><?php endif;?><?php if($move['note']!==''):?> · <?=e($move['note'])?><?php endif;?></small></div><b class="<?=$delta<0?'stock-negative':'stock-positive'?>"><?=$delta>0?'+':''?><?=$delta?></b></article><?php endforeach;?></div>
<?php else:?><p class="muted">No stock movements recorded for this fridge yet.</p><?php endif;?></section>
<script>(()=>{document.querySelectorAll('[data-pack-form]').forEach(form=>form.querySelectorAll('[data-pack-qty]').forEach(button=>button.addEventListener('click',()=>{const input=form.querySelector('[data-pack-quantity]');if(input&&!input.disabled)input.value=Math.min(Number(button.dataset.packQty),Number(input.max||999999))})))})();</script>
<?php elseif($view==='sheets'):?>
<div class="heading"><div><h1>Google Sheets</h1><p class="muted page-description">Order backup and reporting connection.</p></div></div>
<section class="panel">
<h2>ANKH Admin Orders</h2>
<p><a class="button" target="_blank" rel="noopener" href="https://docs.google.com/spreadsheets/d/<?=e($sheetId)?>/edit">Open Google Sheet ↗</a></p>
<div class="line"><span>Connection</span><strong><?=$sheetWebhook?'Webhook saved':'Not connected yet'?></strong></div>
<?php if($sheetLastSync):?><div class="line"><span>Last successful sync</span><strong><?=e(date('d M Y H:i',strtotime($sheetLastSync)))?></strong></div><?php endif;?>
<?php if($sheetLastError):?><p class="error">Last sync problem: <?=e($sheetLastError)?></p><?php endif;?>
<form method="post"><?php csrf();?><input type="hidden" name="action" value="sheets_settings"><input type="hidden" name="return" value="sheets"><label>Google Apps Script Web App URL<input type="url" name="webhook" value="<?=e($sheetWebhook)?>" placeholder="https://script.google.com/macros/s/.../exec" autocomplete="off"></label><button>Save Google connection</button></form>
<?php if($sheetWebhook):?><form method="post" style="margin-top:14px"><?php csrf();?><input type="hidden" name="action" value="sheets_sync_all"><button>Sync all existing orders</button></form><?php endif;?>
</section>
<section class="panel">
<h2>One-time Google setup</h2>
<p class="muted">This only needs doing once. The webhook is protected by a private shared secret, so the Sheet does not need to be publicly editable.</p>
<p>1. Open the Google Sheet above, then choose <strong>Extensions → Apps Script</strong>.</p>
<p>2. Copy the contents of <strong>google-sheets-webhook.gs</strong> from the ANKH Admin GitHub repo into Apps Script.</p>
<label>Spreadsheet ID<input value="<?=e($sheetId)?>" readonly onclick="this.select()"></label>
<label>Private shared secret<input value="<?=e($sheetSecret)?>" readonly onclick="this.select()"></label>
<p>3. In the script, replace <strong>PASTE_SECRET_FROM_ANKH_ADMIN</strong> with the private shared secret above.</p>
<p>4. Choose <strong>Deploy → New deployment → Web app</strong>, execute as yourself, allow access to anyone, then copy the URL ending in <strong>/exec</strong> into the box above.</p>
</section>
<?php elseif($view==='customers'):?>
<?php
$customerSpendByKey=[];$customerOutstandingByKey=[];$customerRecurringKeys=[];
foreach($storedCustomers as $rankCustomer){$rankKey=strtolower(trim((string)$rankCustomer['name'])).'|'.trim((string)$rankCustomer['phone']);$rankHistory=$customerOrderHistory[$rankKey]??[];$customerSpendByKey[$rankKey]=array_sum(array_map(fn($o)=>in_array($o['status'],$paidStatuses,true)?(int)$o['total']:0,$rankHistory));}
arsort($customerSpendByKey);$customerTopSpend=array_filter($customerSpendByKey,fn($spent)=>(int)$spent>0);$customerTopKeys=array_fill_keys(array_slice(array_keys($customerTopSpend),0,min(10,count($customerTopSpend))),true);
foreach($balanceAccounts as $balanceAccount)$customerOutstandingByKey[(string)$balanceAccount['key']]=(int)$balanceAccount['outstanding'];
foreach($retaCyclesActive as $customerCycle){$cycleKey=strtolower(trim((string)$customerCycle['customer_name'])).'|'.trim((string)$customerCycle['phone']);$customerRecurringKeys[$cycleKey]=true;}
$inactiveCustomerCount=0;foreach($storedCustomers as $inactiveCustomer){$inactiveKey=strtolower(trim((string)$inactiveCustomer['name'])).'|'.trim((string)$inactiveCustomer['phone']);$inactiveHistory=$customerOrderHistory[$inactiveKey]??[];$inactiveLast=$inactiveHistory[0]??null;if($inactiveLast && strtotime((string)$inactiveLast['created'])<strtotime('-60 days'))$inactiveCustomerCount++;}
?>
<div class="heading customer-heading"><div><h1>Customers</h1><p class="muted page-description">Search, contact or start another order.</p></div><div class="customer-heading-actions"><a class="quick-action customer-balances-link" href="?view=balances">Balances<?php if($balancesTotalOutstanding>0):?> · <?=money($balancesTotalOutstanding)?><?php endif;?></a><button type="button" id="show-add-customer" class="customer-plus" aria-label="Add customer" title="Add customer" aria-expanded="false" aria-controls="add-customer-panel">+</button></div></div>
<div class="customer-page-filters"><label>Search customers<input id="customer-page-search" type="search" placeholder="Name, phone, address or product"></label><label>Show<select id="customer-status-filter"><option value="active">Active</option><option value="archived">Archived</option><option value="all">All</option></select></label></div>
<div class="customer-intelligence-filters" role="group" aria-label="Customer intelligence filters"><button type="button" class="selected" data-customer-insight="all">All</button><button type="button" data-customer-insight="top">Top customers</button><button type="button" data-customer-insight="inactive">Inactive 60+ days <b><?=$inactiveCustomerCount?></b></button><button type="button" data-customer-insight="outstanding">Outstanding balance <b><?=count($customerOutstandingByKey)?></b></button><button type="button" data-customer-insight="recurring">Recurring <b><?=count($customerRecurringKeys)?></b></button></div>
<p class="ux-result-count" id="ux-customer-visible" role="status" aria-live="polite"></p>
<form method="post" class="panel customer-form" id="add-customer-panel" hidden><?php csrf();?><input type="hidden" name="action" value="customer"><input type="hidden" name="return" value="customers"><div class="customer-form-title"><h2>Add customer</h2><button type="button" class="quiet customer-form-close" data-close-customer-form>Cancel</button></div><div class="two"><label>Name<input name="name" maxlength="160" required autocomplete="name" placeholder="Customer name"></label><label>Phone<input name="phone" maxlength="40" type="tel" autocomplete="tel" placeholder="Phone number"></label></div><label>Address<textarea name="address" maxlength="2000" autocomplete="street-address" placeholder="Delivery address"></textarea></label><?php customerPricingSelect();?><button>Save customer</button></form>
<div id="customer-list" class="customer-intelligence-grid">
<?php foreach($storedCustomers as $c):
 $customerKey=strtolower(trim((string)$c['name'])).'|'.trim((string)$c['phone']);$history=$customerOrderHistory[$customerKey]??[];
 $customerSpent=array_sum(array_map(fn($o)=>in_array($o['status'],$paidStatuses,true)?(int)$o['total']:0,$history));
 $lastOrder=$history[0]??null;$usualProducts=array_slice($customerProductCounts[$customerKey]??[],0,3);
 $customerOrderDates=array_values(array_filter(array_map(fn($o)=>(string)$o['status']==='Cancelled'?null:strtotime((string)$o['created']),$history)));$intervals=[];for($ci=1;$ci<count($customerOrderDates);$ci++)$intervals[]=abs($customerOrderDates[$ci-1]-$customerOrderDates[$ci])/86400;$avgOrderDays=$intervals?(int)round(array_sum($intervals)/count($intervals)):null;
 $isTop=!empty($customerTopKeys[$customerKey]);$isInactive=$lastOrder&&strtotime((string)$lastOrder['created'])<strtotime('-60 days');$hasOutstanding=(int)($customerOutstandingByKey[$customerKey]??0)>0;$hasRecurring=!empty($customerRecurringKeys[$customerKey]);
 $searchBits=$c['name'].' '.$c['phone'].' '.$c['address'].' '.implode(' ',array_column($usualProducts,'name'));
?>
<details class="order customer-card<?=$c['archived']?' archived-customer':''?>" data-customer-search="<?=e(strtolower($searchBits))?>" data-customer-archived="<?=$c['archived']?'1':'0'?>" data-customer-top="<?=$isTop?'1':'0'?>" data-customer-inactive="<?=$isInactive?'1':'0'?>" data-customer-outstanding="<?=$hasOutstanding?'1':'0'?>" data-customer-recurring="<?=$hasRecurring?'1':'0'?>">
<summary><div><div class="customer-name-line"><h2><?=e($c['name'])?></h2><?php if($c['archived']):?><span class="badge customer-archived-badge">Archived</span><?php endif;?><?php if($c['pricing_type']==='family_friends'):?><span class="badge">Family &amp; Friends</span><?php elseif($c['pricing_type']==='free'):?><span class="badge">Free</span><?php endif;?></div><span class="muted"><?=e($c['phone']?:'No phone saved')?></span></div><div class="customer-card-summary"><strong><?=money($customerSpent)?></strong><small><?=count($history)?> <?=count($history)===1?'order':'orders'?><?php if($lastOrder):?> · <?=e(date('d M y',strtotime($lastOrder['created'])))?><?php endif;?></small></div></summary>
<div class="detail">
<div class="customer-metrics"><div><span>Spent</span><strong><?=money($customerSpent)?></strong></div><div><span>Orders</span><strong><?=count($history)?></strong></div><div><span>Last order</span><strong><?=$lastOrder?e(date('d M y',strtotime($lastOrder['created']))):'—'?></strong></div><div><span>Avg. gap</span><strong><?=$avgOrderDays!==null?$avgOrderDays.'d':'—'?></strong></div></div>
<?php if($isTop||$isInactive||$hasOutstanding||$hasRecurring):?><div class="customer-intelligence-badges"><?php if($isTop):?><span>TOP CUSTOMER</span><?php endif;?><?php if($hasRecurring):?><span class="cycle">RECURRING</span><?php endif;?><?php if($hasOutstanding):?><span class="money"><?=money((int)$customerOutstandingByKey[$customerKey])?> DUE</span><?php endif;?><?php if($isInactive):?><span>INACTIVE 60+D</span><?php endif;?></div><?php endif;?>
<?php if($c['address']):?><p class="address"><?=nl2br(e($c['address']))?></p><?php endif;?>
<div class="customer-quick-actions"><a class="quick-action customer-account-link" href="?view=customer&amp;id=<?=$c['id']?>">Open account</a><?php if(!$c['archived']):?><a class="quick-action" href="?view=new&amp;customer_id=<?=$c['id']?>">+ New order</a><?php if($lastOrder):?><a class="quick-action" href="?view=new&amp;repeat_order=<?=$lastOrder['id']?>">Repeat last</a><?php endif;?><?php endif;?><?php if(trim((string)$c['phone'])!==''):?><a class="quick-action" href="tel:<?=e(preg_replace('/[^0-9+]/','',(string)$c['phone']))?>">Call</a><?php endif;?><?php if(trim((string)$c['address'])!==''):?><button type="button" class="quick-action quiet" data-copy-text="<?=e($c['address'])?>">Copy address</button><?php endif;?></div>
<?php if($usualProducts):?><div class="usual-products"><span class="order-check-label">Usual products</span><div class="product-chips"><?php foreach($usualProducts as $usual):?><span><?=e($usual['name'])?> <b>×<?=$usual['qty']?></b></span><?php endforeach;?></div></div><?php endif;?>
<div class="customer-actions"><button type="button" class="quiet edit-customer-button" data-edit-customer="<?=$c['id']?>" aria-expanded="false">Edit customer</button></div>
<form method="post" class="customer-edit-form" data-customer-form="<?=$c['id']?>" hidden><?php csrf();?><input type="hidden" name="action" value="customer"><input type="hidden" name="return" value="customers"><input type="hidden" name="id" value="<?=$c['id']?>"><div class="two"><label>Name<input name="name" maxlength="160" required value="<?=e($c['name'])?>"></label><label>Phone<input name="phone" maxlength="40" type="tel" value="<?=e($c['phone'])?>"></label></div><label>Address<textarea name="address" maxlength="2000"><?=e($c['address'])?></textarea></label><?php customerPricingSelect((string)$c['pricing_type']);?><div class="customer-edit-actions"><button>Save changes</button><button type="button" class="quiet" data-cancel-customer-edit="<?=$c['id']?>">Cancel</button></div></form>
<form method="post" class="customer-archive-form" onsubmit="return confirm('<?=$c['archived']?'Restore this customer?':'Archive this customer? Their order history will be kept.'?>')"><?php csrf();?><input type="hidden" name="action" value="customer_archive"><input type="hidden" name="return" value="customers"><input type="hidden" name="id" value="<?=$c['id']?>"><input type="hidden" name="archive" value="<?=$c['archived']?'0':'1'?>"><button class="quiet"><?=$c['archived']?'Restore customer':'Archive customer'?></button></form>
<?php if($history):?><div class="customer-history"><span class="order-check-label">Order history</span><?php foreach($history as $o):?><div class="line customer-history-line"><span><b>ANK-<?=$o['id']?></b> · <?=e(date('d M y',strtotime($o['created'])))?> · <?=e($o['status'])?></span><strong><?=money($o['total'])?></strong></div><?php endforeach;?></div><?php endif;?>
</div></details>
<?php endforeach;?>
</div>
<p id="customer-page-empty" class="empty" hidden>No customers match that search.</p>
<?php if(!$storedCustomers):?><p class="empty">No customers yet. Tap + to add your first one.</p><?php endif;?>

<?php elseif($view==='balances'):?>
<div class="heading balances-heading"><div><p class="eyebrow">MONEY OWED</p><h1>Customer balances</h1><p class="muted page-description">Outstanding customer balances and payment history.</p></div><a class="quick-action" href="?view=customers">Customers →</a></div>
<section class="balances-hero">
 <div><span>Total outstanding</span><strong><?=money($balancesTotalOutstanding)?></strong><small><?=count($balanceAccounts)?> customer<?=count($balanceAccounts)===1?'':'s'?> currently owe money</small></div>
 <div><span>Paid towards open orders</span><strong><?=money($balancesTotalPaid)?></strong><small>Partial payments already received</small></div>
 <div><span>Delivered &amp; still due</span><strong><?=$balancesOverdueCount?></strong><small>Shown as overdue below</small></div>
</section>
<div class="balance-filter-bar" role="group" aria-label="Filter customer balances">
 <button type="button" class="selected" data-balance-filter="all">All <b><?=count($balanceAccounts)?></b></button>
 <button type="button" data-balance-filter="unpaid">Unpaid <b><?=count(array_filter($balanceAccounts,fn($a)=>$a['unpaid']))?></b></button>
 <button type="button" data-balance-filter="partial">Part-paid <b><?=count(array_filter($balanceAccounts,fn($a)=>$a['part_paid']))?></b></button>
 <button type="button" data-balance-filter="overdue">Overdue <b><?=$balancesOverdueCount?></b></button>
</div>
<p class="balance-filter-note">Overdue means the order has been marked delivered but still has a balance outstanding.</p>
<div class="balance-account-list" id="balance-account-list">
<?php foreach($balanceAccounts as $account):$oldest=$account['oldest'];?>
<details class="balance-account-card" data-balance-unpaid="<?=$account['unpaid']?'1':'0'?>" data-balance-partial="<?=$account['part_paid']?'1':'0'?>" data-balance-overdue="<?=$account['overdue']?'1':'0'?>">
 <summary>
  <div class="balance-customer-main"><h2><?=e($account['customer'])?></h2><span><?=$account['open_orders']?> open balance<?=$account['open_orders']===1?'':'s'?> · <?=$account['total_orders']?> total order<?=$account['total_orders']===1?'':'s'?></span><?php if($oldest):?><small>Oldest unpaid: ANK-<?=str_pad((string)$oldest['id'],4,'0',STR_PAD_LEFT)?> · <?=e(date('d M Y',strtotime($oldest['created'])))?></small><?php endif;?></div>
  <div class="balance-customer-money"><?php if($account['paid']>0):?><span><b><?=money($account['paid'])?></b><small>paid</small></span><?php endif;?><strong><?=money($account['outstanding'])?><small>due</small></strong><i>⌄</i></div>
 </summary>
 <div class="balance-account-body">
  <div class="balance-account-actions"><?php if($account['customer_id']):?><a class="quick-action" href="?view=customer&amp;id=<?=$account['customer_id']?>">Open customer</a><?php endif;?><?php if(trim($account['phone'])!==''):?><a class="quick-action" href="tel:<?=e(preg_replace('/[^0-9+]/','',$account['phone']))?>">Call</a><?php endif;?></div>
  <div class="balance-ledger">
  <?php foreach($account['orders'] as $bo):$boPaid=(int)($bo['paid_amount']??0);$boDue=(int)$bo['balance_due'];$boOverdue=!empty($bo['delivery_date']);?>
   <article class="balance-order<?=$boOverdue?' overdue':''?>">
    <header><div><span>ANK-<?=str_pad((string)$bo['id'],4,'0',STR_PAD_LEFT)?></span><small><?=e(date('d M Y',strtotime($bo['created'])))?><?=$boOverdue?' · Delivered':''?></small></div><?php if($boOverdue):?><b class="balance-overdue-pill">OVERDUE</b><?php endif;?></header>
    <div class="balance-order-flow"><span><small>Order</small><b><?=money($bo['total'])?></b></span><i>→</i><span><small>Paid</small><b><?=money($boPaid)?></b></span><i>→</i><span class="balance-order-due"><small>Remaining</small><b><?=money($boDue)?></b></span></div>
    <?php if($bo['payments']):?><div class="balance-payment-history"><?php foreach($bo['payments'] as $bp):?><div><span><b><?=money($bp['amount'])?></b><small><?=e(date('d M Y',strtotime($bp['created'])))?> · <?=e($bp['method']?:'Method not recorded')?></small></span><?php if($bp['note']):?><em><?=e($bp['note'])?></em><?php endif;?></div><?php endforeach;?></div><?php else:?><p class="balance-no-payments">No payments recorded yet.</p><?php endif;?>
   </article>
  <?php endforeach;?>
  </div>
 </div>
</details>
<?php endforeach;?>
</div>
<p class="empty" id="balance-filter-empty" <?=count($balanceAccounts)?'hidden':''?>>No outstanding customer balances.</p>
<script>
(()=>{const buttons=[...document.querySelectorAll('[data-balance-filter]')],cards=[...document.querySelectorAll('.balance-account-card')],empty=document.getElementById('balance-filter-empty');if(!buttons.length)return;const apply=type=>{let visible=0;cards.forEach(card=>{const show=type==='all'||card.dataset['balance'+type.charAt(0).toUpperCase()+type.slice(1)]==='1';card.hidden=!show;if(show)visible++;});buttons.forEach(button=>{const on=button.dataset.balanceFilter===type;button.classList.toggle('selected',on);button.setAttribute('aria-pressed',on?'true':'false');});if(empty){empty.hidden=visible>0;empty.textContent=visible?'':'No customers match this balance filter.';}};buttons.forEach(button=>button.addEventListener('click',()=>apply(button.dataset.balanceFilter)));})();
</script>

<?php elseif($view==='customer'):?>
<?php if(!$customerAccount):?>
<div class="heading"><div><h1>Customer account</h1></div><a class="quick-action" href="?view=customers">← Customers</a></div><p class="error">That customer could not be found.</p>
<?php else:
 $accountKey=strtolower(trim((string)$customerAccount['name'])).'|'.trim((string)$customerAccount['phone']);$accountHistory=$customerOrderHistory[$accountKey]??[];
 $accountPaid=array_values(array_filter($accountHistory,fn($o)=>in_array($o['status'],$paidStatuses,true)));$accountPaidCount=count($accountPaid);
 $accountSpend=array_sum(array_map(fn($o)=>(int)$o['total'],$accountPaid));$accountAverage=$accountPaidCount?intdiv($accountSpend,$accountPaidCount):0;
 $accountOutstanding=array_sum(array_map(fn($o)=>$o['status']==='Cancelled'?0:max(0,(int)$o['total']-(int)($o['paid_amount']??0)),$accountHistory));
 $accountProfit=0;foreach($accountPaid as $accountOrder){$oid=(int)$accountOrder['id'];$accountProfit+=(int)$accountOrder['total']-(int)($orderCostById[$oid]??0)-(int)($accountOrder['postage_cost']??0);}
 $accountLast=$accountHistory[0]??null;$accountFavourites=array_slice($customerProductCounts[$accountKey]??[],0,5);$accountTopProduct=$accountFavourites[0]??null;
 $accountCycles=array_values(array_filter($retaCyclesActive,fn($cycle)=>strtolower(trim((string)$cycle['customer_name']))===strtolower(trim((string)$customerAccount['name'])) && trim((string)$cycle['phone'])===trim((string)$customerAccount['phone'])));
 $accountOrderDates=array_values(array_filter(array_map(fn($o)=>(string)$o['status']==='Cancelled'?null:strtotime((string)$o['created']),$accountHistory)));$accountIntervals=[];for($ai=1;$ai<count($accountOrderDates);$ai++)$accountIntervals[]=abs($accountOrderDates[$ai-1]-$accountOrderDates[$ai])/86400;$accountAvgDays=$accountIntervals?(int)round(array_sum($accountIntervals)/count($accountIntervals)):null;
 usort($accountCycles,fn($a,$b)=>strcmp((string)$a['next_due_date'],(string)$b['next_due_date']));$accountNextCycle=$accountCycles[0]??null;
 $accountLikelyDate='';$accountLikelyDetail='No repeat pattern yet';
 if($accountNextCycle){$accountLikelyDate=(string)$accountNextCycle['next_due_date'];$accountLikelyDetail='Recurring · '.(string)($accountNextCycle['product_name']?:$accountNextCycle['product_summary']);}
 elseif($accountLast&&$accountAvgDays!==null&&$accountAvgDays>0){$lastTs=strtotime((string)$accountLast['created']);if($lastTs){$likelyTs=strtotime('+'.$accountAvgDays.' days',$lastTs);$guard=0;while($likelyTs<strtotime($todayDate)&&$guard++<24)$likelyTs=strtotime('+'.$accountAvgDays.' days',$likelyTs);$accountLikelyDate=date('Y-m-d',$likelyTs);$accountLikelyDetail='Estimated from '.$accountAvgDays.'-day average'.($accountTopProduct?' · '.(string)$accountTopProduct['name']:'');}}
?>
<div class="heading customer-account-heading"><div><p class="eyebrow">CUSTOMER ACCOUNT</p><h1><?=e($customerAccount['name'])?></h1><p class="muted">Pricing: <?=e(['standard'=>'Standard','family_friends'=>'Family & Friends','free'=>'Free'][(string)$customerAccount['pricing_type']]??'Standard')?> · <?=e($customerAccount['phone']?:'No phone saved')?><?php if($customerAccount['archived']):?> · Archived<?php endif;?></p></div><a class="quick-action" href="?view=customers">← Customers</a></div>
<div class="customer-account-actions"><?php if(!$customerAccount['archived']):?><a class="button" href="?view=new&amp;customer_id=<?=$customerAccount['id']?>">+ New order</a><?php if($accountLast):?><a class="quick-action" href="?view=new&amp;repeat_order=<?=$accountLast['id']?>">Repeat last order</a><?php endif;?><?php endif;?><?php if(trim((string)$customerAccount['phone'])!==''):?><a class="quick-action" href="tel:<?=e(preg_replace('/[^0-9+]/','',(string)$customerAccount['phone']))?>">Call</a><?php endif;?></div>
<section class="panel"><form method="post"><?php csrf();?><input type="hidden" name="action" value="customer_pricing"><input type="hidden" name="return" value="customer"><input type="hidden" name="customer_id" value="<?=(int)$customerAccount['id']?>"><?php customerPricingOptions((string)$customerAccount['pricing_type']);?><button>Save customer pricing</button></form><a class="quick-action" href="?view=reports&amp;type=personal">Personal stock use report</a></section>
<div class="customer-account-stats">
 <article><span>Lifetime spend</span><strong><?=money($accountSpend)?></strong><small><?=$accountPaidCount?> completed order<?=$accountPaidCount===1?'':'s'?></small></article>
 <article><span>Gross profit</span><strong><?=money($accountProfit)?></strong><small><?=array_filter($accountPaid,fn($o)=>!empty($orderMissingCost[(int)$o['id']]))?'Estimate · some product costs are missing':'Saved product costs & postage deducted'?></small></article>
 <article class="<?=$accountOutstanding>0?'attention':''?>"><span>Outstanding</span><strong><?=money($accountOutstanding)?></strong><small>Current unpaid balance</small></article>
 <article><span>Average order</span><strong><?=money($accountAverage)?></strong><small>Completed orders</small></article>
 <article><span>Total orders</span><strong><?=count($accountHistory)?></strong><small>Including open orders</small></article>
 <article><span>Last order</span><strong><?=$accountLast?e(date('d M y',strtotime($accountLast['created']))):'—'?></strong><small><?=$accountLast?'ANK-'.str_pad((string)$accountLast['id'],4,'0',STR_PAD_LEFT):'No orders yet'?></small></article>
 <article><span>Average repeat gap</span><strong><?=$accountAvgDays!==null?$accountAvgDays.' days':'—'?></strong><small>Between non-cancelled orders</small></article>
 <article><span>Most purchased</span><strong><?=$accountTopProduct?e((string)$accountTopProduct['name']):'—'?></strong><small><?=$accountTopProduct?'×'.(int)$accountTopProduct['qty'].' units ordered':'No product history yet'?></small></article>
 <article><span>Next likely order</span><strong><?=$accountLikelyDate?e(date('d M y',strtotime($accountLikelyDate))):'—'?></strong><small><?=e($accountLikelyDetail)?></small></article>
</div>
<div class="customer-account-grid">
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">CONTACT</p><h2>Customer details</h2></div></div><?php if($customerAccount['address']):?><p class="address"><?=nl2br(e($customerAccount['address']))?></p><?php else:?><p class="muted">No address saved.</p><?php endif;?><div class="customer-quick-actions"><?php if($customerAccount['address']):?><button type="button" class="quick-action quiet" data-copy-text="<?=e($customerAccount['address'])?>">Copy address</button><?php endif;?></div></section>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">FAVOURITES</p><h2>Most purchased products</h2></div></div><?php if($accountFavourites):foreach($accountFavourites as $fav):?><div class="dashboard-row"><span><?=e($fav['name'])?></span><strong>×<?=$fav['qty']?></strong></div><?php endforeach;else:?><p class="muted">No product history yet.</p><?php endif;?></section>
</div>
<?php if($accountCycles):?><section class="panel customer-cycle-panel">
<div class="cycle-customer-head"><div><p class="eyebrow">RECURRING</p><h2>Recurring peptides</h2><p>Manage this customer’s expected repeat orders.</p></div><a class="cycle-preview-link" href="?view=orders&amp;section=recurring">Recurring →</a></div>
<div class="customer-cycle-list">
<?php foreach($accountCycles as $cycle):$cycleDue=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycle['next_due_date'],$tz);$cycleDays=$cycleDue?(int)$retaToday->diff($cycleDue)->format('%r%a'):0;?>
<article class="customer-cycle-card">
 <div class="customer-cycle-main"><div class="cycle-product-icon">↻</div><div><span class="cycle-active-pill">ACTIVE</span><h3><?=e($cycle['product_name']?:$cycle['product_summary'])?></h3><p><?=e($cycle['presentation']?:'Peptide')?> · every <?=max(1,(int)($cycle['cycle_weeks']??4))?> weeks</p><small>Ordered by <?=e($cycle['customer_name'])?> · For <?=e(cycleRecipientName($cycle))?></small></div></div>
 <div class="customer-cycle-due"><span>Next expected</span><strong><?=e(date('D d M',strtotime($cycle['next_due_date'])))?></strong><small><?=$cycleDays<0?abs($cycleDays).' days overdue':($cycleDays===0?'Due today':$cycleDays.' days away')?></small></div>
 <details class="customer-cycle-edit"><summary>Amend cycle</summary><form method="post"><?php csrf();?><input type="hidden" name="action" value="cycle_amend"><input type="hidden" name="cycle_id" value="<?=$cycle['id']?>"><input type="hidden" name="return" value="customer"><input type="hidden" name="customer_id" value="<?=$customerAccount['id']?>"><label>Next expected date<input type="date" name="next_due_date" value="<?=e($cycle['next_due_date'])?>" required></label><label>Repeat every<div class="cycle-weeks-field"><input type="number" name="cycle_weeks" min="1" max="52" value="<?=max(1,(int)($cycle['cycle_weeks']??4))?>" required><span>weeks</span></div></label><button>Save changes</button></form></details>
 <form method="post" class="customer-cycle-cancel" onsubmit="return confirm('Cancel this recurring <?=e(addslashes((string)($cycle['product_name']?:'peptide')))?> cycle?');"><?php csrf();?><input type="hidden" name="action" value="reta_cycle_stop"><input type="hidden" name="cycle_id" value="<?=$cycle['id']?>"><input type="hidden" name="reason" value="Cancelled from customer account"><input type="hidden" name="return" value="customer"><input type="hidden" name="customer_id" value="<?=$customerAccount['id']?>"><button type="submit">Cancel recurring</button></form>
</article>
<?php endforeach;?></div></section><?php endif;?>
<section class="panel customer-account-orders"><div class="dashboard-panel-head"><div><p class="eyebrow">HISTORY</p><h2>Orders</h2><p class="muted">Every order for this customer, newest first.</p></div></div>
<?php if($accountHistory):foreach($accountHistory as $ao):?><div class="customer-account-order">
 <div><a href="?view=edit&amp;id=<?=$ao['id']?>" class="ref">ANK-<?=str_pad((string)$ao['id'],4,'0',STR_PAD_LEFT)?></a><strong><?=e(date('d M Y',strtotime($ao['created'])))?></strong><small><?=e($ao['status'])?><?php if($ao['payment_method']):?> · <?=e($ao['payment_method'])?><?php endif;?></small></div>
 <div><strong><?=money($ao['total'])?></strong><?php $aoBalance=max(0,(int)$ao['total']-(int)($ao['paid_amount']??0));?><small><?=$aoBalance>0?'Balance '.money($aoBalance):'Paid in full'?></small><span class="badge status-<?=e(statusClass($ao['status']))?>"><?=e($ao['status'])?></span></div>
 <div class="customer-account-order-actions"><a class="quick-action" href="?view=new&amp;repeat_order=<?=$ao['id']?>">Repeat</a><a class="quick-action" href="?api=order-pdf&amp;id=<?=$ao['id']?>&amp;type=receipt">Receipt</a></div>
</div><?php endforeach;else:?><p class="muted">No orders yet.</p><?php endif;?>
</section>
<?php endif;?>

<?php elseif($view==='reta'):?>
<div class="heading reta-heading"><div><p class="eyebrow">CYCLE PLANNER</p><h1>Upcoming cycles</h1><p class="muted page-description">Customers who may be ready for their next peptide order.</p></div><a class="quick-action" href="?view=dashboard">← Dashboard</a></div>

<div class="cycle-horizon-tabs"><?php foreach([4,5,8,12] as $weeks):?><a href="?view=reta&amp;weeks=<?=$weeks?>" class="<?=$cycleHorizonWeeks===$weeks?'selected':''?>"><?=$weeks?> weeks</a><?php endforeach;?></div>

<div class="reta-forecast-grid cycle-forecast-grid">
 <article><span>Active cycles</span><strong><?=count($retaCyclesActive)?></strong><small><?=count($retaOverdue)?> currently overdue</small></article>
 <article><span>Expected repeats</span><strong><?=$cycleForecast['orders']?></strong><small>Next <?=$cycleHorizonWeeks?> weeks</small></article>
 <article><span>Projected sales</span><strong><?=money($cycleForecast['revenue'])?></strong><small>Forecast only</small></article>
 <article><span>Projected profit / loss</span><strong><?=money($cycleForecast['profit'])?></strong><small>Based on saved product costs</small></article>
</div>
<?php if($cycleForecast['cost_missing']):?><p class="error report-warning"><?=$cycleForecast['cost_missing']?> projected occurrence<?=$cycleForecast['cost_missing']===1?' has':'s have'?> missing supplier cost data, so projected profit may be overstated.</p><?php endif;?>

<div class="cycle-planner-grid">
<section class="panel cycle-calendar-panel"><div class="dashboard-panel-head"><div><p class="eyebrow">CALENDAR</p><h2>Expected orders · next <?=$cycleHorizonWeeks?> weeks</h2><p class="muted">A delivery rolls that product’s next expected date forward by its chosen cycle.</p></div></div>
<?php if($cycleOccurrences):
 $currentWeek='';
 foreach($cycleOccurrences as $occ):
  $date=(string)$occ['occurrence_date'];$dateObj=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$tz);$weekStartLabel=$dateObj?$dateObj->modify('monday this week')->format('Y-m-d'):$date;
  if($weekStartLabel!==$currentWeek):$currentWeek=$weekStartLabel;?>
  <div class="cycle-week-heading"><span>Week of <?=e(date('d M',strtotime($currentWeek)))?></span></div>
 <?php endif;
  $daysAway=$dateObj?(int)$retaToday->diff($dateObj)->format('%r%a'):0;$dueClass=$daysAway<0?'overdue':($daysAway<=7?'soon':'future');?>
 <article class="reta-cycle-card cycle-calendar-card <?=$dueClass?>">
  <div class="reta-cycle-top"><div><span class="reta-due-badge"><?=$daysAway<0?'OVERDUE':($daysAway===0?'DUE TODAY':e(strtoupper(date('D d M',strtotime($date)))))?></span><h3><?=e(cycleRecipientName($occ))?></h3><p><?=e($occ['product_summary'])?></p><small>Ordered by <?=e($occ['customer_name'])?> · For <?=e(cycleRecipientName($occ))?></small></div><div class="reta-cycle-money"><strong><?=money($occ['expected_value'])?></strong><small><?=money($occ['expected_profit'])?> projected profit<?=$occ['cost_missing']?' · cost missing':''?></small></div></div>
  <div class="reta-cycle-meta"><span><b>Last delivered</b><?=e($occ['last_delivery_date']?date('d M Y',strtotime($occ['last_delivery_date'])):'—')?></span><span><b>Expected</b><?=e(date('d M Y',strtotime($date)))?></span><span><b>Cycle</b>Every <?=max(1,(int)($occ['cycle_weeks']??4))?> weeks</span></div>
  <div class="reta-cycle-actions"><?php if((int)$occ['last_order_id']>0):?><a class="quick-action" href="?view=new&amp;repeat_order=<?=$occ['last_order_id']?>">Create repeat order</a><a class="quick-action" href="?view=edit&amp;id=<?=$occ['last_order_id']?>">Edit cycle</a><?php endif;?></div>
 </article>
 <?php endforeach;?>
<?php else:?><p class="empty">Nothing is currently forecast in the next <?=$cycleHorizonWeeks?> weeks.</p><?php endif;?>
</section>

<section class="panel cycle-stock-panel"><div class="dashboard-panel-head"><div><p class="eyebrow">STOCK FORECAST</p><h2>What you may need</h2><p class="muted">Expected units required for the selected <?=$cycleHorizonWeeks?>-week window.</p></div></div>
<?php if($cycleStockRows):?><div class="cycle-stock-list"><?php foreach($cycleStockRows as $stock):?>
<div class="cycle-stock-row<?=($stock['shortfall']??0)>0?' short':''?>"><div><strong><?=e($stock['name'])?></strong><small><?=$stock['current']===null?'Stock not being tracked':'Current stock '.$stock['current']?></small></div><div><span>Need <?=$stock['needed']?></span><?php if($stock['shortfall']!==null):?><b><?=$stock['shortfall']>0?'Short '.$stock['shortfall']:'Covered'?></b><?php endif;?></div></div>
<?php endforeach;?></div><?php else:?><p class="muted">No recurring stock demand in this window yet.</p><?php endif;?>
</section>
</div>

<section class="panel reta-cycle-panel"><div class="dashboard-panel-head"><div><p class="eyebrow">ACTIVE CYCLES</p><h2>Customers & products</h2><p class="muted">Retatrutide defaults to 4 weeks. Any other product can be switched on and given its own cycle from the order screen.</p></div></div>
<?php if($retaCyclesActive):?><div class="reta-cycle-list"><?php foreach($retaCyclesActive as $cycle):
 $dueObj=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycle['next_due_date'],$tz);$daysAway=$dueObj?(int)$retaToday->diff($dueObj)->format('%r%a'):0;$dueClass=$daysAway<0?'overdue':($daysAway<=7?'soon':'future');?>
<article class="reta-cycle-card <?=$dueClass?>">
 <div class="reta-cycle-top"><div><span class="reta-due-badge"><?=$daysAway<0?'OVERDUE '.abs($daysAway).' DAYS':($daysAway===0?'DUE TODAY':($daysAway===1?'DUE TOMORROW':'NEXT '.strtoupper(e(date('d M',strtotime($cycle['next_due_date']))))))?></span><h3><?=e(cycleRecipientName($cycle))?></h3><p><?=e($cycle['product_summary'])?></p><small>Ordered by <?=e($cycle['customer_name'])?> · For <?=e(cycleRecipientName($cycle))?></small></div><div class="reta-cycle-money"><strong><?=money($cycle['expected_value'])?></strong><small><?=money($cycle['expected_profit'])?> projected profit<?=$cycle['cost_missing']?' · cost missing':''?></small></div></div>
 <div class="reta-cycle-meta"><span><b>Last delivered</b><?=e($cycle['last_delivery_date']?date('d M Y',strtotime($cycle['last_delivery_date'])):'—')?></span><span><b>Next expected</b><?=e(date('d M Y',strtotime($cycle['next_due_date'])))?></span><span><b>Cycle</b>Every <?=max(1,(int)($cycle['cycle_weeks']??4))?> weeks</span></div>
 <div class="reta-cycle-actions"><?php if((int)$cycle['last_order_id']>0):?><a class="quick-action" href="?view=new&amp;repeat_order=<?=$cycle['last_order_id']?>">Create repeat order</a><a class="quick-action" href="?view=edit&amp;id=<?=$cycle['last_order_id']?>">Edit last order</a><?php endif;?>
 <form method="post" onsubmit="return confirm('Stop this recurring cycle for <?=e(addslashes($cycle['customer_name']))?>?');"><?php csrf();?><input type="hidden" name="action" value="reta_cycle_stop"><input type="hidden" name="cycle_id" value="<?=$cycle['id']?>"><input type="hidden" name="reason" value="Recurring cycle stopped manually"><input type="hidden" name="return" value="reta"><button class="quiet danger-button">Stop cycle</button></form></div>
</article>
<?php endforeach;?></div><?php else:?><p class="empty">No active cycles yet. Add a Retatrutide order and it defaults to 4 weeks, or switch on Recurring cycle for another product.</p><?php endif;?>
</section>

<?php if($retaCyclesStopped):?><details class="panel reta-stopped"><summary>Stopped cycles (<?=count($retaCyclesStopped)?>)</summary><div class="reta-cycle-list stopped"><?php foreach($retaCyclesStopped as $cycle):?>
<article class="reta-cycle-card stopped"><div class="reta-cycle-top"><div><h3><?=e(cycleRecipientName($cycle))?></h3><p><?=e($cycle['product_summary']?:$cycle['product_name'])?></p><small>Ordered by <?=e($cycle['customer_name'])?> · For <?=e(cycleRecipientName($cycle))?> · <?=e($cycle['end_reason']?:'Stopped')?></small></div><div class="reta-cycle-money"><strong><?=money($cycle['expected_value'])?></strong></div></div>
<form method="post" class="reta-resume-form"><?php csrf();?><input type="hidden" name="action" value="reta_cycle_resume"><input type="hidden" name="cycle_id" value="<?=$cycle['id']?>"><input type="hidden" name="return" value="reta"><label>Restart next expected date<input type="date" name="next_due_date" value="<?=e($retaToday->modify('+'.max(1,(int)($cycle['cycle_weeks']??4)).' weeks')->format('Y-m-d'))?>" required></label><button class="quiet">Restart cycle</button></form></article>
<?php endforeach;?></div></details><?php endif;?>

<?php elseif(in_array($view,['reports','reorder'],true)):?>
<?php
$reportTitles=['overview'=>'Overview','sales'=>'Sales','profit'=>'Profit','outgoings'=>'Supply outgoings','products'=>'Products','customers'=>'Customers','recurring'=>'Recurring','stock'=>'Stock','personal'=>'Personal stock use'];
$reportRangeLabels=['today'=>'Today','week'=>'This week','month'=>'This month','quarter'=>'This quarter','all'=>'All time','custom'=>'Custom dates','next4w'=>'Next 4 weeks','next8w'=>'Next 8 weeks','next12w'=>'Next 12 weeks'];
$reportWindowLabel=$reportRangeLabels[$reportRange]??'Selected period';
$reportTrendMax=1;foreach($reportTrend as $trendRow)$reportTrendMax=max($reportTrendMax,(int)$trendRow['order_value']);
ksort($reportTrend);
$reportUpcomingPreview=array_slice(cycleOccurrences($retaCyclesActive,$retaToday,8),0,5);
$reportExportParams=['view'=>$view==='reorder'?'reorder':'reports','type'=>$reportType,'range'=>$reportRange,'from'=>$reportFrom,'to'=>$reportTo,'period'=>$reportType==='profit'?$reportPeriodKey:'','export'=>'1'];if($reportType==='reorder')$reportExportParams['demand_days']=$reorderWindowDays;
if($reportType==='productorders'){$reportExportParams['product']=$reportProductName;$reportExportParams['source']=$reportProductSource;}
$reportExportUrl='?'.http_build_query($reportExportParams);
$reportMovementExportUrl='?'.http_build_query(array_merge($reportExportParams,['export'=>'stock-movements']));
?>
<div class="heading"><div><h1><?=$view==='reorder'?'Reorder planner':'Reports'?></h1><p class="muted page-description"><?=$view==='reorder'?'Suggested purchases based on available stock, recent sales and supplier pack sizes.':'Sales, products, customers, recurring orders and confirmed stock.'?></p></div><a class="quick-action" href="?view=more">← More</a></div>
<?php if($view==='reports'):?>
<nav class="report-tabs" aria-label="Report types">
<?php foreach($reportTitles as $tabKey=>$tabTitle):$tabRange=$tabKey==='recurring'?($reportType==='recurring'?$reportRange:'next8w'):(str_starts_with($reportRange,'next')?'month':$reportRange);$tabParams=['view'=>'reports','type'=>$tabKey,'range'=>$tabRange];if($tabKey==='recurring'&&!in_array($tabParams['range'],['next4w','next8w','next12w','custom'],true))$tabParams['range']='next8w';if($reportFrom!=='')$tabParams['from']=$reportFrom;if($reportTo!=='')$tabParams['to']=$reportTo;?>
<a class="<?=$reportType===$tabKey||($reportType==='productorders'&&$tabKey==='products')?'selected':''?>" href="?<?=e(http_build_query($tabParams))?>"><?=$tabTitle?></a><?php endforeach;?>
</nav>
<?php endif;?>
<?php if(!in_array($reportType,['profit','reorder'],true)):?>
<details class="ux-report-options" open><script>
// Set the phone disclosure state during parsing, before its contents can paint.
// Desktop and browsers without JavaScript keep the expanded filter form.
(()=>{const report=document.currentScript.parentElement;if(window.matchMedia&&window.matchMedia('(max-width: 760px)').matches)report.open=false})();
</script><summary><span>Dates, filters &amp; exports</span><span aria-hidden="true">⌄</span></summary>
<form class="report-filter-bar" method="get">
<input type="hidden" name="view" value="reports"><input type="hidden" name="type" value="<?=e($reportType)?>">
<?php if($reportType==='productorders'):?><input type="hidden" name="product" value="<?=e($reportProductName)?>"><input type="hidden" name="source" value="<?=e($reportProductSource)?>"><?php endif;?>
<label>Period<select name="range" id="report-range">
<?php if($reportType==='recurring'):?>
<option value="next4w" <?=$reportRange==='next4w'?'selected':''?>>Next 4 weeks</option><option value="next8w" <?=$reportRange==='next8w'?'selected':''?>>Next 8 weeks</option><option value="next12w" <?=$reportRange==='next12w'?'selected':''?>>Next 12 weeks</option><option value="custom" <?=$reportRange==='custom'?'selected':''?>>Custom dates</option>
<?php else:?>
<option value="today" <?=$reportRange==='today'?'selected':''?>>Today</option><option value="week" <?=$reportRange==='week'?'selected':''?>>This week</option><option value="month" <?=$reportRange==='month'?'selected':''?>>This month</option><option value="quarter" <?=$reportRange==='quarter'?'selected':''?>>This quarter</option><option value="all" <?=$reportRange==='all'?'selected':''?>>All time</option><option value="custom" <?=$reportRange==='custom'?'selected':''?>>Custom dates</option>
<?php endif;?>
</select></label>
<label>From<input type="date" name="from" value="<?=e($reportFrom)?>" onchange="document.getElementById('report-range').value='custom'"></label><label>To<input type="date" name="to" value="<?=e($reportTo)?>" onchange="document.getElementById('report-range').value='custom'"></label><button>Apply</button>
<?php if(!$reportRangeError):?><a class="button quiet report-export" href="<?=e($reportExportUrl)?>">Download CSV</a><?php endif;?><button type="button" class="quiet report-print" onclick="window.print()">Print / Save PDF</button>
</form></details>
<?php if($reportRangeError):?><p class="error report-warning">Choose both dates to run a custom report.</p><?php else:?><p class="report-range-note">Showing <strong><?=e($reportWindowLabel)?></strong><?php if($reportFrom!==''||$reportTo!==''):?> · <?=e($reportFrom?:'Start')?> to <?=e($reportTo?:'Today')?><?php endif;?>. Order value is based on order date; cash received is based on payment date.</p><?php endif;?>
<?php elseif($reportType==='reorder'):?><form class="report-filter-bar reorder-window-filter" method="get"><input type="hidden" name="view" value="reorder"><label>Sales lookback<select name="demand_days"><option value="30" <?=$reorderWindowDays===30?'selected':''?>>Last 30 days</option><option value="60" <?=$reorderWindowDays===60?'selected':''?>>Last 60 days</option><option value="90" <?=$reorderWindowDays===90?'selected':''?>>Last 90 days</option></select></label><button>Update estimate</button><a class="button quiet report-export" href="<?=e($reportExportUrl)?>">Download CSV</a><button type="button" class="quiet report-print" onclick="window.print()">Print / Save PDF</button></form><p class="report-range-note">The target includes your low-stock alert, estimated non-recurring demand during the 7-day supplier wait, and potential repeat orders due in the next 30 days. Current open orders are already reserved out of available stock. Suggested quantities are rounded up to full supplier packs.</p><?php else:?><div class="report-range-note">Profit summaries use the selected profit period below and group sales by payment date. <a href="<?=e($reportExportUrl)?>">Download this period</a></div><?php endif;?>
<div class="report-infographic-actions"><button type="button" id="report-infographic-download">☥&nbsp; Create square infographic</button><span id="report-infographic-status" aria-live="polite"></span></div>
<section class="report-infographic-preview" id="report-infographic-preview" hidden aria-label="Infographic preview">
<?php if(in_array($reportType,['personal','productorders'],true)):?><style>.report-infographic-actions{display:none!important}</style><?php endif;?>
 <div class="report-infographic-preview-head"><div><p class="eyebrow">YOUR ANKH INFOGRAPHIC</p><h2>Ready to share</h2></div><button type="button" class="quiet" id="report-infographic-close">Close</button></div>
 <img id="report-infographic-image" alt="Square ANKH report infographic">
 <div class="report-infographic-preview-actions"><button type="button" id="report-infographic-share">Share / Save image</button><a id="report-infographic-save" class="button quiet" download="ankh-report.png">Save PNG</a></div>
 <p class="muted">On iPhone, use Share / Save, or touch and hold the image to save it to Photos.</p>
</section>
<style>
.report-infographic-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:14px 0 18px}
.report-infographic-actions button,.report-infographic-preview-actions button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:46px;padding:11px 18px;border:1px solid #8c7135;border-radius:12px;background:linear-gradient(135deg,#e4c273,#d4aa53);color:#17140e;font-size:14px;font-weight:800}
.report-infographic-actions span{color:var(--muted);font-size:13px}
.report-infographic-preview{max-width:680px;margin:18px auto 24px;padding:18px;border:1px solid var(--line,#303434);border-radius:22px;background:#171a19}
.report-infographic-preview[hidden]{display:none}
.report-infographic-preview-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.report-infographic-preview-head .eyebrow{margin:0 0 4px;color:#d6b45c}
.report-infographic-preview-head h2{margin:0;font-size:20px}
.report-infographic-preview>img{display:block;width:min(100%,540px);height:auto;margin:0 auto;border-radius:14px;background:#101313}
.report-infographic-preview-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin:14px 0 6px}
.report-infographic-preview>p{text-align:center;font-size:13px}
@media(max-width:700px){.report-infographic-actions{display:grid;grid-template-columns:1fr;margin:12px 0}.report-infographic-actions button{width:100%}.report-infographic-actions span{text-align:center}.report-infographic-preview{padding:12px;border-radius:18px}.report-infographic-preview-actions>*{flex:1 1 100%}}
</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const button=document.getElementById('report-infographic-download'),status=document.getElementById('report-infographic-status');
 const preview=document.getElementById('report-infographic-preview'),image=document.getElementById('report-infographic-image');
 const save=document.getElementById('report-infographic-save'),share=document.getElementById('report-infographic-share');
 const close=document.getElementById('report-infographic-close');
 if(!button||!preview||!image||!save)return;
 let infographicFile=null;
 const clean=value=>(value||'').replace(/\s+/g,' ').trim();
 const textOf=element=>clean(element?.innerText||element?.textContent||'');
 function fitText(ctx,text,x,y,maxWidth,font,color,minSize=13){
  let size=Math.round(parseFloat(font.match(/(\d+(?:\.\d+)?)px/)?.[1]||'18')),weight=font.includes('700')?'700 ':'';
  while(size>minSize){ctx.font=weight+size+'px Arial';if(ctx.measureText(text).width<=maxWidth)break;size--}
  ctx.font=weight+size+'px Arial';ctx.fillStyle=color;let shown=text;
  while(shown.length>3&&ctx.measureText(shown+'…').width>maxWidth)shown=shown.slice(0,-1);
  ctx.fillText(shown.length<text.length?shown+'…':shown,x,y);
 }
 const params=new URLSearchParams(location.search);
 const selectedTab=textOf(document.querySelector('.report-tabs a.selected'));
 const reportTitle=selectedTab||textOf(document.querySelector('main h1'))||'Report';
 let rangeLabel='';
 const rangeSelect=document.querySelector('.report-filter-bar select[name="range"]');
 if(rangeSelect)rangeLabel=textOf(rangeSelect.selectedOptions[0]);
 if(params.has('demand_days'))rangeLabel='Sales lookback · '+params.get('demand_days')+' days';
 if(params.has('period'))rangeLabel=textOf(document.querySelector('.report-period.selected span'))||('Period · '+params.get('period'));
 const from=document.querySelector('.report-filter-bar input[name="from"]')?.value||params.get('from')||'';
 const to=document.querySelector('.report-filter-bar input[name="to"]')?.value||params.get('to')||'';
 if(from||to)rangeLabel=(rangeLabel?rangeLabel+' · ':'')+(from||'Start')+' to '+(to||'Today');
 const metrics=[...document.querySelectorAll('.report-metrics article,.ios-reorder-summary>div,.report-periods .report-period')].map(card=>{
  const label=textOf(card.querySelector(':scope > span'))||textOf(card.querySelector('span'))||'Figure';
  const value=textOf(card.querySelector('strong')).replace(/^Profit\s*/,'').replace(/\s+/g,' ')||'—';
  const note=[...card.children].filter(child=>child.tagName==='SMALL').map(textOf).filter(Boolean).join(' · ');
  return {label,value,note};
 }).filter(metric=>metric.value!=='—');
 const sections=[];
 document.querySelectorAll('.report-table').forEach(table=>{
  const headers=[...table.querySelectorAll('thead th')].map(textOf);
  const rows=[...table.querySelectorAll('tbody tr')].map(row=>[...row.querySelectorAll('td')].map(textOf)).filter(row=>row.length>1&&row.some(Boolean));
  if(rows.length)sections.push({title:textOf(table.closest('.panel,section')?.querySelector('h2'))||'Report details',rows:rows.map(row=>({title:row[0]||'Entry',detail:row.slice(1).map((cell,i)=>(headers[i+1]||('Figure '+(i+2)))+': '+cell).join(' · ')}))});
 });
 const reorderCards=[...document.querySelectorAll('.ios-reorder-card')];
 if(reorderCards.length)sections.push({title:'What needs reordering?',rows:reorderCards.map(card=>({title:textOf(card.querySelector('h3'))||'Product',detail:[...card.querySelectorAll('.ios-order-callout>div,.ios-fridge-row>div,.ios-plan-row>div,.ios-reorder-details>span')].map(textOf).filter(Boolean).join(' · ')}))});
 if(params.get('type')==='overview'){
  [...document.querySelectorAll('.report-overview-grid > .panel')].forEach(panel=>{
   const title=textOf(panel.querySelector('h2'))||'Report detail';
   const rows=[...panel.querySelectorAll('.report-trend-row,.dashboard-row')].map(row=>{
    if(row.classList.contains('report-trend-row')){
     return {title:textOf(row.querySelector(':scope > div:first-child > span'))||'Sales trend',detail:[textOf(row.querySelector(':scope > div:first-child > strong')),textOf(row.querySelector(':scope > small'))].filter(Boolean).join(' · ')};
    }
    const first=row.querySelector(':scope > span');
    const rowTitle=textOf(first?.querySelector('strong'))||[...(first?.childNodes||[])].filter(node=>node.nodeType===Node.TEXT_NODE).map(node=>clean(node.textContent)).filter(Boolean).join(' ')||textOf(first)||'Figure';
    return {title:rowTitle,detail:[...[...(first?.querySelectorAll('small')||[])].map(textOf),textOf(row.querySelector(':scope > strong'))].filter(Boolean).join(' · ')};
   }).filter(row=>row.title||row.detail);
   if(rows.length)sections.push({title,rows});
   else {const empty=textOf(panel.querySelector(':scope > p.muted'));if(empty)sections.push({title,rows:[{title:'Status',detail:empty}]})}
  });
 }else if(!document.querySelector('.report-table')){
  const rows=[...document.querySelectorAll('.report-trend-row,.report-grid .dashboard-row,.report-list-row')].map(row=>textOf(row)).filter(Boolean);
  if(rows.length)sections.push({title:'Report details',rows:rows.map((row,index)=>({title:'Figure '+(index+1),detail:row}))});
 }
 function dataUrlBlob(dataUrl){
  const raw=atob(dataUrl.split(',')[1]),bytes=new Uint8Array(raw.length);
  for(let i=0;i<raw.length;i++)bytes[i]=raw.charCodeAt(i);
  return new Blob([bytes],{type:'image/png'});
 }
 button.addEventListener('click',()=>{
  if(!metrics.length&&!sections.length){status.textContent='There are no report figures to export yet.';return}
  button.disabled=true;status.textContent='Creating your square infographic…';
  try{
   const canvas=document.createElement('canvas');canvas.width=1080;canvas.height=1080;
   const ctx=canvas.getContext('2d');if(!ctx)throw new Error('Canvas is unavailable');
   const pad=56,gap=18,cardW=(1080-pad*2-gap)/2;
   function rounded(x,y,w,h,r,fill,stroke='#343737'){
    ctx.beginPath();ctx.moveTo(x+r,y);ctx.arcTo(x+w,y,x+w,y+h,r);ctx.arcTo(x+w,y+h,x,y+h,r);ctx.arcTo(x,y+h,x,y,r);ctx.arcTo(x,y,x+w,y,r);ctx.closePath();ctx.fillStyle=fill;ctx.fill();ctx.strokeStyle=stroke;ctx.lineWidth=1.5;ctx.stroke();
   }
   const bg=ctx.createLinearGradient(0,0,1080,1080);bg.addColorStop(0,'#101313');bg.addColorStop(1,'#1c1c19');ctx.fillStyle=bg;ctx.fillRect(0,0,1080,1080);
   rounded(22,22,1036,1036,30,'#121514','#8c7135');
   ctx.fillStyle='#dfb666';ctx.font='58px Georgia,serif';ctx.fillText('☥',pad,104);
   ctx.fillStyle='#f1e8d5';ctx.font='45px Georgia,serif';ctx.fillText('A N K H',pad+72,91);
   ctx.fillStyle='#c4a354';ctx.font='700 14px Arial';ctx.fillText('PEPTIDES  /  ORDER DESK',pad+76,119);
   ctx.textAlign='right';fitText(ctx,'BUSINESS SNAPSHOT',1080-pad,74,250,'700 14px Arial','#d6b45c');
   fitText(ctx,new Intl.DateTimeFormat('en-GB',{dateStyle:'long'}).format(new Date()),1080-pad,101,300,'16px Arial','#a4a6a2');ctx.textAlign='left';
   ctx.fillStyle='#d6b45c';ctx.font='700 14px Arial';ctx.fillText(reportTitle.toUpperCase(),pad,178);
   fitText(ctx,reportTitle,pad,222,1080-pad*2,'700 38px Arial','#f0eee8',24);
   fitText(ctx,rangeLabel||'Selected report figures',pad,250,1080-pad*2,'18px Arial','#9b9d9f',14);
   const shownMetrics=metrics.slice(0,4);
   shownMetrics.forEach((metric,i)=>{
    const col=i%2,row=Math.floor(i/2),x=pad+col*(cardW+gap),y=274+row*124;
    rounded(x,y,cardW,108,18,'#1b1e1e');
    fitText(ctx,metric.label.toUpperCase(),x+18,y+29,cardW-36,'700 13px Arial','#9b9d9f',11);
    fitText(ctx,metric.value,x+18,y+70,cardW-36,'700 30px Arial','#e3bd66',19);
    fitText(ctx,metric.note||'',x+18,y+94,cardW-36,'13px Arial','#a7aaa5',11);
   });
   const overview=params.get('type')==='overview';
   const productSection=sections.find(section=>/most purchased|product performance/i.test(section.title));
   const upcomingSection=sections.find(section=>/recurring|coming up/i.test(section.title));
   const stockSection=sections.find(section=>/stock/i.test(section.title));
   const customerSection=sections.find(section=>/customer/i.test(section.title));
   let modules;
   if(overview){
    modules=[productSection||{title:'Most purchased',rows:[]},upcomingSection||{title:'Upcoming recurring orders',rows:[]}];
   }else{
    const prioritized=[...sections].sort((a,b)=>{
     const rank=title=>/product|reorder|stock|recurring|customer|sales|order/i.test(title)?0:1;
     return rank(a.title)-rank(b.title);
    });
    modules=prioritized.slice(0,2);
   }
   function drawModule(section,x,y){
    const w=cardW,h=290,rows=(section?.rows||[]).slice(0,4);
    rounded(x,y,w,h,20,'#191c1c');
    fitText(ctx,(section?.title||'Report details').toUpperCase(),x+18,y+34,w-36,'700 17px Arial','#d6b45c',13);
    if(!rows.length){fitText(ctx,'No figures for this section yet',x+18,y+88,w-36,'16px Arial','#9b9d9f',12);return}
    rows.forEach((row,index)=>{
     const ry=y+58+index*51;
     if(index) {ctx.strokeStyle='#303434';ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(x+18,ry-9);ctx.lineTo(x+w-18,ry-9);ctx.stroke()}
     fitText(ctx,row.title,x+18,ry+12,w-36,'700 15px Arial','#f0eee8',11);
     fitText(ctx,row.detail||'',x+18,ry+33,w-36,'13px Arial','#a7aaa5',10);
    });
    if(section.rows.length>4){ctx.fillStyle='#888b87';ctx.font='12px Arial';ctx.fillText('Top 4 of '+section.rows.length+' entries',x+18,y+h-13)}
   }
   drawModule(modules[0],pad,548);
   drawModule(modules[1],pad+cardW+gap,548);
   const stockTracked=stockSection?.rows?.find(row=>/tracked products/i.test(row.title))?.detail||'—';
   const stockLow=stockSection?.rows?.find(row=>/low stock/i.test(row.title))?.detail||'—';
   const leadCustomer=customerSection?.rows?.[0];
   rounded(pad,858,1080-pad*2,118,18,'#201e18','#765f31');
   const colW=(1080-pad*2)/3;
   const footerFacts=[
    {label:'TRACKED PRODUCTS',value:stockTracked},
    {label:'LOW STOCK',value:stockLow},
    {label:leadCustomer?'TOP CUSTOMER':'REPORT',value:leadCustomer?.title||rangeLabel||reportTitle}
   ];
   footerFacts.forEach((fact,i)=>{
    const x=pad+i*colW;
    if(i){ctx.strokeStyle='#51452c';ctx.beginPath();ctx.moveTo(x,878);ctx.lineTo(x,956);ctx.stroke()}
    fitText(ctx,fact.label,x+18,891,colW-36,'700 12px Arial','#a7aaa5',10);
    fitText(ctx,fact.value,x+18,926,colW-36,'700 20px Arial','#e3bd66',13);
   });
   ctx.fillStyle='#777c77';ctx.font='12px Arial';ctx.textAlign='center';ctx.fillText('ANKH ADMIN  ·  '+(rangeLabel||'REPORT FIGURES'),540,1017);ctx.textAlign='left';
   const slug=reportTitle.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')||'report';
   const filename='ankh-'+slug+'-'+new Date().toISOString().slice(0,10)+'.png';
   const dataUrl=canvas.toDataURL('image/png');
   image.src=dataUrl;image.alt='Square ANKH '+reportTitle+' infographic for '+(rangeLabel||'the selected period');
   save.href=dataUrl;save.download=filename;
   try{infographicFile=new File([dataUrlBlob(dataUrl)],filename,{type:'image/png'})}catch(_){infographicFile=null}
   preview.hidden=false;status.textContent='Square infographic created. Preview it below, then share or save it.';
   button.disabled=false;preview.scrollIntoView({behavior:'smooth',block:'start'});
  }catch(error){console.error(error);status.textContent='Could not create the infographic. Please try again.';button.disabled=false}
 });
 share?.addEventListener('click',async()=>{
  if(infographicFile&&navigator.canShare?.({files:[infographicFile]})&&navigator.share){
   try{await navigator.share({files:[infographicFile],title:'ANKH '+reportTitle+' infographic',text:'ANKH report infographic'})}catch(error){if(error?.name!=='AbortError')status.textContent='Use Save PNG or touch and hold the image to save it.'}
  }else status.textContent='Use Save PNG or touch and hold the preview image to save it to Photos.';
 });
 close?.addEventListener('click',()=>{preview.hidden=true;status.textContent='Infographic preview closed.'});
});</script>
<?php if(!$reportRangeError||$reportType==='profit'):?>
<?php if($reportType==='profit'):?>
<?php $supplyProfitFrom=$reportPeriodKey==='all'?'':date('Y-m-d',$reportPeriods[$reportPeriodKey]['start']);$profitSupplySpend=supplySpending($businessSupplies,$supplyProfitFrom);$allSupplySpend=supplySpending($businessSupplies);?>
<section class="panel"><p class="eyebrow"><?=e($reportPeriods[$reportPeriodKey]['label'])?> · BUSINESS SUPPLIES</p><div class="report-lines"><div><span>Gross profit from sales</span><strong><?=money($reportPeriods[$reportPeriodKey]['profit'])?></strong></div><div><span>Paid supplies, less refunds</span><strong><?=money($profitSupplySpend['net'])?></strong></div><div class="report-profit"><span>After recorded supply spending</span><strong><?=money($reportPeriods[$reportPeriodKey]['profit']-$profitSupplySpend['net'])?></strong></div></div><p class="muted">Supplies use payment and refund dates. This estimate deducts recorded supplies from gross profit; other overheads may still need accounting for.</p><a class="quick-action" href="?view=reports&amp;type=outgoings&amp;range=<?=e($reportPeriodKey)?>">View supply outgoings →</a></section>
<div class="report-periods">
<?php foreach(['today','week','month','all'] as $periodKey):$period=$reportPeriods[$periodKey];?><a class="report-period <?=$reportPeriodKey===$periodKey?'selected':''?>" href="?view=reports&amp;type=profit&amp;period=<?=e($periodKey)?>"><span><?=e($period['label'])?></span><strong><small>Profit</small><?=money($period['profit'])?></strong><small><b>Sales <?=money($period['revenue'])?></b> · <?=$period['orders']?> orders</small></a><?php endforeach;?>
</div>
<section class="panel order-profit-breakdown">
<div class="dashboard-panel-head"><div><p class="eyebrow"><?=e(strtoupper($reportPeriods[$reportPeriodKey]['label']))?></p><h2>Profit by order</h2><p class="muted">Tap an order to see exactly how its profit was calculated.</p></div></div>
<?php if($selectedReportOrders):foreach($selectedReportOrders as $row):?>
<details class="profit-order <?=$row['profit']<0?'loss':''?>">
<summary>
<div><span class="ref">ANK-<?=str_pad((string)$row['id'],4,'0',STR_PAD_LEFT)?></span><h3><?=e($row['customer'])?></h3><small><?=e(date('d M Y',strtotime($row['date'])))?><?php if($row['assigned_to']):?> · <?=e($row['assigned_to'])?><?php endif;?></small></div>
<div class="profit-order-summary"><span>Sales <?=money($row['revenue'])?></span><strong><?=money($row['profit'])?> profit</strong><?php if($row['profit']<0):?><em>LOSS</em><?php elseif($row['missing_cost']):?><em class="warning">COST MISSING</em><?php endif;?></div>
</summary>
<div class="profit-order-detail">
<div><span>Sales</span><strong><?=money($row['revenue'])?></strong></div>
<div><span>Product cost</span><strong>− <?=money($row['product_cost'])?></strong></div>
<div><span>Pen cost</span><strong>− <?=money($row['pen_cost'])?></strong></div>
<div><span>Postage cost</span><strong>− <?=money($row['postage'])?></strong></div>
<div class="profit-order-result"><span>Profit</span><strong><?=money($row['profit'])?></strong></div>
<?php if($row['missing_cost']):?><p class="error">One or more items on this order has no cost recorded, so its profit may be overstated.</p><?php endif;?>
<a class="quick-action" href="?view=edit&amp;id=<?=$row['id']?>">Open order</a>
</div>
</details>
<?php endforeach;else:?><p class="muted">No completed sales in this period.</p><?php endif;?>
</section>

<section class="panel report-breakdown"><div class="dashboard-panel-head"><div><p class="eyebrow">ALL TIME</p><h2>Profit breakdown</h2></div></div>
<div class="report-lines">
<div><span>Sales revenue</span><strong><?=money($reportPeriods['all']['revenue'])?></strong></div>
<div><span>Product cost</span><strong>− <?=money(max(0,$reportPeriods['all']['cogs']-$reportPeriods['all']['pen']))?></strong></div>
<div><span>Pen cost</span><strong>− <?=money($reportPeriods['all']['pen'])?></strong></div>
<div><span>Postage cost</span><strong>− <?=money($reportPeriods['all']['postage'])?></strong></div>

<div class="report-profit"><span>Gross profit</span><strong><?=money($reportPeriods['all']['profit'])?></strong></div>
<div><span>Paid business supplies, less refunds</span><strong><?=money($allSupplySpend['net'])?></strong></div>
<div class="report-profit"><span>After recorded supply spending</span><strong><?=money($reportPeriods['all']['profit']-$allSupplySpend['net'])?></strong></div>
</div>
<?php if($uncostedSales>0):?><p class="error report-warning"><?=money($uncostedSales)?> of sold item revenue still has no saved cost, so profit is overstated until those costs are entered.</p><?php endif;?>
</section>

<div class="report-grid">
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">PAYMENTS</p><h2>Sales by payment method</h2></div></div><?php if($paymentBreakdown):foreach($paymentBreakdown as $method=>$amount):?><div class="dashboard-row"><span><?=e($method)?></span><strong><?=money($amount)?></strong></div><?php endforeach;else:?><p class="muted">Payment methods will appear as paid orders are recorded.</p><?php endif;?></section>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">COST SETTINGS</p><h2>Pen cost</h2></div></div><p class="muted">This is the cost to you for one pen. New pen orders snapshot this cost so historical profit stays unchanged if the price changes later.</p><form method="post"><?php csrf();?><input type="hidden" name="action" value="profit_settings"><input type="hidden" name="return" value="reports"><label>Pen unit cost (£)<input name="pen_cost" type="number" min="0" max="100000" step=".01" inputmode="decimal" value="<?=e($penUnitCost===''?'':number_format((int)$penUnitCost/100,2,'.',''))?>" placeholder="Enter your actual cost"></label><button>Save pen cost</button></form></section>
</div>

<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">PRODUCTS</p><h2>Profit by product</h2><p class="muted">Units, actual selling price, discounts, cost, gross profit and margin. Pen price mix uses the final price recorded on each order.</p></div></div>
<div class="profit-table-wrap"><table class="profit-table expanded-profit-table"><thead><tr><th>Product</th><th>Units</th><th>Avg sold</th><th>Avg discount</th><th>Revenue</th><th>Cost</th><th>Gross profit</th><th>Margin</th></tr></thead><tbody>
<?php foreach($productProfit as $productName=>$pp):
 $ppProfit=$pp['revenue']-$pp['cogs'];$margin=$pp['revenue']>0?($ppProfit/$pp['revenue']*100):0;$avgSold=$pp['units']?intdiv((int)$pp['revenue'],(int)$pp['units']):0;$avgDiscount=$pp['units']?intdiv((int)($pp['discount_total']??0),(int)$pp['units']):0;
 $priceMix=$pp['prices']??[];ksort($priceMix,SORT_NUMERIC);
?><tr><td><strong><?=e($productName)?></strong><?=$pp['missing_cost']?' <small>cost missing</small>':''?><?php if(strtolower(trim((string)$productName))==='pen'&&$priceMix):?><small class="price-mix"><?php $mix=[];foreach($priceMix as $price=>$units)$mix[]=money((int)$price).' × '.$units;echo e(implode(' · ',$mix));?></small><?php endif;?></td><td><?=$pp['units']?></td><td><?=money($avgSold)?></td><td><?=money($avgDiscount)?></td><td><?=money($pp['revenue'])?></td><td><?=money($pp['cogs'])?></td><td><?=money($ppProfit)?></td><td><?=number_format($margin,1)?>%</td></tr><?php endforeach;?>
<?php if(!$productProfit):?><tr><td colspan="8" class="muted">No paid product sales yet.</td></tr><?php endif;?>
</tbody></table></div></section>


<?php elseif($reportType==='personal'):?>
<section class="panel"><h2>Personal stock use</h2><p class="muted">Free orders marked delivered, by delivery date. Open and cancelled orders are excluded. Costs use the order’s saved costs, not today’s prices. These orders are excluded from sales and profit reports. Older £0 orders are not automatically classified as personal use.</p>
<?php $personalTotals=[];$personalCost=0;$personalUnits=0;$personalMissing=0;foreach($personalUsage as $r){$key=(string)$r['customer'];$personalTotals[$key]??=['units'=>0,'cost'=>0,'missing'=>0];$units=(int)$r['quantity'];$cost=((int)$r['cost']+(int)$r['presentation_cost'])*$units;$personalTotals[$key]['units']+=$units;$personalTotals[$key]['cost']+=$cost;$personalUnits+=$units;$personalCost+=$cost;if($r['cost']===null){$personalMissing++;$personalTotals[$key]['missing']++;}}?>
<p><strong><?=$personalUnits?> units · <?=money($personalCost)?> recorded stock cost</strong><?php if($personalMissing):?> · Incomplete: <?=$personalMissing?> lines missing costs<?php endif;?></p>
<?php foreach($personalTotals as $person=>$r):?><div class="line"><span><?=e($person)?> · <?=$r['units']?> units</span><strong><?=money($r['cost'])?><?=$r['missing']?' + missing costs':''?></strong></div><?php endforeach;?>
<?php if(!$personalUsage):?><p class="empty">No delivered personal-use orders in this period.</p><?php endif;?>
<?php foreach($personalUsage as $r):?><article class="panel"><strong><?=e($r['customer'])?> · ANK-<?=(int)$r['id']?></strong><p><?=e($r['delivery_date'].' · '.$r['name'].($r['presentation']?' · '.$r['presentation']:''))?></p><p><?=(int)$r['quantity']?> units · <?=$r['cost']===null?'Cost missing':money(((int)$r['cost']+(int)$r['presentation_cost'])*(int)$r['quantity'])?> stock cost</p></article><?php endforeach;?></section>
<?php elseif($reportType==='outgoings'):?>
<section class="report-metrics"><article><span>Supplies paid</span><strong><?=money($reportSupplies['paid'])?></strong><small>By payment date</small></article><article><span>Refunds received</span><strong><?=money($reportSupplies['refunded'])?></strong><small>By refund date</small></article><article><span>Net supply outgoings</span><strong><?=money($reportSupplies['net'])?></strong><small>Payments less refunds</small></article><article><span>Still to pay</span><strong><?=money($reportSupplies['unpaid'])?></strong><small>Unpaid purchases ordered in this period</small></article></section>
<section class="panel"><div class="heading"><div><p class="eyebrow">BUSINESS SUPPLIES</p><h2>Payments &amp; refunds</h2></div><a class="quick-action" href="?view=supplies">Manage purchases →</a></div><p class="muted">Labels, ink, packaging and other supplies. Amounts include recorded delivery and tax. Sale-stock purchases and other overheads are not included here. Cancelled purchases stay in outgoings until a refund is recorded. Download CSV above for the full breakdown.</p>
<?php foreach($reportSupplies['events'] as $r):?><article class="supply-report-row"><div><a href="?view=supplies&amp;edit=<?=(int)$r['id']?>"><strong><?=e($r['item'])?></strong></a><small><?=e($r['supplier'].' · '.$r['event_date'].' · '.$r['event'])?></small></div><strong><?=money((int)$r['amount'])?></strong></article><?php endforeach;?>
<?php if(!$reportSupplies['events']):?><p class="empty">No supply payments or refunds recorded in this period.</p><?php endif;?></section>
<?php elseif($reportType==='overview'):?>
<section class="panel"><div class="line"><span>Business supply outgoings · net paid in this period</span><strong><?=money($reportSupplies['net'])?></strong></div><a class="quick-action" href="?<?=e(http_build_query(['view'=>'reports','type'=>'outgoings','range'=>$reportRange,'from'=>$reportFrom,'to'=>$reportTo]))?>">Supply spending &amp; export →</a></section>
<section class="report-metrics">
<article><span>Order value</span><strong><?=money($reportOrderValue)?></strong><small><?=$reportOrdersFiltered?count($reportOrdersFiltered):0?> orders placed</small></article>
<article><span>Cash received</span><strong><?=money($reportCashReceived)?></strong><small>Payments dated in this period</small></article>
<article><span>Balance due</span><strong><?=money($reportOutstanding)?></strong><small>On orders placed in this period</small></article>
<article><span>Average order</span><strong><?=money($reportAverageOrder)?></strong><small><?=count($reportOrdersFiltered)?> non-cancelled orders</small></article>
</section>
<div class="report-grid report-overview-grid">
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">SALES TREND</p><h2>Order value over time</h2><p class="muted">Daily for shorter ranges; monthly for longer ranges.</p></div></div>
<?php if($reportTrend):foreach($reportTrend as $trendDate=>$trendRow):$barWidth=max(2,(int)round($trendRow['order_value']/$reportTrendMax*100));?><div class="report-trend-row"><div><span><?=e(date(($reportRange==='all'||strlen((string)$trendDate)===7)?'M Y':'D M',strtotime((string)$trendDate)))?></span><strong><?=money($trendRow['order_value'])?></strong></div><div class="report-trend-track"><i style="width:<?=$barWidth?>%"></i></div><small><?=$trendRow['orders']?> orders</small></div><?php endforeach;else:?><p class="muted">No orders in this period.</p><?php endif;?>
</section>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">COMING UP</p><h2>Next recurring orders</h2></div><a class="quick-action" href="?view=reports&amp;type=recurring&amp;range=next8w">View report</a></div>
<?php if($reportUpcomingPreview):foreach($reportUpcomingPreview as $cycle):?><div class="dashboard-row report-list-row"><span><strong><?=e(cycleRecipientName($cycle))?></strong><small><?=e($cycle['product_summary'])?> · Ordered by <?=e($cycle['customer_name'])?></small></span><strong><?=e(date('d M',strtotime((string)$cycle['occurrence_date'])))?></strong></div><?php endforeach;else:?><p class="muted">No upcoming recurring orders are scheduled.</p><?php endif;?>
</section>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">TOP PRODUCTS</p><h2>Most purchased</h2></div><a class="quick-action" href="?<?=e(http_build_query(['view'=>'reports','type'=>'products','range'=>$reportRange,'from'=>$reportFrom,'to'=>$reportTo]))?>">All products</a></div>
<?php $topProductsPreview=array_slice($reportProducts,0,5,true);if($topProductsPreview):foreach($topProductsPreview as $r):?><a class="dashboard-row report-product-summary" href="<?=e(productOrdersReportUrl($r['name'],$reportRange,$reportFrom,$reportTo,'overview'))?>" aria-label="<?=e('View orders for '.$r['name'])?>"><span><?=e($r['name'])?><small><?=$r['orders']?> orders · View orders →</small></span><strong><?=$r['units']?> units</strong></a><?php endforeach;else:?><p class="muted">Product rankings appear when orders are placed.</p><?php endif;?>
</section>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">CUSTOMERS</p><h2>Highest paid</h2></div><a class="quick-action" href="?view=reports&amp;type=customers&amp;range=<?=e($reportRange)?>">All customers</a></div>
<?php $topCustomersPreview=array_slice($reportCustomerStats,0,5,true);if($topCustomersPreview):foreach($topCustomersPreview as $r):?><div class="dashboard-row"><span><?=e($r['name'])?><small><?=$r['orders']?> orders</small></span><strong><?=money($r['paid_recorded'])?></strong></div><?php endforeach;else:?><p class="muted">Customer rankings appear when orders are placed.</p><?php endif;?>
</section>
<section class="panel report-stock-preview"><div class="dashboard-panel-head"><div><p class="eyebrow">STOCK</p><h2>Confirmed stock status</h2></div><a class="quick-action" href="?view=reports&amp;type=stock&amp;range=month">Open stock report</a></div>
<?php if(!$reportTrackedStock):?><p class="muted">Stock reporting will appear after you confirm opening counts in Stock control. Manual quantities are not treated as verified stock.</p><?php else:?><div class="dashboard-row"><span>Tracked products</span><strong><?=count($reportTrackedStock)?></strong></div><div class="dashboard-row"><span>Low stock</span><strong><?=count($reportLowStock)?></strong></div><?php endif;?>
</section>
</div>
<?php elseif($reportType==='sales'):?>
<section class="report-metrics"><article><span>Order value</span><strong><?=money($reportOrderValue)?></strong><small><?=count($reportOrdersFiltered)?> non-cancelled orders by order date</small></article><article><span>Payments received</span><strong><?=money($reportCashReceived)?></strong><small>Actual payment entries by payment date</small></article><article><span>Open balance</span><strong><?=money($reportOutstanding)?></strong><small>Current balance on orders in range</small></article><article><span>Average order value</span><strong><?=money($reportAverageOrder)?></strong><small>Order value ÷ order count</small></article></section>
<div class="report-grid">
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">PAYMENTS RECEIVED</p><h2>By payment method</h2></div></div><?php if($reportPaymentsByMethod):foreach($reportPaymentsByMethod as $method):?><div class="dashboard-row"><span><?=e($method['method'])?><small><?=$method['entries']?> payments</small></span><strong><?=money((int)$method['total'])?></strong></div><?php endforeach;else:?><p class="muted">No payment entries in this date range.</p><?php endif;?></section>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">ORDER STATUS</p><h2>Orders placed in range</h2></div></div><?php if($reportStatusTotals):foreach($reportStatusTotals as $status=>$totals):?><div class="dashboard-row"><span><?=e($status)?><small><?=$totals['orders']?> orders</small></span><strong><?=money($totals['value'])?></strong></div><?php endforeach;else:?><p class="muted">No orders in this date range.</p><?php endif;?></section>
</div>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">ORDER DETAIL</p><h2>Sales and balances</h2><p class="muted">Order value, recorded payments and current balance are shown separately.</p></div></div><div class="profit-table-wrap"><table class="profit-table report-table"><thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Status</th><th>Order value</th><th>Paid</th><th>Balance</th></tr></thead><tbody>
<?php foreach($reportOrdersFiltered as $o):?><tr><td><a href="?view=edit&amp;id=<?=$o['id']?>">ANK-<?=str_pad((string)$o['id'],4,'0',STR_PAD_LEFT)?></a></td><td><?=e(date('d M Y',(int)$o['_report_ts']))?></td><td><?=e($o['customer'])?></td><td><?=e($o['status'])?></td><td><?=money($o['_order_value'])?></td><td><?=money($o['_paid_recorded'])?></td><td><?=money($o['_balance_due'])?></td></tr><?php endforeach;?>
<?php if(!$reportOrdersFiltered):?><tr><td colspan="7" class="muted">No orders in this date range.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($reportType==='products'):?>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">PRODUCT PERFORMANCE</p><h2>Most purchased products</h2><p class="muted">Tap a product row to see its orders in this period. Ranked by units on non-cancelled orders. Line value uses the price saved on each order.</p></div></div><div class="profit-table-wrap"><table class="profit-table report-table"><thead><tr><th>Product</th><th>Units</th><th>Orders</th><th>Average unit value</th><th>Line value</th></tr></thead><tbody>
<?php foreach($reportProducts as $r):$productOrdersUrl=productOrdersReportUrl($r['name'],$reportRange,$reportFrom,$reportTo);?><tr class="report-product-row" data-report-product-url="<?=e($productOrdersUrl)?>"><td><a class="report-product-link" href="<?=e($productOrdersUrl)?>" aria-label="<?=e('View orders for '.$r['name'])?>"><?=e($r['name'])?></a></td><td><?=$r['units']?></td><td><?=$r['orders']?></td><td><?=money($r['average_unit_value'])?></td><td><?=money($r['line_value'])?></td></tr><?php endforeach;?>
<?php if(!$reportProducts):?><tr><td colspan="5" class="muted">No product sales in this date range.</td></tr><?php endif;?></tbody></table></div></section>
<script>document.addEventListener('click',event=>{const row=event.target.closest('[data-report-product-url]');if(!row||event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||event.target.closest('a,button,input,select,textarea')||window.getSelection()?.toString())return;window.location.assign(row.dataset.reportProductUrl)});</script>
<?php elseif($reportType==='productorders'):?>
<section class="panel report-product-orders-heading"><a class="quick-action" href="?<?=e(http_build_query(['view'=>'reports','type'=>$reportProductSource,'range'=>$reportRange,'from'=>$reportFrom,'to'=>$reportTo]))?>">← Back to <?=$reportProductSource==='overview'?'overview':'products'?></a><p class="eyebrow">ORDERS FOR THIS PRODUCT</p><h2><?=e($reportSelectedProduct['name']?:'Choose a product')?></h2><p class="muted"><?=count($reportProductOrders)?> orders · <?=(int)$reportSelectedProduct['units']?> units · <?=money((int)$reportSelectedProduct['line_value'])?> product value</p><p class="muted">Orders placed <?=e($reportFrom!==''&&$reportTo!==''?'from '.$reportFrom.' to '.$reportTo:'across all time')?>. Matches the product rankings; cancelled and free personal-use orders are excluded. Tap an order to open it.</p></section>
<div class="report-product-order-list">
<?php foreach($reportProductOrders as $o):?><a class="report-product-order" href="?view=edit&amp;id=<?=(int)$o['id']?>"><div class="report-product-order-top"><span class="ref">ANK-<?=str_pad((string)$o['id'],4,'0',STR_PAD_LEFT)?></span><time datetime="<?=e(date('Y-m-d',(int)$o['_report_ts']))?>"><?=e(date('d M Y',(int)$o['_report_ts']))?></time></div><h3><?=e(orderDisplayName($o))?></h3><?php if(trim((string)($o['recipient_name']??''))!==''):?><p>Ordered by <?=e($o['customer'])?></p><?php endif;?><div class="report-product-order-bottom"><span><?=(int)$o['_product_units']?> units · <?=money((int)$o['_product_value'])?></span><span><?=e($o['status'])?><?=!empty($o['back_order'])?' · Back order':''?></span></div><small>Open order →</small></a><?php endforeach;?>
<?php if(!$reportProductOrders):?><section class="panel"><p class="empty">No matching orders for this product in the selected period.</p></section><?php endif;?></div>
<?php elseif($reportType==='customers'):?>
<section class="report-metrics"><?php $topCustomer=$reportCustomerStats?reset($reportCustomerStats):null;$topOrderCustomer=$reportCustomerStats;uasort($topOrderCustomer,fn($a,$b)=>$b['order_value']<=>$a['order_value']?:$b['paid_recorded']<=>$a['paid_recorded']);$topOrderCustomer=$topOrderCustomer?reset($topOrderCustomer):null;?><article><span>Top customer by payments recorded</span><strong><?=e($topCustomer['name']??'—')?></strong><small><?=money((int)($topCustomer['paid_recorded']??0))?> recorded against their orders</small></article><article><span>Top customer order value</span><strong><?=money((int)($topOrderCustomer['order_value']??0))?></strong><small><?=e($topOrderCustomer['name']??'—')?></small></article><article><span>Customers</span><strong><?=count($reportCustomerStats)?></strong><small>With orders in this date range</small></article><article><span>Most purchased by top customer</span><strong><?=e($topCustomer['top_product']??'—')?></strong><small><?=e((string)($topCustomer['top_product_units']??0))?> units</small></article></section>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">CUSTOMER PERFORMANCE</p><h2>Spend, orders and favourites</h2><p class="muted">Ranked by recorded payments against orders placed in this date range.</p></div></div><div class="profit-table-wrap"><table class="profit-table report-table"><thead><tr><th>Customer</th><th>Orders</th><th>Most purchased</th><th>Order value</th><th>Paid recorded</th><th>Balance due</th><th>Last order</th></tr></thead><tbody>
<?php foreach($reportCustomerStats as $r):?><tr><td><strong><?=e($r['name'])?></strong><small><?=e($r['phone'])?></small></td><td><?=$r['orders']?></td><td><?=e($r['top_product']?:'—')?><?php if($r['top_product']):?><small><?=$r['top_product_units']?> units</small><?php endif;?></td><td><?=money($r['order_value'])?></td><td><?=money($r['paid_recorded'])?></td><td><?=money($r['balance_due'])?></td><td><?=e($r['last_order'])?></td></tr><?php endforeach;?>
<?php if(!$reportCustomerStats):?><tr><td colspan="7" class="muted">No customer orders in this date range.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($reportType==='recurring'):?>
<section class="report-metrics"><article><span>Upcoming cycles</span><strong><?=count($reportCycles)?></strong><small>Active next due dates in this window</small></article><article><span>Expected order value</span><strong><?=money(array_sum(array_map(fn($c)=>(int)$c['expected_value'],$reportCycles)))?></strong><small>Forecast only, not confirmed sales</small></article><article><span>Expected profit</span><strong><?=money(array_sum(array_map(fn($c)=>(int)$c['expected_profit'],$reportCycles)))?></strong><small>Uses saved cycle cost estimates</small></article><article><span>Overdue cycles</span><strong><?=count($retaOverdue)?></strong><small>Active cycles due before today</small></article></section>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">NEXT DUE DATE</p><h2>Upcoming recurring orders</h2><p class="muted">Shows who placed each order, who it’s for and the next scheduled date.</p></div></div><div class="profit-table-wrap"><table class="profit-table report-table"><thead><tr><th>Due</th><th>Ordered by</th><th>For</th><th>Phone</th><th>Products</th><th>Expected value</th><th>Expected profit</th></tr></thead><tbody>
<?php foreach($reportCycles as $c):?><tr><td><?=e(date('d M Y',strtotime((string)$c['next_due_date'])))?></td><td><?=e($c['customer_name'])?></td><td><?=e(cycleRecipientName($c))?></td><td><?=e($c['phone'])?></td><td><?=e($c['product_summary'])?></td><td><?=money((int)$c['expected_value'])?></td><td><?=money((int)$c['expected_profit'])?><?=$c['cost_missing']?' <small>cost incomplete</small>':''?></td></tr><?php endforeach;?>
<?php if(!$reportCycles):?><tr><td colspan="7" class="muted">No active recurring orders are due in this window.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($reportType==='reorder'):?>
<section class="report-metrics reorder-metrics">
<article><span>Tracked products</span><strong><?=count($reorderRows)?></strong><small>Confirmed stock counts</small></article>
<article><span>Reorder now</span><strong><?=$reorderNeedNow?></strong><small>Needed to cover the calculated target</small></article>
<article><span>Suggested packs</span><strong><?=$reorderPackTotal?></strong><small><?=$reorderUnitTotal?> units in total</small></article>
<article><span>Sales lookback</span><strong><?=$reorderWindowDays?> days</strong><small>Delivered orders only</small></article>
</section>
<style id="reorder-mobile-critical">
@media (max-width:760px){
 .ios-reorder{display:block!important;width:100%!important;margin:12px 0 0!important;padding:12px!important;border:1px solid #303535!important;border-radius:18px!important;background:#141717!important;overflow:hidden!important;box-sizing:border-box!important}
 .ios-reorder *{box-sizing:border-box!important}
 .ios-reorder-header{display:grid!important;grid-template-columns:minmax(0,1fr) 44px!important;align-items:center!important;gap:12px!important}
 .ios-reorder-header .eyebrow{margin:0 0 5px!important;color:#d6b45c!important;font-size:10px!important;letter-spacing:1.6px!important}
 .ios-reorder-header h2{margin:0!important;color:#f0f0eb!important;font-size:24px!important;line-height:1.08!important;letter-spacing:-.5px!important}
 .ios-reorder-header p:last-child{margin:7px 0 0!important;color:#818580!important;font-size:12px!important;line-height:1.45!important}
 .ios-reorder-stock-link{display:grid!important;place-items:center!important;width:44px!important;height:44px!important;padding:0!important;border:1px solid #3b4040!important;border-radius:13px!important;background:#101313!important;text-decoration:none!important}
 .ios-reorder-stock-link span{color:#d8b65e!important;font-size:18px!important}.ios-reorder-stock-link b{display:none!important}
 .ios-reorder-summary{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:7px!important;margin:14px 0 0!important}
 .ios-reorder-summary>div{display:grid!important;gap:4px!important;min-width:0!important;padding:10px 5px!important;border:1px solid #2c3030!important;border-radius:12px!important;background:#101313!important;text-align:center!important}
 .ios-reorder-summary small{color:#747873!important;font-size:10px!important;line-height:1.15!important}.ios-reorder-summary strong{color:#f0f0eb!important;font-size:22px!important;line-height:1!important}
 .ios-reorder-list{display:grid!important;grid-template-columns:1fr!important;gap:12px!important;width:100%!important;margin:12px 0 0!important;padding:0!important}
 .ios-reorder-card{display:block!important;width:100%!important;min-width:0!important;margin:0!important;padding:0!important;overflow:hidden!important;border:1px solid #303535!important;border-radius:16px!important;background:linear-gradient(155deg,#191c1c,#111414)!important;box-shadow:0 8px 22px rgba(0,0,0,.13)!important}
 .ios-reorder-card.priority-0{border-color:#69572f!important;background:linear-gradient(155deg,#201c13,#111414 68%)!important}
 .ios-reorder-card-head{display:grid!important;grid-template-columns:44px minmax(0,1fr) auto!important;align-items:center!important;gap:10px!important;width:100%!important;padding:12px!important;border-bottom:1px solid #292d2d!important}
 .ios-product-icon{display:grid!important;place-items:center!important;width:44px!important;height:44px!important;border:1px solid #3a3e3e!important;border-radius:13px!important;background:#111414!important;color:#929691!important}
 .ios-product-icon svg,.ios-box-icon svg{width:22px!important;height:22px!important;fill:none!important;stroke:currentColor!important;stroke-width:1.8!important;stroke-linecap:round!important;stroke-linejoin:round!important}
 .priority-0 .ios-product-icon{border-color:#5c4d2b!important;background:#2b2518!important;color:#dfbb62!important}
 .ios-reorder-card-head>div{min-width:0!important}.ios-reorder-card-head>div small{display:block!important;color:#6e726e!important;font-size:9px!important;font-weight:800!important;letter-spacing:1px!important}
 .ios-reorder-card h3{display:block!important;overflow:hidden!important;margin:3px 0 0!important;color:#f0f0eb!important;font-size:17px!important;line-height:1.2!important;white-space:nowrap!important;text-overflow:ellipsis!important}
 .ios-reorder-status{display:block!important;padding:6px 8px!important;border:1px solid #3a3e3e!important;border-radius:999px!important;background:#171a1a!important;color:#969a95!important;font-size:9px!important;font-weight:800!important;line-height:1!important;text-transform:uppercase!important;white-space:nowrap!important}
 .priority-0 .ios-reorder-status{border-color:#6a5730!important;background:#302819!important;color:#e4bf66!important}
 .ios-order-callout{display:grid!important;grid-template-columns:minmax(0,1fr) 40px!important;align-items:center!important;gap:10px!important;margin:10px 10px 8px!important;padding:11px 12px!important;border:1px solid #67542e!important;border-radius:13px!important;background:linear-gradient(135deg,#302817,#201c14)!important}
 .ios-order-callout>div{display:grid!important;gap:3px!important;min-width:0!important}.ios-order-callout small{color:#a38a4e!important;font-size:9px!important;font-weight:800!important;letter-spacing:.8px!important}.ios-order-callout strong{color:#e8c36a!important;font-size:20px!important;line-height:1.1!important}.ios-order-callout>div>span{color:#9a9484!important;font-size:11px!important;line-height:1.25!important}
 .ios-box-icon{display:grid!important;place-items:center!important;width:40px!important;height:40px!important;border-radius:12px!important;background:#3a311d!important;color:#e1bc62!important}
 .ios-order-callout.covered{border-color:#31513a!important;background:#17231b!important}.ios-order-callout.covered small{color:#72a07d!important}.ios-order-callout.covered strong{color:#a0cba9!important}.ios-order-callout.covered .ios-box-icon{background:#203729!important;color:#99c8a3!important}
 .ios-fridge-row{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:7px!important;width:auto!important;margin:0!important;padding:0 10px 8px!important}
 .ios-fridge-row>div{display:grid!important;grid-template-columns:1fr!important;grid-template-rows:auto auto auto!important;justify-items:center!important;gap:4px!important;min-width:0!important;padding:9px 4px!important;border:1px solid #2c3030!important;border-radius:12px!important;background:#101313!important;text-align:center!important}
 .ios-person-dot{display:grid!important;grid-row:auto!important;place-items:center!important;width:25px!important;height:25px!important;border-radius:8px!important;background:#1e2221!important;color:#8f938e!important;font-size:9px!important;font-weight:900!important}.ios-person-dot.all{background:#332b19!important;color:#e0bc62!important}
 .ios-fridge-row small{color:#737773!important;font-size:10px!important;line-height:1!important}.ios-fridge-row strong{color:#f0f0eb!important;font-size:18px!important;line-height:1!important}.ios-fridge-row .total{border-color:#514526!important}
 .ios-plan-row{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:7px!important;width:auto!important;margin:0 10px 8px!important;padding:0!important;border:0!important;background:transparent!important}
 .ios-plan-row>span{display:none!important}.ios-plan-row>div{display:grid!important;align-content:center!important;gap:4px!important;min-width:0!important;min-height:59px!important;padding:8px 5px!important;border:1px solid #2b2f2f!important;border-radius:11px!important;background:#101313!important;text-align:center!important}
 .ios-plan-row small{color:#70746f!important;font-size:9px!important;line-height:1.1!important}.ios-plan-row strong{color:#efefea!important;font-size:16px!important;line-height:1.1!important;white-space:normal!important}
 .ios-plan-row .gap{border-color:#64512e!important;background:#2c2518!important}.ios-plan-row .gap small,.ios-plan-row .gap strong{color:#dfbb62!important}.ios-plan-row .ok{border-color:#31503a!important;background:#17231b!important}.ios-plan-row .ok strong{color:#9cc8a5!important}
 .ios-reorder-details{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:7px!important;width:auto!important;margin:0!important;padding:0 10px 9px!important}
 .ios-reorder-details>span{display:grid!important;gap:4px!important;min-width:0!important;padding:8px 4px!important;border-radius:10px!important;background:#181b1b!important;text-align:center!important}
 .ios-reorder-details small{display:block!important;overflow:visible!important;color:#6f736f!important;font-size:9px!important;line-height:1.15!important;white-space:normal!important;text-overflow:clip!important}.ios-reorder-details b{color:#d6d8d3!important;font-size:13px!important;line-height:1!important}
 .ios-reorder-action{display:flex!important;align-items:center!important;gap:9px!important;width:auto!important;min-height:44px!important;margin:0 10px 10px!important;padding:9px 11px!important;border:1px solid #6b5830!important;border-radius:12px!important;background:#302817!important;color:#e5c067!important;font-size:12px!important;font-weight:800!important;text-decoration:none!important}.ios-reorder-action>span{display:grid!important;place-items:center!important;width:26px!important;height:26px!important;border-radius:8px!important;background:#40351e!important;font-size:16px!important}.ios-reorder-action>b{margin-left:auto!important;font-size:19px!important}
 .ios-holding-note{margin:0!important;padding:0 11px 10px!important;color:#777b77!important;font-size:10px!important;line-height:1.35!important}
 .ios-reorder-help{display:block!important;margin:12px 0 0!important;border:1px solid #2d3131!important;border-radius:13px!important;background:#101313!important;overflow:hidden!important}.ios-reorder-help summary{padding:12px!important;color:#aaaDA8!important;font-size:12px!important;font-weight:750!important}.ios-reorder-help p{margin:0!important;padding:0 12px 12px!important;color:#777b77!important;font-size:11px!important;line-height:1.5!important}
}
@media (max-width:390px){
 .ios-reorder{padding:10px!important}.ios-reorder-header p:last-child{display:none!important}.ios-reorder-header h2{font-size:22px!important}
 .ios-reorder-card-head{grid-template-columns:40px minmax(0,1fr) auto!important;padding:10px!important}.ios-product-icon{width:40px!important;height:40px!important}
 .ios-reorder-card h3{font-size:15px!important}.ios-reorder-status{padding:5px 6px!important;font-size:8px!important}
 .ios-fridge-row,.ios-reorder-details{gap:5px!important;padding-left:8px!important;padding-right:8px!important}.ios-plan-row{gap:5px!important;margin-left:8px!important;margin-right:8px!important}
 .ios-fridge-row small,.ios-reorder-details small,.ios-plan-row small{font-size:8px!important}
}
</style>
<section class="ios-reorder">
 <header class="ios-reorder-header">
  <div><p class="eyebrow">STOCK PLANNING</p><h2>Reorder planner</h2><p>Suggestions include the 7-day supplier wait and potential repeat orders due in the next 30 days.</p></div>
  <a href="?view=stock" class="ios-reorder-stock-link"><span aria-hidden="true">⚙</span><b>Stock</b></a>
 </header>
 <div class="ios-reorder-summary">
  <div><small>Reorder now</small><strong><?=$reorderNeedNow?></strong></div>
  <div><small>Packs suggested</small><strong><?=$reorderPackTotal?></strong></div>
  <div><small>Units to order</small><strong><?=$reorderUnitTotal?></strong></div>
 </div>
 <?php if(!$reorderRows):?>
 <div class="ios-reorder-empty"><span>✓</span><h3>No confirmed stock to plan from yet</h3><p>Confirm opening fridge counts first so the planner can use real stock balances.</p><a href="?view=stock">Set up stock control →</a></div>
 <?php else:?><div class="ios-reorder-list">
 <?php foreach($reorderRows as $rr):$status=$rr['suggested_units']<=0?'Covered':($rr['available']<=$rr['threshold']?'Reorder now':'Plan ahead');$gap=max(0,$rr['target']-$rr['available']);?>
 <article class="ios-reorder-card priority-<?=$rr['priority']?>">
  <div class="ios-reorder-card-head">
   <span class="ios-product-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9 3h6M9 6h6M8 8h8l1 3v9H7v-9l1-3Z"/><path d="M9 13h6"/></svg></span>
   <div><small>PRODUCT</small><h3><?=e($rr['name'])?></h3></div>
   <span class="ios-reorder-status"><?=e($status)?></span>
  </div>

  <?php if($rr['packs']>0):?>
  <div class="ios-order-callout">
   <div><small>RECOMMENDED ORDER</small><strong><?=$rr['packs']?> pack<?=$rr['packs']===1?'':'s'?></strong><span><?=$rr['suggested_units']?> units total · <?=$rr['pack_size']?> per pack</span></div>
   <span class="ios-box-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m4 7 8-4 8 4-8 4-8-4Z"/><path d="m4 7v10l8 4 8-4V7M12 11v10"/></svg></span>
  </div>
  <?php else:?>
  <div class="ios-order-callout covered"><div><small>STOCK POSITION</small><strong>No order needed</strong><span>Current stock covers the calculated target.</span></div><span class="ios-box-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span></div>
  <?php endif;?>

  <div class="ios-fridge-row">
   <div><span class="ios-person-dot jay">J</span><small>Jay</small><strong><?=$rr['jay']?></strong></div>
   <div><span class="ios-person-dot tony">T</span><small>Tony</small><strong><?=$rr['tony']?></strong></div>
   <div class="total"><span class="ios-person-dot all">Σ</span><small>Available</small><strong><?=$rr['available']?></strong></div>
  </div>

  <div class="ios-plan-row">
   <div><small>Available now</small><strong><?=$rr['available']?></strong></div>
   <span>→</span>
   <div><small>Target</small><strong><?=$rr['target']?></strong></div>
   <span>→</span>
   <div class="<?=$gap>0?'gap':'ok'?>"><small><?=$gap>0?'Gap':'Position'?></small><strong><?=$gap>0?$gap.' units':'Covered'?></strong></div>
  </div>

  <div class="ios-reorder-details">
   <span><small>Low-stock alert</small><b><?=$rr['threshold']?></b></span>
   <span><small>Non-repeat sold · <?=$reorderWindowDays?>d</small><b><?=$rr['recent_units']?></b></span>
   <span><small>Est. sales · 7d</small><b><?=$rr['lead_time_demand']?></b></span>
   <span><small>Repeat due · 30d</small><b><?=$rr['repeat_30d']?></b></span>
  </div>

  <?php if($rr['packs']>0):?><a class="ios-reorder-action" href="?view=stock&amp;section=incoming"><span>＋</span>Add to incoming stock<b>›</b></a><?php endif;?>
  <?php if($rr['reserved']>0||$rr['unallocated']>0):?><p class="ios-holding-note"><?php if($rr['reserved']>0):?><?=$rr['reserved']?> unit<?=$rr['reserved']===1?' is':'s are'?> reserved on current orders and already removed from available.<?php endif;?><?php if($rr['reserved']>0&&$rr['unallocated']>0):?> · <?php endif;?><?php if($rr['unallocated']>0):?><?=$rr['unallocated']?> holding-stock unit<?=$rr['unallocated']===1?' is':'s are'?> included in available.<?php endif;?></p><?php endif;?>
 </article>
 <?php endforeach;?></div><?php endif;?>
 <details class="ios-reorder-help"><summary>How are these suggestions calculated?</summary><p>Available stock already excludes units reserved for open orders. Delivered items not marked as recurring estimate other sales during the usual 7-day supplier wait. Separately, active recurring cycles due in the next 30 days add potential repeat units; cycles with a newer real order are left out. The low-stock alert is added as a buffer, then any gap is rounded up to full supplier packs. Recent sales use the full selected lookback, so short tracking histories are not scaled up.</p></details>
</section>
<?php elseif($reportType==='stock'):?>
<?php if(!$reportTrackedStock):?><section class="panel report-stock-empty"><p class="eyebrow">STOCK REPORTING IS READY</p><h2>No confirmed stock counts yet</h2><p class="muted">The app won’t treat the old manual product quantities as verified stock. Confirm an opening count for each product when you have physically checked it.</p><a class="button" href="?view=stock">Set up stock control</a></section><?php else:?>
<section class="report-metrics">
<article><span>Tracked products</span><strong><?=count($reportTrackedStock)?></strong><small>Confirmed opening counts</small></article>
<article><span>Units on hand</span><strong><?=$reportTrackedUnits?></strong><small>Across tracked active products</small></article>
<article><span>Low stock</span><strong><?=count($reportLowStock)?></strong><small>At or below reorder threshold</small></article>
<article><span>Retail stock value</span><strong><?=money($reportStockRetailValue)?></strong><small>What tracked stock would sell for at current retail prices</small></article>
<article><span>Stock cost value</span><strong><?=money($reportStockCostValue)?></strong><small><?=$reportStockCostMissing?'Known cost only · '.$reportStockCostMissing.' product'.($reportStockCostMissing===1?'':'s').' missing cost':'Based on current saved product costs'?></small></article>
<article><span>Potential gross profit</span><strong><?=money($reportStockPotentialProfit)?></strong><small><?=$reportStockCostMissing?'Known-cost stock only':'Retail value minus stock cost'?></small></article>
</section>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">CURRENT SNAPSHOT</p><h2>Stock value by product</h2><p class="muted">Retail value uses today’s product price. Potential gross profit is retail value less the current saved unit cost and does not include postage or other overheads.</p></div></div><div class="profit-table-wrap"><table class="profit-table report-table"><thead><tr><th>Product</th><th>On hand</th><th>Alert at</th><th>Unit retail</th><th>Retail value</th><th>Unit cost</th><th>Cost value</th><th>Potential profit</th><th>Status</th></tr></thead><tbody>
<?php foreach($reportTrackedStock as $sp):$stockQty=(int)$sp['stock_qty'];$stockRetail=(int)$sp['price'];$stockCost=$sp['cost']===null?null:(int)$sp['cost'];$stockLow=$stockQty<=(int)$sp['low_stock_at'];?><tr><td><?=e($sp['name'])?></td><td><?=$stockQty?></td><td><?=$sp['low_stock_at']?></td><td><?=money($stockRetail)?></td><td><?=money($stockQty*$stockRetail)?></td><td><?=$stockCost===null?'—':money($stockCost)?></td><td><?=$stockCost===null?'—':money($stockQty*$stockCost)?></td><td><?=$stockCost===null?'—':money($stockQty*($stockRetail-$stockCost))?></td><td><?=$stockLow?'Low stock':'In stock'?></td></tr><?php endforeach;?>
</tbody></table></div></section><?php endif;?>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">MOVEMENT HISTORY</p><h2>Changes in <?=e($reportWindowLabel)?></h2></div><a class="quick-action" href="<?=e($reportMovementExportUrl)?>">Export movements</a></div>
<?php if($reportStockMovements):?><div class="profit-table-wrap"><table class="profit-table report-table"><thead><tr><th>Date</th><th>Product</th><th>Location</th><th>Movement</th><th>Change</th><th>Supplier / batch</th><th>Expiry</th></tr></thead><tbody><?php foreach($reportStockMovements as $m):?><tr><td><?=e(date('d M Y',strtotime((string)$m['created'])))?></td><td><?=e($m['product_name'])?></td><td><?=e(stockLocationName((string)($m['location']??'Unallocated')))?></td><td><?=e(str_replace('_',' ',(string)$m['movement_type']))?></td><td><?=$m['quantity_change']>0?'+':''?><?=e($m['quantity_change'])?></td><td><?=e(trim((string)$m['supplier'].' · '.(string)$m['batch_reference'],' ·'))?></td><td><?=e($m['expiry_date']?:'—')?></td></tr><?php endforeach;?></tbody></table></div><?php else:?><p class="muted">No stock movements in this date range.</p><?php endif;?>
</section>
<?php endif;?>
<?php endif;?>
<script>
(()=>{document.querySelectorAll('.report-table,.expanded-profit-table').forEach(table=>{const labels=[...table.querySelectorAll('thead th')].map(th=>th.textContent.trim());table.querySelectorAll('tbody tr').forEach(row=>{[...row.children].forEach((cell,index)=>{if(cell.tagName==='TD'&&!cell.hasAttribute('colspan'))cell.dataset.label=labels[index]||'';});});});})();
</script>
<?php elseif($view==='supplier'):?>
<?php sc_render($db,$stockProducts,$reservedStockByProduct,$incomingByProduct,$recurringForecast30['stock']??[]); ?>
<?php elseif($view==='activity'):?>
<div class="heading"><div><h1>Order &amp; payment activity</h1><p class="muted page-description">Recent recorded operational changes (test mode).</p></div><a class="quick-action" href="?view=more">← More</a></div>
<section class="panel"><div class="activity-list">
<?php $activityRows=$db->query("SELECT entity_type,entity_id,action,actor_name,details_json,created FROM activity_events ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC); ?>
<?php foreach($activityRows as $entry):$detail=json_decode((string)$entry['details_json'],true)?:[];$orderTarget=$entry['entity_type']==='order'?(int)$entry['entity_id']:(int)($detail['order_id']??0);?>
<article class="activity-row"><div><strong><?=e($entry['action'])?></strong><small><?=e($entry['actor_name'])?> · <?=e(date('d M Y, H:i',strtotime($entry['created'])))?></small><?php if($summary=activitySummary($detail)):?><small><?=e($summary)?></small><?php endif;?></div>
<?php if($orderTarget>0):?><a href="?view=edit&amp;id=<?=$orderTarget?>">Order #<?=$orderTarget?> →</a><?php endif;?></article>
<?php endforeach;?><?php if(!$activityRows):?><p class="muted">Activity tracking is ready. New changes will appear here.</p><?php endif;?></div></section>
<?php elseif($view==='supplies'):
 $supplySection=in_array($_GET['section']??'',['cupboard','shopping','purchases'],true)?(string)$_GET['section']:((isset($_GET['new'])||isset($_GET['edit'])||isset($_GET['status'])||isset($_GET['q'])||($_POST['action']??'')==='business_supply_save')?'purchases':'cupboard');
 if(isset($_POST['return_section'])&&in_array($_POST['return_section'],['cupboard','shopping','purchases'],true))$supplySection=(string)$_POST['return_section'];
 $cupboardItems=$db->query("SELECT * FROM business_supply_items ORDER BY CASE stock_level WHEN 'Out' THEN 0 WHEN 'Running low' THEN 1 ELSE 2 END,name COLLATE NOCASE,id")->fetchAll(PDO::FETCH_ASSOC);$cupboardById=[];$cupboardIncoming=[];$cupboardLast=[];
 foreach($cupboardItems as $c)$cupboardById[(int)$c['id']]=$c;
 foreach($businessSupplies as $p)if($p['cupboard_id']){$cid=(int)$p['cupboard_id'];if($p['status']!=='Cancelled')$cupboardLast[$cid]??=$p;if(in_array($p['status'],['Ordered','Dispatched'],true))$cupboardIncoming[$cid][]=$p;}
 $cupboardActive=array_filter($cupboardItems,static fn($c)=>(int)$c['active']===1);$cupboardNeed=array_filter($cupboardActive,static fn($c)=>$c['stock_level']!=='Enough');$cupboardShopping=array_filter($cupboardActive,static fn($c)=>$c['stock_level']!=='Enough'||$c['owner']!=='');
 $cupboardToBuy=array_filter($cupboardNeed,static fn($c)=>empty($cupboardIncoming[(int)$c['id']]));
 $cupboardFormId=(int)($_GET['edit_item']??0);$sourcePurchaseId=(int)($_GET['from_purchase']??0);$sourcePurchase=null;foreach($businessSupplies as $p)if((int)$p['id']===$sourcePurchaseId)$sourcePurchase=$p;
 $cupboardFormOpen=isset($_GET['new_item'])||$cupboardFormId>0||$sourcePurchaseId>0||($_POST['action']??'')==='supply_item_save';
 $cupboardForm=$cupboardById[$cupboardFormId]??['id'=>0,'revision'=>0,'name'=>'','category'=>'Other supplies','specification'=>'','supplier'=>'','product_url'=>'','backup_supplier'=>'','backup_url'=>'','usual_quantity'=>1,'usual_cost'=>null,'stock_level'=>$supplySection==='shopping'?'Running low':'Enough','stock_note'=>'','requested_by'=>'','notes'=>'','request_key'=>bin2hex(random_bytes(16))];
 if($sourcePurchase&&!$sourcePurchase['cupboard_id'])$cupboardForm=array_replace($cupboardForm,['name'=>$sourcePurchase['item'],'category'=>$sourcePurchase['category'],'supplier'=>$sourcePurchase['supplier'],'product_url'=>$sourcePurchase['product_url'],'usual_quantity'=>$sourcePurchase['quantity'],'usual_cost'=>$sourcePurchase['item_cost'],'notes'=>$sourcePurchase['notes']]);
 $cupboardForm['source_purchase_id']=$sourcePurchaseId;$cupboardForm['source_revision']=$sourcePurchase['revision']??0;$cupboardForm['usual_cost']=$cupboardForm['usual_cost']===null?'':number_format((int)$cupboardForm['usual_cost']/100,2,'.','');
 if(($_POST['action']??'')==='supply_item_save')foreach(array_keys($cupboardForm) as $field)if(isset($_POST[$field])&&is_scalar($_POST[$field]))$cupboardForm[$field]=(string)$_POST[$field];
 $supplyToday=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d');
 $supplyMonth=supplySpending($businessSupplies,substr($supplyToday,0,7).'-01',$supplyToday);$supplyAll=supplySpending($businessSupplies);
 $supplyOpen=array_filter($businessSupplies,static fn($r)=>in_array($r['status'],['Ordered','Dispatched'],true));
 $supplyHistoryId=(int)($_GET['cupboard_filter']??0);$defaultSupplyFilter=$supplyHistoryId>0?'all':'outstanding';$supplyFilter=in_array($_GET['status']??$defaultSupplyFilter,['outstanding','all','Received','Cancelled'],true)?(string)($_GET['status']??$defaultSupplyFilter):$defaultSupplyFilter;$supplySearch=trim((string)($_GET['q']??''));
 $supplyRows=array_filter($businessSupplies,static function($r)use($supplyFilter,$supplySearch){if($supplyFilter==='outstanding'&&!in_array($r['status'],['Ordered','Dispatched'],true))return false;if(in_array($supplyFilter,['Received','Cancelled'],true)&&$r['status']!==$supplyFilter)return false;return $supplySearch===''||stripos(implode(' ',[$r['item'],$r['supplier'],$r['category'],$r['reference'],$r['tracking_reference']]),$supplySearch)!==false;});
 if($supplyHistoryId>0)$supplyRows=array_filter($supplyRows,static fn($p)=>(int)$p['cupboard_id']===$supplyHistoryId);
 $supplyEditId=(int)($_GET['edit']??0);$supplyForm=null;foreach($businessSupplies as $r)if((int)$r['id']===$supplyEditId)$supplyForm=$r;
 $supplyFormOpen=isset($_GET['new'])||$supplyEditId>0||($_POST['action']??'')==='business_supply_save';
 if($supplyFormOpen){
  $supplyForm??=['id'=>0,'revision'=>0,'item'=>'','category'=>'Other supplies','quantity'=>1,'supplier'=>'','reference'=>'','ordered_date'=>$supplyToday,'expected_date'=>'','status'=>'Ordered','item_cost'=>0,'shipping'=>0,'payment_status'=>'Unpaid','paid_date'=>$supplyToday,'refunded_date'=>'','product_url'=>'','tracking_reference'=>'','tracking_url'=>'','notes'=>'','request_key'=>bin2hex(random_bytes(16))];
  $purchaseCupboardId=(int)($supplyForm['cupboard_id']??($_GET['cupboard_id']??($_POST['cupboard_id']??0)));$purchaseCupboard=$cupboardById[$purchaseCupboardId]??null;
  if(!(int)$supplyForm['id']&&$purchaseCupboard)$supplyForm=array_replace($supplyForm,['item'=>$purchaseCupboard['name'],'category'=>$purchaseCupboard['category'],'quantity'=>$purchaseCupboard['usual_quantity'],'supplier'=>$purchaseCupboard['supplier'],'product_url'=>$purchaseCupboard['product_url'],'item_cost'=>$purchaseCupboard['usual_cost']??0,'notes'=>$purchaseCupboard['specification']]);
  $supplyForm['cupboard_id']=$purchaseCupboardId;$supplyForm['cupboard_revision']=$purchaseCupboard['revision']??0;
  foreach(['item_cost','shipping'] as $field)$supplyForm[$field]=number_format((int)$supplyForm[$field]/100,2,'.','');
  if(($_POST['action']??'')==='business_supply_save')foreach(array_keys($supplyForm) as $field)if(isset($_POST[$field])&&is_scalar($_POST[$field]))$supplyForm[$field]=(string)$_POST[$field];
 }
?>
<div class="heading supplies-heading"><div><p class="eyebrow">YOUR SHARED SUPPLIES</p><h1>Business Supplies</h1><p class="muted page-description">Know what you need, where to buy it and who is handling it.</p></div><a class="button" href="?view=supplies&amp;section=<?=e($supplySection)?>&amp;<?=$supplySection==='purchases'?'new=1#supply-form':'new_item=1#cupboard-form'?>"><?=$supplySection==='purchases'?'+ Add purchase':($supplySection==='shopping'?'+ Add request':'+ Add supply')?></a></div>
<div class="supply-top-links"><a class="quick-action" href="?view=more">← More</a><a class="quick-action" href="?view=reports&amp;type=outgoings">Outgoings report →</a></div>
<div class="supply-section-tabs" role="navigation" aria-label="Business supplies sections"><?php foreach(['cupboard'=>'Supplies cupboard','shopping'=>'Shopping list','purchases'=>'Purchases'] as $key=>$label):?><a class="<?=$key===$supplySection?'selected':''?>" <?=$key===$supplySection?'aria-current="page"':''?> href="?view=supplies&amp;section=<?=$key?>"><?=e($label)?><span><?=$key==='cupboard'?count($cupboardActive):($key==='shopping'?count($cupboardShopping):count($supplyOpen))?></span></a><?php endforeach;?></div>
<?php if($supplySection!=='purchases'):?>
<?php if($cupboardFormOpen&&($cupboardFormId>0&&!isset($cupboardById[$cupboardFormId])||$sourcePurchaseId>0&&(!$sourcePurchase||$sourcePurchase['cupboard_id']))):?><p class="error">That supply is unavailable or this purchase already has a cupboard item.</p>
<?php elseif($cupboardFormOpen):?>
<section class="panel" id="cupboard-form"><div class="heading"><h2><?=(int)$cupboardForm['id']?'Edit supply':($supplySection==='shopping'?'Add to shopping list':'Save a supply')?></h2><a class="quick-action" href="?view=supplies&amp;section=<?=e($supplySection)?>">Close</a></div>
<form method="post" class="supply-form"><?php csrf();?><input type="hidden" name="action" value="supply_item_save"><input type="hidden" name="return" value="supplies"><input type="hidden" name="return_section" value="<?=e($supplySection)?>">
<?php foreach(['id','revision','request_key','source_purchase_id','source_revision'] as $field):?><input type="hidden" name="<?=$field?>" value="<?=e($cupboardForm[$field])?>"><?php endforeach;?>
<label class="supply-wide">Supply name<input name="name" maxlength="200" value="<?=e($cupboardForm['name'])?>" placeholder="e.g. Thermal shipping labels" required></label>
<label>Category<select name="category"><?php foreach(supplyCategories() as $v):?><option <?=$cupboardForm['category']===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></label>
<label>Stock level<select name="stock_level"><?php foreach(['Enough','Running low','Out'] as $v):?><option <?=$cupboardForm['stock_level']===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></label>
<label class="supply-wide">Exact size / model / specification<input name="specification" maxlength="1000" value="<?=e($cupboardForm['specification'])?>" placeholder="100 × 150mm · waterproof · printer model or cartridge code"></label>
<label>Stock note<input name="stock_note" maxlength="500" value="<?=e($cupboardForm['stock_note'])?>" placeholder="e.g. One roll left"></label>
<label>Requested by<select name="requested_by"><option value="">Not recorded</option><?php foreach(['Jay','Tony'] as $v):?><option <?=$cupboardForm['requested_by']===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></label>
<label>Preferred supplier<input name="supplier" maxlength="200" value="<?=e($cupboardForm['supplier'])?>"></label>
<label>Usual quantity / packs<input type="number" name="usual_quantity" min="1" max="100000" value="<?=e($cupboardForm['usual_quantity'])?>" required></label>
<label class="supply-wide">Product / reorder link<input type="url" name="product_url" maxlength="2000" value="<?=e($cupboardForm['product_url'])?>" placeholder="https://…"></label>
<label>Usual items total (£)<input type="number" name="usual_cost" min="0" max="100000" step="0.01" value="<?=e($cupboardForm['usual_cost'])?>"><small>Optional total for the usual quantity. Purchases keep the actual price.</small></label>
<label>Backup supplier<input name="backup_supplier" maxlength="200" value="<?=e($cupboardForm['backup_supplier'])?>"></label>
<label class="supply-wide">Backup product link<input type="url" name="backup_url" maxlength="2000" value="<?=e($cupboardForm['backup_url'])?>" placeholder="https://…"></label>
<label class="supply-wide">Ordering notes<textarea name="notes" rows="3" maxlength="4000" placeholder="Colours, pack sizes, delivery advice or what to avoid"><?=e($cupboardForm['notes'])?></textarea></label>
<p class="muted supply-wide">Running low and Out appear on the shared shopping list. Saving a supply or request does not record spending.</p><div class="supply-wide supply-form-actions"><button>Save supply</button><a class="quick-action" href="?view=supplies&amp;section=<?=e($supplySection)?>">Cancel</a></div>
</form></section>
<?php endif;?>
<section class="report-metrics supply-metrics"><article><span>Need buying</span><strong><?=count($cupboardToBuy)?></strong><small>Low or out, with nothing on the way</small></article><article><span>Being handled</span><strong><?=count(array_filter($cupboardActive,static fn($c)=>$c['owner']!==''))?></strong><small>Claimed by Jay or Tony</small></article><article><span>On the way</span><strong><?=count($supplyOpen)?></strong><small>Recorded purchases awaiting delivery</small></article></section>
<?php $cupboardSearch=trim((string)($_GET['cq']??''));$cupboardFilter=in_array($_GET['level']??'all',['all','Enough','Running low','Out','archived'],true)?(string)($_GET['level']??'all'):'all';$cupboardRows=$supplySection==='shopping'?$cupboardShopping:($cupboardFilter==='archived'?array_filter($cupboardItems,static fn($c)=>!(int)$c['active']):$cupboardActive);$cupboardRows=array_filter($cupboardRows,static fn($c)=>($cupboardFilter==='all'||$cupboardFilter==='archived'||$c['stock_level']===$cupboardFilter)&&($cupboardSearch===''||stripos(implode(' ',[$c['name'],$c['specification'],$c['supplier'],$c['category'],$c['stock_note'],$c['owner']]),$cupboardSearch)!==false));?>
<details class="cupboard-search" <?=$cupboardSearch!==''||$cupboardFilter!=='all'?'open':''?>><summary>Find &amp; filter supplies <span aria-hidden="true">⌄</span></summary><form method="get" class="supply-filters"><input type="hidden" name="view" value="supplies"><input type="hidden" name="section" value="<?=e($supplySection)?>"><label>Find a supply<input name="cq" value="<?=e($cupboardSearch)?>" placeholder="Name, model or supplier"></label><label>Show<select name="level"><?php foreach(['all'=>'All stock levels','Out'=>'Out of stock','Running low'=>'Running low','Enough'=>'Enough']+($supplySection==='cupboard'?['archived'=>'Archived supplies']:[]) as $v=>$label):?><option value="<?=e($v)?>" <?=$cupboardFilter===$v?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label><button>Apply</button></form></details>
<p class="muted"><?=$supplySection==='shopping'?'Low supplies and partner claims. Check anything already on the way before buying again.':'Save the exact product once, then use its links whenever either of you needs to reorder.'?></p>
<div class="cupboard-list">
<?php foreach($cupboardRows as $c):$cid=(int)$c['id'];$incoming=$cupboardIncoming[$cid]??[];$last=$cupboardLast[$cid]??null;$levelClass=$c['stock_level']==='Out'?'out':($c['stock_level']==='Running low'?'low':'enough');?>
<article class="panel cupboard-card" id="cupboard-<?=$cid?>"><div class="cupboard-card-head"><div><p class="eyebrow"><?=e($c['category'])?></p><h2><?=e($c['name'])?></h2></div><span class="cupboard-level level-<?=$levelClass?>"><?=e($c['stock_level'])?></span></div>
<?php if($c['specification']):?><p class="cupboard-spec"><?=e($c['specification'])?></p><?php endif;?><?php if($c['stock_note']):?><p class="cupboard-stock-note"><?=e($c['stock_note'])?></p><?php endif;?>
<div class="cupboard-facts"><span><small>Preferred supplier</small><strong><?=e($c['supplier']?:'Add a supplier')?></strong></span><span><small>Usual quantity / packs</small><strong><?=(int)$c['usual_quantity']?></strong></span><?php if($last):?><span><small>Last order · items total</small><strong><?=money((int)$last['item_cost'])?> for <?=(int)$last['quantity']?></strong></span><?php elseif($c['usual_cost']!==null):?><span><small>Usual items total</small><strong><?=money((int)$c['usual_cost'])?></strong></span><?php endif;?></div>
<?php if($c['requested_by']):?><p class="muted">Requested by <?=e($c['requested_by'])?></p><?php endif;?>
<?php if($c['owner']):?><p class="cupboard-owner"><?=e($c['owner'])?> is handling this</p><?php endif;?>
<?php if($incoming):?><div class="cupboard-incoming"><strong>Already on the way</strong><?php foreach($incoming as $p):?><a href="?view=supplies&amp;section=purchases&amp;edit=<?=(int)$p['id']?>#supply-form"><?=(int)$p['quantity']-(int)$p['received_quantity']?> quantity / packs still due<?= $p['expected_date']?' · '.e($p['expected_date']):''?> · View purchase →</a><?php endforeach;?></div><?php endif;?>
<?php if((int)$c['active']):?>
<form method="post" class="cupboard-level-form"><?php csrf();?><input type="hidden" name="action" value="supply_item_change"><input type="hidden" name="return" value="supplies"><input type="hidden" name="return_section" value="<?=e($supplySection)?>"><input type="hidden" name="cupboard_id" value="<?=$cid?>"><input type="hidden" name="cupboard_revision" value="<?=(int)$c['revision']?>"><input type="hidden" name="operation" value="level"><span>Update stock level</span><div><?php foreach(['Enough','Running low','Out'] as $v):?><button class="quiet <?=$c['stock_level']===$v?'selected':''?>" name="stock_level" value="<?=$v?>" <?=$c['stock_level']===$v?'disabled':''?>><?=$v?></button><?php endforeach;?></div></form>
<?php if(!$incoming):?><form method="post" class="cupboard-claim-form"><?php csrf();?><input type="hidden" name="action" value="supply_item_change"><input type="hidden" name="return" value="supplies"><input type="hidden" name="return_section" value="<?=e($supplySection)?>"><input type="hidden" name="cupboard_id" value="<?=$cid?>"><input type="hidden" name="cupboard_revision" value="<?=(int)$c['revision']?>"><input type="hidden" name="operation" value="<?=$c['owner']?'release':'claim'?>"><?php if($c['owner']):?><button class="quick-action">Release <?=e($c['owner'])?>’s claim</button><?php else:?><span>Who is ordering?</span><button class="quick-action" name="owner" value="Jay">Jay will order</button><button class="quick-action" name="owner" value="Tony">Tony will order</button><?php endif;?></form><?php endif;?>
<div class="supply-card-actions"><?php if(!$incoming):?><a class="button" href="?view=supplies&amp;section=purchases&amp;new=1&amp;cupboard_id=<?=$cid?>#supply-form">Record purchase</a><?php endif;?><?php if($c['product_url']):?><a class="quick-action" href="<?=e($c['product_url'])?>" target="_blank" rel="noopener noreferrer">Open product ↗</a><?php endif;?><a class="quick-action" href="?view=supplies&amp;section=<?=e($supplySection)?>&amp;edit_item=<?=$cid?>#cupboard-form">Edit supply</a></div>
<?php else:?><form method="post"><?php csrf();?><input type="hidden" name="action" value="supply_item_change"><input type="hidden" name="return" value="supplies"><input type="hidden" name="return_section" value="cupboard"><input type="hidden" name="cupboard_id" value="<?=$cid?>"><input type="hidden" name="cupboard_revision" value="<?=(int)$c['revision']?>"><button name="operation" value="restore">Restore supply</button></form><?php endif;?>
<details class="cupboard-details"><summary>Notes, backup &amp; purchase history <span aria-hidden="true">⌄</span></summary><div><?php if($c['notes']):?><p><?=nl2br(e($c['notes']))?></p><?php endif;?><?php if($c['backup_supplier']||$c['backup_url']):?><p>Backup: <?=e($c['backup_supplier']?:'Alternative supplier')?><?php if($c['backup_url']):?> · <a target="_blank" rel="noopener noreferrer" href="<?=e($c['backup_url'])?>">Open backup product ↗</a><?php endif;?></p><?php endif;?>
<a class="quick-action" href="?view=supplies&amp;section=purchases&amp;cupboard_filter=<?=$cid?>">Purchase history →</a><small>Last updated <?=e(date('d M Y, H:i',strtotime($c['updated'])))?></small>
<?php if((int)$c['active']&&!$incoming&&!$c['owner']):?><form method="post"><?php csrf();?><input type="hidden" name="action" value="supply_item_change"><input type="hidden" name="return" value="supplies"><input type="hidden" name="return_section" value="cupboard"><input type="hidden" name="cupboard_id" value="<?=$cid?>"><input type="hidden" name="cupboard_revision" value="<?=(int)$c['revision']?>"><button class="quiet" name="operation" value="archive">Archive supply</button></form><?php endif;?></div></details>
</article><?php endforeach;?>
<?php if(!$cupboardRows):?><section class="panel cupboard-empty"><h2><?=$supplySection==='shopping'?'Nothing on this shopping list':'Build your shared supplies cupboard'?></h2><p class="muted"><?=$supplySection==='shopping'?'Mark a supply Running low or Out, or add a request. Items stay visible while someone is handling them.':'Save your labels, ink and packaging with the exact model and supplier links. You can also open Purchases and save an existing purchase as a supply.'?></p><a class="quick-action" href="?view=supplies&amp;section=<?=e($supplySection)?>&amp;new_item=1#cupboard-form"><?=$supplySection==='shopping'?'+ Add request':'+ Add supply'?></a></section><?php endif;?></div>
<?php else:?>
<?php if($supplyFormOpen&&$supplyEditId>0&&(int)$supplyForm['id']===0):?><p class="error">That purchase could not be found.</p>
<?php elseif($supplyFormOpen):?>
<section class="panel" id="supply-form"><div class="heading"><h2><?=(int)$supplyForm['id']>0?'Edit purchase':'Add purchase'?></h2><a class="quick-action" href="?view=supplies">Close</a></div>
<form method="post" class="supply-form"><input type="hidden" name="action" value="business_supply_save"><input type="hidden" name="return" value="supplies"><input type="hidden" name="return_section" value="purchases"><?php csrf();?>
<?php foreach(['id','revision','request_key','cupboard_id','cupboard_revision'] as $field):?><input type="hidden" name="<?=$field?>" value="<?=e($supplyForm[$field])?>"><?php endforeach;?>
<?php if($purchaseCupboard):?><p class="cupboard-purchase-note supply-wide">Linked to <a href="?view=supplies&amp;section=cupboard#cupboard-<?=(int)$purchaseCupboard['id']?>"><?=e($purchaseCupboard['name'])?></a>. Check the actual price before saving. Use delivery check-in below when it arrives to update the cupboard.</p><?php endif;?>
<label class="supply-wide">Item / purchase<input name="item" maxlength="200" value="<?=e($supplyForm['item'])?>" placeholder="e.g. 500 thermal labels" required></label>
<label>Category<select name="category"><?php foreach(supplyCategories() as $category):?><option <?=$supplyForm['category']===$category?'selected':''?>><?=e($category)?></option><?php endforeach;?></select></label>
<label>Quantity / packs<input type="number" name="quantity" min="1" max="100000" value="<?=e($supplyForm['quantity'])?>" required></label>
<label>Ordered from<input name="supplier" maxlength="200" value="<?=e($supplyForm['supplier'])?>" placeholder="Supplier or website" required></label>
<label>Supplier order reference<input name="reference" maxlength="200" value="<?=e($supplyForm['reference'])?>"></label>
<label>Order date<input type="date" name="ordered_date" max="<?=$supplyToday?>" value="<?=e($supplyForm['ordered_date'])?>" required></label>
<label>Expected delivery<input type="date" name="expected_date" value="<?=e($supplyForm['expected_date'])?>"></label>
<label>Items total (£)<input type="number" name="item_cost" min="0" max="100000" step="0.01" value="<?=e($supplyForm['item_cost'])?>" required><small>Total for the whole quantity, including tax.</small></label>
<label>Delivery cost (£)<input type="number" name="shipping" min="0" max="100000" step="0.01" value="<?=e($supplyForm['shipping'])?>" required><small>Enter once, separate from the items total.</small></label>
<label>Delivery status<select name="status"><?php foreach(['Ordered','Dispatched','Received','Cancelled'] as $status):?><option <?=$supplyForm['status']===$status?'selected':''?>><?=$status?></option><?php endforeach;?></select></label>
<label>Payment status<select name="payment_status"><?php foreach(['Unpaid','Paid','Refunded'] as $status):?><option <?=$supplyForm['payment_status']===$status?'selected':''?>><?=$status?></option><?php endforeach;?></select></label>
<label>Payment date<input type="date" name="paid_date" max="<?=$supplyToday?>" value="<?=e($supplyForm['paid_date'])?>"><small>Required for Paid or Refunded purchases.</small></label>
<label>Refund date<input type="date" name="refunded_date" max="<?=$supplyToday?>" value="<?=e($supplyForm['refunded_date'])?>"><small>For a full refund only. Keep the original payment date.</small></label>
<label class="supply-wide">Product / reorder link<input type="url" name="product_url" maxlength="2000" value="<?=e($supplyForm['product_url'])?>" placeholder="https://…"></label>
<label class="supply-wide">Courier &amp; tracking number<input name="tracking_reference" maxlength="200" value="<?=e($supplyForm['tracking_reference'])?>" placeholder="e.g. Royal Mail · tracking number"></label>
<label class="supply-wide">Tracking link<input type="url" name="tracking_url" maxlength="2000" value="<?=e($supplyForm['tracking_url'])?>" placeholder="https://…"></label>
<label class="supply-wide">Notes<textarea name="notes" maxlength="4000" rows="3" placeholder="Sizes, colours, what is included, or delivery notes"><?=e($supplyForm['notes'])?></textarea></label>
<p class="muted supply-wide">Save one item or a whole supplier purchase here. Record its total once. Mark it Paid when money leaves the business; it will then appear in supply outgoings. Receiving these supplies does not add sale stock.</p>
<div class="supply-wide supply-form-actions"><button>Save purchase</button><a class="quick-action" href="?view=supplies">Cancel</a></div>
</form></section>
<?php endif;?>
<section class="report-metrics supply-metrics"><article><span>On the way</span><strong><?=count($supplyOpen)?></strong><small>Purchases awaiting delivery</small></article><article><span>Still to pay</span><strong><?=money($supplyAll['unpaid'])?></strong><small>All non-cancelled unpaid purchases</small></article><article><span>Spent this month</span><strong><?=money($supplyMonth['net'])?></strong><small>Payments less refunds</small></article></section>
<?php if($supplyHistoryId>0):?><p class="cupboard-purchase-note">Purchase history for <?=e($cupboardById[$supplyHistoryId]['name']??'this supply')?> · <a href="?view=supplies&amp;section=purchases&amp;status=all">Show all purchases</a></p><?php endif;?>
<form method="get" class="supply-filters"><input type="hidden" name="view" value="supplies"><input type="hidden" name="section" value="purchases"><?php if($supplyHistoryId>0):?><input type="hidden" name="cupboard_filter" value="<?=$supplyHistoryId?>"><?php endif;?><label>Search purchases<input name="q" value="<?=e($supplySearch)?>" placeholder="Item, supplier or tracking"></label><label>Show<select name="status"><?php foreach(['outstanding'=>'On the way','all'=>'All purchases','Received'=>'Received','Cancelled'=>'Cancelled'] as $key=>$label):?><option value="<?=e($key)?>" <?=$supplyFilter===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label><button>Apply</button></form>
<p class="muted"><?=count($supplyRows)?> purchase<?=count($supplyRows)===1?'':'s'?> shown</p>
<div class="supply-list">
<?php foreach($supplyRows as $r):$total=(int)$r['item_cost']+(int)$r['shipping'];?>
<article class="panel supply-card"><div class="supply-card-head"><div><p class="eyebrow"><?=e($r['category'])?></p><h2><?=e($r['item'])?></h2><p class="muted"><?=e($r['supplier'])?> · <?=(int)$r['quantity']?> quantity / packs</p></div><strong class="supply-total"><?=money($total)?></strong></div>
<div class="supply-badges"><span class="badge"><?=e($r['status'])?></span><span class="badge"><?=e($r['payment_status'])?></span></div>
<?php if($r['cupboard_id']&&isset($cupboardById[(int)$r['cupboard_id']])):?><a class="incoming-edit-link" href="?view=supplies&amp;section=cupboard#cupboard-<?=(int)$r['cupboard_id']?>">Cupboard: <?=e($cupboardById[(int)$r['cupboard_id']]['name'])?> →</a><?php endif;?>
<?php if((int)$r['received_quantity']>0):?><p class="muted"><?=(int)$r['received_quantity']?> of <?=(int)$r['quantity']?> quantity / packs received</p><?php endif;?>
<dl class="supply-meta"><div><dt>Ordered</dt><dd><?=e($r['ordered_date'])?></dd></div><?php if($r['expected_date']):?><div><dt>Expected</dt><dd><?=e($r['expected_date'])?></dd></div><?php endif;?><div><dt>Items + delivery</dt><dd><?=money((int)$r['item_cost'])?> + <?=money((int)$r['shipping'])?></dd></div><?php if($r['reference']):?><div><dt>Reference</dt><dd><?=e($r['reference'])?></dd></div><?php endif;?><?php if($r['paid_date']):?><div><dt>Paid</dt><dd><?=e($r['paid_date'])?></dd></div><?php endif;?><?php if($r['refunded_date']):?><div><dt>Refunded</dt><dd><?=e($r['refunded_date'])?></dd></div><?php endif;?><?php if($r['tracking_reference']):?><div class="supply-wide"><dt>Tracking</dt><dd><?=e($r['tracking_reference'])?></dd></div><?php endif;?></dl>
<?php if($r['notes']):?><p class="supply-notes"><?=nl2br(e($r['notes']))?></p><?php endif;?>
<div class="supply-card-actions"><a class="button" href="?view=supplies&amp;edit=<?=(int)$r['id']?>#supply-form">Edit purchase</a><?php if($r['tracking_url']):?><a class="quick-action" target="_blank" rel="noopener noreferrer" href="<?=e($r['tracking_url'])?>">Track delivery ↗</a><?php endif;?><?php if($r['product_url']):?><a class="quick-action" target="_blank" rel="noopener noreferrer" href="<?=e($r['product_url'])?>">Product / reorder ↗</a><?php endif;?><?php if(!$r['cupboard_id']):?><a class="quick-action" href="?view=supplies&amp;section=cupboard&amp;from_purchase=<?=(int)$r['id']?>#cupboard-form">Save as cupboard item</a><?php endif;?></div>
<?php if(in_array($r['status'],['Ordered','Dispatched'],true)&&(int)$r['received_quantity']<(int)$r['quantity']):$receiptCupboard=$cupboardById[(int)$r['cupboard_id']]??null;?>
<details class="cupboard-details supply-receive"><summary>Check in delivery <span aria-hidden="true">⌄</span></summary><form method="post" class="supply-form"><?php csrf();?><input type="hidden" name="action" value="supply_delivery_receive"><input type="hidden" name="return" value="supplies"><input type="hidden" name="return_section" value="purchases"><input type="hidden" name="purchase_id" value="<?=(int)$r['id']?>"><input type="hidden" name="purchase_revision" value="<?=(int)$r['revision']?>"><input type="hidden" name="cupboard_revision" value="<?=(int)($receiptCupboard['revision']??0)?>">
<label class="supply-wide">Quantity / packs received now<input type="number" name="received_quantity" min="1" max="<?=(int)$r['quantity']-(int)$r['received_quantity']?>" value="<?=(int)$r['quantity']-(int)$r['received_quantity']?>" required><small>Only what arrived in this delivery. Any remainder stays on the way.</small></label>
<?php if($receiptCupboard):?><label>Cupboard level after delivery<select name="stock_level"><?php foreach(['Enough','Running low','Out'] as $v):?><option <?=$receiptCupboard['stock_level']===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></label><label>Updated stock note<input name="stock_note" maxlength="500" value="<?=e($receiptCupboard['stock_note'])?>" placeholder="e.g. Three rolls on the shelf"></label><?php endif;?><button class="supply-wide">Save delivery check-in</button></form></details>
<?php endif;?></article>
<?php endforeach;?>
<?php if(!$supplyRows):?><section class="panel"><h2><?=$businessSupplies?'No matching purchases':'Keep your supplies in one place'?></h2><p class="muted"><?=$businessSupplies?'Try All purchases or a different search.':'Add labels, ink, packaging or equipment with the supplier, total cost and tracking details. Your recorded payments will feed into Reports.'?></p><a class="quick-action" href="?view=supplies&amp;new=1#supply-form">+ Add purchase</a></section><?php endif;?></div>
<?php endif;?>
<?php elseif($view==='shipping'):?>
<?php require __DIR__.'/shipping-intelligence.php'; ?>
<?php elseif($view==='business_alerts'):?>
<?php require_once __DIR__.'/business-alerts.php'; $businessPrefs=ankhAlertPrefs($db); 
$alertRows=$db->query("SELECT o.id,o.customer,o.created,COALESCE(s.value,'') AS email_state FROM orders o LEFT JOIN settings s ON s.key='business_alert_new_order:'||o.id ORDER BY o.id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="heading"><div><h1>Business Alerts</h1><p class="muted page-description">Private email notifications for Jay and Tony, through Google Workspace.</p></div></div>
<?php if(isset($_GET['saved'])):?><p class="notice">Alert preferences saved.</p><?php endif;?>
<?php if(isset($_GET['sent'])):?><p class="notice">Test emails accepted by Google. Check your inboxes.</p><?php endif;?>
<?php if(isset($_GET['order_preview_sent'])):?><p class="notice">New-order preview sent to info@ankhpeptides.com. Check it in Gmail on your iPhone.</p><?php endif;?>
<section class="panel" style="max-width:720px;padding:22px">
<h2>Email recipients</h2><p class="muted">Business notifications go to a single shared inbox. Sender: admin@ankhpeptides.com</p>
<form method="post"><?php csrf();?><input type="hidden" name="action" value="business_alert_save">
<div style="padding:14px 16px;border:1px solid var(--border);border-radius:12px;margin:12px 0"><strong>info@ankhpeptides.com</strong><p class="muted" style="margin:6px 0 0">All business alerts go to this inbox once. Emails are sent from admin@ankhpeptides.com.</p></div>
<label style="display:flex;gap:12px;align-items:center;margin:14px 0"><input type="checkbox" name="alert_new_order" value="1" style="width:auto" <?=$businessPrefs['new_order']?'checked':''?>>Email me immediately when a new order is added</label>
<h2>Daily digest</h2>
<label style="display:flex;gap:12px;align-items:center;margin:14px 0"><input type="checkbox" name="alert_daily" value="1" style="width:auto" <?=$businessPrefs['daily']?'checked':''?>>Send one daily morning email</label>
<p class="muted">Include these sections in the daily summary:</p>
<?php foreach(['delivery'=>'Deliveries scheduled tomorrow','stock'=>'Low stock across both fridges','payment'=>'Payment reminder link','recurring'=>'Recurring orders and reorder planner links'] as $key=>$description):?>
<label style="display:flex;gap:12px;align-items:center;margin:12px 0"><input type="checkbox" name="alert_<?=e($key)?>" value="1" style="width:auto" <?=$businessPrefs[$key]?'checked':''?>><?=e($description)?></label>
<?php endforeach;?>
<button type="submit">Save alert preferences</button></form>
<form method="post" style="margin-top:18px"><?php csrf();?><input type="hidden" name="action" value="business_alert_test"><button class="quiet" type="submit">Send branded test email</button></form>
<form method="post" style="margin-top:12px"><?php csrf();?><input type="hidden" name="action" value="business_alert_preview_order"><button class="quiet" type="submit">Preview latest order email</button></form>
<p class="muted" style="margin-top:10px">Preview uses the most recent saved order without creating a new order or changing stock. The email subject says PREVIEW. Daily sending requires a scheduled task on Krystal. No customer emails are sent.</p>
</section>
<section class="panel" style="max-width:720px;padding:20px;margin-top:16px">
<h2>New-order email history</h2>
<p class="muted">The 12 most recent orders. “Accepted” means Google SMTP accepted the message; it does not guarantee delivery to Gmail. Failed messages show the reason and can be retried. No orders or stock are changed.</p>
<?php if(empty($businessPrefs['new_order'])):?><p role="status" style="padding:12px;border-radius:9px;background:rgba(178,124,36,.12)">New-order notifications are currently switched off. Tick “Email me immediately when a new order is added” above, then Save.</p><?php endif;?>
<?php foreach($alertRows as $mailRow):
 $mailId=(int)$mailRow['id'];$mailState=(string)$mailRow['email_state'];
 $mailFailed=str_starts_with($mailState,'failed:');$mailBusy=str_starts_with($mailState,'sending');
 $mailAccepted=($mailState!==''&&!$mailFailed&&!$mailBusy);
 $mailLabel=$mailFailed?'Failed':($mailBusy?'Sending / uncertain':($mailAccepted?'Accepted by Google':'Not recorded'));
 $mailDetails=$mailFailed?substr($mailState,8):($mailAccepted?'Google accepted the SMTP submission.':($mailBusy?'Do not resend until the previous attempt has been investigated.':'No automatic send attempt is recorded for this order.'));
?>
<div style="border-top:1px solid var(--border);padding:15px 0">
<div style="display:flex;justify-content:space-between;gap:12px;align-items:start;flex-wrap:wrap">
<div><strong>ANK-<?=str_pad((string)$mailId,4,'0',STR_PAD_LEFT)?> · <?=e($mailRow['customer'])?></strong><div class="muted" style="font-size:12px;margin-top:3px"><?=e(date('d M Y, H:i',strtotime((string)$mailRow['created'])))?></div></div>
<strong style="font-size:13px"><?=e($mailLabel)?></strong>
</div>
<p class="muted" style="font-size:12px;line-height:1.5;overflow-wrap:anywhere;margin:8px 0"><?=e($mailDetails)?></p>
<?php if($mailFailed||$mailState===''):?>
<form method="post" onsubmit="return confirm('Send the ANK-<?=str_pad((string)$mailId,4,'0',STR_PAD_LEFT)?> email to info@ankhpeptides.com now? This does not create another order.');"><?php csrf();?><input type="hidden" name="action" value="business_alert_retry_order"><input type="hidden" name="id" value="<?=$mailId?>"><button type="submit" class="quiet"><?=$mailFailed?'Retry failed email':'Send missing email'?></button></form>
<?php endif;?>
</div>
<?php endforeach;?>
</section>
<?php elseif($view==='more'):?>
<div class="heading"><div><h1>More</h1><p class="muted page-description">Products, profit and integrations.</p></div></div>
<div class="more-grid">
<a class="more-card" href="?view=business_alerts"><span class="more-icon">✉</span><div><h2>Business Alerts</h2><p>Email reminders to Jay and Tony, daily summaries, low stock and deliveries.</p></div><b>›</b></a>
<a class="more-card" href="?view=shipping"><span class="more-icon">◈</span><div><h2>Shipping Intelligence</h2><p>China holidays, disruption countdowns and mobile-friendly stock protection forecasts.</p></div><b>›</b></a>
<a class="more-card" href="?view=activity"><span class="more-icon">≡</span><div><h2>Order &amp; payment activity</h2><p>Review recent changes to orders, payments, preparation and deliveries.</p></div><b>›</b></a>
<a class="more-card" href="?view=balances"><span class="more-icon">£</span><div><h2>Customer balances</h2><p><?=money($balancesTotalOutstanding)?> currently outstanding across <?=count($balanceAccounts)?> customer<?=count($balanceAccounts)===1?'':'s'?>.</p></div><b>›</b></a>
<a class="more-card" href="?view=enquiries"><span class="more-icon">✉</span><div><h2>Customer enquiries</h2><p>Read product questions, reply and manage their status.</p></div><b>›</b></a>
<a class="more-card" href="?view=products"><span class="more-icon">◫</span><div><h2>Products</h2><p>Prices, supplier costs and availability.</p></div><b>›</b></a>
<a class="more-card" href="?view=supplier"><span class="more-icon">◎</span><div><h2>Supplier &amp; Profit Centre</h2><p>Supplier box prices, margins, combined purchases and incoming stock.</p></div><b>›</b></a>
<a class="more-card" href="?view=reorder"><span class="more-icon">↗</span><div><h2>Reorder planner</h2><p>See what’s running low and suggested supplier pack quantities.</p></div><b>›</b></a>
<a class="more-card" href="?view=stock"><span class="more-icon">▣</span><div><h2>Stock control</h2><p>Opening counts, receipts, adjustments and movement history.</p></div><b>›</b></a>
<a class="more-card" href="?view=supplies"><span class="more-icon">▤</span><div><h2>Business Supplies</h2><p>Labels, ink, envelopes and other purchases. Suppliers, tracking and outgoings.</p></div><b>›</b></a>
<a class="more-card" href="?view=reports"><span class="more-icon">£</span><div><h2>Profit & reports</h2><p>Sales, costs, fees, profit and product performance.</p></div><b>›</b></a>
<a class="more-card" href="?view=reta"><span class="more-icon">↻</span><div><h2>Cycle Planner</h2><p>Recurring order calendar, demand forecast, projected profit and stock needed.</p></div><b>›</b></a>
<a class="more-card" href="?view=sheets"><span class="more-icon">▦</span><div><h2>Google Sheets</h2><p>Connection, sync and reporting setup.</p></div><b>›</b></a>
</div>
<div class="more-grid utility-grid">
<section class="panel device-card"><span class="more-icon">⌂</span><div><h2>Install ANKH</h2><p>Add ANKH to your iPhone Home Screen for a full-screen app experience.</p></div><button type="button" id="install-ankh-app" class="quiet">Install / instructions</button><div id="install-ankh-help" class="install-help" hidden><strong>On iPhone</strong><p>Open this page in Safari, tap Share, then choose <b>Add to Home Screen</b>.</p></div></section>
<section class="panel device-card"><span class="more-icon">◎</span><div><h2>Face ID / Passkey</h2><p>Use your iPhone passkey instead of entering the PIN on future sign-ins. PIN stays available as a fallback.</p><small><?=$passkeyCount?> passkey<?=$passkeyCount===1?'':'s'?> registered</small></div><button type="button" id="setup-passkey" class="quiet">Set up Face ID / Passkey</button><p id="setup-passkey-status" class="muted passkey-status"></p></section>
</div>

<?php else:?>
<div id="order-saved-marker"></div>
<?php if($savedOrder):?>
<section class="saved-order">
<div class="saved-check">✓</div><p class="eyebrow">ORDER SAVED</p><h1><?=$savedOrder['reference']?></h1><p class="saved-customer"><?=e($savedOrder['customer'])?></p>
<div class="saved-total"><?=money($savedOrder['total_pence'])?></div><span class="badge status-<?=e(statusClass($savedOrder['status']))?>"><?=e($savedOrder['status'])?></span>
<?php
require_once __DIR__.'/business-alerts.php';
$savedEmailState=ankhNewOrderMailState($db,(int)($savedOrder['id']??0));
$savedEmailFailed=str_starts_with($savedEmailState,'failed:');
$savedEmailAccepted=$savedEmailState!==''&&!$savedEmailFailed&&!str_starts_with($savedEmailState,'sending');
$savedEmailStatus=$savedEmailFailed?'Email notification failed: '.substr($savedEmailState,8):($savedEmailAccepted?'Order email accepted by Google SMTP for info@ankhpeptides.com.':(empty(ankhAlertPrefs($db)['new_order'])?'New-order notifications are disabled.':'No completed email submission recorded for this order.'));
?>
<p role="status" class="muted" style="margin-top:10px;font-size:13px;line-height:1.5"><?=e($savedEmailStatus)?> <a href="?view=business_alerts">Check email status →</a></p>
<div class="saved-items"><?php foreach($savedOrder['items'] as $savedItem):?><div class="line"><span><?=e($savedItem['quantity'].' × '.$savedItem['name'].(!empty($savedItem['presentation'])?' · '.$savedItem['presentation']:'').((int)($savedItem['discount_pence']??0)>0?' · Family & Friends':''))?></span><strong><?=money($savedItem['unit_price_pence']*$savedItem['quantity'])?></strong></div><?php endforeach;?><?php if((int)$savedOrder['delivery_charge_pence']>0):?><div class="line"><span>Postage / delivery charge</span><strong><?=money($savedOrder['delivery_charge_pence'])?></strong></div><?php endif;?></div><div class="saved-meta"><span>Paid: <?=money((int)($savedOrder['paid_amount_pence']??0))?></span><span>Balance: <?=money((int)($savedOrder['balance_due_pence']??0))?></span><?php if($savedOrder['payment_method']):?><span>Payment: <?=e($savedOrder['payment_method'])?></span><?php endif;?><?php if($savedOrder['delivery_method']):?><span>Delivery: <?=e($savedOrder['delivery_method'])?></span><?php endif;?><?php if($savedOrder['assigned_to']):?><span>Assigned: <?=e($savedOrder['assigned_to'])?></span><?php endif;?></div>
<div class="saved-actions"><a class="button" href="?view=dashboard">Dashboard</a><a class="button" href="?view=new">+ New order</a><a class="quick-action" href="?view=orders">View orders</a><a class="quick-action" href="?view=new&amp;repeat_order=<?=$savedOrder['id']?>">Repeat order</a><a class="quick-action" href="?api=order-pdf&amp;id=<?=$savedOrder['id']?>&amp;type=invoice">Invoice PDF</a><button type="button" class="quick-action quiet" data-share-pdf="<?=$savedOrder['id']?>" data-document-type="receipt">Share receipt</button></div>
</section>
<?php else:?><p class="error">That saved order could not be found.</p><a class="button" href="?view=orders">Back to orders</a><?php endif;?>
<?php endif;?>
</main>

<div id="ankh-assistant-overlay" class="ankh-assistant-overlay" hidden>
 <section class="ankh-assistant-card realtime-card" role="dialog" aria-modal="true" aria-labelledby="ankh-assistant-title">
  <button type="button" id="ankh-assistant-close" class="ankh-assistant-close" aria-label="End voice conversation">×</button>
  <div class="assistant-live-badge"><i></i><span id="ankh-assistant-live-label">LIVE VOICE</span></div>
  <button type="button" class="ankh-assistant-orb realtime-orb" id="ankh-assistant-orb" aria-label="Voice assistant status"><span>☥</span><b class="realtime-wave wave-a"></b><b class="realtime-wave wave-b"></b></button>
  <p class="eyebrow">ANKH ASSISTANT</p>
  <h2 id="ankh-assistant-title">Connecting…</h2>
  <p id="ankh-assistant-message">Starting a live conversation with ANKH.</p>
  <div id="ankh-assistant-live-log" class="ankh-assistant-live-log" hidden></div>
  <div class="ankh-assistant-examples realtime-examples" id="ankh-assistant-examples">
   <span>You can ask naturally</span>
   <p>“How much is Reta 10mg in a pen?”</p>
   <p>“What does it cost us?”</p>
   <p>“How much profit have we made in the last 3 months?”</p>
   <p>“What does Tony still need to deliver?”</p>
  </div>
  <div class="assistant-live-controls">
   <button type="button" id="ankh-assistant-mute" class="quiet assistant-mute" disabled>Mute mic</button>
   <button type="button" id="ankh-assistant-retry" class="quiet assistant-retry" hidden>Reconnect</button>
   <button type="button" id="ankh-assistant-end" class="assistant-end">End conversation</button>
  </div>
  <small id="ankh-assistant-help">Once connected, just speak — ANKH will answer and keep listening.</small>
 </section>
</div>
<script>
let ankhInstallPrompt=null;
addEventListener('beforeinstallprompt',event=>{event.preventDefault();ankhInstallPrompt=event});
function ankhHaptic(ms=8){try{if(navigator.vibrate)navigator.vibrate(ms)}catch(_){}}
document.addEventListener('click',event=>{if(event.target.closest('button,a,.line-format-button,.customer-result,.product-result'))ankhHaptic(7)},{passive:true});
document.querySelector('#install-ankh-app')?.addEventListener('click',async()=>{
 const help=document.querySelector('#install-ankh-help');
 if(ankhInstallPrompt){ankhInstallPrompt.prompt();try{await ankhInstallPrompt.userChoice}catch(_){}ankhInstallPrompt=null}
 else if(help)help.hidden=!help.hidden;
});
function ankhB64ToBuffer(value){value=value.replace(/-/g,'+').replace(/_/g,'/');while(value.length%4)value+='=';const raw=atob(value),out=new Uint8Array(raw.length);for(let i=0;i<raw.length;i++)out[i]=raw.charCodeAt(i);return out.buffer}
function ankhBufferToB64(buffer){const bytes=new Uint8Array(buffer);let raw='';bytes.forEach(b=>raw+=String.fromCharCode(b));return btoa(raw).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'')}
document.querySelector('#setup-passkey')?.addEventListener('click',async event=>{
 const button=event.currentTarget,status=document.querySelector('#setup-passkey-status');button.disabled=true;if(status)status.textContent='Waiting for your iPhone…';
 try{
  if(!window.PublicKeyCredential||!navigator.credentials)throw new Error('Passkeys are not available in this browser.');
  const start=await fetch('?api=passkey-options',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:<?=json_encode($_SESSION['csrf'])?>,kind:'register'})});const payload=await start.json();if(!start.ok||!payload.ok)throw new Error(payload.error||'Passkey setup could not start.');
  const opts=payload.publicKey;opts.challenge=ankhB64ToBuffer(opts.challenge);opts.user.id=ankhB64ToBuffer(opts.user.id);
  const cred=await navigator.credentials.create({publicKey:opts});if(!cred)throw new Error('Passkey setup was cancelled.');
  const finish=await fetch('?api=passkey-register',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:<?=json_encode($_SESSION['csrf'])?>,name:'iPhone / Face ID',rawId:ankhBufferToB64(cred.rawId),clientDataJSON:ankhBufferToB64(cred.response.clientDataJSON),attestationObject:ankhBufferToB64(cred.response.attestationObject)})});const done=await finish.json();if(!finish.ok||!done.ok)throw new Error(done.error||'Passkey could not be saved.');
  if(status)status.textContent='Face ID / Passkey is ready ✓';ankhHaptic(20);
 }catch(error){if(status)status.textContent=error?.message||'Passkey setup failed.'}
 finally{button.disabled=false}
});

const orderDraftKey='ankh-order-draft-v2';
if(document.querySelector('#order-saved-marker')){try{localStorage.removeItem(orderDraftKey)}catch(_){}}
document.querySelectorAll('[data-copy-text]').forEach(button=>button.addEventListener('click',async()=>{const value=button.dataset.copyText||'';try{await navigator.clipboard.writeText(value);const old=button.textContent;button.textContent='Copied ✓';setTimeout(()=>button.textContent=old,1200)}catch(_){const area=document.createElement('textarea');area.value=value;document.body.append(area);area.select();document.execCommand('copy');area.remove()}}));
document.querySelectorAll('[data-share-pdf]').forEach(button=>button.addEventListener('click',async()=>{
 const id=button.dataset.sharePdf,type=button.dataset.documentType||'receipt',url='?api=order-pdf&id='+encodeURIComponent(id)+'&type='+encodeURIComponent(type),old=button.textContent;button.disabled=true;button.textContent='Preparing…';
 try{
  const response=await fetch(url,{credentials:'same-origin'});if(!response.ok)throw new Error('Could not create the PDF.');
  const blob=await response.blob(),file=new File([blob],'ANKH-ANK-'+String(id).padStart(4,'0')+'-'+type+'.pdf',{type:'application/pdf'});
  if(navigator.share&&navigator.canShare?.({files:[file]}))await navigator.share({files:[file],title:'ANKH '+type});
  else{const objectUrl=URL.createObjectURL(blob);window.open(objectUrl,'_blank');setTimeout(()=>URL.revokeObjectURL(objectUrl),60000)}
 }catch(error){alert(error?.message||'Could not share the PDF.')}
 finally{button.disabled=false;button.textContent=old}
}));

const ankhAssistantButton=document.querySelector('#ankh-assistant-button'),ankhAssistantOverlay=document.querySelector('#ankh-assistant-overlay'),ankhAssistantClose=document.querySelector('#ankh-assistant-close'),ankhAssistantEnd=document.querySelector('#ankh-assistant-end'),ankhAssistantMute=document.querySelector('#ankh-assistant-mute'),ankhAssistantRetry=document.querySelector('#ankh-assistant-retry'),ankhAssistantOrb=document.querySelector('#ankh-assistant-orb'),ankhAssistantTitle=document.querySelector('#ankh-assistant-title'),ankhAssistantMessage=document.querySelector('#ankh-assistant-message'),ankhAssistantExamples=document.querySelector('#ankh-assistant-examples'),ankhAssistantHelp=document.querySelector('#ankh-assistant-help'),ankhAssistantLiveLabel=document.querySelector('#ankh-assistant-live-label'),ankhAssistantLiveLog=document.querySelector('#ankh-assistant-live-log');
let ankhRealtimePc=null,ankhRealtimeDc=null,ankhRealtimeStream=null,ankhRealtimeAudio=null,ankhRealtimeConnected=false,ankhRealtimeMuted=false,ankhRealtimeTranscript='',ankhHandledCalls=new Set();

function assistantRealtimeState(state,title,message){
 if(ankhAssistantTitle)ankhAssistantTitle.textContent=title;
 if(ankhAssistantMessage)ankhAssistantMessage.textContent=message;
 ['connecting','listening','thinking','speaking','error'].forEach(name=>ankhAssistantOverlay?.classList.toggle(name,state===name));
 if(ankhAssistantLiveLabel)ankhAssistantLiveLabel.textContent=state==='connecting'?'CONNECTING':state==='listening'?'LISTENING':state==='thinking'?'CHECKING ANKH':state==='speaking'?'ANKH IS TALKING':state==='error'?'CONNECTION ISSUE':'LIVE VOICE';
 if(ankhAssistantExamples)ankhAssistantExamples.hidden=state!=='listening'||(ankhAssistantLiveLog&&!ankhAssistantLiveLog.hidden);
 if(ankhAssistantRetry)ankhAssistantRetry.hidden=state!=='error';
 if(ankhAssistantMute)ankhAssistantMute.disabled=!ankhRealtimeConnected;
 if(ankhAssistantHelp)ankhAssistantHelp.textContent=state==='connecting'?'Setting up secure live voice…':state==='listening'?'Just speak naturally — follow-up questions keep the same conversation':state==='thinking'?'Checking your live ANKH data…':state==='speaking'?'You can interrupt ANKH by speaking':state==='error'?'Tap Reconnect to try again':'Live conversation';
}
function assistantAddLiveLog(text){
 text=(text||'').trim();if(!text||!ankhAssistantLiveLog)return;
 const bubble=document.createElement('div');bubble.className='assistant-live-bubble assistant';bubble.textContent=text;ankhAssistantLiveLog.append(bubble);ankhAssistantLiveLog.hidden=false;ankhAssistantLiveLog.scrollTop=ankhAssistantLiveLog.scrollHeight;
 if(ankhAssistantExamples)ankhAssistantExamples.hidden=true;
}
function assistantRealtimeCleanup(){
 ankhRealtimeConnected=false;ankhRealtimeMuted=false;ankhHandledCalls.clear();ankhRealtimeTranscript='';
 try{ankhRealtimeDc?.close()}catch(_){}
 try{ankhRealtimePc?.close()}catch(_){}
 ankhRealtimeStream?.getTracks().forEach(track=>track.stop());
 if(ankhRealtimeAudio){try{ankhRealtimeAudio.pause()}catch(_){};ankhRealtimeAudio.srcObject=null;ankhRealtimeAudio.remove()}
 ankhRealtimeDc=null;ankhRealtimePc=null;ankhRealtimeStream=null;ankhRealtimeAudio=null;
 if(ankhAssistantMute){ankhAssistantMute.disabled=true;ankhAssistantMute.textContent='Mute mic'}
}
function assistantCloseRealtime(){
 assistantRealtimeCleanup();if(ankhAssistantOverlay)ankhAssistantOverlay.hidden=true;document.body.classList.remove('assistant-overlay-open');
}
async function assistantFetchRealtimeTool(item){
 if(!item?.call_id||ankhHandledCalls.has(item.call_id))return null;
 ankhHandledCalls.add(item.call_id);let args={};try{args=JSON.parse(item.arguments||'{}')}catch(_){}
 let output;
 try{
  const response=await fetch('?api=assistant-tool',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:<?=json_encode($_SESSION['csrf'])?>,name:item.name,args})});
  const payload=await response.json().catch(()=>({ok:false,error:'The ANKH data tool returned an unreadable response.'}));
  output=payload.ok?payload.result:{error:payload.error||'Could not read ANKH data.'};
 }catch(error){output={error:error?.message||'Could not read ANKH data.'}}
 return {call_id:item.call_id,output};
}
async function assistantRunRealtimeTools(calls){
 if(!calls.length||!ankhRealtimeDc||ankhRealtimeDc.readyState!=='open')return;
 assistantRealtimeState('thinking','Checking ANKH…','I’m looking that up in the live admin data.');
 const results=(await Promise.all(calls.map(assistantFetchRealtimeTool))).filter(Boolean);
 if(!ankhRealtimeDc||ankhRealtimeDc.readyState!=='open')return;
 results.forEach(result=>ankhRealtimeDc.send(JSON.stringify({type:'conversation.item.create',item:{type:'function_call_output',call_id:result.call_id,output:JSON.stringify(result.output)}})));
 ankhRealtimeDc.send(JSON.stringify({type:'response.create'}));
}
function assistantHandleRealtimeEvent(event){
 if(!event||!event.type)return;
 if(event.type==='input_audio_buffer.speech_started'){
  ankhRealtimeTranscript='';assistantRealtimeState('listening','I’m listening…','Keep talking — I’ll answer when you finish.');
 }else if(event.type==='input_audio_buffer.speech_stopped'){
  assistantRealtimeState('thinking','Got it…','Working out the answer.');
 }else if(event.type==='response.created'){
  assistantRealtimeState('thinking','Thinking…','Using this conversation and your ANKH data.');
 }else if(event.type==='response.output_audio.delta'){
  assistantRealtimeState('speaking','ANKH is talking','You can interrupt at any time by speaking.');
 }else if(event.type==='response.output_audio_transcript.delta'){
  ankhRealtimeTranscript+=(event.delta||'');
 }else if(event.type==='response.output_audio_transcript.done'){
  const text=(event.transcript||ankhRealtimeTranscript||'').trim();if(text)assistantAddLiveLog(text);ankhRealtimeTranscript='';
 }else if(event.type==='response.output_audio.done'){
  if(ankhRealtimeConnected)setTimeout(()=>{if(ankhRealtimeConnected)assistantRealtimeState('listening','I’m listening…','Ask another question or follow up on what we were just talking about.')},300);
 }else if(event.type==='response.done'){
  const outputs=Array.isArray(event.response?.output)?event.response.output:[];const calls=outputs.filter(item=>item?.type==='function_call');
  if(calls.length)assistantRunRealtimeTools(calls);
 }else if(event.type==='error'){
  const message=event.error?.message||'The live voice session hit an error.';assistantRealtimeState('error','Voice connection issue',message);
 }
}
async function assistantConnectRealtime(){
 assistantRealtimeCleanup();
 if(!window.RTCPeerConnection||!navigator.mediaDevices?.getUserMedia){assistantRealtimeState('error','Live voice unavailable','This browser does not support the live voice connection.');return}
 assistantRealtimeState('connecting','Connecting…','Starting a secure live conversation with ANKH.');
 try{
  const tokenBody=new URLSearchParams();tokenBody.set('csrf',<?=json_encode($_SESSION['csrf'])?>);
  const tokenResponse=await fetch('?api=realtime-token',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:tokenBody.toString()});
  const token=await tokenResponse.json().catch(()=>({ok:false,error:'Could not read the Realtime token response.'}));
  if(!tokenResponse.ok||!token.ok||!token.value)throw new Error(token.error||'Could not start ANKH Live Voice.');

  const pc=new RTCPeerConnection();ankhRealtimePc=pc;
  const audio=document.createElement('audio');audio.autoplay=true;audio.playsInline=true;audio.className='ankh-realtime-audio';document.body.append(audio);ankhRealtimeAudio=audio;
  pc.addEventListener('track',event=>{audio.srcObject=event.streams[0];audio.play().catch(()=>{})});
  pc.addEventListener('connectionstatechange',()=>{
   if(pc.connectionState==='connected'){ankhRealtimeConnected=true;assistantRealtimeState('listening','I’m listening…','Ask me anything about ANKH and then keep the conversation going.')}
   if(['failed','disconnected'].includes(pc.connectionState)&&!ankhAssistantOverlay?.hidden)assistantRealtimeState('error','Connection lost','Tap Reconnect to continue the conversation.');
  });

  ankhRealtimeStream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true}});
  ankhRealtimeStream.getAudioTracks().forEach(track=>pc.addTrack(track,ankhRealtimeStream));

  const dc=pc.createDataChannel('oai-events');ankhRealtimeDc=dc;
  dc.addEventListener('message',message=>{try{assistantHandleRealtimeEvent(JSON.parse(message.data))}catch(_){}});
  dc.addEventListener('open',()=>{
   ankhRealtimeConnected=true;assistantRealtimeState('listening','I’m listening…','Ask me anything about ANKH and then keep the conversation going.');
   dc.send(JSON.stringify({type:'response.create',response:{output_modalities:['audio'],instructions:'Greet the user in one short sentence as ANKH Assistant, then ask what they would like to know about the business. Do not call a tool for this greeting.'}}));
  });
  dc.addEventListener('close',()=>{ankhRealtimeConnected=false});

  const offer=await pc.createOffer();await pc.setLocalDescription(offer);
  const sdpResponse=await fetch('https://api.openai.com/v1/realtime/calls',{method:'POST',body:offer.sdp,headers:{Authorization:'Bearer '+token.value,'Content-Type':'application/sdp'}});
  if(!sdpResponse.ok)throw new Error('OpenAI Live Voice could not connect.');
  await pc.setRemoteDescription({type:'answer',sdp:await sdpResponse.text()});
 }catch(error){
  assistantRealtimeCleanup();assistantRealtimeState('error','Couldn’t start live voice',error?.message||'Please try again.');
 }
}
function assistantOpenRealtime(){
 if(ankhAssistantOverlay)ankhAssistantOverlay.hidden=false;document.body.classList.add('assistant-overlay-open');
 if(ankhAssistantLiveLog){ankhAssistantLiveLog.replaceChildren();ankhAssistantLiveLog.hidden=true}
 if(ankhAssistantExamples)ankhAssistantExamples.hidden=false;
 assistantConnectRealtime();
}
function assistantToggleMute(){
 const tracks=ankhRealtimeStream?.getAudioTracks()||[];if(!tracks.length)return;ankhRealtimeMuted=!ankhRealtimeMuted;tracks.forEach(track=>track.enabled=!ankhRealtimeMuted);
 if(ankhAssistantMute)ankhAssistantMute.textContent=ankhRealtimeMuted?'Unmute mic':'Mute mic';
 if(ankhRealtimeMuted)assistantRealtimeState('listening','Microphone muted','Tap Unmute mic when you want to continue.');else assistantRealtimeState('listening','I’m listening…','Carry on — the same conversation is still open.');
}
ankhAssistantButton?.addEventListener('click',assistantOpenRealtime);
ankhAssistantClose?.addEventListener('click',assistantCloseRealtime);
ankhAssistantEnd?.addEventListener('click',assistantCloseRealtime);
ankhAssistantRetry?.addEventListener('click',assistantConnectRealtime);
ankhAssistantMute?.addEventListener('click',assistantToggleMute);

const addCustomerButton=document.querySelector('#show-add-customer'),addCustomerPanel=document.querySelector('#add-customer-panel');
addCustomerButton?.addEventListener('click',()=>{const open=addCustomerPanel.hidden;addCustomerPanel.hidden=!open;addCustomerButton.setAttribute('aria-expanded',open?'true':'false');if(open)addCustomerPanel.querySelector('input[name="name"]')?.focus()});
document.querySelectorAll('[data-close-customer-form]').forEach(b=>b.addEventListener('click',()=>{if(addCustomerPanel){addCustomerPanel.hidden=true;addCustomerButton?.setAttribute('aria-expanded','false')}}));
document.querySelectorAll('[data-edit-customer]').forEach(b=>b.addEventListener('click',()=>{const id=b.dataset.editCustomer,form=document.querySelector('[data-customer-form="'+id+'"]');if(!form)return;const open=form.hidden;form.hidden=!open;b.setAttribute('aria-expanded',open?'true':'false');b.textContent=open?'Hide edit':'Edit customer';if(open)form.querySelector('input[name="name"]')?.focus()}));
document.querySelectorAll('[data-cancel-customer-edit]').forEach(b=>b.addEventListener('click',()=>{const id=b.dataset.cancelCustomerEdit,form=document.querySelector('[data-customer-form="'+id+'"]'),toggle=document.querySelector('[data-edit-customer="'+id+'"]');if(form)form.hidden=true;if(toggle){toggle.setAttribute('aria-expanded','false');toggle.textContent='Edit customer'}}));
const customerPageSearch=document.querySelector('#customer-page-search'),customerStatusFilter=document.querySelector('#customer-status-filter'),customerCards=[...document.querySelectorAll('[data-customer-search]')],customerPageEmpty=document.querySelector('#customer-page-empty'),customerInsightButtons=[...document.querySelectorAll('[data-customer-insight]')];let customerInsight='all';
function filterCustomerPage(){const term=(customerPageSearch?.value||'').toLowerCase().trim(),mode=customerStatusFilter?.value||'active';let visible=0;customerCards.forEach(card=>{const archived=card.dataset.customerArchived==='1',statusOk=mode==='all'||(mode==='archived'?archived:!archived),searchOk=!term||card.dataset.customerSearch.includes(term),insightOk=customerInsight==='all'||card.dataset['customer'+customerInsight.charAt(0).toUpperCase()+customerInsight.slice(1)]==='1';card.hidden=!(statusOk&&searchOk&&insightOk);if(!card.hidden)visible++});customerInsightButtons.forEach(button=>{const on=button.dataset.customerInsight===customerInsight;button.classList.toggle('selected',on);button.setAttribute('aria-pressed',on?'true':'false')});if(customerPageEmpty)customerPageEmpty.hidden=visible>0;const label=document.querySelector('#ux-customer-visible');if(label)label.textContent=visible+' customer'+(visible===1?'':'s')+' shown'}
customerInsightButtons.forEach(button=>button.addEventListener('click',()=>{customerInsight=button.dataset.customerInsight||'all';filterCustomerPage()}));customerPageSearch?.addEventListener('input',filterCustomerPage);customerStatusFilter?.addEventListener('change',filterCustomerPage);filterCustomerPage();

const wizard=document.querySelector('#order-wizard'),wizardSteps=[...document.querySelectorAll('[data-wizard-step]')],wizardProgress=[...document.querySelectorAll('[data-progress-step]')],clearDraftButton=document.querySelector('#clear-draft');
const orderForm=document.querySelector('#order-wizard,#edit-order-form');
const customerSuggestions=<?=json_encode($customerSuggestions,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>;
const productCatalog=<?=json_encode($productCatalogForJs,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>;
const initialOrderLines=<?=json_encode($view==='edit'?$editLines:$repeatLines,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>;
const customerSearch=document.querySelector('#customer-search'),customerResults=document.querySelector('#customer-results'),customerPhone=document.querySelector('#customer-phone'),customerAddress=document.querySelector('#customer-address'),recipientName=document.querySelector('#recipient-name');
const productSearch=document.querySelector('#product-search'),productSearchWrap=productSearch?.closest('.product-search-wrap'),productResults=document.querySelector('#product-results'),strengthPicker=document.querySelector('#strength-picker'),strengthOptions=document.querySelector('#strength-options'),strengthProductName=document.querySelector('#strength-product-name'),closeStrengthPicker=document.querySelector('#close-strength-picker'),selectedLinesContainer=document.querySelector('#selected-order-lines'),selectedEmpty=document.querySelector('#selected-empty'),addAnotherButton=document.querySelector('#add-another-product');
const deliveryMethodSelect=document.querySelector('#delivery-method'),assignedToSelect=document.querySelector('#assigned-to'),postageFields=document.querySelector('#postage-fields'),deliveryChargeInput=document.querySelector('#delivery-charge'),postageCostInput=document.querySelector('#postage-cost'),trackingReferenceInput=document.querySelector('#tracking-reference'),paymentMethodInput=document.querySelector('#payment-method');
const futureDeliveryToggle=document.querySelector('#future-delivery'),scheduledDeliveryInput=document.querySelector('#scheduled-delivery-date'),futureDeliveryWrap=document.querySelector('#future-delivery-date-wrap');
function toggleFutureDelivery(){
 if(!futureDeliveryToggle||!scheduledDeliveryInput||!futureDeliveryWrap)return;
 const enabled=futureDeliveryToggle.checked;futureDeliveryWrap.hidden=!enabled;scheduledDeliveryInput.disabled=!enabled;scheduledDeliveryInput.required=enabled;
}
futureDeliveryToggle?.addEventListener('change',toggleFutureDelivery);
toggleFutureDelivery();
const draftEnabled=wizard?.dataset.draftEnabled==='1';
let currentWizardStep=1,lineCounter=0,productSearchMoved=false;
const editingExistingOrder=<?=json_encode($view==='edit')?>;
const savedOrderPricing=<?=json_encode($editOrder['pricing_type']??'standard')?>;
function activeCustomerPricing(){
 if(editingExistingOrder)return savedOrderPricing;
 const name=(customerSearch?.value||'').trim().toLowerCase(),phone=(customerPhone?.value||'').trim();
 const customer=customerSuggestions.find(c=>(c.name||'').trim().toLowerCase()===name&&(c.phone||'').trim()===phone);
 return customer?.pricing_type||'standard';
}
function applyCustomerPricing(reset=false){
 const type=activeCustomerPricing(),automatic=type==='free'||(type==='family_friends'&&!editingExistingOrder);
 let note=document.querySelector('#customer-pricing-note');if(!note&&customerSearch){note=document.createElement('p');note.id='customer-pricing-note';note.className='muted';note.setAttribute('aria-live','polite');customerSearch.after(note);}
 if(note)note.textContent=type==='free'?'Free · Personal stock use. No payment required.':type==='family_friends'?'Family & Friends · Saved product prices apply.':'Standard pricing';
 selectedOrderCards().forEach(card=>{
  const data=lineData(card),p=productCatalog.find(p=>Number(p.id)===data.product_id),price=card.querySelector('[data-line-price]'),discount=card.querySelector('[data-line-discount]'),family=card.querySelector('[data-family-line]');
  if(price){price.readOnly=automatic;if(automatic)price.value=(type==='free'?0:Number(p?.saved_family_price??p?.price??0)).toFixed(2);else if(reset&&!editingExistingOrder)price.value=Number(p?.price??data.base_price).toFixed(2);}
  if(discount&&(automatic||reset))discount.value=type==='family_friends'&&Number(p?.saved_family_price??p?.price)<Number(p?.price)?'1':'0';
  if(family)family.disabled=automatic;
  refreshLineVisuals(card);
 });
 if(deliveryChargeInput){deliveryChargeInput.readOnly=type==='free';if(type==='free')deliveryChargeInput.value='0.00';}
 if(type==='free'&&paymentMethodInput)paymentMethodInput.value='Other';
 updateTotal();
}
customerSearch?.addEventListener('change',()=>applyCustomerPricing(true));
customerPhone?.addEventListener('change',()=>applyCustomerPricing(true));

function moneyFormat(value){return new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP'}).format(Number(value)||0)}
function selectedOrderCards(){return [...document.querySelectorAll('.order-line-card')]}
function isStandalonePenCard(card){return (card?.dataset?.productName||'').trim().toLowerCase()==='pen'}
function lineData(card){
 return {
  product_id:Number(card.querySelector('[data-line-product]')?.value||0),
  quantity:Number(card.querySelector('[data-line-quantity]')?.value||0),
  presentation:card.querySelector('[data-line-presentation]')?.value||'',
  base_price:Number(card.querySelector('[data-line-base-price]')?.value||0),
  discount:card.querySelector('[data-line-discount]')?.value==='1',
  price:Number(card.querySelector('[data-line-price]')?.value||0),
  pair_id:card.dataset.pairId||'',
  paired_pen:card.dataset.pairedPen==='1',
  paired_with_name:card.dataset.pairedWithName||'',
  from_repeat:card.dataset.fromRepeat==='1',
  recurring:card.querySelector('[data-line-recurring]')?.value==='1',
  cycle_weeks:Number(card.querySelector('[data-line-cycle-weeks]')?.value||0)
 };
}
function calculatedLinePrice(card){
 const pricing=activeCustomerPricing();
 if(pricing==='free')return 0;
 if(pricing==='family_friends'&&!editingExistingOrder){const p=productCatalog.find(p=>Number(p.id)===lineData(card).product_id);return Number(p?.saved_family_price??p?.price??0);}
 const data=lineData(card);if(isStandalonePenCard(card))return Math.max(0,data.price||data.base_price);
 const ff=Number(card.dataset.familyPrice);return data.discount?Math.min(data.base_price,Number.isFinite(ff)?ff:Math.max(0,data.base_price-5)):data.base_price;
}
function penCatalogProduct(){return productCatalog.find(p=>p.active&&(p.name||'').trim().toLowerCase()==='pen')||null}
function pairedPenCardFor(card){const pairId=card?.dataset?.pairId;if(!pairId)return null;return selectedOrderCards().find(other=>other!==card&&other.dataset.pairedPen==='1'&&other.dataset.pairId===pairId)||null}
function ensurePairedPenRow(card){
 if(!card||isStandalonePenCard(card)||lineData(card).presentation!=='Pen'||pairedPenCardFor(card))return;
 const pen=penCatalogProduct();if(!pen)return;const d=lineData(card),pairId=card.dataset.pairId||('pair_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,7));card.dataset.pairId=pairId;
 addOrderLine({product_id:pen.id,quantity:Math.max(1,d.quantity),presentation:'',base_price:pen.price,discount:false,price:pen.price,pair_id:pairId,paired_pen:true,paired_with_name:card.dataset.productName||''},false,card);
}
function removePairedPenRow(card){const pen=pairedPenCardFor(card);if(pen)pen.remove()}
function selectLineFormat(card,hiddenPresentation,value){
 const previous=hiddenPresentation.value;hiddenPresentation.value=value;recalculateLinePrice(card);
 if(value==='Pen')ensurePairedPenRow(card);else if(previous==='Pen')removePairedPenRow(card);
 updateSelectedState();updateTotal();saveDraftOrder();
}
function refreshLineVisuals(card){
 const data=lineData(card);
 card.querySelectorAll('[data-line-format]').forEach(button=>button.classList.toggle('selected',button.dataset.lineFormat===data.presentation));
 const family=card.querySelector('[data-family-line]');if(family){family.classList.toggle('selected',data.discount);family.setAttribute('aria-pressed',data.discount?'true':'false')}
 const priceOut=card.querySelector('[data-line-total]');if(priceOut)priceOut.textContent=moneyFormat(data.price*data.quantity);
}
function recalculateLinePrice(card){
 if(activeCustomerPricing()==='free'||(activeCustomerPricing()==='family_friends'&&!editingExistingOrder)){applyCustomerPricing();return;}
 const price=card.querySelector('[data-line-price]');if(price&&!isStandalonePenCard(card))price.value=calculatedLinePrice(card).toFixed(2);refreshLineVisuals(card);updateTotal();
}
function updateSelectedState(){
 const cards=selectedOrderCards(),count=cards.length,hasReadyLine=cards.some(card=>isStandalonePenCard(card)||!!lineData(card).presentation);
 if(selectedEmpty)selectedEmpty.hidden=count>0;
 if(addAnotherButton)addAnotherButton.hidden=!hasReadyLine||productSearchMoved;
}
function moveProductSearchToBottom(){
 if(!productSearchWrap||!selectedLinesContainer)return;
 selectedLinesContainer.after(productSearchWrap);
 if(strengthPicker)productSearchWrap.after(strengthPicker);
 productSearchMoved=true;
 if(addAnotherButton)addAnotherButton.hidden=true;
 if(productSearch){productSearch.value='';productSearch.placeholder='Search another product…';productSearch.focus();showProductResults()}
 setTimeout(()=>productSearchWrap.scrollIntoView({behavior:'smooth',block:'center'}),60);
}
addAnotherButton?.addEventListener('click',moveProductSearchToBottom);

function createFormatButton(value,icon){
 const button=document.createElement('button');button.type='button';button.className='line-format-button';button.dataset.lineFormat=value;
 const iconSpan=document.createElement('span');iconSpan.className='line-format-icon';iconSpan.textContent=icon;
 const label=document.createElement('strong');label.textContent=value;button.append(iconSpan,label);return button;
}
function addOrderLine(data={},scroll=true,insertAfterCard=null){
 if(!selectedLinesContainer)return null;
 const product=productCatalog.find(p=>Number(p.id)===Number(data.product_id));if(!product)return null;
 const key='l'+(++lineCounter)+'_'+Date.now().toString(36),standalonePen=(product.name||'').trim().toLowerCase()==='pen';
 const lowerProduct=(product.name||'').trim().toLowerCase(),isReta=lowerProduct.startsWith('retatrutide'),isGhk=lowerProduct.startsWith('ghk-cu')||lowerProduct.startsWith('ghk cu');
 const recurring=standalonePen?false:(data.recurring===undefined||data.recurring===null?isReta:!!data.recurring);
 const suggestedWeeks=isReta?4:(isGhk?8:4),cycleWeeks=standalonePen?0:Math.min(52,Math.max(1,Number(data.cycle_weeks)||suggestedWeeks));
 const base=Number(data.base_price??product.price)||0,qty=Math.max(1,Number(data.quantity)||1),presentation=standalonePen?'':(data.presentation||''),discount=standalonePen?false:!!data.discount;
 const familyPrice=Number(product.family_price??Math.max(0,base-5)),initialCalculated=standalonePen?base:(discount?Math.min(base,familyPrice):base);
 const price=data.price===undefined||data.price===null?initialCalculated:Number(data.price);
 const pairId=data.pair_id||('pair_'+Date.now().toString(36)+'_'+lineCounter),pairedPen=standalonePen&&!!data.paired_pen,pairedWithName=data.paired_with_name||'',fromRepeat=!!data.from_repeat;

 const card=document.createElement('article');card.className='order-line-card'+(pairedPen?' paired-pen-row':'')+(fromRepeat?' repeat-source-line':'');card.dataset.productName=product.name;card.dataset.productStrength=product.strength||'';card.dataset.familyPrice=String(familyPrice);card.dataset.pairId=pairId;card.dataset.pairedPen=pairedPen?'1':'0';card.dataset.pairedWithName=pairedWithName;card.dataset.fromRepeat=fromRepeat?'1':'0';
 const head=document.createElement('div');head.className='order-line-head';
 const titleWrap=document.createElement('div');const title=document.createElement('h3');title.textContent=product.name;const baseText=document.createElement('small');baseText.textContent=standalonePen?(pairedPen&&pairedWithName?'Pen for '+pairedWithName+' · list price '+moneyFormat(base):'List price '+moneyFormat(base)+' · manual discount available'):'Product '+moneyFormat(base);titleWrap.append(title,baseText);
 const remove=document.createElement('button');remove.type='button';remove.className='line-remove'+(fromRepeat&&!pairedPen?' repeat-line-remove':'');remove.setAttribute('aria-label',(fromRepeat?'Remove from this repeat order: ':'Remove ')+product.name);remove.textContent=fromRepeat&&!pairedPen?'Remove from this order':'×';head.append(titleWrap,remove);card.append(head);

 const hiddenProduct=document.createElement('input');hiddenProduct.type='hidden';hiddenProduct.name='lines['+key+'][product_id]';hiddenProduct.value=String(product.id);hiddenProduct.dataset.lineProduct='';
 const hiddenBase=document.createElement('input');hiddenBase.type='hidden';hiddenBase.name='lines['+key+'][base_price]';hiddenBase.value=base.toFixed(2);hiddenBase.dataset.lineBasePrice='';
 const hiddenPresentation=document.createElement('input');hiddenPresentation.type='hidden';hiddenPresentation.name='lines['+key+'][presentation]';hiddenPresentation.value=presentation;hiddenPresentation.dataset.linePresentation='';
 const hiddenDiscount=document.createElement('input');hiddenDiscount.type='hidden';hiddenDiscount.name='lines['+key+'][discount]';hiddenDiscount.value=discount?'1':'0';hiddenDiscount.dataset.lineDiscount='';
 const hiddenRecurring=document.createElement('input');hiddenRecurring.type='hidden';hiddenRecurring.name='lines['+key+'][recurring]';hiddenRecurring.value=recurring?'1':'0';hiddenRecurring.dataset.lineRecurring='';
 const hiddenCycleWeeks=document.createElement('input');hiddenCycleWeeks.type='hidden';hiddenCycleWeeks.name='lines['+key+'][cycle_weeks]';hiddenCycleWeeks.value=String(cycleWeeks);hiddenCycleWeeks.dataset.lineCycleWeeks='';
 card.append(hiddenProduct,hiddenBase,hiddenPresentation,hiddenDiscount,hiddenRecurring,hiddenCycleWeeks);

 if(!standalonePen){
  const formatTitle=document.createElement('span');formatTitle.className='line-section-label';formatTitle.textContent='Choose format';
  const formats=document.createElement('div');formats.className='line-format-picker';
  [['Pen','▯'],['Cartridge','▤'],['Vial','◉']].forEach(([value,icon])=>{const button=createFormatButton(value,icon);button.addEventListener('click',()=>selectLineFormat(card,hiddenPresentation,value));formats.append(button)});
  card.append(formatTitle,formats);
 }

 if(!standalonePen&&(product.strength||'').toLowerCase().endsWith('mg')){
  const family=document.createElement('button');family.type='button';family.className='family-discount line-family-discount';family.dataset.familyLine='';family.setAttribute('aria-pressed',discount?'true':'false');
  const familyLabel=document.createElement('span');familyLabel.textContent='Family & Friends';const familyValue=document.createElement('strong');familyValue.textContent=moneyFormat(familyPrice);family.append(familyLabel,familyValue);
  family.addEventListener('click',()=>{hiddenDiscount.value=hiddenDiscount.value==='1'?'0':'1';recalculateLinePrice(card);saveDraftOrder()});card.append(family);
 }

 if(!standalonePen){
  const cycleWrap=document.createElement('div');cycleWrap.className='line-cycle-wrap'+(recurring?' selected':'');
  let cycleExpanded=false;
  const cycleToggle=document.createElement('button');cycleToggle.type='button';cycleToggle.className='line-cycle-toggle';cycleToggle.setAttribute('aria-expanded','false');cycleToggle.setAttribute('aria-label','Show recurring cycle options');
  const cycleCopy=document.createElement('span');cycleCopy.innerHTML='<strong>↻ Recurring cycle</strong><small>Tap to change the schedule</small>';
  const cycleSummary=document.createElement('span');cycleSummary.className='line-cycle-summary';
  const cycleStatus=document.createElement('b');const cycleChevron=document.createElement('i');cycleChevron.className='line-cycle-chevron';cycleChevron.setAttribute('aria-hidden','true');cycleChevron.textContent='⌄';cycleSummary.append(cycleStatus,cycleChevron);cycleToggle.append(cycleCopy,cycleSummary);
  const cycleOptions=document.createElement('div');cycleOptions.className='line-cycle-options';cycleOptions.hidden=true;
  const cycleLabel=document.createElement('label');cycleLabel.textContent='Repeat every';const cycleInput=document.createElement('input');cycleInput.type='number';cycleInput.min='1';cycleInput.max='52';cycleInput.step='1';cycleInput.inputMode='numeric';cycleInput.value=String(cycleWeeks);cycleInput.setAttribute('aria-label','Repeat every number of weeks');
  const cycleSuffix=document.createElement('span');cycleSuffix.textContent='weeks';cycleLabel.append(cycleInput,cycleSuffix);
  const cycleQuick=document.createElement('div');cycleQuick.className='line-cycle-quick';[4,5,8,12].forEach(weeks=>{const chip=document.createElement('button');chip.type='button';chip.textContent=weeks+' wk';chip.dataset.cycleQuick=String(weeks);chip.addEventListener('click',()=>{hiddenRecurring.value='1';hiddenCycleWeeks.value=String(weeks);cycleInput.value=String(weeks);refreshCycle();saveDraftOrder()});cycleQuick.append(chip)});
  const cycleHint=document.createElement('small');cycleHint.textContent=isReta?'Retatrutide defaults to 4 weeks.':(isGhk?'GHK suggestion: 8 weeks.':'Choose the customer’s usual cycle.');
  const cycleAction=document.createElement('button');cycleAction.type='button';cycleAction.className='line-cycle-action';
  cycleOptions.append(cycleLabel,cycleQuick,cycleHint,cycleAction);cycleWrap.append(cycleToggle,cycleOptions);card.append(cycleWrap);
  const refreshCycle=()=>{const on=hiddenRecurring.value==='1',weeks=Math.min(52,Math.max(1,Number(hiddenCycleWeeks.value)||suggestedWeeks));hiddenCycleWeeks.value=String(weeks);cycleInput.value=String(weeks);cycleWrap.classList.toggle('selected',on);cycleStatus.textContent=on?'Every '+weeks+' weeks':'Off';cycleAction.textContent=on?'Remove recurrence':'Enable recurrence';cycleAction.setAttribute('aria-pressed',on?'true':'false');cycleOptions.hidden=!cycleExpanded;cycleToggle.setAttribute('aria-expanded',cycleExpanded?'true':'false');cycleChevron.textContent=cycleExpanded?'⌃':'⌄';cycleQuick.querySelectorAll('button').forEach(chip=>chip.classList.toggle('selected',Number(chip.dataset.cycleQuick)===weeks))};
  cycleToggle.addEventListener('click',()=>{cycleExpanded=!cycleExpanded;refreshCycle()});
  cycleAction.addEventListener('click',()=>{hiddenRecurring.value=hiddenRecurring.value==='1'?'0':'1';if(hiddenRecurring.value==='1'&&Number(hiddenCycleWeeks.value)<1)hiddenCycleWeeks.value=String(suggestedWeeks);refreshCycle();saveDraftOrder()});
  cycleInput.addEventListener('input',()=>{let weeks=Math.min(52,Math.max(1,Number(cycleInput.value)||suggestedWeeks));hiddenCycleWeeks.value=String(weeks);hiddenRecurring.value='1';refreshCycle();saveDraftOrder()});
  refreshCycle();
 }

 const controls=document.createElement('div');controls.className='line-controls';
 const qtyWrap=document.createElement('div');qtyWrap.className='line-quantity';const qtyLabel=document.createElement('span');qtyLabel.textContent='Quantity';
 const stepper=document.createElement('div');stepper.className='stepper';const minus=document.createElement('button');minus.type='button';minus.textContent='−';const qtyInput=document.createElement('input');qtyInput.type='number';qtyInput.min='1';qtyInput.max='999';qtyInput.inputMode='numeric';qtyInput.name='lines['+key+'][quantity]';qtyInput.value=String(qty);qtyInput.dataset.lineQuantity='';const plus=document.createElement('button');plus.type='button';plus.textContent='+';
 stepper.append(minus,qtyInput,plus);qtyWrap.append(qtyLabel,stepper);
 const priceLabel=document.createElement('label');priceLabel.className='line-price-field';priceLabel.textContent=standalonePen?'Final pen price (£)':'Price each (£)';const priceInput=document.createElement('input');priceInput.type='number';priceInput.min='0';priceInput.max='100000';priceInput.step='.01';priceInput.inputMode='decimal';priceInput.name='lines['+key+'][price]';priceInput.value=(Number.isFinite(price)?price:initialCalculated).toFixed(2);priceInput.dataset.linePrice='';priceLabel.append(priceInput);
 if(standalonePen){const priceHelp=document.createElement('small');priceHelp.className='line-price-help';priceHelp.textContent='Edit this amount to apply a manual discount. Profit uses the final price charged.';priceLabel.append(priceHelp)}
 controls.append(qtyWrap,priceLabel);card.append(controls);
 const lineTotal=document.createElement('div');lineTotal.className='line-card-total';lineTotal.innerHTML='<span>Line total</span><strong data-line-total></strong>';card.append(lineTotal);

 remove.addEventListener('click',()=>{if(fromRepeat&&!pairedPen&&!confirm('Remove '+product.name+' from this current repeat order? The previous order will stay unchanged.'))return;if(pairedPen){const parent=selectedOrderCards().find(other=>other.dataset.pairId===card.dataset.pairId&&other.dataset.pairedPen!=='1');if(parent){const p=parent.querySelector('[data-line-presentation]');if(p&&p.value==='Pen'){p.value='';recalculateLinePrice(parent)}}}else removePairedPenRow(card);card.remove();updateSelectedState();updateTotal();saveDraftOrder()});
 minus.addEventListener('click',()=>{qtyInput.value=String(Math.max(1,Number(qtyInput.value||1)-1));refreshLineVisuals(card);updateTotal();saveDraftOrder()});
 plus.addEventListener('click',()=>{qtyInput.value=String(Math.min(999,Number(qtyInput.value||1)+1));refreshLineVisuals(card);updateTotal();saveDraftOrder()});
 qtyInput.addEventListener('input',()=>{if(Number(qtyInput.value)<1)qtyInput.value='1';refreshLineVisuals(card);updateTotal();saveDraftOrder()});
 priceInput.addEventListener('input',()=>{refreshLineVisuals(card);updateTotal();saveDraftOrder()});

 if(insertAfterCard&&insertAfterCard.parentNode===selectedLinesContainer)insertAfterCard.after(card);else selectedLinesContainer.append(card);applyCustomerPricing();refreshLineVisuals(card);updateSelectedState();updateTotal();
 if(scroll){productSearch?.blur();if(!productSearchMoved)setTimeout(()=>card.scrollIntoView({behavior:'smooth',block:'center'}),80);else setTimeout(()=>productSearchWrap?.scrollIntoView({behavior:'smooth',block:'center'}),80)}
 return card;
}

function updateTotal(){
 if(activeCustomerPricing()==='free'&&deliveryChargeInput)deliveryChargeInput.value='0.00';
 let total=selectedOrderCards().reduce((sum,card)=>{const d=lineData(card);return sum+(d.price*d.quantity)},0);
 if(deliveryMethodSelect?.value==='Postage')total+=Math.max(0,Number(deliveryChargeInput?.value)||0);
 const out=document.querySelector('#subtotal');if(out){out.setAttribute('aria-live','polite');out.textContent=moneyFormat(total)}
 selectedOrderCards().forEach(refreshLineVisuals);updateSelectedState();
}
function validateLines(){
 const cards=selectedOrderCards();if(!cards.length){alert('Add at least one product.');return false}
 const missing=cards.find(card=>!isStandalonePenCard(card)&&!lineData(card).presentation);
 if(missing){missing.classList.add('needs-format');missing.scrollIntoView({behavior:'smooth',block:'center'});setTimeout(()=>missing.classList.remove('needs-format'),1600);alert('Choose Pen, Cartridge or Vial for each peptide.');return false}
 return true;
}
function buildOrderCheck(){
 const customer=document.querySelector('#check-customer'),detail=document.querySelector('#check-customer-detail'),recipient=document.querySelector('#check-recipient'),products=document.querySelector('#check-products'),orderDate=document.querySelector('#order-date'),checkOrderDate=document.querySelector('#check-order-date'),checkDelivery=document.querySelector('#check-delivery'),checkDeliveryDetail=document.querySelector('#check-delivery-detail');
 if(customer)customer.textContent=customerSearch?.value.trim()||'—';
 if(recipient)recipient.textContent=recipientName?.value.trim()||customerSearch?.value.trim()||'Same person';
 if(detail){const bits=[customerPhone?.value.trim(),document.querySelector('#customer-referrer')?.value.trim()].filter(Boolean);detail.textContent=bits.join(' · ')}
 if(checkOrderDate&&orderDate?.value){const d=new Date(orderDate.value+'T12:00:00');checkOrderDate.textContent=d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'})}
 if(checkDelivery)checkDelivery.textContent=deliveryMethodSelect?.value||'—';
 if(checkDeliveryDetail){const bits=[];if(futureDeliveryToggle?.checked&&scheduledDeliveryInput?.value)bits.push('Planned '+new Date(scheduledDeliveryInput.value+'T12:00:00').toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}));if(deliveryMethodSelect?.value==='Postage'&&Number(deliveryChargeInput?.value||0)>0)bits.push('Postage charged £'+Number(deliveryChargeInput.value).toFixed(2));if(trackingReferenceInput?.value.trim())bits.push(trackingReferenceInput.value.trim());checkDeliveryDetail.textContent=bits.join(' · ')}
 if(products){products.replaceChildren();selectedOrderCards().forEach(card=>{const d=lineData(card),line=document.createElement('div');line.className='check-product-line';const name=document.createElement('span');name.textContent=d.quantity+' × '+card.dataset.productName+(d.presentation?' · '+d.presentation:'')+(d.discount?' · Family & Friends':'');const amount=document.createElement('strong');amount.textContent=moneyFormat(d.quantity*d.price);line.append(name,amount);products.append(line)})}
 updateTotal();
}

function showWizardStep(step){
 if(!wizard)return;currentWizardStep=Number(step)||1;
 wizardSteps.forEach(section=>section.hidden=Number(section.dataset.wizardStep)!==Number(step));
 wizardProgress.forEach(item=>{const n=Number(item.dataset.progressStep);item.classList.toggle('active',n===Number(step));item.classList.toggle('done',n<Number(step))});
 document.activeElement?.blur();wizard.scrollIntoView({behavior:'smooth',block:'start'});
 if(Number(step)===2)setTimeout(()=>productSearch?.focus(),220);
 if(Number(step)===3)buildOrderCheck();
 saveDraftOrder();
}
document.querySelectorAll('[data-wizard-next]').forEach(button=>button.addEventListener('click',()=>{
 const next=Number(button.dataset.wizardNext);
 if(next===2){if(!customerSearch?.value.trim()){customerSearch?.reportValidity();customerSearch?.focus();return}const orderDate=document.querySelector('#order-date');if(!orderDate?.value||!orderDate.checkValidity()){orderDate?.reportValidity();orderDate?.focus();return}if(futureDeliveryToggle?.checked&&(!scheduledDeliveryInput?.value||!scheduledDeliveryInput.checkValidity())){scheduledDeliveryInput?.reportValidity();scheduledDeliveryInput?.focus();return}if(!deliveryMethodSelect?.value){deliveryMethodSelect?.reportValidity();deliveryMethodSelect?.focus();return}}
 if(next===3&&!validateLines())return;showWizardStep(next);
}));
document.querySelectorAll('[data-wizard-back]').forEach(button=>button.addEventListener('click',()=>showWizardStep(Number(button.dataset.wizardBack))));

function chooseCustomer(customer){if(customerSearch)customerSearch.value=customer.name||'';if(customerPhone)customerPhone.value=customer.phone||'';if(customerAddress)customerAddress.value=customer.address||'';if(customerResults){customerResults.hidden=true;customerResults.replaceChildren()}applyCustomerPricing(true);customerSearch?.blur();saveDraftOrder()}
function showCustomerResults(){if(!customerSearch||!customerResults)return;const term=customerSearch.value.toLowerCase().trim();customerResults.replaceChildren();if(term.length<1){customerResults.hidden=true;return}const matches=customerSuggestions.filter(c=>(c.name||'').toLowerCase().includes(term)||(c.phone||'').toLowerCase().includes(term)).slice(0,8);if(!matches.length){customerResults.hidden=true;return}matches.forEach(c=>{const b=document.createElement('button');b.type='button';b.className='customer-result';b.setAttribute('role','option');const main=document.createElement('strong');main.textContent=c.name||'Customer';const info=document.createElement('span');info.textContent=c.phone||'';b.append(main,info);if(c.address){const addr=document.createElement('small');addr.textContent=c.address.replace(/\s+/g,' ').trim();b.append(addr)}b.addEventListener('click',()=>chooseCustomer(c));customerResults.append(b)});customerResults.hidden=false}
customerSearch?.addEventListener('input',showCustomerResults);customerSearch?.addEventListener('focus',showCustomerResults);
document.querySelectorAll('[data-recent-customer]').forEach(button=>button.addEventListener('click',()=>{const customer=customerSuggestions.find(c=>String(c.id)===button.dataset.recentCustomer);if(customer)chooseCustomer(customer)}));
document.addEventListener('click',e=>{if(customerResults&&!e.target.closest('.customer-search-wrap'))customerResults.hidden=true});

const search=document.querySelector('#search'),filter=document.querySelector('#filter');
function applyFilters(){if(!search||!filter)return;let visible=0;document.querySelectorAll('.order-list .order').forEach(o=>{o.hidden=!(o.dataset.search.includes(search.value.toLowerCase().trim())&&(!filter.value||o.dataset.status===filter.value));if(!o.hidden)visible++});const empty=document.querySelector('#empty');if(empty)empty.hidden=visible>0;const label=document.querySelector('#ux-order-visible');if(label)label.textContent=visible+' order'+(visible===1?'':'s')+' shown'}
search?.addEventListener('input',applyFilters);filter?.addEventListener('change',applyFilters);applyFilters();

function productGroups(){
 const groups=new Map();productCatalog.filter(p=>p.active).forEach(product=>{const key=(product.base||product.name).toLowerCase();if(!groups.has(key))groups.set(key,{base:product.base||product.name,products:[]});groups.get(key).products.push(product)});return [...groups.values()];
}
function chooseProduct(product){
 addOrderLine({product_id:product.id,quantity:1,presentation:'',base_price:product.price,discount:false,price:product.price},true);
 if(strengthPicker)strengthPicker.hidden=true;if(productResults){productResults.hidden=true;productResults.replaceChildren()}if(productSearch){productSearch.value='';productSearch.blur()}
}
function showStrengths(group){
 if(!strengthPicker||!strengthOptions||!strengthProductName)return;strengthProductName.textContent=group.base;strengthOptions.replaceChildren();
 [...group.products].sort((a,b)=>(parseFloat(a.strength)||0)-(parseFloat(b.strength)||0)).forEach(product=>{const button=document.createElement('button');button.type='button';button.className='strength-option';const strength=document.createElement('strong');strength.textContent=product.strength||'Add';const price=document.createElement('span');price.textContent=moneyFormat(product.price);button.append(strength,price);button.addEventListener('click',()=>chooseProduct(product));strengthOptions.append(button)});
 strengthPicker.hidden=false;productResults.hidden=true;productSearch?.blur();strengthPicker.scrollIntoView({block:'nearest',behavior:'smooth'});
}
function showProductResults(){
 if(!productSearch||!productResults)return;const term=productSearch.value.toLowerCase().trim();productResults.replaceChildren();const groups=productGroups();
 let matches;if(!term){const reta=groups.find(group=>group.base.toLowerCase()==='retatrutide');matches=reta?[reta]:[]}else{matches=groups.filter(group=>group.base.toLowerCase().includes(term)).sort((a,b)=>a.base.toLowerCase()==='retatrutide'?-1:b.base.toLowerCase()==='retatrutide'?1:a.base.localeCompare(b.base)).slice(0,8)}
 if(!matches.length){productResults.hidden=true;return}
 matches.forEach(group=>{const button=document.createElement('button');button.type='button';button.className='product-result';button.setAttribute('role','option');const name=document.createElement('strong');name.textContent=group.base;const info=document.createElement('span');const strengths=group.products.map(p=>p.strength).filter(Boolean).sort((a,b)=>(parseFloat(a)||0)-(parseFloat(b)||0));info.textContent=strengths.length?strengths.join(' · '):(group.products.length>1?'Choose option':'Add');button.append(name,info);button.addEventListener('click',()=>group.products.length===1?chooseProduct(group.products[0]):showStrengths(group));productResults.append(button)});
 productResults.hidden=false;
}
productSearch?.addEventListener('input',()=>{if(strengthPicker)strengthPicker.hidden=true;showProductResults()});productSearch?.addEventListener('focus',showProductResults);
closeStrengthPicker?.addEventListener('click',()=>{strengthPicker.hidden=true;productSearch?.blur()});
document.addEventListener('click',e=>{if(productResults&&!e.target.closest('.product-search-wrap')&&!e.target.closest('.strength-picker'))productResults.hidden=true});

function togglePostageFields(){
 const isPostage=deliveryMethodSelect?.value==='Postage';if(postageFields)postageFields.hidden=!isPostage;
 if(!isPostage){if(trackingReferenceInput)trackingReferenceInput.value='';if(deliveryChargeInput)deliveryChargeInput.value='0.00';if(postageCostInput)postageCostInput.value='0.00'}
 updateTotal();saveDraftOrder();
}
deliveryMethodSelect?.addEventListener('change',togglePostageFields);deliveryChargeInput?.addEventListener('input',()=>{updateTotal();saveDraftOrder()});trackingReferenceInput?.addEventListener('input',saveDraftOrder);paymentMethodInput?.addEventListener('change',saveDraftOrder);assignedToSelect?.addEventListener('change',saveDraftOrder);

function serializeLines(){return selectedOrderCards().map(card=>lineData(card))}
function saveDraftOrder(){
 if(!draftEnabled||!wizard)return;
 const draft={step:currentWizardStep,customer:customerSearch?.value||'',recipient_name:recipientName?.value||'',phone:customerPhone?.value||'',address:customerAddress?.value||'',referrer:document.querySelector('#customer-referrer')?.value||'',order_date:document.querySelector('#order-date')?.value||'',future_delivery:futureDeliveryToggle?.checked||false,scheduled_delivery_date:scheduledDeliveryInput?.value||'',delivery_method:deliveryMethodSelect?.value||'Local Delivery',assigned_to:assignedToSelect?.value||'',tracking_reference:trackingReferenceInput?.value||'',delivery_charge:deliveryChargeInput?.value||'',postage_cost:postageCostInput?.value||'',payment_method:paymentMethodInput?.value||'',notes:wizard.querySelector('textarea[name="notes"]')?.value||'',lines:serializeLines()};
 const useful=draft.customer||draft.recipient_name||draft.phone||draft.address||draft.lines.length||draft.future_delivery;try{if(useful){localStorage.setItem(orderDraftKey,JSON.stringify(draft));if(clearDraftButton)clearDraftButton.hidden=false}else{localStorage.removeItem(orderDraftKey);if(clearDraftButton)clearDraftButton.hidden=true}}catch(_){}
}
function restoreDraftOrder(){
 if(!draftEnabled||!wizard)return false;let draft=null;try{draft=JSON.parse(localStorage.getItem(orderDraftKey)||'null')}catch(_){}if(!draft)return false;
 if(customerSearch)customerSearch.value=draft.customer||'';if(recipientName)recipientName.value=draft.recipient_name||'';if(customerPhone)customerPhone.value=draft.phone||'';if(customerAddress)customerAddress.value=draft.address||'';
 const ref=document.querySelector('#customer-referrer'),orderDate=document.querySelector('#order-date'),notes=wizard.querySelector('textarea[name="notes"]');if(ref)ref.value=draft.referrer||'';if(orderDate&&draft.order_date)orderDate.value=draft.order_date;if(notes)notes.value=draft.notes||'';
 if(futureDeliveryToggle)futureDeliveryToggle.checked=draft.future_delivery===true;if(scheduledDeliveryInput)scheduledDeliveryInput.value=draft.scheduled_delivery_date||'';toggleFutureDelivery();
 if(deliveryMethodSelect)deliveryMethodSelect.value=draft.delivery_method||'Local Delivery';if(assignedToSelect)assignedToSelect.value=draft.assigned_to||'';if(trackingReferenceInput)trackingReferenceInput.value=draft.tracking_reference||'';if(deliveryChargeInput)deliveryChargeInput.value=draft.delivery_charge||'0.00';if(postageCostInput)postageCostInput.value=draft.postage_cost||'0.00';if(paymentMethodInput)paymentMethodInput.value=draft.payment_method||'';
 selectedLinesContainer?.replaceChildren();(draft.lines||[]).forEach(line=>addOrderLine(line,false));if(clearDraftButton)clearDraftButton.hidden=false;togglePostageFields();showWizardStep(Math.min(3,Math.max(1,Number(draft.step)||1)));return true;
}
clearDraftButton?.addEventListener('click',()=>{try{localStorage.removeItem(orderDraftKey)}catch(_){}location.href='?view=new'});

function reconcilePenRows(){
 const peptidePens=selectedOrderCards().filter(card=>!isStandalonePenCard(card)&&lineData(card).presentation==='Pen');
 const loosePens=selectedOrderCards().filter(card=>isStandalonePenCard(card)&&card.dataset.pairedPen!=='1');
 peptidePens.forEach(card=>{
  if(pairedPenCardFor(card))return;
  const pen=loosePens.shift();
  if(pen){pen.dataset.pairedPen='1';pen.dataset.pairId=card.dataset.pairId;pen.dataset.pairedWithName=card.dataset.productName||'';pen.classList.add('paired-pen-row');const small=pen.querySelector('.order-line-head small');if(small)small.textContent='Pen for '+(card.dataset.productName||'product')+' · list price '+moneyFormat(lineData(pen).base_price)}
  else ensurePairedPenRow(card);
 });
}
const restoredDraft=restoreDraftOrder();
if(!restoredDraft)initialOrderLines.forEach(line=>addOrderLine(line,false));
reconcilePenRows();togglePostageFields();applyCustomerPricing();updateTotal();
wizard?.addEventListener('input',saveDraftOrder);wizard?.addEventListener('change',saveDraftOrder);

const voiceOrderButton=document.querySelector('#voice-order-button'),voiceOrderPanel=document.querySelector('#voice-order-panel'),voiceOrderTitle=document.querySelector('#voice-order-title'),voiceOrderMessage=document.querySelector('#voice-order-message'),voiceOrderTranscript=document.querySelector('#voice-order-transcript'),voiceOrderQuestions=document.querySelector('#voice-order-questions'),voiceOrderPulse=document.querySelector('#voice-order-pulse');
const voiceOrderOverlay=document.querySelector('#voice-order-overlay'),voiceOrderOrb=document.querySelector('#voice-order-orb'),voiceOrderStop=document.querySelector('#voice-order-stop'),voiceOverlayTitle=document.querySelector('#voice-overlay-title'),voiceOverlayMessage=document.querySelector('#voice-overlay-message'),voiceOverlayHelp=document.querySelector('#voice-overlay-help'),voiceOrderTimer=document.querySelector('#voice-order-timer');
let voiceRecorder=null,voiceStream=null,voiceChunks=[],voiceStopTimer=null,voiceElapsedTimer=null,voiceStartedAt=0;
function stopVoiceElapsedTimer(){if(voiceElapsedTimer){clearInterval(voiceElapsedTimer);voiceElapsedTimer=null}}
function updateVoiceTimer(){
 if(!voiceOrderTimer||!voiceStartedAt)return;const seconds=Math.max(0,Math.floor((Date.now()-voiceStartedAt)/1000)),mins=Math.floor(seconds/60),secs=seconds%60;
 voiceOrderTimer.textContent=String(mins).padStart(2,'0')+':'+String(secs).padStart(2,'0');
}
function setVoiceOverlay(state){
 const open=state==='recording'||state==='working';
 if(voiceOrderOverlay)voiceOrderOverlay.hidden=!open;
 document.body.classList.toggle('voice-overlay-open',open);
 if(!open){stopVoiceElapsedTimer();return}
 voiceOrderOverlay?.classList.toggle('working',state==='working');
 if(voiceOrderStop){voiceOrderStop.hidden=state==='working';voiceOrderStop.disabled=state==='working'}
 if(voiceOrderOrb)voiceOrderOrb.disabled=state==='working';
 if(state==='recording'){
  if(voiceOverlayTitle)voiceOverlayTitle.textContent='Listening…';
  if(voiceOverlayMessage)voiceOverlayMessage.textContent='Say the customer, products, quantities, Pen / Cartridge / Vial, delivery and James or Tony.';
  if(voiceOverlayHelp)voiceOverlayHelp.textContent='Tap the big circle or STOP when you’ve finished speaking';
  voiceStartedAt=Date.now();if(voiceOrderTimer)voiceOrderTimer.textContent='00:00';stopVoiceElapsedTimer();voiceElapsedTimer=setInterval(updateVoiceTimer,500);
 }else{
  stopVoiceElapsedTimer();
  if(voiceOverlayTitle)voiceOverlayTitle.textContent='Building your order…';
  if(voiceOverlayMessage)voiceOverlayMessage.textContent='I’m transcribing what you said and matching it to ANKH products.';
  if(voiceOverlayHelp)voiceOverlayHelp.textContent='This normally only takes a few seconds';
 }
}
function setVoiceState(title,message,state='idle'){
 if(voiceOrderPanel)voiceOrderPanel.hidden=false;if(voiceOrderTitle)voiceOrderTitle.textContent=title;if(voiceOrderMessage)voiceOrderMessage.textContent=message;
 if(voiceOrderPulse){voiceOrderPulse.classList.toggle('recording',state==='recording');voiceOrderPulse.classList.toggle('working',state==='working')}
 setVoiceOverlay(state);
}
function stopVoiceTracks(){if(voiceStopTimer){clearTimeout(voiceStopTimer);voiceStopTimer=null}stopVoiceElapsedTimer();voiceStream?.getTracks().forEach(track=>track.stop());voiceStream=null}
function voiceMimeType(){
 const options=['audio/mp4','audio/webm;codecs=opus','audio/webm'];return options.find(type=>window.MediaRecorder?.isTypeSupported?.(type))||'';
}
function showVoiceQuestions(questions=[]){
 if(!voiceOrderQuestions)return;voiceOrderQuestions.replaceChildren();if(!questions.length){voiceOrderQuestions.hidden=true;return}
 const title=document.createElement('strong');title.textContent='Check these details';voiceOrderQuestions.append(title);
 questions.forEach(q=>{const p=document.createElement('p');p.textContent=q;voiceOrderQuestions.append(p)});voiceOrderQuestions.hidden=false;
}
function applyVoiceDraft(payload){
 const draft=payload?.draft||{};if(voiceOrderTranscript){voiceOrderTranscript.hidden=false;voiceOrderTranscript.textContent='“'+(payload.transcript||'')+'”'}
 showVoiceQuestions(Array.isArray(draft.questions)?draft.questions:[]);
 const customer=draft.customer_id?customerSuggestions.find(c=>Number(c.id)===Number(draft.customer_id)):null;
 if(customer)chooseCustomer(customer);else{
  if(customerSearch)customerSearch.value=draft.customer_name||'';if(customerPhone&&draft.phone)customerPhone.value=draft.phone;if(customerAddress&&draft.address)customerAddress.value=draft.address;
 }
 if(recipientName)recipientName.value=draft.recipient_name||'';
 const ref=document.querySelector('#customer-referrer');if(ref&&draft.referrer)ref.value=draft.referrer;
 if(deliveryMethodSelect&&draft.delivery_method)deliveryMethodSelect.value=draft.delivery_method;
 if(assignedToSelect)assignedToSelect.value=draft.assigned_to||'';
 if(paymentMethodInput)paymentMethodInput.value=draft.payment_method||'';
 const notes=wizard?.querySelector('textarea[name="notes"]');if(notes&&draft.notes)notes.value=draft.notes;
 selectedLinesContainer?.replaceChildren();(draft.lines||[]).forEach(line=>addOrderLine(line,false));
 productSearchMoved=false;togglePostageFields();updateSelectedState();updateTotal();saveDraftOrder();
 const missingCustomer=!customerSearch?.value.trim(),missingProducts=!selectedOrderCards().length,missingFormat=selectedOrderCards().some(card=>!isStandalonePenCard(card)&&!lineData(card).presentation);
 if(missingCustomer)showWizardStep(1);else if(missingProducts||missingFormat)showWizardStep(2);else showWizardStep(3);
 setVoiceState('Draft ready','Nothing has been saved. Check the order below, make any changes, then tap Save order.','done');
 voiceOrderPanel?.scrollIntoView({behavior:'smooth',block:'start'});
}
async function sendVoiceOrder(blob,mime){
 setVoiceState('Building draft','Transcribing your order and matching it to ANKH products…','working');voiceOrderButton.disabled=true;voiceOrderButton.classList.remove('recording');voiceOrderButton.classList.add('working');voiceOrderButton.querySelector('strong').textContent='Working…';
 try{
  const data=new FormData();data.append('csrf',<?=json_encode($_SESSION['csrf'])?>);data.append('audio',blob,mime.includes('mp4')?'voice-order.m4a':'voice-order.webm');
  const response=await fetch('?api=voice-order',{method:'POST',body:data,credentials:'same-origin'});const payload=await response.json().catch(()=>({ok:false,error:'Voice ordering returned an unreadable response.'}));
  if(!response.ok||!payload.ok)throw new Error(payload.error||'Voice ordering failed.');
  applyVoiceDraft(payload);
 }catch(error){setVoiceState('Couldn’t build the draft',error?.message||'Please try recording the order again.','error')}
 finally{voiceOrderButton.disabled=false;voiceOrderButton.classList.remove('working','recording');voiceOrderButton.querySelector('strong').textContent='Start voice order'}
}
async function startVoiceOrder(){
 if(voiceOrderButton?.dataset.ready!=='1'){
  setVoiceState('Voice Order is installed','It will activate after the OpenAI API key is added to the private server config and password protection is turned back on.','idle');return;
 }
 if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){setVoiceState('Microphone not available','This browser does not support microphone recording for Voice Order.','error');return}
 try{
  voiceStream=await navigator.mediaDevices.getUserMedia({audio:true});voiceChunks=[];const mime=voiceMimeType();voiceRecorder=mime?new MediaRecorder(voiceStream,{mimeType:mime}):new MediaRecorder(voiceStream);
  voiceRecorder.addEventListener('dataavailable',event=>{if(event.data?.size)voiceChunks.push(event.data)});
  voiceRecorder.addEventListener('stop',()=>{const type=voiceRecorder.mimeType||mime||'audio/mp4',blob=new Blob(voiceChunks,{type});stopVoiceTracks();if(blob.size>100)sendVoiceOrder(blob,type);else setVoiceState('No audio captured','Try again and speak after the microphone starts.','error')},{once:true});
  voiceRecorder.start();voiceOrderButton.classList.add('recording');voiceOrderButton.querySelector('strong').textContent='Listening…';setVoiceState('Listening…','Say the customer, products, quantities, Pen/Cartridge/Vial, delivery and James or Tony if assigned.','recording');
  voiceStopTimer=setTimeout(()=>{if(voiceRecorder?.state==='recording')stopVoiceOrderRecording()},60000);
 }catch(error){stopVoiceTracks();setVoiceState('Microphone permission needed','Allow microphone access in Safari and try again.','error')}
}
function stopVoiceOrderRecording(){
 if(voiceRecorder?.state!=='recording')return;
 setVoiceState('Building draft','Transcribing your order and matching it to ANKH products…','working');
 voiceOrderButton.disabled=true;voiceOrderButton.classList.remove('recording');voiceOrderButton.classList.add('working');voiceOrderButton.querySelector('strong').textContent='Working…';
 voiceRecorder.stop();
}
voiceOrderButton?.addEventListener('click',()=>{if(voiceRecorder?.state==='recording')stopVoiceOrderRecording();else startVoiceOrder()});
voiceOrderStop?.addEventListener('click',stopVoiceOrderRecording);
voiceOrderOrb?.addEventListener('click',stopVoiceOrderRecording);

orderForm?.addEventListener('submit',e=>{
 if(!customerSearch?.value.trim()){e.preventDefault();if(wizard)showWizardStep(1);customerSearch?.reportValidity();return}
 if(!deliveryMethodSelect?.value){e.preventDefault();if(wizard)showWizardStep(1);deliveryMethodSelect?.reportValidity();return}
 if(!validateLines()){e.preventDefault();if(wizard)showWizardStep(2);return}
 const button=orderForm.querySelector('.save-order');if(button){button.disabled=true;button.textContent=orderForm.querySelector('input[name="action"]')?.value==='order_edit'?'Saving changes…':'Saving order…'}
});
const editDateToggle=document.querySelector('#edit-order-date-toggle'),editOrderDate=document.querySelector('#order-date');
if(editDateToggle&&editOrderDate)editDateToggle.addEventListener('change',()=>{editOrderDate.disabled=!editDateToggle.checked;if(editDateToggle.checked)editOrderDate.focus()});
</script><?php endif;?><script>
(()=>{document.addEventListener('click',event=>{const open=event.target.closest('[data-reservation-open]');if(open){const dialog=document.getElementById(open.dataset.reservationOpen);if(dialog&&typeof dialog.showModal==='function')dialog.showModal();return}const close=event.target.closest('[data-reservation-close]');if(close){const dialog=close.closest('dialog');if(dialog)dialog.close()}});document.querySelectorAll('.stock-reservation-dialog').forEach(dialog=>dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close()}))})();
</script>
<script>
document.querySelectorAll('.order-preparation-form').forEach(form=>{
 const checkbox=form.querySelector('input[type="checkbox"]');
 const save=form.querySelector('.order-preparation-save');
 if(!checkbox||!save)return;
 save.hidden=true;
 checkbox.addEventListener('change',()=>{save.hidden=false;form.requestSubmit();});
 form.addEventListener('submit',()=>{save.disabled=true;form.setAttribute('aria-busy','true');});
});
</script>
<script>
(()=>{const active=document.querySelector('.ux-mobile-scopes a[aria-current="page"],.report-tabs a.selected,.sc-tabs a[aria-current="page"]');
 const strip=active?.closest('nav');
 if(strip&&window.innerWidth<=760){
  // Scroll only the horizontal chip strip, never jump the full iPhone page.
  const offset=active.getBoundingClientRect().left-strip.getBoundingClientRect().left;
  strip.scrollLeft+=offset-(strip.clientWidth-active.offsetWidth)/2;
 }
})();
</script>
<dialog id="focus-action-sheet" class="focus-action-sheet" aria-labelledby="focus-sheet-title"><div class="focus-sheet-grip" aria-hidden="true"></div><div class="focus-sheet-heading"><h2 id="focus-sheet-title">Order actions</h2><button type="button" class="quiet" id="focus-sheet-close">Done</button></div><div id="focus-sheet-body"></div></dialog>
<script>
(()=>{
 const sheet=document.getElementById('focus-action-sheet'),body=document.getElementById('focus-sheet-body'),title=document.getElementById('focus-sheet-title');
 if(!sheet||typeof sheet.showModal!=='function')return;
 let source=null,content=null,opener=null;
 const restore=()=>{if(source&&content){source.append(content);source.open=false;}const target=opener;source=null;content=null;opener=null;document.documentElement.classList.remove('focus-sheet-open');target?.focus({preventScroll:true});};
 sheet.addEventListener('close',restore);
 document.getElementById('focus-sheet-close').addEventListener('click',()=>sheet.close());
 sheet.addEventListener('click',event=>{if(event.target===sheet){const rect=sheet.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)sheet.close();}});
 document.querySelectorAll('[data-focus-sheet]').forEach(details=>details.addEventListener('toggle',()=>{
  if(!details.open||!window.matchMedia('(max-width: 760px)').matches||sheet.open)return;
  const next=details.querySelector(':scope > .focus-sheet-content');if(!next)return;
  source=details;content=next;opener=details.querySelector(':scope > summary');
  title.textContent=details.classList.contains('focus-order')?details.querySelector('h3').textContent:opener.textContent.trim();
  body.append(content);details.open=false;sheet.showModal();document.documentElement.classList.add('focus-sheet-open');
 }));
 window.matchMedia('(max-width: 760px)').addEventListener('change',event=>{if(!event.matches&&sheet.open)sheet.close();});
})();
</script>

<?php
// Small, read-only display model. Uploaded photos stay in the existing private catalogue table.
$ankhVisualProducts=[];
if (isset($products,$productCatalogForJs) && is_array($products) && is_array($productCatalogForJs)) {
$ankhVisualContent=isset($customerCatalogueContent) && is_array($customerCatalogueContent)
  ? $customerCatalogueContent : ankhCatalogueContent($db);
$ankhVisualStock=[];
foreach($products as $photoStockProduct){$ankhVisualStock[(int)$photoStockProduct['id']]=$photoStockProduct;}
foreach($productCatalogForJs as $photoCatalogProduct){
 $pid=(int)$photoCatalogProduct['id'];
 $stock=$ankhVisualStock[$pid]??[];
 $meta=$ankhVisualContent[$pid]??[];
 $ankhVisualProducts[]=[
  'id'=>$pid,
  'name'=>(string)$photoCatalogProduct['name'],
  'base'=>(string)$photoCatalogProduct['base'],
  'strength'=>(string)$photoCatalogProduct['strength'],
  'has_photo'=>(int)($meta['image_bytes']??0)>0,
  'tracked'=>(int)($stock['stock_tracking']??0)===1,
  'available'=>max(0,(int)($stock['stock_qty']??0)),
  'low_at'=>max(0,(int)($stock['low_stock_at']??5))
 ];
}
}
?>
<script id="ankh-visual-product-data" type="application/json"><?=json_encode($ankhVisualProducts,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)?></script>
<script src="admin-visuals.js?v=<?=e((string)(@filemtime(__DIR__.'/admin-visuals.js') ?: 0))?>" defer></script>
</body></html>
