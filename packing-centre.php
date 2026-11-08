<?php
if(!($auth??false)||!($db instanceof PDO)){http_response_code(403);exit('Forbidden');}
$pcRun=ankhPackingCentreRun($db,(int)($_GET['run']??0));
$pcRuns=$db->query('SELECT r.*, (SELECT COUNT(*) FROM packing_run_orders m WHERE m.run_id=r.id) order_count FROM packing_runs r ORDER BY r.id DESC')->fetchAll(PDO::FETCH_ASSOC);
$pcClaims=$db->query('SELECT order_id,run_id FROM packing_run_claims')->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<div class="pc-workspace">
 <div class="heading page-header"><div class="page-header-main"><h1>Packing Centre</h1></div><a class="button page-action" href="?view=packing_centre">+ New packing run</a></div>
 <div class="pc-tabs" role="navigation" aria-label="Packing Centre"><a href="?view=packing_centre" <?=$pcRun?'':'aria-current="page"'?>>Choose orders</a><a href="#pc-runs">Saved runs (<?=count($pcRuns)?>)</a><a href="?view=orders">All orders →</a></div>
<?php if(isset($_GET['run'])&&!$pcRun):?><p role="alert" class="error">Packing run not found. Choose a saved run below.</p><?php endif;?>
<?php if($pcRun):$pcRunId=(int)$pcRun['id'];$pcTotals=ankhPackingCentreTotals($pcRun['orders']);?>
 <section class="pc-panel">
  <p class="eyebrow">RUN #<?=$pcRunId?> · <?=e($pcRun['state'])?></p><h2><?=e($pcRun['name'])?></h2>
  <p class="muted"><?=e($pcRun['owner']?:'Unassigned')?> · Created <?=e(date('j M Y, H:i',strtotime($pcRun['created'])))?> UTC · <?=count($pcRun['orders'])?> orders</p>
  <?php if($pcRun['notes']!==''):?><p class="pc-note"><?=nl2br(e($pcRun['notes']))?></p><?php endif;?>
  <div class="pc-print-actions">
   <?php foreach(['pick'=>'Combined picking sheet','slips'=>'Individual packing slips','labels'=>'Envelope name labels'] as $kind=>$label):?><a class="button" href="?view=packing_centre&amp;run=<?=$pcRunId?>&amp;print=<?=$kind?>" target="_blank" rel="noopener"><?=e($label)?> ↗</a><?php endforeach;?>
  </div>
  <p class="muted">Print or save as PDF from the print view. Labels are an A4 cut-out sheet. Printing never marks orders prepared, packaged, paid or delivered.</p>
  <?php if($pcRun['state']==='open'):?><div class="pc-run-actions"><?php foreach(['completed'=>'Finish run','cancelled'=>'Cancel run'] as $state=>$label):?><form method="post" onsubmit="return confirm('Close this packing run and release its orders? Order preparation and delivery statuses will stay unchanged.');"><?php csrf();?><input type="hidden" name="action" value="packing_run_update"><input type="hidden" name="run_id" value="<?=$pcRunId?>"><input type="hidden" name="run_action" value="<?=$state?>"><button class="quiet" type="submit"><?=$label?></button></form><?php endforeach;?></div><?php else:?><p class="pc-note">This run is closed. Its orders are released for future runs. Saved runs show the current order details.</p><?php endif;?>
 </section>
 <section class="pc-panel"><h2>Combined picking list</h2><p class="muted">Pen selections are shown as cartridges. Cartridge refills of the same strength are combined; pen devices are counted separately, once per recorded unit.</p>
  <div class="pc-table-wrap"><table><thead><tr><th>Product / strength</th><th>Presentation</th><th>Units</th><th>Orders</th></tr></thead><tbody><?php foreach($pcTotals as $total):?><tr><td><?=e($total['name'])?></td><td><?=e($total['presentation']?:'Not specified')?></td><td><strong><?=$total['quantity']?></strong></td><td><?=count($total['orders'])?></td></tr><?php endforeach;?></tbody></table></div>
 </section>
 <section class="pc-panel"><h2>Orders in this run</h2><div class="pc-order-list">
 <?php foreach($pcRun['orders'] as $o):?>
  <article class="pc-order"><div class="pc-order-main"><strong><?=e(orderDisplayName($o))?></strong><span>ANK-<?=str_pad((string)$o['id'],4,'0',STR_PAD_LEFT)?> · <?=(int)$o['back_order']===1?'Back order':'Active order'?> · <?=e($o['status'])?></span>
   <?php if($o['changed']):?><p class="pc-warning" role="status">Changed since this run was created. Review before packing; printouts use current details.</p><?php endif;?>
   <?php if(!ankhPackingCentreEligible($o)):?><p class="pc-warning">This order is now delivered or cancelled. Remove it from this run before packing.</p><?php endif;?>
   <?php foreach(ankhPackingCentreLines($o['lines']) as $line):?><small><?=(int)$line['quantity']?> × <?=e($line['name'])?><?= $line['presentation']!==''?' · '.e($line['presentation']):''?></small><?php endforeach;?>
   <?php if($pcPenNote=ankhPackingCentrePenNote($o['lines'])):?><p class="pc-warning"><?=e($pcPenNote)?></p><?php endif;?>
   <a href="?view=edit&amp;id=<?=(int)$o['id']?>">Review order →</a></div>
   <?php if($pcRun['state']==='open'):?><form method="post"><?php csrf();?><input type="hidden" name="action" value="packing_run_update"><input type="hidden" name="run_id" value="<?=$pcRunId?>"><input type="hidden" name="order_id" value="<?=(int)$o['id']?>"><input type="hidden" name="run_action" value="remove"><button type="submit" class="quiet">Release order</button></form><?php endif;?>
  </article>
 <?php endforeach;?>
 <?php if(!$pcRun['orders']):?><p class="muted">No orders remain in this run. Close it and create a new run.</p><?php endif;?></div>
 </section>
<?php else:
 $pcCandidates=array_values(array_filter($orders,'ankhPackingCentreEligible'));
 $pcItems=[];foreach($db->query('SELECT order_id,name,quantity,presentation FROM items ORDER BY id') as $line)$pcItems[(int)$line['order_id']][]=$line;
?>
 <section class="pc-panel"><h2>Create a packing run</h2><p class="muted">Select active orders, back orders or both. Unpaid and zero-stock orders can be included. Orders in an open run are reserved for that run.</p>
 <div class="pc-filters"><label>Search orders<input type="search" id="pc-search" placeholder="Customer, order number or product"></label><label>Order list<select id="pc-source"><option value="all">Active &amp; back orders</option><option value="active">Active orders</option><option value="back">Back orders</option></select></label><label>Assigned to<select id="pc-assignee"><option value="all">Everyone</option><?php $pcOwners=[];foreach($pcCandidates as $o)$pcOwners[(string)$o['assigned_to']]=true;foreach(array_keys($pcOwners) as $owner):?><option value="<?=e($owner?:'__unassigned')?>"><?=e($owner?:'Unassigned')?></option><?php endforeach;?></select></label></div>
 <form method="post" id="pc-create"><?php csrf();?><input type="hidden" name="action" value="packing_run_create"><input type="hidden" name="request_key" value="<?=e(bin2hex(random_bytes(16)))?>"><input type="hidden" name="return" value="packing_centre">
 <div class="pc-run-fields"><label>Run name<input name="run_name" maxlength="100" value="Packing run · <?=e($now->format('j M Y'))?>" required></label><label>Run owner<input name="owner" maxlength="80" placeholder="Jay or Tony"></label><label>Run notes<textarea name="run_notes" maxlength="2000" rows="2" placeholder="Instructions for this packing run"></textarea></label></div>
 <div class="pc-selection-bar"><div><strong id="pc-selected" aria-live="polite">0 orders selected</strong><small>Selection is kept when filtering.</small></div><button type="button" class="quiet" id="pc-select-visible">Select visible</button><button type="button" class="quiet" id="pc-clear">Clear selection</button></div>
 <div class="pc-order-list">
 <?php foreach($pcCandidates as $o):$oid=(int)$o['id'];$claimed=(int)($pcClaims[$oid]??0);$lines=$pcItems[$oid]??[];$search=$o['customer'].' '.($o['recipient_name']??'').' ANK-'.$oid.' ANK-'.str_pad((string)$oid,4,'0',STR_PAD_LEFT).' '.implode(' ',array_column($lines,'name'));?>
  <article class="pc-order" data-pc-order data-search="<?=e(mb_strtolower($search))?>" data-source="<?=(int)$o['back_order']===1?'back':'active'?>" data-assignee="<?=e($o['assigned_to']?:'__unassigned')?>">
   <label class="pc-choice"><input type="checkbox" name="order_ids[]" value="<?=$oid?>" <?=($claimed||!$lines)?'disabled':''?>><span class="pc-order-main"><strong><?=e(orderDisplayName($o))?></strong><span>ANK-<?=str_pad((string)$oid,4,'0',STR_PAD_LEFT)?> · <?=(int)$o['back_order']===1?'Back order':'Active order'?> · <?=e($o['assigned_to']?:'Unassigned')?></span><small>Ordered <?=e(substr($o['created'],0,10))?> · <?=e($o['status'])?><?= $o['scheduled_delivery_date']!==''?' · Delivery '.e($o['scheduled_delivery_date']):''?></small>
    <?php foreach(ankhPackingCentreLines($lines) as $line):?><small><?=(int)$line['quantity']?> × <?=e($line['name'])?><?=$line['presentation']!==''?' · '.e($line['presentation']):''?></small><?php endforeach;?>
    <?php if(!$lines):?><small>No order lines — review this order first.</small><?php endif;?>
   </span></label>
   <?php if($claimed):?><a class="pc-claim" href="?view=packing_centre&amp;run=<?=$claimed?>">In run #<?=$claimed?> →</a><?php endif;?>
  </article>
 <?php endforeach;?>
 </div><p id="pc-empty" class="muted" <?=count($pcCandidates)?'hidden':''?>>No orders match these filters.</p>
 <div class="pc-save"><button type="submit" id="pc-save" disabled>Save packing run</button><p class="muted">Saving a run leaves stock, payments and order statuses unchanged.</p></div>
 </form></section>
<?php endif;?>
 <section class="pc-panel" id="pc-runs"><h2>Saved packing runs</h2><p class="muted">Open runs reserve their orders until you release an order, finish or cancel the run.</p><div class="pc-saved-list">
 <?php foreach($pcRuns as $r):?><a href="?view=packing_centre&amp;run=<?=(int)$r['id']?>"><div><strong><?=e($r['name'])?></strong><small>#<?=(int)$r['id']?> · <?=e($r['owner']?:'Unassigned')?> · <?=(int)$r['order_count']?> orders · <?=e(substr($r['created'],0,10))?></small></div><span><?=e(ucfirst($r['state']))?> →</span></a><?php endforeach;?>
 <?php if(!$pcRuns):?><p class="muted">Your first saved run will appear here for both admins.</p><?php endif;?></div></section>
</div>
<script>
(()=>{const form=document.getElementById('pc-create');if(!form)return;
 const cards=[...form.querySelectorAll('[data-pc-order]')],checks=[...form.querySelectorAll('input[name="order_ids[]"]')];
 const search=document.getElementById('pc-search'),source=document.getElementById('pc-source'),owner=document.getElementById('pc-assignee');
 function count(){const n=checks.filter(c=>c.checked).length;document.getElementById('pc-selected').textContent=n+' order'+(n===1?'':'s')+' selected';document.getElementById('pc-save').disabled=n===0;}
 function filter(){const term=search.value.trim().toLowerCase();cards.forEach(c=>c.hidden=!(c.dataset.search.includes(term)&&(source.value==='all'||c.dataset.source===source.value)&&(owner.value==='all'||c.dataset.assignee===owner.value)));document.getElementById('pc-empty').hidden=cards.some(c=>!c.hidden);}
 [search,source,owner].forEach(el=>el.addEventListener('input',filter));checks.forEach(c=>c.addEventListener('change',count));
 document.getElementById('pc-select-visible').addEventListener('click',()=>{cards.filter(c=>!c.hidden).forEach(c=>{const box=c.querySelector('input');if(!box.disabled)box.checked=true;});count();});
 document.getElementById('pc-clear').addEventListener('click',()=>{checks.forEach(c=>c.checked=false);count();});
 form.addEventListener('submit',()=>{document.getElementById('pc-save').disabled=true;document.getElementById('pc-save').textContent='Saving run…';});
})();
</script>
