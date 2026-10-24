#!/usr/bin/env python3
"""HTTP checks for the real authenticated ANKH staff CMS editor.
Uses disposable databases; never contacts the production site.
"""
import hashlib,os,re,socket,sqlite3,subprocess,tempfile,time,urllib.request,urllib.parse,urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl):return None
def call(base,path,cookie='',data=None):
    req=urllib.request.Request(base+path,data=None if data is None else urllib.parse.urlencode(data).encode(),
        headers=({'Cookie':cookie} if cookie else {})|({'Content-Type':'application/x-www-form-urlencoded'} if data is not None else {}))
    try:
        with urllib.request.build_opener(NoRedirect()).open(req,timeout=9) as r:
            return r.status,dict(r.headers),r.read().decode('utf-8','replace')
    except urllib.error.HTTPError as e:
        return e.code,dict(e.headers),e.read().decode('utf-8','replace')
with tempfile.TemporaryDirectory(prefix='ankh-cms-http-') as tmp:
    d=Path(tmp);admin=d/'order.sqlite';portal=d/'portal.sqlite'
    con=sqlite3.connect(admin)
    con.executescript("""
        CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,description TEXT,price INTEGER,active INTEGER);
        CREATE TABLE customers(id INTEGER PRIMARY KEY,name TEXT,phone TEXT,address TEXT,archived INTEGER DEFAULT 0);
        CREATE TABLE customer_catalogue_visibility(product_id INTEGER PRIMARY KEY,visible INTEGER,updated_at TEXT);
        CREATE TABLE customer_catalogue_content(product_id INTEGER PRIMARY KEY,description TEXT,image_mime TEXT);
        CREATE TABLE customer_catalogue_information(product_id INTEGER PRIMARY KEY,information TEXT);
        INSERT INTO products VALUES(1,'BPC-157 5mg','Admin description',3500,1),(2,'Hidden Item','Hidden',2500,1);
        INSERT INTO customer_catalogue_visibility VALUES(1,1,'now'),(2,0,'now');
        INSERT INTO customer_catalogue_content VALUES(1,'Admin content','');
        INSERT INTO customer_catalogue_information VALUES(1,'{"formula":"ADMIN"}');
    """);con.commit();con.close()
    with sqlite3.connect(portal) as p:
        p.executescript("CREATE TABLE portal_rate_limits(rate_key TEXT PRIMARY KEY,attempts INTEGER NOT NULL,started_at INTEGER NOT NULL);")

    config=d/'config.php'
    passphrase='test-only-cms-password'
    password=subprocess.check_output(['php','-r', 'echo password_hash("'+passphrase+'", PASSWORD_BCRYPT);'],text=True).strip()
    config.write_text("<?php return ['admin_database_path'=>'"+str(admin)+"','portal_database_path'=>'"+str(portal)+"','host'=>'example.invalid','preview_password_hash'=>'"+password+"','twilio_account_sid'=>'dummy','twilio_auth_token'=>'dummy','twilio_verify_service_sid'=>'dummy'];")
    sess=d/'sessions';sess.mkdir()
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    server=subprocess.Popen(['php','-d','session.save_path='+str(sess),'-S',f'127.0.0.1:{port}','-t',str(ROOT/'customer-portal')],
        env=dict(os.environ,ANKH_PORTAL_CONFIG=str(config),ANKH_TEST_ALLOW_HTTP='1'),stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
    try:
        for _ in range(75):
            if server.poll() is not None:raise RuntimeError('Server exited unexpectedly')
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.12):break
            except OSError:time.sleep(.1)
        base=f'http://127.0.0.1:{port}'
        page='/preview-live.php?manage=portal&panel=products&cms_product_id=1'
        status,headers,body=call(base,page)
        assert status==200 and 'Open staff workspace' in body,(status,body[:280])
        cookie=headers.get('Set-Cookie','').split(';',1)[0]
        assert cookie.startswith('ankh_staff_preview='),headers
        csrf_login=re.search('name="csrf" value="([a-f0-9]{64})"',body)
        assert csrf_login,body[:400]
        logged,login_headers,_=call(base,'/preview-live.php',cookie,
            {'csrf':csrf_login.group(1),'action':'staff_login','password':passphrase})
        assert logged==303,(logged,login_headers)
        assert login_headers.get('Location')=='./preview-live.php?manage=portal&panel=products',login_headers
        cookie=login_headers.get('Set-Cookie','').split(';',1)[0] or cookie
        status,_,body=call(base,page,cookie)
        assert status==200 and 'Product page editor' in body,(status,body[:450])
        assert 'BPC-157 5mg' in body and 'cms_save_draft' in body
        assert 'name="price"' not in body and 'name="stock_qty"' not in body
        assert 'cms-studio.css' in body and 'pc-studio-nav' in body
        qa_dir=os.environ.get('ANKH_CMS_QA_DIR')
        if qa_dir:Path(qa_dir).mkdir(parents=True,exist_ok=True);(Path(qa_dir)/'products.html').write_text(body)
        for label in ['Product CMS','Certificates','Storefront settings','Customer requests','Availability','WhatsApp','Customer preview','Sign out']:
            assert label in body,label
        for panel in ['certificates','commerce','requests','availability','contact']:
            panel_status,_,panel_body=call(base,'/preview-live.php?manage=portal&panel='+panel,cookie)
            assert panel_status==200 and 'aria-label="Admin sections"' in panel_body,(panel,panel_status,panel_body[:400])
        for settings,heading in [('business','Business &amp; delivery details'),('basket','Basket product selection'),('checkout','Accessory checkout'),('requests','No guest requests yet')]:
            status,_,settings_body=call(base,'/preview-live.php?manage=portal&panel=commerce&settings='+settings,cookie)
            assert status==200 and heading in settings_body,(settings,status)
            assert 'class="pc-panel pc-sf-staff pc-store-settings"' in settings_body
            if settings=='business':assert 'name="company"' in settings_body and 'name="demo_product[]"' not in settings_body
            if settings=='basket':assert 'pc-basket-search' in settings_body and 'name="company"' not in settings_body
            if qa_dir:(Path(qa_dir)/(settings+'.html')).write_text(settings_body)
        preview_status,_,preview_body=call(base,'/preview-live.php?preview=customers',cookie)
        assert preview_status==200 and 'aria-label="Admin sections"' in preview_body and 'Choose a customer' in preview_body

        m=re.search('name="csrf" value="([a-f0-9]{64})"',body);assert m
        csrf=m.group(1)
        saved,saved_headers,_=call(base,page,cookie,{'csrf':csrf,'action':'sf_facts_save','company':'Test Company','fulfilment':'Sample fulfilment','dispatch':'Sample delivery','support':'Sample support'})
        assert saved==303 and saved_headers['Location'].endswith('settings=business'),saved_headers
        with sqlite3.connect(portal) as db:assert db.execute('SELECT company FROM storefront_business_facts').fetchone()[0]=='Test Company'
        saved,saved_headers,_=call(base,page,cookie,{'csrf':csrf,'action':'sf_demo_save_all','demo_product[]':'1'})
        assert saved==303 and saved_headers['Location'].endswith('settings=basket'),saved_headers
        invalid,_,_=call(base,page,cookie,{'csrf':'not-valid','action':'cms_save_draft','product_id':'1','cms_description':'hijacked'})
        assert invalid==400,invalid
        data={'csrf':csrf,'action':'cms_save_draft','product_id':'1','cms_description':'New CMS description','cms_information[formula]':'C62H98N16O22'}
        status,head,_=call(base,page,cookie,data)
        assert status==303,(status,head)
        with sqlite3.connect(portal) as con:
            row=con.execute('SELECT draft_description,has_published FROM portal_cms_product_content WHERE product_id=1').fetchone()
            assert row==('New CMS description',0),row
        status,_,_=call(base,page,cookie,{'csrf':csrf,'action':'cms_publish','product_id':'1'})
        assert status==303,status
        with sqlite3.connect(portal) as con:
            row=con.execute('SELECT published_description,has_published FROM portal_cms_product_content WHERE product_id=1').fetchone()
            assert row==('New CMS description',1),row
        with sqlite3.connect(admin) as con:
            assert con.execute('SELECT price FROM products WHERE id=1').fetchone()[0]==3500
        status,_,_=call(base,page,cookie,{'csrf':csrf,'action':'cms_unpublish','product_id':'1'})
        assert status==303
        with sqlite3.connect(portal) as con:
            assert con.execute('SELECT has_published FROM portal_cms_product_content WHERE product_id=1').fetchone()[0]==0
        # Live content can be inspected without overwriting an existing draft.
        call(base,page,cookie,{'csrf':csrf,'action':'cms_save_draft','product_id':'1','cms_description':'Published version','cms_information[formula]':'LIVE','cms_intent':'publish'})
        call(base,page,cookie,{'csrf':csrf,'action':'cms_save_draft','product_id':'1','cms_description':'Unpublished version','cms_information[formula]':'DRAFT'})
        _,_,live_body=call(base,page+'&source=live',cookie)
        assert '>Published version</textarea>' in live_body and 'name="cms_information[formula]"' in live_body
        with sqlite3.connect(portal) as db:assert db.execute('SELECT draft_description,published_description FROM portal_cms_product_content WHERE product_id=1').fetchone()==('Unpublished version','Published version')
        rejected,_,_=call(base,page,cookie,{'csrf':csrf,'action':'cms_save_draft','product_id':'2','cms_description':'Must not publish hidden content','cms_information[formula]':'HIDDEN','cms_intent':'publish'})
        assert rejected==303
        with sqlite3.connect(portal) as db:assert db.execute('SELECT COUNT(*) FROM portal_cms_product_content WHERE product_id=2').fetchone()[0]==0
        # Expired sessions keep the requested CMS panel and selected product through sign-in.
        _,logout_headers,_=call(base,'/preview-live.php',cookie,{'csrf':csrf,'action':'logout'})
        cookie=logout_headers.get('Set-Cookie','').split(';',1)[0] or cookie
        logged_out,_,logged_out_body=call(base,page,cookie)
        assert logged_out==200 and 'Product page editor' not in logged_out_body
        target=re.search(r'<form method="post" action="([^"]+)"',logged_out_body).group(1).replace('&amp;','&')
        assert 'cms_product_id=1' in target and 'panel=products' in target,target
        login_csrf=re.search('name="csrf" value="([a-f0-9]{64})"',logged_out_body).group(1)
        again,again_headers,_=call(base,target.replace('./','/',1),cookie,{'csrf':login_csrf,'action':'staff_login','password':passphrase})
        assert again==303 and again_headers.get('Location')==target,(again,again_headers)
        print('PASS: staff login lands in CMS; deep links survive login; all panels and customer preview share navigation; authenticated editing, CSRF and Admin price isolation.')
    finally:
        server.terminate()
        try:
            stdout,stderr=server.communicate(timeout=5)
            if server.returncode is not None and server.returncode not in (-15,0): print(stderr.decode()[-1200:])
        except subprocess.TimeoutExpired:server.kill();server.communicate()
