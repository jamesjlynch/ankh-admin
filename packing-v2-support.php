<?php
declare(strict_types=1);

/**
 * ANKH Packing V2: server-side pick-check bookkeeping only.
 * Never touches inventory, order statuses, delivery records or payments.
 * Caller must authenticate and validate CSRF before calling ankhPackingToggle().
 */
function ankhPackingSignature(array $line):string {
 return hash('sha256',json_encode([
  (int)$line['id'],(int)$line['order_id'],(int)($line['product_id']??0),
  (string)$line['name'],(int)$line['quantity'],(string)($line['presentation']??'')
 ],JSON_THROW_ON_ERROR));
}
function ankhPackingEnsureSchema(PDO $db):void {
 $db->exec('CREATE TABLE IF NOT EXISTS order_packing_checks (
  order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  line_signature TEXT NOT NULL,
  checked_at TEXT NOT NULL,
  checked_by INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(order_id,item_id)
 )');
}
function ankhPackingToggle(PDO $db,int $orderId,int $itemId,bool $picked,int $actorId=0):void {
 if($orderId<1||$itemId<1)throw new InvalidArgumentException('Choose a valid order item.');
 $db->beginTransaction();
 try{
  $orderQ=$db->prepare('SELECT id,status,delivery_date,back_order FROM orders WHERE id=?');
  $orderQ->execute([$orderId]);$order=$orderQ->fetch(PDO::FETCH_ASSOC);
  if(!$order)throw new RuntimeException('Order not found.');
  if((int)$order['back_order']===1||in_array((string)$order['status'],['Cancelled','Delivered','Dispatched'],true)||trim((string)$order['delivery_date'])!=='')
   throw new RuntimeException('Packing checks are only available on active, undelivered orders.');
  $lineQ=$db->prepare('SELECT id,order_id,product_id,name,quantity,presentation FROM items WHERE id=? AND order_id=?');
  $lineQ->execute([$itemId,$orderId]);$line=$lineQ->fetch(PDO::FETCH_ASSOC);
  if(!$line)throw new RuntimeException('That item does not belong to this order.');
  if($picked){
   $q=$db->prepare('INSERT INTO order_packing_checks(order_id,item_id,line_signature,checked_at,checked_by)
    VALUES(?,?,?,?,?) ON CONFLICT(order_id,item_id) DO UPDATE SET
     line_signature=excluded.line_signature,checked_at=excluded.checked_at,checked_by=excluded.checked_by');
   $q->execute([$orderId,$itemId,ankhPackingSignature($line),gmdate('c'),max(0,$actorId)]);
  }else{
   $q=$db->prepare('DELETE FROM order_packing_checks WHERE order_id=? AND item_id=?');$q->execute([$orderId,$itemId]);
  }
  $db->commit();
 }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function ankhPackingCheckedIds(PDO $db,int $orderId,array $lines):array {
 if(!$lines)return [];
 $q=$db->prepare('SELECT item_id,line_signature FROM order_packing_checks WHERE order_id=?');
 $q->execute([$orderId]);$saved=$q->fetchAll(PDO::FETCH_KEY_PAIR);
 $checked=[];
 foreach($lines as $line){$id=(int)$line['id'];if(isset($saved[$id])&&hash_equals((string)$saved[$id],ankhPackingSignature($line)))$checked[$id]=true;}
 return $checked;
}
