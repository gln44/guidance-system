<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$session = [
    'student_id'      => (int) ($_GET['student_id'] ?? 0),
    'session_date'    => date('Y-m-d'),
    'session_time'    => date('H:i'),
    'session_type'    => '',
    'referral_source' => 'Walk-in',
    'concern'         => '',
    'notes'           => '',
    'action_taken'    => '',
    'recommendations' => '',
    'status'          => 'Open',
    'follow_up_date'  => '',
];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM counseling_sessions WHERE id = ?');
    $stmt->execute([$id]);
    $session = $stmt->fetch();
    if (!$session) {
        flash('error', 'That session does not exist.');
        redirect('sessions.php');
    }
    if (!can_modify_session($session)) {
        flash('error', 'Only the counselor who recorded this session, or an administrator, can edit it.');
        redirect('session_view.php?id=' . $id);
    }
    $session['session_time'] = $session['session_time'] ? substr($session['session_time'], 0, 5) : '';
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $text = ['session_date', 'session_time', 'session_type', 'referral_source', 'concern',
             'notes', 'action_taken', 'recommendations', 'status', 'follow_up_date'];
    foreach ($text as $f) {
        $session[$f] = trim($_POST[$f] ?? '');
    }
    $session['student_id'] = (int) ($_POST['student_id'] ?? 0);

    $stmt = db()->prepare('SELECT id FROM students WHERE id = ?');
    $stmt->execute([$session['student_id']]);
    if (!$stmt->fetch()) $errors[] = 'Choose the student this session is for.';

    if (!valid_date($session['session_date'])) $errors[] = 'Enter the date of the session.';
    if ($session['session_time'] !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $session['session_time'])) $errors[] = 'Enter a valid time, or leave it blank.';
    if (!in_array($session['session_type'], SESSION_TYPES, true)) $errors[] = 'Choose the type of session.';
    if (!in_array($session['referral_source'], REFERRAL_SOURCES, true)) $errors[] = 'Choose how the student came to the office.';
    if ($session['concern'] === '') $errors[] = 'Describe the concern the student brought.';
    if (!in_array($session['status'], SESSION_STATUSES, true)) $errors[] = 'Choose a status.';

    if ($session['status'] === 'Follow-up') {
        if (!valid_date($session['follow_up_date'])) {
            $errors[] = 'Set a follow-up date, or change the status.';
        } elseif (valid_date($session['session_date']) && $session['follow_up_date'] < $session['session_date']) {
            $errors[] = 'The follow-up date must be on or after the session date.';
        }
    } else {
        $session['follow_up_date'] = ''; // only kept for follow-up sessions
    }

    if (!$errors) {
        $nullable = fn($v) => $v === '' ? null : $v;
        $values = [
            $session['student_id'],
            $session['session_date'],
            $nullable($session['session_time']),
            $session['session_type'],
            $session['referral_source'],
            $session['concern'],
            $nullable($session['notes']),
            $nullable($session['action_taken']),
            $nullable($session['recommendations']),
            $session['status'],
            $nullable($session['follow_up_date']),
        ];

        if ($id) {
            $values[] = $id;
            db()->prepare('
                UPDATE counseling_sessions SET
                    student_id = ?, session_date = ?, session_time = ?, session_type = ?, referral_source = ?,
                    concern = ?, notes = ?, action_taken = ?, recommendations = ?, status = ?, follow_up_date = ?
                WHERE id = ?
            ')->execute($values);
            flash('success', 'Session updated.');
        } else {
            $values[] = current_user()['id'];
            db()->prepare('
                INSERT INTO counseling_sessions
                    (student_id, session_date, session_time, session_type, referral_source,
                     concern, notes, action_taken, recommendations, status, follow_up_date, counselor_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute($values);
            $id = (int) db()->lastInsertId();
            flash('success', 'Session recorded.');
        }
        redirect('session_view.php?id=' . $id);
    }
}

$students = db()->query('SELECT id, student_no, first_name, last_name, middle_name FROM students ORDER BY last_name, first_name')->fetchAll();

$pageTitle = $id ? 'Edit session' : 'Record a session';
$activeNav = 'sessions';
require __DIR__ . '/includes/header.php';
?>

<header class="page-head">
    <a class="back" href="<?= $id ? 'session_view.php?id=' . $id : 'sessions.php' ?>"><?= icon('arrow-left') ?>Back</a>
    <h1><?= e($pageTitle) ?></h1>
</header>

<?php if (!$students): ?>
    <section class="panel">
        <p class="empty">Add the student’s record first, then come back to record the session. <a href="student_form.php">Add student</a></p>
    </section>
<?php else: ?>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="panel form">
    <?= csrf_field() ?>

    <fieldset>
        <legend>Who and when</legend>
        <div class="fields">
            <div class="span-2 student-picker">
                <label>Find student
                    <input type="search" id="student-filter" placeholder="Type a name or student number to narrow the list" autocomplete="off">
                </label>
                <label>Student <span class="req">required</span>
                    <select name="student_id" id="student-select" required>
                        <option value="">Choose a student</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= selected($session['student_id'], $s['id']) ?>>
                                <?= e(student_name($s)) ?> (<?= e($s['student_no']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <a class="small" href="student_form.php">Student not listed? Add them first.</a>
            </div>
            <label>Date <span class="req">required</span>
                <input type="date" name="session_date" value="<?= e($session['session_date']) ?>" required>
            </label>
            <label>Time
                <input type="time" name="session_time" value="<?= e($session['session_time']) ?>">
            </label>
            <label>Type of session <span class="req">required</span>
                <select name="session_type" required>
                    <option value="">Choose a type</option>
                    <?php foreach (SESSION_TYPES as $opt): ?>
                        <option <?= selected($session['session_type'], $opt) ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>How the student came in <span class="req">required</span>
                <select name="referral_source" required>
                    <?php foreach (REFERRAL_SOURCES as $opt): ?>
                        <option <?= selected($session['referral_source'], $opt) ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </fieldset>

    <fieldset>
        <legend>Session record</legend>
        <div class="fields one-col">
            <label>Concern <span class="req">required</span>
                <span class="hint">What the student came in for, in their words or the referrer’s.</span>
                <textarea name="concern" rows="3" required><?= e($session['concern']) ?></textarea>
            </label>
            <label>Session notes
                <span class="hint">What was discussed and observed.</span>
                <textarea name="notes" rows="7" class="notes-input"><?= e($session['notes']) ?></textarea>
            </label>
            <label>Action taken
                <span class="hint">Interventions, techniques used, or referrals made during the session.</span>
                <textarea name="action_taken" rows="3"><?= e($session['action_taken']) ?></textarea>
            </label>
            <label>Recommendations
                <span class="hint">Next steps for the student, family, or teachers.</span>
                <textarea name="recommendations" rows="3"><?= e($session['recommendations']) ?></textarea>
            </label>
        </div>
    </fieldset>

    <fieldset>
        <legend>Status</legend>
        <div class="status-choices" role="radiogroup">
            <?php foreach (SESSION_STATUSES as $opt): ?>
                <label class="status-choice">
                    <input type="radio" name="status" value="<?= e($opt) ?>" <?= $session['status'] === $opt ? 'checked' : '' ?>>
                    <?= status_badge($opt) ?>
                    <span class="muted small">
                        <?= $opt === 'Open' ? 'Still being worked on' : ($opt === 'Follow-up' ? 'Needs another session on a set date' : 'Concern resolved') ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <label class="follow-up-field" id="follow-up-field">Follow-up date
            <input type="date" name="follow_up_date" value="<?= e($session['follow_up_date']) ?>">
        </label>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= icon('save') ?><?= $id ? 'Save changes' : 'Save session' ?></button>
        <a class="btn btn-quiet" href="<?= $id ? 'session_view.php?id=' . $id : 'sessions.php' ?>">Cancel</a>
    </div>
</form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
