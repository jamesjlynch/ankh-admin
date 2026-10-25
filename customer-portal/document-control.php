<?php
declare(strict_types=1);
/**
 * ANKH Content Studio / read-only document inventory.
 * Never duplicates product state; only active and shop-visible Order Admin IDs
 * are included. BLOBs are never selected. No implied laboratory verification.
 */
function ankhDocumentControlRows(PDO $store,PDO $admin):array {
    $products=$admin->query("SELECT p.id,p.name FROM products p
        INNER JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1
        WHERE p.active=1 ORDER BY p.name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
    $rows=[];foreach($products as $p){
        $id=(int)$p['id'];
        $rows[$id]=[
            'id'=>$id,'name'=>(string)$p['name'],
            'sds_status'=>'missing','sds_id'=>null,'sds_date'=>0,
            'sds_private_count'=>0,'sds_history_count'=>0,
            'coa_count'=>0,'coa_private_count'=>0,'coa_id'=>null,'coa_date'=>0,
            'spec_count'=>0,'doc_history_count'=>0
        ];
    }
    if(!$rows)return [];
    $hasTable=static function(PDO $db,string $table):bool{
        $q=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $q->execute([$table]);return (bool)$q->fetchColumn();
    };
    if($hasTable($store,'portal_product_sds')){
        $draftColumn=function_exists('ankhSdsHasPublicDraftColumn')&&ankhSdsHasPublicDraftColumn($store)
            ?'public_draft':'0 AS public_draft';
        $query=$store->query("SELECT id,product_id,published,reviewed_at,created_at,$draftColumn
            FROM portal_product_sds ORDER BY id DESC");
        foreach($query as $doc){
            $id=(int)$doc['product_id'];if(!isset($rows[$id]))continue;
            $row=&$rows[$id];$row['sds_history_count']++;
            $reviewed=(int)$doc['published']===1&&(int)$doc['reviewed_at']>0;
            $publicDraft=(int)$doc['public_draft']===1;
            if($reviewed){
                if($row['sds_status']!=='reviewed'){
                    $row['sds_status']='reviewed';
                    $row['sds_id']=(int)$doc['id'];
                    $row['sds_date']=(int)$doc['reviewed_at'];
                }
            }elseif($publicDraft && $row['sds_status']!=='reviewed' && $row['sds_status']!=='public-draft'){
                $row['sds_status']='public-draft';
                $row['sds_id']=(int)$doc['id'];
                $row['sds_date']=(int)$doc['created_at'];
            }else if(!$reviewed&&!$publicDraft){
                $row['sds_private_count']++;
                if($row['sds_status']==='missing')$row['sds_status']='private';
            }
            unset($row);
        }
    }
    if($hasTable($store,'portal_product_documents')){
        $q=$store->query("SELECT id,product_id,document_kind,created_at,published,superseded
            FROM portal_product_documents ORDER BY id DESC");
        foreach($q as $doc){
            $id=(int)$doc['product_id'];if(!isset($rows[$id]))continue;
            $row=&$rows[$id];$row['doc_history_count']++;
            $public=(int)$doc['published']===1&&(int)$doc['superseded']===0;
            $kind=(string)$doc['document_kind'];
            if($public && in_array($kind,['original_coa','ankh_batch_coa'],true)){
                $row['coa_count']++;
                if($row['coa_id']===null){$row['coa_id']=(int)$doc['id'];$row['coa_date']=(int)$doc['created_at'];}
            }elseif($public && $kind==='technical_spec'){
                $row['spec_count']++;
            }elseif(!$public && in_array($kind,['original_coa','ankh_batch_coa'],true)){
                $row['coa_private_count']++;
            }
            unset($row);
        }
    }
    return array_values($rows);
}
function ankhDocumentControlPage(PDO $store,PDO $admin):void {
    $rows=ankhDocumentControlRows($store,$admin);
    $total=count($rows);
    $reviewed=count(array_filter($rows,static fn($r)=>$r['sds_status']==='reviewed'));
    $missing=count(array_filter($rows,static fn($r)=>$r['sds_status']==='missing'));
    $coa=count(array_filter($rows,static fn($r)=>$r['coa_count']>0));
    $esc=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    ?>
    <section class="pc-panel ankh-doc-control" aria-labelledby="ankh-doc-heading">
      <div class="ankh-doc-head">
        <div><span class="pc-eyebrow">ANKH / DOCUMENT CONTROL</span><h2 id="ankh-doc-heading">Documentation overview</h2>
        <p>Live catalogue only. Statuses come from the original PDF records—not from product descriptions or assumed testing. Product visibility remains controlled by Order Admin.</p></div>
        <a class="pc-outline-button" href="./preview-live.php?manage=portal&amp;panel=products">Open Product CMS ↗</a>
      </div>
      <div class="ankh-doc-kpis" aria-label="Document inventory summary">
        <div><strong><?=$total?></strong><span>Live products</span></div>
        <div><strong><?=$reviewed?></strong><span>ANKH-reviewed SDS</span></div>
        <div><strong><?=$missing?></strong><span>No SDS recorded</span></div>
        <div><strong><?=$coa?></strong><span>With public analytical report</span></div>
      </div>
      <div class="ankh-doc-notice" role="note"><strong>Important distinction</strong>
      <p>“ANKH-reviewed SDS” records ANKH’s document approval, not independent certification. A public COA is a separately uploaded analytical report; a specification is not proof of independent batch testing. Missing documents are never generated or marked verified automatically.</p></div>
      <div class="ankh-doc-tools">
        <label for="ankh-doc-search">Find a product<input id="ankh-doc-search" type="search" autocomplete="off" placeholder="Search product name or ID"></label>
        <label for="ankh-doc-filter">Document status<select id="ankh-doc-filter">
          <option value="all">All live products</option>
          <option value="missing">No SDS recorded</option>
          <option value="review">Requires SDS attention</option>
          <option value="coa">No public COA</option>
          <option value="ready">ANKH-reviewed SDS</option>
        </select></label>
        <button class="ankh-doc-copy" type="button" id="ankh-doc-copy">Copy missing SDS list</button>
      </div>
      <p class="ankh-doc-result" id="ankh-doc-results" role="status" aria-live="polite"><?=$total?> live products</p>
      <div class="ankh-doc-list" id="ankh-doc-list">
      <?php foreach($rows as $row):
        $status=$row['sds_status'];
        $statusLabel=match($status){
            'reviewed'=>'ANKH reviewed',
            'public-draft'=>'Public working copy',
            'private'=>'Private document only',
            default=>'No SDS recorded'
        };
        $attention=$status!=='reviewed';
        $cms='./preview-live.php?manage=portal&panel=products&cms_product_id='.(int)$row['id'];
        $coaCount=(int)$row['coa_count'];
        $publishedSds=$status==='reviewed'||$status==='public-draft';
        ?>
        <article class="ankh-doc-product" data-document-product data-id="<?=(int)$row['id']?>" data-name="<?=$esc(strtolower($row['name']))?>"
          data-sds="<?=$esc($status)?>" data-coa="<?=$coaCount>0?'yes':'no'?>">
          <div class="ankh-doc-product-main"><span class="ankh-doc-vial" aria-hidden="true">☥</span>
          <div><strong><?=$esc($row['name'])?></strong><small>Product #<?=(int)$row['id']?> · Approved in Order Admin</small></div></div>
          <div class="ankh-doc-product-status">
            <span class="ankh-doc-pill <?=$esc($status)?>" aria-label="Safety Data Sheet status"><?=$esc($statusLabel)?></span>
            <span class="ankh-doc-pill <?=$coaCount?'coa-ready':'coa-missing'?>" aria-label="Analytical certificate status">
            <?=$coaCount?$coaCount.' public analytical report'.($coaCount===1?'':'s'):'No public COA'?></span>
          </div>
          <div class="ankh-doc-product-detail">
          <?php if($row['sds_date']>0):?><small>SDS record: <?=gmdate('d M Y',(int)$row['sds_date'])?></small><?php endif;?>
          <?php if($row['sds_private_count']>0):?><small><?=(int)$row['sds_private_count']?> private/archived SDS revision(s)</small><?php endif;?>
          <?php if($row['coa_private_count']>0):?><small><?=(int)$row['coa_private_count']?> private/archived analytical report(s)</small><?php endif;?>
          <?php if($row['spec_count']>0):?><small><?=(int)$row['spec_count']?> public uploaded specification(s)</small><?php endif;?>
          </div>
          <div class="ankh-doc-product-links">
            <a href="<?=$esc($cms)?>#ankh-certificates">Manage documents ↗</a>
            <?php if($publishedSds&&$row['sds_id']):?><a href="./product-safety-document.php?id=<?=(int)$row['sds_id']?>" target="_blank" rel="noopener">Open SDS PDF ↗</a><?php endif;?>
            <?php if($coaCount&&$row['coa_id']):?><a href="./product-document.php?id=<?=(int)$row['coa_id']?>" target="_blank" rel="noopener">Open COA ↗</a><?php endif;?>
            <a href="./?product=<?=(int)$row['id']?>" target="_blank" rel="noopener">Live product ↗</a>
          </div>
        </article>
      <?php endforeach;?></div>
      <div class="ankh-doc-empty" id="ankh-doc-empty" hidden>No products match these filters. Clear the search or select All live products.</div>
      <p class="ankh-doc-bottom-note">Product specification pages are generated from published CMS data and are not counted as independent analytical reports. Revisions stay in each product’s document manager. No source SDS is assumed for a different strength, formulation or mixture.</p>
    </section>
    <?php
}
