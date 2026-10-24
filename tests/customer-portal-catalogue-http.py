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
    with sqlite3.connect(admin) as db:
        db.executescript("""
        INSERT INTO orders(id,customer,phone,status,created,delivery_date,delivery_charge) VALUES(10,'Sample Two','07999000000','Delivered','2026-10-01','2026-10-22',0),(11,'Sample One','07123456789','Delivered','2026-10-01','2026-10-22',0);
        INSERT INTO items VALUES(10,10,'Case','Vial',1500,1),(11,11,'Reference sample A','Vial',1200,1),(12,10,'Reference sample A','Vial',1,2),(13,10,'Retatrutide 10mg','Vial',6500,1);
        CREATE TABLE reta_cycles(id INTEGER PRIMARY KEY,customer_name TEXT,phone TEXT,last_order_id INTEGER,product_name TEXT,next_due_date TEXT,active INTEGER,cycle_weeks INTEGER,cycle_days INTEGER,presentation TEXT,quantity INTEGER,expected_cost INTEGER);
        INSERT INTO reta_cycles VALUES(1,'Sample Two','07999000000',10,'Case','2026-11-19',1,4,28,'Vial',1,987654),(2,'Sample One','07999000000',11,'Other customer reminder','2026-11-20',1,4,28,'Vial',1,987654),(3,'Sample Two','07999000000',10,'Paused reminder','2026-11-20',0,4,28,'Vial',1,987654);
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
        assert 'Staff sign in' in html and 'Everyday carry case' not in html
        token = csrf(html)
        request('/preview-live.php', {'csrf': token, 'action': 'staff_login', 'password': 'test-password'})
        html = request('/preview-live.php?customer_id=1&tab=catalogue')
        assert '>Case</a></h2>' in html and '£20.00' in html and 'Limited availability' in html and 'Available · 4' not in html
        assert 'pcs-collection' in html and 'pcs-category-tabs' in html and 'pcs-card-actions' in html
        assert 'aria-label="Enquire about Case"' in html and 'View product' in html
        assert 'Stationery</a>' not in html
        qa_path=os.environ.get('ANKH_CATALOGUE_QA_HTML')
        if qa_path:Path(qa_path).write_text(html)
        assert 'Retatrutide' not in html and '£65.00' not in html
        assert '>Reference sample A</a></h2>' in html and 'Laboratory research only' in html
        assert 'Not for human or animal consumption' in html
        assert 'catalogue_action' not in html and 'cost' not in html.lower()
        filtered=request('/preview-live.php?customer_id=1&tab=catalogue&category=Storage&sort=price-low&search=Case')
        assert '>Case</a></h2>' in filtered and '>Reference sample A</a></h2>' not in filtered
        assert 'category=Storage' in filtered and 'search=Case' in filtered and 'sort=price-low' in filtered
        empty=request('/preview-live.php?customer_id=1&tab=catalogue&category=Storage&search=missing')
        assert 'No matching products' in empty
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
        assert '<h1>Case</h1>' in html and '£15.00' in html and 'Add to basket' in html
        assert '&lt;script&gt;example&lt;/script&gt;' in html and '<script>example</script>' not in html
        photo=opener.open(base+'/preview-live.php?customer_id=2&catalogue_image=1')
        assert photo.headers['Content-Type']=='image/png' and photo.read()==image_bytes
        request('/preview-live.php?customer_id=2&catalogue_image=2',status=404)
        html=request('/preview-live.php?customer_id=2&tab=catalogue&product=2')
        assert 'Product not available' in html and '<h1>Retatrutide' not in html
        path='/preview-live.php?customer_id=2&tab=catalogue'
        html=request(path); token=csrf(html)
        request(path,{'csrf':'bad','action':'catalogue_favourite','product_id':1,'save':'1'},status=400)
        html=request(path,{'csrf':token,'action':'catalogue_favourite','product_id':1,'save':'1','customer_id':1})
        assert 'Product saved to favourites' in html
        saved=request(path+'&saved=1');assert '>Case</a></h2>' in saved and '>Reference sample A</a></h2>' not in saved
        other=request('/preview-live.php?customer_id=1&tab=catalogue&saved=1');assert 'No favourites yet' in other
        hidden=request(path,{'csrf':token,'action':'catalogue_favourite','product_id':2,'save':'1'});assert 'no longer available' in hidden
        html=request(path+'&product=1&enquire=1');assert 'product-enquiry' in html and 'data-product-zoom' in html
        nonce=re.search(r'name="request_token" value="([a-f0-9]+)"',html).group(1)
        payload={'csrf':token,'action':'catalogue_enquire','product_id':1,'message':'Question <script>unsafe()</script>','request_token':nonce,'price':1,'customer_id':1}
        html=request(path,payload);assert 'Your enquiry has been sent' in html and '&lt;script&gt;unsafe()&lt;/script&gt;' in html
        request(path,payload)
        with sqlite3.connect(portal) as saved_db:
            row=saved_db.execute('SELECT id,customer_id,price,preview FROM catalogue_enquiries').fetchall()
            assert len(row)==1 and row[0][1:]==(2,1500,1),row
            saved_db.execute('UPDATE catalogue_enquiries SET reply=?,status=? WHERE id=?',('Response <script>reply()</script>','Answered',row[0][0]))
        html=request(path+'&view_enquiries=1');assert 'Answered' in html and 'Response &lt;script&gt;reply()&lt;/script&gt;' in html and '<script>reply()' not in html
        other=request('/preview-live.php?customer_id=1&tab=catalogue&view_enquiries=1');assert 'No enquiries yet' in other and 'Question &lt;script&gt;' not in other
        request(path,{'csrf':token,'action':'catalogue_favourite','product_id':1,'save':'0'})
        assert 'No favourites yet' in request(path+'&saved=1')
        # Real HTTP regression: one customer list -> one existing enquiry thread, not checkout.
        assert 'Add to basket' in request(path+'&product=1')
        request(path, {'csrf':'wrong','action':'collection_set','product_id':1,'quantity':2},status=400)
        added=request(path, {'csrf':token,'action':'collection_set','product_id':1,'quantity':2,'customer_id':1})
        assert 'Request list updated' in added
        request(path, {'csrf':token,'action':'collection_set','product_id':3,'quantity':4})
        list_path='/preview-live.php?customer_id=2&tab=request-list'
        listed=request(list_path)
        assert 'Your basket' in listed and '£78.00' in listed and 'Test reference sample' not in listed
        assert 'Reference sample A' in listed and 'Case' in listed and 'Send order request' in listed
        other_list=request('/preview-live.php?customer_id=1&tab=request-list')
        assert 'Start with something you like' in other_list and '£78.00' not in other_list
        revision=re.search(r'name="revision" value="([a-f0-9]+)"',listed).group(1)
        list_token=re.search(r'name="request_token" value="([a-f0-9]+)"',listed).group(1)
        combined_payload={'csrf':token,'action':'collection_send','revision':revision,'request_token':list_token,
            'message':'Please confirm sample request','payment_preference':'bank_transfer','total':1,'customer_id':1}
        stale=request(list_path,combined_payload|{'revision':'a'*64})
        assert 'product or price has changed' in stale and 'Case' in stale
        sent=request(list_path,combined_payload)
        assert 'Your combined request has been sent' in sent and 'Please confirm sample request' in sent, sent[-6000:]
        request(list_path,combined_payload)
        with sqlite3.connect(portal) as saved_db:
            enquiry=saved_db.execute("SELECT id,customer_id,preview,price FROM catalogue_enquiries WHERE product_name='Multi-product request'").fetchall()
            transfer_note=saved_db.execute("SELECT message FROM catalogue_enquiries WHERE product_name='Multi-product request'").fetchone()[0]
            assert 'Payment preference: Tide bank transfer' in transfer_note
            assert 'This is an enquiry, not a completed order' in transfer_note
            assert len(enquiry)==1 and enquiry[0][1:]==(2,1,7800),enquiry
            saved_lines=saved_db.execute('SELECT product_id,quantity,unit_price FROM catalogue_enquiry_lines WHERE enquiry_id=? ORDER BY position',(enquiry[0][0],)).fetchall()
            assert saved_lines==[(3,4,1200),(1,2,1500)] or saved_lines==[(1,2,1500),(3,4,1200)],saved_lines
        assert 'Start with something you like' in request(list_path)
        assert 'Multi-product request' not in request('/preview-live.php?customer_id=1&tab=catalogue&view_enquiries=1')
        # Staff switches the public label without altering the read-only Admin SQLite data.
        # Availability now has its own studio panel; the default opens Product CMS.
        studio=request('/preview-live.php?manage=portal')
        assert 'Product page editor' in studio and 'pc-studio-nav' in studio
        manage_path='/preview-live.php?manage=portal&panel=availability'
        staff=request(manage_path);assert 'Product availability labels' in staff
        coming=request(manage_path,{'csrf':csrf(staff),'action':'portal_availability','product_id':3,'label':'coming_soon'})
        assert 'Saved successfully' in coming
        product=request(path+'&product=3')
        assert 'Coming soon' in product and 'Notify me when available' in product
        watched=request(path,{'csrf':token,'action':'watch_set','product_id':3,'enabled':1})
        assert 'Product watch saved' in watched
        watch_page=request('/preview-live.php?customer_id=2&tab=watchlist')
        assert 'Reference sample A' in watch_page and 'Stop watching' in watch_page
        assert 'Nothing on your watchlist yet' in request('/preview-live.php?customer_id=1&tab=watchlist')
        # A restored automatic label is detected on the next customer visit.
        request(manage_path,{'csrf':token,'action':'portal_availability','product_id':3,'label':'automatic'})
        restock=request('/preview-live.php?customer_id=2&tab=notifications')
        assert 'A watched product became available' in restock and 'Reference sample A' in restock
        assert 'A watched product became available' not in request('/preview-live.php?customer_id=1&tab=notifications')
        print('Request list and availability-watch HTTP checks passed: ownership, review, enquiry lines, approval controls and in-app restocks.')
        reminders=request('/preview-live.php?customer_id=2&tab=recurring')
        assert 'Next Order Centre' in reminders and '19 Nov 2026' in reminders and 'Every 4 weeks' in reminders
        assert '22 Oct 2026' in reminders and 'Enquire about next order' in reminders and 'order=10' in reminders
        assert 'Other customer reminder' not in reminders and 'Paused reminder' not in reminders and '987654' not in reminders
        assert 'No order or payment is created automatically' in reminders
        account_page=request('/preview-live.php?customer_id=2&tab=account');assert 'tab=recurring' in account_page
        # My Fridge: delivered-order-only importing, private data and customer-controlled forecasts.
        fridge_path='/preview-live.php?customer_id=2&tab=fridge'
        initial_fridge=request(fridge_path)
        assert 'My Fridge' in initial_fridge and 'Add from previous orders' in initial_fridge
        assert 'Undelivered vial' not in initial_fridge
        assert 'tab=fridge' in account_page
        request(fridge_path,{'csrf':'wrong','action':'fridge_add','order_id':10,'line_index':0},status=400)
        request(fridge_path,{'csrf':token,'action':'fridge_add','order_id':999,'line_index':0})
        invalid_import=request(fridge_path,{'csrf':token,'action':'fridge_add','order_id':11,'line_index':0})
        assert 'My Fridge' in invalid_import
        with sqlite3.connect(portal) as dbcheck:
            assert dbcheck.execute('SELECT COUNT(*) FROM portal_fridge_items WHERE customer_id=2 AND preview=1 AND source_order_id=11').fetchone()[0]==0
        added_fridge=request(fridge_path,{'csrf':token,'action':'fridge_add','order_id':10,'line_index':0})
        assert 'Added to My Fridge' in added_fridge and 'Case' in added_fridge
        fridge_item=int(re.search(r'name="item_id" value="([0-9]+)"',added_fridge).group(1))
        with sqlite3.connect(portal) as dbcheck:
            row=dbcheck.execute('SELECT customer_id,preview,source_order_id,source_line_index,remaining_milli FROM portal_fridge_items WHERE id=?',(fridge_item,)).fetchone()
            assert row==(2,1,10,0,1000),row
        assert 'Case' not in request('/preview-live.php?customer_id=1&tab=fridge').split('pc-fridge-card')[0]
        invalid_fridge=request(fridge_path,{'csrf':token,'action':'fridge_update','item_id':fridge_item,'remaining':'3e3','rate':'1','period':'week'})
        assert 'Enter a valid non-negative quantity' in invalid_fridge
        saved_fridge=request(fridge_path,{'csrf':token,'action':'fridge_update','item_id':fridge_item,'remaining':'1','rate':'0.5','period':'week'})
        assert 'Your fridge estimate has been updated' in saved_fridge and 'Estimated until' in saved_fridge
        with sqlite3.connect(portal) as dbcheck:
            row=dbcheck.execute('SELECT remaining_milli,rate_milli,rate_period FROM portal_fridge_items WHERE id=?',(fridge_item,)).fetchone()
            assert row==(1000,500,'week'),row
        other_update=request('/preview-live.php?customer_id=1&tab=fridge',{'csrf':token,'action':'fridge_update','item_id':fridge_item,'remaining':'0','rate':'1','period':'day'})
        assert 'no longer available in your account' in other_update
        with sqlite3.connect(portal) as dbcheck:
            assert dbcheck.execute('SELECT remaining_milli FROM portal_fridge_items WHERE id=?',(fridge_item,)).fetchone()[0]==1000
        request(fridge_path,{'csrf':token,'action':'fridge_archive','item_id':fridge_item})
        archived_fridge=request(fridge_path)
        assert "Let's organise your fridge" in archived_fridge
        with sqlite3.connect(portal) as dbcheck:
            assert dbcheck.execute('SELECT archived FROM portal_fridge_items WHERE id=?',(fridge_item,)).fetchone()[0]==1
        print('My Fridge HTTP checks passed: delivery validation, private ownership, forecasting, updates and archiving.')

        home_page=request('/preview-live.php?customer_id=2&tab=home');assert 'Shop the collection' in home_page
        other=request('/preview-live.php?customer_id=1&tab=recurring');assert 'No recurring orders set up' in other and '19 Nov 2026' not in other
        notices_path='/preview-live.php?customer_id=2&tab=notifications'
        notices=request(notices_path)
        assert 'ANKH replied to your enquiry' in notices and 'Order ANK-0010' in notices
        assert 'notification_read_all' in notices and 'pc-notification-bell' in notices and 'View reply' in notices
        event_keys=re.findall(r'name="event_key" value="([a-f0-9]+)"',notices)
        assert event_keys
        request(notices_path,{'csrf':'wrong','action':'notification_read','event_key':event_keys[0]},status=400)
        request(notices_path,{'csrf':token,'action':'notification_read','event_key':'f'*64},status=400)
        marked=request(notices_path,{'csrf':token,'action':'notification_read_all','customer_id':1})
        assert 'All current notifications marked as read' in marked
        unread=request(notices_path+'&unread=1');assert 'You’re all caught up' in unread
        other_notices=request('/preview-live.php?customer_id=1&tab=notifications')
        assert 'ANKH replied to your enquiry' not in other_notices and 'Order ANK-0010' not in other_notices
        # A restock can be the first notification; explicitly dismiss the reply event.
        reply_key=re.search(r'ANKH replied to your enquiry.*?name="event_key" value="([a-f0-9]+)"',notices,re.S).group(1)
        dismissed=request(notices_path,{'csrf':token,'action':'notification_dismiss','event_key':reply_key})
        assert 'Notification dismissed' in dismissed and 'ANKH replied to your enquiry' not in dismissed
        with sqlite3.connect(portal) as saved_db:
            saved_db.execute('UPDATE catalogue_enquiries SET reply=? WHERE customer_id=2',('A newer answer',))
            assert saved_db.execute('SELECT COUNT(*) FROM portal_notification_state WHERE customer_id=1').fetchone()[0]==0
        fresh=request(notices_path+'&unread=1');assert 'ANKH replied to your enquiry' in fresh and 'is-unread' in fresh
        home=request('/preview-live.php?customer_id=2&tab=home')
        assert 'Shop the collection' in home and 'Your basket' in home
        assert 'pc-shop-hero' in home and 'pc-shop-featured' in home and 'class="pcd-home-banner"' not in home
        assert 'Skip to account content' in home and 'id="customer-main"' in home
        repeat_path='/preview-live.php?customer_id=2&tab=repeat-enquiry&order=10'
        repeat=request(repeat_path)
        assert 'Send combined enquiry' in repeat and '£15.00' in repeat and '£12.00' in repeat
        assert 'Currently unavailable in the catalogue' in repeat
        assert 'Order unavailable' in request('/preview-live.php?customer_id=1&tab=repeat-enquiry&order=10')
        def hidden(html,name):return re.search(r'name="'+name+r'" value="([^"]*)"',html).group(1)
        repeat_payload={'csrf':csrf(repeat),'action':'catalogue_repeat_enquire','source_order_id':10,'quote_revision':hidden(repeat,'quote_revision'),'request_token':hidden(repeat,'request_token'),'repeat_selected[0]':'0','repeat_selected[1]':'1','repeat_quantities[0]':2,'repeat_quantities[1]':3,'message':'Fixture combined question','unit_price':1,'customer_id':1}
        request(repeat_path,repeat_payload|{'csrf':'wrong'},status=400)
        stale=request(repeat_path,repeat_payload|{'quote_revision':'a'*64});assert 'Prices or products have changed' in stale
        blocked=request(repeat_path,repeat_payload|{'repeat_selected[1]':'2'});assert 'unavailable. Review your selection' in blocked
        combined=request(repeat_path,repeat_payload);assert 'Your combined enquiry has been sent' in combined and 'Fixture combined question' in combined
        request(repeat_path,repeat_payload)
        with sqlite3.connect(portal) as saved_db:
            parents=saved_db.execute('SELECT id,customer_id,preview,price FROM catalogue_enquiries WHERE product_id=0 AND product_name LIKE \'Enquire again%\'').fetchall()
            assert len(parents)==1 and parents[0][1:]==(2,1,6600),parents
            lines=saved_db.execute('SELECT product_id,quantity,unit_price FROM catalogue_enquiry_lines WHERE enquiry_id=? ORDER BY position',(parents[0][0],)).fetchall()
            assert lines==[(1,2,1500),(3,3,1200)],lines
        assert 'Fixture combined question' not in request('/preview-live.php?customer_id=1&tab=catalogue&view_enquiries=1')
        reminder_path='/preview-live.php?customer_id=2&tab=recurring'
        page=request(reminder_path);assert 'Request a date or interval change' in page
        snooze={'csrf':csrf(page),'action':'reminder_snooze','cycle_id':1,'snooze_days':7}
        assert 'Reminder alerts snoozed' in request(reminder_path,snooze)
        assert 'Resume alerts' in request(reminder_path)
        assert 'no longer active in your account' in request('/preview-live.php?customer_id=1&tab=recurring',snooze)
        with sqlite3.connect(portal) as saved_db:
            assert saved_db.execute('SELECT customer_id,preview FROM customer_reminder_preferences').fetchall()==[(2,1)]
        assert 'Reminder alerts resumed' in request(reminder_path,snooze|{'action':'reminder_resume'})
        page=request(reminder_path)
        from datetime import date,timedelta
        next_date=(date.today()+timedelta(days=30)).isoformat()
        change={'csrf':csrf(page),'action':'reminder_change_request','cycle_id':1,'requested_weeks':6,'requested_date':next_date,'note':'Fixture date request','reminder_revision':hidden(page,'reminder_revision'),'request_token':hidden(page,'request_token')}
        assert 'reminder has changed' in request(reminder_path,change|{'reminder_revision':'0'*64})
        changed=request(reminder_path,change);assert 'awaiting ANKH approval' in changed and 'Change request: Pending' in changed
        request(reminder_path,change)
        with sqlite3.connect(portal) as saved_db:
            assert saved_db.execute('SELECT customer_id,preview,requested_weeks,status FROM customer_reminder_requests').fetchall()==[(2,1,6,'Pending')]
        assert 'Fixture date request' not in request('/preview-live.php?customer_id=1&tab=recurring')
        print('Repeat enquiry HTTP checks passed: ownership, current Family & Friends prices, hidden products, combined quantities, stale quote, CSRF and replay. Reminder snooze, resume and pending change requests passed.')
        assert hashlib.sha256(admin.read_bytes()).hexdigest() == before, 'Admin database changed'
        print('HTTP catalogue flow passed: Family & Friends pricing, descriptions, protected photos, hidden products, enquiries and unchanged Admin database; separate demo checkout also passed.')
    finally:
        process.terminate()
        process.wait(timeout=5)
        log.close()
