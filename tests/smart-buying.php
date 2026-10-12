<?php
declare(strict_types=1);
require dirname(__DIR__).'/supplier-centre.php';
function checkSmart(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,price INTEGER NOT NULL DEFAULT 0,family_friends_price INTEGER DEFAULT NULL,stock_tracking INTEGER NOT NULL DEFAULT 1,stock_tracking_since TEXT NOT NULL DEFAULT '',stock_jay_qty INTEGER NOT NULL DEFAULT 0,stock_tony_qty INTEGER NOT NULL DEFAULT 0,stock_unallocated_qty INTEGER NOT NULL DEFAULT 0,low_stock_at INTEGER NOT NULL DEFAULT 5);
CREATE TABLE orders(id INTEGER PRIMARY KEY,status TEXT NOT NULL,created TEXT NOT NULL);
CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER NOT NULL,product_id INTEGER,name TEXT NOT NULL,quantity INTEGER NOT NULL,recurring INTEGER NOT NULL DEFAULT 0);
CREATE TABLE stock_delivery_events(order_id INTEGER PRIMARY KEY,delivery_date TEXT NOT NULL);
CREATE TABLE supplier_orders(id INTEGER PRIMARY KEY,supplier TEXT NOT NULL,expected_date TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,note TEXT NOT NULL DEFAULT '',created TEXT NOT NULL);
CREATE TABLE supplier_order_items(id INTEGER PRIMARY KEY,supplier_order_id INTEGER,product_id INTEGER,packs INTEGER,pack_size INTEGER,quantity INTEGER,unit_cost INTEGER,received_qty INTEGER DEFAULT 0);
INSERT INTO products(id,name,stock_jay_qty,stock_tony_qty) VALUES(1,'Example 10mg',-2,5);
INSERT INTO products(id,name,stock_jay_qty,stock_tony_qty) VALUES(2,'Covered 5mg',0,0);
INSERT INTO products(id,name,stock_jay_qty,stock_tony_qty) VALUES(3,'Missing 20mg',-4,0);
INSERT INTO products(id,name,stock_tracking,stock_jay_qty,stock_tony_qty) VALUES(4,'Untracked 5mg',0,0,0);
INSERT INTO products(id,name,stock_jay_qty,stock_tony_qty) VALUES(5,'Plenty 10mg',12,8);
INSERT INTO orders VALUES(1,'Delivered',datetime('now')),(2,'Delivered',datetime('now')),(3,'Cancelled',datetime('now'));
INSERT INTO items(order_id,product_id,name,quantity,recurring) VALUES
 (1,1,'Example 10mg',7,0),(1,1,'Example 10mg',2,1),
 (2,2,'Covered 5mg',1,0),(3,1,'Example 10mg',50,0);");
$today=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d');
$db->prepare('INSERT INTO stock_delivery_events VALUES(?,?)')->execute([1,$today]);
$db->prepare('INSERT INTO stock_delivery_events VALUES(?,?)')->execute([2,$today]);
$db->prepare('INSERT INTO stock_delivery_events VALUES(?,?)')->execute([3,$today]);
sc_setup($db);
$db->exec("INSERT INTO supplier_catalogue(sku,name,strength,pack_size,box_usd_cents,product_id,updated) VALUES
 ('XX10','Example','10mg',10,7000,1,''),
 ('CV5','Covered','5mg',10,2000,2,''),
 ('MS20','Missing','20mg',10,9000,3,''),
 ('UN5','Untracked','5mg',10,1000,4,''),
 ('PL10','Plenty','10mg',10,12000,5,'');");
$delivered=sc_recent_delivered($db,30);
checkSmart(($delivered[1]??0)===7,'Only seven non-recurring delivered units count, not cancelled or recurring items.');
checkSmart(($delivered[2]??0)===1,'Another valid delivered product counts.');
$reserved=[1=>['jay'=>2,'tony'=>1],2=>['jay'=>1],3=>['jay'=>4]];
$incoming=[2=>10];
$catalogue=$db->query('SELECT s.*,p.name AS ankh_name,p.stock_tracking,p.stock_jay_qty,p.stock_tony_qty,p.stock_unallocated_qty,p.low_stock_at FROM supplier_catalogue s JOIN products p ON p.id=s.product_id')->fetchAll(PDO::FETCH_ASSOC);
$forecast=['Example 10mg'=>2,'Plenty 10mg'=>3];
$result=sc_smart_suggestions($catalogue,$reserved,$incoming,$delivered,$forecast,30);
$bySku=[];foreach($result as $row)$bySku[$row['sku']]=$row;
$example=$bySku['XX10'];
checkSmart($example['available_raw']===3&&$example['available']===3,'Net available must include positive spare in Tony’s fridge and NOT subtract reserved orders again.');
checkSmart($example['jay_short']===2 && $example['transfer_tony_to_jay']===2,'Recommend checking a manual Tony-to-James transfer before buying.');
checkSmart($example['lead_demand']===2&&$example['recurring30']===2&&$example['target']===9,'Target should include threshold, delivered 7-day demand and possible 30-day recurring demand.');
checkSmart($example['gap']===6&&$example['boxes']===1&&$example['units']===10,'Demand should be rounded to full boxes of ten.');
checkSmart($example['reserved']===3,'Display reserved units without subtracting them from net stock twice.');
checkSmart($bySku['CV5']['incoming']===10&&$bySku['CV5']['boxes']===0,'Already incoming boxes should remove the need to reorder.');
checkSmart($bySku['MS20']['status']==='shortage'&&$bySku['MS20']['urgent']===4&&$bySku['MS20']['boxes']===1,'Uncovered deficit must be prioritised ahead of routine restock.');
checkSmart($bySku['UN5']['status']==='untracked'&&$bySku['UN5']['boxes']===0,'Unverified stock must not trigger automated purchasing.');
checkSmart($bySku['PL10']['status']==='covered'&&$bySku['PL10']['boxes']===0,'Plenty of physical stock should not be over-ordered.');
checkSmart($result[0]['sku']==='MS20','True urgent shortages should rank first.');
$planWithIncoming=sc_smart_suggestions($catalogue,$reserved,[2=>10,3=>20],$delivered,$forecast,30);
$bySku2=[];foreach($planWithIncoming as $row)$bySku2[$row['sku']]=$row;
checkSmart($bySku2['MS20']['boxes']===0&&$bySku2['MS20']['urgent']===0,'Incoming stock should resolve future purchasing recommendation; manual fridge deficit remains visible separately.');
$repeat=sc_smart_suggestions($catalogue,$reserved,$incoming,$delivered,$forecast,60);
$longWindow=[];foreach($repeat as $r)$longWindow[$r['sku']]=$r;
checkSmart($longWindow['XX10']['lead_demand']===1,'60-day lookback must slow down the demand run rate.');
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function money($n){return '£'.number_format((int)$n/100,2);}
function csrf(){echo '<input type="hidden" name="csrf" value="test">';}
sc_save_setting($db,'supplier_usd_gbp_rate','0.78');
$_GET=['tab'=>'smart','lookback_days'=>'30'];
ob_start();sc_render($db,[],$reserved,$incoming,$forecast);$html=ob_get_clean();
checkSmart(str_contains($html,'SMART BUYING ASSISTANT')&&str_contains($html,'What should we reorder?'),'Smart Buying screen must render.');
checkSmart(str_contains($html,'data-smart-sku="XX10"')&&str_contains($html,'data-smart-boxes="1"'),'Each suggestion must expose a selectable integer full-box count.');
checkSmart(str_contains($html,'Tony has 5 available; up to 2 could be transferred'),'Explain other-fridge spare stock clearly.');
checkSmart(str_contains($html,'£250')&&str_contains($html,'£500')&&str_contains($html,'£1,000'),'Preset budgets should appear.');
checkSmart(str_contains($html,'one-off $70.00 USD order shipping charge'),'Smart Buying should explain that shipping is paid once per order.');
checkSmart(str_contains($html,'nextUsd+shippingUsd')&&!str_contains($html,'boxes*shipping'),'Budget calculation must count fixed shipping once, not per box.');
checkSmart(str_contains($html,'supplier_centre_smart_apply')&&str_contains($html,'Add selected boxes to shared basket'),'Smart buying must stage recommendations, not place an order.');
checkSmart(str_contains($html,'Existing reservations')===false,'Avoid misleading duplicate deduction language.');
echo "PASS: Smart Buying run rate, 30-day recurring, transfer, reserved/incoming, pack rounding, budget UI and prioritization.\n";
