<?php
// ------------------------------------------------------------
// Guidance Counseling System - EXAMPLE configuration
//
// To set up: make a copy of this file in the same folder,
// name the copy config.php, and edit the values in it.
// (config.php is not uploaded to GitHub, so passwords stay private.)
// ------------------------------------------------------------

// Database (XAMPP default is user "root" with an empty password)
// On Vercel there is no config.php, so these come from the project's
// Environment Variables instead; the second value is used when one isn't set.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'guidance_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
// Online databases such as TiDB Cloud and Aiven only accept encrypted connections
define('DB_SSL', filter_var(getenv('DB_SSL'), FILTER_VALIDATE_BOOLEAN));

// Name shown in the sidebar and browser tab
define('APP_NAME', 'Guidance Office');
define('SCHOOL_NAME', getenv('SCHOOL_NAME') ?: 'Your School Name');

// Logo shown in the sidebar, login page, browser tab, and printed records.
// To use your school's logo: put the image (PNG, JPG, or SVG) in assets/img/
// and change this to its file name, e.g. 'assets/img/school-logo.png'
define('LOGO_PATH', getenv('LOGO_PATH') ?: 'assets/img/logo.svg');

// Your local timezone, so "today" and session dates are correct.
// List of values: https://www.php.net/manual/en/timezones.php
define('APP_TIMEZONE', getenv('APP_TIMEZONE') ?: 'UTC');

// Log a counselor out automatically after this many seconds of inactivity
define('SESSION_TIMEOUT', 30 * 60);

// Choices used in the session form
define('SESSION_TYPES', [
    'Academic',
    'Career',
    'Personal / Social',
    'Behavioral',
    'Family',
    'Crisis',
    'Other',
]);

define('REFERRAL_SOURCES', [
    'Walk-in',
    'Teacher referral',
    'Parent / Guardian',
    'Peer referral',
    'Administration',
    'Scheduled follow-up',
]);

define('SESSION_STATUSES', ['Open', 'Follow-up', 'Closed']);

define('YEAR_LEVELS', ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12']);

date_default_timezone_set(APP_TIMEZONE);
