<?php
declare(strict_types=1);
/** CLI-only, once-per-release, guarded content upgrade for original ANKH lab drafts. */
set_exception_handler(static function(Throwable $e):void {
    error_log('ANKH lab editorial update failed ['.get_class($e).']: '.$e->getMessage());
    fwrite(STDERR,"ANKH accessory editorial upgrade failed; no manual edits overwritten.\n");
    exit(1);
});
if(PHP_SAPI!=='cli'||getenv('ANKH_REFRESH_LAB_COPY')!=='1'){fwrite(STDERR,"Private CLI use only.\n");exit(1);}
$home=(string)(getenv('ANKH_DEPLOY_HOME')?:'');
if($home===''||!str_starts_with($home,'/home/')||$home==='/home/lynchde1')throw new RuntimeException('Incorrect production account.');
$configPath=(string)(getenv('ANKH_ADMIN_CONFIG')?:$home.'/ankh-admin-config.php');
if(!is_file($configPath))throw new RuntimeException('Private Admin configuration missing.');
$config=require $configPath;
$database=(string)($config['database_path']??'');
if($database!==$home.'/ankh-admin-private/orders.sqlite'||!is_file($database))
    throw new RuntimeException('Database must match migrated production.');
$marker=$home.'/.ankh-lab-editorial-upgraded-20261025-v1';
if(is_file($marker)){echo "ANKH laboratory copy already reviewed; no changes.\n";exit(0);}
if(!class_exists('SQLite3')||!method_exists('SQLite3','backup'))
    throw new RuntimeException('No database backup functionality available.');
$dir=$home.'/ankh-admin-backups';
if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))
    throw new RuntimeException('Unable to create private backup directory.');
$backup=$dir.'/before-lab-editorial-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
$src=new SQLite3($database,SQLITE3_OPEN_READONLY);
$dest=new SQLite3($backup,SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE);
try{
    if(!$src->backup($dest))throw new RuntimeException('Private SQLite snapshot failed.');
}finally{$dest->close();$src->close();}
if(!is_file($backup)||filesize($backup)<100)throw new RuntimeException('Backup did not pass size verification.');
chmod($backup,0600);
require_once dirname(__DIR__).'/lab-accessory-editorial-update.php';
$db=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON;PRAGMA busy_timeout=5000');
$results=ankhAccessoryUpgradeEditorial($db);
$markerTmp=$marker.'.'.bin2hex(random_bytes(4));
if(file_put_contents($markerTmp,gmdate('c')."\n".json_encode($results,JSON_THROW_ON_ERROR))===false)
    throw new RuntimeException('Unable to record migration state.');
chmod($markerTmp,0600);rename($markerTmp,$marker);
echo "ANKH branded laboratory copy: ".json_encode($results,JSON_THROW_ON_ERROR)."\n";
echo "Every updated page remained inactive and unpublished. Manual content, prices and stock preserved.\n";
