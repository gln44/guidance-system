<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('sessions.php');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM counseling_sessions WHERE id = ?');
$stmt->execute([$id]);
$session = $stmt->fetch();

if (!$session) {
    flash('error', 'That session does not exist.');
    redirect('sessions.php');
}
if (!can_modify_session($session)) {
    flash('error', 'Only the counselor who recorded this session, or an administrator, can delete it.');
    redirect('session_view.php?id=' . $id);
}

db()->prepare('DELETE FROM counseling_sessions WHERE id = ?')->execute([$id]);
flash('success', 'Session deleted.');
redirect('student_view.php?id=' . (int) $session['student_id']);
