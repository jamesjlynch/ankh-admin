<?php
declare(strict_types=1);
if(!defined('ANKH_PORTAL_VIEW') || empty($customer) || !empty($isDemo)){http_response_code(404);exit;}
$catalogueError=false;
try{$catalogueProducts=ankhCatalogueProducts($admin,(int)$customer['id']);}
catch(Throwable $e){error_log('ANKH customer catalogue: '.$e->getMessage());$catalogueProducts=[];$catalogueError=true;}
$catalogueSearch=is_string($_GET['search']??null)?substr(trim($_GET['search']),0,80):'';
$catalogueCategory=is_string($_GET['category']??null)?$_GET['category']:'all';
if(!in_array($catalogueCategory,['all','Research materials','Storage','Stationery'],true))$catalogueCategory='all';
$catalogueSort=is_string($_GET['sort']??null)?$_GET['sort']:'name';
if(!in_array($catalogueSort,['name','price-low','price-high'],true))$catalogueSort='name';
$catalogueVisible=array_values(array_filter($catalogueProducts,static fn(array $p):bool=>($catalogueCategory==='all'||$p['category']===$catalogueCategory)&&($catalogueSearch===''||stripos($p['name'].' '.$p['description'],$catalogueSearch)!==false)));
if($catalogueSort!=='name')usort($catalogueVisible,static fn(array $a,array $b):int=>($catalogueSort==='price-low'?($a['price']<=>$b['price']):($b['price']<=>$a['price']))?:strcasecmp($a['name'],$b['name']));
$catalogueSelected=null;
$catalogueProductId=filter_var($_GET['product']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
foreach($catalogueProducts as $candidate)if($candidate['id']===$catalogueProductId){$catalogueSelected=$candidate;break;}
$catalogueParams=['search'=>$catalogueSearch,'category'=>$catalogueCategory,'sort'=>$catalogueSort];
function pcsEnquiry(array $p):string {
    return 'mailto:info@ankhpeptides.com?'.http_build_query(['subject'=>'Product enquiry · '.$p['name'],'body'=>"Hello ANKH,\n\nPlease send me further information and confirm availability for:\n\n".$p['name']."\nProduct reference: #".$p['id']."\nDisplayed account price: ".portalMoney($p['price'])."\n\nMy enquiry:\n"],'','&',PHP_QUERY_RFC3986);
}
function pcsStock(array $p):string {return $p['available_qty']===null?'Availability on enquiry':($p['available_qty']>0?'Available · '.$p['available_qty']:'Currently unavailable');}
function pcsExcerpt(string $text):string {
    $text=ankhCatalogueSummary($text);
    preg_match('/^.{0,110}/us',$text,$match);$short=$match[0]??'';
    return $short.($short!==$text?'…':'');
}
?>
<div class="pcs-store <?=$catalogueSelected?'pcs-product-page':'pcs-collection'?>">
<?php if(!$catalogueSelected):?>
<header class="pcs-hero"><div class="pcs-hero-copy"><span class="pc-eyebrow"><span class="pcs-gold-line" aria-hidden="true"></span> THE ANKH COLLECTION</span><h1>Research.<br><em>Refined.</em></h1><p>Explore the collection. Find the details.<br>Enquire directly with ANKH.</p><div class="pcs-hero-meta"><span><?=count($catalogueProducts)?> published product<?=count($catalogueProducts)===1?'':'s'?></span><span>Your account prices</span></div></div><div class="pcs-hero-art" aria-hidden="true"><span class="pcs-hero-orbit"></span><span class="pcs-hero-mark">☥</span><span class="pcs-art-caption">ANKH · RESEARCH COLLECTION</span></div></header>
<?php else:?><a class="pc-back" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,$catalogueParams))?>">← Back to the collection</a><?php endif;?>
<details class="pcs-policy"><summary><?=pcIcon('shield')?><span>Laboratory research only · 18+ <small>Not for human or animal consumption</small></span><span aria-hidden="true">⌄</span></summary><p>For laboratory research, in-vitro testing and academic evaluation only. Not intended to diagnose, treat, cure or prevent disease.</p></details>
<?php if($staff):?><details class="pcs-staff-note"><summary>Staff preview · Connected to Admin</summary><p>Photos and descriptions are edited under Products → Customer catalogue. Only active products ticked “Visible on customer's dashboard” are shown. Enquiry buttons open the customer's email app; they do not place an order.</p></details><?php endif;?>
<?php if($catalogueError):?><div class="pc-panel pc-empty" role="alert"><h2>Catalogue temporarily unavailable</h2><p>Please try again later or contact ANKH.</p></div>
<?php elseif($catalogueProductId && !$catalogueSelected):?><div class="pc-panel pc-empty"><h2>Product not available</h2><p>This product is no longer published in the customer catalogue.</p><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">View the collection</a></div>
<?php elseif($catalogueSelected):$p=$catalogueSelected;$info=$p['information'];$document=ankhCatalogueEditorText($p['description'],$info);$documentSections=ankhCatalogueSections($document);$moreProducts=array_values(array_filter($catalogueProducts,static fn(array $item):bool=>$item['id']!==$p['id']));usort($moreProducts,static fn(array $a,array $b):int=>(($b['category']===$p['category'])<=>($a['category']===$p['category']))?:strcasecmp($a['name'],$b['name']));$moreProducts=array_slice($moreProducts,0,3);?>
<nav class="pcs-breadcrumb" aria-label="Breadcrumb"><a href="<?=portalEscape(pcHref('home',$staff,$customerId))?>">Home</a><span>/</span><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Catalogue</a><span>/</span><span><?=portalEscape($p['name'])?></span></nav>
<article class="pcs-detail">
 <div class="pcs-detail-photo pcs-photo">
 <?php if($p['has_photo']):?><img src="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['catalogue_image'=>$p['id']]))?>" alt="<?=portalEscape($p['name'])?>" width="800" height="900"><?php else:?><div class="pcs-placeholder"><span aria-hidden="true">☥</span><small>ANKH / THE COLLECTION</small><span class="pcs-photo-soon">Photograph coming soon</span></div><?php endif;?>
 </div>
 <div class="pcs-detail-copy"><span class="pc-eyebrow"><?=portalEscape($p['category'])?></span><h1><?=portalEscape($p['name'])?></h1><span class="pcs-availability <?=$p['available_qty']===0?'pcs-unavailable':''?>"><span aria-hidden="true"></span><?=portalEscape(pcsStock($p))?></span>
 <div class="pcs-detail-price"><strong><?=portalEscape(portalMoney($p['price']))?></strong><?php if($p['pricing']==='family_friends' && $p['price']<$p['retail_price']):?><del><?=portalEscape(portalMoney($p['retail_price']))?></del><?php endif;?></div><p class="pcs-price-caption"><?=$p['pricing']==='family_friends'?'Your Family & Friends price':($p['pricing']==='free'?'Your account price':'Your product price')?></p>
 <div class="pcs-intro"><p><?=portalEscape($p['description']!==''?pcsExcerpt($p['description']):'Contact the ANKH team for further product information and documentation.')?></p><a href="#pcs-product-information">Read product information ↓</a></div>
 <a class="pcs-enquire" href="<?=portalEscape(pcsEnquiry($p))?>"><?=pcIcon('mail')?> Enquire about this product <?=pcIcon('right')?></a><p class="pcs-enquiry-note">Opens your email app with the product reference and displayed price. No order is placed and no payment is taken.</p>
 <dl class="pcs-facts"><div><dt>Product reference</dt><dd>#<?=$p['id']?></dd></div><div><dt>Intended use</dt><dd>Laboratory research only · 18+</dd></div><div><dt>Supply enquiries</dt><dd>Contact the ANKH team</dd></div></dl>
 </div>
</article>
<section class="pcs-product-information" id="pcs-product-information" aria-labelledby="pcs-information-title">
 <header><span class="pc-eyebrow">THE PRODUCT, IN DETAIL</span><h2 id="pcs-information-title">A closer look<span class="pc-dot">.</span></h2><p>Product details, research information and documentation.</p></header>
 <?php if(count($documentSections)>1):?><nav class="pcs-section-nav" aria-label="Product information sections"><?php foreach($documentSections as $sectionIndex=>$section):?><a href="#pcs-section-<?=$sectionIndex?>"><?=portalEscape($section['title'])?></a><?php endforeach;?></nav><?php endif;?>
 <div class="pcs-reading-layout"><div class="pcs-reading-body">
 <?php if(!$documentSections):?><section class="pcs-reading-section"><h3>Product information</h3><p>Ask ANKH for further product information and documentation.</p></section><?php endif;?>
 <?php foreach($documentSections as $sectionIndex=>$section):?><section class="pcs-reading-section" id="pcs-section-<?=$sectionIndex?>"><h3><?=portalEscape($section['title'])?></h3><div class="pcs-prose"><?=ankhCatalogueFormat($section['text'])?></div></section><?php endforeach;?>
 </div><aside class="pcs-support-card"><span class="pc-eyebrow">HERE TO HELP</span><h3>Need a little more detail?</h3><p>Speak to ANKH about this product, availability or supporting documentation.</p><a class="pcs-enquire" href="<?=portalEscape(pcsEnquiry($p))?>"><?=pcIcon('mail')?> Ask about this product</a><div class="pcs-research-note"><?=pcIcon('shield')?><span>Laboratory research only. 18+.<small>Not for human or animal consumption, diagnosis or treatment.</small></span></div></aside></div>
</section>
<?php if($moreProducts):?><section class="pcs-more-products" aria-labelledby="pcs-more-title"><header><div><span class="pc-eyebrow">KEEP EXPLORING</span><h2 id="pcs-more-title">More from ANKH<span class="pc-dot">.</span></h2></div><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">View catalogue →</a></header><div class="pcs-related-grid"><?php foreach($moreProducts as $other):?><a class="pcs-related-card" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['product'=>$other['id']]))?>"><div class="pcs-photo"><?php if($other['has_photo']):?><img src="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['catalogue_image'=>$other['id']]))?>" alt="<?=portalEscape($other['name'])?>" width="360" height="360" loading="lazy"><?php else:?><span class="pcs-related-symbol" aria-hidden="true">☥</span><?php endif;?></div><div><h3><?=portalEscape($other['name'])?></h3><span><?=portalEscape(portalMoney($other['price']))?></span><small>View product →</small></div></a><?php endforeach;?></div></section><?php endif;?>
<div class="pcs-sticky-enquiry"><div><small><?=portalEscape($p['name'])?></small><strong><?=portalEscape(portalMoney($p['price']))?></strong></div><a class="pcs-enquire" href="<?=portalEscape(pcsEnquiry($p))?>"><?=pcIcon('mail')?> Enquire</a></div>
<?php else:?>
<form class="pcs-toolbar" method="get" action="<?=$staff?'./preview-live.php':'./'?>">
 <?php if($staff):?><input type="hidden" name="customer_id" value="<?=$customerId?>"><?php endif;?><input type="hidden" name="tab" value="catalogue">
 <label class="pcs-search" for="live-catalogue-search"><?=pcIcon('search')?><input type="search" id="live-catalogue-search" name="search" maxlength="80" value="<?=portalEscape($catalogueSearch)?>" placeholder="Find a product" aria-label="Search products"></label>
 <input type="hidden" name="category" value="<?=portalEscape($catalogueCategory)?>">
 <select name="sort" aria-label="Sort products"><?php foreach(['name'=>'Name: A–Z','price-low'=>'Price: low to high','price-high'=>'Price: high to low'] as $key=>$label):?><option value="<?=$key?>" <?=$catalogueSort===$key?'selected':''?>><?=$label?></option><?php endforeach;?></select><button type="submit">Search <?=pcIcon('right')?></button>
</form>
<?php $categoryCounts=array_count_values(array_column($catalogueProducts,'category'));?><div class="pcs-browse-row"><nav class="pcs-category-tabs" aria-label="Filter products by category"><?php foreach(['all'=>'All products','Research materials'=>'Research materials','Storage'=>'Storage','Stationery'=>'Stationery'] as $key=>$label):if($key!=='all' && !isset($categoryCounts[$key]) && $catalogueCategory!==$key)continue;?><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['search'=>$catalogueSearch,'sort'=>$catalogueSort,'category'=>$key]))?>" class="<?=$catalogueCategory===$key?'selected':''?>" <?=$catalogueCategory===$key?'aria-current="true"':''?>><?=portalEscape($label)?><span><?=$key==='all'?count($catalogueProducts):($categoryCounts[$key]??0)?></span></a><?php endforeach;?></nav></div>
<div class="pcs-results"><span><?=count($catalogueVisible)?> product<?=count($catalogueVisible)===1?'':'s'?><?=($catalogueSearch!==''||$catalogueCategory!=='all')?' matching your filters':' in the collection'?></span><?php if(($catalogueProducts[0]['pricing']??'')==='family_friends'):?><span class="pcs-account-badge"><?=pcIcon('check')?> Family &amp; Friends pricing applied</span><?php endif;?></div>
<?php if(!$catalogueVisible):?><div class="pc-panel pc-empty"><h2><?=$catalogueSearch!==''||$catalogueCategory!=='all'?'No matching products':'No products published yet'?></h2><p><?=$catalogueSearch!==''||$catalogueCategory!=='all'?'Try a different search or category.':'Products will appear here when ANKH publishes them.'?></p><?php if($catalogueSearch!==''||$catalogueCategory!=='all'):?><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Clear filters</a><?php endif;?></div>
<?php else:?><div class="pcs-grid">
<?php foreach($catalogueVisible as $p):$productHref=pcHref('catalogue',$staff,$customerId,$catalogueParams+['product'=>$p['id']]);?>
 <article class="pcs-card"><a class="pcs-photo" href="<?=portalEscape($productHref)?>" aria-label="View <?=portalEscape($p['name'])?>">
 <?php if($p['has_photo']):?><img src="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['catalogue_image'=>$p['id']]))?>" alt="<?=portalEscape($p['name'])?>" width="500" height="580" loading="lazy" decoding="async"><?php else:?><div class="pcs-placeholder"><span aria-hidden="true">☥</span><small>ANKH / RESEARCH</small><span class="pcs-photo-soon">Photograph coming soon</span></div><?php endif;?>
 <?php if($p['pricing']==='family_friends'):?><span class="pcs-photo-badge">Family &amp; Friends</span><?php endif;?></a>
 <div class="pcs-card-copy"><span class="pc-eyebrow"><?=portalEscape($p['category'])?></span><h2><a href="<?=portalEscape($productHref)?>"><?=portalEscape($p['name'])?></a></h2><span class="pcs-availability <?=$p['available_qty']===0?'pcs-unavailable':''?>"><span aria-hidden="true"></span><?=portalEscape(pcsStock($p))?></span>
 <?php if($p['description']!==''):?><p class="pcs-card-description"><?=portalEscape(pcsExcerpt($p['description']))?></p><?php endif;?>
 <div class="pcs-card-price"><strong><?=portalEscape(portalMoney($p['price']))?></strong><?php if($p['pricing']==='family_friends' && $p['price']<$p['retail_price']):?><del><?=portalEscape(portalMoney($p['retail_price']))?></del><?php endif;?></div><small class="pcs-price-caption"><?=$p['pricing']==='family_friends'?'Your Family & Friends price':($p['pricing']==='free'?'Your account price':'Retail price')?></small>
 <div class="pcs-card-actions"><a class="pcs-card-view" href="<?=portalEscape($productHref)?>">View product <?=pcIcon('right')?></a><a class="pcs-card-enquiry" href="<?=portalEscape(pcsEnquiry($p))?>" aria-label="Enquire about <?=portalEscape($p['name'])?>" title="Enquire about this product"><?=pcIcon('mail')?></a></div>
 </div></article>
<?php endforeach;?></div><p class="pcs-enquiry-note pcs-collection-note">Explore a product for full specifications and research information. Use the envelope to enquire directly with ANKH.</p><?php endif;?>
<?php endif;?>
</div>
