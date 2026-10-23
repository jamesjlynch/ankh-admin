<?php
declare(strict_types=1);
require_once __DIR__.'/../customer-portal/product-certificates.php';
function ensure(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}
function rejects(callable $fn,string $message):void{
    try{$fn();}catch(InvalidArgumentException $e){return;}
    throw new RuntimeException($message);
}
$admin=new PDO('sqlite::memory:');
$admin->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$admin->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$admin->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,active INTEGER);
CREATE TABLE customer_catalogue_visibility(product_id INTEGER PRIMARY KEY,visible INTEGER);
INSERT INTO products VALUES(1,"Published peptide",1),(2,"Hidden peptide",1),(3,"Inactive compound",0);
INSERT INTO customer_catalogue_visibility VALUES(1,1),(2,0),(3,1);');
$store=new PDO('sqlite::memory:');
$store->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$store->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
ankhDocsInit($store);
$pdf="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
$put=$store->prepare('INSERT INTO portal_product_documents(product_id,document_kind,issuer,batch_ref,report_ref,
 pdf_name,pdf_sha256,pdf_bytes,created_at,published)VALUES(?,?,?,?,?,?,?,?,?,?)');
$put->execute([1,'original_coa','Original laboratory','LOT-1','REF-1','original.pdf',hash('sha256',$pdf),$pdf,time(),0]);
$id=(int)$store->lastInsertId();
ensure(count(ankhDocsPublished($store,1))===0,'Unpublished document was exposed');
ankhDocsChangeVisibility($store,$admin,$id,true);
$published=ankhDocsPublished($store,1);
ensure(count($published)===1,'Public document not retrievable');
ensure($published[0]['issuer']==='Original laboratory','Issuing laboratory attribution missing');
ankhDocsChangeVisibility($store,$admin,$id,false);
ensure(count(ankhDocsPublished($store,1))===0,'Hidden document still exposed');
$put->execute([2,'original_coa','Original laboratory','LOT-2','REF-2','hidden.pdf',hash('sha256',$pdf),$pdf,time(),0]);
$hiddenId=(int)$store->lastInsertId();
rejects(static fn()=>ankhDocsChangeVisibility($store,$admin,$hiddenId,true),'Hidden product was allowed to publish documents');
$put->execute([3,'original_coa','Original laboratory','LOT-3','REF-3','inactive.pdf',hash('sha256',$pdf),$pdf,time(),0]);
$inactiveId=(int)$store->lastInsertId();
rejects(static fn()=>ankhDocsChangeVisibility($store,$admin,$inactiveId,true),'Inactive product was allowed to publish documents');
$store->prepare('UPDATE portal_product_documents SET superseded=1 WHERE id=?')->execute([$id]);
rejects(static fn()=>ankhDocsChangeVisibility($store,$admin,$id,true),'Archived report was allowed back into publication');
echo "ANKH document controls passed: private-by-default uploads, published-only release, retained issuer, hide/unhide, archive safety.\n";
