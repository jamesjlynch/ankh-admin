<?php
declare(strict_types=1);
if (!defined('ANKH_PORTAL_VIEW') || empty($customer)) { http_response_code(404); exit; }

/** Read-only customer workspace: every order here was filtered for this identity by the controller. */
function pcIcon(string $key): string {
    $paths = [
      'home'=>'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
      'catalogue'=>'<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a4 4 0 0 1 8 0v2M8 11v1M16 11v1"/>',
      'orders'=>'<path d="M3 7 12 3l9 4v10l-9 4-9-4z"/><path d="m3 7 9 4 9-4M12 11v10"/>',
      'vault'=>'<path d="M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9l-6-6z"/><path d="M15 3v6h6M8 14h8M8 18h6"/>',
      'support'=>'<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8A8.5 8.5 0 0 1 12.5 20a8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8A8.5 8.5 0 0 1 12.5 3H13a8.5 8.5 0 0 1 8 8.5z"/>',
      'account'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
      'right'=>'<path d="m9 18 6-6-6-6"/>',
      'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
      'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
      'check'=>'<path d="m4 12 5 5L20 6"/>',
      'wallet'=>'<rect x="3" y="6" width="18" height="15" rx="2"/><path d="M3 10h18M7 3h12"/>',
      'shield'=>'<path d="M12 2 4 6v6c0 5 3.5 8.5 8 10 4.5-1.5 8-5 8-10V6z"/><path d="m9 12 2 2 4-4"/>',
      'search'=>'<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
      'mail'=>'<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m3 6 9 8 9-8"/>',
      'truck'=>'<path d="M3 6h12v12H3zM15 10h4l3 4v4h-7z"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/>',
      'print'=>'<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 15h12v7H6z"/>',
      'spark'=>'<path d="m12 2 2.2 7.8L22 12l-7.8 2.2L12 22l-2.2-7.8L2 12l7.8-2.2z"/>',
      'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
    ];
    $path=$paths[$key] ?? $paths['right'];
    return '<svg class="pc-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$path.'</svg>';
}
function pcHref(string $tab, bool $staff, int $customerId, array $params=[]): string {
    $params=array_merge($staff?['customer_id'=>$customerId]:[],['tab'=>$tab],$params);
    if(!$staff && !empty($GLOBALS['isDemo'])) $params=['screen'=>'account']+$params;
    return ($staff?'./preview-live.php':(!empty($GLOBALS['isDemo'])?'./preview.php':'./')).'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}
function pcOrderRef(array $o): string { return 'ANK-'.str_pad((string)$o['id'],4,'0',STR_PAD_LEFT); }
function pcBalance(array $o): int {
    if (($o['status']??'')==='Cancelled') return 0;
    return max(0,(int)$o['total']-(int)$o['paid']);
}
function pcPaymentLabel(array $o): string {
    if (($o['status']??'')==='Cancelled') return 'Cancelled';
    if ((int)$o['total']===0) return 'No payment required';
    if (pcBalance($o)===0) return 'Paid';
    return (int)$o['paid']>0?'Part paid':'Payment outstanding';
}
function pcTone(string $status): string {
    return match($status) {'Delivered'=>'success','Cancelled'=>'muted','Received'=>'neutral','On its way'=>'info',default=>'gold'};
}
function pcLines(array $o): string {
    $n=array_sum(array_map(static fn(array $line):int=>(int)$line['quantity'],$o['lines']??[]));
    return $n.' unit'.($n===1?'':'s');
}
function pcEvents(array $orders): array {
    $events=[];
    foreach($orders as $o) {
        $ref=pcOrderRef($o);
        if (!empty($o['created'])) $events[]=['date'=>(string)$o['created'],'title'=>'Order '.$ref.' received','subtitle'=>'Your order was recorded','icon'=>'orders','order'=>(int)$o['id']];
        if (!empty($o['delivery_date']) && portalStatus($o)==='Delivered') $events[]=['date'=>(string)$o['delivery_date'],'title'=>'Order '.$ref.' delivered','subtitle'=>'Delivery recorded in ANKH','icon'=>'truck','order'=>(int)$o['id']];
        foreach(($o['payments']??[]) as $p) {
            if (!empty($p['created']) && (int)$p['amount']>0) $events[]=['date'=>(string)$p['created'],'title'=>portalMoney((int)$p['amount']).' payment recorded','subtitle'=>'Against order '.$ref,'icon'=>'wallet','order'=>(int)$o['id']];
        }
    }
    usort($events,static fn(array $a,array $b):int=>(strtotime((string)$b['date'])?:0)<=>(strtotime((string)$a['date'])?:0));
    return array_slice($events,0,7);
}
function pcOrderTimeline(array $o): array {
    $status=portalStatus($o);
    if ($status==='Cancelled') return [['title'=>'Order received','state'=>'done'],['title'=>'Order cancelled','state'=>'cancelled']];
    $stage=0;
    if (!in_array((string)$o['status'],['New','Awaiting payment'],true) || !empty($o['reconstituted']) || !empty($o['packaged'])) $stage=1;
    if (!empty($o['packaged']) || in_array((string)$o['status'],['Packed','Dispatched','Delivered'],true)) $stage=2;
    if ($status==='Delivered') $stage=3;
    $titles=['Order received','In preparation','Ready or dispatched','Delivered'];
    return array_map(static fn(int $i):array=>['title'=>$titles[$i],'state'=>$i<$stage?'done':($i===$stage?'current':'upcoming')],range(0,3));
}
$staff=!empty($isStaffPreview);
$customerId=(int)$customer['id'];
$catalogueEnabled=true;
$tab=is_string($_GET['tab']??null)?$_GET['tab']:'home';
$allowedTabs=['home','orders','vault','support','account','recurring'];
if($catalogueEnabled)$allowedTabs[]='catalogue';
if(!empty($isDemo))$allowedTabs=array_merge($allowedTabs,['basket','checkout','confirmation','test-orders']);
if(!in_array($tab,$allowedTabs,true))$tab='home';
$selectedId=filter_var($_GET['order']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$selected=null;
foreach($orders as $candidate) if((int)$candidate['id']===$selectedId) {$selected=$candidate;break;}
if($selected && $tab!=='orders' && $tab!=='vault') $selected=null;
$filter=(string)($_GET['filter']??'all');
if(!in_array($filter,['all','active','delivered','cancelled'],true))$filter='all';
$term=trim((string)($_GET['find']??''));
if(strlen($term)>80)$term=substr($term,0,80);
$visibleOrders=array_values(array_filter($orders,static function(array $o)use($filter,$term):bool{
    $status=portalStatus($o);
    if($filter==='active' && in_array($status,['Delivered','Cancelled'],true))return false;
    if($filter==='delivered' && $status!=='Delivered')return false;
    if($filter==='cancelled' && $status!=='Cancelled')return false;
    if($term==='')return true;
    $haystack=pcOrderRef($o).' '.(string)$o['id'].' '.(string)$o['created'].' '.implode(' ',array_map(static fn(array $l):string=>(string)$l['name'],$o['lines']??[]));
    return stripos($haystack,$term)!==false;
}));
$currentOrders=array_values(array_filter($orders,static fn(array $o):bool=>!in_array(portalStatus($o),['Delivered','Cancelled'],true)));
$deliveredOrders=array_values(array_filter($orders,static fn(array $o):bool=>portalStatus($o)==='Delivered'));
$paidTotal=array_sum(array_map(static fn(array $o):int=>($o['status']??'')==='Cancelled'?0:(int)$o['paid'],$orders));
$outstanding=array_sum(array_map('pcBalance',$orders));
$recent=$currentOrders[0]??($orders[0]??null);
$events=pcEvents($orders);
$menu=$catalogueEnabled?[['home','Home'],['catalogue','Catalogue'],['orders','Orders'],['vault','Vault'],['account','Account']]:[['home','Home'],['orders','Orders'],['vault','Vault'],['support','Support'],['account','Account']];
$activeNav=$tab==='recurring'?'account':(in_array($tab,['basket','checkout','confirmation','test-orders'],true)?'catalogue':$tab);
$recurringOrders=[];$recurringError=false;
if(empty($isDemo) && isset($admin) && !empty($account['phone_e164'])){
    try{$recurringOrders=portalRecurringOrders($admin,$customer,(string)$account['phone_e164'],$orders);}catch(Throwable $e){error_log('ANKH customer reminders: '.$e->getMessage());$recurringError=true;}
}
$dueSoon=count(array_filter($recurringOrders,static fn(array $r):bool=>$r['days_until']<=7));
$contactHref='mailto:info@ankhpeptides.com?subject='.rawurlencode('ANKH customer account support');
?>
<div class="pc-app">
  <div class="pc-overline"><span><?=pcIcon('shield')?> Secure customer workspace</span><a href="<?=portalEscape(pcHref('support',$staff,$customerId))?>">Get support <?=pcIcon('support')?></a></div>
  <nav class="pc-top-nav" aria-label="Customer account navigation">
    <?php foreach($menu as [$key,$label]): ?><a href="<?=portalEscape(pcHref($key,$staff,$customerId))?>" <?=($activeNav===$key?'aria-current="page"':'')?> class="<?=$activeNav===$key?'selected':''?>"><?=pcIcon($key)?><span><?=portalEscape($label)?></span></a><?php endforeach;?>
  </nav>
  <?php if($catalogueEnabled && in_array($tab,['catalogue','basket','checkout','confirmation','test-orders'],true)): ?>
    <?php require __DIR__.(!empty($isDemo)?'/catalogue-view.php':'/live-catalogue.php'); ?>
  <?php elseif($tab==='home'): ?>
  <section class="pc-welcome">
    <div><span class="pc-eyebrow">YOUR PRIVATE ANKH SPACE</span><h1>Good to see you, <em><?=portalEscape((string)$firstName)?></em><span class="pc-dot">.</span></h1><p>Everything about your orders, documents and account in one place.</p></div>
    <div class="pc-welcome-symbol" aria-hidden="true">☥</div>
  </section>
  <?php if($catalogueEnabled):?><a class="pcd-home-banner" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId))?>"><span class="pcd-home-icon"><?=pcIcon('catalogue')?></span><span><small><?=!empty($isDemo)?'ACCESSORY PREVIEW':'RESEARCH CATALOGUE'?></small><strong>Explore the collection</strong><span><?=!empty($isDemo)?'Try the catalogue, basket and checkout with sample accessories.':'See published products, your prices and availability.'?></span></span><?=pcIcon('right')?></a><?php endif;?>
  <?php if($recurringOrders):?><a class="pcr-home-reminder" href="<?=portalEscape(pcHref('recurring',$staff,$customerId))?>"><?=pcIcon('calendar')?><span><strong>Recurring order reminders</strong><small><?=$dueSoon?$dueSoon.' to review in the next 7 days or earlier':'Next expected date: '.portalEscape(portalDate($recurringOrders[0]['due_date']))?></small></span><?=pcIcon('right')?></a><?php endif;?>
  <div class="pc-stat-grid">
    <a class="pc-stat pc-stat-gold" href="<?=portalEscape(pcHref('orders',$staff,$customerId,['filter'=>'active']))?>"><span><?=pcIcon('orders')?> Active orders</span><strong><?=count($currentOrders)?></strong><small>See what’s in progress <?=pcIcon('right')?></small></a>
    <a class="pc-stat" href="<?=portalEscape(pcHref('orders',$staff,$customerId))?>"><span><?=pcIcon('check')?> Total orders</span><strong><?=count($orders)?></strong><small>Complete order history <?=pcIcon('right')?></small></a>
    <div class="pc-stat"><span><?=pcIcon('wallet')?> Payments recorded</span><strong><?=portalEscape(portalMoney($paidTotal))?></strong><small>Excludes cancelled orders</small></div>
    <a class="pc-stat <?=$outstanding>0?'pc-stat-due':''?>" href="<?=portalEscape(pcHref('orders',$staff,$customerId))?>"><span><?=pcIcon('clock')?> Outstanding balance</span><strong><?=portalEscape(portalMoney($outstanding))?></strong><small>Across non-cancelled orders <?=pcIcon('right')?></small></a>
  </div>
  <div class="pc-home-grid">
    <section class="pc-panel pc-feature">
      <div class="pc-section-header"><div><span class="pc-eyebrow">THE LATEST</span><h2><?=count($currentOrders)?'Your order in focus':'Your latest order'?></h2></div><a href="<?=portalEscape(pcHref('orders',$staff,$customerId))?>">All orders <?=pcIcon('right')?></a></div>
      <?php if($recent): $state=portalStatus($recent);?>
      <div class="pc-feature-top"><div><span class="pc-ref"><?=portalEscape(pcOrderRef($recent))?> · <?=portalEscape(portalDate((string)$recent['created']))?></span><h3><?=portalEscape($state)?></h3><p><?=portalEscape(pcLines($recent))?> <span class="pc-separator">·</span> <?=portalEscape(portalMoney((int)$recent['total']))?></p></div><span class="pc-pill pc-pill-<?=pcTone($state)?>"><?=portalEscape($state)?></span></div>
      <div class="pc-mini-timeline"><?php foreach(pcOrderTimeline($recent) as $step):?><span class="pc-mini-step <?=$step['state']?>" title="<?=portalEscape($step['title'])?>"></span><?php endforeach;?></div>
      <div class="pc-feature-meta"><?php if($state==='Delivered' && !empty($recent['delivery_date'])):?>Delivered <?=portalEscape(portalDate((string)$recent['delivery_date']))?><?php elseif(!in_array($state,['Delivered','Cancelled'],true) && !empty($recent['scheduled_delivery_date'])):?>Planned delivery: <?=portalEscape(portalDate((string)$recent['scheduled_delivery_date']))?> <small>not confirmed as delivered</small><?php else:?>Check the latest recorded status in your order history.<?php endif;?></div>
      <a class="pc-primary-link" href="<?=portalEscape(pcHref('orders',$staff,$customerId,['order'=>(int)$recent['id']]))?>">View order journey <?=pcIcon('right')?></a>
      <?php else: ?><div class="pc-empty"><h3>No orders recorded yet</h3><p>When your first order is recorded, its progress will appear here.</p></div><?php endif;?>
    </section>
    <section class="pc-panel pc-activity">
      <div class="pc-section-header"><div><span class="pc-eyebrow">STAY IN THE LOOP</span><h2>Recent activity</h2></div></div>
      <?php if(!$events):?><div class="pc-empty"><p>No recorded activity yet.</p></div><?php else:?>
        <div class="pc-feed"><?php foreach(array_slice($events,0,5) as $event):?><a href="<?=portalEscape(pcHref('orders',$staff,$customerId,['order'=>$event['order']]))?>" class="pc-feed-item"><span class="pc-feed-icon"><?=pcIcon($event['icon'])?></span><span><strong><?=portalEscape($event['title'])?></strong><small><?=portalEscape($event['subtitle'])?></small></span><time><?=portalEscape(portalDate($event['date']))?></time></a><?php endforeach;?></div>
      <?php endif;?>
    </section>
  </div>
  <section class="pc-shortcuts"><div class="pc-section-header"><div><span class="pc-eyebrow">EVERYTHING, SIMPLIFIED</span><h2>At your fingertips</h2></div></div><div class="pc-shortcut-grid">
    <?php foreach([['vault','My documents','Order summaries and records'],['support','Get support','Help with an order'],['account','My details','Contact and delivery information']] as [$key,$title,$sub]):?><a class="pc-shortcut" href="<?=portalEscape(pcHref($key,$staff,$customerId))?>"><span class="pc-shortcut-icon"><?=pcIcon($key)?></span><strong><?=$title?></strong><small><?=$sub?></small><span class="pc-shortcut-arrow"><?=pcIcon('right')?></span></a><?php endforeach;?>
  </div></section>

  <?php elseif($tab==='orders'): ?>
  <section class="pc-page-heading"><span class="pc-eyebrow">EVERY ORDER. ONE PLACE.</span><h1>My orders<span class="pc-dot">.</span></h1><p>Search your history, review payments and follow the recorded delivery progress.</p></section>
  <?php if($selected): ?>
    <a class="pc-back" href="<?=portalEscape(pcHref('orders',$staff,$customerId))?>">← Back to orders</a>
    <?php $status=portalStatus($selected);$balance=pcBalance($selected);?>
    <article class="pc-panel pc-order-detail">
      <div class="pc-detail-heading"><div><span class="pc-eyebrow">ORDER DETAILS</span><h2><?=portalEscape(pcOrderRef($selected))?></h2><p>Placed <?=portalEscape(portalDate((string)$selected['created']))?></p></div><span class="pc-pill pc-pill-<?=pcTone($status)?>"><?=portalEscape($status)?></span></div>
      <div class="pc-timeline" aria-label="Order progress"><?php foreach(pcOrderTimeline($selected) as $step):?><div class="pc-timeline-row <?=$step['state']?>"><span class="pc-timeline-marker"><?=in_array($step['state'],['done','current'],true)?pcIcon('check'):''?></span><div><strong><?=portalEscape($step['title'])?></strong><?php if($step['title']==='Order received'):?><small><?=portalEscape(portalDate((string)$selected['created']))?></small><?php elseif($step['title']==='Delivered' && $status==='Delivered' && !empty($selected['delivery_date'])):?><small><?=portalEscape(portalDate((string)$selected['delivery_date']))?></small><?php else:?><small><?=portalEscape(match($step['state']){'done'=>'Stage completed — time not recorded','current'=>'Latest recorded stage','cancelled'=>'Cancelled','upcoming'=>'Not yet recorded',default=>''})?></small><?php endif;?></div></div><?php endforeach;?></div>
      <?php if(!in_array($status,['Delivered','Cancelled'],true) && !empty($selected['scheduled_delivery_date'])):?><div class="pc-inline-note"><?=pcIcon('calendar')?><span>Planned delivery: <strong><?=portalEscape(portalDate((string)$selected['scheduled_delivery_date']))?></strong>. This is not a delivery confirmation.</span></div><?php endif;?>
      <?php if(!empty($selected['delivery_method']) || !empty($selected['tracking_reference'])):?><div class="pc-delivery-facts">
        <?php if(!empty($selected['delivery_method'])):?><div><span>Delivery method</span><strong><?=portalEscape((string)$selected['delivery_method'])?></strong></div><?php endif;?>
        <?php if(!empty($selected['tracking_reference'])):?><div><span>Tracking reference</span><strong><?=portalEscape((string)$selected['tracking_reference'])?></strong></div><?php endif;?>
      </div><?php endif;?>
      <h3 class="pc-subheading">Items in this order</h3>
      <div class="pc-line-items"><?php foreach($selected['lines'] as $line):?><div class="pc-item-row"><span class="pc-item-symbol"><?=pcIcon('orders')?></span><div><strong><?=portalEscape((string)$line['name'])?></strong><small><?=portalEscape((string)($line['presentation']?:'Standard'))?> · Qty <?= (int)$line['quantity']?></small></div><strong><?=portalEscape(portalMoney((int)$line['price']*(int)$line['quantity']))?></strong></div><?php endforeach;?></div>
      <div class="pc-money-rows"><div><span>Items subtotal</span><strong><?=portalEscape(portalMoney(max(0,(int)$selected['total']-(int)$selected['delivery_charge'])))?></strong></div><div><span>Delivery charge</span><strong><?=portalEscape(portalMoney((int)$selected['delivery_charge']))?></strong></div><div class="pc-money-total"><span>Order total</span><strong><?=portalEscape(portalMoney((int)$selected['total']))?></strong></div><div><span>Payments recorded</span><strong><?=portalEscape(portalMoney((int)$selected['paid']))?></strong></div><div class="pc-money-balance"><span><?=portalEscape(pcPaymentLabel($selected))?></span><strong><?=portalEscape(portalMoney($balance))?> outstanding</strong></div></div>
      <?php if(!empty($selected['payments'])):?><div class="pc-payment-log"><h3 class="pc-subheading">Payment history</h3><?php foreach($selected['payments'] as $payment):?><div><span><?=portalEscape(portalDate((string)$payment['created']))?><?=!empty($payment['method'])?' · '.portalEscape((string)$payment['method']):''?></span><strong><?=portalEscape(portalMoney((int)$payment['amount']))?></strong></div><?php endforeach;?></div><?php endif;?>
      <div class="pc-detail-actions"><a href="<?=portalEscape(pcHref('vault',$staff,$customerId,['order'=>(int)$selected['id']]))?>" class="pc-outline-button"><?=pcIcon('print')?> View printable summary</a><a href="mailto:info@ankhpeptides.com?subject=<?=rawurlencode('Question about '.pcOrderRef($selected))?>" class="pc-outline-button"><?=pcIcon('mail')?> Ask about this order</a></div>
    </article>
  <?php else: ?>
    <div class="pc-panel pc-filter-panel"><form class="pc-filter-form" method="get" action="<?=$staff?'./preview-live.php':'./'?>">
      <?php if($staff):?><input type="hidden" name="customer_id" value="<?=$customerId?>"><?php endif;?><input type="hidden" name="tab" value="orders">
      <label for="pc-find"><?=pcIcon('search')?> <span class="sr-only">Search orders</span></label><input id="pc-find" type="search" name="find" placeholder="Find an order or product" value="<?=portalEscape($term)?>" maxlength="80">
      <select name="filter" aria-label="Filter order statuses"><?php foreach(['all'=>'All orders','active'=>'In progress','delivered'=>'Delivered','cancelled'=>'Cancelled'] as $key=>$label):?><option value="<?=$key?>" <?=$filter===$key?'selected':''?>><?=$label?></option><?php endforeach;?></select><button type="submit">Search</button>
    </form></div>
    <div class="pc-order-grid"><?php foreach($visibleOrders as $order):$status=portalStatus($order);?><a class="pc-order-tile" href="<?=portalEscape(pcHref('orders',$staff,$customerId,['order'=>(int)$order['id']]))?>"><div class="pc-tile-top"><span class="pc-ref"><?=portalEscape(pcOrderRef($order))?> · <?=portalEscape(portalDate((string)$order['created']))?></span><span class="pc-pill pc-pill-<?=pcTone($status)?>"><?=portalEscape($status)?></span></div><div class="pc-tile-mid"><strong><?=portalEscape(pcLines($order))?></strong><span><?=portalEscape(portalMoney((int)$order['total']))?></span></div><div class="pc-tile-bottom"><span><?=portalEscape(pcPaymentLabel($order))?><?php if(pcBalance($order)>0):?> · <?=portalEscape(portalMoney(pcBalance($order)))?> due<?php endif;?></span><?=pcIcon('right')?></div></a><?php endforeach;?></div>
    <?php if(!$visibleOrders):?><div class="pc-empty pc-panel"><h3>Nothing matches that search</h3><p>Try another product name, order reference or filter.</p><a href="<?=portalEscape(pcHref('orders',$staff,$customerId))?>">Clear filters</a></div><?php endif;?>
    <p class="pc-footnote">Orders are shown using the name and mobile currently associated with this customer record. Older records with different details may need manual review.</p>
  <?php endif;?>

  <?php elseif($tab==='vault'): ?>
    <section class="pc-page-heading"><span class="pc-eyebrow">PRIVATE ORDER RECORDS</span><h1>My vault<span class="pc-dot">.</span></h1><p>Find a printable summary of each order, with its recorded items and payments.</p></section>
    <?php if($selected):?>
      <a class="pc-back pc-print-hide" href="<?=portalEscape(pcHref('vault',$staff,$customerId))?>">← Back to vault</a>
      <article class="pc-panel pc-printable">
        <div class="pc-print-head"><div><span class="pc-print-ankh">☥ ANKH</span><span class="pc-eyebrow">CUSTOMER ORDER SUMMARY</span></div><span><?=portalEscape(pcOrderRef($selected))?></span></div>
        <h2>Order summary</h2><p>Issued from current ANKH order records · <?=portalEscape(portalDate((string)$selected['created']))?></p>
        <div class="pc-print-address"><strong><?=portalEscape((string)$customer['name'])?></strong><p><?=nl2br(portalEscape((string)$customer['address']))?></p></div>
        <div class="pc-line-items"><?php foreach($selected['lines'] as $line):?><div class="pc-item-row"><div><strong><?=portalEscape((string)$line['name'])?></strong><small><?=portalEscape((string)$line['presentation'])?> · <?= (int)$line['quantity']?> × <?=portalEscape(portalMoney((int)$line['price']))?></small></div><strong><?=portalEscape(portalMoney((int)$line['price']*(int)$line['quantity']))?></strong></div><?php endforeach;?></div>
        <div class="pc-money-rows"><div><span>Delivery</span><strong><?=portalEscape(portalMoney((int)$selected['delivery_charge']))?></strong></div><div class="pc-money-total"><span>Total</span><strong><?=portalEscape(portalMoney((int)$selected['total']))?></strong></div><div><span>Paid</span><strong><?=portalEscape(portalMoney((int)$selected['paid']))?></strong></div><div><span>Outstanding (excluding cancelled orders)</span><strong><?=portalEscape(portalMoney(pcBalance($selected)))?></strong></div></div>
        <p class="pc-footnote">This is an order summary, not a VAT invoice or proof of independent laboratory testing. Contact ANKH if you require official documents.</p>
        <div class="pc-detail-actions pc-print-hide"><button type="button" id="pc-print-action" class="pc-outline-button"><?=pcIcon('print')?> Print or save as PDF</button><a href="mailto:info@ankhpeptides.com?subject=<?=rawurlencode('Documents for '.pcOrderRef($selected))?>" class="pc-outline-button"><?=pcIcon('mail')?> Request documents</a></div>
      </article>
    <?php else:?>
      <div class="pc-vault-note"><?=pcIcon('shield')?><div><strong>Private, accurate records</strong><p>These summaries use your actual orders. Batch certificates and laboratory reports will only appear here once ANKH has matched and uploaded verified documentation.</p></div></div>
      <section class="pc-section-block"><div class="pc-section-header"><div><span class="pc-eyebrow">AVAILABLE NOW</span><h2>Order summaries</h2></div><span class="pc-count"><?=count($orders)?> records</span></div>
      <?php foreach($orders as $order):?><a class="pc-document-row" href="<?=portalEscape(pcHref('vault',$staff,$customerId,['order'=>(int)$order['id']]))?>"><span><?=pcIcon('vault')?></span><div><strong><?=portalEscape(pcOrderRef($order))?> · Order summary</strong><small><?=portalEscape(portalDate((string)$order['created']))?> · <?=portalEscape(pcLines($order))?></small></div><?=pcIcon('right')?></a><?php endforeach;?>
      <?php if(!$orders):?><div class="pc-empty pc-panel"><p>No order summaries are available for this account yet.</p></div><?php endif;?></section>
      <div class="pc-coming-soon"><span><?=pcIcon('vault')?></span><div><strong>Batch documents &amp; laboratory reports</strong><p>Not available online yet. Only genuine, batch-matched documents will be added. You can request existing records directly.</p><a href="mailto:info@ankhpeptides.com?subject=<?=rawurlencode('Request ANKH batch documents')?>">Request documents <?=pcIcon('right')?></a></div></div>
    <?php endif;?>

  <?php elseif($tab==='support'): ?>
    <section class="pc-page-heading"><span class="pc-eyebrow">WE'RE HERE TO HELP</span><h1>Support centre<span class="pc-dot">.</span></h1><p>Get help with your orders, payments, delivery arrangements and account information.</p></section>
    <div class="pc-support-hero"><span><?=pcIcon('support')?></span><div><span class="pc-eyebrow">DIRECT CONTACT</span><h2>How can we help?</h2><p>For now, support requests open your email app with the appropriate ANKH reference. In-app messaging and tracked support tickets aren't active yet.</p><a class="pc-primary-link" href="<?=portalEscape($contactHref)?>">Email ANKH support <?=pcIcon('right')?></a></div></div>
    <div class="pc-help-grid">
      <?php foreach([
        ['Delivery question','Confirm a date or delivery arrangements','Delivery enquiry'],
        ['Payment question','Check an outstanding or recorded payment','Payment enquiry'],
        ['Missing or incorrect order','Report an issue with a received order','Order issue'],
        ['Change my details','Request a contact or address update','Customer details change'],
      ] as [$heading,$info,$subject]):?><a href="mailto:info@ankhpeptides.com?subject=<?=rawurlencode('ANKH '.$subject)?>" class="pc-help-tile"><?=pcIcon('mail')?><strong><?=portalEscape($heading)?></strong><small><?=portalEscape($info)?></small><span><?=pcIcon('right')?></span></a><?php endforeach;?>
    </div>
    <?php if($orders):?><section class="pc-section-block"><div class="pc-section-header"><div><span class="pc-eyebrow">MAKE IT EASIER</span><h2>Ask about an order</h2></div></div><?php foreach(array_slice($orders,0,5) as $order):?><a class="pc-document-row" href="mailto:info@ankhpeptides.com?subject=<?=rawurlencode('Question about '.pcOrderRef($order))?>"><span><?=pcIcon('orders')?></span><div><strong><?=portalEscape(pcOrderRef($order))?></strong><small><?=portalEscape(portalDate((string)$order['created']))?> · <?=portalEscape(portalStatus($order))?></small></div><?=pcIcon('right')?></a><?php endforeach;?></section><?php endif;?>

  <?php elseif($tab==='recurring'):
    $recurringCatalogue=[];if(empty($isDemo)){try{$recurringCatalogue=ankhCatalogueProducts($admin,$customerId);}catch(Throwable $e){error_log('ANKH reminder product links: '.$e->getMessage());}}
  ?>
    <a class="pc-back" href="<?=portalEscape(pcHref('account',$staff,$customerId))?>">← My account</a>
    <section class="pc-page-heading"><span class="pc-eyebrow">PLAN YOUR NEXT ENQUIRY</span><h1>Recurring orders<span class="pc-dot">.</span></h1><p>Your saved order reminders and expected reorder dates.</p></section>
    <div class="pcr-notice"><?=pcIcon('calendar')?><p>These dates reflect the repeat intervals recorded by ANKH. No order or payment is created automatically.</p></div>
    <?php if($recurringError):?><div class="pc-panel pc-empty" role="alert"><h2>Reminders temporarily unavailable</h2><p>Please try again later or contact ANKH.</p></div>
    <?php elseif(!$recurringOrders):?><div class="pc-panel pc-empty"><h2>No recurring orders set up</h2><p>When ANKH records a recurring order for your account, its expected reorder date will appear here.</p><a class="pc-primary-link" href="<?=portalEscape(pcHref('support',$staff,$customerId))?>">Ask ANKH about a reminder <?=pcIcon('right')?></a></div>
    <?php else:?><div class="pcr-summary"><span><?=count($recurringOrders)?> active reminder<?=count($recurringOrders)===1?'':'s'?></span><span><?=$dueSoon?> to review within 7 days or earlier</span></div><div class="pcr-grid">
    <?php foreach($recurringOrders as $reminder):$days=$reminder['days_until'];$linkedProduct=null;foreach($recurringCatalogue as $candidate)if(strcasecmp(trim($candidate['name']),$reminder['product'])===0){$linkedProduct=$candidate;break;}$dueLabel=$days===0?'Expected today':($days<0?'Date passed':($days===1?'In 1 day':'In '.$days.' days'));?>
    <article class="pcr-card"><div class="pcr-card-top"><span class="pcr-state <?=$days<=7?'pcr-soon':''?>"><?=pcIcon('clock')?> <?=portalEscape($dueLabel)?></span><small><?=$reminder['weeks']?'Every '.$reminder['weeks'].' week'.($reminder['weeks']===1?'':'s'):'Repeat interval not recorded'?></small></div><h2><?=portalEscape($reminder['product'])?></h2><p class="pcr-format"><?=$reminder['quantity']?> × <?=portalEscape($reminder['product'])?><?=$reminder['presentation']!==''?' · '.portalEscape($reminder['presentation']):''?></p><div class="pcr-date"><small>Expected reorder date</small><strong><?=portalEscape(portalDate($reminder['due_date']))?></strong></div><div class="pcr-last"><span>Last delivery</span><strong><?=portalEscape(portalDate($reminder['last_delivery_date']))?></strong></div><?php if($days<0):?><p class="pcr-date-note">This reminder date has passed. Contact ANKH to review it.</p><?php endif;?><div class="pcr-actions"><?php if($linkedProduct):?><a class="pc-primary-link" href="<?=portalEscape(pcHref('catalogue',$staff,$customerId,['product'=>$linkedProduct['id'],'enquire'=>1]))?>#product-enquiry">Enquire about next order <?=pcIcon('right')?></a><?php else:?><a class="pc-primary-link" href="<?=portalEscape(pcHref('support',$staff,$customerId))?>">Contact ANKH <?=pcIcon('right')?></a><?php endif;?><a class="pcr-order-link" href="<?=portalEscape(pcHref('orders',$staff,$customerId,['order'=>$reminder['last_order_id']]))?>">View previous order <?=pcIcon('right')?></a></div></article>
    <?php endforeach;?></div><?php endif;?>
    <p class="pcr-footer-note">Need to change or stop a reminder? Contact ANKH so the account record can be updated.</p>
  <?php elseif($tab==='account'): ?>
    <section class="pc-page-heading"><span class="pc-eyebrow">YOUR INFORMATION</span><h1>My account<span class="pc-dot">.</span></h1><p>Review the contact details ANKH has recorded for you.</p></section>
    <a class="pcr-account-link" href="<?=portalEscape(pcHref('recurring',$staff,$customerId))?>"><span class="pcr-link-icon"><?=pcIcon('calendar')?></span><span><strong>Recurring orders</strong><small><?=count($recurringOrders)?> active reminder<?=count($recurringOrders)===1?'':'s'?> · See expected reorder dates</small></span><?=pcIcon('right')?></a>
    <div class="pc-account-grid">
      <section class="pc-panel pc-account-card"><div class="pc-section-header"><div><span class="pc-eyebrow">PERSONAL PROFILE</span><h2>My details</h2></div><?=pcIcon('account')?></div><div class="pc-profile-big"><span class="pc-profile-avatar"><?=portalEscape(strtoupper(substr((string)$firstName,0,1)))?></span><div><strong><?=portalEscape((string)$customer['name'])?></strong><small>ANKH customer · Account #<?=$customerId?></small></div></div><div class="pc-info-row"><span>Mobile number on file</span><strong><?=portalEscape((string)($customer['phone']?:'Not recorded'))?></strong></div><div class="pc-info-row"><span>Postal / delivery address</span><strong><?=trim((string)$customer['address'])!==''?nl2br(portalEscape((string)$customer['address'])):'No address saved'?></strong></div><div class="pc-info-row"><span>Order history</span><strong><?=count($orders)?> recorded</strong></div><a class="pc-outline-button" href="mailto:info@ankhpeptides.com?subject=<?=rawurlencode('Update my ANKH account details')?>"><?=pcIcon('mail')?> Request a change</a></section>
      <section class="pc-panel pc-account-card"><div class="pc-section-header"><div><span class="pc-eyebrow">SECURITY & PRIVACY</span><h2>Your data</h2></div><?=pcIcon('shield')?></div><div class="pc-privacy-list"><p><?=pcIcon('shield')?> Your order records are private to this account after enrolment and verification.</p><p><?=pcIcon('clock')?> Order details reflect the latest information recorded in ANKH Admin.</p><p><?=pcIcon('mail')?> To access, correct or discuss your personal information, contact the ANKH team.</p></div><a class="pc-outline-button" href="mailto:info@ankhpeptides.com?subject=<?=rawurlencode('ANKH personal data request')?>"><?=pcIcon('mail')?> Request my personal data</a></section>
    </div>
  <?php endif; ?>
</div>
<nav class="pc-bottom-nav" aria-label="Mobile account navigation">
<?php foreach($menu as [$key,$label]):?><a href="<?=portalEscape(pcHref($key,$staff,$customerId))?>" class="<?=$activeNav===$key?'selected':''?>" <?=$activeNav===$key?'aria-current="page"':''?>><?=pcIcon($key)?><span><?=$label?></span></a><?php endforeach;?>
</nav>
<?php if(($tab==='vault' && $selected)||($tab==='catalogue' && empty($isDemo))):?><script src="./account.js?v=interactions-1" defer></script><?php endif;?>

