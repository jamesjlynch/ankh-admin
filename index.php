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
$savedOrderId = 0;
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
CREATE TABLE IF NOT EXISTS customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL COLLATE NOCASE, phone TEXT NOT NULL DEFAULT "", address TEXT NOT NULL DEFAULT "", created TEXT NOT NULL, UNIQUE(name,phone));
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);');
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
$itemColumns=$db->query('PRAGMA table_info(items)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('cost',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN cost INTEGER DEFAULT NULL");
if(!in_array('presentation',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN presentation TEXT NOT NULL DEFAULT ''");
if(!in_array('presentation_cost',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN presentation_cost INTEGER NOT NULL DEFAULT 0");
if(!in_array('base_price',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN base_price INTEGER DEFAULT NULL");
if(!in_array('discount',array_column($itemColumns,'name'),true))$db->exec("ALTER TABLE items ADD COLUMN discount INTEGER NOT NULL DEFAULT 0");
$productColumns=$db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('cost',array_column($productColumns,'name'),true))$db->exec("ALTER TABLE products ADD COLUMN cost INTEGER DEFAULT NULL");
if(!in_array('stock_qty',array_column($productColumns,'name'),true))$db->exec("ALTER TABLE products ADD COLUMN stock_qty INTEGER DEFAULT NULL");
if(!in_array('low_stock_at',array_column($productColumns,'name'),true))$db->exec("ALTER TABLE products ADD COLUMN low_stock_at INTEGER NOT NULL DEFAULT 2");
$customerColumns=$db->query('PRAGMA table_info(customers)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('archived',array_column($customerColumns,'name'),true))$db->exec("ALTER TABLE customers ADD COLUMN archived INTEGER NOT NULL DEFAULT 0");

// Bring existing order customers into the standalone customer list, newest details first.
$existingOrderCustomers=$db->query("SELECT customer,phone,address,created FROM orders WHERE trim(customer)<>'' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$seedCustomer=$db->prepare('INSERT OR IGNORE INTO customers(name,phone,address,created) VALUES (?,?,?,?)');
foreach($existingOrderCustomers as $existingCustomer){
 $seedCustomer->execute([$existingCustomer['customer'],$existingCustomer['phone'],$existingCustomer['address'],$existingCustomer['created']]);
}
$statuses=['New','Awaiting payment','Paid','Packed','Dispatched','Delivered','Cancelled'];
$paymentMethods=['Cash','Bank Transfer','Card','PayPal','Other'];
$deliveryMethods=['Collection','Local Delivery','Postage'];

// Keep the live order catalogue complete without overwriting manually managed prices.
function setting(PDO $db,string $key,string $default=''):string{
 $q=$db->prepare('SELECT value FROM settings WHERE key=?');$q->execute([$key]);$v=$q->fetchColumn();
 return $v===false?$default:(string)$v;
}

function saveSetting(PDO $db,string $key,string $value):void{
 $db->prepare('INSERT OR REPLACE INTO settings(key,value) VALUES (?,?)')->execute([$key,$value]);
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
 'Acetic Acid 0.6% 10ml'=>499
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
$catalogVersion='retail-posters-2026-09-22-v3';
$findProduct=$db->prepare('SELECT id,cost FROM products WHERE lower(trim(name))=lower(trim(?)) ORDER BY id LIMIT 1');
$insertProduct=$db->prepare('INSERT INTO products(name,price,cost,active) VALUES (?,?,?,1)');
$fillProductCost=$db->prepare('UPDATE products SET cost=? WHERE id=? AND cost IS NULL');

// Keep every current retail product available in the database without undoing manual edits.
foreach($currentRetailCatalog as $retailName=>$retailPrice){
 $findProduct->execute([$retailName]);$existing=$findProduct->fetch(PDO::FETCH_ASSOC);
 if(!$existing){
  $insertProduct->execute([$retailName,$retailPrice,$currentSupplierCosts[$retailName]??null]);$existingId=(int)$db->lastInsertId();
 }else{
  $existingId=(int)$existing['id'];
  if(isset($currentSupplierCosts[$retailName]))$fillProductCost->execute([$currentSupplierCosts[$retailName],$existingId]);
 }
}

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

// Cost snapshots: fill only legacy rows that do not already have a saved cost.
$db->exec("UPDATE items SET cost=(SELECT p.cost FROM products p WHERE lower(trim(p.name))=lower(trim(items.name)) LIMIT 1) WHERE cost IS NULL AND lower(trim(name))<>'pen'");
$db->exec("UPDATE items SET base_price=price WHERE base_price IS NULL AND lower(trim(name))<>'pen'");
$penCostSetting=setting($db,'pen_cost_pence','');
if($penCostSetting!=='' && ctype_digit($penCostSetting))$db->prepare("UPDATE items SET cost=? WHERE cost IS NULL AND lower(trim(name))='pen'")->execute([(int)$penCostSetting]);
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
 return [
  'id'=>(int)$o['id'],'reference'=>'ANK-'.str_pad((string)$o['id'],4,'0',STR_PAD_LEFT),'created'=>$o['created'],
  'customer'=>$o['customer'],'phone'=>$o['phone'],'referrer'=>$o['referrer']??'','presentation'=>$o['presentation']??'',
  'payment_method'=>$o['payment_method']??'','delivery_method'=>$o['delivery_method']??'','tracking_reference'=>$o['tracking_reference']??'',
  'delivery_charge_pence'=>(int)($o['delivery_charge']??0),'postage_cost_pence'=>(int)($o['postage_cost']??0),'payment_fee_pence'=>(int)($o['payment_fee']??0),
  'payment_date'=>$o['payment_date']??'','delivery_date'=>$o['delivery_date']??'','address'=>$o['address'],'notes'=>$o['notes'],
  'status'=>$o['status'],'total_pence'=>$total,'items'=>$out
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

// TEMPORARY TEST MODE: set to false when testing is finished.
$testingNoAuth=true;
if($testingNoAuth){$_SESSION['admin']=true;$_SESSION['last']=time();}

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
   $costRaw=trim((string)($_POST['cost']??''));$stockRaw=trim((string)($_POST['stock_qty']??''));$lowRaw=trim((string)($_POST['low_stock_at']??'2'));
   $cost=$costRaw===''?null:filter_var($costRaw,FILTER_VALIDATE_FLOAT);$stock=$stockRaw===''?null:filter_var($stockRaw,FILTER_VALIDATE_INT);$low=filter_var($lowRaw,FILTER_VALIDATE_INT);
   if(!$name || strlen($name)>160 || $price===false || $price<0 || $price>100000 || ($costRaw!==''&&($cost===false||$cost<0||$cost>100000)) || ($stockRaw!==''&&($stock===false||$stock<0||$stock>999999)) || $low===false || $low<0 || $low>999999)throw new Exception('Enter valid product, price and stock details.');
   $id=(int)($_POST['id']??0);$costPence=$cost===null?null:(int)round($cost*100);
   if($id){$q=$db->prepare('UPDATE products SET name=?,price=?,cost=?,stock_qty=?,low_stock_at=?,active=? WHERE id=?');$q->execute([$name,(int)round($price*100),$costPence,$stock,$low,isset($_POST['active'])?1:0,$id]);}
   else{$q=$db->prepare('INSERT INTO products(name,price,cost,stock_qty,low_stock_at) VALUES (?,?,?,?,?)');$q->execute([$name,(int)round($price*100),$costPence,$stock,$low]);}
  }
  if($action==='customer'){
   $customerId=(int)($_POST['id']??0);
   $customerName=trim($_POST['name']??'');$customerPhone=trim($_POST['phone']??'');$customerAddress=trim($_POST['address']??'');
   if(!$customerName || strlen($customerName)>160 || strlen($customerPhone)>40 || strlen($customerAddress)>2000)throw new Exception('Check the customer details and try again.');
   if($customerId){
    $q=$db->prepare('SELECT * FROM customers WHERE id=?');$q->execute([$customerId]);$oldCustomer=$q->fetch(PDO::FETCH_ASSOC);
    if(!$oldCustomer)throw new Exception('Customer could not be found.');
    $q=$db->prepare('SELECT id FROM customers WHERE lower(trim(name))=lower(trim(?)) AND trim(phone)=trim(?) AND id<>? LIMIT 1');
    $q->execute([$customerName,$customerPhone,$customerId]);
    if($q->fetchColumn())throw new Exception('A customer with that name and phone already exists.');
    $db->beginTransaction();
    $db->prepare('UPDATE customers SET name=?,phone=?,address=?,archived=0 WHERE id=?')->execute([$customerName,$customerPhone,$customerAddress,$customerId]);
    $db->prepare('UPDATE orders SET customer=?,phone=? WHERE lower(trim(customer))=lower(trim(?)) AND trim(phone)=trim(?)')->execute([$customerName,$customerPhone,$oldCustomer['name'],$oldCustomer['phone']]);
    $db->commit();
   }else{
    $q=$db->prepare("INSERT INTO customers(name,phone,address,created,archived) VALUES (?,?,?,?,0) ON CONFLICT(name,phone) DO UPDATE SET address=CASE WHEN excluded.address<>'' THEN excluded.address ELSE customers.address END, archived=0");
    $q->execute([$customerName,$customerPhone,$customerAddress,gmdate('c')]);
   }
  }
  if($action==='customer_archive'){
   $customerId=(int)($_POST['id']??0);$archive=($_POST['archive']??'1')==='1'?1:0;
   $q=$db->prepare('UPDATE customers SET archived=? WHERE id=?');$q->execute([$archive,$customerId]);
   if(!$q->rowCount())throw new Exception('Customer could not be found.');
  }
  if($action==='status'){
   $newStatus=(string)($_POST['status']??'');
   if(!in_array($newStatus,$statuses,true))throw new Exception('Choose a valid status.');
   $orderId=(int)$_POST['id'];$todayAction=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d');
   $paymentDate=array_key_exists('payment_date',$_POST)?orderActionDate((string)$_POST['payment_date'],'payment'):'';
   $deliveryDate=array_key_exists('delivery_date',$_POST)?orderActionDate((string)$_POST['delivery_date'],'delivery'):'';
   $paymentMethod=trim((string)($_POST['payment_method']??''));$paymentFee=array_key_exists('payment_fee',$_POST)?postedMoneyPence($_POST['payment_fee'],'payment fee'):-1;
   if($paymentMethod!==''&&!in_array($paymentMethod,$paymentMethods,true))throw new Exception('Choose a valid payment method.');
   $q=$db->prepare('SELECT payment_date,delivery_date,payment_method FROM orders WHERE id=?');$q->execute([$orderId]);$existingDates=$q->fetch(PDO::FETCH_ASSOC);
   if(!$existingDates)throw new Exception('Order could not be found.');
   if($newStatus==='Paid'){
    if($paymentDate==='')$paymentDate=(string)($existingDates['payment_date']?:$todayAction);
    if($paymentMethod==='' && (string)$existingDates['payment_method']==='')throw new Exception('Choose how the payment was received.');
   }
   if($newStatus==='Delivered' && $deliveryDate==='')$deliveryDate=(string)($existingDates['delivery_date']?:$todayAction);
   $db->prepare("UPDATE orders SET status=?,payment_date=CASE WHEN ?<>'' THEN ? ELSE payment_date END,delivery_date=CASE WHEN ?<>'' THEN ? ELSE delivery_date END,payment_method=CASE WHEN ?<>'' THEN ? ELSE payment_method END,payment_fee=CASE WHEN ?>=0 THEN ? ELSE payment_fee END WHERE id=?")->execute([$newStatus,$paymentDate,$paymentDate,$deliveryDate,$deliveryDate,$paymentMethod,$paymentMethod,$paymentFee,$paymentFee,$orderId]);
   $syncError=syncOrderToSheet($db,$orderId);
  }
  if($action==='order_dates'){
   $orderId=(int)($_POST['id']??0);$paymentDate=orderActionDate((string)($_POST['payment_date']??''),'payment');$deliveryDate=orderActionDate((string)($_POST['delivery_date']??''),'delivery');
   $q=$db->prepare('UPDATE orders SET payment_date=?,delivery_date=? WHERE id=?');$q->execute([$paymentDate,$deliveryDate,$orderId]);
   if(!$q->rowCount()){$check=$db->prepare('SELECT id FROM orders WHERE id=?');$check->execute([$orderId]);if(!$check->fetchColumn())throw new Exception('Order could not be found.');}
   $syncError=syncOrderToSheet($db,$orderId);
  }
  if($action==='order_delete'){
   $orderId=(int)($_POST['id']??0);
   $q=$db->prepare('SELECT id FROM orders WHERE id=?');$q->execute([$orderId]);
   if(!$q->fetchColumn())throw new Exception('Order could not be found.');
   $reference='ANK-'.str_pad((string)$orderId,4,'0',STR_PAD_LEFT);
   $db->beginTransaction();
   $db->prepare('DELETE FROM items WHERE order_id=?')->execute([$orderId]);
   $db->prepare('DELETE FROM orders WHERE id=?')->execute([$orderId]);
   $db->commit();
   $syncError=deleteOrderFromSheet($db,$reference);
  }
  if($action==='order' || $action==='order_edit'){
   $isEdit=$action==='order_edit';$orderId=$isEdit?(int)($_POST['id']??0):0;$existingOrder=null;$existingItemCosts=[];
   if($isEdit){
    $q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$orderId]);$existingOrder=$q->fetch(PDO::FETCH_ASSOC);if(!$existingOrder)throw new Exception('Order could not be found.');
    $q=$db->prepare('SELECT name,cost,presentation_cost,base_price FROM items WHERE order_id=?');$q->execute([$orderId]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $existingItem){$existingItemCosts[strtolower(trim((string)$existingItem['name']))]=['cost'=>$existingItem['cost']===null?null:(int)$existingItem['cost'],'presentation_cost'=>(int)($existingItem['presentation_cost']??0),'base_price'=>$existingItem['base_price']===null?null:(int)$existingItem['base_price']];}
   }
   $name=trim($_POST['customer']??'');$phone=trim($_POST['phone']??'');$referrer=trim($_POST['referrer']??'');$address=trim($_POST['address']??'');$notes=trim($_POST['notes']??'');$orderDate=trim($_POST['order_date']??'');
   $paymentMethod=trim((string)($_POST['payment_method']??''));$deliveryMethod=trim((string)($_POST['delivery_method']??''));$trackingReference=trim((string)($_POST['tracking_reference']??''));
   $deliveryCharge=postedMoneyPence($_POST['delivery_charge']??'','postage charge');$postageCost=postedMoneyPence($_POST['postage_cost']??'','postage cost');$paymentFee=postedMoneyPence($_POST['payment_fee']??'','payment fee');
   if(!$name || strlen($name)>160 || strlen($phone)>40 || strlen($referrer)>160 || strlen($address)>2000 || strlen($notes)>4000 || strlen($trackingReference)>200)throw new Exception('Check the order details and try again.');
   if($paymentMethod!==''&&!in_array($paymentMethod,$paymentMethods,true))throw new Exception('Choose a valid payment method.');
   if(!in_array($deliveryMethod,$deliveryMethods,true))throw new Exception('Choose Collection, Local Delivery or Postage.');
   if($deliveryMethod!=='Postage'){$trackingReference='';$deliveryCharge=0;$postageCost=0;}
   $orderTz=new DateTimeZone('Europe/London');$todayLocal=new DateTimeImmutable('today',$orderTz);$chosenDate=DateTimeImmutable::createFromFormat('!Y-m-d',$orderDate,$orderTz);
   if(!$chosenDate || $chosenDate->format('Y-m-d')!==$orderDate || $chosenDate>$todayLocal)throw new Exception('Choose a valid order date.');

   $lines=[];$presentations=[];
   foreach(($_POST['lines']??[]) as $lineKey=>$line){
    if(!is_array($line))continue;
    $productId=(int)($line['product_id']??0);$qty=filter_var($line['quantity']??0,FILTER_VALIDATE_INT);$format=trim((string)($line['presentation']??''));$discountFlag=((string)($line['discount']??'0'))==='1';
    if($productId<1||$qty===false||$qty<1||$qty>999)throw new Exception('Check the product quantities.');
    if(!in_array($format,['Pen','Cartridge','Vial'],true))throw new Exception('Choose Pen, Cartridge or Vial for every peptide.');
    $q=$db->prepare($isEdit?'SELECT * FROM products WHERE id=?':'SELECT * FROM products WHERE id=? AND active=1');$q->execute([$productId]);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)throw new Exception('A selected product is unavailable.');
    $basePrice=postedMoneyPence($line['base_price']??number_format((int)$p['price']/100,2,'.',''),'base price');
    if($basePrice<0)$basePrice=(int)$p['price'];
    $discount=$discountFlag?min(500,$basePrice):0;$formatCharge=$format==='Pen'?2000:0;
    $calculatedPrice=max(0,$basePrice-$discount+$formatCharge);
    $postedPrice=postedMoneyPence($line['price']??number_format($calculatedPrice/100,2,'.',''),'line price');
    $costKey=strtolower(trim((string)$p['name']));$saved=$existingItemCosts[$costKey]??null;
    $productCost=$saved&&array_key_exists('cost',$saved)?$saved['cost']:($p['cost']===null?null:(int)$p['cost']);
    $penCostSetting=setting($db,'pen_cost_pence','');$presentationCost=$format==='Pen'?($penCostSetting!==''?(int)$penCostSetting:0):0;
    if($saved && $format==='Pen' && (int)$saved['presentation_cost']>0)$presentationCost=(int)$saved['presentation_cost'];
    $lines[]=['product'=>$p,'qty'=>(int)$qty,'presentation'=>$format,'base_price'=>$basePrice,'discount'=>$discount,'price'=>$postedPrice,'cost'=>$productCost,'presentation_cost'=>$presentationCost];
    $presentations[$format]=true;
   }
   if(!$lines)throw new Exception('Add at least one peptide.');
   $orderPresentation=count($presentations)===1?(string)array_key_first($presentations):'Mixed';

   if($isEdit){
    $originalLocal=(new DateTimeImmutable((string)$existingOrder['created']))->setTimezone($orderTz);
    $created=(new DateTimeImmutable($orderDate.' '.$originalLocal->format('H:i:s'),$orderTz))->setTimezone(new DateTimeZone('UTC'))->format('c');
   }else{
    $created=$chosenDate->format('Y-m-d')===$todayLocal->format('Y-m-d')?gmdate('c'):(new DateTimeImmutable($orderDate.' 12:00:00',$orderTz))->setTimezone(new DateTimeZone('UTC'))->format('c');
   }

   $db->beginTransaction();
   if($isEdit){
    $db->prepare('UPDATE orders SET customer=?,phone=?,address=?,notes=?,created=?,referrer=?,presentation=?,payment_method=?,delivery_method=?,tracking_reference=?,delivery_charge=?,postage_cost=?,payment_fee=? WHERE id=?')->execute([$name,$phone,$address,$notes,$created,$referrer,$orderPresentation,$paymentMethod,$deliveryMethod,$trackingReference,$deliveryCharge,$postageCost,$paymentFee,$orderId]);
    $db->prepare('DELETE FROM items WHERE order_id=?')->execute([$orderId]);
   }else{
    $db->prepare('INSERT INTO orders(customer,phone,address,notes,created,referrer,presentation,payment_method,delivery_method,tracking_reference,delivery_charge,postage_cost,payment_fee) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$name,$phone,$address,$notes,$created,$referrer,$orderPresentation,$paymentMethod,$deliveryMethod,$trackingReference,$deliveryCharge,$postageCost,$paymentFee]);$orderId=(int)$db->lastInsertId();$savedOrderId=$orderId;
   }
   $saveCustomer=$db->prepare("INSERT INTO customers(name,phone,address,created,archived) VALUES (?,?,?,?,0) ON CONFLICT(name,phone) DO UPDATE SET address=CASE WHEN excluded.address<>'' THEN excluded.address ELSE customers.address END, archived=0");$saveCustomer->execute([$name,$phone,$address,$created]);
   $insertItem=$db->prepare('INSERT INTO items(order_id,name,price,cost,presentation,presentation_cost,base_price,discount,quantity) VALUES (?,?,?,?,?,?,?,?,?)');
   foreach($lines as $line)$insertItem->execute([$orderId,$line['product']['name'],$line['price'],$line['cost'],$line['presentation'],$line['presentation_cost'],$line['base_price'],$line['discount'],$line['qty']]);
   $db->commit();$syncError=syncOrderToSheet($db,$orderId);
  }

  if($action==='profit_settings'){
   $penCost=postedMoneyPence($_POST['pen_cost']??'','pen cost');saveSetting($db,'pen_cost_pence',(string)$penCost);
   $db->prepare("UPDATE items SET cost=? WHERE cost IS NULL AND lower(trim(name))='pen'")->execute([$penCost]);
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
 $flash=$action==='order'?'Order saved.':($action==='order_edit'?'Order updated.':($action==='profit_settings'?'Profit settings saved.':($action==='order_delete'?'Order deleted.':($action==='order_dates'?'Order dates updated.':($action==='status'?'Order status updated.':($action==='product'?'Product saved.':($action==='customer'?((int)($_POST['id']??0)?'Customer updated.':'Customer added.'):($action==='customer_archive'?((($_POST['archive']??'1')==='1')?'Customer archived.':'Customer restored.'):($action==='sheets_settings'?'Google Sheets connection saved.':'')))))))));
 if($flash!=='' && is_string($syncError) && $syncError!=='')$flash.=' Google Sheets sync failed — open the Google Sheets page to retry.';
 if($flash!=='')$_SESSION['flash']=$flash;
 if($action==='order' && $savedOrderId>0){header('Location: ./?view=saved&id='.$savedOrderId);exit;}
 header('Location: ./?view='.urlencode($_POST['return']??'orders'));exit;
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();$error=$ex instanceof PDOException?'Could not save. Please try again.':$ex->getMessage();}
}
$auth=!empty($_SESSION['admin']) && time()-($_SESSION['last']??0)<=3600;
if($auth)$_SESSION['last']=time();
function csrf(){echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';}
function money($n){return '£'.number_format((float)$n/100,2);}
function statusClass(string $status):string{return preg_replace('/[^a-z0-9]+/','-',strtolower(trim($status)));}
$view=in_array($_GET['view']??'', ['dashboard','orders','new','edit','products','customers','sheets','reports','more','saved'],true)?$_GET['view']:'dashboard';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#101112"><meta name="robots" content="noindex,nofollow"><title>ANKH • Order desk</title><link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23101112'/%3E%3Ctext x='6' y='26' font-size='28' fill='%23dfb666'%3E☥%3C/text%3E%3C/svg%3E"><link rel="stylesheet" href="style.css?v=mobile27"></head><body>
<?php if(!$auth): ?>
<main class="login"><div class="mark">☥</div><p class="eyebrow">ANKH / PRIVATE ACCESS</p><h1>Your order desk.</h1><p class="muted">Sign in to manage ANKH orders.</p><?php if($error):?><p role="alert" class="error"><?=e($error)?></p><?php endif;?>
<form method="post"><?php csrf();?><input type="hidden" name="action" value="login"><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button>Sign in →</button></form></main>
<?php else:
$products=$db->query('SELECT * FROM products ORDER BY active DESC,name')->fetchAll(PDO::FETCH_ASSOC);
$orders=$db->query('SELECT o.*,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) AS total FROM orders o LEFT JOIN items i ON i.order_id=o.id GROUP BY o.id ORDER BY o.id DESC')->fetchAll(PDO::FETCH_ASSOC);
$paidStatuses=['Paid','Packed','Dispatched','Delivered'];
$open=count(array_filter($orders,fn($o)=>!in_array($o['status'],['Dispatched','Delivered','Cancelled'],true)));
$paid=array_sum(array_map(fn($o)=>in_array($o['status'],$paidStatuses,true)?$o['total']:0,$orders));

$storedCustomers=$db->query('SELECT * FROM customers ORDER BY archived ASC,name COLLATE NOCASE,id')->fetchAll(PDO::FETCH_ASSOC);
$customerSuggestions=array_values(array_map(fn($customer)=>[
 'id'=>(int)$customer['id'],
 'name'=>(string)$customer['name'],
 'phone'=>(string)$customer['phone'],
 'address'=>(string)$customer['address']
],array_filter($storedCustomers,fn($customer)=>(int)$customer['archived']===0)));
$customerOrderHistory=[];
foreach($orders as $customerOrder){
 $customerKey=strtolower(trim((string)$customerOrder['customer'])).'|'.trim((string)$customerOrder['phone']);
 $customerOrderHistory[$customerKey][]=$customerOrder;
}
$customerProductCounts=[];
$customerProducts=$db->query("SELECT o.customer,o.phone,i.name,SUM(i.quantity) qty FROM orders o JOIN items i ON i.order_id=o.id WHERE o.status<>'Cancelled' AND lower(trim(i.name))<>'pen' GROUP BY lower(trim(o.customer)),trim(o.phone),i.name ORDER BY qty DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach($customerProducts as $customerProduct){
 $key=strtolower(trim((string)$customerProduct['customer'])).'|'.trim((string)$customerProduct['phone']);
 $customerProductCounts[$key][]=['name'=>(string)$customerProduct['name'],'qty'=>(int)$customerProduct['qty']];
}

$newOrderCustomer=null;$repeatOrderData=null;$repeatQty=[];$repeatPrices=[];$repeatPresentation='';$repeatPaymentMethod='';$repeatDeliveryMethod='';$repeatTrackingReference='';$repeatDeliveryCharge=0;$repeatPostageCost=0;$repeatPaymentFee=0;
$editOrder=null;$editQty=[];$editPrices=[];

if($view==='new' && (int)($_GET['customer_id']??0)>0){
 $q=$db->prepare('SELECT * FROM customers WHERE id=? AND archived=0');$q->execute([(int)$_GET['customer_id']]);$newOrderCustomer=$q->fetch(PDO::FETCH_ASSOC)?:null;
}
if($view==='new' && (int)($_GET['repeat_order']??0)>0){
 $repeatId=(int)$_GET['repeat_order'];$q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$repeatId]);$repeatOrder=$q->fetch(PDO::FETCH_ASSOC);
 if($repeatOrder){
  $repeatOrderData=$repeatOrder;$newOrderCustomer=['name'=>$repeatOrder['customer'],'phone'=>$repeatOrder['phone'],'address'=>$repeatOrder['address']];
  $repeatPresentation=(string)($repeatOrder['presentation']??'');$repeatPaymentMethod=(string)($repeatOrder['payment_method']??'');$repeatDeliveryMethod=(string)($repeatOrder['delivery_method']??'');$repeatTrackingReference=(string)($repeatOrder['tracking_reference']??'');$repeatDeliveryCharge=(int)($repeatOrder['delivery_charge']??0);$repeatPostageCost=(int)($repeatOrder['postage_cost']??0);$repeatPaymentFee=(int)($repeatOrder['payment_fee']??0);
  $nameToProduct=[];foreach($products as $rp)if((int)$rp['active']===1)$nameToProduct[strtolower(trim((string)$rp['name']))]=(int)$rp['id'];
  $q=$db->prepare('SELECT name,price,quantity FROM items WHERE order_id=? ORDER BY id');$q->execute([$repeatId]);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $ri){
   if(strtolower(trim((string)$ri['name']))==='pen')continue;
   $pid=$nameToProduct[strtolower(trim((string)$ri['name']))]??0;if(!$pid)continue;
   $repeatQty[$pid]=(int)$ri['quantity'];$repeatPrices[$pid]=number_format((int)$ri['price']/100,2,'.','');
  }
 }
}
if($view==='edit' && (int)($_GET['id']??0)>0){
 $editId=(int)$_GET['id'];$q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$editId]);$editOrder=$q->fetch(PDO::FETCH_ASSOC)?:null;
 if($editOrder){
  $nameToProduct=[];foreach($products as $ep)$nameToProduct[strtolower(trim((string)$ep['name']))]=(int)$ep['id'];
  $q=$db->prepare('SELECT name,price,quantity FROM items WHERE order_id=? ORDER BY id');$q->execute([$editId]);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $ei){if(strtolower(trim((string)$ei['name']))==='pen')continue;$pid=$nameToProduct[strtolower(trim((string)$ei['name']))]??0;if(!$pid)continue;$editQty[$pid]=(int)$ei['quantity'];$editPrices[$pid]=number_format((int)$ei['price']/100,2,'.','');}
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

// Dashboard figures use paid/packed/dispatched/delivered orders as completed sales.
$tz=new DateTimeZone('Europe/London');$now=new DateTimeImmutable('now',$tz);
$todayStart=$now->setTime(0,0)->getTimestamp();$weekStart=$now->modify('monday this week')->setTime(0,0)->getTimestamp();$monthStart=$now->modify('first day of this month')->setTime(0,0)->getTimestamp();
$todaySales=0;$weekSales=0;$unpaidBalance=0;
foreach($orders as $dashboardOrder){
 $salesTs=strtotime((string)($dashboardOrder['payment_date']?:$dashboardOrder['created']))?:0;
 if(in_array($dashboardOrder['status'],$paidStatuses,true)){
  if($salesTs>=$todayStart)$todaySales+=(int)$dashboardOrder['total'];
  if($salesTs>=$weekStart)$weekSales+=(int)$dashboardOrder['total'];
 }
 if(in_array($dashboardOrder['status'],['New','Awaiting payment'],true))$unpaidBalance+=(int)$dashboardOrder['total'];
}
$awaitingPayment=array_values(array_filter($orders,fn($o)=>in_array($o['status'],['New','Awaiting payment'],true)));
$awaitingDelivery=array_values(array_filter($orders,fn($o)=>in_array($o['status'],['Paid','Packed','Dispatched'],true)));
usort($awaitingPayment,fn($a,$b)=>strcmp((string)$a['created'],(string)$b['created']));
usort($awaitingDelivery,fn($a,$b)=>strcmp((string)$a['created'],(string)$b['created']));
$todoOrderIds=array_map('intval',array_merge(array_column($awaitingPayment,'id'),array_column($awaitingDelivery,'id')));
$todoItems=[];
if($todoOrderIds){
 $placeholders=implode(',',array_fill(0,count($todoOrderIds),'?'));
 $q=$db->prepare("SELECT order_id,name,quantity,price FROM items WHERE order_id IN ($placeholders) ORDER BY id");
 $q->execute($todoOrderIds);
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $todoItem)$todoItems[(int)$todoItem['order_id']][]=$todoItem;
}
$grossProfit=0;$uncostedSales=0;$topSelling=[];$orderCostById=[];$orderPenCostById=[];$productProfit=[];$penCostAll=0;
$dashboardItems=$db->query("SELECT i.order_id,i.name,i.price,i.cost,i.quantity,o.status FROM items i JOIN orders o ON o.id=i.order_id WHERE o.status IN ('Paid','Packed','Dispatched','Delivered')")->fetchAll(PDO::FETCH_ASSOC);
foreach($dashboardItems as $dashboardItem){
 $orderId=(int)$dashboardItem['order_id'];$qty=(int)$dashboardItem['quantity'];$line=(int)$dashboardItem['price']*$qty;$cost=$dashboardItem['cost']===null?null:(int)$dashboardItem['cost'];$name=(string)$dashboardItem['name'];
 if($cost!==null){$orderCostById[$orderId]=($orderCostById[$orderId]??0)+$cost*$qty;}else{$uncostedSales+=$line;}
 if(strtolower(trim($name))==='pen'){if($cost!==null){$penLineCost=$cost*$qty;$penCostAll+=$penLineCost;$orderPenCostById[$orderId]=($orderPenCostById[$orderId]??0)+$penLineCost;}continue;}
 $topSelling[$name]??=['qty'=>0,'revenue'=>0];$topSelling[$name]['qty']+=$qty;$topSelling[$name]['revenue']+=$line;
 $productProfit[$name]??=['units'=>0,'revenue'=>0,'cogs'=>0,'missing_cost'=>false];
 $productProfit[$name]['units']+=$qty;$productProfit[$name]['revenue']+=$line;
 if($cost!==null)$productProfit[$name]['cogs']+=$cost*$qty;else$productProfit[$name]['missing_cost']=true;
}
uasort($topSelling,fn($a,$b)=>$b['qty']<=>$a['qty'] ?: $b['revenue']<=>$a['revenue']);$topSelling=array_slice($topSelling,0,5,true);
uasort($productProfit,fn($a,$b)=>$b['revenue']<=>$a['revenue']);

$reportPeriods=[
 'today'=>['label'=>'Today','start'=>$todayStart,'revenue'=>0,'cogs'=>0,'pen'=>0,'postage'=>0,'fees'=>0,'profit'=>0,'orders'=>0],
 'week'=>['label'=>'This week','start'=>$weekStart,'revenue'=>0,'cogs'=>0,'pen'=>0,'postage'=>0,'fees'=>0,'profit'=>0,'orders'=>0],
 'month'=>['label'=>'This month','start'=>$monthStart,'revenue'=>0,'cogs'=>0,'pen'=>0,'postage'=>0,'fees'=>0,'profit'=>0,'orders'=>0],
 'all'=>['label'=>'All time','start'=>0,'revenue'=>0,'cogs'=>0,'pen'=>0,'postage'=>0,'fees'=>0,'profit'=>0,'orders'=>0]
];
$paymentBreakdown=[];
foreach($orders as $reportOrder){
 if(!in_array($reportOrder['status'],$paidStatuses,true))continue;
 $orderId=(int)$reportOrder['id'];$dateText=(string)($reportOrder['payment_date']?:$reportOrder['created']);$reportTs=strtotime($dateText)?:0;
 $revenue=(int)$reportOrder['total'];$cogs=(int)($orderCostById[$orderId]??0);$penCogs=(int)($orderPenCostById[$orderId]??0);$postage=(int)($reportOrder['postage_cost']??0);$fee=(int)($reportOrder['payment_fee']??0);$profit=$revenue-$cogs-$postage-$fee;
 foreach($reportPeriods as $key=>&$period){if($reportTs>=$period['start']){$period['revenue']+=$revenue;$period['cogs']+=$cogs;$period['pen']+=$penCogs;$period['postage']+=$postage;$period['fees']+=$fee;$period['profit']+=$profit;$period['orders']++;}}unset($period);
 $method=trim((string)($reportOrder['payment_method']??''))?:'Not recorded';$paymentBreakdown[$method]=($paymentBreakdown[$method]??0)+$revenue;
}
$grossProfit=$reportPeriods['all']['profit'];
$trackedStock=array_values(array_filter($products,fn($p)=>(int)$p['active']===1 && $p['stock_qty']!==null));
$lowStock=array_values(array_filter($trackedStock,fn($p)=>(int)$p['stock_qty']<=(int)$p['low_stock_at']));
$penUnitCost=setting($db,'pen_cost_pence','');
$referrers=$db->query("SELECT DISTINCT referrer FROM orders WHERE referrer<>'' ORDER BY referrer COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN);
$sheetWebhook=setting($db,'sheets_webhook');$sheetId=setting($db,'sheets_sheet_id');$sheetSecret=setting($db,'sheets_secret');$sheetLastSync=setting($db,'sheets_last_sync');$sheetLastError=setting($db,'sheets_last_error');
?>
<aside><a class="brand" href="?view=dashboard"><span>☥</span> ANKH<small>ORDER DESK</small></a>
<nav class="desktop-nav">
<a class="<?=$view==='dashboard'?'selected':''?>" href="?view=dashboard"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 13h6V4H4zM14 20h6v-9h-6zM4 20h6v-3H4zM14 7h6V4h-6z"/></svg></span><span class="nav-label">Dashboard</span></a>
<a class="<?=$view==='orders'?'selected':''?>" href="?view=orders"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5.5h16v13H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg></span><span class="nav-label">Orders</span></a>
<a class="<?=$view==='new'?'selected':''?>" href="?view=new"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span><span class="nav-label">New</span></a>
<a class="<?=$view==='customers'?'selected':''?>" href="?view=customers"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.5 18c.8-3 2.7-4.5 5.5-4.5S13.7 15 14.5 18"/><circle cx="17" cy="9" r="2"/><path d="M15.5 14c2.7.2 4.3 1.5 5 4"/></svg></span><span class="nav-label">Customers</span></a>
<a class="<?=$view==='products'?'selected':''?>" href="?view=products"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 4h12v16H6z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg></span><span class="nav-label">Products</span></a>
<a class="<?=$view==='reports'?'selected':''?>" href="?view=reports"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 19V9M12 19V5M19 19v-7"/><path d="M3 19h18"/></svg></span><span class="nav-label">Reports</span></a>
<a class="<?=$view==='sheets'?'selected':''?>" href="?view=sheets"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4h14v16H5z"/><path d="M5 9h14M10 9v11M15 9v11M5 14h14"/></svg></span><span class="nav-label">Sheets</span></a>
</nav>
<nav class="mobile-nav">
<a class="<?=$view==='dashboard'?'selected':''?>" href="?view=dashboard"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 13h6V4H4zM14 20h6v-9h-6zM4 20h6v-3H4zM14 7h6V4h-6z"/></svg></span><span class="nav-label">Dashboard</span></a>
<a class="<?=$view==='orders'?'selected':''?>" href="?view=orders"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5.5h16v13H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg></span><span class="nav-label">Orders</span></a>
<a class="nav-new <?=$view==='new'?'selected':''?>" href="?view=new"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span><span class="nav-label">New</span></a>
<a class="<?=$view==='customers'?'selected':''?>" href="?view=customers"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.5 18c.8-3 2.7-4.5 5.5-4.5S13.7 15 14.5 18"/><circle cx="17" cy="9" r="2"/><path d="M15.5 14c2.7.2 4.3 1.5 5 4"/></svg></span><span class="nav-label">Customers</span></a>
<a class="<?=in_array($view,['more','products','reports','sheets'],true)?'selected':''?>" href="?view=more"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg></span><span class="nav-label">More</span></a>
</nav><?php if(!$testingNoAuth):?><form method="post"><?php csrf();?><input type="hidden" name="action" value="logout"><button class="quiet">Sign out</button></form><?php endif;?></aside>
<main><header><p class="eyebrow">ANKH PEPTIDES / ADMIN</p><span class="muted"><?=date('d M Y')?></span></header><?php if($testingNoAuth):?><p class="error" style="background:#3b301b;border-color:#79622d;color:#f5d991">TEST MODE · Password temporarily disabled</p><?php endif;?>
<?php if($error):?><p role="alert" class="error"><?=e($error)?></p><?php endif;?>
<?php if(!empty($_SESSION['flash'])):?><p class="success" role="status"><?=e($_SESSION['flash'])?></p><?php unset($_SESSION['flash']);endif;?>
<?php if($view==='dashboard'): ?>
<div class="heading dashboard-heading"><div><h1>Dashboard</h1><p class="muted page-description">What needs attention and how the business is doing.</p></div><a class="button page-action" href="?view=new">+ New order</a></div>
<div class="dashboard-stats">
<article><span>Today's sales</span><strong><?=money($todaySales)?></strong><small>Completed sales</small></article>
<article><span>This week's sales</span><strong><?=money($weekSales)?></strong><small>Since Monday</small></article>
<article><span>Gross profit</span><strong><?=money($grossProfit)?></strong><small>After costs &amp; fees</small></article>
<article><span>Unpaid</span><strong><?=money($unpaidBalance)?></strong><small><?=count($awaitingPayment)?> orders</small></article>
<article><span>To deliver</span><strong><?=count($awaitingDelivery)?></strong><small>Paid / packed / dispatched</small></article>
<article><span>Low stock</span><strong><?=count($lowStock)?></strong><small><?=count($trackedStock)?> tracked</small></article>
</div>

<?php if($topSelling):?><div class="dashboard-primary-panel"><section class="panel dashboard-panel"><div class="dashboard-panel-head"><div><p class="eyebrow">TOP SELLERS</p><h2>Best-selling products</h2></div></div>
<?php $rank=0;foreach($topSelling as $productName=>$seller):$rank++;?><div class="dashboard-row"><span><b><?=$rank?></b><?=e($productName)?></span><strong><?=$seller['qty']?> sold</strong></div><?php endforeach;?>
</section></div><?php endif;?>

<?php if($awaitingPayment||$awaitingDelivery):?><section class="todo-board">
<div class="todo-board-head"><div><p class="eyebrow">TO-DO</p><h2>Orders needing action</h2></div><div class="todo-counts"><?php if($awaitingPayment):?><span><?=count($awaitingPayment)?> payment</span><?php endif;?><?php if($awaitingDelivery):?><span><?=count($awaitingDelivery)?> delivery</span><?php endif;?></div></div>
<div class="todo-columns<?=(!$awaitingPayment||!$awaitingDelivery)?' single':''?>">

<?php if($awaitingPayment):?><div class="todo-column">
<div class="todo-column-title"><div><span class="todo-icon">£</span><div><h3>Awaiting payment</h3><small><?=money($unpaidBalance)?> outstanding</small></div></div><strong><?=count($awaitingPayment)?></strong></div>
<?php foreach($awaitingPayment as $todo):$items=$todoItems[(int)$todo['id']]??[];?>
<details class="todo-card todo-accordion">
<summary class="todo-accordion-summary">
<div class="todo-accordion-main"><h3><?=e($todo['customer'])?></h3><div class="todo-accordion-products"><?php foreach($items as $item):?><span><?=e($item['quantity'].' × '.$item['name'])?></span><?php endforeach;?></div></div>
<div class="todo-accordion-side"><strong><?=money($todo['total'])?></strong><span class="todo-chevron" aria-hidden="true">⌄</span></div>
</summary>
<div class="todo-accordion-body">
<div class="todo-detail-strip"><span>ANK-<?=str_pad((string)$todo['id'],4,'0',STR_PAD_LEFT)?></span><span><?=e(date('d M Y',strtotime($todo['created'])))?></span></div>
<form method="post" class="todo-action"><?php csrf();?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$todo['id']?>"><input type="hidden" name="status" value="Paid"><input type="hidden" name="return" value="dashboard"><label class="todo-date">Payment method<select name="payment_method" required><option value="">Choose method</option><?php foreach($paymentMethods as $method):?><option value="<?=e($method)?>" <?=$todo['payment_method']===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label><label class="todo-date">Payment date<input type="date" name="payment_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($todo['payment_date']?:$now->format('Y-m-d'))?>" required></label><label class="todo-date">Payment / card fee (£) <span class="muted">(optional)</span><input type="number" name="payment_fee" min="0" max="100000" step=".01" inputmode="decimal" value="<?=e(number_format((int)$todo['payment_fee']/100,2,'.',''))?>"></label><button>✓ Payment received</button></form>
</div>
</details>
<?php endforeach;?></div><?php endif;?>

<?php if($awaitingDelivery):?><div class="todo-column">
<div class="todo-column-title"><div><span class="todo-icon">✓</span><div><h3>Awaiting delivery</h3><small>Paid orders to complete</small></div></div><strong><?=count($awaitingDelivery)?></strong></div>
<?php foreach($awaitingDelivery as $todo):$items=$todoItems[(int)$todo['id']]??[];?>
<details class="todo-card todo-accordion">
<summary class="todo-accordion-summary">
<div class="todo-accordion-main"><h3><?=e($todo['customer'])?></h3><div class="todo-accordion-products"><?php foreach($items as $item):?><span><?=e($item['quantity'].' × '.$item['name'])?></span><?php endforeach;?></div></div>
<div class="todo-accordion-side"><span class="badge status-<?=e(statusClass($todo['status']))?>"><?=e($todo['status'])?></span><span class="todo-chevron" aria-hidden="true">⌄</span></div>
</summary>
<div class="todo-accordion-body">
<div class="todo-detail-strip"><span>ANK-<?=str_pad((string)$todo['id'],4,'0',STR_PAD_LEFT)?></span><span><?=e(date('d M Y',strtotime($todo['created'])))?></span><?php if(!empty($todo['presentation'])):?><span><?=e($todo['presentation'])?></span><?php endif;?><?php if(!empty($todo['delivery_method'])):?><span><?=e($todo['delivery_method'])?></span><?php endif;?></div>
<?php if(trim((string)$todo['address'])!==''):?><p class="todo-address"><?=nl2br(e($todo['address']))?></p><?php endif;?><?php if(!empty($todo['tracking_reference'])):?><p class="todo-tracking">Tracking: <?=e($todo['tracking_reference'])?></p><?php endif;?>
<form method="post" class="todo-action"><?php csrf();?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$todo['id']?>"><input type="hidden" name="status" value="Delivered"><input type="hidden" name="return" value="dashboard"><label class="todo-date">Delivery date<input type="date" name="delivery_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($todo['delivery_date']?:$now->format('Y-m-d'))?>" required></label><button>✓ Delivered</button></form>
</div>
</details>
<?php endforeach;?></div><?php endif;?>

</div></section><?php endif;?>

<?php if($lowStock):?><div class="dashboard-primary-panel"><section class="panel dashboard-panel"><div class="dashboard-panel-head"><div><p class="eyebrow">STOCK</p><h2>Low-stock products</h2></div><a href="?view=products">Manage →</a></div>
<?php foreach(array_slice($lowStock,0,6) as $stockProduct):?><div class="dashboard-row"><span><?=e($stockProduct['name'])?></span><strong><?=$stockProduct['stock_qty']?> left</strong></div><?php endforeach;?>
</section></div><?php endif;?>
<?php if($uncostedSales>0):?><p class="muted dashboard-note">Gross profit excludes costs that have not been mapped yet.</p><?php endif;?>
<?php elseif($view==='orders'): ?>
<div class="heading"><div><h1>Orders</h1><p class="muted page-description">Search, update or repeat any order.</p></div><a class="button page-action" href="?view=new">+ New order</a></div>
<div class="stats compact-stats"><article><span>Open</span><strong><?=$open?></strong></article><article><span>Paid value</span><strong><?=money($paid)?></strong></article><article><span>Total</span><strong><?=count($orders)?></strong></article></div>
<div class="filters"><label>Search orders<input id="search" placeholder="Name, phone or order number"></label><label>Status<select id="filter"><option value="">All statuses</option><?php foreach($statuses as $statusOption):?><option><?=e($statusOption)?></option><?php endforeach;?></select></label></div>
<div class="order-list">
<?php foreach($orders as $o):?><details class="order compact-order" data-search="<?=e(strtolower($o['customer'].' '.$o['phone'].' '.($o['referrer']??'').' ANK-'.$o['id']))?>" data-status="<?=e($o['status'])?>"><summary><div><span class="ref">ANK-<?=str_pad((string)$o['id'],4,'0',STR_PAD_LEFT)?></span><h2><?=e($o['customer'])?></h2><span class="muted"><?=e(date('d M Y',strtotime($o['created'])))?></span></div><div class="order-right"><span class="badge status-<?=e(statusClass($o['status']))?>"><?=e($o['status'])?></span><strong><?=money($o['total'])?></strong></div></summary><div class="detail">
<div class="order-quick-actions"><a class="quick-action edit-action" href="?view=edit&amp;id=<?=$o['id']?>">Edit order</a><?php if(trim((string)$o['phone'])!==''):?><a class="quick-action" href="tel:<?=e(preg_replace('/[^0-9+]/','',(string)$o['phone']))?>">Call</a><?php endif;?><?php if(trim((string)$o['address'])!==''):?><button type="button" class="quick-action quiet" data-copy-text="<?=e($o['address'])?>">Copy address</button><?php endif;?><a class="quick-action" href="?view=new&amp;repeat_order=<?=$o['id']?>">Repeat</a></div>
<div class="order-meta"><span><b>Order</b><em><?=e(date('d M Y',strtotime($o['created'])))?></em></span><?php if($o['payment_date']):?><span><b>Paid</b><em><?=e(date('d M Y',strtotime($o['payment_date'])))?></em></span><?php endif;?><?php if($o['delivery_date']):?><span><b>Delivered</b><em><?=e(date('d M Y',strtotime($o['delivery_date'])))?></em></span><?php endif;?><?php if($o['presentation']):?><span><b>Type</b><em><?=e($o['presentation'])?></em></span><?php endif;?><?php if($o['payment_method']):?><span><b>Payment</b><em><?=e($o['payment_method'])?></em></span><?php endif;?><?php if($o['delivery_method']):?><span><b>Delivery</b><em><?=e($o['delivery_method'])?></em></span><?php endif;?></div>
<?php if($o['address']):?><p class="address"><?=nl2br(e($o['address']))?></p><?php endif;?><?php if($o['tracking_reference']):?><p class="note"><strong>Tracking:</strong> <?=e($o['tracking_reference'])?></p><?php endif;?>
<?php $q=$db->prepare('SELECT * FROM items WHERE order_id=?');$q->execute([$o['id']]);foreach($q as $i):?><div class="line"><span><?=e($i['quantity'].' × '.$i['name'].' @ '.money($i['price']))?></span><strong><?=money($i['price']*$i['quantity'])?></strong></div><?php endforeach;?>
<?php if((int)$o['delivery_charge']>0):?><div class="line"><span>Postage / delivery charge</span><strong><?=money($o['delivery_charge'])?></strong></div><?php endif;?><?php if($o['notes']):?><p class="note"><?=nl2br(e($o['notes']))?></p><?php endif;?>
<details class="order-date-editor"><summary>Edit payment / delivery dates</summary><form method="post" class="order-dates-form"><?php csrf();?><input type="hidden" name="action" value="order_dates"><input type="hidden" name="return" value="orders"><input type="hidden" name="id" value="<?=$o['id']?>"><div class="two"><label>Payment date<input type="date" name="payment_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($o['payment_date']??'')?>"></label><label>Delivery date<input type="date" name="delivery_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($o['delivery_date']??'')?>"></label></div><button>Save dates</button></form></details>
<form method="post" class="status-form compact-status-form"><?php csrf();?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$o['id']?>"><div class="status-fields"><label>Status<select name="status"><?php foreach($statuses as $statusOption):?><option <?=$statusOption===$o['status']?'selected':''?>><?=e($statusOption)?></option><?php endforeach;?></select></label><label>Payment method<select name="payment_method"><option value="">Not set</option><?php foreach($paymentMethods as $method):?><option value="<?=e($method)?>" <?=$o['payment_method']===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label></div><button>Save</button></form>
<form method="post" class="delete-order-form" onsubmit="return confirm('Delete ANK-<?=str_pad((string)$o['id'],4,'0',STR_PAD_LEFT)?>? This permanently removes the order and its items.');"><?php csrf();?><input type="hidden" name="action" value="order_delete"><input type="hidden" name="id" value="<?=$o['id']?>"><input type="hidden" name="return" value="orders"><button class="quiet danger-button">Delete order</button></form></div></details><?php endforeach;?></div>
<p id="empty" class="empty" <?=count($orders)?'hidden':''?>>No orders to show.</p>
<?php elseif($view==='new'):?>
<div class="new-order-head"><div><h1><?=$repeatOrderData?'Repeat order':'New order'?></h1><?php if($repeatOrderData):?><p class="muted page-description">Based on ANK-<?=str_pad((string)$repeatOrderData['id'],4,'0',STR_PAD_LEFT)?>. Check anything that has changed.</p><?php endif;?></div><button type="button" id="clear-draft" class="quiet draft-clear" hidden>Clear draft</button><div class="wizard-progress" aria-label="Order progress"><span class="active" data-progress-step="1">1<span>Customer</span></span><i></i><span data-progress-step="2">2<span>Type</span></span><i></i><span data-progress-step="3">3<span>Products</span></span><i></i><span data-progress-step="4">4<span>Save</span></span></div></div>
<form method="post" class="panel order-wizard" id="order-wizard" data-draft-enabled="<?=(!$newOrderCustomer&&!$repeatOrderData)?'1':'0'?>"><?php csrf();?><input type="hidden" name="action" value="order">

<section class="wizard-step" data-wizard-step="1">
<div class="wizard-step-head"><span class="wizard-kicker">STEP 1 OF 4</span><h2>Customer</h2><p class="muted">Choose an existing customer or enter a new one.</p></div>
<?php if($recentCustomers && !$newOrderCustomer):?><div class="recent-customers"><span class="order-check-label">Recent customers</span><div class="recent-customer-chips"><?php foreach($recentCustomers as $recent):?><button type="button" class="recent-customer" data-recent-customer="<?=$recent['id']?>"><?=e($recent['name'])?></button><?php endforeach;?></div></div><?php endif;?>
<div class="two"><div class="customer-search-wrap"><label>Customer name<input id="customer-search" name="customer" maxlength="160" required autocomplete="off" placeholder="Start typing name or phone…" value="<?=e($_POST['customer']??($newOrderCustomer['name']??''))?>"></label><div id="customer-results" class="customer-results" role="listbox" hidden></div></div><label>Phone<input id="customer-phone" name="phone" maxlength="40" type="tel" autocomplete="tel" value="<?=e($_POST['phone']??($newOrderCustomer['phone']??''))?>"></label></div>
<label>Referrer <span class="muted">(optional)</span><input id="customer-referrer" name="referrer" maxlength="160" list="referrer-list" placeholder="Who sent them to us?" value="<?=e($_POST['referrer']??'')?>"></label><datalist id="referrer-list"><?php foreach($referrers as $r):?><option value="<?=e($r)?>"><?php endforeach;?></datalist>
<label>Delivery address<textarea id="customer-address" name="address" maxlength="2000" autocomplete="street-address"><?=e($_POST['address']??($newOrderCustomer['address']??''))?></textarea></label>
<label class="order-date-field">Order date <span class="muted">(defaults to today)</span><input id="order-date" name="order_date" type="date" required max="<?=e($now->format('Y-m-d'))?>" value="<?=e($_POST['order_date']??$now->format('Y-m-d'))?>"></label>
<div class="order-subsection"><span class="order-check-label">Delivery</span>
<label>Delivery method<select id="delivery-method" name="delivery_method" required><?php $selectedDelivery=$_POST['delivery_method']??($repeatDeliveryMethod?:'Local Delivery');foreach($deliveryMethods as $method):?><option value="<?=e($method)?>" <?=$selectedDelivery===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label>
<div id="postage-fields" class="postage-fields" hidden><label>Tracking / reference <span class="muted">(optional)</span><input id="tracking-reference" name="tracking_reference" maxlength="200" value="<?=e($_POST['tracking_reference']??$repeatTrackingReference)?>" placeholder="Royal Mail / courier reference"></label><div class="two"><label>Postage charged (£)<input id="delivery-charge" name="delivery_charge" type="number" min="0" max="100000" step=".01" inputmode="decimal" value="<?=e($_POST['delivery_charge']??number_format($repeatDeliveryCharge/100,2,'.',''))?>"></label><label>Actual postage cost (£)<input id="postage-cost" name="postage_cost" type="number" min="0" max="100000" step=".01" inputmode="decimal" value="<?=e($_POST['postage_cost']??number_format($repeatPostageCost/100,2,'.',''))?>"></label></div></div>
</div>
<div class="wizard-actions wizard-actions-next"><button type="button" data-wizard-next="2">Next · Choose type →</button></div>
</section>

<section class="wizard-step" data-wizard-step="2" hidden>
<div class="wizard-step-head"><span class="wizard-kicker">STEP 2 OF 4</span><h2>Product type</h2><p class="muted">Choose how this order will be supplied.</p></div>
<input type="hidden" id="presentation" name="presentation" value="<?=e($_POST['presentation']??$repeatPresentation)?>">
<div class="presentation-picker modern-type-picker" aria-label="Choose product type">
<button type="button" data-presentation="Pen"><span class="type-check" aria-hidden="true">✓</span><strong>Pen</strong></button>
<button type="button" data-presentation="Cartridge"><span class="type-check" aria-hidden="true">✓</span><strong>Cartridge</strong></button>
<button type="button" data-presentation="Vial"><span class="type-check" aria-hidden="true">✓</span><strong>Vial</strong></button>
</div>

<div class="wizard-actions"><button type="button" class="quiet wizard-back" data-wizard-back="1">← Back</button><button type="button" data-wizard-next="3">Next · Choose peptide →</button></div>
</section>

<section class="wizard-step" data-wizard-step="3" hidden>
<div class="wizard-step-head"><span class="wizard-kicker">STEP 3 OF 4</span><h2>Products</h2><p class="muted">Find the peptide and choose the strength.</p></div>
<div id="product-choice-area">
<div class="product-search-wrap"><label for="product-search">Find a peptide or product<input id="product-search" type="search" placeholder="Tap for Retatrutide or start typing…" autocomplete="off" aria-autocomplete="list" aria-controls="product-results"></label><div id="product-results" class="product-results" role="listbox" hidden></div></div>
<div id="strength-picker" class="strength-picker" hidden><div class="strength-picker-head"><div><span class="muted">Choose strength</span><strong id="strength-product-name"></strong></div><button type="button" id="close-strength-picker" class="strength-close" aria-label="Close strength choices">×</button></div><div id="strength-options" class="strength-options"></div></div>
<div id="selected-products">
<?php foreach($products as $p):if(!$p['active'])continue;$postedQty=(int)($_POST['qty'][$p['id']]??($repeatQty[$p['id']]??0));$postedPrice=$_POST['price'][$p['id']]??($repeatPrices[$p['id']]??number_format((int)$p['price']/100,2,'.',''));$baseName=$p['name'];$strength='';if(preg_match('/\s+(\d+(?:\.\d+)?\s*(?:mg|ml|iu))$/i',$p['name'],$pm)){$strength=$pm[1];$baseName=trim(substr($p['name'],0,-strlen($pm[0])));} ?><div class="product-pick<?=$postedQty>0?' picked':''?>" data-product-id="<?=$p['id']?>" data-product-name="<?=e(strtolower($p['name']))?>" data-product-label="<?=e($p['name'])?>" data-product-base="<?=e($baseName)?>" data-product-strength="<?=e($strength)?>" data-standard-price="<?=e(number_format((int)$p['price']/100,2,'.',''))?>" <?=$postedQty>0?'':'hidden'?>><span><?=e($p['name'])?><small>Standard <?=money($p['price'])?></small></span><div class="stepper"><button type="button" data-change="-1" aria-label="Remove one <?=e($p['name'])?>">−</button><input inputmode="numeric" aria-label="<?=e($p['name'])?> quantity" class="quantity" name="qty[<?=$p['id']?>]" type="number" min="0" max="999" value="<?=$postedQty?>"><button type="button" data-change="1" aria-label="Add one <?=e($p['name'])?>">+</button></div><label class="order-price">Price each for this order (£)<input class="line-price" name="price[<?=$p['id']?>]" type="number" min="0" max="100000" step=".01" inputmode="decimal" value="<?=e($postedPrice)?>"></label><?php if($strength && preg_match('/mg$/i',$strength)):?><button type="button" class="family-discount" data-family-discount><span>Family &amp; Friends</span><strong>−£5</strong></button><?php endif;?></div><?php endforeach;?>
</div>
<p id="selected-empty" class="selected-empty">No products added yet.</p>

</div>
<div class="wizard-actions"><button type="button" class="quiet wizard-back" data-wizard-back="2">← Back</button><button type="button" data-wizard-next="4">Next · Check order →</button></div>
</section>

<section class="wizard-step" data-wizard-step="4" hidden>
<div class="wizard-step-head"><span class="wizard-kicker">STEP 4 OF 4</span><h2>Check & save</h2><p class="muted">Check the important details, then save.</p></div>
<div class="order-check">
<div class="order-check-section"><span class="order-check-label">Customer</span><strong id="check-customer">—</strong><small id="check-customer-detail"></small></div>
<div class="order-check-section"><span class="order-check-label">Order date</span><strong id="check-order-date">—</strong></div>
<div class="order-check-section"><span class="order-check-label">Type</span><strong id="check-presentation">—</strong><small id="check-presentation-detail"></small></div>
<div class="order-check-section"><span class="order-check-label">Delivery</span><strong id="check-delivery">—</strong><small id="check-delivery-detail"></small></div>
<div class="order-check-section"><span class="order-check-label">Products</span><div id="check-products"></div></div>
<div class="line order-check-total"><strong>Total</strong><strong id="subtotal">£0.00</strong></div>
</div>
<div class="order-subsection"><span class="order-check-label">Payment</span><div class="two"><label>Payment method <span class="muted">(optional until paid)</span><select id="payment-method" name="payment_method"><option value="">Not set yet</option><?php $selectedPayment=$_POST['payment_method']??$repeatPaymentMethod;foreach($paymentMethods as $method):?><option value="<?=e($method)?>" <?=$selectedPayment===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label><label>Payment / card fee (£) <span class="muted">(optional)</span><input id="payment-fee" name="payment_fee" type="number" min="0" max="100000" step=".01" inputmode="decimal" value="<?=e($_POST['payment_fee']??number_format($repeatPaymentFee/100,2,'.',''))?>"></label></div></div>
<label>Notes <span class="muted">(optional)</span><textarea name="notes" maxlength="4000" placeholder="Delivery instructions, payment reference…"><?=e($_POST['notes']??'')?></textarea></label>
<div class="wizard-actions"><button type="button" class="quiet wizard-back" data-wizard-back="3">← Back</button><button class="save-order">Save order</button></div>
</section>
</form>
<?php elseif($view==='edit'):?>
<?php if(!$editOrder):?><div class="heading"><div><h1>Edit order</h1></div><a class="quick-action" href="?view=orders">← Orders</a></div><p class="error">That order could not be found.</p>
<?php else:?>
<div class="heading"><div><h1>Edit ANK-<?=str_pad((string)$editOrder['id'],4,'0',STR_PAD_LEFT)?></h1><p class="muted page-description">Correct the customer, products, prices, payment or delivery details.</p></div><a class="quick-action" href="?view=orders">Cancel</a></div>
<form method="post" class="panel edit-order-form" id="edit-order-form"><?php csrf();?><input type="hidden" name="action" value="order_edit"><input type="hidden" name="return" value="orders"><input type="hidden" name="id" value="<?=$editOrder['id']?>">
<div class="edit-section"><span class="wizard-kicker">CUSTOMER & DATE</span><div class="two"><div class="customer-search-wrap"><label>Customer name<input id="customer-search" name="customer" maxlength="160" required autocomplete="off" value="<?=e($editOrder['customer'])?>"></label><div id="customer-results" class="customer-results" role="listbox" hidden></div></div><label>Phone<input id="customer-phone" name="phone" maxlength="40" type="tel" value="<?=e($editOrder['phone'])?>"></label></div><label>Referrer <span class="muted">(optional)</span><input id="customer-referrer" name="referrer" maxlength="160" value="<?=e($editOrder['referrer']??'')?>"></label><label>Address<textarea id="customer-address" name="address" maxlength="2000"><?=e($editOrder['address'])?></textarea></label><label>Order date<input id="order-date" name="order_date" type="date" required max="<?=e($now->format('Y-m-d'))?>" value="<?=e(date('Y-m-d',strtotime($editOrder['created'])))?>"></label></div>

<div class="edit-section"><span class="wizard-kicker">PRODUCT TYPE</span><input type="hidden" id="presentation" name="presentation" value="<?=e($editOrder['presentation']??'')?>"><div class="presentation-picker modern-type-picker" aria-label="Choose product type"><button type="button" data-presentation="Pen"><span class="type-check" aria-hidden="true">✓</span><strong>Pen</strong></button><button type="button" data-presentation="Cartridge"><span class="type-check" aria-hidden="true">✓</span><strong>Cartridge</strong></button><button type="button" data-presentation="Vial"><span class="type-check" aria-hidden="true">✓</span><strong>Vial</strong></button></div></div>

<div class="edit-section"><span class="wizard-kicker">PRODUCTS</span><div id="product-choice-area"><div class="product-search-wrap"><label for="product-search">Find a peptide or product<input id="product-search" type="search" placeholder="Start typing…" autocomplete="off" aria-autocomplete="list" aria-controls="product-results"></label><div id="product-results" class="product-results" role="listbox" hidden></div></div><div id="strength-picker" class="strength-picker" hidden><div class="strength-picker-head"><div><span class="muted">Choose strength</span><strong id="strength-product-name"></strong></div><button type="button" id="close-strength-picker" class="strength-close" aria-label="Close strength choices">×</button></div><div id="strength-options" class="strength-options"></div></div><div id="selected-products">
<?php foreach($products as $p):$editSelected=(int)($editQty[$p['id']]??0);if(!$p['active']&&!$editSelected)continue;$editPrice=$editPrices[$p['id']]??number_format((int)$p['price']/100,2,'.','');$baseName=$p['name'];$strength='';if(preg_match('/\s+(\d+(?:\.\d+)?\s*(?:mg|ml|iu))$/i',$p['name'],$pm)){$strength=$pm[1];$baseName=trim(substr($p['name'],0,-strlen($pm[0])));} ?><div class="product-pick<?=$editSelected>0?' picked':''?>" data-product-id="<?=$p['id']?>" data-product-name="<?=e(strtolower($p['name']))?>" data-product-label="<?=e($p['name'])?>" data-product-base="<?=e($baseName)?>" data-product-strength="<?=e($strength)?>" data-standard-price="<?=e(number_format((int)$p['price']/100,2,'.',''))?>" <?=$editSelected>0?'':'hidden'?>><span><?=e($p['name'])?><small>Standard <?=money($p['price'])?></small></span><div class="stepper"><button type="button" data-change="-1">−</button><input inputmode="numeric" class="quantity" name="qty[<?=$p['id']?>]" type="number" min="0" max="999" value="<?=$editSelected?>"><button type="button" data-change="1">+</button></div><label class="order-price">Price each (£)<input class="line-price" name="price[<?=$p['id']?>]" type="number" min="0" max="100000" step=".01" inputmode="decimal" value="<?=e($editPrice)?>"></label><?php if($strength && preg_match('/mg$/i',$strength)):?><button type="button" class="family-discount" data-family-discount><span>Family &amp; Friends</span><strong>−£5</strong></button><?php endif;?></div><?php endforeach;?>
</div><p id="selected-empty" class="selected-empty">No products added yet.</p></div></div>

<div class="edit-section"><span class="wizard-kicker">DELIVERY</span><label>Delivery method<select id="delivery-method" name="delivery_method" required><?php $editDeliveryMethod=$editOrder['delivery_method']?:'Local Delivery';foreach($deliveryMethods as $method):?><option value="<?=e($method)?>" <?=$editDeliveryMethod===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label><div id="postage-fields" class="postage-fields" hidden><label>Tracking / reference<input id="tracking-reference" name="tracking_reference" maxlength="200" value="<?=e($editOrder['tracking_reference']??'')?>"></label><div class="two"><label>Postage charged (£)<input id="delivery-charge" name="delivery_charge" type="number" min="0" step=".01" value="<?=e(number_format((int)$editOrder['delivery_charge']/100,2,'.',''))?>"></label><label>Actual postage cost (£)<input id="postage-cost" name="postage_cost" type="number" min="0" step=".01" value="<?=e(number_format((int)$editOrder['postage_cost']/100,2,'.',''))?>"></label></div></div></div>

<div class="edit-section"><span class="wizard-kicker">PAYMENT & NOTES</span><div class="two"><label>Payment method<select id="payment-method" name="payment_method"><option value="">Not set</option><?php foreach($paymentMethods as $method):?><option value="<?=e($method)?>" <?=$editOrder['payment_method']===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label><label>Payment / card fee (£)<input id="payment-fee" name="payment_fee" type="number" min="0" step=".01" value="<?=e(number_format((int)$editOrder['payment_fee']/100,2,'.',''))?>"></label></div><label>Notes<textarea name="notes" maxlength="4000"><?=e($editOrder['notes'])?></textarea></label></div>
<div class="edit-total"><span>Order total</span><strong id="subtotal">£0.00</strong></div><button class="save-order">Save changes</button>
</form>
<?php endif;?>

<?php elseif($view==='products'):?>
<div class="heading"><div><h1>Products</h1><p class="muted">Retail prices, supplier costs and optional stock tracking.</p></div></div>
<form method="post" class="panel"><?php csrf();?><input type="hidden" name="action" value="product"><input type="hidden" name="return" value="products"><h2>Add product</h2>
<div class="product-admin-grid"><label>Name and strength<input name="name" required maxlength="160" placeholder="Product name · 5mg"></label><label>Retail price (£)<input name="price" type="number" min="0" max="100000" step=".01" required></label><label>Cost (£) <span class="muted">(optional)</span><input name="cost" type="number" min="0" max="100000" step=".01"></label><label>Stock <span class="muted">(optional)</span><input name="stock_qty" type="number" min="0" max="999999" step="1" placeholder="Not tracked"></label><label>Low-stock alert<input name="low_stock_at" type="number" min="0" max="999999" step="1" value="2"></label></div>
<button>Add product</button></form>
<?php foreach($products as $p):$costMapped=isset($currentSupplierCosts[$p['name']]);?><details class="order product-admin-card"><summary><div><h2><?=e($p['name'])?></h2><span class="muted"><?=money($p['price'])?> retail<?php if($p['cost']!==null):?> · <?=money($p['cost'])?> cost<?php endif;?></span></div><div class="product-admin-summary"><span><?=$p['active']?'Active':'Hidden'?></span><small><?=$p['stock_qty']===null?'Stock not tracked':e($p['stock_qty']).' in stock'?></small></div></summary><form method="post" class="detail"><?php csrf();?><input type="hidden" name="action" value="product"><input type="hidden" name="return" value="products"><input type="hidden" name="id" value="<?=$p['id']?>">
<label>Name<input name="name" required maxlength="160" value="<?=e($p['name'])?>"></label>
<div class="product-admin-grid"><label>Retail price (£)<input name="price" type="number" min="0" max="100000" step=".01" required value="<?=e(number_format($p['price']/100,2,'.',''))?>"></label><label>Cost (£)<?php if($costMapped):?> <span class="muted">Supplier list</span><?php endif;?><input name="cost" type="number" min="0" max="100000" step=".01" value="<?=$p['cost']===null?'':e(number_format($p['cost']/100,2,'.',''))?>" <?=$costMapped?'readonly':''?>></label><label>Stock quantity<input name="stock_qty" type="number" min="0" max="999999" step="1" placeholder="Leave blank to stop tracking" value="<?=$p['stock_qty']===null?'':e($p['stock_qty'])?>"></label><label>Low-stock alert<input name="low_stock_at" type="number" min="0" max="999999" step="1" value="<?=e($p['low_stock_at'])?>"></label></div>
<label class="check"><input type="checkbox" name="active" <?=$p['active']?'checked':''?>> Available for new orders</label><button>Save product</button></form></details><?php endforeach;?>
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
<div class="heading customer-heading"><div><h1>Customers</h1><p class="muted page-description">Search, contact or start another order.</p></div><button type="button" id="show-add-customer" class="customer-plus" aria-label="Add customer" title="Add customer" aria-expanded="false" aria-controls="add-customer-panel">+</button></div>
<div class="customer-page-filters"><label>Search customers<input id="customer-page-search" type="search" placeholder="Name, phone, address or product"></label><label>Show<select id="customer-status-filter"><option value="active">Active</option><option value="archived">Archived</option><option value="all">All</option></select></label></div>
<form method="post" class="panel customer-form" id="add-customer-panel" hidden><?php csrf();?><input type="hidden" name="action" value="customer"><input type="hidden" name="return" value="customers"><div class="customer-form-title"><h2>Add customer</h2><button type="button" class="quiet customer-form-close" data-close-customer-form>Cancel</button></div><div class="two"><label>Name<input name="name" maxlength="160" required autocomplete="name" placeholder="Customer name"></label><label>Phone<input name="phone" maxlength="40" type="tel" autocomplete="tel" placeholder="Phone number"></label></div><label>Address<textarea name="address" maxlength="2000" autocomplete="street-address" placeholder="Delivery address"></textarea></label><button>Save customer</button></form>
<div id="customer-list">
<?php foreach($storedCustomers as $c):
 $customerKey=strtolower(trim((string)$c['name'])).'|'.trim((string)$c['phone']);$history=$customerOrderHistory[$customerKey]??[];
 $customerSpent=array_sum(array_map(fn($o)=>in_array($o['status'],$paidStatuses,true)?(int)$o['total']:0,$history));
 $lastOrder=$history[0]??null;$usualProducts=array_slice($customerProductCounts[$customerKey]??[],0,3);
 $searchBits=$c['name'].' '.$c['phone'].' '.$c['address'].' '.implode(' ',array_column($usualProducts,'name'));
?>
<details class="order customer-card<?=$c['archived']?' archived-customer':''?>" data-customer-search="<?=e(strtolower($searchBits))?>" data-customer-archived="<?=$c['archived']?'1':'0'?>">
<summary><div><div class="customer-name-line"><h2><?=e($c['name'])?></h2><?php if($c['archived']):?><span class="badge customer-archived-badge">Archived</span><?php endif;?></div><span class="muted"><?=e($c['phone']?:'No phone saved')?></span></div><div class="customer-card-summary"><strong><?=money($customerSpent)?></strong><small><?=count($history)?> <?=count($history)===1?'order':'orders'?><?php if($lastOrder):?> · <?=e(date('d M y',strtotime($lastOrder['created'])))?><?php endif;?></small></div></summary>
<div class="detail">
<div class="customer-metrics"><div><span>Spent</span><strong><?=money($customerSpent)?></strong></div><div><span>Orders</span><strong><?=count($history)?></strong></div><div><span>Last order</span><strong><?=$lastOrder?e(date('d M y',strtotime($lastOrder['created']))):'—'?></strong></div></div>
<?php if($c['address']):?><p class="address"><?=nl2br(e($c['address']))?></p><?php endif;?>
<div class="customer-quick-actions"><?php if(!$c['archived']):?><a class="quick-action" href="?view=new&amp;customer_id=<?=$c['id']?>">+ New order</a><?php if($lastOrder):?><a class="quick-action" href="?view=new&amp;repeat_order=<?=$lastOrder['id']?>">Repeat last</a><?php endif;?><?php endif;?><?php if(trim((string)$c['phone'])!==''):?><a class="quick-action" href="tel:<?=e(preg_replace('/[^0-9+]/','',(string)$c['phone']))?>">Call</a><?php endif;?><?php if(trim((string)$c['address'])!==''):?><button type="button" class="quick-action quiet" data-copy-text="<?=e($c['address'])?>">Copy address</button><?php endif;?></div>
<?php if($usualProducts):?><div class="usual-products"><span class="order-check-label">Usual products</span><div class="product-chips"><?php foreach($usualProducts as $usual):?><span><?=e($usual['name'])?> <b>×<?=$usual['qty']?></b></span><?php endforeach;?></div></div><?php endif;?>
<div class="customer-actions"><button type="button" class="quiet edit-customer-button" data-edit-customer="<?=$c['id']?>" aria-expanded="false">Edit customer</button></div>
<form method="post" class="customer-edit-form" data-customer-form="<?=$c['id']?>" hidden><?php csrf();?><input type="hidden" name="action" value="customer"><input type="hidden" name="return" value="customers"><input type="hidden" name="id" value="<?=$c['id']?>"><div class="two"><label>Name<input name="name" maxlength="160" required value="<?=e($c['name'])?>"></label><label>Phone<input name="phone" maxlength="40" type="tel" value="<?=e($c['phone'])?>"></label></div><label>Address<textarea name="address" maxlength="2000"><?=e($c['address'])?></textarea></label><div class="customer-edit-actions"><button>Save changes</button><button type="button" class="quiet" data-cancel-customer-edit="<?=$c['id']?>">Cancel</button></div></form>
<form method="post" class="customer-archive-form" onsubmit="return confirm('<?=$c['archived']?'Restore this customer?':'Archive this customer? Their order history will be kept.'?>')"><?php csrf();?><input type="hidden" name="action" value="customer_archive"><input type="hidden" name="return" value="customers"><input type="hidden" name="id" value="<?=$c['id']?>"><input type="hidden" name="archive" value="<?=$c['archived']?'0':'1'?>"><button class="quiet"><?=$c['archived']?'Restore customer':'Archive customer'?></button></form>
<?php if($history):?><div class="customer-history"><span class="order-check-label">Order history</span><?php foreach($history as $o):?><div class="line customer-history-line"><span><b>ANK-<?=$o['id']?></b> · <?=e(date('d M y',strtotime($o['created'])))?> · <?=e($o['status'])?></span><strong><?=money($o['total'])?></strong></div><?php endforeach;?></div><?php endif;?>
</div></details>
<?php endforeach;?>
</div>
<p id="customer-page-empty" class="empty" hidden>No customers match that search.</p>
<?php if(!$storedCustomers):?><p class="empty">No customers yet. Tap + to add your first one.</p><?php endif;?>

<?php elseif($view==='reports'):?>
<div class="heading"><div><h1>Profit & reports</h1><p class="muted page-description">Paid sales less product costs, pen costs, postage and payment fees.</p></div><a class="quick-action" href="?view=more">← More</a></div>
<div class="report-periods">
<?php foreach(['today','week','month','all'] as $periodKey):$period=$reportPeriods[$periodKey];?><article class="report-period"><span><?=e($period['label'])?></span><strong><?=money($period['profit'])?></strong><small><?=money($period['revenue'])?> sales · <?=$period['orders']?> orders</small></article><?php endforeach;?>
</div>
<section class="panel report-breakdown"><div class="dashboard-panel-head"><div><p class="eyebrow">ALL TIME</p><h2>Profit breakdown</h2></div></div>
<div class="report-lines">
<div><span>Sales revenue</span><strong><?=money($reportPeriods['all']['revenue'])?></strong></div>
<div><span>Product cost</span><strong>− <?=money(max(0,$reportPeriods['all']['cogs']-$reportPeriods['all']['pen']))?></strong></div>
<div><span>Pen cost</span><strong>− <?=money($reportPeriods['all']['pen'])?></strong></div>
<div><span>Postage cost</span><strong>− <?=money($reportPeriods['all']['postage'])?></strong></div>
<div><span>Payment / card fees</span><strong>− <?=money($reportPeriods['all']['fees'])?></strong></div>
<div class="report-profit"><span>Gross profit</span><strong><?=money($reportPeriods['all']['profit'])?></strong></div>
</div>
<?php if($uncostedSales>0):?><p class="error report-warning"><?=money($uncostedSales)?> of sold item revenue still has no saved cost, so profit is overstated until those costs are entered.</p><?php endif;?>
</section>

<div class="report-grid">
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">PAYMENTS</p><h2>Sales by payment method</h2></div></div><?php if($paymentBreakdown):foreach($paymentBreakdown as $method=>$amount):?><div class="dashboard-row"><span><?=e($method)?></span><strong><?=money($amount)?></strong></div><?php endforeach;else:?><p class="muted">Payment methods will appear as paid orders are recorded.</p><?php endif;?></section>
<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">COST SETTINGS</p><h2>Pen cost</h2></div></div><p class="muted">This is the cost to you for one pen. New pen orders snapshot this cost so historical profit stays unchanged if the price changes later.</p><form method="post"><?php csrf();?><input type="hidden" name="action" value="profit_settings"><input type="hidden" name="return" value="reports"><label>Pen unit cost (£)<input name="pen_cost" type="number" min="0" max="100000" step=".01" inputmode="decimal" value="<?=e($penUnitCost===''?'':number_format((int)$penUnitCost/100,2,'.',''))?>" placeholder="Enter your actual cost"></label><button>Save pen cost</button></form></section>
</div>

<section class="panel"><div class="dashboard-panel-head"><div><p class="eyebrow">PRODUCTS</p><h2>Profit by product</h2></div></div>
<div class="profit-table-wrap"><table class="profit-table"><thead><tr><th>Product</th><th>Units</th><th>Sales</th><th>Cost</th><th>Profit</th><th>Margin</th></tr></thead><tbody>
<?php foreach($productProfit as $productName=>$pp):$ppProfit=$pp['revenue']-$pp['cogs'];$margin=$pp['revenue']>0?($ppProfit/$pp['revenue']*100):0;?><tr><td><?=e($productName)?><?=$pp['missing_cost']?' <small>cost missing</small>':''?></td><td><?=$pp['units']?></td><td><?=money($pp['revenue'])?></td><td><?=money($pp['cogs'])?></td><td><?=money($ppProfit)?></td><td><?=number_format($margin,1)?>%</td></tr><?php endforeach;?>
<?php if(!$productProfit):?><tr><td colspan="6" class="muted">No paid product sales yet.</td></tr><?php endif;?>
</tbody></table></div></section>

<?php elseif($view==='more'):?>
<div class="heading"><div><h1>More</h1><p class="muted page-description">Products, profit and integrations.</p></div></div>
<div class="more-grid">
<a class="more-card" href="?view=products"><span class="more-icon">◫</span><div><h2>Products & stock</h2><p>Prices, supplier costs, availability and stock levels.</p></div><b>›</b></a>
<a class="more-card" href="?view=reports"><span class="more-icon">£</span><div><h2>Profit & reports</h2><p>Sales, costs, fees, profit and product performance.</p></div><b>›</b></a>
<a class="more-card" href="?view=sheets"><span class="more-icon">▦</span><div><h2>Google Sheets</h2><p>Connection, sync and reporting setup.</p></div><b>›</b></a>
</div>

<?php else:?>
<div id="order-saved-marker"></div>
<?php if($savedOrder):?>
<section class="saved-order">
<div class="saved-check">✓</div><p class="eyebrow">ORDER SAVED</p><h1><?=$savedOrder['reference']?></h1><p class="saved-customer"><?=e($savedOrder['customer'])?></p>
<div class="saved-total"><?=money($savedOrder['total_pence'])?></div><span class="badge status-<?=e(statusClass($savedOrder['status']))?>"><?=e($savedOrder['status'])?></span>
<div class="saved-items"><?php foreach($savedOrder['items'] as $savedItem):?><div class="line"><span><?=e($savedItem['quantity'].' × '.$savedItem['name'])?></span><strong><?=money($savedItem['unit_price_pence']*$savedItem['quantity'])?></strong></div><?php endforeach;?><?php if((int)$savedOrder['delivery_charge_pence']>0):?><div class="line"><span>Postage / delivery charge</span><strong><?=money($savedOrder['delivery_charge_pence'])?></strong></div><?php endif;?></div><div class="saved-meta"><?php if($savedOrder['payment_method']):?><span>Payment: <?=e($savedOrder['payment_method'])?></span><?php endif;?><?php if($savedOrder['delivery_method']):?><span>Delivery: <?=e($savedOrder['delivery_method'])?></span><?php endif;?></div>
<div class="saved-actions"><a class="button" href="?view=dashboard">Dashboard</a><a class="button" href="?view=new">+ New order</a><a class="quick-action" href="?view=orders">View orders</a><a class="quick-action" href="?view=new&amp;repeat_order=<?=$savedOrder['id']?>">Repeat order</a></div>
</section>
<?php else:?><p class="error">That saved order could not be found.</p><a class="button" href="?view=orders">Back to orders</a><?php endif;?>
<?php endif;?>
</main><script>
const orderDraftKey='ankh-order-draft-v1';
if(document.querySelector('#order-saved-marker')){try{localStorage.removeItem(orderDraftKey)}catch(_){}}
document.querySelectorAll('[data-copy-text]').forEach(button=>button.addEventListener('click',async()=>{const value=button.dataset.copyText||'';try{await navigator.clipboard.writeText(value);const old=button.textContent;button.textContent='Copied ✓';setTimeout(()=>button.textContent=old,1200)}catch(_){const area=document.createElement('textarea');area.value=value;document.body.append(area);area.select();document.execCommand('copy');area.remove()}}));

const addCustomerButton=document.querySelector('#show-add-customer'),addCustomerPanel=document.querySelector('#add-customer-panel');
addCustomerButton?.addEventListener('click',()=>{const open=addCustomerPanel.hidden;addCustomerPanel.hidden=!open;addCustomerButton.setAttribute('aria-expanded',open?'true':'false');if(open)addCustomerPanel.querySelector('input[name="name"]')?.focus()});
document.querySelectorAll('[data-close-customer-form]').forEach(b=>b.addEventListener('click',()=>{if(addCustomerPanel){addCustomerPanel.hidden=true;addCustomerButton?.setAttribute('aria-expanded','false')}}));
document.querySelectorAll('[data-edit-customer]').forEach(b=>b.addEventListener('click',()=>{const id=b.dataset.editCustomer,form=document.querySelector('[data-customer-form="'+id+'"]');if(!form)return;const open=form.hidden;form.hidden=!open;b.setAttribute('aria-expanded',open?'true':'false');b.textContent=open?'Hide edit':'Edit customer';if(open)form.querySelector('input[name="name"]')?.focus()}));
document.querySelectorAll('[data-cancel-customer-edit]').forEach(b=>b.addEventListener('click',()=>{const id=b.dataset.cancelCustomerEdit,form=document.querySelector('[data-customer-form="'+id+'"]'),toggle=document.querySelector('[data-edit-customer="'+id+'"]');if(form)form.hidden=true;if(toggle){toggle.setAttribute('aria-expanded','false');toggle.textContent='Edit customer'}}));
const customerPageSearch=document.querySelector('#customer-page-search'),customerStatusFilter=document.querySelector('#customer-status-filter'),customerCards=[...document.querySelectorAll('[data-customer-search]')],customerPageEmpty=document.querySelector('#customer-page-empty');
function filterCustomerPage(){if(!customerCards.length)return;const term=(customerPageSearch?.value||'').toLowerCase().trim(),mode=customerStatusFilter?.value||'active';let visible=0;customerCards.forEach(card=>{const archived=card.dataset.customerArchived==='1',statusOk=mode==='all'||(mode==='archived'?archived:!archived),searchOk=!term||card.dataset.customerSearch.includes(term);card.hidden=!(statusOk&&searchOk);if(!card.hidden)visible++});if(customerPageEmpty)customerPageEmpty.hidden=visible>0}
customerPageSearch?.addEventListener('input',filterCustomerPage);customerStatusFilter?.addEventListener('change',filterCustomerPage);filterCustomerPage();

const wizard=document.querySelector('#order-wizard'),wizardSteps=[...document.querySelectorAll('[data-wizard-step]')],wizardProgress=[...document.querySelectorAll('[data-progress-step]')],clearDraftButton=document.querySelector('#clear-draft');
let currentWizardStep=1;
function showWizardStep(step){
 if(!wizard)return;
 currentWizardStep=Number(step)||1;
 wizardSteps.forEach(section=>section.hidden=Number(section.dataset.wizardStep)!==Number(step));
 wizardProgress.forEach(item=>{const n=Number(item.dataset.progressStep);item.classList.toggle('active',n===Number(step));item.classList.toggle('done',n<Number(step))});
 document.activeElement?.blur();
 wizard.scrollIntoView({behavior:'smooth',block:'start'});
 if(Number(step)===3){setTimeout(()=>{productSearch?.focus();showProductResults()},250)}
 if(Number(step)===4)buildOrderCheck();
 if(typeof saveDraftOrder==='function')saveDraftOrder();
}
function selectedOrderRows(){return productRows.filter(row=>Number(row.querySelector('.quantity')?.value||0)>0)}
function buildOrderCheck(){
 const customer=document.querySelector('#check-customer'),detail=document.querySelector('#check-customer-detail'),products=document.querySelector('#check-products'),presentation=document.querySelector('#check-presentation'),presentationDetail=document.querySelector('#check-presentation-detail'),orderDate=document.querySelector('#order-date'),checkOrderDate=document.querySelector('#check-order-date'),checkDelivery=document.querySelector('#check-delivery'),checkDeliveryDetail=document.querySelector('#check-delivery-detail');
 if(customer)customer.textContent=customerSearch?.value.trim()||'—';
 if(detail){const bits=[customerPhone?.value.trim(),document.querySelector('#customer-referrer')?.value.trim()].filter(Boolean);detail.textContent=bits.join(' · ')}
 if(checkOrderDate&&orderDate?.value){const d=new Date(orderDate.value+'T12:00:00');checkOrderDate.textContent=d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'})}
 if(presentation)presentation.textContent=presentationInput?.value||'—';
 if(presentationDetail)presentationDetail.textContent='';
 if(checkDelivery)checkDelivery.textContent=deliveryMethodSelect?.value||'—';
 if(checkDeliveryDetail){const bits=[];if(deliveryMethodSelect?.value==='Postage'&&Number(deliveryChargeInput?.value||0)>0)bits.push('Postage charged £'+Number(deliveryChargeInput.value).toFixed(2));if(trackingReferenceInput?.value.trim())bits.push(trackingReferenceInput.value.trim());checkDeliveryDetail.textContent=bits.join(' · ')}
 if(products){products.replaceChildren();selectedOrderRows().forEach(row=>{const q=Number(row.querySelector('.quantity')?.value||0),price=Number(row.querySelector('.line-price')?.value||0),line=document.createElement('div');line.className='check-product-line';const name=document.createElement('span');name.textContent=q+' × '+row.dataset.productLabel;const amount=document.createElement('strong');amount.textContent=new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP'}).format(q*price);line.append(name,amount);products.append(line)})}
 updateTotal();
}
document.querySelectorAll('[data-wizard-next]').forEach(button=>button.addEventListener('click',()=>{
 const next=Number(button.dataset.wizardNext);
 if(next===2){if(!customerSearch?.value.trim()){customerSearch?.reportValidity();customerSearch?.focus();return}const orderDate=document.querySelector('#order-date');if(!orderDate?.value||!orderDate.checkValidity()){orderDate?.reportValidity();orderDate?.focus();return}if(!deliveryMethodSelect?.value){deliveryMethodSelect?.reportValidity();deliveryMethodSelect?.focus();return}}
 if(next===3&&!presentationInput?.value){alert('Choose Pen, Cartridge or Vial first.');return}
 if(next===4&&!selectedOrderRows().length){alert('Add at least one product before continuing.');productSearch?.focus();showProductResults();return}
 showWizardStep(next);
}));
document.querySelectorAll('[data-wizard-back]').forEach(button=>button.addEventListener('click',()=>showWizardStep(Number(button.dataset.wizardBack))));

const customerSuggestions=<?=json_encode($customerSuggestions,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>;
const customerSearch=document.querySelector('#customer-search'),customerResults=document.querySelector('#customer-results'),customerPhone=document.querySelector('#customer-phone'),customerAddress=document.querySelector('#customer-address');
function chooseCustomer(customer){if(customerSearch)customerSearch.value=customer.name||'';if(customerPhone)customerPhone.value=customer.phone||'';if(customerAddress)customerAddress.value=customer.address||'';if(customerResults){customerResults.hidden=true;customerResults.replaceChildren()}customerSearch?.focus();if(typeof saveDraftOrder==='function')saveDraftOrder()}
function showCustomerResults(){if(!customerSearch||!customerResults)return;const term=customerSearch.value.toLowerCase().trim();customerResults.replaceChildren();if(term.length<1){customerResults.hidden=true;return}const matches=customerSuggestions.filter(c=>(c.name||'').toLowerCase().includes(term)||(c.phone||'').toLowerCase().includes(term)).slice(0,8);if(!matches.length){customerResults.hidden=true;return}matches.forEach(c=>{const b=document.createElement('button');b.type='button';b.className='customer-result';b.setAttribute('role','option');const main=document.createElement('strong');main.textContent=c.name||'Customer';const detail=document.createElement('span');detail.textContent=c.phone||'';b.append(main,detail);if(c.address){const addr=document.createElement('small');addr.textContent=c.address.replace(/\s+/g,' ').trim();b.append(addr)}b.addEventListener('click',()=>chooseCustomer(c));customerResults.append(b)});customerResults.hidden=false}
customerSearch?.addEventListener('input',showCustomerResults);
customerSearch?.addEventListener('focus',showCustomerResults);
document.querySelectorAll('[data-recent-customer]').forEach(button=>button.addEventListener('click',()=>{const customer=customerSuggestions.find(c=>String(c.id)===button.dataset.recentCustomer);if(customer)chooseCustomer(customer)}));
document.addEventListener('click',e=>{if(customerResults&&!e.target.closest('.customer-search-wrap'))customerResults.hidden=true});

const search=document.querySelector('#search'),filter=document.querySelector('#filter');
function applyFilters(){let visible=0;document.querySelectorAll('.order-list .order').forEach(o=>{o.hidden=!(o.dataset.search.includes(search.value.toLowerCase().trim())&&(!filter.value||o.dataset.status===filter.value));if(!o.hidden)visible++});document.querySelector('#empty').hidden=visible>0}
search?.addEventListener('input',applyFilters);filter?.addEventListener('change',applyFilters);
const productSearch=document.querySelector('#product-search'),productResults=document.querySelector('#product-results'),productRows=[...document.querySelectorAll('[data-product-id]')],selectedEmpty=document.querySelector('#selected-empty'),strengthPicker=document.querySelector('#strength-picker'),strengthOptions=document.querySelector('#strength-options'),strengthProductName=document.querySelector('#strength-product-name'),closeStrengthPicker=document.querySelector('#close-strength-picker'),presentationInput=document.querySelector('#presentation'),presentationButtons=[...document.querySelectorAll('[data-presentation]')],productChoiceArea=document.querySelector('#product-choice-area'),deliveryMethodSelect=document.querySelector('#delivery-method'),postageFields=document.querySelector('#postage-fields'),deliveryChargeInput=document.querySelector('#delivery-charge'),postageCostInput=document.querySelector('#postage-cost'),trackingReferenceInput=document.querySelector('#tracking-reference'),paymentMethodInput=document.querySelector('#payment-method'),paymentFeeInput=document.querySelector('#payment-fee');
function togglePostageFields(){const isPostage=deliveryMethodSelect?.value==='Postage';if(postageFields)postageFields.hidden=!isPostage;if(!isPostage){if(trackingReferenceInput)trackingReferenceInput.value='';if(deliveryChargeInput)deliveryChargeInput.value='0.00';if(postageCostInput)postageCostInput.value='0.00'}updateTotal();if(typeof saveDraftOrder==='function')saveDraftOrder()}
deliveryMethodSelect?.addEventListener('change',togglePostageFields);
const draftEnabled=wizard?.dataset.draftEnabled==='1';
function saveDraftOrder(){
 if(!draftEnabled||!wizard)return;
 const qty={},price={};productRows.forEach(row=>{const id=row.dataset.productId,q=Number(row.querySelector('.quantity')?.value||0);if(q>0){qty[id]=q;price[id]=row.querySelector('.line-price')?.value||row.dataset.standardPrice}});
 const draft={step:currentWizardStep,customer:customerSearch?.value||'',phone:customerPhone?.value||'',address:customerAddress?.value||'',referrer:document.querySelector('#customer-referrer')?.value||'',order_date:document.querySelector('#order-date')?.value||'',presentation:presentationInput?.value||'',delivery_method:deliveryMethodSelect?.value||'',tracking_reference:trackingReferenceInput?.value||'',delivery_charge:deliveryChargeInput?.value||'',postage_cost:postageCostInput?.value||'',payment_method:paymentMethodInput?.value||'',payment_fee:paymentFeeInput?.value||'',notes:wizard.querySelector('textarea[name="notes"]')?.value||'',qty,price};
 const useful=draft.customer||draft.phone||draft.address||draft.presentation||Object.keys(qty).length;
 try{if(useful){localStorage.setItem(orderDraftKey,JSON.stringify(draft));if(clearDraftButton)clearDraftButton.hidden=false}else{localStorage.removeItem(orderDraftKey);if(clearDraftButton)clearDraftButton.hidden=true}}catch(_){}
}
function restoreDraftOrder(){
 if(!draftEnabled||!wizard)return;
 let draft=null;try{draft=JSON.parse(localStorage.getItem(orderDraftKey)||'null')}catch(_){}
 if(!draft)return;
 if(customerSearch)customerSearch.value=draft.customer||'';if(customerPhone)customerPhone.value=draft.phone||'';if(customerAddress)customerAddress.value=draft.address||'';
 const ref=document.querySelector('#customer-referrer'),orderDate=document.querySelector('#order-date'),notes=wizard.querySelector('textarea[name="notes"]');if(ref)ref.value=draft.referrer||'';if(orderDate&&draft.order_date)orderDate.value=draft.order_date;if(notes)notes.value=draft.notes||'';if(deliveryMethodSelect)deliveryMethodSelect.value=draft.delivery_method||deliveryMethodSelect.value||'Local Delivery';if(trackingReferenceInput)trackingReferenceInput.value=draft.tracking_reference||'';if(deliveryChargeInput)deliveryChargeInput.value=draft.delivery_charge||'0.00';if(postageCostInput)postageCostInput.value=draft.postage_cost||'0.00';if(paymentMethodInput)paymentMethodInput.value=draft.payment_method||'';if(paymentFeeInput)paymentFeeInput.value=draft.payment_fee||'0.00';togglePostageFields();
 productRows.forEach(row=>{const id=row.dataset.productId,q=row.querySelector('.quantity'),p=row.querySelector('.line-price');if(draft.qty?.[id]){q.value=draft.qty[id];row.hidden=false}if(draft.price?.[id]&&p)p.value=draft.price[id]});
 if(draft.presentation)setPresentation(draft.presentation);updateTotal();if(clearDraftButton)clearDraftButton.hidden=false;showWizardStep(Math.min(4,Math.max(1,Number(draft.step)||1)));
}
clearDraftButton?.addEventListener('click',()=>{try{localStorage.removeItem(orderDraftKey)}catch(_){}location.href='?view=new'});
function setPresentation(value){
 if(!presentationInput)return;
 presentationInput.value=value;
 presentationButtons.forEach(b=>b.classList.toggle('selected',b.dataset.presentation===value));
 if(productChoiceArea)productChoiceArea.hidden=!value;
 updateTotal();
 if(typeof saveDraftOrder==='function')saveDraftOrder();
}
presentationButtons.forEach(b=>b.addEventListener('click',()=>setPresentation(b.dataset.presentation)));
if(presentationInput?.value)setPresentation(presentationInput.value);
function updateTotal(){let total=0,selected=0;document.querySelectorAll('.quantity').forEach(x=>{const row=x.closest('.product-pick'),price=Math.max(0,Number(row.querySelector('.line-price')?.value)||0),qty=Math.max(0,Number(x.value)||0);total+=qty*price;row.classList.toggle('picked',qty>0);row.hidden=qty<=0;if(qty>0)selected++});if(presentationInput?.value==='Pen')total+=20;if(deliveryMethodSelect?.value==='Postage')total+=Math.max(0,Number(deliveryChargeInput?.value)||0);if(selectedEmpty)selectedEmpty.hidden=selected>0;const out=document.querySelector('#subtotal');if(out){out.setAttribute('aria-live','polite');out.textContent=new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP'}).format(total)}}
function addProduct(row){if(!row)return;const q=row.querySelector('.quantity');row.hidden=false;if((Number(q.value)||0)<1)q.value=1;updateTotal();if(strengthPicker)strengthPicker.hidden=true;if(productSearch){productSearch.value='';productSearch.blur()}if(productResults){productResults.hidden=true;productResults.replaceChildren()}syncFamilyDiscountButton(row);row.scrollIntoView({block:'nearest',behavior:'smooth'});if(typeof saveDraftOrder==='function')saveDraftOrder()}
function syncFamilyDiscountButton(row){
 const button=row?.querySelector('[data-family-discount]'),price=row?.querySelector('.line-price');if(!button||!price)return;
 const normal=Math.max(0,Number(row.dataset.standardPrice)||0),discounted=Math.max(0,normal-5),current=Math.max(0,Number(price.value)||0);
 const selected=Math.abs(current-discounted)<0.005;
 button.classList.toggle('selected',selected);button.setAttribute('aria-pressed',selected?'true':'false');
}
function syncFamilyDiscountButtons(){productRows.forEach(syncFamilyDiscountButton)}
document.querySelectorAll('[data-family-discount]').forEach(button=>button.addEventListener('click',()=>{
 const row=button.closest('.product-pick'),price=row?.querySelector('.line-price');if(!row||!price)return;
 const normal=Math.max(0,Number(row.dataset.standardPrice)||0),discounted=Math.max(0,normal-5),selected=button.classList.contains('selected');
 price.value=(selected?normal:discounted).toFixed(2);syncFamilyDiscountButton(row);updateTotal();if(typeof saveDraftOrder==='function')saveDraftOrder();
}));
function productGroups(){const groups=new Map();productRows.forEach(row=>{const base=row.dataset.productBase||row.dataset.productLabel,key=base.toLowerCase();if(!groups.has(key))groups.set(key,{base,rows:[]});groups.get(key).rows.push(row)});return [...groups.values()]}
function showStrengths(group){if(!strengthPicker||!strengthOptions||!strengthProductName)return;strengthProductName.textContent=group.base;strengthOptions.replaceChildren();group.rows.sort((a,b)=>{const av=parseFloat(a.dataset.productStrength)||0,bv=parseFloat(b.dataset.productStrength)||0;return av-bv}).forEach(row=>{const b=document.createElement('button');b.type='button';b.className='strength-option';const strength=document.createElement('strong');strength.textContent=row.dataset.productStrength||'Add';const price=document.createElement('span');price.textContent='£'+Number(row.dataset.standardPrice).toFixed(2);b.append(strength,price);b.addEventListener('click',()=>addProduct(row));strengthOptions.append(b)});strengthPicker.hidden=false;productResults.hidden=true;productSearch.value=group.base;strengthPicker.scrollIntoView({block:'nearest',behavior:'smooth'})}
function showProductResults(){if(!productSearch||!productResults)return;const term=productSearch.value.toLowerCase().trim();productResults.replaceChildren();const groups=productGroups();let matches;if(!term){const reta=groups.find(group=>group.base.toLowerCase()==='retatrutide');matches=reta?[reta]:[]}else{matches=groups.filter(group=>group.base.toLowerCase().includes(term)).sort((a,b)=>{if(a.base.toLowerCase()==='retatrutide')return -1;if(b.base.toLowerCase()==='retatrutide')return 1;return a.base.localeCompare(b.base)}).slice(0,8)}if(!matches.length){productResults.hidden=true;return}matches.forEach(group=>{const b=document.createElement('button');b.type='button';b.className='product-result';b.setAttribute('role','option');const name=document.createElement('strong');name.textContent=group.base;const info=document.createElement('span');const strengths=group.rows.map(r=>r.dataset.productStrength).filter(Boolean).sort((a,b)=>(parseFloat(a)||0)-(parseFloat(b)||0));info.textContent=strengths.length?strengths.join(' · '):(group.rows.length>1?'Choose option':'Add');b.append(name,info);b.addEventListener('click',()=>{if(group.rows.length===1)addProduct(group.rows[0]);else showStrengths(group)});productResults.append(b)});productResults.hidden=false}
productSearch?.addEventListener('input',()=>{if(strengthPicker)strengthPicker.hidden=true;showProductResults()});
productSearch?.addEventListener('focus',showProductResults);
closeStrengthPicker?.addEventListener('click',()=>{strengthPicker.hidden=true;productSearch?.focus()});
document.addEventListener('click',e=>{if(productResults&&!e.target.closest('.product-search-wrap')&&!e.target.closest('.strength-picker'))productResults.hidden=true});
document.querySelectorAll('.quantity,.line-price').forEach(q=>q.addEventListener('input',()=>{updateTotal();if(q.classList.contains('line-price'))syncFamilyDiscountButton(q.closest('.product-pick'))}));deliveryChargeInput?.addEventListener('input',()=>{updateTotal();if(typeof saveDraftOrder==='function')saveDraftOrder()});paymentFeeInput?.addEventListener('input',()=>{if(typeof saveDraftOrder==='function')saveDraftOrder()});trackingReferenceInput?.addEventListener('input',()=>{if(typeof saveDraftOrder==='function')saveDraftOrder()});paymentMethodInput?.addEventListener('change',()=>{if(typeof saveDraftOrder==='function')saveDraftOrder()});
document.querySelectorAll('[data-change]').forEach(b=>b.addEventListener('click',()=>{const row=b.closest('.product-pick'),q=row.querySelector('.quantity');q.value=Math.min(999,Math.max(0,(Number(q.value)||0)+Number(b.dataset.change)));updateTotal();saveDraftOrder()}));
togglePostageFields();updateTotal();syncFamilyDiscountButtons();
wizard?.addEventListener('input',saveDraftOrder);wizard?.addEventListener('change',saveDraftOrder);
restoreDraftOrder();
const orderForm=document.querySelector('.save-order')?.form;
orderForm?.addEventListener('submit',e=>{if(!customerSearch?.value.trim()){e.preventDefault();showWizardStep(1);customerSearch?.reportValidity();return}if(!deliveryMethodSelect?.value){e.preventDefault();showWizardStep(1);deliveryMethodSelect?.reportValidity();return}if(!presentationInput?.value){e.preventDefault();alert('Choose Pen, Cartridge or Vial.');showWizardStep(2);return}if(!selectedOrderRows().length){e.preventDefault();alert('Add at least one product.');showWizardStep(3);return}const b=orderForm.querySelector('.save-order');b.disabled=true;b.textContent=b.form?.querySelector('input[name="action"]')?.value==='order_edit'?'Saving changes…':'Saving order…'});
</script><?php endif;?></body></html>
