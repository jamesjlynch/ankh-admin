<?php
declare(strict_types=1);
/** In-memory regression for the V2 read-only product overview. */
$root=dirname(__DIR__);
$entry=file_get_contents($root.'/index.php');
$tpl=file_get_contents($root.'/product-v2.php');
$css=file_get_contents($root.'/product-v2.css');
$deploy=file_get_contents($root.'/scripts/publish-live.sh');
function verify(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
verify(str_contains($entry,"'products','product','supplier'"),'Product route must be in the authenticated view allowlist.');
verify(str_contains($entry,"require __DIR__.'/product-v2.php'"),'Product detail must use the authenticated page context.');
verify(str_contains($entry,'product-v2-catalogue-grid')&&str_contains($entry,'class="product-v2-tile"'),'A linked photograph catalogue grid is required.');
verify(str_contains($entry,'premium-v2-editing')&&str_contains($entry,'product-v2-current-edit'),'Product editing must still use original controls.');
verify(str_contains($entry,'product-v2.css?v='),'Product UI CSS must be versioned.');
verify(str_contains($deploy,'product-v2.css')&&str_contains($deploy,'product-v2.php'),'The publisher must deploy both product assets.');
verify(!str_contains($tpl,"UPDATE products")&&!str_contains($tpl,'INSERT INTO')&&!str_contains($tpl,'DELETE FROM'),'Product detail is read-only.');
verify(str_contains($tpl,'$pvRecentQuery=$db->prepare')&&str_contains($tpl,'$pvMovementsQuery=$db->prepare'),'Orders and stock changes require prepared queries.');
verify(str_contains($css,'@media(max-width:760px)')&&str_contains($css,'.product-v2-catalogue-grid'),'Responsive product cards are required.');
if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('PDO SQLite is required for the product page test.');
function e($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function money(int $pence):string{return '£'.number_format($pence/100,2);}
function ankhCatalogueContent(PDO $db):array{return [7=>['image_bytes'=>3201]];}
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE orders(id INTEGER,customer TEXT,created TEXT,status TEXT,delivery_date TEXT,assigned_to TEXT,back_order INTEGER);
CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER,product_id INTEGER,name TEXT,quantity INTEGER,price INTEGER,presentation TEXT);
CREATE TABLE stock_movements(id INTEGER PRIMARY KEY,product_id INTEGER,created TEXT,movement_type TEXT,quantity_change INTEGER,location TEXT,order_id INTEGER);');
$db->exec("INSERT INTO orders VALUES(45,'Test customer',datetime('now'),'New','','Jay',0);
INSERT INTO items(order_id,product_id,name,quantity,price,presentation) VALUES(45,7,'Test Vial 5mg',2,2500,'Vial');
INSERT INTO stock_movements(product_id,created,movement_type,quantity_change,location,order_id) VALUES(7,datetime('now'),'Manual adjustment',3,'Jay',NULL);");
$auth=true;
$products=[['id'=>7,'name'=>'Test Vial 5mg','active'=>1,'stock_tracking'=>1,'price'=>3000,'family_friends_price'=>2200,'cost'=>1000,'stock_jay_qty'=>5,'stock_tony_qty'=>2,'stock_unallocated_qty'=>1,'low_stock_at'=>3,'supplier_pack_size'=>10]];
$reservedStockByProduct=[7=>['jay'=>2,'tony'=>1]];
$reservationDetailsByProduct=[7=>[['order_id'=>45,'quantity'=>2,'customer'=>'Test customer','location'=>'Jay']]];
$incomingByProduct=[7=>4];
$_GET['id']='7';
ob_start();include $root.'/product-v2.php';$output=ob_get_clean();
foreach(['Test Vial 5mg','?api=catalogue-image&amp;id=7','£30.00','£22.00','£10.00','£20.00','Test customer','Jay’s fridge','Tony’s fridge','Manual adjustment','?view=products&amp;edit_product=7','4</strong><small>Outstanding supplier items','2 × £25.00'] as $expected){
 verify(str_contains($output,$expected),'Product workspace missing output: '.$expected);
}
$_GET['id']='999';
ob_start();include $root.'/product-v2.php';$notFound=ob_get_clean();
verify(str_contains($notFound,'Product not found'),'Missing product must show a safe not-found state.');
echo "PASS: read-only product workspace shows prices, real available/reserved/incoming counts, order activity, photographs and safe editing links.\n";
