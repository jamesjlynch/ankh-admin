# ANKH Operations Upgrade — Controlled Rollout

The original SQLite database remains the source of truth. Existing orders, customers and payments are not overwritten. Two additive tables are created: operator_accounts and activity_events.

## Safety gate before publishing
1. Verify GitHub Actions PHP lint and feature tests are green.
2. Confirm the EXISTING administrator PIN/password works. The earlier app used a public authentication bypass. The patched app allows test bypass only for PHP CLI fixtures, never through a browser. Without a valid admin credential, deploying would lock staff out.
3. Run a private, integrity-verified SQLite snapshot using scripts/backup-sqlite.php before deploying.
4. Snapshot the existing live web assets to the non-public home-directory release folder. No private config/database file is copied with web assets.

## Jay/Tony PIN onboarding — release not yet approved
Until both personal PINs have been chosen, the operations upgrade remains in its feature branch and the existing live test setup is unchanged. Do not expose the future PIN setup screen to unauthenticated public visitors or publish the upgrade before verifying secure administrator access.
1. Sign in with the existing administrator PIN/password.
2. Open More → Set up Jay and Tony accounts.
3. Enter the existing administrator credential. Jay and Tony each choose and confirm a different six-digit PIN in the app. Never share the PINs in GitHub or chat.
4. Both accounts are created in one database transaction. Activation signs the current session into Jay.
5. Tony signs in on his own device. The former shared PIN and shared passkeys cease to authenticate named users. Individual passkeys require a future account-bound implementation.
6. Change your own PIN through More. Lost PINs can be recovered from an authorised private server terminal; NEVER re-enable public test mode.

## Order flow
The six original dashboard metrics remain. The worklist adds a compact status summary, next action hints, and button forms using existing server-validated order_preparation and delivery_mark transitions with CSRF checks. Mark delivered today always requires an explicit confirmation. Scheduled future deliveries do NOT automatically become delivered or leave the delivery queue.

## Activity
More → Order & payment activity lists recent order changes, payment entries/deletions, preparation changes and delivery dates, with actor and timestamp. Historical activity cannot be retroactively attributed. Before activation, events are attributed to Legacy admin. No customer contact details or secrets are stored in activity event metadata.

## Daily backups
The private backup program uses SQLite VACUUM INTO (consistent snapshot), PRAGMA integrity_check and key table count validation. Backup files are 0600, in a private directory outside public_html. It keeps the latest 30 verified snapshots.

After a FIRST successful manual run, set a daily cPanel cron, for example:

    15 3 * * * /opt/cpanel/ea-php82/root/usr/bin/php /home/lynchde1/ankh-admin-tools/backup-sqlite.php >> /home/lynchde1/ankh-admin-backup.log 2>&1

Ensure HOME=/home/lynchde1 or ANKH_ADMIN_CONFIG is set correctly in cron. This cron is NOT activated by a GitHub merge alone. Independently copy backups off-host and test an isolated restore at least once a month.

## Rollback
Private release snapshots are saved before website code is replaced. Run the private rollback-release.sh with the exact snapshot identifier to restore ONLY PHP/CSS web files, never the live SQLite database. The rollback helper deliberately refuses snapshots that contain the former PUBLIC TEST MODE authentication bypass. An unsafe release must never be restored merely to recover the old interface. Existing database tables remain. Restoring an old DATABASE instead requires a maintenance window and means losing records added after its timestamp.

## Regression matrix
Exercise 390px iPhone, 768px/820px iPad portrait, 1024px/1180px iPad landscape, and 1440px desktop. Check overflow, sidebar, six original metric cards, worklist forms, notifications, customer intelligence, payments, sign-in and sign-out. Check both named accounts independently and test failed credentials.

Production should not be declared live until the Krystal deployment, successful backup and login checks have been independently verified.

## Credential recovery
If the old login credential is unknown after deploying, use the server terminal: php /home/lynchde1/ankh-admin-tools/reset-admin-login.php (before named-account activation) or append Jay / Tony once enabled. The script prompts for a new six-digit PIN privately and never prints it. No public bypass is reintroduced.
