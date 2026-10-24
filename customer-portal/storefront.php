<?php
declare(strict_types=1);
/**
 * Public ANKH storefront front door.
 *
 * No customer session, orders, payment links or Admin writes.
 * Uses the EXISTING active/published product selection only, even if an
 * administrator has not reviewed the commercial sale status separately.
 * Research pages are informational, not an offer of medical products.
 */
require_once __DIR__.'/public-catalogue-core.php';
require_once is_file(__DIR__.'/customer-catalogue.php') ? __DIR__.'/customer-catalogue.php' : dirname(__DIR__).'/customer-catalogue.php';
require_once __DIR__.'/storefront-design.php';
require_once __DIR__.'/storefront-commerce.php';
require_once __DIR__.'/product-certificates.php';
require_once __DIR__.'/product-safety.php';
require_once __DIR__.'/content-studio.php';
$loopback = PHP_SAPI==='cli-server' && getenv('ANKH_TEST_ALLOW_HTTP')==='1'
    && in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true)
    && preg_match('/^(?:127\\.0\\.0\\.1|\\[::1\\])(?::[0-9]+)?$/D',(string)($_SERVER['HTTP_HOST']??''))===1;
if (!$loopback && ($_SERVER['HTTPS']??'')!=='on') {http_response_code(403);exit('HTTPS required.');}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
if(!$loopback)header('Strict-Transport-Security: max-age=31536000');
function pfEscape(mixed $text):string {return htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function pfUrl(array $params=[]):string{return './'.($params?'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986):'');}
function pfType(string $name):string{return ankhPublicType($name);}
function pfExcerpt(string $text,int $max=130):string {
    $text=ankhCatalogueSummary($text);
    if (strlen($text)<=$max)return $text;
    return rtrim(substr($text,0,$max)).'…';
}
try {
    $path=getenv('ANKH_PORTAL_CONFIG') ?: dirname(__DIR__,2).'/ankh-customer-portal-config.php';
    if(!is_file($path))throw new RuntimeException('Private customer configuration absent.');
    $config=require $path;
    $expectedHost=strtolower((string)($config['host']??'order.ankhpeptides.com'));
    if(!$loopback && $expectedHost!==strtolower((string)($_SERVER['HTTP_HOST']??''))){http_response_code(404);exit;}
    $sdsStaffPreview=false;
    if(($_GET['staff_sds_preview']??'')==='1') {
        $sdsProductId=filter_var($_GET['product']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$sdsProductId){http_response_code(404);exit;}
        ini_set('session.use_strict_mode','1');
        session_name('ankh_staff_preview');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!$loopback,'httponly'=>true,'samesite'=>'Strict']);
        session_start();
        if(!ankhSdsStaffPreviewAllowed($config,$_SESSION,time())) {
            http_response_code(403);
            exit('<!doctype html><html lang="en-GB"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ANKH Staff Preview</title><body style="background:#0c100d;color:#f4dfb7;padding:10vh 7vw;font:16px system-ui"><h1>Staff sign-in required</h1><p>Log into ANKH Content Studio before viewing private SDS previews.</p><a style="color:#e5c580" href="./preview-live.php?manage=portal&amp;panel=products&amp;cms_product_id='.(int)$sdsProductId.'">Sign in to Content Studio</a></body></html>');
        }
        $sdsStaffPreview=true;
    }
    $dbPath=is_array($config)?($config['admin_database_path']??null):null;
    if(!is_string($dbPath)||!is_file($dbPath))throw new RuntimeException('The catalogue database is unavailable.');
    $db=new PDO('sqlite:'.$dbPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000');
    $products=ankhPublicCatalogueProducts($db);
} catch (Throwable $e) {
    error_log('ANKH public storefront: '.$e->getMessage());
    http_response_code(503);
    exit('<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ANKH</title></head><body style="background:#0b0d0c;color:#e8d7ad;padding:12vh 8vw;font:16px system-ui"><h1>☥ ANKH</h1><p>The collection is temporarily unavailable.</p><p><a style="color:#e8d7ad" href="./?login=1">Customer account</a> · <a style="color:#e8d7ad" href="mailto:info@ankhpeptides.com">Contact us</a></p></body></html>');
}
// Optional publicly configured WhatsApp Business contact. Never expose private Admin numbers.
$whatsappUrl=null;
try {
    $portalPath=$config['portal_database_path']??null;
    if(is_string($portalPath)&&is_file($portalPath)) {
        $contacts=new PDO('sqlite:'.$portalPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $contacts->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=1000');
        $exists=$contacts->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_public_settings'")->fetchColumn();
        if($exists){
            $number=$contacts->query("SELECT value FROM portal_public_settings WHERE name='whatsapp'")->fetchColumn();
            if(is_string($number)&&preg_match('/^[1-9][0-9]{7,14}$/D',$number))$whatsappUrl='https://wa.me/'.$number.'?text='.rawurlencode('Hi ANKH, I need some help.');
        }
        $contacts=null;
    }
} catch (Throwable $e) { error_log('ANKH public contact link: '.$e->getMessage()); }
// Prices are retrieved independently for the public storefront display only.
// Display eligibility does not enable live sales or change the technical research index.
$previewPrices=[];
try{$previewPrices=sfRetailPreviewPrices($db,$products);}
catch(Throwable $e){error_log('ANKH storefront retail preview: '.$e->getMessage());}
// Public site reads only explicitly approved accessory IDs and staff-verified business facts.
// An absent private database/table means no items can be checked out (fail closed).
$buyable=[];$businessFacts=null;$demoProducts=[];$productDocuments=[];$productSds=[];
foreach($products as $p)$demoProducts[(int)$p['id']]=true; // Demo basket defaults to all published products.
try{
    $portalPath=is_array($config)?($config['portal_database_path']??''):'';
    if(is_string($portalPath)&&is_file($portalPath)){
        $store=new PDO('sqlite:'.$portalPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $store->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=2000');
        $buyable=sfCommerceEligible($store,$db);
        $demoProducts=sfDemoVisible($store,$products);
        $businessFacts=sfCommerceFacts($store);
        $products=ankhCmsOverlay($store,$products);
        if(isset($_GET['product'])){
            $productDocuments=ankhDocsPublished($store,(int)($_GET['product']??0));
            $productSds=ankhSdsPublished($store,(int)($_GET['product']??0));
            if($sdsStaffPreview) {
                $privateSheets=ankhSdsAll($store,(int)($_GET['product']??0));
                if($privateSheets)$productSds=[reset($privateSheets)];
            }
        }
    }
}catch(Throwable $e){error_log('ANKH public shop setup: '.$e->getMessage());}
if(isset($_GET['image'])) {http_response_code(404);exit;}
$id=filter_var($_GET['product']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$selected=null;
if($id!==false&&$id!==null)foreach($products as $p)if((int)$p['id']===(int)$id){$selected=$p;break;}
if($id!==false&&$id!==null&&!$selected)http_response_code(404);
// All facet values come from published products, not untrusted user-provided categories.
$facets=pfFacets($products);
$search=is_string($_GET['q']??null)?trim($_GET['q']):'';
if(strlen($search)>120)$search=substr($search,0,120);
$category=is_string($_GET['category']??null)?$_GET['category']:'All research';
$categories=['All research','Research compound','Laboratory reagent','Research accessory','Multi-component research blend'];
if(!in_array($category,$categories,true))$category='All research';
$strength=is_string($_GET['strength']??null)?$_GET['strength']:'';
if($strength!==''&&!array_key_exists($strength,$facets['strength']))$strength='';
$format=is_string($_GET['format']??null)?$_GET['format']:'';
if($format!==''&&!array_key_exists($format,$facets['format']))$format='';
$sort=is_string($_GET['sort']??null)?$_GET['sort']:'name';
if(!in_array($sort,['name','family','strength'],true))$sort='name';
$filters=['search'=>$search,'category'=>$category,'strength'=>$strength,'format'=>$format];
$filtered=array_values(array_filter($products,static fn($p)=>pfMatches($p,$filters)));
pfOrder($filtered,$sort);
$savedView=($_GET['saved']??'')==='1' && ($id===null||$id===false);
$notice=ankhPublicNotice();
require __DIR__.'/storefront-experience.php';
