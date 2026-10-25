<?php
declare(strict_types=1);
/** Export the fourteen specifically identified PUBLIC SDS working copies for review finalisation.
 *  CLI only, private directory supplied by deployment, no changes to any database.
 */
if(PHP_SAPI!=='cli')exit(1);
$dir=$argv[1]??'';
if(!is_string($dir)||!is_dir($dir)||is_link($dir))throw new RuntimeException('Private output folder required');
$cfg=getenv('ANKH_PORTAL_CONFIG')?:'/home/devcouk/ankh-customer-portal-config.php';
if(!is_file($cfg))throw new RuntimeException('Private ANKH config missing');
$config=require $cfg;
require_once dirname(__DIR__).'/customer-portal/product-safety.php';
$admin=new PDO('sqlite:'.$config['admin_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$admin->exec('PRAGMA query_only=ON');
$db=new PDO('sqlite:'.$config['portal_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA query_only=ON');
$targets=[
 101=>['BPC-157 5mg','ANKH-SDS-BPC-157-5mg.pdf'],
 97=>['DSIP 5mg','ANKH-SDS-DSIP-5mg.pdf'],
 108=>['Epitalon 10mg','ANKH-SDS-Epitalon-10mg.pdf'],
 110=>['GHK-CU 50mg','ANKH-SDS-GHK-CU-50mg.pdf'],
 104=>['Ipamorelin 5mg','ANKH-SDS-Ipamorelin-5mg.pdf'],
 75=>['KissPeptin-10 5mg','ANKH-SDS-KissPeptin-10-5mg.pdf'],
 72=>['KPV 10mg','ANKH-SDS-KPV-10mg.pdf'],
 78=>['MOTS-C 10mg','ANKH-SDS-MOTS-C-10mg.pdf'],
 55=>['NAD+ 500mg','ANKH-SDS-NAD-500mg.pdf'],
 94=>['Selank 5mg','ANKH-SDS-Selank-5mg.pdf'],
 95=>['Semax 5mg','ANKH-SDS-Semax-5mg.pdf'],
 53=>['SS-31 10mg','ANKH-SDS-SS-31-10mg.pdf'],
 96=>['TB-500 2mg','ANKH-SDS-TB-500-2mg.pdf'],
 60=>['TB-500 5mg','ANKH-SDS-TB-500-5mg.pdf'],
];
if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_product_sds'")->fetchColumn())
 throw new RuntimeException('SDS table not found');
$q=$db->prepare('SELECT id,pdf_name,pdf_sha256,pdf_bytes,published,reviewed_at,public_draft FROM portal_product_sds WHERE product_id=? ORDER BY id ASC LIMIT 1');
$check=$admin->prepare('SELECT name,active FROM products WHERE id=?');
$manifest=['date'=>'2026-10-25','purpose'=>'ANKH SDS owner-reviewed publication, preserve originals','items'=>[]];
foreach($targets as $productId=>[$name,$file]){
 if(!ankhDocsProductAllowed($admin,$productId,true))throw new RuntimeException("Product not published/active: $productId");
 $check->execute([$productId]);$p=$check->fetch();
 if(!$p||strcasecmp(trim((string)$p['name']),$name)!==0)throw new RuntimeException("Incorrect product name: $productId");
 $q->execute([$productId]);$row=$q->fetch();
 if(!$row||$row['pdf_name']!==$file||!in_array((int)$row['public_draft'],[0,1],true)||(int)$row['published']!==0||(int)$row['reviewed_at']!==0)
   throw new RuntimeException("Expected specific public draft not found: $name");
 $bytes=is_resource($row['pdf_bytes'])?stream_get_contents($row['pdf_bytes']):(string)$row['pdf_bytes'];
 if(!str_starts_with($bytes,'%PDF-')||!hash_equals((string)$row['pdf_sha256'],hash('sha256',$bytes)))
   throw new RuntimeException("Original SDS integrity failure: $name");
 if(file_put_contents($dir.'/'.$file,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Could not export '.$file);
 chmod($dir.'/'.$file,0600);
 $manifest['items'][]=['product_id'=>$productId,'product_name'=>$name,'filename'=>$file,'previous_document_id'=>(int)$row['id'],'previous_sha256'=>hash('sha256',$bytes),'byte_count'=>strlen($bytes)];
 echo "EXPORTED SDS product_id=$productId document_id={$row['id']}\n";
}
if(count($manifest['items'])!==14)throw new RuntimeException('Not exactly 14');
file_put_contents($dir.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
chmod($dir.'/manifest.json',0600);
echo "SUCCESS: 14 original PDFs exported privately; database unchanged.\n";
