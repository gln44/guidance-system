<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("
    SELECT cs.*, s.student_no, s.first_name, s.last_name, s.middle_name, s.course, s.year_level, s.section,
           c.full_name AS counselor
    FROM counseling_sessions cs
    JOIN students s   ON s.id = cs.student_id
    JOIN counselors c ON c.id = cs.counselor_id
    WHERE cs.id = ?
");
$stmt->execute([$id]);
$session = $stmt->fetch();
if (!$session) {
    flash('error', 'That session does not exist.');
    redirect('sessions.php');
}

$canModify = can_modify_session($session);

$sections = [
    'Concern'         => $session['concern'],
    'Session notes'   => $session['notes'],
    'Action taken'    => $session['action_taken'],
    'Recommendations' => $session['recommendations'],
];

$pageTitle = 'Session with ' . student_name($session, false);
$activeNav = 'sessions';
require __DIR__ . '/includes/header.php';
?>

<header class="page-head row no-print">
    <a class="back" href="student_view.php?id=<?= (int) $session['student_id'] ?>"><?= icon('arrow-left') ?>Student’s history</a>
    <div class="button-row">
        <button type="button" class="btn" data-print><?= icon('printer') ?>Print</button>
        <?php if ($canModify): ?>
            <a class="btn" href="session_form.php?id=<?= $id ?>"><?= icon('edit') ?>Edit</a>
        <?php endif; ?>
    </div>
</header>

<article class="folder case-note">
    <div class="folder-tab"><?= e($session['session_type']) ?></div>
    <div class="folder-body">
        <div class="print-head">
            <img class="logo" src="<?= e(LOGO_PATH) ?>" alt="">
            <div><strong><?= e(SCHOOL_NAME) ?></strong><br><?= e(APP_NAME) ?></div>
        </div>
        <p class="confidential">Confidential counseling record.</p>

        <div class="folder-title row">
            <div>
                <h1><a href="student_view.php?id=<?= (int) $session['student_id'] ?>"><?= e(student_name($session, false)) ?></a></h1>
                <p class="muted">
                    <?= e($session['student_no']) ?>
                    <?php if ($session['course'] || $session['year_level']): ?>
                        <br><?= e(grade_strand($session)) ?><?= $session['section'] ? ', Section ' . e($session['section']) : '' ?>
                    <?php endif; ?>
                </p>
            </div>
            <?= status_badge($session['status']) ?>
        </div>

        <dl class="details">
            <div><dt>Date</dt><dd><?= e(format_date($session['session_date'])) ?> <?= e(format_time($session['session_time'])) ?></dd></div>
            <div><dt>Counselor</dt><dd><?= e($session['counselor']) ?></dd></div>
            <div><dt>How the student came in</dt><dd><?= e($session['referral_source']) ?></dd></div>
            <?php if ($session['status'] === 'Follow-up'): ?>
                <div><dt>Follow-up on</dt><dd><?= e(format_date($session['follow_up_date'])) ?></dd></div>
            <?php endif; ?>
        </dl>

        <?php foreach ($sections as $label => $body): ?>
            <section class="note-section">
                <h2><?= e($label) ?></h2>
                <?php if (trim((string) $body) === ''): ?>
                    <p class="muted">Nothing recorded.</p>
                <?php else: ?>
                    <div class="note-text"><?= nl2br(e($body)) ?></div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <p class="record-meta muted small">
            Recorded <?= e(date('M j, Y g:i A', strtotime($session['created_at']))) ?>.
            <?php if ($session['updated_at'] !== $session['created_at']): ?>
                Last edited <?= e(date('M j, Y g:i A', strtotime($session['updated_at']))) ?>.
            <?php endif; ?>
        </p>
    </div>
</article>

<?php if ($canModify): ?>
    <form method="post" action="session_delete.php" class="danger-zone no-print" data-confirm="Delete this session record? This cannot be undone.">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-danger"><?= icon('trash') ?>Delete session</button>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
