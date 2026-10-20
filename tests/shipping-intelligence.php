<?php
declare(strict_types=1);
// Standalone rendering regression for the automatic disruption forecast.
// Uses an isolated in-memory database; never reads live orders or stock.
date_default_timezone_set('Europe/London');
$_SESSION=['csrf'=>'shipping-test'];
$now=new DateTimeImmutable('today',new DateTimeZone('Europe/London'));
$id='custom-1234567890abcdef';
$_GET=['view'=>'shipping','forecast_event'=>$id];
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
$events=[$id=>[
 'date'=>$now->modify('+45 days')->format('Y-m-d'),
 'end'=>$now->modify('+55 days')->format('Y-m-d'),
 'name'=>'Fixture supplier shutdown','risk'=>'high','detail'=>'Factory closed for test','status'=>'manually managed'
]];
$q=$db->prepare('INSERT INTO settings(key,value) VALUES (?,?)');
$q->execute(['shipping_calendar_edits',json_encode($events,JSON_THROW_ON_ERROR)]);
$stockProducts=[
 ['id'=>1,'name'=>'Example product A','active'=>1,'stock_tracking'=>1,
  'stock_jay_qty'=>6,'stock_tony_qty'=>5,'stock_unallocated_qty'=>0,
  'supplier_pack_size'=>10,'low_stock_at'=>5,'stock_tracking_since'=>$now->modify('-40 days')->format('Y-m-d')],
 ['id'=>2,'name'=>'Example uncounted product','active'=>1,'stock_tracking'=>0,
  'stock_jay_qty'=>0,'stock_tony_qty'=>0,'stock_unallocated_qty'=>0,
  'supplier_pack_size'=>10,'low_stock_at'=>5,'stock_tracking_since'=>'']
];
$supplierItemRows=[
 ['product_id'=>1,'quantity'=>4,'received_qty'=>1,'status'=>'Ordered',
 'expected_date'=>$now->modify('+7 days')->format('Y-m-d')],
 ['product_id'=>1,'quantity'=>10,'received_qty'=>0,'status'=>'Ordered',
 'expected_date'=>'']
];
$retaForecastCycles=[];
function sc_recent_delivered(PDO $db,int $days):array{return [1=>30,2=>12];}
function retaProjectionRange(array $cycles,DateTimeImmutable $start,DateTimeImmutable $end,bool $includeOverdue=false):array{
 return ['stock'=>['Example product A'=>7]];
}
function e($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
ob_start();
require dirname(__DIR__).'/shipping-intelligence.php';
$html=ob_get_clean();
foreach([
 'Fixture supplier shutdown',
 'Example product A',
 'Example uncounted product',
 '70 units',
 'Needs stock count',
 'Order now',
 'Due in time',
 'Estimated cover until'
] as $expected){
 if(strpos($html,$expected)===false){fwrite(STDERR,"Missing shipping forecast output: $expected\n");exit(1);}
}
foreach(['name="cover"','No tracked products yet.'] as $old){
 if(strpos($html,$old)!==false){fwrite(STDERR,"Obsolete manual forecast output: $old\n");exit(1);}
}
// Undated incoming stock is excluded from automatic on-time cover.
if(strpos($html,'10 incoming with unconfirmed/late ETA (excluded)')===false){
 fwrite(STDERR,"Undated supplier stock was not flagged.\n");exit(1);
}
echo "PASS: real net fridge stock, on-time supplier units, dynamic disruption cover, recurring demand and uncounted product warning.\n";
