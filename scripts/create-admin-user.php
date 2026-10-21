<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$home=(string)(getenv('HOME')?:'/home/devcouk');
$configPath=(string)(getenv('ANKH_ADMIN_CONFIG')?:$home.'/ankh-admin-config.php');
if(!is_file($configPath)){fwrite(STDERR,"Missing private config. Set ANKH_ADMIN_CONFIG.\n");exit(1);}
$config=require $configPath;
$db=new PDO('sqlite:'.$config['database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require dirname(__DIR__).'/auth-users.php';
ankhAuthSchema($db);
$user=strtolower(trim((string)($argv[1]??'')));
if(!preg_match('/^[a-z][a-z0-9._-]{2,31}$/',$user)){fwrite(STDERR,"Usage: php create-admin-user.php username\n");exit(1);}
echo "Enter new password for {$user} (not echoed): ";
$command='stty -echo';if(function_exists('shell_exec'))@shell_exec($command);
try{$password=rtrim((string)fgets(STDIN),"\r\n");}finally{if(function_exists('shell_exec'))@shell_exec('stty echo');echo "\n";}
if(strlen($password)<12||strlen($password)>128){fwrite(STDERR,"Password must contain 12-128 characters. No changes made.\n");exit(1);}
$hash=password_hash($password,PASSWORD_DEFAULT);unset($password);
$q=$db->prepare('SELECT id FROM admin_users WHERE username=?');$q->execute([$user]);$id=$q->fetchColumn();
if($id!==false){
 $db->prepare('UPDATE admin_users SET password_hash=?,active=1 WHERE id=?')->execute([$hash,$id]);
 $db->prepare('DELETE FROM admin_trusted_devices WHERE user_id=?')->execute([$id]);
 echo "Updated {$user}; existing remembered devices revoked.\n";
}else{$db->prepare('INSERT INTO admin_users(username,password_hash,active,created_at) VALUES(?,?,1,?)')->execute([$user,$hash,time()]);echo "Created {$user}.\n";}
