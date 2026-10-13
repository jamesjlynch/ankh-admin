<?php
declare(strict_types=1);
/* Exercises the production incoming-stock aggregation, including partial receipts,
 * cancelled orders, duplicate products across supplier orders and empty dates.
 */
$source=file_get_contents(dirname(__DIR__).'/index.php');
function assertIncoming(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$start=strpos($source,'function stockIncomingProductBreakdown(');
$end=strpos($source,'$supplierOrders=$db->query(',$start);
assertIncoming($start!==false&&$end!==false,'Pure incoming summary helper is present.');
eval(substr($source,$start,$end-$start));
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT);
CREATE TABLE supplier_orders(id INTEGER PRIMARY KEY,supplier TEXT,expected_date TEXT,status TEXT);
CREATE TABLE supplier_order_items(id INTEGER PRIMARY KEY,supplier_order_id INTEGER,product_id INTEGER,quantity INTEGER,received_qty INTEGER,packs INTEGER,pack_size INTEGER);
INSERT INTO products VALUES(1,'Retatrutide 10mg'),(2,'GHK-CU 50mg'),(3,'BPC-157 10mg');
INSERT INTO supplier_orders VALUES
 (1,'MJT','2026-11-04','Part received'),
 (2,'MJT','2026-10-27','Ordered'),
 (3,'MJT','2026-11-03','Cancelled'),
 (4,'MJT','2026-11-01','Received'),
 (5,'MJT','','Ordered');
INSERT INTO supplier_order_items VALUES
 (1,1,1,50,20,5,10),
 (2,2,1,20,0,2,10),
 (3,2,2,10,0,1,10),
 (4,3,2,500,0,50,10),
 (5,4,1,10,10,1,10),
 (6,1,3,10,10,1,10),
 (7,5,3,10,0,1,10);");
$codeStart=strpos($source,'$supplierOrderItems=[];$incomingByProduct=[];$supplierItemRows=$db->query(');
$codeEnd=strpos($source,'$orders=$db->query(',$codeStart);
assertIncoming($codeStart!==false&&$codeEnd!==false,'Production supplier open-order query is present.');
eval(substr($source,$codeStart,$codeEnd-$codeStart));
assertIncoming($totalIncomingUnits===70,'Unreceived stock should be 30+20 Retatrutide, 10 GHK-CU and 10 BPC-157.');
assertIncoming(array_sum(array_column($incomingProductBreakdown,'due'))===$totalIncomingUnits,'Breakdown must reconcile exactly with the Stock Control incoming total.');
assertIncoming(count($incomingProductBreakdown)===3,'Summary contains exactly three products with open quantities.');
$byId=[];foreach($incomingProductBreakdown as $row)$byId[$row['product_id']]=$row;
assertIncoming($byId[1]['due']===50&&$byId[1]['orders']===2,'Retatrutide should combine part-received and second outstanding supplier order.');
assertIncoming($byId[1]['expected_date']==='2026-10-27','Earliest scheduled date should be reported for the product.');
assertIncoming($byId[2]['due']===10&&$byId[2]['orders']===1,'Cancelled product lines must be excluded.');
assertIncoming($byId[3]['due']===10&&$byId[3]['orders']===1&&$byId[3]['expected_date']===null,'Fully received product line excluded but open line with no date retained.');
assertIncoming($incomingProductBreakdown[0]['name']==='Retatrutide 10mg','Largest incoming quantity should be first.');
$empty=stockIncomingProductBreakdown([]);
assertIncoming($empty===[],'No incoming orders produce an empty breakdown.');
$css=file_get_contents(dirname(__DIR__).'/style.css');
assertIncoming(str_contains($source,'class="stock-on-way"')&&str_contains($source,'class="incoming-by-product"'),'Overview and Incoming page must both render the grouped quantities.');
assertIncoming(str_contains($source,'array_slice($incomingProductBreakdown,0,4)'),'Overview must stay compact, showing the first four products only.');
assertIncoming(str_contains($source,'View all <?=count($incomingProductBreakdown)?> incoming products'),'Overview must link to complete breakdown.');
assertIncoming(str_contains($css,'.stock-on-way-row')&&str_contains($css,'.incoming-by-product-row'),'Incoming quantity widgets must be styled for mobile.');
echo "PASS: grouped outstanding incoming product quantities, partial receipts, cancellations, dates, overview display and mobile styling.\n";
