<?php
declare(strict_types=1);
/**
 * Staff-only, read-only candidate matching. Order Admin is authoritative.
 * A matching mobile number alone is NOT sufficient: the order must also belong
 * to the securely claimed customer (when claimed), or be uniquely identified
 * by name and phone (unclaimed), AND contain the same approved accessory SKUs.
 * Candidate suggestions never create, reserve, charge or update Admin orders.
 */
function ankhRequestMatchSource(PDO $store,PDO $admin,int $requestId):array {
    if($requestId<1)throw new InvalidArgumentException('Choose a valid accessory request.');
    sfCommerceInit($store);ankhGuestTrackingInit($store);ankhGuestOrderLinkInit($store);
    $q=$store->prepare('SELECT g.id,g.name,g.phone,g.placed_at,g.status,g.lines_json,c.customer_id
        FROM storefront_guest_requests g LEFT JOIN portal_guest_request_claims c ON c.request_id=g.id
        WHERE g.id=?');
    $q->execute([$requestId]);$request=$q->fetch(PDO::FETCH_ASSOC);
    if(!$request||(string)$request['status']!=='Reviewed — create in Admin manually')
        throw new InvalidArgumentException('Only staff-reviewed, approved accessory requests may be linked to existing orders.');
    $phone=portalMobile((string)$request['phone']);
    if(!$phone)throw new InvalidArgumentException('Request phone number cannot be verified.');
    try{$lines=json_decode((string)$request['lines_json'],true,24,JSON_THROW_ON_ERROR);}
    catch(Throwable $e){throw new InvalidArgumentException('Request products are not readable.');}
    if(!is_array($lines)||!array_is_list($lines)||count($lines)<1||count($lines)>20)
        throw new InvalidArgumentException('Request has no valid accessory products.');
    // Recheck the current live Admin + staff commerce approvals on every render
    // AND on every POST. This does not resurrect revoked/hidden products.
    $approved=sfCommerceEligible($store,$admin);
    $requested=[];
    foreach($lines as $line){
        if(!is_array($line)||!isset($line['id'],$line['name'],$line['quantity']))throw new InvalidArgumentException('Invalid request item.');
        $id=filter_var($line['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $qty=filter_var($line['quantity'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>99]]);
        if(!$id||!$qty||isset($requested[$id])||!isset($approved[$id])
            ||(string)$approved[$id]['name']!==(string)$line['name'])
            throw new InvalidArgumentException('A request product is no longer approved for accessory requests. Resolve its eligibility before linking.');
        $requested[$id]=['id'=>$id,'name'=>(string)$approved[$id]['name'],'quantity'=>$qty];
    }
    if($request['customer_id']!==null){
        $q=$admin->prepare('SELECT id,name,phone,address,archived FROM customers WHERE id=?');
        $q->execute([(int)$request['customer_id']]);$owner=$q->fetch(PDO::FETCH_ASSOC);
        if(!$owner||(int)$owner['archived']!==0||portalMobile((string)$owner['phone'])!==$phone)
            throw new InvalidArgumentException('The verified account owner has changed. Recheck identity.');
        $request['owner']=$owner;
        $request['owned_order_ids']=array_fill_keys(array_map(static fn($o)=>(int)$o['id'],portalOrderHistory($admin,$owner,$phone)),true);
    }else{
        // Unclaimed guest requests cannot assume that anyone sharing a phone
        // owns another person's order. Require an unambiguous Admin identity.
        $q=$admin->prepare('SELECT id,phone FROM customers WHERE lower(trim(name))=lower(trim(?))');
        $q->execute([trim((string)$request['name'])]);$owners=[];
        foreach($q as $row)if(portalMobile((string)$row['phone'])===$phone)$owners[]=(int)$row['id'];
        if(count($owners)!==1)
            throw new InvalidArgumentException('Multiple or no matching Admin customer identities. Ask the customer to claim their receipt securely before linking.');
        $request['unclaimed_customer_id']=$owners[0];
    }
    $request['normalised_phone']=$phone;
    $request['approved_lines']=$requested;
    return $request;
}
function ankhRequestMatchEvidence(PDO $admin,array $request,array $order):?array {
    $orderId=(int)($order['id']??0);
    if($orderId<1||portalMobile((string)($order['phone']??''))!==($request['normalised_phone']??null))return null;
    if(isset($request['owned_order_ids'])){
        if(!isset($request['owned_order_ids'][$orderId]))return null;
    }else{
        if(strcasecmp(trim((string)($order['customer']??'')),trim((string)$request['name']))!==0)return null;
        // If Admin has an authoritative saved owner, it must match the
        // uniquely resolved identity, even when name and phone coincide.
        $linked=(bool)$admin->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_order_links'")->fetchColumn();
        if($linked){
            $q=$admin->prepare('SELECT customer_id FROM customer_order_links WHERE order_id=?');
            $q->execute([$orderId]);$owner=$q->fetchColumn();
            if($owner!==false && (int)$owner!==(int)$request['unclaimed_customer_id'])return null;
        }
    }
    $cols=array_column($admin->query('PRAGMA table_info(items)')->fetchAll(PDO::FETCH_ASSOC),'name');
    $hasId=in_array('product_id',$cols,true);
    $q=$admin->prepare('SELECT name,quantity'.($hasId?',product_id':'').' FROM items WHERE order_id=? ORDER BY id ASC');
    $q->execute([$orderId]);$items=$q->fetchAll(PDO::FETCH_ASSOC);
    if(!$items || count($items)>60)return null;
    $requested=$request['approved_lines'];
    $seen=[];$exactQty=true;
    foreach($items as $item){
        $productId=$hasId?(int)($item['product_id']??0):0;
        // If Admin has an explicit product ID, never override it with a name match.
        if($productId>0){
            if(!isset($requested[$productId]))return null;
        }else{
            $matched=[];
            foreach($requested as $id=>$r)if(strcasecmp(trim((string)$item['name']),trim($r['name']))===0)$matched[]=$id;
            if(count($matched)!==1)return null;
            $productId=$matched[0];
        }
        $qty=(int)($item['quantity']??0);
        if($qty<1||$qty>99)return null;
        $seen[$productId]=($seen[$productId]??0)+$qty;
    }
    if(count($seen)!==count($requested))return null;
    foreach($requested as $id=>$line){
        if(!isset($seen[$id]))return null;
        if($seen[$id]!==$line['quantity'])$exactQty=false;
    }
    $placed=(int)$request['placed_at'];
    $created=strtotime((string)($order['created']??''))?:0;
    $days=$created>0?round(($created-$placed)/86400):null;
    $score=($exactQty?200:100)+($days===null?0:max(0,60-(int)abs($days)));
    // The full evidence stamp changes when request quantities/order line
    // quantities, ownership or eligibility changes before confirmation.
    $fingerprint=hash('sha256',json_encode([
        'request'=>$request['id'],'order'=>$orderId,'lines'=>$requested,
        'quantities'=>$seen,'phone'=>$request['normalised_phone'],
        'owner'=>$request['customer_id']??$request['unclaimed_customer_id']??0
    ],JSON_THROW_ON_ERROR));
    return ['order_id'=>$orderId,'status'=>(string)($order['status']??''),
        'created'=>(string)($order['created']??''),'days'=>$days,
        'exact_quantities'=>$exactQty,'score'=>$score,'fingerprint'=>$fingerprint];
}
function ankhRequestMatchSuggestions(PDO $store,PDO $admin,int $requestId,int $limit=5):array {
    $limit=max(1,min(10,$limit));
    $source=ankhRequestMatchSource($store,$admin,$requestId);
    $linked=[];
    foreach($store->query('SELECT order_id FROM portal_guest_order_links') as $row)$linked[(int)$row['order_id']]=true;
    $q=$admin->query('SELECT id,customer,phone,created,status FROM orders ORDER BY id DESC LIMIT 400');
    $found=[];
    foreach($q as $order){
        if(isset($linked[(int)$order['id']]))continue;
        $evidence=ankhRequestMatchEvidence($admin,$source,$order);
        if(!$evidence)continue;
        $days=$evidence['days'];
        // Suggestions are recent, as a guard against confusing old repeat orders.
        // Explicit lookup of an older order is still permitted via manual review.
        if($days!==null&&abs($days)>90)continue;
        $found[]=$evidence;
    }
    usort($found,static fn($a,$b)=>($b['score']<=>$a['score'])?:($b['order_id']<=>$a['order_id']));
    return array_slice($found,0,$limit);
}
function ankhRequestMatchValidate(PDO $store,PDO $admin,int $requestId,int $orderId):array {
    $request=ankhRequestMatchSource($store,$admin,$requestId);
    $q=$admin->prepare('SELECT id,customer,phone,created,status FROM orders WHERE id=?');
    $q->execute([$orderId]);$order=$q->fetch(PDO::FETCH_ASSOC);
    $match=$order?ankhRequestMatchEvidence($admin,$request,$order):null;
    if(!$match)throw new InvalidArgumentException('This order does not securely match the verified customer and approved accessory products.');
    return $match;
}
function ankhRequestMatchToken(array $match,string $csrf,int $requestId):string {
    if(strlen($csrf)<16)throw new InvalidArgumentException('Security token is missing.');
    return hash_hmac('sha256','ankh-request-order-v1:'.$requestId.':'.$match['order_id'].':'.$match['fingerprint'],$csrf);
}
