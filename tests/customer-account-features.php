<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/customer-portal/lib.php';
function ok(bool $condition,string $label):void { if(!$condition)throw new RuntimeException($label); }
function expectInvalid(callable $action,string $label):void {
    try{$action();}catch(InvalidArgumentException $e){return;}
    throw new RuntimeException($label);
}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
portalExtrasInit($db);
$token=str_repeat('a',64);
$id=portalExtrasSaveDelivery($db,9,false,"14 Test Road\nRhyl LL18 1AA",'Ring the doorbell','Text message',$token);
ok($id===portalExtrasSaveDelivery($db,9,false,'attempted mutation','','No preference',$token),'Idempotent delivery request changed');
expectInvalid(static fn()=>portalExtrasSaveDelivery($db,9,false,'Another road','','No preference',str_repeat('b',64)),'Overlapping pending delivery request was accepted');
ok(portalExtrasDelivery($db,9,false)['saved']===null,'Unapproved delivery preference was displayed as saved');
portalExtrasReview($db,'delivery',$id,'approve','Confirmed by ANKH');
ok(str_contains(portalExtrasDelivery($db,9,false)['saved']['address'],'14 Test Road'),'Approved delivery preference did not save');
ok(portalExtrasDelivery($db,9,true)['saved']===null,'Preview vs real customer data leaked');
ok(portalExtrasDelivery($db,10,false)['saved']===null,'Other customer delivery preference leaked');
expectInvalid(static fn()=>portalExtrasReview($db,'delivery',$id,'approve','Again'),'Already reviewed request reapproved');
expectInvalid(static fn()=>portalExtrasSaveDelivery($db,9,false,'x','','Email',str_repeat('c',64)),'Unsupported contact method accepted');
expectInvalid(static fn()=>portalExtrasSaveDelivery($db,9,false,str_repeat('x',401),'','Phone call',str_repeat('c',64)),'Oversized address accepted');
$next=['id'=>42,'revision'=>str_repeat('f',64),'last_order_id'=>72,'product'=>'Test item','due_date'=>'2026-11-01'];
$nid=portalExtrasSaveNext($db,9,false,$next,'request_now',$next['revision'],str_repeat('d',64));
ok($nid===portalExtrasSaveNext($db,9,false,$next,'request_now',$next['revision'],str_repeat('d',64)),'Next-order idempotency broken');
expectInvalid(static fn()=>portalExtrasSaveNext($db,9,false,$next,'skip',$next['revision'],str_repeat('e',64)),'Overlapping next order action accepted');
expectInvalid(static fn()=>portalExtrasSaveNext($db,9,false,$next,'skip','stale',str_repeat('f',64)),'Stale schedule revision accepted');
ok(count(portalExtrasNextList($db,9,false))===1,'Next-order list missing');
ok(count(portalExtrasNextList($db,10,false))===0,'Another account can read next-order requests');
portalExtrasReview($db,'next',$nid,'approve','Arrange in Admin');
ok(portalExtrasNextList($db,9,false)[42]['status']==='Approved','Next-order review did not save');
$id2=portalExtrasSaveNext($db,9,false,$next,'skip',$next['revision'],str_repeat('9',64));
portalExtrasReview($db,'next',$id2,'decline','Please call');
ok(portalExtrasNextList($db,9,false)[42]['status']==='Declined','Newest action was not selected');
$prod=['id'=>1,'available_qty'=>12];
ok(portalExtrasAvailability($prod)['label']==='Available','Automatic tracked stock');
ok(portalExtrasAvailability(['id'=>1,'available_qty'=>3])['label']==='Limited availability','Low stock label');
ok(portalExtrasAvailability(['id'=>1,'available_qty'=>0], [1=>'available'])['label']==='Currently unavailable','Override falsely claimed stock');
ok(portalExtrasAvailability(['id'=>1,'available_qty'=>0], [1=>'coming_soon'])['label']==='Coming soon','Coming soon not allowed');
ok(portalExtrasAvailability(['id'=>1,'available_qty'=>null])['label']==='Ask about availability','Untracked stock fabricated');
ok(portalExtrasAvailability(['id'=>1,'available_qty'=>18],[1=>'unavailable'])['label']==='Currently unavailable','Manual unavailability not honoured');
$order=[
  'status'=>'Delivered','total'=>4500,'paid'=>4000,'created'=>'2026-10-01T10:00:00+01:00','delivery_date'=>'2026-10-05'
];
$cancelled=['status'=>'Cancelled','total'=>9900,'paid'=>0,'created'=>'2026-10-02','delivery_date'=>''];
$stats=portalExtrasInsights([$order,$cancelled]);
ok($stats['count']===1 && $stats['total']===4500 && $stats['paid']===4000 && $stats['outstanding']===500,'Insights totals include cancelled amounts or are incorrect');
ok($stats['delivered']===1 && $stats['last_delivery']==='2026-10-05','Delivery insights incorrect');
ok(count($stats['months'])===6,'Monthly chart should show six months');
$admin=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$admin->exec("CREATE TABLE products (id INTEGER PRIMARY KEY,name TEXT,active INTEGER);
INSERT INTO products(id,name,active) VALUES(1,'Product A',1),(2,'Product B',0); PRAGMA query_only=ON");
portalExtrasAvailabilitySave($db,$admin,1,'limited');
ok(portalExtrasAvailabilityMap($db)[1]==='limited','Staff availability label not saved');
expectInvalid(static fn()=>portalExtrasAvailabilitySave($db,$admin,2,'available'),'Staff edited inactive product');
expectInvalid(static fn()=>portalExtrasAvailabilitySave($db,$admin,1,'made_up'),'Staff accepted bogus availability label');
portalExtrasAvailabilitySave($db,$admin,1,'automatic');
ok(!isset(portalExtrasAvailabilityMap($db)[1]),'Automatic did not remove manual override');
ok((int)$admin->query('SELECT COUNT(*) FROM products')->fetchColumn()===2,'Unexpected Admin data mutation');
echo "Customer account extras: privacy, requests, availability and insights passed.\n";
