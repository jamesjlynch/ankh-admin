<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/customer-portal/lib.php';
function testOK(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);}
function testInvalid(callable $fn,string $label):void {try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($label);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
ankhCustomerInteractionInit($db);
portalCollectionInit($db);
$products=[
['id'=>1,'name'=>'Test laboratory storage case','price'=>1999,'available_qty'=>6,'category'=>'Storage'],
['id'=>2,'name'=>'Test notebook','price'=>500,'available_qty'=>3,'category'=>'Stationery'],
['id'=>3,'name'=>'Test reference sample','price'=>750,'available_qty'=>0,'category'=>'Research materials']
];
$availability=[];
testOK(portalCollectionForCustomer($db,4,false,$products,$availability)===[],'Request list must start empty');
portalCollectionSet($db,4,false,1,3,$products,$availability);
portalCollectionSet($db,4,false,2,2,$products,$availability);
testOK(count(portalCollectionForCustomer($db,4,false,$products,$availability))===2,'Combined request did not save');
testOK(portalCollectionForCustomer($db,5,false,$products,$availability)===[],'Customer request privacy violated');
testOK(portalCollectionForCustomer($db,4,true,$products,$availability)===[],'Preview request privacy violated');
testInvalid(static fn()=>portalCollectionSet($db,4,false,3,1,$products,$availability),'Unavailable product added to request list');
testInvalid(static fn()=>portalCollectionSet($db,4,false,1,100,$products,$availability),'Quantity limit not applied');
testInvalid(static fn()=>portalCollectionSet($db,4,false,12,1,$products,$availability),'Unpublished product accepted');
$items=portalCollectionForCustomer($db,4,false,$products,$availability);
$revision=portalCollectionRevision($items);
testInvalid(static fn()=>portalCollectionSend($db,4,false,$products,$availability,str_repeat('b',64),str_repeat('a',64),'please confirm'),'Stale review accepted');
$changed=$products;$changed[0]['price']=2500;
testInvalid(static fn()=>portalCollectionSend($db,4,false,$changed,$availability,$revision,str_repeat('a',64),'please confirm'),'Price change accepted silently');
$id=portalCollectionSend($db,4,false,$products,$availability,$revision,str_repeat('a',64),'Please confirm packaging');
testOK($id>0,'Enquiry not saved');
$q=$db->query('SELECT * FROM catalogue_enquiries WHERE id='.$id)->fetch();
testOK($q['product_name']==='Multi-product request' && $q['preview']==='0','Combined request not marked correctly');
$lines=ankhCustomerEnquiryLines($db,$id);
testOK(count($lines)===2 && $lines[0]['quantity']===3 && $lines[1]['quantity']===2,'Combined lines lost or miscounted');
testOK($q['price']===(1999*3+500*2),'Combined display price incorrect');
testOK(portalCollectionForCustomer($db,4,false,$products,$availability)===[],'List not cleared after submitting');
testOK(portalCollectionSend($db,4,false,$products,$availability,$revision,str_repeat('a',64),'Repeat submit')===$id,'Idempotent send duplicate');
testInvalid(static fn()=>portalCollectionSend($db,4,false,$products,$availability,$revision,str_repeat('c',64),'Another request'),'Empty list submitted');

portalWatchSet($db,4,false,$products[2],$availability,true);
testOK(count(portalWatches($db,4,false))===1,'Watch not stored');
testOK(portalWatches($db,5,false)===[]&&portalWatches($db,4,true)===[],'Watchlist leaks across identities');
testInvalid(static fn()=>portalWatchSet($db,4,false,$products[0],$availability,true),'Available product watched');
testOK(portalWatchRefresh($db,4,false,$products,$availability)===[],'False restock event at subscription');
$restocked=$products;$restocked[2]['available_qty']=9;
$events=portalWatchRefresh($db,4,false,$restocked,$availability);
testOK(count($events)===1 && $events[0]['kind']==='restock','Restock event missing');
testOK(portalWatchRefresh($db,4,false,$restocked,$availability)===$events,'Duplicate event on repeated visit');
testOK(portalWatchRefresh($db,5,false,$restocked,$availability)===[],'Another account saw restock');
testOK(portalWatchRefresh($db,4,true,$restocked,$availability)===[],'Preview saw customer alert');
$noteEvents=portalNotificationEvents([],[],[],null,$events);
portalNotificationInit($db);
$notifications=portalNotificationList($db,4,false,$noteEvents);
testOK(count($notifications)===1 && !$notifications[0]['read'],'Notification not integrated');
portalNotificationUpdate($db,4,false,$noteEvents,'notification_read',$events[0]['key']);
testOK(portalNotificationList($db,4,false,$noteEvents)[0]['read'],'Restock read state not persisted');
testOK(!portalNotificationList($db,5,false,$noteEvents)[0]['read'],'Read state leaked');
portalWatchRefresh($db,4,false,$products,$availability); // returns to unavailable
$events2=portalWatchRefresh($db,4,false,$restocked,$availability);
testOK(count($events2)===2 && $events2[0]['key']!==$events[0]['key'],'A later restock should be its own event');
portalWatchSet($db,4,false,$restocked[2],$availability,false);
testOK(portalWatches($db,4,false)===[],'Unwatch failed');
testOK(portalWatchRefresh($db,4,false,$restocked,$availability)===[],'Unwatched product kept generating alerts');
echo "Customer request list, multi-line enquiries, watchlists, restock events, isolation, replay and stock-privacy checks passed.\n";
