<?php
/**
 * ANKH Premium V2 packing desk. Included from authenticated index.php only.
 * All order lifecycle changes still use the existing server-validated forms.
 */
if (!($auth??false) || !($db instanceof PDO)) {http_response_code(403);exit('Forbidden');}
if (!($premiumV2Preview??false)){?>
 <div class="heading"><div><h1>Packing station</h1><p class="muted">Available within the optional Premium V2 interface.</p></div></div>
 <a class="quick-action" href="?view=packing&amp;premium=1">Open Premium V2 packing station →</a>
<?php return;}
$pvSelection=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$pvSelectedOrder=null;
if($pvSelection!==false && $pvSelection!==null){
 foreach($orders as $pvOrder){if((int)$pvOrder['id']===(int)$pvSelection){$pvSelectedOrder=$pvOrder;break;}}
}
$pvCatalogueMeta=ankhCatalogueContent($db);
$pvProductById=[];$pvProductByName=[];
foreach($products as $pvProduct){$pvProductById[(int)$pvProduct['id']]=$pvProduct;$pvProductByName[strtolower(trim((string)$pvProduct['name']))]=$pvProduct;}
$pvPhotoId=static function(array $line)use($pvProductById,$pvProductByName,$pvCatalogueMeta):int{
 $id=(int)($line['product_id']??0);
 if($id===0){$found=$pvProductByName[strtolower(trim((string)($line['name']??'')))]??null;$id=$found?(int)$found['id']:0;}
 return $id>0&&(int)($pvCatalogueMeta[$id]['image_bytes']??0)>0?$id:0;
};
$pvReadableDate=static function(string $date):string{
 if(trim($date)==='')return 'Not scheduled';
 $time=strtotime($date);return $time===false?'Not scheduled':date('D j M Y',$time);
};
?>
<div class="packing-v2-workspace">
 <div class="packing-v2-page-head">
  <div><p class="eyebrow">ANKH / OPERATIONS V2</p><h1><?=$pvSelectedOrder?'Packing checklist':'Order journey'?></h1>
   <p class="muted"><?=$pvSelectedOrder?'Check each order line, then use the existing preparation and delivery controls.':'Your work from preparation through delivery, with payment tracked separately.'?></p></div>
  <div class="packing-v2-page-actions">
   <a href="?view=packing" class="packing-v2-link <?=$pvSelectedOrder?'':'current'?>">Workflow board</a>
   <a href="?view=orders" class="packing-v2-link">All orders →</a>
  </div>
 </div>
<?php if($pvSelectedOrder):
 $pvOrder=$pvSelectedOrder;$pvOrderId=(int)$pvOrder['id'];
 $pvLinesQ=$db->prepare('SELECT id,order_id,product_id,name,quantity,presentation,price FROM items WHERE order_id=? ORDER BY id');
 $pvLinesQ->execute([$pvOrderId]);$pvLines=$pvLinesQ->fetchAll(PDO::FETCH_ASSOC);
 $pvChecked=ankhPackingCheckedIds($db,$pvOrderId,$pvLines);
 $pvState=orderPreparationState($pvOrder);
 $pvClosed=$pvState==='closed';
 $pvPickLocked=$pvClosed||(string)$pvOrder['status']==='Dispatched';
 $pvPicked=count($pvChecked);$pvTotal=count($pvLines);
 $pvBalance=max(0,(int)$pvOrder['total']-(int)($pvOrder['paid_amount']??0));
 $pvDelivered=(string)$pvOrder['status']==='Delivered'||trim((string)($pvOrder['delivery_date']??''))!=='';
 $pvStageIndex=$pvDelivered?4:((string)$pvOrder['status']==='Dispatched'?3:($pvState==='packaged'?3:($pvState==='reconstituted'?2:1)));
?>
 <nav class="packing-v2-breadcrumb" aria-label="Breadcrumb"><a href="?view=packing">Packing station</a><span aria-hidden="true">›</span><span>ANK-<?=str_pad((string)$pvOrderId,4,'0',STR_PAD_LEFT)?></span></nav>
 <section class="packing-v2-order-hero">
  <div><span class="packing-v2-ref">ANK-<?=str_pad((string)$pvOrderId,4,'0',STR_PAD_LEFT)?></span>
   <h2><?=e(orderDisplayName($pvOrder))?></h2>
   <p>Ordered by <?=e((string)$pvOrder['customer'])?> · <?=e($pvReadableDate((string)($pvOrder['created']??'')))?></p>
   <div class="packing-v2-order-badges">
    <span><?=e(trim((string)($pvOrder['assigned_to']??''))?:'Unassigned')?></span>
    <span><?=e(trim((string)($pvOrder['delivery_method']??''))?:'Delivery method not set')?></span>
    <?php if((string)($pvOrder['scheduled_delivery_date']??'')!==''):?><span>Planned <?=e($pvReadableDate((string)$pvOrder['scheduled_delivery_date']))?></span><?php endif;?>
   </div>
  </div>
  <div class="packing-v2-order-total"><span>Order value</span><strong><?=money((int)$pvOrder['total'])?></strong>
   <small><?=$pvBalance>0?money($pvBalance).' outstanding':((int)$pvOrder['total']===0?'No payment required':'Paid in full')?></small></div>
 </section>
 <section class="packing-v2-journey" aria-label="Order preparation journey">
  <?php foreach([1=>'To prepare',2=>'Reconstituted',3=>'Packaged',4=>'Delivered'] as $step=>$caption):?>
   <div class="packing-v2-journey-step <?=$pvStageIndex>=$step?'done':''?> <?=$pvStageIndex===$step?'current':''?>">
    <span class="packing-v2-journey-dot"><?=$pvStageIndex>$step?'✓':$step?></span><span><?=e($caption)?></span></div>
  <?php endforeach;?>
 </section>
 <div class="packing-v2-columns">
  <section class="packing-v2-panel" id="packing-lines">
   <div class="packing-v2-panel-head"><div><p class="eyebrow">PICK LIST</p><h2>Products to prepare</h2></div><span class="packing-v2-counter"><?=$pvPicked?> / <?=$pvTotal?> checked</span></div>
   <div class="packing-v2-progress" role="progressbar" aria-label="Packing checklist completed" aria-valuemin="0" aria-valuemax="<?=$pvTotal?>" aria-valuenow="<?=$pvPicked?>"><span style="width:<?=$pvTotal?(int)round(100*$pvPicked/$pvTotal):0?>%"></span></div>
   <p class="packing-v2-helper">Each tick is saved for both admins. A pick check does not reconstitute, package, deliver or charge the order.</p>
   <?php if(!$pvLines):?><p class="packing-v2-empty">No products are on this order. Open the original order to review its lines.</p><?php endif;?>
   <div class="packing-v2-line-list">
   <?php foreach($pvLines as $pvLine):$pvItemId=(int)$pvLine['id'];$pvIsChecked=!empty($pvChecked[$pvItemId]);$pvImageId=$pvPhotoId($pvLine);?>
    <article class="packing-v2-line <?=$pvIsChecked?'is-picked':''?>">
     <div class="packing-v2-line-photo">
      <?php if($pvImageId):?><img src="?api=catalogue-image&amp;id=<?=$pvImageId?>" alt="" loading="lazy" decoding="async" data-packing-photo><?php endif;?>
      <span class="packing-v2-fallback" <?=$pvImageId?'hidden':''?> aria-hidden="true">☥</span>
     </div>
     <div class="packing-v2-line-info"><strong><?=e((string)$pvLine['name'])?></strong>
      <span><?=max(1,(int)$pvLine['quantity'])?> unit<?=((int)$pvLine['quantity']===1)?'':'s'?><?=trim((string)($pvLine['presentation']??''))!==''?' · '.e((string)$pvLine['presentation']):''?></span>
      <small><?=$pvIsChecked?'Picked for packing':'Awaiting pick check'?></small>
     </div>
     <?php if(!$pvPickLocked):?><form method="post" class="packing-v2-check-form">
      <?php csrf();?><input type="hidden" name="action" value="packing_pick"><input type="hidden" name="id" value="<?=$pvOrderId?>"><input type="hidden" name="item_id" value="<?=$pvItemId?>"><input type="hidden" name="picked" value="<?=$pvIsChecked?'0':'1'?>"><input type="hidden" name="return" value="packing">
      <button class="<?=$pvIsChecked?'is-checked':''?>" type="submit" aria-label="<?=$pvIsChecked?'Uncheck':'Mark picked'?> <?=e((string)$pvLine['name'])?>"><span aria-hidden="true"><?=$pvIsChecked?'✓':'○'?></span><span><?=$pvIsChecked?'Picked':'Pick'?></span></button>
     </form><?php else:?><span class="packing-v2-readonly-state"><?=$pvIsChecked?'✓ Picked':'Not picked'?></span><?php endif;?>
    </article>
   <?php endforeach;?>
   </div>
  </section>
  <section class="packing-v2-panel">
   <div class="packing-v2-panel-head"><div><p class="eyebrow">NEXT ACTIONS</p><h2>Preparation &amp; delivery</h2></div></div>
   <?php if($pvClosed):?><p class="packing-v2-helper"><?=((string)$pvOrder['status']==='Cancelled')?'Order cancelled':($pvDelivered?'Delivery already recorded':'This order is no longer active')?>. Preparation controls are unavailable.</p>
   <?php elseif(isBackOrder($pvOrder)):?><p class="packing-v2-helper">Move this order from back orders to active orders before preparing it.</p>
   <?php else:?>
    <?php if((string)$pvOrder['status']==='Dispatched'):?><p class="packing-v2-helper">Dispatched — pick checks and preparation changes are locked, but actual delivery can still be confirmed.</p><?php else:?><div class="packing-v2-preparation-controls"><?php dashboardPreparationControls($pvOrder,'packing');?></div><?php endif;?>
    <p class="packing-v2-helper">Keep the actual preparation stage accurate. Packaging remains an explicit separate action.</p>
    <details class="packing-v2-delivery-disclosure"><summary>Confirm actual delivery <span aria-hidden="true">›</span></summary>
     <form method="post" onsubmit="return confirm('Confirm this order was actually delivered on the selected date? Unpaid balances will remain outstanding.');">
      <?php csrf();?><input type="hidden" name="action" value="delivery_mark"><input type="hidden" name="id" value="<?=$pvOrderId?>"><input type="hidden" name="return" value="packing">
      <label>Actual delivery date<input type="date" name="delivery_date" max="<?=e($now->format('Y-m-d'))?>" value="<?=e($now->format('Y-m-d'))?>" required></label>
      <button type="submit">Confirm delivered</button>
      <small>This records a delivery, not a payment. Select the actual date.</small>
     </form>
    </details>
   <?php endif;?>
   <div class="packing-v2-payment">
    <span>Payment status</span><strong><?=$pvBalance>0?'Outstanding: '.money($pvBalance):((int)$pvOrder['total']===0?'Free / no payment required':'Paid in full')?></strong>
    <small>Payment is independent of preparation and delivery.</small>
   </div>
   <div class="packing-v2-nav-tiles"><a href="?view=orders&amp;search=ANK-<?=$pvOrderId?>">Open original order →</a><a href="?view=edit&amp;id=<?=$pvOrderId?>">Edit order details →</a></div>
  </section>
 </div>
<?php else:
 $pvGroups=['pending'=>[],'reconstituted'=>[],'packaged'=>[],'delivered'=>[]];$pvCount=['pending'=>0,'reconstituted'=>0,'packaged'=>0,'delivered'=>0];$pvUnpaid=0;
 foreach($orders as $pvOrder){
  if(isBackOrder($pvOrder)||(string)$pvOrder['status']==='Cancelled')continue;
  $pvState=orderPreparationState($pvOrder);
  $pvBucket=$pvState==='closed'?'delivered':((string)$pvOrder['status']==='Dispatched'?'packaged':$pvState);
  if(!isset($pvGroups[$pvBucket]))continue;
  $pvCount[$pvBucket]++;
  if($pvBucket!=='delivered'&&max(0,(int)$pvOrder['total']-(int)($pvOrder['paid_amount']??0))>0)$pvUnpaid++;
  if(count($pvGroups[$pvBucket])<30)$pvGroups[$pvBucket][]=$pvOrder;
 }
 $pvBoardIds=[];foreach($pvGroups as $pvGroup)foreach($pvGroup as $pvOrder)$pvBoardIds[]=(int)$pvOrder['id'];
 $pvBoardItems=[];
 if($pvBoardIds){
  $ph=implode(',',array_fill(0,count($pvBoardIds),'?'));
  $q=$db->prepare("SELECT order_id,product_id,name,quantity,presentation FROM items WHERE order_id IN ($ph) ORDER BY id");$q->execute($pvBoardIds);
  foreach($q as $line){$id=(int)$line['order_id'];$pvBoardItems[$id]??=[];if(count($pvBoardItems[$id])<3)$pvBoardItems[$id][]=$line;}
 }
?>
 <div class="packing-v2-stat-strip">
  <div><span>To prepare</span><strong><?=$pvCount['pending']?></strong></div>
  <div><span>Reconstituted</span><strong><?=$pvCount['reconstituted']?></strong></div>
  <div><span>Ready to deliver</span><strong><?=$pvCount['packaged']?></strong></div>
  <div><span>Outstanding balances</span><strong><?=$pvUnpaid?></strong><small>Undelivered orders</small></div>
 </div>
 <nav class="packing-v2-jump-nav" aria-label="Preparation stages">
  <a href="#packing-v2-pending">To prepare <?=$pvCount['pending']?></a>
  <a href="#packing-v2-reconstituted">Reconstituted <?=$pvCount['reconstituted']?></a>
  <a href="#packing-v2-packaged">Packaged <?=$pvCount['packaged']?></a>
  <a href="#packing-v2-delivered">Delivered <?=$pvCount['delivered']?></a>
 </nav>
 <div class="packing-v2-board">
 <?php foreach(['pending'=>['To prepare','Awaiting preparation'],'reconstituted'=>['Reconstituted','Ready for packaging'],'packaged'=>['Packaged','Ready for actual delivery'],'delivered'=>['Delivered','Completed orders']] as $bucket=>$details):?>
  <section class="packing-v2-column" id="packing-v2-<?=e($bucket)?>">
   <div class="packing-v2-column-head"><div><p class="eyebrow"><?=e(strtoupper($bucket))?></p><h2><?=e($details[0])?></h2><small><?=e($details[1])?></small></div><b><?=$pvCount[$bucket]?></b></div>
   <div class="packing-v2-card-list">
    <?php foreach($pvGroups[$bucket] as $pvOrder):$pvId=(int)$pvOrder['id'];$pvOrderLines=$pvBoardItems[$pvId]??[];$pvBalance=max(0,(int)$pvOrder['total']-(int)($pvOrder['paid_amount']??0));?>
    <a class="packing-v2-card" href="?view=packing&amp;id=<?=$pvId?>">
     <div class="packing-v2-card-top"><span>ANK-<?=str_pad((string)$pvId,4,'0',STR_PAD_LEFT)?></span><span><?=e(trim((string)($pvOrder['assigned_to']??''))?:'Unassigned')?></span></div>
     <strong class="packing-v2-card-name"><?=e(orderDisplayName($pvOrder))?></strong>
     <?php if(strcasecmp(trim((string)$pvOrder['customer']),orderDisplayName($pvOrder))!==0):?><small class="packing-v2-card-buyer">Ordered by <?=e((string)$pvOrder['customer'])?></small><?php endif;?>
     <div class="packing-v2-card-products">
      <?php foreach($pvOrderLines as $pvLine):$pvImageId=$pvPhotoId($pvLine);?>
       <span class="packing-v2-card-line"><span class="packing-v2-card-image"><?php if($pvImageId):?><img src="?api=catalogue-image&amp;id=<?=$pvImageId?>" alt="" loading="lazy" decoding="async" data-packing-photo><?php endif;?><span class="packing-v2-fallback" <?=$pvImageId?'hidden':''?> aria-hidden="true">☥</span></span><span><?=max(1,(int)$pvLine['quantity'])?> × <?=e((string)$pvLine['name'])?></span></span>
      <?php endforeach;?>
     </div>
     <div class="packing-v2-card-footer"><span><?=$pvBalance>0?'£'.number_format($pvBalance/100,2).' unpaid':((int)$pvOrder['total']===0?'No payment required':'Paid')?></span><span><?=trim((string)($pvOrder['scheduled_delivery_date']??''))!==''?'Due '.e($pvReadableDate((string)$pvOrder['scheduled_delivery_date'])):e(date('d M',strtotime((string)$pvOrder['created'])))?></span></div>
     <span class="packing-v2-card-action">Open packing checklist →</span>
    </a>
    <?php endforeach;?>
    <?php if(!$pvGroups[$bucket]):?><p class="packing-v2-empty">No orders in this stage.</p><?php endif;?>
   </div>
   <?php if($pvCount[$bucket]>count($pvGroups[$bucket])):?><p class="packing-v2-more">Showing <?=count($pvGroups[$bucket])?> of <?=$pvCount[$bucket]?>. <a href="?view=orders">See all orders</a></p><?php endif;?>
  </section>
 <?php endforeach;?>
 </div>
 <p class="packing-v2-board-foot">The order board reflects existing preparation, delivery and payment records. Checking a product never changes stock or order status.</p>
<?php endif;?>
</div>
<script>
document.querySelectorAll('[data-packing-photo]').forEach(image=>image.addEventListener('error',()=>{
 image.hidden=true;const fallback=image.parentElement?.querySelector('.packing-v2-fallback');if(fallback)fallback.hidden=false;
},{once:true}));
</script>
