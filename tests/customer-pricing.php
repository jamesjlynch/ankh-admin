<?php
declare(strict_types=1);
// Real entrypoint requests; isolated SQLite only, never production.
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/ankh-pricing-'.bin2hex(random_bytes(6));mkdir($temp,0700);
$config=$temp.'/config.php';
file_put_contents($config,'<?php $_SESSION["csrf"]="pricing-fixture"; return '.var_export(['password_hash'=>password_hash('test',PASSWORD_DEFAULT),'database_path'=>$temp.'/fixture.sqlite'],true).';');
function pricingCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function pricingRequest(array $get=[],array $post=[]):string{
 global $root,$config;
 $code='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).');$_GET='.var_export($get,true).';$_POST='.var_export($post+['csrf'=>'pricing-fixture'],true).';$_SERVER["REQUEST_METHOD"]='.var_export($post?'POST':'GET',true).';$_SERVER["REMOTE_ADDR"]="127.0.0.1";$_SERVER["HTTP_HOST"]="localhost";$_SERVER["SCRIPT_NAME"]="/index.php";require '.var_export($root.'/index.php',true).';';
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1',$out,$exit);$html=implode("\n",$out);
 pricingCheck($exit===0&&!preg_match('/(?:Fatal error|Warning|Notice):/',$html),'Request error: '.substr($html,-2000));return $html;
}
try{
 pricingRequest(['view'=>'dashboard']);$db=new PDO('sqlite:'.$temp.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec("INSERT INTO customers(id,name,phone,address,created) VALUES(99001,'Owner fixture','','','2026-01-01'),(99002,'Friend fixture','','','2026-01-01')");
 $db->exec("INSERT INTO products(id,name,price,family_friends_price,cost,stock_tracking,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,stock_qty) VALUES(99001,'Fixture product 5mg',5000,3700,1200,1,20,20,0,40),(99002,'Fixture accessory',1000,NULL,300,0,0,0,0,NULL)");
 pricingRequest([],['action'=>'customer_pricing','customer_id'=>99001,'pricing_type'=>'free','return'=>'customer']);
 pricingRequest([],['action'=>'customer_pricing','customer_id'=>99002,'pricing_type'=>'family_friends','return'=>'customer']);
 $base=['action'=>'order','customer'=>'Owner fixture','phone'=>'','order_date'=>date('Y-m-d'),'delivery_method'=>'Postage','assigned_to'=>'James','delivery_charge'=>'5.00','postage_cost'=>'2.00','payment_method'=>'Other','lines'=>[['product_id'=>99001,'quantity'=>2,'presentation'=>'Vial','base_price'=>'50.00','price'=>'999.00','recurring'=>'1','cycle_weeks'=>4]]];
 pricingRequest([],$base);$id=(int)$db->query('SELECT MAX(id) FROM orders')->fetchColumn();
 $order=$db->query('SELECT * FROM orders WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
 pricingCheck($order['pricing_type']==='free'&&(int)$order['delivery_charge']===0,'Free classification and postage must be zero');
 $line=$db->query('SELECT * FROM items WHERE order_id='.$id)->fetch(PDO::FETCH_ASSOC);
 pricingCheck((int)$line['price']===0&&(int)$line['cost']===1200&&(int)$line['recurring']===0,'Free price enforced; cost retained; no sales recurrence');
 pricingCheck((int)$db->query('SELECT COUNT(*) FROM payments WHERE order_id='.$id)->fetchColumn()===0,'Free order must not create payments');
 foreach(['reconstituted','packaged'] as $stage)pricingRequest([],['action'=>'order_preparation','id'=>$id,'stage'=>$stage,'complete'=>'1']);
 pricingRequest([],['action'=>'delivery_mark','id'=>$id,'delivery_date'=>date('Y-m-d')]);
 pricingCheck((int)$db->query('SELECT stock_jay_qty FROM products WHERE id=99001')->fetchColumn()===18,'Personal use must deduct two units from James stock');
 pricingRequest([],['action'=>'delivery_mark','id'=>$id,'delivery_date'=>date('Y-m-d')]);
 pricingCheck((int)$db->query('SELECT stock_jay_qty FROM products WHERE id=99001')->fetchColumn()===18,'Repeated delivery must not double deduct');
 $report=pricingRequest(['view'=>'reports','type'=>'personal','range'=>'all']);
 pricingCheck(str_contains($report,'2 units')&&str_contains($report,'£24.00'),'Personal report needs quantity and historical stock cost');
 $csv=pricingRequest(['view'=>'reports','type'=>'personal','range'=>'all','export'=>'1']);
 pricingCheck(str_contains($csv,'Owner fixture')&&str_contains($csv,'24.00'),'Personal CSV must export cost');
 pricingCheck(!str_contains($csv,'<!doctype')&&!str_contains($csv,'<html'),'CSV must not include the page header');
 pricingRequest([],['action'=>'customer_pricing','customer_id'=>99001,'pricing_type'=>'standard']);
 pricingCheck($db->query('SELECT pricing_type FROM orders WHERE id='.$id)->fetchColumn()==='free','Customer pricing change must not relabel history');
 $friend=$base;$friend['customer']='Friend fixture';$friend['delivery_method']='Collection';$friend['lines'][0]['price']='0.01';$friend['lines'][0]['recurring']='0';
 pricingRequest([],$friend);$friendId=(int)$db->query('SELECT MAX(id) FROM orders')->fetchColumn();
 pricingCheck((int)$db->query('SELECT price FROM items WHERE order_id='.$friendId)->fetchColumn()===3700,'Saved F&F price must override stale/manual submitted price');
 $friend['lines'][0]['product_id']=99002;pricingRequest([],$friend);$fallbackId=(int)$db->query('SELECT MAX(id) FROM orders')->fetchColumn();
 pricingCheck((int)$db->query('SELECT price FROM items WHERE order_id='.$fallbackId)->fetchColumn()===1000,'Missing saved F&F price must use retail');
 pricingCheck(str_contains(pricingRequest(['view'=>'customer','id'=>99001]),'Save customer pricing'),'Customer pricing UI must render');
 echo "PASS: customer pricing, enforced free/F&F totals, snapshots, stock delivery idempotency, personal report/CSV, historical classification.\n";
}finally{$db=null;foreach(glob($temp.'/*')?:[] as $file)unlink($file);rmdir($temp);}
