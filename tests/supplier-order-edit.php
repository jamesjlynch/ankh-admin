<?php
declare(strict_types=1);
// Real POST requests against a temporary SQLite fixture, never the live database.
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/ankh-supplier-edit-'.bin2hex(random_bytes(6));mkdir($temp,0700);$config=$temp.'/config.php';
file_put_contents($config,'<?php $_SESSION["csrf"]="supplier-edit-fixture"; return '.var_export(['password_hash'=>password_hash('fixture',PASSWORD_DEFAULT),'database_path'=>$temp.'/fixture.sqlite'],true).';');
function editCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function editRequest(array $get=[],array $post=[]):string{
 global $root,$config;
 $code='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).');$_GET='.var_export($get,true).';$_POST='.var_export($post+['csrf'=>'supplier-edit-fixture'],true).';$_SERVER["REQUEST_METHOD"]='.var_export($post?'POST':'GET',true).';$_SERVER["REMOTE_ADDR"]="127.0.0.1";$_SERVER["HTTP_HOST"]="localhost";$_SERVER["SCRIPT_NAME"]="/index.php";require '.var_export($root.'/index.php',true).';';
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1',$output,$exit);$html=implode("\n",$output);editCheck($exit===0&&!preg_match('/(?:Fatal error|Warning|Notice):/',$html),'Request error: '.substr($html,-1500));return $html;
}
try{
 editRequest(['view'=>'stock','section'=>'incoming']);$db=new PDO('sqlite:'.$temp.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec("INSERT INTO products(id,name,price,cost,supplier_pack_size,stock_tracking,stock_jay_qty,stock_tony_qty,stock_unallocated_qty,stock_qty) VALUES(99001,'Original fixture 10mg',1000,500,10,1,20,0,0,20),(99002,'Missed fixture 5mg',1000,300,5,1,0,0,0,0)");
 editRequest([],['action'=>'supplier_order_create','supplier'=>'Fixture supplier','product_id'=>[99001],'packs'=>[2],'unit_cost'=>['5.00'],'return'=>'stock','return_section'=>'incoming']);
 $id=(int)$db->query('SELECT MAX(id) FROM supplier_orders')->fetchColumn();$lineId=(int)$db->query('SELECT id FROM supplier_order_items WHERE supplier_order_id='.$id)->fetchColumn();
 $orderBefore=$db->query('SELECT * FROM supplier_orders WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
 $source=file_get_contents($root.'/index.php');$a=strpos($source,'function supplierEditRevision(');$b=strpos($source,'function editIncomingSupplierOrder(',$a);eval(substr($source,$a,$b-$a));
 $revision=static function()use($db,$id):string{return supplierEditRevision($db->query('SELECT * FROM supplier_orders WHERE id='.$id)->fetch(PDO::FETCH_ASSOC),$db->query('SELECT * FROM supplier_order_items WHERE supplier_order_id='.$id)->fetchAll(PDO::FETCH_ASSOC));};
 $base=['action'=>'supplier_order_edit','supplier_order_id'=>$id,'supplier'=>'Corrected supplier','expected_date'=>'2026-11-01','note'=>'Missed product added','return'=>'stock','return_section'=>'incoming'];
 $old=['id'=>$lineId,'product_id'=>99001,'packs'=>3,'unit_cost'=>'5.00'];$new=['id'=>0,'product_id'=>99002,'packs'=>2,'unit_cost'=>''];
 $post=static function(array $lines,?string $rev=null)use($base,$revision):string{return editRequest(['view'=>'stock','section'=>'incoming'],$base+['revision'=>$rev??$revision(),'lines'=>$lines]);};
 $stocksBefore=$db->query('SELECT id,stock_qty,stock_jay_qty,stock_tony_qty FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
 $post([$old,$new]);$lines=$db->query('SELECT * FROM supplier_order_items WHERE supplier_order_id='.$id.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
 editCheck(count($lines)===2&&(int)$lines[0]['id']===$lineId&&(int)$lines[0]['quantity']===30&&(int)$lines[1]['quantity']===10,'Add a missed product using its own pack size, retaining original line IDs');
 editCheck((int)$lines[1]['unit_cost']===300,'Blank new line cost uses product cost');
 $order=$db->query('SELECT * FROM supplier_orders WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);editCheck($order['supplier']==='Corrected supplier'&&$order['expected_date']==='2026-11-01'&&$order['created']===$orderBefore['created'],'Edit metadata without changing original order date');
 editCheck($stocksBefore===$db->query('SELECT id,stock_qty,stock_jay_qty,stock_tony_qty FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Editing incoming stock must not change physical inventory');
 $newId=(int)$lines[1]['id'];$remove=['id'=>$newId,'product_id'=>99002,'remove'=>'1'];$old['packs']=2;$post([$old,$remove]);editCheck((int)$db->query('SELECT COUNT(*) FROM supplier_order_items WHERE supplier_order_id='.$id)->fetchColumn()===1,'Remove only explicitly selected, unreceived lines');
 editRequest([],['action'=>'supplier_stock_receive','supplier_item_id'=>$lineId,'jay_qty'=>5,'tony_qty'=>0,'unallocated_qty'=>0]);
 $receivedBefore=$db->query('SELECT * FROM stock_movements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
 $post([$old,$new]);editCheck((int)$db->query('SELECT received_qty FROM supplier_order_items WHERE id='.$lineId)->fetchColumn()===5,'Preserve received quantities when adding another product');
 editCheck($receivedBefore===$db->query('SELECT * FROM stock_movements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Preserve stock receipt history');
 $all=$db->query('SELECT * FROM supplier_order_items WHERE supplier_order_id='.$id.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$other=['id'=>$all[1]['id'],'product_id'=>99002,'packs'=>2,'unit_cost'=>'3.00'];
 foreach([array_replace($old,['remove'=>'1']),array_replace($old,['product_id'=>99002]),array_replace($old,['unit_cost'=>'9.00'])] as $invalid){$html=$post([$invalid,$other]);editCheck(str_contains($html,'received stock'),'Received-line removal, replacement and repricing must be rejected');}
 editRequest([],['action'=>'supplier_stock_receive','supplier_item_id'=>$lineId,'jay_qty'=>10,'tony_qty'=>0,'unallocated_qty'=>0]);
 $html=$post([array_replace($old,['packs'=>1]),$other]);editCheck(str_contains($html,'already received'),'Cannot reduce ordered quantity below received units');
 $stale=$revision();editRequest([],['action'=>'supplier_stock_receive','supplier_item_id'=>$lineId,'jay_qty'=>1,'tony_qty'=>0,'unallocated_qty'=>0]);
 $html=$post([$old,$other],$stale);editCheck(str_contains($html,'changed while you were editing'),'Reject stale edits after a receipt');
 $html=$post([$old]);editCheck(str_contains($html,'existing product rows are missing'),'Missing submitted rows must not silently delete products');
 $html=$post([array_replace($old,['id'=>999999]),$other]);editCheck(str_contains($html,'does not belong'),'Reject foreign line IDs');
 editCheck((int)$db->query('SELECT stock_jay_qty FROM products WHERE id=99001')->fetchColumn()===36,'Only actual receipts add physical stock');
 $html=editRequest(['view'=>'stock','section'=>'incoming','edit_supplier'=>$id]);editCheck(str_contains($html,'Save incoming changes')&&str_contains($html,'data-add-edit-line')&&str_contains($html,'edit_supplier='),'Incoming page exposes editing and direct product links');
 $db->exec("UPDATE supplier_orders SET status='Cancelled' WHERE id=".$id);$html=$post([$old,$other]);editCheck(str_contains($html,'Only open incoming supplier orders'),'Closed orders cannot be edited');
 echo "PASS: incoming edits, missed products, pack sizes, metadata, explicit removal, received-stock/history protection, stale guards, foreign rows and physical inventory preservation.\n";
}finally{$db=null;foreach(glob($temp.'/*')?:[] as $file)unlink($file);rmdir($temp);}
