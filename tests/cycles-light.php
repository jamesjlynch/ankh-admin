<?php
declare(strict_types=1);
// Isolated HTTP fixture for the light Cycles tab. No live data is used.

function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }

function freePort(): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$socket) throw new RuntimeException('Could not reserve a test port: '.$errstr);
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
}

function request(string $url, string $method = 'GET', ?array $post = null, array &$cookies = []): array {
    $headers = ['X-Ankh-Fixture-Auth: yes'];
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $key => $value) $pairs[] = $key.'='.$value;
        $headers[] = 'Cookie: '.implode('; ', $pairs);
    }
    $options = ['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 30,
        'follow_location' => 0,
    ]];
    if ($post !== null) {
        $options['http']['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
        $options['http']['content'] = http_build_query($post);
    }
    $body = @file_get_contents($url, false, stream_context_create($options));
    $status = 0; $location = null;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $match)) $status = (int)$match[1];
        if (stripos($line, 'Set-Cookie:') === 0) {
            $pair = explode(';', trim(substr($line, 11)), 2)[0];
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $cookies[$key] = $value;
            }
        }
        if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
    }
    return [$status, (string)$body, $location];
}

function absoluteUrl(string $location, string $base): string {
    if (preg_match('#^https?://#', $location)) return $location;
    $parts = parse_url($base);
    $root = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    return str_starts_with($location, '/') ? $root.$location : $root.'/'.ltrim($location, './');
}

function fetch(string $url, string $method = 'GET', ?array $post = null, array &$cookies = []): array {
    for ($i = 0; $i < 6; $i++) {
        [$status, $body, $location] = request($url, $method, $post, $cookies);
        if ($status >= 300 && $status < 400 && $location) {
            $url = absoluteUrl($location, $url);
            $method = 'GET'; $post = null;
            continue;
        }
        return [$status, $body, $url];
    }
    throw new RuntimeException('Too many redirects while testing the Cycles tab.');
}

$root = dirname(__DIR__);
$temp = sys_get_temp_dir().'/ankh-cycles-light-'.bin2hex(random_bytes(6));
mkdir($temp, 0777, true);
$db = $temp.'/fixture.sqlite';
$config = $temp.'/config.php';
$seed = $temp.'/seed.php';
file_put_contents($config, '<?php if(($_SERVER[\'HTTP_X_ANKH_FIXTURE_AUTH\']??\'\')===\'yes\'){$_SESSION[\'admin\']=true;$_SESSION[\'last\']=time();}return [\'password_hash\'=>\'fixture-only\',\'database_path\'=>'.var_export($db, true).'];');
$port = freePort();
$host = 'http://127.0.0.1:'.$port;
$env = array_merge(getenv(), [
    'ANKH_ADMIN_CONFIG' => $config,
    'ANKH_TEST_ALLOW_HTTP' => '1',
    'ANKH_TEST_SESSION_PATH' => $temp,
]);
$process = proc_open(
    [PHP_BINARY, '-d', 'session.save_path='.$temp, '-S', '127.0.0.1:'.$port, '-t', $root],
    [1 => ['file', $temp.'/server.log', 'a'], 2 => ['file', $temp.'/server.log', 'a']],
    $pipes, $root, $env
);
if (!is_resource($process)) throw new RuntimeException('Could not start the fixture server.');
$cookies = [];
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(50000);
    }
    check($ready, 'The fixture server did not start.');
    [$status] = fetch($host.'/', 'GET', null, $cookies);
    check($status === 200, 'The fixture did not initialise.');

    file_put_contents($seed, '<?php
$db = new PDO("sqlite:".'.var_export($db, true).');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = gmdate("c");
$insert = $db->prepare("INSERT INTO reta_cycles(customer_name,recipient_name,phone,last_order_id,last_order_date,last_delivery_date,next_due_date,expected_value,expected_cost,expected_profit,cost_missing,product_summary,product_name,presentation,quantity,cycle_days,cycle_weeks,active,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)");
for($i=1;$i<=40;$i++){
  $insert->execute(["Customer ".$i,"Recipient ".$i,"0700000".str_pad((string)$i,3,"0",STR_PAD_LEFT),0,date("Y-m-d"),date("Y-m-d"),date("Y-m-d"),24000,9000,15000,0,"Retatrutide 10mg × 1","Retatrutide 10mg","10mg",1,28,4,$now,$now]);
}
');
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($seed).' 2>&1', $seedOutput, $seedCode);
    check($seedCode === 0, 'Could not seed recurring cycles: '.implode(' ', $seedOutput));

    [$status, $html] = fetch($host.'/?view=plan&tab=cycles', 'GET', null, $cookies);
    check($status === 200, 'The Cycles tab did not render.');
    check(str_contains($html, 'RECURRING CUSTOMERS'), 'The recurring customer list is missing.');
    check(substr_count($html, 'data-cycle-row') === 40, 'Every active cycle needs a compact row.');
    check(str_contains($html, 'Forecast &amp; stock'), 'The forecast group is missing.');
    check(preg_match('/<details class="panel cycle-forecast-details"(?![^>]*\bopen\b)/', $html) === 1, 'Forecast and stock should start collapsed.');
    check(substr_count($html, 'cycle-calendar-card') <= 30, 'Occurrence cards must be capped at 30.');
    check(str_contains($html, 'more occurrence'), 'The capped occurrence list needs an "and N more" note.');
    check(strlen($html) < 250 * 1024, 'The Cycles tab HTML must stay below 250KB; got '.strlen($html).' bytes.');

    $token = preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match) ? $match[1] : '';
    check($token !== '', 'The CSRF token is missing from the Cycles tab.');

    [$status, $body, $finalUrl] = fetch($host.'/?view=reta', 'POST', [
        'csrf' => $token,
        'action' => 'reta_cycle_stop',
        'cycle_id' => '1',
        'reason' => 'Customer no longer wants it',
        'return' => 'reta',
    ], $cookies);
    check($status === 200, 'The stop action did not return a page.');
    check(str_contains($finalUrl, 'view=plan') && str_contains($finalUrl, 'tab=cycles'), 'The stop action must return to Plan > Cycles.');

    $pdo = new PDO('sqlite:'.$db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cycle = $pdo->query('SELECT active,end_reason FROM reta_cycles WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    check((int)$cycle['active'] === 0, 'Stopping a cycle must deactivate it.');
    check((string)$cycle['end_reason'] === 'Customer no longer wants it', 'The stop reason was not saved.');

    [, $cyclesHtml] = fetch($host.'/?view=plan&tab=cycles', 'GET', null, $cookies);
    check(substr_count($cyclesHtml, 'data-cycle-row') === 39, 'The stopped cycle must leave the active list.');
    preg_match_all('/<article class="reta-cycle-row[^"]*"[^>]*>(.*?)<\/article>/s', $cyclesHtml, $activeRows);
    check(!preg_match('/<h3>Recipient 1<\/h3>/', implode(' ', $activeRows[1])), 'The stopped cycle is still shown as active.');
    check(str_contains($cyclesHtml, 'Stopped cycles (1)'), 'The stopped cycle should remain available to resume.');

    [, $todayHtml] = fetch($host.'/?view=dashboard', 'GET', null, $cookies);
    check(str_contains($todayHtml, 'Due for reorder'), 'The Today due list should still render.');
    check(!str_contains($todayHtml, 'Stop recurring for Recipient 1?'), 'The stopped cycle must leave the Today due list.');

    echo 'PASS: 40 cycles render in a light, collapsed layout under 250KB; stopping a cycle deactivates it and removes it from Cycles and Today.'."\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach (glob($temp.'/*') ?: [] as $file) @unlink($file);
    @rmdir($temp);
}
