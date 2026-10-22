<?php
declare(strict_types=1);
// Explicit, once-per-request maintenance command. Only catalogue metadata is changed.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$request=$argv[1]??'';
if(!preg_match('/^visibility-reset-[0-9]{8}-[a-z0-9-]{1,40}$/D',$request)){
    fwrite(STDERR,"Supply the explicit catalogue reset request ID.\n");exit(2);
}
$configPath=getenv('ANKH_ADMIN_CONFIG')?:'';
if($configPath==='' || !is_file($configPath)){fwrite(STDERR,"Private Admin configuration missing.\n");exit(2);}
$config=require $configPath;
$database=is_array($config)?($config['database_path']??''):'';
if(!is_string($database)||!is_file($database)){fwrite(STDERR,"Existing Admin database missing.\n");exit(2);}
$db=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA busy_timeout=10000');
$transaction=false;
try{
    $db->exec('BEGIN IMMEDIATE');$transaction=true;
    $db->exec('CREATE TABLE IF NOT EXISTS customer_catalogue_actions(request_id TEXT PRIMARY KEY,completed_at TEXT NOT NULL,previous_visibility_json TEXT NOT NULL)');
    $q=$db->prepare('SELECT 1 FROM customer_catalogue_actions WHERE request_id=?');$q->execute([$request]);
    if($q->fetchColumn()){
        $db->exec('COMMIT');$transaction=false;
        echo "This reset request was already completed. Later selections have been preserved.\n";exit;
    }
    $exists=(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_catalogue_visibility'")->fetchColumn();
    $previous=$exists?$db->query('SELECT product_id,visible,updated_at FROM customer_catalogue_visibility ORDER BY product_id')->fetchAll():[];
    $changed=0;
    if($exists){
        $q=$db->prepare('UPDATE customer_catalogue_visibility SET visible=0,updated_at=? WHERE visible<>0');$q->execute([gmdate('c')]);$changed=$q->rowCount();
        if((int)$db->query('SELECT COUNT(*) FROM customer_catalogue_visibility WHERE visible<>0')->fetchColumn()!==0)throw new RuntimeException('Reset verification failed');
    }
    $db->prepare('INSERT INTO customer_catalogue_actions VALUES(?,?,?)')->execute([$request,gmdate('c'),json_encode($previous,JSON_THROW_ON_ERROR)]);
    $db->exec('COMMIT');$transaction=false;
    echo "SUCCESS: Every catalogue visibility selection is unchecked. Cleared ".$changed." saved selection(s). Product details, prices, stock and order records were not updated.\n";
}catch(Throwable $e){
    if($transaction)$db->exec('ROLLBACK');
    fwrite(STDERR,"Catalogue reset failed; transaction rolled back.\n");exit(1);
}
