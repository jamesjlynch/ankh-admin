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
