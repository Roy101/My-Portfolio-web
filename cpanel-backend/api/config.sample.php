<?php
// 1. Copy this file to  config.php  (same folder)
// 2. Fill in the MySQL details you created in cPanel (MySQL Databases wizard).
// config.php is git-ignored and blocked from the web by .htaccess.
return [
    'db_host'        => 'localhost',
    'db_name'        => 'YOUR_CPANEL_DBNAME',   // e.g. palashr5_portfolio
    'db_user'        => 'YOUR_CPANEL_DBUSER',   // e.g. palashr5_admin
    'db_pass'        => 'YOUR_DB_PASSWORD',
    // The exact origin of your website (used for the read API CORS header).
    'allowed_origin' => 'https://palashroy.me',
];
