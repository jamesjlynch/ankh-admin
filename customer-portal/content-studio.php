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
        $id=filter_var($query['cms_product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($panel==='products'&&$id)$params['cms_product_id']=$id;
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
function ankhStaffNavigation(string $active,string $csrf):void {
    $esc=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $items=['products'=>['Product CMS','Descriptions & images'],'certificates'=>['Certificates','Product documents'],
        'commerce'=>['Storefront settings','Business & basket settings'],'requests'=>['Customer requests','Review requests'],
        'availability'=>['Availability','Public stock labels'],'contact'=>['WhatsApp','Contact settings'],
        'preview'=>['Customer preview','View customer accounts']];
    ?>
    <section class="ankh-staff-menu" aria-label="Staff workspace">
      <div class="ankh-staff-menu-heading"><a class="ankh-staff-home" href="./preview-live.php?manage=portal&amp;panel=products">☥ ANKH <span>STAFF WORKSPACE</span></a>
        <div class="ankh-staff-menu-actions"><a href="./" target="_blank" rel="noopener">View website ↗</a>
        <form method="post" action="./preview-live.php"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><button name="action" value="logout">Sign out</button></form></div></div>
      <details class="ankh-staff-menu-disclosure" open><summary>Staff menu <span><?=$esc($items[$active][0]??'Product CMS')?> <b aria-hidden="true">⌄</b></span></summary>
        <nav class="pc-studio-nav" aria-label="Admin sections"><?php foreach($items as $key=>[$label,$hint]):$href=$key==='preview'?'./preview-live.php?preview=customers':'./preview-live.php?manage=portal&panel='.$key;?>
          <a href="<?=$esc($href)?>" class="<?=$active===$key?'selected':''?>" <?=$active===$key?'aria-current="page"':''?>><strong><?=$esc($label)?></strong><small><?=$esc($hint)?></small></a>
        <?php endforeach;?></nav>
      </details>
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
    if(!$product)throw new InvalidArgumentException('That product is not in Order Admin.');
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
        if($action==='cms_save_draft'){
            if(!is_string($_POST['cms_description']??null)||!is_array($_POST['cms_information']??null))throw new InvalidArgumentException('Check the editor fields.');
            ankhCmsSetDraft($store,$admin,(int)$id,$_POST['cms_description'],$_POST['cms_information'],
                $_FILES['cms_photo']??[],($_POST['remove_cms_photo']??'')==='1');
            $_SESSION['portal_staff_flash']='Draft saved. The published product has not changed.';
        }elseif($action==='cms_publish'){
            ankhCmsPublish($store,$admin,(int)$id);
            $_SESSION['portal_staff_flash']='Product content published to the customer website.';
        }else{
            ankhCmsUnpublish($store,(int)$id);
            $_SESSION['portal_staff_flash']='CMS override hidden. The Order Admin product record is unchanged.';
        }
    }catch(InvalidArgumentException $e){$_SESSION['portal_staff_flash']=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH CMS: '.$e->getMessage());$_SESSION['portal_staff_flash']='Unable to save. No product prices or stock were changed.';}
    header('Location: ./preview-live.php?manage=portal&panel=products&cms_product_id='.(int)$id,true,303);exit;
}
function ankhCmsEditor(PDO $store,PDO $admin,string $csrf):void{
    $esc=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $products=$admin->query('SELECT p.id,p.name,p.active,COALESCE(v.visible,0) AS visible FROM products p
        LEFT JOIN customer_catalogue_visibility v ON v.product_id=p.id ORDER BY p.name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    $selectedId=(int)($_GET['cms_product_id']??0);
    $selected=$selectedId?ankhCmsProduct($admin,$selectedId):null;
    $draft=$selected?ankhCmsRead($store,$selectedId):null;
    $fallbackInfo=$selected?json_decode((string)$selected['information'],true):[];
    if(!is_array($fallbackInfo))$fallbackInfo=[];
    $information=$draft?json_decode((string)$draft['draft_information'],true):$fallbackInfo;
    if(!is_array($information))$information=[];
    $description=$draft?(string)$draft['draft_description']:(string)($selected['description']??'');
    $groups=[
      'Identity & specifications'=>['strength','presentation','appearance','peptide_type','amino_length','formula','weight','sequence','concentration','cas','pubchem','purity'],
      'Research & references'=>['research_focus','research','applications','references','half_life'],
      'Handling & FAQs'=>['storage','prepared_storage','precautions','faq']
    ];
    ?>
    <section class="pc-panel ankh-cms" id="cms-products">
      <div class="pc-section-header"><div><span class="pc-eyebrow">CONTENT STUDIO / PRODUCT LIBRARY</span><h2>Product page editor</h2></div>
      <a href="./" target="_blank" rel="noopener">View storefront ↗</a></div>
      <p class="pc-footnote">Create a draft, update product information and publish when ready. Prices, stock, product names and visibility are read directly from ANKH Order Admin and cannot be edited here.</p>
      <div class="ankh-cms-grid"><aside class="ankh-cms-library"><label for="ankh-cms-search">Find a product</label><input id="ankh-cms-search" type="search" placeholder="Search all products" autocomplete="off">
       <nav aria-label="Choose a product"><?php foreach($products as $product):
          $id=(int)$product['id'];$visible=(int)$product['visible']===1&&(int)$product['active']===1;
          ?><a href="./preview-live.php?manage=portal&amp;panel=products&amp;cms_product_id=<?=$id?>" data-cms-name="<?=$esc(strtolower($product['name']))?>" class="<?=$selectedId===$id?'current':''?>">
          <span class="ankh-cms-vial" aria-hidden="true">◈</span><span class="ankh-cms-item-name"><strong><?=$esc($product['name'])?></strong><small><?=$visible?'Visible':'Hidden in Order Admin'?></small></span><span aria-hidden="true">›</span></a><?php endforeach;?></nav></aside>
       <div class="ankh-cms-main"><?php if(!$selected):?>
          <div class="ankh-cms-empty"><span class="ankh-cms-empty-icon" aria-hidden="true">☥</span><h3>Choose a product to edit</h3><p>Everything in one place: descriptions, imagery, molecular information, research references and published content.</p></div>
       <?php else:
         $isVisible=(int)$selected['active']===1&&(int)$selected['visible']===1;
         $published=$draft&&(int)$draft['has_published']===1;
         ?>
         <div class="ankh-cms-heading"><div><span class="pc-eyebrow">EDIT PRODUCT / #<?=$selectedId?></span><h3><?=$esc($selected['name'])?></h3></div>
           <span class="ankh-cms-status <?=$isVisible?'visible':'hidden'?>"><?=$isVisible?'● Visible on storefront':'○ Hidden in Order Admin'?></span></div>
         <div class="ankh-cms-locked"><span aria-hidden="true">🔒</span><span><strong>Connected to Order Admin</strong><small>Retail and Family &amp; Friends prices, stock quantities, sales and publication switches remain there. This editor cannot modify them.</small></span></div>
         <form method="post" action="./preview-live.php?manage=portal&amp;panel=products" enctype="multipart/form-data" class="ankh-cms-editor" id="ankh-cms-editor">
          <input type="hidden" name="csrf" value="<?=$esc($csrf)?>">
          <input type="hidden" name="action" value="cms_save_draft">
          <input type="hidden" name="product_id" value="<?=$selectedId?>">
          <div class="ankh-cms-field"><label for="ankh-cms-description">Product description</label><p>The introduction customers read on the product page. Use the formatting toolbar to add headings and lists.</p>
           <div class="ankh-cms-toolbar" role="toolbar" aria-label="Description formatting"><button type="button" data-cms-format="heading">Heading</button><button type="button" data-cms-format="bold">Bold</button><button type="button" data-cms-format="list">List</button><button type="button" data-cms-format="link">Link</button></div>
           <textarea id="ankh-cms-description" name="cms_description" rows="11" maxlength="20000" data-cms-text><?=$esc($description)?></textarea>
           <details class="ankh-cms-preview"><summary>Preview description</summary><div id="ankh-cms-preview" class="ankh-cms-preview-content"></div></details></div>
          <details class="ankh-cms-accordion" open><summary><span>Product photograph</span><small>Current image stays until replaced</small></summary><div class="ankh-cms-accordion-body">
            <?php if($draft && $draft['draft_image_mime']):?><p>Draft image uploaded and stored privately. Publish to update the website photo.</p><label class="ankh-cms-check"><input type="checkbox" name="remove_cms_photo" value="1"> Remove override; use original Order Admin photo</label>
            <?php elseif($selected['image_mime']):?><p>A product photo is already held in Order Admin. Upload a new image only if you want to replace it on the customer site.</p><?php endif;?>
            <label class="ankh-cms-field">Upload new photo <input type="file" name="cms_photo" accept="image/jpeg,image/png,image/webp"></label></div></details>
          <?php foreach($groups as $group=>$keys):?>
           <details class="ankh-cms-accordion"><summary><span><?=$esc($group)?></span><small><?=count($keys)?> editable fields</small></summary><div class="ankh-cms-accordion-body ankh-cms-fields">
           <?php foreach($keys as $key):$long=in_array($key,ankhCatalogueLongFields(),true)||in_array($key,['sequence','research_focus'],true);$lim=$long?5000:600;?><label class="ankh-cms-field <?=$long?'wide':''?>"><?=$esc(ankhCatalogueInformationFields()[$key]??$key)?>
           <?php if($long):?><textarea name="cms_information[<?=$key?>]" rows="4" maxlength="<?=$lim?>"><?=$esc($information[$key]??'')?></textarea>
           <?php else:?><input name="cms_information[<?=$key?>]" type="text" maxlength="<?=$lim?>" value="<?=$esc($information[$key]??'')?>"><?php endif;?></label><?php endforeach;?></div></details>
          <?php endforeach;?>
          <div class="ankh-cms-actions"><button type="submit" class="ankh-cms-primary">Save draft</button><span>The draft is not visible until published.</span></div>
         </form>
         <div class="ankh-cms-publish">
           <div><strong>Publication</strong><p><?=$published?'CMS content is currently published. Saving a draft will not alter it until you publish again.':'No CMS override is published. The customer site uses its existing Order Admin content.'?></p></div>
           <div class="ankh-cms-publish-actions"><form method="post"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="product_id" value="<?=$selectedId?>"><button type="submit" name="action" value="cms_publish" <?=$draft&&$isVisible?'':'disabled'?>>Publish saved draft</button></form>
           <?php if($published):?><form method="post"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="product_id" value="<?=$selectedId?>"><button type="submit" name="action" value="cms_unpublish">Revert to Order Admin content</button></form><?php endif;?></div>
         </div>
       <?php endif;?></div></div>
    </section>
    <?php
}
