<?php
// Remembers whether this browser wants the mobile or desktop layout, then goes back.
require_once __DIR__ . '/includes/bootstrap.php';

$mode = $_GET['mode'] ?? 'auto';
if (!in_array($mode, ['auto', 'desktop', 'mobile'], true)) {
    $mode = 'auto';
}
setcookie('view_mode', $mode, [
    'expires'  => time() + 365 * 24 * 60 * 60,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Only go back to a page of this system (never to another website)
$back = (string) ($_GET['back'] ?? 'index.php');
if (!preg_match('/^[A-Za-z0-9_-]+\.php(\?[^\r\n]*)?$/', $back)) {
    $back = 'index.php';
}
redirect($back);
