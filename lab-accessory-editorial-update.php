<?php
declare(strict_types=1);
require_once __DIR__.'/lab-accessory-drafts.php';
require_once __DIR__.'/lab-accessory-editorial.php';
/**
 * Replace only the pristine descriptions created by ANKH's October 25 seeding.
 * Never overwrite manual CMS edits, published profiles or unrelated products.
 * Existing price, sourcing, stock, customers and visibility are left untouched.
 */
function ankhAccessoryUpgradeEditorial(PDO $db):array {
    $new=ankhLaboratoryAccessoryEditorial();
    $original=ankhLabAccessoryDrafts();
    $old=[];foreach($original as $p)$old[$p['sku']]=$p;
    if(count($new)!==12||count($old)!==12||array_diff_key($new,$old)||array_diff_key($old,$new))
        throw new RuntimeException('Editorial product set does not match the original 12 accessories.');
    $results=['updated'=>0,'already_updated'=>0,'manually_edited'=>0,'published_or_active'=>0,'missing'=>0];
    $find=$db->prepare('SELECT s.product_id,p.name,p.active,
        COALESCE(v.visible,0) AS visible,c.description
        FROM lab_accessory_sourcing s
        JOIN products p ON p.id=s.product_id
        JOIN customer_catalogue_content c ON c.product_id=p.id
        LEFT JOIN customer_catalogue_visibility v ON v.product_id=p.id
        WHERE s.sku=?');
    $update=$db->prepare('UPDATE customer_catalogue_content SET description=?,updated_at=?
        WHERE product_id=? AND description=?');
    $db->beginTransaction();
    try{
        foreach($new as $sku=>$description){
            if(!is_string($description)||strlen($description)<1100||strlen($description)>10000
               ||!str_contains($description,'ANKH')||!str_contains($description,'## Frequently asked questions'))
                throw new RuntimeException('Editorial content failed quality checks for '.$sku);
            $find->execute([$sku]);$row=$find->fetch(PDO::FETCH_ASSOC);
            if(!$row || (string)$row['name']!==$old[$sku]['name']){
                $results['missing']++;continue;
            }
            // Never alter a published profile from an automatic upgrade.
            if((int)$row['active']!==0||(int)$row['visible']!==0){
                $results['published_or_active']++;continue;
            }
            if((string)$row['description']===$description){
                $results['already_updated']++;continue;
            }
            if((string)$row['description']!==(string)$old[$sku]['description']){
                $results['manually_edited']++;continue;
            }
            $update->execute([$description,gmdate('c'),(int)$row['product_id'],(string)$row['description']]);
            if($update->rowCount()!==1)throw new RuntimeException('Concurrent accessory CMS update; no draft overwritten.');
            $results['updated']++;
        }
        $db->commit();
        return $results;
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
