<?php
declare(strict_types=1);
/** Shared rules for the unrestricted accessory catalogue. No product data is rewritten. */
function ankhCatalogueKind(string $name): ?string {
    $name=strtolower(trim(preg_replace('/\s+/u',' ',$name)??''));
    // An explicit accessory list, never a default that exposes unknown products.
    $allowed=[
        'case'=>'Storage','carry case'=>'Storage','storage case'=>'Storage','everyday carry case'=>'Storage',
        'desk organiser'=>'Storage','desk organizer'=>'Storage','storage organiser'=>'Storage',
        'blank label sheets'=>'Stationery','label sheets'=>'Stationery','labels'=>'Stationery',
        'notebook'=>'Stationery','pocket notebook'=>'Stationery',
    ];
    return $allowed[$name]??null;
}
function ankhCatalogueInit(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS customer_catalogue_visibility (
        product_id INTEGER PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
        visible INTEGER NOT NULL DEFAULT 0 CHECK(visible IN (0,1)),
        updated_at TEXT NOT NULL
    )');
}
function ankhCatalogueVisibility(PDO $db): array {
    if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_catalogue_visibility'")->fetchColumn())return [];
    return $db->query('SELECT product_id,visible FROM customer_catalogue_visibility')->fetchAll(PDO::FETCH_KEY_PAIR);
}
function ankhCatalogueSetVisibility(PDO $db,int $id,bool $visible): void {
    $q=$db->prepare('SELECT id,name FROM products WHERE id=?');$q->execute([$id]);$p=$q->fetch(PDO::FETCH_ASSOC);
    if(!$p)throw new InvalidArgumentException('Product could not be found.');
    if($visible && ankhCatalogueKind((string)$p['name'])===null)throw new InvalidArgumentException('Customer catalogue visibility is available only for the supported unrestricted accessories.');
    ankhCatalogueInit($db);
    $db->prepare('INSERT INTO customer_catalogue_visibility(product_id,visible,updated_at) VALUES (?,?,?) ON CONFLICT(product_id) DO UPDATE SET visible=excluded.visible,updated_at=excluded.updated_at')->execute([$id,$visible?1:0,gmdate('c')]);
}
function ankhCatalogueProducts(PDO $db,int $customerId): array {
    $visibility=ankhCatalogueVisibility($db);if(!$visibility)return [];
    $pricing='standard';
    $customerColumns=array_column($db->query('PRAGMA table_info(customers)')->fetchAll(PDO::FETCH_ASSOC),'name');
    if(in_array('pricing_type',$customerColumns,true)){
        $q=$db->prepare('SELECT pricing_type FROM customers WHERE id=? AND archived=0');$q->execute([$customerId]);$pricing=(string)($q->fetchColumn()?:'standard');
    }
    $out=[];
    foreach($db->query('SELECT * FROM products WHERE active=1 ORDER BY name COLLATE NOCASE') as $p){
        $category=ankhCatalogueKind((string)$p['name']);
        if($category===null || (int)($visibility[(int)$p['id']]??0)!==1)continue;
        $price=(int)$p['price'];
        if($pricing==='family_friends' && isset($p['family_friends_price']))$price=(int)$p['family_friends_price'];
        if($pricing==='free')$price=0;
        $tracked=(int)($p['stock_tracking']??0)===1;
        // Explicit public fields: never return costs, supplier data or fridge ownership.
        $out[]=['id'=>(int)$p['id'],'name'=>(string)$p['name'],'description'=>(string)($p['description']??''),'category'=>$category,'price'=>max(0,$price),'pricing'=>$pricing,'available_qty'=>$tracked?max(0,(int)($p['stock_qty']??0)):null];
    }
    return $out;
}
function ankhCatalogueAdminControl(array $p,array $visibility): void {
    $eligible=ankhCatalogueKind((string)$p['name'])!==null;
    $visible=$eligible && (int)($visibility[(int)$p['id']]??0)===1;
    ?><form method="post" class="detail product-settings-form customer-catalogue-control"><?php csrf();?><input type="hidden" name="action" value="product_catalogue_visibility"><input type="hidden" name="return" value="products"><input type="hidden" name="product_id" value="<?=(int)$p['id']?>"><label class="check"><input type="checkbox" name="catalogue_visible" value="1" <?=$visible?'checked':''?> <?=$eligible?'':'disabled'?>> Visible on customer's dashboard</label><p class="muted"><?=$eligible?((int)$p['active']===1?'Only changes catalogue visibility. Product details and stock stay as they are.':'This product is inactive. It will stay hidden from customers until you also make it available for new orders.'):'Not enabled for the customer catalogue. Existing Admin records and order history are unchanged.'?></p><?php if($eligible):?><button class="quiet" type="submit">Save catalogue visibility</button><?php endif;?></form><?php
}
