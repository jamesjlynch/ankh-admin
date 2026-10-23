<?php
declare(strict_types=1);
/**
 * ANKH premium storefront: staff-only working preview.
 * Real product records and photographs, isolated session basket, no orders or payments.
 * Explicitly NOT the public store, and does not modify publishing flags or stock.
 */
require_once __DIR__ . '/lib.php';
$local = PHP_SAPI === 'cli-server' && getenv('ANKH_TEST_ALLOW_HTTP') === '1'
    && in_array((string)($_SERVER['SERVER_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
if (!$local && ($_SERVER['HTTPS'] ?? '') !== 'on') { http_response_code(403); exit('HTTPS required.'); }
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
session_name('ankh_staff_preview');
session_set_cookie_params(['lifetime'=>0, 'path'=>'/', 'secure'=>!$local, 'httponly'=>true, 'samesite'=>'Strict']);
session_start();
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
if (!$local) header('Strict-Transport-Security: max-age=31536000');
function sfE(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function sfP(int $p): string { return '£'.number_format(max(0,$p)/100,2); }
function sfLink(array $args=[]): string { return './storefront-preview.php'.($args?'?'.http_build_query($args,'','&',PHP_QUERY_RFC3986):''); }
try {
    $config=portalConfig();
    $host=strtolower((string)($config['host'] ?? 'order.ankhpeptides.com'));
    if (!$local && $host!==strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))) { http_response_code(404);exit; }
    $hash=$config['preview_password_hash']??'';
    if (!is_string($hash) || $hash==='') { http_response_code(503);exit('Staff preview disabled.'); }
    $logged=!empty($_SESSION['staff_preview_authorised'])
        && hash_equals(hash('sha256',$hash),(string)($_SESSION['staff_preview_auth_version']??''))
        && (int)($_SESSION['staff_preview_last']??0)>time()-1200;
    if (!$logged) { header('Location: ./preview-live.php',true,303);exit; }
    $_SESSION['staff_preview_last']=time();
    $db=portalAdminDb($config);
    $metadata=ankhCatalogueContent($db);
    $visibility=ankhCatalogueVisibility($db);
    $products=[];$byId=[];
    foreach($db->query('SELECT id,name,description,price,active FROM products WHERE active=1 ORDER BY name COLLATE NOCASE') as $row) {
        $id=(int)$row['id'];$m=$metadata[$id]??[];
        $p=['id'=>$id,'name'=>(string)$row['name'],
            'description'=>(string)($m['description']?:$row['description']??''),
            'price'=>max(0,(int)$row['price']),
            'category'=>ankhCatalogueKind((string)$row['name']),
            'information'=>(array)($m['information']??[]),
            'photo'=>in_array((string)($m['image_mime']??''),['image/png','image/jpeg','image/webp'],true),
            'published'=>(int)($visibility[$id]??0)===1];
        $products[]=$p;$byId[$id]=$p;
    }
} catch(Throwable $e) {
    error_log('ANKH storefront preview: '.$e->getMessage());
    http_response_code(503);exit('Storefront preview is temporarily unavailable.');
}
$image=filter_var($_GET['image']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if ($image!==false && $image!==null) {
    if (!isset($byId[(int)$image]) || !$byId[(int)$image]['photo']) {http_response_code(404);exit;}
    $q=$db->prepare('SELECT image_data,image_mime FROM customer_catalogue_content WHERE product_id=?');
    $q->execute([(int)$image]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if (!$r || !in_array($r['image_mime'],['image/jpeg','image/png','image/webp'],true)) {http_response_code(404);exit;}
    $bytes=is_resource($r['image_data'])?stream_get_contents($r['image_data']):(string)$r['image_data'];
    if (!is_string($bytes) || strlen($bytes)>8*1024*1024) {http_response_code(404);exit;}
    header('Content-Type: '.$r['image_mime']);header('Content-Length: '.strlen($bytes));echo $bytes;exit;
}
$_SESSION['sf_preview_csrf']??=bin2hex(random_bytes(32));
$_SESSION['sf_preview_cart']??=[];
if (!is_array($_SESSION['sf_preview_cart'])) $_SESSION['sf_preview_cart']=[];
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['sf_preview_csrf'],$_POST['csrf'])) {
        http_response_code(400);exit('The request could not be verified.');
    }
    $action=(string)($_POST['action']??'');
    $id=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $quantity=filter_var($_POST['quantity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>20]]);
    if (!in_array($action,['add','set','clear'],true) || ($action!=='clear' && ($id===false || !isset($byId[(int)$id]) || $quantity===false))) {
        http_response_code(400);exit('Invalid basket request.');
    }
    if ($action==='clear') $_SESSION['sf_preview_cart']=[];
    else {
        $old=(int)($_SESSION['sf_preview_cart'][(int)$id]??0);
        $next=$action==='add'?min(20,$old+(int)$quantity):(int)$quantity;
        if ($next<1) unset($_SESSION['sf_preview_cart'][(int)$id]);
        else $_SESSION['sf_preview_cart'][(int)$id]=$next;
    }
    // PRG: refresh never submits the same basket mutation twice.
    header('Location: '.sfLink(['view'=>'basket']),true,303);exit;
}
// Never trust session basket lines; re-check all ids against the active catalogue.
$basket=[];$total=0;$count=0;
foreach($_SESSION['sf_preview_cart'] as $id=>$qty) {
    $id=(int)$id;$qty=(int)$qty;
    if (!isset($byId[$id]) || $qty<1 || $qty>20) {unset($_SESSION['sf_preview_cart'][$id]);continue;}
    $p=$byId[$id];$line=$p['price']*$qty;
    $basket[]=['product'=>$p,'quantity'=>$qty,'total'=>$line];
    $count+=$qty;$total+=$line;
}
$view=($_GET['view']??'')==='basket'?'basket':'browse';
$productId=filter_var($_GET['product']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$selected=($productId!==false && $productId!==null)?($byId[(int)$productId]??null):null;
$search=trim((string)($_GET['q']??''));if(mb_strlen($search)>70)$search=mb_substr($search,0,70);
$category=(string)($_GET['category']??'All');
$categories=['All','Research materials','Storage','Stationery'];
if(!in_array($category,$categories,true))$category='All';
$sort=(string)($_GET['sort']??'name');
if(!in_array($sort,['name','price-asc','price-desc'],true))$sort='name';
$filtered=array_values(array_filter($products,static fn($p)=>($category==='All'||$p['category']===$category)
    && ($search===''||stripos($p['name'].' '.ankhCatalogueSummary($p['description']),$search)!==false)));
if($sort!=='name')usort($filtered,static fn($a,$b)=>($sort==='price-asc'?($a['price']<=>$b['price']):($b['price']<=>$a['price']))?:strcasecmp($a['name'],$b['name']));
if($productId!==false && $productId!==null && !$selected)http_response_code(404);
?>
<!doctype html>
<html lang="en-GB"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#11120f"><meta name="robots" content="noindex,nofollow">
<title><?=sfE($selected?$selected['name'].' · ANKH Preview':($view==='basket'?'Basket · ANKH Preview':'ANKH · Premium Storefront Preview'))?></title>
<meta name="description" content="Protected internal design preview. No purchases or customer orders.">
<link rel="stylesheet" href="./storefront-preview.css">
</head><body>
<div class="sf-staff"><strong>ANKH / INTERNAL PREVIEW</strong><span>Actual catalogue data · Test basket · Payments and ordering disabled</span><a href="./preview-live.php">Staff portal ↗</a></div>
<div class="sf-promo"><span>✦</span> PRIVATE STOREFRONT DESIGN REVIEW <span>✦</span></div>
<header class="sf-header"><div class="sf-shell sf-head-inner">
 <a class="sf-logo" href="<?=sfE(sfLink())?>"><span class="sf-ankh">☥</span><span><strong>ANKH</strong><small>THE RESEARCH COLLECTION</small></span></a>
 <nav class="sf-links" aria-label="Main navigation"><a href="<?=sfE(sfLink())?>" <?=$view==='browse'&&!$selected?'aria-current="page"':''?>>Collection</a><a href="<?=sfE(sfLink(['category'=>'Storage']))?>">Accessories</a><a href="./public-catalogue.php">Research library</a></nav>
 <a class="sf-cart-nav" href="<?=sfE(sfLink(['view'=>'basket']))?>" aria-label="View preview basket with <?=$count?> items"><span class="sf-basket-icon" aria-hidden="true">▱</span> Basket <span class="sf-cart-count"><?=$count?></span></a>
 </div></header>
<main class="sf-shell">
<?php if($view==='basket'):?>
 <div class="sf-heading"><span class="sf-kicker">YOUR SELECTION</span><h1>The basket<span class="sf-gold">.</span></h1><p>Review your selections. This is an internal design preview and does not submit orders.</p></div>
 <div class="sf-checkout-layout"><section class="sf-basket-list" aria-label="Basket items">
 <?php if(!$basket):?><div class="sf-empty"><span>☥</span><h2>Nothing in your basket yet.</h2><p>Explore the collection to test the product-selection experience.</p><a class="sf-button" href="<?=sfE(sfLink())?>">Explore collection →</a></div><?php endif;?>
 <?php foreach($basket as $line):$p=$line['product'];?>
 <article class="sf-basket-item"><a class="sf-basket-photo" href="<?=sfE(sfLink(['product'=>$p['id']]))?>"><?php if($p['photo']):?><img src="<?=sfE(sfLink(['image'=>$p['id']]))?>" alt=""><?php else:?><span>☥</span><?php endif;?></a>
 <div class="sf-basket-info"><span class="sf-kicker"><?=sfE($p['category'])?></span><h2><?=sfE($p['name'])?></h2><p><?=sfP($p['price'])?> each</p>
 <form class="sf-qty" method="post"><input type="hidden" name="csrf" value="<?=sfE($_SESSION['sf_preview_csrf'])?>"><input type="hidden" name="action" value="set"><input type="hidden" name="product_id" value="<?=$p['id']?>"><label for="sf-qty-<?=$p['id']?>">Quantity</label><input id="sf-qty-<?=$p['id']?>" type="number" min="0" max="20" name="quantity" value="<?=$line['quantity']?>" required><button type="submit">Update</button><button type="submit" name="quantity" value="0" formnovalidate>Remove</button></form></div><strong><?=sfP($line['total'])?></strong></article>
 <?php endforeach;?></section>
 <aside class="sf-summary"><span class="sf-kicker">ORDER OVERVIEW</span><h2>Your summary</h2><div><span>Items (<?=$count?>)</span><strong><?=sfP($total)?></strong></div><div><span>Delivery</span><strong>Calculated at checkout</strong></div><div class="sf-summary-total"><span>Products subtotal</span><strong><?=sfP($total)?></strong></div>
 <p>Checkout cannot accept orders or payments in preview. Delivery, taxes, terms and product eligibility must be verified before launch.</p>
 <button type="button" class="sf-button" disabled aria-disabled="true">Checkout unavailable in preview</button>
 <a class="sf-secondary" href="<?=sfE(sfLink())?>">← Continue browsing</a>
 <?php if($basket):?><form method="post"><input type="hidden" name="csrf" value="<?=sfE($_SESSION['sf_preview_csrf'])?>"><input type="hidden" name="action" value="clear"><button class="sf-clear" type="submit">Clear preview basket</button></form><?php endif;?></aside></div>
<?php elseif($selected):$info=$selected['information'];?>
 <nav class="sf-breadcrumb" aria-label="Breadcrumb"><a href="<?=sfE(sfLink())?>">Collection</a><span>/</span><span><?=sfE($selected['name'])?></span></nav>
 <section class="sf-product-hero"><div class="sf-product-image"><?php if($selected['photo']):?><img src="<?=sfE(sfLink(['image'=>$selected['id']]))?>" alt="<?=sfE($selected['name'])?>"><?php else:?><span>☥</span><?php endif;?></div>
 <div class="sf-product-details"><span class="sf-kicker">ANKH / <?=sfE($selected['category'])?></span><h1><?=sfE($selected['name'])?></h1><div class="sf-tags"><span><?= $selected['published']?'Previously published':'Not currently published' ?></span><span>Research profile</span></div><p class="sf-lead"><?=sfE(ankhCatalogueSummary($selected['description']))?></p><div class="sf-product-price"><?=sfP($selected['price'])?><small>Admin retail price · preview only</small></div>
 <form method="post" class="sf-add-row"><input type="hidden" name="csrf" value="<?=sfE($_SESSION['sf_preview_csrf'])?>"><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?=$selected['id']?>"><label for="sf-product-qty">Qty</label><input id="sf-product-qty" name="quantity" min="1" max="20" type="number" value="1" required><button class="sf-button" type="submit">Add to preview basket →</button></form>
 <p class="sf-product-warning">Technical information only. Not for human or veterinary consumption or medical use. Public availability and checkout require separate legal review.</p></div></section>
 <div class="sf-info-grid"><section><span class="sf-kicker">01 / PRODUCT PROFILE</span><h2>Overview &amp; research context</h2><p><?=nl2br(sfE($selected['description']))?></p>
 <?php foreach(['research_focus'=>'Research focus','research'=>'Scientific literature','research_limitations'=>'Evidence limitations','storage'=>'Storage guidance','precautions'=>'Laboratory handling'] as $key=>$label):if(empty($info[$key]))continue;?><h3><?=sfE($label)?></h3><p><?=nl2br(sfE((string)$info[$key]))?></p><?php endforeach;?></section><aside><span class="sf-kicker">02 / SPECIFICATIONS</span><h2>Product details</h2><dl>
 <?php foreach(['strength'=>'Strength','presentation'=>'Format','sequence'=>'Sequence','formula'=>'Formula','weight'=>'Molecular weight','cas'=>'CAS','purity'=>'Purity'] as $key=>$label):if(trim((string)($info[$key]??''))==='')continue;?><div><dt><?=sfE($label)?></dt><dd><?=sfE($info[$key])?></dd></div><?php endforeach;?></dl><p>Batch-specific verification, purity and storage claims require supporting documents. Reference values are not batch test results.</p></aside></div>
<?php else:?>
 <section class="sf-hero"><div class="sf-hero-copy"><span class="sf-kicker"><span class="sf-line"></span> A NEW STANDARD IN RESEARCH SUPPLY</span><h1>Discover the<br><em>ANKH collection.</em></h1><p>Explore product identities, laboratory research context and an elegant shopping experience designed around clarity.</p>
 <div class="sf-hero-actions"><a class="sf-button" href="#sf-collection">Explore the collection <span>↗</span></a><a href="./public-catalogue.php">View research reference <span>↗</span></a></div>
 <div class="sf-hero-note"><span>☥</span><span>Staff design preview<br><small>All active products · no live selling</small></span></div></div>
 <div class="sf-hero-art"><div class="sf-orbit sf-orbit-a"></div><div class="sf-orbit sf-orbit-b"></div><div class="sf-hero-symbol">☥</div><span class="sf-art-label">A N K H &nbsp; / &nbsp; E S T . 2 0 2 6</span></div></section>
 <div class="sf-trust"><span><b>01</b> Curated catalogue</span><span><b>02</b> In-depth research profiles</span><span><b>03</b> Thoughtful customer experience</span><span><b>04</b> UK-based business</span></div>
 <section class="sf-collection" id="sf-collection"><div class="sf-section-title"><div><span class="sf-kicker">THE COLLECTION</span><h2>Explore every detail<span class="sf-gold">.</span></h2><p><?=count($products)?> active products shown to staff. <?=count(array_filter($products,static fn($p)=>$p['published']))?> are currently marked published.</p></div><span class="sf-index">ANKH / 001</span></div>
 <form method="get" class="sf-filter" role="search"><label for="sf-search">Find a product</label><input name="q" id="sf-search" type="search" maxlength="70" placeholder="Search by name or research topic..." value="<?=sfE($search)?>">
 <label for="sf-cat">Category</label><select id="sf-cat" name="category"><?php foreach($categories as $cat):?><option value="<?=sfE($cat)?>" <?=$category===$cat?'selected':''?>><?=sfE($cat)?></option><?php endforeach;?></select>
 <label for="sf-sort">Sort by</label><select name="sort" id="sf-sort"><option value="name" <?=$sort==='name'?'selected':''?>>Name A–Z</option><option value="price-asc" <?=$sort==='price-asc'?'selected':''?>>Price: low to high</option><option value="price-desc" <?=$sort==='price-desc'?'selected':''?>>Price: high to low</option></select><button type="submit">Apply filters</button></form>
 <p class="sf-results"><?=count($filtered)?> results <span>·</span> Images and prices reflect the existing Admin records</p>
 <?php if(!$filtered):?><div class="sf-empty"><h3>No results found.</h3><p>Try a shorter keyword or clear the category filter.</p><a href="<?=sfE(sfLink())?>">Clear filters →</a></div><?php else:?>
 <div class="sf-grid"><?php foreach($filtered as $p):?>
 <article class="sf-card"><a class="sf-card-image" href="<?=sfE(sfLink(['product'=>$p['id']]))?>" aria-label="View <?=sfE($p['name'])?>">
 <span class="sf-card-topline"><?= $p['published']?'PUBLISHED':'STAFF ONLY' ?></span><?php if($p['photo']):?><img src="<?=sfE(sfLink(['image'=>$p['id']]))?>" loading="lazy" decoding="async" alt="<?=sfE($p['name'])?>"><?php else:?><span class="sf-card-placeholder">☥</span><?php endif;?><span class="sf-card-view">View details ↗</span></a>
 <div class="sf-card-copy"><span class="sf-kicker"><?=sfE($p['category'])?></span><h3><a href="<?=sfE(sfLink(['product'=>$p['id']]))?>"><?=sfE($p['name'])?></a></h3><p><?=sfE(mb_substr(ankhCatalogueSummary($p['description']),0,135))?><?=mb_strlen(ankhCatalogueSummary($p['description']))>135?'…':''?></p><div class="sf-card-bottom"><div><strong><?=sfP($p['price'])?></strong><small>Preview retail price</small></div><form method="post"><input type="hidden" name="csrf" value="<?=sfE($_SESSION['sf_preview_csrf'])?>"><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="quantity" value="1"><button type="submit" aria-label="Add <?=sfE($p['name'])?> to preview basket">＋</button></form></div></div></article>
 <?php endforeach;?></div><?php endif;?></section>
 <section class="sf-editorial"><div><span class="sf-kicker">INSIDE THE ANKH EXPERIENCE</span><h2>Every detail.<br><em>Considered.</em></h2><p>Beautiful photography, transparent technical information and a simple path from discovery to selection. Built for a calm, premium experience on mobile and desktop.</p></div><div class="sf-editorial-emblem">☥</div></section>
<?php endif;?>
</main>
<footer class="sf-footer"><div class="sf-shell sf-footer-grid"><div><div class="sf-footer-logo">☥ ANKH</div><p>Laboratory research reference. Not intended for medical, human or veterinary use. No order or payment is accepted in this preview.</p></div><div><strong>Discover</strong><a href="<?=sfE(sfLink())?>">Collection</a><a href="./public-catalogue.php">Scientific reference</a></div><div><strong>Account</strong><a href="./preview-live.php">Staff preview</a><a href="<?=sfE(sfLink(['view'=>'basket']))?>">Preview basket</a></div></div><div class="sf-shell sf-copyright">ANKH GLOBAL LTD · INTERNAL PREVIEW ONLY · © <?=date('Y')?></div></footer>
</body></html>
