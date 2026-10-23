#!/usr/bin/env python3
"""Public ANKH homepage and private account routing test, using isolated data."""
import base64
import hashlib
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
PNG=base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=")

def verify():
    with tempfile.TemporaryDirectory(prefix="ankh-public-front-") as temp:
        work=Path(temp); admin=work/"admin.sqlite"; portal=work/"accounts.sqlite"
        with sqlite3.connect(admin) as db:
            db.executescript("""
            CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,description TEXT,price INTEGER,stock_qty INTEGER,cost INTEGER,active INTEGER);
            CREATE TABLE customer_catalogue_visibility(product_id INTEGER PRIMARY KEY,visible INTEGER,updated_at TEXT);
            CREATE TABLE customer_catalogue_content(product_id INTEGER PRIMARY KEY,description TEXT,image_data BLOB,image_mime TEXT,image_width INTEGER,image_height INTEGER,updated_at TEXT);
            CREATE TABLE customer_catalogue_information(product_id INTEGER PRIMARY KEY,information TEXT,updated_at TEXT);
            CREATE TABLE customers(id INTEGER PRIMARY KEY,name TEXT,phone TEXT,archived INTEGER);
            INSERT INTO products VALUES(1,'Visible Study Material','Compound reference',9900,14,4400,1),
               (2,'Hidden Compound','Hidden internal description',8888,6,100,1),
               (3,'Inactive Material','Not available',3000,5,200,0);
            INSERT INTO customer_catalogue_visibility VALUES(1,1,'now'),(2,0,'now'),(3,1,'now');
            INSERT INTO customers VALUES(3,'Private Test Person','07123456789',0);
            """)
            db.execute("INSERT INTO customer_catalogue_content VALUES(?,?,?,?,?,?,?)",
                (1,"Technical overview and laboratory reference",PNG,"image/png",1,1,"now"))
            db.execute("INSERT INTO customer_catalogue_information VALUES(?,?,?)",
                (1,'{"research_focus":"In vitro studies","evidence_level":"Preclinical laboratory observations","strength":"5 mg","presentation":"Vial","appearance":"Powder","peptide_type":"Research compound","amino_length":"15 residues","formula":"C62H98N16O22","weight":"1419 g/mol","sequence":"GEPPPGKPADDAGLV","cas":"137525-51-0","purity":"Unverified manufacturer specification","storage":"See supplied documentation"}',"now"))
        with sqlite3.connect(portal) as db:
            db.execute("CREATE TABLE portal_accounts(id INTEGER PRIMARY KEY,customer_id INTEGER,phone_e164 TEXT,active INTEGER)")
        before=hashlib.sha256(admin.read_bytes()).hexdigest()
        config=work/"config.php"
        config.write_text(
            "<?php return ['admin_database_path'=>'"+str(admin)+"','portal_database_path'=>'"+str(portal)+
            "','host'=>'order.ankhpeptides.com','twilio_account_sid'=>'AC"+"a"*32+
            "','twilio_auth_token'=>'"+"b"*32+"','twilio_verify_service_sid'=>'VA"+"c"*32+"'];"
        )
        router=work/"router.php"
        router.write_text("<?php $_SERVER['SERVER_ADDR']='127.0.0.1'; return false;")
        with socket.socket() as sk:
            sk.bind(("127.0.0.1",0));port=sk.getsockname()[1]
        env={**os.environ,"ANKH_PORTAL_CONFIG":str(config),"ANKH_TEST_ALLOW_HTTP":"1"}
        log=open(work/"php.log","w+")
        proc=subprocess.Popen(["php","-S",f"127.0.0.1:{port}","-t",str(ROOT/"customer-portal"),str(router)],env=env,stdout=log,stderr=log)
        host=f"http://127.0.0.1:{port}"
        try:
            for _ in range(80):
                try:
                    with socket.create_connection(("127.0.0.1",port),timeout=.1):break
                except OSError:time.sleep(.1)
            else:raise AssertionError("PHP development server not available")
            def get(path,code=200,raw=False):
                try:
                    with urllib.request.urlopen(host+path,timeout=8) as resp:
                        status=resp.status; data=resp.read()
                except urllib.error.HTTPError as err:status=err.code;data=err.read()
                assert status==code,(path,status,code,data[:350])
                return data if raw else data.decode("utf-8","replace")
            checkout=get("/shop-checkout.php")
            assert 'Online checkout is not available yet' in checkout
            home=get("/")
            assert "Discover the" in home and "ANKH collection" in home
            assert "Visible Study Material" in home
            assert 'data-cart-id="1"' in home, "Published product must show a no-login demo Add to basket button"
            assert 'data-approved-id="1"' in home and 'data-approved-live="0"' in home
            assert 'sf-card-quick' in home and 'sf-save-mini' in home
            demo=get("/shop-demo.php")
            assert "Visible Study Material" in demo and "Simulate checkout" in demo
            assert 'data-demo-price="9900"' in demo and 'sf-demo-subtotal' in demo, "Demo pricing should be a preview"
            assert "Hidden Compound" not in demo and "Inactive Material" not in demo
            request=urllib.request.Request(host+"/shop-demo.php",data=b"confirmed=1",method="POST")
            try:
                urllib.request.urlopen(request,timeout=8)
                raise AssertionError("Demo must reject POST submissions")
            except urllib.error.HTTPError as err:
                assert err.code==405,err.code
            with sqlite3.connect(portal) as db:
                db.executescript("CREATE TABLE storefront_demo_visibility(product_id INTEGER PRIMARY KEY,enabled INTEGER,updated_at INTEGER); INSERT INTO storefront_demo_visibility VALUES(1,0,1);")
            disabled=get("/")
            assert 'data-cart-id="1"' not in disabled and "Reference only" in disabled
            assert "Visible Study Material" not in get("/shop-demo.php"), "Disabled item must not appear in demo checkout"
            with sqlite3.connect(portal) as db:db.execute("UPDATE storefront_demo_visibility SET enabled=1 WHERE product_id=1")
            assert 'data-cart-id="1"' in get("/"), "Staff re-enabled product should be offered in demo basket"
            assert "Hidden Compound" not in home and "Inactive Material" not in home
            assert "Private Test Person" not in home
            assert "£99.00" in home and "RETAIL PREVIEW" in home, "Published retail preview price must be visible"
            assert "£88.88" not in home and "£30.00" not in home, "Hidden or inactive price must stay private"
            assert "4400" not in home and "£44.00" not in home, "Admin cost must stay private"
            assert 'data-approved-price="9900"' in home, "Demo basket should retain price preview without granting checkout"
            assert 'href="./?tab=home"' in home
            assert 'sf-cart-drawer' in home and 'sf-trust-details' in home and 'storefront-cart.js' in home
            assert "storefront-preview.php" not in home
            assert "not intended for human" in home.lower()
            assert "Order" not in home[:80] # public storefront doesn't embed customer orders
            no_results=get("/?q=not-a-match")
            assert "No matching profiles." in no_results and "0 matching profiles" in no_results and "sf-grid-v2" not in no_results
            saved=get("/?saved=1")
            assert "Saved profiles" in saved and "data-saved-grid" in saved and "data-save-id" in saved
            assert "Hidden Compound" not in saved and "Inactive Material" not in saved
            assert 'src="./storefront.js?v=3"' in home
            assert 'storefront-luxury.css?v=1' in home and 'storefront-luxury.js?v=2' in home
            assert 'sf-card-v3' in home and 'data-filter-name="Visible Study Material"' in home
            assert 'data-full-collection="1"' in home
            assert 'sf-suggest-photo' not in home or 'data-suggest-photo=' in home
            assert "data-suggest-name" in home and "sf-photo-wall" not in home or "sf-hero-photo-wall" in home
            profile=get("/?product=1")
            assert "Visible Study Material" in profile
            assert 'sf-gallery-track' in profile and 'data-gallery-next' in profile and 'data-gallery-zoom' in profile
            assert 'sf-gallery-specification' in profile and 'sf-product-zoom' in profile
            assert 'sf-gallery-spec-rows' in profile and 'sf-gallery-spec-row' in profile
            assert "Molecular formula" in profile and "Amino-acid sequence" in profile and "Storage" in profile
            assert "At a glance" in profile and "RETAIL PRICE PREVIEW" in profile and "£99.00" in profile
            assert 'data-open-identity' in profile and 'Complete product identity' in profile
            assert 'sf-mobile-product-action' in profile and 'sf-mobile-product-add' in profile
            assert 'data-cart-id="1"' in profile and 'sf-quick-add' in home
            assert 'src="./storefront-luxury.js?v=2"' in profile
            assert "Preclinical laboratory observations" in get("/?product=1")
            get("/?product=2",404)
            get("/?product=3",404)
            get("/?image=2",404)
            image=get("/public-catalogue.php?image=1",200,raw=True)
            assert image==PNG
            sign_in=get("/?tab=home")
            assert "Sign in" in sign_in and "Mobile number" in sign_in and "Browse the ANKH collection" in sign_in
            assert "Private Test Person" not in sign_in
            private=get("/?tab=orders")
            assert "Enter your mobile number" in private and "Private Test Person" not in private
            assert hashlib.sha256(admin.read_bytes()).hexdigest()==before,"Read-only homepage changed Admin database"
            print("PASS: public homepage, published-only retail PREVIEW prices, expanded full product identity, no private data, secure login, Admin DB unchanged")
        finally:
            proc.terminate()
            try:proc.wait(timeout=5)
            except subprocess.TimeoutExpired:proc.kill();proc.wait()
            log.close()

if __name__=="__main__":verify()
