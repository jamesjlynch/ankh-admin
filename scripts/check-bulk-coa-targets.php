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
foreach(['cjc','igf'] as $term){
  $q=$admin->prepare("SELECT p.id,p.name,p.active,COALESCE(v.visible,0) visible FROM products p
    LEFT JOIN customer_catalogue_visibility v ON v.product_id=p.id
    WHERE lower(p.name) LIKE ? LIMIT 12");
  $q->execute(['%'.$term.'%']);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $item)
    echo "NAME CHECK ".$term." product #".$item['id'].": ".(string)$item['name']
      ." (active=".$item['active'].",visible=".$item['visible'].")\n";
}
if(is_file((string)($c['portal_database_path']??''))){
  $portal=new PDO('sqlite:'.$c['portal_database_path']);
  $portal->exec('PRAGMA query_only=ON');
  $exists=$portal->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_product_documents'")->fetchColumn();
  $amount=$exists?(int)$portal->query('SELECT COUNT(*) FROM portal_product_documents')->fetchColumn():0;
  $published=$exists?(int)$portal->query('SELECT COUNT(*) FROM portal_product_documents WHERE published=1 AND superseded=0')->fetchColumn():0;
  echo "LIVE PORTAL DOCUMENTS: total=".$amount."; public=".$published."\n";
}
echo "PREVIEW: matched ".$matched." of ".count($plan)." report names; publicly listed ".$public."; no data written\n";
if($matched!==count($plan))exit(3);
