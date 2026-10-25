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
 (10,1720000000,'Valid Person','private@example.test','07700900123','Private residence','',
 '[{\"name\":\"Accessory case\",\"quantity\":1,\"unit_price\":1900}]',1900,'Pending staff review'),
 (20,1720000000,'Other Person','other@example.test','07700900456','Other residence','',
 '[]',0,'Reviewed — create in Admin manually');");
$admin->exec("CREATE TABLE customers(id INTEGER PRIMARY KEY,name TEXT,phone TEXT,address TEXT,archived INTEGER);
 INSERT INTO customers VALUES(100,'Valid Person','07700900123','Private residence',0);
 INSERT INTO customers VALUES(200,'Other Person','07700900456','Other residence',0);
 CREATE TABLE orders(id INTEGER PRIMARY KEY,customer TEXT,phone TEXT,created TEXT,status TEXT,
 delivery_date TEXT,scheduled_delivery_date TEXT,delivery_charge INTEGER,packaged INTEGER,reconstituted INTEGER,
 delivery_method TEXT,tracking_reference TEXT);
 INSERT INTO orders VALUES
 (70,'Valid Person','07700900123','2026-10-23','New','','',0,0,0,'',''),
 (80,'Other Person','07700900456','2026-10-23','New','','',0,0,0,'',''),
 (90,'Valid Person','07700900123','2026-10-23','New','','',0,0,0,'','');
 CREATE TABLE customer_order_links(order_id INTEGER PRIMARY KEY,customer_id INTEGER);
 INSERT INTO customer_order_links VALUES(70,100),(80,200),(90,200);
 CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER,name TEXT,presentation TEXT,price INTEGER,quantity INTEGER,product_id INTEGER);
 INSERT INTO items VALUES (1,70,'Accessory case','Standard',1900,1,8);
 CREATE TABLE payments(id INTEGER PRIMARY KEY,order_id INTEGER,amount INTEGER,created TEXT,method TEXT);");
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
ankhGuestOrderLink($store,$admin,10,70,'Tester');
ankhGuestOrderLink($store,$admin,10,70,'Tester');
$rows=ankhGuestTrackingRows($store,100);
verifyLink(count($rows)===1&&(int)$rows[0]['linked_order_id']===70,'Claimed receipt not linked to its real Admin order');
verifyLink(!isset($rows[0]['phone'])&&!isset($rows[0]['address'])&&!isset($rows[0]['email']),'Guest personal details leaked');
verifyLink(ankhGuestTrackingRows($store,200)===[],'Request leaked to unrelated account');
verifyLink($throws(static fn()=>ankhGuestOrderLink($store,$admin,20,70,'Tester')),'One existing order may not be linked to another request');
$q=$admin->query('SELECT status,delivery_date,packaged,reconstituted FROM orders WHERE id=70')->fetch();
verifyLink($q['status']==='New'&&$q['delivery_date']===''&&(int)$q['packaged']===0,'Portal changed the authoritative Admin order');
verifyLink((int)$store->query('SELECT count(*) FROM portal_guest_order_links')->fetchColumn()===1,'Unexpected owner mapping written');
echo "PASS: explicit staff request-to-existing-order link enforces verified receipt, matching phone, Admin owner identity, cross-account privacy and read-only Admin data.\n";
