<?php
declare(strict_types=1);

/**
 * ANKH Customer Portal — isolated public application.
 * The Admin database is opened read-only; portal sessions and enrolments live
 * in a different PRIVATE SQLite database, outside any website document root.
 * Never include the Admin index.php here: it runs migrations and admin code.
 */
function portalConfig(): array {
    $path = getenv('ANKH_PORTAL_CONFIG') ?: dirname(__DIR__, 2) . '/ankh-customer-portal-config.php';
    $config = is_file($path) ? require $path : null;
    if (!is_array($config)) {
        throw new RuntimeException('Customer portal is not configured.');
    }
    foreach (['admin_database_path','portal_database_path','twilio_account_sid','twilio_auth_token','twilio_verify_service_sid'] as $key) {
        if (empty($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException('Customer portal configuration is incomplete.');
        }
    }
    return $config;
}

function portalDb(array $config): PDO {
    $path = $config['portal_database_path'];
    if (!is_file($path)) {
        throw new RuntimeException('Customer access database has not been initialised.');
    }
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
    return $db;
}

function portalAdminDb(array $config): PDO {
    $path = realpath($config['admin_database_path']);
    if (!$path || !is_file($path)) {
        throw new RuntimeException('The order database is unavailable.');
    }
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    // Force the connection read-only. No schema migrations, reservations or updates.
    $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=5000');
    return $db;
}

function portalInitDb(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_accounts (
        id INTEGER PRIMARY KEY,
        auth_version INTEGER NOT NULL DEFAULT 1,
        customer_id INTEGER NOT NULL UNIQUE,
        phone_e164 TEXT NOT NULL UNIQUE,
        active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
        created_at INTEGER NOT NULL,
        verified_at INTEGER DEFAULT NULL
    );
    CREATE TABLE IF NOT EXISTS portal_rate_limits (
        rate_key TEXT PRIMARY KEY,
        attempts INTEGER NOT NULL,
        started_at INTEGER NOT NULL
    );");
    $columns = array_column($db->query('PRAGMA table_info(portal_accounts)')->fetchAll(PDO::FETCH_ASSOC),'name');
    if (!in_array('auth_version',$columns,true)) $db->exec('ALTER TABLE portal_accounts ADD COLUMN auth_version INTEGER NOT NULL DEFAULT 1');
}

function portalMobile(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 40) return null;
    $digits = preg_replace('/[\s().-]+/', '', $raw);
    if (!is_string($digits)) return null;
    if (str_starts_with($digits, '0044')) $digits = '+44' . substr($digits, 4);
    elseif (str_starts_with($digits, '07')) $digits = '+44' . substr($digits, 1);
    elseif (str_starts_with($digits, '447')) $digits = '+' . $digits;
    return preg_match('/^\+447\d{9}$/D', $digits) ? $digits : null;
}

function portalAccountForPhone(PDO $db, string $phone): ?array {
    $q = $db->prepare('SELECT id,customer_id,phone_e164,active,auth_version FROM portal_accounts WHERE phone_e164=? AND active=1');
    $q->execute([$phone]);
    return $q->fetch() ?: null;
}

function portalAccountForId(PDO $db, int $id): ?array {
    $q = $db->prepare('SELECT id,customer_id,phone_e164,active,auth_version FROM portal_accounts WHERE id=? AND active=1');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** Rate limiting on hashed phone/IP, with an atomic SQLite update. */
function portalAllowAttempt(PDO $db, string $key, int $max, int $period): bool {
    $now = time();
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT attempts,started_at FROM portal_rate_limits WHERE rate_key=?');
        $q->execute([$key]);
        $row = $q->fetch();
        if (!$row || (int)$row['started_at'] <= $now - $period) {
            $db->prepare('INSERT INTO portal_rate_limits(rate_key,attempts,started_at) VALUES (?,1,?) ON CONFLICT(rate_key) DO UPDATE SET attempts=1,started_at=excluded.started_at')
                ->execute([$key,$now]);
            $allowed = true;
        } elseif ((int)$row['attempts'] >= $max) {
            $allowed = false;
        } else {
            $db->prepare('UPDATE portal_rate_limits SET attempts=attempts+1 WHERE rate_key=?')->execute([$key]);
            $allowed = true;
        }
        $db->commit();
        return $allowed;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function portalOtpAllowed(PDO $db, string $phone, string $action): bool {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    // Do not trust forwarded IP headers.
    $ipHash = hash('sha256', $ip);
    $phoneHash = hash('sha256', $phone);
    return portalAllowAttempt($db, $action . ':ip:' . $ipHash, $action === 'send' ? 12 : 24, 3600)
        && portalAllowAttempt($db, $action . ':phone:' . $phoneHash, $action === 'send' ? 3 : 8, 600);
}

/** Twilio Verify issues and checks the OTP; no plaintext codes are stored by ANKH. */
function portalTwilio(array $config, string $path, array $data): array {
    if (!extension_loaded('curl')) throw new RuntimeException('SMS service unavailable.');
    $sid = $config['twilio_verify_service_sid'];
    if (!preg_match('/^VA[0-9a-f]{32}$/i', $sid)) throw new RuntimeException('SMS service unavailable.');
    $ch = curl_init('https://verify.twilio.com/v2/Services/' . $sid . '/' . $path);
    if ($ch === false) throw new RuntimeException('SMS service unavailable.');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_USERPWD => $config['twilio_account_sid'] . ':' . $config['twilio_auth_token'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $response = is_string($body) ? json_decode($body, true) : null;
    if ($code < 200 || $code > 299 || !is_array($response)) {
        throw new RuntimeException('SMS verification temporarily unavailable.');
    }
    return $response;
}

function portalSendCode(array $config, string $phone): bool {
    $result = portalTwilio($config, 'Verifications', ['To' => $phone, 'Channel' => 'sms']);
    return ($result['status'] ?? '') === 'pending';
}

function portalVerifyCode(array $config, string $phone, string $code): bool {
    $result = portalTwilio($config, 'VerificationCheck', ['To' => $phone, 'Code' => $code]);
    return ($result['status'] ?? '') === 'approved';
}

/** Only an explicitly enrolled customer is allowed access, never a phone-only match. */
function portalCustomer(PDO $admin, array $account): ?array {
    $q = $admin->prepare('SELECT id,name,phone,address,archived FROM customers WHERE id=?');
    $q->execute([(int)$account['customer_id']]);
    $customer = $q->fetch();
    if (!$customer || (int)$customer['archived'] !== 0) return null;
    if (portalMobile((string)$customer['phone']) !== (string)$account['phone_e164']) return null;
    return $customer;
}

function portalOrderHistory(PDO $admin, array $customer, string $verifiedPhone): array {
    // Legacy Admin orders have NO customer_id. Both exact customer identity
    // AND normalised verified number must match. Never match phone alone.
    $q = $admin->prepare("SELECT id,customer,phone,created,status,delivery_date,
        scheduled_delivery_date,delivery_charge
        FROM orders WHERE lower(trim(customer))=lower(trim(?))
        ORDER BY id DESC LIMIT 1000");
    $q->execute([trim((string)$customer['name'])]);
    $orders = [];
    $items = $admin->prepare('SELECT name,presentation,price,quantity FROM items WHERE order_id=? ORDER BY id ASC');
    $payments = $admin->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id=?');
    foreach ($q as $order) {
        if (portalMobile((string)$order['phone']) !== $verifiedPhone) continue;
        $items->execute([(int)$order['id']]);
        $lines = $items->fetchAll();
        $payments->execute([(int)$order['id']]);
        $paid = (int)$payments->fetchColumn();
        $total = max(0,(int)$order['delivery_charge']);
        foreach ($lines as $line) $total += (int)$line['price'] * (int)$line['quantity'];
        $order['lines'] = $lines;
        $order['total'] = $total;
        $order['paid'] = min($paid,$total);
        $orders[] = $order;
    }
    return $orders;
}

function portalStatus(array $order): string {
    if ($order['status'] === 'Cancelled') return 'Cancelled';
    if (trim((string)$order['delivery_date']) !== '' || in_array($order['status'], ['Delivered','Dispatched'], true)) return 'Delivered';
    if ((string)$order['status'] === 'New') return 'Received';
    return 'In progress';
}

function portalMoney(int $pence): string { return '£' . number_format($pence / 100, 2); }
function portalEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function portalDate(string $date): string {
    try { return (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Europe/London'))->format('j M Y'); }
    catch (Throwable $e) { return 'Date unavailable'; }
}
