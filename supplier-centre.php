<?php
declare(strict_types=1);

/**
 * ANKH Supplier & Profit Centre.
 * Supplier prices are uploaded by an authenticated admin and stored ONLY in SQLite.
 * NEVER publish the supplier price CSV or its contents in the public repository.
 */
function sc_setup(PDO $db):void{
 $db->exec("CREATE TABLE IF NOT EXISTS supplier_catalogue (
  sku TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  strength TEXT NOT NULL,
  pack_size INTEGER NOT NULL DEFAULT 10 CHECK(pack_size>0),
  box_usd_cents INTEGER NOT NULL CHECK(box_usd_cents>=0),
  product_id INTEGER UNIQUE REFERENCES products(id) ON DELETE SET NULL,
  updated TEXT NOT NULL DEFAULT ''
 );
 CREATE TABLE IF NOT EXISTS supplier_basket_drafts (
  id INTEGER PRIMARY KEY CHECK(id=1),
  boxes_json TEXT NOT NULL DEFAULT '{}',
  shipping_gbp TEXT NOT NULL DEFAULT '0',
  expected_date TEXT NOT NULL DEFAULT '',
  updated TEXT NOT NULL
 );
 CREATE TABLE IF NOT EXISTS supplier_price_history (
  id INTEGER PRIMARY KEY,
  sku TEXT NOT NULL,
  old_usd_cents INTEGER NOT NULL,
  new_usd_cents INTEGER NOT NULL,
  changed TEXT NOT NULL
 );
 CREATE TABLE IF NOT EXISTS supplier_purchase_snapshots (
  supplier_order_id INTEGER PRIMARY KEY REFERENCES supplier_orders(id) ON DELETE CASCADE,
  usd_total_cents INTEGER NOT NULL,
  fx_rate REAL NOT NULL,
  shipping_gbp_pence INTEGER NOT NULL DEFAULT 0,
  estimated_gbp_pence INTEGER NOT NULL,
  order_message TEXT NOT NULL,
  created TEXT NOT NULL
 );
 CREATE TABLE IF NOT EXISTS supplier_purchase_lines (
  supplier_order_id INTEGER NOT NULL REFERENCES supplier_orders(id) ON DELETE CASCADE,
  sku TEXT NOT NULL,
  product_name TEXT NOT NULL,
  strength TEXT NOT NULL,
  packs INTEGER NOT NULL,
  units_per_pack INTEGER NOT NULL,
  box_usd_cents INTEGER NOT NULL,
  PRIMARY KEY(supplier_order_id,sku)
 );");
}
function sc_money_usd(int $cents):string{return '$'.number_format($cents/100,2);}
function sc_normal_name(string $name):string{return preg_replace('/[^a-z0-9]/','',strtolower($name));}
function sc_match_products(PDO $db):void{
 $products=$db->query("SELECT id,name FROM products WHERE active=1")->fetchAll(PDO::FETCH_ASSOC);
 $byName=[];
 foreach($products as $product){$key=sc_normal_name((string)$product['name']);$byName[$key][]=(int)$product['id'];}
 $rows=$db->query('SELECT sku,name,strength FROM supplier_catalogue WHERE product_id IS NULL')->fetchAll(PDO::FETCH_ASSOC);
 $countById=[];
 $suggestions=[];
 foreach($rows as $row){
  $key=sc_normal_name($row['name'].' '.$row['strength']);$matches=$byName[$key]??[];
  if(count($matches)!==1)continue;
  $id=$matches[0];$countById[$id]=($countById[$id]??0)+1;
  $suggestions[$row['sku']]=$id;
 }
 $save=$db->prepare('UPDATE supplier_catalogue SET product_id=? WHERE sku=? AND product_id IS NULL');
 foreach($suggestions as $sku=>$id){
  // Never assign two supplier SKUs to one product, or overwrite a manual mapping.
  if($countById[$id]!==1)continue;
  $q=$db->prepare('SELECT count(*) FROM supplier_catalogue WHERE product_id=?');$q->execute([$id]);
  if((int)$q->fetchColumn()>0)continue;
  $save->execute([$id,$sku]);
 }
}
function sc_fx(PDO $db):?float{
 $q=$db->query("SELECT value FROM settings WHERE key='supplier_usd_gbp_rate'");
 $value=trim((string)($q->fetchColumn()?:''));
 if($value===''||!is_numeric($value))return null;
 $fx=(float)$value;
 return $fx>0&&$fx<10?$fx:null;
}
function sc_save_setting(PDO $db,string $key,string $value):void{
 $db->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute([$key,$value]);
}
function sc_post(PDO $db,string $action):void{
 if($action==='supplier_centre_import'){
  $file=$_FILES['supplier_csv']??null;
  if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))throw new Exception('Choose the supplier CSV file and try again.');
  if((int)($file['size']??0)<10||(int)$file['size']>300000)throw new Exception('Supplier file must be a CSV under 300 KB.');
  $handle=fopen($file['tmp_name'],'rb');if(!$handle)throw new Exception('Supplier CSV could not be read.');
  try{
   $columns=fgetcsv($handle);if(!$columns)throw new Exception('Supplier CSV is empty.');
   $columns=array_map(static fn($v)=>strtolower(trim(preg_replace('/^\xEF\xBB\xBF/','',(string)$v))),$columns);
   if($columns!==['sku','name','strength','pack_size','box_usd'])throw new Exception('Use the supplied CSV with columns sku,name,strength,pack_size,box_usd.');
   $rows=[];$seen=[];
   while(($row=fgetcsv($handle))!==false){
    if(count($row)===1&&trim((string)$row[0])==='')continue;
    if(count($row)!==5)throw new Exception('A supplier CSV row has the wrong number of columns.');
    [$sku,$name,$strength,$pack,$usd]=array_map('trim',$row);$sku=strtoupper($sku);
    if(!preg_match('/^[A-Z0-9-]{1,20}$/',$sku)||$name===''||strlen($name)>500||!preg_match('/^\d+(?:\.\d+)?(?:mg|ml|iu)$/i',$strength))throw new Exception('Invalid supplier product code, name or strength on row '.(count($rows)+2).'.');
    if(isset($seen[$sku]))throw new Exception('Duplicate supplier code '.$sku.'.');
    $packQty=filter_var($pack,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>500]]);
    if($packQty===false||!preg_match('/^\d+(?:\.\d{1,2})?$/',$usd)||floatval($usd)>100000)throw new Exception('Invalid pack size or box price for '.$sku.'.');
    $rows[]=[$sku,$name,strtolower($strength),(int)$packQty,(int)round(((float)$usd)*100)];$seen[$sku]=true;
    if(count($rows)>500)throw new Exception('The supplier file contains too many rows.');
   }
   if(!$rows)throw new Exception('No supplier products found.');
  }finally{fclose($handle);}
  $db->beginTransaction();
  try{
   $old=$db->prepare('SELECT box_usd_cents FROM supplier_catalogue WHERE sku=?');
   $upsert=$db->prepare('INSERT INTO supplier_catalogue(sku,name,strength,pack_size,box_usd_cents,updated) VALUES (?,?,?,?,?,?) ON CONFLICT(sku) DO UPDATE SET name=excluded.name,strength=excluded.strength,pack_size=excluded.pack_size,box_usd_cents=excluded.box_usd_cents,updated=excluded.updated');
   $history=$db->prepare('INSERT INTO supplier_price_history(sku,old_usd_cents,new_usd_cents,changed) VALUES (?,?,?,?)');
   $now=gmdate('c');
   foreach($rows as [$sku,$name,$strength,$pack,$usdCents]){
    $old->execute([$sku]);$previous=$old->fetchColumn();
    if($previous!==false && (int)$previous!==$usdCents)$history->execute([$sku,(int)$previous,$usdCents,$now]);
    $upsert->execute([$sku,$name,$strength,$pack,$usdCents,$now]);
   }
   sc_match_products($db);
   sc_save_setting($db,'supplier_import_last',$now);
   $db->commit();
  }catch(Throwable $err){if($db->inTransaction())$db->rollBack();throw $err;}
  $_SESSION['flash']=count($rows).' MJT supplier prices imported; existing product links preserved.';
  header('Location: ./?view=supplier&tab=profit');exit;
 }
 if($action==='supplier_centre_config'){
  $rate=trim((string)($_POST['usd_gbp_rate']??''));
  $shipping=trim((string)($_POST['shipping_per_box']??'0'));
  if($rate!==''&&(!is_numeric($rate)||(float)$rate<=0||(float)$rate>=10))throw new Exception('Enter GBP per 1 USD, such as 0.78.');
  if(!preg_match('/^\d+(?:\.\d{1,2})?$/',$shipping)||(float)$shipping>10000)throw new Exception('Enter a valid GBP shipping estimate per box.');
  sc_save_setting($db,'supplier_usd_gbp_rate',$rate);
  sc_save_setting($db,'supplier_shipping_per_box_pence',(string)(int)round((float)$shipping*100));
  $_SESSION['flash']='Supplier exchange-rate and shipping estimates saved.';
  header('Location: ./?view=supplier&tab=profit');exit;
 }
 if($action==='supplier_centre_map'){
  $sku=strtoupper(trim((string)($_POST['sku']??'')));
  $raw=trim((string)($_POST['product_id']??''));
  if(!preg_match('/^[A-Z0-9-]{1,20}$/',$sku))throw new Exception('Unknown supplier product.');
  $id=$raw===''?null:filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if($id===false)throw new Exception('Choose an ANKH product or leave it unmapped.');
  $q=$db->prepare('SELECT count(*) FROM supplier_catalogue WHERE sku=?');$q->execute([$sku]);if(!(int)$q->fetchColumn())throw new Exception('Supplier SKU not found.');
  if($id!==null){
   $q=$db->prepare('SELECT count(*) FROM products WHERE id=?');$q->execute([$id]);if(!(int)$q->fetchColumn())throw new Exception('ANKH product not found.');
   $q=$db->prepare('SELECT sku FROM supplier_catalogue WHERE product_id=? AND sku<>?');$q->execute([$id,$sku]);
   if($q->fetchColumn()!==false)throw new Exception('That ANKH product is already linked to another supplier SKU. Remove the old link first.');
  }
  $db->prepare('UPDATE supplier_catalogue SET product_id=? WHERE sku=?')->execute([$id,$sku]);
  $_SESSION['flash']='Supplier product mapping saved.';
  header('Location: ./?view=supplier&tab=products');exit;
 }
 if($action==='supplier_centre_price'){
  $sku=strtoupper(trim((string)($_POST['sku']??'')));$box=trim((string)($_POST['box_usd']??''));
  if(!preg_match('/^[A-Z0-9-]{1,20}$/',$sku)||!preg_match('/^\d+(?:\.\d{1,2})?$/',$box)||(float)$box>100000)throw new Exception('Enter a valid USD box price.');
  $newCents=(int)round((float)$box*100);
  $q=$db->prepare('SELECT box_usd_cents FROM supplier_catalogue WHERE sku=?');$q->execute([$sku]);$old=$q->fetchColumn();
  if($old===false)throw new Exception('Supplier product does not exist.');
  $db->beginTransaction();
  try{
   if((int)$old!==$newCents)$db->prepare('INSERT INTO supplier_price_history(sku,old_usd_cents,new_usd_cents,changed) VALUES (?,?,?,?)')->execute([$sku,(int)$old,$newCents,gmdate('c')]);
   $db->prepare('UPDATE supplier_catalogue SET box_usd_cents=?,updated=? WHERE sku=?')->execute([$newCents,gmdate('c'),$sku]);$db->commit();
  }catch(Throwable $err){if($db->inTransaction())$db->rollBack();throw $err;}
  $_SESSION['flash']='Supplier USD box price updated. Past orders are unchanged.';
  header('Location: ./?view=supplier&tab=products');exit;
 }
 if($action==='supplier_centre_place'){
  $boxes=$_POST['boxes']??[];if(!is_array($boxes))throw new Exception('Choose supplier boxes.');
  $requested=[];foreach($boxes as $sku=>$quantity){
   if(!is_string($sku)||!preg_match('/^[A-Z0-9-]{1,20}$/',$sku))throw new Exception('Invalid supplier code.');
   $qty=filter_var($quantity,FILTER_VALIDATE_INT);
   if($qty===false||$qty<0||$qty>100)throw new Exception('Select 0–100 full boxes per product.');
   if($qty>0)$requested[$sku]=(int)$qty;
  }
  if(($_POST['supplier_intent']??'')==='draft'){
   $shipDraft=trim((string)($_POST['shipping_gbp']??'0'));
   $expectedDraft=trim((string)($_POST['expected_date']??''));
   if(!preg_match('/^\d+(?:\.\d{1,2})?$/',$shipDraft)||(float)$shipDraft>100000)throw new Exception('Enter valid shipping for the draft.');
   if($expectedDraft!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expectedDraft)||!checkdate((int)substr($expectedDraft,5,2),(int)substr($expectedDraft,8,2),(int)substr($expectedDraft,0,4))))throw new Exception('Enter a valid expected date.');
   $db->prepare("INSERT INTO supplier_basket_drafts(id,boxes_json,shipping_gbp,expected_date,updated) VALUES (1,?,?,?,?) ON CONFLICT(id) DO UPDATE SET boxes_json=excluded.boxes_json,shipping_gbp=excluded.shipping_gbp,expected_date=excluded.expected_date,updated=excluded.updated")->execute([json_encode($requested,JSON_THROW_ON_ERROR),$shipDraft,$expectedDraft,gmdate('c')]);
   $_SESSION['flash']='Shared supplier basket draft saved for James and Tony.';
   header('Location: ./?view=supplier&tab=order');exit;
  }
  if(!$requested||count($requested)>100)throw new Exception('Select at least one mapped supplier product.');
  $fx=sc_fx($db);
  if($fx===null)throw new Exception('Save an exchange rate first, so stock costs can be recorded in GBP.');
  $ship=trim((string)($_POST['shipping_gbp']??'0'));
  if(!preg_match('/^\d+(?:\.\d{1,2})?$/',$ship)||(float)$ship>100000)throw new Exception('Enter shipping in GBP.');
  $shippingPence=(int)round((float)$ship*100);
  $expected=trim((string)($_POST['expected_date']??''));
  if($expected!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expected)||!checkdate((int)substr($expected,5,2),(int)substr($expected,8,2),(int)substr($expected,0,4))))throw new Exception('Enter a valid expected delivery date.');
  $fetch=$db->prepare('SELECT s.*,p.id AS mapped_id,p.name AS mapped_name FROM supplier_catalogue s LEFT JOIN products p ON p.id=s.product_id WHERE s.sku=?');
  $lines=[];$totalUsd=0;$totalUnits=0;
  foreach($requested as $sku=>$qty){
   $fetch->execute([$sku]);$item=$fetch->fetch(PDO::FETCH_ASSOC);
   if(!$item||!$item['mapped_id'])throw new Exception('Map '.$sku.' to an ANKH product before recording a purchase.');
   $cost=(int)$item['box_usd_cents']*$qty;
   $totalUsd+=$cost;$totalUnits+=(int)$item['pack_size']*$qty;
   $lines[]=['sku'=>$sku,'name'=>$item['name'],'strength'=>$item['strength'],'product_id'=>(int)$item['mapped_id'],'packs'=>$qty,'pack_size'=>(int)$item['pack_size'],'box_usd_cents'=>(int)$item['box_usd_cents'],'usd_total'=>$cost];
  }
  $message=sc_supplier_message($lines,$totalUsd);
  $db->beginTransaction();
  try{
   $db->prepare("INSERT INTO supplier_orders(supplier,expected_date,status,note,created) VALUES (?,?, 'Ordered', ?, ?)")->execute(['MJT',$expected,'Combined James + Tony order from Supplier Centre',gmdate('c')]);
   $orderId=(int)$db->lastInsertId();
   $lineInsert=$db->prepare('INSERT INTO supplier_order_items(supplier_order_id,product_id,packs,pack_size,quantity,unit_cost,received_qty) VALUES (?,?,?,?,?,?,0)');
   $snap=$db->prepare('INSERT INTO supplier_purchase_lines(supplier_order_id,sku,product_name,strength,packs,units_per_pack,box_usd_cents) VALUES (?,?,?,?,?,?,?)');
   foreach($lines as $line){
    $units=$line['packs']*$line['pack_size'];
    // Shipping is allocated in proportion to the supplier value on each line.
    $lineShipping=$totalUsd>0?(int)round($shippingPence*$line['usd_total']/$totalUsd):(int)round($shippingPence*$units/$totalUnits);
    $unitCost=(int)round(($line['usd_total']*$fx+$lineShipping)/$units);
    $lineInsert->execute([$orderId,$line['product_id'],$line['packs'],$line['pack_size'],$units,$unitCost]);
    $snap->execute([$orderId,$line['sku'],$line['name'],$line['strength'],$line['packs'],$line['pack_size'],$line['box_usd_cents']]);
   }
   $estimate=(int)round($totalUsd*$fx)+$shippingPence;
   $db->prepare('INSERT INTO supplier_purchase_snapshots(supplier_order_id,usd_total_cents,fx_rate,shipping_gbp_pence,estimated_gbp_pence,order_message,created) VALUES (?,?,?,?,?,?,?)')->execute([$orderId,$totalUsd,$fx,$shippingPence,$estimate,$message,gmdate('c')]);
   $db->prepare('DELETE FROM supplier_basket_drafts WHERE id=1')->execute();
   $db->commit();
  }catch(Throwable $err){if($db->inTransaction())$db->rollBack();throw $err;}
  $_SESSION['flash']='MJT purchase recorded in Incoming Stock; no inventory has been added yet.';
  header('Location: ./?view=stock&section=incoming');exit;
 }
 throw new Exception('Unknown supplier action.');
}
function sc_supplier_message(array $lines,int $totalUsd):string{
 $text="Hi, please can I order the following from MJT:\n\n";
 foreach($lines as $line){
  $boxes=(int)$line['packs'];$vials=$boxes*(int)$line['pack_size'];
  $text.=$line['sku'].' — '.$line['name'].' '.$line['strength'].': '.$boxes.' box'.($boxes===1?'':'es').' ('.$vials.' vials)'."\n";
 }
 $text.="\nProduct total: ".sc_money_usd($totalUsd)." USD, excluding shipping.\nPlease confirm stock availability, final shipping cost and delivery timeframe. Thank you.";
 return $text;
}
function sc_render(PDO $db,array $products,array $reservedByProduct,array $incomingByProduct):void{
 $tab=(string)($_GET['tab']??'profit');if(!in_array($tab,['profit','order','products','incoming'],true))$tab='profit';
 $catalogue=$db->query("SELECT s.*,p.name AS ankh_name,p.price AS retail,p.family_friends_price AS friends,p.stock_tracking,p.stock_jay_qty,p.stock_tony_qty,p.stock_unallocated_qty FROM supplier_catalogue s LEFT JOIN products p ON p.id=s.product_id ORDER BY s.name COLLATE NOCASE,s.strength COLLATE NOCASE,s.sku")->fetchAll(PDO::FETCH_ASSOC);
 $allProducts=$db->query('SELECT id,name,active FROM products ORDER BY active DESC,name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
 $fx=sc_fx($db);
 $shippingCents=(int)($db->query("SELECT value FROM settings WHERE key='supplier_shipping_per_box_pence'")->fetchColumn()?:0);
 $lastImport=(string)($db->query("SELECT value FROM settings WHERE key='supplier_import_last'")->fetchColumn()?:'');
 $draftRow=$db->query('SELECT boxes_json,shipping_gbp,expected_date FROM supplier_basket_drafts WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];
 $draft=json_decode((string)($draftRow['boxes_json']??'{}'),true);
 if(!is_array($draft))$draft=[];
 $mapped=count(array_filter($catalogue,fn($r)=>$r['product_id']!==null));
 $orderUnits=$db->query("SELECT i.product_id,COALESCE(SUM(i.quantity),0) qty FROM items i JOIN orders o ON o.id=i.order_id WHERE i.product_id IS NOT NULL AND o.status IN ('Paid','Packed','Dispatched','Delivered') AND datetime(o.created)>=datetime('now','-30 days') GROUP BY i.product_id")->fetchAll(PDO::FETCH_KEY_PAIR);
 $rows=[];
 foreach($catalogue as $item){
  $pack=(int)$item['pack_size'];$usd=(int)$item['box_usd_cents'];
  $mappedId=$item['product_id']===null?null:(int)$item['product_id'];
  $estimate=$fx===null?null:((int)round($usd*$fx)+$shippingCents)/$pack;
  $price=$item['retail']===null?null:(int)$item['retail'];
  $friends=$price===null?null:($item['friends']===null?max(0,$price-500):(int)$item['friends']);
  $profit=$estimate===null||$price===null?null:$price-$estimate;
  $margin=$profit===null||$price<=0?null:$profit/$price*100;
  $jay=(int)($item['stock_jay_qty']??0);$tony=(int)($item['stock_tony_qty']??0);
  $jayReserved=$mappedId===null?0:(int)($reservedByProduct[$mappedId]['jay']??0);
  $tonyReserved=$mappedId===null?0:(int)($reservedByProduct[$mappedId]['tony']??0);
  $rows[]=['sku'=>(string)$item['sku'],'name'=>(string)$item['name'],'strength'=>(string)$item['strength'],'pack'=>$pack,'usd'=>$usd,'product_id'=>$mappedId,
   'ankh_name'=>(string)($item['ankh_name']??''),'retail'=>$price,'friends'=>$friends,'estimate'=>$estimate,'profit'=>$profit,'margin'=>$margin,
   'jay_physical'=>$jay+$jayReserved,'tony_physical'=>$tony+$tonyReserved,'jay_available'=>max(0,$jay),'tony_available'=>max(0,$tony),
   'reserved'=>$jayReserved+$tonyReserved,'incoming'=>$mappedId===null?0:(int)($incomingByProduct[$mappedId]??0),
   'sold30'=>$mappedId===null?0:(int)($orderUnits[$mappedId]??0)];
 }
 $matchedRows=array_values(array_filter($rows,fn($r)=>$r['product_id']!==null));
 $profitRows=$rows;
 usort($profitRows,fn($a,$b)=>(($b['margin']??-999999)<=>($a['margin']??-999999)) ?: strcasecmp($a['name'].' '.$a['strength'],$b['name'].' '.$b['strength']));?>
<div class="heading sc-heading"><div><p class="eyebrow">ANKH / SUPPLIER CENTRE</p><h1>Supplier &amp; Profit</h1><p class="muted page-description">Compare costs, build combined MJT orders and split stock after delivery.</p></div></div>
<div class="sc-tabs" role="navigation" aria-label="Supplier Centre sections">
 <a href="?view=supplier&amp;tab=profit" <?=$tab==='profit'?'aria-current="page"':''?>>Profit</a>
 <a href="?view=supplier&amp;tab=order" <?=$tab==='order'?'aria-current="page"':''?>>Build order</a>
 <a href="?view=supplier&amp;tab=products" <?=$tab==='products'?'aria-current="page"':''?>>Prices &amp; matching</a>
 <a href="?view=supplier&amp;tab=incoming" <?=$tab==='incoming'?'aria-current="page"':''?>>Incoming</a>
</div>
<div class="sc-overview">
 <div><small>Supplier products</small><strong><?=count($rows)?></strong></div>
 <div><small>Matched to ANKH</small><strong><?=$mapped?></strong></div>
 <div><small>Awaiting matching</small><strong><?=count($rows)-$mapped?></strong></div>
</div>
<?php if($tab==='profit'):?>
<?php if(!$catalogue):?><section class="panel sc-empty"><h2>Start with the MJT supplier price import</h2><p>Upload the August 2026 supplier CSV through Prices &amp; matching. Wholesale costs are stored privately in ANKH Admin, never in the public code repository.</p><a class="quick-action" href="?view=supplier&amp;tab=products">Import supplier prices →</a></section><?php else:?>
<section class="panel sc-panel"><div class="sc-panel-heading"><div><p class="eyebrow">MARGIN ANALYSIS</p><h2>Profit explorer</h2><p>Estimated profit before overheads, based on the current supplier price and your saved GBP/USD rate.</p></div></div>
<?php if($fx===null):?><p class="sc-notice">Set your GBP per USD exchange rate in Prices &amp; matching to calculate estimated GBP margins. Supplier USD costs are available now.</p><?php endif;?>
<div class="sc-filters"><input type="search" id="sc-profit-search" placeholder="Search product or supplier code…" aria-label="Search supplier products"><select id="sc-profit-sort" aria-label="Sort supplier products"><option value="margin">Highest margin %</option><option value="profit">Highest profit / vial</option><option value="sold">Most ordered (30d)</option><option value="stock">Most available</option><option value="name">Product name</option></select></div>
<div class="sc-product-list" id="sc-profit-list">
<?php foreach($profitRows as $item):$availability=$item['jay_available']+$item['tony_available'];?>
<article class="sc-profit-card" data-search="<?=e(strtolower($item['sku'].' '.$item['name'].' '.$item['strength'].' '.$item['ankh_name']))?>" data-margin="<?=e((string)($item['margin']??-999999))?>" data-profit="<?=e((string)($item['profit']??-999999))?>" data-sold="<?=$item['sold30']?>" data-stock="<?=$availability?>" data-name="<?=e(strtolower($item['name'].' '.$item['strength']))?>">
 <div class="sc-profit-head"><div><small><?=e($item['sku'])?> · <?=e($item['pack'])?> vials/box</small><h3><?=e($item['name'].' '.$item['strength'])?></h3></div><span class="sc-margin-label <?=$item['margin']!==null&&$item['margin']<0?'loss':''?>"><?=$item['margin']===null?'Not mapped':number_format($item['margin'],1).'% margin'?></span></div>
 <div class="sc-profit-metrics"><div><span>Supplier box</span><strong><?=sc_money_usd($item['usd'])?></strong></div><div><span>Landed / vial</span><strong><?=$item['estimate']===null?'—':'£'.number_format($item['estimate']/100,2)?></strong></div><div><span>ANKH retail</span><strong><?=$item['retail']===null?'—':money($item['retail'])?></strong></div><div><span>Gross profit</span><strong><?=$item['profit']===null?'—':'£'.number_format($item['profit']/100,2)?></strong></div></div>
 <div class="sc-profit-bottom"><span>Supplier / vial: <?=sc_money_usd((int)round($item['usd']/$item['pack']))?></span><span>F&amp;F: <?=$item['friends']===null?'—':money($item['friends'])?></span><span>James <?=$item['jay_available']?> · Tony <?=$item['tony_available']?> available</span><span><?=$item['reserved']?> reserved · <?=$item['incoming']?> incoming</span><span><?=$item['sold30']?> units on paid orders (30d)</span></div>
 <?php if($item['product_id']===null):?><a class="sc-map-link" href="?view=supplier&amp;tab=products">Match to ANKH product →</a><?php endif;?>
</article>
<?php endforeach;?></div></section>
<?php endif;?>
<?php elseif($tab==='order'):?>
<section class="panel sc-panel"><div class="sc-panel-heading"><div><p class="eyebrow">ONE COMBINED BASKET</p><h2>Build an MJT order</h2><p>Choose whole boxes. James and Tony split received vials later using existing Incoming Stock.</p></div></div>
<?php if(!$rows):?><p class="sc-notice">Import the August supplier CSV first in Prices &amp; matching.</p><?php else:?>
<p class="sc-notice">Only products matched to your ANKH catalogue can be saved as Incoming Stock. You can still prepare and copy a supplier message for any SKU. A few specialty products have different supplier box sizes; these are shown explicitly.</p>
<div class="sc-filters"><input type="search" id="sc-order-search" placeholder="Find a product or SKU…" aria-label="Search supplier ordering products"></div>
<form method="post" id="sc-order-form"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_place"><input type="hidden" name="return" value="supplier">
<div class="sc-order-items" id="sc-order-items">
<?php foreach($rows as $item):?><div class="sc-order-item" data-search="<?=e(strtolower($item['sku'].' '.$item['name'].' '.$item['strength']))?>">
 <div class="sc-order-name"><small><?=e($item['sku'])?> · <?=e($item['product_id']===null?'Match needed':'ANKH matched')?></small><strong><?=e($item['name'].' '.$item['strength'])?></strong><span><?=sc_money_usd($item['usd'])?> / box · <?=$item['pack']?> vials</span><em>James <?=$item['jay_available']?> · Tony <?=$item['tony_available']?> available<?= $item['incoming']>0?' · '.$item['incoming'].' incoming':''?></em></div>
 <div class="sc-stepper"><button type="button" data-step="-1" aria-label="Remove one box of <?=e($item['sku'])?>">−</button><input type="number" min="0" max="100" step="1" inputmode="numeric" name="boxes[<?=e($item['sku'])?>]" value="<?=max(0,min(100,(int)($draft[$item['sku']]??0)))?>" aria-label="Boxes of <?=e($item['sku'])?>" data-box-sku="<?=e($item['sku'])?>"><button type="button" data-step="1" aria-label="Add one box of <?=e($item['sku'])?>">+</button></div>
</div><?php endforeach;?></div>
<div class="sc-order-checkout"><div class="sc-order-summary"><div><small>Boxes selected</small><strong id="sc-total-boxes">0</strong></div><div><small>Vials incoming</small><strong id="sc-total-vials">0</strong></div><div><small>Supplier total</small><strong id="sc-total-usd">$0.00</strong></div></div>
<label>Expected delivery date (optional)<input type="date" name="expected_date" value="<?=e((string)($draftRow['expected_date']??''))?>"></label><label>Estimated shipping in GBP (£)<input type="number" min="0" max="100000" step=".01" name="shipping_gbp" value="<?=e((string)($draftRow['shipping_gbp']??'0'))?>" id="sc-basket-shipping"></label>
<div class="sc-order-cost"><span>Estimated landed cost in GBP</span><strong id="sc-total-gbp"><?=$fx===null?'Set exchange rate':'£0.00'?></strong><small>Uses saved GBP per USD rate; shipping is estimated and may change on final invoice.</small></div>
<label>Message ready for WhatsApp or supplier<input type="text" readonly value="Supplier order preview" class="sc-visually-hidden" aria-hidden="true"></label><textarea id="sc-supplier-message" readonly rows="6" aria-label="Generated supplier message"></textarea>
<div class="sc-order-actions"><button type="button" id="sc-copy-message" class="sc-copy-button">Copy supplier message</button><button type="submit" name="supplier_intent" value="draft" class="sc-draft-button">Save shared draft</button><button type="submit" id="sc-place-order">Mark as ordered &amp; track incoming</button></div><p id="sc-order-warning" class="sc-order-warning" role="status"></p>
</div></form>
<script>
(function(){
 const rows=<?=json_encode(array_map(fn($r)=>['sku'=>$r['sku'],'name'=>$r['name'],'strength'=>$r['strength'],'pack'=>$r['pack'],'usd'=>$r['usd'],'mapped'=>$r['product_id']!==null],$rows),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const fx=<?=json_encode($fx)?>;
 const inputs=[...document.querySelectorAll('[data-box-sku]')],lookup=Object.fromEntries(rows.map(r=>[r.sku,r])),form=document.querySelector('#sc-order-form'),copy=document.querySelector('#sc-copy-message'),save=document.querySelector('#sc-place-order'),preview=document.querySelector('#sc-supplier-message'),warning=document.querySelector('#sc-order-warning');
 function update(){
  const selected=inputs.map(i=>({...lookup[i.dataset.boxSku],boxes:Math.max(0,Math.min(100,Math.trunc(Number(i.value)||0)))})).filter(r=>r.boxes>0);
  const boxCount=selected.reduce((n,r)=>n+r.boxes,0),vials=selected.reduce((n,r)=>n+r.boxes*r.pack,0),usd=selected.reduce((n,r)=>n+r.boxes*r.usd,0);
  const dollar=(n)=>'$'+(n/100).toFixed(2),gbp=(n)=>'£'+(n/100).toFixed(2);
  document.querySelector('#sc-total-boxes').textContent=String(boxCount);
  document.querySelector('#sc-total-vials').textContent=String(vials);
  document.querySelector('#sc-total-usd').textContent=dollar(usd);
  const shipping=Math.max(0,Number(document.querySelector('#sc-basket-shipping').value)||0);
  document.querySelector('#sc-total-gbp').textContent=fx?gbp(Math.round(usd*fx+shipping*100)):'Set exchange rate';
  preview.value=selected.length?'Hi, please can I order the following from MJT:\n\n'+selected.map(r=>r.sku+' — '+r.name+' '+r.strength+': '+r.boxes+' box'+(r.boxes===1?'':'es')+' ('+(r.boxes*r.pack)+' vials)').join('\n')+'\n\nProduct total: '+dollar(usd)+' USD, excluding shipping.\nPlease confirm stock availability, final shipping cost and delivery timeframe. Thank you.':'Select boxes to generate a supplier message.';
  const missing=selected.filter(r=>!r.mapped);
  warning.textContent=missing.length?'Match '+missing.map(r=>r.sku).join(', ')+' to ANKH products before marking this basket as ordered.':(!fx?'Save an exchange rate in Prices & matching before recording a purchase.':'');
  save.disabled=!selected.length||missing.length>0||!fx;copy.disabled=!selected.length;
 }
 inputs.forEach(i=>{i.addEventListener('input',update);i.closest('.sc-stepper').querySelectorAll('[data-step]').forEach(btn=>btn.addEventListener('click',()=>{i.value=Math.max(0,Math.min(100,(Number(i.value)||0)+Number(btn.dataset.step)));update()}))});
 document.querySelector('#sc-basket-shipping').addEventListener('input',update);
 copy.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(preview.value);copy.textContent='Copied ✓';setTimeout(()=>copy.textContent='Copy supplier message',1600)}catch(err){preview.select();document.execCommand('copy');copy.textContent='Message selected — copy it'}})
 const search=document.querySelector('#sc-order-search');search.addEventListener('input',()=>document.querySelectorAll('.sc-order-item').forEach(item=>{const checked=Number(item.querySelector('[data-box-sku]').value)>0;item.hidden=!checked&&!item.dataset.search.includes(search.value.trim().toLowerCase())}));
 form.addEventListener('submit',e=>{update();if(e.submitter&&e.submitter.value==='draft')return;if(save.disabled)e.preventDefault();else if(!confirm('Record this combined MJT supplier order in Incoming Stock? This does not add physical stock yet.'))e.preventDefault()});
 update();
})();
</script>
<?php endif;?></section>
<?php elseif($tab==='products'):?>
<section class="panel sc-panel"><div class="sc-panel-heading"><div><p class="eyebrow">PRIVATE PRICE IMPORT</p><h2>MJT supplier catalogue</h2><p>Upload wholesale costs directly into the authenticated ANKH database. An import updates current supplier prices but keeps order history and product matches.</p></div></div>
<form method="post" enctype="multipart/form-data" class="sc-import-form"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_import"><input type="hidden" name="return" value="supplier"><label>August MJT supplier CSV<input type="file" name="supplier_csv" accept=".csv,text/csv" required></label><button>Import / update supplier prices</button><small>Most recent import: <?=$lastImport!==''?e(date('d M Y H:i',strtotime($lastImport))):'Not imported yet'?></small></form>
<form method="post" class="sc-config-form"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_config"><input type="hidden" name="return" value="supplier"><h3>Currency &amp; cost estimates</h3><div class="sc-config-grid"><label>GBP per 1 USD <input name="usd_gbp_rate" type="number" min="0.0001" max="9.99" step=".0001" placeholder="e.g. 0.7800" value="<?=$fx===null?'':e((string)$fx)?>"></label><label>Estimated shipping / box (£)<input name="shipping_per_box" type="number" min="0" max="10000" step=".01" value="<?=e(number_format($shippingCents/100,2,'.',''))?>"></label></div><button class="quiet">Save conversion settings</button><p class="muted">Exchange rate is entered manually; figures are estimates, not a live currency quote. Historical orders keep their original costs.</p></form>
<?php if($rows):?><div class="sc-filters"><input id="sc-map-search" type="search" placeholder="Search SKU, strength or product…" aria-label="Filter supplier price mappings"></div><div class="sc-map-list">
<?php foreach($rows as $item):?><details class="sc-map-row" data-search="<?=e(strtolower($item['sku'].' '.$item['name'].' '.$item['strength'].' '.$item['ankh_name']))?>"><summary><div><small><?=e($item['sku'])?> · <?=$item['pack']?>/box</small><strong><?=e($item['name'].' '.$item['strength'])?></strong><em><?=e($item['ankh_name']?:'Unmapped to ANKH')?></em></div><span><?=sc_money_usd($item['usd'])?> ▾</span></summary><div class="sc-map-edit"><form method="post"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_map"><input type="hidden" name="return" value="supplier"><input type="hidden" name="sku" value="<?=e($item['sku'])?>"><label>Link to ANKH product<select name="product_id"><option value="">Not linked</option><?php foreach($allProducts as $p):?><option value="<?=(int)$p['id']?>" <?=$item['product_id']!==null&&(int)$item['product_id']===(int)$p['id']?'selected':''?>><?=e($p['name'])?><?=(int)$p['active']===0?' (hidden)':''?></option><?php endforeach;?></select></label><button>Save product match</button></form><form method="post"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_price"><input type="hidden" name="return" value="supplier"><input type="hidden" name="sku" value="<?=e($item['sku'])?>"><label>Supplier box cost (USD)<input type="number" min="0" max="100000" step=".01" name="box_usd" value="<?=e(number_format($item['usd']/100,2,'.',''))?>"></label><button class="quiet">Update box price</button></form></div></details><?php endforeach;?></div><?php endif;?>
</section>
<?php elseif($tab==='incoming'):?>
<section class="panel sc-panel"><div class="sc-panel-heading"><div><p class="eyebrow">EXISTING INVENTORY</p><h2>Incoming &amp; receiving</h2><p>Placed orders go directly into your existing Incoming Stock system. When parcels arrive, confirm exactly how many units were received and allocate them to James’s or Tony’s fridge.</p></div></div>
<div class="sc-incoming-links"><a class="quick-action" href="?view=stock&amp;section=incoming">Open Incoming Stock →</a><a class="quick-action quiet" href="?view=reorder">Reorder suggestions →</a><a class="quick-action quiet" href="?view=stock">Combined fridge stock →</a></div>
<p class="sc-notice">Buying stock never adds it to a fridge automatically. Your original purchases, exchange rates, supplier messages and unit-cost snapshots remain saved.</p>
</section>
<?php endif;?>
<script>
(function(){
 function bindSearch(inputId,listId,selector){
  const input=document.getElementById(inputId),list=document.getElementById(listId);if(!input||!list)return;
  input.addEventListener('input',()=>list.querySelectorAll(selector).forEach(row=>row.hidden=!row.dataset.search.includes(input.value.trim().toLowerCase())));
 }
 bindSearch('sc-profit-search','sc-profit-list','.sc-profit-card');
 const sort=document.getElementById('sc-profit-sort'),list=document.getElementById('sc-profit-list');
 if(sort&&list)sort.addEventListener('change',()=>{const nodes=[...list.children],metric=sort.value;nodes.sort((a,b)=>metric==='name'?a.dataset.name.localeCompare(b.dataset.name):Number(b.dataset[metric])-Number(a.dataset[metric]));nodes.forEach(node=>list.appendChild(node))});
 const mapSearch=document.getElementById('sc-map-search');if(mapSearch)mapSearch.addEventListener('input',()=>document.querySelectorAll('.sc-map-row').forEach(row=>row.hidden=!row.dataset.search.includes(mapSearch.value.trim().toLowerCase())));
})();
</script>
<?php
}
