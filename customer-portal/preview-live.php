<?php
declare(strict_types=1);
/**
 * Password-protected, read-only staff view of LIVE customer order records.
 * Must NEVER run without preview_password_hash in a private config.
 * No customer can use this to sign in. Twilio is not needed for staff preview.
 */
require_once __DIR__.'/lib.php';
$localHttp = PHP_SAPI === 'cli-server' && getenv('ANKH_TEST_ALLOW_HTTP') === '1'
    && in_array((string)($_SERVER['SERVER_ADDR'] ?? ''), ['127.0.0.1','::1'], true);
if (!$localHttp && ($_SERVER['HTTPS'] ?? '') !== 'on') {
    http_response_code(403); exit('HTTPS required.');
}
ini_set('session.use_strict_mode','1');
session_name('ankh_staff_preview');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!$localHttp,'httponly'=>true,'samesite'=>'Strict']);
session_start();
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; worker-src 'self'; manifest-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
if (!$localHttp) header('Strict-Transport-Security: max-age=31536000');
$_SESSION['portal_csrf'] ??= bin2hex(random_bytes(32));

try {
    $config = portalConfig();
    $expectedHost = strtolower((string)($config['host'] ?? 'order.ankhpeptides.com'));
    if (!$localHttp && $expectedHost !== strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))) {
        http_response_code(404); exit;
    }
    $passwordHash = $config['preview_password_hash'] ?? '';
    if (!is_string($passwordHash) || !preg_match('/^\$2[aby]\$/', $passwordHash) && !str_starts_with($passwordHash,'$argon2')) {
        http_response_code(503);
        exit('Staff preview not enabled. Configure a private password first.');
    }
    $portal = portalDb($config);
} catch (Throwable $e) {
    error_log('ANKH staff preview config: ' . $e->getMessage());
    http_response_code(503);
    exit('Staff preview is not available.');
}
$loginFingerprint = hash('sha256',$passwordHash);
$loggedIn = !empty($_SESSION['staff_preview_authorised'])
    && hash_equals($loginFingerprint,(string)($_SESSION['staff_preview_auth_version'] ?? ''))
    && (int)($_SESSION['staff_preview_last'] ?? 0) > time()-1200;

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals((string)$_SESSION['portal_csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);exit('Invalid request. Refresh and try again.');
    }
    $action=(string)($_POST['action'] ?? '');
    if ($action==='logout') {
        $_SESSION=[];
        session_regenerate_id(true);
        header('Location: ./preview-live.php',true,303);
        exit;
    }
    if ($action==='staff_login' && !$loggedIn) {
        $ip=(string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $allowed=portalAllowAttempt($portal,'staff:'.hash('sha256',$ip),5,900);
        // Constant-time password verifier (including when rate limited).
        $valid=password_verify((string)($_POST['password'] ?? ''),$passwordHash);
        if (!$allowed || !$valid) {
            $error='Incorrect password or too many attempts. Please try again later.';
        } else {
            session_regenerate_id(true);
            $_SESSION['staff_preview_authorised']=true;
            $_SESSION['staff_preview_auth_version']=$loginFingerprint;
            $_SESSION['staff_preview_last']=time();
            header('Location: ./preview-live.php',true,303);
            exit;
        }
    }
}
if (!$loggedIn) {
    unset($_SESSION['staff_preview_authorised'],$_SESSION['staff_preview_auth_version'],$_SESSION['staff_preview_last']);
    // Do not touch the live Admin database before authenticating.
    ?>
    <!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex"><title>Staff preview · ANKH</title>
    <link rel="stylesheet" href="./style.css"></head><body>
    <header class="site-header"><span class="brand"><span class="brand-icon">☥</span>ANKH</span><span class="header-note">PRIVATE STAFF PREVIEW</span></header>
    <main class="page auth"><div class="auth-layout"><section class="welcome-copy"><span class="eyebrow">ANKH · STAFF ACCESS</span><h1>Real data.<br><em>Private review.</em></h1>
    <p>Preview the customer experience using actual order records, without enabling customer SMS logins.</p></section>
    <section class="auth-card"><div class="card-top"><span class="card-symbol">☥</span><span class="step-badge">STAFF ONLY</span></div><h2>Preview access</h2>
    <p class="card-intro">This preview contains real customer information. Enter the temporary staff preview password to continue.</p>
    <?php if($error):?><p class="feedback error" role="alert"><?=portalEscape($error)?></p><?php endif;?>
    <form method="post" action="./preview-live.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?=portalEscape((string)$_SESSION['portal_csrf'])?>">
    <input type="hidden" name="action" value="staff_login">
    <label for="preview_password">Preview password</label>
    <input id="preview_password" class="code-input staff-password-input" name="password" type="password" autocomplete="current-password" required>
    <button class="primary-button" type="submit">Unlock staff preview <span>→</span></button></form>
    <div class="privacy-note"><p>Access expires after 20 minutes of inactivity. No real customer can sign in here.</p></div>
    </section></div></main></body></html>
    <?php
    exit;
}
$_SESSION['staff_preview_last']=time();
try {
    $admin = portalAdminDb($config); // SQLite read-only connection
} catch(Throwable $e) {
    error_log('ANKH staff preview DB: '.$e->getMessage());
    http_response_code(503);exit('Order history is temporarily unavailable.');
}
// Staff-only portal management; the live Admin connection remains read-only.
portalContactStaffController($portal);
portalExtrasStaffController($portal,$admin);
if(($_GET['manage']??'')==='portal'){
    portalExtrasStaffPage($portal,$admin);
    exit;
}
$search=trim((string)($_GET['q'] ?? ''));
if (strlen($search)>80) $search=substr($search,0,80);
if ($search !== '') {
    $stmt=$admin->prepare('SELECT id,name,phone FROM customers WHERE archived=0 AND (name LIKE ? OR phone LIKE ?) ORDER BY name COLLATE NOCASE LIMIT 30');
    $stmt->execute(['%'.$search.'%','%'.$search.'%']);
} else {
    $stmt=$admin->query('SELECT id,name,phone FROM customers WHERE archived=0 ORDER BY id DESC LIMIT 30');
}
$results=$stmt->fetchAll(PDO::FETCH_ASSOC);
$requestedId=filter_var($_GET['customer_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
$customer=null;$account=null;$orders=[];$firstName='';$active=[];
$notice='';
if ($requestedId) {
    $stmt=$admin->prepare('SELECT id,name,phone,address,archived FROM customers WHERE id=? AND archived=0');
    $stmt->execute([$requestedId]);
    $customer=$stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($customer) {
        $mobile=portalMobile((string)$customer['phone']);
        $account=['phone_e164'=>$mobile ?: 'Invalid UK mobile number'];
        if ($mobile) {
            try { $orders=portalOrderHistory($admin,$customer,$mobile); }
            catch(Throwable $e) {error_log('ANKH staff preview orders: '.$e->getMessage());$notice='Order data is temporarily unavailable.';}
        } else $notice='This customer has no valid UK mobile number and cannot currently receive SMS verification. Their history will be unavailable to the proposed portal until this is corrected.';
        $firstName=(string)(preg_split('/\s+/',trim((string)$customer['name']))[0] ?? '');
        $active=array_values(array_filter($orders,static fn(array $o): bool => !in_array(portalStatus($o),['Delivered','Cancelled'],true)));
    } else $notice='That customer record is not available.';
}
if($customer && $account && ($_GET['download']??'')==='statement') {
    require_once __DIR__.'/statement.php';
    portalStatementDownload($orders,$customer);
}
if($customer && $account)portalOrderRequestController($portal,$admin,$customer,$account,$orders,true);
if($customer && $account)portalNotificationController($portal,$admin,$customer,$account,$orders,true);
if($customer && $account)portalExtrasController($portal,$admin,$customer,$account,$orders,true);
if($customer && $account)portalCollectionController($portal,$admin,$customer,true);
if($customer && $account)portalFridgeController($portal,$customer,$orders,true);
if($customer)ankhPortalCatalogueActions($portal,$admin,$customer,true);
if(isset($_GET['catalogue_image'])){
 if(!$customer){http_response_code(404);exit;}
 ankhCatalogueOutputImage($admin,(int)$_GET['catalogue_image'],true);
}
$isStaffPreview=true;
// Staff catalogue uses the real read-only Admin connection.

define('ANKH_PORTAL_VIEW',true);
require __DIR__.'/view.php';

