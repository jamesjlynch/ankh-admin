<?php
declare(strict_types=1);

/** Private catalogue request-list and restock watch features. No checkout. */
function portalCollectionInit(PDO $db):void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_request_list(
        customer_id INTEGER NOT NULL, preview INTEGER NOT NULL CHECK(preview IN (0,1)),
        product_id INTEGER NOT NULL, quantity INTEGER NOT NULL CHECK(quantity BETWEEN 1 AND 99),
        updated_at INTEGER NOT NULL,
        PRIMARY KEY(customer_id,preview,product_id)
    );
    CREATE TABLE IF NOT EXISTS portal_product_watches(
        customer_id INTEGER NOT NULL,preview INTEGER NOT NULL CHECK(preview IN (0,1)),
        product_id INTEGER NOT NULL, created_at INTEGER NOT NULL, last_available INTEGER NOT NULL DEFAULT 0,
        event_counter INTEGER NOT NULL DEFAULT 0,active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
        PRIMARY KEY(customer_id,preview,product_id)
    );
    CREATE TABLE IF NOT EXISTS portal_watch_events(
        customer_id INTEGER NOT NULL,preview INTEGER NOT NULL,product_id INTEGER NOT NULL,
        event_counter INTEGER NOT NULL, product_name TEXT NOT NULL, created_at INTEGER NOT NULL,
        PRIMARY KEY(customer_id,preview,product_id,event_counter)
    );
    CREATE INDEX IF NOT EXISTS portal_watch_events_customer ON portal_watch_events(customer_id,preview,created_at);");
}
function portalCollectionProduct(array $products,int $id):?array {
    foreach($products as $p)if((int)$p['id']===$id)return $p;
    return null;
}
function portalCollectionForCustomer(PDO $db,int $customerId,bool $preview,array $products,array $availability):array {
    portalCollectionInit($db);
    $q=$db->prepare('SELECT product_id,quantity FROM portal_request_list WHERE customer_id=? AND preview=? ORDER BY updated_at DESC,product_id');
    $q->execute([$customerId,(int)$preview]);$items=[];
    foreach($q as $row){
        $product=portalCollectionProduct($products,(int)$row['product_id']);
        if(!$product)continue;
        $state=portalExtrasAvailability($product,$availability);
        $items[]=['product'=>$product,'quantity'=>(int)$row['quantity'],'status'=>$state,
                  'can_request'=>!in_array($state['tone'],['coming','unavailable'],true)];
    }
    return $items;
}
function portalCollectionRevision(array $items):string {
    return hash('sha256',json_encode(array_map(static fn(array $line):array=>[
        (int)$line['product']['id'],(string)$line['product']['name'],(int)$line['product']['price'],
        (int)$line['quantity'],(string)$line['status']['tone'],(bool)$line['can_request']
    ],$items),JSON_THROW_ON_ERROR));
}
function portalCollectionSet(PDO $db,int $customerId,bool $preview,int $productId,int $quantity,array $products,array $availability):void {
    if($productId<1||$quantity<0||$quantity>99)throw new InvalidArgumentException('Choose a quantity between 1 and 99.');
    $product=portalCollectionProduct($products,$productId);
    if($quantity>0){
        if(!$product)throw new InvalidArgumentException('That product is no longer published.');
        $state=portalExtrasAvailability($product,$availability);
        if(in_array($state['tone'],['coming','unavailable'],true))throw new InvalidArgumentException('This product cannot be added while unavailable. Use the availability watch instead.');
    }
    portalCollectionInit($db);
    if($quantity===0){
        $q=$db->prepare('DELETE FROM portal_request_list WHERE customer_id=? AND preview=? AND product_id=?');
        $q->execute([$customerId,(int)$preview,$productId]);return;
    }
    $q=$db->prepare('SELECT COUNT(*) FROM portal_request_list WHERE customer_id=? AND preview=?');
    $q->execute([$customerId,(int)$preview]);
    $q=$db->prepare('SELECT 1 FROM portal_request_list WHERE customer_id=? AND preview=? AND product_id=?');
    $q->execute([$customerId,(int)$preview,$productId]);$exists=(bool)$q->fetchColumn();
    if(!$exists){
        $q=$db->prepare('SELECT COUNT(*) FROM portal_request_list WHERE customer_id=? AND preview=?');
        $q->execute([$customerId,(int)$preview]);
        if((int)$q->fetchColumn()>=20)throw new InvalidArgumentException('The request list can hold up to 20 different products.');
    }
    $db->prepare('INSERT INTO portal_request_list(customer_id,preview,product_id,quantity,updated_at) VALUES(?,?,?,?,?)
      ON CONFLICT(customer_id,preview,product_id) DO UPDATE SET quantity=excluded.quantity,updated_at=excluded.updated_at')
      ->execute([$customerId,(int)$preview,$productId,$quantity,time()]);
}
function portalCollectionSend(PDO $db,int $customerId,bool $preview,array $products,array $availability,string $revision,string $token,string $message,string $paymentPreference='discuss'):int {
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('Refresh the list and try again.');
    if(!in_array($paymentPreference,['discuss','bank_transfer'],true))
        throw new InvalidArgumentException('Choose a valid payment preference.');
    $message=trim($message);
    if(strlen($message)>8000||!preg_match('//u',$message)||preg_match_all('/./us',$message)>2000)throw new InvalidArgumentException('Enter a note of up to 2,000 characters.');
    ankhCustomerInteractionInit($db);portalCollectionInit($db);
    $q=$db->prepare('SELECT id FROM catalogue_enquiries WHERE customer_id=? AND preview=? AND request_token=?');
    $q->execute([$customerId,(int)$preview,$token]);if($id=$q->fetchColumn())return (int)$id;
    $items=portalCollectionForCustomer($db,$customerId,$preview,$products,$availability);
    if(!$items)throw new InvalidArgumentException('Your request list is empty. Add a product first.');
    if(!hash_equals(portalCollectionRevision($items),$revision))throw new InvalidArgumentException('A product or price has changed. Please review your list before sending.');
    if(count($items)>20)throw new InvalidArgumentException('Choose up to 20 products.');
    foreach($items as $item)if(!$item['can_request'])throw new InvalidArgumentException('An item is unavailable. Remove it before sending the request.');
    $lines=array_map(static fn(array $item):array=>[
        'product_id'=>(int)$item['product']['id'],
        'name'=>(string)$item['product']['name'],
        'quantity'=>(int)$item['quantity'],
        'unit_price'=>(int)$item['product']['price']
    ],$items);
    $total=array_sum(array_map(static fn(array $line):int=>$line['unit_price']*$line['quantity'],$lines));
    $body="Please review the products in my request list and confirm the details and availability. This is an enquiry, not a completed order.";
    $body.="\n\nPayment preference: ".($paymentPreference==='bank_transfer'
        ? 'Tide bank transfer (only after ANKH confirms the order and provides verified instructions).'
        : 'Discuss payment method with ANKH after confirmation.');
    if($message!=='')$body.="\n\nCustomer note: ".$message;
    $id=ankhCustomerCreateEnquiry($db,['id'=>0,'name'=>'Multi-product request','price'=>$total],$customerId,$preview,$body,$token,$lines);
    // Clear only after the enquiry and its item snapshots were recorded.
    $db->prepare('DELETE FROM portal_request_list WHERE customer_id=? AND preview=?')->execute([$customerId,(int)$preview]);
    return $id;
}
function portalWatches(PDO $db,int $customerId,bool $preview):array {
    portalCollectionInit($db);
    $q=$db->prepare('SELECT * FROM portal_product_watches WHERE customer_id=? AND preview=? AND active=1 ORDER BY created_at DESC');
    $q->execute([$customerId,(int)$preview]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function portalWatchSet(PDO $db,int $customerId,bool $preview,array $product,array $availability,bool $enabled):void {
    portalCollectionInit($db);
    $id=(int)$product['id'];
    $state=portalExtrasAvailability($product,$availability);
    if($enabled && in_array($state['tone'],['available','limited'],true))throw new InvalidArgumentException('This product is already available. You can add it to your request list.');
    if(!$enabled){
        $db->prepare('UPDATE portal_product_watches SET active=0 WHERE customer_id=? AND preview=? AND product_id=?')
            ->execute([$customerId,(int)$preview,$id]);
        return;
    }
    $q=$db->prepare('SELECT COUNT(*) FROM portal_product_watches WHERE customer_id=? AND preview=? AND active=1');
    $q->execute([$customerId,(int)$preview]);
    $q=$db->prepare('SELECT active FROM portal_product_watches WHERE customer_id=? AND preview=? AND product_id=?');
    $q->execute([$customerId,(int)$preview,$id]);$active=$q->fetchColumn();
    if((int)$active===1)return;
    $q=$db->prepare('SELECT COUNT(*) FROM portal_product_watches WHERE customer_id=? AND preview=? AND active=1');
    $q->execute([$customerId,(int)$preview]);
    if((int)$q->fetchColumn()>=25)throw new InvalidArgumentException('You can watch up to 25 products.');
    $db->prepare('INSERT INTO portal_product_watches(customer_id,preview,product_id,created_at,last_available,active) VALUES(?,?,?,?,0,1)
      ON CONFLICT(customer_id,preview,product_id) DO UPDATE SET active=1,created_at=excluded.created_at,last_available=0')
       ->execute([$customerId,(int)$preview,$id,time()]);
}
/**
 * Detect restocks on authenticated visits; never claims a live push service.
 * One event per unavailable -> available transition observed by the portal.
 * Only products still published to the customer can be resolved or named.
 */
function portalWatchRefresh(PDO $db,int $customerId,bool $preview,array $products,array $availability):array {
    $watches=portalWatches($db,$customerId,$preview);
    foreach($watches as $watch){
        $product=portalCollectionProduct($products,(int)$watch['product_id']);
        if(!$product)continue;
        $state=portalExtrasAvailability($product,$availability);
        $available=in_array($state['tone'],['available','limited'],true)?1:0;
        if($available===(int)$watch['last_available'])continue;
        $db->exec('BEGIN IMMEDIATE');
        try{
            $q=$db->prepare('SELECT active,last_available,event_counter FROM portal_product_watches WHERE customer_id=? AND preview=? AND product_id=?');
            $q->execute([$customerId,(int)$preview,(int)$product['id']]);$current=$q->fetch(PDO::FETCH_ASSOC);
            if($current && (int)$current['active']===1 && (int)$current['last_available']!==$available){
                $counter=(int)$current['event_counter'];
                if($available===1){
                    $counter++;
                    $db->prepare('INSERT OR IGNORE INTO portal_watch_events(customer_id,preview,product_id,event_counter,product_name,created_at) VALUES(?,?,?,?,?,?)')
                       ->execute([$customerId,(int)$preview,(int)$product['id'],$counter,(string)$product['name'],time()]);
                }
                $db->prepare('UPDATE portal_product_watches SET last_available=?,event_counter=? WHERE customer_id=? AND preview=? AND product_id=?')
                   ->execute([$available,$counter,$customerId,(int)$preview,(int)$product['id']]);
            }
            $db->exec('COMMIT');
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    }
    $q=$db->prepare('SELECT e.* FROM portal_watch_events e INNER JOIN portal_product_watches w ON
      w.customer_id=e.customer_id AND w.preview=e.preview AND w.product_id=e.product_id
      WHERE e.customer_id=? AND e.preview=? AND w.active=1 ORDER BY e.created_at DESC LIMIT 50');
    $q->execute([$customerId,(int)$preview]);
    $events=[];
    foreach($q as $row){
        // Never link or surface a product withdrawn from the customer catalogue.
        if(!portalCollectionProduct($products,(int)$row['product_id']))continue;
        $events[]=[
            'key'=>hash('sha256','watch:'.$customerId.':'.(int)$preview.':'.$row['product_id'].':'.$row['event_counter']),
            'kind'=>'restock','title'=>'A watched product became available',
            'body'=>(string)$row['product_name'].' · Review current availability before sending a request.',
            'tab'=>'catalogue','params'=>['product'=>(int)$row['product_id']],
            'priority'=>0,'sort'=>(int)$row['created_at'],'label'=>'Availability alert'
        ];
    }
    return $events;
}
function portalCollectionController(PDO $store,PDO $admin,array $customer,bool $preview):void {
    $action=$_POST['action']??null;
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!in_array($action,['collection_set','collection_send','watch_set'],true))return;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)($_SESSION['portal_csrf']??''),$_POST['csrf'])){http_response_code(400);exit('Invalid request.');}
    $target='catalogue';
    try{
        portalCollectionInit($store);
        $products=ankhCatalogueProducts($admin,(int)$customer['id']);
        $availability=portalExtrasAvailabilityMap($store);
        if($action==='collection_set'){
            $id=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
            $qty=filter_var($_POST['quantity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>99]]);
            if($qty===false||$qty===null)throw new InvalidArgumentException('Choose between 0 and 99 items.');
            portalCollectionSet($store,(int)$customer['id'],$preview,$id,(int)$qty,$products,$availability);
            $_SESSION['collection_flash']=$qty===0?'Product removed from your request list.':'Request list updated.';
            $target=match((string)($_POST['return_to']??'')){'list'=>'basket','home'=>'home',default=>'catalogue'};
            if($target==='catalogue' && (int)($_POST['return_product']??0)===$id)$target='catalogue&product='.$id;
        }elseif($action==='collection_send'){
            $target='request-list';
            if(!is_string($_POST['revision']??null)||!is_string($_POST['request_token']??null)||!is_string($_POST['message']??null))throw new InvalidArgumentException('Refresh the request list and try again.');
            $enquiryId=portalCollectionSend($store,(int)$customer['id'],$preview,$products,$availability,$_POST['revision'],$_POST['request_token'],$_POST['message'],is_string($_POST['payment_preference']??null)?$_POST['payment_preference']:'discuss');
            $_SESSION['collection_flash']='Your combined request has been sent to ANKH. No order or payment has been created.';
            $target='order-requested&enquiry='.(int)$enquiryId;
        }else{
            $id=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
            $product=portalCollectionProduct($products,$id);
            if(!$product)throw new InvalidArgumentException('That product is no longer published.');
            $enabled=($_POST['enabled']??'')==='1';
            portalWatchSet($store,(int)$customer['id'],$preview,$product,$availability,$enabled);
            $_SESSION['collection_flash']=$enabled?'Product watch saved. An in-app alert will appear after the portal detects availability.':'Product watch removed.';
            $target=(($_POST['return_to']??'')==='watchlist')?'watchlist':('catalogue&product='.$id);
        }
        header('Location: '.($preview?'./preview-live.php?customer_id='.(int)$customer['id'].'&':'./?').'tab='.$target,true,303);exit;
    }catch(InvalidArgumentException $e){$GLOBALS['collectionError']=$e->getMessage();$GLOBALS['collectionErrorTab']=$target;}
    catch(Throwable $e){error_log('ANKH private request list: '.$e->getMessage());$GLOBALS['collectionError']='Could not save that change. Please try again.';$GLOBALS['collectionErrorTab']=$target;}
}
