<?php
declare(strict_types=1);
// Test the exact delivery classifier used by dashboard stats, the worklist, and Orders filters.
// This does not create or mutate orders in any database.
$source=file_get_contents(dirname(__DIR__).'/index.php');
$start=strpos($source,'function isBackOrder(array $order)');
$end=strpos($source,'function isFutureDelivery(', $start === false ? 0 : $start);
if($start===false||$end===false)throw new RuntimeException('Cannot locate delivery classifier.');
eval(substr($source,$start,$end-$start));
$today='2026-10-21';
$base=['id'=>74,'status'=>'Pending','back_order'=>0,'delivery_date'=>'','scheduled_delivery_date'=>'','paid_amount'=>0,'reconstituted'=>0,'packaged'=>0];
$cases=[
 ['Unpaid, undated, unprepared', $base, 'awaiting'],
 ['Part-paid, undated',array_replace($base,['paid_amount'=>2000]),'awaiting'],
 ['Paid, undated',array_replace($base,['status'=>'Paid']),'awaiting'],
 ['Packaged, undated',array_replace($base,['packaged'=>1]),'awaiting'],
 ['Dispatched but not delivered',array_replace($base,['status'=>'Dispatched']),'awaiting'],
 ['Delivery scheduled today',array_replace($base,['scheduled_delivery_date'=>'2026-10-21']),'awaiting'],
 ['Future delivery',array_replace($base,['scheduled_delivery_date'=>'2026-11-12']),'future'],
 ['Explicitly delivered',array_replace($base,['delivery_date'=>'2026-10-21']),''],
 ['Delivered status',array_replace($base,['status'=>'Delivered']),''],
 ['Cancelled',array_replace($base,['status'=>'Cancelled']),''],
 ['Back order',array_replace($base,['back_order'=>1]),''],
];
foreach($cases as [$name,$order,$expected]){
 $actual=deliveryBoardSection($order,$today);
 if($actual!==$expected)throw new RuntimeException($name.': expected '.var_export($expected,true).' but got '.var_export($actual,true));
}
echo 'Delivery queue assertions passed: '.count($cases)."\n";
