<?php
declare(strict_types=1);
/**
 * Public no-login checkout VISUAL DEMO. It cannot create enquiries,
 * orders or payments; client keeps the draft locally on this device.
 */
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);exit('Read-only test page.');}
$loopback=PHP_SAPI==='cli-server'&&getenv('ANKH_TEST_ALLOW_HTTP')==='1'
    &&in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true)
    &&preg_match('/^(?:127\\.0\\.0\\.1|\\[::1\\])(?::[0-9]+)?$/D',(string)($_SERVER['HTTP_HOST']??''))===1;
if(!$loopback&&($_SERVER['HTTPS']??'')!=='on'){http_response_code(403);exit('HTTPS required.');}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');header('X-Robots-Tag: noindex,nofollow');
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
require_once __DIR__.'/public-catalogue-core.php';
require_once __DIR__.'/storefront-commerce.php';
function demoEscape(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
try{
    $configFile=getenv('ANKH_PORTAL_CONFIG')?:dirname(__DIR__,2).'/ankh-customer-portal-config.php';
    if(!is_file($configFile))throw new RuntimeException('Missing configuration');
    $config=require $configFile;
    if(!is_array($config))throw new RuntimeException('Bad configuration');
    if(!$loopback&&strtolower((string)($_SERVER['HTTP_HOST']??''))!==strtolower((string)($config['host']??'order.ankhpeptides.com'))){http_response_code(404);exit;}
    $path=$config['admin_database_path']??'';
    if(!is_string($path)||!is_file($path))throw new RuntimeException('Missing catalogue');
    $admin=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $admin->exec('PRAGMA query_only=ON');
    $products=ankhPublicCatalogueProducts($admin);
    $previewPrices=sfRetailPreviewPrices($admin,$products);
    $portalPath=$config['portal_database_path']??'';
    if(is_string($portalPath)&&is_file($portalPath)){
        $portal=new PDO('sqlite:'.$portalPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $portal->exec('PRAGMA query_only=ON');
        $demo=sfDemoVisible($portal,$products);
    }else{$demo=[];foreach($products as $p)$demo[(int)$p['id']]=true;}
}catch(Throwable $e){error_log('ANKH demo checkout: '.$e->getMessage());http_response_code(503);exit('Test basket is temporarily unavailable.');}
?>
<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#0c0d0b"><title>Demo checkout · ANKH</title>
<link rel="stylesheet" href="./storefront-preview.css"><link rel="stylesheet" href="./storefront.css?v=6"><script src="./shop-demo.js?v=2" defer></script></head>
<body class="sf-public sf-checkout-page"><header class="sf-header"><div class="sf-shell sf-head-inner"><a class="sf-logo" href="./" aria-label="ANKH homepage"><span class="sf-ankh" aria-hidden="true">☥</span><span><strong>ANKH</strong><small>SHOPPING DEMONSTRATION</small></span></a><a class="sf-account-entry" href="./">Back to products ↗</a></div></header>
<main class="sf-shell sf-checkout-shell"><a href="./#collection" class="sf-back-link">← Continue browsing</a>
<section class="sf-checkout-title"><span class="sf-kicker">PREVIEW · NO LOGIN REQUIRED</span><h1>Test your checkout<span class="sf-gold">.</span></h1><p>Preview the shopping journey with the products in your basket. This demo never places an order, collects a payment, submits an enquiry or reserves stock.</p></section>
<div class="sf-checkout-layout">
<section class="sf-checkout-panel"><span class="sf-kicker">01 / YOUR SELECTION</span><h2>Basket summary</h2><div class="sf-demo-items" id="sf-demo-lines"><p>Loading your basket…</p></div>
<div class="sf-demo-available" hidden>
<?php foreach($products as $p):if(!isset($demo[(int)$p['id']]))continue;?><span data-demo-id="<?=(int)$p['id']?>" data-demo-name="<?=demoEscape($p['name'])?>" data-demo-price="<?=(int)($previewPrices[(int)$p['id']]??0)?>"></span><?php endforeach;?>
</div>
<p class="sf-checkout-hint">Quantities are saved only in this browser. Displayed retail values are test previews; actual order totals and delivery charges are not confirmed.</p>
<a href="./#collection" class="sf-secondary">← Add more products</a></section>
<aside class="sf-checkout-panel sf-checkout-summary"><span class="sf-kicker">02 / DEMO COMPLETION</span><h2>Ready to preview?</h2><p>No payment details, delivery details or account sign-in are needed for this simulation.</p>
<div class="sf-demo-total"><span>Preview product subtotal</span><strong id="sf-demo-subtotal">£0.00</strong></div><p>Not a payable amount. Delivery and order acceptance are not included.</p>
<button type="button" class="sf-button sf-checkout-submit" id="sf-demo-finish" disabled>Simulate checkout ↗</button>
<p class="sf-demo-confirmed" id="sf-demo-confirmed" hidden role="status">Demo complete. No order, payment or customer record has been created.</p>
<a href="./?tab=home" class="sf-secondary">Go to My Account ↗</a></aside></div>
</main></body></html>
