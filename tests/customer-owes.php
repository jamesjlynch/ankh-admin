<?php
declare(strict_types=1);
// Exercise the real customers screen against isolated data, never production.
$root=dirname(__DIR__);
$temp=sys_get_temp_dir().'/ankh-owes-'.bin2hex(random_bytes(6));
mkdir($temp,0700);
$config=$temp.'/config.php';
file_put_contents($config,'<?php $_SESSION["admin"]=true;$_SESSION["admin_user_id"]=1;$_SESSION["last"]=time(); return '.var_export(['password_hash'=>password_hash('test-only',PASSWORD_DEFAULT),'database_path'=>$temp.'/test.sqlite'],true).';');
function owesCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function owesRender(array $get):string{
 global $root,$config;
 $code='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).'); $_GET='.var_export($get,true).'; $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; $_SERVER["HTTP_HOST"]="localhost"; $_SERVER["SCRIPT_NAME"]="/index.php"; require '.var_export($root.'/index.php',true).';';
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1',$lines,$exit);
 $html=implode("\n",$lines);
 owesCheck($exit===0&&!preg_match('/(?:Fatal error|Warning|Notice):/',$html),'Customers screen did not render: '.substr($html,-1800));
 return $html;
}
try{
 owesRender(['view'=>'dashboard']);
 $db=new PDO('sqlite:'.$temp.'/test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec("INSERT INTO admin_users(id,username,password_hash,active,created_at) VALUES(1,'fixture',".$db->quote(password_hash('test-only',PASSWORD_DEFAULT)).',1,'.time().')');
 $db->exec("INSERT INTO customers(id,name,phone,address,created) VALUES
  (99001,'High Balance','07700900001','','2026-01-01'),
  (99002,'Low Balance','07700900002','','2026-01-01'),
  (99003,'No Balance','07700900003','','2026-01-01'),
  (99004,'Back Order Only','07700900004','','2026-01-01')");
 $db->exec("INSERT INTO orders(id,customer,phone,address,notes,status,created,back_order) VALUES
  (99001,'High Balance','07700900001','','','New','2026-01-10T09:00:00Z',0),
  (99002,'High Balance','07700900001','','','New','2026-03-05T09:00:00Z',0),
  (99003,'Low Balance','07700900002','','','New','2026-02-01T09:00:00Z',0),
  (99004,'No Balance','07700900003','','','Paid','2026-03-01T09:00:00Z',0),
  (99005,'Back Order Only','07700900004','','','New','2025-12-01T09:00:00Z',1)");
 $db->exec("INSERT INTO items(order_id,name,price,quantity) VALUES
  (99001,'Fixture A',15000,1),
  (99002,'Fixture B',5000,1),
  (99003,'Fixture C',5000,1),
  (99004,'Fixture D',4000,1),
  (99005,'Fixture E',9000,1)");
 $db->exec("INSERT INTO payments(order_id,amount,method,note,created) VALUES
  (99003,2000,'Bank transfer','Part payment','2026-02-02T09:00:00Z'),
  (99004,4000,'Bank transfer','Paid in full','2026-03-02T09:00:00Z')");
 $html=owesRender(['view'=>'customers','insight'=>'owes']);
 owesCheck(substr_count($html,'data-customer-insight=')===6,'Customers screen must render six insight filters.');
 owesCheck(preg_match('~<button type="button" class="selected" data-customer-insight="owes">Owes money <b>2</b></button>~',$html)===1,'Owes money chip must be selected by the insight parameter and count only clients with balances.');
 owesCheck(str_contains($html,'£200.00 owed'),'High balance client must show the amount owed.');
 owesCheck(str_contains($html,'£30.00 owed'),'Part-paid client must show the remaining amount owed.');
 owesCheck(str_contains($html,'Oldest unpaid 10 Jan 2026'),'Rows must show the oldest unpaid order date.');
 $highPos=strpos($html,'data-customer-search="high balance');
 $lowPos=strpos($html,'data-customer-search="low balance');
 owesCheck($highPos!==false&&$lowPos!==false&&$highPos<$lowPos,'Owes money list must sort by amount owed, highest first.');
 $backPos=strpos($html,'data-customer-search="back order only');
 owesCheck($backPos!==false,'Back-order fixture must be listed among clients.');
 $backCard=substr($html,$backPos,700);
 owesCheck(str_contains($backCard,'data-customer-owes="0"'),'Back orders must be excluded from money owed.');
 owesCheck(!str_contains($backCard,'owed'),'Back-order client must not show an owed row.');
 $default=owesRender(['view'=>'customers']);
 owesCheck(preg_match('~<button type="button" class="selected" data-customer-insight="all">All</button>~',$default)===1,'All remains the default when no insight is requested.');
 echo "PASS: owes filter counts only non-back-order balances, sorts highest first and shows amount plus oldest unpaid date.\n";
}finally{
 $db=null;
 foreach(glob($temp.'/*')?:[] as $file)unlink($file);
 rmdir($temp);
}
