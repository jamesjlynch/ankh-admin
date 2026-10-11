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
check(array_column($futureDeliveries,'id')===[1],'Future orders must exclude imminent deliveries and terminal orders.');
check(array_column($awaitingDelivery,'id')===[2,3,4,5],'Imminent (including unpaid) and undated paid orders must be awaiting.');
check(!array_intersect(array_column($futureDeliveries,'id'),array_column($awaitingDelivery,'id')),'Delivery sections must never duplicate orders.');
check(!isFutureDelivery($orders[1],'2026-10-12'),'On its due date an order must leave Future deliveries.');
check(deliveryBoardSection(array_merge($base,['scheduled_delivery_date'=>'2026-10-15']),'2026-10-11')==='awaiting','Four days away should be Awaiting delivery.');
check(deliveryBoardSection(array_merge($base,['scheduled_delivery_date'=>'2026-10-16']),'2026-10-11')==='future','Five days away should be Future deliveries.');
check(deliveryBoardSection(array_merge($base,['scheduled_delivery_date'=>'2026-10-16','delivery_section_override'=>'awaiting']),'2026-10-11')==='awaiting','A manual move to Awaiting should override the five-day rule.');
check(deliveryBoardSection(array_merge($base,['scheduled_delivery_date'=>'2026-10-15','delivery_section_override'=>'future']),'2026-10-11')==='future','A manual move to Future should override the five-day rule.');
check(deliveryBoardSection(array_merge($base,['scheduled_delivery_date'=>'2026-10-11','delivery_section_override'=>'future']),'2026-10-11')==='awaiting','A due-today order should not remain hidden in Future.');
check(deliveryBoardSection(array_merge($base,['scheduled_delivery_date'=>'','delivery_section_override'=>'future']),'2026-10-11')==='awaiting','A paid undated order cannot be hidden manually.');
check(deliveryBoardSection(array_merge($base,['scheduled_delivery_date'=>'','status'=>'New','paid_amount'=>0]),'2026-10-11')==='','An unpaid unscheduled order belongs to Awaiting payment only.');
check(deliveryBoardSection(array_merge($base,['scheduled_delivery_date'=>'2026-10-19','delivery_date'=>'2026-10-11']),'2026-10-11')==='','Delivered orders must not appear in either delivery section.');
$ordersSection='all';$_GET=['scope'=>'to_deliver'];$paidStatuses=['Paid','Packed','Dispatched','Delivered'];$todayStart=$today->getTimestamp();$monthStart=$today->modify('first day of this month')->getTimestamp();
$start=strpos($source,"$"."orderScopes=['today_sales'");$end=strpos($source,'?>',$start);
check($start!==false&&$end!==false,'Order drill-down must exist.');eval(substr($source,$start,$end-$start));
check(array_column($listedOrders,'id')===[2,3,4,5],'To-deliver drill-down must match Awaiting delivery.');
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
preg_match("/ALTER TABLE orders ADD COLUMN delivery_section_override TEXT NOT NULL DEFAULT \\'\\'/",$source,$overrideMigration);
check(!empty($overrideMigration),'Manual delivery section migration must exist.');$db->exec($overrideMigration[0]);
check($db->query('SELECT delivery_section_override FROM orders WHERE id=1')->fetchColumn()==='','Existing orders must start with automatic routing.');
$values=array_map(fn($c)=>match($c){'customer'=>'New order','created'=>'2026-10-11T12:00:00+00:00','scheduled_delivery_date'=>'2026-10-20',default=>''},$columns);
$db->prepare($insertSql)->execute($values);$id=(int)$db->lastInsertId();
check($db->query('SELECT scheduled_delivery_date FROM orders WHERE id='.$id)->fetchColumn()==='2026-10-20','Schedule must persist.');
check($db->query('SELECT delivery_date FROM orders WHERE id='.$id)->fetchColumn()==='','Scheduling must not mark delivered.');
preg_match("/UPDATE orders SET customer=\\?,recipient_name=\\?,[^']+ WHERE id=\\?/",$source,$updateMatch);
check(!empty($updateMatch)&&str_contains($updateMatch[0],'scheduled_delivery_date=?'),'Editing must persist the schedule.');
$updateValues=$values;$updateValues[array_search('scheduled_delivery_date',$columns,true)]='';$updateValues[]=$id;
$db->prepare($updateMatch[0])->execute($updateValues);
check($db->query('SELECT scheduled_delivery_date FROM orders WHERE id='.$id)->fetchColumn()==='','Unticking must remove the schedule.');
$start=strpos($source,'   $scheduleFieldsPresent=');$end=strpos($source,'   $lines=[];$presentations=[];',$start);
$scheduleCode=substr($source,$start,$end-$start);$todayLocal=$today;
$isEdit=false;$existingOrder=null;$_POST=['schedule_fields_present'=>'1','future_delivery'=>'1'];
$rejected=false;try{eval($scheduleCode);}catch(Exception $e){$rejected=true;}check($rejected,'A selected schedule without a date must be rejected.');
$_POST=['schedule_fields_present'=>'1','scheduled_delivery_date'=>'2026-10-20'];eval($scheduleCode);check($scheduledDeliveryDate==='','Unticked schedules must ignore stale date values.');
$isEdit=true;$existingOrder=['status'=>'Delivered','delivery_date'=>'2026-10-11','scheduled_delivery_date'=>'2026-10-20'];
$_POST=['schedule_fields_present'=>'1','future_delivery'=>'1','scheduled_delivery_date'=>'2026-10-20'];eval($scheduleCode);check($scheduledDeliveryDate==='2026-10-20','Unrelated edits after early delivery must preserve the original plan.');
$_POST['scheduled_delivery_date']='2026-10-21';$rejected=false;try{eval($scheduleCode);}catch(Exception $e){$rejected=true;}check($rejected,'A completed order cannot receive a new future schedule.');
echo "PASS: future date validation, historical edits, dashboard grouping/sorting, due-day rollover, delivery drill-down, migration, create/edit persistence, no premature delivery.\n";

check(plannedDeliveryDate('2026-10-11','',$today,true)==='2026-10-11','Arranging delivery today must be allowed.');
check(deliveryCountdown('2026-10-11',$today)==='Today','Today countdown.');
check(deliveryCountdown('2026-10-12',$today)==='1 day','Tomorrow countdown.');
check(deliveryCountdown('2026-10-15',$today)==='4 days','Future countdown.');
check(deliveryCountdown('2026-10-09',$today)==='2 days overdue','Overdue countdown.');
check(deliveryCountdown('2026-10-26',new DateTimeImmutable('2026-10-24',$tz))==='2 days','Countdown must use calendar days across DST.');
$columnsInDb=$db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('status',array_column($columnsInDb,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN status TEXT NOT NULL DEFAULT 'New'");
$db->exec("CREATE TABLE payments (id INTEGER PRIMARY KEY,order_id INTEGER,amount INTEGER)");
$db->prepare('INSERT INTO payments(order_id,amount) VALUES (?,?)')->execute([$id,1000]);
$action='order_schedule';
$start=strpos($source,"  if(\$action==='order_schedule'){");$end=strpos($source,"  if(\$action==='order_reconstituted'){",$start);
check($start!==false&&$end!==false,'Dashboard arrange-delivery action must exist.');
$arrangeCode=substr($source,$start,$end-$start);
$planned=(new DateTimeImmutable('tomorrow',$tz))->format('Y-m-d');
foreach(['New','Paid'] as $status){
 $db->prepare("UPDATE orders SET status=?,delivery_date='',scheduled_delivery_date='' WHERE id=?")->execute([$status,$id]);
 $before=$db->query('SELECT * FROM orders WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
 $_POST=['id'=>$id,'scheduled_delivery_date'=>$planned];eval($arrangeCode);
 $after=$db->query('SELECT * FROM orders WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
 check($after['scheduled_delivery_date']===$planned,'Both paid and unpaid orders must be schedulable.');
 unset($before['scheduled_delivery_date'],$after['scheduled_delivery_date']);
 check($before===$after,'Arranging delivery must change only the planned date.');
 check((int)$db->query('SELECT SUM(amount) FROM payments')->fetchColumn()===1000,'Payments must remain untouched.');
 $_POST=['id'=>$id,'schedule_clear'=>'1'];eval($arrangeCode);
 check($db->query('SELECT scheduled_delivery_date FROM orders WHERE id='.$id)->fetchColumn()==='','Clear date must remove plan.');
}
foreach(['Delivered','Cancelled'] as $status){
 $db->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$status,$id]);
 $_POST=['id'=>$id,'scheduled_delivery_date'=>$planned];$rejected=false;
 try{eval($arrangeCode);}catch(Exception $e){$rejected=true;}
 check($rejected&&!$db->inTransaction(),'Terminal orders must reject scheduling and roll back.');
}
$db->prepare("UPDATE orders SET status='Paid',delivery_date='2026-10-10' WHERE id=?")->execute([$id]);
$rejected=false;try{eval($arrangeCode);}catch(Exception $e){$rejected=true;}check($rejected,'Delivered unpaid/paid orders must reject scheduling.');
$db->prepare("UPDATE orders SET delivery_date='' WHERE id=?")->execute([$id]);
foreach(['','bad-date',(new DateTimeImmutable('yesterday',$tz))->format('Y-m-d')] as $invalid){
 $_POST=['id'=>$id,'scheduled_delivery_date'=>$invalid];$rejected=false;
 try{eval($arrangeCode);}catch(Exception $e){$rejected=true;}check($rejected,'Invalid dashboard date must be rejected.');
}
echo "PASS: dashboard scheduling paid/unpaid, clear date, terminal safeguards, isolated date updates, calendar-day countdown.\n";

foreach(['reconstituted','packaged'] as $column){
 $cols=$db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC);
 if(!in_array($column,array_column($cols,'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN ".$column." INTEGER NOT NULL DEFAULT 0");
}
$db->prepare("UPDATE orders SET status='Paid',delivery_date='',reconstituted=0,packaged=0 WHERE id=?")->execute([$id]);
function preparationRow(PDO $db,int $id):array{return $db->query('SELECT * FROM orders WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);}
check(orderPreparationState(preparationRow($db,$id))==='pending','Existing orders must start pending.');
$before=preparationRow($db,$id);
$rejected=false;try{setOrderPreparation($db,$id,'packaged',true);}catch(Exception $e){$rejected=true;}
check($rejected&&!$db->inTransaction(),'Packaging must require preparation first.');
check(preparationRow($db,$id)===$before,'Rejected packaging must leave data unchanged.');
setOrderPreparation($db,$id,'reconstituted',true);
check(orderPreparationState(preparationRow($db,$id))==='reconstituted','Reconstituted stage must persist.');
setOrderPreparation($db,$id,'packaged',true);
$after=preparationRow($db,$id);
check(orderPreparationState($after)==='packaged'&&(int)$after['reconstituted']===0,'Packaging must replace reconstituted.');
unset($before['reconstituted'],$before['packaged'],$after['reconstituted'],$after['packaged']);
check($before===$after,'Preparation stages must not change status, delivery, price or schedule.');
setOrderPreparation($db,$id,'packaged',false);
check(orderPreparationState(preparationRow($db,$id))==='reconstituted','Undo packaging must return to reconstituted.');
setOrderPreparation($db,$id,'reconstituted',false);
check(orderPreparationState(preparationRow($db,$id))==='pending','Undo reconstitution must return to pending.');
foreach(['Delivered','Cancelled'] as $status){
 $db->prepare("UPDATE orders SET status=?,reconstituted=1,packaged=0 WHERE id=?")->execute([$status,$id]);
 check(orderPreparationState(preparationRow($db,$id))==='closed','Terminal orders must hide preparation badges.');
 $rejected=false;try{setOrderPreparation($db,$id,'packaged',true);}catch(Exception $e){$rejected=true;}check($rejected,'Terminal orders cannot change preparation.');
}
$db->prepare("UPDATE orders SET status='Paid',delivery_date='2026-10-11' WHERE id=?")->execute([$id]);
check(orderPreparationState(preparationRow($db,$id))==='closed','An actual delivery date must hide preparation regardless of payment status.');
$rejected=false;try{setOrderPreparation($db,$id,'reconstituted',true);}catch(Exception $e){$rejected=true;}check($rejected,'Actual delivered orders cannot change preparation.');
// Render the shared badge and controls to verify all dashboard variants use the same terminal safeguards.
function e($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function csrf():void{echo '<input name="csrf" value="test">';}
$start=strpos($source,'function orderPreparationBadge(');$end=strpos($source,'function deliveryScheduleTags(',$start);
check($start!==false&&$end!==false,'Shared preparation display helpers must exist.');eval(substr($source,$start,$end-$start));
ob_start();orderPreparationBadge(preparationRow($db,$id));dashboardPreparationControls(preparationRow($db,$id));$html=ob_get_clean();
check($html==='','Delivered dashboard cards must hide preparation badges and controls.');
$db->prepare("UPDATE orders SET delivery_date='',reconstituted=0,packaged=1 WHERE id=?")->execute([$id]);
ob_start();orderPreparationBadge(preparationRow($db,$id));$html=ob_get_clean();
check(str_contains($html,'Packaged · Ready for delivery')&&!str_contains($html,'Reconstituted'),'Packaged badge must replace reconstituted.');
echo "PASS: future orders remain to deliver, preparation transitions, packaging replaces reconstituted, undo, no premature delivery, delivered badge suppression.\n";

check(futureDeliveryPaymentLabel(['total'=>8000,'paid_amount'=>8000])==='Paid','A settled future order should display Paid.');
check(futureDeliveryPaymentLabel(['total'=>6500,'paid_amount'=>0,'status'=>'New'])==='Unpaid','An unpaid future order should display Unpaid, not New.');
check(futureDeliveryPaymentLabel(['total'=>6500,'paid_amount'=>2000])==='Part paid','A deposit should display Part paid.');
check(futureDeliveryPaymentLabel(['total'=>6500,'paid_amount'=>7000])==='Paid','Overpayments should not display unpaid.');
$futureUiStart=strpos($source,'<div class="future-delivery-list">');
$futureUiEnd=strpos($source,'<?php endforeach;?></div></div><?php endif;?>',$futureUiStart);
check($futureUiStart!==false && $futureUiEnd!==false,'Future-delivery section must render.');
$futureUi=substr($source,$futureUiStart,$futureUiEnd-$futureUiStart);
check(str_contains($futureUi,'<details class="future-order-entry"') && str_contains($futureUi,'<summary class="future-order-card"'),'Future order tile must expand when tapped.');
check(!str_contains($futureUi,'<a class="future-order-card"'),'Future order tile should no longer navigate away on tap.');
check(str_contains($futureUi,'future-order-edit-link') && str_contains($futureUi,'?view=edit&amp;id='),'Edit order must remain available inside expansion.');
check(strpos($futureUi,'future-order-details') < strpos($futureUi,'dashboardDeliveryScheduleForm(') && str_contains($futureUi,'dashboardPreparationControls('),'Delivery and preparation controls must stay inside the expandable area.');
$futureCss=file_get_contents(dirname(__DIR__).'/style.css');
check(str_contains($futureCss,'.future-order-entry[open] .future-order-chevron') && str_contains($futureCss,'.future-order-details'),'Compact future order CSS must include an expanded state.');
echo "PASS: compact expandable future delivery tiles, paid/unpaid/part-paid labels, edit access, hidden preparation and date controls.\\n";

$start=strpos($source,"  if(\$action==='order_delivery_section'){");$end=strpos($source,"  if(\$action==='order_reconstituted'){",$start);
check($start!==false&&$end!==false,'Manual delivery section action must exist.');
$moveCode=substr($source,$start,$end-$start);
$action='order_delivery_section';
$db->prepare("UPDATE orders SET status='Paid',delivery_date='',scheduled_delivery_date=?,delivery_section_override='' WHERE id=?")->execute([$planned,$id]);
$moveBefore=preparationRow($db,$id);
$_POST=['id'=>$id,'delivery_section'=>'awaiting'];eval($moveCode);
$moveAfter=preparationRow($db,$id);
check($moveAfter['delivery_section_override']==='awaiting','Manual move into Awaiting must persist.');
unset($moveBefore['delivery_section_override'],$moveAfter['delivery_section_override']);
check($moveBefore===$moveAfter,'Manual section changes cannot alter payment, delivery or preparation.');
$_POST=['id'=>$id,'delivery_section'=>'future'];eval($moveCode);
check($db->query('SELECT delivery_section_override FROM orders WHERE id='.$id)->fetchColumn()==='future','Manual move into Future must persist.');
$_POST=['id'=>$id,'delivery_section'=>'auto'];eval($moveCode);
check($db->query('SELECT delivery_section_override FROM orders WHERE id='.$id)->fetchColumn()==='','Automatic timing can be restored.');
$_POST=['id'=>$id,'delivery_section'=>'awaiting'];eval($moveCode);
$action='order_schedule';
$_POST=['id'=>$id,'scheduled_delivery_date'=>$planned];eval($arrangeCode);
check($db->query('SELECT delivery_section_override FROM orders WHERE id='.$id)->fetchColumn()==='awaiting','Saving the same date must not lose a manual override.');
$newPlanned=(new DateTimeImmutable('tomorrow',$tz))->modify('+1 day')->format('Y-m-d');
$_POST=['id'=>$id,'scheduled_delivery_date'=>$newPlanned];eval($arrangeCode);
check($db->query('SELECT delivery_section_override FROM orders WHERE id='.$id)->fetchColumn()==='','Changing the planned date should restore automatic routing.');
$action='order_delivery_section';
$_POST=['id'=>$id,'delivery_section'=>'future'];eval($moveCode);
$db->prepare("UPDATE orders SET scheduled_delivery_date=? WHERE id=?")->execute([(new DateTimeImmutable('today',$tz))->format('Y-m-d'),$id]);
$before=preparationRow($db,$id);
$_POST=['id'=>$id,'delivery_section'=>'future'];$rejected=false;try{eval($moveCode);}catch(Exception $e){$rejected=true;}
check($rejected&&!$db->inTransaction()&&preparationRow($db,$id)===$before,'Due-today orders must reject manual moves into Future.');
foreach(['Cancelled','Delivered'] as $terminal){
 $db->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$terminal,$id]);
 $_POST=['id'=>$id,'delivery_section'=>'awaiting'];$rejected=false;try{eval($moveCode);}catch(Exception $e){$rejected=true;}
 check($rejected&&!$db->inTransaction(),'Completed/cancelled orders cannot be manually moved.');
}
check(str_contains($source,"dashboardDeliveryMoveControls(\$todo,'future')") && str_contains($source,"dashboardDeliveryMoveControls(\$futureOrder,'awaiting')"),'Both dashboard lists need a manual move control.');
echo "PASS: exclusive 4-day/5-day grouping, manual moves, persistence, date-change reset, terminal-order protections.\\n";
