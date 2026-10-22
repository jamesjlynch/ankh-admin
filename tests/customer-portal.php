<?php
declare(strict_types=1);
require_once __DIR__ . '/../customer-portal/lib.php';

function expectPortal(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
expectPortal(portalMobile('07123 456789')==='+447123456789','UK local mobile');
expectPortal(portalMobile('0044 7123 456789')==='+447123456789','international mobile');
expectPortal(portalMobile('+44 (0) 7123 456789')===null,'Do not guess invalid numbers');
expectPortal(portalMobile('020 7946 0123')===null,'Never accept landlines');
expectPortal(portalMobile('123456')===null,'Never accept arbitrary OTP phone');

$private=tempnam(sys_get_temp_dir(),'ankh-customer-test-');
if($private===false)throw new RuntimeException('Unable to create test database');
$admin=new PDO('sqlite:'.$private,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
try{
$admin->exec("CREATE TABLE customers(id INTEGER PRIMARY KEY,name TEXT,phone TEXT,address TEXT,archived INTEGER);
CREATE TABLE orders(id INTEGER PRIMARY KEY,customer TEXT,phone TEXT,status TEXT,created TEXT,delivery_date TEXT,scheduled_delivery_date TEXT,delivery_charge INTEGER,packaged INTEGER DEFAULT 0,reconstituted INTEGER DEFAULT 0,delivery_method TEXT DEFAULT '',tracking_reference TEXT DEFAULT '');
CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER,name TEXT,presentation TEXT,price INTEGER,quantity INTEGER);
CREATE TABLE payments(id INTEGER PRIMARY KEY,order_id INTEGER,amount INTEGER,created TEXT,method TEXT DEFAULT '');
INSERT INTO customers VALUES (1,'Alice Customer','07123 456789','Road 1',0),(2,'Bob Customer','07123 456789','Road 2',0);
INSERT INTO orders VALUES (1,'Alice Customer','07123 456789','New','2026-10-21T10:00:00Z','','',500),
(2,'Bob Customer','07123 456789','New','2026-10-21T10:00:00Z','','',0),
(3,'Alice Customer','07999 000000','New','2026-10-21T10:00:00Z','','',0),
(4,'Alice Customer','+447123456789','Delivered','2026-10-21T10:00:00Z','2026-10-22','',0);
INSERT INTO items VALUES (1,1,'Test item','Vial',1299,2),(2,4,'Test other','','0',1);
INSERT INTO payments VALUES (1,1,1000,'2026-10-22T08:00:00Z');");
$customer=['id'=>1,'name'=>'Alice Customer','phone'=>'07123 456789','address'=>'Road 1','archived'=>0];
$admin->exec('PRAGMA query_only=ON');
$orders=portalOrderHistory($admin,$customer,'+447123456789');
expectPortal(count($orders)===2,'Customer must only see exact name AND phone');
expectPortal((int)$orders[1]['id']===1,'Correct historic order');
expectPortal((int)$orders[1]['total']===3098,'Totals include items and delivery');
expectPortal((int)$orders[1]['paid']===1000,'Show actual payments');
expectPortal(count($orders[1]['payments'])===1,'Expose one recorded payment event');
expectPortal(portalStatus($orders[0])==='Delivered','Delivered status');
expectPortal(portalStatus($orders[1])==='Received','New status');
expectPortal(portalStatus(['status'=>'Dispatched','delivery_date'=>''])==='On its way','Dispatch must not imply delivered');
try {$admin->exec('DELETE FROM orders');throw new RuntimeException('Admin DB must be read-only');}
catch(PDOException $e) { /* Expected */ }
} finally {unset($admin);@unlink($private);}

$portal=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
portalInitDb($portal);
$portal->exec("INSERT INTO portal_accounts(customer_id,phone_e164,created_at) VALUES (1,'+447123456789',1700000000)");
expectPortal((int)portalAccountForPhone($portal,'+447123456789')['customer_id']===1,'Enrolled phone only');
expectPortal(portalAccountForPhone($portal,'+447999000000')===null,'No automatic account creation');
expectPortal(portalAllowAttempt($portal,'test',2,600),'Allow first request');
expectPortal(portalAllowAttempt($portal,'test',2,600),'Allow second request');
expectPortal(!portalAllowAttempt($portal,'test',2,600),'Block third request');
$portal->exec("UPDATE portal_accounts SET active=0,auth_version=auth_version+1 WHERE customer_id=1");
expectPortal(portalAccountForPhone($portal,'+447123456789')===null,'Revoke immediately');
echo "Customer portal privacy, order reconciliation and throttling tests passed.\n";
