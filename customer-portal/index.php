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
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
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
            if (portalAccountForPhone($portal, $phone)) {
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
?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0b0d0e">
<meta name="description" content="Secure ANKH customer account and order history.">
<link rel="icon" href="./icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="./style.css">
<title><?= $customer ? 'My account' : 'Sign in' ?> · ANKH</title>
</head>
<body>
<div class="ambient ambient-one"></div><div class="ambient ambient-two"></div>
<header class="site-header">
  <a class="brand" href="./" aria-label="ANKH home"><span class="brand-icon">☥</span><span>ANKH<span class="brand-sub">CUSTOMER PORTAL</span></span></a>
  <?php if ($customer): ?>
  <form method="post" class="header-signout"><input type="hidden" name="csrf" value="<?=portalEscape($_SESSION['portal_csrf'])?>"><input type="hidden" name="action" value="logout"><button type="submit">Sign out <span aria-hidden="true">↗</span></button></form>
  <?php else: ?><span class="header-note"><span class="status-pip"></span> PRIVATE ACCESS</span><?php endif; ?>
</header>
<main class="page <?= $customer ? 'account' : 'auth' ?>">
<?php if (!$customer): ?>
  <div class="auth-layout">
    <section class="welcome-copy">
      <span class="eyebrow"><span class="line"></span> YOUR PRIVATE SPACE</span>
      <h1>Everything in<br><em>one place.</em></h1>
      <p>View your order history, track progress and keep your details close. A simpler way to stay connected with ANKH.</p>
      <div class="trust-line"><span class="trust-icon">✳</span><span>Secure sign-in. No password needed.</span></div>
    </section>
    <section class="auth-card">
      <div class="card-top"><span class="card-symbol">☥</span><span class="step-badge"><?= $pending ? 'STEP 02 / 02' : 'STEP 01 / 02' ?></span></div>
      <h2><?= $pending ? 'Check your messages.' : 'Welcome back.' ?></h2>
      <p class="card-intro"><?= $pending ? 'Enter the one-time security code sent to your mobile.' : 'Enter your mobile number to access your ANKH account.' ?></p>
      <?php if ($error): ?><div class="feedback error" role="alert"><?=portalEscape($error)?></div><?php endif; ?>
      <?php if ($notice): ?><div class="feedback info" role="status"><?=portalEscape($notice)?></div><?php endif; ?>
      <?php if ($pending): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=portalEscape($_SESSION['portal_csrf'])?>">
        <input type="hidden" name="action" value="verify">
        <label for="code">Verification code</label>
        <input class="code-input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6,8}" minlength="6" maxlength="8" placeholder="••••••" autofocus required>
        <p class="field-note">Code expires in 10 minutes.</p>
        <button class="primary-button" type="submit">Verify &amp; continue <span>→</span></button>
      </form>
      <form method="post" class="secondary-form">
        <input type="hidden" name="csrf" value="<?=portalEscape($_SESSION['portal_csrf'])?>">
        <input type="hidden" name="action" value="change_phone">
        <button class="text-button" type="submit">Use a different mobile number</button>
      </form>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=portalEscape($_SESSION['portal_csrf'])?>">
        <input type="hidden" name="action" value="send">
        <label for="phone">Mobile number</label>
        <div class="phone-field"><span>🇬🇧 &nbsp;+44</span><input id="phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="07xxx xxxxxx" maxlength="40" required></div>
        <p class="field-note">We’ll send a one-time verification code by SMS.</p>
        <button class="primary-button" type="submit">Continue securely <span>→</span></button>
      </form>
      <?php endif; ?>
      <div class="privacy-note"><span aria-hidden="true">♧</span><p>Only accounts individually approved by ANKH can access order history. Having a mobile number alone does not grant access.</p></div>
    </section>
  </div>
<?php else: ?>
  <div class="account-top">
    <div><span class="eyebrow"><span class="line"></span> YOUR ANKH ACCOUNT</span><h1>Hello, <?=portalEscape((string)$firstName)?><span class="gold-dot">.</span></h1><p>Here’s the latest from your account.</p></div>
    <div class="account-avatar" aria-hidden="true">☥</div>
  </div>
  <?php if ($error): ?><div class="feedback error" role="alert"><?=portalEscape($error)?></div><?php endif; ?>
  <div class="summary-grid">
    <div class="summary-card dark"><span class="summary-label">TOTAL ORDERS</span><strong><?=count($orders)?></strong><span class="summary-bottom">Your full order history</span><span class="summary-decor">◈</span></div>
    <div class="summary-card light"><span class="summary-label">IN PROGRESS</span><strong><?=count($active)?></strong><span class="summary-bottom">Orders not yet delivered</span><span class="summary-decor">↗</span></div>
  </div>
  <div class="section-heading"><div><span class="eyebrow">ORDER HISTORY</span><h2>My orders</h2></div><span class="history-count"><?=count($orders)?> orders</span></div>
  <?php if (!$orders): ?>
    <section class="empty-state"><span>◈</span><h3>No orders here yet</h3><p>Only orders linked to your approved customer identity will appear here. If something is missing, contact us to review it.</p></section>
  <?php else: ?>
    <div class="orders-list">
      <?php foreach ($orders as $order):
        $status = portalStatus($order);
        $balance = max(0,(int)$order['total'] - (int)$order['paid']);
        $paymentLabel = (int)$order['total']===0 ? 'No payment required' : ($balance===0 ? 'Paid in full' : ((int)$order['paid']>0 ? 'Part paid' : 'Payment due'));
        $statusClass = match($status) { 'Delivered' => 'delivered', 'Cancelled' => 'cancelled', default => 'progress' };
      ?>
        <details class="order-card">
          <summary>
            <span class="order-icon" aria-hidden="true">◈</span>
            <span class="order-main"><span class="order-reference">ANK-<?=str_pad((string)$order['id'],4,'0',STR_PAD_LEFT)?> <span class="sep">·</span> <?=portalEscape(portalDate((string)$order['created']))?></span><strong><?=count($order['lines'])?> item<?=count($order['lines'])===1?'':'s'?> <span class="status-pill <?=$statusClass?>"><?=portalEscape($status)?></span></strong><span class="order-payment"><?=portalEscape($paymentLabel)?></span></span>
            <span class="order-right"><strong><?=portalEscape(portalMoney((int)$order['total']))?></strong><span class="chevron" aria-hidden="true">⌄</span></span>
          </summary>
          <div class="order-details">
            <div class="details-header">ITEMS IN THIS ORDER</div>
            <?php foreach ($order['lines'] as $line): ?>
              <div class="detail-row"><div><strong><?=portalEscape((string)$line['name'])?></strong><?php if(trim((string)$line['presentation'])!==''):?><small><?=portalEscape((string)$line['presentation'])?></small><?php endif;?></div><div><span class="item-qty">×<?= (int)$line['quantity'] ?></span> <strong><?=portalEscape(portalMoney((int)$line['price']*(int)$line['quantity']))?></strong></div></div>
            <?php endforeach; ?>
            <?php if ((int)$order['delivery_charge']>0): ?><div class="detail-row"><span>Delivery</span><strong><?=portalEscape(portalMoney((int)$order['delivery_charge']))?></strong></div><?php endif; ?>
            <div class="detail-total"><span>Order total</span><strong><?=portalEscape(portalMoney((int)$order['total']))?></strong></div>
            <div class="detail-payment"><span><?=portalEscape($paymentLabel)?></span><?php if ($balance>0 && $status!=='Cancelled'): ?><strong><?=portalEscape(portalMoney($balance))?> outstanding</strong><?php endif; ?></div>
            <?php if ($status==='Delivered' && trim((string)$order['delivery_date'])!==''): ?><p class="detail-date">Delivered: <?=portalEscape(portalDate((string)$order['delivery_date']))?></p><?php elseif ($status!=='Delivered' && $status!=='Cancelled' && trim((string)$order['scheduled_delivery_date'])!==''): ?><p class="detail-date">Planned delivery: <?=portalEscape(portalDate((string)$order['scheduled_delivery_date']))?> · Subject to confirmation</p><?php endif; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="account-bottom">
    <section class="help-card"><div class="help-symbol">✳</div><div><span class="eyebrow">NEED A HAND?</span><h3>Something not right?</h3><p>Questions about an order or missing history? Get in touch with our team.</p><a href="mailto:info@ankhpeptides.com?subject=ANKH%20Customer%20Account">Contact ANKH <span>↗</span></a></div></section>
    <section class="details-card"><span class="eyebrow">YOUR DETAILS</span><h3><?=portalEscape((string)$customer['name'])?></h3><div class="saved-detail"><span>Verified mobile</span><strong><?=portalEscape((string)$account['phone_e164'])?></strong></div><div class="saved-detail"><span>Saved delivery details</span><strong><?=trim((string)$customer['address'])!=='' ? nl2br(portalEscape((string)$customer['address'])) : 'Not yet saved'?></strong></div><p>Need to make a change? Contact ANKH to update your account securely.</p></section>
  </div>
<?php endif; ?>
</main>
<footer><span>☥ ANKH</span><span>PRIVATE CUSTOMER EXPERIENCE</span><a href="mailto:info@ankhpeptides.com?subject=Privacy%20enquiry">Privacy &amp; support</a></footer>
</body></html>
