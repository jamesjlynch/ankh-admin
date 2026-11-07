<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$temp=sys_get_temp_dir().'/ankh-search-'.bin2hex(random_bytes(6));
mkdir($temp,0700);
$dbPath=$temp.'/fixture.sqlite';
$config=$temp.'/config.php';
$anonConfig=$temp.'/anon.php';
$common='["password_hash"=>password_hash("test-only",PASSWORD_DEFAULT),"database_path"=>'.var_export($dbPath,true).']';
file_put_contents($config,'<?php $_SESSION["admin"]=true;$_SESSION["last"]=time(); return '.$common.';');
file_put_contents($anonConfig,'<?php return '.$common.';');
function runSearch(string $config,string $query):array{
 global $root;
 $code='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).'); $_GET='.var_export(['api'=>'search','q'=>$query],true).'; $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; $_SERVER["HTTP_HOST"]="localhost"; $_SERVER["SCRIPT_NAME"]="/index.php"; require '.var_export($root.'/index.php',true).';';
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=stderr -r '.escapeshellarg($code).' 2>/dev/null',$lines,$exit);
 $output=trim(implode("\n",$lines));
 $data=json_decode($output,true);
 if(!is_array($data))throw new RuntimeException('Search did not return JSON: '.substr($output,-800));
 return $data;
}
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
try{
 runSearch($config,'ab');
 $db=new PDO('sqlite:'.$dbPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec("INSERT INTO customers(id,name,phone,address,created) VALUES(1,'Sarah Jones','07700900123','','2026-01-01'),(2,'Unrelated Person','07700900999','','2026-01-01');");
 $insertCustomer=$db->prepare('INSERT INTO customers(name,phone,address,created) VALUES(?,?,?,?)');
 for($i=1;$i<=7;$i++)$insertCustomer->execute(['Findme Client '.$i,'','','2026-01-01']);
 $insertOrder=$db->prepare('INSERT INTO orders(id,customer,phone,address,notes,status,created,recipient_name) VALUES(?,?,?,?,?,?,?,?)');
 $insertOrder->execute([12,'Jane Smith','','','','New','2026-02-01T10:00:00Z','Sarah Jones']);
 $insertOrder->execute([3,'Other Customer','','','','New','2026-02-02T10:00:00Z','']);
 $insertProduct=$db->prepare('INSERT INTO products(id,name,price,active) VALUES(?,?,?,?)');
 $insertProduct->execute([99001,'Searchable Reta 10mg',5000,1]);
 $insertProduct->execute([99002,'Searchable Reta 20mg',6000,0]);
 $insertProduct->execute([99003,'Searchable B12 Complex',1500,1]);
 $db=null;
 $client=runSearch($config,'Sarah');
 check($client['ok']===true,'Signed-in search must succeed.');
 check(array_column($client['groups']['clients'],'id')===[1],'Client name search must return the matching client.');
 check(array_column($client['groups']['orders'],'id')===[12],'Order search must match recipient name.');
 $reference=runSearch($config,'ANK-12');
 check(array_column($reference['groups']['orders'],'id')===[12],'ANK-<id> must match the order id.');
 $product=runSearch($config,'Searchable Reta');
 check(array_column($product['groups']['products'],'id')===[99001],'Products must match by name and exclude inactive products.');
 $short=runSearch($config,'S');
 check($short['groups']===['clients'=>[],'orders'=>[],'products'=>[]],'Queries under two characters must return no groups.');
 $limited=runSearch($config,'Findme Client');
 check(count($limited['groups']['clients'])===5,'Each group must be capped at five results.');
 $anon=runSearch($anonConfig,'Sarah');
 check($anon['ok']===false&&str_contains((string)$anon['error'],'sign in'),'Search must require a signed-in admin.');
 echo "PASS: search finds clients, orders (including ANK-<id>) and active products, caps groups at five and requires login.\n";
}finally{
 $db=null;
 foreach(glob($temp.'/*') as $file)unlink($file);
 rmdir($temp);
}
