<?php
declare(strict_types=1);
/** Public CMS product photos: no unpublished products or private draft imagery. */
require_once __DIR__.'/content-studio.php';
require_once __DIR__.'/public-catalogue-core.php';
$local=PHP_SAPI==='cli-server'&&getenv('ANKH_TEST_ALLOW_HTTP')==='1'&&
    in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true);
if(!$local&&($_SERVER['HTTPS']??'')!=='on'){http_response_code(403);exit;}
$configFile=getenv('ANKH_PORTAL_CONFIG')?:dirname(__DIR__,2).'/ankh-customer-portal-config.php';
try{
    if(!is_file($configFile))throw new RuntimeException('No private configuration');
    $config=require $configFile;
    if(!is_array($config))throw new RuntimeException('Missing configuration');
    $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
    if(!$local&&$host!==strtolower((string)($config['host']??'order.ankhpeptides.com'))){http_response_code(404);exit;}
    $productId=filter_var($_GET['product']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$productId){http_response_code(404);exit;}
    $admin=new PDO('sqlite:'.$config['admin_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $store=new PDO('sqlite:'.$config['portal_database_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $admin->exec('PRAGMA query_only=ON');$store->exec('PRAGMA query_only=ON');
    $q=$admin->prepare('SELECT p.id FROM products p JOIN customer_catalogue_visibility v
        ON v.product_id=p.id AND v.visible=1 WHERE p.active=1 AND p.id=?');
    $q->execute([(int)$productId]);
    if(!$q->fetchColumn()){http_response_code(404);exit;}
    $row=ankhCmsRead($store,(int)$productId);
    if(!$row || !(int)$row['has_published'] || !in_array($row['published_image_mime'],['image/jpeg','image/png','image/webp'],true)){
        http_response_code(404);exit;
    }
    $data=is_resource($row['published_image'])?stream_get_contents($row['published_image']):(string)$row['published_image'];
    if(!is_string($data)||strlen($data)<12||strlen($data)>8*1024*1024)throw new RuntimeException('Invalid image');
    $mime=(string)$row['published_image_mime'];
    $size=(string)($_GET['size']??'');
    $pixels=match($size){'thumb'=>420,'detail'=>880,default=>0};
    if($pixels>0 && function_exists('imagecreatefromstring') && function_exists('imagewebp')){
        $dimensions=@getimagesizefromstring($data);
        if(is_array($dimensions) && $dimensions[0]>0 && $dimensions[1]>0 &&
           $dimensions[0]<=2500 && $dimensions[1]<=2500 && max($dimensions[0],$dimensions[1])>$pixels){
            $source=@imagecreatefromstring($data);
            if($source!==false){
                $ratio=min(1,$pixels/max(imagesx($source),imagesy($source)));
                $width=max(1,(int)round(imagesx($source)*$ratio));
                $height=max(1,(int)round(imagesy($source)*$ratio));
                $resized=imagecreatetruecolor($width,$height);
                imagealphablending($resized,false);imagesavealpha($resized,true);
                imagefill($resized,0,0,imagecolorallocatealpha($resized,0,0,0,127));
                imagecopyresampled($resized,$source,0,0,0,0,$width,$height,imagesx($source),imagesy($source));
                ob_start();$ok=@imagewebp($resized,null,$size==='detail'?84:79);$compressed=ob_get_clean();
                imagedestroy($resized);imagedestroy($source);
                if($ok&&is_string($compressed)&&$compressed!==''){$data=$compressed;$mime='image/webp';}
            }
        }
    }
    $etag='"'.hash('sha256',$data).'"';
    header('ETag: '.$etag);
    header('Cache-Control: public, max-age=300');
    if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}
    header('Content-Type: '.$mime);
    header('Content-Length: '.strlen($data));
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    echo $data;
}catch(Throwable $e){error_log('ANKH CMS photo: '.$e->getMessage());http_response_code(503);exit;}
