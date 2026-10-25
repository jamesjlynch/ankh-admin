<?php
declare(strict_types=1);
/** Staff-only, server-rendered candidate chooser. Never creates Admin orders. */
function sfGuestMatchPanel(PDO $portal,PDO $admin,array $request,string $csrf):void {
    $id=(int)$request['id'];
    $esc=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $candidates=[];$blocked=null;$manual=null;
    try{
        $candidates=ankhRequestMatchSuggestions($portal,$admin,$id,5);
        if((int)($_GET['request_focus']??0)===$id && ctype_digit((string)($_GET['order_lookup']??''))){
            $orderId=(int)$_GET['order_lookup'];
            if($orderId>0){
                $manual=ankhRequestMatchValidate($portal,$admin,$id,$orderId);
                $used=$portal->prepare('SELECT request_id FROM portal_guest_order_links WHERE order_id=?');
                $used->execute([$orderId]);
                $other=$used->fetchColumn();
                if($other!==false && (int)$other!==$id)throw new InvalidArgumentException('This Admin order is already linked to another request.');
                $already=false;foreach($candidates as $candidate)if((int)$candidate['order_id']===$orderId)$already=true;
                if(!$already)$candidates[]=$manual;
            }
        }
    }catch(InvalidArgumentException $e){$blocked=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH request suggestions: '.$e->getMessage());$blocked='Matching is temporarily unavailable. The request has not been linked.';}
    ?>
    <section class="pc-request-match" aria-label="Suggested Order Admin matches for REQ-<?=$id?>">
      <div class="pc-request-match-head"><div><span class="pc-eyebrow">ANKH / VERIFIED ORDER MATCHING</span><h4>Connect to an existing order</h4></div><span class="pc-request-match-pill">Staff confirmation required</span></div>
      <p class="pc-footnote">Matches must have a verified customer identity, the same approved accessory products and an unused Order Admin record. Suggestions do not create an order, reserve stock, take payment or change delivery status.</p>
      <?php if($blocked):?><p class="pc-request-match-warning" role="status"><?= $esc($blocked) ?></p><?php endif;?>
      <?php if(!$blocked && !$candidates):?><p class="pc-request-match-empty">No safe recent order matches were found. Check that you've created the actual accessory order in Order Admin, or verify an older order number below. Nothing will be linked automatically.</p><?php endif;?>
      <div class="pc-request-match-list">
      <?php foreach($candidates as $match):?>
        <?php $orderId=(int)$match['order_id'];$proof=ankhRequestMatchToken($match,$csrf,$id); ?>
        <form method="post" class="pc-request-match-card" autocomplete="off">
          <input type="hidden" name="csrf" value="<?=$esc($csrf)?>">
          <input type="hidden" name="action" value="sf_guest_link_order">
          <input type="hidden" name="request_id" value="<?=$id?>">
          <input type="hidden" name="order_id" value="<?=$orderId?>">
          <input type="hidden" name="match_proof" value="<?=$esc($proof)?>">
          <div class="pc-request-match-card-head"><strong>ANK-<?=str_pad((string)$orderId,4,'0',STR_PAD_LEFT)?></strong><span class="<?=$match['exact_quantities']?'pc-match-exact':'pc-match-different'?>"><?=$match['exact_quantities']?'Product quantities match':'Quantities differ — review'?></span></div>
          <p>Recorded <?= $esc((string)$match['created']) ?> · <?= $esc((string)$match['status']) ?></p>
          <small>Verified phone, customer ownership and approved accessory product identity match. <?php if(!$match['exact_quantities']):?>Order quantities differ from the original request; confirm the actual Admin record carefully.<?php endif;?></small>
          <div class="pc-request-match-confirm">
            <label class="pc-match-reviewer">Confirmed by <input name="reviewer" maxlength="120" minlength="2" required placeholder="Your name" autocomplete="off"></label>
            <label class="pc-match-checkbox"><input type="checkbox" name="confirm_link" value="yes" required><span>I verified this is the correct existing Order Admin record. Link it to REQ-<?=str_pad((string)$id,5,'0',STR_PAD_LEFT)?>.</span></label>
            <button type="submit">Confirm and link ANK-<?=str_pad((string)$orderId,4,'0',STR_PAD_LEFT)?> <span aria-hidden="true">↗</span></button>
          </div>
        </form>
      <?php endforeach;?>
      </div>
      <form class="pc-request-lookup" method="get" action="./preview-live.php">
        <input type="hidden" name="manage" value="portal"><input type="hidden" name="panel" value="commerce"><input type="hidden" name="settings" value="requests">
        <input type="hidden" name="request_focus" value="<?=$id?>">
        <label>Check an older or missing Admin order
          <input name="order_lookup" type="number" min="1" max="2147483647" required placeholder="Existing order ID"></label>
        <button type="submit">Verify order number</button>
      </form>
      <p class="pc-footnote">If there is no valid match, leave this request unlinked. Verify the customer and products in Order Admin before proceeding.</p>
    </section>
    <?php
}
