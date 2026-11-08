<?php
declare(strict_types=1);
// Exercise the production Holding stock helpers and POST handlers with an isolated SQLite database.
$source=file_get_contents(dirname(__DIR__).'/index.php');
function check(bool $ok,string $description):void{if(!$ok)throw new RuntimeException($description);}
$start=strpos($source,'function stockLocationName(');
$end=strpos($source,'function rememberStockUndo(',$start);
check($start!==false && $end!==false,'Stock helper block exists.');
eval(substr($source,$start,$end-$start));
function rememberStockUndo(int $productId,array $changes,array $movementIds,bool $trackingBefore,string $movementType,string $label):void{
 $_SESSION['stock_undo']=['product_id'=>$productId,'changes'=>$changes,'movement_ids'=>$movementIds];
}
$_SESSION=[];
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,stock_tracking INTEGER,stock_tracking_since TEXT,stock_jay_qty INTEGER,stock_tony_qty INTEGER,stock_unallocated_qty INTEGER,stock_qty INTEGER);
CREATE TABLE orders(id INTEGER PRIMARY KEY,status TEXT,delivery_date TEXT,back_order INTEGER NOT NULL DEFAULT 0);
CREATE TABLE stock_fulfilments(order_id INTEGER,product_id INTEGER,quantity INTEGER,location TEXT);
CREATE TABLE stock_movements(id INTEGER PRIMARY KEY,product_id INTEGER,movement_type TEXT,quantity_change INTEGER,order_id INTEGER,location TEXT,supplier TEXT,batch_reference TEXT,expiry_date TEXT,unit_cost INTEGER,note TEXT,created TEXT);
INSERT INTO products VALUES(1,'Retatrutide 20mg',1,'2026-10-01',0,0,25,25);
INSERT INTO products VALUES(2,'Holding reserved 10mg',1,'2026-10-01',0,0,5,5);
INSERT INTO products VALUES(3,'Empty holding 5mg',1,'2026-10-01',0,0,0,0);
INSERT INTO products VALUES(4,'Untracked holding',0,'',0,0,4,4);
INSERT INTO orders(id,status,delivery_date) VALUES(1,'Paid','');
INSERT INTO stock_fulfilments VALUES(1,2,3,'Unallocated');");

$fetchProduct=static function(int $id)use($db):array{return $db->query('SELECT * FROM products WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);};
$moves=static function(int $id)use($db):array{return $db->query('SELECT * FROM stock_movements WHERE product_id='.$id.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);};

$start=strpos($source,"  if(\$action==='stock_transfer'){");
$end=strpos($source,"  if(\$action==='stock_undo'){",$start);
check($start!==false&&$end!==false,'Stock transfer handler exists.');
$transferHandler=substr($source,$start,$end-$start);

$start=strpos($source,"  if(\$action==='stock_count'){");
$end=strpos($source,"  if(\$action==='stock_transfer'){",$start);
check($start!==false&&$end!==false,'Single-location stock correction handler exists.');
$countHandler=substr($source,$start,$end-$start);

$start=strpos($source,"  if(\$action==='stock_quick_adjust'){");
$end=strpos($source,"  if(\$action==='stock_fridge_counts'){",$start);
check($start!==false&&$end!==false,'Quick stock adjustment handler exists.');
$quickHandler=substr($source,$start,$end-$start);

// Move holding -> Jay through the existing stock_transfer action.
$action='stock_transfer';
$_POST=['product_id'=>'1','quantity'=>'10','from_location'=>'Unallocated','to_location'=>'Jay','note'=>'Assign to Jay','return'=>'stock','return_who'=>'holding'];
eval($transferHandler);
$row=$fetchProduct(1);
check((int)$row['stock_unallocated_qty']===15 && (int)$row['stock_jay_qty']===10,'Holding -> Jay must move 10 units between locations.');
check((int)$row['stock_qty']===25,'Aggregate stock must stay unchanged after a holding transfer.');
$productMoves=$moves(1);
check(count($productMoves)===2,'A holding transfer must write both out and in audit rows.');
check($productMoves[0]['movement_type']==='transfer_out' && (int)$productMoves[0]['quantity_change']===-10 && $productMoves[0]['location']==='Unallocated','The out movement must be recorded against holding.');
check($productMoves[1]['movement_type']==='transfer_in' && (int)$productMoves[1]['quantity_change']===10 && $productMoves[1]['location']==='Jay','The in movement must be recorded against Jay.');
check(($_SESSION['stock_undo']['changes']??null)===['Unallocated'=>10,'Jay'=>-10],'The holding transfer must be undoable.');

// Correct the holding count, keeping the same count_adjustment audit row type as the fridges.
$action='stock_count';
$_POST=['product_id'=>'1','counted_qty'=>'12','location'=>'Unallocated','note'=>'Shelf recount','return'=>'stock','return_who'=>'holding'];
eval($countHandler);
$row=$fetchProduct(1);
check((int)$row['stock_unallocated_qty']===12,'Correcting the holding count must set the counted quantity.');
check((int)$row['stock_qty']===22,'Aggregate stock must follow the corrected holding count.');
$productMoves=$moves(1);
$countMove=end($productMoves);
check($countMove['movement_type']==='count_adjustment' && (int)$countMove['quantity_change']===-3 && $countMove['location']==='Unallocated','A holding correction must write a count_adjustment audit row.');
check(str_contains((string)$countMove['note'],'Holding stock')&&str_contains((string)$countMove['note'],'reservations preserved'),'The correction note must identify holding and preserve reservations.');

// Quick adjust +/- on Unallocated must work and be audited.
$action='stock_quick_adjust';
$_POST=['product_id'=>'3','delta'=>'10','location'=>'Unallocated','return'=>'stock','return_who'=>'holding'];
eval($quickHandler);
$row=$fetchProduct(3);
check((int)$row['stock_unallocated_qty']===10,'+10 must add holding stock.');
$productMoves=$moves(3);
check(end($productMoves)['movement_type']==='manual_add' && (int)end($productMoves)['quantity_change']===10 && end($productMoves)['location']==='Unallocated','Quick holding additions must be audited at Unallocated.');
$_POST=['product_id'=>'3','delta'=>'-1','location'=>'Unallocated','return'=>'stock','return_who'=>'holding'];
eval($quickHandler);
check((int)$fetchProduct(3)['stock_unallocated_qty']===9,'-1 must remove holding stock.');

// Guards: quick removal cannot go below the available holding balance.
$db->exec('UPDATE products SET stock_unallocated_qty=0,stock_qty=0 WHERE id=3');
$_POST=['product_id'=>'3','delta'=>'-1','location'=>'Unallocated','return'=>'stock','return_who'=>'holding'];
$threw=false;try{eval($quickHandler);}catch(Exception $ex){$threw=str_contains($ex->getMessage(),'not enough available stock');}
check($threw,'Removing more holding stock than is available must be blocked.');

// Guards: a transfer cannot exceed the available holding quantity.
$action='stock_transfer';
$_POST=['product_id'=>'2','quantity'=>'6','from_location'=>'Unallocated','to_location'=>'Tony','return'=>'stock','return_who'=>'holding'];
$threw=false;try{eval($transferHandler);}catch(Exception $ex){$threw=str_contains($ex->getMessage(),'not enough stock');}
check($threw,'A holding transfer must not exceed the available holding quantity.');

// Reserved-from-holding is protected the same way as the fridges: the physical count is
// recorded, reservations stay intact, and the shortfall is expressed as negative available.
$action='stock_count';
$_POST=['product_id'=>'2','counted_qty'=>'2','location'=>'Unallocated','note'=>'Short on shelf','return'=>'stock','return_who'=>'holding'];
eval($countHandler);
$row=$fetchProduct(2);
check((int)$row['stock_unallocated_qty']===-1,'A holding count below reserved must record the shortfall, not silently change reservations.');
check((int)$row['stock_unallocated_qty']+activeReservedStock($db,2,'Unallocated')===2,'Holding physical count must equal the counted quantity.');
check((int)$db->query("SELECT COUNT(*) FROM stock_fulfilments WHERE product_id=2")->fetchColumn()===1,'Holding count corrections must preserve reservations.');
$productMoves=$moves(2);
check(end($productMoves)['movement_type']==='count_adjustment' && (int)end($productMoves)['quantity_change']===-6,'Below-reservation holding corrections must be audited.');
check(str_contains((string)end($productMoves)['note'],'reservations preserved'),'Below-reservation holding corrections must say reservations were preserved.');

// Guards: untracked products cannot be counted or transferred as holding stock.
$action='stock_count';$_POST=['product_id'=>'4','counted_qty'=>'1','location'=>'Unallocated'];
$threw=false;try{eval($countHandler);}catch(Exception $ex){$threw=str_contains($ex->getMessage(),'Confirm an opening fridge count first');}
check($threw,'Untracked products must not accept a holding count.');
$action='stock_transfer';$_POST=['product_id'=>'4','quantity'=>'1','from_location'=>'Unallocated','to_location'=>'Jay'];
$threw=false;try{eval($transferHandler);}catch(Exception $ex){$threw=str_contains($ex->getMessage(),'Confirm an opening fridge count first');}
check($threw,'Untracked products must not be transferred from holding.');

// Presentation: fourth switch option, forms return to Holding, and cache version bumped.
check(str_contains($source,'?view=stock&amp;who=holding'),'The stock switch must include a Holding option.');
check(str_contains($source,'name="return_who" value="holding"'),'Holding forms must return to the Holding tab.');
check(str_contains($source,'>Holding</a>'),'The Holding switch label must be visible.');
check(str_contains($source,'Open Holding stock to assign them'),'The All tab holding note must link to the Holding tab.');
check(str_contains($source,'Counted holding qty'),'Holding cards must offer a count correction field.');
check(str_contains($source,'from_location" value="Unallocated"'),'Holding move forms must transfer from Unallocated.');
$sw=file_get_contents(dirname(__DIR__).'/service-worker.js');
check((bool)preg_match("/ankh-shell-v[0-9]+-/",$sw),"The service worker cache name must be versioned.");
$css=file_get_contents(dirname(__DIR__).'/style.css');
check(str_contains($css,'.holding-item-card')&&str_contains($css,'.fridge-person-holding'),'Holding tab styles must exist.');
check(str_contains($css,'.stock-who-switch{grid-template-columns:repeat(4'),'The stock switch must lay out four options.');
echo "PASS: holding -> fridge moves, holding count corrections, quick adjustments, reservation guards and audit rows.\n";
