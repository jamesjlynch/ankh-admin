<?php
declare(strict_types=1);
// Exercise the actual entrypoint against an isolated database, never production data.
$root=dirname(__DIR__);
$temp=sys_get_temp_dir().'/ankh-notifications-'.bin2hex(random_bytes(6));
mkdir($temp,0700);
$config=$temp.'/config.php';
file_put_contents($config,'<?php return '.var_export(['password_hash'=>password_hash('test-only',PASSWORD_DEFAULT),'database_path'=>$temp.'/test.sqlite'],true).';');
function renderPage(string $view,array $extra=[]):string{
 global $root,$config;
 $code='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).'); $_GET='.var_export(['view'=>$view]+$extra,true).'; $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; $_SERVER["HTTP_HOST"]="localhost"; $_SERVER["SCRIPT_NAME"]="/index.php"; require '.var_export($root.'/index.php',true).';';
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1',$lines,$exit);
 $html=implode("\n",$lines);
 if($exit!==0||preg_match('/(?:Fatal error|Warning|Notice):/',$html)||!str_contains($html,'</html>'))throw new RuntimeException($view.' did not render completely: '.substr($html,-1800));
 return $html;
}
try{
 $html=renderPage('dashboard');
 if(!str_contains($html,'Your worklist')||substr_count($html,'class="dashboard-stat-card"')!==6)throw new RuntimeException('Original dashboard cards and worklist must survive.');
 $db=new PDO('sqlite:'.$temp.'/test.sqlite');
 $db->exec("INSERT INTO customers(name,phone,address,created) VALUES('UX Test','01234','','2026-01-01')");
 $html=renderPage('customers');
 foreach(['data-customer-insight="top"','data-customer-insight="inactive"','data-customer-insight="outstanding"','data-customer-insight="recurring"','UX Test'] as $marker)if(!str_contains($html,$marker))throw new RuntimeException('Missing customer filter: '.$marker);
 $html=renderPage('customer',['id'=>'1']);
 foreach(['Lifetime spend','Gross profit','Outstanding','Last order','Average repeat gap','Most purchased','Next likely order'] as $marker)if(!str_contains($html,$marker))throw new RuntimeException('Missing customer metric: '.$marker);
 $source=file_get_contents($root.'/index.php');
 $start=strpos($source,'$deliveredUnpaid=array_values(');$end=strpos($source,"\n",$start);
 $awaitingPayment=[['status'=>'Delivered','delivery_date'=>''],['status'=>'New','delivery_date'=>'2026-10-14'],['status'=>'New','delivery_date'=>'','scheduled_delivery_date'=>'2026-01-01']];
 eval(substr($source,$start,$end-$start));
 if(count($deliveredUnpaid)!==2)throw new RuntimeException('Delivered balance summary must exclude undelivered overdue orders.');
 echo "PASS: dashboard renders through worklist; all customer filters/metrics render; delivered balance calculation remains independent.\n";
}finally{
 $db=null;
 foreach(glob($temp.'/*') as $file)unlink($file);
 rmdir($temp);
}
