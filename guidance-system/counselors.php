<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$errors = [];
$new = ['full_name' => '', 'username' => '', 'role' => 'counselor'];
$me  = current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action    = $_POST['action'] ?? '';
    $accountId = (int) ($_POST['id'] ?? 0);

    if ($action === 'add') {
        $new['full_name'] = trim($_POST['full_name'] ?? '');
        $new['username']  = trim($_POST['username'] ?? '');
        $new['role']      = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'counselor';
        $password = $_POST['password'] ?? '';

        if ($new['full_name'] === '') $errors[] = 'Enter the counselor’s full name.';
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $new['username'])) $errors[] = 'Username must be 3–50 letters, numbers, dots, dashes, or underscores.';
        if (strlen($password) < 8) $errors[] = 'The temporary password must be at least 8 characters.';

        $stmt = db()->prepare('SELECT id FROM counselors WHERE username = ?');
        $stmt->execute([$new['username']]);
        if ($stmt->fetch()) $errors[] = 'The username “' . $new['username'] . '” is already taken.';

        if (!$errors) {
            db()->prepare('INSERT INTO counselors (full_name, username, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$new['full_name'], $new['username'], password_hash($password, PASSWORD_DEFAULT), $new['role']]);
            flash('success', 'Account created for ' . $new['full_name'] . '. Give them the username and temporary password, and ask them to change it after logging in.');
            redirect('counselors.php');
        }
    } elseif ($action === 'toggle' && $accountId) {
        if ($accountId === $me) {
            flash('error', 'You cannot deactivate your own account.');
        } else {
            db()->prepare('UPDATE counselors SET is_active = 1 - is_active WHERE id = ?')->execute([$accountId]);
            flash('success', 'Account status changed.');
        }
        redirect('counselors.php');
    } elseif ($action === 'reset' && $accountId) {
        $password = $_POST['password'] ?? '';
        if (strlen($password) < 8) {
            flash('error', 'The new password must be at least 8 characters.');
        } else {
            db()->prepare('UPDATE counselors SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $accountId]);
            flash('success', 'Password reset. Give the new password to the counselor.');
        }
        redirect('counselors.php');
    }
}

$accounts = db()->query('
    SELECT c.*, (SELECT COUNT(*) FROM counseling_sessions cs WHERE cs.counselor_id = c.id) AS session_count
    FROM counselors c
    ORDER BY c.is_active DESC, c.full_name
')->fetchAll();

$pageTitle = 'Counselor accounts';
$activeNav = 'counselors';
require __DIR__ . '/includes/header.php';
?>

<header class="page-head">
    <h1>Counselor accounts</h1>
    <p class="muted">Only people with an active account here can log in. Deactivate an account when a counselor leaves; their session records stay on file.</p>
</header>

<section class="panel">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Sessions</th><th>Last login</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $a): ?>
                <tr class="<?= $a['is_active'] ? '' : 'inactive' ?>">
                    <td><?= e($a['full_name']) ?><?= (int) $a['id'] === $me ? ' <span class="muted small">(you)</span>' : '' ?></td>
                    <td><?= e($a['username']) ?></td>
                    <td><?= $a['role'] === 'admin' ? 'Administrator' : 'Counselor' ?></td>
                    <td><?= (int) $a['session_count'] ?></td>
                    <td class="nowrap"><?= $a['last_login'] ? e(date('M j, Y g:i A', strtotime($a['last_login']))) : '<span class="muted">Never</span>' ?></td>
                    <td><?= $a['is_active'] ? 'Active' : 'Deactivated' ?></td>
                    <td class="actions">
                        <?php if ((int) $a['id'] !== $me): ?>
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" class="link-button"><?= icon($a['is_active'] ? 'user-x' : 'user-check') ?><?= $a['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                            </form>
                        <?php endif; ?>
                        <details class="reset">
                            <summary><?= icon('key') ?>Reset password</summary>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset">
                                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <label><span class="sr-only">New password for <?= e($a['full_name']) ?></span>
                                    <input type="password" name="password" minlength="8" required placeholder="New password" autocomplete="new-password">
                                </label>
                                <button type="submit" class="btn btn-small"><?= icon('check') ?>Set password</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel form">
    <div class="panel-head"><h2>Add a counselor</h2></div>
    <?php foreach ($errors as $err): ?>
        <div class="flash flash-error" role="alert"><?= e($err) ?></div>
    <?php endforeach; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="fields">
            <label>Full name
                <input type="text" name="full_name" value="<?= e($new['full_name']) ?>" required>
            </label>
            <label>Username
                <input type="text" name="username" value="<?= e($new['username']) ?>" required autocomplete="off">
            </label>
            <label>Temporary password <small class="muted">at least 8 characters</small>
                <input type="password" name="password" minlength="8" required autocomplete="new-password">
            </label>
            <label>Role
                <select name="role">
                    <option value="counselor" <?= selected($new['role'], 'counselor') ?>>Counselor</option>
                    <option value="admin" <?= selected($new['role'], 'admin') ?>>Administrator (can manage accounts)</option>
                </select>
            </label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('user-plus') ?>Create account</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
