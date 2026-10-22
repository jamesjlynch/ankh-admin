<?php
// Shared rendering template. Real portal and fictional preview have separate controllers.
if (!defined('ANKH_PORTAL_VIEW')) { http_response_code(404); exit; }
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
<?php if (!empty($isStaffPreview)): ?>
<div class="staff-preview-strip" role="note"><strong>STAFF PREVIEW · LIVE DATA</strong><span>Private read-only inspection — SMS customer login is not enabled.</span><a href="./preview.php">Fictional demo</a></div>
<?php endif; ?>
<?php if (!empty($isDemo)): ?>
<div class="demo-strip" role="note"><strong>DEMO PREVIEW</strong><span>Fictional customer and orders. No real SMS or customer data.</span><nav aria-label="Demo screens"><a href="./preview.php?screen=login">Login</a><a href="./preview.php?screen=account">Customer dashboard</a></nav></div>
<?php endif; ?>
<div class="ambient ambient-one"></div><div class="ambient ambient-two"></div>
<header class="site-header">
  <a class="brand" href="<?=!empty($isStaffPreview)?'./preview-live.php':(!empty($isDemo)?'./preview.php':'./')?>" aria-label="ANKH home"><span class="brand-icon">☥</span><span>ANKH<span class="brand-sub">CUSTOMER PORTAL</span></span></a>
  <?php if ($customer || !empty($isStaffPreview)): ?>
  <form method="post" action="<?=!empty($isStaffPreview)?'./preview-live.php':'./'?>" class="header-signout"><input type="hidden" name="csrf" value="<?=portalEscape($_SESSION['portal_csrf'])?>"><input type="hidden" name="action" value="logout"><button type="submit"><?=!empty($isStaffPreview)?'Exit staff preview':'Sign out'?> <span aria-hidden="true">↗</span></button></form>
  <?php else: ?><span class="header-note"><span class="status-pip"></span> PRIVATE ACCESS</span><?php endif; ?>
</header>
<main class="page <?= $customer ? 'account' : 'auth' ?>">
<?php if (!empty($isStaffPreview)): ?>
<section class="staff-customer-search">
  <div><span class="eyebrow">PRIVATE VIEW · READ ONLY</span><h2>Choose a customer</h2><p>Find a real customer to check exactly which existing orders would appear on their dashboard.</p></div>
  <form method="get" action="./preview-live.php" class="staff-search-form"><label for="customer_search">Search name or phone</label><div><input id="customer_search" type="search" name="q" value="<?=portalEscape((string)$search)?>" placeholder="Customer name or mobile" maxlength="80"><button type="submit">Find</button></div></form>
  <?php if (!empty($results)): ?><div class="staff-customer-list" role="list">
  <?php foreach($results as $previewRow): ?>
   <a role="listitem" href="./preview-live.php?customer_id=<?=(int)$previewRow['id']?>&amp;q=<?=rawurlencode((string)$search)?>" class="<?=($customer && (int)$customer['id']===(int)$previewRow['id'])?'selected':''?>"><span><?=portalEscape((string)$previewRow['name'])?></span><small>#<?=(int)$previewRow['id']?> · Mobile ending <?=portalEscape(substr(preg_replace('/[^0-9]/','',(string)$previewRow['phone']),-4) ?: '—')?></small><span aria-hidden="true">→</span></a>
  <?php endforeach; ?></div><?php else: ?><p class="staff-empty">No matching customers.</p><?php endif; ?>
</section>
<?php if ($notice): ?><p class="feedback info" role="status"><?=portalEscape($notice)?></p><?php endif; ?>
<?php endif; ?>
<?php if (!empty($isStaffPreview) && !$customer): ?>
<section class="staff-empty-page"><span>☥</span><h1>Select a customer above</h1><p>You'll see their real order history, dates, products, payment statuses and delivery progress without sending them any messages or changing records.</p></section>
<?php elseif (!$customer): ?>
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
