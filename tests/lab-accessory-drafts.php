<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lab-accessory-drafts.php';
require_once dirname(__DIR__).'/customer-catalogue.php';
function labAssert(bool $v,string $message):void{if(!$v)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,price INTEGER NOT NULL,active INTEGER NOT NULL DEFAULT 1,cost INTEGER DEFAULT NULL,
low_stock_at INTEGER NOT NULL DEFAULT 5,supplier_pack_size INTEGER NOT NULL DEFAULT 1,stock_qty INTEGER DEFAULT NULL,stock_tracking INTEGER NOT NULL DEFAULT 0);
INSERT INTO products(name,price,active,cost) VALUES('Existing peptide',3500,1,1500);");
$previous=$db->query("SELECT * FROM products WHERE name='Existing peptide'")->fetch();
$defs=ankhLabAccessoryDrafts();
labAssert(count($defs)===12,'Expected twelve accessory product definitions');
labAssert(count(array_unique(array_column($defs,'sku')))==12,'Duplicate SKU');
labAssert(count(array_unique(array_column($defs,'name')))==12,'Duplicate product name');
foreach($defs as $row){
  labAssert(is_string($row['description'])&&strlen($row['description'])>170,'Each item needs a substantive original description');
  labAssert(!preg_match('/\b(injection|dose|human administration recommended)\b/i',$row['description']),'Unsafe medical presentation in accessories');
  if($row['amazon']!==null)labAssert(preg_match('/^https:\/\/bigshopper\.co\.uk\/product\//',$row['url'])===1,'Amazon-linked reference URL absent');
}
$result=ankhLabAccessoriesSeed($db);
labAssert($result['created']===12&&$result['skipped']===0,'Expected twelve new products');
$rows=$db->query("SELECT * FROM products WHERE name<>'Existing peptide'")->fetchAll();
labAssert(count($rows)===12,'Product records not created');
$source=$db->query("SELECT * FROM lab_accessory_sourcing ORDER BY sku")->fetchAll();
labAssert(count($source)===12,'Private sourcing records incomplete');
$confirmed=0;$pending=0;
foreach($source as $record){
 $q=$db->prepare('SELECT p.*,v.visible,c.description,cat.name AS category_name FROM products p
  JOIN customer_catalogue_visibility v ON v.product_id=p.id
  JOIN customer_catalogue_content c ON c.product_id=p.id
  JOIN catalogue_product_categories m ON m.product_id=p.id
  JOIN catalogue_categories cat ON cat.id=m.category_id WHERE p.id=?');
 $q->execute([(int)$record['product_id']]);$product=$q->fetch();
 labAssert((bool)$product,'Product was not assigned to a category and draft metadata');
 labAssert((int)$product['active']===0 && (int)$product['visible']===0,'Draft products must be inactive and invisible');
 labAssert(strlen($product['description'])>170,'Description not saved in CMS');
 labAssert((int)$product['stock_tracking']===0,'No dummy inventory');
 if($record['amazon_reference_pence']===null){
  $pending++;
  labAssert((int)$product['price']===0 && $product['cost']===null && $record['price_status']==='needs_amazon_price','Unverified quote incorrectly invented');
 }else{
  $confirmed++;
  labAssert((int)$product['price']===(int)$record['amazon_reference_pence']+300,'Price must equal Amazon-linked price plus exactly 300p');
  labAssert((int)$product['cost']===(int)$record['amazon_reference_pence'],'Saved cost must match cited offer');
 }
}
labAssert($confirmed===4&&$pending===8,'Expected four sourced comparisons and eight awaiting exact Amazon quotes');
labAssert($db->query("SELECT * FROM products WHERE name='Existing peptide'")->fetch()===$previous,'Existing product changed');
labAssert(ankhCatalogueProducts($db,1)===[],'Draft accessories must never appear in customer-facing catalogue');
$second=ankhLabAccessoriesSeed($db);
labAssert($second['created']===0&&$second['skipped']===12,'Safe rerun must never duplicate or alter products');
labAssert(count($db->query("SELECT id FROM products")->fetchAll())===13,'Rerun added duplicate products');
echo "PASS: twelve unique accessory drafts, four sourced price+£3 records, eight unpriced hidden drafts, existing-product preservation, accurate categories and idempotent provisioning.\n";
