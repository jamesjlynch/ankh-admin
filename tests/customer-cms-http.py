"""Smoke-test the customer website CMS over real HTTP with disposable SQLite data."""
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

root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='ankh-cms-http-') as folder:
    tmp=Path(folder)
    db=tmp/'admin.sqlite'
    password='cms-test-password'
    hash_value=subprocess.check_output(['php','-r',f'echo password_hash("{password}",PASSWORD_DEFAULT);'],text=True)
    config=base64.b64encode(json.dumps({'database_path':str(db),'password_hash':hash_value}).encode()).decode()
    config_path=tmp/'config.php'
    config_path.write_text('<?php return json_decode(base64_decode("'+config+'"),true);')
    router=tmp/'router.php'
    router.write_text("<?php $_SERVER['SERVER_ADDR']='127.0.0.1'; return false;")
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    host='http://127.0.0.1:'+str(port)
    env=os.environ|{'ANKH_TEST_ALLOW_HTTP':'1','ANKH_ADMIN_CONFIG':str(config_path)}
    with open(tmp/'php-server.log','w+') as log:
        server=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t',str(root),str(router)],env=env,stdout=log,stderr=log)
        def get(opener,path):
            return opener.open(host+path,timeout=25).read().decode()
        def post(opener,data,path='/index.php?view=website_cms'):
            req=urllib.request.Request(host+path,urllib.parse.urlencode(data).encode(),method='POST')
            return opener.open(req,timeout=25).read().decode()
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError:time.sleep(.05)
            else:raise AssertionError('PHP fixture server did not start')
            opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            login_page=get(opener,'/index.php?view=website_cms')
            token=re.search(r'name="csrf" value="([a-f0-9]+)"',login_page)
            assert token,'Missing login CSRF token'
            post(opener,{'csrf':token.group(1),'action':'login','password':password,'return':'website_cms'})
            with sqlite3.connect(db) as conn:
                conn.execute("INSERT INTO products(name,price,cost,active) VALUES ('CMS demo vial 5mg',4250,1700,1)")
                product_id=conn.execute('SELECT id FROM products WHERE name=?',('CMS demo vial 5mg',)).fetchone()[0]
            page=get(opener,f'/index.php?view=website_cms&id={product_id}')
            assert 'Customer website CMS' in page and 'CMS demo vial 5mg' in page,page[:1200]
            assert 'Save website content' in page and 'Website visibility' in page
            assert '<html' in page.lower() and '</html>' in page.lower()
            assert 'Fatal error' not in page and 'temporarily unavailable' not in page
            # Reuse the same CSRF token, as required by the existing admin session.
            csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',page)
            assert csrf
            saved=post(opener,{'csrf':csrf.group(1),'action':'product_catalogue_content','product_id':str(product_id),
               'return':'website_cms','catalogue_visible':'1','catalogue_description':'CMS description from browser fixture.',
               'catalogue_information[strength]':'5mg validated laboratory sample'})
            assert 'Customer website CMS' in saved
            assert 'CMS description from browser fixture.' in saved
            assert '5mg validated laboratory sample' in saved
            with sqlite3.connect(db) as conn:
                row=conn.execute('SELECT price,stock_qty FROM products WHERE id=?',(product_id,)).fetchone()
                assert row[0]==4250,'CMS edited operational price'
                assert conn.execute('SELECT visible FROM customer_catalogue_visibility WHERE product_id=?',(product_id,)).fetchone()[0]==1
            print('PASS: authenticated customer CMS GET/POST, saved content and visibility; operational price unchanged.')
        except Exception:
            log.flush()
            log.seek(0)
            print('PHP test server output:',log.read()[-4000:])
            raise
        finally:
            server.terminate()
            server.wait(timeout=5)
