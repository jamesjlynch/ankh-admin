<?php
declare(strict_types=1);
/**
 * Emergency credential recovery from Krystal terminal only.
 * Requires filesystem access to the private configuration. Never web-accessible.
 * Enter the personal PIN interactively so it does not enter shell history.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
$configPath=getenv('ANKH_ADMIN_CONFIG') ?: rtrim((string)getenv('HOME'),'/').'/ankh-admin-config.php';
if(!is_file($configPath))throw new RuntimeException('Private admin configuration not found.');
$config=require $configPath;
$path=(string)($config['database_path']??'');
if($path===''||!is_file($path))throw new RuntimeException('Private SQLite database not found.');
$console=fopen('/dev/tty','r+');
if(!$console)throw new RuntimeException('An interactive server terminal is required.');
$db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA busy_timeout=5000');
$enabled=(string)($db->query("SELECT value FROM settings WHERE key='operators_enabled'")->fetchColumn()?:'0')==='1';
$name=$enabled?trim((string)($argv[1]??'')):'Legacy admin';
if($enabled&&!in_array($name,['Jay','Tony'],true))throw new RuntimeException('Usage: php reset-admin-login.php Jay|Tony');
fwrite($console,'Enter NEW six-digit PIN: ');
exec('stty -echo < /dev/tty');
try{$first=trim((string)fgets($console));fwrite($console,"\nConfirm PIN: ");$second=trim((string)fgets($console));fwrite($console,"\n");}
finally{exec('stty echo < /dev/tty');}
if(!preg_match('/^[0-9]{6}$/D',$first)||!hash_equals($first,$second))throw new RuntimeException('PINs must match and contain exactly six digits.');
$hash=password_hash($first,PASSWORD_DEFAULT);
if($enabled){
 $q=$db->prepare('UPDATE operator_accounts SET password_hash=?,updated=? WHERE name=? AND active=1');
 $q->execute([$hash,gmdate('c'),$name]);
 if($q->rowCount()!==1)throw new RuntimeException('Active named account not found.');
}else{
 $db->prepare("INSERT INTO settings(key,value) VALUES('admin_pin_hash',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([$hash]);
}
$db->prepare("INSERT INTO activity_events(entity_type,entity_id,action,actor_id,actor_name,details_json,created) VALUES ('security',0,'Credential recovered on server',NULL,'Server console','{}',?)")->execute([gmdate('c')]);
echo "Recovery saved for $name. PIN not printed.\n";
