<?php
declare(strict_types=1);
if(!defined('ANKH_PORTAL_VIEW')||empty($customer)){http_response_code(404);exit;}
$accountGuest=[];$accountEnquiries=[];$activityError=false;
if(empty($isDemo)&&isset($portal)){
  try{
    $accountGuest=ankhGuestTrackingRows($portal,$customerId);
    $accountEnquiries=ankhCustomerEnquiries($portal,$customerId,$staff);
  }catch(Throwable $e){error_log('ANKH account activity: '.$e->getMessage());$activityError=true;}
}
$openGuest=count(array_filter($accountGuest,static fn($r):bool=>($r['status']??'')==='Pending staff review'));
$unanswered=count(array_filter($accountEnquiries,static fn($r):bool=>
  ($r['status']??'')==='Open' && trim((string)($r['reply']??''))===''));
$focusOrder=$currentOrders[0]??($orders[0]??null);
$focusLinked=null;
foreach($accountGuest as $request){
  if(empty($request['linked_order_id']))continue;
  foreach($orders as $owned){
    if((int)$owned['id']===(int)$request['linked_order_id']){
      $focusLinked=['request'=>$request,'order'=>$owned];break 2;
    }
  }
}
$latestRequest=$accountGuest[0]??null;
$latestEnquiry=$accountEnquiries[0]??null;
?>
<section class="pc-connect-dashboard" aria-labelledby="pc-connect-heading">
 <div class="pc-connect-title">
  <div><span class="pc-eyebrow">ANKH / YOUR ACCOUNT</span><h2 id="pc-connect-heading">Your ANKH, connected<span class="pc-dot">.</span></h2>
  <p>Requests, recorded orders and personal research tools—linked to your securely verified account.</p></div>
  <a href="<?=portalEscape(pcHref('requests',$staff,$customerId))?>">All activity <?=pcIcon('right')?></a>
 </div>
 <?php if($activityError):?><p class="pc-connect-hint" role="status">Request summaries are temporarily unavailable. Your recorded orders can still be viewed.</p><?php endif;?>
 <div class="pc-connect-grid">
  <a href="<?=portalEscape(pcHref('requests',$staff,$customerId))?>" class="pc-connect-card pc-connect-card-primary">
    <span class="pc-connect-label"><?=pcIcon('requests')?> REQUESTS &amp; ENQUIRIES</span>
    <strong><?=count($accountGuest)+count($accountEnquiries)?></strong>
    <span class="pc-connect-sub"><?=$openGuest+$unanswered?> awaiting staff review or reply</span>
    <span class="pc-connect-action">Track my requests <?=pcIcon('right')?></span>
  </a>
  <a href="<?=portalEscape(pcHref('orders',$staff,$customerId,['filter'=>'active']))?>" class="pc-connect-card">
    <span class="pc-connect-label"><?=pcIcon('orders')?> ACTIVE ORDERS</span>
    <strong><?=count($currentOrders)?></strong>
    <span class="pc-connect-sub">Stages from your real ANKH Admin records</span>
    <span class="pc-connect-action">View live status <?=pcIcon('right')?></span>
  </a>
  <a href="<?=portalEscape(pcHref('orders',$staff,$customerId))?>" class="pc-connect-card">
    <span class="pc-connect-label"><?=pcIcon('wallet')?> RECORDED BALANCE</span>
    <strong><?=portalEscape(portalMoney($outstanding))?></strong>
    <span class="pc-connect-sub">Outstanding on non-cancelled recorded orders</span>
    <span class="pc-connect-action">Order history <?=pcIcon('right')?></span>
  </a>
 </div>
 <div class="pc-connect-detail-grid">
  <article class="pc-connect-feature">
   <div class="pc-connect-feature-head"><span class="pc-eyebrow">YOUR LATEST RECORDED ORDER</span><a href="<?=portalEscape(pcHref('orders',$staff,$customerId))?>">All orders <?=pcIcon('right')?></a></div>
   <?php if($focusOrder):[$currentStage,$currentStageDetail]=pcRecordedOrderStage($focusOrder);?>
    <div class="pc-connect-order-title"><div><h3><?=portalEscape(pcOrderRef($focusOrder))?></h3><span><?=portalEscape(portalDate((string)$focusOrder['created']))?> · <?=portalEscape(pcLines($focusOrder))?></span></div><span class="pc-connect-stage"><?=portalEscape($currentStage)?></span></div>
    <p><?=portalEscape($currentStageDetail)?></p>
    <?php if(pcBalance($focusOrder)>0 && $currentStage!=='Cancelled'):?><small class="pc-connect-balance"><?=portalEscape(portalMoney(pcBalance($focusOrder)))?> recorded as outstanding · No payment taken here</small><?php endif;?>
    <a class="pc-connect-primary-action" href="<?=portalEscape(pcHref('orders',$staff,$customerId,['order'=>(int)$focusOrder['id']]))?>">Follow this order <?=pcIcon('right')?></a>
   <?php else:?><h3>No orders recorded yet</h3><p>When ANKH has created and securely linked an order to your verified account, its actual status will appear here.</p><a class="pc-connect-primary-action" href="<?=portalEscape(pcHref('requests',$staff,$customerId))?>">My requests <?=pcIcon('right')?></a><?php endif;?>
  </article>
  <article class="pc-connect-feature">
   <div class="pc-connect-feature-head"><span class="pc-eyebrow">YOUR REQUEST ACTIVITY</span><a href="<?=portalEscape(pcHref('requests',$staff,$customerId))?>">Full history <?=pcIcon('right')?></a></div>
   <?php if($focusLinked):
     [$stage,$detail]=pcRecordedOrderStage($focusLinked['order']);?>
     <h3>Request linked to <?=portalEscape(pcOrderRef($focusLinked['order']))?></h3>
     <p><?=portalEscape($stage)?> · <?=portalEscape($detail)?></p>
     <a class="pc-connect-primary-action" href="<?=portalEscape(pcHref('orders',$staff,$customerId,['order'=>(int)$focusLinked['order']['id']]))?>">View linked order <?=pcIcon('right')?></a>
   <?php elseif($latestRequest):?>
     <h3>REQ-<?=str_pad((string)$latestRequest['id'],5,'0',STR_PAD_LEFT)?></h3>
     <p><?=portalEscape((string)$latestRequest['status'])?> · <?=portalEscape(portalDate(date('c',(int)$latestRequest['placed_at'])))?></p>
     <small>A request is not an accepted order. Order tracking begins when a real Admin order is created and securely linked.</small>
     <a class="pc-connect-primary-action" href="<?=portalEscape(pcHref('requests',$staff,$customerId))?>">View request <?=pcIcon('right')?></a>
   <?php elseif($latestEnquiry):?>
     <h3>Latest product enquiry</h3><p><?=portalEscape((string)($latestEnquiry['product_name']??'Enquiry'))?></p>
     <small><?=trim((string)($latestEnquiry['reply']??''))!==''?'ANKH has replied':'Awaiting ANKH response'?></small>
     <a class="pc-connect-primary-action" href="<?=portalEscape(pcHref('requests',$staff,$customerId))?>">Read enquiry <?=pcIcon('right')?></a>
   <?php else:?><h3>No requests linked yet</h3><p>Enquiries from your signed-in account appear here. To track a guest accessory request, securely link its private checkout receipt.</p><a class="pc-connect-primary-action" href="<?=portalEscape(pcHref('requests',$staff,$customerId))?>">Link or track a request <?=pcIcon('right')?></a><?php endif;?>
  </article>
 </div>
 <div class="pc-connect-shortcuts" aria-label="Your quick account actions">
  <a href="<?=portalEscape(pcHref('fridge',$staff,$customerId))?>"><?=pcIcon('fridge')?><span><strong>My Fridge</strong><small>Personal quantities</small></span><?=pcIcon('right')?></a>
  <a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['saved'=>1]))?>"><?=pcIcon('check')?><span><strong>Saved research</strong><small>Favourite products</small></span><?=pcIcon('right')?></a>
  <a href="<?=portalEscape(pcHref('notifications',$staff,$customerId))?>"><?=pcIcon('bell')?><span><strong>Updates</strong><small><?=$unreadNotifications?> unread</small></span><?=pcIcon('right')?></a>
 </div>
</section>
