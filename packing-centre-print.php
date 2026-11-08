<?php
if(!($auth??false)||!($db instanceof PDO)){http_response_code(403);exit('Forbidden');}
$printKind=(string)($_GET['print']??'');
$printRun=ankhPackingCentreRun($db,(int)($_GET['run']??0));
if(!$printRun||!in_array($printKind,['pick','slips','labels'],true)){http_response_code(404);exit('Print view not found.');}
$printOrders=$printRun['orders'];$printTotals=ankhPackingCentreTotals($printOrders);
$printTitle=['pick'=>'Combined picking sheet','slips'=>'Individual packing slips','labels'=>'Envelope name labels'][$printKind];
$printRef=static fn(array $o):string=>'ANK-'.str_pad((string)$o['id'],4,'0',STR_PAD_LEFT);
$printName=static fn(array $o):string=>trim((string)($o['recipient_name']??''))?:$o['customer'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?=e($printTitle.' · '.$printRun['name'])?></title>
<style>
*{box-sizing:border-box}body{font:14px/1.5 Arial,sans-serif;color:#111;background:#e9e9e9;margin:0}main{max-width:210mm;margin:20px auto;background:#fff;padding:14mm}h1{font-size:26px;margin:0 0 5px}h2{font-size:22px;margin:12px 0}h3{margin:16px 0 8px}.muted{color:#555}.toolbar{display:flex;flex-wrap:wrap;gap:16px;align-items:center;padding:16px;background:#181a1b;color:white}.toolbar a{color:#ecd3a0}.toolbar button{padding:12px 20px;border:0;border-radius:8px;background:#dcc18a;color:#111;font:inherit;cursor:pointer}.toolbar p{margin:0}header{border-bottom:2px solid #111;padding-bottom:14px;margin-bottom:18px}.meta{display:flex;justify-content:space-between;gap:16px}.warning{border:2px solid #555;padding:10px;font-weight:bold;white-space:normal}.notes{white-space:pre-wrap;overflow-wrap:anywhere}table{border-collapse:collapse;width:100%;margin:14px 0}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #bbb;overflow-wrap:anywhere}thead{display:table-header-group}tr{break-inside:avoid}.check{display:inline-block;width:15px;height:15px;border:1.5px solid #111;vertical-align:middle;margin-right:8px}.slip{break-after:page}.slip:last-child{break-after:auto}.details{white-space:pre-line;overflow-wrap:anywhere}.checks{display:flex;flex-wrap:wrap;gap:16px;margin:25px 0}.signature{border-top:1px solid #999;padding-top:12px;margin-top:24px}.labels{display:grid;grid-template-columns:1fr 1fr;gap:8mm}.label{border:1px dashed #777;min-height:40mm;padding:8mm;break-inside:avoid;overflow-wrap:anywhere}.label strong{font-size:24px;display:block}.label p{margin:8px 0 0}.foot{font-size:11px;margin-top:20px;color:#555}@page{size:A4;margin:12mm}@media print{body{background:white}.toolbar{display:none}main{margin:0;padding:0;max-width:none}.labels{display:block}.label{display:inline-block;vertical-align:top;width:47%;margin:0 2% 8mm 0}a{color:#111;text-decoration:none}.slip{min-height:0}}@media screen and (max-width:650px){main{margin:0;padding:18px}.labels{grid-template-columns:1fr}.meta{flex-direction:column}th,td{padding:8px 4px;font-size:12px}}

/* Keep long reference lists inside their own column on A4 and screen previews. */
.picking-table{table-layout:fixed;min-width:640px}.picking-table-wrap{overflow-x:auto}
.picking-table th,.picking-table td{vertical-align:top;overflow-wrap:normal;word-break:normal}
.picking-table th:nth-child(1),.picking-table th:nth-child(3),.picking-table th:nth-child(4),.picking-table td:nth-child(3),.picking-table td:nth-child(4){white-space:nowrap}
.picking-table th:nth-child(4),.picking-table td:nth-child(4){text-align:center;font-variant-numeric:tabular-nums}
.picking-table td:nth-child(4){font-size:16px;font-weight:bold}
.picking-table .order-ref{display:inline-block;white-space:nowrap}
@media print{.picking-table{min-width:0}.picking-table-wrap{overflow:visible}}
</style></head><body>
<div class="toolbar"><button type="button" onclick="window.print()">Print / Save as PDF</button><a href="?view=packing&amp;tab=runs&amp;run=<?=(int)$printRun['id']?>">Back to run</a><p>A4<?= $printKind==='labels'?' · Cut-out name labels; not a courier shipping label':''?> · Printing leaves order statuses unchanged.</p></div>
<main>
<?php if($printKind==='pick'):?>
<header><p>ANKH · PACKING CENTRE · RUN #<?=(int)$printRun['id']?></p><h1>Combined picking sheet</h1><h2><?=e($printRun['name'])?></h2><div class="meta"><span>Owner: <?=e($printRun['owner']?:'Unassigned')?></span><span><?=count($printOrders)?> orders · Printed <?=e(gmdate('d M Y H:i'))?> UTC</span></div></header>
<?php if($printRun['notes']!==''):?><p class="notes"><?=e($printRun['notes'])?></p><?php endif;?>
<p>Pen selections are packed in cartridges. Cartridge refills of the same strength are combined. Pen devices are listed separately and existing pen product lines are counted once.</p>
<?php foreach($printOrders as $o):if($o['changed']||!ankhPackingCentreEligible($o)):?><p class="warning"><?=e($printRef($o))?>: <?=!ankhPackingCentreEligible($o)?'NOW DELIVERED OR CANCELLED — review before picking.':'Changed since run creation — check the current contents below.'?></p><?php endif;endforeach;?>
<?php foreach($printOrders as $o):if($penNote=ankhPackingCentrePenNote($o['lines'])):?><p class="warning"><?=e($printRef($o).': '.$penNote)?></p><?php endif;endforeach;?>
<?php if($printRun['state']!=='open'):?><p class="warning"><?=e(ucfirst($printRun['state']))?> run — historical reference. Details below are current.</p><?php endif;?>
<div class="picking-table-wrap"><table class="picking-table"><colgroup><col style="width:9%"><col style="width:28%"><col style="width:19%"><col style="width:10%"><col style="width:34%"></colgroup><thead><tr><th>Picked</th><th>Product / strength</th><th>Presentation</th><th>Units</th><th>Order references</th></tr></thead><tbody>
<?php foreach($printTotals as $total):?><tr><td><span class="check"></span></td><td><?=e($total['name'])?></td><td><?=e($total['presentation']?:'Not specified')?></td><td><strong><?=$total['quantity']?></strong></td><td><?php foreach(array_keys($total['orders']) as $refIndex=>$refId):?><?=$refIndex>0?', ':''?><span class="order-ref">ANK-<?=e(str_pad((string)$refId,4,'0',STR_PAD_LEFT))?></span><?php endforeach;?></td></tr><?php endforeach;?></tbody></table></div>
<h3>Orders in this run</h3><table><thead><tr><th>Order</th><th>Recipient</th><th>List</th><th>Planned delivery</th></tr></thead><tbody><?php foreach($printOrders as $o):?><tr><td><?=e($printRef($o))?></td><td><?=e($printName($o))?></td><td><?=(int)$o['back_order']===1?'Back order':'Active'?></td><td><?=e($o['scheduled_delivery_date']?:'Not arranged')?></td></tr><?php endforeach;?></tbody></table>
<p class="signature">Picked by: ____________________ Date: ____________________</p>
<?php elseif($printKind==='slips'):foreach($printOrders as $o):?>
<section class="slip"><header><p>ANKH · PACKING NOTE · RUN #<?=(int)$printRun['id']?></p><div class="meta"><h1><?=e($printRef($o))?></h1><span>Order date: <?=e(substr($o['created'],0,10))?></span></div><h2><?=e($printName($o))?></h2><?php if($printName($o)!==$o['customer']):?><p>Ordered by: <?=e($o['customer'])?></p><?php endif;?></header>
<?php if($o['changed']||!ankhPackingCentreEligible($o)||$printRun['state']!=='open'):?><p class="warning">Review before packing: <?=!ankhPackingCentreEligible($o)?'order is now delivered or cancelled.':($printRun['state']!=='open'?'this run is closed.':'details changed since this run was created.')?></p><?php endif;?>
<div class="meta"><div><strong>Delivery details</strong><p class="details"><?=e($o['address']?:'Address not recorded')?><br><?=e($o['phone']?:'Phone not recorded')?></p></div><div><p><?=(int)$o['back_order']===1?'BACK ORDER':'ACTIVE ORDER'?> · <?=e($o['status'])?></p><p>Method: <?=e($o['delivery_method']?:'Not specified')?><br>Planned delivery: <?=e($o['scheduled_delivery_date']?:'Not arranged')?></p></div></div>
<table><thead><tr><th>Checked</th><th>Contents</th><th>Presentation</th><th>Quantity</th></tr></thead><tbody><?php foreach(ankhPackingCentreLines($o['lines']) as $line):?><tr><td><span class="check"></span></td><td><?=e($line['name'])?></td><td><?=e($line['presentation']?:'Not specified')?></td><td><?=(int)$line['quantity']?></td></tr><?php endforeach;?></tbody></table>
<?php if($penNote=ankhPackingCentrePenNote($o['lines'])):?><p class="warning"><?=e($penNote)?></p><?php endif;?>
<?php if($o['notes']!==''):?><h3>Order notes</h3><p class="notes"><?=e($o['notes'])?></p><?php endif;?>
<div class="checks"><span><span class="check"></span>Contents checked</span><span><span class="check"></span>Accessories checked</span><span><span class="check"></span>Name on envelope</span></div><p class="signature">Packed by: ____________________ Checked by: ____________________</p><p class="foot">Packing note · not an invoice · <?=e($printRun['name'])?> · Printed <?=e(gmdate('d M Y H:i'))?> UTC<br>Paper checks do not update the app. Confirm actual preparation, packaging and delivery separately.</p></section>
<?php endforeach;else:?>
<h1>Envelope name labels</h1><p class="muted"><?=e($printRun['name'])?> · Run #<?=(int)$printRun['id']?> · A4 cut-out sheet</p><div class="labels"><?php foreach($printOrders as $o):?><section class="label"><strong><?=e($printName($o))?></strong><p><?=e($printRef($o))?> · <?=(int)$o['back_order']===1?'Back order':'Active order'?></p><p>Run #<?=(int)$printRun['id']?><?= $printRun['owner']!==''?' · '.e($printRun['owner']):''?></p><?php if($o['changed']||!ankhPackingCentreEligible($o)||$printRun['state']!=='open'):?><p><strong style="font-size:12px">REVIEW ORDER / RUN BEFORE PACKING</strong></p><?php endif;?></section><?php endforeach;?></div>
<?php endif;?>
<?php if(!$printOrders):?><p>No orders in this run.</p><?php endif;?>
</main></body></html>
