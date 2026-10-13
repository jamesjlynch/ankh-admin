<?php
declare(strict_types=1);
require dirname(__DIR__).'/supplier-centre.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,price INTEGER NOT NULL DEFAULT 0,family_friends_price INTEGER DEFAULT NULL,stock_tracking INTEGER NOT NULL DEFAULT 1,stock_jay_qty INTEGER NOT NULL DEFAULT 0,stock_tony_qty INTEGER NOT NULL DEFAULT 0,stock_unallocated_qty INTEGER NOT NULL DEFAULT 0);
CREATE TABLE supplier_orders(id INTEGER PRIMARY KEY,supplier TEXT NOT NULL,expected_date TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,note TEXT NOT NULL DEFAULT '',created TEXT NOT NULL);
CREATE TABLE supplier_order_items(id INTEGER PRIMARY KEY,supplier_order_id INTEGER,product_id INTEGER,packs INTEGER,pack_size INTEGER,quantity INTEGER,unit_cost INTEGER,received_qty INTEGER DEFAULT 0);
INSERT INTO products(id,name,stock_jay_qty,stock_tony_qty) VALUES(1,'Sample 10mg',1,1),(2,'Second 5mg',0,2),(3,'LL37 5mg',2,0);");
sc_setup($db);
$db->exec("INSERT INTO supplier_catalogue(sku,name,strength,pack_size,box_usd_cents,product_id,updated) VALUES
 ('XX10','Sample','10mg',10,7000,1,''),('YY5','Second','5mg',10,4000,2,''),('375','LL37','5mg',10,3300,3,'')");
$db->exec("INSERT INTO supplier_basket_drafts(id,boxes_json,shipping_gbp,expected_date,updated) VALUES
 (1,'{\"XX10\":3}','17.50','2026-11-25','2026-10-12')");
$db->exec('UPDATE supplier_basket_drafts SET discount_usd_cents=6000 WHERE id=1');
$_SESSION=[];
$_POST=['boxes'=>['XX10'=>'2','YY5'=>'1',375=>'1'],'return'=>'supplier'];
register_shutdown_function(static function()use($db):void{
 $row=$db->query('SELECT * FROM supplier_basket_drafts WHERE id=1')->fetch(PDO::FETCH_ASSOC);
 if(!$row)throw new RuntimeException('Smart suggestions must be saved into the shared basket.');
 $chosen=json_decode((string)$row['boxes_json'],true);
 if(($chosen['XX10']??0)!==3||($chosen['YY5']??0)!==1||($chosen['375']??0)!==1)throw new RuntimeException('Existing basket quantities must not multiply or be overwritten by smaller suggestions.');
 if($row['shipping_gbp']!=='17.50'||$row['expected_date']!=='2026-11-25'||(int)$row['discount_usd_cents']!==6000)throw new RuntimeException('Adding suggestions must not erase the current shipping or expected delivery date.');
 if((int)$db->query('SELECT count(*) FROM supplier_orders')->fetchColumn()!==0)throw new RuntimeException('Buying assistant cannot place orders.');
 if((int)$db->query('SELECT count(*) FROM supplier_order_items')->fetchColumn()!==0)throw new RuntimeException('Buying assistant cannot create incoming receipts.');
 if((int)$db->query('SELECT stock_jay_qty FROM products WHERE id=1')->fetchColumn()!==1)throw new RuntimeException('Buying assistant must not change physical stock.');
 echo "PASS: Smart suggestions merge string/numeric-only SKUs into shared draft without duplicates, orders or stock changes.\n";
});
sc_post($db,'supplier_centre_smart_apply');
throw new RuntimeException('Expected redirect after saving suggestions.');
