#!/usr/bin/env python3
"""Isolated HTTP regression tests for ANKH staff-only storefront preview.
Never contacts production or writes to the Admin database.
"""
import hashlib
import http.cookiejar
import os
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HASH = "test-preview-password-hash"
SESSION = "storefrontciverifieds1234"

def wait_server(port, proc):
    for _ in range(80):
        if proc.poll() is not None:
            raise RuntimeError("PHP server stopped before accepting requests")
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=.15):
                return
        except OSError:
            time.sleep(.1)
    raise RuntimeError("PHP server did not start")

def request(url, cookie=None, data=None):
    headers = {}
    if cookie:
        headers["Cookie"] = cookie
    if data is not None:
        headers["Content-Type"] = "application/x-www-form-urlencoded"
        data = urllib.parse.urlencode(data).encode("utf-8")
    req = urllib.request.Request(url, headers=headers, data=data)
    opener = urllib.request.build_opener(NoRedirect())
    try:
        with opener.open(req, timeout=8) as r:
            return r.status, dict(r.headers), r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode("utf-8", "replace")

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def main():
    with tempfile.TemporaryDirectory(prefix="ankh-sf-ci-") as temp:
        d=Path(temp); db=d/"admin.sqlite"; portal=d/"portal.sqlite"
        conn=sqlite3.connect(db)
        conn.executescript("""
        CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,description TEXT,price INTEGER,active INTEGER);
        CREATE TABLE customer_catalogue_content(product_id INTEGER PRIMARY KEY,description TEXT,
           image_data BLOB,image_mime TEXT,image_width INTEGER,image_height INTEGER,updated_at TEXT);
        CREATE TABLE customer_catalogue_visibility(product_id INTEGER PRIMARY KEY,visible INTEGER,updated_at TEXT);
        CREATE TABLE customer_catalogue_information(product_id INTEGER PRIMARY KEY,information TEXT,updated_at TEXT);
        INSERT INTO products VALUES(1,'Test Case','For storage',1200,1);
        INSERT INTO products VALUES(2,'Private Item','Private research description',3500,1);
        INSERT INTO products VALUES(3,'Inactive Item','Should never appear',500,0);
        INSERT INTO customer_catalogue_visibility VALUES(1,1,'now'),(2,0,'now'),(3,1,'now');
        INSERT INTO customer_catalogue_content VALUES(1,'Compact storage case',NULL,'',0,0,'now');
        INSERT INTO customer_catalogue_content VALUES(2,'Private research description',NULL,'',0,0,'now');
        INSERT INTO customer_catalogue_information VALUES(2,'{"research_focus":"Laboratory context"}','now');
        """)
        conn.commit()
        start=conn.execute("SELECT id,name,description,price,active FROM products ORDER BY id").fetchall()
        conn.close();sqlite3.connect(portal).close()
        config=d/"config.php"
        config.write_text("<?php return ['admin_database_path'=>'"+str(db)+"','portal_database_path'=>'"+str(portal)+"','host'=>'example.invalid','preview_password_hash'=>'"+HASH+"','twilio_account_sid'=>'dummy','twilio_auth_token'=>'dummy','twilio_verify_service_sid'=>'dummy'];",encoding="utf-8")
        sess=d/"sessions";sess.mkdir()
        code='session_name("ankh_staff_preview");session_id("'+SESSION+'");session_start();'+ (
            '$_SESSION["staff_preview_authorised"]=true;'
            '$_SESSION["staff_preview_auth_version"]=hash("sha256","'+HASH+'");'
            '$_SESSION["staff_preview_last"]=time();session_write_close();')
        subprocess.run(["php","-d","session.save_path="+str(sess),"-r",code],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        with socket.socket() as sk:
            sk.bind(("127.0.0.1",0));port=sk.getsockname()[1]
        env=dict(os.environ,ANKH_PORTAL_CONFIG=str(config),ANKH_TEST_ALLOW_HTTP="1")
        cmd=["php","-d","session.save_path="+str(sess),"-S",f"127.0.0.1:{port}","-t",str(ROOT/"customer-portal")]
        server=subprocess.Popen(cmd,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
        try:
            wait_server(port,server)
            base=f"http://127.0.0.1:{port}/storefront-preview.php"
            unauth,headers,_=request(base)
            assert unauth==303 and "preview-live.php" in headers.get("Location",""), ("auth",unauth,headers)
            cookie="ankh_staff_preview="+SESSION
            status,headers,body=request(base,cookie)
            assert status==200,(status,body[:300])
            assert "Test Case" in body and "Private Item" in body and "Inactive Item" not in body
            assert "Test basket" in body and "STAFF ONLY" in body
            assert "£12.00" in body and "£35.00" in body
            csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',body)
            assert csrf,"No protected basket form found"
            invalid,_,_=request(base,cookie,{'csrf':'bad','action':'add','product_id':'1','quantity':'2'})
            assert invalid==400,invalid
            invalid,_,_=request(base,cookie,{'csrf':csrf.group(1),'action':'add','product_id':'3','quantity':'1'})
            assert invalid==400,invalid
            added,head,_=request(base,cookie,{'csrf':csrf.group(1),'action':'add','product_id':'1','quantity':'2'})
            assert added==303 and "view=basket" in head.get("Location",""),(added,head)
            status,_,body=request(base+"?view=basket",cookie)
            assert status==200 and "£24.00" in body and "Checkout unavailable in preview" in body
            assert "Your summary" in body and "Test Case" in body
            code,_,details=request(base+"?product=2",cookie)
            assert code==200 and "Laboratory context" in details and "Private Item" in details
            code,_,_=request(base+"?image=2",cookie)
            assert code==404
            code,_,_=request(base+"?product=3",cookie)
            assert code==404
            with sqlite3.connect(db) as verify:
                after=verify.execute("SELECT id,name,description,price,active FROM products ORDER BY id").fetchall()
            assert start==after,"Storefront changed protected product data!"
            print("PASS: staff-only access, catalogue visibility, product details, CSRF, basket, disabled checkout, DB unchanged")
        finally:
            server.terminate()
            try:server.communicate(timeout=5)
            except subprocess.TimeoutExpired:server.kill();server.communicate()

if __name__=="__main__":
    main()
