<?php
declare(strict_types=1);
// The standalone portal receives the same policy module as Admin during publishing.
require_once is_file(__DIR__.'/customer-catalogue.php')?__DIR__.'/customer-catalogue.php':dirname(__DIR__).'/customer-catalogue.php';
require_once __DIR__.'/account-features.php';
require_once __DIR__.'/request-watch.php';
require_once __DIR__.'/contact-settings.php';
require_once __DIR__.'/public-catalogue-core.php';
require_once __DIR__.'/storefront-commerce.php';
require_once __DIR__.'/storefront-staff.php';
require_once __DIR__.'/my-fridge.php';

/**
 * ANKH Customer Portal — isolated public application.
 * The Admin database is opened read-only; portal sessions and enrolments live
 * in a different PRIVATE SQLite database, outside any website document root.
 * Never include the Admin index.php here: it runs migrations and admin code.
 */
function portalConfig(): array {
    $path = getenv('ANKH_PORTAL_CONFIG') ?: dirname(__DIR__, 2) . '/ankh-customer-portal-config.php';
    $config = is_file($path) ? require $path : null;
    if (!is_array($config)) {
        throw new RuntimeException('Customer portal is not configured.');
    }
    foreach (['admin_database_path','portal_database_path','twilio_account_sid','twilio_auth_token','twilio_verify_service_sid'] as $key) {
        if (empty($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException('Customer portal configuration is incomplete.');
        }
    }
    return $config;
}

function portalDb(array $config): PDO {
    $path = $config['portal_database_path'];
    if (!is_file($path)) {
        throw new RuntimeException('Customer access database has not been initialised.');
    }
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
    return $db;
}

function portalAdminDb(array $config): PDO {
    $path = realpath($config['admin_database_path']);
    if (!$path || !is_file($path)) {
        throw new RuntimeException('The order database is unavailable.');
    }
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    // Force the connection read-only. No schema migrations, reservations or updates.
    $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=5000');
    return $db;
}

function portalInitDb(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_accounts (
        id INTEGER PRIMARY KEY,
        auth_version INTEGER NOT NULL DEFAULT 1,
        customer_id INTEGER NOT NULL UNIQUE,
        phone_e164 TEXT NOT NULL UNIQUE,
        active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
        created_at INTEGER NOT NULL,
        verified_at INTEGER DEFAULT NULL
    );
    CREATE TABLE IF NOT EXISTS portal_rate_limits (
        rate_key TEXT PRIMARY KEY,
        attempts INTEGER NOT NULL,
        started_at INTEGER NOT NULL
    );");
    $columns = array_column($db->query('PRAGMA table_info(portal_accounts)')->fetchAll(PDO::FETCH_ASSOC),'name');
    if (!in_array('auth_version',$columns,true)) $db->exec('ALTER TABLE portal_accounts ADD COLUMN auth_version INTEGER NOT NULL DEFAULT 1');
}

function portalMobile(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 40) return null;
    $digits = preg_replace('/[\s().-]+/', '', $raw);
    if (!is_string($digits)) return null;
    if (str_starts_with($digits, '0044')) $digits = '+44' . substr($digits, 4);
    elseif (str_starts_with($digits, '07')) $digits = '+44' . substr($digits, 1);
    elseif (str_starts_with($digits, '447')) $digits = '+' . $digits;
    return preg_match('/^\+447\d{9}$/D', $digits) ? $digits : null;
}

function portalAccountForPhone(PDO $db, string $phone): ?array {
    $q = $db->prepare('SELECT id,customer_id,phone_e164,active,auth_version FROM portal_accounts WHERE phone_e164=? AND active=1');
    $q->execute([$phone]);
    return $q->fetch() ?: null;
}

function portalAccountForId(PDO $db, int $id): ?array {
    $q = $db->prepare('SELECT id,customer_id,phone_e164,active,auth_version FROM portal_accounts WHERE id=? AND active=1');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** Rate limiting on hashed phone/IP, with an atomic SQLite update. */
function portalAllowAttempt(PDO $db, string $key, int $max, int $period): bool {
    $now = time();
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT attempts,started_at FROM portal_rate_limits WHERE rate_key=?');
        $q->execute([$key]);
        $row = $q->fetch();
        if (!$row || (int)$row['started_at'] <= $now - $period) {
            $db->prepare('INSERT INTO portal_rate_limits(rate_key,attempts,started_at) VALUES (?,1,?) ON CONFLICT(rate_key) DO UPDATE SET attempts=1,started_at=excluded.started_at')
                ->execute([$key,$now]);
            $allowed = true;
        } elseif ((int)$row['attempts'] >= $max) {
            $allowed = false;
        } else {
            $db->prepare('UPDATE portal_rate_limits SET attempts=attempts+1 WHERE rate_key=?')->execute([$key]);
            $allowed = true;
        }
        $db->commit();
        return $allowed;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function portalOtpAllowed(PDO $db, string $phone, string $action): bool {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    // Do not trust forwarded IP headers.
    $ipHash = hash('sha256', $ip);
    $phoneHash = hash('sha256', $phone);
    return portalAllowAttempt($db, $action . ':ip:' . $ipHash, $action === 'send' ? 12 : 24, 3600)
        && portalAllowAttempt($db, $action . ':phone:' . $phoneHash, $action === 'send' ? 3 : 8, 600);
}

/** Twilio Verify issues and checks the OTP; no plaintext codes are stored by ANKH. */
function portalTwilio(array $config, string $path, array $data): array {
    if (!extension_loaded('curl')) throw new RuntimeException('SMS service unavailable.');
    $sid = $config['twilio_verify_service_sid'];
    if (!preg_match('/^VA[0-9a-f]{32}$/i', $sid)) throw new RuntimeException('SMS service unavailable.');
    $ch = curl_init('https://verify.twilio.com/v2/Services/' . $sid . '/' . $path);
    if ($ch === false) throw new RuntimeException('SMS service unavailable.');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_USERPWD => $config['twilio_account_sid'] . ':' . $config['twilio_auth_token'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $response = is_string($body) ? json_decode($body, true) : null;
    if ($code < 200 || $code > 299 || !is_array($response)) {
        throw new RuntimeException('SMS verification temporarily unavailable.');
    }
    return $response;
}

function portalSendCode(array $config, string $phone): bool {
    $result = portalTwilio($config, 'Verifications', ['To' => $phone, 'Channel' => 'sms']);
    return ($result['status'] ?? '') === 'pending';
}

function portalVerifyCode(array $config, string $phone, string $code): bool {
    $result = portalTwilio($config, 'VerificationCheck', ['To' => $phone, 'Code' => $code]);
    return ($result['status'] ?? '') === 'approved';
}

/** Only an explicitly enrolled customer is allowed access, never a phone-only match. */
function portalCustomer(PDO $admin, array $account): ?array {
    $q = $admin->prepare('SELECT id,name,phone,address,archived FROM customers WHERE id=?');
    $q->execute([(int)$account['customer_id']]);
    $customer = $q->fetch();
    if (!$customer || (int)$customer['archived'] !== 0) return null;
    if (portalMobile((string)$customer['phone']) !== (string)$account['phone_e164']) return null;
    return $customer;
}

function portalOrderHistory(PDO $admin, array $customer, string $verifiedPhone): array {
    if(portalMobile((string)($customer['phone']??''))!==$verifiedPhone)return [];
    $identity=$admin->prepare('SELECT id,phone FROM customers WHERE lower(trim(name))=lower(trim(?))');$identity->execute([trim((string)$customer['name'])]);
    $identityIds=[];foreach($identity as $record)if(portalMobile((string)$record['phone'])===$verifiedPhone)$identityIds[]=(int)$record['id'];
    $legacyAllowed=trim((string)$customer['name'])!=='' && count($identityIds)===1 && $identityIds[0]===(int)$customer['id'];
    $linked=(bool)$admin->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_order_links'")->fetchColumn();
    $productLinked=in_array('product_id',array_column($admin->query('PRAGMA table_info(items)')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
    $fields='o.id,o.customer,o.phone,o.created,o.status,o.delivery_date,o.scheduled_delivery_date,o.delivery_charge,o.packaged,o.reconstituted,o.delivery_method,o.tracking_reference';
    // A saved owner is authoritative. Name + verified phone is only a legacy fallback.
    $legacyWhere=$legacyAllowed?'lower(trim(o.customer))=lower(trim(?))':'0=1';
    $sql='SELECT '.$fields.($linked?',l.customer_id AS linked_customer_id':'').' FROM orders o '.($linked?'LEFT JOIN customer_order_links l ON l.order_id=o.id ':'').'WHERE '.($linked?'(l.customer_id=? OR (l.order_id IS NULL AND '.$legacyWhere.'))':$legacyWhere).' ORDER BY o.id DESC LIMIT 1000';
    $q=$admin->prepare($sql);$q->execute(array_merge($linked?[(int)$customer['id']]:[],$legacyAllowed?[trim((string)$customer['name'])]:[]));
    $orders=[];
    $items=$admin->prepare('SELECT i.name,i.presentation,i.price,i.quantity'.($productLinked?',i.product_id':'').' FROM items i WHERE i.order_id=? ORDER BY i.id ASC');
    $payments = $admin->prepare('SELECT amount,created,method FROM payments WHERE order_id=? ORDER BY created DESC,id DESC');
    foreach ($q as $order) {
        if(empty($order['linked_customer_id']) && portalMobile((string)$order['phone'])!==$verifiedPhone)continue;
        unset($order['linked_customer_id']);
        $items->execute([(int)$order['id']]);
        $lines = $items->fetchAll();
        $payments->execute([(int)$order['id']]);
        $paymentRows = $payments->fetchAll();
        $paid = array_sum(array_map(static fn(array $entry): int => (int)$entry['amount'], $paymentRows));
        $total = max(0,(int)$order['delivery_charge']);
        foreach ($lines as $line) $total += (int)$line['price'] * (int)$line['quantity'];
        $order['lines'] = $lines;
        $order['total'] = $total;
        $order['paid'] = min($paid,$total);
        $order['payments'] = array_map(static fn(array $p): array => ['amount'=>(int)$p['amount'],'created'=>(string)$p['created'],'method'=>(string)($p['method']??'')], $paymentRows);
        $orders[] = $order;
    }
    return $orders;
}

function portalStatus(array $order): string {
    if ($order['status'] === 'Cancelled') return 'Cancelled';
    if (trim((string)$order['delivery_date']) !== '' || $order['status'] === 'Delivered') return 'Delivered';
    if ($order['status'] === 'Dispatched') return 'On its way';
    if ((string)$order['status'] === 'New') return 'Received';
    return 'In progress';
}

function portalMoney(int $pence): string { return '£' . number_format($pence / 100, 2); }
function portalEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function portalDate(string $date): string {
    try { return (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Europe/London'))->format('j M Y'); }
    catch (Throwable $e) { return 'Date unavailable'; }
}


/** Saved order reminders only. Never infer schedules from product names or expose forecasts/costs. */
function portalRecurringOrders(PDO $admin,array $customer,string $verifiedPhone,array $orders,?DateTimeImmutable $today=null):array {
    if(portalMobile((string)($customer['phone']??''))!==$verifiedPhone)return [];
    if(!$admin->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='reta_cycles'")->fetchColumn())return [];
    $columns=array_column($admin->query('PRAGMA table_info(reta_cycles)')->fetchAll(PDO::FETCH_ASSOC),'name');
    foreach(['id','customer_name','phone','last_order_id','next_due_date','active','product_name'] as $required)if(!in_array($required,$columns,true))return [];
    $owned=[];foreach($orders as $order){
        if(portalStatus($order)==='Delivered' && trim((string)($order['delivery_date']??''))!=='')$owned[(int)$order['id']]=$order;
    }
    if(!$owned)return [];
    $linked=(bool)$admin->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_order_links'")->fetchColumn();
    $productNames=[];if($admin->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='products'")->fetchColumn())foreach($admin->query('SELECT id,name FROM products') as $product)$productNames[(int)$product['id']]=(string)$product['name'];
    $fields=['c.id','c.customer_name','c.phone','c.last_order_id','c.next_due_date','c.product_name'];
    foreach(['presentation','quantity','cycle_weeks','cycle_days'] as $field)$fields[]=in_array($field,$columns,true)?'c.'.$field:($field==='presentation'?"''":"0").' AS '.$field;
    $q=$admin->prepare('SELECT '.implode(',',$fields).($linked?',l.customer_id AS linked_customer_id':'').' FROM reta_cycles c '.($linked?'LEFT JOIN customer_order_links l ON l.order_id=c.last_order_id ':'').'WHERE c.active=1 AND '.($linked?'(l.customer_id=? OR (l.order_id IS NULL AND lower(trim(c.customer_name))=lower(trim(?))))':'lower(trim(c.customer_name))=lower(trim(?))').' ORDER BY c.next_due_date,c.id');$q->execute($linked?[(int)$customer['id'],trim((string)$customer['name'])]:[trim((string)$customer['name'])]);
    $zone=new DateTimeZone('Europe/London');$today=($today??new DateTimeImmutable('today',$zone))->setTimezone($zone)->setTime(0,0);
    $out=[];
    foreach($q as $cycle){
        if((empty($cycle['linked_customer_id']) && portalMobile((string)$cycle['phone'])!==$verifiedPhone) || !isset($owned[(int)$cycle['last_order_id']]))continue;
        $name=trim((string)$cycle['product_name']);if($name==='')continue;
        $source=$owned[(int)$cycle['last_order_id']];
        // Require the named product to exist in the independently authorised source order.
        $matchedLines=array_values(array_filter($source['lines'],static fn(array $line):bool=>strcasecmp(trim((string)$line['name']),$name)===0 || (isset($productNames[(int)($line['product_id']??0)]) && strcasecmp(trim($productNames[(int)$line['product_id']]),$name)===0)));
        if(!$matchedLines)continue;
        $productIds=array_values(array_unique(array_filter(array_map(static fn(array $line):int=>(int)($line['product_id']??0),$matchedLines))));
        if(count($productIds)>1)continue;
        $productId=$productIds[0]??0;
        if($productId>0 && isset($productNames[$productId]))$name=$productNames[$productId];
        $due=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$cycle['next_due_date'],$zone);
        if(!$due || $due->format('Y-m-d')!==(string)$cycle['next_due_date'])continue;
        $days=(int)$today->diff($due)->format('%r%a');$weeks=(int)$cycle['cycle_weeks'];$interval=(int)$cycle['cycle_days'];
        if($weeks<1||$weeks>52)$weeks=($interval>=7&&$interval<=364&&$interval%7===0)?intdiv($interval,7):0;
        $out[]=['id'=>(int)$cycle['id'],'revision'=>ankhReminderSignature($cycle,$source),'product_id'=>$productId,'product'=>$name,'presentation'=>(string)$cycle['presentation'],'quantity'=>max(1,(int)$cycle['quantity']),'weeks'=>$weeks,'due_date'=>$due->format('Y-m-d'),'days_until'=>$days,'last_order_id'=>(int)$source['id'],'last_delivery_date'=>(string)$source['delivery_date']];
    }
    return $out;
}

/** Current, account-scoped events. No mail, push or writes to the Admin database. */
function portalNotificationEvents(array $orders,array $enquiries,array $reminders,?DateTimeImmutable $today=null,array $restockEvents=[]):array {
    $today=($today??new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->setTimezone(new DateTimeZone('Europe/London'));
    $events=[];
    foreach($enquiries as $row){
        if(trim((string)$row['reply'])==='')continue;
        $key=hash('sha256','reply:'.$row['id'].':'.$row['reply']);
        $events[]=['key'=>$key,'kind'=>'reply','title'=>'ANKH replied to your enquiry','body'=>(string)$row['product_name'].' · Enquiry #'.(int)$row['id'],'tab'=>'catalogue','params'=>['view_enquiries'=>1],'priority'=>0,'sort'=>(int)$row['updated_at'],'label'=>'Reply available'];
    }
    foreach(array_slice($orders,0,30) as $order){
        $date=(string)(trim((string)$order['delivery_date'])!==''?$order['delivery_date']:$order['created']);
        try{$when=new DateTimeImmutable($date,new DateTimeZone('Europe/London'));}catch(Throwable $e){continue;}
        if($when<$today->modify('-90 days'))continue;
        $status=portalStatus($order);$balance=max(0,(int)$order['total']-(int)$order['paid']);
        $state=[$order['id'],$status,$order['delivery_date'],$order['scheduled_delivery_date'],$order['tracking_reference'],$order['packaged'],$order['reconstituted'],$order['total'],$order['paid']];
        $body=$status;
        if(!in_array($status,['Delivered','Cancelled'],true)){if((int)$order['packaged']===1)$body.=' · Packaged';elseif((int)$order['reconstituted']===1)$body.=' · Prepared';}
        if(trim((string)$order['scheduled_delivery_date'])!=='' && $status!=='Delivered' && $status!=='Cancelled')$body.=' · Planned delivery '.portalDate((string)$order['scheduled_delivery_date']);
        if(trim((string)$order['tracking_reference'])!=='')$body.=' · Tracking available';
        if($status!=='Cancelled')$body.=$balance>0?' · '.portalMoney($balance).' outstanding':' · No outstanding balance';
        $events[]=['key'=>hash('sha256','order:'.json_encode($state)),'kind'=>'order','title'=>'Order ANK-'.str_pad((string)$order['id'],4,'0',STR_PAD_LEFT),'body'=>$body,'tab'=>'orders','params'=>['order'=>(int)$order['id']],'priority'=>2,'sort'=>$when->getTimestamp(),'label'=>'Current order update'];
    }
    foreach($reminders as $row){
        $request=$row['change_request']??null;
        if($request && in_array($request['status'],['Approved','Declined'],true))$events[]=['key'=>hash('sha256','reminder-request:'.$request['id'].':'.$request['status']),'kind'=>'reminder','title'=>'Reminder change '.strtolower($request['status']),'body'=>$row['product'].' · '.($request['preview']?'Staff test decision':('Requested '.portalDate($request['requested_date']))),'tab'=>'recurring','params'=>[],'priority'=>0,'sort'=>(int)$request['reviewed_at'],'label'=>'Reminder request update'];
        if($row['days_until']>7 || !empty($row['snoozed_until']))continue;
        $phase=$row['days_until']<0?'passed':($row['days_until']===0?'today':'soon');
        $title=$phase==='today'?'Reorder reminder: expected today':($phase==='passed'?'Review your reorder reminder':'Reorder reminder approaching');
        $events[]=['key'=>hash('sha256','reminder:'.$row['id'].':'.$row['due_date'].':'.$phase),'kind'=>'reminder','title'=>$title,'body'=>$row['product'].' · Expected '.portalDate($row['due_date']),'tab'=>'recurring','params'=>[],'priority'=>1,'sort'=>strtotime($row['due_date'].' 00:00:00 Europe/London'),'label'=>'Saved order reminder'];
    }
    foreach($restockEvents as $restockEvent)$events[]=$restockEvent;
    usort($events,static fn(array $a,array $b):int=>($a['priority']<=>$b['priority'])?:($b['sort']<=>$a['sort']));
    return $events;
}
function portalNotificationInit(PDO $store):void {
    $store->exec('CREATE TABLE IF NOT EXISTS portal_notification_state(customer_id INTEGER NOT NULL,preview INTEGER NOT NULL,event_key TEXT NOT NULL,read_at INTEGER NOT NULL DEFAULT 0,dismissed INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(customer_id,preview,event_key))');
}
function portalNotificationList(PDO $store,int $customerId,bool $preview,array $events):array {
    $q=$store->prepare('SELECT event_key,read_at,dismissed FROM portal_notification_state WHERE customer_id=? AND preview=?');$q->execute([$customerId,(int)$preview]);
    $states=[];foreach($q as $state)$states[$state['event_key']]=$state;
    $out=[];foreach($events as $event){$state=$states[$event['key']]??[];if((int)($state['dismissed']??0)===1)continue;$event['read']=(int)($state['read_at']??0)>0;$out[]=$event;}
    usort($out,static fn(array $a,array $b):int=>((int)$a['read']<=>(int)$b['read'])?:($a['priority']<=>$b['priority'])?:($b['sort']<=>$a['sort']));return $out;
}
function portalNotificationUpdate(PDO $store,int $customerId,bool $preview,array $events,string $action,string $key=''):void {
    if(!in_array($action,['notification_read','notification_dismiss','notification_read_all'],true))throw new InvalidArgumentException('Invalid notification action.');
    $owned=array_column($events,null,'key');
    if($action!=='notification_read_all'&&!isset($owned[$key]))throw new InvalidArgumentException('This notification is no longer available.');
    $keys=$action==='notification_read_all'?array_keys($owned):[$key];
    $store->beginTransaction();
    try{
        $q=$store->prepare('INSERT INTO portal_notification_state(customer_id,preview,event_key,read_at,dismissed) VALUES(?,?,?,?,?) ON CONFLICT(customer_id,preview,event_key) DO UPDATE SET read_at=excluded.read_at,dismissed=MAX(portal_notification_state.dismissed,excluded.dismissed)');
        foreach($keys as $eventKey)$q->execute([$customerId,(int)$preview,$eventKey,time(),$action==='notification_dismiss'?1:0]);
        $store->commit();
    }catch(Throwable $e){$store->rollBack();throw $e;}
}
function portalNotificationController(PDO $store,PDO $admin,array $customer,array $account,array $orders,bool $preview):void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!in_array($_POST['action']??null,['notification_read','notification_dismiss','notification_read_all'],true))return;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)($_SESSION['portal_csrf']??''),$_POST['csrf'])){http_response_code(400);exit('Invalid request.');}
    try{
        ankhCustomerInteractionInit($store);portalNotificationInit($store);
        $reminders=portalReminderPreferences($store,(int)$customer['id'],$preview,portalRecurringOrders($admin,$customer,(string)$account['phone_e164'],$orders));
        $restockEvents=[];
        try{$restockEvents=portalWatchRefresh($store,(int)$customer['id'],$preview,ankhCatalogueProducts($admin,(int)$customer['id']),portalExtrasAvailabilityMap($store));}
        catch(Throwable $e){error_log('ANKH restock events: '.$e->getMessage());}
        $events=portalNotificationEvents($orders,ankhCustomerEnquiries($store,(int)$customer['id'],$preview),$reminders,null,$restockEvents);
        if(!is_string($_POST['event_key']??''))throw new InvalidArgumentException('Invalid notification.');
        portalNotificationUpdate($store,(int)$customer['id'],$preview,$events,$_POST['action'],(string)($_POST['event_key']??''));
        $_SESSION['notification_flash']=$_POST['action']==='notification_dismiss'?'Notification dismissed.':($_POST['action']==='notification_read_all'?'All current notifications marked as read.':'Notification marked as read.');
    }catch(InvalidArgumentException $e){http_response_code(400);exit('This notification is no longer available. Refresh and try again.');}
    catch(Throwable $e){error_log('ANKH notification action: '.$e->getMessage());$_SESSION['notification_flash']='Your changes could not be saved. Please try again.';}
    header('Location: '.($preview?'./preview-live.php?customer_id='.(int)$customer['id'].'&':'./?').'tab=notifications',true,303);exit;
}

function portalRepeatCandidates(array $order,array $catalogue):array {
    $byId=array_column($catalogue,null,'id');$out=[];
    foreach($order['lines'] as $index=>$line){
        $product=null;$id=(int)($line['product_id']??0);
        if($id>0)$product=$byId[$id]??null;
        else{$matches=array_values(array_filter($catalogue,static fn(array $p):bool=>strcasecmp(trim($p['name']),trim((string)$line['name']))===0));if(count($matches)===1)$product=$matches[0];}
        $out[$index]=['index'=>$index,'product_id'=>(int)($product['id']??0),'name'=>(string)($product['name']??$line['name']),'quantity'=>max(1,min(999,(int)$line['quantity'])),'unit_price'=>$product===null?null:(int)$product['price'],'available'=>$product!==null];
    }
    return $out;
}
function portalRepeatQuote(array $order,array $candidates):string {return hash('sha256',json_encode([(int)$order['id'],array_values($candidates)],JSON_THROW_ON_ERROR));}
function portalRepeatEnquiryLines(array $candidates,array $selected,array $quantities):array {
    if(!$selected||count($selected)>25)throw new InvalidArgumentException('Select between 1 and 25 available products.');
    $lines=[];$seen=[];
    foreach($selected as $raw){$index=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($index===false||isset($seen[$index])||empty($candidates[$index]['available']))throw new InvalidArgumentException('One of the selected products is unavailable. Review your selection.');$seen[$index]=true;
        $quantity=filter_var($quantities[$index]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]);if($quantity===false||$quantity===null)throw new InvalidArgumentException('Choose a quantity of 1–999 for each selected product.');
        $p=$candidates[$index];$lines[]=['product_id'=>$p['product_id'],'name'=>$p['name'],'quantity'=>$quantity,'unit_price'=>$p['unit_price']];
    }
    return $lines;
}
function portalReminderPreferences(PDO $store,int $customerId,bool $preview,array $reminders,?DateTimeImmutable $today=null):array {
    $today=($today??new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d');
    $q=$store->prepare('SELECT cycle_id,due_date,snoozed_until FROM customer_reminder_preferences WHERE customer_id=? AND preview=? AND snoozed_until>?');$q->execute([$customerId,(int)$preview,$today]);$snoozes=[];foreach($q as $row)$snoozes[$row['cycle_id'].'|'.$row['due_date']]=$row['snoozed_until'];
    $q=$store->prepare('SELECT * FROM customer_reminder_requests WHERE customer_id=? AND preview=? ORDER BY id DESC LIMIT 200');$q->execute([$customerId,(int)$preview]);$requests=[];foreach($q as $row)if(!isset($requests[(int)$row['cycle_id']]))$requests[(int)$row['cycle_id']]=$row;
    foreach($reminders as &$reminder){$reminder['snoozed_until']=$snoozes[$reminder['id'].'|'.$reminder['due_date']]??'';$reminder['change_request']=$requests[$reminder['id']]??null;}unset($reminder);return $reminders;
}
function portalOrderRequestController(PDO $store,PDO $admin,array $customer,array $account,array $orders,bool $preview):void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!in_array($_POST['action']??null,['catalogue_repeat_enquire','reminder_snooze','reminder_resume','reminder_change_request'],true))return;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)($_SESSION['portal_csrf']??''),$_POST['csrf'])){http_response_code(400);exit('Invalid request.');}
    $target='recurring';
    try{
        ankhCustomerInteractionInit($store);
        if($_POST['action']==='catalogue_repeat_enquire'){
            $target='repeat-enquiry';$order=null;$id=filter_var($_POST['source_order_id']??null,FILTER_VALIDATE_INT);foreach($orders as $candidate)if((int)$candidate['id']===$id){$order=$candidate;break;}
            if(!$order)throw new InvalidArgumentException('This order is not available in your account.');
            $candidates=portalRepeatCandidates($order,ankhCatalogueProducts($admin,(int)$customer['id']));
            if(!is_string($_POST['quote_revision']??null)||!hash_equals(portalRepeatQuote($order,$candidates),$_POST['quote_revision']))throw new InvalidArgumentException('Prices or products have changed. Review the current details and send your enquiry again.');
            if(!is_array($_POST['repeat_selected']??null)||!is_array($_POST['repeat_quantities']??null)||!is_string($_POST['message']??null)||!is_string($_POST['request_token']??null))throw new InvalidArgumentException('Check your selected products and message.');
            $lines=portalRepeatEnquiryLines($candidates,$_POST['repeat_selected'],$_POST['repeat_quantities']);$message=trim($_POST['message']);
            if(strlen($message)>8000||!preg_match('//u',$message)||preg_match_all('/./us',$message)>2000)throw new InvalidArgumentException('Use a message of up to 2,000 characters.');
            $ref='ANK-'.str_pad((string)$order['id'],4,'0',STR_PAD_LEFT);$total=array_sum(array_map(static fn(array $line):int=>$line['quantity']*$line['unit_price'],$lines));
            ankhCustomerCreateEnquiry($store,['id'=>0,'name'=>'Enquire again · '.$ref,'price'=>$total],(int)$customer['id'],$preview,'Please confirm availability for the selected products from '.$ref.'.'.($message!==''?"\n\n".$message:''),$_POST['request_token'],$lines);
            $_SESSION['order_request_flash']='Your combined enquiry has been sent. ANKH will confirm availability; no order or payment has been created.';
            $target='catalogue&view_enquiries=1';
        }else{
            $id=filter_var($_POST['cycle_id']??null,FILTER_VALIDATE_INT);$reminder=null;
            foreach(portalRecurringOrders($admin,$customer,(string)$account['phone_e164'],$orders) as $candidate)if($candidate['id']===$id){$reminder=$candidate;break;}
            if(!$reminder)throw new InvalidArgumentException('This reminder is no longer active in your account.');
            if($_POST['action']==='reminder_snooze'){
                $days=filter_var($_POST['snooze_days']??null,FILTER_VALIDATE_INT);if(!in_array($days,[7,14,28],true))throw new InvalidArgumentException('Choose a 7, 14 or 28-day snooze.');
                $until=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->modify('+'.$days.' days')->format('Y-m-d');
                $store->prepare('INSERT INTO customer_reminder_preferences(customer_id,preview,cycle_id,due_date,snoozed_until) VALUES(?,?,?,?,?) ON CONFLICT(customer_id,preview,cycle_id,due_date) DO UPDATE SET snoozed_until=excluded.snoozed_until')->execute([(int)$customer['id'],(int)$preview,$reminder['id'],$reminder['due_date'],$until]);
                $_SESSION['order_request_flash']='Reminder alerts snoozed until '.portalDate($until).'. Your recorded reorder date is unchanged.';
            }elseif($_POST['action']==='reminder_resume'){
                $store->prepare('DELETE FROM customer_reminder_preferences WHERE customer_id=? AND preview=? AND cycle_id=? AND due_date=?')->execute([(int)$customer['id'],(int)$preview,$reminder['id'],$reminder['due_date']]);$_SESSION['order_request_flash']='Reminder alerts resumed.';
            }else{
                $weeks=filter_var($_POST['requested_weeks']??null,FILTER_VALIDATE_INT);if($weeks===false||$weeks===null||!is_string($_POST['requested_date']??null)||!is_string($_POST['note']??'')||!is_string($_POST['request_token']??null)||!is_string($_POST['reminder_revision']??null)||!hash_equals($reminder['revision'],$_POST['reminder_revision']))throw new InvalidArgumentException('The reminder has changed. Refresh and review your request.');
                ankhReminderRequest($store,(int)$customer['id'],$preview,$reminder,$weeks,$_POST['requested_date'],(string)($_POST['note']??''),$_POST['request_token']);$_SESSION['order_request_flash']='Your reminder change request is awaiting ANKH approval. The recorded date and interval are unchanged.';
            }
        }
        header('Location: '.($preview?'./preview-live.php?customer_id='.(int)$customer['id'].'&':'./?').'tab='.$target,true,303);exit;
    }catch(InvalidArgumentException $e){$GLOBALS['orderRequestError']=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH customer order request: '.$e->getMessage());$GLOBALS['orderRequestError']='Your request could not be saved. Please try again.';}
}
