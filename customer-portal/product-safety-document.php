<?php
declare(strict_types=1);
/** Customer-facing approved SDS or clearly identified unverified draft PDF, product-visible only. */
require_once __DIR__.'/product-safety.php';
$cfg=getenv('ANKH_PORTAL_CONFIG')?:dirname(__DIR__,2).'/ankh-customer-portal-config.php';
if(!is_file($cfg)){http_response_code(503);exit;}
$config=require $cfg;
if(!is_array($config)){http_response_code(503);exit;}
$local=PHP_SAPI==='cli-server'&&getenv('ANKH_TEST_ALLOW_HTTP')==='1'
    &&in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true);
if(!$local&&($_SERVER['HTTPS']??'')!=='on'){http_response_code(403);exit;}
if(!$local&&strtolower((string)($_SERVER['HTTP_HOST']??''))!==strtolower((string)($config['host']??'order.ankhpeptides.com'))){http_response_code(404);exit;}
$staffRequested=($_GET['staff']??'')==='1';
$staffAllowed=false;
if($staffRequested){
    ini_set('session.use_strict_mode','1');
    session_name('ankh_staff_preview');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!$local,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    $fingerprint=hash('sha256',(string)($config['preview_password_hash']??''));
    $staffAllowed=!empty($_SESSION['staff_preview_authorised'])
        &&hash_equals($fingerprint,(string)($_SESSION['staff_preview_auth_version']??''))
        &&(int)($_SESSION['staff_preview_last']??0)>time()-1200;
}
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$id){http_response_code(404);exit;}
try{
    $admin=new PDO('sqlite:'.$config['admin_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $store=new PDO('sqlite:'.$config['portal_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $admin->exec('PRAGMA query_only=ON');$store->exec('PRAGMA query_only=ON');
    if(!$store->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_product_sds'")->fetchColumn()){http_response_code(404);exit;}
    $previewColumn=ankhSdsHasPublicDraftColumn($store)?'public_draft':'0 AS public_draft';
    $q=$store->prepare("SELECT product_id,pdf_sha256,pdf_bytes,published,reviewed_at,$previewColumn FROM portal_product_sds WHERE id=?");
    $q->execute([$id]);$doc=$q->fetch(PDO::FETCH_ASSOC);
    $isApproved=$doc&&(int)$doc['published']===1&&(int)$doc['reviewed_at']>0;
    $isUnverifiedPublicDraft=$doc&&(int)$doc['public_draft']===1;
    if(!$doc||!ankhDocsProductAllowed($admin,(int)$doc['product_id'],true)||(!$staffAllowed&&!$isApproved&&!$isUnverifiedPublicDraft)){http_response_code(404);exit;}
    $pdf=is_resource($doc['pdf_bytes'])?stream_get_contents($doc['pdf_bytes']):(string)$doc['pdf_bytes'];
    if(!hash_equals((string)$doc['pdf_sha256'],hash('sha256',$pdf)))throw new RuntimeException('Document integrity failure');
    header('Content-Type: application/pdf');
    header('X-ANKH-Document-Status: '.($isApproved?'reviewed-sds':'unverified-draft-not-approved-sds'));
    header('Content-Length: '.strlen($pdf));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex,nofollow');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Disposition: inline; filename="'.($isApproved?'ankh-safety-data-sheet.pdf':'ankh-unverified-sds-draft.pdf').'"');
    echo $pdf;
}catch(Throwable $e){error_log('ANKH SDS download: '.$e->getMessage());http_response_code(503);exit;}
