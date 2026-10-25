<?php
declare(strict_types=1);
/**
 * Controlled launch of INFORMATIONAL laboratory accessory profiles, not an
 * ecommerce launch. Product names, sourcing costs and statuses remain private.
 * No checkout approval is granted here; all 12 remain supply-review pending.
 */
require_once __DIR__.'/lab-accessory-drafts.php';
function ankhPublishLabProfiles(PDO $db):array {
    $drafts=ankhLabAccessoryDrafts();
    if(count($drafts)!==12)throw new RuntimeException('Unexpected laboratory accessory catalogue size.');
    $all=$db->prepare('SELECT s.product_id,s.price_status,p.name,p.active,p.price,c.description,
       COALESCE(v.visible,0) AS visible,cat.kind AS category_kind
       FROM lab_accessory_sourcing s JOIN products p ON p.id=s.product_id
       JOIN customer_catalogue_content c ON c.product_id=p.id
       JOIN customer_catalogue_visibility v ON v.product_id=p.id
       JOIN catalogue_product_categories m ON m.product_id=p.id
       JOIN catalogue_categories cat ON cat.id=m.category_id
       WHERE s.sku=?');
    $update=$db->prepare('UPDATE products SET active=1,name=? WHERE id=?');
    $publish=$db->prepare('UPDATE customer_catalogue_visibility SET visible=1,updated_at=? WHERE product_id=?');
    $result=['published'=>0,'already_published'=>0,'awaiting_supplier'=>0,'missing'=>0];
    $checked=[]; 
    foreach($drafts as $d){
        $all->execute([$d['sku']]);$row=$all->fetch(PDO::FETCH_ASSOC);
        if(!$row){$result['missing']++;continue;}
        // All content must be real editorial work, with no fabricated photography.
        if($row['category_kind']!=='accessory'||strlen((string)$row['description'])<1100)
            throw new RuntimeException('Missing category classification or product content: '.$d['sku']);
        if(!in_array((string)$row['price_status'],['reference_unconfirmed','needs_amazon_price'],true))
            throw new RuntimeException('Unexpected sourcing status: '.$d['sku']);
        $expected=$d['name'];
        $safeName=$d['sku']==='LAB-LABEL-WATERPROOF-100'?'Laboratory Labels - Pack of 100':$expected;
        if(!in_array((string)$row['name'],[$expected,$safeName],true))
            throw new RuntimeException('Product was edited; manual review required for '.$d['sku']);
        if((int)$row['price']<0)throw new RuntimeException('Invalid stored draft price.');
        $checked[]=['id'=>(int)$row['product_id'],'name'=>$safeName,'active'=>(int)$row['active'],
            'visible'=>(int)$row['visible']];
        $result['awaiting_supplier']++;
    }
    if($result['missing']!==0 || count($checked)!==12)
        throw new RuntimeException('All 12 original draft product records must exist before publication.');
    $db->beginTransaction();
    try{
        foreach($checked as $item){
            if($item['active']===1&&$item['visible']===1){
                $result['already_published']++;continue;
            }
            $update->execute([$item['name'],$item['id']]);
            $publish->execute([gmdate('c'),$item['id']]);
            $result['published']++;
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return $result;
}
