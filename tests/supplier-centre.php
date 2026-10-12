<?php
declare(strict_types=1);
require dirname(__DIR__).'/supplier-centre.php';
function ok(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,price INTEGER NOT NULL DEFAULT 0,family_friends_price INTEGER DEFAULT NULL,stock_tracking INTEGER NOT NULL DEFAULT 1,stock_jay_qty INTEGER NOT NULL DEFAULT 0,stock_tony_qty INTEGER NOT NULL DEFAULT 0,stock_unallocated_qty INTEGER NOT NULL DEFAULT 0);
CREATE TABLE orders(id INTEGER PRIMARY KEY,status TEXT NOT NULL,created TEXT NOT NULL);
CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER NOT NULL,product_id INTEGER,quantity INTEGER NOT NULL);
CREATE TABLE supplier_orders(id INTEGER PRIMARY KEY,supplier TEXT NOT NULL,expected_date TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,note TEXT NOT NULL DEFAULT '',created TEXT NOT NULL);
CREATE TABLE supplier_order_items(id INTEGER PRIMARY KEY,supplier_order_id INTEGER NOT NULL,product_id INTEGER NOT NULL,packs INTEGER NOT NULL,pack_size INTEGER NOT NULL,quantity INTEGER NOT NULL,unit_cost INTEGER,received_qty INTEGER NOT NULL DEFAULT 0);
INSERT INTO products(id,name,active,price,family_friends_price,stock_jay_qty,stock_tony_qty) VALUES(1,'Example 10mg',1,5000,4500,2,3),(2,'Second item 5mg',1,4000,NULL,0,0);
INSERT INTO orders(id,status,created) VALUES(1,'Paid',datetime('now'));
INSERT INTO items(order_id,product_id,quantity) VALUES(1,1,2);");
sc_setup($db);
sc_setup($db); // idempotent deployment
$db->exec("INSERT INTO supplier_catalogue(sku,name,strength,pack_size,box_usd_cents,updated) VALUES ('XX10','Example','10mg',10,7000,''),('YY5','Second item','5mg',10,2000,''),('ZZ10','Unmatched','10mg',10,3000,'')");
sc_match_products($db);
$catalogue=$db->query("SELECT sku,product_id FROM supplier_catalogue ORDER BY sku")->fetchAll(PDO::FETCH_KEY_PAIR);
ok((int)$catalogue['XX10']===1&&(int)$catalogue['YY5']===2&&$catalogue['ZZ10']===null,'Conservative exact-name+strength mapping must preserve unmatched SKUs.');
$db->exec("UPDATE supplier_catalogue SET product_id=NULL WHERE sku='XX10'");
sc_match_products($db);
ok((int)$db->query("SELECT product_id FROM supplier_catalogue WHERE sku='XX10'")->fetchColumn()===1,'Conservative mapping can be repeated safely.');
sc_save_setting($db,'supplier_usd_gbp_rate','0.7800');
ok(sc_fx($db)===0.78,'Manual GBP/USD rate must be read correctly.');
sc_save_setting($db,'supplier_usd_gbp_rate','');
ok(sc_fx($db)===null,'Missing FX must not silently invent a live exchange rate.');
sc_save_setting($db,'supplier_usd_gbp_rate','0.7800');
ok(sc_money_usd(12000)==='$120.00','USD amounts must be expressed as cents, not pounds.');
$lines=[['sku'=>'XX10','name'=>'Example','strength'=>'10mg','packs'=>2,'pack_size'=>10],['sku'=>'ZZ10','name'=>'Unmatched','strength'=>'10mg','packs'=>1,'pack_size'=>10]];
$message=sc_supplier_message($lines,17000);
ok(str_contains($message,'XX10')&&str_contains($message,'2 boxes (20 vials)'),'Supplier message should show full boxes and total vials.');
ok(str_contains($message,'$170.00 USD')&&!str_contains($message,'GBP'),'Supplier message should quote USD and keep GBP estimates separate.');
ob_start();
$_GET['tab']='profit';
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function money($p){return '£'.number_format((int)$p/100,2);}
function csrf():void{echo '<input type="hidden" name="csrf" value="test">';}
sc_render($db,[],[1=>['jay'=>1,'tony'=>1]],[]);
$html=ob_get_clean();
ok(str_contains($html,'Profit explorer')&&str_contains($html,'Example 10mg'),'Profit explorer should render existing product mappings.');
ok(str_contains($html,'$70.00')&&str_contains($html,'£5.46'),'Estimated unit landed cost should use box USD cost, exchange rate and pack size.');
ok(str_contains($html,'2 units on paid orders (30d)'),'Product order quantities should appear with their status and period labelled.');
ok(str_contains($html,'James 2 · Tony 3 available'),'Fridge availability should use existing balances.');
$_GET['tab']='order';
ob_start();sc_render($db,[],[],[]);$orderPage=ob_get_clean();
ok(str_contains($orderPage,'Copy supplier message'),'Combined supplier message action must render.');
ok(str_contains($orderPage,'name="boxes[XX10]"'),'Full supplier boxes must be selectable by SKU.');
ok(str_contains($orderPage,'Map XX10')===false,'Mapped products should not show a warning at initial zero selected state.');
$_GET['tab']='products';
ob_start();sc_render($db,[],[],[]);$mapping=ob_get_clean();
ok(str_contains($mapping,'supplier_csv')&&str_contains($mapping,'sku')&&str_contains($mapping,'Match'),'Supplier admin price import and mapping must be available.');
ok(str_contains($mapping,'0.78'),'Saved FX rate must be visible for editing.');
$_GET['tab']='incoming';
ob_start();sc_render($db,[],[],[]);$incoming=ob_get_clean();
ok(str_contains($incoming,'?view=stock&amp;section=incoming'),'Incoming Stock must link to existing receive/allocate functions.');
ok(str_contains(file_get_contents(dirname(__DIR__).'/index.php'),"sc_post(\$db,\$action)"),'Main app must wire supplier action handling.');
$main=file_get_contents(dirname(__DIR__).'/index.php');
ok(str_contains($main,'sc_setup($db);'),'Main app must initialize private supplier tables.');
ok(str_contains($main,'sc_render($db,$stockProducts,$reservedStockByProduct,$incomingByProduct)'),'Supplier page must use existing inventory balances.');
ok(!str_contains($main,'MJT Peptide Price List')&&!str_contains(file_get_contents(dirname(__DIR__).'/supplier-centre.php'),'Retatrutide 20mg'),'Wholesale catalogue must not be hardcoded into the public app.');
echo "PASS: supplier schema, matching, FX, USD message, profit, mobile order basket, private CSV import UI, incoming stock integration.\n";
