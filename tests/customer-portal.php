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
INSERT INTO orders(id,customer,phone,status,created,delivery_date,scheduled_delivery_date,delivery_charge) VALUES (1,'Alice Customer','07123 456789','New','2026-10-21T10:00:00Z','','',500),
(2,'Bob Customer','07123 456789','New','2026-10-21T10:00:00Z','','',0),
(3,'Alice Customer','07999 000000','New','2026-10-21T10:00:00Z','','',0),
(4,'Alice Customer','+447123456789','Delivered','2026-10-21T10:00:00Z','2026-10-22','',0);
INSERT INTO items VALUES (1,1,'Test item','Vial',1299,2),(2,4,'Test other','','0',1);
INSERT INTO payments(id,order_id,amount,created) VALUES (1,1,1000,'2026-10-22T08:00:00Z');");
$customer=['id'=>1,'name'=>'Alice Customer','phone'=>'07123 456789','address'=>'Road 1','archived'=>0];
$admin->exec("CREATE TABLE reta_cycles(id INTEGER PRIMARY KEY,customer_name TEXT,phone TEXT,last_order_id INTEGER,product_name TEXT,next_due_date TEXT,active INTEGER,cycle_weeks INTEGER,cycle_days INTEGER,presentation TEXT,quantity INTEGER,expected_cost INTEGER,expected_profit INTEGER);
INSERT INTO reta_cycles VALUES
(1,'Alice Customer','+447123456789',4,'Test other','2026-11-19',1,4,28,'Vial',1,999,888),
(2,'Bob Customer','07123 456789',2,'Test item','2026-11-20',1,4,28,'Vial',1,999,888),
(3,'Alice Customer','07999 000000',4,'Test other','2026-11-21',1,4,28,'Vial',1,999,888),
(4,'Alice Customer','07123 456789',2,'Test other','2026-11-22',1,4,28,'Vial',1,999,888),
(5,'Alice Customer','07123 456789',4,'Test other','2026-11-23',0,4,28,'Vial',1,999,888),
(6,'Alice Customer','07123 456789',4,'Test other','2026-02-30',1,4,28,'Vial',1,999,888),
(7,'Alice Customer','07123 456789',4,'Different product','2026-11-24',1,4,28,'Vial',1,999,888),
(8,'Alice Customer','07123 456789',1,'Test item','2026-11-25',1,4,28,'Vial',1,999,888);");
$admin->exec('PRAGMA query_only=ON');
$orders=portalOrderHistory($admin,$customer,'+447123456789');
expectPortal(count($orders)===2,'Customer must only see exact name AND phone');
$reminders=portalRecurringOrders($admin,$customer,'+447123456789',$orders,new DateTimeImmutable('2026-10-22',new DateTimeZone('Europe/London')));
expectPortal(count($reminders)===1 && $reminders[0]['id']===1,'Only active reminders with matching identity, phone, delivered source and product');
expectPortal($reminders[0]['days_until']===28 && $reminders[0]['weeks']===4,'Saved dates and intervals display accurately');
expectPortal(!isset($reminders[0]['expected_cost'])&&!isset($reminders[0]['expected_profit'])&&!isset($reminders[0]['phone']),'Internal forecasts and contact fields never enter the view model');
expectPortal(portalRecurringOrders($admin,$customer,'+447999000000',$orders)===[],'Unverified phone cannot read reminders');
$overdue=portalRecurringOrders($admin,$customer,'+447123456789',$orders,new DateTimeImmutable('2026-11-20',new DateTimeZone('Europe/London')));
expectPortal($overdue[0]['days_until']===-1 && $overdue[0]['due_date']==='2026-11-19','Passed dates remain explicit instead of rolling forward');
$onDate=portalRecurringOrders($admin,$customer,'+447123456789',$orders,new DateTimeImmutable('2026-11-19',new DateTimeZone('Europe/London')));
expectPortal($onDate[0]['days_until']===0,'Today reminder has a zero-day countdown');

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


$notificationsStore=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
portalNotificationInit($notificationsStore);
$noticeOrder=['id'=>44,'status'=>'New','created'=>'2026-10-22','delivery_date'=>'','scheduled_delivery_date'=>'','tracking_reference'=>'','packaged'=>0,'reconstituted'=>0,'total'=>2000,'paid'=>0];
$noticeReply=['id'=>5,'reply'=>'Saved answer','product_name'=>'Fixture item','updated_at'=>1700000000];
$noticeReminder=['id'=>1,'product'=>'Fixture product','due_date'=>'2026-10-28','days_until'=>6];
$noticeToday=new DateTimeImmutable('2026-10-22',new DateTimeZone('Europe/London'));
$generated=portalNotificationEvents([$noticeOrder],[$noticeReply],[$noticeReminder],$noticeToday);
expectPortal(count($generated)===3,'Replies, current orders and due-soon reminders create notifications');
$visible=portalNotificationList($notificationsStore,1,false,$generated);
expectPortal(count(array_filter($visible,fn($row)=>!$row['read']))===3,'New notifications start unread');
portalNotificationUpdate($notificationsStore,1,false,$generated,'notification_read',$generated[0]['key']);
$visible=portalNotificationList($notificationsStore,1,false,$generated);
expectPortal(count(array_filter($visible,fn($row)=>!$row['read']))===2,'Read state persists across regeneration');
expectPortal(count(array_filter(portalNotificationList($notificationsStore,2,false,$generated),fn($row)=>!$row['read']))===3,'Notification state belongs to one customer');
expectPortal(count(array_filter(portalNotificationList($notificationsStore,1,true,$generated),fn($row)=>!$row['read']))===3,'Preview state is separate from real customer state');
portalNotificationUpdate($notificationsStore,1,false,$generated,'notification_dismiss',$generated[0]['key']);
portalNotificationUpdate($notificationsStore,1,false,$generated,'notification_read_all');
$visible=portalNotificationList($notificationsStore,1,false,$generated);
expectPortal(count($visible)===2 && !array_filter($visible,fn($row)=>!$row['read']),'Mark all read preserves dismissed state');
$changed=portalNotificationEvents([array_replace($noticeOrder,['packaged'=>1])],[$noticeReply],[$noticeReminder],$noticeToday);
expectPortal(count(array_filter(portalNotificationList($notificationsStore,1,false,$changed),fn($row)=>!$row['read']))===1,'A real order change creates a fresh unread update');
$newReply=portalNotificationEvents([$noticeOrder],[array_replace($noticeReply,['reply'=>'Updated answer'])],[$noticeReminder],$noticeToday);
expectPortal(count(array_filter(portalNotificationList($notificationsStore,1,false,$newReply),fn($row)=>!$row['read']))===1,'A changed reply creates a fresh notification');
expectPortal(portalNotificationEvents([],[],[array_replace($noticeReminder,['days_until'=>8])],$noticeToday)===[],'Reminders beyond seven days do not notify');
expectPortal(portalNotificationEvents([array_replace($noticeOrder,['created'=>'2020-01-01'])],[],[],$noticeToday)===[],'Old order history does not flood notifications');
try{portalNotificationUpdate($notificationsStore,1,false,$generated,'notification_read',str_repeat('f',64));throw new RuntimeException('Unknown event accepted');}catch(InvalidArgumentException $e){}
echo "Customer notification generation, persistence, isolation, read/dismiss controls and refresh checks passed.\n";
