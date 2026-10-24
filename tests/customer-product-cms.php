<?php
declare(strict_types=1);
require_once __DIR__.'/../customer-catalogue.php';
require_once __DIR__.'/../customer-portal/content-studio.php';
function cmsAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function cmsReject(callable $f):void{try{$f();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Expected CMS validation to reject this request.');}
cmsAssert(ankhStaffLoginDestination([])==='./preview-live.php?manage=portal&panel=products','Staff sign-in must default to CMS.');
cmsAssert(ankhStaffLoginDestination(['manage'=>'portal','panel'=>'contact','return'=>'https://example.com'])==='./preview-live.php?manage=portal&panel=contact','CMS destination must be internal and allowlisted.');
cmsAssert(ankhStaffLoginDestination(['manage'=>'portal','panel'=>['bad']])==='./preview-live.php?manage=portal&panel=products','Invalid panels must fall back to CMS.');
cmsAssert(ankhStaffLoginDestination(['customer_id'=>'12','tab'=>'orders'])==='./preview-live.php?preview=customers&customer_id=12&tab=orders','Customer preview deep links must survive sign-in.');
cmsAssert(ankhCatalogueFormat("## Product overview\n\n**Details**")==="<h3>Product overview</h3><p><strong>Details</strong></p>",'CMS headings and bold must render on the website.');
cmsAssert(!str_contains(ankhCatalogueFormat('## <script>alert(1)</script>'),'<script>'),'Formatted headings must escape HTML.');
$admin=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$admin->exec("CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,active INTEGER,price INTEGER,stock_jay_qty INTEGER);
CREATE TABLE customer_catalogue_visibility(product_id INTEGER PRIMARY KEY,visible INTEGER);
CREATE TABLE customer_catalogue_content(product_id INTEGER PRIMARY KEY,description TEXT,image_mime TEXT);
CREATE TABLE customer_catalogue_information(product_id INTEGER PRIMARY KEY,information TEXT);
INSERT INTO products VALUES(1,'BPC-157 5mg',1,4900,14),(2,'Hidden product',1,6700,23),(3,'Inactive product',0,5800,6);
INSERT INTO customer_catalogue_visibility VALUES(1,1),(2,0),(3,1);
INSERT INTO customer_catalogue_content VALUES(1,'Original catalogued product','image/png');
INSERT INTO customer_catalogue_information VALUES(1,'{\"formula\":\"ORIGINAL\"}');");
$store=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$before=$admin->query("SELECT price,stock_jay_qty FROM products WHERE id=1")->fetch(PDO::FETCH_ASSOC);
ankhCmsSetDraft($store,$admin,1,'New description',['formula'=>'TESTED'],[],false);
cmsAssert(ankhCmsRead($store,1)!==null,'Draft did not save');
$products=[['id'=>1,'name'=>'BPC-157 5mg','description'=>'Original catalogued product','information'=>['formula'=>'ORIGINAL'],'has_photo'=>true]];
cmsAssert(ankhCmsOverlay($store,$products)[0]['description']==='Original catalogued product','Draft became public before publication.');
ankhCmsPublish($store,$admin,1);
$public=ankhCmsOverlay($store,$products);
cmsAssert($public[0]['description']==='New description','Published description not visible.');
cmsAssert($public[0]['information']['formula']==='TESTED','Published product identity not visible.');
ankhCmsSetDraft($store,$admin,1,'Another unpublished draft',['formula'=>'DRAFT'],[],false);
cmsAssert(ankhCmsOverlay($store,$products)[0]['description']==='New description','Unpublished second draft leaked.');
cmsReject(static fn()=>ankhCmsPublish($store,$admin,2));
cmsReject(static fn()=>ankhCmsPublish($store,$admin,3));
cmsAssert($admin->query("SELECT price,stock_jay_qty FROM products WHERE id=1")->fetch(PDO::FETCH_ASSOC)===$before,'Price/stock changed in Order Admin.');
ankhCmsUnpublish($store,1);
cmsAssert(ankhCmsOverlay($store,$products)[0]['description']==='Original catalogued product','Unpublished override not reverted.');
echo "PASS: CMS drafts/publish/rollback; original product prices and stock untouched; hidden products blocked.\n";
