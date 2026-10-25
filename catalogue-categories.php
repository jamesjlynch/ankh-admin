<?php
declare(strict_types=1);
/** ANKH Admin source-of-truth for customer catalogue categories.
 * Categories don't confer legal marketing or checkout approval.
 * Newly created products always start invisible on the customer website.
 */
function ankhCategoriesInit(PDO $db):void{
    $db->exec("CREATE TABLE IF NOT EXISTS catalogue_categories(
       id INTEGER PRIMARY KEY AUTOINCREMENT,
       name TEXT NOT NULL COLLATE NOCASE UNIQUE,
       kind TEXT NOT NULL DEFAULT 'research' CHECK(kind IN ('research','accessory')),
       created_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS catalogue_product_categories(
       product_id INTEGER PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
       category_id INTEGER NOT NULL REFERENCES catalogue_categories(id) ON DELETE RESTRICT,
       updated_at TEXT NOT NULL
    );");
    $ins=$db->prepare('INSERT OR IGNORE INTO catalogue_categories(name,kind,created_at) VALUES(?,?,?)');
    foreach(['Laboratory Accessories','Petri Dishes','Laboratory Consumables','Laboratory Equipment'] as $name)
        $ins->execute([$name,'accessory',gmdate('c')]);
}
function ankhCategories(PDO $db):array{
    ankhCategoriesInit($db);
    return $db->query("SELECT c.id,c.name,c.kind,COUNT(m.product_id) AS product_count
        FROM catalogue_categories c
        LEFT JOIN catalogue_product_categories m ON m.category_id=c.id
        GROUP BY c.id ORDER BY c.kind,c.name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
}
function ankhProductCategories(PDO $db):array{
    ankhCategoriesInit($db);
    return $db->query("SELECT m.product_id,c.id AS category_id,c.name,c.kind
        FROM catalogue_product_categories m JOIN catalogue_categories c ON c.id=m.category_id")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
}
function ankhCategoryCreate(PDO $db,string $name,string $kind):int{
    $name=trim(preg_replace('/\s+/u',' ',$name)??'');
    if($name==='' || mb_strlen($name)>80 || preg_match('/[<>\\x00-\\x1F]/u',$name))
        throw new InvalidArgumentException('Enter a category name of up to 80 characters.');
    if(!in_array($kind,['accessory','research'],true))
        throw new InvalidArgumentException('Choose a valid category type.');
    ankhCategoriesInit($db);
    $q=$db->prepare('SELECT id FROM catalogue_categories WHERE name=? COLLATE NOCASE');
    $q->execute([$name]);if($q->fetchColumn())throw new InvalidArgumentException('This category already exists.');
    $q=$db->prepare('INSERT INTO catalogue_categories(name,kind,created_at) VALUES(?,?,?)');
    $q->execute([$name,$kind,gmdate('c')]);
    return (int)$db->lastInsertId();
}
function ankhCategoryAssign(PDO $db,int $productId,int $categoryId):void{
    ankhCategoriesInit($db);
    $q=$db->prepare('SELECT id FROM products WHERE id=?');$q->execute([$productId]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Product not found.');
    if($categoryId===0){
        $db->prepare('DELETE FROM catalogue_product_categories WHERE product_id=?')->execute([$productId]);
        return;
    }
    $q=$db->prepare('SELECT id FROM catalogue_categories WHERE id=?');$q->execute([$categoryId]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Choose an existing category.');
    $db->prepare('INSERT INTO catalogue_product_categories(product_id,category_id,updated_at) VALUES(?,?,?)
      ON CONFLICT(product_id) DO UPDATE SET category_id=excluded.category_id,updated_at=excluded.updated_at')
      ->execute([$productId,$categoryId,gmdate('c')]);
}
function ankhCategorySelector(array $categories,int $selected=0,string $name='category_id'):void{
    ?><label>Website category
    <select name="<?=htmlspecialchars($name,ENT_QUOTES,'UTF-8')?>">
      <option value="0">Uncategorised — not set</option>
      <?php foreach($categories as $category):?>
      <option value="<?=(int)$category['id']?>" <?=(int)$category['id']===$selected?'selected':''?>>
        <?=htmlspecialchars((string)$category['name'],ENT_QUOTES,'UTF-8')?> (<?=$category['kind']==='accessory'?'accessory':'research'?>)
      </option>
      <?php endforeach;?>
    </select></label><?php
}
function ankhCategoriesAdminUi(PDO $db):void{
    $categories=ankhCategories($db);
    ?>
    <style>
    .ankh-cat-panel{margin:18px 0;padding:20px;border:1px solid #d3b77d61;border-radius:16px;background:linear-gradient(140deg,#20251e,#131713)}
    .ankh-cat-panel h2{font:400 27px Georgia,serif;color:#f3ddb0;margin:6px 0}
    .ankh-cat-panel p{font-size:12px;line-height:1.65;color:#babeb4}
    .ankh-cat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:9px}
    .ankh-cat-chip{padding:12px 14px;border:1px solid #b99a644d;border-radius:10px;background:#101711;display:grid;gap:5px}
    .ankh-cat-chip strong{font-size:12px;color:#efddba}.ankh-cat-chip small{font-size:10px;color:#aaa99a}
    .ankh-cat-add{display:grid;grid-template-columns:minmax(180px,2fr) minmax(130px,1fr) auto;align-items:end;gap:10px;margin-top:19px}
    .ankh-cat-add label{display:grid;gap:7px;font-size:11px;color:#e8d9b5}
    .ankh-cat-add :is(input,select){width:100%;min-height:45px;background:#101811!important;color:#eee1c0!important;border:1px solid #c3a66e77!important;border-radius:10px;padding:10px;font-size:13px}
    .ankh-cat-add button{min-height:46px;border:1px solid #e1c88a;background:#d9b875;color:#1c1c18;font-weight:700;border-radius:10px;padding:10px 16px}
    .ankh-cat-hidden-note{border-left:3px solid #d1b476;padding:10px 13px;background:#bd995914;border-radius:7px}
    .ankh-visibility-item .ankh-visibility-quick{flex:none;display:inline-flex}
    @media(max-width:640px){.ankh-cat-panel{padding:14px}.ankh-cat-add{grid-template-columns:1fr}.ankh-cat-add button{width:100%}}
    </style>
    <section class="ankh-cat-panel" id="ankh-categories">
    <p class="eyebrow">ANKH / PRODUCT ORGANISATION</p><h2>Accessories &amp; categories</h2>
    <p>Create groups for labware, petri dishes and other suitable accessories. When you add an actual product below, select its category and enter its real price. <strong>New products start hidden from the website.</strong> A category does not authorise sales or override product compliance review.</p>
    <div class="ankh-cat-grid">
     <?php foreach($categories as $c):?>
      <div class="ankh-cat-chip"><strong><?=htmlspecialchars((string)$c['name'],ENT_QUOTES,'UTF-8')?></strong>
      <small><?=$c['kind']==='accessory'?'Laboratory accessory':'Research material'?> · <?=(int)$c['product_count']?> products</small></div>
     <?php endforeach;?>
    </div>
    <form class="ankh-cat-add" method="post"><?php if(function_exists('csrf'))csrf(); ?>
      <input type="hidden" name="action" value="catalogue_category_add"><input type="hidden" name="return" value="products">
      <label>New category<input type="text" name="category_name" maxlength="80" required placeholder="e.g. Pipettes &amp; tips"></label>
      <label>Category purpose<select name="category_kind" required><option value="accessory">Laboratory accessory</option><option value="research">Research material</option></select></label>
      <button type="submit">Add category</button>
    </form>
    </section>
    <?php
}
