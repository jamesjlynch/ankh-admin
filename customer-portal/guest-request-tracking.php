<?php
declare(strict_types=1);
/**
 * ANKH customer request tracking:
 * - guest request ownership requires BOTH the private receipt code and verified
 *   phone on a separately enrolled customer portal account;
 * - staff review never becomes an Admin order without a real Admin action;
 * - never copies guest delivery address, name or email into the customer page.
 */
function ankhGuestTrackingInit(PDO $db):void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_guest_request_claims(
      request_id INTEGER PRIMARY KEY,
      token_sha256 TEXT NOT NULL UNIQUE,
      customer_id INTEGER DEFAULT NULL,
      issued_at INTEGER NOT NULL,
      claimed_at INTEGER DEFAULT NULL
    );
    CREATE INDEX IF NOT EXISTS portal_guest_request_claim_customer
      ON portal_guest_request_claims(customer_id,request_id);");
}
/**
 * Staff must explicitly connect an accessory request to a pre-existing Admin
 * order. Nothing here creates orders, changes stock or marks delivery.
 */
function ankhGuestOrderLinkInit(PDO $db):void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_guest_order_links (
      request_id INTEGER PRIMARY KEY,
      order_id INTEGER NOT NULL UNIQUE,
      linked_at INTEGER NOT NULL,
      linked_by TEXT NOT NULL DEFAULT 'ANKH staff'
    )");
}
function ankhGuestOrderLink(PDO $store,PDO $admin,int $requestId,int $orderId,string $reviewer='ANKH staff'):void {
    if($requestId<1||$orderId<1)throw new InvalidArgumentException('Choose a valid request and recorded Order Admin order.');
    ankhGuestTrackingInit($store);ankhGuestOrderLinkInit($store);
    $q=$store->prepare('SELECT g.id,g.phone,g.status,c.customer_id FROM storefront_guest_requests g
      LEFT JOIN portal_guest_request_claims c ON c.request_id=g.id WHERE g.id=?');
    $q->execute([$requestId]);$guest=$q->fetch(PDO::FETCH_ASSOC);
    if(!$guest||$guest['status']==='Pending staff review'||$guest['status']==='Declined')
        throw new InvalidArgumentException('Review and approve the accessory request before linking an order.');
    $q=$admin->prepare('SELECT id,customer,phone FROM orders WHERE id=?');
    $q->execute([$orderId]);$order=$q->fetch(PDO::FETCH_ASSOC);
    if(!$order||!function_exists('portalMobile')||
      !portalMobile((string)$guest['phone'])||
      portalMobile((string)$order['phone'])!==portalMobile((string)$guest['phone']))
        throw new InvalidArgumentException('Order and request phone numbers do not match. Check the customer record in Order Admin.');
    // Where a receipt is already claimed, require Admin's actual owner link,
    // not just a shared telephone number.
    if($guest['customer_id']!==null){
        $customerId=(int)$guest['customer_id'];
        $q=$admin->prepare('SELECT id,name,phone,address,archived FROM customers WHERE id=?');
        $q->execute([$customerId]);$customer=$q->fetch(PDO::FETCH_ASSOC);
        if(!$customer||(int)$customer['archived']!==0||
          portalMobile((string)$customer['phone'])!==portalMobile((string)$guest['phone']))
            throw new InvalidArgumentException('The verified customer record cannot be confirmed.');
        $claimedCustomerOrders=portalOrderHistory($admin,$customer,portalMobile((string)$guest['phone']));
        if(!in_array($orderId,array_map(static fn(array $o):int=>(int)$o['id'],$claimedCustomerOrders),true))
            throw new InvalidArgumentException('That order is not securely linked to the verified customer in Order Admin.');
    }
    $existing=$store->prepare('SELECT request_id,order_id FROM portal_guest_order_links WHERE request_id=? OR order_id=?');
    $existing->execute([$requestId,$orderId]);
    foreach($existing as $row){
        if((int)$row['request_id']===$requestId && (int)$row['order_id']===$orderId)return;
        throw new InvalidArgumentException('The request or order is already linked to another record.');
    }
    $reviewer=trim($reviewer);
    if(strlen($reviewer)<2||strlen($reviewer)>120)throw new InvalidArgumentException('Enter the staff member confirming the link.');
    $store->prepare('INSERT INTO portal_guest_order_links(request_id,order_id,linked_at,linked_by) VALUES(?,?,?,?)')
          ->execute([$requestId,$orderId,time(),$reviewer]);
}

function ankhGuestTrackingIssue(PDO $db,int $requestId):?string {
    if($requestId<1)throw new InvalidArgumentException('Invalid request reference.');
    ankhGuestTrackingInit($db);
    $exists=$db->prepare('SELECT request_id FROM portal_guest_request_claims WHERE request_id=?');
    $exists->execute([$requestId]);
    if($exists->fetchColumn())return null;
    $request=$db->prepare('SELECT id FROM storefront_guest_requests WHERE id=?');
    $request->execute([$requestId]);
    if(!$request->fetchColumn())throw new InvalidArgumentException('Request not found.');
    $code=strtoupper(bin2hex(random_bytes(16)));
    $db->prepare('INSERT OR IGNORE INTO portal_guest_request_claims(request_id,token_sha256,issued_at) VALUES(?,?,?)')
      ->execute([$requestId,hash('sha256',$code),time()]);
    return $code;
}
function ankhGuestTrackingClaim(PDO $db,int $customerId,string $verifiedPhone,string $code):int {
    $code=strtoupper(trim($code));
    if($customerId<1 || !preg_match('/^[A-F0-9]{32}$/D',$code))throw new InvalidArgumentException('Check your receipt code and try again.');
    ankhGuestTrackingInit($db);
    $q=$db->prepare('SELECT g.id,g.phone,c.customer_id FROM portal_guest_request_claims c
      JOIN storefront_guest_requests g ON g.id=c.request_id
      WHERE c.token_sha256=? LIMIT 1');
    $q->execute([hash('sha256',$code)]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row || !function_exists('portalMobile') ||
       portalMobile((string)$row['phone'])!==$verifiedPhone ||
       ($row['customer_id']!==null && (int)$row['customer_id']!==$customerId))
       throw new InvalidArgumentException('The receipt code could not be linked to this verified account.');
    if($row['customer_id']===null){
        $update=$db->prepare('UPDATE portal_guest_request_claims SET customer_id=?,claimed_at=?
          WHERE request_id=? AND customer_id IS NULL');
        $update->execute([$customerId,time(),(int)$row['id']]);
        if($update->rowCount()!==1)throw new InvalidArgumentException('This request has already been linked to another account.');
    }
    return (int)$row['id'];
}
function ankhGuestTrackingRows(PDO $db,int $customerId):array {
    if($customerId<1)return [];
    $present=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_guest_request_claims'")->fetchColumn();
    if(!$present)return [];
    $hasLink=(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='portal_guest_order_links'")->fetchColumn();
    $linkSelect=$hasLink?'l.order_id AS linked_order_id':'NULL AS linked_order_id';
    $linkJoin=$hasLink?' LEFT JOIN portal_guest_order_links l ON l.request_id=g.id':'';
    $q=$db->prepare('SELECT g.id,g.placed_at,g.lines_json,g.subtotal,g.status,'.$linkSelect.'
      FROM portal_guest_request_claims c JOIN storefront_guest_requests g ON g.id=c.request_id'.$linkJoin.'
      WHERE c.customer_id=? ORDER BY g.placed_at DESC,g.id DESC LIMIT 100');
    $q->execute([$customerId]);
    $rows=[];
    foreach($q as $row){
        try{$decoded=json_decode((string)$row['lines_json'],true,24,JSON_THROW_ON_ERROR);}
        catch(Throwable $e){$decoded=[];}
        $row['lines']=is_array($decoded)?array_values(array_filter($decoded,'is_array')):[];
        unset($row['lines_json']);
        $rows[]=$row;
    }
    return $rows;
}
function ankhGuestTrackingController(PDO $db,array $customer,array $account,bool $preview):void {
    if($preview || ($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || ($_POST['action']??'')!=='claim_guest_request')return;
    if(!is_string($_POST['csrf']??null) || !hash_equals((string)($_SESSION['portal_csrf']??''),(string)$_POST['csrf'])){
        http_response_code(400);exit('Invalid request.');
    }
    try{
        $customerId=(int)($customer['id']??0);
        $verifiedPhone=(string)($account['phone_e164']??'');
        if(!function_exists('portalMobile') || portalMobile((string)($customer['phone']??''))!==$verifiedPhone)
            throw new InvalidArgumentException('Your account requires mobile verification.');
        if(!portalAllowAttempt($db,'receipt_claim:'.$customerId,6,3600))
            throw new InvalidArgumentException('Too many attempts. Please try again later.');
        $id=ankhGuestTrackingClaim($db,$customerId,$verifiedPhone,(string)($_POST['receipt_code']??''));
        $_SESSION['guest_claim_flash']='Receipt REQ-'.str_pad((string)$id,5,'0',STR_PAD_LEFT).' linked to your verified ANKH account.';
        header('Location: ./?tab=requests',true,303);exit;
    }catch(InvalidArgumentException $e){$GLOBALS['guestClaimError']=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH guest claim: '.$e->getMessage());$GLOBALS['guestClaimError']='Unable to link this receipt at present.';}
}
