<?php
declare(strict_types=1);
/**
 * Standalone storefront commerce policy.
 * Deny-by-default: neither public visibility nor a research disclaimer makes a
 * product purchasable. This module never mutates the Admin order database.
 */
function sfCommerceInit(PDO $db):void {
    $db->exec("CREATE TABLE IF NOT EXISTS storefront_checkout_eligibility (
       product_id INTEGER PRIMARY KEY, approved INTEGER NOT NULL DEFAULT 0 CHECK(approved IN(0,1)),
       reviewer TEXT NOT NULL, review_note TEXT NOT NULL, updated_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS storefront_business_facts (
       id INTEGER PRIMARY KEY CHECK(id=1), fulfilment TEXT NOT NULL DEFAULT '',
       dispatch TEXT NOT NULL DEFAULT '', support TEXT NOT NULL DEFAULT '',
       company TEXT NOT NULL DEFAULT '', updated_at INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS storefront_guest_requests (
       id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT NOT NULL UNIQUE, placed_at INTEGER NOT NULL,
       name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT NOT NULL, address TEXT NOT NULL,
       note TEXT NOT NULL DEFAULT '', lines_json TEXT NOT NULL, subtotal INTEGER NOT NULL,
       status TEXT NOT NULL DEFAULT 'Pending staff review',
       CHECK(subtotal>=0)
    );
    CREATE TABLE IF NOT EXISTS storefront_guest_limiter (
       fingerprint TEXT PRIMARY KEY, counter INTEGER NOT NULL, expires_at INTEGER NOT NULL
    );");
}
function sfCommerceEligible(PDO $portal,PDO $admin):array {
    $has=$portal->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='storefront_checkout_eligibility'")->fetchColumn();
    if(!$has)return [];
    $q=$portal->query("SELECT product_id FROM storefront_checkout_eligibility WHERE approved=1");
    $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    if(!$ids)return [];
    // Only accessory products, explicitly published and active. No GLP-1/medicine sale toggle.
    $hasVis=$admin->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='customer_catalogue_visibility'")->fetchColumn();
    if(!$hasVis)return [];
    $result=[];
    $stmt=$admin->prepare("SELECT p.id,p.name,p.price FROM products p JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1 WHERE p.id=? AND p.active=1");
    foreach($ids as $id){
        $stmt->execute([$id]);$p=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$p||!function_exists('ankhPublicType')||ankhPublicType((string)$p['name'])!=='Research accessory')continue;
        $price=(int)$p['price'];
        if($price<1)continue;
        $result[(int)$p['id']]=['id'=>(int)$p['id'],'name'=>(string)$p['name'],'price'=>$price];
    }
    return $result;
}
function sfCommerceFacts(PDO $db):?array {
    $has=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='storefront_business_facts'")->fetchColumn();
    if(!$has)return null;
    $row=$db->query('SELECT company,fulfilment,dispatch,support FROM storefront_business_facts WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}
function sfCommerceSaveFacts(PDO $db,array $input):void {
    $fields=['company'=>160,'fulfilment'=>700,'dispatch'=>700,'support'=>300];$out=[];
    foreach($fields as $key=>$limit){
        $val=$input[$key]??null;
        if(!is_string($val)||strlen($val)>$limit||!preg_match('//u',$val))throw new InvalidArgumentException('Please enter valid business details.');
        $out[$key]=trim($val);
    }
    sfCommerceInit($db);
    $db->prepare('INSERT INTO storefront_business_facts(id,company,fulfilment,dispatch,support,updated_at) VALUES(1,?,?,?,?,?)
      ON CONFLICT(id) DO UPDATE SET company=excluded.company,fulfilment=excluded.fulfilment,
      dispatch=excluded.dispatch,support=excluded.support,updated_at=excluded.updated_at')
      ->execute([$out['company'],$out['fulfilment'],$out['dispatch'],$out['support'],time()]);
}
function sfCommerceSaveEligibility(PDO $db,PDO $admin,int $id,string $decision,string $reviewer,string $basis):void {
    if($id<1 || !in_array($decision,['enable','disable'],true))throw new InvalidArgumentException('Invalid product choice.');
    $q=$admin->prepare('SELECT id,name,active FROM products WHERE id=?');$q->execute([$id]);
    $p=$q->fetch(PDO::FETCH_ASSOC);
    if(!$p)throw new InvalidArgumentException('Product no longer exists.');
    if($decision==='enable'){
        if((int)$p['active']!==1 || !function_exists('ankhPublicType') || ankhPublicType((string)$p['name'])!=='Research accessory')
            throw new InvalidArgumentException('Online checkout is currently limited to approved non-medicinal accessories.');
        if(strlen(trim($reviewer))<3||strlen(trim($reviewer))>120||strlen(trim($basis))<80||strlen(trim($basis))>2000)
            throw new InvalidArgumentException('Provide a reviewer and an eligibility rationale (at least 80 characters).');
        $visible=$admin->prepare('SELECT visible FROM customer_catalogue_visibility WHERE product_id=?');$visible->execute([$id]);
        if((int)$visible->fetchColumn()!==1)throw new InvalidArgumentException('Publish this product in Admin first.');
    }
    sfCommerceInit($db);
    $db->prepare('INSERT INTO storefront_checkout_eligibility(product_id,approved,reviewer,review_note,updated_at) VALUES(?,?,?,?,?)
        ON CONFLICT(product_id) DO UPDATE SET approved=excluded.approved,reviewer=excluded.reviewer,
        review_note=excluded.review_note,updated_at=excluded.updated_at')
        ->execute([$id,$decision==='enable'?1:0,$decision==='enable'?trim($reviewer):'Revoked',$decision==='enable'?trim($basis):'Disabled by staff',time()]);
}
function sfCommerceBuildLines(array $submitted,array $approved):array {
    if(!$submitted||count($submitted)>20)throw new InvalidArgumentException('Choose between one and twenty eligible products.');
    $lines=[];$total=0;
    foreach($submitted as $id=>$quantity){
        if(!is_scalar($id)||!ctype_digit((string)$id)||!is_scalar($quantity)||!preg_match('/^[1-9][0-9]?$/D',(string)$quantity))
            throw new InvalidArgumentException('Review your basket quantities.');
        $id=(int)$id;$qty=(int)$quantity;
        if(!isset($approved[$id]))throw new InvalidArgumentException('A product is no longer eligible for checkout. Please review your basket.');
        $p=$approved[$id];$value=$p['price']*$qty;
        if($value>100000000||$total+$value>100000000)throw new InvalidArgumentException('The basket total is too high.');
        $lines[]=['id'=>$id,'name'=>$p['name'],'quantity'=>$qty,'unit_price'=>$p['price']];
        $total+=$value;
    }
    return ['lines'=>$lines,'subtotal'=>$total];
}
function sfCommerceCreateRequest(PDO $db,array $input,array $approved,string $token,string $fingerprint):int {
    if(!preg_match('/^[a-f0-9]{64}$/D',$token)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint))
        throw new InvalidArgumentException('Refresh checkout and try again.');
    foreach(['name'=>120,'email'=>200,'phone'=>40,'address'=>600,'note'=>600] as $field=>$limit){
        $val=$input[$field]??'';
        if(!is_string($val)||strlen($val)>$limit||!preg_match('//u',$val))
            throw new InvalidArgumentException('Check your contact and delivery details.');
        $input[$field]=trim($val);
    }
    if(strlen($input['name'])<3 || !filter_var($input['email'],FILTER_VALIDATE_EMAIL)||strlen($input['address'])<12
        || !preg_match('/^[+0-9 ()-]{8,40}$/D',$input['phone']))
        throw new InvalidArgumentException('Enter your name, valid email, phone and delivery address.');
    if(($input['confirmed']??'')!=='1')throw new InvalidArgumentException('Please confirm you understand the order request process.');
    if(!is_array($input['items']??null))throw new InvalidArgumentException('Your basket is empty.');
    $computed=sfCommerceBuildLines($input['items'],$approved);
    sfCommerceInit($db);
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT id FROM storefront_guest_requests WHERE token=?');$q->execute([$token]);
        if($existing=$q->fetchColumn()){$db->commit();return (int)$existing;}
        $q=$db->prepare('SELECT counter,expires_at FROM storefront_guest_limiter WHERE fingerprint=?');$q->execute([$fingerprint]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if($r && (int)$r['expires_at']>time() && (int)$r['counter']>=5)throw new InvalidArgumentException('Too many recent requests. Please contact ANKH support.');
        $next=($r&&(int)$r['expires_at']>time())?((int)$r['counter']+1):1;
        $db->prepare('INSERT INTO storefront_guest_limiter(fingerprint,counter,expires_at) VALUES(?,?,?)
          ON CONFLICT(fingerprint) DO UPDATE SET counter=excluded.counter,expires_at=excluded.expires_at')
          ->execute([$fingerprint,$next,time()+3600]);
        $db->prepare('INSERT INTO storefront_guest_requests(token,placed_at,name,email,phone,address,note,lines_json,subtotal)
          VALUES(?,?,?,?,?,?,?,?,?)')->execute([$token,time(),$input['name'],$input['email'],$input['phone'],$input['address'],$input['note'],
              json_encode($computed['lines'],JSON_THROW_ON_ERROR),$computed['subtotal']]);
        $id=(int)$db->lastInsertId();$db->commit();return $id;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}


/**
 * Demo-only basket visibility, completely separate from commercial eligibility.
 * Published profiles are on by default for UI testing; staff may untick each.
 * No prices, orders or payment rights are granted by this flag.
 */
function sfDemoVisible(PDO $store,array $published):array {
    $allowed=[];
    foreach($published as $p)if(isset($p['id'])&&(int)$p['id']>0)$allowed[(int)$p['id']]=true;
    if(!$allowed)return [];
    $table=$store->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='storefront_demo_visibility'")->fetchColumn();
    if(!$table)return $allowed;
    foreach($store->query("SELECT product_id FROM storefront_demo_visibility WHERE enabled=0") as $row)unset($allowed[(int)$row['product_id']]);
    return $allowed;
}
function sfDemoSetVisible(PDO $store,PDO $admin,int $id,bool $enabled):void {
    if($id<1)throw new InvalidArgumentException('Invalid product.');
    $q=$admin->prepare("SELECT p.id FROM products p
      JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1
      WHERE p.id=? AND p.active=1");
    $q->execute([$id]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Product is no longer published.');
    $store->exec("CREATE TABLE IF NOT EXISTS storefront_demo_visibility(
      product_id INTEGER PRIMARY KEY,enabled INTEGER NOT NULL CHECK(enabled IN(0,1)),updated_at INTEGER NOT NULL)");
    $store->prepare("INSERT INTO storefront_demo_visibility(product_id,enabled,updated_at) VALUES(?,?,?)
      ON CONFLICT(product_id) DO UPDATE SET enabled=excluded.enabled,updated_at=excluded.updated_at")
      ->execute([$id,$enabled?1:0,time()]);
}


/** Save a complete staff-selected demo basket list; an empty list turns off all. */
function sfDemoSaveAll(PDO $store,PDO $admin,array $selection):int {
    if(count($selection)>500)throw new InvalidArgumentException('Too many product choices.');
    $chosen=[];
    foreach($selection as $raw){
        if(!is_scalar($raw)||!ctype_digit((string)$raw)||(int)$raw<1)
           throw new InvalidArgumentException('Invalid demo product choice.');
        $chosen[(int)$raw]=true;
    }
    $stmt=$admin->query("SELECT p.id FROM products p
      JOIN customer_catalogue_visibility v ON v.product_id=p.id AND v.visible=1
      WHERE p.active=1");
    $ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    if(array_diff(array_keys($chosen),$ids))throw new InvalidArgumentException('A selected product is no longer published.');
    $store->beginTransaction();
    try{
        foreach($ids as $id)sfDemoSetVisible($store,$admin,$id,isset($chosen[$id]));
        $store->commit();
    }catch(Throwable $e){if($store->inTransaction())$store->rollBack();throw $e;}
    return count($chosen);
}
