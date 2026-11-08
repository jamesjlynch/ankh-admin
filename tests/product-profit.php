<?php
declare(strict_types=1);
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/ankh-product-profit-'.bin2hex(random_bytes(6));mkdir($temp,0700);$config=$temp.'/config.php';
file_put_contents($config,'<?php $_SESSION["admin"]=true;$_SESSION["admin_user_id"]=1;$_SESSION["last"]=time(); return '.var_export(['password_hash'=>password_hash('fixture',PASSWORD_DEFAULT),'database_path'=>$temp.'/fixture.sqlite'],true).';');
function productProfitCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function productProfitRequest(array $get):string{
 global $root,$config;
 $code='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).');$_GET='.var_export($get,true).';$_SERVER["REQUEST_METHOD"]="GET";$_SERVER["REMOTE_ADDR"]="127.0.0.1";$_SERVER["HTTP_HOST"]="localhost";$_SERVER["SCRIPT_NAME"]="/index.php";require '.var_export($root.'/index.php',true).';';
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1',$output,$exit);$html=implode("\n",$output);productProfitCheck($exit===0&&!preg_match('/(?:Fatal error|Warning|Notice):/',$html),'Request failed: '.substr($html,-1000));return $html;
}
function productProfitSection(string $html):string{preg_match('/<section class="panel product-profit-panel".*?<\/section>/s',$html,$match);productProfitCheck(isset($match[0]),'Monthly product profit section is missing');return $match[0];}
function productProfitRow(string $section,string $name):string{preg_match('/<tr class="product-profit-row[^"]*"[^>]*data-product="'.preg_quote($name,'/').'".*?<\/tr>/s',$section,$match);productProfitCheck(isset($match[0]),'Product row is missing: '.$name);return $match[0];}
try{
 productProfitRequest(['view'=>'reports']);$db=new PDO('sqlite:'.$temp.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec("INSERT INTO admin_users(id,username,password_hash,active,created_at) VALUES(1,'fixture',".$db->quote(password_hash('fixture',PASSWORD_DEFAULT)).',1,'.time().')');
 $order=$db->prepare('INSERT INTO orders(id,customer,recipient_name,phone,address,notes,status,created,pricing_type,back_order,payment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
 foreach([
  [99001,'March delivered','Recipient March','','','','Delivered','2025-03-05T12:00:00Z','standard',0,''],
  [99002,'March paid','','','','','Paid','2025-03-20T12:00:00Z','standard',0,'2025-03-20'],
  [99003,'April packed','','','','','Packed','2025-04-02T12:00:00Z','standard',0,''],
  [99004,'March back order','','','','','Paid','2025-03-10T12:00:00Z','standard',1,''],
  [99005,'March cancelled','','','','','Cancelled','2025-03-11T12:00:00Z','standard',0,''],
  [99006,'March free','','','','','Paid','2025-03-12T12:00:00Z','free',0,''],
  [99007,'March unpaid','','','','','New','2025-03-15T12:00:00Z','standard',0,''],
  [99008,'March paid April','','','','','Paid','2025-03-30T12:00:00Z','standard',0,'2025-04-02'],
  [99009,'March mixed cost','','','','','Paid','2025-03-21T12:00:00Z','standard',0,''],
 ] as $r)$order->execute($r);
 $line=$db->prepare('INSERT INTO items(order_id,name,price,quantity,cost,presentation_cost) VALUES (?,?,?,?,?,?)');
 foreach([
  [99001,'Alpha 10mg',1000,2,400,100],
  [99002,'Alpha 10mg',1000,3,400,100],
  [99002,'Beta 5mg',2000,1,null,0],
  [99009,'Mixed Cost',500,1,100,0],
  [99009,'Mixed Cost',500,1,null,0],
  [99003,'Alpha 10mg',1000,1,400,0],
  [99003,'Gamma 20mg',500,4,200,0],
  [99004,'Backorder Only',1000,9,400,0],
  [99005,'Cancelled Only',1000,1,400,0],
  [99006,'Free Only',1000,1,400,0],
  [99007,'Unpaid Only',1000,1,400,0],
  [99008,'Deferred',500,1,200,0],
 ] as $r)$line->execute($r);
 $march=productProfitRequest(['view'=>'reports','month'=>'2025-03']);$marchSection=productProfitSection($march);
 productProfitCheck(str_contains($marchSection,'March 2025'),'Month label is missing');
 productProfitCheck(substr_count($marchSection,'data-product-profit-row')===3,'March should list only completed product sales');
 productProfitCheck(substr_count($marchSection,'data-product-profit-total')===1,'Totals row is missing');
 $alpha=productProfitRow($marchSection,'Alpha 10mg');
 foreach(['£50.00','£25.00','50.0%','>5<'] as $text)productProfitCheck(str_contains($alpha,$text),'Alpha March sums are wrong: '.$text);
 $beta=productProfitRow($marchSection,'Beta 5mg');
 productProfitCheck(str_contains($beta,'is-missing')&&str_contains($beta,'cost missing')&&str_contains($beta,'—'),'Missing-cost product must be flagged and excluded from margin');
 productProfitCheck(str_contains($beta,'£20.00'),'Missing-cost revenue still counts');
 $mixed=productProfitRow($marchSection,'Mixed Cost');
 productProfitCheck(str_contains($mixed,'is-missing')&&str_contains($mixed,'£1.00')&&str_contains($mixed,'£9.00')&&str_contains($mixed,'—'),'Mixed known/missing costs flag the row and keep known cost only');
 $marchTotals=preg_match('/<tr class="product-profit-total".*?<\/tr>/s',$marchSection,$totalMatch)?$totalMatch[0]:'';
 foreach(['>8<','£80.00','£26.00','£54.00','50.0%'] as $text)productProfitCheck(str_contains($marchTotals,$text),'March totals are wrong: '.$text);
 foreach(['Backorder Only','Cancelled Only','Free Only','Unpaid Only','April packed','Deferred'] as $text)productProfitCheck(!str_contains($marchSection,$text),'March section must exclude: '.$text);
 productProfitCheck(str_contains($marchSection,'2 products cost missing'),'Missing-cost count is wrong');
 $april=productProfitRequest(['view'=>'reports','month'=>'2025-04']);$aprilSection=productProfitSection($april);
 productProfitCheck(substr_count($aprilSection,'data-product-profit-row')===3,'April should list its own completed product sales');
 $aprilAlpha=productProfitRow($aprilSection,'Alpha 10mg');productProfitCheck(str_contains($aprilAlpha,'£10.00')&&str_contains($aprilAlpha,'£4.00')&&str_contains($aprilAlpha,'£6.00'),'April Alpha sums are wrong');
 $gamma=productProfitRow($aprilSection,'Gamma 20mg');productProfitCheck(str_contains($gamma,'£20.00')&&str_contains($gamma,'£8.00')&&str_contains($gamma,'£12.00'),'April Gamma sums are wrong');
 $deferred=productProfitRow($aprilSection,'Deferred');productProfitCheck(str_contains($deferred,'£5.00')&&str_contains($deferred,'£3.00'),'Payment date must decide the month');
 productProfitCheck(!str_contains($aprilSection,'Beta 5mg')&&!str_contains($aprilSection,'Mixed Cost'),'March products must not leak into April');
 productProfitCheck(str_contains($march,'month=2025-02')&&str_contains($march,'month=2025-04'),'Previous/next month links are missing');
 $current=(new DateTimeImmutable('now',new DateTimeZone('Europe/London')))->format('Y-m');$currentLabel=(new DateTimeImmutable('now',new DateTimeZone('Europe/London')))->format('F Y');
 $default=productProfitRequest(['view'=>'reports']);$defaultSection=productProfitSection($default);
 productProfitCheck(str_contains($defaultSection,'value="'.$current.'"')&&str_contains($defaultSection,'No completed product sales in '.$currentLabel),'Default month must be the current month');
 $invalid=productProfitRequest(['view'=>'reports','month'=>'2025-13']);productProfitCheck(str_contains(productProfitSection($invalid),'value="'.$current.'"'),'Invalid month must fall back safely');
 echo "PASS: monthly product profit sums, totals, payment-date filtering, back-order/cancelled/free exclusions, missing and mixed costs, margin exclusion and month navigation.\n";
}finally{$db=null;foreach(glob($temp.'/*')?:[] as $file)unlink($file);rmdir($temp);}
