<?php
declare(strict_types=1);
/** Private one-time informational launch of all twelve ANKH lab accessory drafts. */
set_exception_handler(static function(Throwable $e):void{
    fwrite(STDERR,'ANKH lab publication error ['.get_class($e).']: '.$e->getMessage()."\n");
    exit(1);
});
if(PHP_SAPI!=='cli'||getenv('ANKH_PUBLISH_LAB_PROFILES')!=='1'){
    fwrite(STDERR,"CLI publication disabled without explicit release opt-in.\n");exit(1);
}
$home=(string)(getenv('ANKH_DEPLOY_HOME')?:'');
if($home===''||!str_starts_with($home,'/home/')||$home==='/home/lynchde1')
    throw new RuntimeException('Not on migrated Krystal account.');
$configFile=(string)(getenv('ANKH_ADMIN_CONFIG')?:$home.'/ankh-admin-config.php');
if(!is_file($configFile))throw new RuntimeException('Private Admin configuration unavailable.');
$config=require $configFile;
$dbPath=(string)($config['database_path']??'');
if($dbPath!==$home.'/ankh-admin-private/orders.sqlite'||!is_file($dbPath))
    throw new RuntimeException('Unexpected Admin database path.');
$marker=$home.'/.ankh-accessories-published-information-20261025';
if(is_file($marker)){echo "ANKH laboratory information pages already processed; no further edits.\n";exit(0);}
if(!class_exists('SQLite3')||!method_exists('SQLite3','backup'))
    throw new RuntimeException('SQLite online backup is required for database publication.');
$backupDir=$home.'/ankh-admin-backups';
if(!is_dir($backupDir)&&!mkdir($backupDir,0700,true)&&!is_dir($backupDir))
    throw new RuntimeException('Cannot create backup folder.');
$backup=$backupDir.'/before-lab-information-publish-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
$src=new SQLite3($dbPath,SQLITE3_OPEN_READONLY);
$dest=new SQLite3($backup,SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE);
try{
    if(!$src->backup($dest))throw new RuntimeException('SQLite online backup failed.');
}finally{$dest->close();$src->close();}
if(!is_file($backup)||filesize($backup)<100)throw new RuntimeException('Backup verification failed.');
chmod($backup,0600);
require_once dirname(__DIR__).'/lab-accessory-publish.php';
$db=new PDO('sqlite:'.$dbPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON;PRAGMA busy_timeout=5000');
$result=ankhPublishLabProfiles($db);
if($result['published']+$result['already_published']!==12)throw new RuntimeException('Could not confirm all twelve lab drafts.');
$tmp=$marker.'.tmp.'.bin2hex(random_bytes(3));
if(file_put_contents($tmp,gmdate('c')."\n".json_encode($result,JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Unable to mark publication.');
chmod($tmp,0600);
rename($tmp,$marker);
echo 'ANKH lab informational catalogue published: '.json_encode($result,JSON_THROW_ON_ERROR)."\n";
echo "Supplier quotes, product photos, stock and commercial requests remain pending verification.\n";
