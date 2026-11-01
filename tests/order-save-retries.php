<?php
declare(strict_types=1);
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/ankh-order-retries-'.bin2hex(random_bytes(6));mkdir($temp,0700);$config=$temp.'/config.php';
file_put_contents($config,'<?php $_SESSION["admin"]=true;$_SESSION["last"]=time();$_SESSION["csrf"]="retry-fixture"; return '.var_export(['password_hash'=>password_hash('fixture',PASSWORD_DEFAULT),'database_path'=>$temp.'/fixture.sqlite'],true).';');
function retryCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function retryRequest(array $get=[],array $post=[]):string{
 global $root,$config;$code='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).');$_GET='.var_export($get,true).';$_POST='.var_export($post+['csrf'=>'retry-fixture'],true).';$_SERVER["REQUEST_METHOD"]='.var_export($post?'POST':'GET',true).';$_SERVER["REMOTE_ADDR"]="127.0.0.1";$_SERVER["HTTP_HOST"]="localhost";$_SERVER["SCRIPT_NAME"]="/index.php";require '.var_export($root.'/index.php',true).';';
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1',$out,$exit);$html=implode("\n",$out);retryCheck($exit===0&&!preg_match('/(?:Fatal error|Warning|Notice):/',$html),'Request failed: '.substr($html,-1200));return $html;
}
try{
 retryRequest(['view'=>'new']);$db=new PDO('sqlite:'.$temp.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec("INSERT INTO products(id,name,price,cost,active,stock_tracking,stock_tracking_since,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,stock_qty) VALUES(99001,'Retry fixture 5mg',5000,1000,1,1,'',10,0,0,10)");
 $post=['action'=>'order','customer'=>'Retry fixture customer','phone'=>'','order_date'=>date('Y-m-d'),'delivery_method'=>'Local Delivery','assigned_to'=>'James','back_order'=>'1','order_request_key'=>str_repeat('a',32),'lines'=>[['product_id'=>99001,'quantity'=>2,'presentation'=>'Vial','base_price'=>'50.00','price'=>'50.00','recurring'=>'0']]];
 retryRequest(['view'=>'new'],$post);retryRequest(['view'=>'new'],$post);
 $count=(int)$db->query("SELECT COUNT(*) FROM orders WHERE customer='Retry fixture customer'")->fetchColumn();retryCheck($count===1,'Repeated save created '.$count.' orders from the same submission.');
 $first=(int)$db->query('SELECT id FROM orders')->fetchColumn();$items=(int)$db->query('SELECT COUNT(*) FROM items WHERE order_id='.$first)->fetchColumn();retryCheck($items===1,'A replay cannot duplicate item lines');
 retryCheck((int)$db->query('SELECT stock_jay_qty FROM products WHERE id=99001')->fetchColumn()===10,'Replayed back-order saves do not reserve stock');
 $auditCount=(int)$db->query("SELECT COUNT(*) FROM activity_events WHERE entity_id=".$first." AND action='Order created'")->fetchColumn();retryCheck($auditCount===1,'A replay does not create another order-created event');
 $post['order_request_key']=str_repeat('b',32);retryRequest(['view'=>'new'],$post);retryCheck((int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn()===2,'An intentionally new form can create a legitimate repeat order');
 $active=array_replace($post,['back_order'=>'0','order_request_key'=>str_repeat('c',32)]);retryRequest(['view'=>'new'],$active);retryRequest(['view'=>'new'],$active);retryCheck((int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn()===3&&(int)$db->query('SELECT stock_jay_qty FROM products WHERE id=99001')->fetchColumn()===8,'Active-order retries reserve stock exactly once too');
 $invalid=array_replace($post,['order_request_key'=>str_repeat('d',32),'customer'=>'']);retryRequest(['view'=>'new'],$invalid);$invalid['customer']='Corrected invalid fixture';retryRequest(['view'=>'new'],$invalid);retryCheck((int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn()===4,'Validation failure does not consume the submission key');
 retryRequest([],['action'=>'order_delete','id'=>$first,'return'=>'backorders']);$deleted=array_replace($post,['order_request_key'=>str_repeat('a',32)]);$html=retryRequest(['view'=>'new'],$deleted);retryCheck(str_contains($html,'was removed')&&(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn()===3,'A stale save cannot recreate an order that was deleted');
 $missing=$post;unset($missing['order_request_key']);$html=retryRequest(['view'=>'new'],$missing);retryCheck(str_contains($html,'Refresh the New order form')&&(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn()===3,'Old forms without a submission key fail safely');
 echo "PASS: save replays, one item set/audit, active stock reserved once, legitimate repeats, validation retry, deleted-order tombstones and legacy form guards.\n";
}finally{$db=null;foreach(glob($temp.'/*')?:[] as $file)unlink($file);rmdir($temp);}
