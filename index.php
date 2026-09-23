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
if(!in_array('assigned_to',array_column($orderColumns,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN assigned_to TEXT NOT NULL DEFAULT ''");
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
 'Pen'=>1500
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
$catalogVersion='retail-posters-2026-09-23-v4';
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
  'payment_method'=>$o['payment_method']??'','delivery_method'=>$o['delivery_method']??'','assigned_to'=>$o['assigned_to']??'','tracking_reference'=>$o['tracking_reference']??'',
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

// One-time 4-digit PIN setup. Only a SHA-256 hash of the setup token is stored here.
$pinSetupTokenHash='64a1ea8550f3bb078a15088090d021b056d8bdad6ee24d1346e7603693b1cbea';
$savedAdminPinHash=setting($db,'admin_pin_hash','');
$pinSetupComplete=$savedAdminPinHash!=='';
$testingNoAuth=!$pinSetupComplete;
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
   'required'=>['customer_id','customer_name','phone','address','referrer','delivery_method','assigned_to','payment_method','notes','lines','questions','needs_review']
  ];
  $instructions="Convert a spoken ANKH order into a draft. ANKH speech alias rule: 'Reta' means Retatrutide. If the transcript contains Reta, Rita, Reeta or Rayta in a clear product context, especially next to 10mg, 20mg, pen, vial or cartridge, interpret it as Retatrutide rather than a person's name. Never invent customer contact details, products, strengths, quantities, formats, payment methods or delivery people. Use an existing customer_id only when the spoken customer clearly matches the supplied customer list. If an existing customer is chosen, leave phone and address blank because the app fills them from its database. Product_name must be an exact supplied catalogue value. If a product is mentioned without enough strength information to choose exactly, do not guess: omit that line and add a short question. The standalone catalogue product named Pen is an accessory sold on its own: for that product use presentation='' and do not ask for Pen/Cartridge/Vial. For every other product, if Pen/Cartridge/Vial was not said, use an empty presentation and ask which format. 'family and friends', 'friends and family', or 'F&F' means family_friends=true. Delivery defaults to Local Delivery only if no delivery method was said. Assignment is only James or Tony when explicitly stated. Return a draft only; never imply it has been saved.";
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
  $lines=[];
  foreach(($draft['lines']??[]) as $line){
   $name=(string)($line['product_name']??'');if(!isset($productByName[$name]))continue;$p=$productByName[$name];
   $isStandalonePen=strtolower(trim($name))==='pen';
   $qty=max(1,min(99,(int)($line['quantity']??1)));$presentation=$isStandalonePen?'':(in_array(($line['presentation']??''),['Pen','Cartridge','Vial'],true)?(string)$line['presentation']:'');
   $base=(int)$p['price']/100;$canDiscount=!$isStandalonePen&&(bool)preg_match('/\d+(?:\.\d+)?\s*mg$/i',$name);$discount=$canDiscount&&!empty($line['family_friends']);
   $price=max(0,$base-($discount?5:0)+(!$isStandalonePen&&$presentation==='Pen'?20:0));
   $lines[]=['product_id'=>(int)$p['id'],'name'=>$name,'quantity'=>$qty,'presentation'=>$presentation,'base_price'=>$base,'discount'=>$discount,'price'=>$price];
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

  $instructions="You are ANKH Assistant, a conversational voice assistant inside the private ANKH Peptides admin app. Speak naturally in concise British English, like a helpful colleague. This is a live conversation: remember the product, customer, timeframe and topic from previous turns so follow-up questions such as 'what about in a pen?', 'what did it cost us?', 'what about last month?' and 'how many are left?' make sense without the user repeating everything. Reta, Reeta, Rita or Rayta said as a product means Retatrutide. For factual ANKH prices, supplier costs, stock, orders, sales, gross profit, payments or deliveries, ALWAYS use the appropriate tool rather than guessing. Product retail price means the selling price. 'Cost us', 'our cost', 'supplier cost' or similar means the saved supplier cost. If the user simply asks 'what does it cost?' and context is unclear, briefly give both retail and supplier cost or ask which they mean. If a product lookup returns multiple strengths, ask which strength rather than choosing one. Monetary values are GBP. Gross profit means completed sales minus saved product cost, pen cost and postage; mention when missing saved costs make the result incomplete. This assistant is read-only: never claim you changed an order, payment, delivery, stock or customer. Do not provide peptide dosing, administration or medical advice; say this assistant is for ANKH business/admin information. Keep spoken answers short enough to feel conversational, but include the exact figure or names the user asked for.";

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
   $rows=$db->query('SELECT id,name,price,cost,stock_qty,low_stock_at,active FROM products WHERE active=1 ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
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
     'stock_qty'=>$row['stock_qty']===null?null:(int)$row['stock_qty'],
     'low_stock_at'=>$row['stock_qty']===null?null:(int)$row['low_stock_at']
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
   $rows=$db->query("SELECT o.id,o.customer,o.created,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) total
    FROM orders o LEFT JOIN items i ON i.order_id=o.id
    WHERE o.status IN ('New','Awaiting payment')
    GROUP BY o.id ORDER BY datetime(o.created) ASC,o.id ASC")->fetchAll(PDO::FETCH_ASSOC);
   $total=0;$orders=[];foreach($rows as $row){$total+=(int)$row['total'];$orders[]=['reference'=>'ANK-'.str_pad((string)$row['id'],4,'0',STR_PAD_LEFT),'customer'=>(string)$row['customer'],'amount'=>$moneyTool((int)$row['total']),'created'=>(string)$row['created']];}
   $result=['count'=>count($orders),'total_outstanding'=>$moneyTool($total),'orders'=>$orders];
  }elseif($tool==='get_deliveries'){
   $assignee=(string)($args['assignee']??'');$sql="SELECT id,customer,status,assigned_to,delivery_method,created FROM orders WHERE status IN ('Paid','Packed','Dispatched')";$params=[];
   if(in_array($assignee,['James','Tony'],true)){$sql.=" AND assigned_to=?";$params[]=$assignee;}
   $sql.=" ORDER BY datetime(created) ASC,id ASC";$q=$db->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$orders=[];
   foreach($rows as $row)$orders[]=['reference'=>'ANK-'.str_pad((string)$row['id'],4,'0',STR_PAD_LEFT),'customer'=>(string)$row['customer'],'status'=>(string)$row['status'],'assigned_to'=>(string)($row['assigned_to']?:'Unassigned'),'delivery_method'=>(string)($row['delivery_method']?:'Not set'),'created'=>(string)$row['created']];
   $result=['assignee'=>$assignee?:'All','count'=>count($orders),'orders'=>$orders];
  }elseif($tool==='get_low_stock'){
   $rows=$db->query("SELECT name,stock_qty,low_stock_at FROM products WHERE active=1 AND stock_qty IS NOT NULL AND stock_qty<=low_stock_at ORDER BY stock_qty ASC,name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
   $products=[];foreach($rows as $row)$products[]=['name'=>(string)$row['name'],'stock_qty'=>(int)$row['stock_qty'],'low_stock_at'=>(int)$row['low_stock_at']];
   $result=['count'=>count($products),'products'=>$products];
  }elseif($tool==='lookup_customer_last_order'){
   $customer=trim((string)($args['customer_name']??''));if($customer==='')throw new Exception('Tell me the customer name.');
   $q=$db->prepare("SELECT DISTINCT customer FROM orders WHERE lower(customer) LIKE lower(?) ORDER BY customer COLLATE NOCASE LIMIT 8");$q->execute(['%'.$customer.'%']);$names=$q->fetchAll(PDO::FETCH_COLUMN);
   if(!$names)$result=['found'=>false,'customer_query'=>$customer];
   else{
    $exact=array_values(array_filter($names,fn($n)=>strtolower(trim((string)$n))===strtolower($customer)));
    if(count($names)>1&&!$exact)$result=['found'=>true,'ambiguous'=>true,'customer_query'=>$customer,'customer_matches'=>array_values($names)];
    else{
     $chosen=(string)($exact[0]??$names[0]);$q=$db->prepare("SELECT o.*,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) total FROM orders o LEFT JOIN items i ON i.order_id=o.id WHERE o.customer=? GROUP BY o.id ORDER BY datetime(o.created) DESC,o.id DESC LIMIT 1");$q->execute([$chosen]);$row=$q->fetch(PDO::FETCH_ASSOC);
     if(!$row)$result=['found'=>false,'customer_query'=>$customer];
     else{$iq=$db->prepare('SELECT name,quantity,presentation FROM items WHERE order_id=? ORDER BY id');$iq->execute([(int)$row['id']]);$items=[];foreach($iq->fetchAll(PDO::FETCH_ASSOC) as $it){if(strtolower(trim((string)$it['name']))==='pen')continue;$items[]=['name'=>(string)$it['name'],'quantity'=>(int)$it['quantity'],'presentation'=>(string)$it['presentation']];}
      $result=['found'=>true,'ambiguous'=>false,'customer'=>(string)$row['customer'],'reference'=>'ANK-'.str_pad((string)$row['id'],4,'0',STR_PAD_LEFT),'created'=>(string)$row['created'],'status'=>(string)$row['status'],'total'=>$moneyTool((int)$row['total']),'delivery_method'=>(string)($row['delivery_method']??''),'assigned_to'=>(string)($row['assigned_to']??''),'items'=>$items];
     }
    }
   }
  }elseif($tool==='lookup_order'){
   $query=trim((string)($args['query']??''));if($query==='')throw new Exception('Tell me which order to look up.');
   $digits=preg_replace('/\\D+/','',$query);$rows=[];
   if($digits!==''){$q=$db->prepare("SELECT o.*,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) total FROM orders o LEFT JOIN items i ON i.order_id=o.id WHERE o.id=? GROUP BY o.id LIMIT 1");$q->execute([(int)$digits]);$one=$q->fetch(PDO::FETCH_ASSOC);if($one)$rows=[$one];}
   if(!$rows){$q=$db->prepare("SELECT o.*,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) total FROM orders o LEFT JOIN items i ON i.order_id=o.id WHERE lower(o.customer) LIKE lower(?) GROUP BY o.id ORDER BY datetime(o.created) DESC,o.id DESC LIMIT 5");$q->execute(['%'.$query.'%']);$rows=$q->fetchAll(PDO::FETCH_ASSOC);}
   $orders=[];foreach($rows as $row){$orders[]=['reference'=>'ANK-'.str_pad((string)$row['id'],4,'0',STR_PAD_LEFT),'customer'=>(string)$row['customer'],'status'=>(string)$row['status'],'total'=>$moneyTool((int)$row['total']),'created'=>(string)$row['created'],'assigned_to'=>(string)($row['assigned_to']??''),'delivery_method'=>(string)($row['delivery_method']??'')];}
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
    $unpaidRows=$db->query("SELECT o.id,o.customer,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) total FROM orders o LEFT JOIN items i ON i.order_id=o.id WHERE o.status IN ('New','Awaiting payment') GROUP BY o.id")->fetchAll(PDO::FETCH_ASSOC);
    $deliveryRows=$db->query("SELECT id FROM orders WHERE status IN ('Paid','Packed','Dispatched')")->fetchAll(PDO::FETCH_ASSOC);
    $lowRows=$db->query("SELECT id FROM products WHERE active=1 AND stock_qty IS NOT NULL AND stock_qty<=low_stock_at")->fetchAll(PDO::FETCH_ASSOC);
    $unpaidTotal=array_sum(array_map(fn($r)=>(int)$r['total'],$unpaidRows));
    $answer='Today you have '.$moneySpeak($todayRevenue).' in sales and '.$moneySpeak($todayProfit).' gross profit from '.$todayOrders.' completed order'.($todayOrders===1?'':'s').'. There are '.count($unpaidRows).' outstanding payment'.(count($unpaidRows)===1?'':'s').' worth '.$moneySpeak($unpaidTotal).', '.count($deliveryRows).' order'.(count($deliveryRows)===1?'':'s').' still to deliver, and '.count($lowRows).' low-stock product'.(count($lowRows)===1?'':'s').'.';
   }
  }elseif($kind==='outstanding_payments'){
   $rows=$db->query("SELECT o.id,o.customer,o.created,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) total FROM orders o LEFT JOIN items i ON i.order_id=o.id WHERE o.status IN ('New','Awaiting payment') GROUP BY o.id ORDER BY datetime(o.created) ASC,o.id ASC")->fetchAll(PDO::FETCH_ASSOC);
   $total=array_sum(array_map(fn($r)=>(int)$r['total'],$rows));
   foreach($rows as $r)$items[]=['reference'=>'ANK-'.str_pad((string)$r['id'],4,'0',STR_PAD_LEFT),'title'=>(string)$r['customer'],'detail'=>$moneySpeak((int)$r['total']).' outstanding'];
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
   $rows=$db->query("SELECT name,stock_qty,low_stock_at FROM products WHERE active=1 AND stock_qty IS NOT NULL AND stock_qty<=low_stock_at ORDER BY stock_qty ASC,name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
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

if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
 if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) throw new Exception('Please refresh the page and try again.');
 $action=$_POST['action']??'';
 if ($action==='login') {
  $ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');$q=$db->prepare('SELECT * FROM attempts WHERE ip=?');$q->execute([$ip]);$a=$q->fetch(PDO::FETCH_ASSOC);
  if($a && $a['failures']>=5 && time()-(int)$a['started']<900) throw new Exception('Too many attempts. Please wait 15 minutes.');
  if(!password_verify((string)($_POST['password']??''),$adminPasswordHash)){
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
   $paymentMethod=trim((string)($_POST['payment_method']??''));
   if($paymentMethod!==''&&!in_array($paymentMethod,$paymentMethods,true))throw new Exception('Choose a valid payment method.');
   $q=$db->prepare('SELECT payment_date,delivery_date,payment_method FROM orders WHERE id=?');$q->execute([$orderId]);$existingDates=$q->fetch(PDO::FETCH_ASSOC);
   if(!$existingDates)throw new Exception('Order could not be found.');
   if($newStatus==='Paid'){
    if($paymentDate==='')$paymentDate=(string)($existingDates['payment_date']?:$todayAction);
    if($paymentMethod==='' && (string)$existingDates['payment_method']==='')throw new Exception('Choose how the payment was received.');
   }
   if($newStatus==='Delivered' && $deliveryDate==='')$deliveryDate=(string)($existingDates['delivery_date']?:$todayAction);
   $db->prepare("UPDATE orders SET status=?,payment_date=CASE WHEN ?<>'' THEN ? ELSE payment_date END,delivery_date=CASE WHEN ?<>'' THEN ? ELSE delivery_date END,payment_method=CASE WHEN ?<>'' THEN ? ELSE payment_method END WHERE id=?")->execute([$newStatus,$paymentDate,$paymentDate,$deliveryDate,$deliveryDate,$paymentMethod,$paymentMethod,$orderId]);
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
   $paymentMethod=trim((string)($_POST['payment_method']??''));$deliveryMethod=trim((string)($_POST['delivery_method']??''));$assignedTo=trim((string)($_POST['assigned_to']??''));if($assignedTo==='Jay')$assignedTo='James';$trackingReference=trim((string)($_POST['tracking_reference']??''));
   $deliveryCharge=postedMoneyPence($_POST['delivery_charge']??'','postage charge');$postageCost=postedMoneyPence($_POST['postage_cost']??'','postage cost');$paymentFee=$isEdit?(int)($existingOrder['payment_fee']??0):0;
   if(!$name || strlen($name)>160 || strlen($phone)>40 || strlen($referrer)>160 || strlen($address)>2000 || strlen($notes)>4000 || strlen($trackingReference)>200)throw new Exception('Check the order details and try again.');
   if($paymentMethod!==''&&!in_array($paymentMethod,$paymentMethods,true))throw new Exception('Choose a valid payment method.');
   if(!in_array($deliveryMethod,$deliveryMethods,true))throw new Exception('Choose Collection, Local Delivery or Postage.');
   if($assignedTo!==''&&!in_array($assignedTo,$deliveryAssignees,true))throw new Exception('Choose James, Tony or Unassigned.');
   if($deliveryMethod!=='Postage'){$trackingReference='';$deliveryCharge=0;$postageCost=0;}
   $orderTz=new DateTimeZone('Europe/London');$todayLocal=new DateTimeImmutable('today',$orderTz);$chosenDate=DateTimeImmutable::createFromFormat('!Y-m-d',$orderDate,$orderTz);
   if(!$chosenDate || $chosenDate->format('Y-m-d')!==$orderDate || $chosenDate>$todayLocal)throw new Exception('Choose a valid order date.');

   $lines=[];$presentations=[];
   foreach(($_POST['lines']??[]) as $lineKey=>$line){
    if(!is_array($line))continue;
    $productId=(int)($line['product_id']??0);$qty=filter_var($line['quantity']??0,FILTER_VALIDATE_INT);$format=trim((string)($line['presentation']??''));$discountFlag=((string)($line['discount']??'0'))==='1';
    if($productId<1||$qty===false||$qty<1||$qty>999)throw new Exception('Check the product quantities.');
    $q=$db->prepare($isEdit?'SELECT * FROM products WHERE id=?':'SELECT * FROM products WHERE id=? AND active=1');$q->execute([$productId]);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)throw new Exception('A selected product is unavailable.');
    $isStandalonePen=strtolower(trim((string)$p['name']))==='pen';
    if($isStandalonePen)$format='';
    elseif(!in_array($format,['Pen','Cartridge','Vial'],true))throw new Exception('Choose Pen, Cartridge or Vial for every peptide.');
    $basePrice=postedMoneyPence($line['base_price']??number_format((int)$p['price']/100,2,'.',''),'base price');
    if($basePrice<0)$basePrice=(int)$p['price'];
    $discount=(!$isStandalonePen&&$discountFlag)?min(500,$basePrice):0;$formatCharge=(!$isStandalonePen&&$format==='Pen')?2000:0;
    $calculatedPrice=max(0,$basePrice-$discount+$formatCharge);
    $postedPrice=postedMoneyPence($line['price']??number_format($calculatedPrice/100,2,'.',''),'line price');
    $costKey=strtolower(trim((string)$p['name']));$saved=$existingItemCosts[$costKey]??null;
    $productCost=$saved&&array_key_exists('cost',$saved)?$saved['cost']:($p['cost']===null?null:(int)$p['cost']);
    $penCostSetting=setting($db,'pen_cost_pence','');$presentationCost=(!$isStandalonePen&&$format==='Pen')?($penCostSetting!==''?(int)$penCostSetting:0):0;
    if($saved && !$isStandalonePen && $format==='Pen' && (int)$saved['presentation_cost']>0)$presentationCost=(int)$saved['presentation_cost'];
    $lines[]=['product'=>$p,'qty'=>(int)$qty,'presentation'=>$format,'base_price'=>$basePrice,'discount'=>$discount,'price'=>$postedPrice,'cost'=>$productCost,'presentation_cost'=>$presentationCost];
    $presentations[$format]=true;
   }
   if(!$lines)throw new Exception('Add at least one product.');
   $orderPresentation=count($presentations)===1?(string)array_key_first($presentations):'Mixed';

   if($isEdit){
    $originalLocal=(new DateTimeImmutable((string)$existingOrder['created']))->setTimezone($orderTz);
    $created=(new DateTimeImmutable($orderDate.' '.$originalLocal->format('H:i:s'),$orderTz))->setTimezone(new DateTimeZone('UTC'))->format('c');
   }else{
    $created=$chosenDate->format('Y-m-d')===$todayLocal->format('Y-m-d')?gmdate('c'):(new DateTimeImmutable($orderDate.' 12:00:00',$orderTz))->setTimezone(new DateTimeZone('UTC'))->format('c');
   }

   $db->beginTransaction();
   if($isEdit){
    $db->prepare('UPDATE orders SET customer=?,phone=?,address=?,notes=?,created=?,referrer=?,presentation=?,payment_method=?,delivery_method=?,assigned_to=?,tracking_reference=?,delivery_charge=?,postage_cost=?,payment_fee=? WHERE id=?')->execute([$name,$phone,$address,$notes,$created,$referrer,$orderPresentation,$paymentMethod,$deliveryMethod,$assignedTo,$trackingReference,$deliveryCharge,$postageCost,$paymentFee,$orderId]);
    $db->prepare('DELETE FROM items WHERE order_id=?')->execute([$orderId]);
   }else{
    $db->prepare('INSERT INTO orders(customer,phone,address,notes,created,referrer,presentation,payment_method,delivery_method,assigned_to,tracking_reference,delivery_charge,postage_cost,payment_fee) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$name,$phone,$address,$notes,$created,$referrer,$orderPresentation,$paymentMethod,$deliveryMethod,$assignedTo,$trackingReference,$deliveryCharge,$postageCost,$paymentFee]);$orderId=(int)$db->lastInsertId();$savedOrderId=$orderId;
   }
   $saveCustomer=$db->prepare("INSERT INTO customers(name,phone,address,created,archived) VALUES (?,?,?,?,0) ON CONFLICT(name,phone) DO UPDATE SET address=CASE WHEN excluded.address<>'' THEN excluded.address ELSE customers.address END, archived=0");$saveCustomer->execute([$name,$phone,$address,$created]);
   $insertItem=$db->prepare('INSERT INTO items(order_id,name,price,cost,presentation,presentation_cost,base_price,discount,quantity) VALUES (?,?,?,?,?,?,?,?,?)');
   foreach($lines as $line)$insertItem->execute([$orderId,$line['product']['name'],$line['price'],$line['cost'],$line['presentation'],$line['presentation_cost'],$line['base_price'],$line['discount'],$line['qty']]);
   $db->commit();$syncError=syncOrderToSheet($db,$orderId);
  }

  if($action==='profit_settings'){
   $penCost=postedMoneyPence($_POST['pen_cost']??'','pen cost');saveSetting($db,'pen_cost_pence',(string)$penCost);
   $db->prepare("UPDATE products SET cost=? WHERE lower(trim(name))='pen'")->execute([$penCost]);
   $db->prepare("UPDATE items SET cost=? WHERE cost IS NULL AND lower(trim(name))='pen'")->execute([$penCost]);
   $db->prepare("UPDATE items SET presentation_cost=? WHERE presentation='Pen' AND presentation_cost=0")->execute([$penCost]);
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
function assigneeClass(string $name):string{return in_array($name,['James','Tony'],true)?'assignee-'.strtolower($name):'assignee-unassigned';}
$view=in_array($_GET['view']??'', ['dashboard','orders','new','edit','products','customers','sheets','reports','more','saved'],true)?$_GET['view']:'dashboard';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#101112"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>ANKH • Order desk</title><link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23101112'/%3E%3Ctext x='6' y='26' font-size='28' fill='%23dfb666'%3E☥%3C/text%3E%3C/svg%3E"><link rel="stylesheet" href="style.css?v=mobile43"></head><body>
<?php if($pinSetupAuthorized): ?>
<main class="login"><div class="mark">☥</div><p class="eyebrow">ANKH / SECURE SETUP</p><h1>Create your 4-digit PIN.</h1><p class="muted">This PIN will protect ANKH Admin. Once saved, this setup link stops working and Voice Order can activate.</p><?php if($error):?><p role="alert" class="error"><?=e($error)?></p><?php endif;?>
<form method="post" action="?setup_pin=<?=e($pinSetupToken)?>"><?php csrf();?><input type="hidden" name="action" value="create_admin_pin"><input type="hidden" name="setup_pin" value="<?=e($pinSetupToken)?>"><label>New 4-digit PIN<input type="password" name="pin" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" required autocomplete="new-password"></label><label>Confirm PIN<input type="password" name="confirm_pin" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" required autocomplete="new-password"></label><button>Save PIN &amp; secure app →</button></form></main>
<?php elseif(!$auth): ?>
<main class="login"><div class="mark">☥</div><p class="eyebrow">ANKH / PRIVATE ACCESS</p><h1>Enter your PIN.</h1><p class="muted">Use your 4-digit ANKH Admin PIN.</p><?php if($error):?><p role="alert" class="error"><?=e($error)?></p><?php endif;?>
<form method="post"><?php csrf();?><input type="hidden" name="action" value="login"><label>4-digit PIN<input type="password" name="password" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" required autocomplete="current-password"></label><button>Sign in →</button></form></main>
<?php else:
$products=$db->query('SELECT * FROM products ORDER BY active DESC,name')->fetchAll(PDO::FETCH_ASSOC);
$orders=$db->query('SELECT o.*,COALESCE(SUM(i.price*i.quantity),0)+COALESCE(o.delivery_charge,0) AS total FROM orders o LEFT JOIN items i ON i.order_id=o.id GROUP BY o.id ORDER BY datetime(o.created) DESC,o.id DESC')->fetchAll(PDO::FETCH_ASSOC);
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

$newOrderCustomer=null;$repeatOrderData=null;$repeatPaymentMethod='';$repeatDeliveryMethod='';$repeatTrackingReference='';$repeatDeliveryCharge=0;$repeatPostageCost=0;$repeatPaymentFee=0;$repeatLines=[];
$editOrder=null;$editLines=[];

$productCatalogForJs=[];
foreach($products as $catalogProduct){
 $baseName=(string)$catalogProduct['name'];$strength='';
 if(preg_match('/\s+(\d+(?:\.\d+)?\s*(?:mg|ml|iu))$/i',$baseName,$pm)){$strength=$pm[1];$baseName=trim(substr($baseName,0,-strlen($pm[0])));}
 $productCatalogForJs[]=['id'=>(int)$catalogProduct['id'],'name'=>(string)$catalogProduct['name'],'base'=>$baseName,'strength'=>$strength,'price'=>(int)$catalogProduct['price']/100,'active'=>(int)$catalogProduct['active']===1];
}

if($view==='new' && (int)($_GET['customer_id']??0)>0){
 $q=$db->prepare('SELECT * FROM customers WHERE id=? AND archived=0');$q->execute([(int)$_GET['customer_id']]);$newOrderCustomer=$q->fetch(PDO::FETCH_ASSOC)?:null;
}
if($view==='new' && (int)($_GET['repeat_order']??0)>0){
 $repeatId=(int)$_GET['repeat_order'];$q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$repeatId]);$repeatOrder=$q->fetch(PDO::FETCH_ASSOC);
 if($repeatOrder){
  $repeatOrderData=$repeatOrder;$newOrderCustomer=['name'=>$repeatOrder['customer'],'phone'=>$repeatOrder['phone'],'address'=>$repeatOrder['address']];
  $repeatPaymentMethod=(string)($repeatOrder['payment_method']??'');$repeatDeliveryMethod=(string)($repeatOrder['delivery_method']??'');$repeatTrackingReference=(string)($repeatOrder['tracking_reference']??'');$repeatDeliveryCharge=(int)($repeatOrder['delivery_charge']??0);$repeatPostageCost=(int)($repeatOrder['postage_cost']??0);$repeatPaymentFee=(int)($repeatOrder['payment_fee']??0);
  $nameToProduct=[];foreach($products as $rp)if((int)$rp['active']===1)$nameToProduct[strtolower(trim((string)$rp['name']))]=$rp;
  $q=$db->prepare('SELECT name,price,base_price,discount,presentation,quantity FROM items WHERE order_id=? ORDER BY id');$q->execute([$repeatId]);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $ri){
   $isStandalonePen=strtolower(trim((string)$ri['name']))==='pen';
   if($isStandalonePen && $ri['base_price']===null)continue;
   $p=$nameToProduct[strtolower(trim((string)$ri['name']))]??null;if(!$p)continue;
   $format=$isStandalonePen?'':(in_array((string)$ri['presentation'],['Pen','Cartridge','Vial'],true)?(string)$ri['presentation']:(in_array((string)($repeatOrder['presentation']??''),['Pen','Cartridge','Vial'],true)?(string)$repeatOrder['presentation']:'Vial'));
   $base=$ri['base_price']===null?(int)$p['price']:(int)$ri['base_price'];$discount=!$isStandalonePen&&(int)($ri['discount']??0)>0;
   $repeatLines[]=['product_id'=>(int)$p['id'],'name'=>(string)$p['name'],'quantity'=>(int)$ri['quantity'],'presentation'=>$format,'base_price'=>$base/100,'discount'=>$discount,'price'=>$isStandalonePen?(int)$ri['price']/100:max(0,$base-($discount?500:0)+($format==='Pen'?2000:0))/100];
  }
 }
}
if($view==='edit' && (int)($_GET['id']??0)>0){
 $editId=(int)$_GET['id'];$q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$editId]);$editOrder=$q->fetch(PDO::FETCH_ASSOC)?:null;
 if($editOrder){
  $nameToProduct=[];foreach($products as $ep)$nameToProduct[strtolower(trim((string)$ep['name']))]=$ep;
  $q=$db->prepare('SELECT name,price,base_price,discount,presentation,quantity FROM items WHERE order_id=? ORDER BY id');$q->execute([$editId]);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $ei){
   $isStandalonePen=strtolower(trim((string)$ei['name']))==='pen';
   if($isStandalonePen && $ei['base_price']===null)continue;
   $p=$nameToProduct[strtolower(trim((string)$ei['name']))]??null;if(!$p)continue;
   $hasLineFormat=!$isStandalonePen&&in_array((string)$ei['presentation'],['Pen','Cartridge','Vial'],true);
   $format=$isStandalonePen?'':($hasLineFormat?(string)$ei['presentation']:(in_array((string)($editOrder['presentation']??''),['Pen','Cartridge','Vial'],true)?(string)$editOrder['presentation']:'Vial'));
   $base=$ei['base_price']===null?(int)$p['price']:(int)$ei['base_price'];$discount=!$isStandalonePen&&(int)($ei['discount']??0)>0;
   // Legacy orders stored the £20 Pen charge as a separate item. Rebuild that charge
   // into the peptide line when editing so it cannot disappear.
   $editPrice=$isStandalonePen?(int)$ei['price']:($hasLineFormat?(int)$ei['price']:max(0,$base-($discount?500:0)+($format==='Pen'?2000:0)));
   $editLines[]=['product_id'=>(int)$p['id'],'name'=>(string)$p['name'],'quantity'=>(int)$ei['quantity'],'presentation'=>$format,'base_price'=>$base/100,'discount'=>$discount,'price'=>$editPrice/100];
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

// Dashboard figures use paid/packed/dispatched/delivered orders as completed sales.
$tz=new DateTimeZone('Europe/London');$now=new DateTimeImmutable('now',$tz);
$todayStart=$now->setTime(0,0)->getTimestamp();$weekStart=$now->modify('monday this week')->setTime(0,0)->getTimestamp();$monthStart=$now->modify('first day of this month')->setTime(0,0)->getTimestamp();
$todaySales=0;$monthSales=0;$unpaidBalance=0;
foreach($orders as $dashboardOrder){
 $salesTs=strtotime((string)($dashboardOrder['payment_date']?:$dashboardOrder['created']))?:0;
 if(in_array($dashboardOrder['status'],$paidStatuses,true)){
  if($salesTs>=$todayStart)$todaySales+=(int)$dashboardOrder['total'];
  if($salesTs>=$monthStart)$monthSales+=(int)$dashboardOrder['total'];
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
 $q=$db->prepare("SELECT order_id,name,quantity,price,presentation,discount FROM items WHERE order_id IN ($placeholders) ORDER BY id");
 $q->execute($todoOrderIds);
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $todoItem)$todoItems[(int)$todoItem['order_id']][]=$todoItem;
}
$grossProfit=0;$uncostedSales=0;$topSelling=[];$orderCostById=[];$orderPenCostById=[];$orderMissingCost=[];$productProfit=[];$penCostAll=0;
$dashboardItems=$db->query("SELECT i.order_id,i.name,i.price,i.cost,i.presentation,i.presentation_cost,i.quantity,o.status FROM items i JOIN orders o ON o.id=i.order_id WHERE o.status IN ('Paid','Packed','Dispatched','Delivered')")->fetchAll(PDO::FETCH_ASSOC);
foreach($dashboardItems as $dashboardItem){
 $orderId=(int)$dashboardItem['order_id'];$qty=(int)$dashboardItem['quantity'];$line=(int)$dashboardItem['price']*$qty;$cost=$dashboardItem['cost']===null?null:(int)$dashboardItem['cost'];$presentationCost=(int)($dashboardItem['presentation_cost']??0);$name=(string)$dashboardItem['name'];
 if(strtolower(trim($name))==='pen'){
  if($cost!==null){$standalonePenCost=$cost*$qty;$orderCostById[$orderId]=($orderCostById[$orderId]??0)+$standalonePenCost;$orderPenCostById[$orderId]=($orderPenCostById[$orderId]??0)+$standalonePenCost;$penCostAll+=$standalonePenCost;}else{$uncostedSales+=$line;$orderMissingCost[$orderId]=true;}
  $topSelling['Pen']??=['qty'=>0,'revenue'=>0];$topSelling['Pen']['qty']+=$qty;$topSelling['Pen']['revenue']+=$line;
  $productProfit['Pen']??=['units'=>0,'revenue'=>0,'cogs'=>0,'missing_cost'=>false];
  $productProfit['Pen']['units']+=$qty;$productProfit['Pen']['revenue']+=$line;
  if($cost!==null)$productProfit['Pen']['cogs']+=$cost*$qty;else$productProfit['Pen']['missing_cost']=true;
  continue;
 }
 if($cost!==null)$orderCostById[$orderId]=($orderCostById[$orderId]??0)+$cost*$qty;else{$uncostedSales+=$line;$orderMissingCost[$orderId]=true;}
 $penRevenue=0;
 if($presentationCost>0){
  $penCostLine=$presentationCost*$qty;$orderCostById[$orderId]=($orderCostById[$orderId]??0)+$penCostLine;$orderPenCostById[$orderId]=($orderPenCostById[$orderId]??0)+$penCostLine;$penCostAll+=$penCostLine;
  if((string)$dashboardItem['presentation']==='Pen')$penRevenue=2000*$qty;
  $topSelling['Pen']??=['qty'=>0,'revenue'=>0];$topSelling['Pen']['qty']+=$qty;$topSelling['Pen']['revenue']+=$penRevenue;
  $productProfit['Pen']??=['units'=>0,'revenue'=>0,'cogs'=>0,'missing_cost'=>false];$productProfit['Pen']['units']+=$qty;$productProfit['Pen']['revenue']+=$penRevenue;$productProfit['Pen']['cogs']+=$penCostLine;
 }
 $peptideRevenue=max(0,$line-$penRevenue);
 $topSelling[$name]??=['qty'=>0,'revenue'=>0];$topSelling[$name]['qty']+=$qty;$topSelling[$name]['revenue']+=$peptideRevenue;
 $productProfit[$name]??=['units'=>0,'revenue'=>0,'cogs'=>0,'missing_cost'=>false];
 $productProfit[$name]['units']+=$qty;$productProfit[$name]['revenue']+=$peptideRevenue;
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
<article><span>This month's sales</span><strong><?=money($monthSales)?></strong><small><?=e($now->format('F Y'))?></small></article>
<article><span>Gross profit</span><strong><?=money($grossProfit)?></strong><small>After product &amp; delivery costs</small></article>
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
<div class="todo-accordion-main"><h3><?=e($todo['customer'])?></h3><div class="todo-accordion-products"><?php foreach($items as $item):?><span><?=e($item['quantity'].' × '.$item['name'].(!empty($item['presentation'])?' · '.$item['presentation']:'').((int)($item['discount']??0)>0?' · F&F':''))?></span><?php endforeach;?></div></div>
<div class="todo-accordion-side"><strong><?=money($todo['total'])?></strong><span class="todo-chevron" aria-hidden="true">⌄</span></div>
</summary>
<div class="todo-accordion-body">
<div class="todo-detail-strip"><span>ANK-<?=str_pad((string)$todo['id'],4,'0',STR_PAD_LEFT)?></span><span><?=e(date('d M Y',strtotime($todo['created'])))?></span></div>
<form method="post" class="todo-action"><?php csrf();?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$todo['id']?>"><input type="hidden" name="status" value="Paid"><input type="hidden" name="return" value="dashboard"><label class="todo-date">Payment method<select name="payment_method" required><option value="">Choose method</option><?php foreach($paymentMethods as $method):?><option value="<?=e($method)?>" <?=$todo['payment_method']===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label><label class="todo-date">Payment date<input type="date" name="payment_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($todo['payment_date']?:$now->format('Y-m-d'))?>" required></label><button>✓ Payment received</button></form>
</div>
</details>
<?php endforeach;?></div><?php endif;?>

<?php if($awaitingDelivery):?><div class="todo-column">
<div class="todo-column-title"><div><span class="todo-icon">✓</span><div><h3>Awaiting delivery</h3><small>Paid orders to complete</small></div></div><strong><?=count($awaitingDelivery)?></strong></div>
<?php foreach($awaitingDelivery as $todo):$items=$todoItems[(int)$todo['id']]??[];?>
<details class="todo-card todo-accordion">
<summary class="todo-accordion-summary">
<div class="todo-accordion-main"><h3><?=e($todo['customer'])?></h3><div class="todo-accordion-products"><?php foreach($items as $item):?><span><?=e($item['quantity'].' × '.$item['name'].(!empty($item['presentation'])?' · '.$item['presentation']:'').((int)($item['discount']??0)>0?' · F&F':''))?></span><?php endforeach;?></div></div>
<div class="todo-accordion-side"><?php if(!empty($todo['assigned_to'])):?><span class="delivery-assignee assigned <?=e(assigneeClass($todo['assigned_to']))?>"><?=e($todo['assigned_to'])?></span><?php else:?><span class="delivery-assignee assignee-unassigned">Unassigned</span><?php endif;?><span class="todo-chevron" aria-hidden="true">⌄</span></div>
</summary>
<div class="todo-accordion-body">
<div class="todo-detail-strip"><span>ANK-<?=str_pad((string)$todo['id'],4,'0',STR_PAD_LEFT)?></span><span><?=e(date('d M Y',strtotime($todo['created'])))?></span><?php if(!empty($todo['delivery_method'])):?><span><?=e($todo['delivery_method'])?></span><?php endif;?></div>

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
<?php foreach($orders as $o):?><details class="order compact-order" data-search="<?=e(strtolower($o['customer'].' '.$o['phone'].' '.($o['referrer']??'').' '.($o['assigned_to']??'').' ANK-'.$o['id']))?>" data-status="<?=e($o['status'])?>"><summary><div><div class="order-ref-row"><?php if(!empty($o['assigned_to'])):?><span class="delivery-assignee assigned <?=e(assigneeClass($o['assigned_to']))?>"><?=e($o['assigned_to'])?></span><?php else:?><span class="delivery-assignee assignee-unassigned">Unassigned</span><?php endif;?><span class="ref">ANK-<?=str_pad((string)$o['id'],4,'0',STR_PAD_LEFT)?></span></div><h2><?=e($o['customer'])?></h2><span class="muted"><?=e(date('d M Y',strtotime($o['created'])))?></span></div><div class="order-right"><div class="order-summary-badges"><span class="badge status-<?=e(statusClass($o['status']))?>"><?=e($o['status'])?></span></div><strong><?=money($o['total'])?></strong></div></summary><div class="detail">
<div class="order-quick-actions"><a class="quick-action edit-action" href="?view=edit&amp;id=<?=$o['id']?>">Edit order</a><?php if(trim((string)$o['phone'])!==''):?><a class="quick-action" href="tel:<?=e(preg_replace('/[^0-9+]/','',(string)$o['phone']))?>">Call</a><?php endif;?><?php if(trim((string)$o['address'])!==''):?><button type="button" class="quick-action quiet" data-copy-text="<?=e($o['address'])?>">Copy address</button><?php endif;?><a class="quick-action" href="?view=new&amp;repeat_order=<?=$o['id']?>">Repeat</a></div>
<div class="order-meta"><span><b>Order</b><em><?=e(date('d M Y',strtotime($o['created'])))?></em></span><?php if($o['payment_date']):?><span><b>Paid</b><em><?=e(date('d M Y',strtotime($o['payment_date'])))?></em></span><?php endif;?><?php if($o['delivery_date']):?><span><b>Delivered</b><em><?=e(date('d M Y',strtotime($o['delivery_date'])))?></em></span><?php endif;?><?php if($o['payment_method']):?><span><b>Payment</b><em><?=e($o['payment_method'])?></em></span><?php endif;?><?php if($o['delivery_method']):?><span><b>Delivery</b><em><?=e($o['delivery_method'])?></em></span><?php endif;?><?php if($o['assigned_to']):?><span><b>Assigned</b><em><?=e($o['assigned_to'])?></em></span><?php endif;?></div>
<?php if($o['address']):?><p class="address"><?=nl2br(e($o['address']))?></p><?php endif;?><?php if($o['tracking_reference']):?><p class="note"><strong>Tracking:</strong> <?=e($o['tracking_reference'])?></p><?php endif;?>
<?php $q=$db->prepare('SELECT * FROM items WHERE order_id=?');$q->execute([$o['id']]);foreach($q as $i):?><div class="line"><span><?=e($i['quantity'].' × '.$i['name'].(!empty($i['presentation'])?' · '.$i['presentation']:'').((int)($i['discount']??0)>0?' · Family & Friends':'').' @ '.money($i['price']))?></span><strong><?=money($i['price']*$i['quantity'])?></strong></div><?php endforeach;?>
<?php if((int)$o['delivery_charge']>0):?><div class="line"><span>Postage / delivery charge</span><strong><?=money($o['delivery_charge'])?></strong></div><?php endif;?><?php if($o['notes']):?><p class="note"><?=nl2br(e($o['notes']))?></p><?php endif;?>
<details class="order-date-editor"><summary>Edit payment / delivery dates</summary><form method="post" class="order-dates-form"><?php csrf();?><input type="hidden" name="action" value="order_dates"><input type="hidden" name="return" value="orders"><input type="hidden" name="id" value="<?=$o['id']?>"><div class="two"><label>Payment date<input type="date" name="payment_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($o['payment_date']??'')?>"></label><label>Delivery date<input type="date" name="delivery_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($o['delivery_date']??'')?>"></label></div><button>Save dates</button></form></details>
<form method="post" class="status-form compact-status-form"><?php csrf();?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$o['id']?>"><div class="status-fields"><label>Status<select name="status"><?php foreach($statuses as $statusOption):?><option <?=$statusOption===$o['status']?'selected':''?>><?=e($statusOption)?></option><?php endforeach;?></select></label><label>Payment method<select name="payment_method"><option value="">Not set</option><?php foreach($paymentMethods as $method):?><option value="<?=e($method)?>" <?=$o['payment_method']===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label></div><button>Save</button></form>
<form method="post" class="delete-order-form" onsubmit="return confirm('Delete ANK-<?=str_pad((string)$o['id'],4,'0',STR_PAD_LEFT)?>? This permanently removes the order and its items.');"><?php csrf();?><input type="hidden" name="action" value="order_delete"><input type="hidden" name="id" value="<?=$o['id']?>"><input type="hidden" name="return" value="orders"><button class="quiet danger-button">Delete order</button></form></div></details><?php endforeach;?></div>
<p id="empty" class="empty" <?=count($orders)?'hidden':''?>>No orders to show.</p>
<?php elseif($view==='new'):?>
<div class="new-order-head"><div><h1><?=$repeatOrderData?'Repeat order':'New order'?></h1><?php if($repeatOrderData):?><p class="muted page-description">Based on ANK-<?=str_pad((string)$repeatOrderData['id'],4,'0',STR_PAD_LEFT)?>. Check anything that has changed.</p><?php endif;?></div><div class="new-order-tools"><button type="button" id="clear-draft" class="quiet draft-clear" hidden>Clear draft</button></div><div class="wizard-progress" aria-label="Order progress"><span class="active" data-progress-step="1">1<span>Customer</span></span><i></i><span data-progress-step="2">2<span>Products</span></span><i></i><span data-progress-step="3">3<span>Save</span></span></div></div>
<section class="voice-order-launch">
 <div class="voice-order-launch-copy">
  <span class="voice-order-launch-kicker">AI VOICE ORDER</span>
  <h2>Add your order with your voice</h2>
  <p>Tap the microphone and say the customer’s name, product and strength, quantity, Pen / Cartridge / Vial, delivery method and James or Tony if it’s assigned.</p>
  <div class="voice-order-example"><span>Try saying</span><q>Sarah Jones, two Reta 10mg pens, local delivery, assign to James.</q></div><div class="voice-order-alias">You can say <strong>“Reta”</strong> for Retatrutide.</div>
 </div>
 <button type="button" id="voice-order-button" class="voice-order-launch-button" data-ready="<?=$voiceOrderReady?'1':'0'?>" aria-label="Start a voice order">
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
<div class="two"><div class="customer-search-wrap"><label>Customer name<input id="customer-search" name="customer" maxlength="160" required autocomplete="off" placeholder="Start typing name or phone…" value="<?=e($_POST['customer']??($newOrderCustomer['name']??''))?>"></label><div id="customer-results" class="customer-results" role="listbox" hidden></div></div><label>Phone<input id="customer-phone" name="phone" maxlength="40" type="tel" autocomplete="tel" value="<?=e($_POST['phone']??($newOrderCustomer['phone']??''))?>"></label></div>
<label>Referrer <span class="muted">(optional)</span><input id="customer-referrer" name="referrer" maxlength="160" list="referrer-list" placeholder="Who sent them to us?" value="<?=e($_POST['referrer']??'')?>"></label><datalist id="referrer-list"><?php foreach($referrers as $r):?><option value="<?=e($r)?>"><?php endforeach;?></datalist>
<label>Delivery address<textarea id="customer-address" name="address" maxlength="2000" autocomplete="street-address"><?=e($_POST['address']??($newOrderCustomer['address']??''))?></textarea></label>
<label class="order-date-field">Order date<input id="order-date" name="order_date" type="date" required max="<?=e($now->format('Y-m-d'))?>" value="<?=e($_POST['order_date']??$now->format('Y-m-d'))?>"></label>
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
<div class="order-check-section"><span class="order-check-label">Customer</span><strong id="check-customer">—</strong><small id="check-customer-detail"></small></div>
<div class="order-check-section"><span class="order-check-label">Order date</span><strong id="check-order-date">—</strong></div>
<div class="order-check-section"><span class="order-check-label">Delivery</span><strong id="check-delivery">—</strong><small id="check-delivery-detail"></small></div>
<div class="order-check-section"><span class="order-check-label">Products</span><div id="check-products"></div></div>
<div class="line order-check-total"><strong>Total</strong><strong id="subtotal">£0.00</strong></div>
</div>
<div class="order-subsection"><span class="order-check-label">Payment</span><label>Payment method <span class="muted">(optional until paid)</span><select id="payment-method" name="payment_method"><option value="">Not set yet</option><?php $selectedPayment=$_POST['payment_method']??$repeatPaymentMethod;foreach($paymentMethods as $method):?><option value="<?=e($method)?>" <?=$selectedPayment===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label></div>
<label>Notes <span class="muted">(optional)</span><textarea name="notes" maxlength="4000" placeholder="Delivery instructions, payment reference…"><?=e($_POST['notes']??'')?></textarea></label>
<div class="wizard-actions"><button type="button" class="quiet wizard-back" data-wizard-back="2">← Back</button><button class="save-order">Save order</button></div>
</section>
</form>

<?php elseif($view==='edit'):?>
<?php if(!$editOrder):?><div class="heading"><div><h1>Edit order</h1></div><a class="quick-action" href="?view=orders">← Orders</a></div><p class="error">That order could not be found.</p>
<?php else:?>
<div class="heading"><div><h1>Edit ANK-<?=str_pad((string)$editOrder['id'],4,'0',STR_PAD_LEFT)?></h1><p class="muted page-description">Correct the customer, products, formats, prices, payment or delivery details.</p></div><a class="quick-action" href="?view=orders">Cancel</a></div>
<form method="post" class="panel edit-order-form" id="edit-order-form"><?php csrf();?><input type="hidden" name="action" value="order_edit"><input type="hidden" name="return" value="orders"><input type="hidden" name="id" value="<?=$editOrder['id']?>">
<div class="edit-section"><span class="wizard-kicker">CUSTOMER & DATE</span><div class="two"><div class="customer-search-wrap"><label>Customer name<input id="customer-search" name="customer" maxlength="160" required autocomplete="off" value="<?=e($editOrder['customer'])?>"></label><div id="customer-results" class="customer-results" role="listbox" hidden></div></div><label>Phone<input id="customer-phone" name="phone" maxlength="40" type="tel" value="<?=e($editOrder['phone'])?>"></label></div><label>Referrer <span class="muted">(optional)</span><input id="customer-referrer" name="referrer" maxlength="160" value="<?=e($editOrder['referrer']??'')?>"></label><label>Address<textarea id="customer-address" name="address" maxlength="2000"><?=e($editOrder['address'])?></textarea></label><label>Order date<input id="order-date" name="order_date" type="date" required max="<?=e($now->format('Y-m-d'))?>" value="<?=e(date('Y-m-d',strtotime($editOrder['created'])))?>"></label></div>

<div class="edit-section"><span class="wizard-kicker">PRODUCTS</span><div class="product-search-wrap"><label for="product-search">Add product<input id="product-search" type="search" placeholder="Search products…" autocomplete="off" aria-autocomplete="list" aria-controls="product-results"></label><div id="product-results" class="product-results" role="listbox" hidden></div></div><div id="strength-picker" class="strength-picker" hidden><div class="strength-picker-head"><div><span class="muted">Choose strength</span><strong id="strength-product-name"></strong></div><button type="button" id="close-strength-picker" class="strength-close" aria-label="Close strength choices">×</button></div><div id="strength-options" class="strength-options"></div></div><div id="selected-order-lines" class="selected-order-lines"></div><p id="selected-empty" class="selected-empty">No products added yet.</p></div>

<div class="edit-section"><span class="wizard-kicker">DELIVERY</span><label>Delivery method<select id="delivery-method" name="delivery_method" required><?php $editDeliveryMethod=$editOrder['delivery_method']?:'Local Delivery';foreach($deliveryMethods as $method):?><option value="<?=e($method)?>" <?=$editDeliveryMethod===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label><label>Assigned to<select id="assigned-to" name="assigned_to"><option value="">Unassigned</option><?php foreach($deliveryAssignees as $assignee):?><option value="<?=e($assignee)?>" <?=$editOrder['assigned_to']===$assignee?'selected':''?>><?=e($assignee)?></option><?php endforeach;?></select></label><div id="postage-fields" class="postage-fields" hidden><label>Tracking / reference<input id="tracking-reference" name="tracking_reference" maxlength="200" value="<?=e($editOrder['tracking_reference']??'')?>"></label><div class="two"><label>Postage charged (£)<input id="delivery-charge" name="delivery_charge" type="number" min="0" step=".01" value="<?=e(number_format((int)$editOrder['delivery_charge']/100,2,'.',''))?>"></label><label>Actual postage cost (£)<input id="postage-cost" name="postage_cost" type="number" min="0" step=".01" value="<?=e(number_format((int)$editOrder['postage_cost']/100,2,'.',''))?>"></label></div></div></div>

<div class="edit-section"><span class="wizard-kicker">PAYMENT & NOTES</span><label>Payment method<select id="payment-method" name="payment_method"><option value="">Not set</option><?php foreach($paymentMethods as $method):?><option value="<?=e($method)?>" <?=$editOrder['payment_method']===$method?'selected':''?>><?=e($method)?></option><?php endforeach;?></select></label><label>Notes<textarea name="notes" maxlength="4000"><?=e($editOrder['notes'])?></textarea></label></div>
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
<div class="heading"><div><h1>Profit & reports</h1><p class="muted page-description">Paid sales less product costs, pen costs and postage.</p></div><a class="quick-action" href="?view=more">← More</a></div>
<div class="report-periods">
<?php foreach(['today','week','month','all'] as $periodKey):$period=$reportPeriods[$periodKey];?><a class="report-period <?=$reportPeriodKey===$periodKey?'selected':''?>" href="?view=reports&amp;period=<?=e($periodKey)?>"><span><?=e($period['label'])?></span><strong><small>Profit</small><?=money($period['profit'])?></strong><small><b>Sales <?=money($period['revenue'])?></b> · <?=$period['orders']?> orders</small></a><?php endforeach;?>
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
<div class="saved-items"><?php foreach($savedOrder['items'] as $savedItem):?><div class="line"><span><?=e($savedItem['quantity'].' × '.$savedItem['name'].(!empty($savedItem['presentation'])?' · '.$savedItem['presentation']:'').((int)($savedItem['discount_pence']??0)>0?' · Family & Friends':''))?></span><strong><?=money($savedItem['unit_price_pence']*$savedItem['quantity'])?></strong></div><?php endforeach;?><?php if((int)$savedOrder['delivery_charge_pence']>0):?><div class="line"><span>Postage / delivery charge</span><strong><?=money($savedOrder['delivery_charge_pence'])?></strong></div><?php endif;?></div><div class="saved-meta"><?php if($savedOrder['payment_method']):?><span>Payment: <?=e($savedOrder['payment_method'])?></span><?php endif;?><?php if($savedOrder['delivery_method']):?><span>Delivery: <?=e($savedOrder['delivery_method'])?></span><?php endif;?><?php if($savedOrder['assigned_to']):?><span>Assigned: <?=e($savedOrder['assigned_to'])?></span><?php endif;?></div>
<div class="saved-actions"><a class="button" href="?view=dashboard">Dashboard</a><a class="button" href="?view=new">+ New order</a><a class="quick-action" href="?view=orders">View orders</a><a class="quick-action" href="?view=new&amp;repeat_order=<?=$savedOrder['id']?>">Repeat order</a></div>
</section>
<?php else:?><p class="error">That saved order could not be found.</p><a class="button" href="?view=orders">Back to orders</a><?php endif;?>
<?php endif;?>
</main>
<button type="button" id="ankh-assistant-button" class="ankh-assistant-button" aria-label="Talk to ANKH Assistant">
 <span class="assistant-button-ring" aria-hidden="true"></span><span class="assistant-button-icon" aria-hidden="true">🎙</span><span class="assistant-button-label">Talk to ANKH</span>
</button>
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
const orderDraftKey='ankh-order-draft-v2';
if(document.querySelector('#order-saved-marker')){try{localStorage.removeItem(orderDraftKey)}catch(_){}}
document.querySelectorAll('[data-copy-text]').forEach(button=>button.addEventListener('click',async()=>{const value=button.dataset.copyText||'';try{await navigator.clipboard.writeText(value);const old=button.textContent;button.textContent='Copied ✓';setTimeout(()=>button.textContent=old,1200)}catch(_){const area=document.createElement('textarea');area.value=value;document.body.append(area);area.select();document.execCommand('copy');area.remove()}}));

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
const customerPageSearch=document.querySelector('#customer-page-search'),customerStatusFilter=document.querySelector('#customer-status-filter'),customerCards=[...document.querySelectorAll('[data-customer-search]')],customerPageEmpty=document.querySelector('#customer-page-empty');
function filterCustomerPage(){if(!customerCards.length)return;const term=(customerPageSearch?.value||'').toLowerCase().trim(),mode=customerStatusFilter?.value||'active';let visible=0;customerCards.forEach(card=>{const archived=card.dataset.customerArchived==='1',statusOk=mode==='all'||(mode==='archived'?archived:!archived),searchOk=!term||card.dataset.customerSearch.includes(term);card.hidden=!(statusOk&&searchOk);if(!card.hidden)visible++});if(customerPageEmpty)customerPageEmpty.hidden=visible>0}
customerPageSearch?.addEventListener('input',filterCustomerPage);customerStatusFilter?.addEventListener('change',filterCustomerPage);filterCustomerPage();

const wizard=document.querySelector('#order-wizard'),wizardSteps=[...document.querySelectorAll('[data-wizard-step]')],wizardProgress=[...document.querySelectorAll('[data-progress-step]')],clearDraftButton=document.querySelector('#clear-draft');
const orderForm=document.querySelector('#order-wizard,#edit-order-form');
const customerSuggestions=<?=json_encode($customerSuggestions,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>;
const productCatalog=<?=json_encode($productCatalogForJs,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>;
const initialOrderLines=<?=json_encode($view==='edit'?$editLines:$repeatLines,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>;
const customerSearch=document.querySelector('#customer-search'),customerResults=document.querySelector('#customer-results'),customerPhone=document.querySelector('#customer-phone'),customerAddress=document.querySelector('#customer-address');
const productSearch=document.querySelector('#product-search'),productSearchWrap=productSearch?.closest('.product-search-wrap'),productResults=document.querySelector('#product-results'),strengthPicker=document.querySelector('#strength-picker'),strengthOptions=document.querySelector('#strength-options'),strengthProductName=document.querySelector('#strength-product-name'),closeStrengthPicker=document.querySelector('#close-strength-picker'),selectedLinesContainer=document.querySelector('#selected-order-lines'),selectedEmpty=document.querySelector('#selected-empty'),addAnotherButton=document.querySelector('#add-another-product');
const deliveryMethodSelect=document.querySelector('#delivery-method'),assignedToSelect=document.querySelector('#assigned-to'),postageFields=document.querySelector('#postage-fields'),deliveryChargeInput=document.querySelector('#delivery-charge'),postageCostInput=document.querySelector('#postage-cost'),trackingReferenceInput=document.querySelector('#tracking-reference'),paymentMethodInput=document.querySelector('#payment-method');
const draftEnabled=wizard?.dataset.draftEnabled==='1';
let currentWizardStep=1,lineCounter=0,productSearchMoved=false;

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
  price:Number(card.querySelector('[data-line-price]')?.value||0)
 };
}
function calculatedLinePrice(card){
 const data=lineData(card);if(isStandalonePenCard(card))return Math.max(0,data.base_price);
 return Math.max(0,data.base_price-(data.discount?5:0)+(data.presentation==='Pen'?20:0));
}
function refreshLineVisuals(card){
 const data=lineData(card);
 card.querySelectorAll('[data-line-format]').forEach(button=>button.classList.toggle('selected',button.dataset.lineFormat===data.presentation));
 const family=card.querySelector('[data-family-line]');if(family){family.classList.toggle('selected',data.discount);family.setAttribute('aria-pressed',data.discount?'true':'false')}
 const priceOut=card.querySelector('[data-line-total]');if(priceOut)priceOut.textContent=moneyFormat(data.price*data.quantity);
}
function recalculateLinePrice(card){
 const price=card.querySelector('[data-line-price]');if(price)price.value=calculatedLinePrice(card).toFixed(2);refreshLineVisuals(card);updateTotal();
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
function addOrderLine(data={},scroll=true){
 if(!selectedLinesContainer)return null;
 const product=productCatalog.find(p=>Number(p.id)===Number(data.product_id));if(!product)return null;
 const key='l'+(++lineCounter)+'_'+Date.now().toString(36),standalonePen=(product.name||'').trim().toLowerCase()==='pen';
 const base=Number(data.base_price??product.price)||0,qty=Math.max(1,Number(data.quantity)||1),presentation=standalonePen?'':(data.presentation||''),discount=standalonePen?false:!!data.discount;
 const initialCalculated=standalonePen?base:Math.max(0,base-(discount?5:0)+(presentation==='Pen'?20:0));
 const price=data.price===undefined||data.price===null?initialCalculated:Number(data.price);

 const card=document.createElement('article');card.className='order-line-card';card.dataset.productName=product.name;card.dataset.productStrength=product.strength||'';
 const head=document.createElement('div');head.className='order-line-head';
 const titleWrap=document.createElement('div');const title=document.createElement('h3');title.textContent=product.name;const baseText=document.createElement('small');baseText.textContent=(standalonePen?'Standalone accessory ':'Product ')+moneyFormat(base);titleWrap.append(title,baseText);
 const remove=document.createElement('button');remove.type='button';remove.className='line-remove';remove.setAttribute('aria-label','Remove '+product.name);remove.textContent='×';head.append(titleWrap,remove);card.append(head);

 const hiddenProduct=document.createElement('input');hiddenProduct.type='hidden';hiddenProduct.name='lines['+key+'][product_id]';hiddenProduct.value=String(product.id);hiddenProduct.dataset.lineProduct='';
 const hiddenBase=document.createElement('input');hiddenBase.type='hidden';hiddenBase.name='lines['+key+'][base_price]';hiddenBase.value=base.toFixed(2);hiddenBase.dataset.lineBasePrice='';
 const hiddenPresentation=document.createElement('input');hiddenPresentation.type='hidden';hiddenPresentation.name='lines['+key+'][presentation]';hiddenPresentation.value=presentation;hiddenPresentation.dataset.linePresentation='';
 const hiddenDiscount=document.createElement('input');hiddenDiscount.type='hidden';hiddenDiscount.name='lines['+key+'][discount]';hiddenDiscount.value=discount?'1':'0';hiddenDiscount.dataset.lineDiscount='';
 card.append(hiddenProduct,hiddenBase,hiddenPresentation,hiddenDiscount);

 if(!standalonePen){
  const formatTitle=document.createElement('span');formatTitle.className='line-section-label';formatTitle.textContent='Choose format';
  const formats=document.createElement('div');formats.className='line-format-picker';
  [['Pen','▯'],['Cartridge','▤'],['Vial','◉']].forEach(([value,icon])=>{const button=createFormatButton(value,icon);button.addEventListener('click',()=>{hiddenPresentation.value=value;recalculateLinePrice(card);updateSelectedState();saveDraftOrder()});formats.append(button)});
  card.append(formatTitle,formats);
 }

 if(!standalonePen&&(product.strength||'').toLowerCase().endsWith('mg')){
  const family=document.createElement('button');family.type='button';family.className='family-discount line-family-discount';family.dataset.familyLine='';family.setAttribute('aria-pressed',discount?'true':'false');
  const familyLabel=document.createElement('span');familyLabel.textContent='Family & Friends';const familyValue=document.createElement('strong');familyValue.textContent='−£5';family.append(familyLabel,familyValue);
  family.addEventListener('click',()=>{hiddenDiscount.value=hiddenDiscount.value==='1'?'0':'1';recalculateLinePrice(card);saveDraftOrder()});card.append(family);
 }

 const controls=document.createElement('div');controls.className='line-controls';
 const qtyWrap=document.createElement('div');qtyWrap.className='line-quantity';const qtyLabel=document.createElement('span');qtyLabel.textContent='Quantity';
 const stepper=document.createElement('div');stepper.className='stepper';const minus=document.createElement('button');minus.type='button';minus.textContent='−';const qtyInput=document.createElement('input');qtyInput.type='number';qtyInput.min='1';qtyInput.max='999';qtyInput.inputMode='numeric';qtyInput.name='lines['+key+'][quantity]';qtyInput.value=String(qty);qtyInput.dataset.lineQuantity='';const plus=document.createElement('button');plus.type='button';plus.textContent='+';
 stepper.append(minus,qtyInput,plus);qtyWrap.append(qtyLabel,stepper);
 const priceLabel=document.createElement('label');priceLabel.className='line-price-field';priceLabel.textContent='Price each (£)';const priceInput=document.createElement('input');priceInput.type='number';priceInput.min='0';priceInput.max='100000';priceInput.step='.01';priceInput.inputMode='decimal';priceInput.name='lines['+key+'][price]';priceInput.value=(Number.isFinite(price)?price:initialCalculated).toFixed(2);priceInput.dataset.linePrice='';priceLabel.append(priceInput);
 controls.append(qtyWrap,priceLabel);card.append(controls);
 const lineTotal=document.createElement('div');lineTotal.className='line-card-total';lineTotal.innerHTML='<span>Line total</span><strong data-line-total></strong>';card.append(lineTotal);

 remove.addEventListener('click',()=>{card.remove();updateSelectedState();updateTotal();saveDraftOrder()});
 minus.addEventListener('click',()=>{qtyInput.value=String(Math.max(1,Number(qtyInput.value||1)-1));refreshLineVisuals(card);updateTotal();saveDraftOrder()});
 plus.addEventListener('click',()=>{qtyInput.value=String(Math.min(999,Number(qtyInput.value||1)+1));refreshLineVisuals(card);updateTotal();saveDraftOrder()});
 qtyInput.addEventListener('input',()=>{if(Number(qtyInput.value)<1)qtyInput.value='1';refreshLineVisuals(card);updateTotal();saveDraftOrder()});
 priceInput.addEventListener('input',()=>{refreshLineVisuals(card);updateTotal();saveDraftOrder()});

 selectedLinesContainer.append(card);refreshLineVisuals(card);updateSelectedState();updateTotal();
 if(scroll){productSearch?.blur();if(!productSearchMoved)setTimeout(()=>card.scrollIntoView({behavior:'smooth',block:'center'}),80);else setTimeout(()=>productSearchWrap?.scrollIntoView({behavior:'smooth',block:'center'}),80)}
 return card;
}

function updateTotal(){
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
 const customer=document.querySelector('#check-customer'),detail=document.querySelector('#check-customer-detail'),products=document.querySelector('#check-products'),orderDate=document.querySelector('#order-date'),checkOrderDate=document.querySelector('#check-order-date'),checkDelivery=document.querySelector('#check-delivery'),checkDeliveryDetail=document.querySelector('#check-delivery-detail');
 if(customer)customer.textContent=customerSearch?.value.trim()||'—';
 if(detail){const bits=[customerPhone?.value.trim(),document.querySelector('#customer-referrer')?.value.trim()].filter(Boolean);detail.textContent=bits.join(' · ')}
 if(checkOrderDate&&orderDate?.value){const d=new Date(orderDate.value+'T12:00:00');checkOrderDate.textContent=d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'})}
 if(checkDelivery)checkDelivery.textContent=deliveryMethodSelect?.value||'—';
 if(checkDeliveryDetail){const bits=[];if(deliveryMethodSelect?.value==='Postage'&&Number(deliveryChargeInput?.value||0)>0)bits.push('Postage charged £'+Number(deliveryChargeInput.value).toFixed(2));if(trackingReferenceInput?.value.trim())bits.push(trackingReferenceInput.value.trim());checkDeliveryDetail.textContent=bits.join(' · ')}
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
 if(next===2){if(!customerSearch?.value.trim()){customerSearch?.reportValidity();customerSearch?.focus();return}const orderDate=document.querySelector('#order-date');if(!orderDate?.value||!orderDate.checkValidity()){orderDate?.reportValidity();orderDate?.focus();return}if(!deliveryMethodSelect?.value){deliveryMethodSelect?.reportValidity();deliveryMethodSelect?.focus();return}}
 if(next===3&&!validateLines())return;showWizardStep(next);
}));
document.querySelectorAll('[data-wizard-back]').forEach(button=>button.addEventListener('click',()=>showWizardStep(Number(button.dataset.wizardBack))));

function chooseCustomer(customer){if(customerSearch)customerSearch.value=customer.name||'';if(customerPhone)customerPhone.value=customer.phone||'';if(customerAddress)customerAddress.value=customer.address||'';if(customerResults){customerResults.hidden=true;customerResults.replaceChildren()}customerSearch?.blur();saveDraftOrder()}
function showCustomerResults(){if(!customerSearch||!customerResults)return;const term=customerSearch.value.toLowerCase().trim();customerResults.replaceChildren();if(term.length<1){customerResults.hidden=true;return}const matches=customerSuggestions.filter(c=>(c.name||'').toLowerCase().includes(term)||(c.phone||'').toLowerCase().includes(term)).slice(0,8);if(!matches.length){customerResults.hidden=true;return}matches.forEach(c=>{const b=document.createElement('button');b.type='button';b.className='customer-result';b.setAttribute('role','option');const main=document.createElement('strong');main.textContent=c.name||'Customer';const info=document.createElement('span');info.textContent=c.phone||'';b.append(main,info);if(c.address){const addr=document.createElement('small');addr.textContent=c.address.replace(/\s+/g,' ').trim();b.append(addr)}b.addEventListener('click',()=>chooseCustomer(c));customerResults.append(b)});customerResults.hidden=false}
customerSearch?.addEventListener('input',showCustomerResults);customerSearch?.addEventListener('focus',showCustomerResults);
document.querySelectorAll('[data-recent-customer]').forEach(button=>button.addEventListener('click',()=>{const customer=customerSuggestions.find(c=>String(c.id)===button.dataset.recentCustomer);if(customer)chooseCustomer(customer)}));
document.addEventListener('click',e=>{if(customerResults&&!e.target.closest('.customer-search-wrap'))customerResults.hidden=true});

const search=document.querySelector('#search'),filter=document.querySelector('#filter');
function applyFilters(){if(!search||!filter)return;let visible=0;document.querySelectorAll('.order-list .order').forEach(o=>{o.hidden=!(o.dataset.search.includes(search.value.toLowerCase().trim())&&(!filter.value||o.dataset.status===filter.value));if(!o.hidden)visible++});const empty=document.querySelector('#empty');if(empty)empty.hidden=visible>0}
search?.addEventListener('input',applyFilters);filter?.addEventListener('change',applyFilters);

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
 const draft={step:currentWizardStep,customer:customerSearch?.value||'',phone:customerPhone?.value||'',address:customerAddress?.value||'',referrer:document.querySelector('#customer-referrer')?.value||'',order_date:document.querySelector('#order-date')?.value||'',delivery_method:deliveryMethodSelect?.value||'Local Delivery',assigned_to:assignedToSelect?.value||'',tracking_reference:trackingReferenceInput?.value||'',delivery_charge:deliveryChargeInput?.value||'',postage_cost:postageCostInput?.value||'',payment_method:paymentMethodInput?.value||'',notes:wizard.querySelector('textarea[name="notes"]')?.value||'',lines:serializeLines()};
 const useful=draft.customer||draft.phone||draft.address||draft.lines.length;try{if(useful){localStorage.setItem(orderDraftKey,JSON.stringify(draft));if(clearDraftButton)clearDraftButton.hidden=false}else{localStorage.removeItem(orderDraftKey);if(clearDraftButton)clearDraftButton.hidden=true}}catch(_){}
}
function restoreDraftOrder(){
 if(!draftEnabled||!wizard)return false;let draft=null;try{draft=JSON.parse(localStorage.getItem(orderDraftKey)||'null')}catch(_){}if(!draft)return false;
 if(customerSearch)customerSearch.value=draft.customer||'';if(customerPhone)customerPhone.value=draft.phone||'';if(customerAddress)customerAddress.value=draft.address||'';
 const ref=document.querySelector('#customer-referrer'),orderDate=document.querySelector('#order-date'),notes=wizard.querySelector('textarea[name="notes"]');if(ref)ref.value=draft.referrer||'';if(orderDate&&draft.order_date)orderDate.value=draft.order_date;if(notes)notes.value=draft.notes||'';
 if(deliveryMethodSelect)deliveryMethodSelect.value=draft.delivery_method||'Local Delivery';if(assignedToSelect)assignedToSelect.value=draft.assigned_to||'';if(trackingReferenceInput)trackingReferenceInput.value=draft.tracking_reference||'';if(deliveryChargeInput)deliveryChargeInput.value=draft.delivery_charge||'0.00';if(postageCostInput)postageCostInput.value=draft.postage_cost||'0.00';if(paymentMethodInput)paymentMethodInput.value=draft.payment_method||'';
 selectedLinesContainer?.replaceChildren();(draft.lines||[]).forEach(line=>addOrderLine(line,false));if(clearDraftButton)clearDraftButton.hidden=false;togglePostageFields();showWizardStep(Math.min(3,Math.max(1,Number(draft.step)||1)));return true;
}
clearDraftButton?.addEventListener('click',()=>{try{localStorage.removeItem(orderDraftKey)}catch(_){}location.href='?view=new'});

const restoredDraft=restoreDraftOrder();
if(!restoredDraft)initialOrderLines.forEach(line=>addOrderLine(line,false));
togglePostageFields();updateTotal();
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
</script><?php endif;?></body></html>
