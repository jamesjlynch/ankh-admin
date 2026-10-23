<?php
declare(strict_types=1);
/**
 * Populate only missing customer-catalogue descriptions and research fields.
 * Never writes to products, orders, stock, prices, customer data, photos or visibility.
 *
 * Usage: php scripts/backfill-product-research.php --dry-run
 *        php scripts/backfill-product-research.php --apply
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--dry-run','--apply'], true)) {
    fwrite(STDERR, "Usage: php scripts/backfill-product-research.php --dry-run|--apply\n");
    exit(2);
}
$catalogFile = dirname(__DIR__) . '/catalogue-research.json';
if (!is_file($catalogFile)) { fwrite(STDERR, "Missing curated catalogue research data.\n"); exit(2); }
$data = json_decode((string)file_get_contents($catalogFile), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($data['profiles'] ?? null)) { fwrite(STDERR, "Invalid profile list.\n"); exit(2); }
$configFile = getenv('ANKH_ADMIN_CONFIG') ?: '/home/devcouk/ankh-admin-config.php';
if (!is_file($configFile)) { fwrite(STDERR, "Private ANKH configuration not available.\n"); exit(2); }
$config = require $configFile;
$database = is_array($config) ? ($config['database_path'] ?? '') : '';
if (!is_string($database) || !is_file($database)) {
    fwrite(STDERR, "Live SQLite database not available.\n"); exit(2);
}
$db = new PDO('sqlite:' . $database, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000');
if (!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='products'")->fetchColumn()) {
    fwrite(STDERR, "Products table missing.\n"); exit(2);
}
function researchNormalise(string $name): string {
    return trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[^a-z0-9]+/', ' ', strtolower($name))));
}
function researchProfile(string $name, array $profiles): ?array {
    $normalised = researchNormalise($name);
    $matched = [];
    foreach ($profiles as $profile) {
        foreach ($profile['aliases'] ?? [] as $alias) {
            $term = researchNormalise((string)$alias);
            if ($term !== '' && ($normalised === $term || str_starts_with($normalised, $term.' '))) {
                $matched[] = ['length'=>strlen($term), 'profile'=>$profile];
            }
        }
    }
    if (!$matched) return null;
    usort($matched, static fn(array $a,array $b):int => $b['length'] <=> $a['length']);
    return $matched[0]['profile'];
}
function researchDetails(string $name, array $profile): array {
    $info = [];
    if (preg_match('/\b(\d+(?:\.\d+)?)\s*(mg|ml)\b/i', $name, $m)) {
        $info['strength'] = $m[1] . ' ' . strtolower($m[2]);
    }
    foreach (($profile['specifications'] ?? []) as $field => $value) {
        if (is_string($value) && trim($value) !== '') $info[(string)$field] = trim($value);
    }
    $key = (string)($profile['key'] ?? '');
    $category = strtolower($info['peptide_type'] ?? '');
    $accessory = in_array($key,['pen','case','cartridge'],true);
    $diluent = in_array($key,['bacwater','aceticacid'],true);
    $blend = in_array($key,['blendglow','blendwolverine'],true);
    if (!$accessory && !$diluent && !$blend) {
        $info['storage'] = 'Lyophilised research material: store sealed, dry and shielded from UV light. A temperature such as -20°C should be used only when supported by this batch’s supplier stability documentation.';
        $info['prepared_storage'] = 'After laboratory preparation, compatibility and stability depend on solvent, concentration, pH and handling. Do not assume a 2–6°C shelf life without validated compound-specific data.';
        $info['precautions'] = 'For laboratory research only. Product identity, assay, endotoxin and sterility status must be confirmed against actual lot records. Not an instruction for human or veterinary administration.';
    } elseif ($blend) {
        $info['storage'] = 'Storage conditions depend on the verified components and formulation; check the batch documentation.';
        $info['prepared_storage'] = 'Mixture stability after preparation is not established without component-specific compatibility testing.';
        $info['precautions'] = 'Check the full verified ingredient list and quantities; a blend name is not proof of composition.';
    } else {
        $info['storage'] = 'Follow the actual manufacturer's packaging and storage requirements.';
        $info['prepared_storage'] = 'Not applicable as a peptide reconstitution instruction; consult the manufacturer for any prepared-system handling.';
        $info['precautions'] = $info['precautions'] ?? 'Use according to the exact product label and supplier safety/technical documentation.';
    }
    $info['research_focus'] = trim((string)($profile['research_focus'] ?? ''));
    $info['research'] = trim((string)($profile['research'] ?? ''));
    $info['applications'] = $info['research_focus'];
    if (!empty($profile['references']) && is_array($profile['references'])) {
        $info['references'] = implode("\n", array_values(array_filter($profile['references'], 'is_string')));
    }
    $info['faq'] = "What is this product?\n" . trim((string)($profile['overview'] ?? '')) .
        "\n\nDoes published research establish the quality of this batch?\nNo. Identity, purity, sterility and stability are batch-specific and require supplier documentation.";
    return array_filter($info, static fn($v) => is_string($v) && trim($v) !== '');
}
function researchLimit(string $field, string $value): bool {
    $limit = in_array($field,['research','applications','references','precautions','faq'],true) ? 5000 : 600;
    return preg_match('//u',$value) === 1 && preg_match_all('/./us',$value) <= $limit;
}
$products = $db->query("SELECT id,name FROM products WHERE active=1 ORDER BY name COLLATE NOCASE")->fetchAll();
$existingContent = [];
if ($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_catalogue_content'")->fetchColumn()) {
    foreach ($db->query('SELECT product_id,description FROM customer_catalogue_content') as $row) {
        $existingContent[(int)$row['product_id']] = (string)$row['description'];
    }
}
$existingInformation = [];
if ($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_catalogue_information'")->fetchColumn()) {
    foreach ($db->query('SELECT product_id,information FROM customer_catalogue_information') as $row) {
        $decoded = json_decode((string)$row['information'],true);
        if (is_array($decoded)) $existingInformation[(int)$row['product_id']] = $decoded;
    }
}
$updates=[]; $unmatched=[]; $unchanged=0;
foreach ($products as $p) {
    $id=(int)$p['id']; $name=(string)$p['name'];
    $profile=researchProfile($name,$data['profiles']);
    if ($profile===null) { $unmatched[]=$name; continue; }
    $oldDesc=trim($existingContent[$id]??'');
    $newDesc=$oldDesc!=='' ? $oldDesc : trim((string)($profile['overview']??''));
    $oldInfo=$existingInformation[$id]??[];
    $newInfo=$oldInfo;
    foreach (researchDetails($name,$profile) as $field=>$value) {
        if (isset($newInfo[$field]) && trim((string)$newInfo[$field])!=='') continue;
        if (!researchLimit($field,$value)) {throw new RuntimeException("Curated field exceeds allowed length: ".$field);}
        $newInfo[$field]=$value;
    }
    if ($newDesc===$oldDesc && $newInfo===$oldInfo) { $unchanged++; continue; }
    $updates[]=['id'=>$id,'name'=>$name,'description'=>$newDesc,'description_changed'=>$newDesc!==$oldDesc,'information'=>$newInfo,'information_changed'=>$newInfo!==$oldInfo];
}
echo "Active products: ".count($products)."; metadata updates: ".count($updates)."; already complete: ".$unchanged."; unmatched: ".count($unmatched)."\n";
foreach ($updates as $entry) echo ($mode==='--dry-run'?'WOULD FILL: ':'FILL: ') . $entry['name']."\n";
foreach ($unmatched as $name) echo "NEEDS REVIEW (unmapped): ".$name."\n";
if ($mode==='--dry-run' || !$updates) exit(0);

// Consistent private backup before writes; abort if backup is not possible.
$backupDir=dirname(realpath($configFile)).'/ankh-admin-backups';
if (is_link($backupDir) || (!is_dir($backupDir) && !mkdir($backupDir,0700,true))) {
    throw new RuntimeException('Refusing to run without private backup directory.');
}
$backupFile=$backupDir.'/research-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
$db->exec('VACUUM INTO '.$db->quote($backupFile));
chmod($backupFile,0600);
$backupCheck = new PDO('sqlite:'.$backupFile);
if ($backupCheck->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
    throw new RuntimeException('Pre-update backup failed integrity check.');
}
$backupCheck = null;
echo "Private verified backup created.\n";
$before=hash('sha256',json_encode($db->query('SELECT * FROM products ORDER BY id')->fetchAll(),JSON_THROW_ON_ERROR));
$db->exec('BEGIN IMMEDIATE');
try {
    $db->exec('CREATE TABLE IF NOT EXISTS customer_catalogue_content (
        product_id INTEGER PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
        description TEXT NOT NULL DEFAULT "", image_data BLOB, image_mime TEXT NOT NULL DEFAULT "",
        image_width INTEGER NOT NULL DEFAULT 0, image_height INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS customer_catalogue_information (
        product_id INTEGER PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
        information TEXT NOT NULL, updated_at TEXT NOT NULL
    )');
    $writeContent=$db->prepare('INSERT INTO customer_catalogue_content(product_id,description,updated_at) VALUES(?,?,?)
        ON CONFLICT(product_id) DO UPDATE SET description=excluded.description, updated_at=excluded.updated_at');
    $writeInfo=$db->prepare('INSERT INTO customer_catalogue_information(product_id,information,updated_at) VALUES(?,?,?)
        ON CONFLICT(product_id) DO UPDATE SET information=excluded.information, updated_at=excluded.updated_at');
    $stamp=gmdate('c');
    foreach ($updates as $entry) {
        if ($entry['description_changed']) $writeContent->execute([$entry['id'],$entry['description'],$stamp]);
        if ($entry['information_changed']) $writeInfo->execute([
            $entry['id'],json_encode($entry['information'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$stamp
        ]);
    }
    $after=hash('sha256',json_encode($db->query('SELECT * FROM products ORDER BY id')->fetchAll(),JSON_THROW_ON_ERROR));
    if (!hash_equals($before,$after)) throw new RuntimeException('Product names, pricing or inventory were unexpectedly modified.');
    $db->exec('COMMIT');
    echo "SUCCESS: Updated only empty catalogue text fields; names, prices, stock and images unchanged.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->exec('ROLLBACK');
    throw $e;
}
