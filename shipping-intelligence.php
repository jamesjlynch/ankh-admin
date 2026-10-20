<?php
/** Shipping Intelligence: view included from authenticated index.php. All figures are planning estimates. */
$shippingEvents=[
 ['date'=>'2026-11-11','end'=>'2026-11-11','name'=>"Singles' Day",'detail'=>'Parcel volumes may rise during the shopping festival.','risk'=>'medium','status'=>'seasonal'],
 ['date'=>'2026-11-23','end'=>'2026-11-30','name'=>'Black Friday / Cyber Monday','detail'=>'UK parcel and international freight peak.','risk'=>'medium','status'=>'seasonal'],
 ['date'=>'2026-12-15','end'=>'2026-12-28','name'=>'Christmas freight peak','detail'=>'UK couriers and holiday delivery constraints.','risk'=>'high','status'=>'seasonal'],
 ['date'=>'2027-01-01','end'=>'2027-01-01','name'=>"New Year's Day",'detail'=>'Public holiday; check individual carrier schedules.','risk'=>'medium','status'=>'public holiday'],
 ['date'=>'2027-01-15','end'=>'2027-03-07','name'=>'Chinese New Year disruption window','detail'=>'Conservative factory, collection and backlog planning window. Lunar New Year is 6 February; supplier shutdown dates must be confirmed.','risk'=>'critical','status'=>'estimated window'],
 ['date'=>'2027-03-26','end'=>'2027-03-29','name'=>'UK Easter bank holidays','detail'=>'Good Friday and Easter Monday may interrupt UK deliveries.','risk'=>'medium','status'=>'public holiday'],
 ['date'=>'2027-04-03','end'=>'2027-04-05','name'=>'Qingming Festival','detail'=>'Anticipated holiday period; dates subject to the official Chinese timetable.','risk'=>'medium','status'=>'provisional'],
 ['date'=>'2027-05-01','end'=>'2027-05-05','name'=>'Labour Day planning window','detail'=>'Provisional impact period; verify official closure dates.','risk'=>'medium','status'=>'provisional']
];
$shipCalendarDefaults=$shippingEvents;$shipEditsRaw=$db->query("SELECT value FROM settings WHERE key='shipping_calendar_edits'")->fetchColumn();
$shipEdits=is_string($shipEditsRaw)?json_decode($shipEditsRaw,true):[];
if(!is_array($shipEdits))$shipEdits=[];
$shippingEvents=[];
foreach($shipCalendarDefaults as $idx=>$default){
 $id='default-'.$idx;$override=$shipEdits[$id]??[];
 if(!empty($override['deleted']))continue;
 $shippingEvents[]=array_replace($default,is_array($override)?$override:[],['id'=>$id]);
}
foreach($shipEdits as $id=>$event){
 if(!str_starts_with((string)$id,'custom-')||!is_array($event)||!empty($event['deleted']))continue;
 if(!isset($event['date'],$event['end'],$event['name'],$event['risk']))continue;
 $shippingEvents[]=$event+['id'=>$id,'detail'=>'','status'=>'manually managed'];
}
usort($shippingEvents,static fn($a,$b)=>strcmp($a['date'],$b['date']));
$shipToday=new DateTimeImmutable('today',new DateTimeZone('Europe/London'));
$shipUpcoming=array_values(array_filter($shippingEvents,static fn($event)=>$event['end'] >= $shipToday->format('Y-m-d')));
$shipNext=$shipUpcoming[0]??null;
foreach($shipUpcoming as $ev){if($ev['risk']==='critical'){$shipNext=$ev;break;}}
$shipDays=$shipNext ? max(0,(int)$shipToday->diff(new DateTimeImmutable($shipNext['date']))->format('%r%a')) : 0;
$shipProtectionDays=isset($_GET['cover'])&&in_array((int)$_GET['cover'],[21,28,35,42,56],true)?(int)$_GET['cover']:42;
$shipLookback=in_array((int)($_GET['demand_days']??30),[30,60,90],true)?(int)($_GET['demand_days']??30):30;
$shipRows=[];
foreach($reportTrackedStock as $sp){
 $pid=(int)$sp['id'];$available=max(0,(int)$sp['stock_qty']);
 // stock_qty is already net of reservations. Never deduct reserved stock twice.
 $recent=max(0,(int)($reorderSalesByProduct[$pid]??0));
 $recurring=max(0,(int)($reorderRecurringByProduct[$pid]??0));
 $target=(int)ceil($recent*$shipProtectionDays/$shipLookback)+$recurring;
 $gap=max(0,$target-$available);
 $pack=max(1,(int)($sp['supplier_pack_size']??10));
 $units=$gap>0?(int)ceil($gap/$pack)*$pack:0;
 $shipRows[]=['name'=>(string)$sp['name'],'available'=>$available,'recent'=>$recent,'recurring'=>$recurring,'target'=>$target,'gap'=>$gap,'order'=>$units,'pack'=>$pack,'id'=>$pid];
}
usort($shipRows,static fn($a,$b)=>$b['gap']<=>$a['gap'] ?: strcmp($a['name'],$b['name']));
$shipAtRisk=count(array_filter($shipRows,static fn($r)=>$r['gap']>0));
?>
<style>
.ship-page{--ship-gold:#dfb666;--ship-muted:#a2a3a7;color:#f4f0e9;max-width:1120px;margin:auto}
.ship-page *{box-sizing:border-box}.ship-page .ship-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px}.ship-page h1{font-size:clamp(29px,5vw,39px);margin:5px 0 4px;letter-spacing:-1.3px}.ship-page h2{font-size:19px;font-weight:700;margin:0 0 13px}
.ship-page .ship-kicker{font-size:11px;font-weight:700;letter-spacing:1.7px;color:var(--ship-gold);text-transform:uppercase}
.ship-page .ship-sub{color:var(--ship-muted);font-size:14px;line-height:1.5;margin:0}
.ship-page .ship-card{background:linear-gradient(150deg,#202123,#171819);border:1px solid #323335;border-radius:19px;padding:22px;margin-bottom:15px;box-shadow:0 8px 25px #0002}
.ship-page .ship-highlight{background:linear-gradient(125deg,#383020,#1d1d1c 70%);border-color:#92723d}
.ship-page .ship-topline{display:flex;align-items:center;gap:10px;justify-content:space-between;flex-wrap:wrap;margin-bottom:12px}
.ship-page .ship-pill{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:20px;font-size:12px;border:1px solid #555;color:#ddd}
.ship-page .ship-pill.critical,.ship-page .ship-pill.high{border-color:#864c46;background:#4a2624;color:#ffd0c8}
.ship-page .ship-pill.medium{border-color:#937244;background:#493821;color:#f4d6a3}
.ship-page .ship-pill.covered{border-color:#347c61;background:#203d32;color:#b5efd4}
.ship-page .ship-primary{font-size:clamp(24px,5vw,35px);font-weight:750;margin:8px 0}.ship-page .ship-meta{display:flex;gap:12px;align-items:center;flex-wrap:wrap;color:#dbd2be;font-size:13px}
.ship-page .ship-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.ship-page .ship-num{font-size:29px;font-weight:750;margin:9px 0 3px}
.ship-page .ship-row{display:flex;gap:13px;align-items:flex-start;padding:14px 0;border-top:1px solid #323436}.ship-page .ship-date{width:74px;flex:none;text-align:center;background:#292a2a;border:1px solid #37383a;border-radius:11px;padding:11px 5px;color:#efc77f;font-weight:750;font-size:12px}
.ship-page .ship-row-info{flex:1;min-width:0}.ship-page .ship-row-info strong{display:block;font-size:14px;margin-bottom:5px}.ship-page .ship-row-info small{display:block;line-height:1.5;color:var(--ship-muted)}
.ship-page .ship-event-badges{display:flex;gap:5px;flex-wrap:wrap;margin-top:7px}.ship-page .ship-event-badges span{font-size:10px;color:#b9babd;text-transform:capitalize}
.ship-page .ship-bar{height:7px;border-radius:10px;background:#303133;overflow:hidden;margin:8px 0}.ship-page .ship-bar span{height:100%;display:block;background:#d9aa5a;border-radius:10px}
.ship-page .ship-action{color:#1d180f;background:#dfb666;padding:12px 15px;border-radius:12px;font-weight:750;text-decoration:none;display:inline-flex;justify-content:center;align-items:center}
.ship-page .ship-secondary{border:1px solid #444;background:#262729;color:#e8e3d8}
.ship-page .ship-form{display:flex;align-items:end;gap:12px;flex-wrap:wrap}.ship-page .ship-form label{min-width:130px;flex:1;margin:0;color:#c7c6c2}.ship-page .ship-form select{margin-top:8px}
.ship-page .ship-form button{min-height:48px}.ship-page .ship-empty{color:var(--ship-muted);padding:12px 0}.ship-page .ship-foot{color:var(--ship-muted);font-size:12px;line-height:1.6;margin:10px 0}
@media(max-width:600px){.ship-page .ship-card{padding:17px}.ship-page .ship-head{align-items:start}.ship-page .ship-grid{gap:9px}.ship-page .ship-grid .ship-card{padding:15px}.ship-page .ship-form button{width:100%}}
.ship-page .ship-editor{border-top:1px solid #39393a;padding:4px 0}.ship-page .ship-editor summary{padding:15px 2px;min-height:54px;display:flex;justify-content:space-between;align-items:center;gap:10px;font-weight:650;font-size:14px;cursor:pointer}.ship-page .ship-editor summary small{font-size:12px;color:#aaa;display:block;font-weight:400;margin-top:4px}.ship-page .ship-edit-form{padding:12px 0}.ship-page .ship-edit-form label{margin:0 0 13px;font-size:13px;color:#d6d3cd}.ship-page .ship-two{display:grid;grid-template-columns:1fr 1fr;gap:10px}.ship-page .ship-remove{background:#352425;color:#ffbdba;border:1px solid #6a4545;margin:0 0 14px;min-height:45px}@media(max-width:420px){.ship-page .ship-two{grid-template-columns:1fr}}\n.ship-page .ship-event-link{text-decoration:none;color:inherit;align-items:center;min-height:86px}.ship-page .ship-event-link:active{background:#262626}.ship-page .ship-manage-link{width:100%;margin-top:20px}.ship-page .ship-full-notes{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.8;font-size:15px;color:#e7e3db;padding:17px;background:#141515;border-radius:12px;margin-bottom:23px}.ship-page .ship-detail-title{font-size:27px;margin:17px 0}.ship-page .ship-date-span{padding:14px 0 24px;color:#e4cb91}.ship-page .ship-back{color:#dfb666;text-decoration:none;font-size:14px}.ship-page .ship-event-link .ship-row-info{display:block}.ship-page .ship-edit-form textarea{min-height:175px;resize:vertical}\n</style>
<div class="ship-page">
 <div class="ship-head"><div><div class="ship-kicker">Operations / Shipping</div><h1>Shipping Intelligence</h1><p class="ship-sub">China → United Kingdom · supplier disruption planning</p></div><a class="ship-action ship-secondary" href="?view=more" aria-label="Back to More">← More</a></div>
 <?php if($shipNext):?>
 <section class="ship-card ship-highlight"><div class="ship-topline"><span class="ship-kicker">Next major disruption</span><span class="ship-pill <?=e($shipNext['risk'])?>"><?=e(ucfirst($shipNext['risk']))?> risk</span></div>
 <div class="ship-primary"><?=e($shipNext['name'])?></div><p class="ship-sub"><?=e(date('j F Y',strtotime($shipNext['date'])))?> – <?=e(date('j F Y',strtotime($shipNext['end'])))?></p>
 <p class="ship-foot"><?=e($shipNext['detail'])?></p><div class="ship-meta"><strong><?=$shipDays?> days until planning-window start</strong><span class="ship-pill"><?=e($shipNext['status'])?></span></div></section>
 <?php endif;?>
 <div class="ship-grid"><section class="ship-card"><span class="ship-sub">Upcoming events</span><div class="ship-num"><?=count($shipUpcoming)?></div><span class="ship-sub">On your shipping calendar</span></section><section class="ship-card"><span class="ship-sub">Potential stock gaps</span><div class="ship-num"><?=$shipAtRisk?></div><span class="ship-sub">Based on <?=$shipProtectionDays?>-day planning cover</span></section></div>
 <section class="ship-card"><div class="ship-topline"><h2>Stock protection forecast</h2><span class="ship-pill">Live app stock</span></div><p class="ship-sub">Uses current available stock and recent delivered sales. Recurring demand is added separately; check the estimates before buying.</p>
 <form class="ship-form" method="get"><input type="hidden" name="view" value="shipping"><label>Stock coverage<select name="cover"><?php foreach([21,28,35,42,56] as $d):?><option value="<?=$d?>" <?=$d===$shipProtectionDays?'selected':''?>><?=$d?> days</option><?php endforeach;?></select></label><label>Sales history<select name="demand_days"><?php foreach([30,60,90] as $d):?><option value="<?=$d?>" <?=$d===$shipLookback?'selected':''?>><?=$d?> days</option><?php endforeach;?></select></label><button type="submit">Recalculate</button></form>
 <?php if(!$shipRows):?><p class="ship-empty">No tracked products yet. Enable stock tracking in Products to see forecasts here.</p><?php endif;?>
 <?php foreach(array_slice($shipRows,0,30) as $sr):$risk=$sr['gap']>0?($sr['available']===0?'high':'medium'):'covered';?>
 <div class="ship-row"><div class="ship-row-info"><div class="ship-topline"><strong><?=e($sr['name'])?></strong><span class="ship-pill <?=$risk?>"><?=$sr['gap']>0?'Review reorder':'Covered'?></span></div><small><?=$sr['available']?> available · <?=$sr['target']?> estimated required · <?=$sr['recent']?> sold in <?=$shipLookback?> days<?php if($sr['recurring']):?> · <?=$sr['recurring']?> upcoming recurring<?php endif;?></small><div class="ship-bar"><span style="width:<?=min(100,$sr['target']>0?100*$sr['available']/$sr['target']:100)?>%"></span></div><small><?=$sr['order']>0?'Consider ordering '.$sr['order'].' units (pack size '.$sr['pack'].')':'No additional units indicated'?></small></div></div>
 <?php endforeach;?>
 <a class="ship-action" href="?view=reorder">Open full Reorder Planner →</a><p class="ship-foot">Planning estimates only. Available stock is already net of reservations; do not subtract reserved quantities twice. Incoming stock and expiry dates must be checked before ordering. Factory shutdown dates and customs holds are not live-tracked.</p></section>
 <?php $shipMode=in_array((string)($_GET['mode']??''),['manage','event'],true)?(string)$_GET['mode']:'overview';$shipSelectedId=(string)($_GET['event']??'');$shipSelected=null;foreach($shippingEvents as $candidate){if($candidate['id']===$shipSelectedId)$shipSelected=$candidate;} ?>
<?php if($shipMode==='event'):?>
<section class="ship-card ship-detail">
<a class="ship-back" href="?view=shipping">← Back to calendar</a>
<?php if($shipSelected):?>
<div class="ship-kicker">Shipping disruption details</div><h2 class="ship-detail-title"><?=e($shipSelected['name'])?></h2>
<div class="ship-topline"><span class="ship-pill <?=e($shipSelected['risk'])?>"><?=e(ucfirst($shipSelected['risk']))?> risk</span><span class="ship-pill"><?=e($shipSelected['status']??'manual')?></span></div>
<div class="ship-date-span">📅 <?=e(date('j F Y',strtotime($shipSelected['date'])))?> – <?=e(date('j F Y',strtotime($shipSelected['end'])))?></div>
<h2>Information &amp; shipping impact</h2><div class="ship-full-notes"><?=e($shipSelected['detail']??'No additional details recorded yet.')?></div>
<a class="ship-action ship-secondary" href="?view=shipping&amp;mode=manage&amp;event=<?=rawurlencode($shipSelected['id'])?>">Edit this event →</a>
<?php else:?><h2>Event not found</h2><p class="ship-sub">It may have been removed.</p><?php endif;?></section>
<?php elseif($shipMode==='manage'):?>
<section class="ship-card" id="ship-manage">
<div class="ship-topline"><h2>Manage disruption calendar</h2><a class="ship-back" href="?view=shipping">← Done</a></div>
<p class="ship-sub">Edit events individually or add a supplier shutdown. Your changes are shared across both accounts.</p>
<?php if(isset($_GET['calendar'])&&$_GET['calendar']==='saved'):?><p class="success">Event changes saved successfully.</p><?php endif;?>
<?php if(!empty($error)):?><p class="error"><?=e($error)?></p><?php endif;?>
<details class="ship-editor" <?=$shipSelectedId==='new'?'open':''?>><summary>＋ Add new event</summary><form method="post" action="?view=shipping&amp;mode=manage" class="ship-edit-form">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="ship_save"><input type="hidden" name="event_id" value="new">
<label>Event name<input required maxlength="100" name="name" placeholder="Supplier factory closure"></label><div class="ship-two"><label>Start date<input type="date" required name="date"></label><label>End date<input type="date" required name="end"></label></div>
<label>Disruption risk<select name="risk"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Very high</option></select></label>
<label>Full information<textarea name="detail" maxlength="5000" rows="8" placeholder="What is affected, supplier contact, collection deadlines, expected delays and contingency instructions"></textarea></label><button type="submit">Save new event</button></form></details>
<?php foreach($shippingEvents as $editEvent):?>
<details class="ship-editor" <?=$shipSelectedId===$editEvent['id']?'open':''?>><summary><span><?=e($editEvent['name'])?><small><?=e(date('j M Y',strtotime($editEvent['date'])))?> – <?=e(date('j M Y',strtotime($editEvent['end'])))?></small></span><span class="ship-pill <?=e($editEvent['risk'])?>">Edit</span></summary>
<form method="post" action="?view=shipping&amp;mode=manage" class="ship-edit-form">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="ship_save"><input type="hidden" name="event_id" value="<?=e($editEvent['id'])?>">
<label>Event name<input required maxlength="100" name="name" value="<?=e($editEvent['name'])?>"></label><div class="ship-two"><label>Start date<input required type="date" name="date" value="<?=e($editEvent['date'])?>"></label><label>End date<input required type="date" name="end" value="<?=e($editEvent['end'])?>"></label></div>
<label>Risk<select name="risk"><?php foreach(['low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Very high'] as $rv=>$rl):?><option value="<?=e($rv)?>" <?=$editEvent['risk']===$rv?'selected':''?>><?=e($rl)?></option><?php endforeach;?></select></label>
<label>Full information<textarea name="detail" maxlength="5000" rows="8"><?=e($editEvent['detail']??'')?></textarea></label><button type="submit">Save changes</button></form>
<form method="post" action="?view=shipping&amp;mode=manage" onsubmit="return confirm('Remove this disruption from the calendar?')"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="ship_delete"><input type="hidden" name="event_id" value="<?=e($editEvent['id'])?>"><button class="ship-remove" type="submit">Remove event</button></form></details>
<?php endforeach;?>
<details class="ship-editor"><summary>Restore default events</summary><p class="ship-sub">This will remove your custom events and revert all edits.</p><form method="post" action="?view=shipping&amp;mode=manage" onsubmit="return confirm('Reset all calendar changes?')"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="ship_reset"><button class="ship-remove" type="submit">Restore original calendar</button></form></details>
</section>
<?php else:?>
<section class="ship-card" id="ship-calendar"><h2>Upcoming holidays &amp; disruptions</h2><p class="ship-sub">Tap any event to read its full information.</p>
<?php foreach($shipUpcoming as $ev):?><a class="ship-row ship-event-link" href="?view=shipping&amp;mode=event&amp;event=<?=rawurlencode($ev['id'])?>"><span class="ship-date"><?=e(strtoupper(date('d M',strtotime($ev['date']))))?></span><span class="ship-row-info"><span class="ship-topline"><strong><?=e($ev['name'])?></strong><span class="ship-pill <?=e($ev['risk'])?>"><?=e(ucfirst($ev['risk']))?></span></span><small><?=e(mb_strimwidth((string)($ev['detail']??''),0,110,'…'))?></small><span class="ship-event-badges"><span><?=e($ev['status'])?></span><span> · through <?=e(date('j M Y',strtotime($ev['end'])))?></span></span></span><span aria-hidden="true">›</span></a><?php endforeach;?>
<?php if(!$shipUpcoming):?><p class="ship-empty">No upcoming events. Add one in Calendar Management.</p><?php endif;?>
<p class="ship-foot">Planning estimates are not confirmed supplier closure dates.</p>
<a class="ship-action ship-secondary ship-manage-link" href="?view=shipping&amp;mode=manage">✎ Edit disruption calendar →</a></section>
<?php endif;?>
<section class="ship-card"><h2>Supplier &amp; shipment tools</h2><p class="ship-sub">Keep purchasing and stock receipts within your existing system — no duplicated order records.</p><p style="display:flex;flex-wrap:wrap;gap:9px;margin-top:15px"><a class="ship-action" href="?view=supplier">Supplier Centre →</a><a class="ship-action ship-secondary" href="?view=stock&amp;section=incoming">Incoming stock →</a><a class="ship-action ship-secondary" href="?view=supplies">Business Supplies →</a></p></section>
</div>
