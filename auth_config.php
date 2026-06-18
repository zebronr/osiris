<?php
// auth_config.php — single source of truth for the Osiris API key.
//
// Used by:
//   - index.php          (dashboard login form)
//   - log.php             (via auth_guard.php, to require a session)
//   - analyze_date.php    (API key check for the widget endpoint)
//
// IMPORTANT: replace this with a real random secret before deploying, and
// keep this file OUTSIDE any web-accessible directory if possible, or at
// minimum make sure your webserver config denies direct HTTP access to it
// (e.g. an .htaccess "Deny from all" rule, or an nginx location block).
//
// Generate a strong key once with:
//   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
// and paste the result below.

define('OSIRIS_API_KEY', 'iloveyoubaby123MWUAHMWUAH_tovelylitties');