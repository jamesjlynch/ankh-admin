<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$configPath=(string)(getenv('ANKH_ADMIN_CONFIG')?:'/home/devcouk/ankh-admin-config.php');
if(!is_file($configPath)){fwrite(STDERR,"Private config missing.\n");exit(1);}
$config=require $configPath;
$db=new PDO('sqlite:'.$config['database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA busy_timeout=5000');
require dirname(__DIR__).'/business-alerts.php';
try{$result=ankhAlertDispatch($db);echo json_encode($result,JSON_UNESCAPED_SLASHES)."\n";if(!empty($result['errors']))exit(1);}
catch(Throwable $e){fwrite(STDERR,'ANKH alert failed: '.$e->getMessage()."\n");exit(1);}
