"""Exercise the accessory walkthrough over HTTP with temporary, fictional databases."""
import argparse
import base64
import hashlib
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
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php')
args = parser.parse_args()
repo = Path(__file__).resolve().parents[1]

with tempfile.TemporaryDirectory(prefix='ankh-accessory-http-') as temp:
    temp = Path(temp)
    admin = temp / 'admin.sqlite'
    portal = temp / 'portal.sqlite'
    with sqlite3.connect(admin) as db:
        db.executescript("""
        CREATE TABLE customers(id INTEGER PRIMARY KEY,name TEXT,phone TEXT,address TEXT,archived INTEGER,pricing_type TEXT DEFAULT 'standard');
        CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,price INTEGER,active INTEGER,cost INTEGER,stock_tracking INTEGER,stock_qty INTEGER,family_friends_price INTEGER);
        INSERT INTO products VALUES(1,'Case',2000,1,700,1,4,1500),(2,'Retatrutide 10mg',6500,1,2000,1,8,NULL),(3,'Reference sample A',1200,1,300,1,9,NULL);
        CREATE TABLE customer_catalogue_visibility(product_id INTEGER PRIMARY KEY,visible INTEGER,updated_at TEXT);
        INSERT INTO customer_catalogue_visibility VALUES(1,1,'test'),(2,0,'test'),(3,1,'test');
        INSERT INTO customers(id,name,phone,address,archived) VALUES(1,'Sample One','07123456789','Fictional Address',0),(2,'Sample Two','07999000000','Other Fictional Address',0);
        UPDATE customers SET pricing_type='family_friends' WHERE id=2;
        CREATE TABLE orders(id INTEGER PRIMARY KEY,customer TEXT,phone TEXT,status TEXT,created TEXT,delivery_date TEXT,scheduled_delivery_date TEXT,delivery_charge INTEGER,packaged INTEGER,reconstituted INTEGER,delivery_method TEXT,tracking_reference TEXT);
        CREATE TABLE items(id INTEGER PRIMARY KEY,order_id INTEGER,name TEXT,presentation TEXT,price INTEGER,quantity INTEGER);
        CREATE TABLE payments(id INTEGER PRIMARY KEY,order_id INTEGER,amount INTEGER,created TEXT,method TEXT);
        """)
    image_bytes=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
    with sqlite3.connect(admin) as db:
        db.execute('CREATE TABLE customer_catalogue_content(product_id INTEGER PRIMARY KEY,description TEXT,image_data BLOB,image_mime TEXT,image_width INTEGER,image_height INTEGER,updated_at TEXT)')
        for product_id in [1,2]:db.execute('INSERT INTO customer_catalogue_content VALUES(?,?,?,?,?,?,?)',(product_id,'Existing research description <script>example</script>',image_bytes,'image/png',1,1,'test'))
    with sqlite3.connect(admin) as db:
        db.execute('CREATE TABLE customer_catalogue_information(product_id INTEGER PRIMARY KEY,information TEXT,updated_at TEXT)')
        db.execute('INSERT INTO customer_catalogue_information VALUES(?,?,?)',(1,json.dumps({'strength':'Fixture quantity','research':'Original research <script>unsafe</script>','coa_url':'https://example.org/fixture-analysis.pdf','appearance':'Fixture colour','amino_length':'Fixture amino-acid count','prepared_storage':'Fixture prepared conditions','references':'Fixture citation https://pubmed.ncbi.nlm.nih.gov/123456/'}),'test'))
    with sqlite3.connect(portal) as db:
        db.execute('CREATE TABLE portal_rate_limits(rate_key TEXT PRIMARY KEY,attempts INTEGER,started_at INTEGER)')
    before = hashlib.sha256(admin.read_bytes()).hexdigest()
    password_hash = subprocess.check_output([args.php, '-r', 'echo password_hash("test-password",PASSWORD_DEFAULT);'], text=True)
    config = {'host': 'order.ankhpeptides.com', 'admin_database_path': str(admin), 'portal_database_path': str(portal), 'twilio_account_sid': 'PENDING', 'twilio_auth_token': 'PENDING', 'twilio_verify_service_sid': 'PENDING', 'preview_password_hash': password_hash}
    config_path = temp / 'config.php'
    encoded = base64.b64encode(json.dumps(config).encode()).decode()
    config_path.write_text('<?php return json_decode(base64_decode("' + encoded + '"),true);')
    # PHP's development server omits SERVER_ADDR. Supply it in this loopback-only test router.
    router = temp / 'router.php'
    router.write_text("<?php $_SERVER['SERVER_ADDR']='127.0.0.1'; return false;")
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    env = os.environ | {'ANKH_TEST_ALLOW_HTTP': '1', 'ANKH_PORTAL_CONFIG': str(config_path)}
    log = open(temp / 'server.log', 'w+')
    process = subprocess.Popen([args.php, '-S', f'127.0.0.1:{port}', '-t', str(repo / 'customer-portal'), str(router)], env=env, stdout=log, stderr=log)
    base = f'http://127.0.0.1:{port}'
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(path, data=None, status=200):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            response = opener.open(base + path, body, timeout=10)
        except urllib.error.HTTPError as exc:
            response = exc
        html = response.read().decode()
        assert response.code == status, (response.code, html[:300])
        assert 'Fatal error' not in html and 'Warning:' not in html, html[:500]
        if status == 200:
            assert html.lstrip().lower().startswith('<!doctype html>'), html[:200]
            assert '$_SESSION' not in html
        return html

    def csrf(html):
        return re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)

    try:
        for _ in range(60):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1):
                    break
            except OSError:
                time.sleep(.05)
        html = request('/preview.php?screen=account&tab=catalogue')
        assert 'Everyday carry case' in html and './preview.php?screen=account&amp;tab=basket' in html
        token = csrf(html)
        path = '/preview.php?screen=account&tab=basket'
        request(path, {'csrf': 'wrong', 'catalogue_action': 'add', 'product_id': 'carry-case', 'quantity': 1}, status=400)
        html = request(path, {'csrf': token, 'catalogue_action': 'add', 'product_id': 'carry-case', 'quantity': 2, 'price': 1})
        assert '£40.00' in html and 'Accessory added' in html
        html = request('/preview.php?screen=account&tab=checkout')
        checkout_token = re.search(r'name="checkout_token" value="([a-f0-9]+)"', html).group(1)
        payload = {'csrf': token, 'catalogue_action': 'checkout', 'checkout_token': checkout_token, 'delivery_method': 'delivery', 'address': '12 Fictional Street', 'postcode': 'SW1A 1AA', 'notes': '<script>alert(1)</script>', 'acknowledge': 'yes', 'total': 1}
        invalid = payload | {'postcode': 'invalid'}
        html = request(path, invalid)
        assert 'valid UK postcode' in html and '12 Fictional Street' in html
        html = request(path, payload)
        assert '£43.50' in html and 'Test request received' in html and '&lt;script&gt;alert(1)&lt;/script&gt;' in html
        assert '<script>alert(1)</script>' not in html
        reference = re.search(r'TEST-[A-F0-9]{8}', html).group(0)
        html = request(path, payload)
        assert reference in html
        html = request('/preview.php?screen=account&tab=test-orders')
        assert html.count('<h2>' + reference + '</h2>') == 1
        html = request('/preview.php?screen=account&tab=basket')
        assert 'Your basket is waiting' in html
        html = request('/preview.php?screen=account&tab=orders')
        assert reference not in html
        request('/preview.php', {'csrf': token, 'action': 'logout'})
        html = request('/preview.php?screen=account&tab=test-orders')
        assert reference not in html and 'No test requests' in html
        html = request('/preview-live.php?customer_id=1&tab=catalogue')
        assert 'Preview access' in html and 'Everyday carry case' not in html
        token = csrf(html)
        request('/preview-live.php', {'csrf': token, 'action': 'staff_login', 'password': 'test-password'})
        html = request('/preview-live.php?customer_id=1&tab=catalogue')
        assert '>Case</a></h2>' in html and '£20.00' in html and 'Available · 4' in html
        assert 'Retatrutide' not in html and '£65.00' not in html
        assert '>Reference sample A</a></h2>' in html and 'Laboratory research only' in html
        assert 'Not for human or animal consumption' in html
        assert 'catalogue_action' not in html and 'cost' not in html.lower()
        html = request('/preview-live.php?customer_id=2&tab=catalogue')
        assert '>Case</a></h2>' in html
        html=request('/preview-live.php?customer_id=2&tab=catalogue')
        assert '£15.00' in html and 'Your Family & Friends price' in html
        html=request('/preview-live.php?customer_id=2&tab=catalogue&product=1')
        assert 'pcs-section-nav' in html and 'Fixture quantity' in html
        assert 'Fixture colour' in html and 'Fixture amino-acid count' in html and 'Fixture prepared conditions' in html
        assert 'href="https://pubmed.ncbi.nlm.nih.gov/123456/"' in html
        assert 'pcs-sticky-enquiry' in html and 'Specifications' in html
        assert 'More from ANKH' in html and 'pcs-related-card' in html
        assert 'Retatrutide' not in html and '£12.00' in html
        assert 'pcs-quick-specs' not in html
        assert 'Original research &lt;script&gt;unsafe&lt;/script&gt;' in html
        assert 'https://example.org/fixture-analysis.pdf' in html and 'noopener noreferrer' in html
        assert '<script>unsafe</script>' not in html
        assert '<h1>Case</h1>' in html and '£15.00' in html and 'Enquire about this product' in html
        assert '&lt;script&gt;example&lt;/script&gt;' in html and '<script>example</script>' not in html
        photo=opener.open(base+'/preview-live.php?customer_id=2&catalogue_image=1')
        assert photo.headers['Content-Type']=='image/png' and photo.read()==image_bytes
        request('/preview-live.php?customer_id=2&catalogue_image=2',status=404)
        html=request('/preview-live.php?customer_id=2&tab=catalogue&product=2')
        assert 'Product not available' in html and '<h1>Retatrutide' not in html
        assert hashlib.sha256(admin.read_bytes()).hexdigest() == before, 'Admin database changed'
        print('HTTP catalogue flow passed: Family & Friends pricing, descriptions, protected photos, hidden products, enquiries and unchanged Admin database; separate demo checkout also passed.')
    finally:
        process.terminate()
        process.wait(timeout=5)
        log.close()
