<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM students WHERE id = ?');
$stmt->execute([$id]);
$student = $stmt->fetch();
if (!$student) {
    flash('error', 'That student record does not exist.');
    redirect('students.php');
}

$stmt = db()->prepare("
    SELECT cs.*, c.full_name AS counselor
    FROM counseling_sessions cs
    JOIN counselors c ON c.id = cs.counselor_id
    WHERE cs.student_id = ?
    ORDER BY cs.session_date DESC, cs.session_time DESC, cs.id DESC
");
$stmt->execute([$id]);
$sessions = $stmt->fetchAll();

$age = '';
if ($student['birthdate']) {
    $age = (new DateTime($student['birthdate']))->diff(new DateTime('today'))->y;
}

$details = [
    'Student no.'     => $student['student_no'],
    'Grade / strand'  => grade_strand($student),
    'Section'         => $student['section'],
    'Sex'             => $student['sex'],
    'Birthdate'       => $student['birthdate'] ? format_date($student['birthdate']) . " (age $age)" : '',
    'Contact number'  => $student['contact_no'],
    'Email'           => $student['email'],
    'Address'         => $student['address'],
    'Parent / guardian' => trim($student['guardian_name'] . ($student['guardian_contact'] ? ', ' . $student['guardian_contact'] : '')),
];

$pageTitle = student_name($student, false);
$activeNav = 'students';
require __DIR__ . '/includes/header.php';
?>

<header class="page-head">
    <a class="back" href="students.php"><?= icon('arrow-left') ?>All students</a>
</header>

<article class="folder">
    <div class="folder-tab"><?= e($student['student_no']) ?></div>
    <div class="folder-body">
        <div class="folder-title row">
            <div>
                <h1><?= e(student_name($student, false)) ?></h1>
                <p class="muted"><?= count($sessions) ?> counseling session<?= count($sessions) === 1 ? '' : 's' ?> on file</p>
            </div>
            <div class="button-row">
                <a class="btn btn-primary" href="session_form.php?student_id=<?= $id ?>"><?= icon('plus') ?>Record session</a>
                <a class="btn" href="student_form.php?id=<?= $id ?>"><?= icon('edit') ?>Edit details</a>
            </div>
        </div>

        <dl class="details">
            <?php foreach ($details as $label => $value): ?>
                <div>
                    <dt><?= e($label) ?></dt>
                    <dd><?= $value !== '' && $value !== null ? e($value) : '<span class="muted">Not recorded</span>' ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
</article>

<section class="panel">
    <div class="panel-head"><h2>Counseling history</h2></div>
    <?php if (!$sessions): ?>
        <p class="empty">No sessions recorded for this student. <a href="session_form.php?student_id=<?= $id ?>">Record the first session</a></p>
    <?php else: ?>
        <ol class="timeline">
            <?php foreach ($sessions as $s): ?>
                <li>
                    <div class="timeline-date">
                        <strong><?= e(format_date($s['session_date'])) ?></strong>
                        <span class="muted"><?= e(format_time($s['session_time'])) ?></span>
                    </div>
                    <a class="timeline-card" href="session_view.php?id=<?= (int) $s['id'] ?>">
                        <span class="row">
                            <strong><?= e($s['session_type']) ?></strong>
                            <?= status_badge($s['status']) ?>
                        </span>
                        <span class="note-excerpt"><?= e(excerpt($s['concern'], 160)) ?></span>
                        <span class="muted small">Counselor: <?= e($s['counselor']) ?>. Source: <?= e($s['referral_source']) ?>.</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<?php if (is_admin() && !$sessions): ?>
    <form method="post" action="student_delete.php" class="danger-zone" data-confirm="Delete this student record? This cannot be undone.">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-danger"><?= icon('trash') ?>Delete student record</button>
        <span class="muted small">Only students with no sessions on file can be deleted.</span>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
