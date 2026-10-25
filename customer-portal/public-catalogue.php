<?php
declare(strict_types=1);
/**
 * Standalone read-only catalogue. No session/auth, orders, stock, pricing or customer data.
 * Public profiles are the already-published, active customer catalogue products.
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
require_once __DIR__.'/public-catalogue-core.php';
require_once __DIR__.'/content-studio.php';
require_once is_file(__DIR__.'/customer-catalogue.php') ? __DIR__.'/customer-catalogue.php' : dirname(__DIR__).'/customer-catalogue.php';
function esc($s):string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$configFile=getenv('ANKH_PORTAL_CONFIG') ?: dirname(__DIR__,2).'/ankh-customer-portal-config.php';
try {
    if (!is_file($configFile)) throw new RuntimeException('Missing private configuration.');
    $config=require $configFile;
    $file=is_array($config)?($config['admin_database_path']??''):'';
    if (!is_string($file) || !is_file($file)) throw new RuntimeException('Missing private data.');
    $db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=2000');
    $products=ankhPublicCatalogueProducts($db);
    $portalPath=$config['portal_database_path']??null;
    if(is_string($portalPath)&&is_file($portalPath)){
      $store=new PDO('sqlite:'.$portalPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
      $store->exec('PRAGMA query_only=ON');
      $products=ankhCmsOverlay($store,$products);
    }

} catch(Throwable $e) {
    error_log('ANKH public catalogue unavailable: '.$e->getMessage());
    http_response_code(503);
    exit('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Catalogue temporarily unavailable</title><body style="font:16px system-ui;padding:3rem;background:#0c0d0c;color:#efebde"><h1>Catalogue temporarily unavailable</h1><p>Please check back later.</p></body></html>');
}
$imageId=filter_var($_GET['image']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if ($imageId!==false && $imageId!==null) {
    // Only serve photos for active products already marked visible in the catalogue.
    $allowed=array_values(array_filter($products,static fn($p)=>(int)$p['id']===(int)$imageId));
    if (!$allowed || !$allowed[0]['has_photo']) {http_response_code(404);exit;}
    if(!empty($allowed[0]['cms_photo'])){
      $size=(string)($_GET['size']??'thumb');
      header('Location: ./cms-product-image.php?product='.(int)$imageId.'&size='.rawurlencode($size),true,302);
      exit;
    }

    $q=$db->prepare('SELECT image_data,image_mime FROM customer_catalogue_content WHERE product_id=?');
    $q->execute([(int)$imageId]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if (!$r || !in_array($r['image_mime'],['image/png','image/jpeg','image/webp'],true)) {http_response_code(404);exit;}
    $image=is_resource($r['image_data'])?stream_get_contents($r['image_data']):(string)$r['image_data'];
    if ($image===false) {http_response_code(404);exit;}
    $mime=$r['image_mime'];
    // Smaller public thumbnails when GD/WebP are available; unchanged originals otherwise.
    $requestedSize=(string)($_GET['size']??'');
    $targetPixels=match($requestedSize){'thumb'=>420,'detail'=>880,default=>0};
    if($targetPixels>0 && is_string($image) && strlen($image)<8*1024*1024
       && function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $dimensions=@getimagesizefromstring($image);
        if(is_array($dimensions) && $dimensions[0]>0 && $dimensions[1]>0
           && $dimensions[0]<=2500 && $dimensions[1]<=2500
           && max($dimensions[0],$dimensions[1])>$targetPixels){
            $src=@imagecreatefromstring($image);
            if($src!==false){
                $ratio=min(1,$targetPixels/max(imagesx($src),imagesy($src)));
                $width=max(1,(int)round(imagesx($src)*$ratio));$height=max(1,(int)round(imagesy($src)*$ratio));
                $dest=imagecreatetruecolor($width,$height);
                imagealphablending($dest,false);imagesavealpha($dest,true);
                imagefill($dest,0,0,imagecolorallocatealpha($dest,0,0,0,127));
                imagecopyresampled($dest,$src,0,0,0,0,$width,$height,imagesx($src),imagesy($src));
                ob_start();$ok=@imagewebp($dest,null,$requestedSize==='detail'?84:79);$bytes=ob_get_clean();
                imagedestroy($dest);imagedestroy($src);
                if($ok&&is_string($bytes)&&$bytes!==''){$image=$bytes;$mime='image/webp';}
            }
        }
    }
    $etag='"'.hash('sha256',$image).'"';
    header('Cache-Control: public, max-age=600');
    header('ETag: '.$etag);
    if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}
    header_remove('Content-Type');header('Content-Type: '.$mime);
    header('Content-Length: '.strlen($image));header('Content-Disposition: inline');echo $image;exit;
}
$id=filter_var($_GET['product']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$selected=null;
if ($id!==false && $id!==null) foreach ($products as $p) if ((int)$p['id']===(int)$id) {$selected=$p;break;}
if ($id!==false && $id!==null && !$selected) {http_response_code(404);}
$notice=ankhPublicNotice();
function printLinkList(string $text):void {
    foreach (preg_split('/\R/u',trim($text))?:[] as $line) {
        $line=trim($line);if($line==='')continue;
        if (preg_match('~https://[^\s<>()]+~i',$line,$m)) {
            $url=$m[0];$parsed=parse_url($url);
            if (filter_var($url,FILTER_VALIDATE_URL) && ($parsed['scheme']??'')==='https'
                && !isset($parsed['user']) && !isset($parsed['pass'])) {
                echo '<li><span>'.esc(trim(str_replace($url,'',$line))).'</span><a rel="noopener noreferrer" target="_blank" href="'.esc($url).'">'.esc(parse_url($url,PHP_URL_HOST)?:'Source').' ↗</a></li>';
                continue;
            }
        }
        echo '<li>'.esc($line).'</li>';
    }
}
$specs=['strength'=>'Quantity / strength','presentation'=>'Supplied form','appearance'=>'Appearance',
'peptide_type'=>'Research category','amino_length'=>'Residues','formula'=>'Molecular formula',
'weight'=>'Molecular weight','sequence'=>'Sequence','cas'=>'CAS reference','pubchem'=>'PubChem reference',
'concentration'=>'Concentration','half_life'=>'Experimental half-life / context','purity'=>'Documented purity'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0d0e0d">
<meta name="robots" content="noindex,nofollow">
<title><?=esc($selected?$selected['name'].' — ANKH Scientific Reference':'Scientific Reference — ANKH')?></title>
<meta name="description" content="Technical laboratory research reference with publication context, compound specifications and evidence limitations. Not for medical use.">
<style>
:root{color-scheme:dark;--bg:#0b0c0b;--surface:#151715;--surface2:#1b1c19;--line:#39392f;--text:#f3f0e6;--muted:#a9aaa2;--gold:#ebcc83;--gold2:#a88c57;--radius:18px}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:radial-gradient(ellipse at 45% -10%,#302719 0,transparent 43%),var(--bg);color:var(--text);font:15px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif}
a{color:var(--gold)}a:focus-visible,button:focus-visible,input:focus-visible,summary:focus-visible{outline:3px solid var(--gold);outline-offset:4px}
.wrap{width:min(1190px,calc(100% - 40px));margin:auto}.top{position:relative;border-bottom:1px solid var(--line);background:#0c0d0bcc}.top .wrap{display:flex;gap:18px;align-items:center;justify-content:space-between;min-height:78px}.brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:var(--text)}.symbol{font:36px Georgia,serif;color:var(--gold)}.brand strong{display:block;letter-spacing:.16em;font-size:13px}.brand small{display:block;color:var(--muted);font-size:11px;letter-spacing:.08em}.top nav{display:flex;align-items:center;gap:20px;font-size:12px}.top nav a{text-decoration:none}
.hero{padding:65px 0 34px}.eyebrow{text-transform:uppercase;color:var(--gold);font-size:11px;font-weight:800;letter-spacing:.21em}.hero h1{font-size:clamp(34px,6vw,68px);line-height:1.07;letter-spacing:-.045em;max-width:800px;margin:12px 0}.lead{max-width:800px;color:#c3c3b8;font-size:clamp(14px,1.8vw,17px)}
.notice{border:1px solid #807049; background:linear-gradient(120deg,#272216,#191a16);border-radius:var(--radius);padding:20px 23px;margin:25px 0 38px}.notice strong{display:block;color:#f1d69f;font-size:13px;letter-spacing:.05em;text-transform:uppercase}.notice p{color:#e1d7c5;margin:7px 0 0;font-size:13px}
.stats{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:20px 0 26px}.chip{border:1px solid var(--line);background:var(--surface);border-radius:999px;padding:5px 12px;font-size:11px;color:#c6c4b9}
.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;padding:0 0 70px}.product{display:flex;flex-direction:column;overflow:hidden;background:linear-gradient(150deg,#1c1d19,#111310);border:1px solid #37382e;border-radius:22px;text-decoration:none;color:var(--text);min-height:385px;transition:border-color .15s,transform .15s}.product:hover{border-color:var(--gold2);transform:translateY(-2px)}.product-photo{height:205px;display:grid;place-items:center;overflow:hidden;background:radial-gradient(ellipse,#2a2921,#161713)}.product-photo img{height:100%;width:100%;object-fit:contain;padding:16px}.placeholder{font:72px Georgia,serif;color:#c1a97760}.product-body{padding:19px;flex:1;display:flex;flex-direction:column;gap:8px}.product-body small{color:var(--gold);font-size:10px;text-transform:uppercase;letter-spacing:.08em}.product-body h2{font-size:21px;letter-spacing:-.02em;line-height:1.16;margin:0}.product-body p{font-size:12px;color:var(--muted);margin:0;line-height:1.6}.product-foot{padding-top:10px;margin-top:auto;border-top:1px solid var(--line);font-weight:700;color:#f4d994;font-size:12px}
.search{width:min(500px,100%);margin-bottom:22px;padding:13px 16px;border:1px solid var(--line);border-radius:12px;background:#1b1c19;color:var(--text);font:inherit}.empty{border:1px dashed #575347;border-radius:20px;padding:44px 26px;text-align:center;max-width:790px;margin:0 0 80px}.empty h2{margin:0 0 7px}.empty p{color:var(--muted);max-width:560px;margin:0 auto}
.back{display:inline-flex;margin:32px 0 18px;text-decoration:none;font-size:12px;color:var(--gold)}.detail-head{display:grid;grid-template-columns: minmax(240px,.8fr) minmax(0,1.4fr);gap:30px;align-items:center;padding:25px;background:var(--surface);border:1px solid var(--line);border-radius:24px}.detail-visual{min-height:310px;display:grid;place-items:center;background:radial-gradient(ellipse at center,#333126,#181916);border-radius:18px;overflow:hidden}.detail-visual img{width:100%;height:330px;object-fit:contain;padding:12px}.detail-head h1{font-size:clamp(35px,5vw,56px);letter-spacing:-.045em;line-height:1.07;margin:8px 0 20px}.detail-head p{color:#c9c8bd;margin:0 0 14px}.detail-info{display:flex;flex-wrap:wrap;gap:8px;margin-top:24px}
.sections{display:grid;grid-template-columns:minmax(0,1fr) 320px;align-items:start;gap:22px;padding:25px 0 60px}.section,.side{border:1px solid var(--line);border-radius:18px;background:var(--surface);padding:24px;margin-bottom:18px;overflow-wrap:anywhere}.section h2,.side h2{font-size:19px;letter-spacing:-.02em;margin:0 0 12px}.section p{margin:0 0 14px;color:#c5c7bd}.section p:last-child{margin-bottom:0}.section strong{color:#f4ecd6}.section ul{padding-left:19px;color:#d6d1c3}.section a{word-break:break-word}
dl{margin:0}dl div{display:flex;align-items:start;justify-content:space-between;gap:17px;padding:11px 0;border-bottom:1px solid var(--line)}dl div:last-child{border-bottom:0}dt{color:#a5a79b;font-size:12px}dd{margin:0;text-align:right;font-size:12px;max-width:54%;overflow-wrap:anywhere;font-weight:650}summary{cursor:pointer;list-style:none;font-weight:650;font-size:14px}summary::-webkit-details-marker{display:none}details.expand{padding:16px 0;border-top:1px solid var(--line)}details.expand:first-child{border-top:0}details.expand summary:after{content:'+';float:right;color:var(--gold)}details.expand[open] summary:after{content:'−'}details.expand .inner{margin-top:15px;color:#c6c6bc;font-size:13px}.refs{padding-left:22px}.refs li{padding:7px 0;color:#bfc0b6}.refs li a{margin-left:6px}.foot{border-top:1px solid var(--line);padding:24px 0 40px;color:#979b90;font-size:11px}
.hidden{display:none!important}
@media(max-width:950px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.sections{grid-template-columns:1fr}.detail-head{grid-template-columns:1fr 1.1fr}.detail-visual,.detail-visual img{min-height:220px;height:260px}}
@media(max-width:650px){.wrap{width:min(100% - 28px,1190px)}.top .wrap{min-height:69px}.top nav{gap:10px}.hero{padding-top:41px}.grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.product{min-height:305px;border-radius:15px}.product-photo{height:145px}.product-body{padding:13px}.product-body h2{font-size:16px}.product-body p{font-size:11px}.detail-head{grid-template-columns:1fr;padding:14px;gap:20px}.detail-head h1{font-size:37px}.sections{gap:5px}.section,.side{padding:18px}.notice{padding:17px}.detail-visual img{height:245px}}
@media(max-width:370px){.grid{grid-template-columns:1fr}.product-photo{height:190px}}
@media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}*,*:before,*:after{transition:none!important}}
</style>
</head>
<body>
<header class="top"><div class="wrap"><a class="brand" href="./public-catalogue.php"><span class="symbol" aria-hidden="true">☥</span><span><strong>ANKH</strong><small>SCIENTIFIC REFERENCE</small></span></a><nav aria-label="Primary"><a href="./public-catalogue.php">Research index</a><a href="https://www.gov.uk/guidance/borderline-products-how-to-tell-if-your-product-is-a-medicine" target="_blank" rel="noopener noreferrer">UK guidance ↗</a></nav></div></header>
<main class="wrap">
<?php if($selected):$info=$selected['information'];$focus=trim((string)($info['research_focus']??''));?>
<a class="back" href="./public-catalogue.php">← All published research profiles</a>
<div class="detail-head">
 <div class="detail-visual"><?php if($selected['has_photo']):?><img src="?image=<?=(int)$selected['id']?>" alt="<?=esc($selected['name'])?> – documented product photograph"><?php else:?><span class="placeholder" aria-hidden="true">☥</span><?php endif;?></div>
 <div><span class="eyebrow">Scientific reference / <?=esc((($selected['category']??'')?:ankhPublicType($selected['name'])))?></span>
 <h1><?=esc($selected['name'])?></h1><p><?=esc(ankhCatalogueSummary($selected['description']))?></p>
 <div class="detail-info"><span class="chip">Public technical reference</span><span class="chip">Not for medical use</span><span class="chip">No claims of clinical benefit</span></div></div>
</div>
<div class="notice" role="note"><strong>Research use only — not for medical use</strong><p><?=esc($notice)?></p></div>
<div class="sections"><div>
 <section class="section"><span class="eyebrow">01 / Identity</span><h2>Overview</h2><?=ankhCatalogueFormat($selected['description'])?></section>
 <?php if($focus!==''||!empty($info['research'])):?><section class="section"><span class="eyebrow">02 / Scientific evidence</span><h2>Research context</h2>
 <?php if($focus!==''):?><p><strong>Research focus:</strong> <?=esc($focus)?></p><?php endif;?>
 <?php if(!empty($info['evidence_level'])):?><p><strong>Evidence status:</strong> <?=esc($info['evidence_level'])?></p><?php endif;?>
 <?php if(!empty($info['mechanism'])):?><p><strong>Experimental mechanism:</strong> <?=esc($info['mechanism'])?></p><?php endif;?>
 <?php if(!empty($info['research_limitations'])):?><p><strong>Research limitations:</strong> <?=esc($info['research_limitations'])?></p><?php endif;?>
 <?php if(!empty($info['research'])):?><?=ankhCatalogueFormat((string)$info['research'])?><?php endif;?>
 <p><strong>Interpretation:</strong> Findings in a published study depend on the precise molecule, tested preparation, participants or laboratory model. They do not validate the quality or medical use of a product listed here.</p>
 </section><?php endif;?>
 <?php if(!empty($info['references'])):?><section class="section"><span class="eyebrow">03 / Literature</span><h2>Research references</h2><p>External scientific literature for background reading. These are not certificates of analysis or endorsements of this material.</p><ol class="refs"><?php printLinkList((string)$info['references']);?></ol></section><?php endif;?>
 <?php if(!empty($info['faq'])):?><section class="section"><span class="eyebrow">04 / Common questions</span><h2>Scientific FAQs</h2><?=ankhCatalogueFormat((string)$info['faq'])?></section><?php endif;?>
 </div><aside>
 <section class="side"><h2>Product identity</h2>
 <?php if(!empty($info['identity_notes'])):?><p style="font-size:12px;color:#c6c7bd"><?=esc($info['identity_notes'])?></p><?php endif;?>
 <dl><?php foreach($specs as $key=>$label):if(trim((string)($info[$key]??''))==='')continue;?><div><dt><?=esc($label)?></dt><dd><?=esc($info[$key])?></dd></div><?php endforeach;?></dl><p style="color:var(--muted);font-size:11px">Molecular values describe the named reference compound and can differ with salt forms, solvents and batch composition. Blank values have not been verified.</p></section>
 <section class="side"><h2>Handling and limitations</h2>
 <?php foreach(['storage'=>'Unprepared laboratory material','prepared_storage'=>'After laboratory preparation','precautions'=>'Precautions'] as $key=>$label):if(empty($info[$key]))continue;?>
 <details class="expand" <?=$key==='precautions'?'open':''?>><summary><?=esc($label)?></summary><div class="inner"><?=ankhCatalogueFormat((string)$info[$key])?></div></details>
 <?php endforeach;?>
 </section><section class="side"><h2>Batch documentation</h2><p style="color:var(--muted);font-size:12px">Batch testing, sterility, concentration and Certificates of Analysis must be independently documented. No verification is implied by this page.</p></section>
 </aside></div>
<?php elseif($id!==false && $id!==null):?>
<div class="hero"><span class="eyebrow">Not listed</span><h1>Profile not available</h1><p class="lead">This product does not have an approved public research profile.</p><a href="./public-catalogue.php">Return to research index</a></div>
<?php else:?>
<section class="hero"><span class="eyebrow">ANKH / Scientific reference catalogue</span><h1>Technical profiles for laboratory research.</h1><p class="lead">Factual reference information on molecular identity, scientific literature, experimental research models and technical limitations. Profiles are shown when they are active and already marked visible in ANKH's customer catalogue. Public presentation and legal classification require separate regulatory consideration.</p></section>
<div class="notice" role="note"><strong>Research use only — not for medical use</strong><p><?=esc($notice)?></p></div>
<?php if($products):?><div class="stats"><span class="chip"><?=count($products)?> published research profiles</span><span class="chip">Read-only reference</span><span class="chip">No prices or ordering</span></div>
<label for="catalogue-search" class="eyebrow" style="display:block;margin-bottom:8px">Find a compound or material</label>
<input class="search" type="search" id="catalogue-search" placeholder="Search profiles…" autocomplete="off">
<div class="grid" id="catalogue-grid"><?php foreach($products as $p):?>
<a class="product" data-search="<?=esc(strtolower($p['name'].' '.($p['information']['research_focus']??'')))?>" href="?product=<?=(int)$p['id']?>">
 <div class="product-photo"><?php if($p['has_photo']):?><img src="?image=<?=(int)$p['id']?>" loading="lazy" alt="" decoding="async"><?php else:?><span class="placeholder" aria-hidden="true">☥</span><?php endif;?></div>
 <div class="product-body"><small><?=esc((($p['category']??'')?:ankhPublicType($p['name'])))?></small><h2><?=esc($p['name'])?></h2><p><?=esc(substr(ankhCatalogueSummary($p['description']),0,190))?></p><div class="product-foot">Explore technical profile →</div></div>
</a><?php endforeach;?></div>
<p id="no-search" class="empty hidden">No reviewed profiles match that search.</p>
<script>
(()=>{const search=document.getElementById('catalogue-search');if(!search)return;const cards=[...document.querySelectorAll('[data-search]')];search.addEventListener('input',()=>{const q=search.value.trim().toLowerCase();let count=0;cards.forEach(card=>{const show=card.dataset.search.includes(q);card.classList.toggle('hidden',!show);if(show)count++});document.getElementById('no-search').classList.toggle('hidden',count!==0);});})();
</script>
<?php else:?><section class="empty"><span class="eyebrow">Regulatory review</span><h2>No published profiles available</h2><p>The public reference lists only products marked active and visible in ANKH’s existing catalogue. Products without that visibility setting remain hidden.</p></section><?php endif;?>
<?php endif;?>
</main>
<footer class="foot"><div class="wrap">ANKH GLOBAL LTD · Technical scientific information only. No price, availability or clinical-use information is provided on this public page. Public display does not constitute a regulatory authorisation. <a href="https://www.gov.uk/guidance/advertise-your-medicines" rel="noopener noreferrer" target="_blank">MHRA guidance ↗</a></div></footer>
</body></html>