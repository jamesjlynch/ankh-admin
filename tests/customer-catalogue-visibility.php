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
$inactiveRejected=false;
try{ankhCatalogueSetVisibility($db,4,true);}catch(InvalidArgumentException $e){$inactiveRejected=true;}
catalogueCheck($inactiveRejected,'Inactive products cannot be republished by accident');
catalogueCheck(count(ankhCatalogueProducts($db,1))===3,'Inactive products stay hidden');
catalogueCheck($before===$db->query('SELECT * FROM products')->fetchAll(),'Visibility leaves every existing product field unchanged');
$db->exec("UPDATE products SET name='Renamed reference sample',price=2200,stock_qty=0 WHERE id=3");
$renamed=array_values(array_filter(ankhCatalogueProducts($db,1),fn(array $p):bool=>$p['id']===3))[0];
catalogueCheck($renamed['name']==='Renamed reference sample' && $renamed['price']===2200 && $renamed['available_qty']===0,'Renaming and price/stock changes mirror without resetting visibility');
ankhCatalogueSetVisibility($db,3,false);
catalogueCheck(!in_array(3,array_column(ankhCatalogueProducts($db,1),'id'),true),'Unticking removes a product');
try{ankhCatalogueSetVisibility($db,999,true);throw new RuntimeException('Unknown product accepted');}catch(InvalidArgumentException $e){}
$imageBytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$photo=ankhCatalogueValidateImage($imageBytes);
$unchanged=$db->query('SELECT * FROM products')->fetchAll();
ankhCatalogueSaveContent($db,1,"Saved catalogue description\n<script>example</script>",$photo,false,true);
$withPhoto=array_values(array_filter(ankhCatalogueProducts($db,1),fn(array $p):bool=>$p['id']===1))[0];
catalogueCheck($withPhoto['has_photo'] && str_contains($withPhoto['description'],'Saved catalogue'),'Photo and description are included in public fields');
catalogueCheck(!isset($withPhoto['image_data']) && !isset($withPhoto['cost']),'Never put image blobs or costs into product list');
ankhCatalogueSaveContent($db,1,'Updated description',null,false,true);
catalogueCheck((int)ankhCatalogueContent($db)[1]['image_bytes']===strlen($imageBytes),'Saving text preserves existing photo');
ankhCatalogueSaveContent($db,1,'Updated description',null,true,true);
catalogueCheck((int)ankhCatalogueContent($db)[1]['image_bytes']===0,'Removing photo clears photo only');
catalogueCheck($unchanged===$db->query('SELECT * FROM products')->fetchAll(),'Catalogue content never changes existing product data');
foreach(['<svg onload="bad()"></svg>','<?php echo 1;'] as $invalid){try{ankhCatalogueValidateImage($invalid);throw new RuntimeException('Non-photo accepted');}catch(InvalidArgumentException $e){}}
try{ankhCatalogueSaveContent($db,1,str_repeat('a',40001),null,false,true);throw new RuntimeException('Overlong description accepted');}catch(InvalidArgumentException $e){}
$info=['strength'=>'10 mg','research'=>'Original research notes <script>test</script>','coa_url'=>'https://example.org/analysis.pdf'];
ankhCatalogueSaveContent($db,1,'Updated description',null,false,true,$info);
catalogueCheck(ankhCatalogueProducts($db,1)[0]['information']===$info,'Structured information persists and is exposed for published products');
ankhCatalogueSaveContent($db,1,'Updated description',null,false,true);
catalogueCheck(ankhCatalogueInformation($db)[1]===$info,'Older forms preserve structured information');
foreach(['javascript:alert(1)','http://example.org/test','https://user:secret@example.org/test'] as $url){try{ankhCatalogueSaveContent($db,1,'bad',null,false,false,['coa_url'=>$url]);throw new RuntimeException('Unsafe URL accepted');}catch(InvalidArgumentException $e){}}
catalogueCheck(ankhCatalogueInformation($db)[1]===$info && ankhCatalogueVisibility($db)[1]===1,'Validation errors preserve all saved metadata');
ankhCatalogueSaveContent($db,1,'Updated description',null,false,true,[]);
catalogueCheck(ankhCatalogueInformation($db)[1]===[],'Optional product information can be cleared');
catalogueCheck($unchanged===$db->query('SELECT * FROM products')->fetchAll(),'Structured information leaves product records unchanged');
$combined=ankhCatalogueEditorText('Existing overview',['strength'=>'10 mg','storage'=>'Label conditions','research'=>'Saved notes','coa_url'=>'https://example.org/analysis.pdf']);
catalogueCheck(str_contains($combined,'Existing overview') && str_contains($combined,'- Strength / quantity: 10 mg') && str_contains($combined,'Saved notes') && str_contains($combined,'https://example.org/analysis.pdf'),'One-box editor preserves every legacy detail');
$sections=ankhCatalogueSections("Opening paragraph.\n\n## Specifications\n- Format: reference material\n- **Quantity:** fixture\n\n## Notes\n<script>alert(1)</script>\n[Analysis](https://example.org/file.pdf)\n[Bad](javascript:alert(1))");
catalogueCheck(count($sections)===3 && $sections[1]['title']==='Specifications','Heading buttons create readable document sections');
$formatted=ankhCatalogueFormat($sections[1]['text']);
catalogueCheck(str_contains($formatted,'<ul>') && str_contains($formatted,'<strong>Quantity:</strong>'),'Bullets and emphasis render safely');
$formatted=ankhCatalogueFormat($sections[2]['text']);
catalogueCheck(!str_contains($formatted,'<script>') && str_contains($formatted,'&lt;script&gt;') && str_contains($formatted,'rel="noopener noreferrer"') && !str_contains($formatted,'href="javascript:'),'Document links and hostile input are safely rendered');
catalogueCheck(ankhCatalogueSummary("## Overview\nA **short** intro.\n\n## Other\nMore") === 'A short intro.','Preview text strips formatting');
ankhCatalogueSaveContent($db,1,$combined,null,false,true,[]);
catalogueCheck(ankhCatalogueInformation($db)[1]===[] && ankhCatalogueProducts($db,1)[0]['description']===$combined,'Unified save keeps information in one field without duplicated legacy sections');
$guided=['appearance'=>'White (fixture)','peptide_type'=>'Fixture compound','amino_length'=>'15 (fixture)','half_life'=>'Fixture experimental context','pubchem'=>'Fixture identifier','research_focus'=>'Fixture research focus','references'=>'Fixture paper https://pubmed.ncbi.nlm.nih.gov/123456/','prepared_storage'=>'Fixture preparation storage'];
ankhCatalogueSaveContent($db,1,'Product introduction',null,false,true,$guided);
catalogueCheck(ankhCatalogueInformation($db)[1]===$guided,'Guided Midshire-style information persists');
$document=ankhCatalogueEditorText('Product introduction',$guided);
foreach($guided as $value)catalogueCheck(str_contains($document,$value),'Each guided answer is included in the product document');
catalogueCheck(str_contains(ankhCatalogueFormat($guided['references']),'href="https://pubmed.ncbi.nlm.nih.gov/123456/"'),'Pasted literature URLs are clickable without formatting syntax');
catalogueCheck(!str_contains(ankhCatalogueFormat('https://user:secret@example.org/test'),'href='),'Credential-bearing links are never activated');
try{ankhCatalogueSaveContent($db,1,'bad',null,false,true,['amino_length'=>['bad']]);throw new RuntimeException('Array field accepted');}catch(InvalidArgumentException $e){}
catalogueCheck($unchanged===$db->query('SELECT * FROM products')->fetchAll(),'Guided fields leave original product data unchanged');
$db->exec('PRAGMA query_only=ON');
catalogueCheck(count(ankhCatalogueProducts($db,1))===2,'Customer catalogue reads through a read-only connection');
echo "Catalogue defaults, manual visibility, renamed products, pricing, stock and product preservation passed.\n";

$store=new PDO('sqlite::memory:');$store->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);ankhCustomerInteractionInit($store);
$product=['id'=>1,'name'=>'Fixture product','price'=>1500];
ankhCustomerSaveFavourite($store,$product,1,false,true);ankhCustomerSaveFavourite($store,$product,1,false,true);
catalogueCheck(ankhCustomerFavourites($store,1,false)===[1] && ankhCustomerFavourites($store,2,false)===[] && ankhCustomerFavourites($store,1,true)===[],'Favourites are idempotent and isolated by customer and staff test');
ankhCustomerSaveFavourite($store,$product,1,false,false);catalogueCheck(ankhCustomerFavourites($store,1,false)===[],'Favourites can be removed');
$token=str_repeat('a',64);$id=ankhCustomerCreateEnquiry($store,$product,1,false,'Fixture question <script>bad()</script>',$token);
catalogueCheck(ankhCustomerCreateEnquiry($store,$product,1,false,'Duplicate',$token)===$id && count(ankhCustomerEnquiries($store,1,false))===1,'Replayed enquiry submissions do not duplicate');
catalogueCheck(ankhCustomerEnquiries($store,2,false)===[] && ankhCustomerEnquiries($store,1,true)===[],'Enquiries are isolated by customer and preview');
ankhCustomerReply($store,$id,'Fixture answer','Answered');$row=ankhCustomerEnquiries($store,1,false)[0];
catalogueCheck($row['reply']==='Fixture answer' && $row['status']==='Answered' && $row['price']===1500,'Replies and account price snapshots persist');
try{ankhCustomerReply($store,$id,'','Answered');throw new RuntimeException('Blank answer accepted');}catch(InvalidArgumentException $e){}
for($i=0;$i<9;$i++)ankhCustomerCreateEnquiry($store,$product,1,false,'Question',hash('sha256',(string)$i));
try{ankhCustomerCreateEnquiry($store,$product,1,false,'Too many',hash('sha256','more'));throw new RuntimeException('Rate limit failed');}catch(InvalidArgumentException $e){}
echo "Customer favourite isolation, enquiry idempotency, replies, pricing snapshots and throttling passed.\n";

$linkDb=new PDO('sqlite::memory:');$linkDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$linkDb->exec("PRAGMA foreign_keys=ON; CREATE TABLE customers(id INTEGER PRIMARY KEY,name TEXT,phone TEXT);CREATE TABLE orders(id INTEGER PRIMARY KEY,customer TEXT,phone TEXT);
INSERT INTO customers VALUES(1,'Owner one','07123456789'),(2,'Ambiguous','07999000000'),(3,'Ambiguous','+447999000000'),(4,'','07888000000');
INSERT INTO orders VALUES(1,'Owner one','+447123456789'),(2,'Ambiguous','07999000000'),(3,'','07888000000');");
$linkBefore=$linkDb->query('SELECT * FROM orders')->fetchAll();ankhCatalogueLinkIdentities($linkDb);ankhCatalogueLinkIdentities($linkDb);
catalogueCheck((int)$linkDb->query('SELECT COUNT(*) FROM customer_order_links')->fetchColumn()===1,'Durable links only claim a unique named customer with a valid matching phone');
catalogueCheck($linkDb->query('SELECT * FROM orders')->fetchAll()===$linkBefore,'Durable linking preserves all original order data');
$linkDb->exec("UPDATE customers SET name='Renamed owner',phone='07777123456' WHERE id=1");ankhCatalogueLinkIdentities($linkDb);
catalogueCheck((int)$linkDb->query('SELECT customer_id FROM customer_order_links WHERE order_id=1')->fetchColumn()===1,'Durable ownership survives customer profile changes');
echo "Durable customer ownership linking and ambiguous identity rejection passed.\n";

// Disk-backed approval exercises the same two-database transaction as production.
$requestFile=tempnam(sys_get_temp_dir(),'ankh-requests-');$adminFile=tempnam(sys_get_temp_dir(),'ankh-review-');
try{
    $requests=new PDO('sqlite:'.$requestFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);ankhCustomerInteractionInit($requests);
    $review=new PDO('sqlite:'.$adminFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $review->exec("CREATE TABLE customers(id INTEGER PRIMARY KEY,name TEXT,phone TEXT,archived INTEGER);CREATE TABLE orders(id INTEGER PRIMARY KEY,customer TEXT,phone TEXT,status TEXT,delivery_date TEXT);CREATE TABLE reta_cycles(id INTEGER PRIMARY KEY,last_order_id INTEGER,product_name TEXT,next_due_date TEXT,cycle_weeks INTEGER,cycle_days INTEGER,presentation TEXT,quantity INTEGER,active INTEGER,updated TEXT);
    INSERT INTO customers VALUES(1,'Fixture owner','07123456789',0),(2,'Other owner','07999000000',0);INSERT INTO orders VALUES(1,'Fixture owner','07123456789','Delivered','2026-10-01');INSERT INTO reta_cycles VALUES(1,1,'Fixture product','2026-11-01',4,28,'Fixture',1,1,'original');");ankhCatalogueLinkIdentities($review);
    $reminder=function()use($review):array{$cycle=$review->query('SELECT * FROM reta_cycles WHERE id=1')->fetch();$order=$review->query('SELECT * FROM orders WHERE id=1')->fetch();return ['id'=>1,'product'=>$cycle['product_name'],'due_date'=>$cycle['next_due_date'],'weeks'=>(int)$cycle['cycle_weeks'],'last_order_id'=>1,'revision'=>ankhReminderSignature($cycle,$order)];};
    $date=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->modify('+30 days')->format('Y-m-d');$beforeReview=$review->query('SELECT * FROM reta_cycles')->fetchAll();
    $req=ankhReminderRequest($requests,1,true,$reminder(),6,$date,'Staff fixture',str_repeat('a',64));
    catalogueCheck($req===ankhReminderRequest($requests,1,true,$reminder(),6,$date,'Replay',str_repeat('a',64)),'Reminder request replay is idempotent');
    try{ankhReminderRequest($requests,1,true,$reminder(),6,$date,'Duplicate',str_repeat('b',64));throw new RuntimeException('Duplicate pending request accepted');}catch(InvalidArgumentException $e){}
    ankhReviewReminderRequest($review,$requests,$req,'approve','Test response');catalogueCheck($review->query('SELECT * FROM reta_cycles')->fetchAll()===$beforeReview,'Staff test approval never changes a live cycle');
    catalogueCheck($requests->query('SELECT status FROM customer_reminder_requests')->fetchColumn()==='Approved','Staff decision saved');
    $req=ankhReminderRequest($requests,1,false,$reminder(),6,$date,'Live fixture',str_repeat('c',64));
    catalogueCheck($review->query('SELECT * FROM reta_cycles')->fetchAll()===$beforeReview,'Creating request never changes Admin');
    ankhReviewReminderRequest($review,$requests,$req,'approve','Agreed');$cycle=$review->query('SELECT * FROM reta_cycles')->fetch();
    catalogueCheck((int)$cycle['cycle_weeks']===6 && (int)$cycle['cycle_days']===42 && $cycle['next_due_date']===$date,'Real approval updates the target interval and date');
    $req=ankhReminderRequest($requests,1,false,$reminder(),8,$date,'Stale fixture',str_repeat('d',64));$review->exec('UPDATE reta_cycles SET cycle_days=43 WHERE id=1');
    try{ankhReviewReminderRequest($review,$requests,$req,'approve','');throw new RuntimeException('Stale approval accepted');}catch(InvalidArgumentException $e){}
    catalogueCheck($requests->query('SELECT status FROM customer_reminder_requests WHERE id='.$req)->fetchColumn()==='Pending','Stale approval rolls back decision');
    $snapshot=$review->query('SELECT * FROM reta_cycles')->fetchAll();ankhReviewReminderRequest($review,$requests,$req,'decline','Please resubmit');catalogueCheck($review->query('SELECT * FROM reta_cycles')->fetchAll()===$snapshot,'Decline leaves schedule unchanged');
    $req=ankhReminderRequest($requests,2,false,$reminder(),8,$date,'Wrong owner',str_repeat('e',64));
    try{ankhReviewReminderRequest($review,$requests,$req,'approve','');throw new RuntimeException('Wrong owner accepted');}catch(InvalidArgumentException $e){}
    foreach([[0,$date],[53,$date],[4,'2026-02-30'],[4,'2000-01-01']] as [$weeks,$badDate]){try{ankhReminderRequest($requests,1,false,$reminder(),$weeks,$badDate,'',str_repeat('f',64));throw new RuntimeException('Invalid reminder change accepted');}catch(InvalidArgumentException $e){}}
    $lines=[['product_id'=>1,'name'=>'Fixture <script>','quantity'=>2,'unit_price'=>1500],['product_id'=>3,'name'=>'Other fixture','quantity'=>3,'unit_price'=>1200]];
    $combinedId=ankhCustomerCreateEnquiry($requests,['id'=>0,'name'=>'Combined fixture','price'=>6600],1,false,'Combined question',str_repeat('f',64),$lines);
    catalogueCheck(count(ankhCustomerEnquiryLines($requests,$combinedId))===2,'One combined enquiry stores all selected line snapshots');
    catalogueCheck(ankhCustomerCreateEnquiry($requests,['id'=>0,'name'=>'Combined fixture','price'=>6600],1,false,'Replay',str_repeat('f',64),$lines)===$combinedId && count(ankhCustomerEnquiryLines($requests,$combinedId))===2,'Combined enquiry replay does not duplicate lines');
    ob_start();ankhCustomerEnquiryLinesView($lines);$html=ob_get_clean();catalogueCheck(!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;'),'Combined line names are escaped');
    echo "Combined enquiry snapshots, reminder validation, replay, approval, staff isolation, ownership and stale-request rollback passed.\n";
}finally{$requests=null;$review=null;unlink($requestFile);unlink($adminFile);}
