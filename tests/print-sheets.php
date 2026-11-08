<?php
declare(strict_types=1);
require dirname(__DIR__).'/packing-centre-support.php';
function checkPrint(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function printIds(array $orders):array{return array_map(static fn(array $order)=>(int)$order['id'],$orders);}
function printTotalFor(array $totals,string $name):int{foreach($totals as $total)if($total['name']===$name)return (int)$total['quantity'];return 0;}
$line=static fn(int $orderId,string $name,int $quantity,string $presentation='Vial',int $productId=1):array=>['id'=>$orderId,'order_id'=>$orderId,'product_id'=>$productId,'name'=>$name,'quantity'=>$quantity,'presentation'=>$presentation];
$orders=[
 ['id'=>1,'customer'=>'Alice Jay','recipient_name'=>'Alice Jay','status'=>'Paid','assigned_to'=>'James','back_order'=>0,'delivery_date'=>'','scheduled_delivery_date'=>'2026-11-08','created'=>'2026-11-01T09:00:00Z','total'=>5000,'paid_amount'=>0,'address'=>'1 Jay Street','phone'=>'07000000001','notes'=>'Leave with Jay','lines'=>[$line(1,'BPC-157 5mg',2)]],
 ['id'=>2,'customer'=>'Bob Tony','recipient_name'=>'','status'=>'Packed','assigned_to'=>'Tony','back_order'=>0,'delivery_date'=>'','scheduled_delivery_date'=>'2026-11-08','created'=>'2026-11-01T10:00:00Z','total'=>3000,'paid_amount'=>1000,'address'=>'2 Tony Street','phone'=>'07000000002','notes'=>'','lines'=>[$line(2,'BPC-157 5mg',1),$line(2,'CJC-1295 2mg',1,'Vial',2)]],
 ['id'=>3,'customer'=>'Carol Holding','recipient_name'=>'Carol Holding','status'=>'New','assigned_to'=>'','back_order'=>0,'delivery_date'=>'','scheduled_delivery_date'=>'','created'=>'2026-11-02T10:00:00Z','total'=>2000,'paid_amount'=>2000,'address'=>'3 Holding Street','phone'=>'07000000003','notes'=>'','lines'=>[$line(3,'BPC-157 5mg',3)]],
 ['id'=>4,'customer'=>'Dan Back','recipient_name'=>'Dan Back','status'=>'New','assigned_to'=>'James','back_order'=>1,'delivery_date'=>'','scheduled_delivery_date'=>'2026-11-08','created'=>'2026-11-02T11:00:00Z','total'=>9000,'paid_amount'=>0,'address'=>'4 Back Street','phone'=>'07000000004','notes'=>'','lines'=>[$line(4,'BPC-157 5mg',5)]],
 ['id'=>5,'customer'=>'Eve Delivered','recipient_name'=>'Eve Delivered','status'=>'Delivered','assigned_to'=>'Tony','back_order'=>0,'delivery_date'=>'2026-11-07','scheduled_delivery_date'=>'2026-11-08','created'=>'2026-11-02T12:00:00Z','total'=>4000,'paid_amount'=>0,'address'=>'5 Done Street','phone'=>'07000000005','notes'=>'','lines'=>[$line(5,'BPC-157 5mg',1)]],
 ['id'=>6,'customer'=>'Frank Cancelled','recipient_name'=>'Frank Cancelled','status'=>'Cancelled','assigned_to'=>'James','back_order'=>0,'delivery_date'=>'','scheduled_delivery_date'=>'2026-11-08','created'=>'2026-11-02T13:00:00Z','total'=>7000,'paid_amount'=>0,'address'=>'6 Cancelled Street','phone'=>'07000000006','notes'=>'','lines'=>[$line(6,'BPC-157 5mg',7)]],
 ['id'=>7,'customer'=>'Gina Future','recipient_name'=>'Gina Future','status'=>'Paid','assigned_to'=>'Tony','back_order'=>0,'delivery_date'=>'','scheduled_delivery_date'=>'2026-12-01','created'=>'2026-11-03T09:00:00Z','total'=>2500,'paid_amount'=>500,'address'=>'7 Future Street','phone'=>'07000000007','notes'=>'','lines'=>[$line(7,'CJC-1295 2mg',2,'Vial',2)]],
 ['id'=>8,'customer'=>'Helen Jay','recipient_name'=>'Helen Jay','status'=>'Paid','assigned_to'=>'Jay','back_order'=>0,'delivery_date'=>'','scheduled_delivery_date'=>'2026-11-08','created'=>'2026-11-03T10:00:00Z','total'=>1000,'paid_amount'=>0,'address'=>'8 Jay Street','phone'=>'07000000008','notes'=>'','lines'=>[$line(8,'BPC-157 5mg',1)]],
];
checkPrint(ankhPrintPerson('James')==='Jay','James must count as Jay');
checkPrint(ankhPrintPerson('Jay')==='Jay','Jay stays Jay');
checkPrint(ankhPrintPerson('Tony')==='Tony','Tony maps to Tony');
checkPrint(ankhPrintPerson('')==='Holding'&&ankhPrintPerson('Alex')==='Holding','Blank and unknown assignees belong to Holding');
$today='2026-11-08';
$jay=ankhPrintSheetOrders($orders,'Jay',$today,false);
$tony=ankhPrintSheetOrders($orders,'Tony',$today,false);
$holding=ankhPrintSheetOrders($orders,'Holding',$today,false);
$everyone=ankhPrintSheetOrders($orders,'Everyone',$today,false);
$all=ankhPrintSheetOrders($orders,'Everyone',$today,true);
checkPrint(printIds($jay)===[1,8],'Jay sheet must include James and Jay orders only');
checkPrint(printIds($tony)===[2],'Tony sheet must exclude delivered and future orders on today');
checkPrint(printIds($holding)===[3],'Holding sheet must include unassigned orders');
checkPrint(printIds($everyone)===[3,1,2,8],'Everyone sheet must exclude back, cancelled and delivered orders');
checkPrint(printIds($all)===[3,1,2,8,7],'All undelivered must include future orders but still exclude back, cancelled and delivered');
checkPrint(!in_array(4,printIds($everyone),true)&&!in_array(5,printIds($everyone),true)&&!in_array(6,printIds($everyone),true),'Back, delivered and cancelled orders must never print');
$jayTotals=ankhPrintSheetTotals($jay);$everyoneTotals=ankhPrintSheetTotals($everyone);$allTotals=ankhPrintSheetTotals($all);
checkPrint(printTotalFor($jayTotals,'BPC-157 5mg')===3,'Jay product totals must add only Jay orders');
checkPrint(printTotalFor($everyoneTotals,'BPC-157 5mg')===7,'Everyone product totals must add all printable orders');
checkPrint(printTotalFor($everyoneTotals,'CJC-1295 2mg')===1,'Everyone totals must separate products');
checkPrint(printTotalFor($allTotals,'CJC-1295 2mg')===3,'All undelivered totals must include future orders');
$collect=static fn(array $list):int=>array_sum(array_map('ankhPrintOrderBalance',$list));
checkPrint($collect($jay)===6000,'Jay amount to collect must use outstanding balances');
checkPrint($collect($tony)===2000,'Tony amount to collect must subtract payments');
checkPrint($collect($holding)===0,'Paid holding order must collect nothing');
checkPrint($collect($everyone)===8000,'Everyone amount to collect must total printable balances');
checkPrint($collect($all)===10000,'All undelivered amount to collect must include future balances');
checkPrint(ankhPrintSheetTitle('packing','Jay','2026-11-08',false)==='Packing list · Jay · Sun 8 Nov 2026','Packing header must show sheet, person and date');
checkPrint(ankhPrintSheetTitle('delivery','Everyone','2026-11-08',true)==='Delivery sheet · Everyone · All undelivered','Delivery header must show all undelivered');
checkPrint(ankhPrintFirstName('Alice Jay')==='Alice'&&ankhPrintFirstName('  Bob  Tony ')==='Bob','Order sheet must use client first names');
$orderOne=array_values(array_filter($everyone,static fn(array $order)=>(int)$order['id']===1))[0];
checkPrint(ankhPrintOrderItemsText($orderOne)==='2 × BPC-157 5mg · Vial','Items must list quantity, product and presentation');
checkPrint(ankhPrintOrderReference($orderOne)==='ANK-0001','Order numbers must be padded');
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER,product_id INTEGER,name TEXT,quantity INTEGER,presentation TEXT)');
$db->exec("INSERT INTO items(order_id,product_id,name,quantity,presentation) VALUES(1,1,'BPC-157 5mg',2,'Vial'),(1,2,'CJC-1295 2mg',1,'Vial')");
$loaded=ankhPrintAttachLines($db,[['id'=>1],['id'=>99]]);
checkPrint(count($loaded[0]['lines'])===2&&$loaded[1]['lines']===[] ,'Saved lines must attach without inventing items');
echo "PASS: print sheets map James to Jay, separate Tony and Holding, exclude back/cancelled/delivered orders, filter by date/all undelivered, total products, add balances and render headers/names.\n";
