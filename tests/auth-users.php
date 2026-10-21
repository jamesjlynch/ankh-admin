<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth-users.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
ankhAuthSchema($db);
assert(ankhAuthUserCount($db)===0);
$db->prepare('INSERT INTO admin_users(username,password_hash,created_at) VALUES(?,?,?)')->execute(['jay',password_hash('a secure testing password',PASSWORD_DEFAULT),time()]);
$db->prepare('INSERT INTO admin_users(username,password_hash,created_at) VALUES(?,?,?)')->execute(['tony',password_hash('another secure password',PASSWORD_DEFAULT),time()]);
if(ankhAuthUserCount($db)!==2)throw new RuntimeException('Account creation failed');
if(!ankhAuthCredentialsValid($db,'jay','a secure testing password'))throw new RuntimeException('Correct password rejected');
if(ankhAuthCredentialsValid($db,'jay','incorrect'))throw new RuntimeException('Incorrect password accepted');
if(ankhAuthCredentialsValid($db,'nonexistent','a secure testing password'))throw new RuntimeException('Unknown account accepted');
echo "ANKH individual account tests passed\n";
