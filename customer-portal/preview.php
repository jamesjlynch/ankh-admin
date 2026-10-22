<?php
declare(strict_types=1);
/**
 * ANKH fictional customer walkthrough.
 * This controller has no configuration loader and NO database connection.
 * Only sample data is ever presented. Never use preview state for production auth.
 */
require_once __DIR__ . '/lib.php';
$localHttp = PHP_SAPI === 'cli-server' && getenv('ANKH_TEST_ALLOW_HTTP') === '1'
    && in_array((string)($_SERVER['SERVER_ADDR'] ?? ''), ['127.0.0.1','::1'], true);
if (!$localHttp && (($_SERVER['HTTPS'] ?? '') !== 'on')) {
    http_response_code(403);
    exit('Secure HTTPS connection required.');
}
ini_set('session.use_strict_mode','1');
session_name('ankh_customer_preview');
session_set_cookie_params([
    'lifetime'=>0,'path'=>'/','secure'=>!$localHttp,
    'httponly'=>true,'samesite'=>'Strict',
]);
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
$_SESSION['demo_screen'] ??= 'login';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (($_GET['screen'] ?? '') === 'login' || ($_GET['screen'] ?? '') === 'account') {
        $_SESSION['demo_screen'] = (string)$_GET['screen'];
    }
}
$error = '';
$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals((string)$_SESSION['portal_csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Preview request could not be verified. Refresh and retry.');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'logout' || $action === 'change_phone') {
        $_SESSION['demo_screen'] = 'login';
        unset($_SESSION['demo_phone'],$_SESSION['catalogue_demo']);
    } elseif ($action === 'send') {
        $phone = portalMobile((string)($_POST['phone'] ?? ''));
        if (!$phone) $error = 'Enter a valid UK mobile number to continue.';
        else {
            $_SESSION['demo_phone'] = $phone;
            $_SESSION['demo_screen'] = 'code';
            $notice = 'Demo only: no SMS was sent. Enter 123456 to test the next screen.';
        }
    } elseif ($action === 'verify') {
        if ($_SESSION['demo_screen'] !== 'code' || !hash_equals('123456',(string)($_POST['code'] ?? ''))) {
            $error = 'That demo code is incorrect. Use 123456.';
        } else {
            $_SESSION['demo_screen'] = 'account';
        }
    }
}

$isDemo = true;
$pending = $_SESSION['demo_screen'] === 'code';
$customer = null;
$account = null;
$orders = [];
$firstName = '';
if ($_SESSION['demo_screen'] === 'account') {
    // All fields in this view are purely invented demonstration data.
    $customer = ['id'=>999901,'name'=>'Alex Morgan','phone'=>'07123 456789',
        'address'=>"12 Example Lane\nSampletown\nAB1 2CD",'archived'=>0];
    $account = ['id'=>999901,'customer_id'=>999901,'phone_e164'=>'+447123456789','active'=>1];
    $orders = [
        [
          'id'=>104,'customer'=>'Alex Morgan','phone'=>'07123 456789',
          'created'=>'2026-10-21T12:30:00Z','status'=>'New','delivery_date'=>'',
          'scheduled_delivery_date'=>'2026-10-27','delivery_charge'=>0,'total'=>4500,'paid'=>0,
          'lines'=>[['name'=>'Sample product A','presentation'=>'Cartridge','price'=>4500,'quantity'=>1]]
        ],
        [
          'id'=>103,'customer'=>'Alex Morgan','phone'=>'07123 456789',
          'created'=>'2026-10-10T09:05:00Z','status'=>'Paid','delivery_date'=>'',
          'scheduled_delivery_date'=>'2026-10-24','delivery_charge'=>350,'total'=>5850,'paid'=>2500,
          'lines'=>[
              ['name'=>'Sample product B','presentation'=>'Vial','price'=>2750,'quantity'=>2],
          ]
        ],
        [
          'id'=>97,'customer'=>'Alex Morgan','phone'=>'07123 456789',
          'created'=>'2026-09-14T10:20:00Z','status'=>'Delivered','delivery_date'=>'2026-09-16',
          'scheduled_delivery_date'=>'2026-09-16','delivery_charge'=>0,'total'=>3900,'paid'=>3900,
          'lines'=>[
              ['name'=>'Sample product C','presentation'=>'Vial','price'=>3500,'quantity'=>1],
              ['name'=>'Sample accessory','presentation'=>'','price'=>400,'quantity'=>1]
          ]
        ],
        [
          'id'=>91,'customer'=>'Alex Morgan','phone'=>'07123 456789',
          'created'=>'2026-08-20T12:00:00Z','status'=>'Cancelled','delivery_date'=>'',
          'scheduled_delivery_date'=>'','delivery_charge'=>0,'total'=>1500,'paid'=>0,
          'lines'=>[['name'=>'Sample accessory','presentation'=>'','price'=>500,'quantity'=>3]]
        ],
    ];
    $firstName = 'Alex';
}
$active = array_values(array_filter($orders, static fn(array $o): bool => !in_array(portalStatus($o), ['Delivered','Cancelled'],true)));

require_once __DIR__.'/catalogue-demo.php';
if($customer) pcdHandle($customer,false,true);
define('ANKH_PORTAL_VIEW',true);
require __DIR__.'/view.php';

