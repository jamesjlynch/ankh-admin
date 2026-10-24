<?php
declare(strict_types=1);
require_once __DIR__.'/../customer-portal/product-certificates.php';
require_once __DIR__.'/../customer-portal/product-coa-bulk.php';
function ok(bool $assert,string $msg):void{if(!$assert)throw new RuntimeException($msg);}
$m=ankhBulkCoaManifest();
ok(count($m)===13,'Must import only the 13 approved documents');
ok(count(array_unique(array_column($m,2)))===13,'Fingerprints must be unique');
$rows=[];
$i=1;
foreach($m as $f=>$ref){
  $rows[]=['id'=>$i++,'name'=>preg_replace('/ \\(full-length TB4\\)/','',$ref[0]),'active'=>1,'visible'=>1];
}
foreach($m as $f=>$ref){
  $res=ankhBulkResolveProduct($rows,$ref[0],true);
  ok(($res['id']??null)!==null,'No exact match for '.$ref[0]);
}
$some=ankhBulkResolveProduct($rows,'BPC-157 10mg',true);
ok(($some['id']??null)===null,'Strength mismatch must be blocked');
$some=ankhBulkResolveProduct($rows,'TB-500 2mg (full-length TB4)',false);
ok(($some['id']??null)===null,'Generic TB-500 must require identity confirmation');
$some=ankhBulkResolveProduct(array_merge($rows,[$rows[0]]),'BPC-157 5mg',true);
ok(($some['id']??null)===null,'Ambiguous product must be blocked');
$some=ankhBulkResolveProduct($rows,'CJC-1295 with DAC 5mg',true);
ok(($some['id']??null)===null,'DAC must never match No DAC');
$admin=new PDO('sqlite::memory:');
$admin->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$admin->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$admin->exec('CREATE TABLE products (id INTEGER PRIMARY KEY,name TEXT,active INTEGER);
CREATE TABLE customer_catalogue_visibility(product_id INTEGER,visible INTEGER);');
foreach($rows as $row){
  $admin->prepare('INSERT INTO products VALUES (?,?,?)')->execute([$row['id'],$row['name'],$row['active']]);
  $admin->prepare('INSERT INTO customer_catalogue_visibility VALUES (?,?)')->execute([$row['id'],$row['visible']]);
}
$plan=ankhBulkCoaPlan($admin,true);
ok(count($plan)===13,'Plan missing products');
foreach($plan as $file=>$p)ok(($p['id']??null)!==null&&($p['published']??false),'Missing or hidden product in plan '.$file);
echo "ANKH bulk COA matching tests passed: 13 deterministic fingerprints, product strength, DAC distinction, TB-500 gate, ambiguity, published-visibility.\\n";
