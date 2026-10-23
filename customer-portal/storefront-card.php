<?php
if(!isset($p) || !is_array($p)) {http_response_code(404);exit;}
$name=(string)$p['name'];$id=(int)$p['id'];
$summary=pfExcerpt((string)$p['description'],165);
$kind=pfType($name);
$photo=!empty($p['has_photo'])?'./public-catalogue.php?image='.$id:'';
$url=pfUrl(['product'=>$id]);
?><article class="sf-card sf-card-v2">
<a class="sf-card-image" href="<?=pfEscape($url)?>" aria-label="View <?=pfEscape($name)?>">
<span class="sf-card-topline">ANKH / REF. <?=str_pad((string)$id,3,'0',STR_PAD_LEFT)?></span>
<?php if($photo!==''):?><img src="<?=pfEscape($photo)?>" loading="lazy" decoding="async" width="320" height="320" alt=""><?php else:?><span class="sf-card-placeholder" aria-hidden="true">☥</span><?php endif;?>
<span class="sf-card-view" aria-hidden="true">View reference ↗</span></a>
<div class="sf-card-copy"><span class="sf-kicker"><?=pfEscape($kind)?></span>
<h3><a href="<?=pfEscape($url)?>"><?=pfEscape($name)?></a></h3>
<?php if($summary!==''):?><p><?=pfEscape($summary)?></p><?php endif;?>
<div class="sf-card-meta"><?php if(($s=pfStrength($p))!==''):?><span><?=pfEscape($s)?></span><?php endif;?>
<?php if(($f=pfFormat($p))!=='Other'):?><span><?=pfEscape($f)?></span><?php endif;?></div>
<div class="sf-card-bottom"><span class="sf-profile-label">Technical profile</span>
<button type="button" class="sf-card-quick" data-quick-name="<?=pfEscape($name)?>" data-quick-kind="<?=pfEscape($kind)?>" data-quick-summary="<?=pfEscape($summary)?>" data-quick-href="<?=pfEscape($url)?>" data-quick-photo="<?=pfEscape($photo)?>" aria-label="Quick view <?=pfEscape($name)?>">Quick view <span aria-hidden="true">↗</span></button>
</div></div></article>
