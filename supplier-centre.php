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
 // Idempotent optional columns; older drafts and completed purchases remain intact.
 $draftColumns=array_column($db->query('PRAGMA table_info(supplier_basket_drafts)')->fetchAll(PDO::FETCH_ASSOC),'name');
 if(!in_array('shipping_usd_cents',$draftColumns,true))$db->exec('ALTER TABLE supplier_basket_drafts ADD COLUMN shipping_usd_cents INTEGER DEFAULT NULL');
 $snapshotColumns=array_column($db->query('PRAGMA table_info(supplier_purchase_snapshots)')->fetchAll(PDO::FETCH_ASSOC),'name');
 if(!in_array('shipping_usd_cents',$snapshotColumns,true))$db->exec('ALTER TABLE supplier_purchase_snapshots ADD COLUMN shipping_usd_cents INTEGER DEFAULT NULL');
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
function sc_shipping_order_usd_cents(PDO $db):int{
 $value=$db->query("SELECT value FROM settings WHERE key='supplier_shipping_order_usd_cents'")->fetchColumn();
 if($value===false)return 7000; // Quoted $70 shipping fee per entire MJT order.
 return max(0,min(10000000,(int)$value));
}
function sc_shipping_draft_usd_cents(array $draft,?float $fx,int $default):int{
 if(isset($draft['shipping_usd_cents']))return max(0,(int)$draft['shipping_usd_cents']);
 // Preserve older GBP draft shipping estimates until reviewed in the USD field.
 $legacy=(string)($draft['shipping_gbp']??'0');
 if(is_numeric($legacy)&&(float)$legacy>0&&$fx!==null)return (int)round((float)$legacy*100/$fx);
 return $default;
}
function sc_valid_shipping_usd(string $input):int{
 if(!preg_match('/^\d+(?:\.\d{1,2})?$/',$input)||(float)$input>100000)throw new Exception('Enter valid supplier order shipping in USD.');
 return (int)round((float)$input*100);
}
function sc_save_setting(PDO $db,string $key,string $value):void{
 $db->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute([$key,$value]);
}
/**
 * Build transparent buying suggestions. Product's available stock is already AFTER
 * reservations (including negative balances for order shortages), so do not deduct
 * reservations again. Positive spare stock across fridges/holding can cover the
 * other fridge's shortage via a MANUAL transfer before buying.
 */
function sc_smart_suggestions(array $catalogue,array $reservedByProduct,array $incomingByProduct,array $deliveredByProduct,array $recurringByName,int $lookbackDays=30):array{
 if(!in_array($lookbackDays,[30,60,90],true))$lookbackDays=30;
 $forecast=[];foreach($recurringByName as $name=>$quantity){
  $key=strtolower(trim((string)$name));if($key==='pen')continue;
  $forecast[$key]=($forecast[$key]??0)+max(0,(int)$quantity);
 }
 $out=[];
 foreach($catalogue as $item){
  $id=isset($item['product_id'])?(int)$item['product_id']:0;
  if($id<1)continue;
  $tracked=(int)($item['stock_tracking']??0)===1;
  $jay=(int)($item['stock_jay_qty']??0);$tony=(int)($item['stock_tony_qty']??0);$holding=(int)($item['stock_unallocated_qty']??0);
  $rawAvailable=$jay+$tony+$holding;
  $available=max(0,$rawAvailable);$incoming=max(0,(int)($incomingByProduct[$id]??0));
  $reserved=array_sum(array_map('intval',$reservedByProduct[$id]??[]));
  $delivered=max(0,(int)($deliveredByProduct[$id]??0));
  $leadDemand=(int)ceil($delivered*7/$lookbackDays);
  $recurring=max(0,(int)($forecast[strtolower(trim((string)($item['ankh_name']??'')))]??0));
  $threshold=max(0,(int)($item['low_stock_at']??5));
  $pack=max(1,(int)($item['pack_size']??10));
  // Shortage is NOT a second deduction: negative raw available already reflects it.
  $urgent=max(0,-$rawAvailable-$incoming);
  $target=$threshold+$leadDemand+$recurring;
  $gap=$tracked?max(0,$target-$rawAvailable-$incoming):0;
  $boxes=$tracked&&$gap>0?(int)ceil($gap/$pack):0;
  $transferJamesToTony=min(max(0,-$tony),max(0,$jay));
  $transferTonyToJames=min(max(0,-$jay),max(0,$tony));
  $transferHolding=min(max(0,-$jay)+max(0,-$tony),max(0,$holding));
  $status=!$tracked?'untracked':($urgent>0?'shortage':($boxes>0?'reorder':'covered'));
  $out[]=[
   'sku'=>(string)$item['sku'],'name'=>(string)$item['name'].' '.(string)$item['strength'],
   'product_id'=>$id,'pack'=>$pack,'box_usd_cents'=>(int)$item['box_usd_cents'],'tracked'=>$tracked,
   'jay_available'=>max(0,$jay),'tony_available'=>max(0,$tony),'holding_available'=>max(0,$holding),
   'jay_short'=>max(0,-$jay),'tony_short'=>max(0,-$tony),'available_raw'=>$rawAvailable,
   'available'=>$available,'reserved'=>$reserved,'incoming'=>$incoming,
   'recent_delivered'=>$delivered,'lead_demand'=>$leadDemand,'recurring30'=>$recurring,'low_at'=>$threshold,
   'target'=>$target,'gap'=>$gap,'urgent'=>$urgent,'boxes'=>$boxes,'units'=>$boxes*$pack,'status'=>$status,
   'transfer_jay_to_tony'=>$transferJamesToTony,'transfer_tony_to_jay'=>$transferTonyToJames,'transfer_holding'=>$transferHolding
  ];
 }
 $rank=['shortage'=>0,'reorder'=>1,'untracked'=>2,'covered'=>3];
 usort($out,static fn($a,$b)=>$rank[$a['status']]<=>$rank[$b['status']] ?: $b['urgent']<=>$a['urgent'] ?: $b['gap']<=>$a['gap'] ?: strcmp($a['name'],$b['name']));
 return $out;
}
function sc_recent_delivered(PDO $db,int $days):array{
 if(!in_array($days,[30,60,90],true))$days=30;
 // Only actually delivered non-recurring units; don't infer future demand from unpaid or open orders.
 $local=new DateTimeImmutable('today',new DateTimeZone('Europe/London'));
 $start=$local->modify('-'.($days-1).' days')->format('Y-m-d');
 $stmt=$db->prepare("SELECT p.id,SUM(i.quantity) AS qty
  FROM stock_delivery_events e JOIN orders o ON o.id=e.order_id
  JOIN items i ON i.order_id=e.order_id
  JOIN products p ON p.id=COALESCE(i.product_id,(SELECT p2.id FROM products p2 WHERE lower(trim(p2.name))=lower(trim(i.name)) ORDER BY p2.id LIMIT 1))
  WHERE o.status<>'Cancelled' AND COALESCE(i.recurring,0)=0
   AND date(e.delivery_date)>=date(?) AND date(e.delivery_date)<=date(?)
   AND (trim(COALESCE(p.stock_tracking_since,''))='' OR date(e.delivery_date)>=date(p.stock_tracking_since))
  GROUP BY p.id");
 $stmt->execute([$start,$local->format('Y-m-d')]);
 return array_map('intval',$stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}
function sc_post(PDO $db,string $action):void{
 if($action==='supplier_centre_smart_apply'){
  $boxes=$_POST['boxes']??[];
  if(!is_array($boxes)||count($boxes)>500)throw new Exception('Choose valid box quantities.');
  $chosen=[];
  foreach($boxes as $sku=>$count){
   // HTML form field boxes[375] becomes integer key 375 in PHP; normalise before validating.
   $sku=(string)$sku;
   if(!preg_match('/^[A-Z0-9-]{1,20}$/',$sku))throw new Exception('Invalid supplier product code.');
   $n=filter_var($count,FILTER_VALIDATE_INT);
   if($n===false||$n<0||$n>100)throw new Exception('Choose between 0 and 100 boxes.');
   if($n>0)$chosen[$sku]=(int)$n;
  }
  if(!$chosen)throw new Exception('Tick at least one suggested product to add to the shared basket.');
  $catalogue=$db->prepare('SELECT s.product_id,p.stock_tracking FROM supplier_catalogue s JOIN products p ON p.id=s.product_id WHERE s.sku=?');
  foreach($chosen as $sku=>$n){
   $catalogue->execute([$sku]);$row=$catalogue->fetch(PDO::FETCH_ASSOC);
   if(!$row||(int)$row['stock_tracking']!==1)throw new Exception('Match and track the stock for '.$sku.' before adding it.');
  }
  $db->beginTransaction();
  try{
   $row=$db->query('SELECT boxes_json,shipping_gbp,shipping_usd_cents,expected_date FROM supplier_basket_drafts WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];
   $draft=json_decode((string)($row['boxes_json']??'{}'),true);if(!is_array($draft))$draft=[];
   foreach($chosen as $sku=>$n)$draft[$sku]=max((int)($draft[$sku]??0),$n);
   $draft=array_filter($draft,static fn($n)=>(int)$n>0);
   $db->prepare("INSERT INTO supplier_basket_drafts(id,boxes_json,shipping_gbp,shipping_usd_cents,expected_date,updated)
    VALUES (1,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET boxes_json=excluded.boxes_json,updated=excluded.updated")
    ->execute([json_encode($draft,JSON_THROW_ON_ERROR),(string)($row['shipping_gbp']??'0'),$row['shipping_usd_cents']===null?null:(int)$row['shipping_usd_cents'],(string)($row['expected_date']??''),gmdate('c')]);
   $db->commit();
  }catch(Throwable $err){if($db->inTransaction())$db->rollBack();throw $err;}
  $_SESSION['flash']='Selected boxes added to your shared supplier basket for review. Nothing has been ordered.';
  header('Location: ./?view=supplier&tab=order');exit;
 }
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
  $shipping=trim((string)($_POST['shipping_per_order_usd']??'70'));
  if($rate!==''&&(!is_numeric($rate)||(float)$rate<=0||(float)$rate>=10))throw new Exception('Enter GBP per 1 USD, such as 0.78.');
  $shippingUsdCents=sc_valid_shipping_usd($shipping);
  sc_save_setting($db,'supplier_usd_gbp_rate',$rate);
  sc_save_setting($db,'supplier_shipping_order_usd_cents',(string)$shippingUsdCents);
  $_SESSION['flash']='Exchange rate and fixed USD shipping per supplier order saved. Existing purchases are unchanged.';
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
   // MJT supplies numeric-only codes (e.g. LL37 = 375). PHP turns boxes[375] into an integer key.
   $sku=(string)$sku;
   if(!preg_match('/^[A-Z0-9-]{1,20}$/',$sku))throw new Exception('Invalid supplier code.');
   $qty=filter_var($quantity,FILTER_VALIDATE_INT);
   if($qty===false||$qty<0||$qty>100)throw new Exception('Select 0–100 full boxes per product.');
   if($qty>0)$requested[$sku]=(int)$qty;
  }
  if(($_POST['supplier_intent']??'')==='draft'){
   $shipDraft=trim((string)($_POST['shipping_usd']??number_format(sc_shipping_order_usd_cents($db)/100,2,'.','')));
   $expectedDraft=trim((string)($_POST['expected_date']??''));
   $shipDraftCents=sc_valid_shipping_usd($shipDraft);
   if($expectedDraft!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expectedDraft)||!checkdate((int)substr($expectedDraft,5,2),(int)substr($expectedDraft,8,2),(int)substr($expectedDraft,0,4))))throw new Exception('Enter a valid expected date.');
   $draftFx=sc_fx($db);
   $legacyGbp=$draftFx===null?'0':number_format($shipDraftCents*$draftFx/100,2,'.','');
   $db->prepare("INSERT INTO supplier_basket_drafts(id,boxes_json,shipping_gbp,shipping_usd_cents,expected_date,updated) VALUES (1,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET boxes_json=excluded.boxes_json,shipping_gbp=excluded.shipping_gbp,shipping_usd_cents=excluded.shipping_usd_cents,expected_date=excluded.expected_date,updated=excluded.updated")->execute([json_encode($requested,JSON_THROW_ON_ERROR),$legacyGbp,$shipDraftCents,$expectedDraft,gmdate('c')]);
   $_SESSION['flash']='Shared supplier basket draft saved for James and Tony.';
   header('Location: ./?view=supplier&tab=order');exit;
  }
  if(!$requested||count($requested)>100)throw new Exception('Select at least one mapped supplier product.');
  $fx=sc_fx($db);
  if($fx===null)throw new Exception('Save an exchange rate first, so stock costs can be recorded in GBP.');
  $ship=trim((string)($_POST['shipping_usd']??number_format(sc_shipping_order_usd_cents($db)/100,2,'.','')));
  $shippingUsdCents=sc_valid_shipping_usd($ship);
  $shippingPence=(int)round($shippingUsdCents*$fx);
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
  $message=sc_supplier_message($lines);
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
   $db->prepare('INSERT INTO supplier_purchase_snapshots(supplier_order_id,usd_total_cents,fx_rate,shipping_gbp_pence,shipping_usd_cents,estimated_gbp_pence,order_message,created) VALUES (?,?,?,?,?,?,?,?)')->execute([$orderId,$totalUsd,$fx,$shippingPence,$shippingUsdCents,$estimate,$message,gmdate('c')]);
   $db->prepare('DELETE FROM supplier_basket_drafts WHERE id=1')->execute();
   $db->commit();
  }catch(Throwable $err){if($db->inTransaction())$db->rollBack();throw $err;}
  $_SESSION['flash']='MJT purchase recorded in Incoming Stock; no inventory has been added yet.';
  header('Location: ./?view=stock&section=incoming');exit;
 }
 throw new Exception('Unknown supplier action.');
}
/**
 * The message to Jessica is a request for full supplier boxes, not a cost invoice.
 * Keep labels presentational; retain the imported SKU and underlying product unchanged.
 */
function sc_supplier_item_label(string $name,string $strength):string{
 $base=trim($name);
 $key=strtolower(preg_replace('/\s+/','',$base));
 $aliases=['bpc157'=>'BPC-157','kisspeptin-10'=>'Kisspeptin-10','ara290'=>'ARA-290'];
 $base=$aliases[$key]??$base;
 return trim($base.' '.trim($strength));
}
function sc_supplier_message(array $lines):string{
 $parts=[];
 foreach($lines as $line){
  $boxes=(int)$line['packs'];
  if($boxes<1)continue;
  $parts[]='X'.$boxes.' '.sc_supplier_item_label((string)$line['name'],(string)$line['strength']).' ('.$line['sku'].')';
 }
 return "Hi Jessica,\nCan we place an order please for the following?\n\n".implode("\n",$parts)."\n\nThanks";
}
/**
 * Prioritise catalogue entries ANKH has actually purchased from suppliers,
 * then those physically stocked, then products mapped into ANKH's range.
 * Never infer a historical supplier purchase merely from a retail sale.
 */
function sc_previously_purchased_skus(PDO $db):array{
 $skus=[];
 // New purchases preserve the exact supplier SKU even if product mapping later changes.
 $direct=$db->query("SELECT DISTINCT l.sku FROM supplier_purchase_lines l JOIN supplier_orders o ON o.id=l.supplier_order_id WHERE o.status<>'Cancelled'")->fetchAll(PDO::FETCH_COLUMN);
 foreach($direct as $sku)$skus[(string)$sku]=true;
 // Historical supplier orders created before the price-list importer stored product IDs only.
 $legacy=$db->query("SELECT DISTINCT s.sku FROM supplier_order_items i JOIN supplier_orders o ON o.id=i.supplier_order_id JOIN supplier_catalogue s ON s.product_id=i.product_id WHERE o.status<>'Cancelled'")->fetchAll(PDO::FETCH_COLUMN);
 foreach($legacy as $sku)$skus[(string)$sku]=true;
 return $skus;
}
function sc_catalogue_group(array $row,array $purchased):string{
 if(isset($purchased[(string)$row['sku']]))return 'purchased';
 if(((int)($row['jay_physical']??0)+(int)($row['tony_physical']??0))>0)return 'stocked';
 if($row['product_id']!==null)return 'range';
 return 'other';
}
function sc_group_definitions():array{
 return [
  'purchased'=>['Previously purchased','Supplier products with recorded ANKH purchase history'],
  'stocked'=>['Currently stocked','Products held in James’s or Tony’s fridge'],
  'range'=>['Other ANKH products','Matched to your ANKH catalogue but not currently stocked or previously purchased'],
  'other'=>['Other MJT products','Supplier-only products you have not yet added to ANKH']
 ];
}
function sc_render(PDO $db,array $products,array $reservedByProduct,array $incomingByProduct,array $recurringForecast=[]):void{
 $tab=(string)($_GET['tab']??'profit');if(!in_array($tab,['profit','smart','order','products','incoming'],true))$tab='profit';
 $catalogue=$db->query("SELECT s.*,p.name AS ankh_name,p.price AS retail,p.family_friends_price AS friends,p.stock_tracking,p.stock_jay_qty,p.stock_tony_qty,p.stock_unallocated_qty,p.low_stock_at FROM supplier_catalogue s LEFT JOIN products p ON p.id=s.product_id ORDER BY s.name COLLATE NOCASE,s.strength COLLATE NOCASE,s.sku")->fetchAll(PDO::FETCH_ASSOC);
 $allProducts=$db->query('SELECT id,name,active FROM products ORDER BY active DESC,name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
 $fx=sc_fx($db);
 $orderShippingUsdCents=sc_shipping_order_usd_cents($db);
 $lastImport=(string)($db->query("SELECT value FROM settings WHERE key='supplier_import_last'")->fetchColumn()?:'');
 $draftRow=$db->query('SELECT boxes_json,shipping_gbp,shipping_usd_cents,expected_date FROM supplier_basket_drafts WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];
 $draft=json_decode((string)($draftRow['boxes_json']??'{}'),true);
 if(!is_array($draft))$draft=[];
 $draftShippingUsdCents=sc_shipping_draft_usd_cents($draftRow,$fx,$orderShippingUsdCents);
 $draftTotalUsd=0;$draftTotalVials=0;
 foreach($catalogue as $row){
  $boxes=max(0,min(100,(int)($draft[$row['sku']]??0)));
  $draftTotalUsd+=$boxes*(int)$row['box_usd_cents'];
  $draftTotalVials+=$boxes*(int)$row['pack_size'];
 }
 $mapped=count(array_filter($catalogue,fn($r)=>$r['product_id']!==null));
 $orderUnits=$db->query("SELECT i.product_id,COALESCE(SUM(i.quantity),0) qty FROM items i JOIN orders o ON o.id=i.order_id WHERE i.product_id IS NOT NULL AND o.status IN ('Paid','Packed','Dispatched','Delivered') AND datetime(o.created)>=datetime('now','-30 days') GROUP BY i.product_id")->fetchAll(PDO::FETCH_KEY_PAIR);
 $rows=[];
 foreach($catalogue as $item){
  $pack=(int)$item['pack_size'];$usd=(int)$item['box_usd_cents'];
  $mappedId=$item['product_id']===null?null:(int)$item['product_id'];
  // Shipping is a flat order expense: only allocate it where the current shared basket gives
  // a real order composition. Other products show price-before-shipping, not fictitious fees.
  $basketPacks=max(0,min(100,(int)($draft[$item['sku']]??0)));
  $shippingAllocated=$basketPacks>0&&$draftTotalVials>0;
  $lineUsd=$usd*$basketPacks;
  $allocatedShipping=$shippingAllocated&&$fx!==null
    ?($draftTotalUsd>0?(int)round($draftShippingUsdCents*$fx*$lineUsd/$draftTotalUsd):(int)round($draftShippingUsdCents*$fx*($pack*$basketPacks)/$draftTotalVials)):0;
  $boxGbp=$fx===null?null:(int)round($usd*$fx); // Whole-box cost before shipping, rounded once to GBP pence.
  $estimate=$fx===null?null:($shippingAllocated
    ?((int)round($lineUsd*$fx)+$allocatedShipping)/($pack*$basketPacks)
    :($usd*$fx)/$pack);
  $price=$item['retail']===null?null:(int)$item['retail'];
  $friends=$price===null?null:($item['friends']===null?max(0,$price-500):(int)$item['friends']);
  $profit=$estimate===null||$price===null?null:$price-$estimate;
  $margin=$profit===null||$price<=0?null:$profit/$price*100;
  $jay=(int)($item['stock_jay_qty']??0);$tony=(int)($item['stock_tony_qty']??0);
  $jayReserved=$mappedId===null?0:(int)($reservedByProduct[$mappedId]['jay']??0);
  $tonyReserved=$mappedId===null?0:(int)($reservedByProduct[$mappedId]['tony']??0);
  $rows[]=['sku'=>(string)$item['sku'],'name'=>(string)$item['name'],'strength'=>(string)$item['strength'],'pack'=>$pack,'usd'=>$usd,'product_id'=>$mappedId,
   'ankh_name'=>(string)($item['ankh_name']??''),'retail'=>$price,'friends'=>$friends,'box_gbp'=>$boxGbp,'estimate'=>$estimate,'shipping_allocated'=>$shippingAllocated,'profit'=>$profit,'margin'=>$margin,
   'jay_physical'=>$jay+$jayReserved,'tony_physical'=>$tony+$tonyReserved,'jay_available'=>max(0,$jay),'tony_available'=>max(0,$tony),
   'reserved'=>$jayReserved+$tonyReserved,'incoming'=>$mappedId===null?0:(int)($incomingByProduct[$mappedId]??0),
   'sold30'=>$mappedId===null?0:(int)($orderUnits[$mappedId]??0)];
 }
 $lookbackDays=in_array((int)($_GET['lookback_days']??30),[30,60,90],true)?(int)($_GET['lookback_days']??30):30;
 $smartRows=$tab==='smart'?sc_smart_suggestions($catalogue,$reservedByProduct,$incomingByProduct,sc_recent_delivered($db,$lookbackDays),$recurringForecast,$lookbackDays):[];
 $smartSuggested=array_values(array_filter($smartRows,fn($row)=>$row['boxes']>0));
 $matchedRows=array_values(array_filter($rows,fn($r)=>$r['product_id']!==null));
 $purchasedSkus=sc_previously_purchased_skus($db);
 $groupDefinitions=sc_group_definitions();
 $groupedRows=array_fill_keys(array_keys($groupDefinitions),[]);
 foreach($rows as $row)$groupedRows[sc_catalogue_group($row,$purchasedSkus)][]=$row;
 $profitRows=$rows;
 usort($profitRows,fn($a,$b)=>(($b['margin']??-999999)<=>($a['margin']??-999999)) ?: strcasecmp($a['name'].' '.$a['strength'],$b['name'].' '.$b['strength']));
 $groupedProfitRows=array_fill_keys(array_keys($groupDefinitions),[]);
 foreach($profitRows as $row)$groupedProfitRows[sc_catalogue_group($row,$purchasedSkus)][]=$row;
 $groupedSmart=['attention'=>[],'covered'=>[]];
 foreach($smartRows as $row)$groupedSmart[$row['boxes']>0?'attention':'covered'][]=$row;
 ?>
<div class="heading sc-heading"><div><p class="eyebrow">ANKH / SUPPLIER CENTRE</p><h1>Supplier &amp; Profit</h1><p class="muted page-description">Compare costs, build combined MJT orders and split stock after delivery.</p></div></div>
<div class="sc-tabs" role="navigation" aria-label="Supplier Centre sections">
 <a href="?view=supplier&amp;tab=profit" <?=$tab==='profit'?'aria-current="page"':''?>>Profit</a>
 <a href="?view=supplier&amp;tab=smart" <?=$tab==='smart'?'aria-current="page"':''?>>Smart buying</a>
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
<section class="panel sc-panel"><div class="sc-panel-heading"><div><p class="eyebrow">MARGIN ANALYSIS</p><h2>Profit explorer</h2><p>Estimates use your supplier box prices and saved exchange rate. Products in your shared basket include their allocated share of the entire $<?=number_format($draftShippingUsdCents/100,2)?> order shipping charge. Products not in the basket show costs and gross profits <strong>before shipping</strong> until you add them to an order.</p></div></div>
<?php if($fx===null):?><p class="sc-notice">Set your GBP per USD exchange rate in Prices &amp; matching to calculate estimated GBP margins. Supplier USD costs are available now.</p><?php endif;?>
<div class="sc-filters"><input type="search" id="sc-profit-search" placeholder="Search product or supplier code…" aria-label="Search supplier products"><select id="sc-profit-sort" aria-label="Sort supplier products"><option value="margin">Highest margin %</option><option value="profit">Highest profit / vial</option><option value="sold">Most ordered (30d)</option><option value="stock">Most available</option><option value="name">Product name</option></select></div>
<div class="sc-group-container" id="sc-profit-list">
<?php foreach($groupDefinitions as $groupKey=>$groupDescription):$groupItems=$groupedProfitRows[$groupKey];if(!$groupItems)continue;?>
<details class="sc-catalogue-group" data-sc-group="<?=e($groupKey)?>" data-default-open="<?=$groupKey==='other'?'0':'1'?>" <?=$groupKey==='other'?'':'open'?>>
<summary class="sc-catalogue-heading"><span><strong><?=e($groupDescription[0])?></strong><small><?=e($groupDescription[1])?></small></span><b><?=count($groupItems)?></b></summary>
<div class="sc-product-list">
<?php foreach($groupItems as $item):$availability=$item['jay_available']+$item['tony_available'];?>
<details class="sc-profit-card" data-search="<?=e(strtolower($item['sku'].' '.$item['name'].' '.$item['strength'].' '.$item['ankh_name']))?>" data-margin="<?=e((string)($item['margin']??-999999))?>" data-profit="<?=e((string)($item['profit']??-999999))?>" data-sold="<?=$item['sold30']?>" data-stock="<?=$availability?>" data-name="<?=e(strtolower($item['name'].' '.$item['strength']))?>">
 <summary class="sc-profit-head"><div><small><?=e($item['sku'])?> · <?=e($item['pack'])?> vials/box · <?=$item['box_gbp']===null?'FX required':money($item['box_gbp'])?> per box</small><h3><?=e($item['name'].' '.$item['strength'])?></h3></div><span class="sc-margin-label <?=$item['margin']!==null&&$item['margin']<0?'loss':''?>"><?=$item['margin']===null?'Not mapped':number_format($item['margin'],1).'% margin'?></span></summary>
<div class="sc-profit-expanded">
 <div class="sc-profit-metrics">
  <div><span>Supplier box (<?=$item['pack']?> vials)</span><strong><?=$item['box_gbp']===null?'Set exchange rate':money($item['box_gbp'])?></strong><small class="sc-profit-subvalue"><?=sc_money_usd($item['usd'])?> USD · before shipping</small></div>
  <div><span><?=$item['shipping_allocated']?'Landed cost / vial':'Cost / vial'?></span><strong><?=$item['estimate']===null?'—':'£'.number_format($item['estimate']/100,2)?></strong><small class="sc-profit-subvalue"><?=$item['shipping_allocated']?'Includes allocated order shipping':'Before shipping'?></small></div>
  <div><span>ANKH retail / vial</span><strong><?=$item['retail']===null?'—':money($item['retail'])?></strong></div>
  <div><span>Gross profit / vial</span><strong><?=$item['profit']===null?'—':'£'.number_format($item['profit']/100,2)?></strong><small class="sc-profit-subvalue"><?=$item['shipping_allocated']?'After estimated supplier shipping':'Before shipping and overheads'?></small></div>
 </div>
 <div class="sc-profit-bottom"><span><?=!empty($item['shipping_allocated'])?'Includes proportional order shipping':'Before shipping · add to basket for landed cost'?></span><span>Supplier / vial: <?=sc_money_usd((int)round($item['usd']/$item['pack']))?></span><span>F&amp;F: <?=$item['friends']===null?'—':money($item['friends'])?></span><span>Physical: James <?=max(0,$item['jay_physical'])?> · Tony <?=max(0,$item['tony_physical'])?></span><span>Available: James <?=$item['jay_available']?> · Tony <?=$item['tony_available']?></span><span><?=$item['reserved']?> reserved · <?=$item['incoming']?> incoming</span><span><?=$item['sold30']?> units on paid orders (30d)</span></div>
 <?php if($item['product_id']===null):?><a class="sc-map-link" href="?view=supplier&amp;tab=products">Match to ANKH product →</a><?php endif;?>
</div></details>
<?php endforeach;?></div></details>
<?php endforeach;?></div></section>
<?php endif;?>
<?php elseif($tab==='smart'):?>
<section class="panel sc-panel sc-buying-panel">
 <div class="sc-panel-heading"><div><p class="eyebrow">SMART BUYING ASSISTANT</p><h2>What should we reorder?</h2><p>Checks each fridge, existing reservations, incoming supplier purchases, recent delivered sales and possible upcoming recurring orders.</p></div></div>
 <?php if(!$catalogue):?>
 <div class="sc-notice">Import the MJT price list in Prices &amp; matching before using Smart Buying. Your wholesale costs stay private.</div>
 <a class="quick-action" href="?view=supplier&amp;tab=products">Import &amp; match products →</a>
 <?php else:?>
 <form method="get" class="sc-smart-period"><input type="hidden" name="view" value="supplier"><input type="hidden" name="tab" value="smart"><label>Sales lookback<select name="lookback_days" onchange="this.form.submit()"><option value="30" <?=$lookbackDays===30?'selected':''?>>Last 30 days</option><option value="60" <?=$lookbackDays===60?'selected':''?>>Last 60 days</option><option value="90" <?=$lookbackDays===90?'selected':''?>>Last 90 days</option></select></label><small>Average delivered non-recurring demand over <?=$lookbackDays?> days is projected across the usual 7-day supplier lead time. Possible recurring orders over the next 30 days are additional estimates, not confirmed reservations.</small></form>
 <div class="sc-smart-stats"><div><small>Products to consider</small><strong><?=count($smartSuggested)?></strong></div><div><small>Suggested boxes</small><strong><?=array_sum(array_column($smartSuggested,'boxes'))?></strong></div><div><small>Product total (USD)</small><strong><?=sc_money_usd(array_sum(array_map(fn($r)=>$r['boxes']*$r['box_usd_cents'],$smartSuggested)))?></strong></div></div>
 <?php if($fx===null):?><p class="sc-notice">Set the GBP-per-USD rate in Prices &amp; matching to use the purchasing budget calculator. You can still review suggested quantities in USD.</p><?php else:?><p class="sc-notice">Budgets include the <strong>one-off <?=sc_money_usd($orderShippingUsdCents)?> USD order shipping charge</strong> (approximately £<?=number_format($orderShippingUsdCents*$fx/100,2)?>), not a fee for every box.</p><?php endif;?>
 <div class="sc-smart-explainer"><span class="sc-smart-info-icon" aria-hidden="true">ⓘ</span><p><strong>How it decides:</strong> Stock available <em>already excludes</em> reserved customer orders. Suggestions use available + incoming, then allow for your low-stock threshold, estimated 7-day sales and possible recurring orders. Spare stock in James’s or Tony’s fridge is counted before recommending more purchases, but transfers must be done manually.</p></div>
 <div class="sc-smart-budget">
  <div class="sc-smart-budget-heading"><span><strong>Set a buying budget</strong><small>Optional, in GBP · selects affordable boxes in priority order</small></span><span class="sc-smart-budget-symbol" aria-hidden="true">£</span></div>
  <div class="sc-budget-buttons" role="group" aria-label="Supplier purchasing budget">
   <button type="button" data-sc-budget="all">All suggested</button><button type="button" data-sc-budget="250" <?=$fx===null?'disabled':''?>>£250</button><button type="button" data-sc-budget="500" <?=$fx===null?'disabled':''?>>£500</button><button type="button" data-sc-budget="1000" <?=$fx===null?'disabled':''?>>£1,000</button>
  </div>
  <div class="sc-smart-custom"><label>Or enter a budget (£)<input type="number" min="1" max="100000" step="1" id="sc-smart-budget-input" placeholder="e.g. 750" <?=$fx===null?'disabled':''?>></label><button type="button" id="sc-smart-apply-budget" class="quiet" <?=$fx===null?'disabled':''?>>Apply budget</button></div>
  <p id="sc-smart-budget-explain" class="sc-smart-budget-explain" aria-live="polite">Select products below, or apply a suggested budget. Nothing is purchased automatically.</p>
 </div>
 <form method="post" id="sc-smart-form"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_smart_apply"><input type="hidden" name="return" value="supplier">
 <div class="sc-filters"><input type="search" id="sc-smart-search" placeholder="Find one of your ANKH products…" aria-label="Search Smart Buying products"></div>
 <div class="sc-smart-groups" id="sc-smart-list">
 <?php foreach(['attention'=>['Reorder recommendations','Products where buying more may be useful'],'covered'=>['Covered / no order needed','Adequate stock or stock that has not yet been counted']] as $smartGroupKey=>$smartGroupLabel):$smartGroupRows=$groupedSmart[$smartGroupKey];if(!$smartGroupRows)continue;?>
 <details class="sc-catalogue-group" data-sc-group="<?=e($smartGroupKey)?>" data-default-open="<?=$smartGroupKey==='attention'?'1':'0'?>" <?=$smartGroupKey==='attention'?'open':''?>>
 <summary class="sc-catalogue-heading"><span><strong><?=e($smartGroupLabel[0])?></strong><small><?=e($smartGroupLabel[1])?></small></span><b><?=count($smartGroupRows)?></b></summary>
 <div class="sc-smart-list">
 <?php foreach($smartGroupRows as $row):
  $hasTransfer=$row['transfer_jay_to_tony']+$row['transfer_tony_to_jay']+$row['transfer_holding']>0;
  $label=$row['status']==='shortage'?'Urgent shortage':($row['status']==='reorder'?'Consider reordering':($row['status']==='untracked'?'Stock not tracked':'Covered'));
 ?>
 <article class="sc-smart-card status-<?=e($row['status'])?>" data-search="<?=e(strtolower($row['sku'].' '.$row['name']))?>" data-smart-sku="<?=e($row['sku'])?>" data-smart-boxes="<?=$row['boxes']?>" data-smart-price="<?=$row['box_usd_cents']?>" data-smart-pack="<?=$row['pack']?>">
  <div class="sc-smart-card-head">
   <span class="sc-smart-product-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9 3h6m-5 0v4l-3 3v9a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-9l-3-3V3M8 12h8"/></svg></span>
   <div class="sc-smart-product-name"><small><?=e($row['sku'])?> · <?=$row['pack']?> vials/box</small><h3><?=e($row['name'])?></h3></div>
   <span class="sc-smart-status"><?=e($label)?></span>
  </div>
  <div class="sc-smart-metrics"><span><small>Available</small><strong><?=$row['available']?></strong></span><span><small>Reserved</small><strong><?=$row['reserved']?></strong></span><span><small>Incoming</small><strong><?=$row['incoming']?></strong></span><span><small>Target</small><strong><?=$row['target']?></strong></span></div>
  <?php if($row['status']==='untracked'):?><p class="sc-smart-context">Confirm a physical stock count before the assistant recommends buying this product.</p>
  <?php else:?><p class="sc-smart-context">7-day sales estimate: <b><?=$row['lead_demand']?></b> · Potential recurring (30d): <b><?=$row['recurring30']?></b> · Low-stock threshold: <b><?=$row['low_at']?></b></p>
  <?php if($row['jay_short']>0||$row['tony_short']>0):?>
  <div class="sc-smart-transfer"><span aria-hidden="true">⇄</span><div><?php if($row['jay_short']>0):?>James’s fridge is <?=$row['jay_short']?> short. Tony has <?=$row['tony_available']?> available; up to <?=$row['transfer_tony_to_jay']?> could be transferred.<?php endif;?>
  <?php if($row['tony_short']>0):?>Tony’s fridge is <?=$row['tony_short']?> short. James has <?=$row['jay_available']?> available; up to <?=$row['transfer_jay_to_tony']?> could be transferred.<?php endif;?>
  <?php if($row['transfer_holding']>0):?> Holding stock could cover up to <?=$row['transfer_holding']?> after manual allocation.<?php endif;?></div></div>
  <?php endif;?>
  <?php endif;?>
  <div class="sc-smart-choice">
   <label class="sc-smart-tick"><input type="checkbox" data-sc-smart-check <?=$row['boxes']<1?'disabled':''?>><span><b><?=$row['boxes']?> box<?=$row['boxes']===1?'':'es'?> suggested</b><small><?=sc_money_usd($row['box_usd_cents'])?> per box · <?=$row['units']?> vials suggested</small></span></label>
   <div class="sc-stepper"><button type="button" data-sc-step="-1" aria-label="Remove a box for <?=e($row['name'])?>" <?=$row['tracked']?'':'disabled'?>>−</button><input type="number" name="boxes[<?=e($row['sku'])?>]" value="0" min="0" max="100" step="1" inputmode="numeric" data-sc-smart-qty aria-label="Boxes of <?=e($row['name'])?>" <?=$row['tracked']?'':'disabled'?>> <button type="button" data-sc-step="1" aria-label="Add a box for <?=e($row['name'])?>" <?=$row['tracked']?'':'disabled'?>>+</button></div>
  </div>
  <?php if($row['boxes']>0):?><div class="sc-smart-recommendation"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M5 14l7 7 7-7"/></svg><span><?=$row['gap']?> units needed to reach target, after <?=$row['incoming']?> incoming → <strong><?=$row['boxes']?> box<?=$row['boxes']===1?'':'es'?> recommended</strong></span></div><?php endif;?>
 </article>
 <?php endforeach;?>
 </div></details>
 <?php endforeach;?>
 </div>
 <div class="sc-smart-footer">
  <div class="sc-smart-footer-stats"><div><small>Selected boxes</small><strong id="sc-smart-selected-boxes">0</strong></div><div><small>Supplier cost</small><strong id="sc-smart-selected-usd">$0.00</strong></div><div><small>Estimated GBP incl. shipping</small><strong id="sc-smart-selected-gbp"><?=$fx===null?'Set FX rate':'£0.00'?></strong></div></div>
  <button type="submit" class="sc-smart-add-button" id="sc-smart-add-button" disabled>Add selected boxes to shared basket →</button>
  <p>Adding products <strong>never places an order</strong>. Your existing basket quantities are preserved; adding a suggestion again will not multiply them.</p>
 </div>
 </form>
 <script>
 (() => {
  const cards=[...document.querySelectorAll('[data-smart-sku]')];
  const fx=<?=json_encode($fx)?>,shippingUsd=<?=json_encode($orderShippingUsdCents)?>;
  const selector=document.querySelector('#sc-smart-budget-input'),explain=document.querySelector('#sc-smart-budget-explain'),submit=document.querySelector('#sc-smart-add-button');
  const dollar=n=>'$'+(n/100).toFixed(2),pound=n=>'£'+(n/100).toFixed(2);
  function quantity(card,n){
   const input=card.querySelector('[data-sc-smart-qty]');
   input.value=Math.max(0,Math.min(100,Math.trunc(n||0)));
   card.querySelector('[data-sc-smart-check]').checked=Number(input.value)>0;
  }
  function update(){
   const selected=cards.map(card=>({boxes:Number(card.querySelector('[data-sc-smart-qty]').value)||0,price:Number(card.dataset.smartPrice)})).filter(r=>r.boxes>0);
   const boxes=selected.reduce((sum,r)=>sum+r.boxes,0),usd=selected.reduce((sum,r)=>sum+r.boxes*r.price,0);
   document.querySelector('#sc-smart-selected-boxes').textContent=String(boxes);
   document.querySelector('#sc-smart-selected-usd').textContent=dollar(usd);
   document.querySelector('#sc-smart-selected-gbp').textContent=fx?pound(Math.round((usd+(boxes>0?shippingUsd:0))*fx)):'Set FX rate';
   submit.disabled=boxes===0;
  }
  function applyBudget(limit){
   cards.forEach(card=>quantity(card,0));
   let totalUsd=0,boxes=0;
   for(const card of cards){
    const suggested=Number(card.dataset.smartBoxes)||0,price=Number(card.dataset.smartPrice)||0;
    if(suggested<1)continue;
    let count=0;
    // Budget includes the single shipment fee once if at least one box is selected.
    while(count<suggested){
     const nextUsd=totalUsd+price;
     const nextCost=fx?Math.round((nextUsd+shippingUsd)*fx):0;
     if(limit!==null&&(!fx||nextCost>limit))break;
     totalUsd=nextUsd;count++;boxes++;
    }
    if(count>0)quantity(card,count);
   }
   const cost=fx&&boxes?Math.round((totalUsd+shippingUsd)*fx):0;
   explain.textContent=limit===null?boxes+' suggested boxes selected. Review before saving.':boxes+' boxes selected for about '+pound(cost)+' against a '+pound(limit)+' estimated budget. Review before saving.';
   update();
  }
  cards.forEach(card=>{
   const input=card.querySelector('[data-sc-smart-qty]'),check=card.querySelector('[data-sc-smart-check]');
   check.addEventListener('change',()=>{quantity(card,check.checked?(Number(card.dataset.smartBoxes)||1):0);update()});
   input.addEventListener('input',()=>{quantity(card,Number(input.value));update()});
   card.querySelectorAll('[data-sc-step]').forEach(button=>button.addEventListener('click',()=>{quantity(card,Number(input.value)+Number(button.dataset.scStep));update()}));
  });
  document.querySelectorAll('[data-sc-budget]').forEach(button=>button.addEventListener('click',()=>applyBudget(button.dataset.scBudget==='all'?null:Math.round(Number(button.dataset.scBudget)*100))));
  document.querySelector('#sc-smart-apply-budget').addEventListener('click',()=>{const value=Number(selector.value);if(value>0&&fx)applyBudget(Math.round(value*100))});
  update();
 })();
 </script>
 <?php endif;?>
</section>
<?php elseif($tab==='order'):?>
<section class="panel sc-panel"><div class="sc-panel-heading"><div><p class="eyebrow">ONE COMBINED BASKET</p><h2>Build an MJT order</h2><p>Choose whole boxes. James and Tony split received vials later using existing Incoming Stock.</p></div></div>
<?php if(!$rows):?><p class="sc-notice">Import the August supplier CSV first in Prices &amp; matching.</p><?php else:?>
<p class="sc-notice">Only products matched to your ANKH catalogue can be saved as Incoming Stock. You can still prepare and copy a supplier message for any SKU. A few specialty products have different supplier box sizes; these are shown explicitly.</p>
<div class="sc-filters sc-order-filters"><input type="search" id="sc-order-search" placeholder="Search all supplier products…" aria-label="Search all supplier products"></div><p class="sc-find-help">Previously purchased and currently stocked products appear first. Unstocked products stay closed until you open them. Search finds items in every section.</p>
<form method="post" id="sc-order-form"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_place"><input type="hidden" name="return" value="supplier">
<div class="sc-order-quick" id="sc-order-quick" role="status" aria-live="polite"><div><small>YOUR SUPPLIER BASKET</small><strong><span id="sc-quick-boxes">0</span> boxes · <span id="sc-quick-usd">$0.00</span></strong><small id="sc-quick-gbp">Add boxes to see estimated GBP total</small></div><button type="button" id="sc-quick-review" aria-controls="sc-order-checkout">Review basket <span aria-hidden="true">→</span></button></div>
<div class="sc-order-groups" id="sc-order-items">
<?php foreach($groupDefinitions as $groupKey=>$groupDescription):$groupItems=$groupedRows[$groupKey];if(!$groupItems)continue;
$groupHasDraft=false;foreach($groupItems as $groupEntry){if((int)($draft[$groupEntry['sku']]??0)>0){$groupHasDraft=true;break;}}
$groupOpen=in_array($groupKey,['purchased','stocked'],true)||$groupHasDraft;?>
<details class="sc-catalogue-group" data-sc-group="<?=e($groupKey)?>" data-default-open="<?=$groupOpen?'1':'0'?>" <?=$groupOpen?'open':''?>>
<summary class="sc-catalogue-heading"><span><strong><?=e($groupDescription[0])?></strong><small><?=e($groupDescription[1])?></small></span><b><?=count($groupItems)?></b></summary>
<div class="sc-order-items">
<?php foreach($groupItems as $item):?><div class="sc-order-item" data-search="<?=e(strtolower($item['sku'].' '.$item['name'].' '.$item['strength']))?>">
 <div class="sc-order-name"><small><?=e($item['sku'])?> · <?=$item['pack']?> vials/box</small><strong><?=e($item['name'].' '.$item['strength'])?></strong><span class="sc-order-unit-price"><?=sc_money_usd($item['usd'])?> / box<?=$item['box_gbp']===null?'':' · '.money($item['box_gbp'])?></span><em><?=$item['product_id']===null?'Supplier-only · match to receive stock':'James '.$item['jay_available'].' · Tony '.$item['tony_available'].' in stock'?></em></div>
 <div class="sc-order-pick"><div class="sc-stepper"><button type="button" data-step="-1" aria-label="Remove one box of <?=e($item['sku'])?>">−</button><input type="number" min="0" max="100" step="1" inputmode="numeric" name="boxes[<?=e($item['sku'])?>]" value="<?=max(0,min(100,(int)($draft[$item['sku']]??0)))?>" aria-label="Boxes of <?=e($item['sku'])?>" data-box-sku="<?=e($item['sku'])?>"><button type="button" data-step="1" aria-label="Add one box of <?=e($item['sku'])?>">+</button></div><small class="sc-order-line-total" data-sc-line-total aria-live="polite"></small></div>
</div><?php endforeach;?></div></details>
<?php endforeach;?></div>
<div class="sc-order-checkout" id="sc-order-checkout"><h3 class="sc-checkout-title">Review your basket</h3><div class="sc-basket-selected" id="sc-basket-selected" aria-live="polite"><p>No boxes selected yet. Add products above to prepare an order.</p></div><div class="sc-order-summary"><div><small>Boxes selected</small><strong id="sc-total-boxes">0</strong></div><div><small>Vials incoming</small><strong id="sc-total-vials">0</strong></div><div><small>Supplier total</small><strong id="sc-total-usd">$0.00</strong></div></div>
<label>Expected delivery date (optional)<input type="date" name="expected_date" value="<?=e((string)($draftRow['expected_date']??''))?>"></label><label>Supplier shipping per entire order (USD $)<input type="number" min="0" max="100000" step=".01" name="shipping_usd" value="<?=e(number_format($draftShippingUsdCents/100,2,'.',''))?>" id="sc-basket-shipping"><small>Flat $70.00 USD by default. Change here only if this order has a different quoted shipping fee.</small></label>
<div class="sc-order-cost"><span>Estimated landed cost in GBP including order shipping</span><strong id="sc-total-gbp"><?=$fx===null?'Set exchange rate':'£0.00'?></strong><small id="sc-shipping-breakdown">Fixed shipping applies once per order and is allocated proportionally by supplier value across purchased vials. Rates and actual invoices may differ.</small></div>
<label>Message ready for WhatsApp or supplier<input type="text" readonly value="Supplier order preview" class="sc-visually-hidden" aria-hidden="true"></label><textarea id="sc-supplier-message" readonly rows="10" aria-label="Generated supplier message"></textarea><small class="muted">The message lists full boxes and supplier codes. Costs and shipping are calculated above for your ANKH records, not included in Jessica’s message.</small>
<div class="sc-order-actions"><button type="button" id="sc-copy-message" class="sc-copy-button">Copy supplier message</button><button type="submit" name="supplier_intent" value="draft" class="sc-draft-button">Save shared draft</button><button type="submit" id="sc-place-order">Mark as ordered &amp; track incoming</button></div><p id="sc-order-warning" class="sc-order-warning" role="status"></p>
</div></form>
<script>
(function(){
 const rows=<?=json_encode(array_map(fn($r)=>['sku'=>$r['sku'],'name'=>$r['name'],'strength'=>$r['strength'],'message_label'=>sc_supplier_item_label($r['name'],$r['strength']),'pack'=>$r['pack'],'usd'=>$r['usd'],'mapped'=>$r['product_id']!==null],$rows),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const fx=<?=json_encode($fx)?>;
 const inputs=[...document.querySelectorAll('[data-box-sku]')],lookup=Object.fromEntries(rows.map(r=>[r.sku,r])),form=document.querySelector('#sc-order-form'),copy=document.querySelector('#sc-copy-message'),save=document.querySelector('#sc-place-order'),preview=document.querySelector('#sc-supplier-message'),warning=document.querySelector('#sc-order-warning');
 function update(){
  const selected=inputs.map(i=>({...lookup[i.dataset.boxSku],boxes:Math.max(0,Math.min(100,Math.trunc(Number(i.value)||0)))})).filter(r=>r.boxes>0);
  const boxCount=selected.reduce((n,r)=>n+r.boxes,0),vials=selected.reduce((n,r)=>n+r.boxes*r.pack,0),usd=selected.reduce((n,r)=>n+r.boxes*r.usd,0);
  const dollar=(n)=>'$'+(n/100).toFixed(2),gbp=(n)=>'£'+(n/100).toFixed(2);
  document.querySelector('#sc-total-boxes').textContent=String(boxCount);
  document.querySelector('#sc-total-vials').textContent=String(vials);
  document.querySelector('#sc-total-usd').textContent=dollar(usd);
  document.querySelector('#sc-quick-boxes').textContent=String(boxCount);
  document.querySelector('#sc-quick-usd').textContent=dollar(usd);
  document.querySelector('#sc-quick-gbp').textContent=fx&&boxCount>0?'Approx. '+gbp(Math.round((usd+Math.round(Math.max(0,Number(document.querySelector('#sc-basket-shipping').value)||0)*100))*fx))+' incl. shipping':(boxCount>0?'Set exchange rate for GBP cost':'Add boxes to see estimated GBP total');
  document.querySelector('#sc-order-quick').classList.toggle('has-items',boxCount>0);
  const selectedList=document.querySelector('#sc-basket-selected');
  selectedList.replaceChildren();
  if(selected.length===0){const empty=document.createElement('p');empty.textContent='No boxes selected yet. Add products above to prepare an order.';selectedList.append(empty);}
  selected.forEach(r=>{
   const entry=document.createElement('div'),name=document.createElement('span'),cost=document.createElement('strong');
   name.textContent='X'+r.boxes+' '+r.name+' '+r.strength+' ('+r.sku+')';
   cost.textContent=dollar(r.boxes*r.usd);entry.append(name,cost);selectedList.append(entry);
  });
  inputs.forEach(i=>{
   const qty=Math.max(0,Math.min(100,Math.trunc(Number(i.value)||0)));
   const row=i.closest('.sc-order-item'),subtotal=row.querySelector('[data-sc-line-total]');
   row.classList.toggle('is-selected',qty>0);
   subtotal.textContent=qty>0?'Line: '+dollar(qty*lookup[i.dataset.boxSku].usd):'';
  });
  const shippingUsd=Math.round(Math.max(0,Number(document.querySelector('#sc-basket-shipping').value)||0)*100);
  document.querySelector('#sc-total-gbp').textContent=fx?gbp(Math.round((usd+(boxCount>0?shippingUsd:0))*fx)):'Set exchange rate';
  document.querySelector('#sc-shipping-breakdown').textContent='Shipping once per order: '+dollar(boxCount>0?shippingUsd:0)+(fx?' ≈ '+gbp(Math.round((boxCount>0?shippingUsd:0)*fx)):'')+'. Allocated across the ordered products by supplier value. Final invoice may differ.';
  preview.value=selected.length?'Hi Jessica,\nCan we place an order please for the following?\n\n'+selected.map(r=>'X'+r.boxes+' '+r.message_label+' ('+r.sku+')').join('\n')+'\n\nThanks':'Select boxes to generate a supplier message.';
  const missing=selected.filter(r=>!r.mapped);
  warning.textContent=missing.length?'Match '+missing.map(r=>r.sku).join(', ')+' to ANKH products before marking this basket as ordered.':(!fx?'Save an exchange rate in Prices & matching before recording a purchase.':'');
  save.disabled=!selected.length||missing.length>0||!fx;copy.disabled=!selected.length;
 }
 inputs.forEach(i=>{i.addEventListener('input',update);i.closest('.sc-stepper').querySelectorAll('[data-step]').forEach(btn=>btn.addEventListener('click',()=>{i.value=Math.max(0,Math.min(100,(Number(i.value)||0)+Number(btn.dataset.step)));update()}))});
 document.querySelector('#sc-basket-shipping').addEventListener('input',update);
 copy.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(preview.value);copy.textContent='Copied ✓';setTimeout(()=>copy.textContent='Copy supplier message',1600)}catch(err){preview.select();document.execCommand('copy');copy.textContent='Message selected — copy it'}})
 const search=document.querySelector('#sc-order-search');
 search.addEventListener('input',()=>window.scFilterSupplierGroups&&window.scFilterSupplierGroups('sc-order-items','.sc-order-item',search.value));
 document.querySelector('#sc-quick-review').addEventListener('click',()=>{
  document.querySelector('#sc-order-checkout').scrollIntoView({behavior:'smooth',block:'start'});
 });
 form.addEventListener('submit',e=>{update();if(e.submitter&&e.submitter.value==='draft')return;if(save.disabled)e.preventDefault();else if(!confirm('Record this combined MJT supplier order in Incoming Stock? This does not add physical stock yet.'))e.preventDefault()});
 update();
})();
</script>
<?php endif;?></section>
<?php elseif($tab==='products'):?>
<section class="panel sc-panel"><div class="sc-panel-heading"><div><p class="eyebrow">PRIVATE PRICE IMPORT</p><h2>MJT supplier catalogue</h2><p>Upload wholesale costs directly into the authenticated ANKH database. An import updates current supplier prices but keeps order history and product matches.</p></div></div>
<form method="post" enctype="multipart/form-data" class="sc-import-form"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_import"><input type="hidden" name="return" value="supplier"><label>August MJT supplier CSV<input type="file" name="supplier_csv" accept=".csv,text/csv" required></label><button>Import / update supplier prices</button><small>Most recent import: <?=$lastImport!==''?e(date('d M Y H:i',strtotime($lastImport))):'Not imported yet'?></small></form>
<form method="post" class="sc-config-form"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_config"><input type="hidden" name="return" value="supplier"><h3>Currency &amp; cost estimates</h3><div class="sc-config-grid"><label>GBP per 1 USD <input name="usd_gbp_rate" type="number" min="0.0001" max="9.99" step=".0001" placeholder="e.g. 0.7572" value="<?=$fx===null?'':e((string)$fx)?>"></label><label>Supplier shipping per order (USD $)<input name="shipping_per_order_usd" type="number" min="0" max="100000" step=".01" value="<?=e(number_format($orderShippingUsdCents/100,2,'.',''))?>"></label></div><button class="quiet">Save conversion settings</button><p class="muted">MJT currently quotes $70.00 for the whole order, regardless of boxes. The fixed USD shipping amount is converted at your manually saved exchange rate and allocated to products when you build an order. No historical costs are overwritten.</p></form>
<?php if($rows):?><div class="sc-filters"><input id="sc-map-search" type="search" placeholder="Search all supplier SKUs, strengths or products…" aria-label="Filter supplier price mappings"></div><p class="sc-find-help">Your existing products come first. Tap any row to edit its mapping or supplier price. The wider MJT catalogue is collapsed below.</p><div class="sc-map-groups" id="sc-map-list">
<?php foreach($groupDefinitions as $groupKey=>$groupDescription):$groupItems=$groupedRows[$groupKey];if(!$groupItems)continue;?>
<details class="sc-catalogue-group" data-sc-group="<?=e($groupKey)?>" data-default-open="<?=$groupKey==='other'?'0':'1'?>" <?=$groupKey==='other'?'':'open'?>>
<summary class="sc-catalogue-heading"><span><strong><?=e($groupDescription[0])?></strong><small><?=e($groupDescription[1])?></small></span><b><?=count($groupItems)?></b></summary>
<div class="sc-map-list">
<?php foreach($groupItems as $item):?><details class="sc-map-row" data-search="<?=e(strtolower($item['sku'].' '.$item['name'].' '.$item['strength'].' '.$item['ankh_name']))?>"><summary><div><small><?=e($item['sku'])?> · <?=$item['pack']?>/box</small><strong><?=e($item['name'].' '.$item['strength'])?></strong><em><?=e($item['ankh_name']?:'Unmapped to ANKH')?></em></div><span><?=sc_money_usd($item['usd'])?> ▾</span></summary><div class="sc-map-edit"><form method="post"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_map"><input type="hidden" name="return" value="supplier"><input type="hidden" name="sku" value="<?=e($item['sku'])?>"><label>Link to ANKH product<select name="product_id"><option value="">Not linked</option><?php foreach($allProducts as $p):?><option value="<?=(int)$p['id']?>" <?=$item['product_id']!==null&&(int)$item['product_id']===(int)$p['id']?'selected':''?>><?=e($p['name'])?><?=(int)$p['active']===0?' (hidden)':''?></option><?php endforeach;?></select></label><button>Save product match</button></form><form method="post"><?php csrf();?><input type="hidden" name="action" value="supplier_centre_price"><input type="hidden" name="return" value="supplier"><input type="hidden" name="sku" value="<?=e($item['sku'])?>"><label>Supplier box cost (USD)<input type="number" min="0" max="100000" step=".01" name="box_usd" value="<?=e(number_format($item['usd']/100,2,'.',''))?>"></label><button class="quiet">Update box price</button></form></div></details><?php endforeach;?></div></details>
<?php endforeach;?></div><?php endif;?>
</section>
<?php elseif($tab==='incoming'):?>
<section class="panel sc-panel"><div class="sc-panel-heading"><div><p class="eyebrow">EXISTING INVENTORY</p><h2>Incoming &amp; receiving</h2><p>Placed orders go directly into your existing Incoming Stock system. When parcels arrive, confirm exactly how many units were received and allocate them to James’s or Tony’s fridge.</p></div></div>
<div class="sc-incoming-links"><a class="quick-action" href="?view=stock&amp;section=incoming">Open Incoming Stock →</a><a class="quick-action quiet" href="?view=reorder">Reorder suggestions →</a><a class="quick-action quiet" href="?view=stock">Combined fridge stock →</a></div>
<p class="sc-notice">Buying stock never adds it to a fridge automatically. Your original purchases, exchange rates, supplier messages and unit-cost snapshots remain saved.</p>
</section>
<?php endif;?>
<script>
(function(){
 function filterGroups(listId,cardSelector,search){
  const list=document.getElementById(listId);if(!list)return;
  const term=(search||'').trim().toLowerCase();
  list.querySelectorAll('.sc-catalogue-group').forEach(group=>{
   const cards=[...group.querySelectorAll(cardSelector)];
   let visible=0;
   cards.forEach(card=>{
    // Selected basket products remain visible even when filtering by search.
    const quantityInput=card.querySelector('[data-box-sku]');
    const selected=quantityInput&&Number(quantityInput.value)>0;
    const match=!term||card.dataset.search.includes(term)||selected;
    card.hidden=!match;if(match)visible++;
   });
   group.hidden=visible===0;
   if(term&&visible)group.open=true;
   else if(!term)group.open=group.dataset.defaultOpen==='1'||cards.some(card=>Number(card.querySelector('[data-box-sku]')?.value||0)>0);
  });
 }
 window.scFilterSupplierGroups=filterGroups;
 [['sc-profit-search','sc-profit-list','.sc-profit-card'],['sc-map-search','sc-map-list','.sc-map-row'],['sc-smart-search','sc-smart-list','.sc-smart-card']].forEach(([inputId,listId,cardSelector])=>{
  const input=document.getElementById(inputId);
  if(input)input.addEventListener('input',()=>filterGroups(listId,cardSelector,input.value));
 });
 const sort=document.getElementById('sc-profit-sort'),list=document.getElementById('sc-profit-list');
 if(sort&&list)sort.addEventListener('change',()=>{
  const metric=sort.value;
  list.querySelectorAll('.sc-product-list').forEach(inner=>{
   const nodes=[...inner.children].filter(el=>el.classList.contains('sc-profit-card'));
   nodes.sort((a,b)=>metric==='name'?a.dataset.name.localeCompare(b.dataset.name):Number(b.dataset[metric])-Number(a.dataset[metric]));
   nodes.forEach(node=>inner.appendChild(node));
  });
 });
})();
</script>
<?php
}
