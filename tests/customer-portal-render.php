<?php
declare(strict_types=1);
/**
 * Render-level regression test: public and staff preview share this HTML.
 * This prevents accidental output of server-side PHP source above the UI.
 */
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'order.ankhpeptides.com';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['screen'] = 'account';

ob_start();
require dirname(__DIR__).'/customer-portal/preview.php';
$html=(string)ob_get_clean();
$normalised=ltrim($html);
$must = [
    ['Preview must start with doctype',str_starts_with(strtolower($normalised),'<!doctype html>')],
    ['Exactly one doctype',substr_count(strtolower($html),'<!doctype html>') === 1],
    ['Fictional customer name',str_contains($html,'Alex Morgan')],
    ['Fictional order line',str_contains($html,'Sample product A')],
    ['Demo data safety notice',str_contains($html,'DEMO PREVIEW')],
    ['Rendered app main landmark',str_contains($html,'<main')],
    ['Complete HTML document',str_contains($html,'</html>')],
    ['No stray fallback page',!str_contains($html,'The customer portal is not available right now.')],
    ['No leaked PHP controller code',!str_contains($html,'portalAccountForId(')],
    ['No leaked auth logic',!str_contains($html,'portalOtpAllowed(')],
    ['No raw server-side session code',!str_contains($html,'$_SESSION')],
];
foreach($must as [$label,$pass]) {
    if(!$pass)throw new RuntimeException('Customer preview rendering failed: '.$label);
}
echo "Customer portal HTML render tests passed.\n";
