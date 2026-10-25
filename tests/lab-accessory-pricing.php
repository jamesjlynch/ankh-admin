<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lab-accessory-drafts.php';
require_once dirname(__DIR__).'/lab-accessory-pricing.php';
function pcheck(bool $c,string $message):void{if(!$c)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,price INTEGER,active INTEGER,cost INTEGER,low_stock_at INTEGER DEFAULT 5,supplier_pack_size INTEGER DEFAULT 1,stock_qty INTEGER DEFAULT NULL);
INSERT INTO products(name,price,active,cost) VALUES('Historical Peptide',1000,1,500);");
ankhLabAccessoriesSeed($db);$rows=ankhLabAccessorySourceRows($db);pcheck(count($rows)===12,'Sourcing view must contain all twelve drafts');
$sku='LAB-PETRI-90-20';
ankhLabAccessoryUpdateAmazonPrice($db,$sku,'6.99','https://www.amazon.co.uk/dp/B012345678');
$q=$db->prepare("SELECT p.price,p.cost,p.active,v.visible,s.amazon_reference_pence,s.reference_url
 FROM lab_accessory_sourcing s JOIN products p ON p.id=s.product_id
 JOIN customer_catalogue_visibility v ON v.product_id=p.id WHERE s.sku=?");
$q->execute([$sku]);$item=$q->fetch();
pcheck((int)$item['cost']===699&&(int)$item['price']===999,'Amazon price £6.99 must produce £9.99 retail and £6.99 cost');
pcheck((int)$item['active']===0&&(int)$item['visible']===0,'Staff cost update must not publish or activate product');
pcheck((int)$item['amazon_reference_pence']===699,'Private evidence must mirror cost');
pcheck($item['reference_url']==='https://www.amazon.co.uk/dp/B012345678','Listing reference link was lost');
$before=$db->query('SELECT price,cost FROM products WHERE name="Historical Peptide"')->fetch();
foreach([['0','https://www.amazon.co.uk/'],['7.999','https://www.amazon.co.uk/'],['-3',''],['10001',''],['7.00','http://example.org/'],['7.00','https://random.example/buy']] as [$cost,$url]){
    $rejected=false;try{ankhLabAccessoryUpdateAmazonPrice($db,$sku,$cost,$url);}catch(InvalidArgumentException $e){$rejected=true;}
    pcheck($rejected,'Invalid price or source URL was accepted');
}
pcheck($db->query('SELECT price,cost FROM products WHERE name="Historical Peptide"')->fetch()===$before,'Updating lab accessories affected unrelated order product');
$unknown=false;try{ankhLabAccessoryUpdateAmazonPrice($db,'LAB-MISSING-999','5.99','');}catch(InvalidArgumentException $e){$unknown=true;}
pcheck($unknown,'Missing product must be rejected');
echo "PASS: verified staff Amazon quote editor adjusts only cost and retail+£3, preserves invisibility and stock and rejects invalid links and values.\n";
