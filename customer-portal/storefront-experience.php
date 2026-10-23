<?php
/** ANKH public storefront: accessible design components; data populated only from reviewed publication flags. */
if(!isset($products,$filtered,$notice)) {http_response_code(404);exit;}
$heroPhotos=array_slice(array_values(array_filter($products,static fn($p)=>!empty($p['has_photo']))),0,3);
$variants=$selected?pfVariants($selected,$products):[];
$related=$selected?pfRelated($selected,$products):[];
?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0c0d0b"><meta name="robots" content="noindex,nofollow">
<meta name="description" content="Discover the ANKH collection, technical laboratory research profiles and private ANKH customer account.">
<title><?=pfEscape($selected?$selected['name'].' · ANKH':'ANKH · Research Collection')?></title>
<link rel="icon" href="./icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="./storefront-preview.css">
<link rel="stylesheet" href="./storefront.css?v=2">
<script defer src="./storefront.js?v=2"></script>
<script defer src="./storefront-cart.js?v=1"></script>
</head>
<body class="sf-public">
<a href="#main" class="sf-skip">Skip to content</a>
<div class="sf-promo"><span aria-hidden="true">✦</span> ANKH GLOBAL LTD <span aria-hidden="true">✦</span> TECHNICAL RESEARCH COLLECTION</div>
<header class="sf-header"><div class="sf-shell sf-head-inner">
<a class="sf-logo" href="./" aria-label="ANKH home"><span class="sf-ankh" aria-hidden="true">☥</span><span><strong>ANKH</strong><small>THE RESEARCH COLLECTION</small></span></a>
<nav class="sf-links" aria-label="Primary navigation">
<a href="./" <?=!$selected?'aria-current="page"':''?>>Collection</a>
<a href="./#sf-process">Our approach</a>
<a href="./public-catalogue.php">Research library</a>
<?php if(!empty($whatsappUrl)):?><a href="<?=pfEscape($whatsappUrl)?>" rel="noopener noreferrer" target="_blank">WhatsApp support ↗</a><?php else:?><a href="mailto:info@ankhpeptides.com">Contact</a><?php endif;?>
</nav>
<a class="sf-saved-entry" href="./?saved=1" aria-label="Open saved research shortlist">Saved <span data-saved-count>0</span></a>
<button class="sf-basket-entry sf-cart-toggle" type="button" data-open-cart aria-label="Open accessory shopping basket">Basket <span data-cart-count>0</span> ↗</button>
<a class="sf-cart-nav sf-account-entry" href="./?tab=home" aria-label="Open your ANKH customer account"><span class="sf-user-symbol" aria-hidden="true">◉</span> My Account <span aria-hidden="true">↗</span></a>
</div></header>
<main class="sf-shell" id="main">
<?php if($selected):$info=$selected['information'];?>
<nav class="sf-breadcrumb" aria-label="Breadcrumb"><a href="./">Collection</a><span aria-hidden="true">/</span><a href="<?=pfEscape(pfUrl(['category'=>pfType($selected['name'])]))?>"><?=pfEscape(pfType($selected['name']))?></a><span aria-hidden="true">/</span><span><?=pfEscape($selected['name'])?></span></nav>
<section class="sf-product-hero sf-product-hero-v2">
<div class="sf-detail-visual-column"><div class="sf-product-image sf-photo-stage">
<?php if($selected['has_photo']):?><img src="<?=pfEscape('./public-catalogue.php?image='.(int)$selected['id'])?>" alt="<?=pfEscape($selected['name'].' product photograph')?>" fetchpriority="high" decoding="async" width="640" height="640"><?php else:?><span aria-hidden="true">☥</span><?php endif;?>
<span class="sf-photo-mark" aria-hidden="true">☥</span></div>
<div class="sf-image-caption"><span>ANKH / PRODUCT PROFILE</span><span>REF. <?=str_pad((string)$selected['id'],4,'0',STR_PAD_LEFT)?></span></div></div>
<div class="sf-product-details">
<span class="sf-kicker"><?=pfEscape(pfType($selected['name']))?> <span aria-hidden="true">/</span> PROFILE <?=str_pad((string)$selected['id'],3,'0',STR_PAD_LEFT)?></span>
<h1><?=pfEscape($selected['name'])?></h1>
<div class="sf-tags"><span>Laboratory reference</span><span>Technical specifications</span></div>
<p class="sf-lead"><?=pfEscape(pfExcerpt((string)$selected['description'],340))?></p>
<?php if(count($variants)>1):?><div class="sf-variants"><strong>Related formats &amp; strengths</strong><div class="sf-variant-items">
<?php foreach($variants as $v):?><a href="<?=pfEscape(pfUrl(['product'=>$v['id']]))?>" <?=$v['id']===$selected['id']?'aria-current="page"':''?> title="<?=pfEscape($v['name'])?>"><?=pfEscape(pfStrength($v)?:$v['name'])?></a><?php endforeach;?>
</div></div><?php endif;?>
<div class="sf-detail-spec-mini">
<?php foreach(['strength'=>'Quantity','presentation'=>'Presentation','purity'=>'Purity reference'] as $field=>$label):if(empty($info[$field]))continue;?><span><small><?=pfEscape($label)?></small><strong><?=pfEscape($info[$field])?></strong></span><?php endforeach;?>
</div>
<?php if(isset($buyable[(int)$selected['id']])):?>
<div class="sf-shop-inline"><span>Available for accessory request · <strong>£<?=number_format($buyable[(int)$selected['id']]['price']/100,2)?></strong></span>
<button class="sf-button" type="button" data-cart-id="<?=(int)$selected['id']?>" data-cart-price="<?=(int)$buyable[(int)$selected['id']]['price']?>" data-cart-name="<?=pfEscape($selected['name'])?>">Add to basket ＋</button></div>
<?php endif;?>
<div class="sf-detail-actions">
<a class="sf-button" href="./?tab=home">Open My Account <span aria-hidden="true">↗</span></a>
<a class="sf-secondary" href="./public-catalogue.php?product=<?=(int)$selected['id']?>">Full scientific reference →</a><button type="button" class="sf-save-action" data-save-id="<?=(int)$selected['id']?>" aria-pressed="false">♡ Save to research shortlist</button>
</div>
<p class="sf-product-warning">Technical research information only. This page does not offer medical products for sale. See the laboratory and regulatory notice below.</p>
</div></section>
<section class="sf-profile-content" aria-label="Product profile">
<div class="sf-profile-main">
<div class="sf-section-intro"><span class="sf-kicker">IN-DEPTH REFERENCE</span><h2>Beyond the label<span class="sf-gold">.</span></h2><p>Organised scientific information, without distracting you from the details.</p></div>
<details class="sf-accordion" open><summary><span><b>01</b> Overview</span><span aria-hidden="true">＋</span></summary><div class="sf-accordion-content"><?=ankhCatalogueFormat((string)$selected['description'])?></div></details>
<?php $sections=[
  ['title'=>'Research focus &amp; scientific context','keys'=>['research_focus'=>'Research focus','research'=>'Research and literature overview','evidence_level'=>'Evidence status','mechanism'=>'Experimental mechanism','research_limitations'=>'Evidence limitations','applications'=>'Experimental research topics','references'=>'Published references']],
  ['title'=>'Material identity &amp; specifications','keys'=>['peptide_type'=>'Compound type','appearance'=>'Appearance','amino_length'=>'Amino-acid length','weight'=>'Molecular weight','formula'=>'Molecular formula','sequence'=>'Amino-acid sequence','cas'=>'CAS number','pubchem'=>'PubChem CID','half_life'=>'Experimental half-life context']],
  ['title'=>'Storage &amp; handling','keys'=>['storage'=>'Storage before preparation','prepared_storage'=>'Storage after laboratory preparation','precautions'=>'Laboratory handling']],
  ['title'=>'Questions &amp; documentation','keys'=>['faq'=>'Questions &amp; answers']],
];?>
<?php foreach($sections as $i=>$section):$has=false;foreach($section['keys'] as $field=>$label)if(!empty($info[$field]))$has=true;
if(!$has)continue;?><details class="sf-accordion"><summary><span><b><?=str_pad((string)($i+2),2,'0',STR_PAD_LEFT)?></b> <?=$section['title']?></span><span aria-hidden="true">＋</span></summary><div class="sf-accordion-content">
<?php foreach($section['keys'] as $field=>$label):if(trim((string)($info[$field]??''))==='')continue;?><h3><?=pfEscape($label)?></h3><?=ankhCatalogueFormat((string)$info[$field])?><?php endforeach;?>
</div></details><?php endforeach;?>
<details class="sf-accordion"><summary><span><b>06</b> Regulatory &amp; evidence notice</span><span aria-hidden="true">＋</span></summary><div class="sf-accordion-content"><p><?=pfEscape($notice)?></p><p>Research references are not evidence of pharmaceutical approval, batch release, sterility or suitability for administration. An analysis document should be assessed against the actual batch and laboratory method.</p></div></details>
</div>
<aside class="sf-profile-aside">
<div class="sf-spec-card"><span class="sf-kicker">PRODUCT IDENTITY</span><h2>At a glance.</h2><dl>
<?php foreach(['strength'=>'Strength / quantity','presentation'=>'Supplied form','appearance'=>'Appearance','peptide_type'=>'Material type','amino_length'=>'Amino-acid length','formula'=>'Formula','weight'=>'Molecular weight','sequence'=>'Sequence','cas'=>'CAS number','purity'=>'Listed purity','storage'=>'Storage'] as $key=>$label):
if(trim((string)($info[$key]??''))==='')continue;?><div><dt><?=pfEscape($label)?></dt><dd><?=pfEscape((string)$info[$key])?></dd></div><?php endforeach;?>
</dl><p>Specifications are provided as reference information and are not a substitute for a batch-specific certificate.</p></div>
<div class="sf-assist-card"><span class="sf-kicker">NEED CLARIFICATION?</span><h3>Talk to ANKH.</h3><p>Questions about existing orders, product documentation or your account?</p><?php if(!empty($whatsappUrl)):?><a href="<?=pfEscape($whatsappUrl)?>" target="_blank" rel="noopener noreferrer">WhatsApp ANKH ↗</a><?php else:?><a href="mailto:info@ankhpeptides.com?subject=ANKH%20product%20enquiry">Contact support ↗</a><?php endif;?></div>
</aside></section>
<?php if($related):?><section class="sf-related"><div class="sf-section-title"><div><span class="sf-kicker">MORE TO EXPLORE</span><h2>Continue researching<span class="sf-gold">.</span></h2></div></div>
<div class="sf-grid">
<?php foreach($related as $p):require __DIR__.'/storefront-card.php';endforeach;?>
</div></section><?php endif;?>
<?php elseif(!empty($savedView)):?>
<section class="sf-heading sf-saved-heading"><span class="sf-kicker">YOUR RESEARCH LIST</span><h1>Saved profiles<span class="sf-gold">.</span></h1><p>Keep track of technical profiles on this device. Your shortlist is stored only in this browser and does not create an order.</p><a href="./#collection" class="sf-secondary">← Browse all profiles</a></section>
<div class="sf-saved-empty" data-saved-empty><h2>Nothing saved yet.</h2><p>Select the heart beside any profile to add it to this list.</p><a class="sf-button" href="./#collection">Explore collection ↗</a></div>
<section class="sf-grid sf-grid-v2 sf-saved-grid" data-saved-grid hidden aria-label="Your saved profiles">
<?php foreach($products as $p):require __DIR__.'/storefront-card.php';endforeach;?>
</section>
<noscript><p>Saved lists require JavaScript enabled. Product profiles remain available through the collection.</p></noscript>
<?php elseif($id!==false&&$id!==null):?>
<div class="sf-heading"><span class="sf-kicker">NOT AVAILABLE</span><h1>Product not found.</h1><p>This profile is not currently published. <a href="./">Return to the collection.</a></p></div>
<?php else:?>
<section class="sf-hero sf-hero-v2">
<div class="sf-hero-copy"><span class="sf-kicker"><span class="sf-line"></span> CURATED RESEARCH REFERENCE / UK</span>
<h1>Discover the<br><em>ANKH collection.</em></h1>
<p>Explore a modern research collection with considered design, clear specifications and an account experience that keeps everything together.</p>
<div class="sf-hero-actions"><a class="sf-button" href="#collection">Explore the collection <span aria-hidden="true">↗</span></a><a href="./?tab=home">My ANKH account <span aria-hidden="true">→</span></a></div>
<div class="sf-hero-note"><span aria-hidden="true">☥</span><span>Explore with confidence<br><small>Factual profiles · Account privacy first</small></span></div></div>
<div class="sf-hero-art sf-real-art" aria-label="ANKH product collection photography">
<?php if($heroPhotos):?><div class="sf-hero-photo-wall">
<?php foreach($heroPhotos as $j=>$h):?><a href="<?=pfEscape(pfUrl(['product'=>$h['id']]))?>" class="sf-hero-photo sf-hero-photo-<?=$j?>" title="<?=pfEscape($h['name'])?>"><img src="<?=pfEscape('./public-catalogue.php?image='.(int)$h['id'].($j===0?'':'&size=thumb'))?>" alt="<?=pfEscape($h['name'])?>" <?= $j===0?'fetchpriority="high"':'loading="lazy"'?> decoding="async"><span><?=pfEscape($h['name'])?></span></a><?php endforeach;?>
</div><?php else:?><span class="sf-hero-symbol" aria-hidden="true">☥</span><?php endif;?>
<span class="sf-art-label">A N K H &nbsp; / &nbsp; T H E &nbsp; C O L L E C T I O N</span>
</div></section>
<div class="sf-trust" aria-label="Research and account features"><span><b>01</b> Research profiles</span><span><b>02</b> Product details</span><span><b>03</b> Private account</span><span><b>04</b> Order history &amp; My Fridge</span></div>
<section class="sf-collection" id="collection"><div class="sf-section-title"><div><span class="sf-kicker">THE COLLECTION</span><h2>Explore every detail<span class="sf-gold">.</span></h2>
<p>Browse <?=count($products)?> published technical profiles with your existing ANKH product photography.</p></div><span class="sf-index">ANKH / 001</span></div>
<div class="sf-category-chips" aria-label="Browse by type">
<?php foreach($categories as $c):?><a href="<?=pfEscape(pfUrl($c==='All research'?[]:['category'=>$c]))?>#collection" class="<?=$category===$c?'is-active':''?>" <?=$category===$c?'aria-current="true"':''?>><?=pfEscape($c)?><span><?=count(array_filter($products,static fn($p)=>$c==='All research'||pfType($p['name'])===$c))?></span></a><?php endforeach;?>
</div>
<form method="get" action="./#collection" class="sf-filter sf-filter-v2" role="search" id="sf-search-form">
<div class="sf-search-box"><label for="sf-search">Search the collection</label><input id="sf-search" name="q" type="search" autocomplete="off" maxlength="120" placeholder="Search products or research context…" value="<?=pfEscape($search)?>" aria-describedby="sf-search-help" aria-controls="sf-suggestions"><span class="sf-search-icon" aria-hidden="true">⌕</span>
<div id="sf-suggestions" class="sf-suggestions" hidden role="status" aria-live="polite"></div><small id="sf-search-help">Type a product name or compound to find matching profiles.</small></div>
<div class="sf-select-wrap"><label for="sf-cat">Category</label><select id="sf-cat" name="category"><?php foreach($categories as $c):?><option value="<?=pfEscape($c)?>" <?=$category===$c?'selected':''?>><?=pfEscape($c)?></option><?php endforeach;?></select></div>
<div class="sf-select-wrap"><label for="sf-strength">Strength</label><select id="sf-strength" name="strength"><option value="">All strengths</option><?php foreach($facets['strength'] as $s=>$qty):?><option value="<?=pfEscape($s)?>" <?=$strength===$s?'selected':''?>><?=pfEscape($s)?> (<?=$qty?>)</option><?php endforeach;?></select></div>
<div class="sf-select-wrap"><label for="sf-format">Format</label><select id="sf-format" name="format"><option value="">All formats</option><?php foreach($facets['format'] as $f=>$qty):?><option value="<?=pfEscape($f)?>" <?=$format===$f?'selected':''?>><?=pfEscape($f)?> (<?=$qty?>)</option><?php endforeach;?></select></div>
<div class="sf-select-wrap"><label for="sf-sort">Sort</label><select id="sf-sort" name="sort"><option value="name" <?=$sort==='name'?'selected':''?>>Name A–Z</option><option value="family" <?=$sort==='family'?'selected':''?>>Product family</option><option value="strength" <?=$sort==='strength'?'selected':''?>>Strength</option></select></div>
<button type="submit" class="sf-filter-apply">Show products <span aria-hidden="true">↗</span></button>
</form>
<?php if($search!==''||$strength!==''||$format!==''||$category!=='All research'||$sort!=='name'):?><div class="sf-active-filter"><span>Showing filtered results</span><a href="./#collection">Clear all filters ×</a></div><?php endif;?>
<p class="sf-results"><?=count($filtered)?> matching profile<?=count($filtered)===1?'':'s'?> <span>·</span> Select an image or quick view to discover more</p>
<?php if(!$filtered):?><div class="sf-empty"><h3>No matching profiles.</h3><p>Try a different term, strength or format.</p><a href="./#collection" class="sf-secondary">Clear filters →</a></div><?php else:?>
<div class="sf-grid sf-grid-v2" id="sf-product-grid"><?php foreach($filtered as $p):require __DIR__.'/storefront-card.php';endforeach;?></div><?php endif;?>
</section>
<section class="sf-trust-details" id="sf-trust" aria-labelledby="sf-trust-title">
<div class="sf-section-title"><div><span class="sf-kicker">TRANSPARENT BY DESIGN</span><h2 id="sf-trust-title">Know who you're dealing with<span class="sf-gold">.</span></h2>
<p>Useful, factual business information. No invented reviews, unverifiable certifications or artificial countdowns.</p></div></div>
<div class="sf-trust-columns">
<article><span class="sf-kicker">THE COMPANY</span><h3>ANKH GLOBAL LTD</h3>
<?php if(!empty($businessFacts['company'])):?><p><?=nl2br(pfEscape($businessFacts['company']))?></p><?php else:?><p>Company documentation and registered particulars are available on request.</p><?php endif;?></article>
<article><span class="sf-kicker">FULFILMENT &amp; DELIVERY</span><h3>Clear expectations.</h3>
<?php if(!empty($businessFacts['fulfilment'])):?><p><?=nl2br(pfEscape($businessFacts['fulfilment']))?></p><?php endif;?>
<?php if(!empty($businessFacts['dispatch'])):?><p><?=nl2br(pfEscape($businessFacts['dispatch']))?></p><?php else:?><p>Availability, delivery area, charges and timing are confirmed individually before an order is accepted. No fixed delivery promise is implied.</p><?php endif;?></article>
<article><span class="sf-kicker">HUMAN SUPPORT</span><h3>Speak to ANKH.</h3>
<?php if(!empty($businessFacts['support'])):?><p><?=nl2br(pfEscape($businessFacts['support']))?></p><?php else:?><p>Contact ANKH directly for support availability and existing order queries.</p><?php endif;?>
<a href="mailto:info@ankhpeptides.com">Email ANKH support ↗</a></article>
</div><p class="sf-trust-fineprint">Research pages do not establish medical suitability or product quality. Independent batch documentation will only be linked when available and verified.</p></section>
<section class="sf-editorial sf-editorial-v2" id="sf-process"><div><span class="sf-kicker">THE ANKH EXPERIENCE</span><h2>Every detail.<br><em>Considered.</em></h2>
<p>Your research reference and your personal ANKH account belong in one thoughtfully designed experience.</p><a class="sf-button" href="./?tab=home">Open My Account <span aria-hidden="true">→</span></a></div><div class="sf-editorial-emblem" aria-hidden="true">☥</div></section>
<section class="sf-process" aria-label="How ANKH works">
<div><span class="sf-step-number">01 / DISCOVER</span><h3>Find information fast.</h3><p>Filter research profiles by category, listed strength and supplied format.</p></div>
<div><span class="sf-step-number">02 / UNDERSTAND</span><h3>Explore the context.</h3><p>Open product profiles for material details, literature references and evidence limitations.</p></div>
<div><span class="sf-step-number">03 / MANAGE</span><h3>Your account, connected.</h3><p>Existing ANKH customers can securely view order history, delivery status and My Fridge.</p></div>
</section>
<?php endif;?>
</main>
<div id="sf-approved-items" hidden aria-hidden="true">
<?php foreach($buyable as $item):?><span data-approved-id="<?=(int)$item['id']?>" data-approved-name="<?=pfEscape($item['name'])?>" data-approved-price="<?=(int)$item['price']?>"></span><?php endforeach;?>
</div>
<dialog id="sf-cart-drawer" class="sf-cart-drawer" aria-labelledby="sf-cart-heading">
<div class="sf-cart-head"><div><span class="sf-kicker">YOUR ANKH BASKET</span><h2 id="sf-cart-heading">Your selections<span class="sf-gold">.</span></h2></div><button type="button" data-close-cart class="sf-cart-dismiss" aria-label="Close basket">×</button></div>
<p class="sf-cart-description">Approved non-medicinal accessories only. Your selections are saved on this device. Submission is an order request, not payment.</p>
<div id="sf-cart-lines" class="sf-cart-lines" role="status" aria-live="polite"><p>Nothing in your basket yet.</p></div>
<div class="sf-cart-foot"><div><span>Product subtotal</span><strong id="sf-cart-subtotal">£0.00</strong></div>
<p>Delivery is confirmed by ANKH before an order is accepted. No payment is collected here.</p>
<a class="sf-button sf-cart-checkout" href="./shop-checkout.php" id="sf-cart-checkout">Review &amp; send request ↗</a></div></dialog>
<footer class="sf-footer"><div class="sf-shell sf-footer-grid"><div><div class="sf-footer-logo">☥ ANKH</div><p>ANKH GLOBAL LTD · Technical research information only. Not intended for human or veterinary consumption or administration. No medical approval is claimed.</p></div><div><strong>Discover</strong><a href="./">Collection</a><a href="./public-catalogue.php">Scientific reference</a><a href="mailto:info@ankhpeptides.com">Customer support</a></div><div><strong>Your ANKH</strong><a href="./?tab=home">My Account</a><a href="./?saved=1">Saved research</a><a href="./?tab=basket">Customer basket</a><a href="./?tab=orders">My orders</a><a href="./?tab=fridge">My Fridge</a></div></div><div class="sf-shell sf-copyright">ANKH GLOBAL LTD · PUBLIC RESEARCH REFERENCE · © <?=date('Y')?></div></footer>
<dialog class="sf-quick-dialog" id="sf-quick" aria-labelledby="sf-quick-title"><div class="sf-quick-inner">
<button type="button" class="sf-quick-close" data-close-quick aria-label="Close quick view">×</button><div class="sf-quick-photo"><img id="sf-quick-photo" alt="" hidden><span id="sf-quick-fallback" aria-hidden="true">☥</span></div><div class="sf-quick-text"><span class="sf-kicker" id="sf-quick-kind"></span><h2 id="sf-quick-title"></h2><p id="sf-quick-summary"></p><a id="sf-quick-link" class="sf-button" href="./">Explore profile ↗</a><p class="sf-quick-note">Technical research reference only, not an offer to purchase medicines.</p></div>
</div></dialog>
<?php if(!$selected):?><div class="sf-search-corpus" hidden aria-hidden="true">
<?php foreach(($search===''?$products:$filtered) as $p):?><span data-suggest-name="<?=pfEscape($p['name'])?>" data-suggest-kind="<?=pfEscape(pfType($p['name']))?>" data-suggest-href="<?=pfEscape(pfUrl(['product'=>$p['id']]))?>"></span><?php endforeach;?>
</div>
<?php endif;?>
<nav class="sf-bottom-menu" aria-label="Mobile shortcuts">
<a href="./#collection"><span aria-hidden="true">⌕</span>Explore</a>
<a href="./?saved=1"><span aria-hidden="true">♡</span>Saved</a>
<button type="button" data-open-cart class="sf-mobile-cart"><span aria-hidden="true">▢</span>Basket <b data-cart-count>0</b></button>
<a href="./?tab=home"><span aria-hidden="true">◉</span>My Account</a></nav>
</body></html>
