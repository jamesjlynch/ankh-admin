<?php
declare(strict_types=1);
// ANKH business-only email: no customer recipients and no public entrypoint.
function ankhAlertPrefs(PDO $db):array {
 $q=$db->prepare("SELECT value FROM settings WHERE key='business_alert_prefs'");$q->execute();$saved=json_decode((string)($q->fetchColumn()?:'{}'),true);
 $defaults=['daily'=>true,'new_order'=>true,'stock'=>true,'payment'=>true,'delivery'=>true,'recurring'=>true];
 return array_replace($defaults,is_array($saved)?array_intersect_key($saved,$defaults):[]);
}
function ankhAlertRecipients(array $prefs):array {
 return ['info@ankhpeptides.com'];
}
function ankhAlertSummary(PDO $db,array $prefs):string {
 $today=new DateTimeImmutable('today',new DateTimeZone('Europe/London'));
 $tomorrow=$today->modify('+1 day')->format('Y-m-d');
 $out=["ANKH Admin — business alerts",$today->format('l j F Y'),""];
 if(!empty($prefs['delivery'])){
  $q=$db->prepare("SELECT COUNT(*) FROM orders WHERE scheduled_delivery_date=? AND status NOT IN ('Cancelled','Delivered','Dispatched') AND COALESCE(delivery_date,'')=''");
  $q->execute([$tomorrow]);$out[]='Deliveries scheduled for tomorrow: '.(int)$q->fetchColumn();
 }
 if(!empty($prefs['stock'])){
  $q=$db->query("SELECT name,COALESCE(stock_jay_qty,0)+COALESCE(stock_tony_qty,0) AS qty,low_stock_at FROM products WHERE active=1 AND stock_tracking=1 AND COALESCE(stock_jay_qty,0)+COALESCE(stock_tony_qty,0)<=low_stock_at ORDER BY qty,name LIMIT 25");
  $rows=$q->fetchAll(PDO::FETCH_ASSOC);$out[]='Low-stock products: '.count($rows);
  foreach($rows as $r)$out[]='  - '.$r['name'].': '.$r['qty'].' available (threshold '.$r['low_stock_at'].')';
 }
 if(!empty($prefs['payment'])){
  $out[]='Unpaid balances and overdue payment details: review the Balances page.';
 }
 if(!empty($prefs['recurring']))$out[]='Recurring orders and reorder forecasts: review Cycle Planner / Reorder Planner.';
 $out[]='';$out[]='Open ANKH Admin: https://admin.ankhpeptides.com/';$out[]='Internal business notification — not for customers.';
 return implode("\n",$out)."\n";
}
function ankhAlertHtml(PDO $db,array $prefs,bool $test=false):string {
 $today=new DateTimeImmutable('today',new DateTimeZone('Europe/London'));
 $date=htmlspecialchars($today->format('l j F Y'),ENT_QUOTES,'UTF-8');
 $rows=[];$delivery=null;$lowCount=null;
 if(!empty($prefs['delivery'])){
  $tomorrow=$today->modify('+1 day')->format('Y-m-d');
  $q=$db->prepare("SELECT COUNT(*) FROM orders WHERE scheduled_delivery_date=? AND status NOT IN ('Cancelled','Delivered','Dispatched') AND COALESCE(delivery_date,'')=''");
  $q->execute([$tomorrow]);$delivery=(int)$q->fetchColumn();
 }
 if(!empty($prefs['stock'])){
  $q=$db->query("SELECT name,COALESCE(stock_jay_qty,0)+COALESCE(stock_tony_qty,0) AS qty,low_stock_at FROM products WHERE active=1 AND stock_tracking=1 AND COALESCE(stock_jay_qty,0)+COALESCE(stock_tony_qty,0)<=low_stock_at ORDER BY qty,name LIMIT 25");
  $rows=$q->fetchAll(PDO::FETCH_ASSOC);$lowCount=count($rows);
 }
 $block='';
 if($test)$block.='<tr><td style="padding:14px 24px;background:#263324;color:#cce7bc;font-size:13px">TEST PREVIEW — figures below come from your current ANKH database. Daily sending is unchanged.</td></tr>';
 $tiles='';
 if($delivery!==null)$tiles.='<td style="width:50%;padding:8px"><div style="padding:18px;border:1px solid #39362d;border-radius:12px;background:#20211f"><span style="font-size:12px;color:#aaa69b">DUE TOMORROW</span><div style="color:#f5e4b6;font-size:30px;font-weight:700;margin-top:7px">'.$delivery.'</div><div style="font-size:12px;color:#c7c3b8">Scheduled deliveries</div></div></td>';
 if($lowCount!==null)$tiles.='<td style="width:50%;padding:8px"><div style="padding:18px;border:1px solid #39362d;border-radius:12px;background:#20211f"><span style="font-size:12px;color:#aaa69b">STOCK WATCH</span><div style="color:#f5e4b6;font-size:30px;font-weight:700;margin-top:7px">'.$lowCount.'</div><div style="font-size:12px;color:#c7c3b8">Products at or below threshold</div></div></td>';
 if($tiles!=='')$block.='<tr><td style="padding:15px 16px 5px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'.$tiles.'</tr></table></td></tr>';
 if($lowCount!==null){
  $block.='<tr><td style="padding:18px 24px 6px"><h2 style="margin:0;font-size:17px;color:#f4d891">Low-stock watchlist</h2><p style="color:#b4b0a8;font-size:13px;line-height:1.6;margin:8px 0">Stock across Jay and Tony’s fridges.</p></td></tr>';
  if(!$rows)$block.='<tr><td style="padding:6px 24px 18px;color:#c6d9bc;font-size:13px">No tracked products are below their alert threshold.</td></tr>';
  else foreach($rows as $row){
   $name=htmlspecialchars((string)$row['name'],ENT_QUOTES,'UTF-8');$qty=(int)$row['qty'];$threshold=(int)$row['low_stock_at'];
   $block.='<tr><td style="padding:7px 24px"><table role="presentation" width="100%" style="border-bottom:1px solid #363731;padding-bottom:10px"><tr><td style="color:#efede5;font-size:13px;font-weight:600">'.$name.'</td><td align="right" style="color:#ebc27a;font-size:12px">'.$qty.' available / alert at '.$threshold.'</td></tr></table></td></tr>';
  }
 }
 if(!empty($prefs['payment'])||!empty($prefs['recurring'])){
  $block.='<tr><td style="padding:20px 24px 8px"><h2 style="font-size:17px;color:#f4d891;margin:0">Your business checklist</h2></td></tr>';
  if(!empty($prefs['payment']))$block.='<tr><td style="padding:8px 24px;color:#e3e0d5;font-size:13px">• Review outstanding balances <a style="color:#e9c777" href="https://admin.ankhpeptides.com/?view=balances">View payments →</a></td></tr>';
  if(!empty($prefs['recurring']))$block.='<tr><td style="padding:8px 24px;color:#e3e0d5;font-size:13px">• Check recurring demand <a style="color:#e9c777" href="https://admin.ankhpeptides.com/?view=reta">Cycle planner →</a> and <a style="color:#e9c777" href="https://admin.ankhpeptides.com/?view=reorder">Reorder planner →</a></td></tr>';
 }
 return '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ANKH Admin Daily Brief</title></head><body style="margin:0;padding:24px 12px;background:#0c0e0c;color:#ece9df;font-family:Arial,Helvetica,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;margin:0 auto;background:#171a17;border:1px solid #3e382b;border-radius:16px"><tr><td style="padding:32px 24px 26px;background:#121410;text-align:center;border-bottom:1px solid #383426"><div style="font-size:36px;color:#dcb96d">☥</div><div style="font-size:24px;letter-spacing:3px;color:#e7c780;font-weight:bold">ANKH ADMIN</div><div style="font-size:12px;color:#aaa69a;letter-spacing:2px;margin-top:8px">PRIVATE BUSINESS BRIEF</div></td></tr><tr><td style="padding:26px 24px 8px"><h1 style="font-size:23px;line-height:1.3;color:#faf2dd;margin:0">Your daily overview</h1><p style="font-size:13px;color:#aba99f;margin-top:8px">'.$date.'</p></td></tr>'.$block.'<tr><td style="padding:28px 24px 18px;text-align:center"><a href="https://admin.ankhpeptides.com/" style="display:inline-block;background:#d7b369;color:#17130c;text-decoration:none;font-weight:bold;font-size:13px;padding:14px 23px;border-radius:10px">Open ANKH Admin →</a></td></tr><tr><td style="text-align:center;padding:18px 22px 26px;border-top:1px solid #39362d;color:#8f9188;font-size:11px;line-height:1.7">Internal business notification for Jay and Tony only.<br>Sent by admin@ankhpeptides.com · ANKH Admin</td></tr></table></body></html>';
}
function ankhOrderNotice(PDO $db,int $orderId):array {
 $q=$db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$orderId]);$order=$q->fetch(PDO::FETCH_ASSOC);
 if(!$order)throw new RuntimeException('Order no longer exists.');
 $q=$db->prepare('SELECT i.*,p.stock_jay_qty,p.stock_tony_qty,p.stock_unallocated_qty,p.stock_tracking,p.low_stock_at FROM items i LEFT JOIN products p ON p.id=i.product_id WHERE i.order_id=? ORDER BY i.id');
 $q->execute([$orderId]);$items=$q->fetchAll(PDO::FETCH_ASSOC);
 $total=(int)($order['delivery_charge']??0);
 foreach($items as $item)$total+=(int)$item['quantity']*(int)$item['price'];
 $q=$db->prepare('SELECT username FROM admin_users WHERE id=?');$q->execute([(int)($_SESSION['admin_user_id']??0)]);
 $username=strtolower((string)($q->fetchColumn()?:''));
 // Creator comes from authenticated account, not order assignee.
 $actor=match($username){'jay','james'=>'James','tony','anthony'=>'Tony',default=>'An ANKH administrator'};
 return [$order,$items,$total,$actor];
}
function ankhNewOrderBody(PDO $db,int $orderId):array {
 [$order,$items,$total,$actor]=ankhOrderNotice($db,$orderId);
 $money=static fn(int $p):string=>'£'.number_format($p/100,2);
 $escape=static fn($s):string=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 $ref='ANK-'.str_pad((string)$orderId,4,'0',STR_PAD_LEFT);
 $paidStmt=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=?');$paidStmt->execute([$orderId]);$paid=(int)$paidStmt->fetchColumn();
 $outstanding=max(0,$total-$paid);
 $paymentLabel=$total===0?'Free':($outstanding===0?'Paid in full':($paid>0?'Part paid':'Awaiting payment'));
 $historyStmt=$db->prepare("SELECT COUNT(*) FROM orders WHERE id<>? AND lower(trim(customer))=lower(trim(?)) AND (phone=? OR ?='')");
 $historyStmt->execute([$orderId,(string)$order['customer'],(string)$order['phone'],(string)$order['phone']]);
 $priorOrders=(int)$historyStmt->fetchColumn();$historyLabel=$priorOrders===0?'New customer':'Returning customer · '.$priorOrders.' previous order'.($priorOrders===1?'':'s');
 $scheduled=trim((string)($order['scheduled_delivery_date']??''));
 $deliveryCountdown='';
 if($scheduled!==''&&preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$scheduled)){
  $days=(int)(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->diff(new DateTimeImmutable($scheduled,new DateTimeZone('Europe/London')))->format('%r%a');
  $deliveryCountdown=$days>0?'Due in '.$days.' day'.($days===1?'':'s'):($days===0?'Due today':'Delivery date passed');
 }
 $prepLabel=!empty($order['packaged'])?'Packaged':(!empty($order['reconstituted'])?'Reconstituted · packaging outstanding':'Awaiting preparation');
 $alerts=[];$actions=[];
 if($outstanding>0)$actions[]='Collect '.$money($outstanding).' outstanding';
 if(empty($order['packaged']))$actions[]='Prepare and package ordered items';
 if($deliveryCountdown!=='')$actions[]='Check delivery · '.$deliveryCountdown;
 $recurringCount=0;foreach($items as $item)if((int)($item['recurring']??0)===1)$recurringCount++;
 if($recurringCount>0)$actions[]='Review recurring schedule for '.$recurringCount.' product'.($recurringCount===1?'':'s');
 $subject='New order '.$ref.' — added by '.$actor;
 $plain=$actor." has added a new order to the ANKH Admin App.\nOrder: ".$ref."\nCustomer: ".$order['customer']."\n";
 if(trim((string)($order['recipient_name']??''))!=='')$plain.="For: ".$order['recipient_name']."\n";
 $plain.="Phone: ".($order['phone']?:'Not provided')."\nDelivery method: ".($order['delivery_method']?:'Not set')."\nAddress: ".($order['address']?:'Not recorded')."\n";
 if(!empty($order['scheduled_delivery_date']))$plain.="Scheduled delivery: ".$order['scheduled_delivery_date']."\n";
 $plain.="Payment method: ".($order['payment_method']?:'Not set')."\nAssigned to: ".($order['assigned_to']?:'Not assigned')."\n";
 $plain.="\nProducts:\n";
 $rows='';
 foreach($items as $item){
  $qty=(int)$item['quantity'];$name=(string)$item['name'];$presentation=trim((string)($item['presentation']??''));$line=$qty*(int)$item['price'];
  $stock='Not tracked';$stockHtml='<span style="color:#91958c">Not tracked</span>';
  if((int)($item['stock_tracking']??0)===1){
   $available=(int)$item['stock_jay_qty']+(int)$item['stock_tony_qty'];$threshold=(int)($item['low_stock_at']??0);
   $jay=(int)$item['stock_jay_qty'];$tony=(int)$item['stock_tony_qty'];
   $stock=$available.' combined available (Jay '.$jay.', Tony '.$tony.')';
   if($available<=$threshold){$alerts[]=$name.' at/below stock threshold';$actions[]='Check stock for '.$name;}
   if($available<0||(int)$item['stock_jay_qty']<0||(int)$item['stock_tony_qty']<0){$alerts[]=$name.' has a stock reservation shortage';$actions[]='Resolve stock reservation shortage for '.$name;}
   $color=$available<=$threshold?'#f6ae7c':'#c8d9b9';
   $stockHtml='<span style="color:'.$color.';font-weight:bold">'.$available.' available</span><br><span style="color:#b5b1a7;font-size:12px">Jay: '.$jay.' &nbsp;·&nbsp; Tony: '.$tony.' &nbsp;·&nbsp; Alert at: '.$threshold.'</span>';
  }
  $plain.=$qty.' × '.$name.($presentation?' ('.$presentation.')':'').' — '.$money($line).' | '.$stock."\n";
  $rows.='<tr><td style="padding:12px 4px;border-bottom:1px solid #36362e"><div style="color:#f4ede0;font-weight:bold;font-size:14px">'.$escape($name).'</div><div style="font-size:12px;color:#a8a69d;margin-top:4px">'.$qty.' × '.$escape($presentation?:'Standard').'</div><div style="margin-top:8px;font-size:12px">Remaining stock: '.$stockHtml.'</div></td><td align="right" valign="top" style="padding:12px 4px;border-bottom:1px solid #36362e;white-space:nowrap;color:#e8ca8a;font-weight:bold">'.$money($line).'</td></tr>';
 }
 $plain.="\nPayment status: ".$paymentLabel." | Paid: ".$money($paid)." | Outstanding: ".$money($outstanding)."\nCustomer history: ".$historyLabel."\nPreparation: ".$prepLabel.($deliveryCountdown!==''?' | '.$deliveryCountdown:'')."\n";
 $plain.="\nDelivery charge: ".$money((int)($order['delivery_charge']??0))."\nOrder total: ".$money($total)."\n";
 if(trim((string)($order['notes']??''))!=='')$plain.="Order notes: ".$order['notes']."\n";
 $plain.="\nOpen order: https://admin.ankhpeptides.com/?view=edit&id=".$orderId."\n";
 $label=static fn(string $key,$val):string=>'<tr><td style="font-size:12px;color:#aaa89e;padding:7px 0;vertical-align:top;width:135px">'.$escape($key).'</td><td style="font-size:13px;color:#f2eee3;padding:7px 0;white-space:pre-wrap">'.$escape($val?:'Not provided').'</td></tr>';
 $details=$label('Customer',(string)$order['customer']).$label('Ordered for',(string)($order['recipient_name']??'')).$label('Phone',(string)$order['phone']).$label('Delivery method',(string)($order['delivery_method']??'')).$label('Delivery address',(string)$order['address']).$label('Delivery date',(string)($order['scheduled_delivery_date']??'')).$label('Assigned to',(string)($order['assigned_to']??'')).$label('Payment method',(string)($order['payment_method']??''));
 $quick='<tr><td style="padding:6px 25px 12px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #554630;background:#24251e;border-radius:12px"><tr><td style="padding:16px 12px"><div style="font-size:11px;color:#b7b1a3">PAYMENT STATUS</div><div style="font-size:14px;color:#edcd88;font-weight:bold;margin-top:7px">'.$escape($paymentLabel).'</div></td><td style="padding:16px 12px" align="right"><div style="font-size:11px;color:#b7b1a3">TO COLLECT</div><div style="font-size:23px;color:#edcd88;font-weight:bold;margin-top:7px">'.$money($outstanding).'</div></td></tr></table></td></tr>';
 $info='<div style="padding:12px 0;border-top:1px solid #39372f;border-bottom:1px solid #39372f;margin:14px 0;color:#d5cab2;font-size:12px;line-height:1.8">'.$escape($historyLabel).' &nbsp;·&nbsp; '.$escape($prepLabel).($deliveryCountdown!==''?' &nbsp;·&nbsp; '.$escape($deliveryCountdown):'').'</div>';
 $nextHtml='';
 if($actions){$nextHtml='<div style="margin-top:24px;padding:16px;background:#282720;border:1px solid #594b31;border-radius:11px"><div style="color:#f0d18c;font-size:16px;font-weight:bold;margin-bottom:10px">Next actions</div>';foreach(array_unique($actions) as $task)$nextHtml.='<div style="color:#f2e8d2;font-size:13px;padding:7px 0;line-height:1.4">• '.$escape($task).'</div>';$nextHtml.='</div>';}
 $warnings='';
 if($alerts){$warnings='<div style="background:#38291f;border:1px solid #79533c;padding:15px;border-radius:11px;margin-top:20px"><div style="font-size:14px;color:#ffcf9f;font-weight:bold">Stock attention required</div>';foreach(array_unique($alerts) as $alert)$warnings.='<div style="font-size:12px;color:#f4d9bd;padding:6px 0">'.$escape($alert).'</div>';$warnings.='</div>';}
 $plain.="\n".$historyLabel."\n".$prepLabel."\nNext actions:\n".implode("\n",array_unique($actions))."\n";
 $notes=trim((string)($order['notes']??''))!==''?'<div style="padding:15px;border:1px solid #55452b;border-radius:9px;color:#e6d2a3;font-size:12px;white-space:pre-wrap;margin-top:15px"><b>Order notes</b><br>'.$escape($order['notes']).'</div>':'';
 $html='<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="background:#0f110e;margin:0;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;color:#eee9db"><table role="presentation" style="max-width:620px;margin:auto;background:#191b18;border:1px solid #48402e;border-radius:16px" cellspacing="0" cellpadding="0" width="100%"><tr><td style="background:#111410;padding:28px 23px;text-align:center;border-bottom:1px solid #473d2c"><div style="font-size:34px;color:#e3bf75">☥</div><div style="font-size:24px;color:#e8c982;font-weight:bold;letter-spacing:2px">ANKH ADMIN</div><div style="color:#a6a296;font-size:11px;letter-spacing:2px;margin-top:9px">NEW ORDER RECEIVED</div></td></tr><tr><td style="padding:25px"><div style="display:inline-block;background:#393423;color:#f4d794;border-radius:15px;padding:6px 12px;font-size:12px">'.$escape($ref).'</div><h1 style="font-size:23px;color:#fbf3dc;margin:17px 0 6px">'.$escape($actor).' has added an order</h1>'.$info.'<p style="font-size:13px;color:#c7c1b2;margin:0">A new order has been entered into ANKH Admin.</p><div style="margin:22px 0;padding:20px;background:#25261f;border:1px solid #504630;border-radius:12px"><div style="font-size:12px;color:#bbb5a6">ORDER VALUE</div><div style="font-size:32px;font-weight:bold;color:#edcd88;margin-top:6px">'.$money($total).'</div><div style="color:#b6b4a9;font-size:12px;margin-top:4px">'.count($items).' product line'.(count($items)===1?'':'s').'</div></div><h2 style="font-size:16px;color:#e8c982;margin:0 0 12px">Customer & delivery</h2><table width="100%" cellspacing="0">'.$details.'</table>'.$notes.'<h2 style="font-size:16px;color:#e8c982;margin:24px 0 8px">Products ordered & stock remaining</h2><table width="100%" cellspacing="0">'.$rows.'</table><table width="100%" style="margin-top:19px;border-top:1px solid #514730"><tr><td style="padding:13px 4px;color:#aaa89e;font-size:12px">Delivery charge</td><td align="right" style="color:#f0e6d2;font-size:13px">'.$money((int)($order['delivery_charge']??0)).'</td></tr><tr><td style="padding:8px 4px;font-weight:bold">ORDER TOTAL</td><td align="right" style="padding:8px 4px;color:#edcd88;font-weight:bold;font-size:20px">'.$money($total).'</td></tr></table>'.$warnings.$nextHtml.'<p style="text-align:center;margin:26px 0 12px"><a href="https://admin.ankhpeptides.com/?view=edit&amp;id='.$orderId.'" style="display:inline-block;background:#dab66e;color:#19140b;text-decoration:none;border-radius:10px;padding:14px 26px;font-weight:bold;font-size:13px">View order in ANKH Admin →</a></p></td></tr><tr><td style="text-align:center;border-top:1px solid #3c382d;padding:20px;color:#8f9288;font-size:11px">Private operational alert · ANKH Admin<br>From admin@ankhpeptides.com · Internal use only</td></tr></table></body></html>';
 $html=ankhMobileOrderHtml($orderId,$order,$items,$total,$actor,$paid,$outstanding,$paymentLabel,$historyLabel,$prepLabel,$deliveryCountdown);
 return [$subject,$plain,$html];
}

/** iPhone-first order email. Keep a deliberately light, single-column layout:
 * Gmail iOS can auto-invert dark HTML emails into low-contrast beige. */
function ankhMobileOrderHtml(int $orderId,array $order,array $items,int $total,string $actor,int $paid,int $outstanding,string $paymentLabel,string $historyLabel,string $prepLabel,string $deliveryCountdown):string {
 $e=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 $money=static fn(int $p):string=>'£'.number_format($p/100,2);
 $url='https://admin.ankhpeptides.com/?view=edit&id='.$orderId;
 $ref='ANK-'.str_pad((string)$orderId,4,'0',STR_PAD_LEFT);
 $customer=(string)($order['customer']??'');
 $date=(new DateTimeImmutable('now',new DateTimeZone('Europe/London')))->format('D j M · g:i A');
 $products='';$alerts=[];$actions=[];$units=0;$recurring=false;
 foreach($items as $item){
  $qty=max(0,(int)$item['quantity']);$units+=$qty;
  $name=$e($item['name']);$presentation=$e(trim((string)($item['presentation']??''))?:'Standard');
  $line=$money((int)$item['price']*$qty);
  $stock='<span style="color:#777d75">Stock not tracked</span>';
  if((int)($item['stock_tracking']??0)===1){
   // Product fridge quantities are available balances AFTER active reservations.
   // Never subtract the newly saved order a second time.
   $jay=(int)($item['stock_jay_qty']??0);$tony=(int)($item['stock_tony_qty']??0);
   $balance=$jay+$tony;$available=max(0,$balance);
   $low=$balance<=(int)($item['low_stock_at']??0);
   $short=$jay<0||$tony<0||$balance<0;
   $stock='<span style="font-weight:700;color:'.($short||$low?'#96622a':'#315b45').'">'.$available.' left</span>'
    .' <span style="color:#6c746b">· Jay '.max(0,$jay).' / Tony '.max(0,$tony).'</span>';
   if($short)$alerts[]=(string)$item['name'].' — reservation shortage';
   elseif($low)$alerts[]=(string)$item['name'].' — low stock ('.$available.' left)';
  }
  if((int)($item['recurring']??0)===1)$recurring=true;
  $products.='<tr><td style="padding:14px 0;border-bottom:1px solid #e7e8e3;vertical-align:top;word-break:break-word">'
   .'<div style="font-size:15px;font-weight:700;color:#242a23;line-height:1.4">'.$name.'</div>'
   .'<div style="font-size:13px;color:#626960;margin-top:4px">'.$qty.' × '.$presentation.'</div>'
   .'<div style="font-size:12px;line-height:1.6;margin-top:7px">'.$stock.'</div></td>'
   .'<td align="right" valign="top" style="padding:15px 0 12px 8px;border-bottom:1px solid #e7e8e3;color:#252b24;white-space:nowrap;font-weight:700;font-size:14px">'.$line.'</td></tr>';
 }
 $detail=static function(string $label,string $value)use($e):string{
  if(trim($value)==='')return '';
  return '<tr><td valign="top" style="padding:6px 0;font-size:12px;line-height:1.6;color:#70766d;width:32%">'.$e($label).'</td>'
   .'<td valign="top" style="padding:6px 0 6px 8px;font-size:14px;line-height:1.5;color:#252c25;font-weight:600;overflow-wrap:anywhere;word-break:break-word">'.$e($value).'</td></tr>';
 };
 $recipient=trim((string)($order['recipient_name']??''));
 $customerDetails=$detail('Customer',$customer).$detail('History',$historyLabel);
 if($recipient!==''&&strcasecmp($recipient,$customer)!==0)$customerDetails.=$detail('For',$recipient);
 $customerDetails.=$detail('Phone',(string)($order['phone']??''));
 $customerDetails.=$detail('Method',(string)($order['delivery_method']??''));
 $customerDetails.=$detail('Delivery date',(string)($order['scheduled_delivery_date']??''));
 $customerDetails.=$detail('Address',(string)($order['address']??''));
 $customerDetails.=$detail('Assigned to',(string)($order['assigned_to']??''));
 $customerDetails.=$detail('Payment via',(string)($order['payment_method']??''));
 $notes=trim((string)($order['notes']??''));
 $notesHtml=$notes!==''?'<div style="padding:12px;margin-top:12px;background:#f6f5f0;border-left:3px solid #c0a068"><div style="font-size:11px;letter-spacing:.5px;font-weight:bold;color:#6b5834">ORDER NOTES</div><div style="margin-top:5px;font-size:13px;line-height:1.55;color:#363b34;white-space:pre-wrap;overflow-wrap:anywhere">'.$e($notes).'</div></div>':'';
 $warnHtml='';
 if($alerts){
  $warnHtml='<tr><td style="padding:2px 18px 13px"><div style="padding:12px;background:#fff7eb;border-left:3px solid #b78745"><div style="font-size:13px;font-weight:700;color:#7e511f">Stock attention</div>';
  foreach(array_unique($alerts) as $alert)$warnHtml.='<div style="font-size:13px;line-height:1.5;color:#664520;margin-top:5px">'.$e($alert).'</div>';
  $warnHtml.='</div></td></tr>';
 }
 if($outstanding>0)$actions[]='Collect '.$money($outstanding).' outstanding';
 $status=(string)($order['status']??'');
 if(!in_array($status,['Cancelled','Delivered','Dispatched'],true)){
  if(empty($order['reconstituted']))$actions[]='Prepare products';
  if(empty($order['packaged']))$actions[]='Package order';
 }
 if(trim((string)($order['scheduled_delivery_date']??''))!=='')$actions[]='Check delivery arrangements';
 if($recurring)$actions[]='Review recurring order';
 if($alerts)$actions[]='Review flagged stock';
 $actionRows='';
 foreach($actions as $action)$actionRows.='<tr><td style="width:18px;padding:5px 0;font-size:15px;color:#ab8546;vertical-align:top">•</td><td style="padding:5px 0;color:#353c34;font-size:14px;line-height:1.4">'.$e($action).'</td></tr>';
 $actionSection=$actionRows!==''?'<tr><td style="padding:9px 18px 13px"><div style="font-size:16px;font-weight:700;color:#262d25;margin-bottom:8px">Next actions</div><table role="presentation" cellspacing="0" cellpadding="0" width="100%">'.$actionRows.'</table></td></tr>':'';
 $html='<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
 .'<body style="margin:0;padding:0;background:#f3f4f1;color:#252b24;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%">'
 .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:#f3f4f1"><tr><td align="center" style="padding:10px 6px">'
 .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;max-width:540px;table-layout:fixed;background:#ffffff;border:1px solid #e1e4df;border-radius:10px">'
 .'<tr><td style="padding:18px 18px 16px;border-bottom:3px solid #b9914e"><div style="font-size:19px;font-weight:800;letter-spacing:1px;color:#1c241b">☥ ANKH <span style="color:#ae8641">ADMIN</span></div><div style="font-size:11px;color:#737b70;letter-spacing:1.1px;font-weight:700;margin-top:5px">NEW ORDER</div></td></tr>'
 .'<tr><td style="padding:18px 18px 10px"><div style="font-size:12px;font-weight:700;color:#987643">'.$e($ref).'</div><h1 style="font-size:21px;font-weight:800;color:#202820;line-height:1.3;margin:7px 0">'.$e($actor).' added an order</h1><div style="font-size:12px;color:#6e756d">'.$e($date).'</div></td></tr>'
 .'<tr><td style="padding:5px 18px 16px"><div style="padding:15px;background:#f7f6f1;border:1px solid #e7e3d8;border-radius:8px"><div style="font-size:11px;font-weight:700;letter-spacing:.5px;color:#737971">ORDER TOTAL</div><div style="font-size:27px;font-weight:800;color:#232b23;line-height:1.4">'.$money($total).'</div><div style="font-size:13px;color:#6a7169">'.$units.' item'.($units===1?'':'s').' · '.$e($paymentLabel).'</div>'.($outstanding>0?'<div style="font-weight:700;color:#805b28;font-size:13px;margin-top:5px">'.$money($outstanding).' to collect</div>':'').'</div></td></tr>'
 .'<tr><td style="padding:7px 18px 17px"><h2 style="font-size:16px;color:#252e25;margin:0 0 8px">Customer & delivery</h2><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;table-layout:fixed">'.$customerDetails.'</table>'.$notesHtml.'</td></tr>'
 .'<tr><td style="padding:15px 18px 7px;border-top:1px solid #ebebe7"><h2 style="font-size:16px;color:#252e25;margin:0 0 5px">Products</h2><div style="font-size:12px;line-height:1.5;color:#6f786e">Stock available after existing reservations</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;table-layout:fixed">'.$products.'</table></td></tr>'
 .$warnHtml
 .'<tr><td style="padding:7px 18px 14px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;border-top:1px solid #e7e9e3"><tr><td style="padding:13px 0 5px;color:#6a736a;font-size:13px">Delivery</td><td align="right" style="padding:13px 0 5px;font-size:13px;color:#292e28">'.$money((int)($order['delivery_charge']??0)).'</td></tr><tr><td style="padding:5px 0;color:#6a736a;font-size:13px">Paid</td><td align="right" style="padding:5px 0;color:#292e28;font-size:13px">'.$money($paid).'</td></tr><tr><td style="padding:8px 0;color:#282e28;font-weight:700;font-size:13px">Outstanding</td><td align="right" style="padding:8px 0;color:#85622c;font-size:15px;font-weight:700">'.$money($outstanding).'</td></tr></table></td></tr>'
 .$actionSection
 .'<tr><td style="padding:5px 18px 23px"><a href="'.$e($url).'" style="display:block;background:#202820;border-radius:8px;padding:14px 10px;text-align:center;color:#ffffff;text-decoration:none;font-size:15px;line-height:22px;font-weight:700">View order in ANKH Admin &#8594;</a></td></tr>'
 .'<tr><td style="padding:15px 18px;border-top:1px solid #e7e9e3;text-align:center;font-size:11px;line-height:1.6;color:#808780">ANKH Admin · Internal business notification<br>admin@ankhpeptides.com → info@ankhpeptides.com</td></tr>'
 .'</table></td></tr></table></body></html>';
 return $html;
}

function ankhNewOrderMailState(PDO $db,int $orderId):string {
 $q=$db->prepare('SELECT value FROM settings WHERE key=?');
 $q->execute(['business_alert_new_order:'.$orderId]);
 $value=$q->fetchColumn();
 return $value===false?'':(string)$value;
}
// One message per order. Failed attempts may only be retried deliberately through the
// authenticated settings page. A prior SMTP 250 is never automatically resent.
function ankhSendNewOrderNotice(PDO $db,int $orderId,bool $manualRetry=false):string {
 $prefs=ankhAlertPrefs($db);
 if(!$manualRetry&&empty($prefs['new_order']))return 'disabled';
 if($orderId<1)throw new RuntimeException('Choose a valid order.');
 $key='business_alert_new_order:'.$orderId;
 $db->beginTransaction();
 try{
  $q=$db->prepare('SELECT value FROM settings WHERE key=?');$q->execute([$key]);$previous=$q->fetchColumn();
  if($previous!==false){
   if(!$manualRetry){$db->rollBack();return 'already_handled';}
   if(!str_starts_with((string)$previous,'failed:')){
    throw new RuntimeException(str_starts_with((string)$previous,'sending')?'An email may already be in progress. Contact support before retrying to avoid duplicates.':'Google previously accepted this order email. A duplicate will not be sent.');
   }
   $db->prepare('UPDATE settings SET value=? WHERE key=?')->execute(['sending:'.gmdate('c'),$key]);
  }else{
   $exists=$db->prepare('SELECT id FROM orders WHERE id=?');$exists->execute([$orderId]);
   if(!$exists->fetchColumn())throw new RuntimeException('This order does not exist.');
   $db->prepare('INSERT INTO settings(key,value) VALUES(?,?)')->execute([$key,'sending:'.gmdate('c')]);
  }
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 try{
  [$subject,$plain,$html]=ankhNewOrderBody($db,$orderId);
  ankhSmtpSend('info@ankhpeptides.com',$subject,$plain,$html);
  $db->prepare('UPDATE settings SET value=? WHERE key=?')->execute(['accepted:'.gmdate('c'),$key]);
  return 'accepted';
 }catch(Throwable $e){
  $db->prepare('UPDATE settings SET value=? WHERE key=?')->execute(['failed: '.substr($e->getMessage(),0,220),$key]);
  throw $e;
 }
}
function ankhSmtpSend(string $recipient,string $subject,string $body,?string $html=null):void {
 if(!in_array(strtolower($recipient),['info@ankhpeptides.com'],true))throw new RuntimeException('Recipient is not an authorised ANKH administrator.');
 $host='smtp-relay.gmail.com';$port=587;
 $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'SNI_enabled'=>true]]);
 $socket=@stream_socket_client("tcp://{$host}:{$port}",$errno,$errstr,12,STREAM_CLIENT_CONNECT,$context);
 if(!$socket)throw new RuntimeException('Google SMTP connection failed.');
 stream_set_timeout($socket,15);
 $read=function()use($socket):array {
  $raw='';$code=0;
  do{$line=fgets($socket,2048);if($line===false)throw new RuntimeException('Google SMTP connection closed.');$raw.=$line;$code=(int)substr($line,0,3);}while(isset($line[3])&&$line[3]==='-');
  return [$code,$raw];
 };
 $expect=function(array $allowed)use($read):void{$response=$read();if(!in_array($response[0],$allowed,true))throw new RuntimeException('Google SMTP rejected request ('.$response[0].').');};
 $send=function(string $command)use($socket):void{if(fwrite($socket,$command."\r\n")===false)throw new RuntimeException('SMTP write failed.');};
 try{
  $expect([220]);$send('EHLO admin.ankhpeptides.com');$expect([250]);$send('STARTTLS');$expect([220]);
  if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('STARTTLS negotiation failed.');
  $send('EHLO admin.ankhpeptides.com');$expect([250]);
  $from='admin@ankhpeptides.com';
  $send('MAIL FROM:<'.$from.'>');$expect([250]);$send('RCPT TO:<'.$recipient.'>');$expect([250,251]);$send('DATA');$expect([354]);
  $safeSubject=str_replace(["\r","\n"],' ',$subject);
  $headers="From: ANKH Admin <{$from}>\r\nTo: <{$recipient}>\r\nSubject: {$safeSubject}\r\nDate: ".date(DATE_RFC2822)."\r\nMIME-Version: 1.0\r\n";
  if($html!==null){
   $boundary='ankh_'.bin2hex(random_bytes(14));
   $headers.="Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
   $payload=$headers."\r\n--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n")."--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html),76,"\r\n")."--{$boundary}--\r\n";
  }else{$payload=$headers."Content-Type: text/plain; charset=UTF-8\r\n\r\n".$body;}
  $payload=preg_replace('/^\./m','..',str_replace(["\r\n","\r"],"\n",$payload));
  fwrite($socket,str_replace("\n","\r\n",rtrim($payload,"\n"))."\r\n.\r\n");$expect([250]);
  $send('QUIT');
 }finally{fclose($socket);}
}
function ankhAlertDispatch(PDO $db,bool $test=false):array {
 $prefs=ankhAlertPrefs($db);$recipients=ankhAlertRecipients($prefs);
 if(!$recipients)throw new RuntimeException('No business alerts recipient configured.');
 if(!$test&&!$prefs['daily'])return ['skipped'=>'Daily summary disabled'];
 // Use a date key and reserve it transactionally before attempting delivery to prevent duplicates.
 $day=(new DateTimeImmutable('now',new DateTimeZone('Europe/London')))->format('Y-m-d');
 $sent=[];$errors=[];
 foreach($recipients as $address){
  $key='business_alert_sent:'.$day.':'.$address;
  if(!$test){
   $db->beginTransaction();
   $q=$db->prepare("SELECT value FROM settings WHERE key=?");$q->execute([$key]);
   if($q->fetchColumn()!==false){$db->rollBack();continue;}
   $db->prepare("INSERT INTO settings(key,value) VALUES(?,?)")->execute([$key,'attempting']);
   $db->commit();
  }
  try{
   ankhSmtpSend($address,$test?'ANKH Admin — Branded Email Preview':'ANKH Admin — Daily Business Summary',ankhAlertSummary($db,$prefs),ankhAlertHtml($db,$prefs,$test));
   $sent[]=$address;
   if(!$test)$db->prepare("UPDATE settings SET value=? WHERE key=?")->execute([gmdate('c'),$key]);
  }catch(Throwable $e){
   $errors[]=$address.': '.$e->getMessage();
   if(!$test)$db->prepare("DELETE FROM settings WHERE key=? AND value='attempting'")->execute([$key]);
  }
 }
 return ['sent'=>$sent,'errors'=>$errors];
}
