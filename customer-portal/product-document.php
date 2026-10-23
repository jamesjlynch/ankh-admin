<?php
declare(strict_types=1);
require_once __DIR__.'/product-certificates.php';
$cfg=getenv('ANKH_PORTAL_CONFIG')?:dirname(__DIR__,2).'/ankh-customer-portal-config.php';
if(!is_file($cfg)){http_response_code(503);exit;}
$config=require $cfg;
if(!is_array($config)){http_response_code(503);exit;}
$local=PHP_SAPI==='cli-server' && getenv('ANKH_TEST_ALLOW_HTTP')==='1'
    && in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true);
if(!$local&&($_SERVER['HTTPS']??'')!=='on'){http_response_code(403);exit;}
if(!$local&&strtolower((string)($_SERVER['HTTP_HOST']??''))!==strtolower((string)($config['host']??'order.ankhpeptides.com'))){http_response_code(404);exit;}
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$id){http_response_code(404);exit;}
try{
    $admin=new PDO('sqlite:'.$config['admin_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $store=new PDO('sqlite:'.$config['portal_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $admin->exec('PRAGMA query_only=ON');$store->exec('PRAGMA query_only=ON');
    if(!$store->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_product_documents'")->fetchColumn()){
       http_response_code(404);exit;
    }
    $q=$store->prepare("SELECT * FROM portal_product_documents WHERE id=? AND published=1 AND superseded=0");
    $q->execute([$id]);$doc=$q->fetch(PDO::FETCH_ASSOC);
    if(!$doc || !ankhDocsProductAllowed($admin,(int)$doc['product_id'],true)){http_response_code(404);exit;}
    $pdf=is_resource($doc['pdf_bytes'])?stream_get_contents($doc['pdf_bytes']):(string)$doc['pdf_bytes'];
    if(!hash_equals((string)$doc['pdf_sha256'],hash('sha256',$pdf)))throw new RuntimeException('Document integrity failure');
    header('Content-Type: application/pdf');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex,nofollow');
    header('Content-Disposition: inline; filename="ankh-product-document.pdf"');
    echo $pdf;
}catch(Throwable $e){error_log('ANKH product documentation: '.$e->getMessage());http_response_code(503);exit;}
