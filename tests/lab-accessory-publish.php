<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lab-accessory-publish.php';
require_once dirname(__DIR__).'/lab-accessory-editorial-update.php';
function publishCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,price INTEGER NOT NULL,active INTEGER NOT NULL DEFAULT 1,cost INTEGER DEFAULT NULL,low_stock_at INTEGER NOT NULL DEFAULT 5,supplier_pack_size INTEGER NOT NULL DEFAULT 1);
INSERT INTO products(name,price,active,cost) VALUES('Existing reference product',6500,1,2000);");
$original=$db->query('SELECT * FROM products WHERE id=1')->fetch();
$seed=ankhLabAccessoriesSeed($db);publishCheck($seed['created']===12,'Expected 12 initial hidden products');
$copy=ankhAccessoryUpgradeEditorial($db);publishCheck($copy['updated']===12,'Expected 12 long copy upgrades');
$before=$db->query('SELECT p.id,p.price,p.cost FROM products p JOIN lab_accessory_sourcing s ON s.product_id=p.id')->fetchAll();
$done=ankhPublishLabProfiles($db);
publishCheck($done['published']===12&&$done['awaiting_supplier']===12,'Expected to publish all 12 as sourcing-pending');
$published=$db->query('SELECT p.id,p.name,p.active,v.visible FROM products p JOIN lab_accessory_sourcing s ON s.product_id=p.id JOIN customer_catalogue_visibility v ON v.product_id=p.id')->fetchAll();
publishCheck(count($published)===12,'All 12 drafts must remain present');
foreach($published as $p)publishCheck((int)$p['visible']===1&&(int)$p['active']===1,'Information page not published');
$renamed=array_filter($published,static fn($p)=>$p['name']==='Laboratory Labels - Pack of 100');
publishCheck(count($renamed)===1,'Unverified waterproof claim remains in product title');
$after=$db->query('SELECT p.id,p.price,p.cost FROM products p JOIN lab_accessory_sourcing s ON s.product_id=p.id')->fetchAll();
publishCheck($before===$after,'Publishing must not change cost or prices');
publishCheck($db->query('SELECT * FROM products WHERE id=1')->fetch()===$original,'Existing peptide or historic product altered');
$again=ankhPublishLabProfiles($db);
publishCheck($again['published']===0&&$again['already_published']===12,'Repeat publish must do nothing');
echo "PASS: twelve lab information pages publish once, unverified waterproof claim removed, supplier status unchanged, source prices intact, unrelated products preserved.\n";
