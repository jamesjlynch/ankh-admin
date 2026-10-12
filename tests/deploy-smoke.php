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
// cPanel installations using an older deployment manifest may still only publish index.php.
// Keep the production module inlined until all servers have successfully deployed the separate file.
$begin="// BEGIN SELF-CONTAINED SUPPLIER CENTRE — included in index.php so legacy Krystal deployments work.\n";
$end="\n// END SELF-CONTAINED SUPPLIER CENTRE";
$start=strpos($index,$begin);
if($start===false)throw new RuntimeException('Self-contained Supplier Centre code missing from deployed index.php.');
$start+=strlen($begin);
$stop=strpos($index,$end,$start);
if($stop===false)throw new RuntimeException('Self-contained supplier code lacks a closing marker.');
$inline=substr($index,$start,$stop-$start);
$reference=file_get_contents($module);
$reference=preg_replace('/^<\\?php\\s+declare\\(strict_types=1\\);\\s*/','',$reference);
if(rtrim($inline)!==rtrim($reference))throw new RuntimeException('Inlined module is out of sync with supplier-centre.php.');
if(str_contains($index,"require_once __DIR__.'/supplier-centre.php'"))throw new RuntimeException('Entry point still depends on a separately deployed module.');
if(!str_contains($index,'sc_setup($db);')||!str_contains($index,'sc_render($db,$stockProducts,$reservedStockByProduct,$incomingByProduct'))throw new RuntimeException('Inlined supplier module is not wired into the app.');
echo "PASS: Supplier Centre works from deployed index.php alone; reference module matches and both deploy scripts include it.\\n";
