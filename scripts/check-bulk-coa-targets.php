<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$config=getenv('ANKH_PORTAL_CONFIG') ?: '/home/devcouk/ankh-customer-portal-config.php';
if(!is_file($config)){fwrite(STDERR,"Private config unavailable\n");exit(2);}
$c=require $config;
if(!is_array($c)||!is_file((string)($c['admin_database_path']??''))){fwrite(STDERR,"Admin database unavailable\n");exit(2);}
require_once dirname(__DIR__).'/customer-portal/product-coa-bulk.php';
$admin=new PDO('sqlite:'.$c['admin_database_path'],null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$admin->exec('PRAGMA query_only=ON');
if(!class_exists('ZipArchive')){fwrite(STDERR,"Missing ZipArchive extension\n");exit(2);}
$plan=ankhBulkCoaPlan($admin,true);
$matched=0;$public=0;
foreach($plan as $entry){
  if(!isset($entry['id'])){echo "UNMATCHED ".$entry['label']." : ".($entry['error']??'unknown')."\n";continue;}
  ++$matched;if($entry['published'])++$public;
  echo ($entry['published']?'PUBLIC ':'PRIVATE ').$entry['label'].' => product #'.$entry['id']."\n";
}
echo "PREVIEW: matched ".$matched." of ".count($plan)." report names; publicly listed ".$public."; no data written\n";
if($matched!==count($plan))exit(3);
