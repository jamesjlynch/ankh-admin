<?php
declare(strict_types=1);
$source=file_get_contents(dirname(__DIR__).'/index.php');
$a=strpos($source,'function backOrderStockPlan(');$b=strpos($source,'function renderBackOrderStockPlan(',$a);eval(substr($source,$a,$b-$a));
$a=strpos($source,'function stockIncomingProductBreakdown(');$b=strpos($source,'$supplierOrders=',$a);eval(substr($source,$a,$b-$a));
function planCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,stock_tracking INTEGER,stock_qty INTEGER);
CREATE TABLE orders(id INTEGER PRIMARY KEY,back_order INTEGER,status TEXT,delivery_date TEXT);
CREATE TABLE items(order_id INTEGER,product_id INTEGER,name TEXT,presentation TEXT,quantity INTEGER);
INSERT INTO products VALUES(1,'Retatrutide 10mg',1,3),(2,'Retatrutide 20mg',1,-2),(3,'ARA-290 10mg',0,0),(4,'Pen',1,1);
INSERT INTO orders VALUES(1,1,'New',''),(2,1,'Paid',''),(3,0,'Paid',''),(4,1,'Cancelled',''),(5,1,'Delivered','2026-10-19');
INSERT INTO items VALUES(1,1,'Retatrutide 10mg','Pen',2),(1,2,'Retatrutide 20mg','Vial',2),(1,4,'Pen','',2),
(2,1,'Retatrutide 10mg','Cartridge',1),(2,2,'Retatrutide 20mg','Pen',1),(2,4,'Pen','',1),(2,3,'ARA-290 10mg','Vial',2),
(3,1,'Retatrutide 10mg','Pen',99),(4,2,'Retatrutide 20mg','Vial',99),(5,4,'Pen','',99);");
$base=['supplier_order_id'=>1,'status'=>'Part received','expected_date'=>'2026-10-25'];
$lines=[
 $base+['product_id'=>1,'product_name'=>'Retatrutide 10mg','quantity'=>4,'received_qty'=>1],
 $base+['product_id'=>2,'product_name'=>'Retatrutide 20mg','quantity'=>4,'received_qty'=>0],
 $base+['product_id'=>4,'product_name'=>'Pen','quantity'=>5,'received_qty'=>2],
 array_replace($base,['product_id'=>1,'product_name'=>'Retatrutide 10mg','quantity'=>100,'received_qty'=>0,'status'=>'Cancelled']),
 array_replace($base,['product_id'=>4,'product_name'=>'Pen','quantity'=>100,'received_qty'=>100,'status'=>'Received'])
];
$incoming=array_column(stockIncomingProductBreakdown($lines),null,'product_id');
$before=$db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$rows=array_column(backOrderStockPlan($db,$incoming),null,'id');
planCheck(count($rows)===4,'Keep separate products, strengths and accessories');
planCheck($rows[1]['quantity']===3&&$rows[1]['formats']===['Cartridge'=>1,'Pen'=>2],'Combine demand across orders, retain format quantities');
planCheck($rows[1]['incoming']===3&&$rows[1]['shortfall']===0,'Only outstanding supplier units count; cancelled supplier orders excluded');
planCheck($rows[2]['quantity']===3&&$rows[2]['incoming']===4&&$rows[2]['shortfall']===1&&$rows[2]['active_shortage']===2,'Incoming must first cover existing active-stock shortages');
planCheck($rows[4]['quantity']===3,'Explicit pen lines count once, not again from peptide presentation');
planCheck($rows[4]['incoming']===3&&$rows[4]['shortfall']===0,'Part received accessories retain only outstanding incoming units');
planCheck($rows[3]['available']===null&&$rows[3]['shortfall']===null,'Untracked stock must stay unknown');
planCheck($rows[1]['expected_date']==='2026-10-25','Incoming arrival date retained');
planCheck($before===$db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Planning must not allocate, reserve or mutate inventory');
echo "PASS: product/strength/format demand, explicit pens without duplication, outstanding incoming, active reservation shortages, untracked counts and read-only stock planning.\n";
