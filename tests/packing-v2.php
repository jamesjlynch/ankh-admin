<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/packing-v2-support.php';
function checkV2(bool $pass,string $message):void{if(!$pass)throw new RuntimeException($message);}
function e($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function money(int $pence):string{return '£'.number_format($pence/100,2);}
function csrf():void{echo '<input type="hidden" name="csrf" value="fixture">';}
function isBackOrder(array $order):bool{return (int)($order['back_order']??0)===1;}
function orderPreparationState(array $order):string{
 if(isBackOrder($order)||in_array((string)$order['status'],['Cancelled','Delivered'],true)||trim((string)($order['delivery_date']??''))!=='')return 'closed';
 if((int)($order['packaged']??0)===1)return 'packaged';
 return (int)($order['reconstituted']??0)===1?'reconstituted':'pending';
}
function orderDisplayName(array $order):string{return (string)($order['recipient_name']?:$order['customer']);}
function dashboardPreparationControls(array $order,string $return):void{echo '<p data-fixture-stage="'.e($return).'">Existing stage controls</p>';}
function ankhCatalogueContent(PDO $db):array{return [8=>['image_bytes'=>100]];}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE orders(id INTEGER PRIMARY KEY,customer TEXT,status TEXT,delivery_date TEXT DEFAULT "",back_order INTEGER DEFAULT 0);
 CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER NOT NULL REFERENCES orders(id),product_id INTEGER,name TEXT,quantity INTEGER,presentation TEXT,price INTEGER);
 CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT);');
$db->exec("INSERT INTO orders(id,customer,status,delivery_date,back_order) VALUES(1,'Alex Test','New','',0),(2,'Other Person','New','',0),(3,'Closed order','Delivered','2026-10-15',0),(4,'Waiting order','New','',1);
 INSERT INTO products(id,name) VALUES(8,'Test product 10mg');
 INSERT INTO items(id,order_id,product_id,name,quantity,presentation,price) VALUES(17,1,8,'Test product 10mg',2,'Cartridge',1200),(18,2,8,'Test product 10mg',1,'Vial',800),(19,3,8,'Test product 10mg',1,'Vial',800),(20,4,8,'Test product 10mg',1,'Vial',800);");
ankhPackingEnsureSchema($db);
ankhPackingEnsureSchema($db); // migration must be idempotent
$q=$db->prepare('SELECT id,order_id,product_id,name,quantity,presentation FROM items WHERE id=?');$q->execute([17]);$line=$q->fetch(PDO::FETCH_ASSOC);
checkV(!ankhPackingCheckedIds($db,1,[$line]),'Default item should not be marked picked.');
ankhPackingToggle($db,1,17,true,4);
checkV(!empty(ankhPackingCheckedIds($db,1,[$line])[17]),'Pick check should persist in the database.');
$q=$db->query('SELECT status,delivery_date FROM orders WHERE id=1');$life=$q->fetch(PDO::FETCH_ASSOC);
checkV($life['status']==='New'&&$life['delivery_date']==='','Pick check must never advance order or delivery.');
checkV((int)$db->query('SELECT COUNT(*) FROM order_packing_checks WHERE order_id=1')->fetchColumn()===1,'Expected one check.');
$denied=false;try{ankhPackingToggle($db,1,18,true);}catch(RuntimeException $e){$denied=true;}
checkV($denied,'Checking an item from the wrong order must fail.');
$denied=false;try{ankhPackingToggle($db,3,19,true);}catch(RuntimeException $e){$denied=true;}
checkV($denied,'Delivered orders must be locked.');
$denied=false;try{ankhPackingToggle($db,4,20,true);}catch(RuntimeException $e){$denied=true;}
checkV($denied,'Waiting orders must be locked.');
$db->exec('UPDATE items SET quantity=3 WHERE id=17');
$q=$db->prepare('SELECT id,order_id,product_id,name,quantity,presentation FROM items WHERE id=?');$q->execute([17]);$changed=$q->fetch(PDO::FETCH_ASSOC);
checkV(!ankhPackingCheckedIds($db,1,[$changed]),'Editing quantity must invalidate stale pick check.');
ankhPackingToggle($db,1,17,true,4);
checkV(!empty(ankhPackingCheckedIds($db,1,[$changed])[17]),'The edited quantity should be re-checkable.');
ankhPackingToggle($db,1,17,false,4);
checkV(!ankhPackingCheckedIds($db,1,[$changed]),'Removing a pick check should persist.');
$index=file_get_contents($root.'/index.php');
$tpl=file_get_contents($root.'/packing-v2.php');
$deploy=file_get_contents($root.'/scripts/publish-live.sh');
checkV(str_contains($index,"require_once __DIR__.'/packing-v2-support.php'"),'Safe packing bookkeeping must load before POST actions.');
checkV(str_contains($index,"if($action==='packing_pick')"),'Packing POST handler missing.');
checkV(str_contains($index,"$view==='packing'"),'Packing route should be reachable.');
checkV(str_contains($index,"'packing_pick'"),'Packing action should be auditable.');
checkV(str_contains($deploy,'packing-v2.php')&&str_contains($deploy,'packing-v2.css')&&str_contains($deploy,'packing-v2-support.php'),'Packing assets must publish atomically.');
checkV(str_contains($tpl,'dashboardPreparationControls($pvOrder,')&&str_contains($tpl,'name="action" value="delivery_mark"'),'Packing UI must use existing preparation and delivery controls.');

$auth=true;$premiumV2Preview=true;$pvSelection=null;
$_GET['id']='1';
$orders=[[
 'id'=>1,'customer'=>'Alex Test','recipient_name'=>'Sam Test','status'=>'New','delivery_date'=>'','back_order'=>0,
 'packaged'=>0,'reconstituted'=>0,'created'=>'2026-10-23 13:05:00','assigned_to'=>'James','delivery_method'=>'Collection',
 'scheduled_delivery_date'=>'2026-10-24','total'=>2400,'paid_amount'=>0
]];
$products=[['id'=>8,'name'=>'Test product 10mg']];
$now=new DateTimeImmutable('2026-10-23 13:30:00',new DateTimeZone('Europe/London'));
ob_start();include $root.'/packing-v2.php';$html=ob_get_clean();
foreach(['Packing checklist','Sam Test','Alex Test','Test product 10mg','?api=catalogue-image&amp;id=8',
 'id="packing-lines"','value="packing_pick"','Existing stage controls','value="delivery_mark"',
 '£24.00 outstanding','2 units','Planned Sat 24 Oct 2026'] as $expected){
 checkV(str_contains($html,$expected),'Missing station output: '.$expected);
}
unset($_GET['id']);ob_start();include $root.'/packing-v2.php';$board=ob_get_clean();
checkV(str_contains($board,'Order journey')&&str_contains($board,'To prepare')&&str_contains($board,'Alex Test'),'Board must list active orders.');
echo "PASS: cross-device durable picking, stale line invalidation, order isolation, closed/back-order safety, no lifecycle mutations, photo station and board.\n";
