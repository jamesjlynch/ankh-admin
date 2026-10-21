<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$configPath=(string)(getenv('ANKH_ADMIN_CONFIG')?:((string)(getenv('HOME')?:'/home/devcouk')).'/ankh-admin-config.php');
if(!is_file($configPath)){fwrite(STDERR,"Private configuration not found.\n");exit(1);}
$config=require $configPath;
$db=new PDO('sqlite:'.$config['database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require dirname(__DIR__).'/auth-users.php';
ankhAuthSchema($db);
$username=strtolower(trim((string)($argv[1]??'')));
$email=strtolower(trim((string)($argv[2]??'')));
if(!preg_match('/^[a-z][a-z0-9._-]{2,31}$/',$username)||!filter_var($email,FILTER_VALIDATE_EMAIL)){
 fwrite(STDERR,"Usage: php set-admin-email.php username email@example.com\n");exit(1);
}
$stmt=$db->prepare('UPDATE admin_users SET email=? WHERE username=?');
try{$stmt->execute([$email,$username]);}
catch(PDOException $e){fwrite(STDERR,"Email already assigned to another account (or invalid database update).\n");exit(1);}
if($stmt->rowCount()===0){fwrite(STDERR,"Account not found or email already set.\n");exit(1);}
echo "Email saved for {$username}. You may sign in using either identifier.\n";
