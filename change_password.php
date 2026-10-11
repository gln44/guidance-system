<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current = $_POST['current'] ?? '';
    $new     = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    $stmt = db()->prepare('SELECT password_hash FROM counselors WHERE id = ?');
    $stmt->execute([current_user()['id']]);
    $hash = $stmt->fetchColumn();

    if (!password_verify($current, $hash)) $errors[] = 'Your current password is incorrect.';
    if (strlen($new) < 8) $errors[] = 'Your new password must be at least 8 characters.';
    if ($new !== $confirm) $errors[] = 'The new passwords do not match.';

    if (!$errors) {
        db()->prepare('UPDATE counselors SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), current_user()['id']]);
        session_regenerate_id(true);
        flash('success', 'Password changed.');
        redirect('dashboard.php');
    }
}

$pageTitle = 'Change password';
$activeNav = 'password';
require __DIR__ . '/includes/header.php';
?>

<header class="page-head">
    <h1>Change password</h1>
</header>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="panel form narrow">
    <?= csrf_field() ?>
    <div class="fields one-col">
        <label>Current password
            <input type="password" name="current" required autocomplete="current-password">
        </label>
        <label>New password
            <input type="password" name="new" minlength="8" required autocomplete="new-password">
            <span class="hint">At least 8 characters.</span>
        </label>
        <label>Confirm new password
            <input type="password" name="confirm" minlength="8" required autocomplete="new-password">
        </label>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= icon('key') ?>Change password</button>
        <a class="btn btn-quiet" href="dashboard.php">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
