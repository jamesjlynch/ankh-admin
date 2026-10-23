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
    if($filter['category']!=='All research' && pfType((string)$p['name'])!==$filter['category'])return false;
    if($filter['strength']!==''&& pfStrength($p)!==$filter['strength'])return false;
    if($filter['format']!==''&& pfFormat($p)!==$filter['format'])return false;
    if($filter['search']!==''){
        $info=(array)($p['information']??[]);
        $text=(string)$p['name'].' '.pfType((string)$p['name']).' '.(string)($info['research_focus']??'').' '.(string)($info['peptide_type']??'').' '.pfStrength($p);
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
    $other=array_values(array_filter($products,static fn($p)=>(int)$p['id']!==(int)$selected['id']));
    usort($other,static fn($a,$b)=>
        (int)(pfFamily((string)$b['name'])===pfFamily((string)$selected['name']))<=>(int)(pfFamily((string)$a['name'])===pfFamily((string)$selected['name']))
        ?: (int)(pfType((string)$b['name'])===pfType((string)$selected['name']))<=>(int)(pfType((string)$a['name'])===pfType((string)$selected['name']))
        ?: strnatcasecmp((string)$a['name'],(string)$b['name']));
    return array_slice($other,0,$limit);
}
function pfVariants(array $selected,array $products):array {
    $family=pfFamily((string)$selected['name']);
    return array_values(array_filter($products,static fn($p)=>pfFamily((string)$p['name'])===$family));
}
function pfValidHTTPS(string $url):bool {
    $parts=parse_url($url);
    return filter_var($url,FILTER_VALIDATE_URL)!==false && is_array($parts)
        && strtolower((string)($parts['scheme']??''))==='https'
        && empty($parts['user'])&&empty($parts['pass']);
}
