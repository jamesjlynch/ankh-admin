<?php
declare(strict_types=1);
/**
 * Public catalogue gate. Public product visibility is INDEPENDENT of account
 * catalogue visibility, stock and order availability. Default: no public products.
 * The admin must record an external regulatory/advertising assessment per product.
 * Recording a review is NOT a government authorisation or substitute for advice.
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
function ankhPublicCatalogueProducts(PDO $db):array {
    if (!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='catalogue_public_reviews'")->fetchColumn()) return [];
    if (!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_catalogue_content'")->fetchColumn()) return [];
    $hasInfo=(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_catalogue_information'")->fetchColumn();
    $sql='SELECT p.id,p.name,c.description,c.image_mime,r.reviewed_at'.
        ($hasInfo?',i.information':'').
        ' FROM products p JOIN catalogue_public_reviews r ON r.product_id=p.id
          JOIN customer_catalogue_content c ON c.product_id=p.id'.
        ($hasInfo?' LEFT JOIN customer_catalogue_information i ON i.product_id=p.id':'').
        ' WHERE p.active=1 AND r.public_enabled=1 AND length(trim(r.review_basis))>=80
          AND length(trim(r.reviewed_by))>=3 AND length(trim(c.description))>=60
          ORDER BY p.name COLLATE NOCASE';
    $rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['information']=$hasInfo?json_decode((string)($row['information']??''),true):[];
        if (!is_array($row['information'])) $row['information']=[];
        unset($row['information']['coa_url']); // Do not publicly claim a COA without independent batch verification.
        $row['has_photo']=in_array((string)$row['image_mime'],['image/png','image/webp','image/jpeg'],true);
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
    return 'Technical research information only. Not intended, authorised or supplied as a medicine, '
        .'treatment, dietary supplement or veterinary product. Not for human or animal consumption '
        .'or administration. No dosage, injection, therapeutic or self-treatment instructions are provided. '
        .'Published studies describe specific research materials and conditions, not proof that ANKH products '
        .'are safe, effective, sterile, equivalent to trial products, or suitable for medical use.';
}
