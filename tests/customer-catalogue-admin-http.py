"""Verify Admin's separate catalogue checkbox with a disposable database, never live data."""
import argparse
import base64
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request

parser=argparse.ArgumentParser()
parser.add_argument('--php',default='php')
args=parser.parse_args()
repo=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='ankh-catalogue-admin-') as directory:
    temp=Path(directory)
    database=temp/'admin.sqlite'
    password_hash=subprocess.check_output([args.php,'-r','echo password_hash("fixture-password",PASSWORD_DEFAULT);'],text=True)
    config={'database_path':str(database),'password_hash':password_hash}
    encoded=base64.b64encode(json.dumps(config).encode()).decode()
    config_file=temp/'config.php'
    config_file.write_text('<?php return json_decode(base64_decode("'+encoded+'"),true);')
    router=temp/'router.php'
    router.write_text("<?php $_SERVER['SERVER_ADDR']='127.0.0.1'; return false;")
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    env=os.environ|{'ANKH_TEST_ALLOW_HTTP':'1','ANKH_ADMIN_CONFIG':str(config_file)}
    log=open(temp/'server.log','w+')
    process=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','-t',str(repo),str(router)],env=env,stdout=log,stderr=log)
    base=f'http://127.0.0.1:{port}/index.php?view=products'
    def client():return urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    opener=client()
    def request(data=None,connection=None):
        result=(connection or opener).open(base,urllib.parse.urlencode(data).encode() if data is not None else None,timeout=30)
        html=result.read().decode()
        assert result.code==200,(result.code,html[:400])
        assert 'Fatal error' not in html and 'Warning:' not in html,html[:1000]
        return html
    def snapshot():
        with sqlite3.connect(database) as db:
            return {table:db.execute('SELECT * FROM '+table+' ORDER BY id').fetchall() for table in ['products','customers','orders','items','payments']}
    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):break
            except OSError:time.sleep(.05)
        html=request()
        token=re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1)
        html=request({'csrf':token,'action':'login','password':'fixture-password','return':'products'})
        with sqlite3.connect(database) as db:
            db.execute("INSERT INTO products(name,price,cost,active) VALUES('Case',2000,700,1),('Reference sample A',1200,300,1)")
        html=request()
        assert 'Save catalogue visibility' in html, re.findall(r'<h[12][^>]*>.*?</h[12]>|<div class="error".*?</div>',html,re.S)[:20]
        token=re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1)
        with sqlite3.connect(database) as db:
            case=db.execute("SELECT id FROM products WHERE name='Case'").fetchone()[0]
            excluded=db.execute("SELECT id FROM products WHERE name='Reference sample A'").fetchone()[0]
        before=snapshot()
        html=request({'csrf':token,'action':'product_catalogue_visibility','product_id':case,'catalogue_visible':'1','return':'products'})
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT visible FROM customer_catalogue_visibility WHERE product_id=?',(case,)).fetchone()==(1,)
        assert before==snapshot(),'Visibility changed an existing record'
        html=request({'csrf':token,'action':'product_catalogue_visibility','product_id':excluded,'catalogue_visible':'1','return':'products'})
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT visible FROM customer_catalogue_visibility WHERE product_id=?',(excluded,)).fetchone()==(1,)
        html=request({'csrf':'bad','action':'product_catalogue_visibility','product_id':case,'return':'products'})
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT visible FROM customer_catalogue_visibility WHERE product_id=?',(case,)).fetchone()==(1,)
            assert db.execute('SELECT visible FROM customer_catalogue_visibility WHERE product_id=?',(excluded,)).fetchone()==(1,)
        anonymous=client();anon_html=request(connection=anonymous)
        anon_token=re.search(r'name="csrf" value="([a-f0-9]+)"',anon_html).group(1)
        request({'csrf':anon_token,'action':'product_catalogue_visibility','product_id':case,'return':'products'},connection=anonymous)
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT visible FROM customer_catalogue_visibility WHERE product_id=?',(case,)).fetchone()==(1,)
        request({'csrf':token,'action':'product_catalogue_visibility','product_id':case,'return':'products'})
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT visible FROM customer_catalogue_visibility WHERE product_id=?',(case,)).fetchone()==(0,)
        assert before==snapshot()
        print('Admin catalogue checkbox HTTP checks passed: sign-in, CSRF, show/hide for arbitrary names and unchanged product/customer/order/payment records.')
    finally:
        process.terminate();process.wait(timeout=5);log.close()
