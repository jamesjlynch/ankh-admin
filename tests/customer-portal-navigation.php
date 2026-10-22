<?php
declare(strict_types=1);
/** Full five-tab render regression without customer PII or SMS credentials. */
$tab = $argv[1] ?? 'home';
$order = $argv[2] ?? '';
$expected = [
  'home' => ['At your fingertips','Recent activity','Payments recorded','Your order in focus'],
  'orders' => ['My orders','Find an order or product','ANK-0104'],
  'vault' => ['My vault','Order summaries','Batch documents'],
  'support' => ['Support centre','Email ANKH support','Delivery question'],
  'account' => ['My account','My details','Sampletown','Request my personal data'],
];
if(!isset($expected[$tab]))exit(2);
$_SERVER['HTTPS']='on';
$_SERVER['HTTP_HOST']='order.ankhpeptides.com';
$_SERVER['REQUEST_METHOD']='GET';
$_GET=['screen'=>'account','tab'=>$tab];
if($order!=='' && ctype_digit($order))$_GET['order']=(int)$order;
ob_start();
require dirname(__DIR__).'/customer-portal/preview.php';
$html=(string)ob_get_clean();
$checks=$order!=='' ? ($tab==='orders'?
    ['Order details','Order received','Payment history','Sample product A'] :
    ['Order summary','Print or save as PDF','Sample product B']) : $expected[$tab];
foreach($checks as $needle){
    if(!str_contains($html,$needle))throw new RuntimeException("The {$tab} page is missing expected text: {$needle}");
}
if(substr_count($html,'<nav class="pc-bottom-nav"')!==1)throw new RuntimeException('Mobile navigation missing or duplicated');
if(!str_starts_with(ltrim(strtolower($html)),'<!doctype html>'))throw new RuntimeException('Malformed document');
if(str_contains($html,'portalAllowAttempt(')||str_contains($html,'$_SESSION'))throw new RuntimeException('Raw PHP code leaked to response');
if(!str_contains($html,'DEMO PREVIEW'))throw new RuntimeException('Fictional preview notice missing');
echo "Customer {$tab}".($order!==''?' order '.$order:'')." rendering passed.\n";
