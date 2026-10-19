<?php
declare(strict_types=1);
/**
 * Non-mutating mobile UX regression for the ANKH server-rendered app.
 * Browser controls are progressive enhancements; all navigation uses ordinary links.
 */
$root=dirname(__DIR__);
$app=file_get_contents($root.'/index.php');
$css=file_get_contents($root.'/style.css');
function uxCheck(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
uxCheck(substr_count($app,'class="ux-mobile-actions"')===1,'Exactly one dashboard mobile quick-action strip is expected.');
foreach(['?view=new','?view=orders&amp;scope=to_deliver','?view=stock','?view=supplier&amp;tab=order','?view=reports'] as $route){
 uxCheck(str_contains($app,'href="'.$route.'"'),'The dashboard needs working one-tap quick links: '.$route);
}
uxCheck(str_contains($app,'class="ux-mobile-scopes order-view-nav"')&&str_contains($app,'aria-label="Order views"'),'Orders must offer an accessible quick-filter strip.');
foreach(['?view=orders&amp;scope=unpaid','?view=orders&amp;scope=to_deliver','?view=orders&amp;section=recurring'] as $route){
 uxCheck(str_contains($app,'href="'.$route.'"'),'Orders scope link missing: '.$route);
}
uxCheck(str_contains($app,'class="filters ux-order-filters '),'Order search/status filters must have compact mobile styling.');
uxCheck(str_contains($app,'id="ux-order-visible"')&&str_contains($app,'id="ux-customer-visible"'),'Live result counters needed for orders and customers.');
uxCheck(str_contains($app,"label.textContent=visible+' order'")&&str_contains($app,"label.textContent=visible+' customer'"),'Live result counters must update during searches.');
uxCheck(str_contains($app,'<details class="ux-report-options" open>')&&str_contains($app,'Dates, filters &amp; exports'),'Secondary report options need a disclosure, open by default without JS.');
uxCheck(str_contains($app,'report.open=false')&&str_contains($app,'matchMedia'), 'The report-options disclosure must collapse only for small screens with JavaScript.');
uxCheck(!str_contains($app,'active.scrollIntoView('),'Active tab should never auto-scroll the entire iPhone page.');
uxCheck(str_contains($app,'strip.scrollLeft+='),'Selected horizontal nav tabs should scroll into view without a page jump.');
uxCheck(str_contains($css,'.ux-mobile-actions a{')&&str_contains($css,'min-height:50px'),'Mobile quick actions must be tap-friendly.');
uxCheck(str_contains($css,'.ux-mobile-scopes a{')&&str_contains($css,'min-height:43px'),'Order filter chips must be tap-friendly.');
uxCheck(str_contains($css,'.more-grid:not(.utility-grid){grid-template-columns:repeat(2'),'Mobile More menu should use compact two-up tiles.');
uxCheck(str_contains($css,'.ux-report-options>summary:focus-visible'),'Keyboard focus must remain visible on report options.');
uxCheck(str_contains($css,'@media(prefers-reduced-motion:reduce)'),'Reduce motion for accessible mobile use.');
uxCheck(str_contains($css,'.orders-section-tabs{display:none}')&&str_contains($css,'.dashboard-heading .page-action{display:none}'),'Do not duplicate mobile orders tabs or new-order actions.');
uxCheck(str_contains($app,'sc_setup($db);')&&str_contains($app,"sc_render(\$db,\$stockProducts,\$reservedStockByProduct,\$incomingByProduct"),'Supplier Centre must remain accessible in the bundled app.');
echo "PASS: accessible compact iPhone quick actions, reports disclosure, live filters and no vertical nav jumps.\n";
