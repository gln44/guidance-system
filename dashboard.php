<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo   = db();
$today = date('Y-m-d');
$week  = date('Y-m-d', strtotime('+7 days'));
$monthStart = date('Y-m-01');

$openCount = (int) $pdo->query("SELECT COUNT(*) FROM counseling_sessions WHERE status = 'Open'")->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM counseling_sessions WHERE status = 'Follow-up' AND follow_up_date <= ?");
$stmt->execute([$week]);
$dueCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM counseling_sessions WHERE session_date >= ?');
$stmt->execute([$monthStart]);
$monthCount = (int) $stmt->fetchColumn();

$studentCount = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();

// Follow-ups that need attention (overdue first, then soonest)
$stmt = $pdo->prepare("
    SELECT cs.id, cs.follow_up_date, cs.session_type, cs.concern,
           s.id AS student_id, s.first_name, s.last_name, s.middle_name, c.full_name AS counselor
    FROM counseling_sessions cs
    JOIN students s   ON s.id = cs.student_id
    JOIN counselors c ON c.id = cs.counselor_id
    WHERE cs.status = 'Follow-up'
    ORDER BY cs.follow_up_date IS NULL, cs.follow_up_date
    LIMIT 8
");
$stmt->execute();
$followUps = $stmt->fetchAll();

$recent = $pdo->query("
    SELECT cs.id, cs.session_date, cs.session_type, cs.status, cs.concern,
           s.first_name, s.last_name, s.middle_name, c.full_name AS counselor
    FROM counseling_sessions cs
    JOIN students s   ON s.id = cs.student_id
    JOIN counselors c ON c.id = cs.counselor_id
    ORDER BY cs.session_date DESC, cs.session_time DESC, cs.id DESC
    LIMIT 8
")->fetchAll();

// Concerns recorded this month, by session type
$stmt = $pdo->prepare('SELECT session_type, COUNT(*) AS total FROM counseling_sessions WHERE session_date >= ? GROUP BY session_type ORDER BY total DESC');
$stmt->execute([$monthStart]);
$byType = $stmt->fetchAll();
$maxType = $byType ? max(array_column($byType, 'total')) : 1;

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', current_user()['full_name'])[0];

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<header class="page-head">
    <h1><?= e($greeting) ?>, <?= e($firstName) ?></h1>
    <p class="summary">
        <?php if ($dueCount === 0 && $openCount === 0): ?>
            No follow-ups are due this week and there are no open cases.
        <?php else: ?>
            <?= $dueCount ?> follow-up<?= $dueCount === 1 ? ' is' : 's are' ?> due within the week,
            and <?= $openCount ?> case<?= $openCount === 1 ? ' is' : 's are' ?> still open.
        <?php endif; ?>
    </p>
    <p class="tally">
        <span><strong><?= $monthCount ?></strong> sessions in <?= e(date('F')) ?></span>
        <span><strong><?= $studentCount ?></strong> student records</span>
    </p>
</header>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head">
            <h2>Follow-ups</h2>
            <a href="sessions.php?status=Follow-up">See all</a>
        </div>
        <?php if (!$followUps): ?>
            <p class="empty">Sessions marked “Follow-up” will show here with their due dates.</p>
        <?php else: ?>
            <ul class="due-list">
                <?php foreach ($followUps as $f):
                    $overdue = $f['follow_up_date'] && $f['follow_up_date'] < $today;
                    $isToday = $f['follow_up_date'] === $today; ?>
                    <li class="<?= $overdue ? 'overdue' : '' ?>">
                        <a href="session_view.php?id=<?= (int) $f['id'] ?>&amp;back=dashboard.php">
                            <span class="due-date">
                                <?php if (!$f['follow_up_date']): ?>No date set
                                <?php elseif ($overdue): ?><small>Overdue</small><?= e(format_date($f['follow_up_date'])) ?>
                                <?php elseif ($isToday): ?>Today
                                <?php else: ?><?= e(format_date($f['follow_up_date'])) ?><?php endif; ?>
                            </span>
                            <strong><?= e(student_name($f, false)) ?></strong>
                            <span class="muted"><?= e($f['session_type']) ?>, with <?= e($f['counselor']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Concerns this month</h2>
        </div>
        <?php if (!$byType): ?>
            <p class="empty">No sessions recorded in <?= e(date('F')) ?> yet. <a href="session_form.php">Record a session</a></p>
        <?php else: ?>
            <ul class="bars">
                <?php foreach ($byType as $t): ?>
                    <li>
                        <span class="bar-label"><?= e($t['session_type']) ?></span>
                        <span class="bar-track"><span class="bar-fill" style="width: <?= round($t['total'] / $maxType * 100) ?>%"></span></span>
                        <span class="bar-value"><?= (int) $t['total'] ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="panel">
    <div class="panel-head">
        <h2>Recent sessions</h2>
        <a href="sessions.php">See all</a>
    </div>
    <?php if (!$recent): ?>
        <p class="empty">No sessions yet. Start by <a href="students.php">adding a student</a>, then record their first session.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Date</th><th>Student</th><th>Type</th><th>Concern</th><th>Counselor</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr class="row-link" data-href="session_view.php?id=<?= (int) $r['id'] ?>&amp;back=dashboard.php">
                        <td class="nowrap"><?= e(format_date($r['session_date'])) ?></td>
                        <td class="nowrap"><a class="row-main" href="session_view.php?id=<?= (int) $r['id'] ?>&amp;back=dashboard.php"><?= e(student_name($r)) ?></a></td>
                        <td><?= e($r['session_type']) ?></td>
                        <td class="muted"><?= e(excerpt($r['concern'], 60)) ?></td>
                        <td><?= e($r['counselor']) ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
