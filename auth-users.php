<?php
declare(strict_types=1);
/** ANKH: per-user password and revocable persistent login. Private DB only. */
function ankhAuthSchema(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS admin_users (id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE COLLATE NOCASE, password_hash TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, created_at INTEGER NOT NULL)");
 $db->exec("CREATE TABLE IF NOT EXISTS admin_trusted_devices (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE, selector TEXT NOT NULL UNIQUE, token_hash TEXT NOT NULL, expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL, last_used_at INTEGER NOT NULL, user_agent TEXT NOT NULL DEFAULT '')");
 $columns=array_column($db->query('PRAGMA table_info(admin_users)')->fetchAll(PDO::FETCH_ASSOC),'name');
 if(!in_array('email',$columns,true))$db->exec("ALTER TABLE admin_users ADD COLUMN email TEXT");
 $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_users_email ON admin_users(email COLLATE NOCASE) WHERE email IS NOT NULL");
 $db->exec("CREATE INDEX IF NOT EXISTS idx_admin_trusted_expiry ON admin_trusted_devices(expires_at)");
}
function ankhAuthUserCount(PDO $db):int{return (int)$db->query('SELECT COUNT(*) FROM admin_users WHERE active=1')->fetchColumn();}
function ankhAuthCookiePath():string {return rtrim(dirname((string)($_SERVER['SCRIPT_NAME']??'/')),'/').'/';}
function ankhAuthClearCookie():void {
 setcookie('ankh_remember','',['expires'=>time()-3600,'path'=>ankhAuthCookiePath(),'secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
}
function ankhAuthRemember(PDO $db,int $userId):void {
 $selector=bin2hex(random_bytes(12));$token=bin2hex(random_bytes(32));$now=time();
 $db->prepare('INSERT INTO admin_trusted_devices(user_id,selector,token_hash,expires_at,created_at,last_used_at,user_agent) VALUES(?,?,?,?,?,?,?)')->execute([$userId,$selector,hash('sha256',$token),$now+2592000,$now,$now,substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,200)]);
 setcookie('ankh_remember',$selector.':'.$token,['expires'=>$now+2592000,'path'=>ankhAuthCookiePath(),'secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
}
function ankhAuthClearRemember(PDO $db):void {
 $value=(string)($_COOKIE['ankh_remember']??'');$parts=explode(':',$value,2);
 if(count($parts)===2&&preg_match('/^[a-f0-9]{24}$/',$parts[0]))$db->prepare('DELETE FROM admin_trusted_devices WHERE selector=?')->execute([$parts[0]]);
 ankhAuthClearCookie();
}
function ankhAuthRestore(PDO $db):void {
 if(!empty($_SESSION['admin']) && !empty($_SESSION['admin_user_id']) && time()-(int)($_SESSION['last']??0)<=3600)return;
 unset($_SESSION['admin'],$_SESSION['admin_user_id'],$_SESSION['last']);
 $parts=explode(':',(string)($_COOKIE['ankh_remember']??''),2);
 if(count($parts)!==2||!preg_match('/^[a-f0-9]{24}$/',$parts[0])||!preg_match('/^[a-f0-9]{64}$/',$parts[1]))return;
 $q=$db->prepare('SELECT d.*,u.active FROM admin_trusted_devices d JOIN admin_users u ON u.id=d.user_id WHERE d.selector=?');$q->execute([$parts[0]]);$row=$q->fetch(PDO::FETCH_ASSOC);
 if(!$row||!(int)$row['active']||(int)$row['expires_at']<=time()||!hash_equals((string)$row['token_hash'],hash('sha256',$parts[1]))){
  if($row)$db->prepare('DELETE FROM admin_trusted_devices WHERE selector=?')->execute([$parts[0]]);
  ankhAuthClearCookie();return;
 }
 session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['admin_user_id']=(int)$row['user_id'];$_SESSION['last']=time();
 // Rotate on every restore. Old stolen tokens stop working immediately.
 $db->prepare('DELETE FROM admin_trusted_devices WHERE id=?')->execute([(int)$row['id']]);ankhAuthRemember($db,(int)$row['user_id']);
}
function ankhAuthPasswordLogin(PDO $db,string $username,string $password,bool $remember):bool {
 $q=$db->prepare('SELECT id,password_hash FROM admin_users WHERE (username=? OR email=? COLLATE NOCASE) AND active=1');$q->execute([trim($username),trim($username)]);$row=$q->fetch(PDO::FETCH_ASSOC);
 if(!$row||!password_verify($password,(string)$row['password_hash']))return false;
 session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['admin_user_id']=(int)$row['id'];$_SESSION['last']=time();
 ankhAuthClearRemember($db);if($remember)ankhAuthRemember($db,(int)$row['id']);
 return true;
}

function ankhAuthCredentialsValid(PDO $db,string $username,string $password):bool {
 $q=$db->prepare('SELECT password_hash FROM admin_users WHERE (username=? OR email=? COLLATE NOCASE) AND active=1');$q->execute([trim($username),trim($username)]);$hash=$q->fetchColumn();
 // Constant-time password check also for unknown users.
 return password_verify($password,$hash===false?'$2y$10$abcdefghijklmnopqrstuu9nOCyCReBJD27VyRbmbWZGE/1QNaUqG':(string)$hash);
}
