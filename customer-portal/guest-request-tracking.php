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
    $q=$db->prepare('SELECT g.id,g.placed_at,g.lines_json,g.subtotal,g.status
      FROM portal_guest_request_claims c JOIN storefront_guest_requests g ON g.id=c.request_id
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
