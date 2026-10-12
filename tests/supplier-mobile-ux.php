<?php
declare(strict_types=1);
require dirname(__DIR__).'/supplier-centre.php';
function mobileAssert(bool $yes,string $msg):void{if(!$yes)throw new RuntimeException($msg);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,price INTEGER NOT NULL DEFAULT 0,family_friends_price INTEGER DEFAULT NULL,stock_tracking INTEGER NOT NULL DEFAULT 1,stock_jay_qty INTEGER NOT NULL DEFAULT 0,stock_tony_qty INTEGER NOT NULL DEFAULT 0,stock_unallocated_qty INTEGER NOT NULL DEFAULT 0,low_stock_at INTEGER NOT NULL DEFAULT 5);
CREATE TABLE orders(id INTEGER PRIMARY KEY,status TEXT NOT NULL,created TEXT NOT NULL);
CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER NOT NULL,product_id INTEGER,quantity INTEGER NOT NULL);
CREATE TABLE supplier_orders(id INTEGER PRIMARY KEY,supplier TEXT NOT NULL,expected_date TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,note TEXT NOT NULL DEFAULT '',created TEXT NOT NULL);
CREATE TABLE supplier_order_items(id INTEGER PRIMARY KEY,supplier_order_id INTEGER NOT NULL,product_id INTEGER NOT NULL,packs INTEGER NOT NULL,pack_size INTEGER NOT NULL,quantity INTEGER NOT NULL,unit_cost INTEGER,received_qty INTEGER NOT NULL DEFAULT 0);
INSERT INTO products(id,name,price,stock_jay_qty,stock_tony_qty) VALUES
 (1,'Retatrutide 10mg',4500,0,0),
 (2,'GHK-Cu 50mg',2300,2,1),
 (3,'KPV 10mg',2400,0,0),
 (4,'Retatrutide 20mg',9000,0,0);");
sc_setup($db);
$db->exec("INSERT INTO supplier_catalogue(sku,name,strength,pack_size,box_usd_cents,product_id,updated) VALUES
 ('RT10','Retatrutide','10mg',10,9000,1,''),
 ('CU50','GHK-Cu','50mg',10,3300,2,''),
 ('KP10','KPV','10mg',10,4500,3,''),
 ('RT20','Retatrutide','20mg',10,12000,4,''),
 ('MS10','MOTS-C','10mg',10,6000,NULL,'')");
$db->exec("INSERT INTO supplier_orders(id,supplier,status,created) VALUES
 (1,'MJT','Ordered','2026-08-01'),(2,'MJT','Cancelled','2026-08-05')");
$db->exec("INSERT INTO supplier_order_items(supplier_order_id,product_id,packs,pack_size,quantity,unit_cost) VALUES
 (1,1,1,10,10,600),(2,4,1,10,10,500)");
$db->exec("INSERT INTO supplier_purchase_lines(supplier_order_id,sku,product_name,strength,packs,units_per_pack,box_usd_cents) VALUES
 (1,'RT10','Retatrutide','10mg',1,10,9000),
 (2,'RT20','Retatrutide','20mg',1,10,12000)");
$history=sc_previously_purchased_skus($db);
mobileAssert(isset($history['RT10']),'Purchases before the new importer must be recognised by product ID.');
mobileAssert(!isset($history['RT20']),'Cancelled supplier orders must NOT count as purchased.');
$labels=sc_group_definitions();
mobileAssert(array_keys($labels)===['purchased','stocked','range','other'],'Purchase, stock, range, supplier-only order should be defined.');
$rows=$db->query("SELECT s.*,p.stock_jay_qty AS jay_physical,p.stock_tony_qty AS tony_physical FROM supplier_catalogue s LEFT JOIN products p ON s.product_id=p.id")->fetchAll(PDO::FETCH_ASSOC);
$groups=[];foreach($rows as $r)$groups[$r['sku']]=sc_catalogue_group($r,$history);
mobileAssert($groups['RT10']==='purchased','Purchased items take precedence even with no fridge stock.');
mobileAssert($groups['CU50']==='stocked','Currently stocked products should follow purchase history.');
mobileAssert($groups['KP10']==='range'&&$groups['RT20']==='range','Unstocked ANKH products are separated; cancelled purchases stay here.');
mobileAssert($groups['MS10']==='other','Unmapped MJT-only catalogue products should be placed last.');
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function money($n){return '£'.number_format((int)$n/100,2);}
function csrf(){echo '<input type="hidden" name="csrf" value="test">';}
sc_save_setting($db,'supplier_usd_gbp_rate','0.7570');
$_GET=['tab'=>'order'];
ob_start();sc_render($db,[],[],[]);$html=ob_get_clean();
mobileAssert(str_contains($html,'sc-order-groups')&&str_contains($html,'sc-order-pick'),'Build Order renders grouped tap-friendly product tiles.');
mobileAssert(str_contains($html,'id="sc-order-quick"')&&str_contains($html,'id="sc-quick-usd"')&&str_contains($html,'id="sc-quick-gbp"'),'Running USD and GBP cost summary must be available while selecting boxes.');
mobileAssert(str_contains($html,'id="sc-order-checkout"')&&str_contains($html,'id="sc-basket-selected"'),'Review basket should list selected line items and totals.');
mobileAssert(str_contains($html,"'$70.00'")===false&&str_contains($html,'name="shipping_usd"'),'Shipping stays an editable single per-order USD amount.');
mobileAssert(str_contains($html,'data-sc-line-total')&&str_contains($html,'qty*lookup[i.dataset.boxSku].usd'),'Each selected tile must show the running product line cost.');
mobileAssert(str_contains($html,'data-sc-group="purchased" data-default-open="1" open')&&str_contains($html,'data-sc-group="stocked" data-default-open="1" open'),'Previously purchased and stocked groups should open by default.');
mobileAssert(str_contains($html,'data-sc-group="range" data-default-open="0"')&&str_contains($html,'data-sc-group="other" data-default-open="0"'),'Not-currently-stocked and unused supplier products should start closed.');
mobileAssert(str_contains($html,'scFilterSupplierGroups')&&str_contains($html,'scrollIntoView'),'Search should find hidden sections and review button should navigate to checkout.');
$_GET=['tab'=>'profit'];
ob_start();sc_render($db,[],[],[]);$profit=ob_get_clean();
mobileAssert(str_contains($profit,'sc-profit-expanded')&&str_contains($profit,'sc-catalogue-group'),'Profit Explorer should use compact expandable categories.');
$_GET=['tab'=>'products'];
ob_start();sc_render($db,[],[],[]);$mapping=ob_get_clean();
mobileAssert(str_contains($mapping,'sc-map-groups')&&str_contains($mapping,'data-default-open="0"'),'Supplier-only matching catalogue should be collapsible.');
echo "PASS: supplier purchase history grouping, compact mobile tiles, search, live totals, checkout review, no new supplier data.\n";
