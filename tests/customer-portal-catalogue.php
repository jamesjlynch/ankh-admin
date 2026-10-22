<?php
declare(strict_types=1);
require dirname(__DIR__).'/customer-portal/catalogue-demo.php';
function expect(bool $value,string $label): void { if(!$value)throw new RuntimeException($label); }
function rejects(callable $fn,string $label):void {
    try {$fn();}catch(InvalidArgumentException $e){return;}
    throw new RuntimeException($label);
}
$cart=pcdCartChange([],'carry-case','2',true);
expect(pcdTotal(pcdLines($cart))===4000,'Calculate price from server product list');
rejects(fn()=>pcdCartChange($cart,'not-in-catalogue',1,true),'Reject unknown product');
rejects(fn()=>pcdCartChange($cart,'notebook',1,true),'Reject unavailable accessory');
foreach(['-1','1.5','100',[],null] as $bad) rejects(fn()=>pcdCartChange($cart,'carry-case',$bad,true),'Reject invalid quantity');
rejects(fn()=>pcdCartChange($cart,'carry-case',9,true),'Reject combined quantity above limit');
expect(pcdCartChange($cart,'carry-case','0',false)===[],'Zero quantity removes line');
$state=['cart'=>$cart,'orders'=>[],'checkout_token'=>str_repeat('a',48)];
$input=['checkout_token'=>$state['checkout_token'],'delivery_method'=>'delivery','address'=>'12 Fictional Street','postcode'=>'SW1A 1AA','notes'=>'<script>bad()</script>','acknowledge'=>'yes','total'=>1,'price'=>1];
$invalid=$input;$invalid['postcode']='invalid';
rejects(function()use(&$state,$invalid){pcdCheckout($state,$invalid);},'Require valid delivery postcode');
expect(count($state['orders'])===0 && count($state['cart'])===1,'Validation does not modify basket or orders');
$invalid=$input;$invalid['acknowledge']='no';
rejects(function()use(&$state,$invalid){pcdCheckout($state,$invalid);},'Require explicit test acknowledgement');
$invalid=$input;$invalid['checkout_token']='stale';
rejects(function()use(&$state,$invalid){pcdCheckout($state,$invalid);},'Reject stale checkout');
$order=pcdCheckout($state,$input);
expect($order['total']===4350 && $order['delivery']===350,'Ignore tampered prices and calculate delivery on server');
expect(str_starts_with($order['ref'],'TEST-'),'Test reference is unmistakable');
expect($state['cart']===[] && count($state['orders'])===1,'Successful checkout empties basket');
$again=pcdCheckout($state,$input);
expect($again['ref']===$order['ref'] && count($state['orders'])===1,'Duplicate checkout is idempotent');
$state['cart']=['label-sheets'=>1];$input['checkout_token']=$state['checkout_token'];$input['delivery_method']='collection';$input['address']='';$input['postcode']='';
$order=pcdCheckout($state,$input);
expect($order['total']===500 && $order['address']==='' && $order['delivery']===0,'Collection does not require address or charge delivery');
$_SERVER['REQUEST_METHOD']='GET';$_SESSION=[];
pcdHandle(['id'=>1],true,false);$_SESSION['catalogue_demo']['cart']=['carry-case'=>1];
pcdHandle(['id'=>2],true,false);
expect($_SESSION['catalogue_demo']['cart']===[],'Switching customers clears prior basket');
$_SESSION['catalogue_demo']['cart']=['carry-case'=>1];pcdHandle(['id'=>2],false,false);
expect($_SESSION['catalogue_demo']['cart']===['carry-case'=>1],'Production mode cannot mutate demo state');
echo "Accessory preview quantity, pricing, validation, duplicate submission and isolation checks passed.\n";
