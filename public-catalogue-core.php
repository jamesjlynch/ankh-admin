<?php
declare(strict_types=1);
/**
 * The public read-only product index mirrors existing customer catalogue visibility.
 * Historical public-review records are retained for audit compatibility but are
 * not an additional publishing prerequisite. Product legal classification still
 * requires a separate professional assessment; no web flag authorises medicines.
 */
function ankhPublicReviewInit(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS catalogue_public_reviews (
        product_id INTEGER PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
        public_enabled INTEGER NOT NULL DEFAULT 0 CHECK(public_enabled IN (0,1)),
        reviewed_by TEXT NOT NULL DEFAULT "",
        review_basis TEXT NOT NULL DEFAULT "",
        reviewed_at TEXT NOT NULL DEFAULT "",
        updated_at TEXT NOT NULL
    )');
}
function ankhPublicReviewRows(PDO $db): array {
    if (!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='catalogue_public_reviews'")->fetchColumn()) return [];
    $out=[];
    foreach ($db->query('SELECT product_id,public_enabled,reviewed_by,review_basis,reviewed_at FROM catalogue_public_reviews') as $row) {
        $out[(int)$row['product_id']]=$row;
    }
    return $out;
}
function ankhPublicReviewSave(PDO $db,int $productId,string $decision,array $input):void {
    if ($productId<1 || !in_array($decision,['approve','hide'],true)) throw new InvalidArgumentException('Invalid public listing decision.');
    $q=$db->prepare('SELECT id,active,name FROM products WHERE id=?');$q->execute([$productId]);
    $product=$q->fetch(PDO::FETCH_ASSOC);
    if (!$product) throw new InvalidArgumentException('The product does not exist.');
    ankhPublicReviewInit($db);
    $now=gmdate(DATE_ATOM);
    if ($decision==='hide') {
        $db->prepare('UPDATE catalogue_public_reviews SET public_enabled=0,updated_at=? WHERE product_id=?')->execute([$now,$productId]);
        return;
    }
    if ((int)$product['active']!==1) throw new InvalidArgumentException('Only active products can be approved for public technical display.');
    $name=trim((string)($input['reviewed_by']??''));
    $basis=trim((string)($input['review_basis']??''));
    if (mb_strlen($name)>120 || mb_strlen($basis)>3000 || mb_strlen($name)<3 || mb_strlen($basis)<80)
        throw new InvalidArgumentException('Record the reviewer and a meaningful review reference (at least 80 characters).');
    if (($input['checked_classification']??'')!=='1'||($input['checked_advertising']??'')!=='1'||($input['checked_documents']??'')!=='1')
        throw new InvalidArgumentException('Complete all external legal and documentation review confirmations before making this product public.');
    $content=$db->prepare('SELECT description FROM customer_catalogue_content WHERE product_id=?');$content->execute([$productId]);
    $description=trim((string)$content->fetchColumn());
    if (strlen($description)<60) throw new InvalidArgumentException('Add a complete factual product overview before public review.');
    $db->prepare('INSERT INTO catalogue_public_reviews(product_id,public_enabled,reviewed_by,review_basis,reviewed_at,updated_at)
        VALUES(?,1,?,?,?,?)
        ON CONFLICT(product_id) DO UPDATE SET public_enabled=1,reviewed_by=excluded.reviewed_by,
        review_basis=excluded.review_basis,reviewed_at=excluded.reviewed_at,updated_at=excluded.updated_at')
        ->execute([$productId,$name,$basis,$now,$now]);
}
/**
 * Reuse precisely the products previously published in the customer catalogue.
 * Public content has no price/stock/order/customer fields and never falls back
 * to all active products if the visibility table or catalogue content is absent.
 * External regulatory review is still required; this is a technical visibility rule.
 */
function ankhPublicCatalogueProducts(PDO $db):array {
    $required=['customer_catalogue_visibility','customer_catalogue_content'];
    $tables=array_flip($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN));
    foreach ($required as $table) if (!isset($tables[$table])) return [];
    $hasInfo=isset($tables['customer_catalogue_information']);
    $sql='SELECT p.id,p.name,c.description,c.image_mime'.
        ($hasInfo?',i.information':'').
        ' FROM products p
          JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1
          LEFT JOIN customer_catalogue_content c ON c.product_id=p.id'.
        ($hasInfo?' LEFT JOIN customer_catalogue_information i ON i.product_id=p.id':'').
        ' WHERE p.active=1
          ORDER BY p.name COLLATE NOCASE';
    $rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['description']=(string)($row['description']??'');
        $row['information']=$hasInfo?json_decode((string)($row['information']??''),true):[];
        if (!is_array($row['information'])) $row['information']=[];
        unset($row['information']['coa_url']); // No automatic claim of batch verification.
        $row['has_photo']=in_array((string)($row['image_mime']??''),['image/png','image/webp','image/jpeg'],true);
        unset($row['image_mime']);
    } unset($row);
    return $rows;
}
function ankhPublicType(string $name): string {
    $n=strtolower($name);
    if (preg_match('/\b(?:pen|cartridge|case|labels?|notebook|envelopes?|starter kit)\b/',$n))return 'Research accessory';
    if (preg_match('/\b(?:water|acetic acid)\b/',$n))return 'Laboratory reagent';
    if (preg_match('/\b(?:stack|blend)\b/',$n))return 'Multi-component research blend';
    return 'Research compound';
}
function ankhPublicNotice():string {
    return 'Technical research information only. Not intended for human or veterinary consumption '
        .'or administration. No medical, therapeutic, dosage, injection or self-treatment instructions '
        .'are provided. No clinical-use approval or medicine licence is claimed. A research-use disclaimer '
        .'does not determine the legal classification of any substance. Published studies describe '
        .'specific experimental materials, not proof that ANKH materials are safe, effective, sterile, '
        .'equivalent to trial products, or suitable for medical use.';
}
