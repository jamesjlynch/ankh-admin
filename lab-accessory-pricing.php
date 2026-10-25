<?php
declare(strict_types=1);
/** Private product sourcing editor. Changes only Admin cost and retail prices. */
function ankhLabAccessorySourceRows(PDO $db):array{
    $exists=(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='lab_accessory_sourcing'")->fetchColumn();
    if(!$exists)return [];
    return $db->query("SELECT s.*,p.name,p.price,p.cost,p.active,v.visible,cat.name AS category
       FROM lab_accessory_sourcing s JOIN products p ON p.id=s.product_id
       LEFT JOIN customer_catalogue_visibility v ON v.product_id=p.id
       LEFT JOIN catalogue_product_categories m ON m.product_id=p.id
       LEFT JOIN catalogue_categories cat ON cat.id=m.category_id
       ORDER BY cat.name COLLATE NOCASE,p.name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
}
function ankhLabAccessoryUpdateAmazonPrice(PDO $db,string $sku,string $rawPrice,string $url):void {
    $sku=trim($sku);
    if(!preg_match('/^LAB-[A-Z0-9-]{4,64}$/',$sku))throw new InvalidArgumentException('Invalid accessory reference.');
    $cost=filter_var(trim($rawPrice),FILTER_VALIDATE_FLOAT);
    if($cost===false||!is_finite((float)$cost)||$cost<0.01||$cost>10000||!preg_match('/^\d+(?:\.\d{1,2})?$/',trim($rawPrice)))
        throw new InvalidArgumentException('Enter an Amazon purchase price in pounds with up to two decimal places.');
    $url=trim($url);
    if(strlen($url)>1200||$url!=='' && (!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https'
      ||!in_array(strtolower((string)parse_url($url,PHP_URL_HOST)),['amazon.co.uk','www.amazon.co.uk','bigshopper.co.uk','www.bigshopper.co.uk'],true)))
        throw new InvalidArgumentException('Use an HTTPS Amazon UK or Amazon-linked comparison URL.');
    $pence=(int)round((float)$cost*100);
    $q=$db->prepare('SELECT s.product_id FROM lab_accessory_sourcing s JOIN products p ON p.id=s.product_id WHERE s.sku=?');
    $q->execute([$sku]);$id=$q->fetchColumn();
    if($id===false)throw new InvalidArgumentException('Accessory source record not found.');
    $db->beginTransaction();
    try {
        // Admin remains sole authority. No publication or stock changes.
        $db->prepare('UPDATE products SET cost=?,price=? WHERE id=?')->execute([$pence,$pence+300,(int)$id]);
        $db->prepare("UPDATE lab_accessory_sourcing SET amazon_reference_pence=?,reference_url=?,observed_date=?,price_status='reference_unconfirmed',
            note=? WHERE sku=?")->execute([$pence,$url,gmdate('Y-m-d'),'Staff-entered Amazon UK reference; verify checkout cost and packaging before sale.',$sku]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function ankhLabAccessoryPricingPanel(PDO $db):void {
    $rows=ankhLabAccessorySourceRows($db);
    if(!$rows)return;
    $priced=count(array_filter($rows,static fn($r)=>$r['amazon_reference_pence']!==null));
    ?>
    <style>
    .ankh-lab-price{margin:18px 0;padding:20px;border:1px solid #d3b77d66;border-radius:16px;background:linear-gradient(140deg,#222720,#151917)}
    .ankh-lab-price h2{font:400 27px Georgia,serif;color:#f2dbac;margin:5px 0}
    .ankh-lab-price>p{font-size:12px;line-height:1.7;color:#c2c4b8}
    .ankh-lab-price-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,360px),1fr));gap:10px;margin-top:14px}
    .ankh-lab-price-card{padding:16px;border:1px solid #d2ba8850;background:#101811;border-radius:12px}
    .ankh-lab-price-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:9px}
    .ankh-lab-price-head strong{font-size:14px;color:#f4e5c7}
    .ankh-lab-price-head small{font-size:10px;color:#d8bd82}
    .ankh-lab-price-card>p{font-size:11px;color:#bec0b5}
    .ankh-lab-price-card form{display:grid;gap:10px}
    .ankh-lab-price-card label{font-size:11px;display:grid;gap:6px;color:#dbcda8}
    .ankh-lab-price-card input{min-width:0;width:100%;min-height:44px;background:#161f18!important;color:#f7e5c2!important;border:1px solid #c3a76f77!important;border-radius:9px;padding:9px;font-size:13px}
    .ankh-lab-price-card button{min-height:47px;border:1px solid #d8b775;border-radius:9px;background:#dbbe84;color:#191c16;font-size:12px;font-weight:750}
    .ankh-lab-price-foot{font-size:11px;color:#d8c7a5}
    .ankh-lab-price :is(input,button):focus-visible{outline:3px solid #e6cf96;outline-offset:2px}
    @media(max-width:580px){.ankh-lab-price{padding:13px}.ankh-lab-price-list{grid-template-columns:1fr}}
    </style>
    <section class="ankh-lab-price" id="ankh-amazon-costs">
       <span class="eyebrow">ANKH / LABORATORY ACCESSORY SOURCING</span>
       <h2>Amazon cost + £3</h2>
       <p><?=$priced?> of <?=count($rows)?> draft accessories have an Amazon-linked reference price. Enter the amount you can actually buy the <strong>exact pack</strong> for, and Admin updates the saved cost and retail price automatically. This <strong>does not activate, publish, reserve or change stock</strong>. Check delivery, VAT and returns costs before offering products for sale.</p>
       <div class="ankh-lab-price-list">
        <?php foreach($rows as $r):$hasPrice=$r['amazon_reference_pence']!==null;?>
        <article class="ankh-lab-price-card">
         <div class="ankh-lab-price-head"><strong><?=htmlspecialchars((string)$r['name'],ENT_QUOTES,'UTF-8')?></strong>
         <small><?=$hasPrice?'Price reference recorded':'AMAZON PRICE NEEDED'?></small></div>
         <p><?=htmlspecialchars((string)($r['category']??'Laboratory supplies'),ENT_QUOTES,'UTF-8')?> · <?=$r['active']?'Active':'Inactive draft'?> · <?=((int)($r['visible']??0)===1)?'Published':'Hidden from website'?></p>
         <form method="post">
           <?php if(function_exists('csrf'))csrf();?>
           <input type="hidden" name="action" value="lab_accessory_price_update">
           <input type="hidden" name="return" value="products">
           <input type="hidden" name="lab_sku" value="<?=htmlspecialchars((string)$r['sku'],ENT_QUOTES,'UTF-8')?>">
           <label>Amazon purchase price (£)
            <input type="number" min="0.01" step=".01" max="10000" inputmode="decimal" name="amazon_price" required value="<?=$hasPrice?number_format((int)$r['amazon_reference_pence']/100,2,'.',''):''?>" placeholder="Exact pack's Amazon price"></label>
           <label>Amazon listing or linked reference (HTTPS)
            <input type="url" name="amazon_url" maxlength="1200" value="<?=htmlspecialchars((string)$r['reference_url'],ENT_QUOTES,'UTF-8')?>" placeholder="https://www.amazon.co.uk/..."></label>
           <span class="ankh-lab-price-foot">Saved ANKH retail: <strong>£<?=number_format((int)$r['price']/100,2)?></strong>
             <?=$hasPrice?'· reference £'.number_format((int)$r['amazon_reference_pence']/100,2):'· unpriced until you confirm the Amazon quote'?></span>
           <button type="submit">Save purchase cost &amp; retail + £3</button>
         </form>
        </article>
        <?php endforeach;?>
       </div>
    </section>
    <?php
}
