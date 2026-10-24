<?php
declare(strict_types=1);
/** Isolated read-only selector tests: no Admin/portal DB writes. */
function pfType(string $name):string {
    if(preg_match('/\b(?:pen|cartridge|case|starter kit)\b/i',$name))return
        preg_match('/\bretatrutide\b/i',$name)?'Research compound':'Research accessory';
    if(preg_match('/\b(?:stack|blend)\b/i',$name))return 'Multi-component research blend';
    return 'Research compound';
}
require_once dirname(__DIR__).'/customer-portal/storefront-design.php';
function verifyVariants(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
}
$products=[
 ['id'=>1,'name'=>'Retatrutide 10mg','information'=>['strength'=>'10mg','presentation'=>'Lyophilised powder in vial']],
 ['id'=>2,'name'=>'Retatrutide 20mg','information'=>['strength'=>'20mg','presentation'=>'Lyophilised powder in vial']],
 ['id'=>3,'name'=>'Retatrutide 20mg Cartridge','information'=>['strength'=>'20mg','presentation'=>'Cartridge']],
 ['id'=>4,'name'=>'Retatrutide 30mg With Pen','information'=>['strength'=>'30mg','presentation'=>'Pen and cartridge']],
 ['id'=>5,'name'=>'BPC-157 5mg','information'=>['strength'=>'5mg']],
 ['id'=>6,'name'=>'BPC-157 10mg','information'=>['strength'=>'10mg']],
 ['id'=>7,'name'=>'Glow Stack 20mg','information'=>['strength'=>'20mg']],
];
$selected=$products[1];
$related=pfVariants($selected,$products);
verifyVariants(count($related)===4,'Variant families mixed unrelated products');
$variantIds=array_column($related,'id');
verifyVariants(count($variantIds)===4 && !array_diff([1,2,3,4],$variantIds),'Variant relationships wrong');
verifyVariants($variantIds[0]===1 && $variantIds[3]===4,'Strength ordering wrong');
$options=pfVariantSelector($selected,$related);
verifyVariants(count($options['strengths'])===3,'Must offer three actual strengths');
verifyVariants(count($options['formats'])===2,'Must offer two actual 20mg formats');
$byStrength=[];foreach($options['strengths'] as $o)$byStrength[$o['label']]=$o;
verifyVariants($byStrength['10mg']['id']===1 && $byStrength['20mg']['id']===2 && $byStrength['30mg']['id']===4,
    'Strength targets must use real IDs and retain preferred format when possible');
verifyVariants($byStrength['20mg']['selected']===true,'Current strength not active');
$byFormat=[];foreach($options['formats'] as $o)$byFormat[$o['label']]=$o;
verifyVariants($byFormat['Vial']['id']===2 && $byFormat['Cartridge']['id']===3,'Format must remain on current strength');
verifyVariants($byFormat['Vial']['selected']===true,'Current format not active');
$hidden=array_values(array_filter($products,static fn($p)=>(int)$p['id']!==3));
$hiddenOptions=pfVariantSelector($selected,pfVariants($selected,$hidden));
verifyVariants(count($hiddenOptions['formats'])===0,'A hidden variant must not be advertised');
$single=pfVariantSelector($products[4],pfVariants($products[4],[$products[4]]));
verifyVariants(!$single['strengths']&&!$single['formats'],'No meaningless single-choice UI');
$info=[
 ['key'=>'sequence','label'=>'Sequence','value'=>'REAL SEQUENCE'],
 ['key'=>'formula','label'=>'Molecular formula','value'=>'C10H12N2'],
 ['key'=>'strength','label'=>'Strength','value'=>'20mg'],
 ['key'=>'cas','label'=>'CAS number','value'=>'123-45-6'],
 ['key'=>'storage','label'=>'Storage','value'=>'Recorded CMS storage'],
 ['key'=>'weight','label'=>'Molecular weight','value'=>'1234']
];
$highlights=pfSpecHighlights($info);
verifyVariants(count($highlights)===5,'Specification card should remain concise on mobile');
verifyVariants($highlights[0]['key']==='strength' && $highlights[1]['key']==='formula','Highlights need priority ordering');
verifyVariants(pfSpecHighlights([])===[],'Must not invent missing specifications');
echo "PASS: Variants only use real products, format/strength context stays correct, hidden products do not leak and CMS slide is factual.\n";
