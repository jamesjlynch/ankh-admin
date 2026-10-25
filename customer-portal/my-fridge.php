<?php
declare(strict_types=1);
/**
 * ANKH private inventory tracker.
 * Users confirm delivered research stock; projections use only amounts entered by them.
 * No clinical advice, dosing suggestions, medication reminders, or sales automation.
 */
function portalFridgeInit(PDO $db):void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_fridge_items(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      customer_id INTEGER NOT NULL,
      preview INTEGER NOT NULL CHECK(preview IN (0,1)),
      source_order_id INTEGER NOT NULL,
      source_line_index INTEGER NOT NULL,
      product_name TEXT NOT NULL,
      presentation TEXT NOT NULL DEFAULT '',
      units_received INTEGER NOT NULL,
      unit TEXT NOT NULL CHECK(unit IN ('mg','ml','items')),
      unit_amount_milli INTEGER NOT NULL,
      remaining_milli INTEGER NOT NULL CHECK(remaining_milli>=0),
      rate_milli INTEGER NOT NULL DEFAULT 0 CHECK(rate_milli>=0),
      rate_period TEXT NOT NULL DEFAULT 'none' CHECK(rate_period IN ('none','day','week')),
      as_of_date TEXT NOT NULL,
      archived INTEGER NOT NULL DEFAULT 0 CHECK(archived IN (0,1)),
      created_at INTEGER NOT NULL,
      updated_at INTEGER NOT NULL,
      UNIQUE(customer_id,preview,source_order_id,source_line_index)
    );
    CREATE INDEX IF NOT EXISTS portal_fridge_owner ON portal_fridge_items(customer_id,preview,archived,updated_at);
    CREATE TABLE IF NOT EXISTS portal_fridge_activity (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      customer_id INTEGER NOT NULL,
      preview INTEGER NOT NULL CHECK(preview IN (0,1)),
      item_id INTEGER NOT NULL,
      product_name TEXT NOT NULL,
      event TEXT NOT NULL CHECK(event IN ('added','updated','archived','restored')),
      remaining_milli INTEGER NOT NULL,
      rate_milli INTEGER NOT NULL,
      rate_period TEXT NOT NULL,
      unit TEXT NOT NULL,
      created_at INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS portal_fridge_activity_owner ON portal_fridge_activity(customer_id,preview,id);");
}
function portalFridgeRecordActivity(PDO $db,int $customerId,bool $preview,int $id,array $row,string $event):void {
    $db->prepare('INSERT INTO portal_fridge_activity(customer_id,preview,item_id,product_name,event,remaining_milli,rate_milli,rate_period,unit,created_at)
      VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([
      $customerId,(int)$preview,$id,(string)$row['product_name'],$event,
      max(0,(int)$row['remaining_milli']),max(0,(int)$row['rate_milli']),
      (string)$row['rate_period'],(string)$row['unit'],time()
    ]);
}
function portalFridgeHistory(PDO $db,int $customerId,bool $preview,int $limit=18):array {
    portalFridgeInit($db);
    $limit=max(1,min(80,$limit));
    $q=$db->prepare('SELECT item_id,product_name,event,remaining_milli,rate_milli,rate_period,unit,created_at
      FROM portal_fridge_activity WHERE customer_id=? AND preview=? ORDER BY id DESC LIMIT '.$limit);
    $q->execute([$customerId,(int)$preview]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function portalFridgeDate():string {
    return (new DateTimeImmutable('now',new DateTimeZone('Europe/London')))->format('Y-m-d');
}
/** Exact decimal with at most three places; reject scientific notation, negative and invalid input. */
function portalFridgeParseAmount(mixed $input,bool $required=true):int {
    if(!is_string($input)||!preg_match('/^(?:[0-9]{1,6})(?:\.[0-9]{1,3})?$/D',trim($input))){
        if(!$required && $input==='')return 0;
        throw new InvalidArgumentException('Enter a valid non-negative quantity, with up to three decimal places.');
    }
    $parts=explode('.',trim($input),2);
    return (int)$parts[0]*1000+(int)str_pad($parts[1]??'',3,'0');
}
function portalFridgeFormat(int $amount):string {
    $text=number_format($amount/1000,3,'.','');
    return rtrim(rtrim($text,'0'),'.') ?: '0';
}
function portalFridgeUnit(array $line):array {
    $text=(string)($line['name']??'').' '.(string)($line['presentation']??'');
    if(preg_match('/(?<![A-Za-z0-9])([0-9]{1,6}(?:\.[0-9]{1,3})?)\s*(mg|ml)\b/i',$text,$m)){
        try{
            $strength=portalFridgeParseAmount($m[1]);
            if($strength>0)return ['unit'=>strtolower($m[2]),'per_item'=>$strength];
        }catch(InvalidArgumentException $e){}
    }
    return ['unit'=>'items','per_item'=>1000];
}
/** Only items from this customer's currently authorized delivered orders may be imported. */
function portalFridgeCandidates(array $orders,array $items):array {
    $existing=[];foreach($items as $item)$existing[(int)$item['source_order_id'].':'.(int)$item['source_line_index']]=true;
    $result=[];
    foreach($orders as $order){
        if(portalStatus($order)!=='Delivered')continue;
        foreach(($order['lines']??[]) as $index=>$line){
            if(!is_array($line)||trim((string)($line['name']??''))===''||(int)($line['quantity']??0)<=0)continue;
            $key=(int)$order['id'].':'.$index;
            if(isset($existing[$key]))continue;
            $measure=portalFridgeUnit($line);
            $result[]=[
                'order_id'=>(int)$order['id'],'line_index'=>(int)$index,
                'name'=>(string)$line['name'],'presentation'=>(string)($line['presentation']??''),
                'quantity'=>(int)$line['quantity'],'unit'=>$measure['unit'],
                'total_milli'=>min(999999999,$measure['per_item']*(int)$line['quantity']),
                'delivered'=>(string)($order['delivery_date']??'')
            ];
        }
    }
    return $result;
}
function portalFridgeItems(PDO $db,int $customerId,bool $preview):array {
    portalFridgeInit($db);
    $q=$db->prepare('SELECT * FROM portal_fridge_items WHERE customer_id=? AND preview=? AND archived=0 ORDER BY updated_at DESC,id DESC');
    $q->execute([$customerId,(int)$preview]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function portalFridgeOwned(PDO $db,int $customerId,bool $preview,int $id):array {
    $q=$db->prepare('SELECT * FROM portal_fridge_items WHERE id=? AND customer_id=? AND preview=? AND archived=0');
    $q->execute([$id,$customerId,(int)$preview]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new InvalidArgumentException('That inventory item is no longer available in your account.');
    return $row;
}
/** Daily/weekly linear inventory estimate (not a dosing or treatment recommendation). */
function portalFridgeProjection(array $row,?string $today=null):array {
    $zone=new DateTimeZone('Europe/London');
    $date=$today??portalFridgeDate();
    $now=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
    $asOf=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$row['as_of_date'],$zone);
    if(!$now||!$asOf)throw new InvalidArgumentException('Invalid inventory reference date.');
    $days=max(0,(int)$asOf->diff($now)->format('%r%a'));
    $daily=($row['rate_period']==='day'?(int)$row['rate_milli']:($row['rate_period']==='week'?(int)$row['rate_milli']/7:0));
    $remaining=max(0,(int)round((int)$row['remaining_milli']-$daily*$days));
    $runout=null;$daysLeft=null;
    if($daily>0){
        $daysLeft=(int)ceil($remaining/$daily);
        if($daysLeft<=3650)$runout=$now->modify('+'.$daysLeft.' days')->format('Y-m-d');
    }
    $fraction=(int)$row['unit_amount_milli']>0?(int)min(100,round(100*$remaining/(int)$row['unit_amount_milli'])):0;
    return ['remaining_milli'=>$remaining,'daily_milli'=>$daily,'runout_date'=>$runout,'days_left'=>$daysLeft,
      'percent'=>$fraction,'estimated'=>$days>0&&$daily>0];
}
function portalFridgeAdd(PDO $db,int $customerId,bool $preview,array $orders,int $orderId,int $lineIndex):int {
    portalFridgeInit($db);
    $chosen=null;
    foreach($orders as $order)if((int)$order['id']===$orderId&&portalStatus($order)==='Delivered'){
        $line=$order['lines'][$lineIndex]??null;
        if(is_array($line)&&(int)($line['quantity']??0)>0&&trim((string)($line['name']??''))!=='')$chosen=$line;
        break;
    }
    if(!$chosen)throw new InvalidArgumentException('Only products from your delivered orders can be added.');
    $measure=portalFridgeUnit($chosen);
    $units=(int)$chosen['quantity'];$total=$units*$measure['per_item'];
    if($units>999||$total<=0||$total>999999999)throw new InvalidArgumentException('Please check the received quantity.');
    $q=$db->prepare('SELECT id,archived FROM portal_fridge_items WHERE customer_id=? AND preview=? AND source_order_id=? AND source_line_index=?');
    $q->execute([$customerId,(int)$preview,$orderId,$lineIndex]);$existing=$q->fetch(PDO::FETCH_ASSOC);
    if($existing && (int)$existing['archived']===0)return (int)$existing['id'];
    if(!$existing){
        $q=$db->prepare('SELECT COUNT(*) FROM portal_fridge_items WHERE customer_id=? AND preview=? AND archived=0');
        $q->execute([$customerId,(int)$preview]);
        if((int)$q->fetchColumn()>=80)throw new InvalidArgumentException('Your fridge is full. Archive an old entry before adding more.');
    }
    $now=time();$date=portalFridgeDate();
    if($existing){
        $db->prepare("UPDATE portal_fridge_items SET archived=0,product_name=?,presentation=?,units_received=?,
          unit=?,unit_amount_milli=?,remaining_milli=?,rate_milli=0,rate_period='none',as_of_date=?,updated_at=?
          WHERE id=? AND customer_id=? AND preview=?")
          ->execute([(string)$chosen['name'],(string)($chosen['presentation']??''),$units,$measure['unit'],$total,$total,$date,$now,(int)$existing['id'],$customerId,(int)$preview]);
        $restored=portalFridgeOwned($db,$customerId,$preview,(int)$existing['id']);
        portalFridgeRecordActivity($db,$customerId,$preview,(int)$existing['id'],$restored,'restored');
        return (int)$existing['id'];
    }
    $db->prepare("INSERT INTO portal_fridge_items(customer_id,preview,source_order_id,source_line_index,product_name,
       presentation,units_received,unit,unit_amount_milli,remaining_milli,rate_milli,rate_period,as_of_date,created_at,updated_at)
       VALUES(?,?,?,?,?,?,?,?,?,?,0,'none',?,?,?)")->execute([
        $customerId,(int)$preview,$orderId,$lineIndex,(string)$chosen['name'],(string)($chosen['presentation']??''),
        $units,$measure['unit'],$total,$total,$date,$now,$now]);
    $id=(int)$db->lastInsertId();
    $added=portalFridgeOwned($db,$customerId,$preview,$id);
    portalFridgeRecordActivity($db,$customerId,$preview,$id,$added,'added');
    return $id;
}
function portalFridgeUpdate(PDO $db,int $customerId,bool $preview,int $id,mixed $remaining,mixed $rate,mixed $period):void {
    portalFridgeInit($db);
    $item=portalFridgeOwned($db,$customerId,$preview,$id);
    $balance=portalFridgeParseAmount($remaining);
    $rateMilli=portalFridgeParseAmount($rate===null||$rate===''?'0':$rate);
    if(!is_string($period)||!in_array($period,['none','day','week'],true))throw new InvalidArgumentException('Choose a daily or weekly interval.');
    if($period==='none')$rateMilli=0;
    if($rateMilli===0)$period='none';
    if($balance>999999999||$rateMilli>999999999)throw new InvalidArgumentException('Please enter a smaller quantity.');
    $db->prepare('UPDATE portal_fridge_items SET remaining_milli=?,rate_milli=?,rate_period=?,as_of_date=?,updated_at=? WHERE id=? AND customer_id=? AND preview=? AND archived=0')
      ->execute([$balance,$rateMilli,$period,portalFridgeDate(),time(),$id,$customerId,(int)$preview]);
    $after=portalFridgeOwned($db,$customerId,$preview,$id);
    portalFridgeRecordActivity($db,$customerId,$preview,$id,$after,'updated');
}
function portalFridgeArchive(PDO $db,int $customerId,bool $preview,int $id):void {
    $before=portalFridgeOwned($db,$customerId,$preview,$id);
    $db->prepare('UPDATE portal_fridge_items SET archived=1,updated_at=? WHERE id=? AND customer_id=? AND preview=?')
      ->execute([time(),$id,$customerId,(int)$preview]);
    portalFridgeRecordActivity($db,$customerId,$preview,$id,$before,'archived');
}
function portalFridgeController(PDO $store,array $customer,array $orders,bool $preview):void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!in_array($_POST['action']??null,['fridge_add','fridge_update','fridge_archive'],true))return;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)($_SESSION['portal_csrf']??''),$_POST['csrf'])){http_response_code(400);exit('Invalid request.');}
    try{
        $customerId=(int)$customer['id'];
        $id=filter_var($_POST['item_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
        switch($_POST['action']){
            case 'fridge_add':
                $oid=filter_var($_POST['order_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
                $index=filter_var($_POST['line_index']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
                if($index===false||$index===null)throw new InvalidArgumentException('Select a valid product.');
                portalFridgeAdd($store,$customerId,$preview,$orders,$oid,(int)$index);
                $_SESSION['fridge_flash']='Added to My Fridge. Confirm the amount currently remaining to make your estimate accurate.';
                break;
            case 'fridge_update':
                portalFridgeUpdate($store,$customerId,$preview,$id,$_POST['remaining']??null,$_POST['rate']??null,$_POST['period']??null);
                $_SESSION['fridge_flash']='Your fridge estimate has been updated.';
                break;
            case 'fridge_archive':
                portalFridgeArchive($store,$customerId,$preview,$id);
                $_SESSION['fridge_flash']='Product archived from My Fridge. Your order record is unchanged.';
                break;
        }
        header('Location: '.($preview?'./preview-live.php?customer_id='.$customerId.'&':'./?').'tab=fridge',true,303);exit;
    }catch(InvalidArgumentException $e){$GLOBALS['fridgeError']=$e->getMessage();}
    catch(Throwable $e){error_log('ANKH fridge inventory: '.$e->getMessage());$GLOBALS['fridgeError']='Unable to save the inventory update. Please try again.';}
}
