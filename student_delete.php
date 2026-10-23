<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('students.php');
}
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT COUNT(*) FROM counseling_sessions WHERE student_id = ?');
$stmt->execute([$id]);

if ((int) $stmt->fetchColumn() > 0) {
    flash('error', 'This student has sessions on file, so the record cannot be deleted.');
    redirect('student_view.php?id=' . $id);
}

$stmt = db()->prepare('SELECT photo FROM students WHERE id = ?');
$stmt->execute([$id]);
$photo = $stmt->fetchColumn();
db()->prepare('DELETE FROM students WHERE id = ?')->execute([$id]);
delete_student_photo_file($photo ?: null);
flash('success', 'Student record deleted.');
redirect('students.php');
