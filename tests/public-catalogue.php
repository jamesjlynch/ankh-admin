<?php
declare(strict_types=1);
require dirname(__DIR__).'/public-catalogue-core.php';
require dirname(__DIR__).'/customer-catalogue.php';
function checkPublic(bool $value,string $message):void { if (!$value) throw new RuntimeException($message); }
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON;CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,price INTEGER,active INTEGER,stock_qty INTEGER,cost INTEGER);
INSERT INTO products VALUES(1,"KPV 10mg",2299,1,6,450),(2,"Retatrutide 10mg",4500,1,2,900),(3,"Pen",2000,0,3,500);');
$before=$db->query('SELECT * FROM products ORDER BY id')->fetchAll();
checkPublic(ankhPublicCatalogueProducts($db)===[],'Public index must default to closed');
ankhPublicReviewInit($db);
checkPublic(ankhPublicCatalogueProducts($db)===[],'Creating review table must not publish anyone');
ankhCatalogueInit($db);
ankhCatalogueSaveContent($db,1,'KPV technical description of chemical identity and laboratory literature; technical use only.',null,false,true,['sequence'=>'Lys-Pro-Val','research'=>'Preclinical models','purity'=>'']);
checkPublic(ankhPublicCatalogueProducts($db)===[],'Customer visibility must not grant public visibility');
try {
 ankhPublicReviewSave($db,1,'approve',['reviewed_by'=>'A Person','review_basis'=>str_repeat('a',100),'checked_classification'=>'1']);
 throw new RuntimeException('Incomplete attestation accepted');
} catch(InvalidArgumentException $e){}
try {
 ankhPublicReviewSave($db,3,'approve',['reviewed_by'=>'A Person','review_basis'=>str_repeat('a',100),'checked_classification'=>'1','checked_advertising'=>'1','checked_documents'=>'1']);
 throw new RuntimeException('Inactive product approved');
} catch(InvalidArgumentException $e){}
ankhPublicReviewSave($db,1,'approve',[
'reviewed_by'=>'External reviewer','review_basis'=>'Independent professional regulatory review reference 2026-10-23, product classification, intended use and non-promotional copy checked.',
'checked_classification'=>'1','checked_advertising'=>'1','checked_documents'=>'1',
]);
$rows=ankhPublicCatalogueProducts($db);
checkPublic(count($rows)===1 && $rows[0]['name']==='KPV 10mg','Only separately reviewed profile may be public');
checkPublic(!isset($rows[0]['price'],$rows[0]['cost'],$rows[0]['stock_qty']),'Public query must not disclose business fields');
checkPublic(isset($rows[0]['information']['sequence']) && !isset($rows[0]['information']['coa_url']),'Science allowed, COA not auto-claimed');
checkPublic(str_contains(ankhPublicNotice(),'Not for human or animal consumption'),'Every public profile has prominent health disclaimer');
ankhCatalogueSaveContent($db,1,'Revised laboratory-only description of chemical identity and experimental evidence.',null,false,true,['research'=>'Preclinical models']);
checkPublic(ankhPublicCatalogueProducts($db)===[],'Editing a reviewed profile revokes public release');
ankhPublicReviewSave($db,1,'approve',[
'reviewed_by'=>'External reviewer','review_basis'=>'Independent professional regulatory review reference 2026-10-23, product classification, intended use and non-promotional copy checked.',
'checked_classification'=>'1','checked_advertising'=>'1','checked_documents'=>'1',
]);
ankhPublicReviewSave($db,1,'hide',[]);
checkPublic(ankhPublicCatalogueProducts($db)===[],'Revocation must immediately unpublish');
checkPublic($before===$db->query('SELECT * FROM products ORDER BY id')->fetchAll(),'Names, prices, costs, stock and activation must not change');
echo "Public catalogue gate, review attestations, medical warnings, confidentiality and product protection passed.\n";
