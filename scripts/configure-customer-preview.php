<?php
declare(strict_types=1);
/**
 * One-time, CLI-only provisioning for ANKH staff preview.
 * Stores a slow password hash outside the web root; never stores plaintext.
 * This DOES NOT configure customer SMS authentication.
 */
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
$path=getenv('ANKH_PORTAL_CONFIG') ?: rtrim((string)(getenv('HOME') ?: '/home/devcouk'),'/').'/ankh-customer-portal-config.php';
if (!is_file($path) || is_link($path)) {fwrite(STDERR,"Private portal configuration missing or unsafe.\n");exit(1);}
$realPath=realpath($path);
if (!$realPath || preg_match('~(?:^|/)public_html(?:/|$)~i',$realPath)) {fwrite(STDERR,"Refusing to write inside a public document root.\n");exit(1);}
$config=require $realPath;
if (!is_array($config) || empty($config['admin_database_path']) || empty($config['portal_database_path'])) {fwrite(STDERR,"Portal config missing expected paths.\n");exit(1);}
if (!empty($config['preview_password_hash']) && ($argv[1] ?? '') !== '--rotate') {
    echo "Staff preview password is already configured. Use --rotate only to replace it.\n";
    exit;
}
$password=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
$config['preview_password_hash']=password_hash($password,PASSWORD_DEFAULT);
$folder=dirname($realPath);
$tmp=$folder.'/.ankh-portal-config.'.bin2hex(random_bytes(8)).'.tmp';
umask(0077);
$contents="<?php\nreturn ".var_export($config,true).";\n";
if (file_put_contents($tmp,$contents,LOCK_EX)===false) {fwrite(STDERR,"Failed to write temporary configuration.\n");exit(1);}
chmod($tmp,0600);
if (!rename($tmp,$realPath)) {unlink($tmp);fwrite(STDERR,"Failed to replace configuration.\n");exit(1);}
chmod($realPath,0600);
echo "Private staff preview password configured.\n";
echo "STAFF PREVIEW PASSWORD (SAVE THIS AND NEVER SEND IT IN CHAT):\n".$password."\n";
echo "This password only unlocks the owner-only preview of real customer data. Customer logins remain disabled until Twilio is set up.\n";
