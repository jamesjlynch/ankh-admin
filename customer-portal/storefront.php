<?php
declare(strict_types=1);
/**
 * Public ANKH storefront front door.
 *
 * No customer session, orders, payment links or Admin writes.
 * Uses the EXISTING active/published product selection only, even if an
 * administrator has not reviewed the commercial sale status separately.
 * Research pages are informational, not an offer of medical products.
 */
require_once __DIR__.'/public-catalogue-core.php';
require_once __DIR__.'/customer-catalogue.php';
$loopback = PHP_SAPI==='cli-server' && getenv('ANKH_TEST_ALLOW_HTTP')==='1'
    && in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true)
    && preg_match('/^(?:127\\.0\\.0\\.1|\\[::1\\])(?::[0-9]+)?$/D',(string)($_SERVER['HTTP_HOST']??''))===1;
if (!$loopback && ($_SERVER['HTTPS']??'')!=='on') {http_response_code(403);exit('HTTPS required.');}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
if(!$loopback)header('Strict-Transport-Security: max-age=31536000');
function pfEscape(mixed $text):string {return htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function pfUrl(array $params=[]):string{return './'.($params?'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986):'');}
function pfType(string $name):string{return ankhPublicType($name);}
function pfExcerpt(string $text,int $max=130):string {
    $text=ankhCatalogueSummary($text);
    if (strlen($text)<=$max)return $text;
    return rtrim(substr($text,0,$max)).'…';
}
try {
    $path=getenv('ANKH_PORTAL_CONFIG') ?: dirname(__DIR__,2).'/ankh-customer-portal-config.php';
    if(!is_file($path))throw new RuntimeException('Private customer configuration absent.');
    $config=require $path;
    $expectedHost=strtolower((string)($config['host']??'order.ankhpeptides.com'));
    if(!$loopback && $expectedHost!==strtolower((string)($_SERVER['HTTP_HOST']??''))){http_response_code(404);exit;}
    $dbPath=is_array($config)?($config['admin_database_path']??null):null;
    if(!is_string($dbPath)||!is_file($dbPath))throw new RuntimeException('The catalogue database is unavailable.');
    $db=new PDO('sqlite:'.$dbPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000');
    $products=ankhPublicCatalogueProducts($db);
} catch (Throwable $e) {
    error_log('ANKH public storefront: '.$e->getMessage());
    http_response_code(503);
    exit('<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ANKH</title></head><body style="background:#0b0d0c;color:#e8d7ad;padding:12vh 8vw;font:16px system-ui"><h1>☥ ANKH</h1><p>The collection is temporarily unavailable.</p><p><a style="color:#e8d7ad" href="./?login=1">Customer account</a> · <a style="color:#e8d7ad" href="mailto:info@ankhpeptides.com">Contact us</a></p></body></html>');
}
if(isset($_GET['image'])) {http_response_code(404);exit;}
$id=filter_var($_GET['product']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$selected=null;
if($id!==false && $id!==null)foreach($products as $p)if((int)$p['id']===(int)$id){$selected=$p;break;}
if($id!==false && $id!==null && !$selected)http_response_code(404);
$search=is_string($_GET['q']??null)?trim($_GET['q']):'';
if(strlen($search)>120)$search=substr($search,0,120);
$category=is_string($_GET['category']??null)?$_GET['category']:'All research';
$categories=['All research','Research compound','Laboratory reagent','Research accessory','Multi-component research blend'];
if(!in_array($category,$categories,true))$category='All research';
$filtered=array_values(array_filter($products,static function(array $p) use($category,$search):bool {
    if($category!=='All research' && pfType((string)$p['name'])!==$category)return false;
    $text=$p['name'].' '.($p['information']['research_focus']??'');
    return $search==='' || stripos($text,$search)!==false;
}));
$notice=ankhPublicNotice();
?>
<!doctype html>
<html lang="en-GB">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="theme-color" content="#0c0d0b"><meta name="robots" content="noindex,nofollow">
<title><?=pfEscape($selected?$selected['name'].' · ANKH':'ANKH · Research Collection')?></title>
<meta name="description" content="Explore the ANKH research collection, technical reference profiles and private customer account.">
<link rel="icon" href="./icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="./storefront-preview.css">
<link rel="stylesheet" href="./storefront.css">
</head>
<body class="sf-public">
<a href="#main" class="sf-skip">Skip to main content</a>
<div class="sf-promo">ANKH <span aria-hidden="true">✦</span> TECHNICAL RESEARCH COLLECTION</div>
<header class="sf-header"><div class="sf-shell sf-head-inner">
<a class="sf-logo" href="./" aria-label="ANKH home"><span class="sf-ankh" aria-hidden="true">☥</span><span><strong>ANKH</strong><small>RESEARCH COLLECTION</small></span></a>
<nav class="sf-links" aria-label="Primary"><a href="./" <?=!$selected?'aria-current="page"':''?>>Collection</a><a href="./public-catalogue.php">Research library</a><a href="mailto:info@ankhpeptides.com">Contact</a></nav>
<a class="sf-cart-nav sf-account-entry" href="./?tab=home" aria-label="Sign in or open your ANKH account"><span class="sf-user-symbol" aria-hidden="true">◉</span> My Account <span aria-hidden="true">↗</span></a>
</div></header>
<main class="sf-shell" id="main">
<?php if($selected):$info=$selected['information'];?>
<nav class="sf-breadcrumb" aria-label="Breadcrumb"><a href="./">Collection</a><span>/</span><span><?=pfEscape($selected['name'])?></span></nav>
<section class="sf-product-hero">
<div class="sf-product-image"><?php if($selected['has_photo']):?><img src="<?=pfEscape('./public-catalogue.php?image='.(int)$selected['id'])?>" alt="<?=pfEscape($selected['name'])?>"><?php else:?><span aria-hidden="true">☥</span><?php endif;?></div>
<div class="sf-product-details"><span class="sf-kicker">ANKH / <?=pfEscape(pfType($selected['name']))?></span><h1><?=pfEscape($selected['name'])?></h1>
<div class="sf-tags"><span>Technical reference</span><span>Not for medical use</span></div>
<p class="sf-lead"><?=pfEscape(pfExcerpt($selected['description'],600))?></p>
<p class="sf-product-warning"><?=pfEscape($notice)?></p>
<div class="sf-detail-actions"><a class="sf-button" href="./?tab=home">My Account <span aria-hidden="true">→</span></a><a class="sf-secondary" href="./public-catalogue.php?product=<?=(int)$selected['id']?>">Explore full scientific profile ↗</a></div>
</div></section>
<div class="sf-info-grid"><section><span class="sf-kicker">01 / RESEARCH REFERENCE</span><h2>Overview &amp; evidence</h2>
<?=ankhCatalogueFormat((string)$selected['description'])?>
<?php foreach(['research_focus'=>'Research focus','evidence_level'=>'Evidence status','mechanism'=>'Experimental mechanism','research'=>'Research context','research_limitations'=>'Evidence limitations','precautions'=>'Laboratory precautions'] as $key=>$title):if(empty($info[$key]))continue;?><h3><?=pfEscape($title)?></h3><?=ankhCatalogueFormat((string)$info[$key])?><?php endforeach;?>
</section><aside><span class="sf-kicker">02 / PRODUCT IDENTITY</span><h2>Technical specifications</h2><dl>
<?php foreach(['strength'=>'Strength / quantity','presentation'=>'Supplied form','weight'=>'Molecular weight','formula'=>'Molecular formula','sequence'=>'Sequence','cas'=>'CAS number','storage'=>'Storage'] as $key=>$title):if(trim((string)($info[$key]??''))==='')continue;?><div><dt><?=pfEscape($title)?></dt><dd><?=pfEscape((string)$info[$key])?></dd></div><?php endforeach;?></dl>
<p>Specifications and research publications do not establish the identity or quality of a particular batch. Product documentation requires independent verification.</p></aside></div>
<?php elseif($id!==false&&$id!==null):?>
<div class="sf-heading"><span class="sf-kicker">NOT AVAILABLE</span><h1>Product not found.</h1><p>This profile is not currently published. <a href="./">Return to the collection.</a></p></div>
<?php else:?>
<section class="sf-hero"><div class="sf-hero-copy"><span class="sf-kicker"><span class="sf-line"></span> ANKH GLOBAL LTD / UK</span><h1>Discover the<br><em>ANKH collection.</em></h1>
<p>Technical product profiles, thoughtful presentation and research information, all in one place. Explore freely, then securely access your personal ANKH account.</p>
<div class="sf-hero-actions"><a class="sf-button" href="#collection">Explore the collection <span aria-hidden="true">↗</span></a><a href="./?tab=home">Customer sign in <span aria-hidden="true">→</span></a></div>
<div class="sf-hero-note"><span aria-hidden="true">☥</span><span>Research reference<br><small>Public product browsing · Private account access</small></span></div></div>
<div class="sf-hero-art" aria-hidden="true"><div class="sf-orbit sf-orbit-a"></div><div class="sf-orbit sf-orbit-b"></div><div class="sf-hero-symbol">☥</div><span class="sf-art-label">A N K H / R E S E A R C H</span></div></section>
<div class="sf-trust" aria-label="Site features"><span><b>01</b> Product profiles</span><span><b>02</b> Scientific references</span><span><b>03</b> Secure customer access</span><span><b>04</b> Order history &amp; My Fridge</span></div>
<section class="sf-collection" id="collection">
<div class="sf-section-title"><div><span class="sf-kicker">THE COLLECTION</span><h2>Explore every detail<span class="sf-gold">.</span></h2><p>Browse <?=count($products)?> published technical profiles. Products hidden in Admin remain hidden here.</p></div><span class="sf-index">ANKH / 001</span></div>
<form method="get" action="./" class="sf-filter" role="search">
<label for="sf-search">Search collection</label><input id="sf-search" type="search" name="q" value="<?=pfEscape($search)?>" placeholder="Search research profiles…" maxlength="120">
<label for="sf-category">Product category</label><select id="sf-category" name="category"><?php foreach($categories as $item):?><option value="<?=pfEscape($item)?>" <?=$category===$item?'selected':''?>><?=pfEscape($item)?></option><?php endforeach;?></select>
<button type="submit">Search collection</button></form>
<p class="sf-results"><?=count($filtered)?> matching profile<?=count($filtered)===1?'':'s'?></p>
<?php if(!$filtered):?><div class="sf-empty"><h3>No matching profiles.</h3><p>Try a different term or browse all products.</p><a class="sf-secondary" href="./">Clear search →</a></div><?php else:?>
<div class="sf-grid">
<?php foreach($filtered as $p):?><article class="sf-card">
<a class="sf-card-image" href="<?=pfEscape(pfUrl(['product'=>$p['id']]))?>" aria-label="View <?=pfEscape($p['name'])?>">
<?php if($p['has_photo']):?><img src="<?=pfEscape('./public-catalogue.php?image='.(int)$p['id'])?>" alt="" loading="lazy" decoding="async"><?php else:?><span class="sf-card-placeholder" aria-hidden="true">☥</span><?php endif;?><span class="sf-card-view">Discover more ↗</span></a>
<div class="sf-card-copy"><span class="sf-kicker"><?=pfEscape(pfType($p['name']))?></span><h3><a href="<?=pfEscape(pfUrl(['product'=>$p['id']]))?>"><?=pfEscape($p['name'])?></a></h3><p><?=pfEscape(pfExcerpt($p['description']))?></p>
<div class="sf-card-bottom"><span class="sf-profile-label">Technical profile</span><a href="<?=pfEscape(pfUrl(['product'=>$p['id']]))?>" class="sf-card-cta">View details <span aria-hidden="true">↗</span></a></div></div></article><?php endforeach;?></div><?php endif;?>
</section>
<section class="sf-editorial"><div><span class="sf-kicker">YOUR PERSONAL ANKH SPACE</span><h2>Discover here.<br><em>Manage there.</em></h2><p>Already an ANKH customer? Sign in to see your order history, account details, saved products, delivery progress and My Fridge in one secure place.</p><a class="sf-button" href="./?tab=home">Open My Account <span aria-hidden="true">→</span></a></div><div class="sf-editorial-emblem" aria-hidden="true">☥</div></section>
<?php endif;?>
</main>
<footer class="sf-footer"><div class="sf-shell sf-footer-grid"><div><div class="sf-footer-logo">☥ ANKH</div><p>Technical research information only. Not intended for human or veterinary consumption or administration. Product publication does not establish regulatory authorisation.</p></div><div><strong>Explore</strong><a href="./">Collection</a><a href="./public-catalogue.php">Scientific reference</a></div><div><strong>Your ANKH</strong><a href="./?tab=home">Customer account</a><a href="mailto:info@ankhpeptides.com">Support</a></div></div><div class="sf-shell sf-copyright">ANKH GLOBAL LTD · PUBLIC RESEARCH REFERENCE · © <?=date('Y')?></div></footer>
</body></html>
