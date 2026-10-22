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
    $text=trim(preg_replace('/\s+/u',' ',$text)??'');
    preg_match('/^.{0,110}/us',$text,$match);$short=$match[0]??'';
    return $short.($short!==$text?'…':'');
}
?>
<div class="pcs-store">
<?php if(!$catalogueSelected):?>
<header class="pcs-hero"><div><span class="pc-eyebrow">ANKH / THE COLLECTION</span><h1>Explore the catalogue<span class="pc-dot">.</span></h1><p>Product details, current availability and your account prices, all in one place.</p></div><span class="pcs-hero-mark" aria-hidden="true">☥</span></header>
<?php else:?><a class="pc-back" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,$catalogueParams))?>">← Back to the collection</a><?php endif;?>
<details class="pcs-policy"><summary><?=pcIcon('shield')?><span>Laboratory research only · 18+ <small>Not for human or animal consumption</small></span><span aria-hidden="true">⌄</span></summary><p>For laboratory research, in-vitro testing and academic evaluation only. Not intended to diagnose, treat, cure or prevent disease.</p></details>
<?php if($staff):?><details class="pcs-staff-note"><summary>Staff preview · Connected to Admin</summary><p>Photos and descriptions are edited under Products → Customer catalogue. Only active products ticked “Visible on customer's dashboard” are shown. Enquiry buttons open the customer's email app; they do not place an order.</p></details><?php endif;?>
<?php if($catalogueError):?><div class="pc-panel pc-empty" role="alert"><h2>Catalogue temporarily unavailable</h2><p>Please try again later or contact ANKH.</p></div>
<?php elseif($catalogueProductId && !$catalogueSelected):?><div class="pc-panel pc-empty"><h2>Product not available</h2><p>This product is no longer published in the customer catalogue.</p><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">View the collection</a></div>
<?php elseif($catalogueSelected):$p=$catalogueSelected;$info=$p['information'];$specLabels=['strength'=>'Strength / quantity','concentration'=>'Concentration','presentation'=>'Presentation','appearance'=>'Appearance','purity'=>'Purity','cas'=>'CAS number','formula'=>'Molecular formula','weight'=>'Molecular weight','sequence'=>'Sequence','storage'=>'Storage'];$specs=array_intersect_key($info,$specLabels);?>
<nav class="pcs-breadcrumb" aria-label="Breadcrumb"><a href="<?=portalEscape(pcHref('home',$staff,$customerId))?>">Home</a><span>/</span><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Catalogue</a><span>/</span><span><?=portalEscape($p['name'])?></span></nav>
<article class="pcs-detail">
 <div class="pcs-detail-photo pcs-photo">
 <?php if($p['has_photo']):?><img src="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['catalogue_image'=>$p['id']]))?>" alt="<?=portalEscape($p['name'])?>" width="800" height="900"><?php else:?><div class="pcs-placeholder"><span aria-hidden="true">☥</span><small>ANKH / PRODUCT PHOTOGRAPH</small><span class="pcs-photo-soon">Photograph coming soon</span></div><?php endif;?>
 </div>
 <div class="pcs-detail-copy"><span class="pc-eyebrow"><?=portalEscape($p['category'])?></span><h1><?=portalEscape($p['name'])?></h1><span class="pcs-availability <?=$p['available_qty']===0?'pcs-unavailable':''?>"><span aria-hidden="true"></span><?=portalEscape(pcsStock($p))?></span>
 <div class="pcs-detail-price"><strong><?=portalEscape(portalMoney($p['price']))?></strong><?php if($p['pricing']==='family_friends' && $p['price']<$p['retail_price']):?><del><?=portalEscape(portalMoney($p['retail_price']))?></del><?php endif;?></div><p class="pcs-price-caption"><?=$p['pricing']==='family_friends'?'Your Family & Friends price':($p['pricing']==='free'?'Your account price':'Your product price')?></p>
 <div class="pcs-intro"><p><?=portalEscape($p['description']!==''?pcsExcerpt($p['description']):'Contact the ANKH team for further product information and documentation.')?></p><a href="#pcs-product-information">Read product information ↓</a></div>
 <?php $quick=array_intersect_key($info,array_flip(['strength','concentration','purity','presentation']));if($quick):?><dl class="pcs-quick-specs"><?php foreach($quick as $key=>$value):?><div><dt><?=portalEscape($specLabels[$key])?></dt><dd><?=portalEscape($value)?></dd></div><?php endforeach;?></dl><?php endif;?>
 <a class="pcs-enquire" href="<?=portalEscape(pcsEnquiry($p))?>"><?=pcIcon('mail')?> Enquire about this product <?=pcIcon('right')?></a><p class="pcs-enquiry-note">Opens your email app with the product reference and displayed price. No order is placed and no payment is taken.</p>
 <dl class="pcs-facts"><div><dt>Product reference</dt><dd>#<?=$p['id']?></dd></div><div><dt>Intended use</dt><dd>Laboratory research only · 18+</dd></div><div><dt>Supply enquiries</dt><dd>Contact the ANKH team</dd></div></dl>
 </div>
</article>
<section class="pcs-product-information" id="pcs-product-information" aria-labelledby="pcs-information-title">
 <header><span class="pc-eyebrow">THE PRODUCT, IN DETAIL</span><h2 id="pcs-information-title">Research &amp; specifications<span class="pc-dot">.</span></h2><p>Explore the product information and supporting documentation.</p></header>
 <div class="pcs-information-grid"><div class="pcs-accordions">
 <details open class="pcs-accordion"><summary>Product overview <span aria-hidden="true">+</span></summary><div><?php if($p['description']!==''):?><p><?=nl2br(portalEscape($p['description']))?></p><?php else:?><p>Contact ANKH for detailed product information.</p><?php endif;?></div></details>
 <?php if($specs):?><details class="pcs-accordion" open><summary>Specifications <span aria-hidden="true">+</span></summary><div><dl class="pcs-spec-table"><?php foreach($specs as $key=>$value):?><div><dt><?=portalEscape($specLabels[$key])?></dt><dd><?=nl2br(portalEscape($value))?></dd></div><?php endforeach;?></dl></div></details><?php endif;?>
 <?php foreach(['research'=>'Research overview','applications'=>'Applications in research','precautions'=>'Precautions & handling','faq'=>'Frequently asked questions'] as $key=>$label):if(empty($info[$key]))continue;?><details class="pcs-accordion"><summary><?=portalEscape($label)?> <span aria-hidden="true">+</span></summary><div><p><?=nl2br(portalEscape($info[$key]))?></p></div></details><?php endforeach;?>
 <details class="pcs-accordion"><summary>Research use &amp; supply enquiries <span aria-hidden="true">+</span></summary><div><p>For laboratory research, in-vitro testing and academic evaluation only. Not for human or animal consumption. Not intended to diagnose, treat, cure or prevent disease. Available to customers aged 18 or over.</p><p>For product documentation, storage information or supply questions, please contact ANKH before proceeding.</p></div></details>
 </div><aside class="pcs-document-card"><span class="pc-eyebrow">PRODUCT SUPPORT</span><?=pcIcon('shield')?><h3>Know your research material.</h3><p>Ask our team about specifications, documentation and availability for this product.</p><?php if(!empty($info['coa_url'])):?><a class="pcs-document-link" href="<?=portalEscape($info['coa_url'])?>" target="_blank" rel="noopener noreferrer">View analysis document ↗</a><?php endif;?><a class="pcs-detail-link" href="<?=portalEscape(pcsEnquiry($p))?>">Ask about <?=portalEscape($p['name'])?> →</a></aside></div>
</section>
<div class="pcs-sticky-enquiry"><div><small><?=portalEscape($p['name'])?></small><strong><?=portalEscape(portalMoney($p['price']))?></strong></div><a class="pcs-enquire" href="<?=portalEscape(pcsEnquiry($p))?>"><?=pcIcon('mail')?> Enquire</a></div>
<?php else:?>
<form class="pcs-toolbar" method="get" action="<?=$staff?'./preview-live.php':'./'?>">
 <?php if($staff):?><input type="hidden" name="customer_id" value="<?=$customerId?>"><?php endif;?><input type="hidden" name="tab" value="catalogue">
 <label class="pcs-search" for="live-catalogue-search"><?=pcIcon('search')?><input type="search" id="live-catalogue-search" name="search" maxlength="80" value="<?=portalEscape($catalogueSearch)?>" placeholder="Find a product" aria-label="Search products"></label>
 <select name="category" aria-label="Product category"><?php foreach(['all'=>'All categories','Research materials'=>'Research materials','Storage'=>'Storage','Stationery'=>'Stationery'] as $key=>$label):?><option value="<?=$key?>" <?=$catalogueCategory===$key?'selected':''?>><?=$label?></option><?php endforeach;?></select>
 <select name="sort" aria-label="Sort products"><?php foreach(['name'=>'Name: A–Z','price-low'=>'Price: low to high','price-high'=>'Price: high to low'] as $key=>$label):?><option value="<?=$key?>" <?=$catalogueSort===$key?'selected':''?>><?=$label?></option><?php endforeach;?></select><button type="submit">Apply</button>
</form>
<div class="pcs-results"><span><?=count($catalogueVisible)?> product<?=count($catalogueVisible)===1?'':'s'?></span><?php if(($catalogueProducts[0]['pricing']??'')==='family_friends'):?><span class="pcs-account-badge"><?=pcIcon('check')?> Family &amp; Friends pricing applied</span><?php endif;?></div>
<?php if(!$catalogueVisible):?><div class="pc-panel pc-empty"><h2><?=$catalogueSearch!==''||$catalogueCategory!=='all'?'No matching products':'No products published yet'?></h2><p><?=$catalogueSearch!==''||$catalogueCategory!=='all'?'Try a different search or category.':'Products will appear here when ANKH publishes them.'?></p><?php if($catalogueSearch!==''||$catalogueCategory!=='all'):?><a href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>">Clear filters</a><?php endif;?></div>
<?php else:?><div class="pcs-grid">
<?php foreach($catalogueVisible as $p):$productHref=pcHref('catalogue',$staff,$customerId,$catalogueParams+['product'=>$p['id']]);?>
 <article class="pcs-card"><a class="pcs-photo" href="<?=portalEscape($productHref)?>" aria-label="View <?=portalEscape($p['name'])?>">
 <?php if($p['has_photo']):?><img src="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['catalogue_image'=>$p['id']]))?>" alt="<?=portalEscape($p['name'])?>" width="500" height="580" loading="lazy" decoding="async"><?php else:?><div class="pcs-placeholder"><span aria-hidden="true">☥</span><small>ANKH / RESEARCH</small><span class="pcs-photo-soon">Photograph coming soon</span></div><?php endif;?>
 <?php if($p['pricing']==='family_friends'):?><span class="pcs-photo-badge">Family &amp; Friends</span><?php endif;?></a>
 <div class="pcs-card-copy"><span class="pc-eyebrow"><?=portalEscape($p['category'])?></span><h2><a href="<?=portalEscape($productHref)?>"><?=portalEscape($p['name'])?></a></h2><span class="pcs-availability <?=$p['available_qty']===0?'pcs-unavailable':''?>"><span aria-hidden="true"></span><?=portalEscape(pcsStock($p))?></span>
 <?php if($p['description']!==''):?><p class="pcs-card-description"><?=portalEscape(pcsExcerpt($p['description']))?></p><?php endif;?>
 <div class="pcs-card-price"><strong><?=portalEscape(portalMoney($p['price']))?></strong><?php if($p['pricing']==='family_friends' && $p['price']<$p['retail_price']):?><del><?=portalEscape(portalMoney($p['retail_price']))?></del><?php endif;?></div><small class="pcs-price-caption"><?=$p['pricing']==='family_friends'?'Your Family & Friends price':($p['pricing']==='free'?'Your account price':'Retail price')?></small>
 <a class="pcs-enquire" href="<?=portalEscape(pcsEnquiry($p))?>"><?=pcIcon('mail')?> <span>Enquire about this product</span></a><a class="pcs-detail-link" href="<?=portalEscape($productHref)?>">View details <?=pcIcon('right')?></a>
 </div></article>
<?php endforeach;?></div><p class="pcs-enquiry-note pcs-collection-note">Select a product to read the full description. Enquiries open your email app; no order is placed or payment taken.</p><?php endif;?>
<?php endif;?>
</div>
