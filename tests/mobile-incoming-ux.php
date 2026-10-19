<?php
declare(strict_types=1);
/**
 * Stage 2 mobile read-only regression: Stock Incoming.
 * Never loads the app, receives stock or mutates a database.
 */
$root=dirname(__DIR__);
$app=file_get_contents($root.'/index.php');
$css=file_get_contents($root.'/style.css');
function uxIncomingAssert(bool $ok,string $message):void{
 if(!$ok)throw new RuntimeException($message);
}
uxIncomingAssert(str_contains($app,'class="ux-skip-link"')&&preg_match('/<main\b[^>]*id="ankh-main"[^>]*tabindex="-1"/', $app)===1,'Skip-to-main keyboard navigation missing.');
uxIncomingAssert(str_contains($app,'<details class="incoming-by-product ux-incoming-products"')&&str_contains($app,'<summary class="incoming-by-product-head">'),'Incoming product quantities must be in a tap-to-expand section.');
uxIncomingAssert(str_contains($app,'id="incoming-by-product-title"')&&str_contains($app,'<?=$totalIncomingUnits?> units to receive'),'Incoming summary must retain outstanding unit counts.');
$start=strpos($app,'<?php foreach($openSupplierOrders as $supplierOrder):?><details class="supplier-order-card ux-incoming-order">');
$stop=$start===false?false:strpos($app,'<?php else:?><div class="incoming-empty">',$start);
uxIncomingAssert($start!==false&&$stop!==false,'Supplier orders must become expandable disclosure cards.');
$card=substr($app,$start,$stop-$start);
uxIncomingAssert(str_contains($card,'class="ux-incoming-order-summary"')&&str_contains($card,'class="ux-incoming-order-content"'),'Supplier summary/detail wiring missing.');
uxIncomingAssert(str_contains($card,'class="supplier-order-products"')&&str_contains($card,'class="supplier-product-row"'),'Supplier item rows must stay accessible inside the disclosure.');
uxIncomingAssert(str_contains($card,'name="action" value="supplier_stock_receive"')&&str_contains($card,'name="supplier_item_id"'),'Stock receive action and item ID must be preserved.');
foreach(['name="jay_qty"','name="tony_qty"','name="unallocated_qty"','name="return_section" value="incoming"'] as $field){
 uxIncomingAssert(str_contains($card,$field),'Missing existing stock receive allocation field: '.$field);
}
uxIncomingAssert(str_contains($card,"(int)$".'incomingItem'."['quantity']-(int)$".'incomingItem'."['received_qty']"),'Partial receipts must still subtract from due quantities.');
uxIncomingAssert(str_contains($card,'supplier_order_cancel')&&str_contains($card,'onsubmit="return confirm('),'Existing supplier cancellation guard missing.');
uxIncomingAssert(str_contains($app,'class="stock-workspace-nav stock-workspace-dock"'),'Stock workspace navigation must remain.');
uxIncomingAssert(str_contains($css,'.ux-incoming-order>summary')&&str_contains($css,'.ux-incoming-product-content'),'Readable mobile disclosure CSS absent.');
uxIncomingAssert(str_contains($css,'.ux-incoming-order-content .supplier-receive input{min-height:44px'),'Stock receipt touch targets must remain large.');
uxIncomingAssert(str_contains($css,':focus-visible')&&str_contains($css,'prefers-reduced-motion:reduce'),'Keyboard focus and reduced motion support missing.');
echo "PASS: Stage 2 iPhone Incoming Stock disclosures, navigation, readable tap targets and untouched receive forms.\n";
