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
foreach(['e','money','csrf','assigneeClass','deliveryCountdown','orderPreparationState','orderPreparationBadge','dashboardPreparationControls','futureDeliveryPaymentLabel','orderDisplayName','orderBuyerCaption','dashboardQueueView','deliveryScheduleTags','dashboardDeliveryScheduleForm','dashboardDeliveryMoveControls'] as $name)loadPresentationFunction($source,$name);
recordingCheck(orderDisplayName(['customer'=>'Morgan','recipient_name'=>'Lee'])==='Lee','Recipient should lead the card.');
recordingCheck(orderDisplayName(['customer'=>'Morgan','recipient_name'=>' '])==='Morgan','Blank recipient should fall back to purchaser.');
recordingCheck(dashboardQueueView(['payment'=>0,'delivery'=>2,'future'=>1])==='delivery','Default queue must select available work.');
recordingCheck(dashboardQueueView(['payment'=>2],'bogus','future')==='future','Remembered valid empty filters must remain selectable.');
recordingCheck(dashboardQueueView(['payment'=>2],'../settings','invalid')==='payment','Unknown queue input must be rejected.');
$start=strpos($source,'<section class="todo-board dashboard-action-queue"');
$end=strpos($source,'<details class="dashboard-stock-shortage',$start);
recordingCheck($start!==false&&$end!==false,'Queue must precede stock detail.');
$template=substr($source,$start,$end-$start);
// The stock alert PHP conditional begins immediately after the queue.
$template=substr($template,0,strrpos($template,'<?php if($stockShortages):?>'));
$tz=new DateTimeZone('Europe/London');$now=new DateTimeImmutable('2026-10-13 12:00:00',$tz);
$base=['customer'=>'Morgan','recipient_name'=>'Lee','status'=>'New','total'=>6500,'paid_amount'=>0,'delivery_date'=>'','created'=>'2026-10-11','assigned_to'=>'James','payment_method'=>'Bank Transfer','delivery_method'=>'Local Delivery','address'=>'','tracking_reference'=>'','reconstituted'=>0,'packaged'=>0,'delivery_section_override'=>''];
$near=$base+['id'=>1,'scheduled_delivery_date'=>'2026-10-16'];
$future=$base+['id'=>2,'scheduled_delivery_date'=>'2026-10-23'];
$awaitingPayment=[$near,$future];$awaitingDelivery=[$near];$futureDeliveries=[$future];
$todoItems=[1=>[['quantity'=>1,'name'=>'Test product','presentation'=>'Vial']],2=>[['quantity'=>1,'name'=>'Test product','presentation'=>'Vial']]];
$dashboardQueueCounts=['payment'=>2,'delivery'=>1,'future'=>1];$stockShortages=[];$stockShortageUnits=0;$unpaidBalance=13000;$paymentMethods=['Bank Transfer'];
foreach(['payment'=>2,'delivery'=>1,'future'=>1] as $dashboardQueue=>$expected){
 $_SESSION=['csrf'=>'fixture-only','dashboard_queue'=>$dashboardQueue];
 ob_start();eval('?>'.$template);$html=ob_get_clean();
 $cards=substr_count($html,'class="todo-card todo-accordion"')+substr_count($html,'class="future-order-entry"');
 recordingCheck($cards===$expected,'Only the selected queue may render orders: '.$dashboardQueue);
 recordingCheck(substr_count($html,'aria-current="page"')===1,'Exactly one queue must be current.');
 recordingCheck(!str_contains($html,'class="future-order-date"'),'Date tiles must not duplicate the combined badge.');
 recordingCheck(str_contains($html,'<h3>Lee</h3>')&&str_contains($html,'Ordered by Morgan'),'Recipient and buyer must both remain visible.');
 recordingCheck(str_contains($html,'name="return_queue" value="'.$dashboardQueue.'"'),'Actions must preserve queue context.');
}
recordingCheck(strpos($source,'id="ankh-assistant-button"')<strpos($source,'<main id="ankh-main"'),'Assistant trigger must be in the header.');
recordingCheck(substr_count($source,'id="ankh-assistant-button"')===1,'Assistant trigger must not be duplicated.');
recordingCheck(str_contains($source,'viewport-fit=cover'),'iOS safe-area values must be enabled.');
recordingCheck(str_contains($source,'All-time gross profit'),'Profit period must be explicit.');
echo "PASS: selected queues render once, recipient-first names, queue context, combined dates, header assistant and safe-area metadata.\n";
