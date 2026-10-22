<?php
declare(strict_types=1);
/** Staff-configured WhatsApp contact. Never guess or expose staff personal numbers. */
function portalContactInit(PDO $db):void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_public_settings(name TEXT PRIMARY KEY,value TEXT NOT NULL,updated_at INTEGER NOT NULL)");
}
function portalContactWhatsApp(PDO $db):?string {
    portalContactInit($db);
    $q=$db->prepare("SELECT value FROM portal_public_settings WHERE name='whatsapp'");
    $q->execute();
    $number=(string)($q->fetchColumn()?:'');
    return preg_match('/^[1-9][0-9]{7,14}$/D',$number)?$number:null;
}
function portalContactWhatsAppUrl(?string $number,string $message='Hi ANKH, I need some help.'):?string {
    if(!is_string($number)||!preg_match('/^[1-9][0-9]{7,14}$/D',$number))return null;
    return 'https://wa.me/'.$number.'?text='.rawurlencode(mbSafeMessage($message));
}
function mbSafeMessage(string $message):string {
    // Never include customer name, phone or other private account data in the external link.
    return substr(trim($message),0,450);
}
function portalContactSaveWhatsApp(PDO $db,mixed $value):void {
    if(!is_string($value)||strlen($value)>50)throw new InvalidArgumentException('Enter a valid WhatsApp Business number.');
    $raw=trim($value);
    if($raw===''){
        portalContactInit($db);
        $db->prepare("DELETE FROM portal_public_settings WHERE name='whatsapp'")->execute([]);
        return;
    }
    $digits=preg_replace('/[\s()+.\-]/','',$raw);
    if(!is_string($digits)||!preg_match('/^[0-9]{8,15}$/D',$digits))throw new InvalidArgumentException('Use an international number, for example 447700900000. No other text.');
    if(str_starts_with($digits,'0'))$digits='44'.substr($digits,1);
    if(!preg_match('/^[1-9][0-9]{7,14}$/D',$digits))throw new InvalidArgumentException('Enter an international number of 8–15 digits.');
    portalContactInit($db);
    $db->prepare("INSERT INTO portal_public_settings(name,value,updated_at) VALUES('whatsapp',?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at")->execute([$digits,time()]);
}
function portalContactStaffController(PDO $db):void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||($_POST['action']??'')!=='portal_whatsapp')return;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)($_SESSION['portal_csrf']??''),$_POST['csrf'])){http_response_code(400);exit('Invalid request.');}
    try{
        portalContactSaveWhatsApp($db,$_POST['whatsapp']??null);
        $_SESSION['portal_staff_flash']='WhatsApp Business contact saved.';
    }catch(InvalidArgumentException $e){$_SESSION['portal_staff_flash']=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH portal WhatsApp setup: '.$e->getMessage());$_SESSION['portal_staff_flash']='WhatsApp contact could not be saved.';}
    header('Location: ./preview-live.php?manage=portal',true,303);exit;
}
