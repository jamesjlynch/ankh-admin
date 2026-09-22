<?php
// Run only from a terminal. Never accepts configuration through the web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$path = getenv('ANKH_ADMIN_CONFIG') ?: dirname(__DIR__,2).'/ankh-admin-config.php';
if (file_exists($path)) { fwrite(STDERR,"Configuration already exists; no changes made.\n"); exit(1); }
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR,"Enable PDO SQLite in the hosting PHP settings first.\n"); exit(1); }
$password = bin2hex(random_bytes(16));
$data = dirname($path).'/ankh-admin-private';
if (!is_dir($data) && !mkdir($data,0700,true)) { exit("Cannot create private directory.\n"); }
umask(0077);
$config=['password_hash'=>password_hash($password,PASSWORD_DEFAULT),'database_path'=>$data.'/orders.sqlite'];
if (file_put_contents($path,"<?php\nreturn ".var_export($config,true).";\n",LOCK_EX)===false)exit("Cannot write configuration.\n");
echo "Admin password (save securely): ".$password."\n";
echo "Configuration created. Visit the app using HTTPS.\n";
