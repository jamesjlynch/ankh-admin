<?php
/** One consistent product tile across collection, saved and related views. */
if(!isset($p) || !is_array($p)){http_response_code(404);exit;}
$name=(string)$p['name'];$id=(int)$p['id'];
$summary=pfExcerpt((string)$p['description'],115);
$kind=pfCategory($p);$strength=pfStrength($p);$format=pfFormat($p);
$photo=!empty($p['has_photo'])?ankhCmsPhotoUrl($p,'thumb'):'';
$url=pfUrl(['product'=>$id]);
$hasDemo=isset($demoProducts[$id]);
$canRequest=isset($buyable[$id]);
$inBasket=$hasDemo||$canRequest;
$previewPrice=$previewPrices[$id]??null;
?>
<article class="sf-card sf-card-v3 sf-card-v2" data-profile-id="<?=$id?>"
  data-filter-name="<?=pfEscape($name)?>" data-filter-kind="<?=pfEscape($kind)?>"
  data-filter-strength="<?=pfEscape($strength)?>" data-filter-format="<?=pfEscape($format)?>"
  data-filter-text="<?=pfEscape(trim($name.' '.$kind.' '.$strength.' '.$format.' '.$summary))?>">
 <div class="sf-card-visual">
  <a class="sf-card-image" href="<?=pfEscape($url)?>" aria-label="View <?=pfEscape($name)?>">
   <span class="sf-card-topline">ANKH / <?=str_pad((string)$id,3,'0',STR_PAD_LEFT)?></span>
   <?php if($photo!==''):?><img src="<?=pfEscape($photo)?>" loading="lazy" decoding="async" width="420" height="420" alt="<?=pfEscape($name.' product photograph')?>"><?php else:?><span class="sf-card-placeholder" aria-hidden="true">☥</span><?php endif;?>
   <span class="sf-card-view" aria-hidden="true">Discover ↗</span>
  </a>
  <span class="sf-card-photo-halo" aria-hidden="true"></span>
 </div>
 <div class="sf-card-copy">
   <div class="sf-card-eyebrow"><span><?=pfEscape($kind)?></span><small>REF. <?=str_pad((string)$id,3,'0',STR_PAD_LEFT)?></small></div>
   <h3><a href="<?=pfEscape($url)?>"><?=pfEscape($name)?></a></h3>
   <?php if($summary!==''):?><p><?=pfEscape($summary)?></p><?php endif;?>
   <div class="sf-card-meta"><?php if($strength!==''):?><span><?=pfEscape($strength)?></span><?php endif;?><?php if($format!=='Other'):?><span><?=pfEscape($format)?></span><?php endif;?></div>
   <?php if($previewPrice!==null):?>
    <div class="sf-card-price"><span><?=$canRequest?'PRICE':'RETAIL PREVIEW'?></span><strong>£<?=number_format((int)$previewPrice/100,2)?></strong></div>
   <?php endif;?>
   <?php if($inBasket):?>
    <div class="sf-shop-line <?=$canRequest?'sf-request-line':'sf-demo-line'?>">
     <span class="sf-demo-label"><?=$canRequest?'ACCESSORY REQUEST · STAFF REVIEW':'RESEARCH DEMO · NO ORDER'?></span>
     <button type="button" class="sf-add-cart" data-cart-id="<?=$id?>" aria-label="<?=pfEscape(($canRequest?'Add to accessory request: ':'Preview in demo basket: ').$name)?>"><?=$canRequest?'Add to request':'Try demo basket'?> <span aria-hidden="true">＋</span></button>
    </div>
   <?php else:?><p class="sf-reference-state">Reference only · No basket or order request</p><?php endif;?>
   <div class="sf-card-bottom" role="group" aria-label="<?=pfEscape($name)?> quick actions">
     <button type="button" class="sf-save-action sf-save-mini" data-save-id="<?=$id?>" aria-pressed="false" aria-label="Save <?=pfEscape($name)?> to research shortlist">♡ Save</button>
     <button type="button" class="sf-card-quick" data-quick-name="<?=pfEscape($name)?>" data-quick-kind="<?=pfEscape($kind)?>" data-quick-summary="<?=pfEscape($summary)?>" data-quick-href="<?=pfEscape($url)?>" data-quick-cart-id="<?=$inBasket?$id:0?>" data-quick-mode="<?=$canRequest?'request':'demo'?>" data-quick-photo="<?=pfEscape($photo)?>" aria-label="Quick view <?=pfEscape($name)?>">Quick view <span aria-hidden="true">↗</span></button>
   </div>
 </div>
</article>
