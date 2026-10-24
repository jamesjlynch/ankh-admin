<?php
declare(strict_types=1);
/** ANKH SDS library. Separate from batch-specific COAs; drafts remain private. */
require_once __DIR__.'/product-certificates.php';

function ankhSdsInit(PDO $db):void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_product_sds (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      product_id INTEGER NOT NULL,
      pdf_name TEXT NOT NULL,
      pdf_sha256 TEXT NOT NULL,
      pdf_bytes BLOB NOT NULL,
      source_url TEXT NOT NULL DEFAULT '',
      created_at INTEGER NOT NULL,
      published INTEGER NOT NULL DEFAULT 0 CHECK(published IN(0,1)),
      reviewed_by TEXT NOT NULL DEFAULT '',
      review_note TEXT NOT NULL DEFAULT '',
      reviewed_at INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS idx_portal_sds_visible ON portal_product_sds(product_id,published,id);");
}
function ankhSdsPublished(PDO $store,int $productId):array {
    if($productId<1)return [];
    if(!$store->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_product_sds'")->fetchColumn())return [];
    $q=$store->prepare("SELECT id,product_id,pdf_name,reviewed_at FROM portal_product_sds WHERE product_id=? AND published=1 AND reviewed_at>0 ORDER BY id DESC LIMIT 1");
    $q->execute([$productId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function ankhSdsAll(PDO $store,int $productId):array {
    if(!$store->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_product_sds'")->fetchColumn())return [];
    $q=$store->prepare("SELECT id,product_id,pdf_name,pdf_sha256,source_url,created_at,published,reviewed_at,reviewed_by FROM portal_product_sds WHERE product_id=? ORDER BY id DESC LIMIT 15");
    $q->execute([$productId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function ankhSdsSaveBytes(PDO $store,PDO $admin,int $productId,string $filename,string $bytes,string $source=''):int {
    if(!ankhDocsProductAllowed($admin,$productId,true))throw new InvalidArgumentException('Only active visible ANKH products can receive SDSs.');
    if(strlen($bytes)<80||strlen($bytes)>8*1024*1024||!str_starts_with($bytes,'%PDF-')||!str_contains(substr($bytes,-2048),'%%EOF'))
        throw new InvalidArgumentException('Choose a complete PDF smaller than 8 MB.');
    if(class_exists('finfo')&&(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)!=='application/pdf')
        throw new InvalidArgumentException('Only PDF safety sheets are supported.');
    if($source!==''&&(!filter_var($source,FILTER_VALIDATE_URL)||strtolower((string)parse_url($source,PHP_URL_SCHEME))!=='https'))
        throw new InvalidArgumentException('Invalid reference document link.');
    $name=preg_replace('/[^A-Za-z0-9._-]/','-',basename($filename))??'ANKH-SDS.pdf';
    if(!str_ends_with(strtolower($name),'.pdf')||strlen($name)>130)$name='ANKH-SDS.pdf';
    ankhSdsInit($store);
    $hash=hash('sha256',$bytes);
    $check=$store->prepare('SELECT id FROM portal_product_sds WHERE product_id=? AND pdf_sha256=? LIMIT 1');
    $check->execute([$productId,$hash]);$found=$check->fetchColumn();
    if($found)return (int)$found; // idempotent, never silently republish
    $insert=$store->prepare('INSERT INTO portal_product_sds(product_id,pdf_name,pdf_sha256,pdf_bytes,source_url,created_at,published) VALUES(?,?,?,?,?,?,0)');
    $insert->bindValue(1,$productId,PDO::PARAM_INT);$insert->bindValue(2,$name);
    $insert->bindValue(3,$hash);$insert->bindValue(4,$bytes,PDO::PARAM_LOB);
    $insert->bindValue(5,$source);$insert->bindValue(6,time(),PDO::PARAM_INT);
    $insert->execute();return (int)$store->lastInsertId();
}
function ankhSdsPublish(PDO $store,PDO $admin,int $docId,bool $publish,string $reviewer='',string $note=''):void {
    ankhSdsInit($store);
    $q=$store->prepare('SELECT product_id,reviewed_at FROM portal_product_sds WHERE id=?');$q->execute([$docId]);
    $doc=$q->fetch(PDO::FETCH_ASSOC);
    if(!$doc||!ankhDocsProductAllowed($admin,(int)$doc['product_id'],true))
        throw new InvalidArgumentException('Document or published product not available.');
    if($publish) {
        $reviewer=trim($reviewer);$note=trim($note);
        if(mb_strlen($reviewer)<3||mb_strlen($reviewer)>120||mb_strlen($note)<20||mb_strlen($note)>1500)
            throw new InvalidArgumentException('Record the competent SDS reviewer and basis of review before publishing.');
    }
    $store->beginTransaction();
    try {
        if($publish) {
            $store->prepare('UPDATE portal_product_sds SET published=0 WHERE product_id=?')->execute([(int)$doc['product_id']]);
            $store->prepare('UPDATE portal_product_sds SET published=1,reviewed_at=?,reviewed_by=?,review_note=? WHERE id=?')
                ->execute([time(),$reviewer,$note,$docId]);
        }else{$store->prepare('UPDATE portal_product_sds SET published=0 WHERE id=?')->execute([$docId]);}
        $store->commit();
    }catch(Throwable $e){if($store->inTransaction())$store->rollBack();throw $e;}
}
function ankhSdsStaffController(PDO $store,PDO $admin):void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')return;
    $action=(string)($_POST['action']??'');
    if(!in_array($action,['ankh_sds_upload','ankh_sds_visibility'],true))return;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)($_SESSION['portal_csrf']??''),(string)$_POST['csrf'])){
        http_response_code(400);exit('Invalid staff request.');
    }
    $productId=(int)($_POST['product_id']??0);
    try {
        if(!ankhDocsProductAllowed($admin,$productId,true))throw new InvalidArgumentException('The ANKH product must be approved and visible.');
        if($action==='ankh_sds_upload'){
            $file=$_FILES['sds_pdf']??[];
            if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))
                throw new InvalidArgumentException('Choose a PDF to upload.');
            $bytes=file_get_contents($file['tmp_name']);
            if(!is_string($bytes))throw new InvalidArgumentException('PDF could not be read.');
            ankhSdsSaveBytes($store,$admin,$productId,(string)($file['name']??'sds.pdf'),$bytes,(string)($_POST['source_url']??''));
            $_SESSION['portal_staff_flash']='Safety data sheet attached privately. It will not appear on the public shop until reviewed and published.';
        }else{
            $docId=(int)($_POST['sds_id']??0);
            $q=$store->prepare('SELECT product_id FROM portal_product_sds WHERE id=?');$q->execute([$docId]);
            if((int)$q->fetchColumn()!==$productId)throw new InvalidArgumentException('The document does not belong to this product.');
            $publish=($_POST['visibility']??'')==='publish';
            if($publish&&($_POST['review_confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm the SDS technical review before publication.');
            ankhSdsPublish($store,$admin,$docId,$publish,(string)($_POST['reviewed_by']??''),(string)($_POST['review_note']??''));
            $_SESSION['portal_staff_flash']=$publish?'Reviewed SDS published on the product page.':'SDS hidden from the public product page.';
        }
    }catch(InvalidArgumentException $e){$_SESSION['portal_staff_flash']=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH SDS manager: '.$e->getMessage());$_SESSION['portal_staff_flash']='The safety sheet could not be saved.';}
    header('Location: ./preview-live.php?manage=portal&panel=products&cms_product_id='.$productId.'#ankh-sds',true,303);exit;
}
function ankhSdsStaffSection(PDO $store,PDO $admin,string $csrf,int $productId):void {
    if(!ankhDocsProductAllowed($admin,$productId,true))return;
    $docs=ankhSdsAll($store,$productId);
    $esc=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    ?>
    <section class="pc-panel ankh-sds-admin" id="ankh-sds" style="border:1px solid #bfa46b60;border-radius:18px;padding:clamp(16px,3vw,28px);margin-top:20px;background:linear-gradient(140deg,#25251d,#131613)">
      <div class="pc-section-header"><div><span class="pc-eyebrow">ANKH / PRODUCT SAFETY</span><h2>Safety Data Sheets</h2></div></div>
      <p class="pc-footnote">SDSs are separate from Certificates of Analysis. Attach drafts here, check every chemical identity and safety section, then explicitly approve public release. Hiding a product hides its SDS automatically.</p>
      <?php foreach($docs as $doc):?>
        <div class="ankh-sds-row" style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between;padding:18px;margin:12px 0;border:1px solid #cfb78355;border-radius:12px">
          <div><strong><?=$esc($doc['pdf_name'])?></strong><small style="display:block;margin-top:5px"><?=$doc['published']?'Published · reviewed by '.$esc($doc['reviewed_by']):'Private draft · not customer-visible'?></small></div>
          <form method="post" style="display:grid;gap:9px;min-width:min(100%,270px)">
            <input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="product_id" value="<?=$productId?>">
            <input type="hidden" name="sds_id" value="<?=(int)$doc['id']?>">
            <?php if(!$doc['published']):?>
              <label>Reviewer<input type="text" name="reviewed_by" maxlength="120" placeholder="Competent reviewer" required></label>
              <label>Review basis<textarea name="review_note" minlength="20" maxlength="1500" rows="3" placeholder="Chemical identity, hazard, handling, emergency information and source verified" required></textarea></label>
              <label class="ankh-check"><input type="checkbox" name="review_confirmed" value="1" required> Technical content checked and approved for ANKH supply</label>
            <?php endif;?>
            <button type="submit" name="action" value="ankh_sds_visibility" formaction="./preview-live.php?manage=portal&panel=products&cms_product_id=<?=$productId?>">
              <input type="hidden" name="visibility" value="<?=$doc['published']?'hide':'publish'?>"><?=$doc['published']?'Hide from storefront':'Approve & publish SDS'?>
            </button>
          </form>
        </div>
      <?php endforeach;?>
      <form method="post" enctype="multipart/form-data" action="./preview-live.php?manage=portal&panel=products&cms_product_id=<?=$productId?>" style="display:grid;gap:13px;margin-top:20px">
        <input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="action" value="ankh_sds_upload">
        <input type="hidden" name="product_id" value="<?=$productId?>">
        <label>Attach another SDS draft<input type="file" name="sds_pdf" accept=".pdf,application/pdf" required></label>
        <label>Source sheet URL (optional)<input type="url" name="source_url" placeholder="https://..." maxlength="1000"></label>
        <button type="submit">Attach privately for review</button>
      </form>
    </section>
    <?php
}
