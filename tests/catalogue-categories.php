<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/catalogue-categories.php';
require_once dirname(__DIR__).'/customer-catalogue.php';
require_once dirname(__DIR__).'/public-catalogue-core.php';
function checkCategories(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,price INTEGER NOT NULL,active INTEGER NOT NULL DEFAULT 1);
INSERT INTO products VALUES(100,'BPC-157 5mg',1400,1),(101,'90mm petri dishes',500,1);
CREATE TABLE customers(id INTEGER PRIMARY KEY,pricing_type TEXT,archived INTEGER);INSERT INTO customers VALUES(1,'standard',0);");
ankhCategoriesInit($db);
$catalogue=ankhCategories($db);
checkCategories(count($catalogue)>=4,'Default lab categories were not created');
$byName=[];foreach($catalogue as $c)$byName[$c['name']]=$c;
checkCategories(isset($byName['Petri Dishes'])&&$byName['Petri Dishes']['kind']==='accessory','Petri Dishes accessory category not found');
$id=ankhCategoryCreate($db,'Pipettes & Tips','accessory');
checkCategories($id>0,'Category was not created');
$invalid=false;try{ankhCategoryCreate($db,'Pipettes & Tips','accessory');}catch(InvalidArgumentException $e){$invalid=true;}
checkCategories($invalid,'Category duplicates must be blocked');
$invalid=false;try{ankhCategoryCreate($db,'Products','unchecked');}catch(InvalidArgumentException $e){$invalid=true;}
checkCategories($invalid,'Invalid category classification allowed');
$invalid=false;try{ankhCategoryCreate($db,'<script>alert(1)</script>','accessory');}catch(InvalidArgumentException $e){$invalid=true;}
checkCategories($invalid,'HTML in category name allowed');
$petriId=(int)$byName['Petri Dishes']['id'];ankhCategoryAssign($db,101,$petriId);
checkCategories(ankhProductCategories($db)[101]['name']==='Petri Dishes','Category mapping not saved');
ankhCatalogueInit($db);ankhCatalogueSetVisibility($db,100,true);
checkCategories(ankhCatalogueProducts($db,1)[0]['name']==='BPC-157 5mg','Existing visible product missing');
checkCategories(count(ankhCatalogueProducts($db,1))===1,'A new or hidden accessory must never publish itself');
ankhCatalogueSetVisibility($db,101,true);
$rows=ankhCatalogueProducts($db,1);
checkCategories(count($rows)===2&&$rows[1]['category']==='Petri Dishes','Published accessory category not exposed correctly');
ankhCatalogueSetVisibility($db,100,false);
$rows=ankhCatalogueProducts($db,1);
checkCategories(count($rows)===1&&$rows[0]['name']==='90mm petri dishes','Hiding a compound must remove it from customer catalogue');
checkCategories(ankhPublicType('90mm petri dishes')==='Research accessory','Petri dishes should be classified as lab accessories');
checkCategories(ankhPublicType('Retatrutide 10mg cartridge')!=='Research accessory','A peptide cartridge must not bypass accessory controls');
checkCategories(ankhPublicType('BPC-157 5mg')!=='Research accessory','Unapproved peptides must not be classified as lab accessories');
ankhCategoryAssign($db,101,0);
checkCategories(!isset(ankhProductCategories($db)[101]),'Clearing a category must work');
checkCategories((int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn()===2,'Categories affected product or order inventory');
echo "PASS: lab accessory categories, safe names, product assignment, published-only visibility, hidden-peptide gating and accessory classification.\n";
