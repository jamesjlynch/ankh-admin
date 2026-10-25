<?php
declare(strict_types=1);

/**
 * ANKH customer account extras. Portal-only, private SQLite data.
 * NEVER updates Admin orders, customers, stock or reminder schedules.
 */
function portalExtrasInit(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_product_availability(
        product_id INTEGER PRIMARY KEY, label TEXT NOT NULL DEFAULT 'automatic',
        updated_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS portal_delivery_preferences(
        customer_id INTEGER NOT NULL, preview INTEGER NOT NULL CHECK(preview IN (0,1)),
        address TEXT NOT NULL, notes TEXT NOT NULL DEFAULT '', contact_method TEXT NOT NULL DEFAULT 'No preference',
        updated_at INTEGER NOT NULL, PRIMARY KEY(customer_id,preview)
    );
    CREATE TABLE IF NOT EXISTS portal_delivery_requests(
        id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL,
        preview INTEGER NOT NULL CHECK(preview IN (0,1)),
        address TEXT NOT NULL, notes TEXT NOT NULL, contact_method TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'Pending' CHECK(status IN ('Pending','Approved','Declined')),
        reviewer_note TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL,
        reviewed_at INTEGER DEFAULT NULL, request_token TEXT NOT NULL,
        UNIQUE(customer_id,preview,request_token)
    );
    CREATE TABLE IF NOT EXISTS portal_next_order_requests(
        id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL,
        preview INTEGER NOT NULL CHECK(preview IN (0,1)), cycle_id INTEGER NOT NULL,
        source_order_id INTEGER NOT NULL, revision TEXT NOT NULL, product_name TEXT NOT NULL,
        due_date TEXT NOT NULL, kind TEXT NOT NULL CHECK(kind IN ('request_now','skip')),
        status TEXT NOT NULL DEFAULT 'Pending' CHECK(status IN ('Pending','Approved','Declined')),
        reviewer_note TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL,
        reviewed_at INTEGER DEFAULT NULL, request_token TEXT NOT NULL,
        UNIQUE(customer_id,preview,request_token)
    );
    CREATE INDEX IF NOT EXISTS portal_next_order_customer ON portal_next_order_requests(customer_id,preview,cycle_id,id);
    CREATE INDEX IF NOT EXISTS portal_delivery_customer ON portal_delivery_requests(customer_id,preview,id);");
}
function portalExtrasText(mixed $input,int $limit,bool $mandatory=false):string {
    if (!is_string($input)) throw new InvalidArgumentException('Please check the information entered.');
    $text=trim($input);
    if(($mandatory && $text==='')||strlen($text)>$limit*4||!preg_match('//u',$text)||preg_match_all('/./us',$text)>$limit)
        throw new InvalidArgumentException('Check the details entered and their length.');
    return $text;
}
function portalExtrasToken(mixed $token):string {
    if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('Please refresh and try again.');
    return $token;
}
function portalExtrasLimit(PDO $db,string $table,int $customerId,bool $preview,int $max=5):void {
    if(!in_array($table,['portal_delivery_requests','portal_next_order_requests'],true))throw new LogicException('Invalid request type.');
    $q=$db->prepare("SELECT COUNT(*) FROM ".$table." WHERE customer_id=? AND preview=? AND created_at>?");
    $q->execute([$customerId,(int)$preview,time()-3600]);
    if((int)$q->fetchColumn()>=$max)throw new InvalidArgumentException('You have recently sent several requests. Please try again later.');
}
function portalExtrasAvailabilityMap(PDO $db):array {
    portalExtrasInit($db);
    $out=[];
    foreach($db->query('SELECT product_id,label FROM portal_product_availability') as $r)$out[(int)$r['product_id']]=$r['label'];
    return $out;
}
function portalExtrasAvailability(array $product,array $overrides=[]):array {
    $label=$overrides[(int)$product['id']]??'automatic';
    $qty=$product['available_qty']??null;
    if(!in_array($label,['automatic','available','limited','coming_soon','unavailable'],true))$label='automatic';
    if($label==='automatic')$label=$qty===null?'enquire':($qty<=0?'unavailable':($qty<=5?'limited':'available'));
    // An override can hide stock, but cannot claim in-stock when tracking reports zero.
    if($qty!==null && $qty<=0 && in_array($label,['available','limited'],true))$label='unavailable';
    return match($label){
        'available'=>['label'=>'Available','tone'=>'available'],
        'limited'=>['label'=>'Limited availability','tone'=>'limited'],
        'coming_soon'=>['label'=>'Coming soon','tone'=>'coming'],
        'unavailable'=>['label'=>'Currently unavailable','tone'=>'unavailable'],
        default=>['label'=>'Ask about availability','tone'=>'enquire'],
    };
}
function portalExtrasDelivery(PDO $db,int $customerId,bool $preview):array {
    portalExtrasInit($db);
    $q=$db->prepare('SELECT address,notes,contact_method,updated_at FROM portal_delivery_preferences WHERE customer_id=? AND preview=?');
    $q->execute([$customerId,(int)$preview]);$saved=$q->fetch(PDO::FETCH_ASSOC)?:null;
    $q=$db->prepare('SELECT * FROM portal_delivery_requests WHERE customer_id=? AND preview=? ORDER BY id DESC LIMIT 1');
    $q->execute([$customerId,(int)$preview]);$latest=$q->fetch(PDO::FETCH_ASSOC)?:null;
    return ['saved'=>$saved,'latest'=>$latest];
}
function portalExtrasSaveDelivery(PDO $db,int $customerId,bool $preview,mixed $address,mixed $notes,mixed $method,mixed $token):int {
    $address=portalExtrasText($address,400,true);
    $notes=portalExtrasText($notes,500);
    $method=portalExtrasText($method,40,true);
    if(!in_array($method,['No preference','Text message','Phone call'],true))throw new InvalidArgumentException('Choose a contact preference.');
    $token=portalExtrasToken($token);
    portalExtrasInit($db);$db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare('SELECT id FROM portal_delivery_requests WHERE customer_id=? AND preview=? AND request_token=?');
        $q->execute([$customerId,(int)$preview,$token]);if($id=$q->fetchColumn()){$db->exec('COMMIT');return (int)$id;}
        $q=$db->prepare("SELECT 1 FROM portal_delivery_requests WHERE customer_id=? AND preview=? AND status='Pending' LIMIT 1");
        $q->execute([$customerId,(int)$preview]);if($q->fetchColumn())throw new InvalidArgumentException('Your current delivery preference request is awaiting review.');
        portalExtrasLimit($db,'portal_delivery_requests',$customerId,$preview);
        $db->prepare('INSERT INTO portal_delivery_requests(customer_id,preview,address,notes,contact_method,created_at,request_token) VALUES(?,?,?,?,?,?,?)')
            ->execute([$customerId,(int)$preview,$address,$notes,$method,time(),$token]);
        $id=(int)$db->lastInsertId();$db->exec('COMMIT');return $id;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function portalExtrasNextList(PDO $db,int $customerId,bool $preview):array {
    portalExtrasInit($db);
    $q=$db->prepare('SELECT * FROM portal_next_order_requests WHERE customer_id=? AND preview=? ORDER BY id DESC LIMIT 100');
    $q->execute([$customerId,(int)$preview]);$rows=[];
    foreach($q as $row)if(!isset($rows[(int)$row['cycle_id']]))$rows[(int)$row['cycle_id']]=$row;
    return $rows;
}
function portalExtrasSaveNext(PDO $db,int $customerId,bool $preview,array $reminder,mixed $kind,mixed $revision,mixed $token):int {
    if(!in_array($kind,['request_now','skip'],true))throw new InvalidArgumentException('Choose a valid next-order action.');
    if(!is_string($revision)||!hash_equals((string)$reminder['revision'],$revision))throw new InvalidArgumentException('The reminder changed. Refresh and try again.');
    $token=portalExtrasToken($token);
    portalExtrasInit($db);$db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare('SELECT id FROM portal_next_order_requests WHERE customer_id=? AND preview=? AND request_token=?');
        $q->execute([$customerId,(int)$preview,$token]);if($id=$q->fetchColumn()){$db->exec('COMMIT');return (int)$id;}
        $q=$db->prepare("SELECT 1 FROM portal_next_order_requests WHERE customer_id=? AND preview=? AND cycle_id=? AND revision=? AND status='Pending' LIMIT 1");
        $q->execute([$customerId,(int)$preview,$reminder['id'],$revision]);
        if($q->fetchColumn())throw new InvalidArgumentException('There is already a request awaiting review for this reminder.');
        portalExtrasLimit($db,'portal_next_order_requests',$customerId,$preview);
        $db->prepare('INSERT INTO portal_next_order_requests(customer_id,preview,cycle_id,source_order_id,revision,product_name,due_date,kind,created_at,request_token) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$customerId,(int)$preview,$reminder['id'],$reminder['last_order_id'],$revision,$reminder['product'],$reminder['due_date'],$kind,time(),$token]);
        $id=(int)$db->lastInsertId();$db->exec('COMMIT');return $id;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function portalExtrasController(PDO $store,PDO $admin,array $customer,array $account,array $orders,bool $preview):void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!in_array($_POST['action']??null,['portal_delivery_save','portal_next_action'],true))return;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)($_SESSION['portal_csrf']??''),$_POST['csrf'])){http_response_code(400);exit('Invalid request.');}
    $target='account';
    try {
        if($_POST['action']==='portal_delivery_save'){
            portalExtrasSaveDelivery($store,(int)$customer['id'],$preview,$_POST['address']??null,$_POST['notes']??null,$_POST['contact_method']??null,$_POST['request_token']??null);
            $_SESSION['portal_extras_flash']='Delivery preferences submitted for review. Your current details remain unchanged until ANKH confirms them.';
        }else{
            $target='recurring';
            $id=filter_var($_POST['cycle_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;$reminder=null;
            foreach(portalRecurringOrders($admin,$customer,(string)$account['phone_e164'],$orders) as $row)if($row['id']===$id){$reminder=$row;break;}
            if(!$reminder)throw new InvalidArgumentException('This reminder is no longer active in your account.');
            portalExtrasSaveNext($store,(int)$customer['id'],$preview,$reminder,$_POST['request_kind']??null,$_POST['reminder_revision']??null,$_POST['request_token']??null);
            $_SESSION['portal_extras_flash']=($_POST['request_kind']==='skip'?'Your request to skip this cycle':'Your request for the next order').' has been sent for ANKH review. No new order or payment has been created.';
        }
        header('Location: '.($preview?'./preview-live.php?customer_id='.(int)$customer['id'].'&':'./?').'tab='.$target,true,303);exit;
    }catch(InvalidArgumentException $e){$GLOBALS['portalExtrasError']=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH portal extras: '.$e->getMessage());$GLOBALS['portalExtrasError']='Your request could not be saved. Please try again.';}
}
function portalExtrasReview(PDO $store,string $type,int $id,string $decision,string $note):void {
    if(!in_array($type,['delivery','next'],true)||$id<1||!in_array($decision,['approve','decline'],true))throw new InvalidArgumentException('Review details are invalid.');
    $note=portalExtrasText($note,500);
    $table=$type==='delivery'?'portal_delivery_requests':'portal_next_order_requests';
    portalExtrasInit($store);$store->exec('BEGIN IMMEDIATE');
    try {
        $q=$store->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row || $row['status']!=='Pending')throw new InvalidArgumentException('This request was already reviewed.');
        if($type==='delivery' && $decision==='approve'){
            $store->prepare('INSERT INTO portal_delivery_preferences(customer_id,preview,address,notes,contact_method,updated_at) VALUES(?,?,?,?,?,?) ON CONFLICT(customer_id,preview) DO UPDATE SET address=excluded.address,notes=excluded.notes,contact_method=excluded.contact_method,updated_at=excluded.updated_at')
                ->execute([(int)$row['customer_id'],(int)$row['preview'],$row['address'],$row['notes'],$row['contact_method'],time()]);
        }
        // A next-order approval is an acknowledgement to arrange manually in Admin.
        // It MUST NOT mark an order placed, suppress cycles, or modify Admin scheduling.
        $store->prepare('UPDATE '.$table.' SET status=?,reviewer_note=?,reviewed_at=? WHERE id=? AND status=\'Pending\'')
            ->execute([$decision==='approve'?'Approved':'Declined',$note,time(),$id]);
        $store->exec('COMMIT');
    }catch(Throwable $e){$store->exec('ROLLBACK');throw $e;}
}
function portalExtrasAvailabilitySave(PDO $store,PDO $admin,int $productId,string $label):void {
    if($productId<1||!in_array($label,['automatic','available','limited','coming_soon','unavailable'],true))throw new InvalidArgumentException('Invalid availability setting.');
    $q=$admin->prepare('SELECT id FROM products WHERE id=? AND active=1');$q->execute([$productId]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Product is no longer active.');
    // Publishing visibility is still controlled in Admin, not in this form.
    portalExtrasInit($store);
    if($label==='automatic')$store->prepare('DELETE FROM portal_product_availability WHERE product_id=?')->execute([$productId]);
    else $store->prepare('INSERT INTO portal_product_availability(product_id,label,updated_at) VALUES(?,?,?) ON CONFLICT(product_id) DO UPDATE SET label=excluded.label,updated_at=excluded.updated_at')->execute([$productId,$label,time()]);
}
function portalExtrasStaffController(PDO $store,PDO $admin):void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!in_array($_POST['action']??null,['portal_review','portal_availability'],true))return;
    try {
        if($_POST['action']==='portal_review'){
            $id=filter_var($_POST['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
            portalExtrasReview($store,(string)($_POST['request_type']??''),$id,(string)($_POST['decision']??''),(string)($_POST['reviewer_note']??''));
        }else{
            $id=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
            portalExtrasAvailabilitySave($store,$admin,$id,(string)($_POST['label']??''));
        }
        $_SESSION['portal_staff_flash']='Saved successfully.';
    }catch(InvalidArgumentException $e){$_SESSION['portal_staff_flash']=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH staff portal extras: '.$e->getMessage());$_SESSION['portal_staff_flash']='Could not save your change.';}
    header('Location: ./preview-live.php?manage=portal&panel='.(($_POST['action']??'')==='portal_availability'?'availability':'requests'),true,303);exit;
}
function portalExtrasInsights(array $orders):array {
    $valid=array_values(array_filter($orders,static fn(array $o):bool=>(string)($o['status']??'')!=='Cancelled'));
    $total=array_sum(array_map(static fn(array $o):int=>(int)$o['total'],$valid));
    $paid=array_sum(array_map(static fn(array $o):int=>(int)$o['paid'],$valid));
    $delivered=array_values(array_filter($valid,static fn(array $o):bool=>portalStatus($o)==='Delivered'));
    usort($delivered,static fn(array $a,array $b):int=>strcmp((string)($b['delivery_date']??''),(string)($a['delivery_date']??'')));
    $months=[];$zone=new DateTimeZone('Europe/London');
    for($i=5;$i>=0;$i--){$d=(new DateTimeImmutable('first day of this month',$zone))->modify('-'.$i.' months');$months[$d->format('Y-m')]=['label'=>$d->format('M'),'value'=>0];}
    foreach($valid as $o){
        try{$key=(new DateTimeImmutable((string)$o['created']))->setTimezone($zone)->format('Y-m');}catch(Throwable $e){continue;}
        if(isset($months[$key]))$months[$key]['value']+=(int)$o['total'];
    }
    $biggest=max(1,...array_column(array_values($months),'value'));
    return ['count'=>count($valid),'total'=>$total,'paid'=>$paid,'outstanding'=>max(0,$total-$paid),
        'average'=>count($valid)?(int)round($total/count($valid)):0,
        'last_delivery'=>$delivered[0]['delivery_date']??null,
        'delivered'=>count($delivered),'months'=>$months,'max_month'=>$biggest];
}
function portalExtrasStaffPage(PDO $store,PDO $admin):void {
    portalExtrasInit($store);
    $availability=portalExtrasAvailabilityMap($store);
    $whatsapp=portalContactWhatsApp($store);
    $products=$admin->query('SELECT p.id,p.name,p.active FROM products p JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1 WHERE p.active=1 ORDER BY p.name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    $delivery=$store->query("SELECT * FROM portal_delivery_requests ORDER BY CASE status WHEN 'Pending' THEN 0 ELSE 1 END,id DESC LIMIT 70")->fetchAll(PDO::FETCH_ASSOC);
    $next=$store->query("SELECT * FROM portal_next_order_requests ORDER BY CASE status WHEN 'Pending' THEN 0 ELSE 1 END,id DESC LIMIT 70")->fetchAll(PDO::FETCH_ASSOC);
    $names=[];$customers=$admin->query('SELECT id,name FROM customers');foreach($customers as $c)$names[(int)$c['id']]=$c['name'];
    $flash=(string)($_SESSION['portal_staff_flash']??'');unset($_SESSION['portal_staff_flash']);
    $esc=static fn(mixed $v):string=>(string)htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $csrf=$esc($_SESSION['portal_csrf']??'');
    ?>
    <!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Customer tools · ANKH</title><link rel="stylesheet" href="./style.css"><link rel="stylesheet" href="./account.css?v=studio-4"><link rel="stylesheet" href="./cms-studio.css?v=product-docs-5"><link rel="stylesheet" href="./document-control.css?v=1"><link rel="stylesheet" href="./request-order-match.css?v=1"></head>
    <body class="pc-ops-body"><a class="cms-skip" href="#cms-main-content">Skip to workspace</a><main class="pc-ops">
    <?php $panel=(string)($_GET['panel']??'products');if(!in_array($panel,['products','documents','certificates','commerce','requests','availability','contact'],true))$panel='products'; ?>
    <?php $sectionTitles=['products'=>['Product CMS','Create, refine and publish your product pages.'],'certificates'=>['Certificates','Manage the documents customers can view.'],'documents'=>['Document Control','See SDS, COA and missing-document status across approved products.'],'commerce'=>['Storefront settings','Keep the details of your business up to date.'],'requests'=>['Customer requests','Review requests and delivery preferences.'],'availability'=>['Availability','Manage the availability labels on your website.'],'contact'=>['WhatsApp','Make it easy for customers to reach you.']]; ?>
    <header class="pc-ops-header"><span class="pc-eyebrow">WORKSPACE <span aria-hidden="true">/</span> <?=$esc($sectionTitles[$panel][0])?></span>
      <div class="pc-ops-heading"><div><h1><?=$esc($sectionTitles[$panel][0])?></h1><p><?=$esc($sectionTitles[$panel][1])?></p></div><a class="pc-outline-button" href="./preview-live.php?preview=customers">Customer preview →</a></div>
    </header>
    <?php ankhStaffNavigation($panel,(string)($_SESSION['portal_csrf']??'')); ?>
    <?php if($flash!==''):?><p class="pc-notification-feedback" role="status"><?=$esc($flash)?></p><?php endif;?>
    <div class="pc-ops-stack" id="cms-main-content" tabindex="-1">
    <?php if($panel==='products'):ankhCmsEditor($store,$admin,(string)($_SESSION['portal_csrf']??''));endif;?>
    <?php if($panel==='documents'):ankhDocumentControlPage($store,$admin);endif;?>
    <?php if($panel==='contact'):?><section class="pc-panel pc-ops-contact"><div class="pc-section-header"><div><span class="pc-eyebrow">CUSTOMER CONTACT</span><h2>WhatsApp Business</h2></div></div>
      <p class="pc-footnote">Enter the ANKH business WhatsApp number, with country code. Once saved, the Shop, Basket, Orders and Account screens show an instant-message link. The customer's order information is not automatically sent to WhatsApp. Leave blank to disable the button.</p>
      <form method="post" class="pc-ops-contact-form"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="portal_whatsapp">
        <label>Business WhatsApp number <input name="whatsapp" type="tel" inputmode="tel" placeholder="447700900000" autocomplete="off" maxlength="50" value="<?=$esc($whatsapp??'')?>"></label>
        <button type="submit">Save WhatsApp contact</button>
      </form>
      <?php if($whatsapp):?><p class="pc-footnote">Currently enabled · <a href="<?=$esc(portalContactWhatsAppUrl($whatsapp))?>" target="_blank" rel="noopener noreferrer">Open test chat ↗</a></p><?php else:?><p class="pc-footnote">Not yet configured. A WhatsApp button will appear after an approved number is saved.</p><?php endif;?>
    </section>
    <?php endif;?>
    <?php if($panel==='commerce'):sfStaffSection($store,$admin,$csrf);endif; ?>

    <?php if($panel==='requests'):?><section class="pc-panel"><div class="pc-section-header"><div><span class="pc-eyebrow">PRIVATE REQUESTS</span><h2>Next orders &amp; skips</h2></div><span class="pc-count"><?=count($next)?></span></div><p class="pc-footnote">Approve to acknowledge the request. Manually create orders or change recurring schedules in Admin. Approval here never places an order or changes an Admin date.</p>
    <?php if(!$next):?><p>No requests yet.</p><?php endif;?>
    <?php foreach($next as $r):?><details class="pc-ops-request"><summary><strong><?=$esc($names[(int)$r['customer_id']]??'Customer #'.$r['customer_id'])?></strong> · <?=$r['kind']==='skip'?'Skip cycle':'Request now'?> · <?=$esc($r['status'])?> <?=$r['preview']?'· STAFF TEST':''?></summary><p><?=$esc($r['product_name'])?> · Due <?=$esc($r['due_date'])?> · Source order #<?=(int)$r['source_order_id']?></p><p><?=$r['kind']==='skip'?'Requested to skip this cycle. An Admin schedule update may be needed.':'Customer would like the next order arranged. No order has been created.'?></p><?php if($r['status']==='Pending'):?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="portal_review"><input type="hidden" name="request_type" value="next"><input type="hidden" name="request_id" value="<?=(int)$r['id']?>"><label>Customer response (optional)<textarea name="reviewer_note" maxlength="500" rows="2"></textarea></label><div class="pc-ops-decisions"><button name="decision" value="approve">Accept request</button><button name="decision" value="decline">Decline request</button></div></form><?php else:?><p><?=$esc($r['reviewer_note'])?></p><?php endif;?></details><?php endforeach;?></section>
    <section class="pc-panel"><div class="pc-section-header"><div><span class="pc-eyebrow">DELIVERY</span><h2>Saved preferences</h2></div><span class="pc-count"><?=count($delivery)?></span></div><p class="pc-footnote">Accepting saves a preference in the customer portal only. If required, update the address in Admin separately.</p>
    <?php if(!$delivery):?><p>No changes requested yet.</p><?php endif;?>
    <?php foreach($delivery as $r):?><details class="pc-ops-request"><summary><strong><?=$esc($names[(int)$r['customer_id']]??'Customer #'.$r['customer_id'])?></strong> · <?=$esc($r['status'])?> <?=$r['preview']?'· STAFF TEST':''?></summary><p><strong>Preferred address</strong></p><p class="pc-ops-pre"><?=$esc($r['address'])?></p><p><strong>Delivery note</strong> · <?=$esc($r['notes']?:'None')?></p><p><strong>Contact</strong> · <?=$esc($r['contact_method'])?></p><?php if($r['status']==='Pending'):?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="portal_review"><input type="hidden" name="request_type" value="delivery"><input type="hidden" name="request_id" value="<?=(int)$r['id']?>"><label>Response (optional)<textarea name="reviewer_note" maxlength="500" rows="2"></textarea></label><div class="pc-ops-decisions"><button name="decision" value="approve">Approve preference</button><button name="decision" value="decline">Decline</button></div></form><?php else:?><p><?=$esc($r['reviewer_note'])?></p><?php endif;?></details><?php endforeach;?></section>
    <?php endif;?>
    <?php if($panel==='availability'):?><section class="pc-panel"><div class="pc-section-header"><div><span class="pc-eyebrow">THE COLLECTION</span><h2>Product availability labels</h2></div></div><p class="pc-footnote">Automatic labels use tracked stock without exposing quantities. A manual label never makes an unpublished product visible or overrides a zero tracked stock quantity into Available.</p>
    <div class="pc-ops-products"><?php foreach($products as $p):?><form method="post" class="pc-ops-product"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="portal_availability"><input type="hidden" name="product_id" value="<?=(int)$p['id']?>"><strong><?=$esc($p['name'])?></strong><select name="label" aria-label="Availability for <?=$esc($p['name'])?>"><?php foreach(['automatic'=>'Automatic','available'=>'Available','limited'=>'Limited','coming_soon'=>'Coming soon','unavailable'=>'Unavailable'] as $key=>$label):?><option value="<?=$key?>" <?=($availability[(int)$p['id']]??'automatic')===$key?'selected':''?>><?=$label?></option><?php endforeach;?></select><button type="submit">Save</button></form><?php endforeach;?></div>
    </section><?php endif;?></div></main><script src="./cms-editor.js?v=studio-4" defer></script><script src="./document-control.js?v=1" defer></script></body></html>
    <?php
}
