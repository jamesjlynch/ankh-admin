<?php
declare(strict_types=1);
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/ankh-product-report-'.bin2hex(random_bytes(6));mkdir($temp,0700);$config=$temp.'/config.php';
file_put_contents($config,'<?php return '.var_export(['password_hash'=>password_hash('fixture',PASSWORD_DEFAULT),'database_path'=>$temp.'/fixture.sqlite'],true).';');
function productReportCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function productReportRequest(array $get):string{
 global $root,$config;
 $code='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).');$_GET='.var_export($get,true).';$_SERVER["REQUEST_METHOD"]="GET";$_SERVER["REMOTE_ADDR"]="127.0.0.1";$_SERVER["HTTP_HOST"]="localhost";$_SERVER["SCRIPT_NAME"]="/index.php";require '.var_export($root.'/index.php',true).';';
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1',$output,$exit);$html=implode("\n",$output);productReportCheck($exit===0&&!preg_match('/(?:Fatal error|Warning|Notice):/',$html),'Request failed: '.substr($html,-1000));return $html;
}
try{
 productReportRequest(['view'=>'reports']);$db=new PDO('sqlite:'.$temp.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $today=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d');$name='Report Reta 10mg & A+B / %';
 $order=$db->prepare('INSERT INTO orders(id,customer,recipient_name,phone,address,notes,status,created,pricing_type,back_order) VALUES (?,?,?,?,?,?,?,?,?,?)');
 foreach([[99001,'Buyer March','Recipient March','New','2025-03-10','standard',0],[99002,'Buyer waiting','','Awaiting payment','2025-03-31','standard',1],[99003,'Buyer February','','New','2025-02-28','standard',0],[99004,'Buyer different strength','','New','2025-03-20','standard',0],[99005,'Buyer cancelled','','Cancelled','2025-03-15','standard',0],[99006,'Buyer personal','','New','2025-03-16','free',0],[99007,'Buyer this month','','New',$today,'standard',0]] as $r)$order->execute([$r[0],$r[1],$r[2],'','','',$r[3],$r[4].'T12:00:00+00:00',$r[5],$r[6]]);
 $line=$db->prepare('INSERT INTO items(order_id,name,price,quantity,presentation) VALUES (?,?,?,?,?)');foreach([[99001,$name,1000,2,'Pen'],[99001,' '.strtolower($name).' ',800,3,'Vial'],[99001,'Unrelated accessory',999,1,''],[99002,$name,1200,1,'Vial'],[99003,$name,1000,7,''],[99004,'Report Reta 20mg',1000,9,''],[99005,$name,1000,99,''],[99006,$name,1000,50,''],[99007,$name,1000,4,'']] as $r)$line->execute($r);
 $base=['view'=>'reports','type'=>'productorders','product'=>$name,'range'=>'custom','from'=>'2025-03-01','to'=>'2025-03-31','source'=>'products'];$html=productReportRequest($base);
 preg_match_all('/<a class="report-product-order".*?<\/a>/s',$html,$matches);$cards=implode("\n",$matches[0]);
 productReportCheck(substr_count($html,'class="report-product-order"')===2,'One card per matching order, with duplicate product lines merged');
 foreach(['Recipient March','Ordered by Buyer March','Buyer waiting','6 units','£56.00','5 units · £44.00','Back order'] as $text)productReportCheck(str_contains($html,$text),'Missing drill-down detail: '.$text);
 foreach(['Buyer February','Buyer different strength','Buyer cancelled','Buyer personal','Unrelated accessory'] as $text)productReportCheck(!str_contains($cards,$text),'Non-matching rows must not appear in the order list: '.$text);
 productReportCheck(str_contains($html,'name="product" value="Report Reta 10mg &amp; A+B / %"')&&str_contains($html,'name="source" value="products"'),'Date filter preserves exact product and source');
 preg_match('/href="([^"]+)">← Back to products/',$html,$back);parse_str(parse_url(html_entity_decode($back[1]),PHP_URL_QUERY),$backParams);productReportCheck($backParams['from']==='2025-03-01'&&$backParams['to']==='2025-03-31','Back link preserves custom dates');
 $rank=productReportRequest(array_replace($base,['type'=>'products']));preg_match('/data-report-product-url="([^"]+)"/',$rank,$match);parse_str(parse_url(html_entity_decode($match[1]),PHP_URL_QUERY),$link);productReportCheck($link['type']==='productorders'&&$link['range']==='custom'&&$link['from']==='2025-03-01'&&$link['to']==='2025-03-31','Whole table row opens the same date window');
 productReportCheck(str_contains($rank,'report-product-link'),'Product row exposes a native keyboard-accessible link');
 $overview=productReportRequest(array_replace($base,['type'=>'overview']));productReportCheck(str_contains($overview,'report-product-summary')&&str_contains($overview,'source=overview'),'Overview top products also link to matching orders');
 $all=productReportRequest(array_replace($base,['range'=>'all']));productReportCheck(substr_count($all,'class="report-product-order"')===4&&str_contains($all,'17 units'),'All-time drill-down includes all matching non-cancelled, non-personal orders');
 $month=productReportRequest(array_replace($base,['range'=>'month']));productReportCheck(substr_count($month,'class="report-product-order"')===1&&str_contains($month,'Buyer this month'),'This month uses the report date boundaries');
 $csv=productReportRequest($base+['export'=>'1']);productReportCheck(str_contains($csv,'Units of product')&&str_contains($csv,'Recipient March')&&str_contains($csv,'44.00')&&!str_contains($csv,'Buyer February'),'CSV exports only the selected product and dates');
 $empty=productReportRequest(array_replace($base,['product'=>'No matching product <script>']));productReportCheck(str_contains($empty,'No matching orders')&&!str_contains($empty,'No matching product <script>'),'Missing or unsafe product names render a safe empty state');
 echo "PASS: clickable product links, date preservation, exact strength/name matching, one card per order, quantities/values, recipients, back orders, exclusions, all-time/month ranges, CSV and escaped empty states.\n";
}finally{$db=null;foreach(glob($temp.'/*')?:[] as $file)unlink($file);rmdir($temp);}
