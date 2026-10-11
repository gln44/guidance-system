<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

// If no accounts exist yet, send the first visitor to setup
$hasAccounts = (int) db()->query('SELECT COUNT(*) FROM counselors')->fetchColumn() > 0;
if (!$hasAccounts) {
    redirect('setup.php');
}

$error    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare('SELECT * FROM counselors WHERE username = ?');
    $stmt->execute([$username]);
    $account = $stmt->fetch();

    if ($account && $account['is_active'] && password_verify($password, $account['password_hash'])) {
        session_regenerate_id(true); // prevents session fixation
        $_SESSION['user'] = [
            'id'        => (int) $account['id'],
            'full_name' => $account['full_name'],
            'username'  => $account['username'],
            'role'      => $account['role'],
        ];
        $_SESSION['last_activity'] = time();
        unset($_SESSION['csrf_token']);

        db()->prepare('UPDATE counselors SET last_login = NOW() WHERE id = ?')->execute([$account['id']]);
        redirect('dashboard.php');
    }

    sleep(1); // slows down password guessing
    $error = ($account && !$account['is_active'])
        ? 'This account is deactivated. Contact the guidance office administrator.'
        : 'The username or password is incorrect.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in | <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:ital,wght@0,400;0,700;1,400&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&display=swap" rel="stylesheet">
    <link rel="icon" href="<?= e(LOGO_PATH) ?>">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <div class="folder-tab">Counselors only</div>
        <div class="auth-body">
            <img class="logo" src="<?= e(LOGO_PATH) ?>" alt="">
            <h1><?= e(APP_NAME) ?></h1>
            <p class="muted"><?= e(SCHOOL_NAME) ?></p>

            <?php foreach (get_flashes() as $f): ?>
                <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
            <?php endforeach; ?>
            <?php if ($error): ?>
                <div class="flash flash-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="stack" autocomplete="on">
                <?= csrf_field() ?>
                <label>
                    Username
                    <input type="text" name="username" value="<?= e($username) ?>" required autofocus autocomplete="username">
                </label>
                <label>
                    Password
                    <input type="password" name="password" required autocomplete="current-password">
                </label>
                <button type="submit" class="btn btn-primary btn-block"><?= icon('log-in') ?>Log in</button>
            </form>
            <p class="fine-print">Student records in this system are confidential. Log out when you leave your desk.</p>
        </div>
    </main>
</body>
</html>
