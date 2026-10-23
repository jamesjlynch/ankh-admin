<?php
declare(strict_types=1);
/**
 * Live public technical index must mirror the ALREADY published
 * customer catalogue visibility (not all active products).
 */
require dirname(__DIR__).'/public-catalogue-core.php';
require dirname(__DIR__).'/customer-catalogue.php';
function checkPublic(bool $v,string $message):void { if(!$v) throw new RuntimeException($message); }
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON;CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,price INTEGER,active INTEGER,stock_qty INTEGER,cost INTEGER);
INSERT INTO products VALUES(1,"KPV 10mg",2299,1,6,450),(2,"Retatrutide 10mg",4500,1,2,900),(3,"Pen",2000,0,3,500),(4,"SS-31 10mg",2600,1,2,400);');
$before=$db->query('SELECT * FROM products ORDER BY id')->fetchAll();
checkPublic(ankhPublicCatalogueProducts($db)===[],'No visibility table must not expose all active products');
ankhCatalogueInit($db);
checkPublic(ankhPublicCatalogueProducts($db)===[],'Unpublished products must stay private');
ankhCatalogueSaveContent($db,1,'KPV scientific compound information and research details. No advice for administration.',null,false,true,['sequence'=>'Lys-Pro-Val','research'=>'Preclinical model','coa_url'=>'https://example.com/coa']);
ankhCatalogueSaveContent($db,2,'The scientific profile contains identity and experimental evidence, but was hidden.',null,false,false,['sequence'=>'ABC']);
ankhCatalogueSaveContent($db,3,'This inactive accessory is marked customer visible, but inactive.',null,false,true);
ankhCatalogueSaveContent($db,4,'This visible research item has extensive scientific information.',null,false,true);
$rows=ankhPublicCatalogueProducts($db);
checkPublic(count($rows)===2,'Only active previously-published products should appear');
checkPublic(array_column($rows,'name')===['KPV 10mg','SS-31 10mg'],'Product names/ordering must reflect customer visibility');
checkPublic(!isset($rows[0]['price'],$rows[0]['cost'],$rows[0]['stock_qty'],$rows[0]['reviewed_at']),'Public listing must not expose price, stock, costs or review metadata');
checkPublic(isset($rows[0]['information']['sequence'])&&!isset($rows[0]['information']['coa_url']),'Science included but unverified COA links not automatically exposed');
checkPublic(str_contains(ankhPublicNotice(),'Not intended for human or veterinary consumption'),'Medical-use warning required');
ankhCatalogueSaveContent($db,4,'Revised scientific literature and chemical identity.',null,false,false);
checkPublic(count(ankhPublicCatalogueProducts($db))===1,'Hidden customer product must immediately disappear from public listings');
ankhCatalogueSaveContent($db,4,'Revised scientific literature and chemical identity.',null,false,true);
checkPublic(count(ankhPublicCatalogueProducts($db))===2,'Republished customer product should automatically return');
checkPublic($before===$db->query('SELECT * FROM products ORDER BY id')->fetchAll(),'Names, prices, costs and stock must be unchanged');
echo "PASS: public reference mirrors existing published active products; hidden and inactive stay hidden; no sensitive stock/price data.\n";
