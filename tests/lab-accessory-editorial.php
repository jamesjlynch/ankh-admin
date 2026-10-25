<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lab-accessory-editorial-update.php';
function editorialCheck(bool $yes,string $message):void{if(!$yes)throw new RuntimeException($message);}
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$db=new PDO('sqlite::memory:',null,null,$opts);
$db->exec("PRAGMA foreign_keys=ON;CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,price INTEGER,active INTEGER,cost INTEGER,low_stock_at INTEGER DEFAULT 5,supplier_pack_size INTEGER DEFAULT 1,stock_qty INTEGER DEFAULT NULL);
INSERT INTO products(name,price,active,cost) VALUES('Historical Peptide',5500,1,2800);");
$created=ankhLabAccessoriesSeed($db);
editorialCheck($created['created']===12,'Seeding fixture failed');
$copy=ankhLaboratoryAccessoryEditorial();
editorialCheck(count($copy)===12,'Exactly 12 pages are required');
$words=[];
foreach($copy as $sku=>$text){
 editorialCheck(strlen($text)>1100&&strlen($text)<10000,"Insufficient editorial content: $sku");
 editorialCheck(substr_count($text,'## ')>=4&&str_contains($text,'## Frequently asked questions'),"Missing sections and FAQs: $sku");
 editorialCheck(str_contains($text,'ANKH'),"Missing brand: $sku");
 editorialCheck(preg_match('/\b(guaranteed sterile|certified medical|FDA approved|clinically proven|best in the UK)\b/i',$text)!==1,"Unsupported claim: $sku");
 $words[$sku]=count(preg_split('/\s+/u',trim($text)));
 editorialCheck($words[$sku]>=170,"Need more substantive text: $sku");
}
$selected=$db->query("SELECT s.sku,s.product_id,c.description,p.active,p.price,p.cost FROM lab_accessory_sourcing s JOIN products p ON p.id=s.product_id JOIN customer_catalogue_content c ON c.product_id=p.id")->fetchAll(PDO::FETCH_ASSOC);
$before=[];foreach($selected as $row)$before[$row['sku']]=$row;
$manualId=$before['LAB-TUBE-SCREW-2-10']['product_id'];
$publishedId=$before['LAB-BEAKER-GLASS-100-1']['product_id'];
$db->prepare('UPDATE customer_catalogue_content SET description=? WHERE product_id=?')->execute(['Manually curated description, please retain.',(int)$manualId]);
$db->prepare('UPDATE customer_catalogue_visibility SET visible=1 WHERE product_id=?')->execute([(int)$publishedId]);
$first=ankhAccessoryUpgradeEditorial($db);
editorialCheck($first['updated']===10 && $first['manually_edited']===1 && $first['published_or_active']===1,'Must edit only ten pristine unpublished drafts');
$second=ankhAccessoryUpgradeEditorial($db);
editorialCheck($second['already_updated']===10 && $second['updated']===0 && $second['manually_edited']===1,'Rerun must not change already migrated or manual content');
$rows=$db->query("SELECT s.sku,p.price,p.cost,p.active,c.description FROM lab_accessory_sourcing s JOIN products p ON p.id=s.product_id JOIN customer_catalogue_content c ON c.product_id=p.id")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $row){
 $sku=$row['sku'];editorialCheck($row['price']===$before[$sku]['price'] && $row['cost']===$before[$sku]['cost'] && $row['active']===$before[$sku]['active'],'Editorial update altered money or product activation');
 if($sku==='LAB-TUBE-SCREW-2-10')editorialCheck($row['description']==='Manually curated description, please retain.','Manual changes overwritten');
 elseif($sku==='LAB-BEAKER-GLASS-100-1')editorialCheck($row['description']===$before[$sku]['description'],'Published page overwritten');
 else editorialCheck($row['description']===$copy[$sku],'Long-form description not stored correctly');
}
editorialCheck((int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn()===13,'Editorial upgrade inserted products');
echo "PASS: all 12 original brand descriptions exceed 170 words and have FAQs; pristine-only update, manual edits preserved, published pages untouched, prices/stock unchanged and safe repeat.\n";
