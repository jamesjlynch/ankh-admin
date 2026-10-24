<?php
declare(strict_types=1);
/**
 * Staff-only storefront CMS. Public copy is stored separately from the Order
 * Admin database. Product IDs, publication visibility, prices and stock always
 * come from Admin. Drafts are never public until deliberately published.
 */
function ankhStaffLoginDestination(array $query):string {
    $panels=['products','certificates','commerce','requests','availability','contact'];
    if(($query['manage']??'')==='portal'){
        $panel=is_string($query['panel']??null)&&in_array($query['panel'],$panels,true)?$query['panel']:'products';
        $params=['manage'=>'portal','panel'=>$panel];
        if($panel==='commerce'&&is_string($query['settings']??null)&&in_array($query['settings'],['business','basket','checkout','requests'],true))$params['settings']=$query['settings'];
        $id=filter_var($query['cms_product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($panel==='products'&&$id)$params['cms_product_id']=$id;
        if($panel==='products'&&($query['source']??'')==='live')$params['source']='live';
    }elseif(isset($query['customer_id'])||isset($query['q'])||($query['preview']??'')==='customers'){
        $params=['preview'=>'customers'];
        $id=filter_var($query['customer_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id)$params['customer_id']=$id;
        if(is_string($query['q']??null))$params['q']=substr($query['q'],0,80);
        if(is_string($query['tab']??null)&&in_array($query['tab'],['home','orders','vault','support','account','insights','recurring','notifications','catalogue','basket','fridge'],true))$params['tab']=$query['tab'];
    }else $params=['manage'=>'portal','panel'=>'products'];
    return './preview-live.php?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}
/** Only rendered by authenticated staff pages; never by customer accounts. */
function ankhCmsIcon(string $key):string {
    $paths=match($key){
      'products'=>'<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
      'certificates'=>'<path d="M6 3h9l3 3v15H6zM14 3v5h4M9 12h6M9 16h4"/>',
      'commerce'=>'<path d="M3 10h18M5 10v11h14V10M3 10l2-7h14l2 7M9 21v-7h6v7"/>',
      'requests'=>'<path d="M4 4h16v12H9l-5 5zM8 8h8M8 12h5"/>',
      'availability'=>'<path d="M3 12s3-7 9-7 9 7 9 7-3 7-9 7-9-7-9-7z"/><circle cx="12" cy="12" r="3"/>',
      'contact'=>'<path d="M4 20l1-5a8 8 0 1 1 4 4zM9 8c0 4 3 7 7 7"/>',
      default=>'<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>'};
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'.$paths.'</svg>';
}
function ankhStaffNavigation(string $active,string $csrf):void {
    $esc=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $items=['products'=>['Product CMS','Content & certificates'],
        'commerce'=>['Storefront settings','Business & basket settings'],'requests'=>['Customer requests','Review requests'],
        'availability'=>['Availability','Public stock labels'],'contact'=>['WhatsApp','Contact settings'],
        'preview'=>['Customer preview','View customer accounts']];
    ?>
    <section class="ankh-staff-menu" aria-label="Staff workspace">
      <div class="ankh-staff-menu-heading"><a class="ankh-staff-home" href="./preview-live.php?manage=portal&amp;panel=products"><span class="cms-brand-mark" aria-hidden="true">☥</span><span class="cms-brand-name">ANKH<small>CONTENT STUDIO</small></span></a>
        <div class="ankh-staff-menu-actions"><a href="./" target="_blank" rel="noopener">View website ↗</a>
        <form method="post" action="./preview-live.php"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><button name="action" value="logout">Sign out</button></form></div></div>
      <details class="ankh-staff-menu-disclosure" open><summary>Staff menu <span><?=$esc($items[$active][0]??'Product CMS')?> <b aria-hidden="true">⌄</b></span></summary>
        <nav class="pc-studio-nav" aria-label="Admin sections"><?php foreach($items as $key=>[$label,$hint]):$href=$key==='preview'?'./preview-live.php?preview=customers':'./preview-live.php?manage=portal&panel='.$key;?>
          <a href="<?=$esc($href)?>" class="<?=$active===$key?'selected':''?>" <?=$active===$key?'aria-current="page"':''?>><span class="cms-nav-icon" aria-hidden="true"><?=ankhCmsIcon($key)?></span><span class="cms-nav-copy"><strong><?=$esc($label)?></strong><small><?=$esc($hint)?></small></span></a>
        <?php endforeach;?></nav>
      </details><div class="cms-sidebar-note"><span aria-hidden="true">↔</span><span>Connected to Order Admin<small>Prices, stock &amp; visibility managed there.</small></span></div>
    </section>
    <?php
}
function ankhCmsInit(PDO $db):void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_cms_product_content(
        product_id INTEGER PRIMARY KEY,
        draft_description TEXT NOT NULL DEFAULT '',
        draft_information TEXT NOT NULL DEFAULT '{}',
        draft_image BLOB,
        draft_image_mime TEXT NOT NULL DEFAULT '',
        published_description TEXT NOT NULL DEFAULT '',
        published_information TEXT NOT NULL DEFAULT '{}',
        published_image BLOB,
        published_image_mime TEXT NOT NULL DEFAULT '',
        has_published INTEGER NOT NULL DEFAULT 0 CHECK(has_published IN(0,1)),
        updated_at INTEGER NOT NULL DEFAULT 0,
        published_at INTEGER NOT NULL DEFAULT 0
    )");
}
function ankhCmsRead(PDO $db,int $productId):?array {
    if($productId<1)return null;
    if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_cms_product_content'")->fetchColumn())return null;
    $q=$db->prepare('SELECT * FROM portal_cms_product_content WHERE product_id=?');
    $q->execute([$productId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}
function ankhCmsProduct(PDO $admin,int $id):?array {
    if($id<1)return null;
    $q=$admin->prepare("SELECT p.id,p.name,p.active,COALESCE(v.visible,0) AS visible,
        COALESCE(c.description,'') AS description,
        COALESCE(i.information,'{}') AS information,
        COALESCE(c.image_mime,'') AS image_mime
        FROM products p
        LEFT JOIN customer_catalogue_visibility v ON v.product_id=p.id
        LEFT JOIN customer_catalogue_content c ON c.product_id=p.id
        LEFT JOIN customer_catalogue_information i ON i.product_id=p.id
        WHERE p.id=?");
    $q->execute([$id]);$p=$q->fetch(PDO::FETCH_ASSOC);
    return $p?:null;
}
function ankhCmsSetDraft(PDO $store,PDO $admin,int $id,string $description,array $information,array $file,bool $removePhoto):void {
    $product=ankhCmsProduct($admin,$id);
    if(!$product||!(int)$product['active']||!(int)$product['visible'])throw new InvalidArgumentException('This product must be approved for the shop in Order Admin before editing it in the CMS.');
    $description=trim($description);
    if(strlen($description)>80000 || !preg_match('//u',$description) || preg_match_all('/./us',$description)>20000)
        throw new InvalidArgumentException('Description must be valid text of at most 20,000 characters.');
    $information=ankhCatalogueValidateInformation($information);
    // The COA must continue to come from the separately verified document manager.
    unset($information['coa_url']);
    $photo=null;$photoMime='';
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
        $valid=ankhCataloguePhotoUpload($file);
        if(!$valid)throw new InvalidArgumentException('Select a valid JPEG, PNG or WebP image.');
        $photo=$valid['data'];$photoMime=$valid['mime'];
    }
    ankhCmsInit($store);
    $original=ankhCmsRead($store,$id);
    if(!$original){
        $q=$store->prepare('INSERT INTO portal_cms_product_content(product_id,draft_description,draft_information,updated_at)
            VALUES(?,?,?,?)');
        $q->execute([$id,$description,json_encode($information,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),time()]);
    }else{
        $store->prepare('UPDATE portal_cms_product_content SET draft_description=?,draft_information=?,updated_at=? WHERE product_id=?')
            ->execute([$description,json_encode($information,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),time(),$id]);
    }
    if($removePhoto)$store->prepare("UPDATE portal_cms_product_content SET draft_image=NULL,draft_image_mime='' WHERE product_id=?")->execute([$id]);
    if($photo!==null)$store->prepare('UPDATE portal_cms_product_content SET draft_image=?,draft_image_mime=? WHERE product_id=?')->execute([$photo,$photoMime,$id]);
}
function ankhCmsPublish(PDO $store,PDO $admin,int $id):void{
    $product=ankhCmsProduct($admin,$id);
    if(!$product||!(int)$product['active']||!(int)$product['visible'])
        throw new InvalidArgumentException('Publish this active product in ANKH Order Admin before releasing its CMS content.');
    ankhCmsInit($store);
    if(!ankhCmsRead($store,$id))throw new InvalidArgumentException('Save the product description before publishing.');
    $store->prepare('UPDATE portal_cms_product_content SET
      published_description=draft_description,published_information=draft_information,
      published_image=draft_image,published_image_mime=draft_image_mime,
      has_published=1,published_at=? WHERE product_id=?')->execute([time(),$id]);
}
function ankhCmsUnpublish(PDO $store,int $id):void{
    ankhCmsInit($store);
    $store->prepare('UPDATE portal_cms_product_content SET has_published=0 WHERE product_id=?')->execute([$id]);
}
function ankhCmsOverlay(PDO $store,array $products):array{
    if(!$products || !$store->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_cms_product_content'")->fetchColumn())return $products;
    $rows=$store->query('SELECT product_id,published_description,published_information,published_image_mime
       FROM portal_cms_product_content WHERE has_published=1')->fetchAll(PDO::FETCH_ASSOC);
    $byId=[];foreach($rows as $row)$byId[(int)$row['product_id']]=$row;
    foreach($products as &$product){
       $row=$byId[(int)$product['id']]??null;if(!$row)continue;
       $product['description']=(string)$row['published_description'];
       $info=json_decode((string)$row['published_information'],true);
       if(is_array($info))$product['information']=$info;
       unset($product['information']['coa_url']);
       if(in_array($row['published_image_mime'],['image/png','image/jpeg','image/webp'],true)){
          $product['has_photo']=true;$product['cms_photo']=true;
       }
    }unset($product);
    return $products;
}
function ankhCmsPhotoUrl(array $product,string $size='thumb'):string{
    $endpoint=!empty($product['cms_photo'])?'./cms-product-image.php?product=':'./public-catalogue.php?image=';
    return $endpoint.(int)$product['id'].'&size='.rawurlencode($size);
}
function ankhCmsStaffController(PDO $store,PDO $admin):void {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')return;
    $action=(string)($_POST['action']??'');
    if(!in_array($action,['cms_save_draft','cms_publish','cms_unpublish'],true))return;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)($_SESSION['portal_csrf']??''),(string)$_POST['csrf'])){
        http_response_code(400);exit('Invalid request.');
    }
    $id=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    try{
        if(!$id)throw new InvalidArgumentException('Choose a product.');
        $cmsProduct=ankhCmsProduct($admin,(int)$id);
        if(!$cmsProduct||!(int)$cmsProduct['active']||!(int)$cmsProduct['visible'])throw new InvalidArgumentException('This product is not approved to show on the shop.');
        if($action==='cms_save_draft'){
            $saveAndPublish=($_POST['cms_intent']??'')==='publish';
            if($saveAndPublish){
                $product=ankhCmsProduct($admin,(int)$id);
                if(!$product||!(int)$product['active']||!(int)$product['visible'])throw new InvalidArgumentException('Make this product visible in Order Admin before publishing.');
                $store->beginTransaction();
            }
            if(!is_string($_POST['cms_description']??null)||!is_array($_POST['cms_information']??null))throw new InvalidArgumentException('Check the editor fields.');
            ankhCmsSetDraft($store,$admin,(int)$id,$_POST['cms_description'],$_POST['cms_information'],
                $_FILES['cms_photo']??[],($_POST['remove_cms_photo']??'')==='1');
            if($saveAndPublish){ankhCmsPublish($store,$admin,(int)$id);$store->commit();}
            $_SESSION['portal_staff_flash']=$saveAndPublish?'Product content saved and published.':'Draft saved. The published product has not changed.';
        }elseif($action==='cms_publish'){
            ankhCmsPublish($store,$admin,(int)$id);
            $_SESSION['portal_staff_flash']='Product content published to the customer website.';
        }else{
            ankhCmsUnpublish($store,(int)$id);
            $_SESSION['portal_staff_flash']='CMS override hidden. The Order Admin product record is unchanged.';
        }
    }catch(InvalidArgumentException $e){if($store->inTransaction())$store->rollBack();$_SESSION['portal_staff_flash']=$e->getMessage();}
    catch(Throwable $e){if($store->inTransaction())$store->rollBack();error_log('ANKH CMS: '.$e->getMessage());$_SESSION['portal_staff_flash']='Unable to save. No product prices or stock were changed.';}
    header('Location: ./preview-live.php?manage=portal&panel=products&cms_product_id='.(int)$id,true,303);exit;
}
function ankhCmsEditor(PDO $store,PDO $admin,string $csrf):void{
    $esc=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $products=$admin->query("SELECT p.id,p.name,p.active,COALESCE(v.visible,0) AS visible,COALESCE(c.image_mime,'') AS image_mime FROM products p
        INNER JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1 LEFT JOIN customer_catalogue_content c ON c.product_id=p.id WHERE p.active=1 ORDER BY p.name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
    foreach($products as &$libraryProduct)$libraryProduct['has_photo']=in_array($libraryProduct['image_mime'],['image/jpeg','image/png','image/webp'],true);
    unset($libraryProduct);
    $products=ankhCmsOverlay($store,$products);
    $selectedId=(int)($_GET['cms_product_id']??0);
    $allowedIds=array_column($products,'id');
    $selected=$selectedId&&in_array($selectedId,array_map('intval',$allowedIds),true)?ankhCmsProduct($admin,$selectedId):null;
    $draft=$selected?ankhCmsRead($store,$selectedId):null;
    $fallbackInfo=$selected?json_decode((string)$selected['information'],true):[];
    if(!is_array($fallbackInfo))$fallbackInfo=[];
    $information=$draft?json_decode((string)$draft['draft_information'],true):$fallbackInfo;
    if(!is_array($information))$information=[];
    $description=$draft?(string)$draft['draft_description']:(string)($selected['description']??'');
    $live=$selected?ankhCmsOverlay($store,[['id'=>$selectedId,'description'=>$selected['description'],'information'=>$fallbackInfo,'has_photo'=>$selected['image_mime']!=='']])[0]:null;
    $editingLive=($_GET['source']??'')==='live';
    if($editingLive&&$live){$description=(string)$live['description'];$information=(array)$live['information'];}
    $visibleCount=count(array_filter($products,static fn($p)=>(int)$p['active']===1&&(int)$p['visible']===1));
    $groups=[
      'Identity & specifications'=>['strength','presentation','appearance','peptide_type','amino_length','formula','weight','sequence','concentration','cas','pubchem','purity'],
      'Research & references'=>['research_focus','research','applications','references','half_life'],
      'Handling & FAQs'=>['storage','prepared_storage','precautions','faq']
    ];
    ?>
    <section class="pc-panel ankh-cms" id="cms-products">
      <div class="cms-library-heading"><div><h2>Product page editor</h2><p>Manage descriptions, imagery and product information.</p></div><div class="cms-library-count"><strong><?=count($products)?></strong> shop-approved products</div></div>
      <div class="ankh-cms-grid"><aside class="ankh-cms-library"><label for="ankh-cms-search">Find a product</label><input id="ankh-cms-search" type="search" placeholder="Search approved products" autocomplete="off">
       <nav aria-label="Choose a product"><?php foreach($products as $product):
          $id=(int)$product['id'];$visible=(int)$product['visible']===1&&(int)$product['active']===1;
          ?><a href="./preview-live.php?manage=portal&amp;panel=products&amp;cms_product_id=<?=$id?>" data-cms-name="<?=$esc(strtolower($product['name']))?>" class="<?=$selectedId===$id?'current':''?>">
          <span class="ankh-cms-vial" aria-hidden="true"><?php if($visible&&!empty($product['has_photo'])):?><img src="<?=$esc(ankhCmsPhotoUrl($product,'thumb'))?>" alt="" loading="lazy"><?php else:?><?=ankhCmsIcon('products')?><?php endif;?></span><span class="ankh-cms-item-name"><strong><?=$esc($product['name'])?></strong><small><?=$visible?'Visible':'Hidden in Order Admin'?></small></span><span aria-hidden="true">›</span></a><?php endforeach;?></nav><p class="cms-library-empty" hidden>No matching products.</p></aside>
       <div class="ankh-cms-main"><?php if(!$selected):?>
          <div class="ankh-cms-empty"><span class="ankh-cms-empty-icon" aria-hidden="true">☥</span><h3><?=$products?'Choose a product to edit':'No products approved for the shop'?></h3><p>Only active products approved to show on the shop in Order Admin appear here. Descriptions, images and certificates are managed together.</p></div>
       <?php else:
         $isVisible=(int)$selected['active']===1&&(int)$selected['visible']===1;
         $published=$draft&&(int)$draft['has_published']===1;
         ?>
         <div class="ankh-cms-heading"><div><span class="pc-eyebrow">PRODUCT / #<?=str_pad((string)$selectedId,3,'0',STR_PAD_LEFT)?></span><h3><?=$esc($selected['name'])?></h3></div>
           <span class="ankh-cms-status <?=$isVisible?'visible':'hidden'?>"><?=$isVisible?'● Visible on storefront':'○ Hidden in Order Admin'?></span></div>
         <div class="cms-product-shortcuts"><a href="#ankh-certificates">Certificates &amp; documents ↓</a></div>
         <div class="cms-editor-meta"><span><i class="cms-status-dot"></i><?=$draft?'Saved draft':'Existing website content'?><?php if($draft):?> · <?=gmdate('d M Y, H:i',(int)$draft['updated_at'])?> UTC<?php endif;?></span><?php if($isVisible):?><a href="./?product=<?=$selectedId?>" target="_blank" rel="noopener">View live page ↗</a><?php endif;?></div>
         <div class="cms-content-tabs"><a class="<?=$editingLive?'':'selected'?>" href="./preview-live.php?manage=portal&amp;panel=products&amp;cms_product_id=<?=$selectedId?>">Edit draft</a><a class="<?=$editingLive?'selected':''?>" href="./preview-live.php?manage=portal&amp;panel=products&amp;cms_product_id=<?=$selectedId?>&amp;source=live">Load live text</a></div>
         <?php if($editingLive):?><p class="cms-editor-note">The current website description and product information are loaded below. Your saved draft stays unchanged until you click Save draft.</p><?php endif;?>
         <form method="post" action="./preview-live.php?manage=portal&amp;panel=products" enctype="multipart/form-data" class="ankh-cms-editor" id="ankh-cms-editor">
          <input type="hidden" name="csrf" value="<?=$esc($csrf)?>">
          <input type="hidden" name="action" value="cms_save_draft">
          <input type="hidden" name="product_id" value="<?=$selectedId?>">
          <div class="ankh-cms-field"><label for="ankh-cms-description">Product description</label><p>The introduction customers read on the product page. Use the formatting toolbar to add headings and lists.</p>
           <div class="ankh-cms-toolbar" role="toolbar" aria-label="Description formatting"><button type="button" data-cms-format="heading">Heading</button><button type="button" data-cms-format="bold">Bold</button><button type="button" data-cms-format="list">List</button><button type="button" data-cms-format="link">Link</button></div>
           <textarea id="ankh-cms-description" name="cms_description" rows="11" maxlength="20000" data-cms-text><?=$esc($description)?></textarea>
           <details class="ankh-cms-preview"><summary>Preview description</summary><div id="ankh-cms-preview" class="ankh-cms-preview-content"></div></details></div>
          <details class="ankh-cms-accordion"><summary><span>Product photograph</span><small>Upload or replace image</small></summary><div class="ankh-cms-accordion-body"><div class="cms-photo-editor"><?php if($isVisible&&$live&&$live['has_photo']):?><img class="cms-current-photo" src="<?=$esc(ankhCmsPhotoUrl($live,'thumb'))?>" alt="Current website photo of <?=$esc($selected['name'])?>"><?php endif;?><div>
            <?php if($draft && $draft['draft_image_mime']):?><p>Draft image uploaded and stored privately. Publish to update the website photo.</p><label class="ankh-cms-check"><input type="checkbox" name="remove_cms_photo" value="1"> Remove override; use original Order Admin photo</label>
            <?php elseif($selected['image_mime']):?><p>A product photo is already held in Order Admin. Upload a new image only if you want to replace it on the customer site.</p><?php endif;?>
            <label class="ankh-cms-field">Upload new photo <input type="file" name="cms_photo" accept="image/jpeg,image/png,image/webp"></label><p class="cms-upload-status" role="status"></p><img class="cms-upload-preview" alt="Selected image preview" hidden></div></div></div></details>
          <?php foreach($groups as $group=>$keys):?>
           <details class="ankh-cms-accordion"><summary><span><?=$esc($group)?></span><small><?=count($keys)?> editable fields</small></summary><div class="ankh-cms-accordion-body ankh-cms-fields">
           <?php foreach($keys as $key):$long=in_array($key,ankhCatalogueLongFields(),true)||in_array($key,['sequence','research_focus'],true);$lim=in_array($key,ankhCatalogueLongFields(),true)?5000:600;?><label class="ankh-cms-field <?=$long?'wide':''?>"><?=$esc(ankhCatalogueInformationFields()[$key]??$key)?>
           <?php if($long):?><textarea name="cms_information[<?=$key?>]" rows="4" maxlength="<?=$lim?>"><?=$esc($information[$key]??'')?></textarea>
           <?php else:?><input name="cms_information[<?=$key?>]" type="text" maxlength="<?=$lim?>" value="<?=$esc($information[$key]??'')?>"><?php endif;?></label><?php endforeach;?></div></details>
          <?php endforeach;?>
          <div class="ankh-cms-actions"><span id="cms-save-state" role="status">No unsaved changes</span><div><button type="submit" class="cms-secondary-action">Save draft</button><button type="submit" name="cms_intent" value="publish" <?=$isVisible?'':'disabled'?> class="ankh-cms-primary">Save &amp; publish</button></div></div>
         </form>
         <div class="ankh-cms-publish">
           <div><strong>Publication</strong><p><?=$published?'CMS content is currently published. Saving a draft will not alter it until you publish again.':'No CMS override is published. The customer site uses its existing Order Admin content.'?></p></div>
           <div class="ankh-cms-publish-actions"><form method="post"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="product_id" value="<?=$selectedId?>"><button type="submit" name="action" value="cms_publish" <?=$draft&&$isVisible?'':'disabled'?>>Publish saved draft</button></form>
           <?php if($published):?><form method="post"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="product_id" value="<?=$selectedId?>"><button type="submit" name="action" value="cms_unpublish">Revert to Order Admin content</button></form><?php endif;?></div>
         </div>
         <?php ankhDocsStaffSection($store,$admin,$csrf,$selectedId); ?>
       <?php endif;?></div></div>
    </section>
    <?php
}
