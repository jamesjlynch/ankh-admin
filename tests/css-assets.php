<?php
declare(strict_types=1);
// Exercise the real public asset handler with no app configuration or SQLite.
// Legacy deploys can publish index.php without publishing new static files.
$root=dirname(__DIR__);
foreach(['core-css'=>'style.core.min.css','full-css'=>'style.min.css'] as $asset=>$file){
 $snippet='$_GET["asset"]='.var_export($asset,true).'; require '.var_export($root.'/index.php',true).';';
 $actual=shell_exec(escapeshellarg(PHP_BINARY).' -n -r '.escapeshellarg($snippet));
 $expected=file_get_contents($root.'/'.$file);
 if($actual!==$expected)throw new RuntimeException('Public compiled CSS output differs from '.$file);
}
echo "PASS: public CSS fallback serves both exact compiled stylesheets without app/database configuration.\n";
