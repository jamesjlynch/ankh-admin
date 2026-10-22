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

/** Customer interactions live in the private portal database, never the order database. */
function ankhCustomerInteractionInit(PDO $db):void {
    $db->exec("CREATE TABLE IF NOT EXISTS catalogue_favourites(customer_id INTEGER NOT NULL,product_id INTEGER NOT NULL,preview INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,PRIMARY KEY(customer_id,product_id,preview));
    CREATE TABLE IF NOT EXISTS catalogue_enquiries(id INTEGER PRIMARY KEY,customer_id INTEGER NOT NULL,product_id INTEGER NOT NULL,preview INTEGER NOT NULL DEFAULT 0,product_name TEXT NOT NULL,price INTEGER NOT NULL,message TEXT NOT NULL,reply TEXT NOT NULL DEFAULT '',status TEXT NOT NULL DEFAULT 'Open',created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,request_token TEXT NOT NULL,UNIQUE(customer_id,preview,request_token));
    CREATE INDEX IF NOT EXISTS catalogue_enquiries_customer ON catalogue_enquiries(customer_id,preview,created_at);
    CREATE TABLE IF NOT EXISTS catalogue_enquiry_lines(enquiry_id INTEGER NOT NULL REFERENCES catalogue_enquiries(id) ON DELETE CASCADE,position INTEGER NOT NULL,product_id INTEGER NOT NULL,name TEXT NOT NULL,quantity INTEGER NOT NULL,unit_price INTEGER NOT NULL,PRIMARY KEY(enquiry_id,position));
    CREATE TABLE IF NOT EXISTS customer_reminder_preferences(customer_id INTEGER NOT NULL,preview INTEGER NOT NULL,cycle_id INTEGER NOT NULL,due_date TEXT NOT NULL,snoozed_until TEXT NOT NULL,PRIMARY KEY(customer_id,preview,cycle_id,due_date));
    CREATE TABLE IF NOT EXISTS customer_reminder_requests(id INTEGER PRIMARY KEY,customer_id INTEGER NOT NULL,preview INTEGER NOT NULL,cycle_id INTEGER NOT NULL,product_name TEXT NOT NULL,original_due_date TEXT NOT NULL,original_weeks INTEGER NOT NULL,source_order_id INTEGER NOT NULL,revision TEXT NOT NULL,requested_weeks INTEGER NOT NULL,requested_date TEXT NOT NULL,note TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'Pending',admin_note TEXT NOT NULL DEFAULT '',created_at INTEGER NOT NULL,reviewed_at INTEGER NOT NULL DEFAULT 0,request_token TEXT NOT NULL,UNIQUE(customer_id,preview,request_token));
    CREATE UNIQUE INDEX IF NOT EXISTS customer_reminder_request_pending ON customer_reminder_requests(customer_id,preview,cycle_id) WHERE status='Pending';");
}
function ankhCustomerInteractionStore():PDO {
    $adminConfig=getenv('ANKH_ADMIN_CONFIG')?:dirname(__DIR__,2).'/ankh-admin-config.php';
    $path=getenv('ANKH_PORTAL_CONFIG')?:dirname($adminConfig).'/ankh-customer-portal-config.php';
    $config=is_file($path)?require $path:null;
    if(!is_array($config)||!is_string($config['portal_database_path']??null)||!is_file($config['portal_database_path']))throw new RuntimeException('Customer inbox is unavailable.');
    $db=new PDO('sqlite:'.$config['portal_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000');ankhCustomerInteractionInit($db);return $db;
}
function ankhCustomerFavourites(PDO $db,int $customerId,bool $preview):array {
    $q=$db->prepare('SELECT product_id FROM catalogue_favourites WHERE customer_id=? AND preview=?');$q->execute([$customerId,(int)$preview]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}
function ankhCustomerEnquiries(PDO $db,int $customerId,bool $preview):array {
    $q=$db->prepare('SELECT * FROM catalogue_enquiries WHERE customer_id=? AND preview=? ORDER BY created_at DESC,id DESC LIMIT 100');$q->execute([$customerId,(int)$preview]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row)$row['lines']=ankhCustomerEnquiryLines($db,(int)$row['id']);unset($row);return $rows;
}
function ankhCustomerSaveFavourite(PDO $store,array $product,int $customerId,bool $preview,bool $save):void {
    if($save)$store->prepare('INSERT OR IGNORE INTO catalogue_favourites(customer_id,product_id,preview,created_at) VALUES(?,?,?,?)')->execute([$customerId,$product['id'],(int)$preview,time()]);
    else $store->prepare('DELETE FROM catalogue_favourites WHERE customer_id=? AND product_id=? AND preview=?')->execute([$customerId,$product['id'],(int)$preview]);
}
function ankhCustomerCreateEnquiry(PDO $store,array $product,int $customerId,bool $preview,string $message,string $token,array $lines=[]):int {
    $message=trim($message);
    if($message===''||strlen($message)>20000||!preg_match('//u',$message)||preg_match_all('/./us',$message)>5000)throw new InvalidArgumentException('Enter a question of up to 5,000 characters.');
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('Refresh the page before sending your enquiry.');
    if(count($lines)>25)throw new InvalidArgumentException('Select up to 25 products per enquiry.');
    foreach($lines as $line)if(!is_array($line)||!is_int($line['product_id']??null)||$line['product_id']<1||!is_string($line['name']??null)||!is_int($line['quantity']??null)||$line['quantity']<1||$line['quantity']>999||!is_int($line['unit_price']??null)||$line['unit_price']<0)throw new InvalidArgumentException('Check the selected products.');
    $store->exec('BEGIN IMMEDIATE');
    try{
        $q=$store->prepare('SELECT id FROM catalogue_enquiries WHERE customer_id=? AND preview=? AND request_token=?');$q->execute([$customerId,(int)$preview,$token]);
        if($id=$q->fetchColumn()){$store->exec('COMMIT');return (int)$id;}
        $q=$store->prepare('SELECT COUNT(*) FROM catalogue_enquiries WHERE customer_id=? AND preview=? AND created_at>?');$q->execute([$customerId,(int)$preview,time()-3600]);
        if((int)$q->fetchColumn()>=10)throw new InvalidArgumentException('You have sent several enquiries. Please try again later.');
        $store->prepare('INSERT INTO catalogue_enquiries(customer_id,product_id,preview,product_name,price,message,created_at,updated_at,request_token) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$customerId,$product['id'],(int)$preview,$product['name'],$product['price'],$message,time(),time(),$token]);
        $id=(int)$store->lastInsertId();
        if($lines){$insert=$store->prepare('INSERT INTO catalogue_enquiry_lines(enquiry_id,position,product_id,name,quantity,unit_price) VALUES(?,?,?,?,?,?)');foreach(array_values($lines) as $position=>$line)$insert->execute([$id,$position,$line['product_id'],$line['name'],$line['quantity'],$line['unit_price']]);}
        $store->exec('COMMIT');return $id;
    }catch(Throwable $e){$store->exec('ROLLBACK');throw $e;}
}
function ankhCustomerReply(PDO $store,int $id,string $reply,string $status):void {
    $reply=trim($reply);
    if(!in_array($status,['Open','Answered','Closed'],true)||strlen($reply)>20000||!preg_match('//u',$reply)||preg_match_all('/./us',$reply)>5000||($status==='Answered'&&$reply===''))throw new InvalidArgumentException('Choose a status and enter a reply of up to 5,000 characters. Answered enquiries need a reply.');
    $q=$store->prepare('UPDATE catalogue_enquiries SET reply=?,status=?,updated_at=? WHERE id=?');$q->execute([$reply,$status,time(),$id]);
    if(!$q->rowCount())throw new InvalidArgumentException('Enquiry could not be found.');
}
function ankhPortalCatalogueActions(PDO $store,PDO $admin,array $customer,bool $preview):void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!in_array($_POST['action']??null,['catalogue_favourite','catalogue_enquire'],true))return;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)($_SESSION['portal_csrf']??''),$_POST['csrf'])){http_response_code(400);exit('Invalid request.');}
    try{
        ankhCustomerInteractionInit($store);
        $product=null;$id=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT);
        foreach(ankhCatalogueProducts($admin,(int)$customer['id']) as $p)if($p['id']===$id){$product=$p;break;}
        if(!$product)throw new InvalidArgumentException('This product is no longer available in the catalogue.');
        if($_POST['action']==='catalogue_favourite'){
            ankhCustomerSaveFavourite($store,$product,(int)$customer['id'],$preview,($_POST['save']??'')==='1');
            $_SESSION['catalogue_flash']=($_POST['save']??'')==='1'?'Product saved to favourites.':'Product removed from favourites.';
            $extra=($_POST['saved']??'')==='1'?'&saved=1':'';
            if((int)($_POST['return_product']??0)===$product['id'])$extra.='&product='.$product['id'];
        }else{
            if(!is_string($_POST['message']??null)||!is_string($_POST['request_token']??null))throw new InvalidArgumentException('Enter your question.');
            ankhCustomerCreateEnquiry($store,$product,(int)$customer['id'],$preview,$_POST['message'],$_POST['request_token']);
            $_SESSION['catalogue_flash']='Your enquiry has been sent. You can read the response here when ANKH replies.';$extra='&view_enquiries=1';
        }
        $url=($preview?'./preview-live.php?customer_id='.(int)$customer['id'].'&':'./?').'tab=catalogue'.$extra;
        header('Location: '.$url,true,303);exit;
    }catch(InvalidArgumentException $e){$GLOBALS['catalogueInteractionError']=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH catalogue interaction: '.$e->getMessage());$GLOBALS['catalogueInteractionError']='Your request could not be saved. Please try again.';}
}
function ankhCustomerInbox(PDO $admin):void {
    ?><div class="heading"><div><h1>Customer enquiries</h1><p class="muted">Product questions and replies. Staff preview tests are labelled separately.</p></div><a class="quick-action" href="?view=more">← More</a></div><?php
    try{$store=ankhCustomerInteractionStore();$rows=$store->query('SELECT * FROM catalogue_enquiries ORDER BY CASE status WHEN "Open" THEN 0 WHEN "Answered" THEN 1 ELSE 2 END,created_at DESC LIMIT 100')->fetchAll();foreach($rows as &$row)$row['lines']=ankhCustomerEnquiryLines($store,(int)$row['id']);unset($row);}
    catch(Throwable $e){error_log('ANKH customer inbox: '.$e->getMessage());?><div class="panel"><p>The customer inbox is temporarily unavailable. Please try again later.</p></div><?php return;}
    if(!$rows){?><div class="panel"><h2>No enquiries yet</h2><p>Customer product questions will appear here.</p></div><?php }
    foreach($rows as $row){$q=$admin->prepare('SELECT name FROM customers WHERE id=?');$q->execute([$row['customer_id']]);$name=$q->fetchColumn()?:'Customer #'.$row['customer_id'];?><details class="panel" style="margin-bottom:16px"><summary style="cursor:pointer;list-style:none"><small><?=e($row['status'])?> · <?=e(date('d M Y H:i',$row['created_at']))?> <?=$row['preview']?' · STAFF TEST':''?></small><h2><?=e($row['product_name'])?></h2><p><?=e($name)?> · Enquiry #<?=(int)$row['id']?> · <?=empty($row['lines'])?'Displayed price':'Displayed product total'?> £<?=number_format($row['price']/100,2)?></p></summary><?php ankhCustomerEnquiryLinesView($row['lines']);?><p style="white-space:pre-wrap;overflow-wrap:anywhere"><?=e($row['message'])?></p><form method="post"><?php csrf();?><input type="hidden" name="action" value="catalogue_enquiry_reply"><input type="hidden" name="enquiry_id" value="<?=(int)$row['id']?>"><label>Reply shown to the customer<textarea name="reply" maxlength="5000" rows="5"><?=e($row['reply'])?></textarea></label><label>Status<select name="enquiry_status"><?php foreach(['Open','Answered','Closed'] as $state):?><option <?=$state===$row['status']?'selected':''?>><?=$state?></option><?php endforeach;?></select></label><button type="submit">Save reply &amp; status</button></form></details><?php }
    ankhReminderRequestsInbox($admin,$store);
}

/** Add durable links only for unambiguous legacy identities; keep original business records intact. */
function ankhCatalogueIdentityPhone(string $phone):?string {
    $phone=preg_replace('/[\s().-]+/','',trim($phone))??'';
    if(str_starts_with($phone,'0044'))$phone='+44'.substr($phone,4);
    elseif(str_starts_with($phone,'07'))$phone='+44'.substr($phone,1);
    elseif(str_starts_with($phone,'447'))$phone='+'.$phone;
    return preg_match('/^\+447\d{9}$/D',$phone)?$phone:null;
}
function ankhCatalogueLinkIdentities(PDO $db):void {
    if($db->inTransaction())throw new RuntimeException('Identity links must be updated outside an order transaction.');
    $db->beginTransaction();
    try{
        $db->exec('CREATE TABLE IF NOT EXISTS customer_order_links(order_id INTEGER PRIMARY KEY REFERENCES orders(id) ON DELETE CASCADE,customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,source_name TEXT NOT NULL,source_phone TEXT NOT NULL);
        CREATE INDEX IF NOT EXISTS customer_order_links_customer ON customer_order_links(customer_id,order_id)');
        $customers=[];foreach($db->query('SELECT id,name,phone FROM customers') as $customer){$phone=ankhCatalogueIdentityPhone((string)$customer['phone']);if($phone!==null && trim((string)$customer['name'])!=='')$customers[strtolower(trim((string)$customer['name'])).'|'.$phone][]=(int)$customer['id'];}
        $insert=$db->prepare('INSERT INTO customer_order_links(order_id,customer_id,source_name,source_phone) VALUES(?,?,?,?) ON CONFLICT(order_id) DO UPDATE SET customer_id=excluded.customer_id,source_name=excluded.source_name,source_phone=excluded.source_phone');
        $remove=$db->prepare('DELETE FROM customer_order_links WHERE order_id=?');
        $rows=$db->query('SELECT o.id,o.customer,o.phone FROM orders o LEFT JOIN customer_order_links l ON l.order_id=o.id WHERE l.order_id IS NULL OR l.source_name<>o.customer OR l.source_phone<>o.phone')->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $order){$phone=ankhCatalogueIdentityPhone((string)$order['phone']);$ids=$phone===null?[]:($customers[strtolower(trim((string)$order['customer'])).'|'.$phone]??[]);
            if(count($ids)===1)$insert->execute([(int)$order['id'],$ids[0],(string)$order['customer'],(string)$order['phone']]);else $remove->execute([(int)$order['id']]);
        }
        $db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
}

function ankhCustomerEnquiryLines(PDO $db,int $id):array {
    $q=$db->prepare('SELECT product_id,name,quantity,unit_price FROM catalogue_enquiry_lines WHERE enquiry_id=? ORDER BY position');$q->execute([$id]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function ankhCustomerEnquiryLinesView(array $lines):void {
    if(!$lines)return;$escape=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    ?><div class="customer-enquiry-lines"><table><thead><tr><th>Product</th><th>Quantity</th><th>Unit price</th><th>Total</th></tr></thead><tbody><?php foreach($lines as $line):?><tr><td><?=$escape($line['name'])?></td><td><?=(int)$line['quantity']?></td><td>£<?=number_format($line['unit_price']/100,2)?></td><td>£<?=number_format($line['quantity']*$line['unit_price']/100,2)?></td></tr><?php endforeach;?></tbody></table></div><?php
}
function ankhReminderSignature(array $cycle,array $order):string {
    return hash('sha256',json_encode([(int)$cycle['id'],(string)$cycle['product_name'],(string)$cycle['next_due_date'],(int)($cycle['cycle_weeks']??0),(int)($cycle['cycle_days']??0),(int)$cycle['last_order_id'],(string)$order['delivery_date'],(string)($cycle['presentation']??''),max(1,(int)($cycle['quantity']??0))],JSON_THROW_ON_ERROR));
}
function ankhReminderDate(string $value):bool {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Europe/London'));return $date && $date->format('Y-m-d')===$value;
}
function ankhReminderRequest(PDO $store,int $customerId,bool $preview,array $reminder,int $weeks,string $date,string $note,string $token):int {
    $today=new DateTimeImmutable('today',new DateTimeZone('Europe/London'));$note=trim($note);
    if($weeks<1||$weeks>52||!ankhReminderDate($date)||$date<$today->format('Y-m-d')||$date>$today->modify('+366 days')->format('Y-m-d'))throw new InvalidArgumentException('Choose a repeat interval of 1–52 weeks and a next date within the coming year.');
    if(strlen($note)>8000||!preg_match('//u',$note)||preg_match_all('/./us',$note)>2000||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('Use a note of up to 2,000 characters and refresh the page if needed.');
    $store->exec('BEGIN IMMEDIATE');
    try{
        $q=$store->prepare('SELECT id FROM customer_reminder_requests WHERE customer_id=? AND preview=? AND request_token=?');$q->execute([$customerId,(int)$preview,$token]);if($id=$q->fetchColumn()){$store->exec('COMMIT');return (int)$id;}
        $q=$store->prepare("SELECT id FROM customer_reminder_requests WHERE customer_id=? AND preview=? AND cycle_id=? AND status='Pending'");$q->execute([$customerId,(int)$preview,$reminder['id']]);if($q->fetchColumn())throw new InvalidArgumentException('A change request for this reminder is already awaiting review.');
        $q=$store->prepare('SELECT COUNT(*) FROM customer_reminder_requests WHERE customer_id=? AND preview=? AND created_at>?');$q->execute([$customerId,(int)$preview,time()-3600]);if((int)$q->fetchColumn()>=5)throw new InvalidArgumentException('Please wait before sending another reminder request.');
        $store->prepare('INSERT INTO customer_reminder_requests(customer_id,preview,cycle_id,product_name,original_due_date,original_weeks,source_order_id,revision,requested_weeks,requested_date,note,created_at,request_token) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$customerId,(int)$preview,$reminder['id'],$reminder['product'],$reminder['due_date'],$reminder['weeks'],$reminder['last_order_id'],$reminder['revision'],$weeks,$date,$note,time(),$token]);
        $id=(int)$store->lastInsertId();$store->exec('COMMIT');return $id;
    }catch(Throwable $e){$store->exec('ROLLBACK');throw $e;}
}
function ankhReviewReminderRequest(PDO $admin,PDO $store,int $id,string $decision,string $note):void {
    $note=trim($note);
    if(!in_array($decision,['approve','decline'],true)||strlen($note)>8000||!preg_match('//u',$note)||preg_match_all('/./us',$note)>2000)throw new InvalidArgumentException('Check the decision and use a note of up to 2,000 characters.');
    if($admin->inTransaction())throw new RuntimeException('Finish the current change before reviewing a reminder.');
    $file=(string)$store->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'];if($file===''||!is_file($file))throw new RuntimeException('The private request database is unavailable.');
    // Keep the attachment for this request: Admin can have active read cursors that prevent DETACH.
    $attached=false;foreach($admin->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $database){if($database['name']!=='reminder_requests')continue;if($database['file']!==$file)throw new RuntimeException('Unexpected reminder database attachment.');$attached=true;}
    if(!$attached){$attach=$admin->prepare('ATTACH DATABASE ? AS reminder_requests');$attach->execute([$file]);$attach=null;}
    try{
        $admin->exec('BEGIN IMMEDIATE');
        try{
            $q=$admin->prepare('SELECT * FROM reminder_requests.customer_reminder_requests WHERE id=?');$q->execute([$id]);$request=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$request||$request['status']!=='Pending')throw new InvalidArgumentException('This request has already been reviewed or is unavailable.');
            if($decision==='approve'){
                $q=$admin->prepare('SELECT * FROM reta_cycles WHERE id=? AND active=1');$q->execute([$request['cycle_id']]);$cycle=$q->fetch(PDO::FETCH_ASSOC);
                $q=$admin->prepare('SELECT * FROM orders WHERE id=?');$q->execute([(int)($cycle['last_order_id']??0)]);$order=$q->fetch(PDO::FETCH_ASSOC);
                $q=$admin->prepare('SELECT * FROM customers WHERE id=? AND archived=0');$q->execute([$request['customer_id']]);$customer=$q->fetch(PDO::FETCH_ASSOC);
                if(!$cycle||!$order||!$customer||$order['status']==='Cancelled'||trim((string)$order['delivery_date'])==='')throw new InvalidArgumentException('This reminder is no longer eligible. Decline the request and ask the customer to submit a new one.');
                $q=$admin->prepare('SELECT customer_id FROM customer_order_links WHERE order_id=?');$q->execute([$order['id']]);$owner=$q->fetchColumn();$q->closeCursor();
                if((int)$owner!==(int)$request['customer_id']||!hash_equals($request['revision'],ankhReminderSignature($cycle,$order)))throw new InvalidArgumentException('The reminder or order ownership has changed. Decline this stale request and ask for a new one.');
                if(!ankhReminderDate($request['requested_date'])||$request['requested_date']<(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d'))throw new InvalidArgumentException('The requested date has passed. Ask the customer for a new date.');
                if(!(int)$request['preview'])$admin->prepare('UPDATE reta_cycles SET cycle_weeks=?,cycle_days=?,next_due_date=?,updated=? WHERE id=?')->execute([$request['requested_weeks'],$request['requested_weeks']*7,$request['requested_date'],gmdate('c'),$request['cycle_id']]);
            }
            $admin->prepare('UPDATE reminder_requests.customer_reminder_requests SET status=?,admin_note=?,reviewed_at=? WHERE id=?')->execute([$decision==='approve'?'Approved':'Declined',$note,time(),$id]);
            $admin->exec('COMMIT');
        }catch(Throwable $e){$admin->exec('ROLLBACK');error_log('ANKH reminder review: '.$e->getMessage());throw $e;}
    }finally{$q=null;}
}
function ankhReminderRequestsInbox(PDO $admin,PDO $store):void {
    $rows=$store->query("SELECT * FROM customer_reminder_requests ORDER BY CASE status WHEN 'Pending' THEN 0 ELSE 1 END,id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    ?><section id="reminder-requests"><h2>Reminder change requests</h2><p class="muted">Approve or decline requested dates and intervals. Customer snoozes only silence their personal reminders.</p><?php if(!$rows):?><p>No reminder change requests yet.</p><?php endif;?><?php foreach($rows as $row):$q=$admin->prepare('SELECT name FROM customers WHERE id=?');$q->execute([$row['customer_id']]);$name=$q->fetchColumn()?:'Customer #'.$row['customer_id'];?><details class="panel" style="margin-bottom:16px"><summary style="cursor:pointer"><strong><?=e($row['product_name'])?></strong> · <?=e($row['status'])?><?php if($row['preview']):?> · STAFF TEST — approval will not change the live reminder<?php endif;?><p><?=e($name)?> · Request #<?=(int)$row['id']?></p></summary><p>Recorded: every <?=(int)$row['original_weeks']?> weeks · <?=e($row['original_due_date'])?></p><p>Requested: every <?=(int)$row['requested_weeks']?> weeks · <?=e($row['requested_date'])?></p><p style="white-space:pre-wrap"><?=e($row['note'])?></p><?php if($row['status']==='Pending'):?><form method="post"><?php csrf();?><input type="hidden" name="action" value="reminder_request_review"><input type="hidden" name="request_id" value="<?=(int)$row['id']?>"><label>Response to customer<textarea name="admin_note" maxlength="2000" rows="3"></textarea></label><label>Decision<select name="decision"><option value="approve">Approve requested date and interval</option><option value="decline">Decline request</option></select></label><button type="submit">Save decision</button></form><?php else:?><p style="white-space:pre-wrap"><?=e($row['admin_note'])?></p><?php endif;?></details><?php endforeach;?></section><?php
}
