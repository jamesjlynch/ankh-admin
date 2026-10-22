<?php
declare(strict_types=1);
require_once __DIR__.'/../customer-portal/lib.php';
require_once __DIR__.'/../customer-portal/statement.php';

function statementCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$customer=['id'=>1,'name'=>'Example Customer','phone'=>'07123 456789','address'=>"18 Test Street\nLiverpool"];
$orders=[
 ['id'=>7,'created'=>'2026-10-21T12:00:00+01:00','status'=>'New','delivery_date'=>'','total'=>3500,'paid'=>1000,'payments'=>[['amount'=>1000,'created'=>'2026-10-21T14:00:00+01:00','method'=>'Bank transfer']]],
 ['id'=>8,'created'=>'2026-10-20T12:00:00+01:00','status'=>'Delivered','delivery_date'=>'2026-10-21','total'=>1850,'paid'=>1850,'payments'=>[['amount'=>1850,'created'=>'2026-10-20','method'=>'Card']]],
 ['id'=>9,'created'=>'2026-10-19T12:00:00+01:00','status'=>'Cancelled','delivery_date'=>'','total'=>9000,'paid'=>0,'payments'=>[]],
];
$s=portalStatementTotals($orders);
statementCheck($s===['orders'=>2,'charged'=>5350,'paid'=>2850,'outstanding'=>2500,'cancelled'=>1],'Cancelled orders excluded from financial summary');
statementCheck(portalStatementTotals([])['outstanding']===0,'Empty account balances safe');
statementCheck(portalStatementTotals([['status'=>'New','total'=>800,'paid'=>2000]])['outstanding']===0,'Overpayments do not create negative balances');
$pdf=portalStatementPdf($orders,$customer,new DateTimeImmutable('2026-10-22T19:45:00+01:00'));
statementCheck(str_starts_with($pdf,'%PDF-1.4') && str_contains($pdf,'startxref'),'Well-formed PDF header and cross-reference');
statementCheck(str_contains($pdf,'ANK-0007')&&str_contains($pdf,'ANK-0008')&&str_contains($pdf,'ANK-0009'),'All recorded order references appear');
statementCheck(str_contains($pdf,'Bank transfer')&&str_contains($pdf,'Card'),'Payment history appears');
statementCheck(str_contains($pdf,'Cancelled orders are excluded'),'Clear cancelled order treatment');
statementCheck(!str_contains($pdf,'Road 2')&&!str_contains($pdf,'Bob Customer'),'No unrelated customer data');
statementCheck(str_contains($pdf,'Page 1'),'Page footer present');
$long=[];for($i=1;$i<=70;$i++)$long[]=['id'=>$i,'created'=>'2026-10-22','status'=>'New','delivery_date'=>'','total'=>1500,'paid'=>0,'payments'=>[['amount'=>200,'created'=>'2026-10-21','method'=>'Transfer']]];
$longPdf=portalStatementPdf($long,$customer);
statementCheck(substr_count($longPdf,'/Type /Page /Parent')>=2,'Long statements paginate cleanly');
statementCheck(str_contains($longPdf,'ANK-0070'),'Last order retained after pagination');
$escaped=portalPdfString("Test (one) \\ two");
statementCheck(str_contains($escaped,'\\(')&&str_contains($escaped,'\\)'),'PDF meta-characters are escaped');
echo "Customer statement totals, private fields, payment detail, escaping and pagination passed.\n";
