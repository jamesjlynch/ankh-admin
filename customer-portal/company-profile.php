<?php
declare(strict_types=1);
/**
 * ANKH public company profile. Informational only: no product or medical claims.
 * /about.php and /our-standards.php select the page; neither queries private data.
 */
$page = ($ankhCompanyPage ?? '') === 'standards' ? 'standards' : 'about';
$isStandards = $page === 'standards';
$metaTitle = $isStandards ? 'Our Standards | ANKH Peptides' : 'About ANKH | ANKH Peptides';
$metaDescription = $isStandards
    ? 'Our approach to product identity, supporting documentation, scientific information and responsible publication.'
    : 'Explore ANKH Peptides: UK research supply, industry experience dating back to 2016, and laboratory operations across England and Wales.';
$https = ($_SERVER['HTTPS'] ?? '') === 'on';
$localTest = PHP_SAPI === 'cli-server'
    && getenv('ANKH_TEST_ALLOW_HTTP') === '1'
    && in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
if (!$https && !$localTest && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('HTTPS required.');
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; script-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
if ($https) header('Strict-Transport-Security: max-age=31536000');
?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b0d0d">
<meta name="robots" content="noindex,nofollow">
<meta name="description" content="<?=htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8')?>">
<meta property="og:title" content="<?=htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8')?>">
<meta property="og:description" content="<?=htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8')?>">
<title><?=htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8')?></title>
<link rel="icon" href="./icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="./company-profile.css?v=1">
</head>
<body class="cp-page <?=$isStandards ? 'cp-standards-page' : 'cp-about-page'?>">
<a class="cp-skip" href="#main">Skip to content</a>
<div class="cp-topline"><span>ANKH GLOBAL LTD</span><span>BRITISH RESEARCH REFERENCE &amp; LABORATORY SUPPLIES</span></div>
<header class="cp-header">
  <div class="cp-wrap cp-head">
    <a class="cp-brand" href="./" aria-label="ANKH collection home"><span class="cp-brand-mark" aria-hidden="true">☥</span><span class="cp-brand-text"><strong>ANKH</strong><small>THE RESEARCH COLLECTION</small></span></a>
    <nav class="cp-nav" aria-label="Primary navigation">
      <a href="./">Collection</a>
      <a href="./public-catalogue.php">Research library</a>
      <a href="./about.php" <?=$isStandards ? '' : 'aria-current="page"'?>>About us</a>
      <a href="./our-standards.php" <?=$isStandards ? 'aria-current="page"' : ''?>>Our standards</a>
    </nav>
    <a class="cp-account" href="./?tab=home">My Account <span aria-hidden="true">↗</span></a>
  </div>
</header>
<main id="main">
<?php if (!$isStandards): ?>
  <section class="cp-hero cp-wrap" aria-labelledby="cp-main-title">
    <div class="cp-hero-copy">
      <p class="cp-eyebrow"><span class="cp-line"></span> ANKH / OUR STORY</p>
      <h1 id="cp-main-title">Rooted in experience.<br><em>Driven by progress.</em></h1>
      <p class="cp-lead">ANKH Peptides is an independent British business focused on research peptides, scientific reference information and laboratory accessories. We combine sector experience with a clear, modern approach to product information and customer service.</p>
      <div class="cp-actions">
        <a class="cp-btn" href="./#collection">Explore the collection <span aria-hidden="true">↗</span></a>
        <a class="cp-textlink" href="./our-standards.php">Our approach to standards <span aria-hidden="true">↗</span></a>
      </div>
    </div>
    <div class="cp-hero-art" aria-label="Decorative ANKH emblem and company signature">
      <span class="cp-art-coordinate cp-art-top">ANKH / ENGLAND + WALES</span>
      <div class="cp-art-orbit cp-orbit-one"></div><div class="cp-art-orbit cp-orbit-two"></div>
      <span class="cp-art-ankh" aria-hidden="true">☥</span>
      <span class="cp-art-coordinate cp-art-bottom">SCIENCE <span>·</span> INFORMATION <span>·</span> SERVICE</span>
    </div>
  </section>

  <section class="cp-proofbar" aria-label="Company at a glance">
    <div class="cp-wrap cp-proofgrid">
      <div><span class="cp-proof-main">2016</span><span class="cp-proof-label">Industry experience dating back to</span></div>
      <div><span class="cp-proof-main">UK-based</span><span class="cp-proof-label">Independent British business</span></div>
      <div><span class="cp-proof-main">England + Wales</span><span class="cp-proof-label">Laboratory operations</span></div>
      <div><span class="cp-proof-main">Research-first</span><span class="cp-proof-label">Technical information and laboratory essentials</span></div>
    </div>
  </section>

  <section class="cp-wrap cp-section cp-intro" aria-labelledby="cp-intro-title">
    <div class="cp-section-side"><span class="cp-eyebrow">01 / WHO WE ARE</span><span class="cp-side-rule"></span></div>
    <div class="cp-section-content">
      <h2 id="cp-intro-title">A British business with <em>a long-term view.</em></h2>
      <p>Our involvement in the peptide sector dates back to 2016. That experience has helped shape what matters to us: clearly presented technical information, dependable communication, thoughtful operations and a straightforward customer experience.</p>
      <p>With laboratory operations in England and Wales, ANKH has a distinctly British foundation and an ambition to build a respected, enduring name in research supply.</p>
      <p>We are independently operated and family-owned, but our focus is firmly on the work: creating a professional service that improves as the scientific landscape develops.</p>
    </div>
  </section>

  <section class="cp-wide-panel" aria-labelledby="cp-collection-title">
    <div class="cp-wrap">
      <div class="cp-section-heading"><div><span class="cp-eyebrow">02 / WHAT WE DO</span><h2 id="cp-collection-title">One connected <em>research experience.</em></h2></div><p>The ANKH collection brings together specialist research material profiles, supporting laboratory equipment and useful scientific context.</p></div>
      <div class="cp-cardgrid">
        <article class="cp-info-card"><span class="cp-card-index">01 — COMPOUNDS</span><span class="cp-card-glyph" aria-hidden="true">◇</span><h3>Research peptides</h3><p>Technical profiles for the compounds currently approved for publication in our live research collection, with relevant specifications and scientific context.</p><a href="./#collection">Explore published profiles ↗</a></article>
        <article class="cp-info-card"><span class="cp-card-index">02 — EQUIPMENT</span><span class="cp-card-glyph" aria-hidden="true">▦</span><h3>Laboratory accessories</h3><p>Practical laboratory essentials, preparation materials and storage accessories where listed and available in our current catalogue.</p><a href="./#sf-laboratory-supplies">View laboratory supplies ↗</a></article>
        <article class="cp-info-card"><span class="cp-card-index">03 — KNOWLEDGE</span><span class="cp-card-glyph" aria-hidden="true">⌘</span><h3>Scientific reference</h3><p>Research summaries, literature references and technical limitations presented separately from medical claims or unsupported conclusions.</p><a href="./public-catalogue.php">Open research library ↗</a></article>
      </div>
    </div>
  </section>

  <section class="cp-wrap cp-section cp-feature" aria-labelledby="cp-quality-title">
    <div class="cp-section-side"><span class="cp-eyebrow">03 / OUR APPROACH</span><span class="cp-side-rule"></span></div>
    <div class="cp-section-content">
      <h2 id="cp-quality-title">Clarity isn't an extra.<br><em>It's the starting point.</em></h2>
      <p>Product identity, published specifications and the limits of the available evidence should be easy to find. We are developing a catalogue that puts this information alongside the relevant product record, rather than burying it in marketing language.</p>
      <div class="cp-featurelist">
        <div><span>01</span><div><h3>Information with context</h3><p>Research profiles distinguish experimental literature from established clinical evidence.</p></div></div>
        <div><span>02</span><div><h3>Documentation with status</h3><p>Where reviewed batch records or other technical documents are available, their scope and status should be clear. A document is not a blanket quality guarantee.</p></div></div>
        <div><span>03</span><div><h3>Responsible publication</h3><p>Our public collection reflects the products enabled for display in ANKH's existing catalogue controls.</p></div></div>
      </div>
      <a class="cp-textlink cp-feature-link" href="./our-standards.php">Read our standards &amp; documentation approach ↗</a>
    </div>
  </section>

  <section class="cp-wrap cp-digital" aria-labelledby="cp-digital-title">
    <span class="cp-eyebrow">04 / BUILT FOR THE FUTURE</span>
    <div class="cp-digital-grid"><div><h2 id="cp-digital-title">Traditional experience.<br><em>Modern infrastructure.</em></h2><p>Our platform is designed to make product research and existing customer relationships easier to manage, from searchable technical profiles to private account records and practical stock-tracking tools.</p></div><div class="cp-digital-mark" aria-hidden="true"><span>ANKH</span><span>CONNECTED EXPERIENCE</span><span>01 / DISCOVER<br>02 / UNDERSTAND<br>03 / MANAGE</span></div></div>
  </section>
<?php else: ?>
  <section class="cp-hero cp-hero-standards cp-wrap" aria-labelledby="cp-main-title">
    <div class="cp-hero-copy">
      <p class="cp-eyebrow"><span class="cp-line"></span> ANKH / OUR STANDARDS</p>
      <h1 id="cp-main-title">Proof over promises.<br><em>Detail over hype.</em></h1>
      <p class="cp-lead">Scientific materials deserve clear identities, accurate descriptions and honest documentation. Our standards page explains what we publish, what documents can demonstrate, and where the limits lie.</p>
      <div class="cp-actions"><a class="cp-btn" href="./public-catalogue.php">Research library <span aria-hidden="true">↗</span></a><a class="cp-textlink" href="./about.php">About ANKH <span aria-hidden="true">↗</span></a></div>
    </div>
    <div class="cp-hero-art cp-standards-art" aria-label="Decorative ANKH documentation emblem">
      <span class="cp-art-coordinate cp-art-top">IDENTITY / EVIDENCE / TRANSPARENCY</span>
      <div class="cp-art-orbit cp-orbit-one"></div><div class="cp-art-orbit cp-orbit-two"></div>
      <span class="cp-art-ankh" aria-hidden="true">☥</span>
      <span class="cp-art-coordinate cp-art-bottom">KNOW WHAT THE RECORD SHOWS</span>
    </div>
  </section>

  <section class="cp-proofbar" aria-label="Principles"><div class="cp-wrap cp-proofgrid">
    <div><span class="cp-proof-main">01</span><span class="cp-proof-label">Clear product identity</span></div>
    <div><span class="cp-proof-main">02</span><span class="cp-proof-label">Documented evidence status</span></div>
    <div><span class="cp-proof-main">03</span><span class="cp-proof-label">Controlled public catalogue</span></div>
    <div><span class="cp-proof-main">04</span><span class="cp-proof-label">Responsible research information</span></div>
  </div></section>

  <section class="cp-wrap cp-section cp-intro" aria-labelledby="cp-standard-title">
    <div class="cp-section-side"><span class="cp-eyebrow">01 / THE PRINCIPLE</span><span class="cp-side-rule"></span></div>
    <div class="cp-section-content">
      <h2 id="cp-standard-title">A claim is only as strong as <em>the evidence behind it.</em></h2>
      <p>We want ANKH's public information to be specific and verifiable. A technical profile, laboratory safety document or research citation has a defined purpose; none should be presented as proof of something it did not assess.</p>
      <p>Our aim is to explain material identity, specifications and supporting records without implying universal laboratory certification, guaranteed purity, clinical efficacy or medical suitability.</p>
    </div>
  </section>

  <section class="cp-wide-panel" aria-labelledby="cp-pillar-title"><div class="cp-wrap">
    <div class="cp-section-heading"><div><span class="cp-eyebrow">02 / OUR FRAMEWORK</span><h2 id="cp-pillar-title">Four principles.<br><em>One consistent approach.</em></h2></div><p>A practical framework for how we present and manage public research information.</p></div>
    <div class="cp-cardgrid cp-fourcards">
      <article class="cp-info-card"><span class="cp-card-index">01 — IDENTIFY</span><h3>Product identity</h3><p>Name, stated form, strength and available specifications should be identifiable on the relevant listing. Molecular reference data is distinguished from batch-specific test results.</p></article>
      <article class="cp-info-card"><span class="cp-card-index">02 — DOCUMENT</span><h3>Evidence &amp; records</h3><p>We distinguish research literature, specifications, safety data and any analytical certificates. Where a document is unavailable or unverified, that status should not be disguised.</p></article>
      <article class="cp-info-card"><span class="cp-card-index">03 — REVIEW</span><h3>Publication controls</h3><p>Our public collection uses the active products specifically enabled in the ANKH catalogue. Publication of a profile is not a medicine authorisation or approval to supply it.</p></article>
      <article class="cp-info-card"><span class="cp-card-index">04 — COMMUNICATE</span><h3>Clear limitations</h3><p>Research findings are described in context, with no promise of outcomes, treatment effects or human or veterinary suitability.</p></article>
    </div>
  </div></section>

  <section class="cp-wrap cp-section cp-feature" aria-labelledby="cp-coa-title">
    <div class="cp-section-side"><span class="cp-eyebrow">03 / DOCUMENTATION</span><span class="cp-side-rule"></span></div>
    <div class="cp-section-content">
      <h2 id="cp-coa-title">Understanding the <em>paperwork.</em></h2>
      <p>Not every product profile has a verified, batch-matched Certificate of Analysis (COA). We do not describe a research summary, an SDS or a supplier's generic specification as independent proof of the contents of an individual batch.</p>
      <div class="cp-document-grid">
        <article><span>DOCUMENT 01</span><h3>Certificate of Analysis</h3><p>When available and authentic, a COA should identify the batch, tests conducted, method or laboratory, and reported results. It verifies only the tests and sample covered by that certificate.</p></article>
        <article><span>DOCUMENT 02</span><h3>Safety Data Sheet</h3><p>An SDS provides hazard and handling information for its identified material. It is not a certificate of purity, sterility or clinical safety.</p></article>
        <article><span>DOCUMENT 03</span><h3>Scientific literature</h3><p>Published studies help explain research context. They do not authenticate the identity or quality of stock in a particular vial or packet.</p></article>
      </div>
      <p class="cp-caution"><strong>Important distinction:</strong> We do not claim GMP manufacture, ISO accreditation, independent testing, sterility or a specific purity percentage unless supporting evidence exists for the exact product and relevant scope.</p>
    </div>
  </section>

  <section class="cp-wrap cp-section cp-feature cp-standard-last" aria-labelledby="cp-responsibility-title">
    <div class="cp-section-side"><span class="cp-eyebrow">04 / RESPONSIBILITY</span><span class="cp-side-rule"></span></div>
    <div class="cp-section-content">
      <h2 id="cp-responsibility-title">Research use comes with <em>clear boundaries.</em></h2>
      <p>Scientific reference profiles are informational. Research compounds are not presented as medicines, and their inclusion in a public catalogue does not determine their UK legal classification, authorise their sale, or establish that they are suitable for human or veterinary use.</p>
      <p>Product classification and marketing obligations depend on the substance, its presentation and the applicable UK requirements. Customers should not interpret any laboratory document or research citation as dosing advice or a medical recommendation.</p>
      <div class="cp-actions"><a class="cp-textlink" href="https://www.gov.uk/guidance/borderline-products-how-to-tell-if-your-product-is-a-medicine" target="_blank" rel="noopener noreferrer">Read MHRA classification guidance ↗</a></div>
    </div>
  </section>
<?php endif; ?>
  <section class="cp-bottomcta">
    <div class="cp-wrap cp-bottominner">
      <div><span class="cp-eyebrow">THE NEXT STEP</span><h2>Explore ANKH.<br><em>With the details in view.</em></h2><p>Browse the live collection of published product profiles, or contact us about documentation, accounts and existing orders.</p></div>
      <div class="cp-cta-buttons"><a class="cp-btn" href="./#collection">Explore the collection ↗</a><a class="cp-textlink" href="mailto:info@ankhpeptides.com">Contact ANKH ↗</a></div>
    </div>
  </section>
</main>
<footer class="cp-footer">
  <div class="cp-wrap cp-footer-inner">
    <div><a class="cp-footer-logo" href="./"><span>☥</span> ANKH</a><p>ANKH GLOBAL LTD · Research information and laboratory supplies. Not intended for human or veterinary administration. No medical approval is claimed.</p></div>
    <div><strong>Explore</strong><a href="./">Collection</a><a href="./public-catalogue.php">Research library</a><a href="./about.php">About ANKH</a><a href="./our-standards.php">Our standards</a></div>
    <div><strong>Support</strong><a href="./?tab=home">My Account</a><a href="mailto:info@ankhpeptides.com">Email our team</a></div>
  </div>
  <div class="cp-wrap cp-copyright"><span>© <?=date('Y')?> ANKH GLOBAL LTD</span><span>BRITISH ROOTS. SCIENTIFIC FOCUS.</span></div>
</footer>
</body>
</html>
