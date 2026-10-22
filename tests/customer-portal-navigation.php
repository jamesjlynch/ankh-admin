<?php
declare(strict_types=1);
/** Full five-tab render regression without customer PII or SMS credentials. */
$tab = $argv[1] ?? 'home';
$requestedOrderId = (string)($argv[2] ?? '');
$expected = [
  'catalogue' => ['Considered essentials','Everyday carry case','Add to test basket','Fictional products'],
  'basket' => ['Your basket','Your basket is waiting','Explore accessories'],
  'checkout' => ['Checkout','Add an accessory first'],
  'test-orders' => ['Your test requests','No test requests here yet'],
  'home' => ['At your fingertips','Recent activity','Payments recorded','Your order in focus'],
  'orders' => ['My orders','Find an order or product','ANK-0104'],
  'vault' => ['My vault','Order summaries','Batch documents'],
  'support' => ['Support centre','Email ANKH support','Delivery question'],
  'notifications' => ['Notifications','No notifications to show','All updates'],
  'recurring' => ['Recurring orders','No recurring orders set up','No order or payment is created automatically'],
  'account' => ['My account','My details','Sampletown','Request my personal data'],
];
if(!isset($expected[$tab]))exit(2);
$_SERVER['HTTPS']='on';
$_SERVER['HTTP_HOST']='order.ankhpeptides.com';
$_SERVER['REQUEST_METHOD']='GET';
$_GET=['screen'=>'account','tab'=>$tab];
if($requestedOrderId!=='' && ctype_digit($requestedOrderId))$_GET['order']=(int)$requestedOrderId;
ob_start();
require dirname(__DIR__).'/customer-portal/preview.php';
$html=(string)ob_get_clean();
$checks=$requestedOrderId!=='' ? ($tab==='orders'?
    ['ORDER DETAILS','Order received','Items in this order','Sample product A'] :
    ['Order summary','Print or save as PDF','Sample product B']) : $expected[$tab];
foreach($checks as $needle){
    if(!str_contains($html,$needle))throw new RuntimeException("The {$tab} page is missing expected text: {$needle}");
}
if(substr_count($html,'<nav class="pc-bottom-nav"')!==1)throw new RuntimeException('Mobile navigation missing or duplicated');
if(!str_starts_with(ltrim(strtolower($html)),'<!doctype html>'))throw new RuntimeException('Malformed document');
if(str_contains($html,'portalAllowAttempt(')||str_contains($html,'$_SESSION'))throw new RuntimeException('Raw PHP code leaked to response');
if(!str_contains($html,'DEMO PREVIEW'))throw new RuntimeException('Fictional preview notice missing');
echo "Customer {$tab}".($requestedOrderId!==''?' order '.$requestedOrderId:'')." rendering passed.\n";

