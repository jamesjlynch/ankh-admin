# ANKH Customer Portal — Phase 1

**Customer URL:** https://order.ankhpeptides.com (planned; NOT live merely by merging this branch).

A separate, iPhone-first public PHP application. ANKH Admin remains private at its existing URL and is the only system that can change stock, prices, customer records, payments and orders. The customer portal queries the **same SQLite orders database read-only** and maintains a separate tiny private authentication database. No order requests, product catalogue, online payments or checkout are enabled in this phase.

## Before launch (requires Krystal and Twilio account access)

1. On the **same Krystal cPanel account** as the live ANKH Admin SQLite database, choose **Domains → Create a New Domain**. Enter **order.ankhpeptides.com**. **Untick "Share document root"**. Set the document root to **public_html/ankh-order**. Never point this domain at the Admin folder.
2. Ensure DNS for `order.ankhpeptides.com` resolves to that Krystal account. If DNS is managed externally, add the correct DNS record there. Enable/verify SSL (cPanel AutoSSL). **Do not enable customer sign-in over HTTP.**
3. Create a **Twilio Verify Service** for SMS (a paid external service, not provisioned by this code). Its service SID begins with VA. Obtain your Account SID and Auth Token securely. Configure SMS fraud protection, geographic permissions and spend alerts within Twilio.
4. In the Krystal terminal, use the **actual live** `ankh-admin-config.php` to locate the existing private SQLite database; do not guess the path or move that database. Copy `customer-portal/config.example.php` to **/home/CPANEL_USER/ankh-customer-portal-config.php**, update the two private database paths and Twilio credentials, and `chmod 600` the populated config file. Keep it **outside public_html**, never commit it to Git.
5. Ensure PHP 8.1+ has `pdo_sqlite` and `curl`. In the checked-out repository, run:

   ```sh
   export ANKH_PORTAL_CONFIG=/home/CPANEL_USER/ankh-customer-portal-config.php
   php scripts/customer-portal-accounts.php init
   php scripts/customer-portal-accounts.php list
   php scripts/customer-portal-accounts.php grant CUSTOMER_ID
   # To remove access immediately:
   php scripts/customer-portal-accounts.php revoke CUSTOMER_ID
   ```

   **Only explicitly granted accounts** can receive a code or view order history. Shared numbers are blocked from being granted to multiple identities. Before granting, confirm the customer ID and current phone in Admin. Users must complete SMS verification; the CLI never signs them in.

6. Publish **only after** those prerequisites are complete:

   ```sh
   export ANKH_CUSTOMER_PORTAL_ROOT=/home/CPANEL_USER/public_html/ankh-order
   export ANKH_DEPLOY_PHP=/opt/cpanel/ea-php82/root/usr/bin/php
   bash scripts/publish-customer-portal.sh
   ```

   The publisher intentionally refuses to write to the existing Admin folder. Unlike the Admin app, it does **not** auto-deploy on a GitHub push. A working cPanel subdomain, private config and SMS service must be verified first.

## Identity and privacy boundaries

- Never match users by telephone number alone. Existing Admin orders have no `customer_id`, so this portal requires an operator-approved mapping to one **Admin customer ID**, then restricts orders to **both** that customer's current name and normalised verified UK mobile.
- If the current Admin name or telephone changes, old orders with the former name/number will no longer appear until a controlled reconciliation is implemented. **Do not relax the name/phone filter.** Shared/changed number cases require manual identity review.
- Grants, revocations and phone changes invalidate prior portal sessions via an account authorization version. Verified sessions expire after 30 minutes idle. OTP codes are never stored by this app.
- No customer can see supplier costs, profit, stock per fridge, admin notes, bank/payment methods, another customer's account or raw admin data.
- Private config and both SQLite files must remain outside every document root. HTTPS, CSRF protection, same-site httpOnly cookies, no-store headers, strict host and content security policies are enforced.
- Store a suitable GDPR privacy notice and lawful-basis/retention information on the public business website before releasing the portal to real customers. SMS verification verifies control of a number, not identity by itself; manual account approval remains essential.

## Test checklist

- A granted test customer receives a real SMS and signs in, views only their own history, item snapshots, totals, payment balance and planned/delivered status.
- An ungranted number cannot view information; the interface does not reveal whether a number is registered.
- Two customer records using the same number do not automatically share history. Attempt to grant both: the second must fail.
- Changed phone/archived customer/revoked grant blocks the previous session; a subsequent sign-in requires new OTP.
- Test a cancelled, free, part-paid, delivered and scheduled-for-future order. Planned dates never imply an order is delivered.
- Check on a real iPhone at 375px and 390px widths; test SMS auto-fill and VoiceOver keyboard navigation.
- Run `php tests/customer-portal.php`, `php -l customer-portal/index.php`, and the complete GitHub CI suite.

## Known Phase 1 limits

- No self-enrolment or automatic matching of old orders, to avoid exposing shared-phone histories.
- No online ordering or reordering. Those features require a separate review of the UK's medicine/product-classification, advertising and supply rules, alongside fulfilment requirements.
- Twilio credentials and SMS service are **not** included. A working login cannot be claimed until they are configured and an actual SMS/login test passes.
- cPanel DNS, document-root and certificate settings cannot be created by repository code alone.

## Testing before Twilio is configured (safe fictional demo)

The **real** sign-in route `https://order.ankhpeptides.com/` deliberately returns a temporary unavailable page until valid Twilio credentials are installed; it cannot send or accept test bypass codes.

You can publish a **separate simulated customer experience** with zero access to production data or SMS:

```bash
set -euo pipefail
REPO=/home/devcouk/repositories/ankh-admin-git
STAGING=/home/devcouk/ankh-customer-portal-staging

git -C "$REPO" fetch origin feature/ankh-customer-portal:refs/remotes/origin/feature/ankh-customer-portal
git -C "$REPO" archive origin/feature/ankh-customer-portal customer-portal scripts/customer-portal-accounts.php scripts/publish-customer-portal.sh tests/customer-portal.php | tar -x -C "$STAGING"
php "$STAGING/tests/customer-portal.php"
export ANKH_CUSTOMER_PORTAL_ROOT=/home/devcouk/public_html/ankh-order
export ANKH_PORTAL_CONFIG=/home/devcouk/ankh-customer-portal-config.php
bash "$STAGING/scripts/publish-customer-portal.sh"
```

Preview URL: **https://order.ankhpeptides.com/preview.php**

The preview has Login and Customer dashboard buttons. The login screen accepts a valid UK-format *test* mobile number and demonstrates the code-entry page; on preview.php **only**, enter sample code **123456**, or use the Customer dashboard button to go straight to the fake account. It shows expandable example orders, delivery dates, partial payment, order totals, account details, and responsive layouts. The demo has only hard-coded **fictional** customer records: no Admin DB connection, no account creation, no SMS delivery, and no stock changes. It is not a production authentication test.

Use an iPhone to check typography, buttons, overflow, responsiveness, accordions, and navigation; test the real OTP delivery, enrolment, stale-session revocation, and real order visibility **after** installing Twilio, with a specifically approved test customer.

## Owner-only preview using LIVE existing customers (no Twilio required)

**Do not bypass the customer login or publish an unprotected list of customers.** Use the distinct **temporary staff password** to open `/preview-live.php`. This route reads the real Admin SQLite records with `PRAGMA query_only=ON`. No editing and no SMS messages.

- The **main public customer route** remains locked until Twilio is configured.
- The staff preview has a separate, 20-minute idle-expiring session and rate-limited temporary password. Private config stores only a password hash. All staff preview customer selection pages require authentication.
- You can search by existing customer name or phone and select a specific customer to see their exact eligible order history, item quantities, payments and delivery status.
- Historical orders are matched by both current customer name and phone. If either has changed, orders won't automatically appear. This is intentional and must be addressed in a future reconciliation feature, not by dropping identity checks.
- **Do not share the staff preview password with customers** and rotate or remove `preview_password_hash` when customer SMS login goes live.
- The preview is not a substitute for verifying actual customer SMS authentication later.

From the server shell after downloading the latest feature branch, run:

```bash
export ANKH_PORTAL_CONFIG=/home/devcouk/ankh-customer-portal-config.php
php /home/devcouk/ankh-customer-portal-staging/scripts/configure-customer-preview.php
```

Save the one-time randomly generated password privately. **Never paste it into ChatGPT, GitHub, screenshots, tickets, or messages.** A second run won't reset it; add `--rotate` to intentionally rotate it.

Then deploy with the dedicated `scripts/publish-customer-portal.sh` script and open **https://order.ankhpeptides.com/preview-live.php** to sign in as staff. The folder must be `/home/devcouk/public_html/ankh-order`.

**Before giving customers the domain:** disable or remove the staff preview (delete the published `preview-live.php` and remove `preview_password_hash` from private config) once staff review finishes.

## October 2026 — Premium customer workspace (five tabs)

Once signed in, **Home**, **Orders**, **Vault**, **Support** and **Account** use a unified black-and-gold responsive design. On iPhone there is a fixed five-tab bottom navigation; on wider screens navigation is horizontal. The protected live-data staff preview uses the same views as the eventual customer account.

- **Home:** active order count, total order count, recorded payments, outstanding balances, latest order progress and dated activity derived from actual order creation/payment/delivery records.
- **Orders:** customer-scoped search and status filters, expandable individual order journeys, item prices/quantities, delivery method/reference if present, recorded payment events, outstanding balance and a link to a printable summary. Stages are based on existing order status and preparation flags; only created and delivered dates are shown as verified event timestamps. Planned delivery is not proof of delivery.
- **Vault:** printable order summaries generated from the authenticated customer's actual order data; not VAT invoices and not automatically-issued batch certificates. Laboratory and batch documents are clearly labelled as not yet online; links request documents by email.
- **Support:** working mailto links for different types of enquiry and individual order references. This does **not** falsely claim to provide live chat, unread messages or support-ticket tracking.
- **Account:** name, mobile, address, recorded order count and email-based change / personal-data requests. No direct customer-editing yet.

All views are server-rendered PHP with escaped customer content, and order drill-down selection is constrained to the current verified customer's already-filtered order array. There are no new public database write permissions and no sales/checkout, reordering, discounts or dosing features.

### Safe deployment to the existing protected Krystal staff preview

Keep the main Admin checkout on `main`. Update staging **without switching branches**:

```sh
bash -lc 'set -euo pipefail; R=/home/devcouk/repositories/ankh-admin-git; S=/home/devcouk/ankh-customer-portal-staging; git -C "$R" fetch origin feature/ankh-customer-portal:refs/remotes/origin/feature/ankh-customer-portal; git -C "$R" archive origin/feature/ankh-customer-portal customer-portal scripts/customer-portal-accounts.php scripts/configure-customer-preview.php scripts/publish-customer-portal.sh tests/customer-portal.php tests/customer-portal-render.php tests/customer-portal-navigation.php | tar -x -C "$S"; php "$S/tests/customer-portal.php"; php "$S/tests/customer-portal-render.php"; for tab in home orders vault support account; do php "$S/tests/customer-portal-navigation.php" "$tab"; done'
```

After all tests pass, publish to the isolated Krystal subdomain only:

```sh
ANKH_PORTAL_CONFIG=/home/devcouk/ankh-customer-portal-config.php ANKH_CUSTOMER_PORTAL_ROOT=/home/devcouk/public_html/ankh-order bash /home/devcouk/ankh-customer-portal-staging/scripts/publish-customer-portal.sh
```

Then visit `https://order.ankhpeptides.com/preview-live.php`, unlock with the existing private staff preview password and select a real customer. The actual customer login at `/` remains gated until a valid SMS provider is installed and tested.

CI contains isolated fixtures covering identity matching, payment and delivery totals, the rendered template (no raw PHP leakage), the five tabs and order-detail/print views. A PHP render/lint test cannot replace an end-to-end iPhone check with an authenticated account.
