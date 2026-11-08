<?php
declare(strict_types=1);
// Authenticated callers only. Packing runs never mutate orders, payments or stock.
function ankhPackingCentreSchema(PDO $db):void{
 $db->exec("CREATE TABLE IF NOT EXISTS packing_runs (
 id INTEGER PRIMARY KEY,name TEXT NOT NULL,owner TEXT NOT NULL DEFAULT '',notes TEXT NOT NULL DEFAULT '',
 state TEXT NOT NULL DEFAULT 'open' CHECK(state IN ('open','completed','cancelled')),
 request_key TEXT NOT NULL UNIQUE,created TEXT NOT NULL,updated TEXT NOT NULL,created_by INTEGER NOT NULL DEFAULT 0);
 CREATE TABLE IF NOT EXISTS packing_run_orders (
 run_id INTEGER NOT NULL REFERENCES packing_runs(id) ON DELETE CASCADE,
 order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
 signature TEXT NOT NULL,PRIMARY KEY(run_id,order_id));
 CREATE TABLE IF NOT EXISTS packing_run_claims (
 order_id INTEGER PRIMARY KEY REFERENCES orders(id) ON DELETE CASCADE,
 run_id INTEGER NOT NULL REFERENCES packing_runs(id) ON DELETE CASCADE);
 CREATE INDEX IF NOT EXISTS packing_run_members ON packing_run_orders(order_id);");
}
function ankhPackingCentreOrder(PDO $db,int $id):?array{
 $q=$db->prepare('SELECT o.*,COALESCE((SELECT SUM(price*quantity) FROM items WHERE order_id=o.id),0)+COALESCE(o.delivery_charge,0) total,COALESCE((SELECT SUM(amount) FROM payments WHERE order_id=o.id),0) paid_amount FROM orders o WHERE o.id=?');
 $q->execute([$id]);$order=$q->fetch(PDO::FETCH_ASSOC);if(!$order)return null;
 $q=$db->prepare('SELECT id,product_id,name,quantity,presentation FROM items WHERE order_id=? ORDER BY id');$q->execute([$id]);$order['lines']=$q->fetchAll(PDO::FETCH_ASSOC);return $order;
}
function ankhPackingCentreSignature(array $o):string{
 return hash('sha256',json_encode([$o['customer'],$o['recipient_name']??'',$o['address'],$o['phone'],$o['notes'],$o['back_order'],$o['status'],$o['delivery_date'],$o['scheduled_delivery_date']??'',$o['delivery_method']??'',$o['lines']],JSON_THROW_ON_ERROR));
}
function ankhPackingCentreEligible(array $o):bool{
 return !in_array((string)$o['status'],['Cancelled','Delivered'],true)&&trim((string)($o['delivery_date']??''))==='';
}
function ankhPackingCentreCreate(PDO $db,array $ids,string $name,string $owner,string $notes,string $key,int $actor=0):int{
 if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new RuntimeException('Refresh this page and try again.');
 $ids=array_values(array_unique(array_map('intval',$ids)));
 if(!$ids||count($ids)>200||min($ids)<1)throw new RuntimeException('Choose between 1 and 200 orders.');
 $name=trim($name);$owner=trim($owner);$notes=trim($notes);
 if($name===''||mb_strlen($name)>100||mb_strlen($owner)>80||mb_strlen($notes)>2000)throw new RuntimeException('Use a run name up to 100 characters, owner up to 80 and notes up to 2000.');
 $db->beginTransaction();
 try{
  // First write obtains SQLite's writer lock before testing claims from other admins.
  $q=$db->prepare("INSERT INTO packing_runs(name,owner,notes,request_key,created,updated,created_by) VALUES(?,?,?,?,?,?,?) ON CONFLICT(request_key) DO NOTHING");
  $q->execute([$name,$owner,$notes,$key,gmdate('c'),gmdate('c'),$actor]);
  $fresh=$q->rowCount()>0;$q=$db->prepare('SELECT id FROM packing_runs WHERE request_key=?');$q->execute([$key]);$run=(int)$q->fetchColumn();
  if(!$fresh){$db->commit();return $run;}
  foreach($ids as $id){
   $o=ankhPackingCentreOrder($db,$id);
   if(!$o||!ankhPackingCentreEligible($o)||!$o['lines'])throw new RuntimeException('Order #'.$id.' is no longer available for a packing run. Refresh the list.');
   $q=$db->prepare('SELECT run_id FROM packing_run_claims WHERE order_id=?');$q->execute([$id]);$other=$q->fetchColumn();
   if($other!==false)throw new RuntimeException('Order #'.$id.' is already in packing run #'.$other.'. Open that run or release the order first.');
   $db->prepare('INSERT INTO packing_run_orders(run_id,order_id,signature) VALUES(?,?,?)')->execute([$run,$id,ankhPackingCentreSignature($o)]);
   $db->prepare('INSERT INTO packing_run_claims(order_id,run_id) VALUES(?,?)')->execute([$id,$run]);
  }
  $db->commit();return $run;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function ankhPackingCentreUpdate(PDO $db,int $run,string $action,int $order=0):void{
 if(!in_array($action,['completed','cancelled','remove'],true))throw new RuntimeException('Choose a valid packing action.');
 $db->beginTransaction();
 try{
  $q=$db->prepare("UPDATE packing_runs SET updated=? WHERE id=? AND state='open'");$q->execute([gmdate('c'),$run]);
  if(!$q->rowCount())throw new RuntimeException('This run is already closed or no longer exists.');
  if($action==='remove'){
   $db->prepare('DELETE FROM packing_run_claims WHERE run_id=? AND order_id=?')->execute([$run,$order]);
   $db->prepare('DELETE FROM packing_run_orders WHERE run_id=? AND order_id=?')->execute([$run,$order]);
  }else{
   $db->prepare('UPDATE packing_runs SET state=? WHERE id=?')->execute([$action,$run]);
   $db->prepare('DELETE FROM packing_run_claims WHERE run_id=?')->execute([$run]);
  }
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function ankhPackingCentreRun(PDO $db,int $id):?array{
 $q=$db->prepare('SELECT * FROM packing_runs WHERE id=?');$q->execute([$id]);$run=$q->fetch(PDO::FETCH_ASSOC);if(!$run)return null;
 $q=$db->prepare('SELECT order_id,signature FROM packing_run_orders WHERE run_id=? ORDER BY order_id');$q->execute([$id]);$members=$q->fetchAll(PDO::FETCH_ASSOC);$run['orders']=[];
 foreach($members as $member){$o=ankhPackingCentreOrder($db,(int)$member['order_id']);if(!$o)continue;$o['changed']=!hash_equals($member['signature'],ankhPackingCentreSignature($o));$run['orders'][]=$o;}
 return $run;
}
/** Physical packing contents derived without rewriting saved order or price records. */
function ankhPackingCentreLines(array $lines):array{
 $contents=[];$penUnits=0;$selectedPens=0;
 foreach($lines as $line){
  if(strtolower(trim((string)$line['name']))==='pen'){
   $penUnits+=(int)$line['quantity'];continue;
  }
  if(strcasecmp(trim((string)($line['presentation']??'')),'Pen')===0){
   $line['presentation']='Cartridge';$selectedPens+=(int)$line['quantity'];
  }
  $contents[]=$line;
 }
 // Modern orders already contain separate editable Pen rows; never add those twice.
 // Older bundled orders may have only the peptide's Pen presentation.
 $devices=$penUnits>0?$penUnits:$selectedPens;
 if($devices>0)$contents[]=['product_id'=>0,'name'=>'Pen devices','presentation'=>'Device','quantity'=>$devices];
 return $contents;
}
function ankhPackingCentrePenNote(array $lines):string{
 $recorded=0;$selected=0;
 foreach($lines as $line){
  if(strtolower(trim((string)$line['name']))==='pen')$recorded+=(int)$line['quantity'];
  elseif(strcasecmp(trim((string)($line['presentation']??'')),'Pen')===0)$selected+=(int)$line['quantity'];
 }
 if($selected>0&&$recorded===0)return $selected.' pen device'.($selected===1?'':'s').' included from the pen selection; no separate pen product line is recorded.';
 if($recorded>0&&$recorded<$selected)return 'Check pen quantity: '.$selected.' cartridge + pen units selected, but only '.$recorded.' pen device'.($recorded===1?' is':'s are').' recorded. Review the order before packing.';
 return '';
}
function ankhPackingCentreTotals(array $orders):array{
 $totals=[];
 foreach($orders as $o)foreach(ankhPackingCentreLines($o['lines']) as $line){
  $key=json_encode([(int)($line['product_id']??0),$line['name'],$line['presentation']],JSON_THROW_ON_ERROR);
  if(!isset($totals[$key]))$totals[$key]=['name'=>$line['name'],'presentation'=>$line['presentation'],'quantity'=>0,'orders'=>[]];
  $totals[$key]['quantity']+=(int)$line['quantity'];$totals[$key]['orders'][(int)$o['id']]=true;
 }
 uasort($totals,static fn($a,$b)=>strnatcasecmp($a['name'].' '.$a['presentation'],$b['name'].' '.$b['presentation']));return array_values($totals);
}
/** Read-only fridge sheets. James is stored as Jay in assigned_to. */
function ankhPrintPerson(string $assignedTo):string{
 $assignee=trim($assignedTo);
 if(in_array($assignee,['James','Jay'],true))return 'Jay';
 if($assignee==='Tony')return 'Tony';
 return 'Holding';
}
function ankhPrintPersonLabel(string $person):string{
 return in_array($person,['Jay','Tony','Holding','Everyone'],true)?$person:'Everyone';
}
function ankhPrintPersonFromRequest(string $person):string{
 return in_array($person,['Jay','Tony','Holding','Everyone'],true)?$person:'Everyone';
}
function ankhPrintSheetFromRequest(string $sheet):string{
 return $sheet==='delivery'?'delivery':'packing';
}
function ankhPrintClientName(array $order):string{
 $recipient=trim((string)($order['recipient_name']??''));
 return $recipient!==''?$recipient:trim((string)($order['customer']??''));
}
function ankhPrintFirstName(string $name):string{
 $name=trim(preg_replace('/\s+/',' ',$name));
 if($name==='')return 'Client';
 $parts=preg_split('/\s+/',$name) ?: [$name];
 return (string)($parts[0]??$name);
}
function ankhPrintOrderReference(array $order):string{
 return 'ANK-'.str_pad((string)(int)($order['id']??0),4,'0',STR_PAD_LEFT);
}
function ankhPrintOrderBalance(array $order):int{
 return max(0,(int)($order['total']??0)-(int)($order['paid_amount']??0));
}
function ankhPrintOrderLines(array $order):array{
 return ankhPackingCentreLines(is_array($order['lines']??null)?$order['lines']:[]);
}
function ankhPrintOrderItemsText(array $order):string{
 $parts=[];
 foreach(ankhPrintOrderLines($order) as $line){
  $label=(int)$line['quantity'].' × '.$line['name'];
  $presentation=trim((string)($line['presentation']??''));
  if($presentation!=='')$label.=' · '.$presentation;
  $parts[]=$label;
 }
 return implode(' · ',$parts);
}
/** Excludes back orders, cancelled and delivered orders. Undated orders stay in every date view. */
function ankhPrintSheetOrders(array $orders,string $person,string $date,bool $allUndelivered):array{
 $person=ankhPrintPersonFromRequest($person);$selected=[];
 foreach($orders as $order){
  if((int)($order['back_order']??0)===1)continue;
  if(in_array((string)($order['status']??''),['Cancelled','Delivered'],true))continue;
  if(trim((string)($order['delivery_date']??''))!=='')continue;
  if($person!=='Everyone'&&ankhPrintPerson((string)($order['assigned_to']??''))!==$person)continue;
  if(!$allUndelivered){
   $scheduled=trim((string)($order['scheduled_delivery_date']??''));
   if($scheduled!==''&&$scheduled>$date)continue;
  }
  $selected[]=$order;
 }
 usort($selected,static function(array $a,array $b):int{
  return strcmp((string)($a['scheduled_delivery_date']??''),(string)($b['scheduled_delivery_date']??''))
   ?: strcmp((string)($a['created']??''),(string)($b['created']??''))
   ?: (int)($a['id']??0)<=>(int)($b['id']??0);
 });
 return $selected;
}
function ankhPrintSheetTotals(array $orders):array{
 return ankhPackingCentreTotals($orders);
}
function ankhPrintSheetTitle(string $sheet,string $person,string $date,bool $allUndelivered):string{
 $type=$sheet==='delivery'?'Delivery sheet':'Packing list';
 $when=$allUndelivered?'All undelivered':date('D j M Y',strtotime($date) ?: time());
 return $type.' · '.ankhPrintPersonLabel($person).' · '.$when;
}
/** Loads saved order lines for display without changing orders, stock or payments. */
function ankhPrintAttachLines(PDO $db,array $orders):array{
 $ids=array_values(array_unique(array_filter(array_map(static fn($order)=>(int)($order['id']??0),$orders),static fn($id)=>$id>0)));
 if(!$ids)return $orders;
 $placeholders=implode(',',array_fill(0,count($ids),'?'));
 $q=$db->prepare("SELECT id,order_id,product_id,name,quantity,presentation FROM items WHERE order_id IN ($placeholders) ORDER BY id");
 $q->execute($ids);
 $lines=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $line)$lines[(int)$line['order_id']][]=$line;
 foreach($orders as &$order)$order['lines']=$lines[(int)($order['id']??0)]??[];
 unset($order);
 return $orders;
}
