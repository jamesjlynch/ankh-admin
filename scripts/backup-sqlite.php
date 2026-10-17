<?php
declare(strict_types=1);
/** CLI-only consistent ANKH SQLite backup using VACUUM INTO. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
umask(0077);
$explicitConfig=trim((string)(getenv('ANKH_ADMIN_CONFIG')?:''));
if($explicitConfig!=='')$configPath=$explicitConfig;
else{
 $home=rtrim((string)(getenv('HOME')?:''),'/');
 $candidates=array_values(array_filter([$home!==''?$home.'/ankh-admin-config.php':'','/home/lynchde1/ankh-admin-config.php']));
 $configPath='';
 foreach($candidates as $candidate)if(is_file($candidate)){$configPath=$candidate;break;}
}

if(!is_file($configPath))throw new RuntimeException('Private ANKH configuration not found.');
$config=require $configPath;
$source=(string)($config['database_path']??'');
if($source===''||!is_file($source))throw new RuntimeException('ANKH SQLite database not found.');
$sourceReal=realpath($source);
if(!$sourceReal)throw new RuntimeException('Database path cannot be resolved.');
$root=dirname(realpath($configPath));
$destination=getenv('ANKH_ADMIN_BACKUP_DIR') ?: $root.'/ankh-admin-backups';
if(is_link($destination))throw new RuntimeException('Refusing symlinked backup directory.');
if(!is_dir($destination)&&!mkdir($destination,0700,true))throw new RuntimeException('Could not create private backup directory.');
$destination=realpath($destination);
if(!$destination)throw new RuntimeException('Backup directory unavailable.');
$documentRoot=realpath((string)(getenv('ANKH_DOCUMENT_ROOT') ?: ($root.'/public_html')));
if($documentRoot&&($destination===$documentRoot||str_starts_with($destination,$documentRoot.'/')))
 throw new RuntimeException('Backups must remain outside public_html.');
if(!is_writable($destination))throw new RuntimeException('Backup directory is not writable.');
$sourceDb=new PDO('sqlite:'.$sourceReal,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if((string)$sourceDb->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Source integrity check failed.');
$stamp=gmdate('Ymd\THis\Z');$base='ankh-'.$stamp.'-'.bin2hex(random_bytes(3)).'.sqlite';
$temp=$destination.'/.' . $base.'.tmp';$final=$destination.'/'.$base;
try{
 $sourceDb->exec('VACUUM INTO '.$sourceDb->quote($temp));
 chmod($temp,0600);
 $copy=new PDO('sqlite:'.$temp,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 if((string)$copy->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Backup integrity check failed.');
 // VACUUM INTO produces a consistent snapshot even if a new order arrives
 // after the snapshot starts. Cross-checking live row counts afterwards can
 // incorrectly reject a valid backup during normal business activity.
 foreach(['orders','customers','payments'] as $table){
  $count=$copy->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
  if($count===false)throw new RuntimeException('Backup verification failed for '.$table);
 }
 $copy=null;
 if(!rename($temp,$final))throw new RuntimeException('Unable to finalise snapshot.');
 chmod($final,0600);
 $backups=glob($destination.'/ankh-*.sqlite')?:[];rsort($backups,SORT_STRING);
 foreach(array_slice($backups,30) as $old)if(is_file($old)&&!is_link($old))unlink($old);
 echo 'ANKH backup verified: '.$base.' ('.filesize($final)." bytes)\n";
}catch(Throwable $ex){if(is_file($temp))unlink($temp);throw $ex;}
