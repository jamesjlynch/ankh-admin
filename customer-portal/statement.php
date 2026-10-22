<?php
declare(strict_types=1);

/**
 * Self-contained, server-rendered account statement PDF.
 * Caller must authenticate and obtain identity-filtered orders before invoking.
 * No external renderer, temporary files or network requests.
 */
function portalPdfString(string $text): string {
    $text = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text) ?? '';
    $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
    if ($encoded === false) $encoded = preg_replace('/[^\x20-\x7e]/', '?', $text) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
}

function portalStatementClip(string $value,int $length):string {
    if(function_exists('mb_substr'))return mb_substr($value,0,$length,'UTF-8');
    $chars=preg_split('//u',$value,-1,PREG_SPLIT_NO_EMPTY);
    return $chars===false?substr($value,0,$length):implode('',array_slice($chars,0,$length));
}

function portalStatementTotals(array $orders): array {
    $totals = ['orders'=>0,'charged'=>0,'paid'=>0,'outstanding'=>0,'cancelled'=>0];
    foreach ($orders as $order) {
        if (($order['status'] ?? '') === 'Cancelled') {
            ++$totals['cancelled'];
            continue;
        }
        ++$totals['orders'];
        $total = max(0,(int)($order['total'] ?? 0));
        $paid = max(0,min($total,(int)($order['paid'] ?? 0)));
        $totals['charged'] += $total;
        $totals['paid'] += $paid;
        $totals['outstanding'] += max(0,$total-$paid);
    }
    return $totals;
}

function portalStatementPdf(array $orders, array $customer, ?DateTimeImmutable $now=null): string {
    $today = ($now ?? new DateTimeImmutable('now',new DateTimeZone('Europe/London')))->setTimezone(new DateTimeZone('Europe/London'));
    $summary = portalStatementTotals($orders);
    $pages=[];
    $ops=[]; $y=0; $pageNumber=0;
    $rect=static function(float $x,float $yb,float $w,float $h,string $colour)use(&$ops):void {
        $ops[]=$colour.' rg '.sprintf('%.1f %.1f %.1f %.1f re f',$x,$yb,$w,$h);
    };
    $line=static function(float $x1,float $y1,float $x2,float $y2,string $colour='0.82 0.82 0.80')use(&$ops):void {
        $ops[]=$colour.' RG 0.5 w '.sprintf('%.1f %.1f m %.1f %.1f l S',$x1,$y1,$x2,$y2);
    };
    $text=static function(float $x,float $yb,string $message,int $size=10,bool $bold=false,string $colour='0.16 0.17 0.16')use(&$ops):void {
        $ops[]='BT '.$colour.' rg /F'.($bold?'2':'1').' '.$size.' Tf '.sprintf('%.1f %.1f Td ',$x,$yb).'('.portalPdfString($message).') Tj ET';
    };
    $newPage=function()use(&$pages,&$ops,&$y,&$pageNumber,$rect,$text,$line,$today):void {
        if ($pageNumber>0) $pages[]=$ops;
        $ops=[];++$pageNumber;
        $rect(0,757,595,85,'0.065 0.077 0.073');
        $rect(42,779,4,36,'0.82 0.64 0.32');
        $text(58,797,'ANKH',27,true,'0.92 0.78 0.52');
        $text(59,780,'PRIVATE ACCOUNT STATEMENT',8,true,'0.82 0.81 0.78');
        $text(430,796,$today->format('d M Y'),10,false,'0.92 0.91 0.88');
        $text(430,780,'Customer copy',8,false,'0.77 0.77 0.74');
        $line(42,48,553,48);
        $text(42,33,'ANKH GLOBAL LTD  |  Recorded account information',8,false,'0.47 0.47 0.45');
        $text(516,33,'Page '.$pageNumber,8,false,'0.47 0.47 0.45');
        $y=731;
    };
    $ensure=function(float $height)use(&$y,$newPage):void {if($y-$height<75)$newPage();};
    $newPage();
    $text(42,$y,'PREPARED FOR',8,true,'0.57 0.44 0.22');$y-=24;
    $text(42,$y,portalStatementClip((string)($customer['name']??'Customer'),85),17,true);$y-=22;
    $address = preg_split('/\R/u',(string)($customer['address']??''))?:[];
    foreach(array_slice($address,0,3) as $addr) {if(trim($addr)==='')continue;$text(42,$y,portalStatementClip(trim($addr),90),9);$y-=15;}
    $y-=9;
    $line(42,$y,553,$y);$y-=25;
    $text(42,$y,'Orders: '.$summary['orders'],10,true);
    $text(182,$y,'Recorded payments: '.portalMoney($summary['paid']),10,true);
    $text(393,$y,'Outstanding: '.portalMoney($summary['outstanding']),10,true,'0.58 0.39 0.17');
    $y-=22;
    $text(42,$y,'Charges recorded: '.portalMoney($summary['charged']).'   |   Cancelled: '.$summary['cancelled'],9);
    $y-=22;
    $text(42,$y,'Based on saved order prices and payments at '.$today->format('d M Y, H:i').' UK time.',8,false,'0.47 0.47 0.45');
    $y-=35;
    $text(42,$y,'ORDER AND PAYMENT RECORD',11,true);$y-=15;
    $rect(42,$y-22,511,26,'0.92 0.91 0.87');
    $text(50,$y-12,'ORDER / DATE',8,true);
    $text(235,$y-12,'STATUS',8,true);
    $text(358,$y-12,'TOTAL',8,true);
    $text(427,$y-12,'PAID',8,true);
    $text(490,$y-12,'DUE',8,true);
    $y-=34;
    foreach ($orders as $order) {
        $payments=$order['payments']??[];
        $paymentLines=[];
        foreach ($payments as $payment) {
            $when=(string)($payment['created']??'');
            try {$when=(new DateTimeImmutable($when))->setTimezone(new DateTimeZone('Europe/London'))->format('d M Y');}
            catch(Throwable $e) {$when='Date unavailable';}
            $detail=$when.'  '.portalMoney((int)($payment['amount']??0));
            if(trim((string)($payment['method']??''))!=='')$detail.='  '.portalStatementClip(trim((string)$payment['method']),32);
            $paymentLines[]=$detail;
        }
        $height=38+(count($paymentLines)>0?14+13*min(15,count($paymentLines)):0);
        $ensure($height+9);
        $ref='ANK-'.str_pad((string)($order['id']??0),4,'0',STR_PAD_LEFT);
        $created=portalDate((string)($order['created']??''));
        $cancelled=($order['status']??'')==='Cancelled';
        $status=portalStatus($order);
        $total=max(0,(int)($order['total']??0));
        $paid=max(0,min($total,(int)($order['paid']??0)));
        $balance=$cancelled?0:max(0,$total-$paid);
        $text(50,$y,$ref,10,true);$text(50,$y-14,$created,8,false,'0.48 0.48 0.45');
        $text(235,$y,portalStatementClip($status,17),9);
        $text(358,$y,portalMoney($total),9);
        $text(427,$y,portalMoney($paid),9);
        $text(490,$y,portalMoney($balance),9,true);
        $y-=33;
        if($paymentLines){
            $text(50,$y,'Recorded payments',8,true,'0.47 0.47 0.45');$y-=14;
            foreach(array_slice($paymentLines,0,15) as $part) {$text(63,$y,$part,8);$y-=13;}
            if(count($paymentLines)>15){$text(63,$y,'Additional payments are available in your account history.',8);$y-=13;}
        }
        $line(42,$y,553,$y,'0.88 0.87 0.84');$y-=18;
    }
    if(!$orders){$text(50,$y,'No recorded orders are available for this account.',10);$y-=28;}
    $ensure(56);
    $y-=8;
    $text(42,$y,'Important account information',10,true);$y-=19;
    $text(42,$y,'Cancelled orders are excluded from totals and outstanding balances.',8);$y-=13;
    $text(42,$y,'This is a statement of recorded activity, not a VAT invoice or payment receipt.',8);$y-=13;
    $text(42,$y,'For corrections, contact info@ankhpeptides.com.',8);
    $pages[]=$ops;
    $objects=['','', '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding>>',
                       '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding>>'];
    $kids=[];
    foreach($pages as $draw){
        $stream=implode("\n",$draw)."\n";
        $streamId=count($objects)+1;
        $objects[]='<</Length '.strlen($stream).">>\nstream\n".$stream."endstream";
        $pageId=count($objects)+1;
        $objects[]='<</Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources <</Font <</F1 3 0 R /F2 4 0 R>>>> /Contents '.$streamId.' 0 R>>';
        $kids[]=$pageId.' 0 R';
    }
    $objects[0]='<</Type /Catalog /Pages 2 0 R>>';
    $objects[1]='<</Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).'>>';
    $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets=[0];
    foreach($objects as $id=>$body){
        $offsets[] = strlen($pdf);
        $pdf.=($id+1)." 0 obj\n".$body."\nendobj\n";
    }
    $xref=strlen($pdf);
    $pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
    foreach(array_slice($offsets,1) as $offset)$pdf.=sprintf('%010d 00000 n ', $offset)."\n";
    $pdf.="trailer\n<</Size ".(count($objects)+1)." /Root 1 0 R>>\nstartxref\n".$xref."\n%%EOF\n";
    return $pdf;
}

function portalStatementDownload(array $orders,array $customer):never {
    $pdf=portalStatementPdf($orders,$customer);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="ankh-account-statement-'.date('Ymd').'.pdf"');
    header('Content-Length: '.strlen($pdf));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}
