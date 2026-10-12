<?php
declare(strict_types=1);
require dirname(__DIR__).'/supplier-centre.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,price INTEGER NOT NULL DEFAULT 0,family_friends_price INTEGER DEFAULT NULL,stock_tracking INTEGER NOT NULL DEFAULT 1,stock_jay_qty INTEGER NOT NULL DEFAULT 0,stock_tony_qty INTEGER NOT NULL DEFAULT 0,stock_unallocated_qty INTEGER NOT NULL DEFAULT 0);
CREATE TABLE supplier_orders(id INTEGER PRIMARY KEY,supplier TEXT NOT NULL,expected_date TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,note TEXT NOT NULL DEFAULT '',created TEXT NOT NULL);
CREATE TABLE supplier_order_items(id INTEGER PRIMARY KEY,supplier_order_id INTEGER NOT NULL,product_id INTEGER NOT NULL,packs INTEGER NOT NULL,pack_size INTEGER NOT NULL,quantity INTEGER NOT NULL,unit_cost INTEGER,received_qty INTEGER NOT NULL DEFAULT 0);
INSERT INTO products(id,name,price,stock_jay_qty) VALUES(1,'Sample 10mg',5000,2);");
sc_setup($db);
$db->exec("INSERT INTO supplier_catalogue(sku,name,strength,pack_size,box_usd_cents,updated) VALUES ('XX10','Sample','10mg',10,7000,''),('375','LL37','5mg',10,3300,'')");
$_SESSION=[];
// HTML input boxes[375] is converted to an integer array key by PHP, not a string.
$_POST=['boxes'=>['XX10'=>'3',375=>'2'],'supplier_intent'=>'draft','shipping_usd'=>'70.00','expected_date'=>'2026-11-15'];
register_shutdown_function(static function()use($db):void{
 $draft=$db->query('SELECT * FROM supplier_basket_drafts WHERE id=1')->fetch(PDO::FETCH_ASSOC);
 if(!$draft)throw new RuntimeException('Shared supplier draft not saved.');
 $saved=json_decode($draft['boxes_json'],true);
 if(($saved['XX10']??0)!==3||($saved['375']??0)!==2||(int)$draft['shipping_usd_cents']!==7000||$draft['expected_date']!=='2026-11-15')throw new RuntimeException('Saved basket quantities, estimated shipping, or expected date are wrong.');
 if((int)$db->query('SELECT COUNT(*) FROM supplier_orders')->fetchColumn()!==0)throw new RuntimeException('Saving a draft must not place an incoming supplier order.');
 $stock=(int)$db->query('SELECT stock_jay_qty FROM products WHERE id=1')->fetchColumn();
 if($stock!==2)throw new RuntimeException('Saving a draft must not alter fridge stock.');
 echo "PASS: shared draft saves string and numeric-only supplier SKUs (375) without orders or stock changes.\n";
});
sc_post($db,'supplier_centre_place');
throw new RuntimeException('Expected supplier draft action to redirect.');
