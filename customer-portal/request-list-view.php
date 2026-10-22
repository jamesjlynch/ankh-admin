<?php
declare(strict_types=1);
if(!defined('ANKH_PORTAL_VIEW')||empty($customer)||!empty($isDemo)){http_response_code(404);exit;}
$listError=false;$items=[];$availability=[];$products=[];
try{
    $products=ankhCatalogueProducts($admin,(int)$customer['id']);
    $availability=portalExtrasAvailabilityMap($portal);
    $items=portalCollectionForCustomer($portal,(int)$customer['id'],$staff,$products,$availability);
}catch(Throwable $e){error_log('ANKH request-list view: '.$e->getMessage());$listError=true;}
$total=array_sum(array_map(static fn(array $line):int=>$line['product']['price']*$line['quantity'],$items));
$unavailable=count(array_filter($items,static fn(array $line):bool=>!$line['can_request']));
$flash=(string)($_SESSION['collection_flash']??'');unset($_SESSION['collection_flash']);
?>
<section class="pc-request-page">
  <a class="pc-back" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">← Continue browsing</a>
  <section class="pc-page-heading"><span class="pc-eyebrow">THE ANKH COLLECTION</span><h1>My request list<span class="pc-dot">.</span></h1><p>Build one combined request from the catalogue. ANKH reviews availability and confirms the details; this is not a checkout.</p></section>
  <?php if($flash!==''):?><p class="pc-notification-feedback" role="status"><?=portalEscape($flash)?></p><?php endif;?>
  <?php if(!empty($GLOBALS['collectionError'])):?><p class="pc-notification-feedback" role="alert"><?=portalEscape($GLOBALS['collectionError'])?></p><?php endif;?>
  <?php if($listError):?><div class="pc-panel pc-empty" role="alert"><h2>Your request list is temporarily unavailable</h2><p>Please try again later.</p></div>
  <?php elseif(!$items):?><div class="pc-panel pc-request-empty"><span aria-hidden="true">☥</span><h2>Start with something you like.</h2><p>Open a product and choose Add to request list. Everything stays saved privately in your account until you send it.</p><a class="pc-primary-link" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Explore products →</a></div>
  <?php else:?>
  <div class="pc-request-layout">
    <div class="pc-request-items">
      <?php foreach($items as $line):$p=$line['product'];?>
      <article class="pc-request-item">
        <div class="pc-request-icon" aria-hidden="true">☥</div>
        <div class="pc-request-item-main">
          <h2><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['product'=>$p['id']]))?>"><?=portalEscape($p['name'])?></a></h2>
          <span class="pcs-availability <?='pcs-status-'.$line['status']['tone']?>"><?=portalEscape($line['status']['label'])?></span>
          <small><?=portalEscape(portalMoney($p['price']))?> each · Current account price, subject to confirmation</small>
          <form method="post" class="pc-request-quantity">
            <input type="hidden" name="csrf" value="<?=portalEscape($_SESSION['portal_csrf'])?>">
            <input type="hidden" name="action" value="collection_set">
            <input type="hidden" name="return_to" value="list">
            <input type="hidden" name="product_id" value="<?=$p['id']?>">
            <label>Quantity <input type="number" name="quantity" min="1" max="99" value="<?=$line['quantity']?>" required></label>
            <button type="submit" <?=$line['can_request']?'':'disabled'?>>Update</button>
            <button type="submit" name="quantity" value="0" formnovalidate>Remove</button>
          </form>
          <?php if(!$line['can_request']):?><p class="pc-request-warning">This product is not currently available. Remove it to send the request.</p><?php endif;?>
        </div>
        <strong class="pc-request-line-value"><?=portalEscape(portalMoney($p['price']*$line['quantity']))?></strong>
      </article>
      <?php endforeach;?>
    </div>
    <aside class="pc-request-summary pc-panel">
      <span class="pc-eyebrow">REVIEW YOUR LIST</span><h2><?=count($items)?> product<?=count($items)===1?'':'s'?></h2>
      <div class="pc-request-price"><span>Indicative product total</span><strong><?=portalEscape(portalMoney($total))?></strong></div>
      <p>Prices shown are your current account prices. Delivery, final availability and any charges will be confirmed by ANKH. No purchase or payment is made here.</p>
      <?php if($unavailable):?><p class="pc-request-warning"><?=$unavailable?> unavailable product<?=$unavailable===1?'':'s'?> must be removed before submitting.</p><?php endif;?>
      <form method="post" class="pc-request-send">
        <input type="hidden" name="csrf" value="<?=portalEscape($_SESSION['portal_csrf'])?>">
        <input type="hidden" name="action" value="collection_send">
        <input type="hidden" name="revision" value="<?=portalEscape(portalCollectionRevision($items))?>">
        <input type="hidden" name="request_token" value="<?=bin2hex(random_bytes(32))?>">
        <label class="pc-field">Anything else ANKH should know? <span>(optional)</span><textarea name="message" rows="4" maxlength="2000" placeholder="Add a note to your request"></textarea></label>
        <button type="submit" class="pc-primary-link" <?=$unavailable?'disabled':''?>>Send combined request <?=pcIcon('right')?></button>
      </form>
      <span class="pc-request-private"><?=pcIcon('shield')?> Saved privately to your verified account</span>
    </aside>
  </div>
  <?php endif;?>
</section>
