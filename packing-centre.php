<?php
if(isset($_SERVER['SCRIPT_FILENAME'])&&realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){$q=$_GET;$q['view']='packing';header('Location: ./?'.http_build_query($q),true,302);exit;}
if(!($auth??false)||!($db instanceof PDO)){http_response_code(403);exit('Forbidden');}
if(($packingTab??'')==='print'){
 $printTz=new DateTimeZone('Europe/London');
 $printToday=isset($now)&&$now instanceof DateTimeImmutable?$now->setTime(0,0):new DateTimeImmutable('today',$printTz);
 $printPerson=ankhPrintPersonFromRequest((string)($_GET['person']??'Everyone'));
 $printSheet=ankhPrintSheetFromRequest((string)($_GET['sheet']??'packing'));
 $printDateRaw=trim((string)($_GET['date']??''));
 $printAll=!empty($_GET['all'])||$printDateRaw==='all';
 $printDate=$printToday;
 if(!$printAll&&$printDateRaw!==''){
  $printParsed=DateTimeImmutable::createFromFormat('!Y-m-d',$printDateRaw,$printTz);
  if($printParsed&&$printParsed->format('Y-m-d')===$printDateRaw)$printDate=$printParsed;
 }
 $printOrders=ankhPrintSheetOrders(is_array($orders??null)?$orders:[],$printPerson,$printDate->format('Y-m-d'),$printAll);
 $printOrders=ankhPrintAttachLines($db,$printOrders);
 $printTotals=ankhPrintSheetTotals($printOrders);
 $printTitle=ankhPrintSheetTitle($printSheet,$printPerson,$printDate->format('Y-m-d'),$printAll);
 $printTotalToCollect=array_sum(array_map('ankhPrintOrderBalance',$printOrders));
 $printPeople=['Jay'=>'Jay','Tony'=>'Tony','Holding'=>'Holding','Everyone'=>'Everyone'];
 ?>
<style>
.print-sheets-screen{max-width:1160px;margin:auto}
.print-controls{display:grid;gap:16px;padding:18px;border:1px solid #39352d;border-radius:18px;background:var(--surface,#17191c);margin:20px 0}
.print-people{display:flex;flex-wrap:wrap;gap:10px;border:0;padding:0;margin:0;min-width:0}
.print-people legend{padding:0;color:#bdbab1;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.print-chip{display:inline-flex!important;flex-direction:row!important;align-items:center;gap:8px;min-height:44px;padding:10px 16px;border:1px solid #4b4434;border-radius:999px;font-size:13px;cursor:pointer;margin:0}
.print-chip input{width:18px!important;min-height:18px!important;margin:0!important;accent-color:#d4b878}
.print-chip span{font-size:13px;line-height:1}
.print-chip:has(input:checked){background:#d4b878;color:#171713;border-color:#d4b878}
.print-fields{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;min-width:0}
.print-fields label{display:flex!important;flex-direction:column!important;gap:7px;font-size:12px;margin:0;min-width:150px;max-width:100%}
.print-fields input[type=date],.print-fields select{min-height:44px;font-size:14px}
.print-all{flex-direction:row!important;align-items:center!important;min-height:44px;gap:9px!important;min-width:0!important}
.print-all input{width:18px!important;min-height:18px!important;margin:0!important;accent-color:#d4b878}
.print-actions{display:flex;flex-wrap:wrap;gap:10px}
.print-actions button{min-height:44px;font-size:14px}
.print-sheet-preview{background:#fff;color:#111;border:1px solid #b9b2a2;border-radius:16px;padding:24px;margin:20px 0;max-width:100%;overflow-wrap:anywhere}
.print-sheet-head{border-bottom:2px solid #111;padding-bottom:12px;margin-bottom:16px}
.print-sheet-head .eyebrow{color:#6b5524;font-size:12px;margin:0 0 4px}
.print-sheet-head h2{font-size:clamp(19px,4vw,28px);margin:4px 0;color:#111;line-height:1.2}
.print-sheet-meta{font-size:13px;color:#555;margin:0}
.print-section-title{font-size:14px;margin:18px 0 8px;color:#111;letter-spacing:.08em;text-transform:uppercase}
.print-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%}
.print-table{width:100%;border-collapse:collapse;font-size:13px;color:#111;min-width:560px}
.print-table th,.print-table td{text-align:left;padding:9px 8px;border-bottom:1px solid #ccc;vertical-align:top;overflow-wrap:anywhere}
.print-table thead th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#444}
.print-delivery-table{min-width:900px}
.print-tick{display:inline-grid;place-items:center;width:17px;height:17px;border:1.5px solid #111;border-radius:2px;vertical-align:middle}
.print-tick.is-checked{background:#111;color:#fff;border-color:#111}
.print-tick.is-checked::after{content:"✓";font-size:12px;line-height:1}
.print-ref{display:block;font-size:12px;color:#555;margin-top:2px;font-weight:400}
.print-num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.print-nowrap{white-space:nowrap}
.print-total-label{text-align:right;font-size:14px;padding-top:12px;border-top:2px solid #111;white-space:nowrap}
.print-empty{padding:24px;text-align:center;color:#555;font-size:14px}
@media(max-width:650px){.print-sheet-preview{padding:14px;border-radius:12px}.print-fields{flex-direction:column;align-items:stretch}.print-fields label{min-width:0}.print-chip{flex:1 1 calc(50% - 5px);justify-content:center}.print-table{font-size:12px}}
@media print{
 @page{size:A4 portrait;margin:12mm}
 html,body{background:#fff!important;color:#000!important}
 body:has(>.admin-app-shell){height:auto!important;overflow:visible!important}
 .admin-app-shell{display:block!important;height:auto!important;overflow:visible!important}
 .admin-app-shell>aside,.admin-app-shell>nav.mobile-nav,.admin-app-shell>main#ankh-main>header,.packing-screen,.print-sheets-screen>.heading,.print-controls,.no-print,.ux-skip-link,.ios-status-bar,.ankh-toast-region{display:none!important}
 .admin-app-shell>main#ankh-main{overflow:visible!important;height:auto!important;max-width:none!important;padding:0!important;margin:0!important}
 .print-sheets-screen{max-width:none!important;margin:0!important}
 .print-sheet-preview{border:0!important;border-radius:0!important;padding:0!important;margin:0!important;box-shadow:none!important;background:#fff!important;color:#000!important}
 .print-sheet-head,.print-sheet-head h2,.print-sheet-meta,.print-section-title,.print-table th,.print-table td,.print-ref,.print-empty,.print-total-label{color:#000!important}
 .print-table{font-size:10.5pt!important;width:100%!important;min-width:0!important}
 .print-delivery-table{font-size:10pt!important}
 .print-table th,.print-table td{padding:5px 4px!important;border-color:#999!important}
 .print-table-wrap{overflow:visible!important}
 .print-table tr{break-inside:avoid!important;page-break-inside:avoid!important}
 .print-sheet-head{break-after:avoid!important;page-break-after:avoid!important}
 .print-tick{border-color:#000!important}
 .print-tick.is-checked{background:#000!important;color:#fff!important}
 a{color:#000!important;text-decoration:none!important}
}
</style>
<div class="pc-workspace print-sheets-screen">
 <div class="heading page-header"><div class="page-header-main"><h1>Print sheets</h1></div></div>
 <form method="get" class="print-controls" data-print-controls>
  <input type="hidden" name="view" value="packing"><input type="hidden" name="tab" value="print">
  <fieldset class="print-people"><legend>Person</legend>
   <?php foreach($printPeople as $printKey=>$printLabel):?><label class="print-chip"><input type="radio" name="person" value="<?=e($printKey)?>" <?=$printPerson===$printKey?'checked':''?>><span><?=e($printLabel)?></span></label><?php endforeach;?>
  </fieldset>
  <div class="print-fields">
   <label>Date<input type="date" name="date" value="<?=$printAll?'':e($printDate->format('Y-m-d'))?>" <?=$printAll?'disabled':''?>></label>
   <label class="print-all"><input type="checkbox" name="all" value="1" <?=$printAll?'checked':''?>> All undelivered</label>
   <label>Sheet<select name="sheet"><option value="packing" <?=$printSheet==='packing'?'selected':''?>>Packing list</option><option value="delivery" <?=$printSheet==='delivery'?'selected':''?>>Delivery sheet</option></select></label>
  </div>
  <div class="print-actions"><button type="submit" class="quiet">Update preview</button><button type="button" class="button" onclick="window.print()">Print</button></div>
 </form>
 <section class="print-sheet-preview" data-print-sheet="<?=e($printSheet)?>" data-print-person="<?=e($printPerson)?>">
  <div class="print-sheet-head"><p class="eyebrow">ANKH PEPTIDES</p><h2 data-print-title><?=e($printTitle)?></h2><p class="print-sheet-meta"><?=count($printOrders)?> order<?=count($printOrders)===1?'':'s'?> · Printing never changes order status, stock or payments.</p></div>
  <?php if(!$printOrders):?><p class="print-empty" role="status">No undelivered orders match this sheet.</p>
  <?php elseif($printSheet==='delivery'):?>
  <div class="print-table-wrap"><table class="print-table print-delivery-table"><thead><tr><th>#</th><th>Client</th><th>Address</th><th>Phone</th><th>Items</th><th class="print-num">Collect</th><th>Paid</th><th>Notes</th><th>Signature</th></tr></thead><tbody>
  <?php foreach($printOrders as $printIndex=>$printOrder):$printBalance=ankhPrintOrderBalance($printOrder);$printAddress=trim((string)($printOrder['address']??''));?>
  <tr class="print-sheet-row"><td><?=$printIndex+1?></td><td><strong><?=e(ankhPrintClientName($printOrder))?></strong><span class="print-ref"><?=e(ankhPrintOrderReference($printOrder))?></span></td><td><?=$printAddress!==''?nl2br(e($printAddress)):'—'?></td><td class="print-nowrap"><?=e((string)($printOrder['phone']??''))?></td><td><?=e(ankhPrintOrderItemsText($printOrder))?></td><td class="print-num"><?=$printBalance>0?money($printBalance):'Paid'?></td><td><span class="print-tick<?=$printBalance<=0?' is-checked':''?>" aria-hidden="true"></span></td><td><?=trim((string)($printOrder['notes']??''))!==''?nl2br(e((string)$printOrder['notes'])):'—'?></td><td><span class="print-tick" aria-hidden="true"></span></td></tr>
  <?php endforeach;?></tbody><tfoot><tr><th colspan="5" class="print-total-label">Total to collect</th><th class="print-num" data-print-total><?=money($printTotalToCollect)?></th><th colspan="3"></th></tr></tfoot></table></div>
  <?php else:?>
  <h3 class="print-section-title">Products to pull</h3>
  <div class="print-table-wrap"><table class="print-table"><thead><tr><th>Tick</th><th>Product</th><th class="print-num">Total qty</th></tr></thead><tbody>
  <?php foreach($printTotals as $printTotal):?><tr class="print-sheet-row"><td><span class="print-tick" aria-hidden="true"></span></td><td><?=e($printTotal['name'])?><?=trim((string)$printTotal['presentation'])!==''?' · '.e($printTotal['presentation']):''?></td><td class="print-num"><strong><?=(int)$printTotal['quantity']?></strong></td></tr><?php endforeach;?>
  </tbody></table></div>
  <h3 class="print-section-title">Orders</h3>
  <div class="print-table-wrap"><table class="print-table"><thead><tr><th>Tick</th><th>Client / order</th><th>Items</th></tr></thead><tbody>
  <?php foreach($printOrders as $printOrder):?><tr class="print-sheet-row"><td><span class="print-tick" aria-hidden="true"></span></td><td><strong><?=e(ankhPrintFirstName(ankhPrintClientName($printOrder)))?></strong><span class="print-ref"><?=e(ankhPrintOrderReference($printOrder))?></span></td><td><?=e(ankhPrintOrderItemsText($printOrder))?></td></tr><?php endforeach;?>
  </tbody></table></div>
  <?php endif;?>
 </section>
</div>
<script>
(()=>{const form=document.querySelector('[data-print-controls]');if(!form)return;const all=form.querySelector('input[name="all"]');const date=form.querySelector('input[name="date"]');if(!all||!date)return;const sync=()=>{date.disabled=all.checked;};all.addEventListener('change',()=>{sync();form.requestSubmit?form.requestSubmit():form.submit();});form.querySelectorAll('input[name="person"],select[name="sheet"]').forEach(el=>el.addEventListener('change',()=>{form.requestSubmit?form.requestSubmit():form.submit();}));sync();})();
</script>
<?php return; }
$pcRun=ankhPackingCentreRun($db,(int)($_GET['run']??0));
$pcRuns=$db->query('SELECT r.*, (SELECT COUNT(*) FROM packing_run_orders m WHERE m.run_id=r.id) order_count FROM packing_runs r ORDER BY r.id DESC')->fetchAll(PDO::FETCH_ASSOC);
$pcClaims=$db->query('SELECT order_id,run_id FROM packing_run_claims')->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<div class="pc-workspace">
 <div class="heading page-header"><div class="page-header-main"><h1>Packing Centre</h1></div><a class="button page-action" href="?view=packing&amp;tab=runs">+ New packing run</a></div>
 <div class="pc-tabs" role="navigation" aria-label="Packing Centre"><a href="?view=packing&amp;tab=runs" <?=$pcRun?'':'aria-current="page"'?>>Choose orders</a><a href="#pc-runs">Saved runs (<?=count($pcRuns)?>)</a><a href="?view=orders">All orders →</a></div>
<?php if(isset($_GET['run'])&&!$pcRun):?><p role="alert" class="error">Packing run not found. Choose a saved run below.</p><?php endif;?>
<?php if($pcRun):$pcRunId=(int)$pcRun['id'];$pcTotals=ankhPackingCentreTotals($pcRun['orders']);?>
 <section class="pc-panel">
  <p class="eyebrow">RUN #<?=$pcRunId?> · <?=e($pcRun['state'])?></p><h2><?=e($pcRun['name'])?></h2>
  <p class="muted"><?=e($pcRun['owner']?:'Unassigned')?> · Created <?=e(date('j M Y, H:i',strtotime($pcRun['created'])))?> UTC · <?=count($pcRun['orders'])?> orders</p>
  <?php if($pcRun['notes']!==''):?><p class="pc-note"><?=nl2br(e($pcRun['notes']))?></p><?php endif;?>
  <div class="pc-print-actions">
   <?php foreach(['pick'=>'Combined picking sheet','slips'=>'Individual packing slips','labels'=>'Envelope name labels'] as $kind=>$label):?><a class="button" href="?view=packing&amp;tab=runs&amp;run=<?=$pcRunId?>&amp;print=<?=$kind?>" target="_blank" rel="noopener"><?=e($label)?> ↗</a><?php endforeach;?>
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
 <form method="post" id="pc-create"><?php csrf();?><input type="hidden" name="action" value="packing_run_create"><input type="hidden" name="request_key" value="<?=e(bin2hex(random_bytes(16)))?>"><input type="hidden" name="return" value="packing">
 <div class="pc-run-fields"><label>Run name<input name="run_name" maxlength="100" value="Packing run · <?=e($now->format('j M Y'))?>" required></label><label>Run owner<input name="owner" maxlength="80" placeholder="Jay or Tony"></label><label>Run notes<textarea name="run_notes" maxlength="2000" rows="2" placeholder="Instructions for this packing run"></textarea></label></div>
 <div class="pc-selection-bar"><div><strong id="pc-selected" aria-live="polite">0 orders selected</strong><small>Selection is kept when filtering.</small></div><button type="button" class="quiet" id="pc-select-visible">Select visible</button><button type="button" class="quiet" id="pc-clear">Clear selection</button></div>
 <div class="pc-order-list">
 <?php foreach($pcCandidates as $o):$oid=(int)$o['id'];$claimed=(int)($pcClaims[$oid]??0);$lines=$pcItems[$oid]??[];$search=$o['customer'].' '.($o['recipient_name']??'').' ANK-'.$oid.' ANK-'.str_pad((string)$oid,4,'0',STR_PAD_LEFT).' '.implode(' ',array_column($lines,'name'));?>
  <article class="pc-order" data-pc-order data-search="<?=e(mb_strtolower($search))?>" data-source="<?=(int)$o['back_order']===1?'back':'active'?>" data-assignee="<?=e($o['assigned_to']?:'__unassigned')?>">
   <label class="pc-choice"><input type="checkbox" name="order_ids[]" value="<?=$oid?>" <?=($claimed||!$lines)?'disabled':''?>><span class="pc-order-main"><strong><?=e(orderDisplayName($o))?></strong><span>ANK-<?=str_pad((string)$oid,4,'0',STR_PAD_LEFT)?> · <?=(int)$o['back_order']===1?'Back order':'Active order'?> · <?=e($o['assigned_to']?:'Unassigned')?></span><small>Ordered <?=e(substr($o['created'],0,10))?> · <?=e($o['status'])?><?= $o['scheduled_delivery_date']!==''?' · Delivery '.e($o['scheduled_delivery_date']):''?></small>
    <?php foreach(ankhPackingCentreLines($lines) as $line):?><small><?=(int)$line['quantity']?> × <?=e($line['name'])?><?=$line['presentation']!==''?' · '.e($line['presentation']):''?></small><?php endforeach;?>
    <?php if(!$lines):?><small>No order lines — review this order first.</small><?php endif;?>
   </span></label>
   <?php if($claimed):?><a class="pc-claim" href="?view=packing&amp;tab=runs&amp;run=<?=$claimed?>">In run #<?=$claimed?> →</a><?php endif;?>
  </article>
 <?php endforeach;?>
 </div><p id="pc-empty" class="muted" <?=count($pcCandidates)?'hidden':''?>>No orders match these filters.</p>
 <div class="pc-save"><button type="submit" id="pc-save" disabled>Save packing run</button><p class="muted">Saving a run leaves stock, payments and order statuses unchanged.</p></div>
 </form></section>
<?php endif;?>
 <section class="pc-panel" id="pc-runs"><h2>Saved packing runs</h2><p class="muted">Open runs reserve their orders until you release an order, finish or cancel the run.</p><div class="pc-saved-list">
 <?php foreach($pcRuns as $r):?><a href="?view=packing&amp;tab=runs&amp;run=<?=(int)$r['id']?>"><div><strong><?=e($r['name'])?></strong><small>#<?=(int)$r['id']?> · <?=e($r['owner']?:'Unassigned')?> · <?=(int)$r['order_count']?> orders · <?=e(substr($r['created'],0,10))?></small></div><span><?=e(ucfirst($r['state']))?> →</span></a><?php endforeach;?>
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
