<?php
declare(strict_types=1);
/* Static regression for the deployed PHP entrypoint and its runtime dependencies.
 * Do not ship new require_once dependencies without updating BOTH Krystal deploy paths.
 */
$root=dirname(__DIR__);
$index=file_get_contents($root.'/index.php');
$deploy=file_get_contents($root.'/deploy-krystal.sh');
$cpanel=file_get_contents($root.'/.cpanel.yml');
$module=$root.'/supplier-centre.php';
if(!is_file($module))throw new RuntimeException('Supplier Centre module missing in repository.');
$phpLint=trim((string)shell_exec('php -l '.escapeshellarg($module).' 2>&1'));
if(!str_contains($phpLint,'No syntax errors detected'))throw new RuntimeException('Supplier Centre must pass syntax check.');
if(!str_contains($deploy,'for file in')||!str_contains($deploy,'supplier-centre.php manifest.webmanifest'))throw new RuntimeException('Krystal shell deploy must copy supplier-centre.php.');
if(!str_contains($cpanel,'index.php supplier-centre.php style.css'))throw new RuntimeException('cPanel deployment must include supplier-centre.php.');
foreach(['deploy-krystal.sh'=>$deploy,'.cpanel.yml'=>$cpanel] as $name=>$content){
 if(!str_contains($content,'php -l supplier-centre.php'))throw new RuntimeException($name.' must lint supplier-centre.php before deploying.');
}
if(!str_contains($index,"is_file(__DIR__.'/supplier-centre.php')"))throw new RuntimeException('Entry point must guard missing optional module.');
if(!str_contains($index,'if($supplierCentreAvailable)'))throw new RuntimeException('Optional supplier render/startup guards absent.');
if(!str_contains($index,'Supplier Centre unavailable'))throw new RuntimeException('Supplier route must provide a safe fallback.');
echo "PASS: both deployment methods include and lint supplier module; dashboard has missing-file fallback.\n";
