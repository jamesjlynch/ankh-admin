<?php
declare(strict_types=1);
// Exercise actual routes and POSTs with a private temporary database.
$root=dirname(__DIR__);$temp=sys_get_temp_dir().'/ankh-supplies-'.bin2hex(random_bytes(6));mkdir($temp,0700);$config=$temp.'/config.php';
file_put_contents($config,'<?php $_SESSION["csrf"]="supplies-fixture"; return '.var_export(['password_hash'=>password_hash('fixture',PASSWORD_DEFAULT),'database_path'=>$temp.'/fixture.sqlite'],true).';');
function supplyCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function supplyRequest(array $get=[],array $post=[]):string{
 global $root,$config;
 $code='putenv('.var_export('ANKH_ADMIN_CONFIG='.$config,true).');$_GET='.var_export($get,true).';$_POST='.var_export($post+['csrf'=>'supplies-fixture'],true).';$_SERVER["REQUEST_METHOD"]='.var_export($post?'POST':'GET',true).';$_SERVER["REMOTE_ADDR"]="127.0.0.1";$_SERVER["HTTP_HOST"]="localhost";$_SERVER["SCRIPT_NAME"]="/index.php";require '.var_export($root.'/index.php',true).';';
 exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1',$output,$exit);$html=implode("\n",$output);supplyCheck($exit===0&&!preg_match('/(?:Fatal error|Warning|Notice):/',$html),'Request error: '.substr($html,-1000));return $html;
}
try{
 $page=supplyRequest(['view'=>'supplies','new'=>1]);supplyCheck(str_contains($page,'Save purchase')&&str_contains($page,'name="tracking_url"'),'Supplies page exposes purchase form');
 $db=new PDO('sqlite:'.$temp.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $before=$db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$orderCount=(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
 $input=['action'=>'business_supply_save','return'=>'supplies','request_key'=>str_repeat('a',32),'item'=>'Labels <sample>','category'=>'Labels','supplier'=>'=Fixture supplier','quantity'=>'3','reference'=>'INV-1','ordered_date'=>'2025-01-02','expected_date'=>'2027-01-01','status'=>'Ordered','item_cost'=>'12.34','shipping'=>'2.50','payment_status'=>'Paid','paid_date'=>'2025-02-03','refunded_date'=>'','product_url'=>'https://example.com/labels?a=1&b=2','tracking_reference'=>'Royal Mail · ABC123','tracking_url'=>'https://example.com/track','notes'=>'500 labels per pack'];
 supplyRequest(['view'=>'supplies'],$input);$row=$db->query('SELECT * FROM business_supplies')->fetch(PDO::FETCH_ASSOC);$id=(int)$row['id'];
 supplyCheck((int)$row['item_cost']===1234&&(int)$row['shipping']===250&&(int)$row['quantity']===3,'Money is saved as integer pence; quantity does not multiply total');
 supplyRequest(['view'=>'supplies'],$input);supplyCheck((int)$db->query('SELECT COUNT(*) FROM business_supplies')->fetchColumn()===1,'Retrying a submission cannot duplicate a purchase');
 $source=file_get_contents($root.'/index.php');$a=strpos($source,'function supplySpending(');$b=strpos($source,'function orderTotalPence(',$a);eval(substr($source,$a,$b-$a));
 $spend=static function(string $from='',string $to='')use($db):array{return supplySpending($db->query('SELECT * FROM business_supplies')->fetchAll(PDO::FETCH_ASSOC),$from,$to);};
 supplyCheck($spend('2025-01-01','2025-01-31')['net']===0&&$spend('2025-02-01','2025-02-28')['net']===1484,'Spending follows payment date rather than order date');
 $edit=$input+['id'=>$id,'revision'=>1];$edit['status']='Cancelled';supplyRequest(['view'=>'supplies'],$edit);
 supplyCheck($spend()['net']===1484,'Cancellation alone must not erase actual spending');
 $edit['revision']=2;$edit['payment_status']='Refunded';$edit['refunded_date']='2025-03-04';supplyRequest(['view'=>'supplies'],$edit);
 supplyCheck($spend()['net']===0&&$spend('2025-03-01','2025-03-31')['net']===-1484,'Full refunds reverse spending on their own date');
 $stale=supplyRequest(['view'=>'supplies','edit'=>$id],$edit);supplyCheck(str_contains($stale,'changed while you were editing'),'Concurrent edits are rejected');
 $edit['revision']=3;$edit['status']='Received';$edit['payment_status']='Paid';supplyRequest(['view'=>'supplies'],$edit);
 $html=supplyRequest(['view'=>'supplies','status'=>'Received']);supplyCheck(str_contains($html,'Labels &lt;sample&gt;')&&!str_contains($html,'Labels <sample>')&&str_contains($html,'Track delivery'),'Cards escape user content and expose safe links');
 supplyCheck(!str_contains(supplyRequest(['view'=>'supplies','q'=>'does-not-exist','status'=>'all']),'class="panel supply-card"'),'Search filters purchase cards');
 $saved=$db->query('SELECT * FROM business_supplies')->fetch(PDO::FETCH_ASSOC);$edit['revision']=4;
 foreach([['product_url'=>'javascript:alert(1)'],['tracking_url'=>'data:text/html,bad'],['item_cost'=>'1.234'],['item_cost'=>'-5'],['quantity'=>'0'],['ordered_date'=>'2025-02-31'],['paid_date'=>''],['payment_status'=>'Refunded','refunded_date'=>'2025-01-01']] as $invalid){supplyRequest(['view'=>'supplies','edit'=>$id],array_replace($edit,$invalid));supplyCheck($saved===$db->query('SELECT * FROM business_supplies')->fetch(PDO::FETCH_ASSOC),'Invalid fields cannot mutate a purchase');}
 $unpaid=array_replace($input,['request_key'=>str_repeat('b',32),'payment_status'=>'Unpaid','item'=>'Envelopes','item_cost'=>'4.00','shipping'=>'0.00']);supplyRequest(['view'=>'supplies'],$unpaid);
 supplyCheck($spend()['unpaid']===400&&$spend()['net']===1484,'Unpaid commitments are separate from paid outgoings');
 $report=supplyRequest(['view'=>'reports','type'=>'outgoings','range'=>'custom','from'=>'2025-02-01','to'=>'2025-02-28']);supplyCheck(str_contains($report,'Net supply outgoings')&&str_contains($report,'£14.84'),'Outgoings report includes payment events and totals');
 $csv=supplyRequest(['view'=>'reports','type'=>'outgoings','range'=>'all','export'=>'1']);supplyCheck(str_contains($csv,'Outflow / refund')&&str_contains($csv,"'=Fixture supplier")&&str_contains($csv,'14.84')&&!str_contains($csv,'Envelopes'),'CSV includes paid events, protects formulas and excludes unpaid commitments');
 supplyCheck(str_contains(supplyRequest(['view'=>'reports','type'=>'profit','period'=>'all']),'After recorded supply spending'),'Profit report accounts for recorded supplies');
 supplyCheck(str_contains(supplyRequest(['view'=>'more']),'?view=supplies'),'More links to separate Business Supplies section');
 supplyCheck($before===$db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)&&$orderCount===(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn(),'Business supplies cannot modify sale inventory or customer orders');
 echo "PASS: purchases, edits, duplicate/stale protection, validation, search, delivery, exact costs, payment/refund dates, report/CSV integration and inventory isolation.\n";
}finally{$db=null;foreach(glob($temp.'/*')?:[] as $file)unlink($file);rmdir($temp);}
