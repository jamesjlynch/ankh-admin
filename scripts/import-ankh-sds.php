<?php
declare(strict_types=1);
/** CLI-only, idempotent import of reviewed-scope ANKH SDS *drafts*.
 * Does not publish customer-facing documents or touch Order Admin data.
 */
if(PHP_SAPI!=='cli')exit(1);
$source=$argv[1]??'';
if(!is_string($source)||!is_dir($source)||is_link($source))throw new RuntimeException('An existing private SDS directory is required.');
$configPath=getenv('ANKH_PORTAL_CONFIG')?:'/home/devcouk/ankh-customer-portal-config.php';
if(!is_file($configPath))throw new RuntimeException('Private portal configuration missing.');
$config=require $configPath;
if(!is_array($config)||!is_file($config['admin_database_path']??'')||!is_file($config['portal_database_path']??''))throw new RuntimeException('Private database configuration unavailable.');
require_once dirname(__DIR__).'/customer-portal/product-safety.php';
$admin=new PDO('sqlite:'.$config['admin_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$admin->exec('PRAGMA query_only=ON');
$store=new PDO('sqlite:'.$config['portal_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$store->exec('PRAGMA busy_timeout=6000');
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
$check=$admin->prepare('SELECT p.name,p.active,v.visible FROM products p LEFT JOIN customer_catalogue_visibility v ON v.product_id=p.id WHERE p.id=?');
$approved=[];
foreach($targets as $id=>[$expectedName,$file]){
    $check->execute([$id]);$found=$check->fetch(PDO::FETCH_ASSOC);
    if(!$found || strcasecmp(trim((string)$found['name']),$expectedName)!==0 || (int)$found['active']!==1 || (int)$found['visible']!==1){
        throw new RuntimeException('Product mapping no longer matches an active visible ANKH product. ID '.$id);
    }
    $path=rtrim($source,'/').'/'.$file;
    if(!is_file($path)||is_link($path)||filesize($path)>8*1024*1024||filesize($path)<80)throw new RuntimeException('Missing/invalid expected PDF '.$file);
    $approved[$id]=[$file,file_get_contents($path)];
}
foreach($approved as $id=>[$file,$bytes]){
    if(!is_string($bytes))throw new RuntimeException('Unreadable SDS: '.$file);
    $url='https://www.uk-peptides.com/safety-data-sheets/';
    $docId=ankhSdsSaveBytes($store,$admin,$id,$file,$bytes,$url);
    echo "STAGED-SDS product_id=$id doc_id=$docId file=$file (private draft)\n";
}
echo "SUCCESS: 14 source-matched ANKH SDS working copies attached privately. Zero documents published by importer.\n";
