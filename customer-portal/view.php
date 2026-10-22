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
<link rel="stylesheet" href="./account.css?v=collection-6">
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
<details class="staff-switcher" <?php if (!$customer): ?>open<?php endif; ?>><summary><?= $customer ? 'Staff preview · Viewing '.portalEscape((string)$customer['name']).' — switch customer' : 'Choose a customer to preview' ?><span aria-hidden="true">⌄</span></summary><section class="staff-customer-search">
  <div><span class="eyebrow">PRIVATE VIEW · READ ONLY</span><h2>Choose a customer</h2><p>Find a real customer to check exactly which existing orders would appear on their dashboard.</p></div>
  <form method="get" action="./preview-live.php" class="staff-search-form"><label for="customer_search">Search name or phone</label><div><input id="customer_search" type="search" name="q" value="<?=portalEscape((string)$search)?>" placeholder="Customer name or mobile" maxlength="80"><button type="submit">Find</button></div></form>
  <?php if (!empty($results)): ?><div class="staff-customer-list" role="list">
  <?php foreach($results as $previewRow): ?>
   <a role="listitem" href="./preview-live.php?customer_id=<?=(int)$previewRow['id']?>&amp;q=<?=rawurlencode((string)$search)?>" class="<?=($customer && (int)$customer['id']===(int)$previewRow['id'])?'selected':''?>"><span><?=portalEscape((string)$previewRow['name'])?></span><small>#<?=(int)$previewRow['id']?> · Mobile ending <?=portalEscape(substr(preg_replace('/[^0-9]/','',(string)$previewRow['phone']),-4) ?: '—')?></small><span aria-hidden="true">→</span></a>
  <?php endforeach; ?></div><?php else: ?><p class="staff-empty">No matching customers.</p><?php endif; ?>
</section></details>
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
  <?php require __DIR__.'/customer-experience.php'; ?>
<?php endif; ?>
</main>
<footer><span>☥ ANKH</span><span>PRIVATE CUSTOMER EXPERIENCE</span><a href="mailto:info@ankhpeptides.com?subject=Privacy%20enquiry">Privacy &amp; support</a></footer>
</body></html>

