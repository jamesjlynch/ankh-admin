<?php
declare(strict_types=1);
require dirname(__DIR__).'/supplier-centre.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,price INTEGER NOT NULL DEFAULT 0,family_friends_price INTEGER DEFAULT NULL,stock_tracking INTEGER NOT NULL DEFAULT 1,stock_jay_qty INTEGER NOT NULL DEFAULT 0,stock_tony_qty INTEGER NOT NULL DEFAULT 0,stock_unallocated_qty INTEGER NOT NULL DEFAULT 0);
CREATE TABLE supplier_orders(id INTEGER PRIMARY KEY,supplier TEXT NOT NULL,expected_date TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,note TEXT NOT NULL DEFAULT '',created TEXT NOT NULL);
CREATE TABLE supplier_order_items(id INTEGER PRIMARY KEY,supplier_order_id INTEGER NOT NULL REFERENCES supplier_orders(id),product_id INTEGER NOT NULL REFERENCES products(id),packs INTEGER NOT NULL,pack_size INTEGER NOT NULL,quantity INTEGER NOT NULL,unit_cost INTEGER,received_qty INTEGER NOT NULL DEFAULT 0);
INSERT INTO products(id,name,price,stock_jay_qty,stock_tony_qty) VALUES (1,'Sample 10mg',5000,3,2),(2,'Sample 5mg',4000,5,0);");
sc_setup($db);
$db->exec("INSERT INTO supplier_catalogue(sku,name,strength,pack_size,box_usd_cents,product_id,updated) VALUES
 ('XX10','Sample','10mg',10,7000,1,''),
 ('YY5','Sample','5mg',10,2000,2,'');");
sc_save_setting($db,'supplier_usd_gbp_rate','0.78');
$_SESSION=[];
$_POST=[
 'boxes'=>['XX10'=>'2','YY5'=>'1',375=>'0'], // zero-quantity numeric-only SKU must not block order submission
 'expected_date'=>'2026-11-01',
 'shipping_usd'=>'70.00',
 'discount_usd'=>'60.00'
];
register_shutdown_function(static function()use($db):void{
 $orders=$db->query('SELECT * FROM supplier_orders')->fetchAll(PDO::FETCH_ASSOC);
 if(count($orders)!==1||$orders[0]['supplier']!=='MJT'||$orders[0]['status']!=='Ordered')throw new RuntimeException('Expected one MJT incoming supplier order.');
 $id=(int)$orders[0]['id'];
 $items=$db->query('SELECT * FROM supplier_order_items ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC);
 if(count($items)!==2||(int)$items[0]['packs']!==2||(int)$items[0]['quantity']!==20||(int)$items[1]['packs']!==1||(int)$items[1]['quantity']!==10)throw new RuntimeException('Expected one combined order with full ten-vial boxes.');
 if((int)$items[0]['unit_cost']!==580||(int)$items[1]['unit_cost']!==166)throw new RuntimeException('Landed unit costs should include saved FX and proportionally allocated shipping.');
 $snap=$db->query('SELECT * FROM supplier_purchase_snapshots WHERE supplier_order_id='.$id)->fetch(PDO::FETCH_ASSOC);
 if((int)$snap['usd_total_cents']!==16000||(int)$snap['shipping_gbp_pence']!==5460||(int)$snap['shipping_usd_cents']!==7000||(int)$snap['discount_usd_cents']!==6000||(int)$snap['discount_gbp_pence']!==4680||(int)$snap['estimated_gbp_pence']!==13260)throw new RuntimeException('Supplier order value and shipping snapshot incorrect.');
 $expectedMessage="Hi Jessica,\nCan we place an order please for the following?\n\nX2 Sample 10mg (XX10)\nX1 Sample 5mg (YY5)\n\nThanks";
 if((float)$snap['fx_rate']!==0.78||$snap['order_message']!==$expectedMessage)throw new RuntimeException('Saved supplier order message must preserve Jessica’s exact X-count format without internal prices.');
 if((int)$snap['shipping_usd_cents']!==7000||(int)$snap['usd_total_cents']!==16000)throw new RuntimeException('Removing prices from supplier message must not change internal purchase accounting.');
 $lines=$db->query('SELECT * FROM supplier_purchase_lines ORDER BY sku')->fetchAll(PDO::FETCH_ASSOC);
 if(count($lines)!==2||(int)$lines[0]['box_usd_cents']!==7000||(int)$lines[1]['box_usd_cents']!==2000)throw new RuntimeException('Per-SKU historical supplier box costs not recorded.');
 $stock=$db->query('SELECT stock_jay_qty,stock_tony_qty FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC);
 if((int)$stock['stock_jay_qty']!==3||(int)$stock['stock_tony_qty']!==2)throw new RuntimeException('Placing supplier order must never add stock before receiving it.');
 if((int)$db->query('SELECT SUM(received_qty) FROM supplier_order_items')->fetchColumn()!==0)throw new RuntimeException('Supplier order must start entirely unreceived.');
 echo "PASS: $60 supplier discount applied once and allocated by product; purchase snapshots, prices and stock remain safe.\n";
});
sc_post($db,'supplier_centre_place');
throw new RuntimeException('Expected purchase action to redirect and exit.');
