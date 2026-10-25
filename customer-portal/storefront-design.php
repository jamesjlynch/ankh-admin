<?php
declare(strict_types=1);
/** Read-only presentation helpers. Never change product IDs, source names or visibility. */
function pfStrength(array $product):string {
    $s=(string)($product['information']['strength']??'');
    if($s!=='' && preg_match('/^.{1,35}$/u',$s))return $s;
    if(preg_match('/\b(\d+(?:\.\d+)?\s*(?:mg|mcg|µg|g|ml))\b/iu',(string)$product['name'],$m))return str_replace(' ','',$m[1]);
    return '';
}
function pfFamily(string $name):string {
    $name=preg_replace('/\b\d+(?:\.\d+)?\s*(?:mg|mcg|µg|g|ml)\b/iu','',$name)??$name;
    return strtolower(trim(preg_replace('/\s+|[—–]/u',' ',$name)??$name));
}
function pfFormat(array $p):string {
    $text=(string)($p['information']['presentation']??'');
    if($text===''){
        if(preg_match('/\b(?:cartridge|pen|vial|powder|solution|case|pack)\b/i',(string)$p['name'],$m))$text=$m[0];
    }
    return trim($text)===''?'Other':(mbSafeTitle($text));
}
function mbSafeTitle(string $text):string {
    // No mbstring runtime dependency on shared hosting.
    $text=trim(preg_replace('/\s+/u',' ',$text)??$text);
    return strlen($text)>75?substr($text,0,75):$text;
}
function pfFacets(array $products):array {
    $strength=[];$formats=[];
    foreach($products as $p) {
        $s=pfStrength($p);if($s!=='')$strength[$s]=($strength[$s]??0)+1;
        $f=pfFormat($p);$formats[$f]=($formats[$f]??0)+1;
    }
    uksort($strength,'strnatcasecmp');uksort($formats,'strnatcasecmp');
    // Keep only meaningful, succinct format facets.
    $formats=array_filter($formats,static fn($qty,$name)=>$qty>=1 && strlen((string)$name)<=35,ARRAY_FILTER_USE_BOTH);
    return ['strength'=>$strength,'format'=>$formats];
}
function pfMatches(array $p,array $filter):bool {
    if($filter['category']!=='All research' && pfCategory($p)!==$filter['category'])return false;
    if($filter['strength']!==''&& pfStrength($p)!==$filter['strength'])return false;
    if($filter['format']!==''&& pfFormat($p)!==$filter['format'])return false;
    if($filter['search']!==''){
        $info=(array)($p['information']??[]);
        $text=(string)$p['name'].' '.pfCategory($p).' '.pfType((string)$p['name']).' '.(string)($info['research_focus']??'').' '.(string)($info['peptide_type']??'').' '.pfStrength($p);
        if(stripos($text,$filter['search'])===false)return false;
    }
    return true;
}
function pfOrder(array &$products,string $sort):void {
    usort($products,static function(array $a,array $b)use($sort):int {
        if($sort==='family')$diff=strnatcasecmp(pfFamily((string)$a['name']),pfFamily((string)$b['name']));
        elseif($sort==='strength')$diff=strnatcasecmp(pfStrength($a),pfStrength($b));
        else $diff=0;
        return $diff?:strnatcasecmp((string)$a['name'],(string)$b['name']);
    });
}
function pfRelated(array $selected,array $products,int $limit=4):array {
    $isAccessory=pfType((string)$selected['name'])==='Research accessory';
    $other=array_values(array_filter($products,static fn($p)=>
        (int)$p['id']!==(int)$selected['id'] &&
        (!$isAccessory || pfType((string)$p['name'])==='Research accessory')
    ));
    usort($other,static fn($a,$b)=>
        (int)(pfFamily((string)$b['name'])===pfFamily((string)$selected['name']))<=>(int)(pfFamily((string)$a['name'])===pfFamily((string)$selected['name']))
        ?: (int)(pfCategory($b)===pfCategory($selected))<=>(int)(pfCategory($a)===pfCategory($selected))
        ?: (int)(pfType((string)$b['name'])===pfType((string)$selected['name']))<=>(int)(pfType((string)$a['name'])===pfType((string)$selected['name']))
        ?: strnatcasecmp((string)$a['name'],(string)$b['name']));
    return array_slice($other,0,$limit);
}
/**
 * Related strengths and physical formats must resolve to real, storefront-approved
 * product IDs, never artificial SKUs. The caller passes only visible active products.
 * Keep accessory families separate from compounds; a shared word must not create
 * a bogus product relationship.
 */
function pfVariantFamily(array $product):string {
    $family=pfFamily((string)$product['name']);
    // ANKH's general category helper classifies "Cartridge" and "With Pen" as
    // accessories even in a compound title. Group by the compound/family name,
    // not by that broad browsing category.
    // Common *presentation suffixes*, not arbitrary words from compound names.
    $family=preg_replace('/(?:\s+(?:vial|cartridge|refill|refill only|with pen|pen included|pen bundle))+\s*$/iu','',$family)??$family;
    return trim($family);
}
function pfVariantPresentation(array $product):string {
    $name=(string)$product['name'];
    // A physical-format suffix in a product title is more specific than a generic
    // CMS phrase such as "powder", "lyophilised", or "research vial".
    if(preg_match('/\bwith[\s-]+pen\b/i',$name))return 'With pen';
    if(preg_match('/\bcartridge\b/i',$name))return 'Cartridge';
    if(preg_match('/\brefill\b/i',$name))return 'Refill';
    if(preg_match('/\bvial\b/i',$name))return 'Vial';
    $format=pfFormat($product);
    if(preg_match('/\bcartridge\b/i',$format))return 'Cartridge';
    if(preg_match('/\bwith[\s-]+pen\b/i',$format))return 'With pen';
    if(preg_match('/\brefill\b/i',$format))return 'Refill';
    if(preg_match('/\bvial\b/i',$format))return 'Vial';
    return $format==='Other'?'Standard':$format;
}
function pfVariants(array $selected,array $products):array {
    $family=pfVariantFamily($selected);
    $selectedHasStrength=pfStrength($selected)!=='';
    $variants=array_values(array_filter($products,static fn($p)=>
        pfVariantFamily($p)===$family &&
        (pfStrength($p)!=='')===$selectedHasStrength
    ));
    usort($variants,static function($a,$b):int {
        $s=strnatcasecmp(pfStrength($a),pfStrength($b));
        return $s?:strnatcasecmp(pfVariantPresentation($a),pfVariantPresentation($b));
    });
    return $variants;
}
/**
 * Build two independent selections. Strength cards try to retain the current
 * presentation; format cards always retain the current strength. Navigation uses
 * the selected real product ID, so price, image and eligibility reload from
 * Order Admin rather than being modified client-side.
 */
function pfVariantSelector(array $selected,array $variants):array {
    $currentStrength=pfStrength($selected);
    $currentFormat=pfVariantPresentation($selected);
    $strengthGroups=[];$formatGroups=[];
    foreach($variants as $variant){
        $strength=pfStrength($variant);
        $format=pfVariantPresentation($variant);
        if($strength!=='')$strengthGroups[$strength][]=$variant;
        if($strength===$currentStrength)$formatGroups[$format][]=$variant;
    }
    uksort($strengthGroups,'strnatcasecmp');
    uksort($formatGroups,'strnatcasecmp');
    $strengthCards=[];
    foreach($strengthGroups as $strength=>$options){
        $candidate=null;
        foreach($options as $option){
            if(pfVariantPresentation($option)===$currentFormat){$candidate=$option;break;}
        }
        if($candidate===null)$candidate=$options[0];
        $strengthCards[]=[
            'label'=>$strength,'id'=>(int)$candidate['id'],
            'selected'=>$strength===$currentStrength,
            'sub_label'=>count($options)>1 ? count($options).' formats' : pfVariantPresentation($candidate)
        ];
    }
    $formatCards=[];
    foreach($formatGroups as $format=>$options){
        $candidate=$options[0];
        $formatCards[]=[
            'label'=>$format,'id'=>(int)$candidate['id'],
            'selected'=>$format===$currentFormat,
            'sub_label'=>pfStrength($candidate)
        ];
    }
    // If a product is unlabelled, do not invent a strength or format.
    return [
        'strengths'=>count($strengthCards)>1?$strengthCards:[],
        'formats'=>count($formatCards)>1?$formatCards:[],
        'current_strength'=>$currentStrength,
        'current_format'=>$currentFormat
    ];
}
/** Prioritised factual slide; all remaining CMS data stays in the detail below. */
function pfSpecHighlights(array $identityRows):array {
    $priority=['strength','presentation','formula','cas','weight','sequence','storage'];
    $ordered=[];
    foreach($priority as $key)foreach($identityRows as $row){
        if(($row['key']??'')===$key){$ordered[]=$row;break;}
    }
    return array_slice($ordered,0,5);
}
function pfValidHTTPS(string $url):bool {
    $parts=parse_url($url);
    return filter_var($url,FILTER_VALIDATE_URL)!==false && is_array($parts)
        && strtolower((string)($parts['scheme']??''))==='https'
        && empty($parts['user'])&&empty($parts['pass']);
}

/** Full material identity, shared between the gallery and the reference summary. */
function pfIdentityRows(array $information):array {
    $labels=[
        'strength'=>'Strength / quantity',
        'presentation'=>'Supplied form',
        'appearance'=>'Appearance',
        'peptide_type'=>'Material type',
        'amino_length'=>'Amino-acid length',
        'formula'=>'Molecular formula',
        'weight'=>'Molecular weight',
        'sequence'=>'Amino-acid sequence',
        'cas'=>'CAS number',
        'purity'=>'Listed purity (unverified)',
        'storage'=>'Storage',
        'concentration'=>'Listed concentration',
        'pubchem'=>'PubChem reference',
        'half_life'=>'Experimental half-life',
        'prepared_storage'=>'Prepared laboratory storage'
    ];
    $rows=[];
    foreach($labels as $key=>$label){
        $value=$information[$key]??'';
        if(!is_string($value))continue;
        $value=trim($value);
        if($value==='')continue;
        $rows[]=['key'=>$key,'label'=>$label,'value'=>$value];
    }
    return $rows;
}
