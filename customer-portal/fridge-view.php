<?php
declare(strict_types=1);
if(!defined('ANKH_PORTAL_VIEW')||empty($customer)){http_response_code(404);exit;}
if(!empty($isDemo)):?>
<section class="pc-fridge">
  <header class="pc-fridge-heading"><div><span class="pc-eyebrow">YOUR PRIVATE INVENTORY</span><h1>My Fridge<span class="pc-dot">.</span></h1><p>Keep track of products you already have, and see an estimate of when they may run out.</p></div><div class="pc-fridge-heading-symbol" aria-hidden="true">☥</div></header>
  <div class="pc-fridge-intro"><span><?=pcIcon('shield')?></span><p><strong>Preview only</strong> · Your real fridge starts empty. After signing in, you can choose which delivered products to add. Only quantities you confirm are used in your estimates.</p></div>
  <div class="pc-fridge-demo pc-panel"><span class="pc-eyebrow">FICTIONAL EXAMPLE</span><h2>Sample research material</h2><p>Estimated remaining: <strong>2.5 mg</strong> · Example weekly usage: 0.5 mg</p><div class="pc-fridge-track"><span style="width:50%"></span></div><strong>Illustrative estimate: approximately 5 weeks</strong><p>No treatment or dosing recommendations are provided by this feature.</p></div>
</section>
<?php return; endif;
if(empty($portal)){http_response_code(503);return;}
$fridgeDataError=false;$fridgeItems=[];$fridgeCandidates=[];
try{
    $fridgeItems=portalFridgeItems($portal,$customerId,$staff);
    $fridgeCandidates=portalFridgeCandidates($orders,$fridgeItems);
}catch(Throwable $e){error_log('ANKH fridge view: '.$e->getMessage());$fridgeDataError=true;}
$flash=(string)($_SESSION['fridge_flash']??'');unset($_SESSION['fridge_flash']);
$unitsInFridge=array_sum(array_map(static fn(array $item):int=>(int)$item['units_received'],$fridgeItems));
$hasForecast=count(array_filter($fridgeItems,static fn(array $item):bool=>(int)$item['rate_milli']>0));
$fridgeMethod='All dates are estimates based on the amount you enter and the daily or weekly research usage rate you set. The calculation assumes a consistent rate; it does not confirm actual stock, stability or safe use.';
?>
<section class="pc-fridge">
  <header class="pc-fridge-heading"><div><span class="pc-eyebrow">YOUR PERSONAL STOCK TRACKER</span><h1>My Fridge<span class="pc-dot">.</span></h1><p>One place to keep track of your research products. Know what you have, what you have used, and what might be running low.</p></div><div class="pc-fridge-heading-symbol" aria-hidden="true">☥</div></header>
  <?php if($flash!==''):?><div class="pc-notification-feedback" role="status"><?=portalEscape($flash)?></div><?php endif;?>
  <?php if(!empty($GLOBALS['fridgeError'])):?><div class="pc-notification-feedback" role="alert"><?=portalEscape($GLOBALS['fridgeError'])?></div><?php endif;?>
  <?php if($fridgeDataError):?><section class="pc-panel pc-empty" role="alert"><h2>My Fridge is temporarily unavailable</h2><p>Your order records are unchanged. Please try again later.</p></section>
  <?php else:?>
  <div class="pc-fridge-stats">
    <div><span class="pc-eyebrow">IN YOUR FRIDGE</span><strong><?=count($fridgeItems)?></strong><small>Confirmed stock entries</small></div>
    <div><span class="pc-eyebrow">FROM YOUR ORDERS</span><strong><?=count($fridgeCandidates)?></strong><small>Delivered lines ready to add</small></div>
    <div><span class="pc-eyebrow">FORECASTS</span><strong><?=$hasForecast?></strong><small>Items with your usage rate saved</small></div>
  </div>
  <?php if(!$fridgeItems):?>
  <section class="pc-fridge-empty pc-panel"><span aria-hidden="true">☥</span><h2>Let's organise your fridge.</h2><p>Products from your delivered orders are ready to add below. Start with one you have in stock and confirm how much is left. You can always update it later.</p><a href="#pc-fridge-import" class="pc-primary-link">Add a delivered product <?=pcIcon('right')?></a></section>
  <?php else:?>
  <div class="pc-fridge-heading-row"><div><span class="pc-eyebrow">YOUR INVENTORY</span><h2>Saved products</h2></div><a href="#pc-fridge-import">+ Add from orders</a></div>
  <div class="pc-fridge-grid">
  <?php foreach($fridgeItems as $entry):
    $projection=portalFridgeProjection($entry);
    $rate=(int)$entry['rate_milli'];
    $hasRate=$rate>0&&$entry['rate_period']!=='none';
    $end=$projection['runout_date'];
    $remaining=portalFridgeFormat($projection['remaining_milli']);
    $unit=$entry['unit']==='items'?'items':$entry['unit'];
    $daysLeft=$projection['days_left'];
    $level=$projection['percent'];
    $class=$hasRate&&$daysLeft!==null&&$daysLeft<=7?'pc-fridge-soon':($hasRate&&$daysLeft!==null&&$daysLeft<=21?'pc-fridge-watch':'');
    ?>
    <article class="pc-fridge-card <?=$class?>">
      <header><div class="pc-fridge-mark" aria-hidden="true">☥</div><div><small class="pc-eyebrow"><?=portalEscape((string)$entry['presentation']?:'SAVED INVENTORY')?></small><h3><?=portalEscape((string)$entry['product_name'])?></h3><small>From order <?=portalEscape('ANK-'.str_pad((string)$entry['source_order_id'],4,'0',STR_PAD_LEFT))?> · <?=$entry['units_received']?> item<?=$entry['units_received']===1?'':'s'?> received</small></div></header>
      <div class="pc-fridge-level"><div><span><?= $projection['estimated']?'Estimated remaining':'Last recorded remaining' ?></span><strong><?=$remaining?> <small><?=portalEscape($unit)?></small></strong></div><div class="pc-fridge-track" role="meter" aria-label="Estimated remaining as a percentage of recorded starting stock" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?=$level?>"><span style="width:<?=$level?>%"></span></div></div>
      <div class="pc-fridge-forecast">
        <?php if($hasRate&&$daysLeft!==null&&$daysLeft===0):?><span class="pc-fridge-forecast-icon"><?=pcIcon('clock')?></span><div><strong>Possibly out of stock</strong><small>Update your actual remaining amount to correct this estimate.</small></div>
        <?php elseif($hasRate&&$end):?><span class="pc-fridge-forecast-icon"><?=pcIcon('calendar')?></span><div><strong>Estimated until <?=portalEscape(portalDate($end))?></strong><small>About <?=$daysLeft?> day<?=$daysLeft===1?'':'s'?> remaining at your entered rate</small></div>
        <?php elseif($hasRate):?><span class="pc-fridge-forecast-icon"><?=pcIcon('calendar')?></span><div><strong>More than 10 years estimated</strong><small>Based on the entered rate</small></div>
        <?php else:?><span class="pc-fridge-forecast-icon"><?=pcIcon('clock')?></span><div><strong>Add a usage estimate</strong><small>Enter your own research usage rate to see a possible run-out date</small></div><?php endif;?>
      </div>
      <details class="pc-fridge-edit" <?=!$hasRate?'open':''?>>
        <summary><?=pcIcon('spark')?> <?=!$hasRate?'Set your estimates':'Update quantities and estimate'?> <span aria-hidden="true">⌄</span></summary>
        <form method="post" class="pc-fridge-form">
          <input type="hidden" name="csrf" value="<?=portalEscape($_SESSION['portal_csrf'])?>">
          <input type="hidden" name="action" value="fridge_update">
          <input type="hidden" name="item_id" value="<?=$entry['id']?>">
          <label>Amount actually remaining now <small>(<?=$unit?>)</small>
            <input name="remaining" inputmode="decimal" type="text" maxlength="12" pattern="[0-9]{1,6}(\.[0-9]{1,3})?" value="<?=portalEscape(portalFridgeFormat($projection['remaining_milli']))?>" required>
          </label>
          <div class="pc-fridge-frequency"><label>Your estimated usage <small>(<?=$unit?>)</small>
            <input name="rate" inputmode="decimal" type="text" maxlength="12" pattern="[0-9]{1,6}(\.[0-9]{1,3})?" value="<?=portalEscape(portalFridgeFormat($rate))?>">
          </label><label>Frequency <small>(optional)</small>
            <select name="period"><option value="none" <?=$entry['rate_period']==='none'?'selected':''?>>Don't forecast</option><option value="day" <?=$entry['rate_period']==='day'?'selected':''?>>Per day</option><option value="week" <?=$entry['rate_period']==='week'?'selected':''?>>Per week</option></select>
          </label></div>
          <p class="pc-fridge-help">These amounts are entered by you. This is an inventory projection, not an instruction on how to use the product. Saving resets the calculation from today's remaining amount.</p>
          <button class="pc-primary-link" type="submit">Save my inventory <?=pcIcon('check')?></button>
        </form>
      </details>
      <form class="pc-fridge-archive" method="post"><input type="hidden" name="csrf" value="<?=portalEscape($_SESSION['portal_csrf'])?>"><input type="hidden" name="action" value="fridge_archive"><input type="hidden" name="item_id" value="<?=$entry['id']?>"><button type="submit">Remove from My Fridge</button></form>
    </article>
  <?php endforeach;?></div>
  <?php endif;?>
  <section class="pc-fridge-import" id="pc-fridge-import">
    <div class="pc-fridge-heading-row"><div><span class="pc-eyebrow">PRE-FILLED FROM YOUR ACCOUNT</span><h2>Add from previous orders</h2><p>Only delivered items are shown. Add an item if you actually have it in your fridge; the order itself is not proof that any stock remains.</p></div></div>
    <?php if(!$fridgeCandidates):?><div class="pc-fridge-no-candidates"><?=pcIcon('check')?><p>No additional delivered products to add right now.</p><a href="<?=portalEscape(pcHref('orders',$staff,$customerId))?>">View order history <?=pcIcon('right')?></a></div>
    <?php else:?>
      <div class="pc-fridge-import-list"><?php foreach(array_slice($fridgeCandidates,0,40) as $candidate):?>
        <article class="pc-fridge-import-row"><span class="pc-fridge-import-icon" aria-hidden="true">☥</span><div><strong><?=portalEscape($candidate['name'])?></strong><small><?=$candidate['quantity']?> × <?=portalEscape($candidate['presentation']?:'Item')?> · <?=portalEscape(portalFridgeFormat($candidate['total_milli']))?> <?=portalEscape($candidate['unit'])?> · From ANK-<?=str_pad((string)$candidate['order_id'],4,'0',STR_PAD_LEFT)?></small></div><form method="post"><input type="hidden" name="csrf" value="<?=portalEscape($_SESSION['portal_csrf'])?>"><input type="hidden" name="action" value="fridge_add"><input type="hidden" name="order_id" value="<?=$candidate['order_id']?>"><input type="hidden" name="line_index" value="<?=$candidate['line_index']?>"><button type="submit">+ Add</button></form></article>
      <?php endforeach;?></div>
    <?php endif;?>
  </section>
  <?php endif;?>
  <aside class="pc-fridge-disclaimer"><?=pcIcon('shield')?><div><strong>For inventory tracking only</strong><p><?=portalEscape($fridgeMethod)?></p><p>These products are sold for laboratory research only, not human or animal consumption. This tool gives no dosing, medical, storage-stability or treatment advice. It does not automatically order products or send you reminders.</p></div></aside>
</section>
