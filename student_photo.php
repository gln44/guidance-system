<?php
// Shows a student's photo, only to logged-in counselors.
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$stmt = db()->prepare('SELECT photo FROM students WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$photo = (string) $stmt->fetchColumn();
$path = $photo !== '' ? photo_dir() . '/' . basename($photo) : '';

if ($path === '' || !is_file($path)) {
    http_response_code(404);
    exit;
}

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400'); // the browser may keep it, shared caches may not
readfile($path);
