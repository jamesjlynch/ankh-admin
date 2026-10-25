<?php
declare(strict_types=1);
/** Staff-only ANKH storefront controls. Called solely after staff preview authentication. */
function sfStaffController(PDO $portal,PDO $admin):void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'
       ||!in_array($_POST['action']??'',['sf_facts_save','sf_eligibility_save','sf_guest_review','sf_guest_link_order','sf_demo_save_all'],true))return;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)($_SESSION['portal_csrf']??''),$_POST['csrf'])){http_response_code(400);exit('Invalid request.');}
    try{
        switch($_POST['action']){
            case 'sf_facts_save':
                sfCommerceSaveFacts($portal,$_POST);
                $_SESSION['portal_staff_flash']='Storefront business information saved.';
                break;
            case 'sf_eligibility_save':
                $id=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
                sfCommerceSaveEligibility($portal,$admin,$id,(string)($_POST['decision']??''),(string)($_POST['reviewer']??''),(string)($_POST['basis']??''));
                $_SESSION['portal_staff_flash']='Checkout eligibility reviewed.';
                break;
            case 'sf_guest_link_order':
                $requestId=filter_var($_POST['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
                $orderId=filter_var($_POST['order_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
                ankhGuestOrderLink($portal,$admin,$requestId,$orderId,(string)($_POST['reviewer']??''));
                $_SESSION['portal_staff_flash']='Reviewed request linked to an existing Order Admin order. No stock, order or delivery details were modified.';
                break;
            case 'sf_demo_save_all':
                $selected=$_POST['demo_product']??[];
                if(!is_array($selected))throw new InvalidArgumentException('Invalid product selection.');
                $count=sfDemoSaveAll($portal,$admin,$selected);
                $_SESSION['portal_staff_flash']=$count.' products enabled for the non-transactional demo basket.';
                break;
            case 'sf_guest_review':
                $id=filter_var($_POST['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
                $status=(string)($_POST['decision']??'');
                if($id<1||!in_array($status,['Reviewed — create in Admin manually','Declined'],true))throw new InvalidArgumentException('Choose a valid request decision.');
                sfCommerceInit($portal);
                $q=$portal->prepare("UPDATE storefront_guest_requests SET status=? WHERE id=? AND status='Pending staff review'");
                $q->execute([$status,$id]);
                if($q->rowCount()!==1)throw new InvalidArgumentException('This request has already been reviewed or does not exist.');
                $_SESSION['portal_staff_flash']='Guest request status updated. Admin orders were not modified.';
                break;
        }
    }catch(InvalidArgumentException $e){$_SESSION['portal_staff_flash']=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH shop staff controls: '.$e->getMessage());$_SESSION['portal_staff_flash']='Could not save storefront settings.';}
    $section=match($_POST['action']??''){'sf_demo_save_all'=>'basket','sf_eligibility_save'=>'checkout','sf_guest_review'=>'requests','sf_guest_link_order'=>'requests',default=>'business'};
    header('Location: ./preview-live.php?manage=portal&panel=commerce&settings='.$section,true,303);exit;
}
function sfStaffSection(PDO $portal,PDO $admin,string $csrf):void {
    sfCommerceInit($portal);
    $esc=static fn(mixed $v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $facts=sfCommerceFacts($portal)??['company'=>'','fulfilment'=>'','dispatch'=>'','support'=>''];
    $eligibility=[];
    foreach($portal->query("SELECT product_id,approved,reviewer,review_note FROM storefront_checkout_eligibility") as $row)$eligibility[(int)$row['product_id']]=$row;
    $candidates=$admin->query('SELECT p.id,p.name,p.active FROM products p JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1 WHERE p.active=1 ORDER BY p.name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    $candidates=array_values(array_filter($candidates,static fn($p)=>function_exists('ankhPublicType')&&ankhPublicType((string)$p['name'])==='Research accessory'));
    $demoCandidates=[];
    try{
        $demoCandidates=$admin->query('SELECT p.id,p.name FROM products p JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1 WHERE p.active=1 ORDER BY p.name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    }catch(Throwable $e){error_log('ANKH demo list: '.$e->getMessage());}
    $demoAllowed=sfDemoVisible($portal,$demoCandidates);
    ankhGuestOrderLinkInit($portal);
    $requests=$portal->query("SELECT g.id,g.placed_at,g.name,g.email,g.phone,g.address,g.note,g.lines_json,g.subtotal,g.status,l.order_id AS linked_order_id
       FROM storefront_guest_requests g LEFT JOIN portal_guest_order_links l ON l.request_id=g.id
       ORDER BY CASE g.status WHEN 'Pending staff review' THEN 0 ELSE 1 END,g.id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
    $settings=is_string($_GET['settings']??null)?$_GET['settings']:'business';
    if(!in_array($settings,['business','basket','checkout','requests'],true))$settings='business';
    $pending=count(array_filter($requests,static fn($r)=>$r['status']==='Pending staff review'));
    ?>
    <section class="pc-panel pc-sf-staff pc-store-settings" id="storefront-controls">
      <div class="pc-section-header"><div><span class="pc-eyebrow">WEBSITE MANAGEMENT</span><h2>Storefront settings</h2><p class="pc-footnote">Update your business details and manage the customer shopping experience.</p></div></div>
      <nav class="pc-settings-tabs" aria-label="Storefront settings sections">
      <?php foreach(['business'=>'Business details','basket'=>'Basket products','checkout'=>'Accessory checkout','requests'=>'Guest requests'] as $key=>$label):?><a href="./preview-live.php?manage=portal&amp;panel=commerce&amp;settings=<?=$key?>" <?=$settings===$key?'aria-current="page"':''?>><?=$esc($label)?><?php if($key==='requests'&&$pending):?><span><?=$pending?></span><?php endif;?></a><?php endforeach;?></nav>
      <?php if($settings==='business'):?><div class="pc-settings-section">
      <h3>Business &amp; delivery details</h3>
      <p class="pc-footnote">This information appears on your public website. Leave a field blank to hide it; only publish details you can verify.</p>
      <form method="post" class="pc-settings-form"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="action" value="sf_facts_save">
        <label>Registered business details <textarea name="company" maxlength="160" rows="2" placeholder="Company name and verifiable registration details"><?=$esc($facts['company'])?></textarea></label>
        <label>Fulfilment information <textarea name="fulfilment" maxlength="700" rows="3" placeholder="Only verified packing and fulfilment information"><?=$esc($facts['fulfilment'])?></textarea></label>
        <label>Delivery arrangements <textarea name="dispatch" maxlength="700" rows="3" placeholder="Actual delivery area, times and charges if known"><?=$esc($facts['dispatch'])?></textarea></label>
        <label>Support availability <textarea name="support" maxlength="300" rows="2" placeholder="Actual support hours and response methods"><?=$esc($facts['support'])?></textarea></label>
        <div class="pc-settings-save"><span>Updates appear on the website when saved.</span><button type="submit">Save business details <span aria-hidden="true">↗</span></button></div>
      </form>
      </div><?php elseif($settings==='basket'):?><section class="pc-demo-manager pc-settings-section"><div class="pc-settings-title"><div><h3>Basket product selection</h3><p class="pc-footnote">Choose which published products show a demo basket button.</p></div><span class="pc-settings-pill" data-settings-selected><?=count($demoAllowed)?> selected</span></div>
      <p class="pc-settings-note">These switches control the demo basket only. Website visibility stays in Order Admin; enabling a demo button does not enable purchases or payments.</p>
      <form method="post" class="pc-demo-manager-form"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="action" value="sf_demo_save_all">
        <div class="pc-settings-filter"><label for="pc-basket-search">Find a product<input id="pc-basket-search" type="search" placeholder="Search product names…" autocomplete="off"></label><div><button type="button" class="pc-settings-secondary" data-settings-select="all">Select all</button><button type="button" class="pc-settings-secondary" data-settings-select="none">Clear all</button></div></div>
        <div class="pc-demo-manager-list">
        <?php foreach($demoCandidates as $p):$id=(int)$p['id'];?>
         <label data-settings-product="<?=$esc(strtolower($p['name']))?>"><input type="checkbox" name="demo_product[]" value="<?=$id?>" <?=isset($demoAllowed[$id])?'checked':''?>>
         <span><?=$esc($p['name'])?></span></label>
        <?php endforeach;?></div>
        <?php if(!$demoCandidates):?><p class="pc-settings-empty">No products are published yet. Choose visible products in Order Admin first.</p><?php endif;?>
        <p class="pc-settings-empty" id="pc-basket-no-results" hidden>No products match your search.</p>
        <div class="pc-settings-save"><span>Selection is saved only when you click save.</span><button type="submit">Save basket products</button></div>
      </form></section>
      <?php elseif($settings==='checkout'):?><div class="pc-settings-section"><h3>Accessory checkout</h3>
      <p class="pc-footnote">Eligibility must be assessed separately from visibility, including the actual product, intended use and legal position. A research-use label is not regulatory authorisation. Accessory requests remain subject to ANKH confirmation.</p>
      <?php if(!$candidates):?><p class="pc-settings-empty">No eligible accessories to review.</p><?php endif;?>
      <div class="pc-sf-eligibility">
      <?php foreach($candidates as $p):$row=$eligibility[(int)$p['id']]??null; ?>
      <details><summary><strong><?=$esc($p['name'])?></strong> · <?=!empty($row['approved'])?'Guest requests enabled':'Checkout disabled'?></summary>
      <form method="post"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="action" value="sf_eligibility_save"><input type="hidden" name="product_id" value="<?=(int)$p['id']?>">
      <label>Reviewed by <input name="reviewer" maxlength="120" placeholder="Name" value="<?=$esc($row['reviewer']??'')?>"></label>
      <label>Documented rationale <textarea name="basis" rows="3" maxlength="2000" placeholder="Record the specific basis for allowing this product to be ordered"><?=$esc($row['review_note']??'')?></textarea></label>
      <button name="decision" value="enable">Approve accessory checkout</button>
      <button name="decision" value="disable">Disable checkout</button></form></details><?php endforeach;?>
      </div>
      </div><?php else:?><div class="pc-settings-section"><h3>Guest requests</h3>
      <p class="pc-footnote">These are requests for staff review, not paid orders. Verify product eligibility, stock, delivery and contact details before manually recording a confirmed order in ANKH Admin. Do not mark a request as paid without checking Tide.</p>
      <?php if(!$requests):?><div class="pc-settings-empty"><strong>No guest requests yet</strong><p>New guest checkout requests will appear here for you to review.</p></div><?php endif;?>
      <div class="pc-sf-requests"><?php foreach($requests as $r):?>
        <details><summary><strong>REQ-<?=str_pad((string)$r['id'],5,'0',STR_PAD_LEFT)?></strong> · <?=$esc($r['name'])?> · <?=$esc($r['status'])?></summary>
          <p><strong>Contact:</strong> <?=$esc($r['email'])?> · <?=$esc($r['phone'])?></p>
          <p><strong>Address:</strong> <?=$esc($r['address'])?></p>
          <p><strong>Note:</strong> <?=$esc($r['note'])?></p>
          <?php foreach((json_decode((string)$r['lines_json'],true)?:[]) as $line):?><p><?=$esc($line['quantity'])?> × <?=$esc($line['name'])?> · £<?=number_format((int)$line['unit_price']/100,2)?></p><?php endforeach;?>
          <p><strong>Product subtotal (excludes delivery):</strong> £<?=number_format((int)$r['subtotal']/100,2)?></p>
          <?php if($r['status']==='Pending staff review'):?><form method="post"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="action" value="sf_guest_review"><input type="hidden" name="request_id" value="<?=(int)$r['id']?>">
            <button name="decision" value="Reviewed — create in Admin manually">Mark reviewed</button><button name="decision" value="Declined">Decline</button></form><?php endif;?>
          <?php if($r['status']!=='Pending staff review' && $r['status']!=='Declined'):?>
            <?php if(!empty($r['linked_order_id'])):?><p class="pc-sf-linked-order"><strong>Linked to Order Admin #<?=(int)$r['linked_order_id']?></strong>. The customer sees progress after secure receipt claiming and account verification.</p>
            <?php else:?><form method="post" class="pc-sf-link-order"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="action" value="sf_guest_link_order"><input type="hidden" name="request_id" value="<?=(int)$r['id']?>">
             <p class="pc-footnote">Only link an order after it exists in Order Admin. The recorded order and request phone numbers must match. Claimed receipts also require verified customer ownership.</p>
             <label>Existing Order Admin number<input type="number" name="order_id" min="1" step="1" required placeholder="e.g. 105"></label>
             <label>Confirmed by<input name="reviewer" maxlength="120" required placeholder="Staff name"></label>
             <button type="submit">Link existing Admin order</button></form><?php endif;?>
          <?php endif;?>
        </details><?php endforeach;?></div>
      </div><?php endif;?>
    </section>
    <?php
}
