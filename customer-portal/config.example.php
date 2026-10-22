<?php
// Copy this to /home/YOUR_CPANEL_USER/ankh-customer-portal-config.php
// OUTSIDE any public website directory; chmod 600 and fill secrets there.
// Never commit the populated file or place it in public_html.
return [
  'host' => 'order.ankhpeptides.com',
  'admin_database_path' => '/home/YOUR_CPANEL_USER/ankh-admin-private/orders.sqlite',
  'portal_database_path' => '/home/YOUR_CPANEL_USER/ankh-customer-portal-private/accounts.sqlite',
  'twilio_account_sid' => 'AC_REPLACE_WITH_ACCOUNT_SID',
  'twilio_auth_token' => 'REPLACE_WITH_SECRET',
  'twilio_verify_service_sid' => 'VA_REPLACE_WITH_VERIFY_SERVICE_SID',
];
