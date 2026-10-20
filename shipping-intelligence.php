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
// Work from calendar dates, not a manually chosen number of cover days.
$shipRiskEvents=array_values(array_filter($shipUpcoming,static fn($event)=>in_array($event['risk'],['high','critical'],true)));
if(!$shipRiskEvents)$shipRiskEvents=$shipUpcoming;
$shipForecastEvent=$shipRiskEvents[0]??null;
foreach($shipUpcoming as $event){
 if((string)($_GET['forecast_event']??'')===(string)$event['id']){$shipForecastEvent=$event;break;}
}
// Keep the overview highlight aligned with the disruption used by the forecast.
$shipNext=$shipForecastEvent;
$shipDays=$shipNext?max(0,(int)$shipToday->diff(new DateTimeImmutable($shipNext['date'],$shipToday->getTimezone()))->format('%r%a')):0;
$shipLeadDays=7;          // The existing supplier lead-time assumption.
$shipRecoveryDays=7;      // Extra planning buffer; not a carrier guarantee.
$shipCoverEnd=$shipForecastEvent
 ? (new DateTimeImmutable($shipForecastEvent['end'],$shipToday->getTimezone()))->modify('+'.($shipLeadDays+$shipRecoveryDays).' days')
 : null;
$shipCoverageDays=$shipCoverEnd?max(1,(int)$shipToday->diff($shipCoverEnd)->format('%r%a')+1):0;
$shipLastOrderDate=$shipForecastEvent
 ? (new DateTimeImmutable($shipForecastEvent['date'],$shipToday->getTimezone()))->modify('-'.($shipLeadDays+$shipRecoveryDays).' days')
 : null;
$shipRecentSales=sc_recent_delivered($db,30);
$shipFutureRecurring=$shipCoverEnd?retaProjectionRange($retaForecastCycles,$shipToday,$shipCoverEnd,true):['stock'=>[]];
$shipRecurringByProduct=[];
$shipProductNameIds=[];
foreach($stockProducts as $product){
 if((int)($product['active']??0)!==1)continue;
 $key=strtolower(trim((string)$product['name']));
 $shipProductNameIds[$key]??=[];
 $shipProductNameIds[$key][]=(int)$product['id'];
}
foreach(($shipFutureRecurring['stock']??[]) as $name=>$qty){
 $ids=$shipProductNameIds[strtolower(trim((string)$name))]??[];
 if(count($ids)===1)$shipRecurringByProduct[$ids[0]]=($shipRecurringByProduct[$ids[0]]??0)+max(0,(int)$qty);
}
// Count only outstanding deliveries with a future, recorded ETA before the cover end.
// Late, undated and later orders need review and must not give false reassurance.
$shipOnTimeIncoming=[];$shipUncertainIncoming=[];
foreach($supplierItemRows as $item){
 if(in_array((string)$item['status'],['Received','Cancelled'],true))continue;
 $remaining=max(0,(int)$item['quantity']-(int)$item['received_qty']);
 if($remaining===0)continue;
 $id=(int)$item['product_id'];$eta=substr(trim((string)($item['expected_date']??'')),0,10);
 if($shipCoverEnd && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$eta) && $eta>$shipToday->format('Y-m-d') && $eta<=$shipCoverEnd->format('Y-m-d')){
  $shipOnTimeIncoming[$id]=($shipOnTimeIncoming[$id]??0)+$remaining;
 }else{$shipUncertainIncoming[$id]=($shipUncertainIncoming[$id]??0)+$remaining;}
}
$shipRows=[];$shipNeedsCount=0;$shipAtRisk=0;$shipOrderNow=0;
if($shipCoverEnd){
 foreach($stockProducts as $sp){
  if((int)($sp['active']??0)!==1)continue;
  $id=(int)$sp['id'];$tracked=(int)($sp['stock_tracking']??0)===1;
  $recent=max(0,(int)($shipRecentSales[$id]??0));
  $recurring=max(0,(int)($shipRecurringByProduct[$id]??0));
  $incoming=max(0,(int)($shipOnTimeIncoming[$id]??0));
  $uncertain=max(0,(int)($shipUncertainIncoming[$id]??0));
  if(!$tracked&&$recent===0&&$recurring===0&&$incoming===0&&$uncertain===0)continue;
  $net=(int)($sp['stock_jay_qty']??0)+(int)($sp['stock_tony_qty']??0)+(int)($sp['stock_unallocated_qty']??0);
  $available=$tracked?max(0,$net):null;
  // Existing fridge balances are already net of reservations. Do not deduct twice.
  $buffer=($recent>0||$recurring>0)?max(0,(int)($sp['low_stock_at']??0)):0;
  $expectedNonRecurring=(int)ceil($recent*$shipCoverageDays/30);
  $demand=$expectedNonRecurring+$recurring;
  $target=$demand+$buffer;
  $gap=$tracked?max(0,$target-$net-$incoming):null;
  $pack=max(1,(int)($sp['supplier_pack_size']??10));
  $suggestedUnits=$gap!==null&&$gap>0?(int)ceil($gap/$pack)*$pack:0;
  $trackingDate=substr((string)($sp['stock_tracking_since']??''),0,10);
  $historyDays=$trackingDate!==''&&$trackingDate>$shipToday->modify('-30 days')->format('Y-m-d')
   ? max(1,(int)(new DateTimeImmutable($trackingDate,$shipToday->getTimezone()))->diff($shipToday)->format('%r%a')+1) : 30;
  $lowHistory=$tracked&&$historyDays<14;
  $immediateShortage=$net<=0&&$demand>0;
  if(!$tracked){$state='uncounted';$shipNeedsCount++;}
  elseif($gap>0){
   $state=($shipLastOrderDate!==null&&$shipToday>=$shipLastOrderDate)||$immediateShortage?'order':'low';
   $shipAtRisk++;if($state==='order')$shipOrderNow++;
  }elseif($lowHistory||($recent===0&&$recurring===0&&$net<=0)){$state='review';}
  else{$state='covered';}
  $shipRows[]=[
   'id'=>$id,'name'=>(string)$sp['name'],'tracked'=>$tracked,'state'=>$state,
   'available'=>$available,'jay'=>(int)($sp['stock_jay_qty']??0),
   'tony'=>(int)($sp['stock_tony_qty']??0),'unallocated'=>(int)($sp['stock_unallocated_qty']??0),
   'recent'=>$recent,'recurring'=>$recurring,'incoming'=>$incoming,'uncertain'=>$uncertain,
   'demand'=>$demand,'target'=>$target,'gap'=>$gap,'pack'=>$pack,'order'=>$suggestedUnits,'limited_history'=>$lowHistory
  ];
 }
}
$shipRank=['order'=>0,'low'=>1,'uncounted'=>2,'review'=>3,'covered'=>4];
usort($shipRows,static fn($a,$b)=>($shipRank[$a['state']]<=>$shipRank[$b['state']])?:((int)($b['gap']??0)<=>(int)($a['gap']??0))?:strcmp($a['name'],$b['name']));
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
.ship-page .ship-editor{border-top:1px solid #39393a;padding:4px 0}.ship-page .ship-editor summary{padding:15px 2px;min-height:54px;display:flex;justify-content:space-between;align-items:center;gap:10px;font-weight:650;font-size:14px;cursor:pointer}.ship-page .ship-editor summary small{font-size:12px;color:#aaa;display:block;font-weight:400;margin-top:4px}.ship-page .ship-edit-form{padding:12px 0}.ship-page .ship-edit-form label{margin:0 0 13px;font-size:13px;color:#d6d3cd}.ship-page .ship-two{display:grid;grid-template-columns:1fr 1fr;gap:10px}.ship-page .ship-remove{background:#352425;color:#ffbdba;border:1px solid #6a4545;margin:0 0 14px;min-height:45px}@media(max-width:420px){.ship-page .ship-two{grid-template-columns:1fr}}\n.ship-page .ship-event-link{text-decoration:none;color:inherit;align-items:center;min-height:86px}.ship-page .ship-event-link:active{background:#262626}.ship-page .ship-manage-link{width:100%;margin-top:20px}.ship-page .ship-full-notes{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.8;font-size:15px;color:#e7e3db;padding:17px;background:#141515;border-radius:12px;margin-bottom:23px}.ship-page .ship-detail-title{font-size:27px;margin:17px 0}.ship-page .ship-date-span{padding:14px 0 24px;color:#e4cb91}.ship-page .ship-back{color:#dfb666;text-decoration:none;font-size:14px}.ship-page .ship-event-link .ship-row-info{display:block}.ship-page .ship-edit-form textarea{min-height:175px;resize:vertical}\n/* Isolated, accessible iPhone calendar cards: text links have no underline. */
.ship-page .ship-calendar-panel{background:#18191b;border-color:#2b2d31;padding:21px}
.ship-page .ship-calendar-intro{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:20px}
.ship-page .ship-calendar-intro h2{font-size:21px;line-height:1.25;margin:0 0 7px;font-weight:750;color:#f4efe2}
.ship-page .ship-calendar-intro .ship-sub{max-width:44ch;line-height:1.55}
.ship-page .ship-count{white-space:nowrap;font-size:11px;font-weight:650;color:#d6be83;background:#2e291e;border:1px solid #4f432b;padding:6px 9px;border-radius:30px}
.ship-page .ship-event-tiles{display:grid;grid-template-columns:1fr;gap:11px;margin-top:16px}
.ship-page a.ship-event-tile,.ship-page a.ship-event-tile:link,.ship-page a.ship-event-tile:visited{display:block;min-width:0;background:#222326;border:1px solid #373a3e;border-radius:17px;padding:16px 17px;color:#eeeae2!important;text-decoration:none!important;-webkit-text-decoration:none!important;box-shadow:0 2px 12px #0002;transition:border-color .15s,background-color .15s,transform .15s}
.ship-page a.ship-event-tile *,.ship-page a.ship-event-tile:visited *{text-decoration:none!important;-webkit-text-decoration:none!important}
.ship-page a.ship-event-tile:hover,.ship-page a.ship-event-tile:focus-visible{background:#28292c;border-color:#a1864d}
.ship-page a.ship-event-tile:active{background:#2f2b24;transform:scale(.995)}
.ship-page .ship-event-tile-top{display:flex;justify-content:space-between;align-items:center;gap:8px;min-height:26px;margin-bottom:12px}
.ship-page .ship-event-tile-date{display:block;color:#e8c77d;font-size:12px;font-weight:750;letter-spacing:.3px;white-space:nowrap}
.ship-page .ship-risk-chip{display:inline-flex;align-items:center;justify-content:center;flex:none;white-space:nowrap;border:1px solid #666;border-radius:100px;font-size:11px;line-height:1.2;font-weight:650;color:#dedede;padding:6px 9px}
.ship-page .ship-risk-chip.critical,.ship-page .ship-risk-chip.high{border-color:#85514d;color:#f4c4bd;background:#402725}
.ship-page .ship-risk-chip.medium{border-color:#8b714b;color:#f5d6a0;background:#403323}
.ship-page .ship-risk-chip.low{border-color:#437565;color:#b3ead5;background:#22372f}
.ship-page .ship-event-tile-title{display:flex;align-items:flex-start;gap:12px;justify-content:space-between}
.ship-page .ship-event-tile-title h3{font-size:18px;line-height:1.35;letter-spacing:-.25px;font-weight:750;color:#f8f4ea;margin:0;overflow-wrap:anywhere;min-width:0;flex:1}
.ship-page .ship-tile-chevron{font-size:25px;line-height:1;flex:none;color:#d9b56f;padding-top:1px}
.ship-page .ship-event-preview{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.55;max-height:3.1em;color:#b6b6b8;font-size:13px;margin:9px 0 13px;overflow-wrap:anywhere}
.ship-page .ship-event-tile-bottom{padding-top:10px;border-top:1px solid #383a3c;display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:11px;line-height:1.4;color:#a4a7a9}
.ship-page .ship-read-more{color:#e7c987;font-weight:700;white-space:nowrap}
.ship-page .ship-read-more span{margin-left:3px;font-size:13px}
.ship-page .ship-calendar-panel>.ship-foot{margin-top:17px}
.ship-page .ship-calendar-panel .ship-manage-link{margin-top:15px;display:flex;text-decoration:none!important}
@media(max-width:600px){.ship-page .ship-calendar-panel{padding:14px}.ship-page a.ship-event-tile{padding:15px}.ship-page .ship-calendar-intro{padding:4px 2px 0}.ship-page .ship-calendar-intro h2{font-size:20px}.ship-page .ship-count{font-size:10px}.ship-page .ship-event-tile-title h3{font-size:17px}.ship-page .ship-event-preview{font-size:13px}}
@media(prefers-reduced-motion:reduce){.ship-page a.ship-event-tile{transition:none}}

/* Automatic stock-cover planning */
.ship-page .ship-forecast-card .ship-topline{margin-bottom:12px}
.ship-page .ship-event-select{margin-top:17px;display:flex;align-items:end;gap:10px}
.ship-page .ship-event-select label{flex:1;min-width:0;margin:0;font-size:13px;color:#bbb}
.ship-page .ship-event-select select{margin-top:7px;min-width:0;font-size:15px}
.ship-page .ship-forecast-choose{flex:none;font-size:13px;padding:11px 13px;min-height:48px}
.ship-page .ship-forecast-dates{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:17px}
.ship-page .ship-forecast-dates>div{border:1px solid #393a3b;border-radius:12px;background:#242426;padding:12px 11px}
.ship-page .ship-forecast-dates small{font-size:11px;color:#b8b7b5;display:block;line-height:1.4}
.ship-page .ship-forecast-dates strong{font-size:13px;color:#f0d290;display:block;margin-top:7px;line-height:1.35}
.ship-page .ship-result-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:21px 0 14px}
.ship-page .ship-result-metrics>div{padding:14px 9px;border:1px solid #393a3b;background:#212223;border-radius:13px;text-align:center}
.ship-page .ship-result-metrics strong{font-size:25px;font-weight:750;display:block;color:#eed49b}
.ship-page .ship-result-metrics span{font-size:11px;line-height:1.35;color:#bebdb9;display:block;margin-top:4px}
.ship-page .ship-product-results{display:grid;gap:11px;margin:15px 0}
.ship-page .ship-product-card{background:#222326;border:1px solid #36383a;border-radius:15px;padding:16px;min-width:0}
.ship-page .ship-product-heading{display:flex;gap:12px;justify-content:space-between;align-items:flex-start;margin-bottom:12px}
.ship-page .ship-product-heading h3{margin:0;font-weight:750;line-height:1.35;font-size:17px;color:#f5f0e6;overflow-wrap:anywhere}
.ship-page .ship-status-tag{font-size:11px;font-weight:700;border-radius:25px;padding:6px 9px;line-height:1.2;white-space:nowrap;background:#303138;color:#eee;flex:none}
.ship-page .ship-status-tag.order{background:#542c28;color:#ffcebf}
.ship-page .ship-status-tag.low{background:#473820;color:#f4dbac}
.ship-page .ship-status-tag.covered{background:#243d31;color:#bbefcc}
.ship-page .ship-status-tag.uncounted,.ship-page .ship-status-tag.review{background:#34343a;color:#dad5dd}
.ship-page .ship-product-numbers{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}
.ship-page .ship-product-numbers span{background:#191a1d;border-radius:10px;padding:10px 7px;text-align:center}
.ship-page .ship-product-numbers small,.ship-page .ship-buy-row small{font-size:10px;line-height:1.3;display:block;color:#aaa}
.ship-page .ship-product-numbers strong{font-size:22px;color:#f1e8d2;font-weight:700;display:block;margin-top:6px}
.ship-page .ship-product-desc,.ship-page .ship-history-warning{color:#b5b6b8;font-size:12px;line-height:1.6;margin:12px 0 0}
.ship-page .ship-history-warning{color:#f3d39a}
.ship-page .ship-buy-row{display:flex;justify-content:space-between;gap:10px;border-top:1px solid #3b3b3e;margin-top:13px;padding-top:13px}
.ship-page .ship-buy-row>div{flex:1}
.ship-page .ship-buy-row strong{display:block;margin-top:4px;color:#f0ce8c;font-size:16px}
.ship-page .ship-covered-note{font-size:12px;color:#a8d8b9;margin:12px 0 0}
.ship-page .ship-inline-link{display:inline-block;margin-top:12px;color:#f0ce8c;text-decoration:none}
.ship-page .ship-forecast-empty{border:1px dashed #5a503a;border-radius:13px;background:#27241f;padding:18px;margin-top:16px}
.ship-page .ship-forecast-empty strong{font-size:15px}
.ship-page .ship-forecast-empty p{font-size:13px;color:#bfbcb5;line-height:1.6}
.ship-page .ship-reorder-link{margin-top:10px;text-decoration:none}
@media(max-width:600px){.ship-page .ship-event-select{display:block}.ship-page .ship-forecast-choose{width:100%;margin-top:10px}.ship-page .ship-forecast-dates{grid-template-columns:1fr 1fr}.ship-page .ship-forecast-dates>div:last-child{grid-column:1/-1}.ship-page .ship-product-card{padding:14px}.ship-page .ship-product-heading h3{font-size:16px}}
</style>
<div class="ship-page">
<?php if(!in_array((string)($_GET['mode']??''),['manage','event'],true)): ?>
 <div class="ship-head"><div><div class="ship-kicker">Operations / Shipping</div><h1>Shipping Intelligence</h1><p class="ship-sub">China → United Kingdom · supplier disruption planning</p></div><a class="ship-action ship-secondary" href="?view=more" aria-label="Back to More">← More</a></div>
 <?php if($shipNext):?>
 <section class="ship-card ship-highlight"><div class="ship-topline"><span class="ship-kicker">Next major disruption</span><span class="ship-pill <?=e($shipNext['risk'])?>"><?=e(ucfirst($shipNext['risk']))?> risk</span></div>
 <div class="ship-primary"><?=e($shipNext['name'])?></div><p class="ship-sub"><?=e(date('j F Y',strtotime($shipNext['date'])))?> – <?=e(date('j F Y',strtotime($shipNext['end'])))?></p>
 <p class="ship-foot"><?=e($shipNext['detail'])?></p><div class="ship-meta"><strong><?=$shipDays?> days until planning-window start</strong><span class="ship-pill"><?=e($shipNext['status'])?></span></div></section>
 <?php endif;?>
 <div class="ship-grid"><section class="ship-card"><span class="ship-sub">Upcoming events</span><div class="ship-num"><?=count($shipUpcoming)?></div><span class="ship-sub">On your shipping calendar</span></section><section class="ship-card"><span class="ship-sub">Potential stock gaps</span><div class="ship-num"><?=$shipAtRisk?></div><span class="ship-sub"><?=$shipNeedsCount?> without confirmed stock counts</span></section></div>
 <section class="ship-card ship-forecast-card">
  <div class="ship-topline"><h2>Stock protection forecast</h2><span class="ship-pill">Automatic</span></div>
  <?php if($shipForecastEvent&&$shipCoverEnd):?>
   <p class="ship-sub">Your stock and existing orders are compared with the next disruption, so you don't need to guess how many days to cover.</p>
   <form method="get" class="ship-event-select"><input type="hidden" name="view" value="shipping">
    <label>Disruption to plan around
    <select name="forecast_event" onchange="this.form.submit()">
     <?php foreach($shipUpcoming as $event):?><option value="<?=e($event['id'])?>" <?=$shipForecastEvent['id']===$event['id']?'selected':''?>><?=e($event['name'].' · '.date('j M',strtotime($event['date'])))?></option><?php endforeach;?>
    </select></label>
    <button type="submit" class="ship-forecast-choose">Update forecast</button>
   </form>
   <div class="ship-forecast-dates">
    <div><small>Disruption starts</small><strong><?=e(date('j M Y',strtotime($shipForecastEvent['date'])))?></strong></div>
    <div><small>Estimated cover until</small><strong><?=e($shipCoverEnd->format('j M Y'))?></strong></div>
    <div><small>Suggested order review by</small><strong><?=e($shipLastOrderDate->format('j M Y'))?></strong></div>
   </div>
   <p class="ship-foot">Cover through <?=e($shipCoverEnd->format('j M'))?> includes 7 days' normal supplier lead time and 7 days' estimated recovery buffer after the event. These are planning assumptions, not confirmed shutdown or arrival dates.</p>
   <div class="ship-result-metrics">
    <div><strong><?=$shipOrderNow?></strong><span>Order now</span></div>
    <div><strong><?=$shipAtRisk-$shipOrderNow?></strong><span>Running low</span></div>
    <div><strong><?=$shipNeedsCount?></strong><span>Need a count</span></div>
   </div>
   <?php if(!$shipRows):?><div class="ship-forecast-empty"><strong>No product records are available for a reliable forecast.</strong><p>Confirm fridge stock counts in Stock Control. The app will then automatically calculate what might be needed before the disruption.</p><a class="ship-action ship-secondary" href="?view=stock">Open Stock Control →</a></div><?php endif;?>
   <div class="ship-product-results">
   <?php foreach($shipRows as $sr):
     $state=$sr['state'];$stateText=['order'=>'Order now','low'=>'Running low','covered'=>'Covered','uncounted'=>'Needs stock count','review'=>'Review data'][$state]??'Review';
   ?>
   <article class="ship-product-card">
     <div class="ship-product-heading"><h3><?=e($sr['name'])?></h3><span class="ship-status-tag <?=e($state)?>"><?=e($stateText)?></span></div>
     <?php if(!$sr['tracked']):?>
       <p class="ship-product-desc">Stock has not been counted in the fridge system. A purchase estimate would be unreliable.</p>
       <a class="ship-inline-link" href="?view=stock">Confirm stock count →</a>
     <?php else:?>
       <div class="ship-product-numbers">
        <span><small>Available now</small><strong><?=$sr['available']?></strong></span>
        <span><small>Due in time</small><strong>+<?=$sr['incoming']?></strong></span>
        <span><small>Estimated needed</small><strong><?=$sr['target']?></strong></span>
       </div>
       <p class="ship-product-desc"><?=$sr['recent']?> units delivered in 30 days · <?=$sr['recurring']?> future recurring · <?=$sr['demand']?> forecast demand<?php if($sr['uncertain']>0):?> · <?=$sr['uncertain']?> incoming with unconfirmed/late ETA (excluded)<?php endif;?></p>
       <?php if($sr['gap']>0):?>
        <div class="ship-buy-row"><div><small>Estimated shortfall</small><strong><?=$sr['gap']?> units</strong></div><div><small>Supplier pack estimate</small><strong><?=$sr['order']?> units</strong></div></div>
       <?php elseif($state==='covered'):?>
        <p class="ship-covered-note">Existing available stock plus expected deliveries covers estimated demand.</p>
       <?php else:?><p class="ship-product-desc">Not enough recent sales history to confirm a reliable coverage estimate.</p><?php endif;?>
       <?php if($sr['limited_history']):?><p class="ship-history-warning">Stock tracking started recently. Treat the sales-based forecast as provisional.</p><?php endif;?>
     <?php endif;?>
   </article>
   <?php endforeach;?>
   </div>
  <?php else:?>
   <div class="ship-forecast-empty"><strong>No upcoming calendar disruptions found.</strong><p>Add an event to enable automatic stock coverage planning.</p><a class="ship-action ship-secondary" href="?view=shipping&amp;mode=manage">Manage calendar →</a></div>
  <?php endif;?>
  <a class="ship-action ship-secondary ship-reorder-link" href="?view=reorder">Open full Reorder Planner →</a>
  <p class="ship-foot">Estimates use non-recurring delivered sales (last 30 days), projected recurring orders not already open, fridge balances net of reservations, and only incoming orders with a future ETA within this cover period. Expiry dates, changes in demand and actual customs/carrier delays still require a manual check. Nothing is ordered automatically.</p>
 </section>
 <?php endif; ?>
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
<section class="ship-card ship-calendar-panel" id="ship-calendar">
 <div class="ship-calendar-intro">
  <div><h2>Upcoming disruptions</h2><p class="ship-sub">Select an event to see the full dates, notes and shipping impact.</p></div>
  <span class="ship-count"><?=count($shipUpcoming)?> events</span>
 </div>
 <div class="ship-event-tiles">
 <?php foreach($shipUpcoming as $ev):?>
 <a class="ship-event-tile" href="?view=shipping&amp;mode=event&amp;event=<?=rawurlencode($ev['id'])?>" aria-label="Read <?=e($ev['name'])?> event details">
  <div class="ship-event-tile-top">
   <span class="ship-event-tile-date"><?=e(date('j M Y',strtotime($ev['date'])))?></span>
   <span class="ship-risk-chip <?=e($ev['risk'])?>"><?=e($ev['risk']==='critical'?'Very high':ucfirst($ev['risk']))?> risk</span>
  </div>
  <div class="ship-event-tile-title"><h3><?=e($ev['name'])?></h3><span class="ship-tile-chevron" aria-hidden="true">›</span></div>
  <p class="ship-event-preview"><?=e((string)($ev['detail']??'No additional details recorded yet.'))?></p>
  <div class="ship-event-tile-bottom"><span>Until <?=e(date('j M Y',strtotime($ev['end'])))?></span><span class="ship-read-more">View details <span aria-hidden="true">↗</span></span></div>
 </a>
 <?php endforeach;?>
 <?php if(!$shipUpcoming):?><p class="ship-empty">No upcoming events. Add one in Calendar Management.</p><?php endif;?>
 </div>
 <p class="ship-foot">Holiday dates and disruption estimates may differ from your supplier's confirmed closure schedule.</p>
 <a class="ship-action ship-secondary ship-manage-link" href="?view=shipping&amp;mode=manage">✎ Edit disruption calendar →</a>
 </section>
<?php endif;?>
<section class="ship-card"><h2>Supplier &amp; shipment tools</h2><p class="ship-sub">Keep purchasing and stock receipts within your existing system — no duplicated order records.</p><p style="display:flex;flex-wrap:wrap;gap:9px;margin-top:15px"><a class="ship-action" href="?view=supplier">Supplier Centre →</a><a class="ship-action ship-secondary" href="?view=stock&amp;section=incoming">Incoming stock →</a><a class="ship-action ship-secondary" href="?view=supplies">Business Supplies →</a></p></section>
</div>
