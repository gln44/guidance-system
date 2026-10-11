<?php
// First-run setup: creates the first administrator account.
// It locks itself once any account exists, so it cannot be used again.
require_once __DIR__ . '/includes/bootstrap.php';

$locked = (int) db()->query('SELECT COUNT(*) FROM counselors')->fetchColumn() > 0;
$errors = [];
$input  = ['full_name' => '', 'username' => ''];

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $input['full_name'] = trim($_POST['full_name'] ?? '');
    $input['username']  = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if ($input['full_name'] === '') $errors[] = 'Enter your full name.';
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $input['username'])) $errors[] = 'Username must be 3–50 letters, numbers, dots, dashes, or underscores.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'The two passwords do not match.';

    if (!$errors) {
        $stmt = db()->prepare("INSERT INTO counselors (full_name, username, password_hash, role) VALUES (?, ?, ?, 'admin')");
        $stmt->execute([$input['full_name'], $input['username'], password_hash($password, PASSWORD_DEFAULT)]);
        flash('success', 'Administrator account created. Log in to start.');
        redirect('login.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup | <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(LOGO_PATH) ?>">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <div class="folder-tab">First-time setup</div>
        <div class="auth-body">
            <img class="logo" src="<?= e(LOGO_PATH) ?>" alt="">
            <?php if ($locked): ?>
                <h1>Setup is already done</h1>
                <p class="muted">An account already exists. New counselor accounts are added by an administrator from inside the system.</p>
                <a class="btn btn-primary btn-block" href="login.php"><?= icon('log-in') ?>Go to log in</a>
            <?php else: ?>
                <h1>Create the administrator account</h1>
                <p class="muted">This account can add and deactivate other counselor accounts.</p>

                <?php foreach ($errors as $err): ?>
                    <div class="flash flash-error" role="alert"><?= e($err) ?></div>
                <?php endforeach; ?>

                <form method="post" class="stack">
                    <?= csrf_field() ?>
                    <label>Full name
                        <input type="text" name="full_name" value="<?= e($input['full_name']) ?>" required>
                    </label>
                    <label>Username
                        <input type="text" name="username" value="<?= e($input['username']) ?>" required autocomplete="username">
                    </label>
                    <label>Password <small class="muted">at least 8 characters</small>
                        <input type="password" name="password" required minlength="8" autocomplete="new-password">
                    </label>
                    <label>Confirm password
                        <input type="password" name="confirm" required minlength="8" autocomplete="new-password">
                    </label>
                    <button type="submit" class="btn btn-primary btn-block"><?= icon('user-plus') ?>Create account</button>
                </form>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
