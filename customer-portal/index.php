<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$localHttp = PHP_SAPI === 'cli-server' && getenv('ANKH_TEST_ALLOW_HTTP') === '1'
    && in_array((string)($_SERVER['SERVER_ADDR'] ?? ''), ['127.0.0.1','::1'], true);
if (!$localHttp && (($_SERVER['HTTPS'] ?? '') !== 'on')) {
    http_response_code(403);
    exit('Secure HTTPS connection required.');
}
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $localHttp ? '0' : '1');
session_name('ankh_customer_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !$localHttp, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
if (!$localHttp) header('Strict-Transport-Security: max-age=31536000');
$_SESSION['portal_csrf'] ??= bin2hex(random_bytes(32));

try {
    $config = portalConfig();
    $expectedHost = strtolower((string)($config['host'] ?? 'order.ankhpeptides.com'));
    $actualHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($expectedHost !== $actualHost && !$localHttp) {
        http_response_code(404);
        exit;
    }
    // Do not expose public login before a real SMS provider is configured.
    if (!preg_match('/^AC[0-9a-f]{32}$/i', (string)$config['twilio_account_sid'])
        || !preg_match('/^VA[0-9a-f]{32}$/i', (string)$config['twilio_verify_service_sid'])
        || strlen((string)$config['twilio_auth_token']) < 20) {
        http_response_code(503);
        exit('<!doctype html><html lang="en"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>ANKH customer portal</title><body style="background:#0b0d0e;color:#e8c77d;font:16px system-ui;padding:10vh 8vw"><h1>☥ ANKH</h1><p>Customer sign-in is not yet available.</p></body></html>');
    }
    $portal = portalDb($config);
    $admin = portalAdminDb($config);
} catch (Throwable $e) {
    error_log('ANKH customer portal configuration: ' . $e->getMessage());
    http_response_code(503);
    exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>ANKH</title><body style="background:#0b0d0e;color:#e8d7ad;font:16px system-ui;padding:12vh 8vw"><h1>☥ ANKH</h1><p>The customer portal is not available right now.</p><p>Please contact info@ankhpeptides.com.</p>');
}

$error = '';
$notice = '';
$account = null;
$customer = null;
$orders = [];
if (isset($_SESSION['portal_account_id'])) {
    $last = (int)($_SESSION['portal_last_activity'] ?? 0);
    if ($last < time() - 1800) {
        unset($_SESSION['portal_account_id'], $_SESSION['portal_last_activity'], $_SESSION['portal_auth_version'], $_SESSION['portal_phone']);
        $notice = 'Your session expired. Please sign in again.';
    } else {
        $account = portalAccountForId($portal, (int)$_SESSION['portal_account_id']);
        if ($account && ((int)($_SESSION['portal_auth_version'] ?? 0) !== (int)$account['auth_version'] || (string)($_SESSION['portal_phone'] ?? '') !== (string)$account['phone_e164'])) $account=null;
        $customer = $account ? portalCustomer($admin,$account) : null;
        if (!$customer) {
            unset($_SESSION['portal_account_id'], $_SESSION['portal_last_activity'], $_SESSION['portal_auth_version'], $_SESSION['portal_phone']);
            $account = null;
            $notice = 'Account access needs to be confirmed. Please contact ANKH.';
        } else {
            $_SESSION['portal_last_activity'] = time();
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals((string)$_SESSION['portal_csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('The request could not be verified. Refresh the page and try again.');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: ./', true, 303);
        exit;
    }
    if (!$customer && $action === 'send') {
        $phone = portalMobile((string)($_POST['phone'] ?? ''));
        if (!$phone) {
            $error = 'Enter a valid UK mobile number to continue.';
        } elseif (!portalOtpAllowed($portal, $phone, 'send')) {
            $error = 'Too many attempts. Please try again later.';
        } else {
            $_SESSION['portal_pending_phone'] = $phone;
            $_SESSION['portal_pending_until'] = time() + 600;
            // Deliberately show the same confirmation to unregistered numbers.
            if (($eligible = portalAccountForPhone($portal, $phone)) && portalCustomer($admin, $eligible)) {
                try { portalSendCode($config,$phone); }
                catch (Throwable $e) { error_log('ANKH portal SMS delivery failed: ' . $e->getMessage()); }
            }
            $notice = 'If this mobile number has been enabled, a code will arrive by SMS.';
        }
    } elseif (!$customer && $action === 'verify') {
        $phone = (string)($_SESSION['portal_pending_phone'] ?? '');
        $code = trim((string)($_POST['code'] ?? ''));
        if (!preg_match('/^[0-9]{6,8}$/D', $code) || (int)($_SESSION['portal_pending_until'] ?? 0) < time() || !$phone) {
            $error = 'That code is invalid or has expired. Request a new one.';
        } elseif (!portalOtpAllowed($portal, $phone, 'check')) {
            $error = 'Too many attempts. Please try again later.';
        } else {
            $login = portalAccountForPhone($portal,$phone);
            $verified = false;
            if ($login) {
                try { $verified = portalVerifyCode($config,$phone,$code); }
                catch (Throwable $e) { error_log('ANKH portal SMS check failed: ' . $e->getMessage()); }
            }
            if ($verified && $login && portalCustomer($admin,$login)) {
                session_regenerate_id(true);
                $_SESSION['portal_account_id'] = (int)$login['id'];
                $_SESSION['portal_auth_version'] = (int)$login['auth_version'];
                $_SESSION['portal_phone'] = (string)$login['phone_e164'];
                $_SESSION['portal_last_activity'] = time();
                unset($_SESSION['portal_pending_phone'],$_SESSION['portal_pending_until']);
                $portal->prepare('UPDATE portal_accounts SET verified_at=? WHERE id=?')->execute([time(),(int)$login['id']]);
                header('Location: ./', true, 303);
                exit;
            }
            $error = 'We could not verify that code. Check it and try again.';
        }
    } elseif (!$customer && $action === 'change_phone') {
        unset($_SESSION['portal_pending_phone'],$_SESSION['portal_pending_until']);
        header('Location: ./', true, 303);
        exit;
    }
}

if ($customer && $account) {
    try { $orders = portalOrderHistory($admin,$customer,(string)$account['phone_e164']); }
    catch (Throwable $e) {
        error_log('ANKH portal orders: ' . $e->getMessage());
        $error = 'Orders are temporarily unavailable. Please try again later.';
    }
}
$pending = !$customer && !empty($_SESSION['portal_pending_phone']) && (int)($_SESSION['portal_pending_until'] ?? 0) >= time();
$firstName = $customer ? preg_split('/\s+/',trim((string)$customer['name']))[0] : '';
$active = array_values(array_filter($orders, fn(array $o): bool => !in_array(portalStatus($o), ['Delivered','Cancelled'], true)));

if($customer)ankhPortalCatalogueActions($portal,$admin,$customer,false);
if(isset($_GET['catalogue_image'])){
 if(!$customer){http_response_code(404);exit;}
 ankhCatalogueOutputImage($admin,(int)$_GET['catalogue_image'],true);
}
define('ANKH_PORTAL_VIEW', true);
require __DIR__ . '/view.php';

