<?php
declare(strict_types=1);
$source=file_get_contents(dirname(__DIR__).'/index.php');
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$start=strpos($source,'function money(');$end=strpos($source,'function balanceCustomerFirstName(',$start);eval(substr($source,$start,$end-$start));
$start=strpos($source,'function isBackOrder(');$end=strpos($source,'function assertOrderActive(',$start);eval(substr($source,$start,$end-$start));
$start=strpos($source,'function orderDisplayName(');$end=strpos($source,'function orderBuyerCaption(',$start);eval(substr($source,$start,$end-$start));
$start=strpos($source,'function morningBriefData(');$end=strpos($source,'function dashboardQueueView(',$start);eval(substr($source,$start,$end-$start));

$now=new DateTimeImmutable('2026-11-08T09:30:00+00:00');
$today=$now->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d');
$orders=[
 ['id'=>1,'customer'=>'Alice Delivery','recipient_name'=>'','phone'=>'100','status'=>'Paid','back_order'=>0,'delivery_date'=>'','scheduled_delivery_date'=>$today,'total'=>5000,'paid_amount'=>5000],
 ['id'=>2,'customer'=>'Bob Owed','recipient_name'=>'','phone'=>'200','status'=>'Paid','back_order'=>0,'delivery_date'=>'','scheduled_delivery_date'=>'','total'=>4000,'paid_amount'=>1000],
 ['id'=>3,'customer'=>'Cara Backorder','recipient_name'=>'','phone'=>'300','status'=>'Paid','back_order'=>1,'delivery_date'=>'','scheduled_delivery_date'=>$today,'total'=>9000,'paid_amount'=>0],
];
$products=[
 ['id'=>1,'name'=>'Low Product','active'=>1,'stock_tracking'=>1,'stock_qty'=>3,'stock_jay_qty'=>1,'stock_tony_qty'=>1,'stock_unallocated_qty'=>1,'low_stock_at'=>5],
 ['id'=>2,'name'=>'Healthy Product','active'=>1,'stock_tracking'=>1,'stock_qty'=>40,'stock_jay_qty'=>20,'stock_tony_qty'=>10,'stock_unallocated_qty'=>10,'low_stock_at'=>5],
 ['id'=>3,'name'=>'Hidden Low Product','active'=>0,'stock_tracking'=>1,'stock_qty'=>0,'stock_jay_qty'=>0,'stock_tony_qty'=>0,'stock_unallocated_qty'=>0,'low_stock_at'=>5],
 ['id'=>4,'name'=>'Untracked Product','active'=>1,'stock_tracking'=>0,'stock_qty'=>null,'stock_jay_qty'=>0,'stock_tony_qty'=>0,'stock_unallocated_qty'=>0,'low_stock_at'=>5],
];
$reorders=[['client'=>'Cycle A'],['client'=>'Cycle B']];
$supplierOrders=[
 ['id'=>1,'status'=>'Ordered','total_qty'=>20,'received_qty'=>0],
 ['id'=>2,'status'=>'Received','total_qty'=>10,'received_qty'=>10],
 ['id'=>3,'status'=>'Cancelled','total_qty'=>5,'received_qty'=>0],
];
$brief=morningBriefData($orders,$products,$reorders,$supplierOrders,$now);
check($brief['today']===$today,'Brief uses the Europe/London today date.');
check($brief['deliveries']===1,'Only the active order scheduled today counts as a delivery.');
check($brief['owed']===3000&&$brief['clients']===1,'Outstanding money excludes back orders and groups by client.');
check($brief['low_stock']===1,'Low stock counts active tracked products across Jay, Tony and holding.');
check($brief['reorders']===2&&$brief['supplier_orders']===1&&$brief['supplier_units']===20,'Reorders and incoming supplier orders use outstanding counts only.');
check(array_column($brief['lines'],'key')===['deliveries','money','low-stock','reorders','supplier'],'Brief lines appear in priority order and hide zero counts.');
$lines=[];foreach($brief['lines'] as $line)$lines[$line['key']]=$line;
check($lines['deliveries']['detail']==='1 · Alice Delivery','Deliveries show the count and first names.');
check(str_contains($lines['money']['detail'],'£30.00')&&str_contains($lines['money']['detail'],'1 client'),'Money line shows the outstanding total and client count.');
check($lines['low-stock']['detail']==='1 product below threshold','Low stock line shows the product count.');
check($lines['deliveries']['href']==='?view=dashboard&queue=delivery#dashboard-actions','Deliveries link to the worklist To deliver filter.');
check($lines['money']['href']==='?view=balances','Money link opens Balances.');
check($lines['low-stock']['href']==='?view=products&filter=low#products-list','Low stock links to the Products low-stock chip.');
check($lines['reorders']['href']==='?view=plan&tab=cycles','Reorders link to Plan Cycles.');
check($lines['supplier']['href']==='?view=buying&tab=supplier&supplier_tab=incoming','Supplier orders link to Buying Incoming.');

$backOnly=morningBriefData([$orders[2]],[],[],[],$now);
check($backOnly['deliveries']===0&&$backOnly['owed']===0&&$backOnly['clients']===0,'Back orders never count towards deliveries or money owed.');

$empty=morningBriefData([],[],[],[],$now);
check($empty['lines']===[]&&$empty['all_clear']===true,'An empty day reports all clear.');

$many=morningBriefData(array_merge($orders,[
 ['id'=>4,'customer'=>'Dan Delivery','recipient_name'=>'','phone'=>'400','status'=>'Paid','back_order'=>0,'delivery_date'=>'','scheduled_delivery_date'=>$today,'total'=>1000,'paid_amount'=>1000],
 ['id'=>5,'customer'=>'Eve Delivery','recipient_name'=>'','phone'=>'500','status'=>'Paid','back_order'=>0,'delivery_date'=>'','scheduled_delivery_date'=>$today,'total'=>1000,'paid_amount'=>1000],
 ['id'=>6,'customer'=>'Finn Delivery','recipient_name'=>'','phone'=>'600','status'=>'Paid','back_order'=>0,'delivery_date'=>'','scheduled_delivery_date'=>$today,'total'=>1000,'paid_amount'=>1000],
]),$products,$reorders,$supplierOrders,$now);
$manyLines=[];foreach($many['lines'] as $line)$manyLines[$line['key']]=$line;
check(str_contains($manyLines['deliveries']['detail'],'+1 more'),'Delivery names are capped at the first three.');
echo "PASS: morning brief counts deliveries, excludes back-order money, flags low stock, lists reorders and incoming supplier orders with the right links.\n";
