<?php
declare(strict_types=1);
/** Isolated accessory walkthrough. Never reads products, writes orders or sends messages. */
function pcdProducts(): array {
    return [
        'carry-case'=>['name'=>'Everyday carry case','category'=>'Storage','description'=>'A compact zipped case for organising everyday accessories.','price'=>2000,'stock'=>12,'art'=>'case'],
        'desk-organiser'=>['name'=>'Desk organiser','category'=>'Storage','description'=>'Keep small stationery and everyday essentials neatly together.','price'=>1200,'stock'=>8,'art'=>'organiser'],
        'label-sheets'=>['name'=>'Blank label sheets','category'=>'Stationery','description'=>'A pack of ten blank adhesive sheets for your own labels.','price'=>500,'stock'=>20,'art'=>'labels'],
        'notebook'=>['name'=>'Pocket notebook','category'=>'Stationery','description'=>'A simple lined notebook for notes, lists and reminders.','price'=>600,'stock'=>0,'art'=>'notebook'],
    ];
}
function pcdLines(array $cart): array {
    $products=pcdProducts(); $lines=[];
    foreach($cart as $id=>$quantity) {
        if(!isset($products[$id]) || !is_int($quantity) || $quantity<1 || $quantity>min(10,$products[$id]['stock'])) {
            throw new InvalidArgumentException('A basket item is unavailable. Remove it and try again.');
        }
        $p=$products[$id];
        $lines[]=['id'=>$id,'name'=>$p['name'],'quantity'=>$quantity,'price'=>$p['price'],'total'=>$p['price']*$quantity];
    }
    return $lines;
}
function pcdTotal(array $lines): int { return array_sum(array_column($lines,'total')); }
function pcdCartChange(array $cart, string $id, mixed $raw, bool $add): array {
    $products=pcdProducts();
    if(!isset($products[$id]) || !is_scalar($raw)) throw new InvalidArgumentException('Choose an available accessory.');
    $quantity=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>$add?1:0,'max_range'=>10]]);
    if($quantity===false) throw new InvalidArgumentException('Choose a whole quantity between '.($add?'1':'0').' and 10.');
    $next=$add?($cart[$id]??0)+$quantity:$quantity;
    if($next>min(10,$products[$id]['stock'])) throw new InvalidArgumentException('That quantity exceeds the sample availability.');
    if($next===0) unset($cart[$id]); else $cart[$id]=$next;
    pcdLines($cart);
    return $cart;
}
function pcdCheckout(array &$state, array $input): array {
    $token=$input['checkout_token']??'';
    if(!is_string($token)) throw new InvalidArgumentException('Refresh the checkout and try again.');
    // Retried submissions resolve to the same test confirmation, never another order.
    foreach($state['orders'] as $order) if(hash_equals($order['token'],$token)) return $order;
    if(!hash_equals($state['checkout_token'],$token)) throw new InvalidArgumentException('Your basket changed. Review checkout again.');
    $lines=pcdLines($state['cart']);
    if(!$lines) throw new InvalidArgumentException('Add an accessory before checking out.');
    $method=$input['delivery_method']??'';
    if(!is_string($method) || !in_array($method,['collection','delivery'],true)) throw new InvalidArgumentException('Choose collection or delivery.');
    $address=$input['address']??''; $postcode=$input['postcode']??''; $notes=$input['notes']??'';
    foreach([$address,$postcode,$notes] as $text) if(!is_string($text)) throw new InvalidArgumentException('Check your delivery details.');
    $address=trim($address); $postcode=strtoupper(trim($postcode)); $notes=trim($notes);
    if(strlen($address)>500 || strlen($notes)>500 || strlen($postcode)>12) throw new InvalidArgumentException('Keep the address and notes under 500 characters and check the postcode.');
    if($method==='delivery' && (strlen($address)<8 || !preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?\s*[0-9][A-Z]{2}$/D',$postcode))) throw new InvalidArgumentException('Enter a delivery address and valid UK postcode, or choose collection.');
    if(($input['acknowledge']??'')!=='yes') throw new InvalidArgumentException('Confirm that this is a test submission.');
    $delivery=$method==='delivery'?350:0;
    $order=['ref'=>'TEST-'.strtoupper(bin2hex(random_bytes(4))),'token'=>$token,'created'=>gmdate('c'),'lines'=>$lines,'subtotal'=>pcdTotal($lines),'delivery'=>$delivery,'total'=>pcdTotal($lines)+$delivery,'method'=>$method,'address'=>$method==='delivery'?$address:'','postcode'=>$method==='delivery'?$postcode:'','notes'=>$notes,'status'=>'Test request received'];
    array_unshift($state['orders'],$order); $state['orders']=array_slice($state['orders'],0,10);
    $state['cart']=[]; unset($state['draft']); $state['checkout_token']=bin2hex(random_bytes(24));
    return $order;
}
function pcdHandle(array $customer, bool $staff, bool $demo): void {
    if(!$staff && !$demo) return;
    $owner=($staff?'staff:':'demo:').(int)$customer['id'];
    if(($_SESSION['catalogue_demo']['owner']??'')!==$owner) {
        $_SESSION['catalogue_demo']=['owner'=>$owner,'cart'=>[],'orders'=>[],'checkout_token'=>bin2hex(random_bytes(24))];
    }
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || !isset($_POST['catalogue_action'])) return;
    if(!is_string($_POST['csrf']??null) || !hash_equals((string)$_SESSION['portal_csrf'],$_POST['csrf'])) {
        http_response_code(400); exit('Invalid request. Refresh and try again.');
    }
    $state=&$_SESSION['catalogue_demo'];
    $action=is_string($_POST['catalogue_action'])?$_POST['catalogue_action']:'';
    $tab='basket'; $params=[];
    try {
        if(in_array($action,['add','update','remove'],true)) {
            $id=is_string($_POST['product_id']??null)?$_POST['product_id']:'';
            $state['cart']=pcdCartChange($state['cart'],$id,$action==='remove'?0:($_POST['quantity']??null),$action==='add');
            $state['checkout_token']=bin2hex(random_bytes(24));
            $state['flash']=$action==='add'?'Accessory added to your test basket.':'Test basket updated.';
        } elseif($action==='checkout') {
            $order=pcdCheckout($state,$_POST);$tab='confirmation';$params=['test_ref'=>$order['ref']];
        } elseif($action==='clear') {
            $state['cart']=[];$state['orders']=[];$state['checkout_token']=bin2hex(random_bytes(24));$tab='catalogue';$state['flash']='Your test basket and test requests have been cleared.';
        } else { throw new InvalidArgumentException('Choose a valid catalogue action.'); }
    } catch(InvalidArgumentException $e) {
        $state['error']=$e->getMessage();$tab=$action==='checkout'?'checkout':'basket';
        if($action==='checkout') {
            $state['draft']=[];
            foreach(['delivery_method','address','postcode','notes'] as $key) $state['draft'][$key]=is_string($_POST[$key]??null)?substr($_POST[$key],0,$key==='postcode'?12:500):'';
        }
    }
    $query=array_merge($staff?['customer_id'=>(int)$customer['id']]:['screen'=>'account'],['tab'=>$tab],$params);
    header('Location: '.($staff?'./preview-live.php':'./preview.php').'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),true,303);
    exit;
}
