<?php
declare(strict_types=1);
// Exercise exact production stock helpers and POST handlers with an isolated SQLite database.
$source=file_get_contents(dirname(__DIR__).'/index.php');
function check(bool $ok,string $description):void{if(!$ok)throw new RuntimeException($description);}
$start=strpos($source,'function stockLocationName(');
$end=strpos($source,'function rememberStockUndo(',$start);
check($start!==false && $end!==false,'Stock helper block exists.');
eval(substr($source,$start,$end-$start));
function rememberStockUndo(int $productId,array $changes,array $movementIds,bool $trackingBefore,string $movementType,string $label):void{
 $_SESSION['stock_undo']=['product_id'=>$productId,'changes'=>$changes,'movement_ids'=>$movementIds];
}
$_SESSION=[];
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,stock_tracking INTEGER,stock_tracking_since TEXT,stock_jay_qty INTEGER,stock_tony_qty INTEGER,stock_unallocated_qty INTEGER,stock_qty INTEGER);
CREATE TABLE orders(id INTEGER PRIMARY KEY,status TEXT,delivery_date TEXT,back_order INTEGER NOT NULL DEFAULT 0);
CREATE TABLE stock_fulfilments(order_id INTEGER,product_id INTEGER,quantity INTEGER,location TEXT);
CREATE TABLE stock_movements(id INTEGER PRIMARY KEY,product_id INTEGER,movement_type TEXT,quantity_change INTEGER,order_id INTEGER,location TEXT,supplier TEXT,batch_reference TEXT,expiry_date TEXT,unit_cost INTEGER,note TEXT,created TEXT);
INSERT INTO products VALUES(1,'Retatrutide 20mg',1,'2026-10-01',1,1,0,2);
INSERT INTO orders(id,status,delivery_date) VALUES(1,'Paid',''),(2,'New','');
INSERT INTO stock_fulfilments VALUES(1,1,2,'Jay'),(2,1,1,'Tony');");
check(activeReservedStock($db,1,'Jay')===2,'Jay has two units reserved.');
check(activeReservedStock($db,1,'Tony')===1,'Tony has one unit reserved.');
// Full stock-control form: physically zero, although orders reserve two + one.
$start=strpos($source,"  if(\$action==='stock_fridge_counts'){");
$end=strpos($source,"  if(\$action==='stock_count'){",$start);
check($start!==false&&$end!==false,'Combined fridge stock form handler exists.');
$combinedHandler=substr($source,$start,$end-$start);
$action='stock_fridge_counts';
$_POST=['product_id'=>'1','jay_physical'=>'0','tony_physical'=>'0','expected_jay_physical'=>'3','expected_tony_physical'=>'2'];
eval($combinedHandler);
$record=$db->query('SELECT * FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check((int)$record['stock_jay_qty']===-2 && (int)$record['stock_tony_qty']===-1,'Fridge stock saves shortages instead of rejecting a count.');
check((int)$record['stock_qty']===-3,'Aggregate available stock remains consistent after recording both shortages.');
check((int)$record['stock_jay_qty']+activeReservedStock($db,1,'Jay')===0,'Jay physical count stays zero.');
check((int)$record['stock_tony_qty']+activeReservedStock($db,1,'Tony')===0,'Tony physical count stays zero.');
check((int)$db->query("SELECT count(*) FROM stock_fulfilments")->fetchColumn()===2,'Reservations must not be deleted by a physical count.');
check((int)$db->query("SELECT count(*) FROM stock_movements WHERE movement_type='count_adjustment'")->fetchColumn()===2,'Both physical count adjustments must be audited.');
// Alert calculations must identify which fridge and affected orders.
$reserveMap=[1=>['jay'=>2,'tony'=>1]];
$details=[1=>[
 ['order_id'=>1,'customer'=>'Customer A','location'=>'Jay','quantity'=>2],
 ['order_id'=>2,'customer'=>'Customer B','location'=>'Tony','quantity'=>1]
]];
$shortages=stockReservationShortages([$record],$reserveMap,$details);
check(count($shortages)===2,'Both fridges should produce distinct dashboard warnings.');
check($shortages[0]['location']==='Jay' && $shortages[0]['missing']===2,'Jay should show two missing units.');
check($shortages[1]['location']==='Tony' && $shortages[1]['missing']===1,'Tony should show one missing unit.');
check($shortages[0]['orders'][0]['order_id']===1 && $shortages[1]['orders'][0]['order_id']===2,'Warn with the associated order for each fridge.');
// Count can be corrected back upwards, and the warning automatically clears as soon as stock covers reservations.
$start=strpos($source,"  if(\$action==='stock_count'){");
$end=strpos($source,"  if(\$action==='stock_transfer'){",$start);
check($start!==false&&$end!==false,'Single-fridge stock correction handler exists.');
$singleHandler=substr($source,$start,$end-$start);
$action='stock_count';$_POST=['product_id'=>'1','counted_qty'=>'2','location'=>'Jay','note'=>'Physical recount'];
eval($singleHandler);
$record=$db->query('SELECT * FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check((int)$record['stock_jay_qty']===0,'Single-fridge physical recount should restore Jay without changing reservations.');
$shortages=stockReservationShortages([$record],$reserveMap,$details);
check(count($shortages)===1 && $shortages[0]['location']==='Tony','Reconciled fridge should vanish from alerts while another shortage stays active.');
adjustProductStockAtLocation($db,1,'Tony',1);
$record=$db->query('SELECT * FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check(count(stockReservationShortages([$record],$reserveMap,$details))===0,'Restocking the remaining fridge should clear all alerts.');
// Repeat zero count via product-management editor, formerly blocked when reserved > physical.
$start=strpos($source,"  if(\$action==='stock_open'){");
$end=strpos($source,"  if(\$action==='stock_receive'){",$start);
check($start!==false&&$end!==false,'Product opening-count handler exists.');
$openHandler=substr($source,$start,$end-$start);
$action='stock_open';$_POST=['product_id'=>'1','opening_qty'=>'0','location'=>'Jay','confirm_opening'=>'1'];
eval($openHandler);
check((int)$db->query('SELECT stock_jay_qty FROM products WHERE id=1')->fetchColumn()===-2,'Product fridge editor should also allow counts below reserved.');
check(str_contains($source,'id="stock-shortage-title"'),'Dashboard shortage alert must render.');
check(str_contains($source,'stock-shortage-orders'),'Dashboard must show linked affected orders.');
check(str_contains($source,'name="jay_physical" min="0"') && str_contains($source,'name="tony_physical" min="0"'),'Combined form HTML must permit a zero count.');
check(str_contains($source,'name="counted_qty" type="number" min="0"'),'Single fridge HTML must permit a zero count.');
echo "PASS: below-reservation physical counts, per-fridge alerts, reservation safety, audit, and recovery.\n";

/* A shortage can be covered by the OTHER fridge's genuinely unreserved stock. */
$exampleProduct=[
 'id'=>3,'name'=>'Retatrutide 20mg','stock_tracking'=>1,
 'stock_jay_qty'=>-1,'stock_tony_qty'=>3,'stock_unallocated_qty'=>0
];
$exampleReservations=[3=>['jay'=>2,'tony'=>2]];
$exampleOrders=[3=>[
 ['order_id'=>71,'customer'=>'Trish Beech','location'=>'Jay','quantity'=>1],
 ['order_id'=>72,'customer'=>'Steph Myhan','location'=>'Jay','quantity'=>1],
 ['order_id'=>73,'customer'=>'Tony Customer','location'=>'Tony','quantity'=>2]
]];
$example=stockReservationShortages([$exampleProduct],$exampleReservations,$exampleOrders);
check(count($example)===1,'Only James has a stock shortage.');
check($example[0]['location_owner']==='James','Jay reservations must be attributed to James.');
check($example[0]['other_owner']==='Tony'&&$example[0]['other_available']===3,'Tony’s true available quantity must exclude his two reserved units.');
check($example[0]['transferable']===1&&$example[0]['remaining_after_transfer']===0,'A one-unit transfer from Tony would fully cover the shortage.');
check(count($example[0]['orders'])===2,'Both affected James orders must remain linked.');
$exampleProduct['stock_jay_qty']=-3;$exampleReservations[3]['jay']=4;$exampleProduct['stock_tony_qty']=1;
$partial=stockReservationShortages([$exampleProduct],$exampleReservations,$exampleOrders);
check($partial[0]['missing']===3&&$partial[0]['other_available']===1&&$partial[0]['transferable']===1&&$partial[0]['remaining_after_transfer']===2,'Partial spare stock must not be presented as fully sufficient.');
$exampleProduct['stock_tony_qty']=-1;
$none=stockReservationShortages([$exampleProduct],$exampleReservations,$exampleOrders);
$jay=array_values(array_filter($none,fn($row)=>$row['location']==='Jay'));
check(count($jay)===1&&$jay[0]['other_available']===0&&$jay[0]['transferable']===0,'Tony’s shortage must never count as transferable stock.');
$tony=array_values(array_filter($none,fn($row)=>$row['location']==='Tony'));
check(count($tony)===1&&$tony[0]['location_owner']==='Tony'&&$tony[0]['other_owner']==='James'&&$tony[0]['other_available']===0,'Tony shortages should look at James’s truly available units.');
$unallocated=stockReservationShortages([[
 'id'=>4,'name'=>'Selank','stock_tracking'=>1,'stock_jay_qty'=>0,'stock_tony_qty'=>2,'stock_unallocated_qty'=>-1
]],[4=>['unallocated'=>1]],[4=>[['order_id'=>74,'customer'=>'Unassigned','location'=>'Unallocated','quantity'=>1]]]);
check($unallocated[0]['other_owner']===null&&$unallocated[0]['transferable']===0,'Unallocated stock should not arbitrarily imply a transfer from a named fridge.');
check(str_contains($source,'class="stock-shortage-product-icon"'),'New design must use a product SVG icon.');
check(str_contains($source,'class="stock-shortage-opportunity '),'Dashboard must display other-fridge transfer availability.');
check(str_contains($source,'stock-shortage-order-links'),'Affected customer orders must remain navigable.');
check(str_contains($source,"'remaining_after_transfer'=>max(0,") ,'Transfer advice must distinguish shortages not fully covered.');
check(str_contains($source,"@filemtime(__DIR__.'/style.css')"),'New releases must cache-bust the stylesheet.');
$css=file_get_contents(dirname(__DIR__).'/style.css');
check(str_contains($css,'.dashboard-stock-shortage')&&str_contains($css,'.stock-shortage-opportunity-icon svg'),'Styled warning and transfer SVG icons must exist in the stylesheet.');
echo "PASS: assignment-aware shortage alerts, other-fridge available units, full/partial/no transfer, styling and cache versioning.\n";
