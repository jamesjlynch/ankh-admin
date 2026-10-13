<?php
declare(strict_types=1);
require dirname(__DIR__).'/supplier-centre.php';
function basketAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$rows=[
 ['sku'=>'BC10','name'=>'BPC157','strength'=>'10mg','pack'=>10,'usd'=>6500,'product_id'=>1,'retail'=>2300],
 ['sku'=>'RT10','name'=>'Retatrutide','strength'=>'10mg','pack'=>10,'usd'=>9000,'product_id'=>2,'retail'=>6500],
 ['sku'=>'X5','name'=>'Unmapped','strength'=>'5mg','pack'=>10,'usd'=>2000,'product_id'=>null,'retail'=>null],
 ['sku'=>'ZERO','name'=>'Zero retail','strength'=>'5mg','pack'=>10,'usd'=>1000,'product_id'=>3,'retail'=>0],
];
$summary=sc_basket_profit_summary($rows,['BC10'=>1,'RT10'=>5,'X5'=>0],0.76,7000);
// 60 vials at full ANKH retail: £230 + £3,250 = £3,480.
// MJT: $65 + ($90 × 5) = $515; flat shipping $70 => $585 × 0.76 = £444.60.
// Potential gross profit after supplier shipping = £3,035.40.
basketAssert($summary['boxes']===6&&$summary['vials']===60,'Full supplier box quantities must drive vial count.');
basketAssert($summary['supplier_usd_cents']===51500&&$summary['shipping_usd_cents']===7000,'Single flat $70 shipping must be added only once.');
basketAssert($summary['priced_revenue_gbp_pence']===348000,'Projected sales must use ANKH retail price per individual vial.');
basketAssert($summary['landed_gbp_pence']===44460,'Supplier cost and flat USD shipping should be converted using saved FX.');
basketAssert($summary['profit_gbp_pence']===303540,'Projected gross profit must deduct purchasing and shipping from full retail.');
basketAssert(abs($summary['margin_percent']-(303540/348000*100))<0.001,'Projected margin must use projected retail revenue.');
$incomplete=sc_basket_profit_summary($rows,['BC10'=>1,'X5'=>1],0.76,7000);
basketAssert(count($incomplete['unpriced'])===1&&str_contains($incomplete['unpriced'][0],'X5'),'Unmapped selected products must be identified.');
basketAssert($incomplete['profit_gbp_pence']===null&&$incomplete['margin_percent']===null,'Do not claim whole-basket profit when unpriced products are selected.');
$zero=sc_basket_profit_summary($rows,['BC10'=>1,'ZERO'=>1],0.76,7000);
basketAssert($zero['profit_gbp_pence']===null&&count($zero['unpriced'])===1,'Zero selling prices must be treated as missing.');
$noFx=sc_basket_profit_summary($rows,['BC10'=>1],null,7000);
basketAssert($noFx['profit_gbp_pence']===null&&$noFx['landed_gbp_pence']===null,'Missing exchange rate must not result in invented GBP profit.');
$empty=sc_basket_profit_summary($rows,[],0.76,7000);
basketAssert($empty['boxes']===0&&$empty['shipping_usd_cents']===0&&$empty['profit_gbp_pence']===null,'Empty basket must not incur shipping or show profit.');
$loss=sc_basket_profit_summary($rows,[ 'BC10'=>1],0.76,100000);
basketAssert($loss['profit_gbp_pence']<0,'Real loss scenarios must display a negative projected margin.');
$module=file_get_contents(dirname(__DIR__).'/supplier-centre.php');
basketAssert(str_contains($module,'id="sc-basket-profit-value"')&&str_contains($module,'id="sc-basket-revenue"')&&str_contains($module,'id="sc-basket-landed"'),'Build Order must display projected profit, retail and supplier costs.');
basketAssert(str_contains($module,"'retail'=>\$r['retail']")&&str_contains($module,"r.boxes*r.pack*Number(r.retail)"),'Client-side totals must use live ANKH per-vial retail values and supplier box quantities.');
basketAssert(str_contains($module,'id="sc-quick-profit"')&&str_contains($module,'quickProfit.hidden=profit===null'),'Sticky basket profit preview must not display incomplete estimates.');
echo "PASS: supplier basket future retail revenue, one-time shipping, projected profit and margin, incomplete pricing, no FX, no purchase, and losses.\n";
