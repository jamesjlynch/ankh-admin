<?php
declare(strict_types=1);
/** Staff-only ANKH storefront controls. Called solely after staff preview authentication. */
function sfStaffController(PDO $portal,PDO $admin):void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'
       ||!in_array($_POST['action']??'',['sf_facts_save','sf_eligibility_save','sf_guest_review','sf_demo_save_all'],true))return;
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
    header('Location: ./preview-live.php?manage=portal',true,303);exit;
}
function sfStaffSection(PDO $portal,PDO $admin,string $csrf):void {
    sfCommerceInit($portal);
    $esc=static fn(mixed $v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $facts=sfCommerceFacts($portal)??['company'=>'','fulfilment'=>'','dispatch'=>'','support'=>''];
    $eligibility=[];
    foreach($portal->query("SELECT product_id,approved,reviewer,review_note FROM storefront_checkout_eligibility") as $row)$eligibility[(int)$row['product_id']]=$row;
    $candidates=$admin->query('SELECT id,name,active FROM products WHERE active=1 ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    $candidates=array_values(array_filter($candidates,static fn($p)=>function_exists('ankhPublicType')&&ankhPublicType((string)$p['name'])==='Research accessory'));
    $demoCandidates=[];
    try{
        $demoCandidates=$admin->query('SELECT p.id,p.name FROM products p JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1 WHERE p.active=1 ORDER BY p.name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    }catch(Throwable $e){error_log('ANKH demo list: '.$e->getMessage());}
    $demoAllowed=sfDemoVisible($portal,$demoCandidates);
    $requests=$portal->query('SELECT id,placed_at,name,email,phone,address,note,lines_json,subtotal,status FROM storefront_guest_requests ORDER BY CASE status WHEN "Pending staff review" THEN 0 ELSE 1 END,id DESC LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <section class="pc-panel pc-sf-staff" id="storefront-controls">
      <div class="pc-section-header"><div><span class="pc-eyebrow">PUBLIC STOREFRONT · STAFF ONLY</span><h2>Shopping &amp; business information</h2></div></div>
      <p class="pc-footnote">Technical profiles remain public as configured in Admin. Products are <strong>not purchasable</strong> unless individually approved below. Only non-medicinal accessories are supported for guest checkout requests. Requests do not change live Admin orders, stock or payments.</p>
      <h3>Verified business and delivery information</h3>
      <p class="pc-footnote">Only enter facts you can support. Blank fields are simply omitted publicly. Do not advertise unsupported delivery promises or laboratory claims.</p>
      <form method="post" class="pc-ops-contact-form"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="action" value="sf_facts_save">
        <label>Registered business details <textarea name="company" maxlength="160" rows="2" placeholder="Company name and verifiable registration details"><?=$esc($facts['company'])?></textarea></label>
        <label>Fulfilment information <textarea name="fulfilment" maxlength="700" rows="3" placeholder="Only verified packing and fulfilment information"><?=$esc($facts['fulfilment'])?></textarea></label>
        <label>Delivery arrangements <textarea name="dispatch" maxlength="700" rows="3" placeholder="Actual delivery area, times and charges if known"><?=$esc($facts['dispatch'])?></textarea></label>
        <label>Support availability <textarea name="support" maxlength="300" rows="2" placeholder="Actual support hours and response methods"><?=$esc($facts['support'])?></textarea></label>
        <button type="submit">Publish business information</button>
      </form>
      <section class="pc-demo-manager"><h3>Demo basket products</h3>
      <p class="pc-footnote"><strong>All published products are ticked by default.</strong> Untick any you want to exclude from the public basket simulation. This changes only the demonstration buttons; it never enables real orders, bank transfers or payments for research compounds.</p>
      <form method="post" class="pc-demo-manager-form"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="action" value="sf_demo_save_all">
        <div class="pc-demo-manager-list">
        <?php foreach($demoCandidates as $p):$id=(int)$p['id'];?>
         <label><input type="checkbox" name="demo_product[]" value="<?=$id?>" <?=isset($demoAllowed[$id])?'checked':''?>>
         <span><?=$esc($p['name'])?></span></label>
        <?php endforeach;?></div>
        <button type="submit">Save demo basket products</button>
      </form></section>
      <h3>Eligible accessories for guest checkout requests</h3>
      <p class="pc-footnote">Eligibility must be assessed separately from visibility, including the actual product, intended use and legal position. A research-use label is not regulatory authorisation. Accessory requests remain subject to ANKH confirmation.</p>
      <?php if(!$candidates):?><p>No eligible accessory candidates are present.</p><?php endif;?>
      <div class="pc-sf-eligibility">
      <?php foreach($candidates as $p):$row=$eligibility[(int)$p['id']]??null; ?>
      <details><summary><strong><?=$esc($p['name'])?></strong> · <?=!empty($row['approved'])?'Guest requests enabled':'Checkout disabled'?></summary>
      <form method="post"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="action" value="sf_eligibility_save"><input type="hidden" name="product_id" value="<?=(int)$p['id']?>">
      <label>Reviewed by <input name="reviewer" maxlength="120" placeholder="Name" value="<?=$esc($row['reviewer']??'')?>"></label>
      <label>Documented rationale <textarea name="basis" rows="3" maxlength="2000" placeholder="Record the specific basis for allowing this product to be ordered"><?=$esc($row['review_note']??'')?></textarea></label>
      <button name="decision" value="enable">Approve accessory checkout</button>
      <button name="decision" value="disable">Disable checkout</button></form></details><?php endforeach;?>
      </div>
      <h3>Guest checkout requests</h3>
      <p class="pc-footnote">These are requests for staff review, not paid orders. Verify product eligibility, stock, delivery and contact details before manually recording a confirmed order in ANKH Admin. Do not mark a request as paid without checking Tide.</p>
      <?php if(!$requests):?><p>No guest checkout requests have been received.</p><?php endif;?>
      <div class="pc-sf-requests"><?php foreach($requests as $r):?>
        <details><summary><strong>REQ-<?=str_pad((string)$r['id'],5,'0',STR_PAD_LEFT)?></strong> · <?=$esc($r['name'])?> · <?=$esc($r['status'])?></summary>
          <p><strong>Contact:</strong> <?=$esc($r['email'])?> · <?=$esc($r['phone'])?></p>
          <p><strong>Address:</strong> <?=$esc($r['address'])?></p>
          <p><strong>Note:</strong> <?=$esc($r['note'])?></p>
          <?php foreach((json_decode((string)$r['lines_json'],true)?:[]) as $line):?><p><?=$esc($line['quantity'])?> × <?=$esc($line['name'])?> · £<?=number_format((int)$line['unit_price']/100,2)?></p><?php endforeach;?>
          <p><strong>Product subtotal (excludes delivery):</strong> £<?=number_format((int)$r['subtotal']/100,2)?></p>
          <?php if($r['status']==='Pending staff review'):?><form method="post"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="action" value="sf_guest_review"><input type="hidden" name="request_id" value="<?=(int)$r['id']?>">
            <button name="decision" value="Reviewed — create in Admin manually">Mark reviewed</button><button name="decision" value="Declined">Decline</button></form><?php endif;?>
        </details><?php endforeach;?></div>
    </section>
    <?php
}
