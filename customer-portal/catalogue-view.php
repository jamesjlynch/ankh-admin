<?php
declare(strict_types=1);
if(!defined('ANKH_PORTAL_VIEW') || empty($catalogueEnabled) || empty($customer)) { http_response_code(404);exit; }
$demoState=&$_SESSION['catalogue_demo'];
$demoLines=pcdLines($demoState['cart']);
$demoCount=array_sum(array_column($demoLines,'quantity'));
$demoProducts=pcdProducts();
$demoDraft=$demoState['draft']??[];
$demoFlash=(string)($demoState['flash']??'');$demoError=(string)($demoState['error']??'');
unset($demoState['flash'],$demoState['error']);
function pcdFields(string $action, string $id=''): string {
    return '<input type="hidden" name="csrf" value="'.portalEscape((string)$_SESSION['portal_csrf']).'"><input type="hidden" name="catalogue_action" value="'.portalEscape($action).'"><input type="hidden" name="product_id" value="'.portalEscape($id).'">';
}
function pcdArt(string $key): string {
    $art=[
        'case'=>'<rect x="28" y="38" width="104" height="86" rx="18"/><path d="M59 38v-8a12 12 0 0 1 12-12h18a12 12 0 0 1 12 12v8M28 76h104M116 72v12"/><circle cx="80" cy="93" r="10"/>',
        'organiser'=>'<path d="m25 70 55-22 55 22v46l-55 22-55-22zM25 70l55 22 55-22M80 92v46M52 60v45M108 60v45"/><path d="M60 56V22h8v31M94 54V30h9v28"/>',
        'labels'=>'<path d="M39 37h87v100H39zM30 27h87v10"/><rect x="51" y="51" width="26" height="19" rx="3"/><rect x="87" y="51" width="26" height="19" rx="3"/><rect x="51" y="82" width="26" height="19" rx="3"/><rect x="87" y="82" width="26" height="19" rx="3"/><path d="M52 116h60"/>',
        'notebook'=>'<rect x="42" y="22" width="82" height="116" rx="8"/><path d="M57 22v116M72 51h35M72 65h35M72 79h27M102 22v22l-7-6-7 6V22"/>',
    ];
    return '<svg viewBox="0 0 160 160" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($art[$key]??$art['case']).'</svg>';
}
$demoPost=pcHref('basket',$staff,$customerId);
?>
<div class="pcd-notice" role="note"><?=pcIcon('shield')?><div><strong>Accessory ordering preview</strong><span>Fictional products, prices and availability. No payment, email, live order or stock change. Test requests last for this session and reset when you switch customer or sign out.</span></div></div>
<?php if($demoFlash):?><div class="feedback info" role="status"><?=portalEscape($demoFlash)?></div><?php endif;?>
<?php if($demoError):?><div class="feedback error" role="alert"><?=portalEscape($demoError)?></div><?php endif;?>
<nav class="pcd-subnav" aria-label="Accessory preview navigation">
  <a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>" <?=$tab==='catalogue'?'aria-current="page"':''?>>Catalogue</a>
  <a href="<?=portalEscape(pcHref('basket',$staff,$customerId))?>" <?=in_array($tab,['basket','checkout'],true)?'aria-current="page"':''?>>Basket <span><?=$demoCount?></span></a>
  <a href="<?=portalEscape(pcHref('test-orders',$staff,$customerId))?>" <?=$tab==='test-orders'?'aria-current="page"':''?>>Test requests <span><?=count($demoState['orders'])?></span></a>
</nav>
<?php if($tab==='catalogue'):
    $demoFind=is_string($_GET['search']??null)?substr(trim($_GET['search']),0,80):'';
    $demoCategory=is_string($_GET['category']??null)?$_GET['category']:'all';
    if(!in_array($demoCategory,['all','Storage','Stationery'],true))$demoCategory='all';
    $demoVisible=array_filter($demoProducts,static fn(array $p):bool=>($demoCategory==='all'||$p['category']===$demoCategory)&&($demoFind===''||stripos($p['name'].' '.$p['description'],$demoFind)!==false));
?>
<section class="pc-page-heading"><span class="pc-eyebrow">THE EVERYDAY COLLECTION · DEMO</span><h1>Considered essentials<span class="pc-dot">.</span></h1><p>Explore a small sample collection. Try adding an accessory and follow the complete order journey.</p></section>
<div class="pc-panel pc-filter-panel"><form class="pc-filter-form" method="get" action="<?=$staff?'./preview-live.php':'./preview.php'?>">
<?php if($staff):?><input type="hidden" name="customer_id" value="<?=$customerId?>"><?php else:?><input type="hidden" name="screen" value="account"><?php endif;?><input type="hidden" name="tab" value="catalogue">
<label for="pcd-search"><?=pcIcon('search')?><span class="sr-only">Search accessories</span></label><input id="pcd-search" type="search" name="search" value="<?=portalEscape($demoFind)?>" maxlength="80" placeholder="Find an accessory">
<select name="category" aria-label="Accessory category"><?php foreach(['all'=>'All categories','Storage'=>'Storage','Stationery'=>'Stationery'] as $key=>$label):?><option value="<?=$key?>" <?=$demoCategory===$key?'selected':''?>><?=$label?></option><?php endforeach;?></select><button type="submit">Search</button></form></div>
<div class="pcd-product-grid">
<?php foreach($demoVisible as $id=>$product):?>
<article class="pcd-product"><div class="pcd-product-art pcd-art-<?=portalEscape($product['art'])?>"><?=pcdArt($product['art'])?><span class="pcd-art-caption">ANKH / SAMPLE COLLECTION</span><span class="pcd-stock"><?=$product['stock']>0?'Sample availability: '.$product['stock']:'Unavailable in demo'?></span></div><div class="pcd-product-copy"><span class="pc-eyebrow"><?=portalEscape($product['category'])?></span><h2><?=portalEscape($product['name'])?></h2><p><?=portalEscape($product['description'])?></p><div class="pcd-price"><strong><?=portalEscape(portalMoney($product['price']))?></strong><small>Example price</small></div>
<form method="post" action="<?=portalEscape($demoPost)?>" class="pcd-add-form"><?=pcdFields('add',$id)?><label class="sr-only" for="qty-<?=portalEscape($id)?>">Quantity for <?=portalEscape($product['name'])?></label><select id="qty-<?=portalEscape($id)?>" name="quantity" <?=$product['stock']===0?'disabled':''?>><?php foreach(range(1,max(1,min(10,$product['stock']))) as $qty):?><option value="<?=$qty?>"><?=$qty?></option><?php endforeach;?></select><button type="submit" <?=$product['stock']===0?'disabled':''?>><?=$product['stock']>0?'Add to test basket':'Unavailable'?></button></form></div></article>
<?php endforeach;?>
</div>
<?php if(!$demoVisible):?><div class="pc-panel pc-empty"><h2>No accessories found</h2><p>Try another search or view the full collection.</p><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Clear filters</a></div><?php endif;?>
<?php elseif($tab==='basket'):?>
<section class="pc-page-heading"><span class="pc-eyebrow">YOUR SELECTION · DEMO</span><h1>Your basket<span class="pc-dot">.</span></h1><p>Check quantities before trying checkout. All prices here are examples.</p></section>
<?php if(!$demoLines):?><div class="pc-panel pc-empty"><h2>Your basket is waiting</h2><p>Choose an accessory to try the order flow.</p><a class="pc-primary-link" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Explore accessories <?=pcIcon('right')?></a></div>
<?php else:?><div class="pcd-checkout-grid"><section class="pc-panel pcd-basket-items">
<?php foreach($demoLines as $line):?><article class="pcd-basket-row"><div><span class="pc-eyebrow">SAMPLE ACCESSORY</span><h2><?=portalEscape($line['name'])?></h2><p><?=portalEscape(portalMoney($line['price']))?> each</p></div><strong><?=portalEscape(portalMoney($line['total']))?></strong><div class="pcd-row-controls"><form method="post" action="<?=portalEscape($demoPost)?>"><?=pcdFields('update',$line['id'])?><label for="basket-<?=portalEscape($line['id'])?>">Quantity</label><input id="basket-<?=portalEscape($line['id'])?>" type="number" inputmode="numeric" name="quantity" min="0" max="<?=min(10,$demoProducts[$line['id']]['stock'])?>" value="<?=$line['quantity']?>" required><button type="submit">Update</button></form><form method="post" action="<?=portalEscape($demoPost)?>"><?=pcdFields('remove',$line['id'])?><button type="submit" class="pcd-remove" aria-label="Remove <?=portalEscape($line['name'])?>">Remove</button></form></div></article><?php endforeach;?></section>
<aside class="pc-panel pcd-summary"><span class="pc-eyebrow">AT A GLANCE</span><h2>Basket summary</h2><div class="pc-money-rows"><div><span><?=$demoCount?> items</span><strong><?=portalEscape(portalMoney(pcdTotal($demoLines)))?></strong></div><div><span>Delivery</span><span>Choose at checkout</span></div></div><p>Collection is free in this demo. Sample UK delivery is £3.50.</p><a class="pc-primary-link" href="<?=portalEscape(pcHref('checkout',$staff,$customerId))?>">Continue to checkout <?=pcIcon('right')?></a><a class="pcd-text-link" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Continue browsing</a></aside></div><?php endif;?>
<?php elseif($tab==='checkout'):?>
<section class="pc-page-heading"><span class="pc-eyebrow">ONE LAST LOOK · DEMO</span><h1>Checkout<span class="pc-dot">.</span></h1><p>Use fictional delivery details to test the experience. No payment is collected.</p></section>
<?php if(!$demoLines):?><div class="pc-panel pc-empty"><h2>Add an accessory first</h2><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Back to catalogue</a></div><?php else:?>
<div class="pcd-checkout-grid"><form class="pc-panel pcd-checkout-form" method="post" action="<?=portalEscape($demoPost)?>"><?=pcdFields('checkout')?><input type="hidden" name="checkout_token" value="<?=portalEscape($demoState['checkout_token'])?>"><h2>Delivery or collection</h2>
<fieldset><legend>Choose a fulfilment option</legend><label class="pcd-radio"><input type="radio" name="delivery_method" value="collection" <?=($demoDraft['delivery_method']??'collection')!=='delivery'?'checked':''?>><span><strong>Collect in person</strong><small>Free · sample option only</small></span></label><label class="pcd-radio"><input type="radio" name="delivery_method" value="delivery" <?=($demoDraft['delivery_method']??'')==='delivery'?'checked':''?>><span><strong>UK delivery</strong><small>£3.50 sample charge · date to be agreed</small></span></label></fieldset>
<label for="pcd-address">Delivery address <small>Only needed for delivery</small></label><textarea id="pcd-address" name="address" maxlength="500" rows="3" placeholder="Use a fictional address for testing"><?=portalEscape((string)($demoDraft['address']??''))?></textarea>
<label for="pcd-postcode">UK postcode</label><input id="pcd-postcode" name="postcode" type="text" maxlength="12" placeholder="Example: SW1A 1AA" autocapitalize="characters" value="<?=portalEscape((string)($demoDraft['postcode']??''))?>">
<label for="pcd-notes">Delivery or collection notes <small>Optional</small></label><textarea id="pcd-notes" name="notes" maxlength="500" rows="3" placeholder="Example: Please arrange a collection time"><?=portalEscape((string)($demoDraft['notes']??''))?></textarea>
<label class="pcd-checkbox"><input type="checkbox" name="acknowledge" value="yes" required><span>I understand this creates a test request only. Nothing will be charged, reserved or sent to ANKH Admin.</span></label>
<button class="pc-primary-link pcd-submit" type="submit">Submit test request <?=pcIcon('right')?></button><a class="pcd-text-link" href="<?=portalEscape(pcHref('basket',$staff,$customerId))?>">Back to basket</a></form>
<aside class="pc-panel pcd-summary"><span class="pc-eyebrow">REVIEW YOUR SELECTION</span><h2>Order summary</h2><div class="pc-money-rows"><?php foreach($demoLines as $line):?><div><span><?=$line['quantity']?> × <?=portalEscape($line['name'])?></span><strong><?=portalEscape(portalMoney($line['total']))?></strong></div><?php endforeach;?><div class="pc-money-total"><span>Collection total</span><strong><?=portalEscape(portalMoney(pcdTotal($demoLines)))?></strong></div><div><span>With UK delivery</span><strong><?=portalEscape(portalMoney(pcdTotal($demoLines)+350))?></strong></div></div><div class="pcd-next"><strong>What happens next?</strong><p>See an example confirmation and find it under Test requests. In this walkthrough, there is no staff approval or fulfilment.</p></div></aside></div><?php endif;?>
<?php elseif(in_array($tab,['confirmation','test-orders'],true)):
    $demoRef=is_string($_GET['test_ref']??null)?$_GET['test_ref']:'';
    $demoShown=array_values(array_filter($demoState['orders'],static fn(array $o):bool=>$demoRef===''||$o['ref']===$demoRef));
?>
<section class="pc-page-heading"><span class="pc-eyebrow">ACCESSORY WALKTHROUGH</span><h1><?=$tab==='confirmation'?'Test request received':'Your test requests'?><span class="pc-dot">.</span></h1><p>These requests are saved only in this preview session. They do not appear in real order history.</p></section>
<?php if(!$demoShown):?><div class="pc-panel pc-empty"><h2>No test requests here yet</h2><p>Complete the accessory checkout to see an example confirmation.</p><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Explore the catalogue</a></div><?php endif;?>
<?php foreach($demoShown as $demoOrder):?><article class="pc-panel pcd-confirmation"><div class="pcd-confirmation-top"><span class="pcd-success-icon"><?=pcIcon('check')?></span><div><span class="pc-eyebrow">TEST REQUEST · NO PAYMENT TAKEN</span><h2><?=portalEscape($demoOrder['ref'])?></h2><p><?=portalEscape(portalDate($demoOrder['created']))?> · <?=portalEscape($demoOrder['status'])?></p></div></div><div class="pc-money-rows"><?php foreach($demoOrder['lines'] as $line):?><div><span><?=$line['quantity']?> × <?=portalEscape($line['name'])?></span><strong><?=portalEscape(portalMoney($line['total']))?></strong></div><?php endforeach;?><div><span><?=$demoOrder['method']==='delivery'?'Sample delivery':'Collection'?></span><strong><?=portalEscape(portalMoney($demoOrder['delivery']))?></strong></div><div class="pc-money-total"><span>Test total</span><strong><?=portalEscape(portalMoney($demoOrder['total']))?></strong></div></div><?php if($demoOrder['address']):?><div class="pcd-next"><strong>Test delivery details</strong><p><?=nl2br(portalEscape($demoOrder['address']))?><br><?=portalEscape($demoOrder['postcode'])?></p></div><?php endif;?><?php if($demoOrder['notes']):?><div class="pcd-next"><strong>Your test notes</strong><p><?=nl2br(portalEscape($demoOrder['notes']))?></p></div><?php endif;?><p class="pc-footnote">No real order has been placed. No stock has been reserved, no payment is due and no message has been sent.</p></article><?php endforeach;?>
<div class="pc-detail-actions"><a class="pc-primary-link" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Back to catalogue <?=pcIcon('right')?></a><form method="post" action="<?=portalEscape($demoPost)?>"><?=pcdFields('clear')?><button class="pc-outline-button" type="submit">Clear my test requests and basket</button></form></div>
<?php endif;?>
