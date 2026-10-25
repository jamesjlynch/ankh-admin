<?php
declare(strict_types=1);
if(!defined('ANKH_PORTAL_VIEW')||empty($customer)){http_response_code(404);exit;}
$shopProducts=[];$shopAvailability=[];$shopBasketQuantities=[];
if(empty($isDemo)&&isset($admin,$portal)){
    try{
        $shopProducts=array_slice(ankhCatalogueProducts($admin,$customerId),0,4);
        $shopAvailability=portalExtrasAvailabilityMap($portal);
        foreach(portalCollectionForCustomer($portal,$customerId,$staff,$shopProducts,$shopAvailability) as $entry)$shopBasketQuantities[(int)$entry['product']['id']]=(int)$entry['quantity'];
    }catch(Throwable $e){error_log('ANKH home catalogue: '.$e->getMessage());}
}
?>
<section class="pc-shop-hero" aria-labelledby="pc-shop-heading">
  <div class="pc-shop-hero-copy">
    <span class="pc-eyebrow">ANKH / PRIVATE CUSTOMER EXPERIENCE</span>
    <span class="pc-shop-greeting">Welcome back, <?=portalEscape((string)$firstName)?></span>
    <h1 id="pc-shop-heading">Welcome to your<br><em>ANKH account.</em></h1>
    <p>Everything in one place: follow recorded orders, review requests, manage your research inventory and explore the collection.</p>
    <div class="pc-shop-hero-actions">
      <a class="pc-primary-link" href="<?=portalEscape(pcHref('requests',$staff,$customerId))?>">Track my requests <?=pcIcon('right')?></a>
      <a class="pc-shop-secondary" href="<?=portalEscape(pcHref('orders',$staff,$customerId))?>"><?=pcIcon('orders')?> My orders</a>
    </div>
  </div>
  <div class="pc-shop-hero-art" aria-hidden="true"><span>☥</span><small>ANKH / RESEARCH COLLECTION</small></div>
</section>
<?php require __DIR__.'/account-activity.php'; ?>
<section class="pc-home-operating" aria-label="Your next step">
 <div class="pc-home-operating-copy"><span class="pc-eyebrow"><?=portalEscape((string)$homeFocus['eyebrow'])?></span><h2><?=portalEscape((string)$homeFocus['title'])?></h2><p><?=portalEscape((string)$homeFocus['body'])?></p></div>
 <a href="<?=portalEscape(pcHref((string)$homeFocus['tab'],$staff,$customerId,(array)$homeFocus['params']))?>" class="pc-primary-link"><?=portalEscape((string)$homeFocus['action'])?> <?=pcIcon('right')?></a>
</section>
<form class="pc-shop-search" method="get" action="<?=$staff?'./preview-live.php':(!empty($isDemo)?'./preview.php':'./')?>">
  <?php if($staff):?><input type="hidden" name="customer_id" value="<?=$customerId?>"><?php elseif(!empty($isDemo)):?><input type="hidden" name="screen" value="account"><?php endif;?>
  <input type="hidden" name="tab" value="catalogue">
  <?=pcIcon('search')?><label for="pc-shop-query" class="sr-only">Search products</label><input id="pc-shop-query" type="search" name="search" maxlength="80" placeholder="What are you looking for?" enterkeyhint="search"><button type="submit">Search <?=pcIcon('right')?></button>
</form>
<a class="pc-fridge-home-banner" href="<?=portalEscape(pcHref('fridge',$staff,$customerId))?>"><span><?=pcIcon('fridge')?></span><div><strong>My Fridge</strong><small>Keep track of what you have and see when your stock may run out</small></div><?=pcIcon('right')?></a>
<nav class="pc-shop-quick" aria-label="Popular shopping shortcuts">
  <a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>"><span><?=pcIcon('catalogue')?></span><strong>All products</strong><small>Explore the collection</small></a>
  <a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['saved'=>1]))?>"><span><?=pcIcon('check')?></span><strong>Saved favourites</strong><small>Products you love</small></a>
  <a href="<?=portalEscape(pcHref('watchlist',$staff,$customerId))?>"><span><?=pcIcon('bell')?></span><strong>Availability alerts</strong><small>Keep an eye on stock</small></a>
</nav>
<section class="pc-shop-featured" aria-label="Browse products">
  <div class="pc-section-header"><div><span class="pc-eyebrow">EXPLORE THE COLLECTION</span><h2>Shop the collection<span class="pc-dot">.</span></h2><p>A curated preview of products available in your account.</p></div><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">View all products <?=pcIcon('right')?></a></div>
  <?php if(!$shopProducts):?>
  <div class="pc-panel pc-empty"><h3><?=!empty($isDemo)?'Explore the sample collection':'Your collection is being prepared'?></h3><p>Published products will appear here. Open the catalogue to explore the full range.</p><a class="pc-primary-link" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Go to shop <?=pcIcon('right')?></a></div>
  <?php else:?><div class="pc-shop-featured-grid">
  <?php foreach($shopProducts as $p):$availability=portalExtrasAvailability($p,$shopAvailability);$canAdd=!in_array($availability['tone'],['coming','unavailable'],true);?>
  <article class="pc-shop-featured-card">
    <a class="pc-shop-featured-image" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['product'=>$p['id']]))?>">
      <?php if(!empty($p['has_photo'])):?><img src="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['catalogue_image'=>$p['id']]))?>" alt="<?=portalEscape($p['name'])?>" loading="lazy" width="340" height="340"><?php else:?><span aria-hidden="true">☥</span><?php endif;?>
    </a>
    <div class="pc-shop-featured-content"><small><?=portalEscape($p['category'])?></small><h3><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['product'=>$p['id']]))?>"><?=portalEscape($p['name'])?></a></h3>
    <span class="pcs-availability pcs-status-<?=$availability['tone']?>"><?=portalEscape($availability['label'])?></span><div class="pc-shop-featured-foot"><strong><?=portalEscape(portalMoney($p['price']))?></strong>
    <?php if($canAdd):?><form method="post"><input type="hidden" name="csrf" value="<?=portalEscape($_SESSION['portal_csrf'])?>"><input type="hidden" name="action" value="collection_set"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="quantity" value="<?=min(99,1+(int)($shopBasketQuantities[$p['id']]??0))?>"><input type="hidden" name="return_to" value="home"><button type="submit" aria-label="Add <?=portalEscape($p['name'])?> to basket"><?=pcIcon('basket')?> <?=!empty($shopBasketQuantities[$p['id']])?'Add another':'Add'?></button></form><?php else:?><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['product'=>$p['id']]))?>">View <?=pcIcon('right')?></a><?php endif;?>
    </div></div>
  </article>
  <?php endforeach;?></div><?php endif;?>
</section>
<section class="pc-shop-account-strip" aria-label="Your account at a glance">
  <div class="pc-shop-account-title"><span class="pc-eyebrow">YOUR ANKH ACCOUNT</span><h2>Everything in one place.</h2></div>
  <div class="pc-shop-account-links">
    <a href="<?=portalEscape(pcHref('orders',$staff,$customerId))?>"><?=pcIcon('orders')?><strong><?=count($currentOrders)?> active order<?=count($currentOrders)===1?'':'s'?></strong><small>View progress</small><?=pcIcon('right')?></a>
    <a href="<?=portalEscape(pcHref('basket',$staff,$customerId))?>"><?=pcIcon('basket')?><strong><?=$requestCount?> in basket</strong><small>Review your products</small><?=pcIcon('right')?></a>
    <a href="<?=portalEscape(pcHref('account',$staff,$customerId))?>"><?=pcIcon('account')?><strong>My account</strong><small>Details and preferences</small><?=pcIcon('right')?></a>
  </div>
</section>
<?php if($recent):$state=portalStatus($recent);?>
<section class="pc-shop-latest pc-panel"><div><span class="pc-eyebrow">LATEST ORDER</span><h2><?=portalEscape(pcOrderRef($recent))?> <span class="pc-pill pc-pill-<?=pcTone($state)?>"><?=portalEscape($state)?></span></h2><p><?=portalEscape(portalDate((string)$recent['created']))?> · <?=portalEscape(pcLines($recent))?></p></div><a class="pc-primary-link" href="<?=portalEscape(pcHref('orders',$staff,$customerId,['order'=>(int)$recent['id']]))?>">Track my order <?=pcIcon('right')?></a></section>
<?php endif;?>
<?php if($whatsappUrl):?><div class="pc-shop-help-inline"><span><?=pcIcon('chat')?></span><div><strong>Need a hand?</strong><p>Questions about an order or your account? Chat with ANKH.</p></div><a href="<?=portalEscape($whatsappUrl)?>" target="_blank" rel="noopener noreferrer">WhatsApp <?=pcIcon('right')?></a></div><?php endif;?>
<details class="pc-install-help" id="pc-install-help"><summary><span class="pc-install-icon"><?=pcIcon('spark')?></span><span><strong>Add ANKH to your Home Screen</strong><small>Open your account like an app on iPhone or Android</small></span><?=pcIcon('right')?></summary><div class="pc-install-body"><p><strong>iPhone:</strong> Open in Safari, choose Share → Add to Home Screen → Add.</p><p><strong>Android:</strong> Use Chrome's Install app or Add to Home Screen option.</p><button type="button" class="pc-primary-link" id="pc-install-app" hidden>Install ANKH <?=pcIcon('right')?></button><p class="pc-footnote">Private account information is not stored offline.</p></div></details>
