<?php
declare(strict_types=1);
require dirname(__DIR__).'/supplier-centre.php';
function shipAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,price INTEGER NOT NULL DEFAULT 0,family_friends_price INTEGER DEFAULT NULL,stock_tracking INTEGER NOT NULL DEFAULT 1,stock_jay_qty INTEGER NOT NULL DEFAULT 0,stock_tony_qty INTEGER NOT NULL DEFAULT 0,stock_unallocated_qty INTEGER NOT NULL DEFAULT 0,low_stock_at INTEGER NOT NULL DEFAULT 5);
CREATE TABLE orders(id INTEGER PRIMARY KEY,status TEXT NOT NULL,created TEXT NOT NULL);
CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER NOT NULL,product_id INTEGER,quantity INTEGER NOT NULL);
CREATE TABLE supplier_orders(id INTEGER PRIMARY KEY,supplier TEXT NOT NULL,expected_date TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,note TEXT NOT NULL DEFAULT '',created TEXT NOT NULL);
CREATE TABLE supplier_order_items(id INTEGER PRIMARY KEY,supplier_order_id INTEGER NOT NULL,product_id INTEGER NOT NULL,packs INTEGER NOT NULL,pack_size INTEGER NOT NULL,quantity INTEGER NOT NULL,unit_cost INTEGER,received_qty INTEGER NOT NULL DEFAULT 0);
INSERT INTO products(id,name,price) VALUES(1,'Sample 10mg',5000),(2,'Sample 5mg',4000);");
sc_setup($db);
$db->exec("INSERT INTO supplier_catalogue(sku,name,strength,pack_size,box_usd_cents,product_id,updated) VALUES
 ('XX10','Sample','10mg',10,7000,1,''),('YY5','Sample','5mg',10,2000,2,'')");
sc_save_setting($db,'supplier_usd_gbp_rate','0.78');
shipAssert(sc_shipping_order_usd_cents($db)===7000,'Default shipping should be $70 per entire order.');
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function money($n){return '£'.number_format((int)$n/100,2);}
function csrf(){echo '<input type="hidden" name="csrf" value="test">';}
$_GET=['tab'=>'profit'];
ob_start();sc_render($db,[],[],[]);$unplanned=ob_get_clean();
shipAssert(str_contains($unplanned,'Before shipping · add to basket for landed cost'),'Unplanned supplier catalogue products must not show ungrounded shipping-inclusive costs.');
shipAssert(str_contains($unplanned,'£5.46'),'Single-box base product cost should be in GBP before shipping.');
$db->prepare("INSERT INTO supplier_basket_drafts(id,boxes_json,shipping_gbp,shipping_usd_cents,expected_date,updated) VALUES (1,?,'0',7000,'',datetime('now'))")->execute([json_encode(['XX10'=>2,'YY5'=>1])]);
ob_start();sc_render($db,[],[],[]);$planned=ob_get_clean();
shipAssert(str_contains($planned,'Includes proportional order shipping'),'Profit display must label landed costs as including order shipping when draft composition is known.');
shipAssert(str_contains($planned,'£7.85')&&str_contains($planned,'£2.24'),'One $70 shipping fee must be shared by product value, then by vials.');
shipAssert(!str_contains($planned,'£12.48'),'Do not apply a full fixed shipping fee to EACH supplier box.');
// Verify real MJT price card: GHK-CU $33/10 at saved FX 0.7570 = £24.98/box, £2.50/vial before shipping.
$db->exec("INSERT INTO products(id,name,price) VALUES(3,'GHK-CU 50mg',2300)");
$db->exec("INSERT INTO supplier_catalogue(sku,name,strength,pack_size,box_usd_cents,product_id,updated) VALUES ('CU50','GHK-CU','50mg',10,3300,3,'')");
sc_save_setting($db,'supplier_usd_gbp_rate','0.7570');
ob_start();sc_render($db,[],[],[]);$ghkCard=ob_get_clean();
shipAssert(str_contains($ghkCard,'GHK-CU 50mg')&&str_contains($ghkCard,'Supplier box (10 vials)'),'GHK-CU card must clearly label the box quantity.');
shipAssert(str_contains($ghkCard,'£24.98')&&str_contains($ghkCard,'$33.00 USD')&&str_contains($ghkCard,'£2.50'),'GHK-CU card must show both GBP box and per-vial costs and supplier USD reference.');
shipAssert(str_contains($ghkCard,'Cost / vial')&&str_contains($ghkCard,'Before shipping'),'Product outside basket must not be falsely described as landed cost.');
sc_save_setting($db,'supplier_usd_gbp_rate','');
ob_start();sc_render($db,[],[],[]);$missingFx=ob_get_clean();
shipAssert(str_contains($missingFx,'Set exchange rate'),'Missing FX must be displayed honestly rather than inventing GBP box costs.');
sc_save_setting($db,'supplier_usd_gbp_rate','0.78');
// A snapshot recorded before this migration must keep its historical GBP charge unchanged.
$db->exec("INSERT INTO supplier_orders(id,supplier,status,note,created) VALUES (1,'MJT','Received','','2026-08-01')");
$db->exec("INSERT INTO supplier_purchase_snapshots(supplier_order_id,usd_total_cents,fx_rate,shipping_gbp_pence,estimated_gbp_pence,order_message,created)
VALUES(1,16000,0.78,960,13440,'old purchase','2026-08-01')");
sc_setup($db);
$old=$db->query('SELECT shipping_gbp_pence,shipping_usd_cents,estimated_gbp_pence FROM supplier_purchase_snapshots WHERE supplier_order_id=1')->fetch(PDO::FETCH_ASSOC);
shipAssert((int)$old['shipping_gbp_pence']===960&&(int)$old['estimated_gbp_pence']===13440&&$old['shipping_usd_cents']===null,'Historical purchases must retain their original GBP shipping and cost snapshots.');
echo "PASS: order-level $70 USD shipping correctly distributed across draft items; profits labelled and history unchanged.\n";
