<?php
declare(strict_types=1);
/**
 * Automatic one-page ANKH technical specification PDF for published products.
 * Not an analytical certificate. Only displays existing product identity data.
 */
require_once __DIR__.'/public-catalogue-core.php';
function apQuote(string $value):string{
    if(function_exists('iconv'))$value=(string)@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
    $value=preg_replace('/[^\x20-\x7e]/','',$value)??'';
    return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$value);
}
function apText(float $x,float $y,string $s,int $pt=10,bool $bold=false,string $rgb='0.16 0.21 0.18'):string{
    return "BT /".($bold?'F2':'F1')." ".$pt." Tf ".$rgb." rg 1 0 0 1 ".$x." ".$y." Tm (".apQuote($s).") Tj ET\n";
}
function apRect(float $x,float $y,float $w,float $h,string $rgb):string{
    return $rgb." rg ".$x." ".$y." ".$w." ".$h." re f\n";
}
function apLine(float $x,float $y,float $a,float $b,string $rgb='0.77 0.63 0.40'):string{
    return $rgb." RG 1 w ".$x." ".$y." m ".$a." ".$b." l S\n";
}
function apWrap(string $text,int $max=74):array{
    $text=trim(preg_replace('/\s+/',' ',$text)??$text);
    if($text==='')return ['Not supplied'];
    return explode("\n",wordwrap($text,$max,"\n",true));
}
function apBuildPdf(string $name,array $data,int $productId):string{
    $ink='0.11 0.16 0.13';$muted='0.42 0.45 0.42';$gold='0.79 0.64 0.40';
    $commands=apRect(0,680,595,162,'0.057 0.078 0.067');
    // Stylised ANKH mark, vector-only so the document prints crisply.
    $commands.=$gold." RG 2.5 w 66 763 15 0 360 arc S\n"; // replaced below with portable path commands
    $commands=apRect(0,680,595,162,'0.057 0.078 0.067');
    $commands.=apLine(51,762,51,709,$gold).apLine(30,743,72,743,$gold);
    $commands.=$gold." RG 2 w 42 763 m 42 776 60 776 60 763 c 60 753 42 753 42 763 c S\n";
    $commands.=apText(92,752,'ANKH',34,true,'0.88 0.75 0.52');
    $commands.=apText(94,731,'P E P T I D E S',11,false,'0.94 0.90 0.79');
    $commands.=apText(44,699,'PRODUCT SPECIFICATION  /  RESEARCH REFERENCE',14,true,'0.94 0.90 0.79');
    $commands.=apText(44,649,$name,18,true,$ink);
    $commands.=apText(44,628,'ANKH / PRODUCT  '.str_pad((string)$productId,4,'0',STR_PAD_LEFT),9,true,$muted);
    $commands.=apLine(44,614,551,614);
    $rows=[
      ['Strength / quantity',(string)($data['strength']??'')],
      ['Supplied form',(string)($data['presentation']??'')],
      ['Chemical type',(string)($data['peptide_type']??'')],
      ['Molecular formula',(string)($data['formula']??'')],
      ['Molecular weight',(string)($data['weight']??'')],
      ['CAS identifier',(string)($data['cas']??'')],
      ['Appearance',(string)($data['appearance']??'')],
      ['Storage reference',(string)($data['storage']??'')],
    ];
    $commands.=apText(44,589,'CHEMICAL IDENTITY & SPECIFICATIONS',10,true,$ink);
    $y=573;
    foreach($rows as [$label,$val]){
        if(trim($val)==='')$val='Not specified in published product profile';
        if(strlen($val)>65)$val=substr($val,0,62).'...';
        $commands.=apText(47,$y,$label,8,true,$muted).apText(222,$y,$val,9,false,$ink);
        $commands.=apLine(44,$y-11,551,$y-11,'0.85 0.84 0.79');
        $y-=31;
    }
    $commands.=apText(44,300,'PEPTIDE SEQUENCE / STRUCTURAL IDENTITY',10,true,$ink);
    $seq=trim((string)($data['sequence']??''));
    if($seq!==''){
        $str=substr($seq,0,150);
        $seqLines=apWrap($str,78);
        foreach(array_slice($seqLines,0,3) as $i=>$line)
          $commands.=apText(46,279-$i*15,$line,10,false,$ink);
        if(preg_match('/^[ACDEFGHIKLMNPQRSTVWY]{2,45}$/D',$seq)){
          $commands.=apText(46,229,'Linear sequence schematic (not an atom-by-atom structure)',8,false,$muted);
          $letters=str_split(substr($seq,0,24));
          foreach($letters as $i=>$letter){
            $row=(int)floor($i/12);$col=$i%12;
            $x=52+$col*42;$top=204-$row*28;
            $commands.=apRect($x-9,$top-6,22,20,'0.88 0.84 0.71');
            $commands.=apText($x-3,$top,$letter,9,true,$ink);
            if($col<11 && $i+1<count($letters) && ($i+1)%12!==0)$commands.=apLine($x+13,$top+4,$x+32,$top+4);
          }
        }
    }else{
        $commands.=apText(46,278,'Sequence or chemical structure has not been established for this form.',9,false,$muted);
    }
    $commands.=apLine(44,133,551,133);
    $commands.=apText(44,116,'ANALYTICAL CERTIFICATE',9,true,$ink);
    $commands.=apText(44,99,'No original batch analytical report is attached to this specification.',9,false,$muted);
    $commands.=apRect(0,0,595,73,'0.057 0.078 0.067');
    $commands.=apText(44,52,'ANKH PEPTIDES  /  ANKH GLOBAL LTD',10,true,'0.88 0.75 0.52');
    $commands.=apText(44,35,'Company no. 17289000  |  Registered in England and Wales',8,false,'0.92 0.91 0.85');
    $commands.=apText(44,21,'167-169 Great Portland Street, London W1W 5PF  |  ankhpeptides.com',8,false,'0.81 0.82 0.79');
    $stream=$commands;
    $objects=[
     '<< /Type /Catalog /Pages 2 0 R >>',
     '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
     '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
     '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
     '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
     '<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."endstream"
    ];
    $out="%PDF-1.4\n";$offsets=[0];
    foreach($objects as $index=>$obj){
        $offsets[]=strlen($out);
        $out.=($index+1)." 0 obj\n".$obj."\nendobj\n";
    }
    $xref=strlen($out);$out.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
    foreach(array_slice($offsets,1) as $offset)$out.=sprintf('%010d 00000 n ', $offset)."\n";
    $out.="trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    return $out;
}
$local=PHP_SAPI==='cli-server'&&getenv('ANKH_TEST_ALLOW_HTTP')==='1';
if(!$local&&($_SERVER['HTTPS']??'')!=='on'){http_response_code(403);exit('HTTPS required.');}
$configFile=getenv('ANKH_PORTAL_CONFIG')?:dirname(__DIR__,2).'/ankh-customer-portal-config.php';
try{
    if(!is_file($configFile))throw new RuntimeException('Missing private configuration');
    $config=require $configFile;
    if(!is_array($config))throw new RuntimeException('Private config invalid');
    if(!$local&&strtolower((string)($_SERVER['HTTP_HOST']??''))!==strtolower((string)($config['host']??'order.ankhpeptides.com'))){
        http_response_code(404);exit;
    }
    $db=new PDO('sqlite:'.$config['admin_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=1500');
    $id=filter_var($_GET['product']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id){http_response_code(404);exit;}
    $product=null;
    foreach(ankhPublicCatalogueProducts($db) as $row)if((int)$row['id']===(int)$id){$product=$row;break;}
    if(!$product){http_response_code(404);exit;}
    $pdf=apBuildPdf((string)$product['name'],(array)$product['information'],(int)$id);
    header('Content-Type: application/pdf');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex,noarchive');
    header('Content-Disposition: inline; filename="ankh-product-specification-'.$id.'.pdf"');
    header('Content-Length: '.strlen($pdf));echo $pdf;
}catch(Throwable $e){
    error_log('ANKH product specification: '.$e->getMessage());
    http_response_code(503);header('Content-Type: text/plain; charset=UTF-8');
    echo 'Product specification is temporarily unavailable.';
}
