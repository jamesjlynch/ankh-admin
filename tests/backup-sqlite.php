<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$temp=sys_get_temp_dir().'/ankh-backup-'.bin2hex(random_bytes(6));
mkdir($temp,0700);mkdir($temp.'/public_html',0700);
$db=new PDO('sqlite:'.$temp.'/orders.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE orders(id INTEGER PRIMARY KEY,status TEXT);
CREATE TABLE customers(id INTEGER PRIMARY KEY,name TEXT);
CREATE TABLE payments(id INTEGER PRIMARY KEY,amount INTEGER);
INSERT INTO orders(status) VALUES('Paid');
INSERT INTO customers(name) VALUES('Fixture');
INSERT INTO payments(amount) VALUES(1000);");
$db=null;
$config=$temp.'/config.php';
file_put_contents($config,'<?php return '.var_export(['database_path'=>$temp.'/orders.sqlite','password_hash'=>'fixture'],true).';');
try{
 $vars=['ANKH_ADMIN_CONFIG'=>$config,'ANKH_DOCUMENT_ROOT'=>$temp.'/public_html','ANKH_ADMIN_BACKUP_DIR'=>$temp.'/backups'];
 $prefix='';foreach($vars as $key=>$val)$prefix.=$key.'='.escapeshellarg($val).' ';
 $output=[];$code=1;
 exec($prefix.escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/backup-sqlite.php').' 2>&1',$output,$code);
 if($code!==0)throw new RuntimeException("Backup failed: ".implode("\n",$output));
 $files=glob($temp.'/backups/ankh-*.sqlite')?:[];
 if(count($files)!==1)throw new RuntimeException('Expected one backup snapshot.');
 $copy=new PDO('sqlite:'.$files[0],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 if($copy->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Backup integrity verification failed.');
 foreach(['orders','customers','payments'] as $table)if((int)$copy->query("SELECT COUNT(*) FROM $table")->fetchColumn()!==1)throw new RuntimeException('Fixture count mismatch.');
 $copy=null;
 if((fileperms($files[0]) & 0077)!==0)throw new RuntimeException('Backup file was accessible beyond owner.');
 if(str_starts_with(realpath($files[0]),realpath($temp.'/public_html')))throw new RuntimeException('Backup leaked into public_html.');
 echo "PASS: consistent private SQLite snapshot, verified contents, secure permissions.\n";
}finally{
 foreach(glob($temp.'/backups/*')?:[] as $file)unlink($file);
 if(is_dir($temp.'/backups'))rmdir($temp.'/backups');
 foreach(glob($temp.'/public_html/*')?:[] as $file)unlink($file);
 rmdir($temp.'/public_html');
 unlink($config);unlink($temp.'/orders.sqlite');rmdir($temp);
}
