<?php
declare(strict_types=1);
/**
 * Publish the exact fourteen previously imported ANKH working SDS references.
 * Public status is "unverified draft", not a completed technical review.
 * A single transaction, strict product/name/PDF integrity matching and no other
 * database changes. Re-runs are idempotent.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$configPath=getenv('ANKH_PORTAL_CONFIG')?:'/home/devcouk/ankh-customer-portal-config.php';
if(!is_file($configPath))throw new RuntimeException('Private portal config missing.');
$config=require $configPath;
if(!is_array($config)||!is_file((string)($config['admin_database_path']??''))||!is_file((string)($config['portal_database_path']??'')))
    throw new RuntimeException('Required private databases unavailable.');
require_once dirname(__DIR__).'/customer-portal/product-safety.php';
$admin=new PDO('sqlite:'.$config['admin_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$admin->exec('PRAGMA query_only=ON');
$store=new PDO('sqlite:'.$config['portal_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$store->exec('PRAGMA busy_timeout=8000');
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
$prod=$admin->prepare('SELECT p.name,p.active,v.visible FROM products p JOIN customer_catalogue_visibility v ON v.product_id=p.id WHERE p.id=?');
$check=$store->prepare('SELECT id,product_id,pdf_name,pdf_sha256,pdf_bytes,published,reviewed_at FROM portal_product_sds WHERE product_id=? ORDER BY id DESC LIMIT 1');
if(!$store->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_product_sds'")->fetchColumn())
    throw new RuntimeException('Imported SDS table missing. Nothing was published.');
$validated=[];
foreach($targets as $productId=>[$name,$expectedPdf]) {
    $prod->execute([$productId]);$p=$prod->fetch(PDO::FETCH_ASSOC);
    if(!$p||(int)$p['active']!==1||(int)$p['visible']!==1||strcasecmp((string)$p['name'],$name)!==0)
        throw new RuntimeException('Product no longer an exact active visible match: '.$productId);
    $check->execute([$productId]);$doc=$check->fetch(PDO::FETCH_ASSOC);
    if(!$doc||$doc['pdf_name']!==$expectedPdf)
        throw new RuntimeException('Original SDS import missing or mismatched for '.$name);
    $pdf=is_resource($doc['pdf_bytes'])?stream_get_contents($doc['pdf_bytes']):(string)$doc['pdf_bytes'];
    if(strlen($pdf)<200||!str_starts_with($pdf,'%PDF-')||!str_contains(substr($pdf,-2048),'%%EOF')
        ||!hash_equals((string)$doc['pdf_sha256'],hash('sha256',$pdf)))
        throw new RuntimeException('Invalid or corrupt SDS bytes: '.$name);
    if((int)$doc['reviewed_at']>0 || (int)$doc['published']===1)
        throw new RuntimeException('Expected a privately stored unreviewed draft, not an approved document: '.$name);
    $validated[$productId]=[(int)$doc['id'],$name];
}
if(count($validated)!==14)throw new RuntimeException('Incorrect number of SDS drafts.');
// Migration is additive, independent of Order Admin stock/pricing records.
ankhSdsInit($store);
$store->beginTransaction();
try {
    $update=$store->prepare('UPDATE portal_product_sds SET public_draft=1 WHERE id=? AND product_id=? AND published=0 AND reviewed_at=0');
    foreach($validated as $productId=>[$id,$name]) {
        $update->execute([$id,$productId]);
        if($update->rowCount()!==1)throw new RuntimeException('Could not enable unverified draft '.$name);
    }
    $store->commit();
} catch(Throwable $e) {
    if($store->inTransaction())$store->rollBack();
    throw $e;
}
foreach($validated as $productId=>[$id,$name])
    echo "PUBLIC-UNVERIFIED-SDS product_id=$productId doc_id=$id product=$name\n";
echo "SUCCESS: Exactly 14 ANKH drafts available to the live storefront, explicitly UNVERIFIED; ZERO technical approvals recorded.\n";
