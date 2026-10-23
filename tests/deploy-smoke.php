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
$publisher=file_get_contents($root.'/scripts/publish-live.sh');
if(!str_contains($deploy,'/bin/bash scripts/publish-live.sh'))throw new RuntimeException('Krystal cron must call the shared publisher.');
if(!str_contains($cpanel,'/bin/bash scripts/publish-live.sh'))throw new RuntimeException('cPanel deployment must call the same publisher.');
foreach(['index.php','supplier-centre.php','setup.php','style.min.css','style.core.min.css','service-worker.js','admin-visuals.js','admin-visuals.css'] as $required){
 if(!str_contains($publisher,$required))throw new RuntimeException('Publisher is missing asset '.$required);
}
foreach(['admin-visuals.js','admin-visuals.css'] as $visualAsset){
 if(!is_file($root.'/'.$visualAsset))throw new RuntimeException('Missing admin photo asset: '.$visualAsset);
 if(!str_contains($index,$visualAsset))throw new RuntimeException('Admin entrypoint does not load photo asset: '.$visualAsset);
}
if(!str_contains($publisher,'"$php" -l supplier-centre.php'))throw new RuntimeException('Publisher must lint Supplier Centre before copying.');
if(!str_contains($publisher,'install -m 644')||!str_contains($publisher,'mv -f "$stage"'))throw new RuntimeException('Publisher must atomically install web assets.');
if(str_contains($publisher,'backup-sqlite.php')||str_contains($deploy,'backup-sqlite.php')||str_contains($cpanel,'backup-sqlite.php'))throw new RuntimeException('Ordinary code publishing must not depend on database backup.');
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
echo "PASS: Supplier Centre remains standalone and both Krystal deploy paths use one shared publisher.\\n";
