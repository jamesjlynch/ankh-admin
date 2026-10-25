<?php
declare(strict_types=1);
/**
 * Import photo mock-ups only for the twelve seeded laboratory accessory SKUs.
 * Uses SKU->product_id linkage; never changes prices, stock, descriptions or
 * the existing photo chosen manually in the Customer CMS.
 */
set_exception_handler(static function(Throwable $e):void {
    fwrite(STDERR, 'ANKH lab image import failed ['.get_class($e).']: '.$e->getMessage()."\n");
    exit(1);
});
if (PHP_SAPI !== 'cli' || getenv('ANKH_IMPORT_LAB_IMAGES') !== '1') {
    fwrite(STDERR, "CLI-only accessory image import.\n"); exit(1);
}
$home=(string)(getenv('ANKH_DEPLOY_HOME')?:'');
if ($home !== '/home/devcouk') throw new RuntimeException('Unexpected hosting account.');
$configPath=(string)(getenv('ANKH_ADMIN_CONFIG')?:$home.'/ankh-admin-config.php');
if (!is_file($configPath)) throw new RuntimeException('Private ANKH configuration unavailable.');
$config=require $configPath;
$database=(string)($config['database_path']??'');
if ($database!==$home.'/ankh-admin-private/orders.sqlite' || !is_file($database))
    throw new RuntimeException('Refusing unexpected database path.');
$skus=[
 'LAB-PETRI-90-20',
 'LAB-PETRI-60-20',
 'LAB-TUBE-MICRO-15-100',
 'LAB-TUBE-SCREW-2-10',
 'LAB-PIPETTE-5-100',
 'LAB-RACK-SMALL-1',
 'LAB-LABEL-WATERPROOF-100',
 'LAB-BOX-SAMPLE-1',
 'LAB-BEAKER-GLASS-100-1',
 'LAB-CYLINDER-100-1',
 'LAB-SLIDES-100-COVERS-100',
 'LAB-BAGS-GRIP-50-100'
];
$root=dirname(__DIR__).'/assets/lab-accessories';
$db=new PDO('sqlite:'.$database,null,null,[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA foreign_keys=ON;PRAGMA busy_timeout=5000');
$select=$db->prepare('SELECT s.product_id,p.name,
  CASE WHEN c.image_data IS NULL THEN 0 ELSE length(c.image_data) END AS image_bytes
  FROM lab_accessory_sourcing s
  JOIN products p ON p.id=s.product_id
  JOIN customer_catalogue_content c ON c.product_id=s.product_id
  WHERE s.sku=?');
$pending=[];
$skipped=0;
foreach ($skus as $sku) {
  $select->execute([$sku]);$row=$select->fetch();
  if (!$row) throw new RuntimeException('Unmatched original laboratory accessory SKU: '.$sku);
  if ((int)$row['image_bytes']>0) {++$skipped;continue;}
  $path=$root.'/'.$sku.'.jpg.b64';
  if (!is_file($path)) throw new RuntimeException('Missing image asset: '.$sku);
  $encoded=file_get_contents($path);
  if ($encoded===false) throw new RuntimeException('Unreadable image asset: '.$sku);
  $bytes=base64_decode(trim($encoded),true);
  if ($bytes===false || strlen($bytes)<5000 || strlen($bytes)>8*1024*1024)
      throw new RuntimeException('Invalid image bytes: '.$sku);
  $info=@getimagesizefromstring($bytes);
  if (!$info || ($info['mime']??'')!=='image/jpeg'
      || $info[0]<400 || $info[1]<400 || $info[0]>12000 || $info[1]>12000)
      throw new RuntimeException('Invalid JPEG image dimensions: '.$sku);
  $pending[]=[$sku,(int)$row['product_id'],$bytes,(int)$info[0],(int)$info[1]];
}
if (!$pending) {
  echo "ANKH accessory imagery: all ".count($skus)." products already have images; nothing overwritten.\n";
  exit(0);
}
// Back up live SQLite before adding any photo BLOBs.
if (!class_exists('SQLite3') || !method_exists('SQLite3','backup'))
    throw new RuntimeException('SQLite online backup unavailable; refusing data write.');
$dir=$home.'/ankh-admin-backups';
if (!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))
    throw new RuntimeException('Cannot create private backup directory.');
$backup=$dir.'/before-lab-images-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
$src=new SQLite3($database,SQLITE3_OPEN_READONLY);
$dest=new SQLite3($backup,SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE);
try {
  if (!$src->backup($dest)) throw new RuntimeException('Database image import backup failed.');
} finally {$dest->close();$src->close();}
if (!is_file($backup) || filesize($backup)<100) throw new RuntimeException('Backup could not be verified.');
chmod($backup,0600);
$update=$db->prepare('UPDATE customer_catalogue_content
  SET image_data=:data,image_mime=:mime,image_width=:width,image_height=:height,
  updated_at=:time WHERE product_id=:id AND (image_data IS NULL OR length(image_data)=0)');
$imported=0;
$db->beginTransaction();
try {
  foreach ($pending as [$sku,$id,$bytes,$width,$height]) {
    $update->bindValue(':data',$bytes,PDO::PARAM_LOB);
    $update->bindValue(':mime','image/jpeg');
    $update->bindValue(':width',$width,PDO::PARAM_INT);
    $update->bindValue(':height',$height,PDO::PARAM_INT);
    $update->bindValue(':time',gmdate('c'));
    $update->bindValue(':id',$id,PDO::PARAM_INT);
    $update->execute();
    $imported+=$update->rowCount();
  }
  $db->commit();
} catch(Throwable $e) {
  if ($db->inTransaction()) $db->rollBack();
  throw $e;
}
echo "ANKH laboratory product photos: added $imported; left $skipped existing product photos unchanged.\n";
