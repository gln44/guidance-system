<?php
require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $_SESSION = [];
    session_destroy();
    session_start();
    flash('success', 'You are logged out.');
}
redirect('login.php');
