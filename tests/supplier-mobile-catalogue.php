<?php
declare(strict_types=1);
require dirname(__DIR__).'/supplier-centre.php';
function groupOk(bool $test,string $msg):void{if(!$test)throw new RuntimeException($msg);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,price INTEGER NOT NULL DEFAULT 0,family_friends_price INTEGER DEFAULT NULL,stock_tracking INTEGER NOT NULL DEFAULT 1,stock_tracking_since TEXT NOT NULL DEFAULT '',stock_jay_qty INTEGER NOT NULL DEFAULT 0,stock_tony_qty INTEGER NOT NULL DEFAULT 0,stock_unallocated_qty INTEGER NOT NULL DEFAULT 0,low_stock_at INTEGER NOT NULL DEFAULT 5);
CREATE TABLE orders(id INTEGER PRIMARY KEY,status TEXT NOT NULL,created TEXT NOT NULL);
CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER NOT NULL,product_id INTEGER,name TEXT NOT NULL,quantity INTEGER NOT NULL,recurring INTEGER NOT NULL DEFAULT 0);
CREATE TABLE stock_delivery_events(order_id INTEGER PRIMARY KEY,delivery_date TEXT NOT NULL);
CREATE TABLE supplier_orders(id INTEGER PRIMARY KEY,supplier TEXT NOT NULL DEFAULT 'MJT',expected_date TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,note TEXT NOT NULL DEFAULT '',created TEXT NOT NULL);
CREATE TABLE supplier_order_items(id INTEGER PRIMARY KEY,supplier_order_id INTEGER NOT NULL,product_id INTEGER NOT NULL,packs INTEGER NOT NULL DEFAULT 1,pack_size INTEGER NOT NULL DEFAULT 10,quantity INTEGER NOT NULL,unit_cost INTEGER,received_qty INTEGER NOT NULL DEFAULT 0);
INSERT INTO products(id,name,price,stock_jay_qty,stock_tony_qty) VALUES
 (1,'BPC157 10mg',4000,0,0),(2,'Retatrutide 20mg',6500,7,0),
 (3,'KPV 10mg',4000,0,0),(4,'Selank 5mg',3500,0,0);
INSERT INTO supplier_orders(id,supplier,status,note,created) VALUES
 (1,'MJT','Received','','2026-09-01'),(2,'MJT','Ordered','','2026-09-12'),(3,'MJT','Cancelled','','2026-10-01');");
sc_setup($db);
$db->exec("INSERT INTO supplier_catalogue(sku,name,strength,pack_size,box_usd_cents,product_id,updated) VALUES
 ('BC10','BPC157','10mg',10,4200,1,''),
 ('RT20','Retatrutide','20mg',10,12000,2,''),
 ('KP10','KPV','10mg',10,3500,3,''),
 ('SL5','Selank','5mg',10,2500,4,''),
 ('AA5','Other MJT','5mg',10,1500,NULL,''),
 ('ZZ5','Cancelled-only','5mg',10,1800,NULL,'');
INSERT INTO supplier_purchase_lines(supplier_order_id,sku,product_name,strength,packs,units_per_pack,box_usd_cents) VALUES
 (1,'BC10','BPC157','10mg',1,10,4200),
 (3,'ZZ5','Cancelled-only','5mg',1,10,1800);
INSERT INTO supplier_order_items(supplier_order_id,product_id,packs,pack_size,quantity,received_qty) VALUES
 (2,4,1,10,10,0);");
$history=sc_previously_purchased_skus($db);
groupOk(isset($history['BC10'])&&isset($history['SL5'])&&!isset($history['ZZ5']),'Purchase history must count exact SKU snapshots and older product-based incoming purchases, excluding cancelled supplier orders.');
$rows=$db->query("SELECT s.sku,s.product_id,p.stock_jay_qty AS jay_physical,p.stock_tony_qty AS tony_physical FROM supplier_catalogue s LEFT JOIN products p ON p.id=s.product_id")->fetchAll(PDO::FETCH_ASSOC);
$groups=[];foreach($rows as $row)$groups[$row['sku']]=sc_catalogue_group($row,$history);
groupOk($groups['BC10']==='purchased'&&$groups['SL5']==='purchased','Previously purchased products must appear first.');
groupOk($groups['RT20']==='stocked'&&$groups['KP10']==='range','Products physically in fridges should appear before other mapped ANKH products.');
groupOk($groups['AA5']==='other'&&$groups['ZZ5']==='other','Unused supplier-only products belong in the separately collapsed catalogue.');
sc_save_setting($db,'supplier_usd_gbp_rate','0.7570');
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function money($n){return '£'.number_format((int)$n/100,2);}
function csrf(){echo '<input type="hidden" name="csrf" value="test">';}
foreach(['profit','order','products'] as $tab){
 $_GET=['tab'=>$tab];
 ob_start();sc_render($db,[],[],[]);$html=ob_get_clean();
 groupOk(str_contains($html,'Previously purchased')&&str_contains($html,'Currently stocked')&&str_contains($html,'Other ANKH products')&&str_contains($html,'Other MJT products'),$tab.' must have the four clearly labelled groups.');
 groupOk(str_contains($html,'data-sc-group="other" data-default-open="0"'),$tab.' must collapse the supplier-only catalogue initially.');
 groupOk(strpos($html,'Previously purchased')<strpos($html,'Currently stocked')&&strpos($html,'Currently stocked')<strpos($html,'Other ANKH products')&&strpos($html,'Other ANKH products')<strpos($html,'Other MJT products'),$tab.' must prioritise purchase history then existing stock and catalogue.');
 groupOk(str_contains($html,'scFilterSupplierGroups'),$tab.' must support searching through otherwise collapsed lists.');
 if($tab==='profit')groupOk(str_contains($html,'<details class="sc-profit-card"')&&str_contains($html,'sc-profit-expanded'),'Profit cards must be compact and expandable on iPhone.');
 if($tab==='order')groupOk(str_contains($html,'data-box-sku="AA5"')&&str_contains($html,'Copy supplier message'),'Supplier-only products must remain available for ordering, not deleted from forms.');
 if($tab==='products')groupOk(str_contains($html,'name="supplier_csv"')&&str_contains($html,'Save product match'),'Catalogue matching and imported prices must remain editable.');
}
$db->exec("INSERT INTO supplier_basket_drafts(id,boxes_json,shipping_gbp,shipping_usd_cents,expected_date,updated)
 VALUES (1,'{\"AA5\":2}','0',7000,'',datetime('now'))");
$_GET=['tab'=>'order'];
ob_start();sc_render($db,[],[],[]);$draftHtml=ob_get_clean();
groupOk(str_contains($draftHtml,'data-sc-group="other" data-default-open="1" open'),'An existing basket containing an other-supplier product must open that group automatically.');
$_GET=['tab'=>'smart'];
ob_start();sc_render($db,[],[],[]);$smart=ob_get_clean();
groupOk(str_contains($smart,'Covered / no order needed')&&str_contains($smart,'data-default-open="0"'),'Smart Buying should collapse healthy/no-order products.');
groupOk(str_contains($smart,'sc-smart-search'),'Smart Buying must offer product search.');
$css=file_get_contents(dirname(__DIR__).'/style.css');
groupOk(str_contains($css,'.sc-tabs{display:flex;flex-wrap:nowrap')&&str_contains($css,'overflow-x:auto'),'Supplier tabs must scroll horizontally rather than compress on mobile.');
groupOk(str_contains($css,'.sc-order-items{max-height:none;overflow:visible'),'Order lists must not create nested iOS scroll areas.');
echo "PASS: supplier history first, fridge-stock priority, collapsed supplier-only catalogue, cross-group search, mobile profit disclosures and preserved drafts.\n";
