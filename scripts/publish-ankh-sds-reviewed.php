<?php
declare(strict_types=1);
/**
 * Memory-constrained Krystal safe: finalise ONE owner-reviewed ANKH SDS per run.
 * Transaction boundaries are per-product so a 14-PDF SQLite COMMIT never holds
 * tens of megabytes of BLOBs. Original PDF rows remain private and unchanged.
 * Idempotent for an already-finalised exact PDF; never touches Order Admin.
 */
if(PHP_SAPI!=='cli')exit(1);
[$script,$originals,$finished,$productArg]=array_pad($argv,4,null);
$productId=filter_var($productArg,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$productId||!is_string($originals)||!is_string($finished)||!is_dir($originals)||!is_dir($finished)
 ||is_link($originals)||is_link($finished))throw new RuntimeException('Private original/final directories and one product ID required');
$configPath=getenv('ANKH_PORTAL_CONFIG')?:'/home/devcouk/ankh-customer-portal-config.php';
if(!is_file($configPath))throw new RuntimeException('Private config missing');
$config=require $configPath;
if(!is_array($config)||!is_file((string)($config['admin_database_path']??''))||!is_file((string)($config['portal_database_path']??'')))
 throw new RuntimeException('Private ANKH databases unavailable');
require_once dirname(__DIR__).'/customer-portal/product-safety.php';
$manifest=json_decode((string)file_get_contents($originals.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(!is_array($manifest)||($manifest['date']??'')!=='2026-10-25'||count($manifest['items']??[])!==14)
 throw new RuntimeException('Invalid original PDF manifest');
$item=null;
foreach($manifest['items'] as $candidate){
 if((int)($candidate['product_id']??0)===$productId){$item=$candidate;break;}
}
if(!is_array($item))throw new RuntimeException('Product absent from approved manifest');
$name=(string)$item['filename'];
if(!preg_match('/^ANKH-SDS-[A-Za-z0-9-]+\.pdf$/D',$name))throw new RuntimeException('Invalid filename');
$admin=new PDO('sqlite:'.$config['admin_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$admin->exec('PRAGMA query_only=ON');
if(!ankhDocsProductAllowed($admin,$productId,true))throw new RuntimeException('Product not active/visible');
$q=$admin->prepare('SELECT name FROM products WHERE id=?');
$q->execute([$productId]);
if(strcasecmp(trim((string)$q->fetchColumn()),(string)$item['product_name'])!==0)
 throw new RuntimeException('Product name changed');
$store=new PDO('sqlite:'.$config['portal_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$store->exec('PRAGMA busy_timeout=10000');
$store->exec('PRAGMA cache_size=-1500');
$store->exec('PRAGMA temp_store=FILE');
$oldId=(int)$item['previous_document_id'];
$q=$store->prepare('SELECT id,pdf_name,pdf_sha256,pdf_bytes,source_url,published,public_draft,reviewed_at FROM portal_product_sds WHERE id=? AND product_id=?');
$q->execute([$oldId,$productId]);$old=$q->fetch(PDO::FETCH_ASSOC);
if(!$old||$old['pdf_name']!==$name||(int)$old['published']!==0
 ||!hash_equals((string)$old['pdf_sha256'],(string)$item['previous_sha256']))
 throw new RuntimeException('Original source revision changed');
$sourceBytes=is_resource($old['pdf_bytes'])?stream_get_contents($old['pdf_bytes']):(string)$old['pdf_bytes'];
if(!hash_equals((string)$old['pdf_sha256'],hash('sha256',$sourceBytes)))
 throw new RuntimeException('Original SDS SHA mismatch');
unset($sourceBytes,$old['pdf_bytes']);
$file=$finished.'/'.$name;
if(!is_file($file)||is_link($file)||filesize($file)<15000||filesize($file)>8*1024*1024)
 throw new RuntimeException('Final PDF missing or wrong size');
$hash=hash_file('sha256',$file);
if(hash_equals((string)$old['pdf_sha256'],$hash))throw new RuntimeException('Reviewed PDF has not changed');
$q=$store->prepare('SELECT id,pdf_sha256,published,reviewed_at FROM portal_product_sds WHERE product_id=? ORDER BY id DESC LIMIT 1');
$q->execute([$productId]);$latest=$q->fetch(PDO::FETCH_ASSOC);
if((int)$latest['published']===1 && (int)$latest['reviewed_at']>0 && hash_equals((string)$latest['pdf_sha256'],$hash)){
 echo "ALREADY-REVIEWED product_id=$productId document_id={$latest['id']}\n";
 exit(0);
}
if((int)$latest['id']!==$oldId || (int)$old['public_draft']!==1)
 throw new RuntimeException('Unexpected SDS record version or release state');
$bytes=file_get_contents($file);
if(!is_string($bytes)||!str_starts_with($bytes,'%PDF-')||!str_contains(substr($bytes,-2048),'%%EOF')
 ||!hash_equals(hash('sha256',$bytes),$hash))throw new RuntimeException('Invalid final PDF bytes');
if(class_exists('finfo')&&(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)!=='application/pdf')
 throw new RuntimeException('Invalid final PDF type');
$note='ANKH catalogue owner confirmed review and approval of the published safety sheets on 25 October 2026. This revision removes legacy draft markings only. No independent testing or third-party certification is implied. Original document retained privately.';
$store->exec('BEGIN IMMEDIATE');
try{
 $archive=$store->prepare('UPDATE portal_product_sds SET public_draft=0,published=0 WHERE id=? AND product_id=? AND public_draft=1 AND published=0');
 $archive->execute([$oldId,$productId]);
 if($archive->rowCount()!==1)throw new RuntimeException('Could not archive old public draft');
 $insert=$store->prepare('INSERT INTO portal_product_sds (product_id,pdf_name,pdf_sha256,pdf_bytes,source_url,created_at,published,public_draft,reviewed_by,review_note,reviewed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
 $now=time();
 $insert->bindValue(1,$productId,PDO::PARAM_INT);
 $insert->bindValue(2,$name);
 $insert->bindValue(3,$hash);
 $insert->bindValue(4,$bytes,PDO::PARAM_LOB);
 $insert->bindValue(5,(string)$old['source_url']);
 $insert->bindValue(6,$now,PDO::PARAM_INT);
 $insert->bindValue(7,1,PDO::PARAM_INT);
 $insert->bindValue(8,0,PDO::PARAM_INT);
 $insert->bindValue(9,'ANKH GLOBAL LTD — business owner confirmation');
 $insert->bindValue(10,$note);
 $insert->bindValue(11,$now,PDO::PARAM_INT);
 $insert->execute();
 $newId=(int)$store->lastInsertId();
 $store->commit();
}catch(Throwable $e){if($store->inTransaction())$store->rollBack();throw $e;}
echo "REVIEWED-SDS-PUBLISHED product_id=$productId document_id=$newId original_id=$oldId\n";
