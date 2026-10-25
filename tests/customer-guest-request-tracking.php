<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/customer-portal/lib.php';
function checkTracked(bool $ok,string $reason):void {if(!$ok)throw new RuntimeException($reason);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE storefront_guest_requests(
 id INTEGER PRIMARY KEY, placed_at INTEGER, name TEXT, email TEXT, phone TEXT, address TEXT,
 note TEXT, lines_json TEXT, subtotal INTEGER, status TEXT)");
$db->prepare('INSERT INTO storefront_guest_requests VALUES(1,?,?,?,?,?,?,?,?,?)')->execute([
 time(),'Private Person','private@example.test','07700900123','A private house','',json_encode([
 ['id'=>5,'name'=>'Accessory pouch','quantity'=>2,'unit_price'=>1200]
 ]),2400,'Pending staff review'
]);
$db->prepare('INSERT INTO storefront_guest_requests VALUES(2,?,?,?,?,?,?,?,?,?)')->execute([
 time(),'Other Person','other@example.test','07700900456','Other private house','',json_encode([
 ['id'=>6,'name'=>'Different product','quantity'=>1,'unit_price'=>1500]
 ]),1500,'Pending staff review'
]);
$verified=portalMobile('07700900123');checkTracked($verified!==null,'Fixture mobile not valid');
$secret=ankhGuestTrackingIssue($db,1);
checkTracked(is_string($secret)&&preg_match('/^[A-F0-9]{32}$/D',$secret)===1,'Missing private receipt token');
checkTracked(ankhGuestTrackingIssue($db,1)===null,'Receipt may never be silently reissued on repeat');
checkTracked(ankhGuestTrackingRows($db,101)===[],'Guest requests exposed before claim');
$rejected=false;try{ankhGuestTrackingClaim($db,101,portalMobile('07700900456'),$secret);}catch(InvalidArgumentException $e){$rejected=true;}
checkTracked($rejected,'A secret alone must not bypass verified phone requirement');
checkTracked(ankhGuestTrackingRows($db,101)===[],'Wrong-phone request was incorrectly claimed');
$rejected=false;try{ankhGuestTrackingClaim($db,101,$verified,str_repeat('0',32));}catch(InvalidArgumentException $e){$rejected=true;}
checkTracked($rejected,'Incorrect code accepted');
checkTracked(ankhGuestTrackingClaim($db,101,$verified,$secret)===1,'Valid phone and code cannot claim');
$rows=ankhGuestTrackingRows($db,101);
checkTracked(count($rows)===1&&$rows[0]['lines'][0]['name']==='Accessory pouch','Owned request missing or other customer leaked');
checkTracked(!isset($rows[0]['phone'])&&!isset($rows[0]['address'])&&!isset($rows[0]['email'])&&!isset($rows[0]['name']),'Guest PII leaked into account response');
$db->exec("UPDATE storefront_guest_requests SET status='Reviewed — create in Admin manually' WHERE id=1");
$rows=ankhGuestTrackingRows($db,101);
checkTracked($rows[0]['status']==='Reviewed — create in Admin manually','Tracking is not live to ANKH staff status');
$rejected=false;try{ankhGuestTrackingClaim($db,202,$verified,$secret);}catch(InvalidArgumentException $e){$rejected=true;}
checkTracked($rejected,'A different customer account was allowed to steal a claimed request');
checkTracked(ankhGuestTrackingRows($db,202)===[],'Claim leak across accounts');
echo "ANKH guest request claims: secret + verified phone, no cross-customer leakage, current staff status and no guest PII passed.\n";
