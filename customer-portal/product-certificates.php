<?php
declare(strict_types=1);
/**
 * Private, per-product PDF documentation. Unmodified uploaded reports retain
 * their issuer and analytical claims. All Admin product data is read-only.
 */
function ankhDocsInit(PDO $db):void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_product_documents (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      product_id INTEGER NOT NULL,
      document_kind TEXT NOT NULL CHECK(document_kind IN ('original_coa','ankh_batch_coa','technical_spec')),
      issuer TEXT NOT NULL DEFAULT '',
      batch_ref TEXT NOT NULL DEFAULT '',
      report_ref TEXT NOT NULL DEFAULT '',
      pdf_name TEXT NOT NULL,
      pdf_sha256 TEXT NOT NULL,
      pdf_bytes BLOB NOT NULL,
      created_at INTEGER NOT NULL,
      published INTEGER NOT NULL DEFAULT 0 CHECK(published IN (0,1)),
      superseded INTEGER NOT NULL DEFAULT 0 CHECK(superseded IN (0,1))
    );
    CREATE INDEX IF NOT EXISTS idx_portal_documents_public
      ON portal_product_documents(product_id,published,superseded,id);");
}
function ankhDocsPublished(PDO $store,int $productId):array {
    if($productId<1)return [];
    if(!$store->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_product_documents'")->fetchColumn())return [];
    $q=$store->prepare("SELECT id,product_id,document_kind,issuer,batch_ref,report_ref,pdf_name,created_at
        FROM portal_product_documents
        WHERE product_id=? AND published=1 AND superseded=0 ORDER BY id DESC LIMIT 12");
    $q->execute([$productId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function ankhDocsAll(PDO $store,?int $productId=null):array {
    if(!$store->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_product_documents'")->fetchColumn())return [];
    $q=$store->prepare("SELECT id,product_id,document_kind,issuer,batch_ref,report_ref,pdf_name,
        created_at,published,superseded FROM portal_product_documents".($productId!==null?' WHERE product_id=?':'')." ORDER BY id DESC LIMIT 150");
    $q->execute($productId!==null?[$productId]:[]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function ankhDocsProductAllowed(PDO $admin,int $id,bool $mustBePublished):bool {
    if($id<1)return false;
    if($mustBePublished){
        $q=$admin->prepare('SELECT 1 FROM products p INNER JOIN customer_catalogue_visibility v
          ON v.product_id=p.id AND v.visible=1 WHERE p.id=? AND p.active=1');
    }else{
        $q=$admin->prepare('SELECT 1 FROM products WHERE id=? AND active=1');
    }
    $q->execute([$id]);return (bool)$q->fetchColumn();
}
function ankhDocsUpload(PDO $store,PDO $admin,array $form,array $file):int {
    $id=filter_var($form['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($id===false||!ankhDocsProductAllowed($admin,(int)$id,false))
        throw new InvalidArgumentException('Select an active product.');
    $kind=(string)($form['document_kind']??'');
    if(!in_array($kind,['original_coa','ankh_batch_coa','technical_spec'],true))
        throw new InvalidArgumentException('Select the correct document type.');
    $issuer=trim((string)($form['issuer']??''));
    $batch=trim((string)($form['batch_ref']??''));
    $reference=trim((string)($form['report_ref']??''));
    if(strlen($issuer)<3||strlen($issuer)>180||strlen($batch)>120||strlen($reference)>120
       ||preg_match('//u',$issuer.$batch.$reference)!==1)
        throw new InvalidArgumentException('Add a genuine issuing organisation and valid references.');
    if($kind==='ankh_batch_coa' && (strlen($batch)<2||strlen($reference)<2||($form['matches_batch']??'')!=='1'))
        throw new InvalidArgumentException('ANKH batch certificates require a verified matching lot and report reference.');
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK
       ||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))
        throw new InvalidArgumentException('Select the unchanged original PDF report.');
    if((int)($file['size']??0)<80||(int)$file['size']>8*1024*1024)
        throw new InvalidArgumentException('PDF report must be between 80 bytes and 8 MB.');
    $contents=file_get_contents($file['tmp_name']);
    if(!is_string($contents)||strlen($contents)>8*1024*1024
       ||!str_starts_with($contents,'%PDF-')||!str_contains(substr($contents,-2048),'%%EOF'))
        throw new InvalidArgumentException('The upload is not a complete PDF report.');
    if(class_exists('finfo')&&(new finfo(FILEINFO_MIME_TYPE))->buffer($contents)!=='application/pdf')
        throw new InvalidArgumentException('Only PDFs are supported.');
    $filename=basename((string)($file['name']??'report.pdf'));
    $filename=preg_replace('/[^A-Za-z0-9._-]/','-',$filename)??'report.pdf';
    if(strlen($filename)>130 || !str_ends_with(strtolower($filename),'.pdf'))$filename='report.pdf';
    $publish=($form['publish_now']??'')==='1' && ankhDocsProductAllowed($admin,(int)$id,true);
    ankhDocsInit($store);
    $store->beginTransaction();
    try{
        if(($form['replace_previous']??'')==='1')
            $store->prepare('UPDATE portal_product_documents SET published=0,superseded=1 WHERE product_id=?')
                ->execute([(int)$id]);
        $q=$store->prepare("INSERT INTO portal_product_documents
            (product_id,document_kind,issuer,batch_ref,report_ref,pdf_name,pdf_sha256,pdf_bytes,created_at,published)
            VALUES(?,?,?,?,?,?,?,?,?,?)");
        $q->execute([(int)$id,$kind,$issuer,$batch,$reference,$filename,hash('sha256',$contents),$contents,time(),$publish?1:0]);
        $new=(int)$store->lastInsertId();
        $store->commit();return $new;
    }catch(Throwable $e){$store->rollBack();throw $e;}
}
function ankhDocsChangeVisibility(PDO $store,PDO $admin,int $id,bool $published):void {
    if($id<1)throw new InvalidArgumentException('Invalid document.');
    ankhDocsInit($store);
    $q=$store->prepare('SELECT product_id,superseded FROM portal_product_documents WHERE id=?');$q->execute([$id]);
    $doc=$q->fetch(PDO::FETCH_ASSOC);
    if(!$doc || (int)$doc['superseded']===1)throw new InvalidArgumentException('The document is unavailable.');
    if($published&&!ankhDocsProductAllowed($admin,(int)$doc['product_id'],true))
        throw new InvalidArgumentException('Publish the active product before publishing documents.');
    $store->prepare('UPDATE portal_product_documents SET published=? WHERE id=?')->execute([$published?1:0,$id]);
}
function ankhDocsStaffController(PDO $store,PDO $admin):void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')return;
    $action=(string)($_POST['action']??'');
    if(!in_array($action,['ankh_document_upload','ankh_document_visibility'],true))return;
    if(!is_string($_POST['csrf']??null)||
       !hash_equals((string)($_SESSION['portal_csrf']??''),(string)$_POST['csrf'])){
        http_response_code(400);exit('Invalid staff request.');
    }
    $returnProductId=(int)($_POST['product_id']??0);
    try{
        if($action==='ankh_document_visibility'){
            $q=$store->prepare('SELECT product_id FROM portal_product_documents WHERE id=?');$q->execute([(int)($_POST['document_id']??0)]);
            $actualProductId=(int)$q->fetchColumn();
            if(!$actualProductId||($returnProductId&&$returnProductId!==$actualProductId))throw new InvalidArgumentException('This certificate does not belong to the selected product.');
            $returnProductId=$actualProductId;
        }
        if(!ankhDocsProductAllowed($admin,$returnProductId,true))throw new InvalidArgumentException('Only shop-approved products can be managed in the CMS.');
        if($action==='ankh_document_upload'){
            ankhDocsUpload($store,$admin,$_POST,$_FILES['original_pdf']??[]);
            $_SESSION['portal_staff_flash']='Product documentation uploaded. Review the public status below.';
        }else{
            ankhDocsChangeVisibility($store,$admin,(int)($_POST['document_id']??0),($_POST['visibility']??'')==='publish');
            $_SESSION['portal_staff_flash']='Certificate visibility updated.';
        }
    }catch(InvalidArgumentException $e){$_SESSION['portal_staff_flash']=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH certificate manager: '.$e->getMessage());$_SESSION['portal_staff_flash']='The document could not be saved.';}
    header('Location: ./preview-live.php?manage=portal&panel=products&cms_product_id='.$returnProductId.'#ankh-certificates',true,303);exit;
}
function ankhDocsKindName(string $kind):string {
    return match($kind){
      'ankh_batch_coa'=>'ANKH matching-batch COA',
      'original_coa'=>'Original analytical certificate',
      'technical_spec'=>'Product specification',
      default=>'Documentation'
    };
}
function ankhDocsStaffSection(PDO $store,PDO $admin,string $csrf,?int $productId=null):void {
    if($productId!==null&&!ankhDocsProductAllowed($admin,$productId,true))return;
    $documents=ankhDocsAll($store,$productId);
    $q=$admin->prepare('SELECT p.id,p.name FROM products p JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1 WHERE p.active=1'.($productId!==null?' AND p.id=?':'').' ORDER BY p.name COLLATE NOCASE');
    $q->execute($productId!==null?[$productId]:[]);$products=$q->fetchAll(PDO::FETCH_ASSOC);
    $names=[];foreach($products as $p)$names[(int)$p['id']]=(string)$p['name'];
    $esc=static fn(mixed $value):string=>(string)htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $formAction='./preview-live.php?manage=portal&panel=products'.($productId!==null?'&cms_product_id='.$productId:'').'#ankh-certificates';
    ?>
    <section class="pc-panel ankh-cert-admin" id="ankh-certificates">
      <div class="pc-section-header"><div><span class="pc-eyebrow">DOCUMENT CONTROL</span><h2>Certificates &amp; documents</h2></div></div>
      <p class="pc-footnote">Upload the original report for a specific product. Keep the laboratory/issuing organisation and analytical information intact. Publish, unpublish or replace documents as new test reports arrive. Unpublished products never show public PDFs.</p>
      <form method="post" enctype="multipart/form-data" class="ankh-coa-form" action="<?=$esc($formAction)?>">
        <input type="hidden" name="csrf" value="<?=$esc($csrf)?>">
        <input type="hidden" name="action" value="ankh_document_upload">
        <?php if($productId!==null):?><input type="hidden" name="product_id" value="<?=$productId?>"><p class="cms-document-product">Documents for <strong><?=$esc($names[$productId])?></strong></p><?php else:?>
        <label>Product<select name="product_id" required><option value="">Choose product</option>
        <?php foreach($products as $p):?><option value="<?=(int)$p['id']?>"><?=$esc($p['name'])?></option><?php endforeach;?></select></label><?php endif;?>
        <label>Document type<select name="document_kind" required>
          <option value="original_coa">Original COA / external report</option>
          <option value="ankh_batch_coa">ANKH batch-tested report</option>
          <option value="technical_spec">Technical specification</option>
        </select></label>
        <label>Report issuer<input name="issuer" type="text" required maxlength="180" placeholder="Laboratory or report issuer"></label>
        <label>Lot / batch reference<input name="batch_ref" type="text" maxlength="120" placeholder="As shown in report"></label>
        <label>Report reference<input name="report_ref" type="text" maxlength="120" placeholder="Original report reference"></label>
        <label>Original PDF<input name="original_pdf" type="file" accept=".pdf,application/pdf" required></label>
        <label class="ankh-check"><input name="matches_batch" type="checkbox" value="1"> I checked this report against the ANKH batch (required for ANKH batch COAs)</label>
        <label class="ankh-check"><input name="replace_previous" type="checkbox" value="1"> Archive previous documents for this product</label>
        <label class="ankh-check"><input name="publish_now" type="checkbox" value="1"> Publish immediately if the product is public</label>
        <button type="submit">Upload product document</button>
      </form>
      <?php if(!$documents):?><p class="pc-footnote">No reports uploaded yet. Nothing will be advertised as independently tested until a matching report is uploaded.</p><?php endif;?>
      <div class="ankh-coa-list">
      <?php foreach($documents as $doc):if(!isset($names[(int)$doc['product_id']]))continue;?>
      <div class="ankh-coa-row"><div><strong><?=$esc($names[(int)$doc['product_id']])?></strong>
        <small><?=$esc(ankhDocsKindName((string)$doc['document_kind']))?> · <?=$esc($doc['issuer'])?>
        <?php if($doc['batch_ref']!==''):?> · Batch <?=$esc($doc['batch_ref'])?><?php endif;?></small>
        <?php if($doc['published']&&!$doc['superseded']):?><a href="./product-document.php?id=<?=(int)$doc['id']?>" target="_blank" rel="noopener">View certificate ↗</a><?php endif;?>
        <small><?=$esc($doc['pdf_name'])?> · <?=$doc['superseded']?'Archived':($doc['published']?'Public':'Hidden')?></small></div>
      <?php if(!$doc['superseded']):?><form method="post" action="<?=$esc($formAction)?>">
        <input type="hidden" name="csrf" value="<?=$esc($csrf)?>">
        <input type="hidden" name="action" value="ankh_document_visibility">
        <input type="hidden" name="document_id" value="<?=(int)$doc['id']?>"><input type="hidden" name="product_id" value="<?=(int)$doc['product_id']?>">
        <button type="submit" name="visibility" value="<?=$doc['published']?'hide':'publish'?>"><?=$doc['published']?'Hide':'Publish'?></button>
      </form><?php endif;?></div><?php endforeach;?></div>
    </section>
    <?php
}
