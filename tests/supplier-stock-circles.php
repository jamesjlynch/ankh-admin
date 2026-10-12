<?php
declare(strict_types=1);
require dirname(__DIR__).'/supplier-centre.php';
function assertBadge(bool $pass,string $message):void{if(!$pass)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("PRAGMA foreign_keys=ON;
CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,price INTEGER NOT NULL DEFAULT 0,family_friends_price INTEGER DEFAULT NULL,stock_tracking INTEGER NOT NULL DEFAULT 1,stock_jay_qty INTEGER NOT NULL DEFAULT 0,stock_tony_qty INTEGER NOT NULL DEFAULT 0,stock_unallocated_qty INTEGER NOT NULL DEFAULT 0,low_stock_at INTEGER NOT NULL DEFAULT 5);
CREATE TABLE orders(id INTEGER PRIMARY KEY,status TEXT NOT NULL,created TEXT NOT NULL);
CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER NOT NULL,product_id INTEGER,quantity INTEGER NOT NULL);
CREATE TABLE supplier_orders(id INTEGER PRIMARY KEY,supplier TEXT NOT NULL,expected_date TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,note TEXT NOT NULL DEFAULT '',created TEXT NOT NULL);
CREATE TABLE supplier_order_items(id INTEGER PRIMARY KEY,supplier_order_id INTEGER NOT NULL,product_id INTEGER NOT NULL,packs INTEGER NOT NULL,pack_size INTEGER NOT NULL,quantity INTEGER NOT NULL,unit_cost INTEGER,received_qty INTEGER DEFAULT 0);
INSERT INTO products(id,name,stock_tracking,stock_jay_qty,stock_tony_qty,stock_unallocated_qty) VALUES
 (1,'Retatrutide 20mg',1,0,3,0),
 (2,'Exactly five 10mg',1,2,3,0),
 (3,'Six available 5mg',1,4,2,0),
 (4,'Reserved deficit 20mg',1,-2,5,0),
 (5,'Not tracked 5mg',0,8,0,0),
 (6,'Holding stock 10mg',1,1,0,2),
 (7,'Zero stock 10mg',1,0,0,0);
");
sc_setup($db);
$db->exec("INSERT INTO supplier_catalogue(sku,name,strength,pack_size,box_usd_cents,product_id,updated) VALUES
 ('RT20','Retatrutide','20mg',10,12000,1,''),
 ('E5','Exactly five','10mg',10,2000,2,''),
 ('S6','Six available','5mg',10,2200,3,''),
 ('RD20','Reserved deficit','20mg',10,2100,4,''),
 ('NT5','Not tracked','5mg',10,2500,5,''),
 ('HD10','Holding stock','10mg',10,2800,6,''),
 ('Z10','Zero stock','10mg',10,3100,7,''),
 ('MX10','MJT only','10mg',10,3500,NULL,'');");
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function money($p){return '£'.number_format((int)$p/100,2);}
function csrf(){echo '<input type="hidden" name="csrf" value="test">';}
$_GET=['tab'=>'order'];
ob_start();sc_render($db,[],[4=>['jay'=>2]],[]);$html=ob_get_clean();
function badgeFor(string $html,string $sku):array{
 $pattern='~<div class="sc-order-item" data-search="'.preg_quote(strtolower($sku),'~').'[^"]*">\s*<span class="sc-order-stock-badge is-(\w+)"[^>]*>([^<]+)</span>~s';
 if(!preg_match($pattern,$html,$m))throw new RuntimeException('No combined stock circle for '.$sku);
 return [$m[1],trim($m[2])];
}
assertBadge(badgeFor($html,'RT20')===['low','3'],'0 James + 3 Tony must show red circle 3.');
assertBadge(badgeFor($html,'E5')===['edge','5'],'Exactly 5 available should use neutral amber threshold.');
assertBadge(badgeFor($html,'S6')===['good','6'],'6+ available should show a green circle.');
assertBadge(badgeFor($html,'RD20')===['low','3'],'-2 reserved deficit + 5 spare must show 3 combined, not falsely show 5.');
assertBadge(badgeFor($html,'HD10')===['low','3'],'Include unallocated holding vials in combined stock.');
assertBadge(badgeFor($html,'Z10')===['low','0'],'Zero available vials should display a red 0.');
assertBadge(badgeFor($html,'NT5')===['unknown','—'],'Stock tracking disabled must not fake a low-stock zero.');
assertBadge(badgeFor($html,'MX10')===['unknown','—'],'Supplier-only unmapped items have no ANKH fridge count.');
assertBadge(str_contains($html,'Combined available stock: 3 vials'),'Badge must have accessible combined-count label.');
assertBadge(!str_contains($html,'James 0 · Tony 3 in stock'),'Separate fridge figures must be removed from Build Order tiles.');
assertBadge(str_contains($html,'6+ vials')&&str_contains($html,'0–4 vials')&&str_contains($html,'5 vials'),'The colour key must explain all thresholds.');
assertBadge(str_contains($html,'data-box-sku="RT20"')&&str_contains($html,'id="sc-order-quick"'),'Quantity selectors and running basket totals must remain available.');
$css=file_get_contents(dirname(__DIR__).'/style.css');
assertBadge(str_contains($css,'.sc-order-stock-badge{position:absolute;top:10px;right:10px')&&str_contains($css,'.sc-order-stock-badge.is-good')&&str_contains($css,'.sc-order-stock-badge.is-low'),'Badges must appear in top-right of order tiles with correct colours.');
echo "PASS: combined stock circles handle 0, 3, 5 and 6 vials, net reservations, holding stock, untracked products and unchanged basket UX.\n";
