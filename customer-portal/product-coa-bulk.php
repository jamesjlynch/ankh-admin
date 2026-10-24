<?php
declare(strict_types=1);
/**
 * ANKH 13-report bulk attachment.
 * Exactly the user-approved 2026-10-23 PDFs are allowed by immutable SHA-256.
 * No network requests, no Admin database writes, no filename-based guessing.
 */
function ankhBulkCoaManifest():array {
    return [
      'BPC_157_5mg_ANKH_Certificate_of_Analysis.pdf'=>['BPC-157 5mg','BPC5-024','eb49878e4de9845a81ad7b8261369fc7af216e3c91976fd08abff79a7e42b12f'],
      'Epitalon_10mg_ANKH_Certificate_of_Analysis.pdf'=>['Epitalon 10mg','EPI10-024','5006fb556e07ad69b1f5423dc4edb18e14e440cc7b7489c69b7e893d83634889'],
      'Selank_5mg_ANKH_Certificate_of_Analysis.pdf'=>['Selank 5mg','SEL5-024','0c38f94dc7b895a176af6f77dc4191a5a157c435a4d3aeea08ce767730d1e8c9'],
      'Semax_5mg_ANKH_Certificate_of_Analysis.pdf'=>['Semax 5mg','SEM5-024','57baae47f297f42ff90ee6b1ebde087a3d00d6dcc95d339ce06586f4db4b59ae'],
      'MOTS_C_10mg_ANKH_Certificate_of_Analysis.pdf'=>['MOTS-C 10mg','MOT10-024','63adc031a97d88113037bda240f9f78bc0c49d9bac8db35589c10e9b0e793753'],
      'DSIP_5mg_ANKH_Certificate_of_Analysis.pdf'=>['DSIP 5mg','DSI5-024','01d50e9ae002e054ff93fa04e1d6e5428341e4696a7abb08e032c8fc4aff4139'],
      'KPV_10mg_ANKH_Certificate_of_Analysis.pdf'=>['KPV 10mg','KPV10-024','5893e928bcb023e9fb1ba0bb13b1e7ea4cfc05ba84b5973667f3d4c1168cd9a7'],
      'Ipamorelin_5mg_ANKH_Certificate_of_Analysis.pdf'=>['Ipamorelin 5mg','IPA5-024','3b9ba2b6ebdc4c5afbcc97e95791a0752432b2fd52123bce78a0b2db355aff6b'],
      'Kisspeptin_10_5mg_ANKH_Certificate_of_Analysis.pdf'=>['Kisspeptin-10 5mg','KIS5-024','cdead68972cef8b1286deb716f71276e0f541c453639b1fe70b768f3094375e6'],
      'GHK_Cu_50mg_ANKH_Certificate_of_Analysis.pdf'=>['GHK-Cu 50mg','GHK50-024','a6f55409a864bd58b9a2f780c8b9923aa7a61814d6868dedba5f63025bec8cd5'],
      'CJC_1295_No_DAC_5mg_ANKH_Certificate_of_Analysis.pdf'=>['CJC-1295 No DAC 5mg','CJC5-024','03db5a4a65eeeb58199fe149e2b5ac53112ba344ae85ee7e0b2d6f49fde47875'],
      'IGF_1_LR3_1mg_ANKH_Certificate_of_Analysis.pdf'=>['IGF-1 LR3 1mg','IGF1-024','c8da098c5c6fefad919bbfa7b43660ecd5d9785d94f271fb07af50335774603c'],
      'TB_500_2mg_Full_Length_TB4_ANKH_Certificate_of_Analysis.pdf'=>['TB-500 2mg (full-length TB4)','THY2-024','fee185f215b078eabd20a2f78b6b7541a7a72dc0ec7c65a0fb4add722aa96758']
    ];
}
function ankhBulkNormalise(string $label):string {
    $text=strtolower($label);
    // Match the same peptide and amount only. Do not remove meaningful DAC, salt or fragment markers.
    return preg_replace('/[^a-z0-9]/','',$text)??'';
}
function ankhBulkResolveProduct(array $rows,string $label,bool $isFullLengthConfirmed=false):array {
    $label=ankhBulkNormalise($label);
    if(str_starts_with($label,'tb500')&&!$isFullLengthConfirmed)
        return ['id'=>null,'error'=>'TB-500 requires confirmation of full-length thymosin beta-4 identity.'];
    $target=preg_replace('/full-lengthtb4/','',$label)??$label;
    $candidates=[];
    foreach($rows as $row){
        if((int)$row['active']!==1)continue;
        $actual=ankhBulkNormalise((string)$row['name']);
        if($actual===$target||in_array($actual,[$target.'vial',$target.'lyophilisedpowder'],true)){
            $candidates[]=$row;
        }
    }
    if(count($candidates)!==1)
        return ['id'=>null,'error'=>count($candidates)===0?'No exact active product with this name and strength.':'Ambiguous product match; manual review required.'];
    return ['id'=>(int)$candidates[0]['id'],'name'=>(string)$candidates[0]['name'],
        'published'=>(int)$candidates[0]['visible']===1];
}
function ankhBulkCoaPlan(PDO $admin,bool $tbFullLengthConfirmed):array {
    $rows=$admin->query("SELECT p.id,p.name,p.active,COALESCE(v.visible,0) AS visible
        FROM products p LEFT JOIN customer_catalogue_visibility v ON v.product_id=p.id")->fetchAll(PDO::FETCH_ASSOC);
    $plan=[];
    foreach(ankhBulkCoaManifest() as $file=>$ref){
        $match=ankhBulkResolveProduct($rows,$ref[0],$tbFullLengthConfirmed);
        $plan[$file]=['label'=>$ref[0],'ref'=>$ref[1],'sha256'=>$ref[2]]+$match;
    }
    return $plan;
}
function ankhBulkCoaImport(PDO $store,PDO $admin,array $post,array $file):array {
    if(!class_exists('ZipArchive'))throw new InvalidArgumentException('ZIP support is not enabled on this server.');
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))
        throw new InvalidArgumentException('Please choose the original ANKH 13 COA ZIP pack.');
    if((int)($file['size']??0)<1000||(int)$file['size']>12*1024*1024)
        throw new InvalidArgumentException('ZIP pack must be between 1KB and 12MB.');
    if(($post['attest_reference']??'')!=='1')
        throw new InvalidArgumentException('Confirm the certificates are reference analyses and not independent batch-specific results.');
    $approvedFullLength=($post['confirm_full_length_tb4']??'')==='1';
    $plan=ankhBulkCoaPlan($admin,$approvedFullLength);
    $zip=new ZipArchive();
    if($zip->open($file['tmp_name'],ZipArchive::CHECKCONS)!==true)
        throw new InvalidArgumentException('Unable to open or verify ZIP.');
    $manifest=ankhBulkCoaManifest();
    $contents=[];$issues=[];$seen=[];
    try{
        if($zip->numFiles>50)throw new InvalidArgumentException('Too many files in this ZIP.');
        for($i=0;$i<$zip->numFiles;$i++){
            $stat=$zip->statIndex($i);
            if(!is_array($stat))throw new InvalidArgumentException('ZIP metadata is unreadable.');
            $entry=(string)($stat['name']??'');
            if(str_contains($entry,'..')||str_contains($entry,'/')||str_contains($entry,'\\'))
                throw new InvalidArgumentException('ZIP must contain flat files only.');
            if(!array_key_exists($entry,$manifest))continue; // Audit CSV, checklist and README may be present.
            if(isset($seen[$entry]))throw new InvalidArgumentException('Duplicate certificate: '.$entry);
            $seen[$entry]=true;
            if((int)($stat['size']??0)>8*1024*1024)throw new InvalidArgumentException('Oversized certificate: '.$entry);
            $bytes=$zip->getFromIndex($i,8*1024*1024+1);
            if(!is_string($bytes)||strlen($bytes)!==(int)$stat['size']
               ||!str_starts_with($bytes,'%PDF-')||!str_contains(substr($bytes,-2048),'%%EOF')
               ||!hash_equals($manifest[$entry][2],hash('sha256',$bytes)))
                throw new InvalidArgumentException('This PDF differs from the approved document pack: '.$entry);
            $contents[$entry]=$bytes;
        }
    }finally{$zip->close();}
    if(count($contents)!==count($manifest))
        throw new InvalidArgumentException('Expected all 13 approved PDFs; received '.count($contents).'. Use the complete ANKH 13-report ZIP.');
    // Nothing is saved if any match is missing or ambiguous.
    foreach($plan as $file=>$entry){
        if(empty($entry['id']))$issues[]=$entry['label'].': '.$entry['error'];
        if($entry['label']==='TB-500 2mg (full-length TB4)' && $approvedFullLength &&
           !preg_match('/(full.?length|thymosin.?beta.?4)/i',(string)($entry['name']??''))){
            // An affirmative checkbox is necessary but not sufficient to assert molecular equivalence.
            // Reference may be stored privately, but cannot be published to a generic TB-500 product.
            if(($post['publish_now']??'')==='1')$issues[]='TB-500 2mg: generic TB-500 label does not establish full-length identity for public release.';
        }
    }
    if($issues)throw new InvalidArgumentException('No changes saved. '.implode(' | ',$issues));
    $publish=($post['publish_now']??'')==='1';
    if($publish){
        foreach($plan as $entry)if(!$entry['published'])
            throw new InvalidArgumentException('No changes saved: '.$entry['label'].' is not a published website product.');
    }
    ankhDocsInit($store);
    $out=[];$store->beginTransaction();
    try{
        foreach($plan as $filename=>$entry){
            $productId=(int)$entry['id'];$sha=$entry['sha256'];
            $q=$store->prepare('SELECT id,product_id FROM portal_product_documents WHERE pdf_sha256=? LIMIT 1');
            $q->execute([$sha]);$found=$q->fetch(PDO::FETCH_ASSOC);
            if($found&& (int)$found['product_id']!==$productId)
                throw new InvalidArgumentException('Duplicate report is already attached to a different product.');
            if($found){
                $id=(int)$found['id'];
                $store->prepare('UPDATE portal_product_documents SET published=?,superseded=0 WHERE id=?')
                    ->execute([$publish?1:0,$id]);
                $out[]=$entry['name'].' (existing attachment '.($publish?'published':'saved privately').')';
                continue;
            }
            $q=$store->prepare("INSERT INTO portal_product_documents
                (product_id,document_kind,issuer,batch_ref,report_ref,pdf_name,pdf_sha256,pdf_bytes,created_at,published,superseded)
                VALUES(?,'original_coa',?,?,?,?,?,?,?, ?,0)");
            $q->execute([$productId,'Reformatted original analytical reference; not batch-verified','',
                $entry['ref'],$filename,$sha,$contents[$filename],time(),$publish?1:0]);
            $out[]=$entry['name'].' ('.($publish?'published':'saved privately').')';
        }
        $store->commit();
    }catch(Throwable $e){$store->rollBack();throw $e;}
    return $out;
}
function ankhBulkCoaStaffController(PDO $store,PDO $admin):void {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_POST['action']??'')!=='ankh_bulk_coa_upload')return;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)($_SESSION['portal_csrf']??''),(string)$_POST['csrf'])){
        http_response_code(400);exit('Invalid staff request.');
    }
    try {
        $result=ankhBulkCoaImport($store,$admin,$_POST,$_FILES['coa_zip']??[]);
        $_SESSION['portal_staff_flash']='COA import complete: '.count($result).' reports '.(($_POST['publish_now']??'')==='1'?'published':'saved privately').'.';
    }catch(InvalidArgumentException $e){
        $_SESSION['portal_staff_flash']=$e->getMessage();
    }catch(Throwable $e){
        error_log('ANKH bulk COA import: '.$e->getMessage());
        $_SESSION['portal_staff_flash']='Import failed; no reports were changed.';
    }
    header('Location: ./preview-live.php?manage=portal#ankh-certificates',true,303);exit;
}
function ankhBulkCoaStaffForm(string $csrf):void {
    $e=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES,'UTF-8');
    ?>
    <div class="ankh-bulk-coa-import">
      <h3>Attach your 13 finished ANKH reports together</h3>
      <p>Upload the single <strong>ANKH_13_Rebranded_Analytical_COAs.zip</strong> pack.
        Exact PDF checksums, chemical name and strength are verified before any file is saved.
        The import stops if a product is missing or ambiguous; no other order or stock records are touched.</p>
      <form method="post" enctype="multipart/form-data" action="./preview-live.php?manage=portal#ankh-certificates">
        <input type="hidden" name="csrf" value="<?=$e($csrf)?>">
        <input type="hidden" name="action" value="ankh_bulk_coa_upload">
        <label>13-report ZIP <input type="file" name="coa_zip" accept=".zip,application/zip,application/x-zip-compressed" required></label>
        <label class="ankh-check"><input type="checkbox" name="attest_reference" value="1" required>
          I understand the source reports are analytical references and do not establish an independently tested ANKH batch.</label>
        <label class="ankh-check"><input type="checkbox" name="confirm_full_length_tb4" value="1">
          Confirm that the TB-500 product is full-length thymosin beta-4, not a fragment.</label>
        <label class="ankh-check"><input type="checkbox" name="publish_now" value="1">
          Publish all 13 as reference reports for their matching public products (otherwise save privately).</label>
        <button type="submit">Verify matches and attach 13 reports</button>
      </form>
    </div>
    <?php
}
