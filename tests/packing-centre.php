<?php
declare(strict_types=1);
require dirname(__DIR__).'/packing-centre-support.php';
function checkPC(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function deniedPC(callable $fn):void{$denied=false;try{$fn();}catch(RuntimeException $e){$denied=true;}checkPC($denied,'Expected operation to be rejected.');}
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA foreign_keys=ON');
$db->exec("CREATE TABLE orders(id INTEGER PRIMARY KEY,customer TEXT,recipient_name TEXT DEFAULT '',phone TEXT DEFAULT '',address TEXT DEFAULT '',notes TEXT DEFAULT '',status TEXT DEFAULT 'New',created TEXT DEFAULT '2026-11-06',back_order INTEGER DEFAULT 0,delivery_date TEXT DEFAULT '',delivery_charge INTEGER DEFAULT 0,scheduled_delivery_date TEXT DEFAULT '',delivery_method TEXT DEFAULT '');
 CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER REFERENCES orders(id),product_id INTEGER,name TEXT,quantity INTEGER,presentation TEXT,price INTEGER);
 CREATE TABLE payments(id INTEGER PRIMARY KEY,order_id INTEGER,amount INTEGER);
 CREATE TABLE products(id INTEGER PRIMARY KEY,stock_qty INTEGER);
 INSERT INTO products VALUES(1,0);
 INSERT INTO orders(id,customer,recipient_name,address,notes,back_order) VALUES(1,'Buyer','Recipient <test>','A Street','Pack & check',0),(2,'Waiting customer','','B Street','',1),(3,'Other customer','','C Street','',0),(4,'Closed','','D Street','',0);
 UPDATE orders SET status='Delivered',delivery_date='2026-11-06' WHERE id=4;
 INSERT INTO items VALUES(1,1,1,'Product 10mg',2,'Vial',100),(2,2,1,'Product 10mg',3,'Vial',100),(3,2,1,'Product 10mg',1,'Cartridge',100),(4,3,2,'Product 20mg',1,'Vial',200),(5,4,1,'Product 10mg',1,'Vial',100);
 INSERT INTO payments VALUES(1,2,100);");
ankhPackingCentreSchema($db);ankhPackingCentreSchema($db);
$snapshot=static function()use($db){$out=[];foreach(['orders','items','payments','products'] as $t)$out[$t]=$db->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC);return $out;};$before=$snapshot();
$run=ankhPackingCentreCreate($db,[1,2,1],'Test run','Jay','Run notes',str_repeat('a',32),7);
checkPC($run>0,'Run saved');checkPC(count(ankhPackingCentreRun($db,$run)['orders'])===2,'Unique active and back orders saved at zero stock');
checkPC(ankhPackingCentreCreate($db,[1,2],'Test run','Jay','Run notes',str_repeat('a',32),7)===$run,'Repeated submission is idempotent');
deniedPC(fn()=>ankhPackingCentreCreate($db,[3,1],'Conflict','Tony','',str_repeat('b',32)));
checkPC((int)$db->query('SELECT COUNT(*) FROM packing_runs')->fetchColumn()===1,'Conflict must roll back whole run');
checkPC((int)$db->query('SELECT COUNT(*) FROM packing_run_claims WHERE order_id=3')->fetchColumn()===0,'Conflict must not leave claims');
deniedPC(fn()=>ankhPackingCentreCreate($db,[4],'Closed','Tony','',str_repeat('c',32)));
deniedPC(fn()=>ankhPackingCentreCreate($db,[999],'Missing','Tony','',str_repeat('d',32)));
deniedPC(fn()=>ankhPackingCentreCreate($db,[],'Empty','Tony','',str_repeat('e',32)));
$loaded=ankhPackingCentreRun($db,$run);$totals=ankhPackingCentreTotals($loaded['orders']);
checkPC(count($totals)===2,'Presentation must be grouped separately');
$vials=array_values(array_filter($totals,fn($t)=>$t['presentation']==='Vial'))[0];checkPC($vials['quantity']===5&&count($vials['orders'])===2,'Picking totals must sum selected orders');
checkPC(!$loaded['orders'][0]['changed'],'Fresh run signatures match');
$auth=true;foreach(['pick','slips','labels'] as $kind){$_GET=['run'=>$run,'print'=>$kind];ob_start();include dirname(__DIR__).'/packing-centre-print.php';$html=ob_get_clean();checkPC(str_contains($html,'Recipient &lt;test&gt;'),'Print recipient and escape text');checkPC(!str_contains($html,'Recipient <test>'),'No unescaped recipient');checkPC(str_contains($html,'window.print()'),'Browser printing available');}
checkPC($snapshot()===$before,'Create, read and print must not mutate orders, payments or stock');
$db->exec('UPDATE items SET quantity=4 WHERE id=1');checkPC(ankhPackingCentreRun($db,$run)['orders'][0]['changed'],'Edited orders must be flagged');
ankhPackingCentreUpdate($db,$run,'remove',1);checkPC(count(ankhPackingCentreRun($db,$run)['orders'])===1,'Release removes only chosen order');
$new=ankhPackingCentreCreate($db,[1],'Next run','Tony','',str_repeat('f',32));checkPC($new!==$run,'Released order can be claimed');
ankhPackingCentreUpdate($db,$run,'completed');checkPC((int)$db->query('SELECT COUNT(*) FROM packing_run_claims WHERE order_id=2')->fetchColumn()===0,'Finish releases claims');checkPC(count(ankhPackingCentreRun($db,$run)['orders'])===1,'Finish preserves history');
deniedPC(fn()=>ankhPackingCentreUpdate($db,$run,'remove',2));
ankhPackingCentreUpdate($db,$new,'cancelled');checkPC((int)$db->query('SELECT COUNT(*) FROM packing_run_claims')->fetchColumn()===0,'Cancel releases claims');
// Pen is a physical accessory; the peptide itself always goes in a cartridge.
$packingLine=static fn($name,$qty,$format,$product=1)=>['name'=>$name,'quantity'=>$qty,'presentation'=>$format,'product_id'=>$product];
$penLines=[$packingLine('Retatrutide 10mg',10,'Pen'),$packingLine('Retatrutide 10mg',7,'Cartridge'),$packingLine('Pen',10,'',99)];
$copy=$penLines;$physical=ankhPackingCentreLines($penLines);$combined=ankhPackingCentreTotals([['id'=>90,'lines'=>$penLines]]);
checkPC($copy===$penLines,'Packing normalization must preserve original saved lines');
checkPC(count($combined)===2,'Pen peptides and cartridge refills must combine, devices separate');
$cartridges=array_values(array_filter($combined,fn($t)=>$t['name']==='Retatrutide 10mg'))[0];
$devices=array_values(array_filter($combined,fn($t)=>$t['name']==='Pen devices'))[0];
checkPC($cartridges['quantity']===17&&$cartridges['presentation']==='Cartridge','10 pen selections + 7 refills = 17 cartridges');
checkPC($devices['quantity']===10&&$devices['presentation']==='Device','Existing pen rows must not be counted twice');
checkPC(ankhPackingCentrePenNote($penLines)==='','Matched device rows need no warning');
$withExtras=[...$penLines,$packingLine(' pen ',2,'Pen',99)];
checkPC(array_values(array_filter(ankhPackingCentreLines($withExtras),fn($l)=>$l['name']==='Pen devices'))[0]['quantity']===12,'Standalone extra pens must stay included');
$legacy=[$packingLine('Retatrutide 10mg',2,'Pen')];
checkPC(ankhPackingCentreLines($legacy)[1]['quantity']===2&&ankhPackingCentrePenNote($legacy)!=='','Legacy bundled pen orders need devices and an explanatory note');
checkPC(count(ankhPackingCentreLines([$packingLine('Retatrutide 10mg',3,'Cartridge')]))===1,'Refills must not add pen devices');
checkPC(count(ankhPackingCentreLines([$packingLine('Penicillin',1,'Vial')]))===1,'Do not treat other product names as pen devices');
checkPC(ankhPackingCentreLines([$packingLine('Pen',3,'',99)])[0]['quantity']===3,'Pen-only accessory orders remain valid');
$partial=[$packingLine('Retatrutide 10mg',3,'Pen'),$packingLine('Pen',1,'',99)];
checkPC(str_contains(ankhPackingCentrePenNote($partial),'Check pen quantity'),'Partial device records must be flagged, not silently fabricated');
$twoOrders=ankhPackingCentreTotals([['id'=>91,'lines'=>[$packingLine('Pen',5,'',99)]],['id'=>92,'lines'=>$legacy]]);
checkPC(array_values(array_filter($twoOrders,fn($t)=>$t['name']==='Pen devices'))[0]['quantity']===7,'Another customer’s standalone devices must not suppress legacy pen devices');
echo "PASS: additive schema, mixed orders, zero stock/unpaid, totals/presentation, idempotence, atomic claim conflicts, print escaping, stale edits, release/finish/cancel and no operational mutations.\n";
