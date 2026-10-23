<?php
declare(strict_types=1);
require dirname(__DIR__).'/customer-portal/storefront-commerce.php';
function check(bool $yes,string $why):void{if(!$yes)throw new RuntimeException($why);}
$admin=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$admin->exec("CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,price INTEGER,active INTEGER);
CREATE TABLE customer_catalogue_visibility(product_id INTEGER PRIMARY KEY,visible INTEGER);
INSERT INTO products VALUES(1,'BPC-157 5mg',5000,1),(2,'Retatrutide 10mg',6000,1),(3,'Pen case',1500,1),(4,'Unpublished material',4000,1),(5,'Inactive item',3500,0);
INSERT INTO customer_catalogue_visibility VALUES(1,1),(2,1),(3,1),(4,0),(5,1);");
$portal=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$published=[['id'=>1],['id'=>2],['id'=>3]];
$visible=sfDemoVisible($portal,$published);
check(count($visible)===3,'All published profiles must be demo enabled by default');
check(isset($visible[1],$visible[2],$visible[3]),'All compounds and accessories should be present in the no-payment demo');
check(!isset($visible[4],$visible[5]),'Hidden/inactive items should stay out');
sfDemoSaveAll($portal,$admin,[1,3]);
$visible=sfDemoVisible($portal,$published);
check(isset($visible[1],$visible[3])&&!isset($visible[2]),'Staff unticking must immediately remove only selected product');
sfDemoSaveAll($portal,$admin,[]);
check(sfDemoVisible($portal,$published)===[],'Unticking every demo product should disable all');
sfDemoSaveAll($portal,$admin,[1,2,3]);
check(count(sfDemoVisible($portal,$published))===3,'Staff can re-enable every published product');
try{sfDemoSaveAll($portal,$admin,[1,4]);throw new RuntimeException('Staff may not enable an unpublished product');}
catch(InvalidArgumentException $e){}
$records=$portal->query('SELECT COUNT(*) FROM storefront_demo_visibility')->fetchColumn();
check((int)$records===3,'No unrelated product settings changed');
check((int)$admin->query('SELECT COUNT(*) FROM products WHERE active=1')->fetchColumn()===4,'Admin DB remains unmodified');
echo "Public all-products demo basket checks passed: default enabled, staff exclusions, hidden items and read-only Admin.\n";
