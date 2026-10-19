<?php
declare(strict_types=1);
$source=file_get_contents(dirname(__DIR__).'/index.php');
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$helperStart=strpos($source,'function isBackOrder(');$helperEnd=strpos($source,'function assertOrderActive(',$helperStart);eval(substr($source,$helperStart,$helperEnd-$helperStart));
$start=strpos($source,'function todayOverviewData(');$end=strpos($source,'function preparationBoardData(',$start);eval(substr($source,$start,$end-$start));
$start=strpos($source,'function deliveryBoardSection(');$end=strpos($source,'function isFutureDelivery(',$start);eval(substr($source,$start,$end-$start));
$start=strpos($source,'function orderPreparationState(');$end=strpos($source,'function setOrderPreparation(',$start);eval(substr($source,$start,$end-$start));
$start=strpos($source,'function preparationBoardData(');$end=strpos($source,"if(\$reportBoardRequest!==''",$start);eval(substr($source,$start,$end-$start));
$start=strpos($source,'function deliveryCountdown(');$end=strpos($source,'function ', $start+10);eval(substr($source,$start,$end-$start));
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE orders(id INTEGER PRIMARY KEY,back_order INTEGER NOT NULL DEFAULT 0,created TEXT,status TEXT,payment_date TEXT DEFAULT '',delivery_date TEXT DEFAULT '',scheduled_delivery_date TEXT DEFAULT '',delivery_section_override TEXT DEFAULT '',delivery_charge INTEGER DEFAULT 0,customer TEXT DEFAULT 'Test customer',recipient_name TEXT DEFAULT '',delivery_method TEXT DEFAULT 'Local Delivery',assigned_to TEXT DEFAULT '',reconstituted INTEGER DEFAULT 0,packaged INTEGER DEFAULT 0);CREATE TABLE items(order_id INTEGER,price INTEGER,quantity INTEGER,id INTEGER PRIMARY KEY,name TEXT DEFAULT 'Product');CREATE TABLE payments(order_id INTEGER,amount INTEGER)");
$insert=$db->prepare('INSERT INTO orders(id,created,status,payment_date,delivery_date,scheduled_delivery_date,delivery_charge) VALUES(?,?,?,?,?,?,?)');
foreach([
 [1,'2026-10-14T10:00:00+01:00','Paid','2026-10-14','','2026-10-30',200],
 [2,'2026-10-14T10:00:00+01:00','New','','','',0],
 [3,'2026-10-12T10:00:00+01:00','Delivered','2026-10-12','2026-10-13','',0],
 [4,'2026-10-14T10:00:00+01:00','Cancelled','','','',0],
 [5,'2026-10-13T23:30:00Z','New','','','2026-10-13',0],
 [6,'2026-10-10T10:00:00+01:00','Paid','2026-10-15','','',0],
] as $row){$insert->execute($row);$db->prepare('INSERT INTO items(order_id,price,quantity) VALUES(?,1000,1)')->execute([$row[0]]);}
$db->exec('INSERT INTO payments VALUES(1,1200),(2,400),(3,250)');
$now=new DateTimeImmutable('2026-10-14T12:00:00+01:00');$data=todayOverviewData($db,$now);
check($data['today_sales']===1200,'Sales use payment date and include delivery fees.');
check($data['today_orders']===3,'Orders exclude cancellations and use London local dates.');
check($data['unpaid']===3350&&$data['unpaid_orders']===4,'Outstanding balances must subtract partial payments and exclude cancelled orders.');
check($data['delivered_unpaid']===750,'Delivered unpaid must remain visible.');
check($data['to_deliver']===3&&$data['overdue']===1,'Future planned deliveries stay open; delivered orders leave the queue.');
check(count($data['days'])===7&&$data['week_sales']===2200,'Week includes seven days but excludes future dated sales.');
check($data['days'][0]['date']==='2026-10-12'&&$data['days'][3]['sales']===0,'Week begins Monday and future days are empty.');
$db->exec("UPDATE orders SET assigned_to='James',reconstituted=1 WHERE id=1; UPDATE orders SET assigned_to='Tony',packaged=1 WHERE id=5; UPDATE orders SET scheduled_delivery_date='2026-10-14' WHERE id=6");
$ops=todayOverviewData($db,$now);
check($ops['preparation']===['pending'=>2,'reconstituted'=>1,'packaged'=>1],'Preparation stages must be exclusive and exclude cancelled/delivered orders.');
check($ops['workload']===['Jay'=>1,'Tony'=>1,'Unassigned'=>1],'Delivery workload must count pending deliveries once and map James to Jay.');
check($ops['due_today']===1&&$ops['overdue']===1&&$ops['next_delivery']==='2026-10-30','Delivery summary must distinguish due, overdue and future.');
$board=preparationBoardData($db,$now);
check(array_column($board['columns']['pending'],'id')===[6,2],'Due-today preparation must precede undated orders.');
check(array_column($board['columns']['reconstituted'],'id')===[1]&&array_column($board['columns']['packaged'],'id')===[5],'Stages must match the app preparation state.');
check($board['columns']['reconstituted'][0]['owner']==='Jay'&&$board['columns']['packaged'][0]['owner']==='Tony','Board assignment aliases.');
check($board['columns']['pending'][1]['balance']===600,'Partial payments must show only remaining balance.');
check($board['columns']['pending'][0]['items'][0]['quantity']===1,'Product quantities must be preserved.');
check($board['columns']['pending'][0]['customer']==='Test customer'&&$board['columns']['pending'][0]['order_date']==='2026-10-10'&&$board['columns']['pending'][0]['delivery_method']==='Local Delivery'&&$board['columns']['pending'][0]['total']===1000,'Order cards must include customer, local order date, delivery method and total.');
check(!array_key_exists('phone',$board['columns']['pending'][0])&&!array_key_exists('address',$board['columns']['pending'][0]),'TV payload must omit contact details.');
check($board['due_today']===1&&$board['overdue']===1,'Board summary must use all open orders.');
$db->exec("UPDATE orders SET delivery_date='2026-10-14' WHERE id=1");$closed=todayOverviewData($db,$now);
check($closed['preparation']['reconstituted']===0&&$closed['workload']['Jay']===0&&$closed['next_delivery']==='','Manual delivery removes an order from preparation, workload and next delivery.');
$db->exec('DELETE FROM orders; DELETE FROM items; DELETE FROM payments');$empty=todayOverviewData($db,$now);
check($empty['today_sales']===0&&$empty['to_deliver']===0&&count($empty['days'])===7,'Empty database must render a complete zero state.');
$dst=todayOverviewData($db,new DateTimeImmutable('2026-10-25T23:30:00Z'));check($dst['days'][0]['date']==='2026-10-19','DST must not shift week boundaries.');
echo "PASS: overview totals, partial payments, cancellations, future deliveries, UK dates, weekly sales, DST and empty data.\n";
