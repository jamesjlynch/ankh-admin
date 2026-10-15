<?php
declare(strict_types=1);
// Source + isolated integration guard. Never accesses the Krystal database.
$root=dirname(__DIR__);
$source=file_get_contents($root.'/index.php');
function opsAssert(bool $passed,string $message):void{if(!$passed)throw new RuntimeException($message);}
$required=[
 'operator_accounts','activity_events','operators_setup','operator_change_password',
 'validOperatorPassword','recordActivity','activityForOrder',
 'dashboardNextActionLabel','dashboardOneTapActions',
 'operations-next-form','Mark delivered today',
 'data-customer-insight="recurring"','id="ankh-notifications"',
 'if($auditTrack)','if($operatorsEnabled)','PHP_SAPI===\'cli\''
];
foreach($required as $text)opsAssert(str_contains($source,$text),'Operations marker missing: '.$text);
opsAssert(!str_contains($source,'$testingNoAuth=true;'),'Public web auth bypass must not exist.');
opsAssert(!str_contains($source,'<aside class="desktop-today-panel"'),'Unrequested fixed aside must never return.');
opsAssert(substr_count($source,'class="dashboard-stat-card"')===6,'Six original metric cards must remain.');
opsAssert(str_contains($source,'Confirm this order has actually been delivered today?'),'Delivery completion requires user confirmation.');
// An isolated DB validates migrations and rendering without modifying production.
$temp=sys_get_temp_dir().'/ankh-ops-'.bin2hex(random_bytes(6));mkdir($temp,0700);
$config=$temp.'/config.php';
file_put_contents($config,'<?php return '.var_export(['password_hash'=>password_hash('fixture-only',PASSWORD_DEFAULT),'database_path'=>$temp.'/test.sqlite'],true).';');
try{
 $script='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).'); $_GET=["view"=>"dashboard"]; $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; $_SERVER["SCRIPT_NAME"]="/index.php"; require '.var_export($root.'/index.php',true).';';
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($script).' 2>&1',$lines,$code);
 $html=implode("\n",$lines);
 opsAssert($code===0&&str_contains($html,'Your worklist')&&!str_contains($html,'Fatal error'),'Dashboard failed to render in isolated database.');
 $db=new PDO('sqlite:'.$temp.'/test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 foreach(['orders','customers','payments','operator_accounts','activity_events'] as $table){
  $q=$db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);
  opsAssert($q->fetchColumn()===$table,'Missing additive table: '.$table);
 }
 opsAssert((int)$db->query('SELECT COUNT(*) FROM operator_accounts')->fetchColumn()===0,'Operators must be opt-in, not seeded with defaults.');
 // Audit storage is append-only, includes actor and preserves exact order IDs.
 $db->exec("INSERT INTO activity_events(entity_type,entity_id,action,actor_id,actor_name,details_json,created) VALUES ('order',42,'Prepared',NULL,'Legacy admin','{}','2026-10-15T10:00:00Z')");
 $row=$db->query('SELECT entity_id,actor_name,action FROM activity_events')->fetch(PDO::FETCH_ASSOC);
 opsAssert((int)$row['entity_id']===42 && $row['actor_name']==='Legacy admin','Audit row attribution was not persisted.');
 echo "PASS: isolated schema, dashboard rendering, default access gate, named-account and action markers.\n";
}finally{
 $db=null;
 foreach(glob($temp.'/*')?:[] as $file)unlink($file);
 rmdir($temp);
}
