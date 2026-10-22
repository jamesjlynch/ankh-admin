<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/customer-portal/lib.php';
function verifyShop(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function rejectsShop(callable $callback):void {
    try{$callback();}catch(InvalidArgumentException $e){return;}
    throw new RuntimeException('Invalid input was accepted.');
}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
verifyShop(portalContactWhatsApp($db)===null,'WhatsApp incorrectly enabled by default');
verifyShop(portalContactWhatsAppUrl(null)===null,'Invented WhatsApp URL');
portalContactSaveWhatsApp($db,'+44 7700 900000');
verifyShop(portalContactWhatsApp($db)==='447700900000','Staff-configured number not normalized');
$link=portalContactWhatsAppUrl(portalContactWhatsApp($db),'Hi ANKH, I need help with order ANK-0104.');
verifyShop(str_starts_with((string)$link,'https://wa.me/447700900000?text='),'WhatsApp link malformed');
verifyShop(str_contains((string)$link,'ANK-0104'),'User-chosen order reference missing from chat');
verifyShop(!str_contains((string)$link,'+44'),'WhatsApp country prefix incorrectly encoded');
rejectsShop(static fn()=>portalContactSaveWhatsApp($db,'Hello World'));
rejectsShop(static fn()=>portalContactSaveWhatsApp($db,'+44 7700 900000;javascript:alert(1)'));
rejectsShop(static fn()=>portalContactSaveWhatsApp($db,'123'));
rejectsShop(static fn()=>portalContactSaveWhatsApp($db,['447700900000']));
verifyShop(portalContactWhatsApp($db)==='447700900000','Bad value changed valid setting');
portalContactSaveWhatsApp($db,'');
verifyShop(portalContactWhatsApp($db)===null,'WhatsApp should disable when cleared');
echo "Shop contact: verified-number isolation, chat links and validation passed.\n";
