<?php
// Vercel entry point. Vercel only runs PHP files inside api/, so every page
// request comes here (see vercel.json) and is handed to the matching page in
// the main folder. On XAMPP or a normal web host this file is never used.

$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$page = ltrim((string) $path, '/');
if ($page === '') {
    $page = 'index.php';
}

// Only the pages in the main folder can be opened, never files in config/, includes/ or database/
if (!preg_match('/^[a-z_]+\.php$/', $page) || !is_file($root . '/' . $page)) {
    http_response_code(404);
    echo '<p style="font-family:sans-serif;padding:2rem">Page not found. <a href="/">Go to the start page</a></p>';
    exit;
}

// Make the page see itself as if it had been opened directly
$_SERVER['SCRIPT_NAME']     = '/' . $page;
$_SERVER['PHP_SELF']        = '/' . $page;
$_SERVER['SCRIPT_FILENAME'] = $root . '/' . $page;
chdir($root);

require $root . '/' . $page;
