<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("
    SELECT cs.*, s.student_no, s.first_name, s.last_name, s.middle_name, s.sex, s.birthdate, s.photo,
           s.course, s.year_level, s.section, s.contact_no, s.email, s.address, s.guardian_name, s.guardian_contact,
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

// "Back" returns to the list the session was opened from (Sessions by default),
// and the menu stays on that tab.
$back = (string) ($_GET['back'] ?? '');
if (!preg_match('/^(sessions|student_view|dashboard)\.php(\?[^\r\n]*)?$/', $back)) {
    $back = 'sessions.php';
}
if (strpos($back, 'student_view.php') === 0) {
    [$backLabel, $activeNav] = ['Student’s history', 'students'];
} elseif (strpos($back, 'dashboard.php') === 0) {
    [$backLabel, $activeNav] = ['Dashboard', 'dashboard'];
} else {
    [$backLabel, $activeNav] = ['Sessions', 'sessions'];
}

// The student's personal details, shown with the session
$age = $session['birthdate'] ? (new DateTime($session['birthdate']))->diff(new DateTime('today'))->y : null;
$personal = [
    'Student no.'       => $session['student_no'],
    'Grade / strand'    => grade_strand($session),
    'Section'           => $session['section'],
    'Sex'               => $session['sex'],
    'Birthdate'         => $session['birthdate'] ? format_date($session['birthdate']) . " (age $age)" : '',
    'Contact number'    => $session['contact_no'],
    'Email'             => $session['email'],
    'Address'           => $session['address'],
    'Parent / guardian' => trim($session['guardian_name'] . ($session['guardian_contact'] ? ', ' . $session['guardian_contact'] : '')),
];

$stmt = db()->prepare('SELECT COUNT(*) FROM counseling_sessions WHERE student_id = ? AND id <> ?');
$stmt->execute([(int) $session['student_id'], $id]);
$otherSessions = (int) $stmt->fetchColumn();

$sections = [
    'Concern'         => $session['concern'],
    'Session notes'   => $session['notes'],
    'Action taken'    => $session['action_taken'],
    'Recommendations' => $session['recommendations'],
];

$pageTitle = 'Session with ' . student_name($session, false);
require __DIR__ . '/includes/header.php';
?>

<header class="page-head row no-print">
    <a class="back" href="<?= e($back) ?>"><?= icon('arrow-left') ?><?= e($backLabel) ?></a>
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
            <span class="print-mark">Confidential</span>
        </div>
        <p class="print-title print-only">Counseling Session Record</p>
        <p class="confidential no-print">Confidential counseling record.</p>

        <div class="folder-title row">
            <div>
                <h1><?= e(student_name($session, false)) ?></h1>
                <p class="muted"><?= e($session['student_no']) ?><?= grade_strand($session) ? ', ' . e(grade_strand($session)) : '' ?><?= $session['section'] ? ', Section ' . e($session['section']) : '' ?></p>
            </div>
            <?= status_badge($session['status']) ?>
        </div>

        <section class="record-block">
            <h2 class="block-title">Personal details</h2>
            <div class="details-with-photo">
            <?= student_photo($session, 'photo-md') ?>
            <dl class="details">
                <?php foreach ($personal as $label => $value): ?>
                    <div<?= in_array($label, ['Email', 'Address', 'Parent / guardian'], true) ? ' class="wide"' : '' ?>>
                        <dt><?= e($label) ?></dt>
                        <dd><?= $value !== '' && $value !== null ? e($value) : '<span class="muted">Not recorded</span>' ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
            </div>
        </section>

        <section class="record-block">
            <h2 class="block-title">Session details</h2>
            <dl class="details">
                <div><dt>Date</dt><dd><?= e(format_date($session['session_date'])) ?> <?= e(format_time($session['session_time'])) ?></dd></div>
                <div><dt>Type of session</dt><dd><?= e($session['session_type']) ?></dd></div>
                <div><dt>Counselor</dt><dd><?= e($session['counselor']) ?></dd></div>
                <div><dt>How the student came in</dt><dd><?= e($session['referral_source']) ?></dd></div>
                <div class="wide"><dt>Status</dt><dd><?= status_text($session['status'], $session['status'] === 'Follow-up' ? $session['follow_up_date'] : null) ?></dd></div>
            </dl>
        </section>

        <div class="record-notes">
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

        <div class="print-only">
            <?= signatories('Counseled by', $session['counselor'], 'session_record') ?>
        </div>
    </div>
</article>

<p class="other-sessions no-print">
    <?php if ($otherSessions > 0): ?>
        <?= e($session['first_name']) ?> has <?= $otherSessions ?> other session<?= $otherSessions === 1 ? '' : 's' ?> on file.
    <?php endif; ?>
    <a href="student_view.php?id=<?= (int) $session['student_id'] ?>">Open <?= e($session['first_name']) ?>’s student page</a>
</p>

<?php if ($canModify): ?>
    <form method="post" action="session_delete.php" class="danger-zone no-print" data-confirm="Delete this session record? This cannot be undone.">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-danger"><?= icon('trash') ?>Delete session</button>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
