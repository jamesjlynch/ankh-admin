<?php
declare(strict_types=1);
/**
 * ANKH Tide bank-transfer instructions for STAFF-CONFIRMED existing orders.
 * No Tide API, payment capture, customer claims of payment, or Admin writes.
 * The customer portal database is private and lives outside document root.
 */
function portalTideInit(PDO $db):void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_tide_bank_details(
        id INTEGER PRIMARY KEY CHECK(id=1),
        holder TEXT NOT NULL, sort_code TEXT NOT NULL, account_number TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 0 CHECK(enabled IN (0,1)),
        updated_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS portal_tide_order_permissions(
        order_id INTEGER PRIMARY KEY,
        enabled INTEGER NOT NULL DEFAULT 0 CHECK(enabled IN (0,1)),
        reviewed_at INTEGER NOT NULL,
        reviewed_note TEXT NOT NULL DEFAULT ''
    );");
}
function portalTideDetails(PDO $db):?array {
    $has=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_tide_bank_details'")->fetchColumn();
    if(!$has)return null;
    $r=$db->query('SELECT holder,sort_code,account_number,enabled FROM portal_tide_bank_details WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    return is_array($r)?$r:null;
}
function portalTideSaveDetails(PDO $db,mixed $holder,mixed $sort,mixed $account,mixed $enabled):void {
    if(!is_string($holder)||!is_string($sort)||!is_string($account))throw new InvalidArgumentException('Enter the account holder, sort code and account number.');
    $holder=trim(preg_replace('/\\s+/u',' ',$holder)??$holder);
    if(!preg_match("/^[a-zA-Z0-9 .&'()\\-]{3,100}$/D",$holder))throw new InvalidArgumentException('Enter the exact business bank account holder name, up to 100 characters.');
    $sort=preg_replace('/[ -]/','',$sort)??'';
    if(!preg_match('/^[0-9]{6}$/D',$sort))throw new InvalidArgumentException('Sort code must contain exactly six digits.');
    $account=trim($account);
    if(!preg_match('/^[0-9]{8}$/D',$account))throw new InvalidArgumentException('Account number must contain exactly eight digits.');
    if(!in_array($enabled,['0','1'],true))throw new InvalidArgumentException('Invalid payment-instruction setting.');
    portalTideInit($db);
    $db->prepare('INSERT INTO portal_tide_bank_details(id,holder,sort_code,account_number,enabled,updated_at) VALUES(1,?,?,?,?,?)
        ON CONFLICT(id) DO UPDATE SET holder=excluded.holder,sort_code=excluded.sort_code,
        account_number=excluded.account_number,enabled=excluded.enabled,updated_at=excluded.updated_at')
        ->execute([$holder,$sort,$account,(int)$enabled,time()]);
}
function portalTideOrderBalance(PDO $admin,int $id):?array {
    if($id<1)return null;
    $q=$admin->prepare('SELECT id,customer,status,delivery_charge FROM orders WHERE id=?');
    $q->execute([$id]);$order=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($order))return null;
    $items=$admin->prepare('SELECT COALESCE(SUM(price*quantity),0) FROM items WHERE order_id=?');
    $items->execute([$id]);
    $payments=$admin->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=?');
    $payments->execute([$id]);
    $total=max(0,(int)$order['delivery_charge']+(int)$items->fetchColumn());
    $paid=max(0,(int)$payments->fetchColumn());
    return ['id'=>$id,'customer'=>(string)$order['customer'],'status'=>(string)$order['status'],
        'total'=>$total,'paid'=>$paid,'due'=>max(0,$total-$paid)];
}
function portalTideOrderIsEnabled(PDO $db,int $id):bool {
    if($id<1)return false;
    $has=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_tide_order_permissions'")->fetchColumn();
    if(!$has)return false;
    $q=$db->prepare('SELECT enabled FROM portal_tide_order_permissions WHERE order_id=?');$q->execute([$id]);
    return (int)$q->fetchColumn()===1;
}
function portalTideSaveOrder(PDO $db,PDO $admin,int $id,mixed $enabled,mixed $reviewed):void {
    if($id<1||!in_array($enabled,['0','1'],true))throw new InvalidArgumentException('Choose a valid existing order.');
    $order=portalTideOrderBalance($admin,$id);
    if(!$order)throw new InvalidArgumentException('This order could not be found.');
    if($enabled==='1') {
        $config=portalTideDetails($db);
        if(!$config || (int)$config['enabled']!==1)throw new InvalidArgumentException('Save and enable the ANKH Tide business receiving details first.');
        if($order['status']==='Cancelled'||$order['due']<1)throw new InvalidArgumentException('A cancelled or fully paid order cannot receive transfer instructions.');
        if($reviewed!=='1')throw new InvalidArgumentException('Confirm you have reviewed the individual order and product eligibility.');
    }
    portalTideInit($db);
    $db->prepare('INSERT INTO portal_tide_order_permissions(order_id,enabled,reviewed_at,reviewed_note)
        VALUES(?,?,?,?) ON CONFLICT(order_id) DO UPDATE SET
        enabled=excluded.enabled,reviewed_at=excluded.reviewed_at,reviewed_note=excluded.reviewed_note')
        ->execute([$id,(int)$enabled,time(),$enabled==='1'?'Staff confirmed payment request for this order':'Staff disabled payment request']);
}
function portalTideInstructions(PDO $db,array $selected,array $ownedOrders):?array {
    $id=(int)($selected['id']??0);
    if($id<1)return null;
    $owned=false;
    foreach($ownedOrders as $o)if((int)($o['id']??0)===$id){$owned=true;break;}
    if(!$owned || (string)($selected['status']??'')==='Cancelled')return null;
    $due=max(0,(int)($selected['total']??0)-(int)($selected['paid']??0));
    if($due<1||!portalTideOrderIsEnabled($db,$id))return null;
    $detail=portalTideDetails($db);
    if(!$detail || (int)$detail['enabled']!==1)return null;
    return ['holder'=>$detail['holder'],'sort_code'=>substr($detail['sort_code'],0,2).'-'.substr($detail['sort_code'],2,2).'-'.substr($detail['sort_code'],4,2),
        'account_number'=>$detail['account_number'],'amount'=>$due,
        'reference'=>'ANK-'.str_pad((string)$id,4,'0',STR_PAD_LEFT)];
}
function portalTideStaffController(PDO $store,PDO $admin):void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!in_array($_POST['action']??null,['tide_bank_save','tide_order_save'],true))return;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)($_SESSION['portal_csrf']??''),$_POST['csrf'])){http_response_code(400);exit('Invalid request.');}
    try {
        if($_POST['action']==='tide_bank_save') {
            portalTideSaveDetails($store,$_POST['bank_holder']??null,$_POST['sort_code']??null,$_POST['account_number']??null,$_POST['bank_enabled']??'0');
            $_SESSION['portal_staff_flash']='Tide business receiving details saved.';
        } else {
            $id=filter_var($_POST['order_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
            portalTideSaveOrder($store,$admin,$id,$_POST['enable_order']??null,$_POST['eligibility_reviewed']??null);
            $_SESSION['portal_staff_flash']='Tide instruction setting updated for order ANK-'.str_pad((string)$id,4,'0',STR_PAD_LEFT).'.';
        }
    } catch(InvalidArgumentException $e) {$_SESSION['portal_staff_flash']=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH Tide staff setup: '.$e->getMessage());$_SESSION['portal_staff_flash']='Tide settings could not be saved.';}
    header('Location: ./preview-live.php?manage=portal',true,303);exit;
}
function portalTideStaffSection(PDO $store,PDO $admin,string $csrf):void {
    $bank=portalTideDetails($store);
    $orders=$admin->query('SELECT id FROM orders ORDER BY id DESC LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
    $esc=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    ?>
    <section class="pc-panel pc-tide-settings" id="tide-settings">
    <div class="pc-section-header"><div><span class="pc-eyebrow">BANK TRANSFERS · STAFF ONLY</span><h2>Tide business receiving details</h2></div></div>
    <p class="pc-footnote">Enter details from your actual Tide business account. Nothing is shown publicly. Customers only see instructions inside their own confirmed order when that exact order is individually authorised below. Tide does not automatically notify ANKH of payment.</p>
    <form method="post" class="pc-tide-settings-form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="action" value="tide_bank_save">
      <label>Account holder <input type="text" name="bank_holder" maxlength="100" required autocomplete="off" value="<?=$esc($bank['holder']??'')?>" placeholder="Exact name shown in Tide"></label>
      <label>Sort code <input type="text" name="sort_code" inputmode="numeric" maxlength="8" required autocomplete="off" value="<?=$esc($bank['sort_code']??'')?>" placeholder="00-00-00"></label>
      <label>Account number <input type="text" name="account_number" inputmode="numeric" minlength="8" maxlength="8" required autocomplete="off" value="<?=$esc($bank['account_number']??'')?>" placeholder="8 digits"></label>
      <label class="pc-tide-toggle"><input type="checkbox" name="bank_enabled" value="1" <?=!empty($bank['enabled'])?'checked':''?>> Enable bank-transfer instructions for individually authorised orders</label>
      <button type="submit">Save Tide receiving details</button>
    </form>
    <h3>Authorise payment requests per order</h3>
    <p class="pc-footnote">Order-level authorisation is off by default, including historic orders. Only enable after confirming the products, transaction and payment method are permitted. The amount is always calculated from live Admin order and payment records.</p>
    <div class="pc-tide-orders">
    <?php foreach($orders as $record):$order=portalTideOrderBalance($admin,(int)$record['id']);if(!$order)continue;
      $enabled=portalTideOrderIsEnabled($store,(int)$order['id']); ?>
      <div class="pc-tide-order"><div><strong>ANK-<?=str_pad((string)$order['id'],4,'0',STR_PAD_LEFT)?></strong> · <?=$esc($order['customer'])?><small><?=$esc($order['status'])?> · <?=function_exists('portalMoney')?portalMoney($order['due']):'£'.number_format($order['due']/100,2)?> outstanding<?=$enabled?' · Bank details authorised':''?></small></div>
      <form method="post"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><input type="hidden" name="action" value="tide_order_save"><input type="hidden" name="order_id" value="<?=(int)$order['id']?>">
       <?php if($enabled):?><input type="hidden" name="enable_order" value="0"><button type="submit">Disable</button>
       <?php else:?><input type="hidden" name="enable_order" value="1"><label><input type="checkbox" name="eligibility_reviewed" value="1" required> Order reviewed</label><button type="submit" <?=$order['status']==='Cancelled'||$order['due']<1?'disabled':''?>>Enable</button><?php endif;?>
      </form></div>
    <?php endforeach;?>
    </div></section>
    <?php
}
