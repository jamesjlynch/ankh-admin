<?php
declare(strict_types=1);
/** One-off, opt-in private Krystal catalogue seeding. Never runs via HTTP. */
set_exception_handler(static function(Throwable $e):void {
    fwrite(STDERR,'ANKH lab accessory provisioning error ['.get_class($e).']: '.$e->getMessage()."\n");
    exit(1);
});
if(PHP_SAPI!=='cli'||getenv('ANKH_SEED_ACCESSORIES')!=='1'){
    fwrite(STDERR,"Private CLI provisioning only.\n");exit(1);
}
$home=(string)(getenv('ANKH_DEPLOY_HOME')?:'');
if($home===''||!str_starts_with($home,'/home/')||$home==='/home/lynchde1')
    throw new RuntimeException('Refusing to seed outside the active Krystal account.');
$configPath=(string)(getenv('ANKH_ADMIN_CONFIG')?:$home.'/ankh-admin-config.php');
if(!is_file($configPath))throw new RuntimeException('Private Order Admin configuration unavailable.');
$config=require $configPath;
$database=(string)($config['database_path']??'');
$expected=$home.'/ankh-admin-private/orders.sqlite';
if($database!==$expected||!is_file($database))
    throw new RuntimeException('Refusing unexpected Admin database path.');
$marker=$home.'/.ankh-lab-accessories-seeded-20261025';
if(is_file($marker)){echo "ANKH lab accessory drafts already processed; leaving saved products and prices unchanged.\n";exit(0);}
require_once dirname(__DIR__).'/lab-accessory-drafts.php';
$dir=$home.'/ankh-admin-backups';
if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))
    throw new RuntimeException('Cannot make private database backup directory.');
$db=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
$backup=$dir.'/before-lab-accessories-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
if(!class_exists('SQLite3')||!method_exists('SQLite3','backup'))
    throw new RuntimeException('Server lacks SQLite online-backup support; refusing to write to customer data.');
$sourceSnapshot=new SQLite3($database,SQLITE3_OPEN_READONLY);
$backupSnapshot=new SQLite3($backup,SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE);
try{
    if(!$sourceSnapshot->backup($backupSnapshot))
        throw new RuntimeException('SQLite online snapshot failed.');
}finally{
    $backupSnapshot->close();
    $sourceSnapshot->close();
}
if(!is_file($backup)||filesize($backup)<100)throw new RuntimeException('A complete backup could not be verified.');
chmod($backup,0600);
$result=ankhLabAccessoriesSeed($db);
$markerTmp=$marker.'.tmp.'.bin2hex(random_bytes(4));
file_put_contents($markerTmp,gmdate('c')."\n".json_encode($result,JSON_THROW_ON_ERROR));
chmod($markerTmp,0600);rename($markerTmp,$marker);
echo "ANKH accessory draft provisioning: ".json_encode($result,JSON_THROW_ON_ERROR)."\n";
echo "All newly created products inactive and unpublished; unquoted lines require price review.\n";
