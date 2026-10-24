<?php
/** ANKH customer website CMS. Included only after the existing admin authentication. */
ankhCatalogueInit($db);
$cmsProducts=$db->query('SELECT id,name,active,price FROM products ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
$cmsVisibility=ankhCatalogueVisibility($db);
$cmsContent=ankhCatalogueContent($db);
$cmsActive=array_values(array_filter($cmsProducts,static fn($p)=>(int)$p['active']===1));
$cmsSelected=(int)($_GET['id']??0);
if(!$cmsSelected && $cmsActive)$cmsSelected=(int)$cmsActive[0]['id'];
$cmsProduct=null;foreach($cmsProducts as $p)if((int)$p['id']===$cmsSelected){$cmsProduct=$p;break;}
$cmsInfo=$cmsContent[$cmsSelected]??[];
$cmsCount=count(array_filter($cmsActive,fn($p)=>(int)($cmsVisibility[(int)$p['id']]??0)===1));
?>
<link rel="stylesheet" href="customer-cms.css?v=2">
<script src="catalogue-admin.js?v=unified-2" defer></script>
<script src="customer-cms.js?v=2" defer></script>
<div class="heading"><div><p class="cms-kicker">ANKH / CONTENT MANAGEMENT</p><h1>Customer website CMS</h1><p class="muted">Edit customer-facing information and choose what appears on your website.</p></div><a class="quick-action" href="?view=products">Order Admin products →</a></div>
<div class="cms-stats"><div><strong><?=count($cmsProducts)?></strong><span>Total products</span></div><div><strong><?=$cmsCount?></strong><span>Visible</span></div><div><strong><?=count($cmsActive)-$cmsCount?></strong><span>Hidden active</span></div></div>
<div class="cms-layout">
<aside class="cms-sidebar">
 <h2>Website manager</h2><a href="#cms-edit" class="cms-side-link">✎ Product content</a><a href="#cms-visibility" class="cms-side-link">◉ Website visibility</a><a href="#cms-guide" class="cms-side-link">ⓘ Connected data</a>
 <label class="cms-search-label" for="cms-search">Find a product</label><input type="search" id="cms-search" placeholder="Search products…" autocomplete="off">
 <div class="cms-products" id="cms-products"><?php foreach($cmsProducts as $p):$id=(int)$p['id'];$on=(int)($cmsVisibility[$id]??0)===1;?>
 <a data-cms-search="<?=e(strtolower((string)$p['name']))?>" class="cms-product <?=$id===$cmsSelected?'selected':''?>" href="?view=website_cms&amp;id=<?=$id?>#cms-edit"><span><?=e($p['name'])?></span><small><?=$on?'● Visible':'○ Hidden'?></small></a>
 <?php endforeach;?></div>
</aside>
<div class="cms-main">
<?php if($cmsProduct):?>
<section class="cms-panel" id="cms-edit">
 <div class="cms-panel-heading"><div><span class="cms-kicker">PRODUCT EDITOR</span><h2><?=e($cmsProduct['name'])?></h2><p>Only website text, research details and imagery can be changed here.</p></div><span class="cms-status"><?=(int)($cmsVisibility[$cmsSelected]??0)===1?'Visible':'Hidden'?></span></div>
 <div class="cms-readonly"><span>Product price <strong>Managed by Order Admin</strong></span><span>Stock and reservations <strong>Managed by Order Admin</strong></span></div>
 <form method="post" enctype="multipart/form-data" class="cms-form">
 <?php csrf();?><input type="hidden" name="action" value="product_catalogue_content"><input type="hidden" name="product_id" value="<?=$cmsSelected?>"><input type="hidden" name="return" value="website_cms"><input type="hidden" name="catalogue_visible" value="<?=(int)($cmsVisibility[$cmsSelected]??0)===1?'1':'0'?>">
 <label for="cms-description">Product description</label><p class="cms-help">Use short paragraphs, headings and factual product information. Existing text is preserved when you open the editor.</p>
 <div class="catalogue-document-editor cms-document-editor">
 <div class="cms-editor-toolbar" role="toolbar" aria-label="Description formatting">
 <button type="button" data-catalogue-insert="heading">Heading</button><button type="button" data-catalogue-insert="bold"><strong>Bold</strong></button><button type="button" data-catalogue-insert="bullet">• List</button><button type="button" data-catalogue-insert="link">↗ Link</button>
 <button type="button" id="cms-preview-toggle" aria-expanded="false" aria-controls="cms-preview">Preview</button></div>
 <textarea data-catalogue-document id="cms-description" name="catalogue_description" rows="12" maxlength="40000"><?=e((string)($cmsInfo['description']??''))?></textarea>
 <div class="cms-editor-bottom"><span>Use headings and paragraphs like in a document editor.</span><span data-catalogue-count></span></div>
 <div id="cms-preview" class="cms-preview" hidden aria-live="polite"></div>
 </div>
 <h3>Technical and research information</h3><p class="cms-help">Only enter independently verifiable information. Empty fields are omitted from the customer website.</p>
 <div class="cms-fields"><?php foreach(ankhCatalogueInformationFields() as $key=>$label):$val=(string)($cmsInfo['information'][$key]??'');?>
 <label><?=e($label)?><?php if(in_array($key,ankhCatalogueLongFields(),true)):?><textarea name="catalogue_information[<?=e($key)?>]" rows="4"><?=e($val)?></textarea><?php else:?><input name="catalogue_information[<?=e($key)?>]" value="<?=e($val)?>" maxlength="600"><?php endif;?></label>
 <?php endforeach;?></div>
 <h3>Product image</h3><?php if((int)($cmsInfo['image_bytes']??0)>0):?><img class="cms-photo" src="?api=catalogue-image&amp;id=<?=$cmsSelected?>" alt="Current product photo"><?php endif;?>
 <label>Replace product image<input type="file" name="catalogue_photo" data-catalogue-photo accept="image/jpeg,image/png,image/webp"></label>
 <p class="cms-help" data-photo-status>No new photo selected.</p><img hidden class="cms-photo" data-photo-preview alt="Photo upload preview"><label class="cms-check"><input type="checkbox" name="remove_catalogue_photo" value="1"> Remove existing image</label>
 <div class="cms-actions"><button type="submit">Save website content</button><a href="?view=product&amp;id=<?=$cmsSelected?>">Open operational product record ↗</a></div>
 </form>
</section>
<?php endif;?>
<section class="cms-panel" id="cms-visibility"><div class="cms-panel-heading"><div><span class="cms-kicker">PUBLISHING</span><h2>Website visibility</h2><p>Tick products that should be shown. This never changes their prices, stock or historical orders.</p></div></div>
<form method="post"><?php csrf();?><input type="hidden" name="action" value="product_catalogue_visibility_bulk"><input type="hidden" name="return" value="website_cms"><input type="hidden" name="visibility_confirmation" value="yes"><div class="cms-visibility-grid">
<?php foreach($cmsActive as $p):$id=(int)$p['id'];?><label class="cms-visibility-item"><input type="checkbox" name="catalogue_ids[]" value="<?=$id?>" <?=(int)($cmsVisibility[$id]??0)===1?'checked':''?>><span><?=e($p['name'])?></span></label><?php endforeach;?></div>
<div class="cms-actions"><button type="submit">Save visibility settings</button><button type="button" class="cms-secondary" id="cms-all">Select all</button><button type="button" class="cms-secondary" id="cms-none">Clear all</button></div>
</form></section>
<section class="cms-panel" id="cms-guide"><h2>How this works</h2><p>The Order Admin product record is the single source of truth for name, price and stock. This CMS stores descriptions, technical details, photos and website visibility against the same product ID. Existing orders and inventory are not modified.</p><p class="cms-help">Visibility controls catalogue display; separate compliance or public-review approval may still be required before a product can be offered for purchase.</p></section>
</div></div>
<script>(function(){const q=document.getElementById('cms-search');q?.addEventListener('input',()=>document.querySelectorAll('[data-cms-search]').forEach(x=>x.hidden=!x.dataset.cmsSearch.includes(q.value.toLowerCase().trim())));const boxes=[...document.querySelectorAll('#cms-visibility input[name="catalogue_ids[]"]')];document.getElementById('cms-all')?.addEventListener('click',()=>boxes.forEach(x=>x.checked=true));document.getElementById('cms-none')?.addEventListener('click',()=>boxes.forEach(x=>x.checked=false));})();</script>
