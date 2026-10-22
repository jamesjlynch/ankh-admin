<?php
declare(strict_types=1);
// Private, CLI-only ANKH customer account enrolment.
// No customer can see history until an operator explicitly enables their record.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/customer-portal/lib.php';

if (!getenv('ANKH_PORTAL_CONFIG')) {
    $home = getenv('HOME');
    if (is_string($home) && $home !== '') {
        putenv('ANKH_PORTAL_CONFIG=' . rtrim($home,'/') . '/ankh-customer-portal-config.php');
    }
}
$usage = "Usage:\n  php scripts/customer-portal-accounts.php init\n  php scripts/customer-portal-accounts.php list\n  php scripts/customer-portal-accounts.php grant CUSTOMER_ID\n  php scripts/customer-portal-accounts.php revoke CUSTOMER_ID\n";
$command = $argv[1] ?? '';
if (!in_array($command,['init','list','grant','revoke'],true)) {fwrite(STDERR,$usage);exit(2);}
try {
    $config = portalConfig();
    $path = $config['portal_database_path'];
    if ($command === 'init' && !is_file($path)) {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('Cannot create private directory.');
        $realDirectory = realpath($directory);
        if (!$realDirectory || preg_match('~(?:^|/)public_html(?:/|$)~i',$realDirectory)) {
            throw new RuntimeException('Portal database must be outside public_html.');
        }
        chmod($realDirectory,0700);
        $db = new PDO('sqlite:' . $path,null,null,[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        portalInitDb($db);
        chmod($path,0600);
        echo "Created private customer portal database. No accounts enrolled.\n";
        exit;
    }
    $portal = portalDb($config);
    if ($command === 'init') {
        portalInitDb($portal);
        echo "Private customer portal database already exists; schema checked.\n";
        exit;
    }
    $admin = portalAdminDb($config);
    if ($command === 'list') {
        $q=$portal->query('SELECT customer_id,phone_e164,active,verified_at FROM portal_accounts ORDER BY customer_id');
        foreach ($q as $row) {
            $customerQuery=$admin->prepare('SELECT name FROM customers WHERE id=?');
            $customerQuery->execute([(int)$row['customer_id']]);
            $name=(string)($customerQuery->fetchColumn() ?: '[missing customer]');
            echo '#' . (int)$row['customer_id'] . '  ' . $name . '  ' . $row['phone_e164'] . '  ' . ((int)$row['active']?'ACTIVE':'REVOKED') . '  ' . ($row['verified_at'] ? 'VERIFIED' : 'NOT YET VERIFIED') . "\n";
        }
        exit;
    }
    $id=filter_var($argv[2] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) {fwrite(STDERR,$usage);exit(2);}
    if ($command === 'grant') {
        $q=$admin->prepare('SELECT id,name,phone,archived FROM customers WHERE id=?');
        $q->execute([$id]); $customer=$q->fetch(PDO::FETCH_ASSOC);
        if (!$customer || (int)$customer['archived']) throw new RuntimeException('Customer not found or archived.');
        $mobile=portalMobile((string)$customer['phone']);
        if (!$mobile) throw new RuntimeException('Customer needs a valid UK mobile number in Admin first.');
        // Shared phone numbers cannot access multiple identities, by design.
        $q=$portal->prepare('SELECT customer_id FROM portal_accounts WHERE phone_e164=? AND customer_id<>?');
        $q->execute([$mobile,$id]);
        if ($q->fetchColumn() !== false) throw new RuntimeException('This mobile is linked to a different account. Resolve shared-number identity manually.');
        $q=$portal->prepare('SELECT id,phone_e164,active FROM portal_accounts WHERE customer_id=?');
        $q->execute([$id]); $existing=$q->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $portal->prepare('UPDATE portal_accounts SET phone_e164=?,active=1,verified_at=NULL WHERE customer_id=?')->execute([$mobile,$id]);
        } else {
            $portal->prepare('INSERT INTO portal_accounts(customer_id,phone_e164,active,created_at) VALUES (?,?,1,?)')->execute([$id,$mobile,time()]);
        }
        echo "Granted login to customer #$id (" . $customer['name'] . ") at $mobile. SMS verification still required.\n";
        exit;
    }
    $q=$portal->prepare('UPDATE portal_accounts SET active=0,verified_at=NULL WHERE customer_id=?');
    $q->execute([$id]);
    echo $q->rowCount() ? "Revoked portal access for customer #$id.\n" : "No account exists for customer #$id.\n";
} catch (Throwable $e) {
    fwrite(STDERR,'Customer portal: ' . $e->getMessage() . "\n");
    exit(1);
}
