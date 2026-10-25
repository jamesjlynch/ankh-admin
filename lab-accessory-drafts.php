<?php
declare(strict_types=1);
/**
 * ANKH Laboratory Supplies: carefully held draft catalogue.
 * No active orders, stock or prices are altered. Initial sourcing costs are
 * Amazon-linked third-party displayed offers, NOT confirmed checkout quotes.
 * Unverified prices are NULL in sourcing and £0 internally on inactive drafts.
 */
require_once __DIR__.'/catalogue-categories.php';

function ankhLabAccessoryDrafts():array {
  return [
    ['sku'=>'LAB-PETRI-90-20','name'=>'90mm Petri Dishes - Pack of 20','category'=>'Petri Dishes','amazon'=>null,'url'=>'',
     'description'=>"90mm Petri Dishes | Pack of 20\n\nA practical laboratory essential for sample presentation, educational demonstrations and appropriate culture-media work. Each shallow dish comprises a base and removable lid, providing a clear area for organising and observing samples on a bench.\n\nPack contents: 20 lidded dishes. Nominal diameter: 90mm. Confirm material, venting, depth, packaging and any sterility claims against the actual supplier listing before publication. Not intended for administration to humans or animals."],
    ['sku'=>'LAB-PETRI-60-20','name'=>'60mm Petri Dishes - Pack of 20','category'=>'Petri Dishes','amazon'=>null,'url'=>'',
     'description'=>"60mm Petri Dishes | Pack of 20\n\nCompact lidded Petri dishes suited to small-scale laboratory observation, specimen organisation and teaching activities. The smaller footprint makes them convenient where bench space is limited or individual samples need separate containers.\n\nPack contents: 20 dishes with lids. Nominal diameter: 60mm. Material, packaging, venting and sterile/non-sterile status must be verified against the selected supplier before sale."],
    ['sku'=>'LAB-TUBE-MICRO-15-100','name'=>'1.5ml Microcentrifuge Tubes - Pack of 100','category'=>'Sample Tubes','amazon'=>null,'url'=>'',
     'description'=>"1.5ml Microcentrifuge Tubes | Pack of 100\n\nSpace-saving laboratory tubes for organising and containing small experimental samples. Suitable for ordinary benchtop storage and preparation when the chosen tube's material and specifications are compatible with the laboratory's application.\n\nNominal capacity: 1.5ml per tube. Pack contents: 100 tubes. Check material, lid design, chemical compatibility, temperature tolerance, centrifuge rating and sterility directly on the supplier's technical specification; none are guaranteed by this draft listing."],
    ['sku'=>'LAB-TUBE-SCREW-2-10','name'=>'2ml Screw-Cap Sample Tubes - Pack of 10','category'=>'Sample Tubes','amazon'=>349,'url'=>'https://bigshopper.co.uk/product/10-x-2ml-plastic-test-tube-vial-container-blue-screw-cap-with-o-ring-seal-2748635798.htm',
     'description'=>"2ml Screw-Cap Sample Tubes | Pack of 10\n\nSmall, convenient polypropylene containers for organising and storing non-clinical laboratory samples and reference materials. The screw-cap closure and described O-ring seal support tidy handling when used within the manufacturer's specified conditions.\n\nPack contents: 10 containers. Nominal capacity: 2ml each. The referenced supplier listing describes blue screw caps with O-ring seals. Check chemical compatibility and temperature limits before use. These are not presented as sterile, medical devices or suitable for human administration."],
    ['sku'=>'LAB-PIPETTE-5-100','name'=>'5ml Disposable Transfer Pipettes - Pack of 100','category'=>'Liquid Handling','amazon'=>899,'url'=>'https://bigshopper.co.uk/product/g2plus-plastic-transfer-pipette-5ml-washable-pasteur-pipettes-measuring-pipettors-disposable-dropper-for-mixing-acrylic-paints-and-lab-pack-of-100-1093611778.htm',
     'description'=>"5ml Disposable Transfer Pipettes | Pack of 100\n\nFlexible graduated transfer pipettes for moving small amounts of non-clinical liquids between laboratory containers. Simple bulb-operated handling makes them useful for routine demonstrations, practical teaching and basic sample-transfer tasks.\n\nNominal capacity: 5ml per pipette. Pack contents: 100. Referenced marketplace listing describes clear polyethylene pipettes with graduated markings. These are general-purpose transfer tools and not a substitute for calibrated volumetric equipment or sterile medical syringes."],
    ['sku'=>'LAB-RACK-SMALL-1','name'=>'Laboratory Tube Rack - 1 Unit','category'=>'Laboratory Equipment','amazon'=>null,'url'=>'',
     'description'=>"Laboratory Tube Rack | Single Unit\n\nA simple bench organiser designed to keep compatible sample tubes upright, accessible and easy to identify. Useful for setting up a workstation, separating different samples and reducing clutter during ordinary laboratory procedures.\n\nQuantity: one rack. Tube hole diameter, capacity, rack material and temperature resistance depend on the final supplied design. Confirm which tube sizes fit before listing or dispatch."],
    ['sku'=>'LAB-LABEL-WATERPROOF-100','name'=>'Waterproof Laboratory Labels - Pack of 100','category'=>'Storage & Labelling','amazon'=>null,'url'=>'',
     'description'=>"Waterproof Laboratory Labels | Pack of 100\n\nIdentification labels for organising sample containers, racks and laboratory storage. Intended to help keep clearly marked records of sample names, reference numbers, dates and storage locations.\n\nPack contents: 100 labels. Adhesive, surface dimensions, printer compatibility and resistance to moisture, solvents and low temperatures must be confirmed for the exact supplier SKU. Do not describe these as waterproof publicly unless that claim is supported by the manufacturer."],
    ['sku'=>'LAB-BOX-SAMPLE-1','name'=>'Laboratory Sample Storage Box - 1 Unit','category'=>'Storage & Labelling','amazon'=>null,'url'=>'',
     'description'=>"Laboratory Sample Storage Box | Single Unit\n\nA reusable organisational box for storing compatible small tubes and laboratory consumables in one convenient place. Suitable for keeping a bench, shelf or research storage area orderly and for separating labelled groups of samples.\n\nQuantity: one box. Capacity, dimensions, insert layout, material and temperature suitability must be checked against the selected manufacturer's product details before publication."],
    ['sku'=>'LAB-BEAKER-GLASS-100-1','name'=>'100ml Glass Laboratory Beaker - 1 Unit','category'=>'Laboratory Equipment','amazon'=>null,'url'=>'',
     'description'=>"100ml Glass Laboratory Beaker | Single Unit\n\nA compact, general-purpose laboratory vessel for holding, mixing and transferring compatible liquids during educational activities and everyday bench procedures. Its wide opening provides easy access for stirring, decanting and cleaning.\n\nNominal volume: 100ml. Quantity: one beaker. Verify the actual glass composition, graduated markings, spout, heat tolerance and care instructions with the chosen supplier. Beaker graduations are indicative and not a substitute for calibrated measuring equipment."],
    ['sku'=>'LAB-CYLINDER-100-1','name'=>'100ml Graduated Measuring Cylinder - 1 Unit','category'=>'Liquid Handling','amazon'=>null,'url'=>'',
     'description'=>"100ml Graduated Measuring Cylinder | Single Unit\n\nAn upright measuring cylinder intended for approximate liquid-volume measurements in ordinary laboratory demonstrations, basic mixing tasks and sample preparation. A graduated scale makes visual reading straightforward when used on a level surface.\n\nNominal capacity: 100ml. Quantity: one cylinder. Final material, scale interval, tolerance, pouring spout and base type are to be confirmed from the selected manufacturer's specification. Not a calibrated medical dosing device."],
    ['sku'=>'LAB-SLIDES-100-COVERS-100','name'=>'Microscope Slides (100) & Coverslips (100) - Set','category'=>'Laboratory Consumables','amazon'=>899,'url'=>'https://bigshopper.co.uk/product/re-gen-100pcs-blank-round-ground-edge-clear-microscope-slides-100-square-cover-glass-slips-1093940735.htm',
     'description'=>"Microscope Slides & Coverslips | 100 + 100 Set\n\nA convenient glass microscopy set for preparing and observing specimens in educational or general laboratory work. Slides provide the main viewing surface, while thin square coverslips help protect prepared specimens during suitable microscopy procedures.\n\nSet contains: 100 clear microscope slides and 100 square coverslips. The referenced RE-GEN listing specifies approximately 25.4 × 76.2mm slides and 22 × 22mm coverslips. Handle glass carefully; inspect for chips or damage before use."],
    ['sku'=>'LAB-BAGS-GRIP-50-100','name'=>'Resealable Sample Bags 50x50mm - Pack of 100','category'=>'Storage & Labelling','amazon'=>389,'url'=>'https://bigshopper.co.uk/product/grip-seal-polythene-zip-lock-bags-50mm-x-50mm-clear-pack-of-yellow-100pcs-2-x-2-5x5cm-1138680734.htm',
     'description'=>"Resealable Sample Bags | 50 × 50mm | Pack of 100\n\nCompact clear grip-seal bags for organising small dry laboratory accessories, sample labels and non-hazardous reference items. The resealable closure makes sorting, identifying and storing small pieces more convenient.\n\nPack contents: 100 bags. Nominal size: 50 × 50mm. The referenced supplier offer describes small polythene grip-seal pouches. Do not use as sterile packaging, hazardous-substance containment or medical specimen transport without the appropriate certified product."]
  ];
}
function ankhLabAccessoriesSeed(PDO $db):array {
    ankhCategoriesInit($db);
    $db->exec("CREATE TABLE IF NOT EXISTS lab_accessory_sourcing (
       sku TEXT PRIMARY KEY, product_id INTEGER NOT NULL UNIQUE REFERENCES products(id) ON DELETE CASCADE,
       amazon_reference_pence INTEGER DEFAULT NULL, reference_url TEXT NOT NULL DEFAULT '',
       observed_date TEXT NOT NULL, price_status TEXT NOT NULL CHECK(price_status IN ('reference_unconfirmed','needs_amazon_price')),
       note TEXT NOT NULL DEFAULT ''
    )");
    $categoryLookup=[];
    foreach(ankhCategories($db) as $cat)$categoryLookup[(string)$cat['name']]=(int)$cat['id'];
    foreach(['Sample Tubes','Liquid Handling','Storage & Labelling'] as $category){
        if(!isset($categoryLookup[$category]))
            $categoryLookup[$category]=ankhCategoryCreate($db,$category,'accessory');
    }
    $db->exec('CREATE TABLE IF NOT EXISTS customer_catalogue_visibility(
      product_id INTEGER PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
      visible INTEGER NOT NULL DEFAULT 0 CHECK(visible IN (0,1)),updated_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS customer_catalogue_content(
      product_id INTEGER PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
      description TEXT NOT NULL DEFAULT "",image_data BLOB,image_mime TEXT NOT NULL DEFAULT "",
      image_width INTEGER NOT NULL DEFAULT 0,image_height INTEGER NOT NULL DEFAULT 0,updated_at TEXT NOT NULL
    )');
    $existing=$db->prepare('SELECT id FROM products WHERE lower(trim(name))=lower(trim(?))');
    $insert=$db->prepare('INSERT INTO products(name,price,cost,active,low_stock_at,supplier_pack_size)
        VALUES(?,?,?,0,5,1)');
    $desc=$db->prepare('INSERT INTO customer_catalogue_content(product_id,description,updated_at) VALUES(?,?,?)
      ON CONFLICT(product_id) DO NOTHING');
    // Do not use the old public catalogue's historical visible-by-default behavior.
    $vis=$db->prepare('INSERT INTO customer_catalogue_visibility(product_id,visible,updated_at) VALUES(?,0,?)
      ON CONFLICT(product_id) DO NOTHING');
    $source=$db->prepare('INSERT OR IGNORE INTO lab_accessory_sourcing
      (sku,product_id,amazon_reference_pence,reference_url,observed_date,price_status,note)
       VALUES(?,?,?,?,?,?,?)');
    $created=0;$skipped=0;$priced=0;$needs=0;
    $db->exec('BEGIN IMMEDIATE');
    try{
      foreach(ankhLabAccessoryDrafts() as $p){
        $existing->execute([$p['name']]);
        if($existing->fetchColumn()!==false){$skipped++;continue;}
        $amazon=$p['amazon'];
        $insert->execute([$p['name'],$amazon===null?0:$amazon+300,$amazon]);
        $id=(int)$db->lastInsertId();
        ankhCategoryAssign($db,$id,$categoryLookup[$p['category']]);
        $desc->execute([$id,$p['description'],gmdate('c')]);
        $vis->execute([$id,gmdate('c')]);
        $source->execute([
          $p['sku'],$id,$amazon,(string)$p['url'],'2026-10-25',
          $amazon===null?'needs_amazon_price':'reference_unconfirmed',
          $amazon===null?'No matching Amazon UK price verified; draft remains inactive and unpublished.':
            'Price seen in Amazon-linked comparison listing; check Amazon checkout price, VAT and delivery before activation.'
        ]);
        $created++;
        if($amazon===null)$needs++;else $priced++;
      }
      $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return compact('created','skipped','priced','needs');
}
