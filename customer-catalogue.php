<?php
declare(strict_types=1);
/** Shared customer catalogue metadata. No product data is rewritten. */
function ankhCatalogueKind(string $name): string {
    $name=strtolower(trim(preg_replace('/\s+/u',' ',$name)??''));
    // Display categories only. Visibility is always an explicit per-product choice.
    $allowed=[
        'case'=>'Storage','carry case'=>'Storage','storage case'=>'Storage','everyday carry case'=>'Storage',
        'desk organiser'=>'Storage','desk organizer'=>'Storage','storage organiser'=>'Storage',
        'blank label sheets'=>'Stationery','label sheets'=>'Stationery','labels'=>'Stationery',
        'notebook'=>'Stationery','pocket notebook'=>'Stationery',
    ];
    return $allowed[$name]??'Research materials';
}
function ankhCatalogueInit(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS customer_catalogue_visibility (
        product_id INTEGER PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
        visible INTEGER NOT NULL DEFAULT 0 CHECK(visible IN (0,1)),
        updated_at TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS customer_catalogue_content (
        product_id INTEGER PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
        description TEXT NOT NULL DEFAULT "", image_data BLOB, image_mime TEXT NOT NULL DEFAULT "",
        image_width INTEGER NOT NULL DEFAULT 0,image_height INTEGER NOT NULL DEFAULT 0,updated_at TEXT NOT NULL
    )');
}
function ankhCatalogueInformationFields(): array {
    return ['strength'=>'Strength / quantity','concentration'=>'Concentration','presentation'=>'Supplied form / vial format','appearance'=>'Colour / appearance','peptide_type'=>'Peptide / compound type','amino_length'=>'Amino-acid length','purity'=>'Purity','cas'=>'CAS number','formula'=>'Molecular formula','weight'=>'Molecular weight','sequence'=>'Amino-acid sequence','half_life'=>'Experimental half-life / study context','pubchem'=>'PubChem CID','research_focus'=>'Research focus','research'=>'Research and literature overview','applications'=>'Research topics / applications','references'=>'Literature references','storage'=>'Storage before preparation','prepared_storage'=>'Storage after laboratory preparation','precautions'=>'Laboratory handling notes','faq'=>'Questions and answers','coa_url'=>'COA document link'];
}
function ankhCatalogueLongFields():array {return ['research','applications','references','precautions','faq'];}
function ankhCatalogueNarrativeFields():array {
    return ['research_focus'=>'Research focus','research'=>'Research and literature','applications'=>'Research topics','references'=>'Literature references','storage'=>'Storage before preparation','prepared_storage'=>'Storage after laboratory preparation','precautions'=>'Laboratory handling','faq'=>'Questions & answers'];
}
function ankhCatalogueInformation(PDO $db): array {
    if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_catalogue_information'")->fetchColumn())return [];
    $out=[];foreach($db->query('SELECT product_id,information FROM customer_catalogue_information') as $row){$info=json_decode($row['information'],true);if(is_array($info))$out[(int)$row['product_id']]=$info;}
    return $out;
}
function ankhCatalogueValidateInformation(array $input): array {
    $out=[];
    foreach(ankhCatalogueInformationFields() as $key=>$label){
        $value=$input[$key]??'';
        if(!is_string($value))throw new InvalidArgumentException('Check the product information fields.');
        $value=trim($value);$limit=in_array($key,ankhCatalogueLongFields(),true)?5000:600;
        if(strlen($value)>$limit*4 || !preg_match('//u',$value) || preg_match_all('/./us',$value)>$limit)throw new InvalidArgumentException($label.' is too long.');
        if($key==='coa_url' && $value!=='' && (!filter_var($value,FILTER_VALIDATE_URL) || strtolower((string)parse_url($value,PHP_URL_SCHEME))!=='https' || parse_url($value,PHP_URL_USER)!==null || parse_url($value,PHP_URL_PASS)!==null))throw new InvalidArgumentException('Use an HTTPS link to your own product analysis document.');
        if($value!=='')$out[$key]=$value;
    }
    return $out;
}
function ankhCatalogueContent(PDO $db): array {
    if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_catalogue_content'")->fetchColumn())return [];
    $out=[];
    foreach($db->query('SELECT product_id,description,image_mime,image_width,image_height,updated_at,CASE WHEN image_data IS NULL THEN 0 ELSE length(image_data) END AS image_bytes FROM customer_catalogue_content') as $row)$out[(int)$row['product_id']]=$row;
    foreach(ankhCatalogueInformation($db) as $id=>$info){$out[$id]['information']=$info;}
    return $out;
}
function ankhCatalogueValidateImage(string $bytes): array {
    if($bytes===''||strlen($bytes)>8*1024*1024)throw new InvalidArgumentException('Choose a photo smaller than 8 MB.');
    $info=@getimagesizefromstring($bytes);
    if(!$info || !in_array($info['mime']??'', ['image/jpeg','image/png','image/webp'],true))throw new InvalidArgumentException('Choose a JPG, PNG or WebP photograph.');
    if($info[0]<1||$info[1]<1||$info[0]>12000||$info[1]>12000||$info[0]*$info[1]>32000000)throw new InvalidArgumentException('Choose a smaller photograph, up to 32 megapixels.');
    return ['data'=>$bytes,'mime'=>$info['mime'],'width'=>$info[0],'height'=>$info[1]];
}
function ankhCataloguePhotoUpload(array $file): ?array {
    $error=$file['error']??UPLOAD_ERR_NO_FILE;
    if($error===UPLOAD_ERR_NO_FILE)return null;
    if($error!==UPLOAD_ERR_OK)throw new InvalidArgumentException('The photo could not be uploaded. Try a smaller JPG, PNG or WebP photograph.');
    if(!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Choose a photograph to upload.');
    if((int)($file['size']??0)>8*1024*1024)throw new InvalidArgumentException('Choose a photo smaller than 8 MB.');
    $bytes=file_get_contents($file['tmp_name']);
    if($bytes===false)throw new InvalidArgumentException('The uploaded photo could not be read.');
    return ankhCatalogueValidateImage($bytes);
}
function ankhCatalogueSaveContent(PDO $db,int $id,string $description,?array $photo,bool $remove,bool $visible,?array $information=null):void {
    if($information!==null)$information=ankhCatalogueValidateInformation($information);
    $description=trim($description);
    if(strlen($description)>160000 || !preg_match('//u',$description) || preg_match_all('/./us',$description)>40000)throw new InvalidArgumentException('Use product information of up to 40,000 characters.');
    if($photo!==null && $remove)throw new InvalidArgumentException('Choose a new photo or remove the existing photo, rather than both.');
    if($photo!==null)$photo=ankhCatalogueValidateImage($photo['data']);
    $q=$db->prepare('SELECT id FROM products WHERE id=?');$q->execute([$id]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Product could not be found.');
    ankhCatalogueInit($db);$db->beginTransaction();
    try{
        $db->prepare('INSERT INTO customer_catalogue_content(product_id,description,updated_at) VALUES(?,?,?) ON CONFLICT(product_id) DO UPDATE SET description=excluded.description,updated_at=excluded.updated_at')->execute([$id,$description,gmdate('c')]);
        if($photo!==null){
            $q=$db->prepare('UPDATE customer_catalogue_content SET image_data=?,image_mime=?,image_width=?,image_height=? WHERE product_id=?');
            $q->bindValue(1,$photo['data'],PDO::PARAM_LOB);$q->bindValue(2,$photo['mime']);$q->bindValue(3,$photo['width'],PDO::PARAM_INT);$q->bindValue(4,$photo['height'],PDO::PARAM_INT);$q->bindValue(5,$id,PDO::PARAM_INT);$q->execute();
        }elseif($remove){$db->prepare('UPDATE customer_catalogue_content SET image_data=NULL,image_mime="",image_width=0,image_height=0 WHERE product_id=?')->execute([$id]);}
        if($information!==null){
            $db->exec('CREATE TABLE IF NOT EXISTS customer_catalogue_information(product_id INTEGER PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,information TEXT NOT NULL,updated_at TEXT NOT NULL)');
            $db->prepare('INSERT INTO customer_catalogue_information(product_id,information,updated_at) VALUES(?,?,?) ON CONFLICT(product_id) DO UPDATE SET information=excluded.information,updated_at=excluded.updated_at')->execute([$id,json_encode($information,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),gmdate('c')]);
        }
        ankhCatalogueSetVisibility($db,$id,$visible);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function ankhCatalogueOutputImage(PDO $db,int $id,bool $publishedOnly):never {
    if($id<1||!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_catalogue_content'")->fetchColumn()){http_response_code(404);exit;}
    if($publishedOnly && !ankhCatalogueVisibility($db)){http_response_code(404);exit;}
    $sql='SELECT c.image_data,c.image_mime FROM customer_catalogue_content c JOIN products p ON p.id=c.product_id ';
    if($publishedOnly)$sql.='JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1 ';
    $sql.='WHERE c.product_id=? AND c.image_data IS NOT NULL'.($publishedOnly?' AND p.active=1':'');
    $q=$db->prepare($sql);$q->execute([$id]);$photo=$q->fetch(PDO::FETCH_ASSOC);
    if(!$photo || !in_array($photo['image_mime'],['image/jpeg','image/png','image/webp'],true)){http_response_code(404);exit;}
    $bytes=is_resource($photo['image_data'])?stream_get_contents($photo['image_data']):(string)$photo['image_data'];
    header('Content-Type: '.$photo['image_mime']);header('Content-Length: '.strlen($bytes));
    header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Disposition: inline; filename="catalogue-product-'.$id.'.'.(['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$photo['image_mime']]).'"');
    echo $bytes;exit;
}
function ankhCatalogueVisibility(PDO $db): array {
    if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_catalogue_visibility'")->fetchColumn())return [];
    return $db->query('SELECT product_id,visible FROM customer_catalogue_visibility')->fetchAll(PDO::FETCH_KEY_PAIR);
}
function ankhCatalogueSetVisibility(PDO $db,int $id,bool $visible): void {
    $q=$db->prepare('SELECT id,name FROM products WHERE id=?');$q->execute([$id]);$p=$q->fetch(PDO::FETCH_ASSOC);
    if(!$p)throw new InvalidArgumentException('Product could not be found.');
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
    $content=ankhCatalogueContent($db);
    foreach($db->query('SELECT * FROM products WHERE active=1 ORDER BY name COLLATE NOCASE') as $p){
        $category=ankhCatalogueKind((string)$p['name']);
        if((int)($visibility[(int)$p['id']]??0)!==1)continue;
        $price=(int)$p['price'];
        if($pricing==='family_friends' && isset($p['family_friends_price']))$price=(int)$p['family_friends_price'];
        if($pricing==='free')$price=0;
        $tracked=(int)($p['stock_tracking']??0)===1;
        // Explicit public fields: never return costs, supplier data or fridge ownership.
        $meta=$content[(int)$p['id']]??[];
        $out[]=['id'=>(int)$p['id'],'name'=>(string)$p['name'],'description'=>(string)($meta['description']??$p['description']??''),'category'=>$category,'price'=>max(0,$price),'retail_price'=>max(0,(int)$p['price']),'pricing'=>$pricing,'information'=>(array)($meta['information']??[]),'has_photo'=>(int)($meta['image_bytes']??0)>0,'available_qty'=>$tracked?max(0,(int)($p['stock_qty']??0)):null];
    }
    return $out;
}
/** One portable document keeps legacy details editable without losing information. */
function ankhCatalogueEditorText(string $description,array $information=[]):string {
    $parts=[trim($description)];$specs=[];
    foreach(ankhCatalogueInformationFields() as $key=>$label){
        if(empty($information[$key]) || ($key==='coa_url' || array_key_exists($key,ankhCatalogueNarrativeFields())))continue;
        $label=$key==='purity'?'Purity':$label;
        $specs[]='- '.$label.': '.$information[$key];
    }
    if($specs)$parts[]="## Specifications\n".implode("\n",$specs);
    foreach(ankhCatalogueNarrativeFields() as $key=>$label){
        if(!empty($information[$key]))$parts[]='## '.$label."\n".$information[$key];
    }
    if(!empty($information['coa_url']))$parts[]="## Documentation\n[View analysis document](".$information['coa_url'].")";
    return trim(implode("\n\n",array_filter($parts,static fn(string $part):bool=>$part!=='')));
}
function ankhCatalogueSections(string $text):array {
    $sections=[];$title='Overview';$lines=[];
    foreach(preg_split('/\R/u',$text)?:[] as $line){
        if(preg_match('/^#{1,3}\s+(.+?)\s*#*\s*$/u',trim($line),$match)){
            if(trim(implode("\n",$lines))!=='')$sections[]=['title'=>$title,'text'=>trim(implode("\n",$lines))];
            $title=$match[1];$lines=[];
        }else{$lines[]=$line;}
    }
    if(trim(implode("\n",$lines))!=='')$sections[]=['title'=>$title,'text'=>trim(implode("\n",$lines))];
    return $sections;
}
function ankhCatalogueSummary(string $text):string {
    $sections=ankhCatalogueSections($text);$text=$sections[0]['text']??'';
    $text=preg_replace('/\[([^\]]+)\]\([^\s)]+\)/u','$1',$text)??$text;
    $text=str_replace('**','',$text);$text=preg_replace('/^\s*[-*]\s+/mu','',$text)??$text;
    return trim(preg_replace('/\s+/u',' ',$text)??'');
}
/** Minimal formatting. HTML is always text; only HTTPS document links become links. */
function ankhCatalogueInline(string $text):string {
    $escape=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='';$tokens=preg_split('/(\[[^\]\r\n]{1,200}\]\(https:\/\/[^\s)]+\)|\*\*[^*\r\n]+\*\*)/u',$text,-1,PREG_SPLIT_DELIM_CAPTURE)?:[$text];
    foreach($tokens as $token){
        if(preg_match('/^\[([^\]]+)\]\((https:\/\/[^\s)]+)\)$/u',$token,$m) && filter_var($m[2],FILTER_VALIDATE_URL) && parse_url($m[2],PHP_URL_USER)===null && parse_url($m[2],PHP_URL_PASS)===null){
            $html.='<a href="'.$escape($m[2]).'" target="_blank" rel="noopener noreferrer">'.$escape($m[1]).' ↗</a>';
        }elseif(preg_match('/^\*\*(.+)\*\*$/u',$token,$m)){$html.='<strong>'.$escape($m[1]).'</strong>';}
        else{
            $chunks=preg_split('/(https:\/\/[^\s<>"\x27]+)/u',$token,-1,PREG_SPLIT_DELIM_CAPTURE)?:[$token];
            foreach($chunks as $chunk){$url=rtrim($chunk,'.;,)]');$suffix=substr($chunk,strlen($url));
                if(str_starts_with($url,'https://') && filter_var($url,FILTER_VALIDATE_URL) && parse_url($url,PHP_URL_USER)===null && parse_url($url,PHP_URL_PASS)===null){$html.='<a href="'.$escape($url).'" target="_blank" rel="noopener noreferrer">'.$escape($url).'</a>'.$escape($suffix);}
                else{$html.=$escape($chunk);}
            }
        }
    }
    return $html;
}
function ankhCatalogueFormat(string $text):string {
    $html='';$paragraph=[];$bullets=[];
    $flushParagraph=static function()use(&$html,&$paragraph):void{if($paragraph){$html.='<p>'.ankhCatalogueInline(implode("\n",$paragraph)).'</p>';$paragraph=[];}};
    $flushBullets=static function()use(&$html,&$bullets):void{if($bullets){$html.='<ul>';foreach($bullets as $item)$html.='<li>'.ankhCatalogueInline($item).'</li>';$html.='</ul>';$bullets=[];}};
    foreach(preg_split('/\R/u',$text)?:[] as $line){
        if(trim($line)===''){$flushParagraph();$flushBullets();}
        elseif(preg_match('/^\s*[-*]\s+(.+)$/u',$line,$m)){$flushParagraph();$bullets[]=$m[1];}
        else{$flushBullets();$paragraph[]=$line;}
    }
    $flushParagraph();$flushBullets();return $html;
}
function ankhCatalogueAdminControl(array $p,array $visibility,array $content=[]): void {
    static $editorStyles=false;
    if(!$editorStyles){$editorStyles=true;?><style>
    .catalogue-guide-intro{margin:22px 0 16px}.catalogue-guide-intro h4{margin:0 0 8px;font-size:18px}.catalogue-guide-intro p{font-size:12px;line-height:1.8}.catalogue-guide-step{border:1px solid #484b42;border-radius:14px;margin:12px 0;background:#151b1c;overflow:hidden}.catalogue-guide-step summary{list-style:none;display:flex;align-items:center;gap:12px;cursor:pointer;padding:17px;font-size:14px;font-weight:650}.catalogue-guide-step summary::-webkit-details-marker{display:none}.catalogue-guide-step summary .step-number{display:grid;place-items:center;width:27px;height:27px;border-radius:50%;background:#e3c4771b;color:#e4c580;flex:none;font-size:11px}.catalogue-guide-step summary>span:nth-child(2){flex:1}.catalogue-guide-step summary small{display:block;color:#aaa99f;font-size:10px;font-weight:400;margin-top:4px;line-height:1.6}.catalogue-guide-step summary .step-toggle{color:#e4c580;font-size:20px}.catalogue-guide-step[open] summary .step-toggle{transform:rotate(45deg)}.catalogue-guide-fields{padding:0 17px 19px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px 14px}.catalogue-guide-fields label{display:block!important;margin:0!important;min-width:0;font-size:12px}.catalogue-guide-fields .wide{grid-column:1/-1}.catalogue-guide-fields input,.catalogue-guide-fields textarea{width:100%;box-sizing:border-box;margin-top:8px!important;min-width:0;font-size:16px!important}.catalogue-guide-fields textarea{min-height:110px;resize:vertical;line-height:1.8}.catalogue-guide-fields small{display:block;color:#a9aaa0;font-size:10px;line-height:1.7;margin-top:6px}.catalogue-guide-fields .guide-description textarea{min-height:170px}.catalogue-guide-price{margin:17px 0;padding:13px 15px;border:1px solid #786b454f;border-radius:12px;font-size:11px;line-height:1.8;color:#d5c698}.catalogue-guide-step summary:focus-visible{outline:2px solid #e4c580;outline-offset:-4px}@media(max-width:480px){.catalogue-guide-fields{grid-template-columns:1fr}.catalogue-guide-step summary{padding:15px}.catalogue-guide-fields{padding:0 15px 17px}}
    </style><?php }
    $visible=(int)($visibility[(int)$p['id']]??0)===1;
    ?><form method="post" enctype="multipart/form-data" class="detail product-settings-form customer-catalogue-control"><?php csrf();?><input type="hidden" name="action" value="product_catalogue_content"><input type="hidden" name="return" value="products"><input type="hidden" name="product_id" value="<?=(int)$p['id']?>"><h3>Customer catalogue</h3><label>Product photograph<input type="file" name="catalogue_photo" accept="image/jpeg,image/png,image/webp" data-catalogue-photo></label><small class="muted" data-photo-status role="status">Choose a JPG, PNG or WebP photo. Large phone photos are resized before upload.</small><img data-photo-preview src="<?=(int)($content['image_bytes']??0)>0?'?api=catalogue-image&amp;id='.(int)$p['id']:''?>" alt="Product photograph preview" width="160" height="160" style="object-fit:contain;border-radius:12px;max-width:100%;background:#f1eee5" <?=(int)($content['image_bytes']??0)>0?'':'hidden'?>><?php if((int)($content['image_bytes']??0)>0):?><label class="check"><input type="checkbox" name="remove_catalogue_photo" value="1"> Remove current photograph</label><?php endif;?><input type="hidden" name="catalogue_editor" value="guided"><div class="catalogue-guide-intro"><h4>Build your product page</h4><p class="muted">Start with the description. Open any section below to add more detail. Everything is optional and empty fields stay hidden.</p></div>
<?php $guideGroups=[
 ['title'=>'Description','hint'=>'What is this product?','fields'=>['description']],
 ['title'=>'Product specifications','hint'=>'Quantity, form, colour and compound details','fields'=>['strength','presentation','appearance','peptide_type','amino_length','weight','formula','sequence','concentration','purity','cas','half_life','pubchem']],
 ['title'=>'Research & literature','hint'=>'Research focus, background and published references','fields'=>['research_focus','research','applications','references']],
 ['title'=>'Storage & laboratory handling','hint'=>'Storage conditions and supplied form','fields'=>['storage','prepared_storage','precautions']],
 ['title'=>'COA & common questions','hint'=>'Your analysis document and any useful answers','fields'=>['coa_url','faq']],
];$guideLabels=ankhCatalogueInformationFields();$guideInfo=(array)($content['information']??[]);
$guideHints=['strength'=>'Quantity per supplied item, as shown on its label.','presentation'=>'For example, the supplied material form and vial size.','appearance'=>'Colour and physical appearance of the supplied material.','peptide_type'=>'The compound or peptide classification.','amino_length'=>'Number of amino-acid residues, where applicable.','weight'=>'Include the unit and relevant compound form.','sequence'=>'The amino-acid sequence, if available.','half_life'=>'Optional: include the experimental model, conditions and reference.','purity'=>'Enter the value and method supported by your own documentation.','pubchem'=>'The identifier for the relevant compound form.','research_focus'=>'A short summary of the research topics.','research'=>'Describe the research background and limitations of the evidence.','references'=>'Paper titles and PubMed / DOI links, one reference per line.','storage'=>'Use the conditions stated on your product label or batch document.','prepared_storage'=>'Optional conditions for laboratory-prepared material, if applicable.','precautions'=>'Supplied form, handling precautions and relevant laboratory notes.','coa_url'=>'Paste an HTTPS link to the analysis document for this product.','faq'=>'Write each question followed by its answer, separated by a blank line.'];
foreach($guideGroups as $groupIndex=>$group):$completed=count(array_filter($group['fields'],static fn(string $key):bool=>$key==='description'?trim((string)($content['description']??$p['description']??''))!=='':trim((string)($guideInfo[$key]??''))!==''));?>
<details class="catalogue-guide-step" <?=$groupIndex===0?'open':''?>><summary><span class="step-number"><?=$groupIndex+1?></span><span><?=e($group['title'])?><small><?=e($group['hint'])?><?=$completed?' · '.$completed.' filled':''?></small></span><span class="step-toggle" aria-hidden="true">+</span></summary><div class="catalogue-guide-fields">
<?php foreach($group['fields'] as $key):$long=$key==='description'||in_array($key,ankhCatalogueLongFields(),true)||$key==='sequence';$wide=$long||in_array($key,['research_focus','coa_url','half_life','storage','prepared_storage'],true);$value=$key==='description'?(string)($content['description']??$p['description']??''):(string)($guideInfo[$key]??'');$limit=$key==='description'?40000:(in_array($key,ankhCatalogueLongFields(),true)?5000:600);?><label class="<?=$wide?'wide ':''?><?=$key==='description'?'guide-description':''?>"><?=e($key==='description'?'Product description':$guideLabels[$key])?><?php if($long):?><textarea name="<?=$key==='description'?'catalogue_description':'catalogue_information['.$key.']'?>" maxlength="<?=$limit?>" rows="<?=$key==='description'?6:3?>"><?=e($value)?></textarea><?php else:?><input type="<?=$key==='coa_url'?'url':'text'?>" name="catalogue_information[<?=$key?>]" maxlength="<?=$limit?>" value="<?=e($value)?>" <?=$key==='coa_url'?'placeholder="https://…"':''?>><?php endif;?><?php if(isset($guideHints[$key])):?><small><?=e($guideHints[$key])?></small><?php endif;?></label><?php endforeach;?>
</div></details><?php endforeach;?>
<p class="catalogue-guide-price">Customers see the price saved above. Family &amp; Friends customers see their saved Family &amp; Friends price, or retail price if none is set.</p><label class="check"><input type="checkbox" name="catalogue_visible" value="1" <?=$visible?'checked':''?>> Visible on customer's dashboard</label><p class="muted"><?=(int)$p['active']===1?'Tick to publish this product, then save catalogue details. Untick and save to hide it.':'This product is inactive. It will stay hidden from customers until you also make it available for new orders.'?></p><button class="quiet" type="submit">Save catalogue details</button></form><?php
}
