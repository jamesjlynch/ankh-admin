<?php
declare(strict_types=1);
// Real entrypoint requests against an isolated SQLite fixture; never production data.
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/ankh-bank-details-'.bin2hex(random_bytes(6));mkdir($temp,0700);
$config=$temp.'/config.php';
file_put_contents($config,'<?php $_SESSION["admin"]=true; $_SESSION["last"]=time(); $_SESSION["csrf"]="bank-details-fixture"; return '.var_export(['password_hash'=>password_hash('fixture-only',PASSWORD_DEFAULT),'database_path'=>$temp.'/fixture.sqlite'],true).';');
function bankCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function bankRequest(array $get=[],array $post=[]):string{
 global $root,$config;
 $code='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).');$_GET='.var_export($get,true).';$_POST='.var_export($post+['csrf'=>'bank-details-fixture'],true).';$_SERVER["REQUEST_METHOD"]='.var_export($post?'POST':'GET',true).';$_SERVER["REMOTE_ADDR"]="127.0.0.1";$_SERVER["HTTP_HOST"]="localhost";$_SERVER["SCRIPT_NAME"]="/index.php";require '.var_export($root.'/index.php',true).';';
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1',$out,$exit);$html=implode("\n",$out);
 bankCheck($exit===0&&!preg_match('/(?:Fatal error|Warning|Notice):/',$html),'Request error: '.substr($html,-2000));return $html;
}
function bankReminderMessage(string $html):string{
 if(!preg_match('/data-reminder-message="([^"]*)"/',$html,$match))throw new RuntimeException('Balance reminder message not found.');
 return html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
}
function bankReference(string $message):array{
 if(!preg_match('/Reference: ([^\r\n]+)/',$message,$match))throw new RuntimeException('Reminder reference not found.');
 return array_values(array_map('trim',explode(',',$match[1])));
}
try{
 bankRequest(['view'=>'balances']);$db=new PDO('sqlite:'.$temp.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $setting=static function(string $key)use($db):string{$q=$db->prepare('SELECT value FROM settings WHERE key=?');$q->execute([$key]);return (string)($q->fetchColumn()?:'');};
 $db->exec("INSERT INTO orders(id,customer,phone,address,notes,status,created) VALUES(90001,'Bank Fixture','07123456789','','','New','2026-11-01T10:00:00Z'); INSERT INTO items(order_id,name,price,quantity) VALUES(90001,'Fixture peptide',5000,1);");
 $html=bankRequest(['view'=>'balances']);$message=bankReminderMessage($html);
 bankCheck(!str_contains($message,'Pay by bank transfer'),'Reminder must keep its current text when bank details are not set.');
 bankCheck(str_contains($html,'Add bank details in Settings'),'Missing bank details must show a Settings link beside the reminder button.');
 bankCheck(!str_contains($html,'Payment details saved.'),'Unsaved settings must not show a saved notice.');

 foreach([
  ['bank_account_name'=>'','bank_sort_code'=>'12-34-56','bank_account_number'=>'12345678','error'=>'account name'],
  ['bank_account_name'=>'Fixture Bank','bank_sort_code'=>'12-34-5','bank_account_number'=>'12345678','error'=>'six-digit sort code'],
  ['bank_account_name'=>'Fixture Bank','bank_sort_code'=>'12-34-56','bank_account_number'=>'1234567','error'=>'eight-digit account number'],
  ['bank_account_name'=>'Fixture Bank','bank_sort_code'=>'12-34-56','bank_account_number'=>'12345678','bank_name'=>str_repeat('x',121),'error'=>'bank name']
 ] as $attempt){
  $error=$attempt['error'];unset($attempt['error']);
  $html=bankRequest([],['action'=>'bank_details_save']+$attempt);
  bankCheck(str_contains($html,$error),'Invalid '.$error.' must be rejected.');
  bankCheck($setting('bank_account_name')==='','Invalid details must not be saved.');
 }
 $html=bankRequest([],['action'=>'bank_details_save','csrf'=>'wrong-fixture','bank_account_name'=>'Fixture Bank','bank_sort_code'=>'12-34-56','bank_account_number'=>'12345678']);
 bankCheck(str_contains($html,'Please refresh the page and try again.'),'Wrong CSRF token must be rejected.');
 bankCheck($setting('bank_account_name')==='','CSRF rejection must not save bank details.');

 bankRequest([],['action'=>'bank_details_save','bank_account_name'=>'Fixture Bank','bank_sort_code'=>'12-34-56','bank_account_number'=>'1234 5678','bank_name'=>'Fake Bank']);
 bankCheck($setting('bank_account_name')==='Fixture Bank'&&$setting('bank_sort_code')==='12-34-56'&&$setting('bank_account_number')==='12345678'&&$setting('bank_name')==='Fake Bank','Valid fake bank details must be saved and normalised.');
 bankCheck((int)$db->query("SELECT COUNT(*) FROM activity_events WHERE details_json LIKE '%Fixture Bank%' OR details_json LIKE '%12345678%'")->fetchColumn()===0,'Bank details must never be written to the activity log.');

 $html=bankRequest(['view'=>'balances']);$message=bankReminderMessage($html);
 bankCheck(str_contains($message,"Pay by bank transfer:\nName: Fixture Bank\nSort code: 12-34-56\nAccount: 12345678\nReference: ANK-90001"),'Single-order reminder must append the bank footer and order reference.');
 bankCheck(!str_contains($html,'Add bank details in Settings'),'Saved bank details must remove the Settings prompt.');

 $db->exec("INSERT INTO orders(id,customer,phone,address,notes,status,created) VALUES(90002,'Bank Fixture','07123456789','','','New','2026-11-02T10:00:00Z'); INSERT INTO items(order_id,name,price,quantity) VALUES(90002,'Fixture peptide',2500,1);");
 $message=bankReminderMessage(bankRequest(['view'=>'balances']));$references=bankReference($message);
 bankCheck(count($references)===2&&in_array('ANK-90001',$references,true)&&in_array('ANK-90002',$references,true),'Multi-order balance must list each order number.');

 $db->exec("INSERT INTO orders(id,customer,phone,address,notes,status,created) VALUES(90003,'Bank Fixture','07123456789','','','New','2026-11-03T10:00:00Z'),(90004,'Bank Fixture','07123456789','','','New','2026-11-04T10:00:00Z'),(90005,'Bank Fixture','07123456789','','','New','2026-11-05T10:00:00Z'),(90006,'Bank Fixture','07123456789','','','New','2026-11-06T10:00:00Z'); INSERT INTO items(order_id,name,price,quantity) VALUES(90003,'Fixture peptide',1000,1),(90004,'Fixture peptide',1000,1),(90005,'Fixture peptide',1000,1),(90006,'Fixture peptide',1000,1);");
 $references=bankReference(bankReminderMessage(bankRequest(['view'=>'balances'])));
 bankCheck(count($references)===5,'Multi-order reference must be capped at five order numbers.');
 bankCheck(count(array_unique($references))===5,'Multi-order reference must not repeat order numbers.');

 echo "PASS: bank details save/normalise, server validation, CSRF rejection, private settings, reminder text with and without details, single/multi reference and five-order cap.\n";
}finally{$db=null;foreach(glob($temp.'/*')?:[] as $file)unlink($file);rmdir($temp);}
