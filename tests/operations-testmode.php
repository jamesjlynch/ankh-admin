<?php
declare(strict_types=1);
// Source checks and isolated SQLite rendering. Never uses real Krystal data.
$root=dirname(__DIR__);
$source=file_get_contents($root.'/index.php');
function checkOperations(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach([
 'CREATE TABLE IF NOT EXISTS activity_events',
 'function recordActivity(', 'function activityForOrder(', 'function activitySummary(',
 'dashboardNextActionLabel','dashboardOneTapActions','operations-next-form',
 'Confirm this order has actually been delivered today?', 
 'data-customer-insight="recurring"','id="ankh-notifications"',
 'if($auditTrack)', "if(\$view==='activity')",
] as $key)checkOperations(str_contains($source,$key),'Required operations feature missing: '.$key);
checkOperations(str_contains($source,'$testingNoAuth=true;'),'Must keep original test-mode access unchanged.');
checkOperations(!str_contains($source,'operator_accounts') && !str_contains($source,'operators_setup'),'Account enrolment must not be included in the test-mode release.');
checkOperations(!str_contains($source,'<aside class="desktop-today-panel"'),'The unwanted sidebar was restored.');
checkOperations(substr_count($source,'class="dashboard-stat-card"')===6,'Dashboard summary design changed.');
$temp=sys_get_temp_dir().'/ankh-testmode-'.bin2hex(random_bytes(6));
mkdir($temp,0700);
$config=$temp.'/config.php';
file_put_contents($config,'<?php return '.var_export(['password_hash'=>password_hash('fixture-only',PASSWORD_DEFAULT),'database_path'=>$temp.'/fixture.sqlite'],true).';');
try{
 $cmd='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).');$_GET=["view"=>"dashboard"];$_SERVER["REQUEST_METHOD"]="GET";$_SERVER["REMOTE_ADDR"]="127.0.0.1";$_SERVER["SCRIPT_NAME"]="/index.php";require '.var_export($root.'/index.php',true).';';
 $out=[];$code=1;
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($cmd).' 2>&1',$out,$code);
 $html=implode("\n",$out);
 checkOperations($code===0&&!str_contains($html,'Fatal error')&&str_contains($html,'Your worklist'),'Dashboard could not render using isolated SQLite fixture: '.substr($html,-800));
 $db=new PDO('sqlite:'.$temp.'/fixture.sqlite');
 foreach(['orders','customers','payments','activity_events'] as $table){
  $q=$db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);
  checkOperations((int)$q->fetchColumn()===1,'Missing base/additive table: '.$table);
 }
 checkOperations((int)$db->query("SELECT COUNT(*) FROM activity_events")->fetchColumn()===0,'Historical actions must not be fabricated.');
 echo "PASS: unchanged test access, source and isolated operations schema/rendering.\n";
}finally{
 $db=null;
 foreach(glob($temp.'/*')?:[] as $file)unlink($file);
 rmdir($temp);
}
