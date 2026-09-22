<?php
declare(strict_types=1);
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict']);
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
if (!$config || empty($config['password_hash']) || empty($config['database_path'])) {
 http_response_code(503);
 exit('<!doctype html><meta name="viewport" content="width=device-width"><title>ANKH Admin setup</title><body style="background:#101112;color:#ead9ac;font:18px system-ui;padding:10vw"><h1>☥ ANKH Admin</h1><p>The app is installed. Private access and database configuration need to be completed before orders can be managed.</p>');
}
$db = new PDO('sqlite:'.$config['database_path'], null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
$db->exec('CREATE TABLE IF NOT EXISTS attempts (ip TEXT PRIMARY KEY, failures INTEGER NOT NULL, started INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, price INTEGER NOT NULL CHECK(price>=0), active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY, customer TEXT NOT NULL, phone TEXT NOT NULL, address TEXT NOT NULL, notes TEXT NOT NULL, status TEXT NOT NULL DEFAULT "New", created TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL REFERENCES orders(id), name TEXT NOT NULL, price INTEGER NOT NULL, quantity INTEGER NOT NULL CHECK(quantity>0));
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);');
$orderColumns=$db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('referrer',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN referrer TEXT NOT NULL DEFAULT ''");
$statuses=['New','Awaiting payment','Paid','Packed','Dispatched','Cancelled'];

function setting(PDO $db,string $key,string $default=''):string{
 $q=$db->prepare('SELECT value FROM settings WHERE key=?');$q->execute([$key]);$v=$q->fetchColumn();
 return $v===false?$default:(string)$v;
}
function saveSetting(PDO $db,string $key,string $value):void{
 $db->prepare('INSERT OR REPLACE INTO settings(key,value) VALUES (?,?)')->execute([$key,$value]);
}
function sheetsWebhookValid(string $url):bool{
 $p=parse_url($url);$host=strtolower((string)($p['host']??''));
 return (($p['scheme']??'')==='https') && ($host==='script.google.com' || str_ends_with($host,'.googleusercontent.com'));
}
function orderForSheet(PDO $db,int $id):?array{
 $q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$id]);$o=$q->fetch(PDO::FETCH_ASSOC);if(!$o)return null;
 $q=$db->prepare('SELECT name,price,quantity FROM items WHERE order_id=? ORDER BY id');$q->execute([$id]);$items=$q->fetchAll(PDO::FETCH_ASSOC);
 $total=0;$out=[];
 foreach($items as $i){$line=(int)$i['price']*(int)$i['quantity'];$total+=$line;$out[]=['name'=>$i['name'],'unit_price_pence'=>(int)$i['price'],'quantity'=>(int)$i['quantity']];}
 return ['id'=>(int)$o['id'],'reference'=>'ANK-'.str_pad((string)$o['id'],4,'0',STR_PAD_LEFT),'created'=>$o['created'],'customer'=>$o['customer'],'phone'=>$o['phone'],'referrer'=>$o['referrer']??'','address'=>$o['address'],'notes'=>$o['notes'],'status'=>$o['status'],'total_pence'=>$total,'items'=>$out];
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
if(setting($db,'sheets_sheet_id')==='')saveSetting($db,'sheets_sheet_id','1j9ucRgbGcB56olVTGiBJDJNMxgggB1jg4KUWZuslUwA');
if(setting($db,'sheets_secret')==='')saveSetting($db,'sheets_secret',bin2hex(random_bytes(24)));

if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
 if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) throw new Exception('Please refresh the page and try again.');
 $action=$_POST['action']??'';
 if ($action==='login') {
  $ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');$q=$db->prepare('SELECT * FROM attempts WHERE ip=?');$q->execute([$ip]);$a=$q->fetch(PDO::FETCH_ASSOC);
  if($a && $a['failures']>=5 && time()-(int)$a['started']<900) throw new Exception('Too many attempts. Please wait 15 minutes.');
  if(!password_verify((string)($_POST['password']??''),$config['password_hash'])){
   if(!$a || time()-(int)$a['started']>=900){$q=$db->prepare('INSERT OR REPLACE INTO attempts VALUES (?,1,?)');$q->execute([$ip,time()]);}
   else{$q=$db->prepare('UPDATE attempts SET failures=failures+1 WHERE ip=?');$q->execute([$ip]);}
   throw new Exception('Password not recognised.');
  }
  $db->prepare('DELETE FROM attempts WHERE ip=?')->execute([$ip]);
  session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['last']=time();
 } else {
  if(empty($_SESSION['admin']) || time()-($_SESSION['last']??0)>3600) throw new Exception('Please sign in again.');
  if($action==='logout'){$_SESSION=[];session_destroy();header('Location: ./');exit;}
  if($action==='product'){
   $name=trim($_POST['name']??'');$price=filter_var($_POST['price']??'',FILTER_VALIDATE_FLOAT);
   if(!$name || strlen($name)>160 || $price===false || $price<0 || $price>100000)throw new Exception('Enter a product name and valid price.');
   $id=(int)($_POST['id']??0);
   if($id){$q=$db->prepare('UPDATE products SET name=?,price=?,active=? WHERE id=?');$q->execute([$name,(int)round($price*100),isset($_POST['active'])?1:0,$id]);}
   else{$q=$db->prepare('INSERT INTO products(name,price) VALUES (?,?)');$q->execute([$name,(int)round($price*100)]);}
  }
  if($action==='status'){
   if(!in_array($_POST['status']??'',$statuses,true))throw new Exception('Choose a valid status.');
   $orderId=(int)$_POST['id'];
   $db->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$_POST['status'],$orderId]);
   $syncError=syncOrderToSheet($db,$orderId);
  }
  if($action==='order'){
   $name=trim($_POST['customer']??'');$phone=trim($_POST['phone']??'');$referrer=trim($_POST['referrer']??'');$address=trim($_POST['address']??'');$notes=trim($_POST['notes']??'');
   if(!$name || strlen($name)>160 || strlen($phone)>40 || strlen($referrer)>160 || strlen($address)>2000 || strlen($notes)>4000)throw new Exception('Check the customer details and try again.');
   $lines=[];foreach(($_POST['qty']??[]) as $id=>$qty){$n=filter_var($qty,FILTER_VALIDATE_INT);if($n===false || $n<0 || $n>999)throw new Exception('Quantities must be between 0 and 999.');if(!$n)continue;$q=$db->prepare('SELECT * FROM products WHERE id=? AND active=1');$q->execute([(int)$id]);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)throw new Exception('A selected product is unavailable.');$price=filter_var($_POST['price'][$id]??((int)$p['price']/100),FILTER_VALIDATE_FLOAT);if($price===false||$price<0||$price>100000)throw new Exception('Check the price for '.$p['name'].'.');$lines[]=[$p,$n,(int)round($price*100)];}
   if(!$lines)throw new Exception('Add at least one product.');
   $db->beginTransaction();
   $db->prepare('INSERT INTO orders(customer,phone,address,notes,created,referrer) VALUES (?,?,?,?,?,?)')->execute([$name,$phone,$address,$notes,gmdate('c'),$referrer]);$oid=$db->lastInsertId();
   foreach($lines as [$p,$n,$orderPrice])$db->prepare('INSERT INTO items(order_id,name,price,quantity) VALUES (?,?,?,?)')->execute([$oid,$p['name'],$orderPrice,$n]);
   $db->commit();
   $syncError=syncOrderToSheet($db,(int)$oid);
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
 $flash=$action==='order'?'Order saved.':($action==='status'?'Order status updated.':($action==='product'?'Product saved.':($action==='sheets_settings'?'Google Sheets connection saved.':'')));
 if($flash!=='' && is_string($syncError) && $syncError!=='')$flash.=' Google Sheets sync failed — open the Google Sheets page to retry.';
 if($flash!=='')$_SESSION['flash']=$flash;
 header('Location: ./?view='.urlencode($_POST['return']??'orders'));exit;
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();$error=$ex instanceof PDOException?'Could not save. Please try again.':$ex->getMessage();}
}
$auth=!empty($_SESSION['admin']) && time()-($_SESSION['last']??0)<=3600;
if($auth)$_SESSION['last']=time();
function csrf(){echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';}
function money($n){return '£'.number_format((float)$n/100,2);}
$view=in_array($_GET['view']??'', ['orders','new','products','customers','sheets'],true)?$_GET['view']:'orders';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#101112"><meta name="robots" content="noindex,nofollow"><title>ANKH • Order desk</title><link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23101112'/%3E%3Ctext x='6' y='26' font-size='28' fill='%23dfb666'%3E☥%3C/text%3E%3C/svg%3E"><link rel="stylesheet" href="style.css?v=mobile3"></head><body>
<?php if(!$auth): ?>
<main class="login"><div class="mark">☥</div><p class="eyebrow">ANKH / PRIVATE ACCESS</p><h1>Your order desk.</h1><p class="muted">Sign in to manage ANKH orders.</p><?php if($error):?><p role="alert" class="error"><?=e($error)?></p><?php endif;?>
<form method="post"><?php csrf();?><input type="hidden" name="action" value="login"><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button>Sign in →</button></form></main>
<?php else:
$products=$db->query('SELECT * FROM products ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$orders=$db->query('SELECT o.*,COALESCE(SUM(i.price*i.quantity),0) AS total FROM orders o LEFT JOIN items i ON i.order_id=o.id GROUP BY o.id ORDER BY o.id DESC')->fetchAll(PDO::FETCH_ASSOC);
$open=count(array_filter($orders,fn($o)=>!in_array($o['status'],['Dispatched','Cancelled'])));
$paid=array_sum(array_map(fn($o)=>in_array($o['status'],['Paid','Packed','Dispatched'])?$o['total']:0,$orders));
$referrers=$db->query("SELECT DISTINCT referrer FROM orders WHERE referrer<>'' ORDER BY referrer COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN);
$sheetWebhook=setting($db,'sheets_webhook');$sheetId=setting($db,'sheets_sheet_id');$sheetSecret=setting($db,'sheets_secret');$sheetLastSync=setting($db,'sheets_last_sync');$sheetLastError=setting($db,'sheets_last_error');
?>
<aside><a class="brand" href="./"><span>☥</span> ANKH<small>ORDER DESK</small></a><nav><?php foreach(['orders'=>'Orders','new'=>'New order','customers'=>'Customers','products'=>'Products','sheets'=>'Google Sheets'] as $key=>$label):?><a class="<?=$view===$key?'selected':''?>" href="?view=<?=$key?>"><?=$label?></a><?php endforeach;?></nav><form method="post"><?php csrf();?><input type="hidden" name="action" value="logout"><button class="quiet">Sign out</button></form></aside>
<main><header><p class="eyebrow">ANKH PEPTIDES / ADMIN</p><span class="muted"><?=date('d M Y')?></span></header>
<?php if($error):?><p role="alert" class="error"><?=e($error)?></p><?php endif;?>
<?php if(!empty($_SESSION['flash'])):?><p class="success" role="status"><?=e($_SESSION['flash'])?></p><?php unset($_SESSION['flash']);endif;?>
<?php if($view==='orders'): ?>
<div class="heading"><div><h1>Orders</h1><p class="muted">Tap an order to see its items and update its progress.</p></div><a class="button" href="?view=new">+ New order</a></div>
<div class="stats"><article><span>Open orders</span><strong><?=$open?></strong></article><article><span>Paid order value · all time</span><strong><?=money($paid)?></strong></article><article><span>Total orders</span><strong><?=count($orders)?></strong></article></div>
<div class="filters"><label>Search orders<input id="search" placeholder="Name, phone or order number"></label><label>Status<select id="filter"><option value="">All statuses</option><?php foreach($statuses as $s):?><option><?=e($s)?></option><?php endforeach;?></select></label></div>
<div class="order-list">
<?php foreach($orders as $o):?><details class="order" data-search="<?=e(strtolower($o['customer'].' '.$o['phone'].' '.($o['referrer']??'').' ANK-'.$o['id']))?>" data-status="<?=e($o['status'])?>"><summary><div><span class="ref">ANK-<?=str_pad((string)$o['id'],4,'0',STR_PAD_LEFT)?></span><h2><?=e($o['customer'])?></h2><span class="muted"><?=e(date('d M Y',strtotime($o['created'])))?></span></div><div class="order-right"><span class="badge"><?=e($o['status'])?></span><strong><?=money($o['total'])?></strong><small>Tap to open ↓</small></div></summary><div class="detail">
<p><?=e($o['phone'])?></p><?php if(!empty($o['referrer'])):?><p><strong>Referred by:</strong> <?=e($o['referrer'])?></p><?php endif;?><p class="address"><?=nl2br(e($o['address']))?></p>
<?php $q=$db->prepare('SELECT * FROM items WHERE order_id=?');$q->execute([$o['id']]);foreach($q as $i):?><div class="line"><span><?=e($i['quantity'].' × '.$i['name'].' @ '.money($i['price']).' each')?></span><strong><?=money($i['price']*$i['quantity'])?></strong></div><?php endforeach;?>
<?php if($o['notes']):?><p class="note"><?=nl2br(e($o['notes']))?></p><?php endif;?>
<form method="post" class="status-form"><?php csrf();?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$o['id']?>"><label>Order status<select name="status"><?php foreach($statuses as $s):?><option <?=$s===$o['status']?'selected':''?>><?=e($s)?></option><?php endforeach;?></select></label><button>Save status</button></form></div></details><?php endforeach;?></div>
<p id="empty" class="empty" <?=count($orders)?'hidden':''?>>No orders to show. Create an order to get started.</p>
<?php elseif($view==='new'):?>
<h1>New order</h1><p class="muted">Customer → products → save. That's it.</p><form method="post" class="panel"><?php csrf();?><input type="hidden" name="action" value="order"><h2 class="step">1. Customer</h2><div class="two"><label>Customer name<input name="customer" maxlength="160" required autocomplete="name" value="<?=e($_POST['customer']??'')?>"></label><label>Phone<input name="phone" maxlength="40" type="tel" autocomplete="tel" value="<?=e($_POST['phone']??'')?>"></label></div><label>Referrer <span class="muted">(optional)</span><input name="referrer" maxlength="160" list="referrer-list" placeholder="Who sent them to us?" value="<?=e($_POST['referrer']??'')?>"></label><datalist id="referrer-list"><?php foreach($referrers as $r):?><option value="<?=e($r)?>"><?php endforeach;?></datalist><label>Delivery address<textarea name="address" maxlength="2000" autocomplete="street-address"><?=e($_POST['address']??'')?></textarea></label><h2 class="step">2. Products</h2><p class="muted">Tap + to add it. If they have a discount, change the price that appears underneath.</p>
<?php foreach($products as $p):if(!$p['active'])continue;$postedPrice=$_POST['price'][$p['id']]??number_format((int)$p['price']/100,2,'.','');?><div class="product-pick"><span><?=e($p['name'])?><small>Standard <?=money($p['price'])?></small></span><div class="stepper"><button type="button" data-change="-1" aria-label="Remove one <?=e($p['name'])?>">−</button><input inputmode="numeric" aria-label="<?=e($p['name'])?> quantity" class="quantity" name="qty[<?=$p['id']?>]" type="number" min="0" max="999" value="<?=e($_POST['qty'][$p['id']]??0)?>"><button type="button" data-change="1" aria-label="Add one <?=e($p['name'])?>">+</button></div><label class="order-price">Price each for this order (£)<input class="line-price" name="price[<?=$p['id']?>]" type="number" min="0" max="100000" step=".01" inputmode="decimal" value="<?=e($postedPrice)?>"></label></div><?php endforeach;?>
<?php if(!$products):?><p>Add products in the <a href="?view=products">Products tab</a> first.</p><?php endif;?>
<h2 class="step">3. Check and save</h2><div class="line"><strong>Product subtotal</strong><strong id="subtotal">£0.00</strong></div><label>Notes (optional)<textarea name="notes" maxlength="4000" placeholder="Delivery instructions, payment reference…"><?=e($_POST['notes']??'')?></textarea></label><p class="muted">Saving records the order. It does not take payment or message the customer.</p><button class="save-order">Save this order</button></form>
<?php elseif($view==='products'):?>
<h1>Products</h1><p class="muted">Changes apply to new orders. Existing orders keep their original prices.</p>
<form method="post" class="panel"><?php csrf();?><input type="hidden" name="action" value="product"><input type="hidden" name="return" value="products"><h2>Add product</h2><div class="two"><label>Name and strength<input name="name" required maxlength="160" placeholder="Product name · 5mg"></label><label>Price (£)<input name="price" type="number" min="0" max="100000" step=".01" required></label></div><button>Add product</button></form>
<?php foreach($products as $p):?><details class="order"><summary><h2><?=e($p['name'])?></h2><span><?=money($p['price'])?> · <?=$p['active']?'Active':'Hidden'?></span></summary><form method="post" class="detail"><?php csrf();?><input type="hidden" name="action" value="product"><input type="hidden" name="return" value="products"><input type="hidden" name="id" value="<?=$p['id']?>"><label>Name<input name="name" required maxlength="160" value="<?=e($p['name'])?>"></label><label>Price (£)<input name="price" type="number" min="0" max="100000" step=".01" required value="<?=e($p['price']/100)?>"></label><label class="check"><input type="checkbox" name="active" <?=$p['active']?'checked':''?>> Available for new orders</label><button>Save product</button></form></details><?php endforeach;?>
<?php elseif($view==='sheets'):?>
<h1>Google Sheets</h1><p class="muted">Orders stay safely in this app and can also be mirrored into your ANKH Google Sheet.</p>
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
<?php else:?>
<h1>Customers</h1><p class="muted">Customer history from your recorded orders.</p>
<?php $customers=[];foreach($orders as $o){$key=strtolower($o['customer']).'|'.$o['phone'];$customers[$key]??=['name'=>$o['customer'],'phone'=>$o['phone'],'orders'=>[]];$customers[$key]['orders'][]=$o;}foreach($customers as $c):?>
<details class="order"><summary><div><h2><?=e($c['name'])?></h2><span class="muted"><?=e($c['phone'])?></span></div><span><?=count($c['orders'])?> orders</span></summary><div class="detail"><?php foreach($c['orders'] as $o):?><div class="line"><span>ANK-<?=$o['id']?> · <?=e($o['status'])?></span><strong><?=money($o['total'])?></strong></div><?php endforeach;?></div></details>
<?php endforeach;if(!$customers):?><p class="empty">Customers appear here when you create orders.</p><?php endif;endif;?>
</main><script>
const search=document.querySelector('#search'),filter=document.querySelector('#filter');
function applyFilters(){let visible=0;document.querySelectorAll('.order-list .order').forEach(o=>{o.hidden=!(o.dataset.search.includes(search.value.toLowerCase().trim())&&(!filter.value||o.dataset.status===filter.value));if(!o.hidden)visible++});document.querySelector('#empty').hidden=visible>0}
search?.addEventListener('input',applyFilters);filter?.addEventListener('change',applyFilters);
function updateTotal(){let total=0;document.querySelectorAll('.quantity').forEach(x=>{const row=x.closest('.product-pick'),price=Math.max(0,Number(row.querySelector('.line-price')?.value)||0),qty=Math.max(0,Number(x.value)||0);total+=qty*price;row.classList.toggle('picked',qty>0)});const out=document.querySelector('#subtotal');if(out){out.setAttribute('aria-live','polite');out.textContent=new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP'}).format(total)}}
document.querySelectorAll('.quantity,.line-price').forEach(q=>q.addEventListener('input',updateTotal));
document.querySelectorAll('[data-change]').forEach(b=>b.addEventListener('click',()=>{const q=b.parentElement.querySelector('input');q.value=Math.min(999,Math.max(0,(Number(q.value)||0)+Number(b.dataset.change)));updateTotal()}));
updateTotal();
const orderForm=document.querySelector('.save-order')?.form;
orderForm?.addEventListener('submit',e=>{if(!Array.from(orderForm.querySelectorAll('.quantity')).some(q=>Number(q.value)>0)){e.preventDefault();alert('Choose at least one product using the + buttons.');orderForm.querySelector('[data-change]')?.focus();return;}const b=orderForm.querySelector('.save-order');b.disabled=true;b.textContent='Saving order…'});
</script><?php endif;?></body></html>
