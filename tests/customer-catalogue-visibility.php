<?php
declare(strict_types=1);
require dirname(__DIR__).'/customer-catalogue.php';
function catalogueCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,price INTEGER,active INTEGER,cost INTEGER,stock_tracking INTEGER,stock_qty INTEGER,family_friends_price INTEGER,description TEXT);
CREATE TABLE customers(id INTEGER PRIMARY KEY,archived INTEGER,pricing_type TEXT);
INSERT INTO customers VALUES(1,0,'standard'),(2,0,'family_friends'),(3,0,'free');
INSERT INTO products VALUES(1,'Case',2000,1,700,1,4,1500,'Existing case description'),(2,'Retatrutide 10mg',6500,1,2000,1,8,NULL,'Private'),(3,'Reference sample A',1500,1,500,1,10,NULL,'Existing research description'),(4,'Label sheets',500,0,100,0,NULL,NULL,''),(5,'New name not on any list',900,1,200,0,NULL,NULL,'');");
$before=$db->query('SELECT * FROM products')->fetchAll();
catalogueCheck(ankhCatalogueProducts($db,1)===[],'Missing metadata defaults to hidden');
ankhCatalogueInit($db);ankhCatalogueInit($db);
catalogueCheck(ankhCatalogueProducts($db,1)===[],'All products start unchecked');
ankhCatalogueSetVisibility($db,1,true);
$products=ankhCatalogueProducts($db,1);
catalogueCheck(count($products)===1 && $products[0]['name']==='Case' && $products[0]['price']===2000 && $products[0]['available_qty']===4,'Mirror existing name, price and stock');
catalogueCheck($products[0]['description']==='Existing case description','Preserve existing description');
catalogueCheck(!array_key_exists('cost',$products[0]),'Never expose internal cost');
catalogueCheck(ankhCatalogueProducts($db,2)[0]['price']===1500,'Use customer family price');
catalogueCheck(ankhCatalogueProducts($db,3)[0]['price']===0,'Respect free account pricing');
ankhCatalogueSetVisibility($db,3,true);ankhCatalogueSetVisibility($db,5,true);
catalogueCheck(count(ankhCatalogueProducts($db,1))===3,'Visibility is selectable regardless of product name');
$ids=array_column(ankhCatalogueProducts($db,1),'id');
catalogueCheck(!in_array(2,$ids,true),'Unticked Retatrutide stays hidden');
ankhCatalogueSetVisibility($db,4,true);
catalogueCheck(count(ankhCatalogueProducts($db,1))===3,'Inactive products remain hidden even if ticked');
catalogueCheck($before===$db->query('SELECT * FROM products')->fetchAll(),'Visibility leaves every existing product field unchanged');
$db->exec("UPDATE products SET name='Renamed reference sample',price=2200,stock_qty=0 WHERE id=3");
$renamed=array_values(array_filter(ankhCatalogueProducts($db,1),fn(array $p):bool=>$p['id']===3))[0];
catalogueCheck($renamed['name']==='Renamed reference sample' && $renamed['price']===2200 && $renamed['available_qty']===0,'Renaming and price/stock changes mirror without resetting visibility');
ankhCatalogueSetVisibility($db,3,false);
catalogueCheck(!in_array(3,array_column(ankhCatalogueProducts($db,1),'id'),true),'Unticking removes a product');
try{ankhCatalogueSetVisibility($db,999,true);throw new RuntimeException('Unknown product accepted');}catch(InvalidArgumentException $e){}
$db->exec('PRAGMA query_only=ON');
catalogueCheck(count(ankhCatalogueProducts($db,1))===2,'Customer catalogue reads through a read-only connection');
echo "Catalogue defaults, manual visibility, renamed products, pricing, stock and product preservation passed.\n";
