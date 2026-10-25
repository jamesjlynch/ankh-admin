<?php
declare(strict_types=1);
/** Standalone guest checkout for individually approved non-medicinal accessories. */
require_once __DIR__.'/public-catalogue-core.php';
require_once __DIR__.'/storefront-commerce.php';
require_once __DIR__.'/guest-request-tracking.php';
$local=PHP_SAPI==='cli-server' && getenv('ANKH_TEST_ALLOW_HTTP')==='1'
    && in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true)
    && preg_match('/^(?:127\\.0\\.0\\.1|\\[::1\\])(?::[0-9]+)?$/D',(string)($_SERVER['HTTP_HOST']??''))===1;
if(!$local && ($_SERVER['HTTPS']??'')!=='on'){http_response_code(403);exit('HTTPS required.');}
session_name('ankh_guest_checkout');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!$local,'httponly'=>true,'samesite'=>'Lax']);
ini_set('session.use_strict_mode','1');
session_start();
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
if(!$local)header('Strict-Transport-Security: max-age=31536000');
function gcE(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gcMoney(int $p):string{return '£'.number_format($p/100,2);}
$error='';$products=[];$received=null;
try {
    $path=getenv('ANKH_PORTAL_CONFIG')?:dirname(__DIR__,2).'/ankh-customer-portal-config.php';
    if(!is_file($path))throw new RuntimeException('Missing configuration.');
    $config=require $path;
    if(!is_array($config))throw new RuntimeException('Invalid configuration.');
    $expectedHost=strtolower((string)($config['host']??'order.ankhpeptides.com'));
    if(!$local && strtolower((string)($_SERVER['HTTP_HOST']??''))!==$expectedHost){http_response_code(404);exit;}
    $adminPath=$config['admin_database_path']??null;$portalPath=$config['portal_database_path']??null;
    if(!is_string($adminPath)||!is_file($adminPath)||!is_string($portalPath)||!is_file($portalPath))
        throw new RuntimeException('Private order databases unavailable.');
    $admin=new PDO('sqlite:'.$adminPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $admin->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=2500');
    $portal=new PDO('sqlite:'.$portalPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $portal->exec('PRAGMA busy_timeout=4000');
    $products=sfCommerceEligible($portal,$admin);
    $_SESSION['guest_csrf']??=bin2hex(random_bytes(32));
    $_SESSION['guest_token']??=bin2hex(random_bytes(32));
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['guest_csrf'],$_POST['csrf'])){
            http_response_code(400);throw new InvalidArgumentException('Your checkout session expired. Please refresh the page.');
        }
        if((string)($_POST['website']??'')!=='')throw new InvalidArgumentException('Unable to process this request.');
        $token=is_string($_POST['request_token']??null)?$_POST['request_token']:'';
        if(!hash_equals((string)$_SESSION['guest_token'],$token)){
            throw new InvalidArgumentException('The checkout was already submitted. Please refresh to start another.');
        }
        $ip=(string)($_SERVER['REMOTE_ADDR']??'');
        $fingerprint=hash('sha256',date('Y-m-d-H').':'.($config['host']??'').':'.$ip);
        $inputs=[
            'name'=>$_POST['name']??'','email'=>$_POST['email']??'','phone'=>$_POST['phone']??'',
            'address'=>$_POST['address']??'','note'=>$_POST['note']??'','confirmed'=>$_POST['confirmed']??'',
            'items'=>array_filter((array)($_POST['items']??[]),static fn($qty)=>is_scalar($qty) && (string)$qty!=='0' && (string)$qty!=='')
        ];
        $id=sfCommerceCreateRequest($portal,$inputs,$products,$token,$fingerprint);
        $_SESSION['guest_received']=$id;
        $claim=ankhGuestTrackingIssue($portal,$id);
        if($claim!==null){$_SESSION['guest_receipt_code']=$claim;$_SESSION['guest_receipt_id']=$id;}
        $_SESSION['guest_received_product_ids']=array_map('intval',array_keys($inputs['items']));
        $_SESSION['guest_token']=bin2hex(random_bytes(32));
        header('Location: ./shop-checkout.php?received=1',true,303);exit;
    }
    if(($_GET['received']??'')==='1'&&!empty($_SESSION['guest_received'])){
        $received=(int)$_SESSION['guest_received'];
    }
}catch(InvalidArgumentException $e){$error=$e->getMessage();}
catch(Throwable $e){error_log('ANKH guest checkout: '.$e->getMessage());$error='Guest checkout is temporarily unavailable. Please contact ANKH support.';}
?>
<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0e100d"><meta name="robots" content="noindex,nofollow">
<title>Checkout request · ANKH</title><link rel="stylesheet" href="./storefront-preview.css"><link rel="stylesheet" href="./storefront.css?v=5"><link rel="stylesheet" href="./storefront-basket-premium.css?v=1"><script defer src="./checkout.js?v=2"></script></head>
<body class="sf-public sf-checkout-page">
<header class="sf-header"><div class="sf-shell sf-head-inner"><a class="sf-logo" href="./"><span class="sf-ankh">☥</span><span><strong>ANKH</strong><small>CHECKOUT REQUEST</small></span></a><a class="sf-account-entry" href="./?tab=home">My Account ↗</a></div></header>
<main class="sf-shell sf-checkout-shell"><a class="sf-back-link" href="./#collection">← Back to collection</a>
<?php if($received!==null):?>
<section class="sf-checkout-success" data-successful-request-ids="<?=gcE(implode(',',array_map('intval',(array)($_SESSION['guest_received_product_ids']??[]))))?>"><span class="sf-kicker">REQUEST RECEIVED</span><h1>Thank you<span class="sf-gold">.</span></h1><p>Your accessory order request has been recorded for ANKH review.</p>
<div class="sf-checkout-ref">Reference <strong>REQ-<?=str_pad((string)$received,5,'0',STR_PAD_LEFT)?></strong></div>
<?php if(!empty($_SESSION['guest_receipt_code']) && (int)($_SESSION['guest_receipt_id']??0)===$received):?><div class="sf-checkout-receipt"><span class="sf-kicker">LINK THIS REQUEST TO YOUR ACCOUNT</span><p>Your private receipt code:</p><strong><?=gcE((string)$_SESSION['guest_receipt_code'])?></strong><p>Save this code, then sign in to My Account with the same mobile number you entered on this request. Under <b>My Requests</b>, choose Link a guest receipt. ANKH will verify both your code and account phone before showing it.</p></div><?php endif;?>
<p>ANKH will contact you to confirm availability, delivery charges and payment arrangements. <strong>This is not a completed purchase, and no payment has been taken.</strong> Your request is not yet listed in My Orders until ANKH creates an order in Admin.</p>
<a href="./?tab=requests" class="sf-button">My requests &amp; tracking ↗</a><a href="./" class="sf-secondary">Continue browsing</a></section>
<?php else:?>
<section class="sf-checkout-title"><span class="sf-kicker">01 / REVIEW YOUR SELECTION</span><h1>Complete your request<span class="sf-gold">.</span></h1><p>For approved non-medicinal accessories only. Your basket is saved on this device; no payment is collected until ANKH confirms an order.</p></section>
<?php if($error!==''):?><p class="sf-checkout-error" role="alert"><?=gcE($error)?></p><?php endif;?>
<?php if(!$products):?><div class="sf-checkout-unavailable"><h2>Online checkout is not available yet.</h2><p>No products are currently approved for direct online order requests. You can still browse the collection and use your ANKH account.</p><a class="sf-button" href="./#collection">Browse profiles ↗</a></div>
<?php else:?>
<form method="post" action="./shop-checkout.php" class="sf-checkout-layout" id="sf-checkout-form">
<input type="hidden" name="csrf" value="<?=gcE($_SESSION['guest_csrf']??'')?>"><input type="hidden" name="request_token" value="<?=gcE($_SESSION['guest_token']??'')?>">
<div class="sf-honey"><label>Leave this field empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
<div class="sf-checkout-main"><section class="sf-checkout-panel"><span class="sf-kicker">01 / ELIGIBLE ACCESSORIES</span><h2>Review your items</h2><p class="sf-checkout-hint" id="sf-checkout-selected-count">Only eligible accessories can be requested.</p>
<?php foreach($products as $p):?><div class="sf-checkout-line" data-item-row="<?= (int)$p['id']?>">
<div><strong><?=gcE($p['name'])?></strong><small><?=gcMoney($p['price'])?> each</small></div>
<label>Quantity <input type="number" name="items[<?= (int)$p['id']?>]" data-checkout-qty="<?=(int)$p['id']?>" data-unit-price="<?=$p['price']?>" min="0" max="99" step="1" value="0"></label></div><?php endforeach;?>
<button type="button" id="sf-checkout-show-all" class="sf-checkout-show-all" hidden>Show other eligible accessories ↗</button>
<p class="sf-checkout-hint">ANKH validates product prices and eligibility when you submit. Research demos never become orders.</p></section>
<section class="sf-checkout-panel"><span class="sf-kicker">02 / CONTACT &amp; DELIVERY</span><h2>Your details</h2>
<div class="sf-checkout-fields"><label>Full name <input type="text" name="name" autocomplete="name" required maxlength="120" value="<?=gcE($_POST['name']??'')?>"></label>
<label>Email <input type="email" name="email" autocomplete="email" required maxlength="200" value="<?=gcE($_POST['email']??'')?>"></label>
<label>Phone number <input type="tel" name="phone" autocomplete="tel" required maxlength="40" value="<?=gcE($_POST['phone']??'')?>"></label>
<label>Delivery address <textarea name="address" required maxlength="600" rows="3" autocomplete="street-address"><?=gcE($_POST['address']??'')?></textarea></label>
<label>Optional note <textarea name="note" maxlength="600" rows="2"><?=gcE($_POST['note']??'')?></textarea></label>
</div></section></div>
<aside class="sf-checkout-panel sf-checkout-summary"><span class="sf-kicker">03 / CONFIRM</span><h2>Your request</h2>
<div class="sf-checkout-total"><span>Product subtotal</span><strong id="sf-guest-total">£0.00</strong></div>
<div class="sf-checkout-total"><span>Delivery</span><strong>Confirmed by ANKH</strong></div>
<p>No delivery price has been invented or charged. ANKH will confirm the complete amount and payment method before proceeding.</p>
<label class="sf-checkout-consent"><input type="checkbox" name="confirmed" value="1" required> <span>I understand this is an order request, not a completed purchase. I agree ANKH may contact me about this request.</span></label>
<button class="sf-button sf-checkout-submit" type="submit">Send request for review ↗</button>
<small class="sf-checkout-privacy">Submitted details are stored privately for ANKH staff review. No customer payment details are collected. No card or bank information is required.</small>
</aside></form>
<?php endif;endif;?>
</main></body></html>
