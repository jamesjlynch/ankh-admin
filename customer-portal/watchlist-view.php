<?php
declare(strict_types=1);
if(!defined('ANKH_PORTAL_VIEW')||empty($customer)||!empty($isDemo)){http_response_code(404);exit;}
$watchlistError=false;$watched=[];$products=[];$availability=[];
try{
    $products=ankhCatalogueProducts($admin,$customerId);
    $availability=portalExtrasAvailabilityMap($portal);
    foreach(portalWatches($portal,$customerId,$staff) as $watch){
        $product=portalCollectionProduct($products,(int)$watch['product_id']);
        if(!$product)continue;
        $watched[]=['product'=>$product,'state'=>portalExtrasAvailability($product,$availability),'watch'=>$watch];
    }
}catch(Throwable $e){error_log('ANKH watchlist view: '.$e->getMessage());$watchlistError=true;}
$flash=(string)($_SESSION['collection_flash']??'');unset($_SESSION['collection_flash']);
?>
<section class="pc-watch-page">
<a class="pc-back" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">← Back to catalogue</a>
<section class="pc-page-heading"><span class="pc-eyebrow">PRODUCT AVAILABILITY</span><h1>My watchlist<span class="pc-dot">.</span></h1><p>Follow products you are interested in. You'll see a private notification in your account when the portal detects they're available again.</p></section>
<?php if($flash!==''):?><p class="pc-notification-feedback" role="status"><?=portalEscape($flash)?></p><?php endif;?>
<div class="pc-watch-disclaimer"><?=pcIcon('bell')?><div><strong>How notifications work</strong><p>Availability is checked when you visit your ANKH account. Alerts appear in Notifications, not by SMS or phone push. If stock changes while you're away, the portal will detect its current status on your next visit.</p></div></div>
<?php if($watchlistError):?><div class="pc-panel pc-empty" role="alert"><h2>Watchlist temporarily unavailable</h2></div>
<?php elseif(!$watched):?><section class="pc-panel pc-request-empty"><span aria-hidden="true">☥</span><h2>Nothing on your watchlist yet</h2><p>Open an unavailable product in the catalogue and choose Notify me when available.</p><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>" class="pc-primary-link">Explore the catalogue <?=pcIcon('right')?></a></section>
<?php else:?>
<div class="pc-watch-grid">
<?php foreach($watched as $item):$p=$item['product'];$state=$item['state'];?>
<article class="pc-watch-card">
  <div class="pc-watch-mark">☥</div>
  <div class="pc-watch-copy"><span class="pc-eyebrow"><?=portalEscape($p['category'])?></span><h2><?=portalEscape($p['name'])?></h2>
    <span class="pcs-availability <?='pcs-status-'.$state['tone']?>"><?=portalEscape($state['label'])?></span>
    <p><?=$state['tone']==='available'||$state['tone']==='limited'?'This product is currently listed as available.':'We are watching this item for you.'?></p>
    <div class="pc-watch-actions">
      <a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['product'=>$p['id']]))?>">View product <?=pcIcon('right')?></a>
      <form method="post"><input type="hidden" name="csrf" value="<?=portalEscape($_SESSION['portal_csrf'])?>"><input type="hidden" name="action" value="watch_set"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="enabled" value="0"><input type="hidden" name="return_to" value="watchlist"><button type="submit">Stop watching</button></form>
    </div>
  </div>
</article>
<?php endforeach;?>
</div>
<a class="pc-outline-button" href="<?=portalEscape(pcHref('notifications',$staff,$customerId))?>">View account notifications <?=pcIcon('right')?></a>
<?php endif;?>
</section>
