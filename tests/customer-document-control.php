<?php
declare(strict_types=1);
/** Read-only document dashboard regressions; only disposable SQLite data. */
require_once dirname(__DIR__).'/customer-portal/document-control.php';
function checkDoc(bool $ok,string $message):void {
 if(!$ok)throw new RuntimeException($message);
}
$admin=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$store=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$admin->exec("CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,active INTEGER);
  CREATE TABLE customer_catalogue_visibility(product_id INTEGER PRIMARY KEY,visible INTEGER);
  INSERT INTO products VALUES
    (101,'BPC-157 5mg',1),(73,'BPC-157 10mg',1),(125,'Glow Stack',1),
    (78,'MOTS-C 10mg',1),(53,'SS-31 10mg',1),(999,'Hidden item',1),(998,'Inactive',0);
  INSERT INTO customer_catalogue_visibility VALUES
    (101,1),(73,1),(125,1),(78,1),(53,1),(999,0),(998,1);");
$rows=ankhDocumentControlRows($store,$admin);
checkDoc(count($rows)===5,'Only five active approved products should be returned');
foreach($rows as $r)checkDoc($r['sds_status']==='missing'&&$r['coa_count']===0,'Empty document store must not invent documents');
$store->exec("CREATE TABLE portal_product_sds(id INTEGER PRIMARY KEY,product_id INTEGER,published INTEGER,public_draft INTEGER,reviewed_at INTEGER,created_at INTEGER);
INSERT INTO portal_product_sds VALUES
 (11,101,0,0,0,100),(15,101,1,0,123456,200),
 (20,73,0,1,0,300),(30,125,0,0,0,400),(31,999,1,0,111111,400),
 (40,53,1,0,120000,500);");
$store->exec("CREATE TABLE portal_product_documents(id INTEGER PRIMARY KEY,product_id INTEGER,document_kind TEXT,created_at INTEGER,published INTEGER,superseded INTEGER);
INSERT INTO portal_product_documents VALUES
 (10,101,'original_coa',123,1,0),(11,101,'technical_spec',100,1,0),
 (12,101,'original_coa',120,0,1),(13,73,'original_coa',300,0,0),
 (14,125,'technical_spec',200,1,0),(15,999,'ankh_batch_coa',123,1,0),
 (16,53,'ankh_batch_coa',455,1,0);");
$rows=ankhDocumentControlRows($store,$admin);
$by=[];foreach($rows as $r)$by[$r['id']]=$r;
checkDoc(count($rows)===5&&!isset($by[999])&&!isset($by[998]),'Hidden/inactive product and documentation must stay out of CMS');
checkDoc($by[101]['sds_status']==='reviewed' && $by[101]['sds_id']===15 && $by[101]['sds_private_count']===1,'Review should outrank archived SDS');
checkDoc($by[101]['coa_count']===1&&$by[101]['coa_private_count']===1&&$by[101]['spec_count']===1,'COA, archived report and specification must remain distinct');
checkDoc($by[73]['sds_status']==='public-draft'&&$by[73]['coa_count']===0&&$by[73]['coa_private_count']===1,'Unverified public working copy and private COA must not count as reviewed');
checkDoc($by[125]['sds_status']==='private'&&$by[125]['coa_count']===0&&$by[125]['spec_count']===1,'Private SDS and specification must not count as published approved PDF/COA');
checkDoc($by[78]['sds_status']==='missing','No SDS recorded must not be replaced with an invented record');
checkDoc($by[53]['sds_status']==='reviewed'&&$by[53]['coa_count']===1,'Real reviewed SDS with public COA should count properly');
ob_start();ankhDocumentControlPage($store,$admin);$html=(string)ob_get_clean();
checkDoc(str_contains($html,'Document Control')||str_contains($html,'Documentation overview'),'Dashboard not rendered');
checkDoc(str_contains($html,'data-sds="missing"')&&str_contains($html,'data-coa="no"'),'Filter markers missing');
checkDoc(str_contains($html,'cms_product_id=73')&&str_contains($html,'product-safety-document.php?id=20'),'Editor and correct SDS links missing');
checkDoc(!str_contains($html,'Hidden item')&&!str_contains($html,'Inactive'),'Dashboard leaked hidden/inactive products');
checkDoc(str_contains($html,'No public COA')&&str_contains($html,'No SDS recorded'),'Missing documents must be obvious');
echo "PASS: Document Control uses Order Admin visibility, separates reviewed/public/private/missing SDS and COA, never invents certification, and renders safe links.\n";
