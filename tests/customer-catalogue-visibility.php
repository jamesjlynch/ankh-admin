<?php
declare(strict_types=1);
require dirname(__DIR__).'/customer-catalogue.php';
function catalogueCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,price INTEGER,active INTEGER,cost INTEGER,stock_tracking INTEGER,stock_qty INTEGER,family_friends_price INTEGER,description TEXT);
CREATE TABLE customers(id INTEGER PRIMARY KEY,archived INTEGER,pricing_type TEXT);
INSERT INTO customers VALUES(1,0,'standard'),(2,0,'family_friends'),(3,0,'free');
INSERT INTO products VALUES(1,'Case',2000,1,700,1,4,1500,'Existing case description'),(2,'Retatrutide 10mg',6500,1,2000,1,8,NULL,'Private'),(3,'BPC-157 5mg',1500,1,500,1,10,NULL,'Private'),(4,'Label sheets',500,0,100,0,NULL,NULL,''),(5,'Pen',1500,1,500,1,10,NULL,'');");
$before=$db->query('SELECT * FROM products')->fetchAll();
catalogueCheck(ankhCatalogueProducts($db,1)===[],'Legacy database without metadata defaults to hidden');
ankhCatalogueInit($db);ankhCatalogueInit($db);
catalogueCheck(ankhCatalogueProducts($db,1)===[],'No product is automatically published');
ankhCatalogueSetVisibility($db,1,true);
$products=ankhCatalogueProducts($db,1);
catalogueCheck(count($products)===1 && $products[0]['name']==='Case' && $products[0]['price']===2000 && $products[0]['available_qty']===4,'Use existing Admin name, price and available stock');
catalogueCheck($products[0]['description']==='Existing case description','Use existing descriptions only');
catalogueCheck(!array_key_exists('cost',$products[0]),'Never expose internal cost');
catalogueCheck(ankhCatalogueProducts($db,2)[0]['price']===1500,'Use this customer family price');
catalogueCheck(ankhCatalogueProducts($db,3)[0]['price']===0,'Respect free account pricing');
foreach([2,3,5] as $id){
    try{ankhCatalogueSetVisibility($db,$id,true);throw new RuntimeException('Excluded product accepted');}catch(InvalidArgumentException $e){}
    // A forged/stale metadata row still cannot expose unsupported products.
    $db->exec("INSERT INTO customer_catalogue_visibility VALUES($id,1,'test')");
}
catalogueCheck(count(ankhCatalogueProducts($db,1))===1,'Apply eligibility when reading, not just when saving');
ankhCatalogueSetVisibility($db,4,true);
catalogueCheck(count(ankhCatalogueProducts($db,1))===1,'Inactive product stays hidden even if ticked');
catalogueCheck($before===$db->query('SELECT * FROM products')->fetchAll(),'Changing visibility leaves every product field unchanged');
$db->exec('UPDATE products SET price=2200,stock_qty=0 WHERE id=1');
catalogueCheck(ankhCatalogueProducts($db,1)[0]['price']===2200 && ankhCatalogueProducts($db,1)[0]['available_qty']===0,'Admin edits appear on next catalogue read');
ankhCatalogueSetVisibility($db,1,false);
catalogueCheck(ankhCatalogueProducts($db,1)===[],'Unticking removes the product');
$db->exec("UPDATE products SET active=1 WHERE id=4");ankhCatalogueSetVisibility($db,4,true);
$db->exec('PRAGMA query_only=ON');
catalogueCheck(count(ankhCatalogueProducts($db,1))===1,'Customer catalogue works through read-only connection');
echo "Customer catalogue defaults, visibility, exclusions, personalised pricing, live stock and product preservation passed.\n";
