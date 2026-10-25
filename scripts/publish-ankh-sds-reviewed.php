<?php
declare(strict_types=1);
/** Review release for exactly ONE existing ANKH product, with private file storage.
 * Never loads/writes a large PDF BLOB into SQLite. Previous PDF BLOB remains in
 * its existing unpublished record for audit and safe recovery.
 */
if(PHP_SAPI!=='cli')exit(1);
[$script,$originals,$finished,$productArg]=array_pad($argv,4,null);
$productId=filter_var($productArg,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$productId||!is_string($originals)||!is_string($finished)||!is_dir($originals)||!is_dir($finished)
  ||is_link($originals)||is_link($finished))throw new RuntimeException('Original/final folders and one product ID required');
$configPath=getenv('ANKH_PORTAL_CONFIG')?:'/home/devcouk/ankh-customer-portal-config.php';
if(!is_file($configPath))throw new RuntimeException('Private config missing');
$config=require $configPath;
if(!is_array($config)||!is_file((string)($config['admin_database_path']??''))||!is_file((string)($config['portal_database_path']??'')))
 throw new RuntimeException('Private ANKH databases unavailable');
require_once dirname(__DIR__).'/customer-portal/product-safety.php';
$manifest=json_decode((string)file_get_contents($originals.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(!is_array($manifest)||($manifest['date']??'')!=='2026-10-25'||count($manifest['items']??[])!==14)throw new RuntimeException('Invalid SDS manifest');
$item=null;
foreach($manifest['items'] as $value)if((int)($value['product_id']??0)===$productId){$item=$value;break;}
if(!is_array($item))throw new RuntimeException('Product absent from authorised SDS manifest');
$name=(string)$item['filename'];
if(!preg_match('/^ANKH-SDS-[A-Za-z0-9-]+\.pdf$/D',$name))throw new RuntimeException('Unsafe SDS name');
$admin=new PDO('sqlite:'.$config['admin_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$admin->exec('PRAGMA query_only=ON');
if(!ankhDocsProductAllowed($admin,$productId,true))throw new RuntimeException('Product is no longer approved for the public catalogue');
$p=$admin->prepare('SELECT name FROM products WHERE id=?');$p->execute([$productId]);
if(strcasecmp(trim((string)$p->fetchColumn()),(string)$item['product_name'])!==0)throw new RuntimeException('Product name mismatch');
$db=new PDO('sqlite:'.$config['portal_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA busy_timeout=8000');
$db->exec('PRAGMA cache_size=-1000');
ankhSdsInit($db);
$oldId=(int)$item['previous_document_id'];
$q=$db->prepare('SELECT id,pdf_name,pdf_sha256,source_url,published,public_draft,reviewed_at FROM portal_product_sds WHERE id=? AND product_id=?');
$q->execute([$oldId,$productId]);$old=$q->fetch(PDO::FETCH_ASSOC);
if(!$old||$old['pdf_name']!==$name||(int)$old['published']!==0
 ||!hash_equals((string)$old['pdf_sha256'],(string)$item['previous_sha256']))
 throw new RuntimeException('Original SDS revision mismatch');
$input=$finished.'/'.$name;
if(!is_file($input)||is_link($input)||filesize($input)<15000||filesize($input)>8*1024*1024)
 throw new RuntimeException('Reviewed PDF missing or too large');
$hash=hash_file('sha256',$input);
if(hash_equals($hash,(string)$old['pdf_sha256']))throw new RuntimeException('PDF has not changed from draft');
$filename=substr($name,0,-4).'-'.substr($hash,0,12).'.pdf';
$root=dirname($config['portal_database_path']).'/approved-sds';
if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new RuntimeException('Cannot create private SDS vault');
chmod($root,0700);
$target=$root.'/'.$filename;
// Atomic private-file write, completed before the DB row points to it.
if(!is_file($target)){
 $temporary=$root.'/.' . $filename.'.tmp.'.getmypid();
 if(!copy($input,$temporary)){throw new RuntimeException('Cannot write reviewed PDF to private vault');}
 chmod($temporary,0600);
 if(!hash_equals($hash,hash_file('sha256',$temporary))){@unlink($temporary);throw new RuntimeException('Copied PDF integrity error');}
 if(!rename($temporary,$target)){@unlink($temporary);throw new RuntimeException('Cannot activate reviewed PDF');}
}
if(!hash_equals($hash,hash_file('sha256',$target)))throw new RuntimeException('Private SDS vault hash mismatch');
$q=$db->prepare('SELECT id,pdf_sha256,published,reviewed_at FROM portal_product_sds WHERE product_id=? ORDER BY id DESC LIMIT 1');
$q->execute([$productId]);$latest=$q->fetch(PDO::FETCH_ASSOC);
if((int)$latest['published']===1&&(int)$latest['reviewed_at']>0 && hash_equals((string)$latest['pdf_sha256'],$hash)){
 echo "ALREADY-REVIEWED product_id=$productId document_id={$latest['id']}\n";exit(0);
}
if((int)$latest['id']!==$oldId||(int)$old['public_draft']!==1)
 throw new RuntimeException('Unexpected SDS release state');
$reviewNote='ANKH catalogue owner confirmed review and approval of these SDSs on 25 October 2026. This revision removes legacy draft markings without changing the technical content. No independent certification or laboratory verification is implied. Original PDF remains archived privately.';
$db->exec('BEGIN IMMEDIATE');
try {
 $archive=$db->prepare('UPDATE portal_product_sds SET public_draft=0,published=0 WHERE id=? AND product_id=? AND public_draft=1 AND published=0');
 $archive->execute([$oldId,$productId]);
 if($archive->rowCount()!==1)throw new RuntimeException('Original SDS not archived');
 $ins=$db->prepare("INSERT INTO portal_product_sds
    (product_id,pdf_name,pdf_sha256,pdf_bytes,source_url,created_at,published,public_draft,reviewed_by,review_note,reviewed_at,approved_pdf_file)
    VALUES (?,?,?,X'',?, ?,1,0,?,?,?,?)");
 $now=time();
 $ins->execute([$productId,$name,$hash,(string)$old['source_url'],$now,
   'ANKH GLOBAL LTD — business owner confirmation',$reviewNote,$now,$filename]);
 $newId=(int)$db->lastInsertId();
 $db->commit();
} catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
echo "REVIEWED-SDS-PUBLISHED product_id=$productId document_id=$newId preserved_original=$oldId\n";
