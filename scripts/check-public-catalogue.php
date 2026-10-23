<?php
declare(strict_types=1);
/** Server-side read-only diagnostic. No customer data or secrets logged. */
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
$configPath=getenv('ANKH_PORTAL_CONFIG')?:'/home/devcouk/ankh-customer-portal-config.php';
if(!is_file($configPath))throw new RuntimeException('Private portal config not found');
$config=require $configPath;
if(!is_array($config)||!is_string($config['admin_database_path']??null)||!is_file($config['admin_database_path']))
 throw new RuntimeException('The customer portal Admin database path is unavailable');
$db=new PDO('sqlite:'.$config['admin_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=5000');
require dirname(__DIR__).'/customer-portal/public-catalogue-core.php';
$active=(int)$db->query('SELECT count(*) FROM products WHERE active=1')->fetchColumn();
$tables=array_flip($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN));
$visible=isset($tables['customer_catalogue_visibility'])?(int)$db->query('SELECT count(*) FROM products p JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1 WHERE p.active=1')->fetchColumn():0;
$published=count(ankhPublicCatalogueProducts($db));
echo "Public catalogue read-only verification: active=$active; existing_published=$visible; publicly_listed=$published\n";
if($published!==$visible)throw new RuntimeException('Public catalogue differs from previously published catalogue visibility');
if($active>0 && $visible===0)echo "NOTICE: Active products exist but none has customer catalogue visibility enabled.\n";
