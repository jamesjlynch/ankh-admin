"""Verify Admin's separate catalogue checkbox with a disposable database, never live data."""
import argparse
import urllib.error
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
    def request(data=None,connection=None,content_type=None):
        body=data if isinstance(data,bytes) else (urllib.parse.urlencode(data).encode() if data is not None else None)
        req=urllib.request.Request(base,body,headers={'Content-Type':content_type} if content_type else {})
        result=(connection or opener).open(req,timeout=30)
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
        assert 'Save catalogue details' in html, re.findall(r'<h[12][^>]*>.*?</h[12]>|<div class="error".*?</div>',html,re.S)[:20]
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
        image=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
        fields={'csrf':token,'action':'product_catalogue_content','product_id':str(case),'catalogue_description':'Product description <script>bad()</script>','catalogue_visible':'1','return':'products'}
        boundary='ankh-photo-test-boundary'
        body=b''
        for key,value in fields.items():body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+value+'\r\n').encode()
        body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="catalogue_photo"; filename="photo.png"\r\nContent-Type: image/png\r\n\r\n').encode()+image+('\r\n--'+boundary+'--\r\n').encode()
        request(body,content_type='multipart/form-data; boundary='+boundary)
        html=request()
        assert '&lt;script&gt;bad()&lt;/script&gt;' in html and 'data-photo-preview' in html
        image_url=base.split('?')[0]+'?api=catalogue-image&id='+str(case)
        response=opener.open(image_url)
        assert response.headers['Content-Type']=='image/png' and response.read()==image
        assert response.headers['X-Content-Type-Options']=='nosniff'
        try:
            client().open(image_url)
            raise AssertionError('Anonymous image access allowed')
        except urllib.error.HTTPError as exc:assert exc.code==401
        request({'csrf':token,'action':'product_catalogue_content','product_id':case,'catalogue_description':'Description retained','remove_catalogue_photo':'1','catalogue_visible':'1','return':'products'})
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT description,image_data FROM customer_catalogue_content WHERE product_id=?',(case,)).fetchone()==('Description retained',None)
        assert before==snapshot()
        print('Photo upload, authenticated image delivery, escaped description and photo removal checks passed.')
        print('Admin catalogue checkbox HTTP checks passed: sign-in, CSRF, show/hide for arbitrary names and unchanged product/customer/order/payment records.')
    finally:
        process.terminate();process.wait(timeout=5);log.close()
