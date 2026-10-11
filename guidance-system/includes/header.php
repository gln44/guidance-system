<?php
// Expects $pageTitle (string) and $activeNav (string) to be set by the page.
$user = current_user();
$nav = [
    'dashboard' => ['Dashboard', 'dashboard.php'],
    'students'  => ['Students', 'students.php'],
    'sessions'  => ['Sessions', 'sessions.php'],
];
if (is_admin()) {
    $nav['counselors'] = ['Counselors', 'counselors.php'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Guidance') ?> | <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:ital,wght@0,400;0,700;1,400&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&display=swap" rel="stylesheet">
    <link rel="icon" href="<?= e(LOGO_PATH) ?>">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <a class="brand" href="dashboard.php">
            <img class="logo" src="<?= e(LOGO_PATH) ?>" alt="">
            <span>
                <strong><?= e(APP_NAME) ?></strong>
                <small><?= e(SCHOOL_NAME) ?></small>
            </span>
        </a>

        <a class="btn btn-new" href="session_form.php"><?= icon('plus') ?>Record a session</a>

        <nav class="nav" aria-label="Main">
            <?php foreach ($nav as $key => [$label, $href]): ?>
                <a href="<?= e($href) ?>" <?= ($activeNav ?? '') === $key ? 'aria-current="page"' : '' ?>><?= icon($key) ?><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="account">
            <p>
                <strong><?= e($user['full_name']) ?></strong>
                <small><?= $user['role'] === 'admin' ? 'Administrator' : 'Counselor' ?></small>
            </p>
            <a href="change_password.php" <?= ($activeNav ?? '') === 'password' ? 'aria-current="page"' : '' ?>><?= icon('key') ?>Change password</a>
            <form method="post" action="logout.php">
                <?= csrf_field() ?>
                <button type="submit" class="link-button"><?= icon('log-out') ?>Log out</button>
            </form>
        </div>
    </aside>

    <main class="main" id="main">
        <?php foreach (get_flashes() as $f): ?>
            <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
