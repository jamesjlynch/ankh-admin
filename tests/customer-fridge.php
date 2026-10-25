<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/customer-portal/lib.php';
function fcheck(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
function finvalid(callable $cb):void{try{$cb();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid inventory action was accepted.');}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
portalFridgeInit($db);
fcheck(portalFridgeParseAmount('2.125')===2125,'Decimal amount incorrect');
fcheck(portalFridgeFormat(2125)==='2.125','Decimal formatting incorrect');
fcheck(portalFridgeFormat(10000)==='10','Whole amount formatting incorrect');
foreach(['-1','1e3','0.1234','1,000','NaN','  ','1mg','9999999','0x10'] as $bad)finvalid(static fn()=>portalFridgeParseAmount($bad));
fcheck(portalFridgeUnit(['name'=>'BPC-157 5mg'])===['unit'=>'mg','per_item'=>5000],'Mg strength extraction incorrect');
fcheck(portalFridgeUnit(['name'=>'BAC water 10 mL'])===['unit'=>'ml','per_item'=>10000],'Ml strength extraction incorrect');
fcheck(portalFridgeUnit(['name'=>'Research case'])===['unit'=>'items','per_item'=>1000],'Unlabelled item units incorrect');
$orders=[
 ['id'=>21,'status'=>'Delivered','delivery_date'=>'2026-10-10','lines'=>[
     ['name'=>'Research peptide 5mg','presentation'=>'Vial','quantity'=>2,'price'=>2000],
     ['name'=>'Sample research bottle 10ml','presentation'=>'','quantity'=>1,'price'=>1000]
 ]],
 ['id'=>22,'status'=>'New','delivery_date'=>'','lines'=>[['name'=>'Undelivered vial 10mg','quantity'=>1]]],
 ['id'=>23,'status'=>'Cancelled','delivery_date'=>'','lines'=>[['name'=>'Cancelled vial 10mg','quantity'=>1]]],
];
$choices=portalFridgeCandidates($orders,[]);
fcheck(count($choices)===2,'Undelivered or cancelled products shown');
fcheck($choices[0]['total_milli']===10000 && $choices[1]['total_milli']===10000,'Initial delivery quantities wrong');
finvalid(static fn()=>portalFridgeAdd($db,7,false,$orders,22,0));
finvalid(static fn()=>portalFridgeAdd($db,7,false,$orders,23,0));
finvalid(static fn()=>portalFridgeAdd($db,7,false,$orders,21,9));
$id=portalFridgeAdd($db,7,false,$orders,21,0);
fcheck($id>0,'Delivered item not added');
fcheck(portalFridgeAdd($db,7,false,$orders,21,0)===$id,'Double-import permitted');
fcheck(count(portalFridgeItems($db,7,false))===1,'Unexpected real account entries');
fcheck(count(portalFridgeItems($db,7,true))===0,'Staff preview can see real customer inventory');
fcheck(count(portalFridgeItems($db,8,false))===0,'Other customer can see inventory');
finvalid(static fn()=>portalFridgeOwned($db,8,false,$id));
finvalid(static fn()=>portalFridgeOwned($db,7,true,$id));
finvalid(static fn()=>portalFridgeUpdate($db,8,false,$id,'1','2','day'));
finvalid(static fn()=>portalFridgeArchive($db,7,true,$id));
portalFridgeUpdate($db,7,false,$id,'10','2','week');
$row=portalFridgeOwned($db,7,false,$id);
fcheck((int)$row['remaining_milli']===10000 && (int)$row['rate_milli']===2000 && $row['rate_period']==='week','Usage configuration wrong');
$start=(new DateTimeImmutable((string)$row['as_of_date'],new DateTimeZone('Europe/London')))->modify('+7 days')->format('Y-m-d');
$projection=portalFridgeProjection($row,$start);
fcheck($projection['remaining_milli']===8000,'Week of projection did not subtract exactly one weekly amount');
fcheck($projection['days_left']===28,'Expected estimated days remaining');
fcheck($projection['runout_date']===(new DateTimeImmutable($start))->modify('+28 days')->format('Y-m-d'),'Projected run-out date incorrect');
fcheck($projection['percent']===80,'Stock progress percentage wrong');
foreach([['1e8','1','week'],['0','-1','day'],['2','0.00001','day'],['2','2','every hour']] as $bad)finvalid(static fn()=>portalFridgeUpdate($db,7,false,$id,...$bad));
fcheck(portalFridgeOwned($db,7,false,$id)['rate_period']==='week','Invalid update changed saved setting');
$staffId=portalFridgeAdd($db,7,true,$orders,21,0);
fcheck($staffId!==$id,'Preview and live row collision');
portalFridgeUpdate($db,7,true,$staffId,'3','1','day');
fcheck((int)portalFridgeOwned($db,7,false,$id)['remaining_milli']===10000,'Preview write affected live stock');
$another=portalFridgeAdd($db,8,false,$orders,21,0);
fcheck($another!==$id,'Customers share a stock record');
portalFridgeArchive($db,7,false,$id);
fcheck(count(portalFridgeItems($db,7,false))===0,'Archived item still visible');
fcheck(count(portalFridgeItems($db,8,false))===1,'Archiving one customer removed another customer stock');
$restored=portalFridgeAdd($db,7,false,$orders,21,0);
fcheck($restored===$id && (int)portalFridgeOwned($db,7,false,$id)['remaining_milli']===10000,'Restoring archived order line failed');
fcheck(count(portalFridgeCandidates($orders,portalFridgeItems($db,7,false)))===1,'Already imported source shown again');
fcheck(count(portalFridgeHistory($db,7,false))===4,'Own inventory history should include add, edit, archive and restore');
fcheck(count(portalFridgeHistory($db,8,false))===1,'Other customer history must be isolated');
fcheck(count(portalFridgeHistory($db,7,true))===2,'Staff preview history must not show live records');
echo "My Fridge: order eligibility, quantities, forecasts, decimals, customer isolation, staff isolation and archiving passed.\n";
