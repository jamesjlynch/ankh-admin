<?php
declare(strict_types=1);
// Read-only fixture: low and out-of-stock products must surface on Stock, Today and New order.
$root=dirname(__DIR__);
$temp=sys_get_temp_dir().'/ankh-low-stock-'.bin2hex(random_bytes(6));
mkdir($temp,0700);
$config=$temp.'/config.php';
file_put_contents($config,'<?php $_SESSION["admin"]=true;$_SESSION["admin_user_id"]=1;$_SESSION["last"]=time(); return '.var_export(['password_hash'=>password_hash('test-only',PASSWORD_DEFAULT),'database_path'=>$temp.'/test.sqlite'],true).';');
function renderPage(string $view,array $extra=[]):string{
 global $root,$config;
 $code='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).'); $_GET='.var_export(['view'=>$view]+$extra,true).'; $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; $_SERVER["HTTP_HOST"]="localhost"; $_SERVER["SCRIPT_NAME"]="/index.php"; require '.var_export($root.'/index.php',true).';';
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1',$lines,$exit);
 $html=implode("\n",$lines);
 if($exit!==0||preg_match('/(?:Fatal error|Warning|Notice):/',$html)||!str_contains($html,'</html>'))throw new RuntimeException($view.' did not render completely: '.substr($html,-1800));
 return $html;
}
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
try{
 renderPage('dashboard');
 $db=new PDO('sqlite:'.$temp.'/test.sqlite');
 $db->exec("INSERT INTO admin_users(id,username,password_hash,active,created_at) VALUES(1,'fixture',".$db->quote(password_hash('test-only',PASSWORD_DEFAULT)).',1,'.time().')');
 $insert=$db->prepare("INSERT INTO products(id,name,price,active,cost,stock_qty,low_stock_at,stock_tracking,stock_tracking_since,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,supplier_pack_size) VALUES(?,?,?,1,?,?,2,1,'2026-10-01',?,?,0,10)");
 foreach([
  [90001,'Low fixture one',1000,400,1,1,0],
  [90002,'Out fixture two',1000,400,-2,-1,-1],
  [90003,'Low fixture three',1000,400,0,0,0],
  [90004,'Low fixture four',1000,400,2,2,0],
  [90005,'Low fixture five',1000,400,2,1,1],
 ] as $row){$insert->execute($row);}

 $stock=renderPage('stock');
 foreach([
  'data-stock-low-banner',
  'stock-low-banner is-low',
  'stock-low-banner is-out',
  'Low – 1 left after today’s orders',
  'Out – short by 2',
  'data-stock-add-product="90002"',
  'id="stock-add-stock"',
  '>Add stock<',
 ] as $marker)check(str_contains($stock,$marker),'Stock view missing: '.$marker);

 $dashboard=renderPage('dashboard');
 foreach([
  'today-low-stock',
  'today-low-chip',
  'today-low-chip is-out',
  'Out · short by 2',
  '+1 more',
 ] as $marker)check(str_contains($dashboard,$marker),'Today missing: '.$marker);

 $new=renderPage('new');
 check(str_contains($new,'"available":-2'),'New order product data must include available stock after reservations.');
 check(str_contains($new,'data-line-stock-warning'),'New order line must render an inline stock warning.');
 check(str_contains($new,"'Only '+Math.max(0,available)+' available'"),'New order warning must use the Only N available wording.');
 check(str_contains($new,'function validateLines()'),'Order validation must remain intact.');
 echo "PASS: low/out banners with Add stock, Today chips and the new-order availability warning.\n";
}finally{
 $db=null;
 foreach(glob($temp.'/*') as $file)unlink($file);
 rmdir($temp);
}
