<?php
declare(strict_types=1);
// Render the real dashboard queue against fixtures, without live configuration,
// SQLite or POST requests. An unpaid future order belongs to two filters but
// must appear only once in whichever queue the user selects.
$source=file_get_contents(dirname(__DIR__).'/index.php');
function recordingCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function loadPresentationFunction(string $source,string $name):void{
 $start=strpos($source,'function '.$name.'(');
 if($start===false)throw new RuntimeException('Missing helper: '.$name);
 $code='';$depth=0;$started=false;
 foreach(token_get_all('<?php '.substr($source,$start)) as $token){
  if(is_array($token)&&$token[0]===T_OPEN_TAG&&$code==='')continue;
  $text=is_array($token)?$token[1]:$token;$code.=$text;
  if($token==='{'){$depth++;$started=true;}
  if($token==='}'&&--$depth===0&&$started){eval($code);return;}
 }
 throw new RuntimeException('Unclosed helper: '.$name);
}
foreach(['e','money','csrf','assigneeClass','deliveryCountdown','deliveryBoardSection','orderPreparationState','orderPreparationBadge','dashboardPreparationControls','futureDeliveryPaymentLabel','orderDisplayName','orderBuyerCaption','dashboardQueueView','dashboardWorklist','dashboardWorkGroup','dashboardWorklistSort','orderFocusStatuses','deliveryScheduleTags','dashboardDeliveryScheduleForm','dashboardDeliveryMoveControls'] as $name)loadPresentationFunction($source,$name);
recordingCheck(orderDisplayName(['customer'=>'Morgan','recipient_name'=>'Lee'])==='Lee','Recipient should lead the card.');
recordingCheck(orderDisplayName(['customer'=>'Morgan','recipient_name'=>' '])==='Morgan','Blank recipient should fall back to purchaser.');
recordingCheck(dashboardQueueView(['payment'=>0,'delivery'=>2,'future'=>1])==='all','Default worklist must show all work.');
recordingCheck(dashboardQueueView(['payment'=>2],'bogus','future')==='future','Remembered valid empty filters must remain selectable.');
recordingCheck(dashboardQueueView(['payment'=>2],'../settings','invalid')==='all','Unknown queue input must be rejected.');
$start=strpos($source,'<section class="todo-board dashboard-action-queue"');
$end=strpos($source,'<!-- dashboard-worklist-end -->',$start);
recordingCheck($start!==false&&$end!==false,'Queue must precede stock detail.');
$template=substr($source,$start,$end-$start);
$tz=new DateTimeZone('Europe/London');$now=new DateTimeImmutable('2026-10-13 12:00:00',$tz);
$base=['customer'=>'Morgan','recipient_name'=>'Lee','status'=>'New','total'=>6500,'paid_amount'=>0,'delivery_date'=>'','created'=>'2026-10-11','assigned_to'=>'James','payment_method'=>'Bank Transfer','delivery_method'=>'Local Delivery','address'=>'','tracking_reference'=>'','reconstituted'=>0,'packaged'=>0,'delivery_section_override'=>''];
$near=$base+['id'=>1,'scheduled_delivery_date'=>'2026-10-16'];
$future=$base+['id'=>2,'scheduled_delivery_date'=>'2026-10-23'];
$delivered=array_replace($base,['id'=>3,'recipient_name'=>'Delivered fixture','delivery_date'=>'2026-10-12','scheduled_delivery_date'=>'2026-10-12']);
$overdue=array_replace($base,['id'=>4,'scheduled_delivery_date'=>'2026-10-10']);
$undated=array_replace($base,['id'=>5,'status'=>'Paid','paid_amount'=>6500,'scheduled_delivery_date'=>'']);
$partial=array_replace($base,['id'=>6,'paid_amount'=>2000,'scheduled_delivery_date'=>'2026-10-24','packaged'=>1]);
$awaitingPayment=[$near,$future,$delivered,$overdue,$partial];$awaitingDelivery=[$near,$overdue,$undated];$futureDeliveries=[$future,$partial];
$todoItems=[1=>[['quantity'=>1,'name'=>'Test product','presentation'=>'Vial']],2=>[['quantity'=>1,'name'=>'Test product','presentation'=>'Vial']]];
$all=dashboardWorklist($awaitingPayment,$awaitingDelivery,$futureDeliveries);$delivery=dashboardWorklist([],$awaitingDelivery,$futureDeliveries);
recordingCheck(count($all)===6,'Overlapping work must be deduplicated by order ID.');
recordingCheck(array_column(dashboardWorklistSort($all,'2026-10-13'),'id')===[4,1,2,6,5,3],'Overdue, upcoming, unscheduled and delivered-unpaid work must sort predictably.');
$dashboardQueueCounts=['all'=>6,'payment'=>5,'delivery'=>5,'future'=>2];$stockShortages=[];$stockShortageUnits=0;$unpaidBalance=30500;$paymentMethods=['Bank Transfer'];
foreach(['cards','dispatch'] as $dashboardLayout)foreach(['all'=>6,'payment'=>5,'delivery'=>5,'future'=>2] as $dashboardQueue=>$expected){
 $_SESSION=['csrf'=>'fixture-only','dashboard_queue'=>$dashboardQueue];
 $dashboardWork=dashboardWorklistSort(match($dashboardQueue){'payment'=>$awaitingPayment,'delivery'=>$delivery,'future'=>$futureDeliveries,default=>$all},'2026-10-13');
 ob_start();eval('?>'.$template);$html=ob_get_clean();
 $cards=substr_count($html,'class="todo-card todo-accordion focus-order"');
 recordingCheck($cards===$expected,'Only the selected queue may render orders: '.$dashboardQueue);
 recordingCheck(substr_count($html,'aria-current="page"')===1,'Exactly one queue must be current.');
 recordingCheck(!str_contains($html,'class="future-order-date"'),'Date tiles must not duplicate the combined badge.');
 recordingCheck(str_contains($html,'<h3>Lee</h3>')&&str_contains($html,'Ordered by Morgan'),'Recipient and buyer must both remain visible.');
 recordingCheck(str_contains($html,'name="return_queue" value="'.$dashboardQueue.'"'),'Actions must preserve queue context.');
 foreach($dashboardWork as $order)recordingCheck(substr_count($html,'id="work-order-'.$order['id'].'"')===1,'Each order must appear once in either layout.');
 recordingCheck(str_contains($html,'layout-'.$dashboardLayout),'Chosen layout must be rendered.');
 recordingCheck(str_contains($html,'?view=edit&amp;id='),'Every worklist must preserve editing access.');
 if(in_array($dashboardQueue,['all','payment'],true)){
  recordingCheck(str_contains($html,'Delivered fixture'),'Delivered-unpaid work must remain visible.');
  preg_match('/id="work-order-3".*?<\/details>/s',$html,$match);
  recordingCheck(str_contains($match[0],'focus-paid">Delivered')&&str_contains($match[0],'£65.00 unpaid'),'Payment and delivery statuses must be independent.');
  recordingCheck(!str_contains($match[0],'name="stage"')&&!str_contains($match[0],'Mark delivered'),'Delivered orders must not offer preparation or delivery again.');
 }
}
ob_start();orderFocusStatuses($partial);$partialHtml=ob_get_clean();
recordingCheck(str_contains($partialHtml,'£45.00 remaining')&&str_contains($partialHtml,'Packaged · Ready'),'Partial payment and preparation must both remain visible.');
ob_start();orderFocusStatuses(array_replace($base,['customer'=>'<script>x</script>','recipient_name'=>'<b>unsafe</b>']));$statuses=ob_get_clean();
ob_start();orderBuyerCaption(array_replace($base,['customer'=>'<script>x</script>']));$caption=ob_get_clean();
recordingCheck(!str_contains($caption,'<script>')&&str_contains($caption,'&lt;script&gt;'),'Buyer captions must remain escaped.');
recordingCheck(strpos($source,'id="ankh-assistant-button"')<strpos($source,'<main id="ankh-main"'),'Assistant trigger must be in the header.');
recordingCheck(substr_count($source,'id="ankh-assistant-button"')===1,'Assistant trigger must not be duplicated.');
recordingCheck(str_contains($source,'viewport-fit=cover'),'iOS safe-area values must be enabled.');
recordingCheck(str_contains($source,'All-time gross profit'),'Profit period must be explicit.');
echo "PASS: selected queues render once, recipient-first names, queue context, combined dates, header assistant and safe-area metadata.\n";
