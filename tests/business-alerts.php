<?php
declare(strict_types=1);
// Self-contained test of notification generation only. Never connects to SMTP or live SQLite.
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE settings (key TEXT PRIMARY KEY,value TEXT NOT NULL);
 CREATE TABLE orders (id INTEGER PRIMARY KEY,customer TEXT,recipient_name TEXT,phone TEXT,address TEXT,notes TEXT,created TEXT,delivery_charge INTEGER,delivery_method TEXT,payment_method TEXT,assigned_to TEXT,scheduled_delivery_date TEXT,status TEXT,packaged INTEGER,reconstituted INTEGER,delivery_date TEXT);
 CREATE TABLE products (id INTEGER PRIMARY KEY,stock_jay_qty INTEGER,stock_tony_qty INTEGER,stock_unallocated_qty INTEGER,stock_tracking INTEGER,low_stock_at INTEGER);
 CREATE TABLE items (id INTEGER PRIMARY KEY,order_id INTEGER,product_id INTEGER,name TEXT,quantity INTEGER,price INTEGER,presentation TEXT,recurring INTEGER);
 CREATE TABLE payments (id INTEGER PRIMARY KEY,order_id INTEGER,amount INTEGER);
 CREATE TABLE admin_users (id INTEGER PRIMARY KEY,username TEXT);
 INSERT INTO admin_users VALUES(1,'jay');
 INSERT INTO orders VALUES(74,'Example buyer','Recipient','07123456789','Liverpool','Leave at desk','2026-10-21T12:00:00Z',0,'Local Delivery','Bank Transfer','James','','New',0,0,'');
 INSERT INTO products VALUES(55,3,2,0,1,5);
 INSERT INTO items VALUES(1,74,55,'Research product 10mg',1,4500,'Cartridge',0);
");
$_SESSION['admin_user_id']=1;
require dirname(__DIR__).'/business-alerts.php';
if(ankhAlertRecipients(ankhAlertPrefs($db))!==['info@ankhpeptides.com'])throw new RuntimeException('Unexpected email recipient');
if(ankhNewOrderMailState($db,74)!=='')throw new RuntimeException('Unsent state must be empty');
[$subject,$plain,$html]=ankhNewOrderBody($db,74);
foreach(['ANK-0074','James'] as $word)if(!str_contains($subject,$word))throw new RuntimeException('Subject missing '.$word);
foreach(['£45.00','Example buyer','Research product 10mg','Jay 3 / Tony 2'] as $word)if(!str_contains($plain,$word)&&!str_contains($html,$word))throw new RuntimeException('Email missing '.$word);
if(!str_contains($html,'viewport')||!str_contains($html,'View order in ANKH Admin'))throw new RuntimeException('Mobile HTML missing');
echo "ANKH new-order email generator passed.\n";
