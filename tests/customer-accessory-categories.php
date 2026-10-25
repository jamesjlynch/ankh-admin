<?php
declare(strict_types=1);
/** Synthetic catalogue fixture; no live product, payment or customer data. */
require_once dirname(__DIR__).'/customer-portal/lib.php';
function accessoryCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$admin=new PDO('sqlite::memory:',null,null,$opts);
$store=new PDO('sqlite::memory:',null,null,$opts);
$admin->exec("CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,price INTEGER,active INTEGER);
CREATE TABLE customer_catalogue_visibility(product_id INTEGER PRIMARY KEY,visible INTEGER);
CREATE TABLE customer_catalogue_content(product_id INTEGER PRIMARY KEY,description TEXT,image_mime TEXT);
CREATE TABLE catalogue_categories(id INTEGER PRIMARY KEY,name TEXT,kind TEXT);
CREATE TABLE catalogue_product_categories(product_id INTEGER PRIMARY KEY,category_id INTEGER);
INSERT INTO products VALUES(1,'90mm Petri Dishes',750,1),(2,'Retatrutide 10mg cartridge',6500,1),(3,'Glass Beaker',850,1),(4,'Hidden Lab Pipette',500,1);
INSERT INTO customer_catalogue_visibility VALUES(1,1),(2,1),(3,1),(4,0);
INSERT INTO customer_catalogue_content VALUES(1,'Laboratory petri dishes',''),(2,'Research compound',''),(3,'Glass laboratory beaker',''),(4,'Lab pipette','');
INSERT INTO catalogue_categories VALUES(1,'Petri Dishes','accessory'),(2,'Laboratory Equipment','accessory');
INSERT INTO catalogue_product_categories VALUES(1,1),(2,1),(3,2),(4,2);");
$rows=ankhPublicCatalogueProducts($admin);
accessoryCheck(count($rows)===3,'Only approved visible products may appear in catalogue');
$map=[];foreach($rows as $row)$map[(int)$row['id']]=$row;
accessoryCheck($map[1]['category']==='Petri Dishes','Published petri dish category did not reach storefront');
accessoryCheck($map[3]['category']==='Laboratory Equipment','Published beaker category did not reach storefront');
accessoryCheck(!isset($map[4]),'Hidden laboratory accessory appeared despite Order Admin visibility');
accessoryCheck(ankhPublicType('90mm Petri Dishes')==='Research accessory','Petri dishes not recognised as lab accessories');
accessoryCheck(ankhPublicType('Retatrutide 10mg cartridge')!=='Research accessory','Peptide cartridge falsely treated as accessory');
sfCommerceInit($store);
$store->exec("INSERT INTO storefront_checkout_eligibility VALUES
 (1,1,'Staff','Labware eligibility reviewed',1720000000),
 (2,1,'Staff','A bad approval must be blocked by classification',1720000000),
 (3,1,'Staff','Labware eligibility reviewed',1720000000),
 (4,1,'Staff','Labware eligibility reviewed',1720000000)");
$eligible=sfCommerceEligible($store,$admin);
accessoryCheck(isset($eligible[1],$eligible[3]),'Explicitly approved published labware should be eligible');
accessoryCheck(!isset($eligible[2]),'Research compound incorrectly enabled by category or accessory approval');
accessoryCheck(!isset($eligible[4]),'Unpublished labware incorrectly enabled for checkout');
$admin->exec('UPDATE customer_catalogue_visibility SET visible=0 WHERE product_id=1');
accessoryCheck(!isset(sfCommerceEligible($store,$admin)[1]),'Hiding an accessory must revoke checkout visibility immediately');
accessoryCheck(count(ankhPublicCatalogueProducts($admin))===2,'Hidden product must disappear from public catalogue');
require_once dirname(__DIR__).'/customer-portal/storefront-design.php';
if(!function_exists('pfType')){function pfType(string $name):string{return ankhPublicType($name);}}
if(!function_exists('pfCategory')){function pfCategory(array $p):string{return trim((string)($p['category']??''))!==''?(string)$p['category']:pfType((string)$p['name']);}}
if(!function_exists('pfEscape')){function pfEscape(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}
accessoryCheck(count(pfRelated($map[1],array_values($map)))===1,'Labware recommendations must exclude a research compound from accessory detail pages');
accessoryCheck((int)pfRelated($map[1],array_values($map))[0]['id']===3,'Related products should stay within real published accessory catalogue');
accessoryCheck(pfCategory($map[1])==='Petri Dishes','Source-of-truth category must be available to category browse');
$labCategories=[];
foreach($map as $product)if(pfType((string)$product['name'])==='Research accessory')$labCategories[pfCategory($product)]=true;
accessoryCheck(count($labCategories)===2 && !isset($labCategories['Research compound']),'Only real labware categories may populate the department');
echo "PASS: custom labware categories propagate safely; visibility hides products; peptide checkout stays blocked; accessory recommendations stay in approved labware.\n";
