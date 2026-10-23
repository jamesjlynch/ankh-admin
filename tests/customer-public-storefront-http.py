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
                (1,'{"research_focus":"In vitro studies","evidence_level":"Preclinical laboratory observations"}',"now"))
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
            home=get("/")
            assert "Discover the" in home and "ANKH collection" in home
            assert "Visible Study Material" in home
            assert "Hidden Compound" not in home and "Inactive Material" not in home
            assert "Private Test Person" not in home
            assert "£99" not in home and "9900" not in home and "4400" not in home
            assert 'href="./?tab=home"' in home
            assert "storefront-preview.php" not in home
            assert "not intended for human" in home.lower()
            assert "Order" not in home[:80] # public storefront doesn't embed customer orders
            no_results=get("/?q=not-a-match")
            assert "No matching profiles." in no_results and "0 matching profiles" in no_results and "sf-grid-v2" not in no_results
            assert "Visible Study Material" in get("/?product=1")
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
            print("PASS: public homepage, published-only details/images, no prices or private data, secure account sign-in, Admin DB unchanged")
        finally:
            proc.terminate()
            try:proc.wait(timeout=5)
            except subprocess.TimeoutExpired:proc.kill();proc.wait()
            log.close()

if __name__=="__main__":verify()
