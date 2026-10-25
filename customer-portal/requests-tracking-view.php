<?php
declare(strict_types=1);
if(!defined('ANKH_PORTAL_VIEW')||empty($customer)){http_response_code(404);exit;}
$trackedGuest=[];$trackedEnquiries=[];$trackedError=false;
if(empty($isDemo)&&isset($portal)){
  try{
    $trackedGuest=ankhGuestTrackingRows($portal,$customerId);
    $trackedEnquiries=ankhCustomerEnquiries($portal,$customerId,$staff);
  }catch(Throwable $e){error_log('ANKH account request tracking: '.$e->getMessage());$trackedError=true;}
}
$pendingRequests=0;
foreach($trackedGuest as $g)if(($g['status']??'')==='Pending staff review')$pendingRequests++;
foreach($trackedEnquiries as $e)if(($e['status']??'')==='Open')$pendingRequests++;
$trackingFlash=(string)($_SESSION['guest_claim_flash']??'');unset($_SESSION['guest_claim_flash']);
?>
<section class="pc-tracking" aria-labelledby="pc-tracking-heading">
 <header class="pc-page-heading pc-tracking-heading">
  <span class="pc-eyebrow">YOUR ANKH / PRIVATE ACTIVITY</span>
  <h1 id="pc-tracking-heading">My Requests<span class="pc-dot">.</span></h1>
  <p>Follow genuine ANKH enquiries, linked accessory requests and recorded orders. Every stage below comes from an actual saved record, not a simulated checkout.</p>
 </header>
 <?php if($trackingFlash!==''):?><p class="pc-notification-feedback" role="status"><?=portalEscape($trackingFlash)?></p><?php endif;?>
 <?php if(!empty($GLOBALS['guestClaimError'])):?><p class="pc-notification-feedback" role="alert"><?=portalEscape((string)$GLOBALS['guestClaimError'])?></p><?php endif;?>
 <?php if($trackedError):?><section class="pc-panel pc-empty" role="alert"><h2>Request tracking temporarily unavailable</h2><p>Your Order Admin records are unaffected. Please try again.</p></section><?php else:?>
 <div class="pc-tracking-metrics">
  <a href="#pc-tracking-requests"><span>Requests and enquiries</span><strong><?=count($trackedGuest)+count($trackedEnquiries)?></strong><small><?=$pendingRequests?> awaiting ANKH review or response</small></a>
  <a href="<?=portalEscape(pcHref('orders',$staff,$customerId,['filter'=>'active']))?>"><span>Recorded active orders</span><strong><?=count($currentOrders)?></strong><small>View their real preparation and delivery status</small></a>
  <a href="<?=portalEscape(pcHref('orders',$staff,$customerId))?>"><span>Order history</span><strong><?=count($orders)?></strong><small>Every recorded Admin order in your account</small></a>
 </div>
 <section class="pc-tracking-stack" id="pc-tracking-requests">
  <div class="pc-section-header"><div><span class="pc-eyebrow">01 / WHAT YOU'VE REQUESTED</span><h2>Requests &amp; enquiries</h2></div></div>
  <?php if(!$trackedGuest&&!$trackedEnquiries):?><div class="pc-panel pc-tracking-empty"><span aria-hidden="true">☥</span><h3>No linked requests yet</h3><p>Requests sent from your signed-in account appear here automatically. Guest accessory requests can be linked using the receipt code from checkout.</p><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>" class="pc-outline-button">Explore the catalogue <?=pcIcon('right')?></a></div><?php endif;?>
  <?php foreach($trackedGuest as $g):$status=(string)$g['status'];$isPending=$status==='Pending staff review';$isDeclined=$status==='Declined';?>
   <details class="pc-tracking-card">
    <summary><div class="pc-tracking-ref"><span class="pc-eyebrow">GUEST ACCESSORY REQUEST</span><strong>REQ-<?=str_pad((string)$g['id'],5,'0',STR_PAD_LEFT)?></strong><small><?=portalEscape(portalDate(date('c',(int)$g['placed_at'])))?> · <?=count($g['lines'])?> line item<?=count($g['lines'])===1?'':'s'?></small></div><span class="pc-tracking-badge <?=$isDeclined?'is-declined':($isPending?'is-pending':'is-reviewed')?>"><?=portalEscape($isPending?'Awaiting staff review':($isDeclined?'Declined':'Reviewed by ANKH'))?></span><?=pcIcon('right')?></summary>
    <div class="pc-tracking-detail">
     <p class="pc-tracking-context">A guest accessory request is not an accepted order. Staff review does not automatically create an Admin order or confirm delivery.</p>
     <?php foreach($g['lines'] as $line):?><div class="pc-tracking-line"><span><?=max(0,(int)($line['quantity']??0))?> × <?=portalEscape((string)($line['name']??'Product'))?></span><strong><?=portalEscape(portalMoney(max(0,(int)($line['unit_price']??0))*max(0,(int)($line['quantity']??0))))?></strong></div><?php endforeach;?>
     <div class="pc-tracking-line pc-tracking-total"><span>Recorded request value</span><strong><?=portalEscape(portalMoney(max(0,(int)$g['subtotal'])))?></strong></div>
     <p class="pc-tracking-context">Current request stage: <strong><?=portalEscape($status)?></strong>. For any order subsequently created by ANKH, use the linked Order History below.</p>
    </div>
   </details>
  <?php endforeach;?>
  <?php foreach($trackedEnquiries as $e):$status=(string)$e['status'];$hasReply=trim((string)($e['reply']??''))!=='';?>
   <details class="pc-tracking-card">
    <summary><div class="pc-tracking-ref"><span class="pc-eyebrow">SIGNED-IN PRODUCT ENQUIRY</span><strong>ENQ-<?=str_pad((string)$e['id'],5,'0',STR_PAD_LEFT)?></strong><small><?=portalEscape(portalDate(date('c',(int)$e['created_at'])))?> · <?=portalEscape((string)($e['product_name']??'Research enquiry'))?></small></div><span class="pc-tracking-badge <?=$hasReply?'is-reviewed':'is-pending'?>"><?=portalEscape($hasReply?'ANKH replied':($status==='Closed'?'Closed':'Awaiting response'))?></span><?=pcIcon('right')?></summary>
    <div class="pc-tracking-detail">
     <?php if(!empty($e['lines'])):foreach($e['lines'] as $line):?><div class="pc-tracking-line"><span><?=max(0,(int)$line['quantity'])?> × <?=portalEscape((string)$line['name'])?></span></div><?php endforeach;else:?><p><?=portalEscape((string)$e['product_name'])?></p><?php endif;?>
     <?php if($hasReply):?><div class="pc-tracking-reply"><strong>ANKH's response</strong><p><?=nl2br(portalEscape((string)$e['reply']))?></p></div><?php endif;?>
     <p class="pc-tracking-context">This is an enquiry only. It does not place an order or reserve any stock.</p>
    </div>
   </details>
  <?php endforeach;?>
 </section>
 <section class="pc-tracking-orders">
  <div class="pc-section-header"><div><span class="pc-eyebrow">02 / CONFIRMED RECORDS</span><h2>Orders recorded by ANKH</h2></div><a href="<?=portalEscape(pcHref('orders',$staff,$customerId))?>">See all <?=pcIcon('right')?></a></div>
  <?php if(!$orders):?><div class="pc-panel pc-tracking-empty"><h3>No recorded orders yet</h3><p>If ANKH converts a request into an accepted order and associates it with your verified customer account, it will be shown under My Orders.</p></div><?php endif;?>
  <?php foreach(array_slice($orders,0,4) as $order):$status=portalStatus($order);?><a class="pc-tracking-order" href="<?=portalEscape(pcHref('orders',$staff,$customerId,['order'=>(int)$order['id']]))?>"><span><strong><?=portalEscape(pcOrderRef($order))?></strong><small><?=portalEscape(portalDate((string)$order['created']))?> · <?=portalEscape(pcLines($order))?></small></span><span class="pc-tracking-badge"><?=portalEscape($status)?></span><?=pcIcon('right')?></a><?php endforeach;?>
 </section>
 <?php if(empty($isDemo)&&!$staff):?><details class="pc-tracking-claim" id="pc-claim-receipt">
  <summary><span><?=pcIcon('shield')?></span><span><strong>Link a guest accessory receipt</strong><small>Use your private checkout receipt code</small></span><?=pcIcon('right')?></summary>
  <div><p>If you submitted an accessory request as a guest, enter the code provided on its receipt. Your signed-in mobile number must also match the number on that request. This protects other customers' information.</p>
   <form method="post" action="./?tab=requests">
    <input type="hidden" name="csrf" value="<?=portalEscape((string)($_SESSION['portal_csrf']??''))?>">
    <input type="hidden" name="action" value="claim_guest_request">
    <label>32-character receipt code<input type="text" name="receipt_code" autocomplete="off" autocapitalize="characters" spellcheck="false" minlength="32" maxlength="32" pattern="[A-Fa-f0-9]{32}" required placeholder="Paste the private receipt code"></label>
    <button type="submit" class="pc-primary-link">Link my receipt securely <?=pcIcon('right')?></button>
   </form>
  </div>
 </details><?php endif;?>
 <?php endif;?>
 <p class="pc-footnote">For security, guest requests aren't associated with a customer account simply because somebody typed that customer's name or phone number into a form. Private claim verification is required.</p>
</section>
