<?php
declare(strict_types=1);
/** Isolated CMS regression. Never opens the production database. */
require dirname(__DIR__).'/customer-catalogue.php';
function cmsVerify(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf():void{echo '<input type="hidden" name="csrf" value="test">';}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON;CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,active INTEGER NOT NULL,price INTEGER NOT NULL,stock_qty INTEGER NOT NULL);');
$db->exec("INSERT INTO products(id,name,active,price,stock_qty) VALUES(1,'BPC-157 5mg',1,2999,24)");
ankhCatalogueInit($db);
ankhCatalogueSaveContent($db,1,"Example laboratory research information.\n\n## Specifications\n- Form: vial",null,false,true,['storage'=>'Store as indicated by validated laboratory documentation.','coa_url'=>'https://example.org/analysis.pdf','sds_url'=>'https://example.org/safety-data-sheet.pdf']);
$contents=ankhCatalogueContent($db);
cmsVerify(($contents[1]['description']??'')!=='','Content save failed.');
cmsVerify(($contents[1]['information']['storage']??'')!=='','Technical field was not persisted.');
cmsVerify(($contents[1]['information']['sds_url']??'')==='https://example.org/safety-data-sheet.pdf','Product SDS URL must persist.');
try {ankhCatalogueValidateInformation(['sds_url'=>'javascript:alert(1)']);throw new RuntimeException('Unsafe SDS link accepted.');}catch(InvalidArgumentException $expected){}
cmsVerify((int)(ankhCatalogueVisibility($db)[1]??0)===1,'Visibility must persist independently.');
$row=$db->query('SELECT price,stock_qty FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC);
cmsVerify((int)$row['price']===2999&&(int)$row['stock_qty']===24,'Saving CMS content must not alter Order Admin prices or stock.');
$_GET['id']='1';
ob_start();include dirname(__DIR__).'/customer-cms.php';$html=ob_get_clean();
foreach(['Product description','name="catalogue_description"','data-catalogue-document','name="catalogue_information[storage]"','name="catalogue_information[sds_url]"','name="catalogue_ids[]"','Save website content','Order Admin'] as $expected) cmsVerify(str_contains($html,$expected),'CMS view missing '.$expected);
cmsVerify(!str_contains($html,'name="price"')&&!str_contains($html,'name="stock_qty"'),'CMS must not expose operational price or stock inputs.');
$index=file_get_contents(dirname(__DIR__).'/index.php');
$publisher=file_get_contents(dirname(__DIR__).'/scripts/publish-live.sh');
cmsVerify(str_contains($index,"elseif(\$view==='website_cms')"),'CMS must load only inside authenticated admin view.');
foreach(['customer-cms.php','customer-cms.css','customer-cms.js'] as $asset)cmsVerify(str_contains($publisher,$asset),'Publisher missing '.$asset);
ankhCatalogueSetVisibility($db,1,false);
cmsVerify((int)(ankhCatalogueVisibility($db)[1]??1)===0,'Visibility hide did not persist.');
$row=$db->query('SELECT price,stock_qty FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC);
cmsVerify((int)$row['price']===2999&&(int)$row['stock_qty']===24,'Visibility changes must never alter stock or price.');
echo "PASS: website CMS content and visibility are connected; price and stock remain unchanged.\n";
