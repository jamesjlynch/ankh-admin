<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$app=file_get_contents($root.'/index.php');
$css=file_get_contents($root.'/premium-v2.css');
$publisher=file_get_contents($root.'/scripts/publish-live.sh');
$publisherTest=file_get_contents($root.'/tests/publish-live.sh');
if($app===false||$css===false||$publisher===false||$publisherTest===false){throw new RuntimeException('Required UI and deployment files missing.');}
foreach([
 'body class="<?=$premiumV2Preview?',
 'ankh_premium_v2_preview',
 "'1','0'",
 'premium-v2.css?v=',
 'premium-v2-preview-banner',
 '?view=dashboard&amp;premium=0',
 '<nav class="mobile-nav">',
 'id="ankh-main"'
] as $required){if(!str_contains($app,$required))throw new RuntimeException('Missing optional UI preview wiring: '.$required);}
foreach([
 'body.ankh-premium-v2',
 '.ankh-premium-v2 .mobile-nav',
 '.ankh-premium-v2 .dashboard-stat-card',
 '.ankh-premium-v2 .product-tile',
 '.ankh-premium-v2 .stock-product-card',
 '@media(max-width:760px)',
 '@media(prefers-reduced-motion:reduce)',
 ':focus-visible'
] as $required){if(!str_contains($css,$required))throw new RuntimeException('Missing V2 design foundation rule: '.$required);}
if(str_contains($css,'@import ')||str_contains($css,'url(https://'))throw new RuntimeException('V2 foundation must not add external dependencies.');
if(!str_contains($publisher,'premium-v2.css')||!str_contains($publisherTest,'premium-v2.css'))throw new RuntimeException('Premium UI stylesheet is not deployed and checked.');
echo "PASS: premium V2 is scoped, opt-in, accessible and in the atomic publishing manifest.\n";
