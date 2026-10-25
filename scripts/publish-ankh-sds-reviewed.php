<?php
declare(strict_types=1);
/** One-time, atomic release of 14 ANKH owner-reviewed PDFs from the private directory.
 * Originals are kept as unpublished, unchanged records for traceability.
 * Never changes product catalog, prices, stock, orders or batch COAs.
 */
if(PHP_SAPI!=='cli')exit(1);
$originals=$argv[1]??null;$finished=$argv[2]??null;
if(!is_string($originals)||!is_string($finished)||!is_dir($originals)||!is_dir($finished)
  ||is_link($originals)||is_link($finished))throw new RuntimeException('Two private working directories are required');
$configPath=getenv('ANKH_PORTAL_CONFIG')?:'/home/devcouk/ankh-customer-portal-config.php';
if(!is_file($configPath))throw new RuntimeException('Portal config missing');
$config=require $configPath;
if(!is_array($config)||!is_file((string)($config['admin_database_path']??''))||!is_file((string)($config['portal_database_path']??'')))
 throw new RuntimeException('Private databases unavailable');
require_once dirname(__DIR__).'/customer-portal/product-safety.php';
$admin=new PDO('sqlite:'.$config['admin_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$admin->exec('PRAGMA query_only=ON');
$store=new PDO('sqlite:'.$config['portal_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$store->exec('PRAGMA busy_timeout=10000');
$manifest=json_decode((string)file_get_contents($originals.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(!is_array($manifest)||($manifest['date']??'')!=='2026-10-25'||!is_array($manifest['items']??null)||count($manifest['items'])!==14)
 throw new RuntimeException('Unexpected manifest contents');
$ids=[];$ready=[];
$q=$store->prepare('SELECT id,pdf_name,pdf_sha256,pdf_bytes,source_url,published,public_draft,reviewed_at FROM portal_product_sds WHERE product_id=? ORDER BY id DESC LIMIT 1');
$p=$admin->prepare('SELECT name,active FROM products WHERE id=?');
foreach($manifest['items'] as $item){
 $productId=(int)($item['product_id']??0);
 if($productId<1||isset($ids[$productId]))throw new RuntimeException('Invalid or repeated SDS product');
 $ids[$productId]=true;
 if(!ankhDocsProductAllowed($admin,$productId,true))throw new RuntimeException("Product no longer approved: $productId");
 $p->execute([$productId]);$prod=$p->fetch(PDO::FETCH_ASSOC);
 if(!$prod||strcasecmp(trim((string)$prod['name']),(string)($item['product_name']??''))!==0)
  throw new RuntimeException("Product name changed: $productId");
 $name=(string)($item['filename']??'');
 if(!preg_match('/^ANKH-SDS-[A-Za-z0-9-]+\.pdf$/D',$name))throw new RuntimeException("Invalid PDF name: $name");
 $q->execute([$productId]);$old=$q->fetch(PDO::FETCH_ASSOC);
 if(!$old||(int)$old['id']!==(int)($item['previous_document_id']??0)
   ||$old['pdf_name']!==$name||(int)$old['public_draft']!==1
   ||(int)$old['published']!==0||(int)$old['reviewed_at']!==0
   ||!hash_equals((string)$old['pdf_sha256'],(string)($item['previous_sha256']??'')))
    throw new RuntimeException("SDS revision changed while preparing: $productId");
 $oldBytes=is_resource($old['pdf_bytes'])?stream_get_contents($old['pdf_bytes']):(string)$old['pdf_bytes'];
 if(!hash_equals(hash('sha256',$oldBytes),(string)$old['pdf_sha256']))throw new RuntimeException("Original hash mismatch: $productId");
 $file=$finished.'/'.$name;
 if(!is_file($file)||is_link($file))throw new RuntimeException("Final PDF missing: $name");
 $bytes=file_get_contents($file);
 if(!is_string($bytes)||strlen($bytes)<15000||strlen($bytes)>8*1024*1024||!str_starts_with($bytes,'%PDF-')
   ||!str_contains(substr($bytes,-2048),'%%EOF')||hash_equals(hash('sha256',$bytes),hash('sha256',$oldBytes)))
  throw new RuntimeException("Final PDF failed signature, size, or revision checks: $name");
 if(class_exists('finfo')&&(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)!=='application/pdf')
  throw new RuntimeException("Final PDF has invalid MIME type: $name");
 $ready[]=['product_id'=>$productId,'old_id'=>(int)$old['id'],'name'=>$name,'bytes'=>$bytes,'source'=>(string)$old['source_url'],'hash'=>hash('sha256',$bytes)];
}
if(count($ready)!==14||count($ids)!==14)throw new RuntimeException('Not exactly 14');
$reviewer='ANKH GLOBAL LTD — business owner confirmation';
$note='Catalogue owner confirmed review and approval of all fourteen published safety sheets on 25 October 2026. This replaces draft markings on ANKH-rebranded documents. No independent testing or external certification is implied. Original source PDF retained as an unpublished revision.';
$store->exec('BEGIN IMMEDIATE');
try{
 $archive=$store->prepare('UPDATE portal_product_sds SET public_draft=0,published=0 WHERE id=? AND product_id=? AND public_draft=1 AND published=0');
 $insert=$store->prepare('INSERT INTO portal_product_sds (product_id,pdf_name,pdf_sha256,pdf_bytes,source_url,created_at,published,public_draft,reviewed_by,review_note,reviewed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
 $now=time();
 foreach($ready as $doc){
   $archive->execute([$doc['old_id'],$doc['product_id']]);
   if($archive->rowCount()!==1)throw new RuntimeException('Could not archive original '.$doc['name']);
   $insert->bindValue(1,$doc['product_id'],PDO::PARAM_INT);
   $insert->bindValue(2,$doc['name']);
   $insert->bindValue(3,$doc['hash']);
   $insert->bindValue(4,$doc['bytes'],PDO::PARAM_LOB);
   $insert->bindValue(5,$doc['source'],PDO::PARAM_STR);
   $insert->bindValue(6,$now,PDO::PARAM_INT);
   $insert->bindValue(7,1,PDO::PARAM_INT);
   $insert->bindValue(8,0,PDO::PARAM_INT);
   $insert->bindValue(9,$reviewer);
   $insert->bindValue(10,$note);
   $insert->bindValue(11,$now,PDO::PARAM_INT);
   $insert->execute();
   $docId=(int)$store->lastInsertId();
   echo "REVIEWED-SDS-STAGED product_id={$doc['product_id']} new_document_id=$docId original_document_id={$doc['old_id']}\n";
 }
 $store->commit();
}catch(Throwable $e){if($store->inTransaction())$store->rollBack();throw $e;}
echo "SUCCESS: 14 owner-reviewed ANKH SDS PDFs published in one transaction. All prior draft PDFs archived privately. Other database data unchanged.\n";
