<?php
declare(strict_types=1);
// Isolated regression checks: do not load the live app or its database/config.
$source=file_get_contents(dirname(__DIR__).'/index.php');
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$start=strpos($source,'function plannedDeliveryDate(');$end=strpos($source,'function orderActionDate(',$start);
check($start!==false&&$end!==false,'Scheduling helpers must exist.');
eval(substr($source,$start,$end-$start));
$tz=new DateTimeZone('Europe/London');$now=new DateTimeImmutable('2026-10-11 18:00:00',$tz);$today=$now->setTime(0,0);
check(plannedDeliveryDate('2026-10-12','',$today)==='2026-10-12','Tomorrow must be accepted.');
check(plannedDeliveryDate('','',$today)==='','An unchecked schedule must be clearable.');
check(plannedDeliveryDate('2026-10-09','2026-10-09',$today)==='2026-10-09','Editing an unchanged historical schedule must preserve it.');
foreach(['2026-10-11','2026-10-10','2026-02-30','bad-date','2026-1-1'] as $invalid){
 $rejected=false;try{plannedDeliveryDate($invalid,'',$today);}catch(Exception $e){$rejected=true;}check($rejected,'Invalid/new past date accepted: '.$invalid);
}
$base=['customer'=>'Test','recipient_name'=>'','phone'=>'','referrer'=>'','assigned_to'=>'','status'=>'Paid','created'=>'2026-10-11T12:00:00+00:00','payment_date'=>'2026-10-11','delivery_date'=>'','total'=>1000,'paid_amount'=>1000];
$orders=[
 array_merge($base,['id'=>1,'scheduled_delivery_date'=>'2026-10-20']),
 array_merge($base,['id'=>2,'scheduled_delivery_date'=>'2026-10-12','status'=>'New','paid_amount'=>0]),
 array_merge($base,['id'=>3,'scheduled_delivery_date'=>'2026-10-11']),
 array_merge($base,['id'=>4,'scheduled_delivery_date'=>'2026-10-10']),
 array_merge($base,['id'=>5,'scheduled_delivery_date'=>'']),
 array_merge($base,['id'=>6,'scheduled_delivery_date'=>'2026-10-15','delivery_date'=>'2026-10-11']),
 array_merge($base,['id'=>7,'scheduled_delivery_date'=>'2026-10-15','status'=>'Cancelled']),
 array_merge($base,['id'=>8,'scheduled_delivery_date'=>'2026-10-15','status'=>'Delivered']),
];
$start=strpos($source,'$futureDeliveries=array_values(');$end=strpos($source,'usort($awaitingPayment',$start);
check($start!==false&&$end!==false,'Dashboard scheduling dataset must exist.');
eval(substr($source,$start,$end-$start));
check(array_column($futureDeliveries,'id')===[2,1],'Future orders must be nearest first; delivered/cancelled must be excluded.');
check(array_column($awaitingDelivery,'id')===[3,4,5],'Only due or unscheduled paid orders should await delivery.');
check(!isFutureDelivery($orders[1],'2026-10-12'),'On its due date an order must leave Future deliveries.');
$ordersSection='all';$_GET=['scope'=>'to_deliver'];$paidStatuses=['Paid','Packed','Dispatched','Delivered'];$todayStart=$today->getTimestamp();$monthStart=$today->modify('first day of this month')->getTimestamp();
$start=strpos($source,"$"."orderScopes=['today_sales'");$end=strpos($source,'?>',$start);
check($start!==false&&$end!==false,'Order drill-down must exist.');eval(substr($source,$start,$end-$start));
check(array_column($listedOrders,'id')===[3,4,5],'To-deliver drill-down must match the dashboard.');
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
preg_match("/INSERT INTO orders\\(customer,recipient_name,[^']+\\)/",$source,$insertMatch);
check(!empty($insertMatch),'Order insert must exist.');
$insertSql=$insertMatch[0];preg_match('/INSERT INTO orders\\(([^)]+)\\)/',$insertSql,$columnsMatch);$columns=explode(',',$columnsMatch[1]);
check(in_array('scheduled_delivery_date',$columns,true),'New orders must save the schedule.');
$oldColumns=array_filter($columns,fn($c)=>$c!=='scheduled_delivery_date');
$db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY,'.implode(',',array_map(fn($c)=>$c.' TEXT',$oldColumns)).", delivery_date TEXT NOT NULL DEFAULT '')");
$db->exec("INSERT INTO orders(id,customer) VALUES (1,'Existing')");
preg_match('/ALTER TABLE orders ADD COLUMN scheduled_delivery_date TEXT NOT NULL DEFAULT \'\'/',$source,$migration);
check(!empty($migration),'Schedule migration must exist.');$db->exec($migration[0]);
check($db->query('SELECT scheduled_delivery_date FROM orders WHERE id=1')->fetchColumn()==='','Existing orders must start unscheduled.');
$values=array_map(fn($c)=>match($c){'customer'=>'New order','created'=>'2026-10-11T12:00:00+00:00','scheduled_delivery_date'=>'2026-10-20',default=>''},$columns);
$db->prepare($insertSql)->execute($values);$id=(int)$db->lastInsertId();
check($db->query('SELECT scheduled_delivery_date FROM orders WHERE id='.$id)->fetchColumn()==='2026-10-20','Schedule must persist.');
check($db->query('SELECT delivery_date FROM orders WHERE id='.$id)->fetchColumn()==='','Scheduling must not mark delivered.');
preg_match("/UPDATE orders SET customer=\\?,recipient_name=\\?,[^']+ WHERE id=\\?/",$source,$updateMatch);
check(!empty($updateMatch)&&str_contains($updateMatch[0],'scheduled_delivery_date=?'),'Editing must persist the schedule.');
$updateValues=$values;$updateValues[array_search('scheduled_delivery_date',$columns,true)]='';$updateValues[]=$id;
$db->prepare($updateMatch[0])->execute($updateValues);
check($db->query('SELECT scheduled_delivery_date FROM orders WHERE id='.$id)->fetchColumn()==='','Unticking must remove the schedule.');
echo "PASS: future date validation, historical edits, dashboard grouping/sorting, due-day rollover, delivery drill-down, migration, create/edit persistence, no premature delivery.\n";
