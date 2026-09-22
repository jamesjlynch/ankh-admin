# ANKH Order Desk

Private, mobile-first order management in ANKH black and gold. PHP 8.1+ and PDO SQLite required. No paid third-party service is required by this app; normal hosting costs apply.

## Included
- Password sign-in, session expiry, CSRF protection and login throttling.
- Orders with multiple products, quantities, customer/contact details and notes.
- New / Awaiting payment / Paid / Packed / Dispatched / Cancelled statuses.
- Order search, status filters and customer history.
- Product creation/editing and hiding products from new orders.
- Prices stored in integer pence and snapshotted when an order is created.
- Data stored in a private SQLite database on the server.

This is a separate admin app. It does not yet import the storefront catalogue, WhatsApp conversations or storefront orders automatically. Products and orders initially need to be entered manually. There is one shared administrator login.

## Install on Krystal

1. Upload index.php, style.css and setup.php into public_html/ankh-admin/.
2. Enable PHP 8.1 or later and the pdo_sqlite extension.
3. In the hosting terminal, run: php ~/public_html/ankh-admin/setup.php
4. Save the generated password in your password manager.
5. Open https://YOUR-DOMAIN/ankh-admin/ and sign in.
6. Add your products, then create orders.

The default layout stores ankh-admin-config.php and ankh-admin-private/ in your hosting account home directory, outside public_html. Do not put the SQLite database in a public web folder. If using a different directory layout, set ANKH_ADMIN_CONFIG to an absolute path outside the document root before running setup and in the PHP runtime.

Setup is terminal-only and refuses to replace existing configuration. No password or database is committed to GitHub. HTTPS is required because session cookies are secure-only.

## Backup and maintenance
Back up the private configuration and database using your hosting backup system. Keep backups outside public_html. A backup of GitHub alone does not include orders. For live SQLite backups use SQLite's backup mechanism or take the app offline during the copy; do not assume copying a busy database is consistent.

Changing an order status does not send a message to the customer or take payment. Paid statuses are recorded manually. Values exclude delivery fees, tax adjustments and refunds.

## Validation
Source was reviewed for escaping, prepared queries, server-side validation and authorization checks. This authoring environment did not have a PHP runtime, so PHP lint and browser/database end-to-end testing still need to be run before real customer use:
- php -l index.php
- php -l setup.php
- Sign in, add/edit a product, create a two-item order and verify totals.
- Update its status, search/filter it and inspect customer history.
- Sign out and verify private records are inaccessible.
- Test at a phone-sized viewport.

No deployment workflow is configured yet. The existing storefront's deployment settings and secrets are not automatically shared with this repository.
