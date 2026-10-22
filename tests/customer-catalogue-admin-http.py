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
    interaction_db=temp/'portal.sqlite'
    sqlite3.connect(interaction_db).close()
    interaction_config=temp/'portal-config.php'
    interaction_config.write_text('<?php return '+"['portal_database_path'=>'"+str(interaction_db)+"'];")
    env=os.environ|{'ANKH_TEST_ALLOW_HTTP':'1','ANKH_ADMIN_CONFIG':str(config_file),'ANKH_PORTAL_CONFIG':str(interaction_config)}
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
        fields={'csrf':token,'action':'product_catalogue_content','product_id':str(case),'catalogue_description':'Product description <script>bad()</script>','catalogue_visible':'1','return':'products','catalogue_information[strength]':'Fixture specification','catalogue_information[research]':'Fixture research notes'}
        boundary='ankh-photo-test-boundary'
        body=b''
        for key,value in fields.items():body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+value+'\r\n').encode()
        body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="catalogue_photo"; filename="photo.png"\r\nContent-Type: image/png\r\n\r\n').encode()+image+('\r\n--'+boundary+'--\r\n').encode()
        request(body,content_type='multipart/form-data; boundary='+boundary)
        html=request()
        assert 'Fixture specification' in html and 'Fixture research notes' in html
        with sqlite3.connect(database) as db:
            assert 'Fixture specification' in db.execute('SELECT information FROM customer_catalogue_information WHERE product_id=?',(case,)).fetchone()[0]
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
        html=request()
        assert 'catalogue-guide-step' in html and 'catalogue_information[amino_length]' in html
        assert 'data-catalogue-insert' not in html and 'Research &amp; literature' in html
        assert 'Fixture specification' in html and 'Fixture research notes' in html
        unified='Original overview.\n\n## Specifications\n- Quantity: fixture\n\n## Research notes\nNew saved information.'
        request({'csrf':token,'action':'product_catalogue_content','product_id':case,'catalogue_editor':'unified','catalogue_description':unified,'catalogue_visible':'1','return':'products'})
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT description FROM customer_catalogue_content WHERE product_id=?',(case,)).fetchone()==(unified,)
            assert json.loads(db.execute('SELECT information FROM customer_catalogue_information WHERE product_id=?',(case,)).fetchone()[0])==[]
        html=request()
        assert 'New saved information.' in html and 'Fixture specification' not in html
        guided={'peptide_type':'Fixture peptide','appearance':'Fixture colour','amino_length':'15 (fixture)','weight':'Fixture molecular weight','formula':'Fixture formula','sequence':'Fixture sequence','half_life':'Fixture conditions and reference','pubchem':'Fixture identifier','research_focus':'Fixture research focus','research':'Fixture research background','references':'Fixture paper https://pubmed.ncbi.nlm.nih.gov/123456/','storage':'Fixture sealed storage','prepared_storage':'Fixture prepared storage','coa_url':'https://example.org/analysis.pdf'}
        fields={'csrf':token,'action':'product_catalogue_content','product_id':case,'catalogue_editor':'guided','catalogue_description':'Guided product introduction','catalogue_visible':'1','return':'products'}
        fields.update({'catalogue_information['+key+']':value for key,value in guided.items()})
        request(fields)
        with sqlite3.connect(database) as db:
            saved=json.loads(db.execute('SELECT information FROM customer_catalogue_information WHERE product_id=?',(case,)).fetchone()[0])
            assert saved==guided
            assert db.execute('SELECT description FROM customer_catalogue_content WHERE product_id=?',(case,)).fetchone()==('Guided product introduction',)
        html=request()
        assert 'Fixture colour' in html and 'Fixture prepared storage' in html
        php_seed="require '"+str(repo/'customer-catalogue.php')+"'; $s=ankhCustomerInteractionStore(); ankhCustomerCreateEnquiry($s,['id'=>1,'name'=>'Inbox fixture','price'=>1500],1,true,'Inbox question <script>bad()</script>',str_repeat('a',64));"
        subprocess.check_call([args.php,'-r',php_seed],env=env)
        inbox_url=base.split('?')[0]+'?view=enquiries'
        inbox=opener.open(inbox_url).read().decode();assert 'Inbox fixture' in inbox and '&lt;script&gt;bad()&lt;/script&gt;' in inbox and 'STAFF TEST' in inbox
        fields={'csrf':token,'action':'catalogue_enquiry_reply','enquiry_id':1,'reply':'Answer <script>reply()</script>','enquiry_status':'Answered'}
        html=request(fields)
        assert 'Customer enquiries' in html and 'Answer &lt;script&gt;reply()&lt;/script&gt;' in html
        with sqlite3.connect(interaction_db) as db:
            assert db.execute('SELECT reply,status FROM catalogue_enquiries WHERE id=1').fetchone()==('Answer <script>reply()</script>','Answered')
        with sqlite3.connect(interaction_db) as db:
            db.execute("INSERT INTO customer_reminder_requests(customer_id,preview,cycle_id,product_name,original_due_date,original_weeks,source_order_id,revision,requested_weeks,requested_date,note,created_at,request_token) VALUES(1,1,99,'Reminder fixture','2026-11-01',4,1,'fixture',6,'2026-12-01','Fixture request',1,?)",('b'*64,))
        inbox=opener.open(inbox_url).read().decode();assert 'Reminder change requests' in inbox and 'Reminder fixture' in inbox
        review={'csrf':token,'action':'reminder_request_review','request_id':1,'decision':'decline','admin_note':'Fixture response <script>bad()</script>'}
        request(review|{'csrf':'bad'})
        with sqlite3.connect(interaction_db) as db:assert db.execute('SELECT status FROM customer_reminder_requests WHERE id=1').fetchone()==('Pending',)
        html=request(review);assert 'Declined' in html and 'Fixture response &lt;script&gt;bad()&lt;/script&gt;' in html
        with sqlite3.connect(interaction_db) as db:assert db.execute('SELECT status,admin_note FROM customer_reminder_requests WHERE id=1').fetchone()==('Declined','Fixture response <script>bad()</script>')
        print('Admin reminder decision HTTP checks passed: authenticated inbox, CSRF rejection, decision persistence and escaped response.')
        anonymous=client().open(inbox_url).read().decode();assert 'Inbox question' not in anonymous
        assert before==snapshot()
        print('Guided fields, legacy content, optional sections and original records preservation passed.')
        print('Photo upload, authenticated image delivery, escaped description and photo removal checks passed.')
        print('Admin catalogue checkbox HTTP checks passed: sign-in, CSRF, show/hide for arbitrary names and unchanged product/customer/order/payment records.')
    finally:
        process.terminate();process.wait(timeout=5);log.close()
