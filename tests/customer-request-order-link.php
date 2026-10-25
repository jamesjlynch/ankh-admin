<?php
declare(strict_types=1);
/** Real-owner privacy tests for the explicit guest receipt -> existing Admin order link. */
require_once dirname(__DIR__).'/customer-portal/lib.php';
function verifyLink(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pdo=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$store=new PDO('sqlite::memory:',null,null,$pdo);
$admin=new PDO('sqlite::memory:',null,null,$pdo);
$store->exec("CREATE TABLE storefront_guest_requests(
 id INTEGER PRIMARY KEY,placed_at INTEGER,name TEXT,email TEXT,phone TEXT,address TEXT,
 note TEXT,lines_json TEXT,subtotal INTEGER,status TEXT);
 INSERT INTO storefront_guest_requests VALUES
 (10,1792756800,'Valid Person','private@example.test','07700900123','Private residence','',
 '[{\"id\":8,\"name\":\"Accessory case\",\"quantity\":1,\"unit_price\":1900}]',1900,'Pending staff review'),
 (20,1792756800,'Other Person','other@example.test','07700900456','Other residence','',
 '[]',0,'Reviewed — create in Admin manually'),
 (30,1792756800,'Valid Person','private@example.test','07700900123','Private residence','',
 '[{\"id\":9,\"name\":\"Retatrutide 10mg\",\"quantity\":1,\"unit_price\":6500}]',6500,'Reviewed — create in Admin manually'),
 (40,1792756800,'Valid Person','private@example.test','07700900123','Private residence','',
 '[{\"id\":8,\"name\":\"Accessory case\",\"quantity\":1,\"unit_price\":1900}]',1900,'Reviewed — create in Admin manually');");
$admin->exec("CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,active INTEGER,price INTEGER);
 INSERT INTO products VALUES (8,'Accessory case',1,1900),(9,'Retatrutide 10mg',1,6500);
 CREATE TABLE customer_catalogue_visibility(product_id INTEGER PRIMARY KEY,visible INTEGER);
 INSERT INTO customer_catalogue_visibility VALUES(8,1),(9,1);
 CREATE TABLE customers(id INTEGER PRIMARY KEY,name TEXT,phone TEXT,address TEXT,archived INTEGER);
 INSERT INTO customers VALUES(100,'Valid Person','07700900123','Private residence',0);
 INSERT INTO customers VALUES(200,'Other Person','07700900456','Other residence',0);
 CREATE TABLE orders(id INTEGER PRIMARY KEY,customer TEXT,phone TEXT,created TEXT,status TEXT,
 delivery_date TEXT,scheduled_delivery_date TEXT,delivery_charge INTEGER,packaged INTEGER,reconstituted INTEGER,
 delivery_method TEXT,tracking_reference TEXT);
 INSERT INTO orders VALUES
 (70,'Valid Person','07700900123','2026-10-23','New','','',0,0,0,'',''),
 (80,'Other Person','07700900456','2026-10-23','New','','',0,0,0,'',''),
 (90,'Valid Person','07700900123','2026-10-23','New','','',0,0,0,'',''),
 (71,'Valid Person','07700900123','2026-10-24','New','','',0,0,0,'','');
 CREATE TABLE customer_order_links(order_id INTEGER PRIMARY KEY,customer_id INTEGER);
 INSERT INTO customer_order_links VALUES(70,100),(80,200),(90,200),(71,100);
 CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER,name TEXT,presentation TEXT,price INTEGER,quantity INTEGER,product_id INTEGER);
 INSERT INTO items VALUES (1,70,'Accessory case','Standard',1900,1,8),(2,71,'Accessory case','Standard',1900,2,8);
 CREATE TABLE payments(id INTEGER PRIMARY KEY,order_id INTEGER,amount INTEGER,created TEXT,method TEXT);");
sfCommerceInit($store);
 $store->exec("INSERT INTO storefront_checkout_eligibility(product_id,approved,reviewer,review_note,updated_at)
 VALUES(8,1,'Research admin','Approved non-medical research accessory',1792756800),
       (9,1,'Research admin','Synthetic compound should not become an accessory',1792756800)");
ankhGuestTrackingInit($store);
ankhGuestOrderLinkInit($store);
$code=ankhGuestTrackingIssue($store,10);
verifyLink(is_string($code),'Receipt token missing');
verifyLink(ankhGuestTrackingRows($store,100)===[],'Guest request exposed before claim');
$throws=static function(callable $action):bool{
 try{$action();}catch(InvalidArgumentException $e){return true;}return false;
};
verifyLink($throws(static fn()=>ankhGuestOrderLink($store,$admin,10,70,'Tester')),'Pending request cannot link order');
$store->exec("UPDATE storefront_guest_requests SET status='Reviewed — create in Admin manually' WHERE id=10");
verifyLink(ankhGuestTrackingClaim($store,100,portalMobile('07700900123'),(string)$code)===10,'Claim could not be linked securely');
verifyLink($throws(static fn()=>ankhGuestOrderLink($store,$admin,10,80,'Tester')),'Wrong phone must block linking another customer');
verifyLink($throws(static fn()=>ankhGuestOrderLink($store,$admin,10,90,'Tester')),'Matching phone must not override explicit wrong Admin owner link');
$before=ankhRequestMatchSuggestions($store,$admin,10);
verifyLink(count($before)===2,'Expected two candidate orders with matching approved accessory identity');
verifyLink((int)$before[0]['order_id']===70 && $before[0]['exact_quantities']===true,'Exact-quantity matching order should rank first');
verifyLink((int)$before[1]['order_id']===71 && $before[1]['exact_quantities']===false,'Quantity differences must be visibly flagged');
verifyLink($throws(static fn()=>ankhRequestMatchValidate($store,$admin,10,90)),'Matching phone but wrong saved Admin customer cannot pass');
verifyLink($throws(static fn()=>ankhRequestMatchSuggestions($store,$admin,30)),'Research compound must never generate a transactional order suggestion');
$token=ankhRequestMatchToken($before[0],str_repeat('f',32),10);
verifyLink(strlen($token)===64&&$token!==ankhRequestMatchToken($before[1],str_repeat('f',32),10),'Suggested proof must bind to exact Admin order evidence');
verifyLink($throws(static fn()=>ankhRequestMatchToken($before[0],'short',10)),'Missing CSRF secret must block link proof');
ankhGuestOrderLink($store,$admin,10,70,'Tester');
ankhGuestOrderLink($store,$admin,10,70,'Tester');
$rows=ankhGuestTrackingRows($store,100);
verifyLink(count($rows)===1&&(int)$rows[0]['linked_order_id']===70,'Claimed receipt not linked to its real Admin order');
verifyLink(!isset($rows[0]['phone'])&&!isset($rows[0]['address'])&&!isset($rows[0]['email']),'Guest personal details leaked');
verifyLink(ankhGuestTrackingRows($store,200)===[],'Request leaked to unrelated account');
verifyLink($throws(static fn()=>ankhGuestOrderLink($store,$admin,20,70,'Tester')),'One existing order may not be linked to another request');
$q=$admin->query('SELECT status,delivery_date,packaged,reconstituted FROM orders WHERE id=70')->fetch();
verifyLink($q['status']==='New'&&$q['delivery_date']===''&&(int)$q['packaged']===0,'Portal changed the authoritative Admin order');
verifyLink($throws(static fn()=>ankhGuestOrderLink($store,$admin,40,70,'Tester')),'A second request must not hijack an existing linked order');
$remaining=ankhRequestMatchSuggestions($store,$admin,10);
verifyLink(count($remaining)===1 && (int)$remaining[0]['order_id']===71,'Already linked orders must be excluded from other suggestions');
$store->exec('UPDATE storefront_checkout_eligibility SET approved=0 WHERE product_id=8');
verifyLink($throws(static fn()=>ankhRequestMatchValidate($store,$admin,10,71)),'Revoking accessory approval must invalidate stale candidate evidence');
verifyLink((int)$store->query('SELECT count(*) FROM portal_guest_order_links')->fetchColumn()===1,'Unexpected owner mapping written');
echo "PASS: explicit staff request-to-existing-order link enforces verified receipt, matching phone, Admin owner identity, cross-account privacy and read-only Admin data.\n";
