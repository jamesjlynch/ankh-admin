<?php
declare(strict_types=1);
// Real entrypoint requests; isolated SQLite only, never production.
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/ankh-backorders-'.bin2hex(random_bytes(6));mkdir($temp,0700);
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
 $db->exec("INSERT INTO products(id,name,price,cost,stock_tracking,stock_tracking_since,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,stock_qty) VALUES(99001,'Waiting fixture 5mg',5000,1200,1,'',4,5,0,9)");
 $base=['action'=>'order','customer'=>'Waiting customer fixture','phone'=>'','order_date'=>date('Y-m-d'),'delivery_method'=>'Local Delivery','assigned_to'=>'James','lines'=>[['product_id'=>99001,'quantity'=>2,'presentation'=>'Vial','base_price'=>'50.00','price'=>'50.00','recurring'=>'0']]];
 $getOrder=fn($id)=>$db->query('SELECT * FROM orders WHERE id='.(int)$id)->fetch(PDO::FETCH_ASSOC);
 $available=fn()=>(int)$db->query('SELECT stock_jay_qty FROM products WHERE id=99001')->fetchColumn();
 $postMove=fn($id,$flag)=>pricingRequest([],['action'=>'order_backorder','id'=>$id,'back_order'=>$flag]);
 pricingRequest([],$base);$active=(int)$db->query('SELECT MAX(id) FROM orders')->fetchColumn();
 pricingCheck((int)$getOrder($active)['back_order']===0&&$available()===2,'New orders must default active and reserve normally');
 pricingRequest([],['action'=>'order_preparation','id'=>$active,'stage'=>'reconstituted','complete'=>'1']);
 $postMove($active,'1');pricingCheck($available()===4&&(int)$getOrder($active)['reconstituted']===0,'Moving to waiting must release reservation and reset preparation');
 $postMove($active,'1');pricingCheck($available()===4,'Repeated move must not release twice');
 $waiting=$base;$waiting['back_order']='1';$waiting['customer']='Second waiting customer';pricingRequest([],$waiting);$id=(int)$db->query('SELECT MAX(id) FROM orders')->fetchColumn();
 pricingCheck((int)$getOrder($id)['back_order']===1&&$available()===4,'Creating waiting orders must not reserve stock');
 pricingRequest([],['action'=>'payment_add','id'=>$id,'amount'=>'30.00','method'=>'Other','payment_date'=>date('Y-m-d')]);
 pricingCheck((int)$db->query('SELECT SUM(amount) FROM payments WHERE order_id='.$id)->fetchColumn()===3000&&(int)$getOrder($id)['back_order']===1,'Deposit must leave order waiting');
 $before=$getOrder($id);$edit=$waiting;$edit['action']='order_edit';$edit['id']=$id;$edit['lines'][0]['quantity']=3;pricingRequest([],$edit);
 pricingCheck((int)$getOrder($id)['back_order']===1&&$available()===4,'Editing waiting order must not reserve');
 $html=pricingRequest(['view'=>'orders','section'=>'backorders']);
 pricingCheck(str_contains($html,'Waiting for stock')&&str_contains($html,'Stock available')&&str_contains($html,'Second waiting customer'),'Waiting list should render availability and customer');
 pricingCheck(strpos($html,'<h2>Waiting customer fixture</h2>')<strpos($html,'<h2>Second waiting customer</h2>'),'Waiting list must show oldest first');
 $html=pricingRequest(['view'=>'orders']);pricingCheck(!str_contains($html,'<h2>Second waiting customer</h2>'),'Normal order list must exclude waiting orders');
 $html=pricingRequest(['view'=>'dashboard']);pricingCheck(!str_contains($html,'<h3>Second waiting customer</h3>'),'Dashboard must exclude waiting workload');
 foreach([
 ['action'=>'delivery_mark','delivery_date'=>date('Y-m-d')],
 ['action'=>'status','status'=>'Delivered'],['action'=>'status','status'=>'Dispatched'],['action'=>'status','status'=>'Packed'],
 ['action'=>'order_dates','payment_date'=>'','delivery_date'=>date('Y-m-d')],
 ['action'=>'order_preparation','stage'=>'reconstituted','complete'=>'1']
 ] as $attempt){$html=pricingRequest([],$attempt+['id'=>$id]);pricingCheck(str_contains($html,'Move this back order to active'),'Waiting fulfilment must be blocked: '.$attempt['action']);}
 pricingCheck($getOrder($id)['delivery_date']===''&&$available()===4,'Rejected fulfilment must not mutate delivery or stock');
 $db->exec('UPDATE products SET stock_jay_qty=2,stock_qty=7 WHERE id=99001');
 $html=$postMove($id,'0');pricingCheck(str_contains($html,'Cannot activate yet')&&(int)$getOrder($id)['back_order']===1&&$available()===2,'Insufficient assigned stock must reject activation even when Tony has stock');
 $db->exec('UPDATE products SET stock_jay_qty=4,stock_qty=9 WHERE id=99001');
 $postMove($id,'0');$after=$getOrder($id);
 pricingCheck((int)$after['back_order']===0&&$available()===1&&$after['created']===$before['created']&&$after['activated_at']!=='','Activation must reserve once and preserve original order date');
 pricingCheck((int)$db->query('SELECT SUM(amount) FROM payments WHERE order_id='.$id)->fetchColumn()===3000,'Activation must retain deposit');
 $postMove($id,'0');pricingCheck($available()===1,'Repeated activation must be idempotent');
 $html=$postMove($active,'0');pricingCheck(str_contains($html,'Cannot activate yet'),'Activating one order must consume availability for others');
 pricingRequest([],['action'=>'delivery_mark','id'=>$id,'delivery_date'=>date('Y-m-d')]);pricingCheck($available()===1,'Delivery must not deduct reserved stock twice');
 $html=$postMove($id,'1');pricingCheck(str_contains($html,'Only open, undelivered'),'Delivered orders must not return to waiting');
 pricingRequest([],['action'=>'status','id'=>$active,'status'=>'Cancelled']);pricingCheck((int)$getOrder($active)['back_order']===0&&$available()===1,'Cancellation must archive waiting order without stock change');
 $new=pricingRequest(['view'=>'new']);pricingCheck(preg_match('/name="back_order"[^>]*checked/',$new)!==1,'Back order option must be unchecked by default');
 // Waiting orders entered before stock tracking starts must reserve on release.
 $waiting['customer']='Historical waiting customer';$waiting['order_date']=date('Y-m-d',strtotime('-3 days'));pricingRequest([],$waiting);$old=(int)$db->query('SELECT MAX(id) FROM orders')->fetchColumn();
 $db->exec("UPDATE products SET stock_tracking_since='".date('Y-m-d')."',stock_jay_qty=5,stock_qty=10 WHERE id=99001");$postMove($old,'0');pricingCheck($available()===3,'Release date must be used when stock tracking began after order date');
 echo "PASS: active default, waiting creation/edit/payment, separate lists, FIFO, stock availability, guarded fulfilment, release/activation idempotency, assigned fridge, retained dates/deposit, cancellation and historical stock tracking.\n";
}finally{$db=null;foreach(glob($temp.'/*')?:[] as $file)unlink($file);rmdir($temp);}
