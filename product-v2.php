<?php
/**
 * ANKH Premium V2 — authenticated product workspace.
 * Read-only display. Prices and stock are updated in the existing Products editor.
 * The caller has already authenticated the administrator and loaded $products,
 * $reservedStockByProduct, $reservationDetailsByProduct and $incomingByProduct.
 */
if (!($auth??false) || !isset($db,$products) || !($db instanceof PDO)) {
 http_response_code(403);
 exit('Forbidden');
}
$productV2Id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$productV2Id && isset($_GET['id']) && is_scalar($_GET['id']) && ctype_digit((string)$_GET['id']))$productV2Id=(int)$_GET['id'];
$productV2=null;
if($productV2Id){foreach($products as $item){if((int)$item['id']===(int)$productV2Id){$productV2=$item;break;}}}
?>
<?php if(!$productV2):?>
 <div class="heading"><div><p class="eyebrow">PRODUCT WORKSPACE</p><h1>Product not found</h1><p class="muted">This product may have been removed or its reference has changed.</p></div></div>
 <a class="product-v2-back" href="?view=products">← Back to products</a>
<?php else:
 $pvId=(int)$productV2['id'];
 $pvName=(string)$productV2['name'];
 $pvCatalogue=ankhCatalogueContent($db);
 $pvContent=$pvCatalogue[$pvId]??[];
 $pvHasImage=(int)($pvContent['image_bytes']??0)>0;
 $pvTracked=(int)($productV2['stock_tracking']??0)===1;
 $pvActive=(int)($productV2['active']??0)===1;
 $pvPrice=(int)$productV2['price'];
 $pvFamily=$productV2['family_friends_price']===null?max(0,$pvPrice-500):(int)$productV2['family_friends_price'];
 $pvCost=$productV2['cost']===null?null:(int)$productV2['cost'];
 $pvLow=max(0,(int)($productV2['low_stock_at']??5));
 $pvStock=[
  'Jay'=> (int)($productV2['stock_jay_qty']??0),
  'Tony'=> (int)($productV2['stock_tony_qty']??0),
  'Unallocated'=> (int)($productV2['stock_unallocated_qty']??0)
 ];
 $pvReservedByLocation=$reservedStockByProduct[$pvId]??[];
 $pvReserved=array_sum(array_map('intval',$pvReservedByLocation));
 $pvAvailable=array_sum($pvStock);
 $pvPhysical=$pvAvailable+$pvReserved;
 $pvIncoming=max(0,(int)($incomingByProduct[$pvId]??0));
 $pvLowState=$pvTracked && $pvAvailable<=$pvLow;
 $pvRecentQuery=$db->prepare("SELECT o.id,o.created,o.customer,o.status,o.delivery_date,o.assigned_to,i.quantity,i.price,i.presentation
 FROM items i JOIN orders o ON o.id=i.order_id
 WHERE i.product_id=:product_id OR (i.product_id IS NULL AND lower(trim(i.name))=lower(trim(:product_name)))
 ORDER BY datetime(o.created) DESC,o.id DESC,i.id DESC LIMIT 12");
 $pvRecentQuery->execute([':product_id'=>$pvId,':product_name'=>$pvName]);
 $pvOrders=$pvRecentQuery->fetchAll(PDO::FETCH_ASSOC);
 $pvThirtyQuery=$db->prepare("SELECT COALESCE(SUM(i.quantity),0) FROM items i JOIN orders o ON o.id=i.order_id
 WHERE (i.product_id=:product_id OR (i.product_id IS NULL AND lower(trim(i.name))=lower(trim(:product_name))))
 AND o.status<>'Cancelled' AND o.back_order=0 AND datetime(o.created)>=datetime('now','-30 days')");
 $pvThirtyQuery->execute([':product_id'=>$pvId,':product_name'=>$pvName]);
 $pvLast30=(int)$pvThirtyQuery->fetchColumn();
 $pvMovementsQuery=$db->prepare("SELECT sm.created,sm.movement_type,sm.quantity_change,sm.location,sm.order_id FROM stock_movements sm WHERE sm.product_id=? ORDER BY datetime(sm.created) DESC,sm.id DESC LIMIT 8");
 $pvMovementsQuery->execute([$pvId]);
 $pvMovements=$pvMovementsQuery->fetchAll(PDO::FETCH_ASSOC);
 $pvDetails=$reservationDetailsByProduct[$pvId]??[];
 $pvPack=max(1,(int)($productV2['supplier_pack_size']??10));
 $pvStrength='';
 if(preg_match('/\s+(\d+(?:\.\d+)?\s*(?:mg|ml|iu))$/i',$pvName,$pvMatch))$pvStrength=trim($pvMatch[1]);
?>
<div class="product-v2-workspace">
 <nav class="product-v2-breadcrumb" aria-label="Breadcrumb"><a href="?view=products">Products</a><span aria-hidden="true">›</span><span><?=e($pvName)?></span></nav>
 <div class="product-v2-workspace-top">
  <div><p class="eyebrow">ANKH / PRODUCT WORKSPACE</p><h1><?=e($pvName)?></h1><p class="muted">Prices, availability, reservations and order activity in one place.</p></div>
  <div style="display:flex;gap:9px;flex-wrap:wrap;justify-content:flex-end"><a class="product-v2-edit-link" href="?view=website_cms&amp;id=<?=$pvId?>#cms-edit">Edit website content <span aria-hidden="true">↗</span></a><a class="product-v2-edit-link" href="?view=products&amp;edit_product=<?=$pvId?>">Edit product settings <span aria-hidden="true">↗</span></a></div>
 </div>
 <section class="product-v2-hero" aria-label="Product overview">
  <div class="product-v2-photo" aria-label="<?=e($pvName)?> product photograph">
   <?php if($pvHasImage):?><img src="?api=catalogue-image&amp;id=<?=$pvId?>" alt="<?=e($pvName)?>" loading="eager" decoding="async" data-product-v2-photo><?php endif;?>
   <span class="product-v2-photo-placeholder" <?=$pvHasImage?'hidden':''?> aria-hidden="true">☥</span>
   <span class="product-v2-photo-caption">ANKH PEPTIDES <span>PRODUCT <?=$pvId?></span></span>
  </div>
  <div class="product-v2-hero-details">
   <div class="product-v2-status-row">
    <span class="product-v2-pill <?=$pvActive?'is-active':'is-hidden'?>"><?=$pvActive?'Active product':'Hidden product'?></span>
    <span class="product-v2-pill <?=$pvTracked?($pvLowState?'is-low':'is-good'):'is-hidden'?>"><?=$pvTracked?($pvLowState?'Stock attention':'Tracked stock healthy'):'Stock not tracked'?></span>
   </div>
   <p class="product-v2-kicker"><?=e($pvStrength?:'Product')?></p>
   <h2><?=e($pvName)?></h2>
   <p class="product-v2-price"><?=money($pvPrice)?><small> retail price</small></p>
   <div class="product-v2-price-columns">
    <div><span>Family &amp; Friends</span><strong><?=money($pvFamily)?></strong></div>
    <div><span>Unit cost</span><strong><?=$pvCost===null?'Not set':money($pvCost)?></strong></div>
    <div><span>Indicative unit margin</span><strong><?=$pvCost===null?'Not available':money($pvPrice-$pvCost)?></strong></div>
   </div>
   <p class="product-v2-price-note">Indicative margin is retail minus recorded product cost, before delivery, fees and other expenses. Existing orders retain their saved prices.</p>
   <div class="product-v2-hero-actions">
    <a class="product-v2-primary-action" href="?view=new">Create order <span aria-hidden="true">↗</span></a>
    <a class="product-v2-secondary-action" href="?view=stock">Open stock control <span aria-hidden="true">›</span></a>
   </div>
  </div>
 </section>
 <div class="product-v2-metrics" aria-label="Product summary">
  <div class="product-v2-metric"><span>Available units</span><strong><?=$pvTracked?$pvAvailable:'—'?></strong><small><?=$pvTracked?'Jay + Tony + unallocated':'Not tracked'?></small></div>
  <div class="product-v2-metric"><span>Reserved units</span><strong><?=$pvTracked?$pvReserved:'—'?></strong><small>On undelivered orders</small></div>
  <div class="product-v2-metric"><span>Incoming units</span><strong><?=$pvIncoming?></strong><small>Outstanding supplier items</small></div>
  <div class="product-v2-metric"><span>Ordered in 30 days</span><strong><?=$pvLast30?></strong><small>Excludes cancelled / back orders</small></div>
 </div>
 <div class="product-v2-columns">
  <section class="product-v2-section">
   <div class="product-v2-section-head"><div><p class="eyebrow">INVENTORY</p><h2>Where your stock is</h2></div><a href="?view=stock">View stock →</a></div>
   <?php if($pvTracked):?>
    <div class="product-v2-location-list">
    <?php foreach($pvStock as $pvLocation=>$pvQty):$pvLocReserved=max(0,(int)($pvReservedByLocation[strtolower($pvLocation)]??0));?>
     <div class="product-v2-location"><div class="product-v2-location-head"><strong><?=e($pvLocation==='Unallocated'?'Unallocated holding':$pvLocation.'’s fridge')?></strong><span><b><?=$pvQty?></b> available</span></div>
      <div class="product-v2-location-track"><i style="width:<?=max(0,min(100,(int)round(($pvQty>0?max(0,$pvQty):0)/max(1,$pvPhysical)*100)))?>%"></i></div>
      <small><?=$pvLocReserved?> reserved · <?=$pvQty+$pvLocReserved?> physical<?=$pvQty<0?' · '.abs($pvQty).' short':''?></small>
     </div>
    <?php endforeach;?>
    </div>
    <div class="product-v2-stock-foot"><span>Low-stock alert at <strong><?=$pvLow?></strong> available</span><span>Supplier pack <strong><?=$pvPack?></strong></span></div>
    <div class="product-v2-stock-foot"><span>Combined physical <strong><?=$pvPhysical?></strong></span><span>Incoming <strong><?=$pvIncoming?></strong></span></div>
   <?php else:?><p class="product-v2-empty">This product is not tracked in Stock Control. You can enable tracking using the existing product editor.</p><?php endif;?>
  </section>
  <section class="product-v2-section">
   <div class="product-v2-section-head"><div><p class="eyebrow">COMMITMENTS</p><h2>Reserved for orders</h2></div><span><?=$pvReserved?> units</span></div>
   <?php if($pvDetails):?><div class="product-v2-list">
    <?php foreach(array_slice($pvDetails,0,8) as $pvReservation):?>
     <a class="product-v2-item" href="?view=orders&amp;search=ANK-<?=(int)$pvReservation['order_id']?>">
      <div><strong><?=e((string)$pvReservation['customer'])?></strong><small>ANK-<?=str_pad((string)(int)$pvReservation['order_id'],4,'0',STR_PAD_LEFT)?> · <?=e((string)$pvReservation['location'])?></small></div>
      <b><?=max(0,(int)$pvReservation['quantity'])?> reserved</b>
     </a>
    <?php endforeach;?>
    </div>
    <?php if(count($pvDetails)>8):?><p class="product-v2-note">Showing 8 of <?=count($pvDetails)?> reservations. Stock Control has the complete list.</p><?php endif;?>
   <?php else:?><p class="product-v2-empty">No open orders currently reserve this product.</p><?php endif;?>
  </section>
 </div>
 <section class="product-v2-section">
  <div class="product-v2-section-head"><div><p class="eyebrow">CUSTOMER ACTIVITY</p><h2>Recent orders</h2></div><span>Latest 12 lines</span></div>
  <?php if($pvOrders):?><div class="product-v2-list">
   <?php foreach($pvOrders as $pvOrder):?>
    <a class="product-v2-item product-v2-order-row" href="?view=orders&amp;search=ANK-<?=(int)$pvOrder['id']?>">
     <div><strong><?=e((string)$pvOrder['customer'])?></strong><small>ANK-<?=str_pad((string)(int)$pvOrder['id'],4,'0',STR_PAD_LEFT)?> · <?=e((string)$pvOrder['created'])?></small></div>
     <div class="product-v2-order-state"><b><?=max(0,(int)$pvOrder['quantity'])?> × <?=money((int)$pvOrder['price'])?></b><small><?=e((string)$pvOrder['status'])?><?=trim((string)$pvOrder['presentation'])!==''?' · '.e((string)$pvOrder['presentation']):''?></small></div>
    </a>
   <?php endforeach;?>
  </div><?php else:?><p class="product-v2-empty">No orders recorded for this product yet.</p><?php endif;?>
 </section>
 <section class="product-v2-section">
  <div class="product-v2-section-head"><div><p class="eyebrow">AUDIT TRAIL</p><h2>Stock movements</h2></div><a href="?view=stock&amp;section=history">Full history →</a></div>
  <?php if($pvMovements):?><div class="product-v2-list">
   <?php foreach($pvMovements as $pvMovement):?><div class="product-v2-item product-v2-movement">
    <div><strong><?=e((string)$pvMovement['movement_type'])?></strong><small><?=e((string)$pvMovement['location'])?> · <?=e((string)$pvMovement['created'])?></small></div><b><?=$pvMovement['quantity_change']>0?'+':''?><?=(int)$pvMovement['quantity_change']?></b>
   </div><?php endforeach;?>
  </div><?php else:?><p class="product-v2-empty">No recorded movements for this product.</p><?php endif;?>
 </section>
</div>
<script>
document.querySelectorAll('[data-product-v2-photo]').forEach(img=>img.addEventListener('error',()=>{
 img.hidden=true;const fallback=img.parentElement?.querySelector('.product-v2-photo-placeholder');
 if(fallback)fallback.hidden=false;
},{once:true}));
</script>
<?php endif;?>
