<?php
declare(strict_types=1);
if(!defined('ANKH_PORTAL_VIEW')||empty($customer)||empty($portal)||!empty($isDemo)){http_response_code(404);exit;}
$requestedId=filter_var($_GET['enquiry']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$requestedEnquiry=null;
try{
    foreach(ankhCustomerEnquiries($portal,$customerId,$staff) as $item){
        if((int)$item['id']===$requestedId&&$item['product_name']==='Multi-product request'){$requestedEnquiry=$item;break;}
    }
}catch(Throwable $e){error_log('ANKH basket confirmation: '.$e->getMessage());}
?>
<section class="pc-confirmation-page">
<?php if(($confirmationFlash=(string)($_SESSION['collection_flash']??''))!==''):unset($_SESSION['collection_flash']);?><p class="pc-notification-feedback" role="status"><?=portalEscape($confirmationFlash)?></p><?php endif;?>
<?php if(!$requestedEnquiry):?>
 <section class="pc-panel pc-empty"><h1>Request not found</h1><p>We couldn't find that request in this account.</p><a class="pc-primary-link" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['view_enquiries'=>1]))?>">View my enquiries <?=pcIcon('right')?></a></section>
<?php else:?>
 <div class="pc-confirmation-card"><div class="pc-confirmation-mark"><?=pcIcon('check')?></div><span class="pc-eyebrow">ANKH · REQUEST CONFIRMATION</span>
 <h1>Request received<span class="pc-dot">.</span></h1><p>Thanks, <?=portalEscape((string)$firstName)?>. Your basket has been sent to ANKH for review.</p>
 <div class="pc-confirmation-ref"><span>Reference</span><strong>ENQ-<?=str_pad((string)$requestedEnquiry['id'],5,'0',STR_PAD_LEFT)?></strong><span class="pc-pill pc-pill-gold"><?=portalEscape((string)$requestedEnquiry['status'])?></span></div>
 <div class="pc-confirmation-lines"><h2>Your requested products</h2>
 <?php foreach($requestedEnquiry['lines'] as $line):?><div><span><?=$line['quantity']?> × <?=portalEscape($line['name'])?></span><strong><?=portalEscape(portalMoney($line['unit_price']*$line['quantity']))?></strong></div><?php endforeach;?>
 <div class="pc-confirmation-total"><span>Indicative product total</span><strong><?=portalEscape(portalMoney((int)$requestedEnquiry['price']))?></strong></div></div>
 <?php if(str_contains((string)$requestedEnquiry['message'],"Customer note: ")):?><p class="pc-confirmation-customer-note"><strong>Your note</strong><span><?=nl2br(portalEscape(trim(substr((string)$requestedEnquiry['message'],(int)strpos((string)$requestedEnquiry['message'],"Customer note: ")+15))))?></span></p><?php endif;?>
 <div class="pc-confirmation-steps"><h2>What happens next?</h2><ol><li><strong>Request saved</strong><span>ANKH has received your product enquiry.</span></li><li><strong>Our team reviews it</strong><span>We confirm prices, availability and any delivery charges.</span></li><li><strong>Order details confirmed</strong><span>Only confirmed orders appear in My Orders. No payment has been taken.</span></li></ol></div>
 <div class="pc-confirmation-buttons"><a class="pc-primary-link" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Continue shopping <?=pcIcon('right')?></a><a class="pc-outline-button" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['view_enquiries'=>1]))?>">View my requests</a></div>
 <?php if($whatsappNumber):?><p class="pc-confirmation-help">Need help? <a href="<?=portalEscape(portalContactWhatsAppUrl($whatsappNumber,'Hi ANKH, I have a question about request ENQ-'.str_pad((string)$requestedEnquiry['id'],5,'0',STR_PAD_LEFT)))?>" target="_blank" rel="noopener noreferrer">Contact us on WhatsApp ↗</a></p><?php endif;?>
 </div>
<?php endif;?>
</section>
