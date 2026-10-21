<?php
declare(strict_types=1);
// ANKH business-only email: no customer recipients and no public entrypoint.
function ankhAlertPrefs(PDO $db):array {
 $q=$db->prepare("SELECT value FROM settings WHERE key='business_alert_prefs'");$q->execute();$saved=json_decode((string)($q->fetchColumn()?:'{}'),true);
 $defaults=['jay'=>true,'tony'=>true,'daily'=>true,'stock'=>true,'payment'=>true,'delivery'=>true,'recurring'=>true];
 return array_replace($defaults,is_array($saved)?array_intersect_key($saved,$defaults):[]);
}
function ankhAlertRecipients(array $prefs):array {
 $list=[];
 if(!empty($prefs['jay']))$list[]='james@ankhpeptides.com';
 if(!empty($prefs['tony']))$list[]='anthony@ankhpeptides.com';
 return $list;
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
function ankhSmtpSend(string $recipient,string $subject,string $body):void {
 if(!in_array(strtolower($recipient),['james@ankhpeptides.com','anthony@ankhpeptides.com'],true))throw new RuntimeException('Recipient is not an authorised ANKH administrator.');
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
  $headers="From: ANKH Admin <{$from}>\r\nTo: <{$recipient}>\r\nSubject: {$safeSubject}\r\nDate: ".date(DATE_RFC2822)."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
  $payload=$headers."\r\n".preg_replace('/^\./m','..',str_replace(["\r\n","\r"],"\n",$body));
  fwrite($socket,str_replace("\n","\r\n",rtrim($payload,"\n"))."\r\n.\r\n");$expect([250]);
  $send('QUIT');
 }finally{fclose($socket);}
}
function ankhAlertDispatch(PDO $db,bool $test=false):array {
 $prefs=ankhAlertPrefs($db);$recipients=ankhAlertRecipients($prefs);
 if(!$recipients)throw new RuntimeException('Enable at least one recipient in Business Alerts.');
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
   ankhSmtpSend($address,$test?'ANKH Admin — Test Email':'ANKH Admin — Daily Business Summary', $test?"Test successful. ANKH Admin can send business emails through Google Workspace.\n":ankhAlertSummary($db,$prefs));
   $sent[]=$address;
   if(!$test)$db->prepare("UPDATE settings SET value=? WHERE key=?")->execute([gmdate('c'),$key]);
  }catch(Throwable $e){
   $errors[]=$address.': '.$e->getMessage();
   if(!$test)$db->prepare("DELETE FROM settings WHERE key=? AND value='attempting'")->execute([$key]);
  }
 }
 return ['sent'=>$sent,'errors'=>$errors];
}
