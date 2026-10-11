<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$q      = trim($_GET['q'] ?? '');
$type   = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';
$mine   = !empty($_GET['mine']);

$gradeNumber = (int) ($_GET['grade'] ?? 0);
$grade   = in_array('Grade ' . $gradeNumber, YEAR_LEVELS, true) ? 'Grade ' . $gradeNumber : '';
$section = $grade !== '' ? trim($_GET['section'] ?? '') : '';
const NO_SECTION = '__none';

// Link to this page with the current filters, changing only what's given
function sessions_link(array $changes): string
{
    $params = array_merge($_GET, $changes);
    unset($params['page']);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== 0);
    return 'sessions.php' . ($params ? '?' . http_build_query($params) : '');
}

// ---------- Filters other than grade and section ----------
$base = [];
$baseParams = [];

// Search: a grade typed in the box ("grade 7", "g11", "10") filters by grade; every other
// word must match the student number, a name, the strand, the section, or the concern.
[$searchGrade, $words] = parse_student_search($q);
if ($searchGrade !== null) {
    $base[] = 's.year_level = ?';
    $baseParams[] = $searchGrade;
}
foreach ($words as $word) {
    $base[] = '(s.student_no LIKE ? OR s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ?
               OR s.course LIKE ? OR s.section LIKE ? OR s.year_level LIKE ? OR cs.concern LIKE ?)';
    array_push($baseParams, ...array_fill(0, 8, '%' . $word . '%'));
}
if (in_array($type, SESSION_TYPES, true)) {
    $base[] = 'cs.session_type = ?';
    $baseParams[] = $type;
}
if (in_array($status, SESSION_STATUSES, true)) {
    $base[] = 'cs.status = ?';
    $baseParams[] = $status;
}
if (valid_date($from)) {
    $base[] = 'cs.session_date >= ?';
    $baseParams[] = $from;
}
if (valid_date($to)) {
    $base[] = 'cs.session_date <= ?';
    $baseParams[] = $to;
}
if ($mine) {
    $base[] = 'cs.counselor_id = ?';
    $baseParams[] = current_user()['id'];
}

$joins = 'FROM counseling_sessions cs
          JOIN students s   ON s.id = cs.student_id
          JOIN counselors c ON c.id = cs.counselor_id';
$baseWhere = $base ? 'WHERE ' . implode(' AND ', $base) : '';

// ---------- Counts for the grade and section buttons (respecting the other filters) ----------
$gradeCounts = [];
$stmt = db()->prepare("SELECT s.year_level, COUNT(*) AS total $joins $baseWhere GROUP BY s.year_level");
$stmt->execute($baseParams);
foreach ($stmt->fetchAll() as $row) {
    $gradeCounts[(string) $row['year_level']] = (int) $row['total'];
}
$allCount = array_sum($gradeCounts);

$sectionButtons = [];
$noSectionCount = 0;
if ($grade !== '') {
    $stmt = db()->prepare("SELECT s.section, COUNT(*) AS total $joins "
        . ($baseWhere ? $baseWhere . ' AND' : 'WHERE') . ' s.year_level = ? GROUP BY s.section');
    $stmt->execute(array_merge($baseParams, [$grade]));
    $used = [];
    foreach ($stmt->fetchAll() as $row) {
        if ((string) $row['section'] === '') {
            $noSectionCount += (int) $row['total'];
        } else {
            $used[lower($row['section'])] = ['name' => $row['section'], 'count' => (int) $row['total']];
        }
    }
    foreach (section_names_by_grade()[$grade] ?? [] as $name) {
        $sectionButtons[] = ['name' => $name, 'count' => $used[lower($name)]['count'] ?? 0];
        unset($used[lower($name)]);
    }
    $extra = array_values($used);
    usort($extra, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    $sectionButtons = array_merge($sectionButtons, $extra);
}

// ---------- The list itself ----------
$conditions = $base;
$params = $baseParams;
if ($grade !== '') {
    $conditions[] = 's.year_level = ?';
    $params[] = $grade;
}
if ($section === NO_SECTION) {
    $conditions[] = "(s.section IS NULL OR s.section = '')";
} elseif ($section !== '') {
    $conditions[] = 's.section = ?';
    $params[] = $section;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = db()->prepare("SELECT COUNT(*) $joins $where");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$p = paginate($total);

$stmt = db()->prepare("
    SELECT cs.id, cs.session_date, cs.session_time, cs.session_type, cs.concern, cs.status, cs.follow_up_date,
           s.id AS student_id, s.student_no, s.first_name, s.last_name, s.middle_name,
           s.year_level, s.course, s.section, c.full_name AS counselor
    $joins $where
    ORDER BY cs.session_date DESC, cs.session_time DESC, cs.id DESC
    LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute($params);
$sessions = $stmt->fetchAll();

$filtered = $conditions !== [];
$showing = $grade !== '' ? $grade : 'All grades';
if ($section === NO_SECTION) {
    $showing .= ', no section';
} elseif ($section !== '') {
    $showing .= ', section ' . $section;
}

$pageTitle = 'Sessions';
$activeNav = 'sessions';
require __DIR__ . '/includes/header.php';
?>

<header class="page-head row">
    <h1>Sessions</h1>
    <a class="btn btn-primary" href="session_form.php"><?= icon('plus') ?>Record session</a>
</header>

<nav class="chip-row" aria-label="Filter sessions by grade level">
    <a class="chip" href="<?= e(sessions_link(['grade' => '', 'section' => ''])) ?>" <?= $grade === '' ? 'aria-current="true"' : '' ?>>
        All grades <span class="chip-count"><?= $allCount ?></span>
    </a>
    <?php foreach (YEAR_LEVELS as $level): $n = (int) substr($level, 6); ?>
        <a class="chip" href="<?= e(sessions_link(['grade' => $n, 'section' => ''])) ?>" <?= $grade === $level ? 'aria-current="true"' : '' ?>>
            <?= e($level) ?> <span class="chip-count"><?= $gradeCounts[$level] ?? 0 ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($grade !== ''): ?>
    <nav class="chip-row chip-row-sections" aria-label="Filter <?= e($grade) ?> sessions by section">
        <span class="chip-label">Sections</span>
        <a class="chip" href="<?= e(sessions_link(['grade' => $gradeNumber, 'section' => ''])) ?>" <?= $section === '' ? 'aria-current="true"' : '' ?>>
            All sections <span class="chip-count"><?= $gradeCounts[$grade] ?? 0 ?></span>
        </a>
        <?php foreach ($sectionButtons as $b): ?>
            <a class="chip" href="<?= e(sessions_link(['grade' => $gradeNumber, 'section' => $b['name']])) ?>" <?= lower($section) === lower($b['name']) ? 'aria-current="true"' : '' ?>>
                <?= e($b['name']) ?> <span class="chip-count"><?= $b['count'] ?></span>
            </a>
        <?php endforeach; ?>
        <?php if ($noSectionCount > 0): ?>
            <a class="chip" href="<?= e(sessions_link(['grade' => $gradeNumber, 'section' => NO_SECTION])) ?>" <?= $section === NO_SECTION ? 'aria-current="true"' : '' ?>>
                No section <span class="chip-count"><?= $noSectionCount ?></span>
            </a>
        <?php endif; ?>
        <?php if (!$sectionButtons && $noSectionCount === 0): ?>
            <span class="muted small">No sessions with <?= e($grade) ?> students<?= $base ? ' match these filters' : ' yet' ?>.</span>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<form class="toolbar filters" method="get" role="search">
    <?php if ($grade !== ''): ?><input type="hidden" name="grade" value="<?= $gradeNumber ?>"><?php endif; ?>
    <?php if ($section !== ''): ?><input type="hidden" name="section" value="<?= e($section) ?>"><?php endif; ?>
    <label class="grow">Search
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Student, grade, section, or concern, e.g. “grade 8 Narra”">
    </label>
    <label>Type
        <select name="type">
            <option value="">All types</option>
            <?php foreach (SESSION_TYPES as $opt): ?>
                <option <?= selected($type, $opt) ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Status
        <select name="status">
            <option value="">Any status</option>
            <?php foreach (SESSION_STATUSES as $opt): ?>
                <option <?= selected($status, $opt) ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>From <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="check"><input type="checkbox" name="mine" value="1" <?= $mine ? 'checked' : '' ?>> My sessions only</label>
    <div class="button-row">
        <button type="submit" class="btn"><?= icon('filter') ?>Apply filters</button>
        <?php if ($filtered): ?><a class="btn btn-quiet" href="sessions.php"><?= icon('x') ?>Clear all</a><?php endif; ?>
    </div>
</form>

<section class="panel">
    <div class="panel-head">
        <h2><?= e($showing) ?></h2>
        <span class="muted small"><?= $total ?> session<?= $total === 1 ? '' : 's' ?><?= $base ? ' matching the filters' : '' ?></span>
    </div>
    <?php if (!$sessions): ?>
        <p class="empty">
            <?= $filtered ? 'No sessions match. Try another grade or section, a wider date range, or clear the filters.' : 'No sessions recorded yet.' ?>
            <a href="session_form.php">Record a session</a>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Date</th><th>Student</th><th>Grade / section</th><th>Type</th><th>Concern</th><th>Counselor</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($sessions as $r): ?>
                    <tr>
                        <td class="nowrap">
                            <a href="session_view.php?id=<?= (int) $r['id'] ?>"><?= e(format_date($r['session_date'])) ?></a>
                            <?php if ($r['session_time']): ?><br><span class="muted small"><?= e(format_time($r['session_time'])) ?></span><?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <a href="student_view.php?id=<?= (int) $r['student_id'] ?>"><?= e(student_name($r)) ?></a><br>
                            <span class="muted small"><?= e($r['student_no']) ?></span>
                        </td>
                        <td>
                            <?= e(grade_strand($r)) ?: '<span class="muted">None</span>' ?>
                            <?php if ($r['section']): ?><br><span class="muted small"><?= e($r['section']) ?></span><?php endif; ?>
                        </td>
                        <td><?= e($r['session_type']) ?></td>
                        <td class="muted"><?= e(excerpt($r['concern'], 70)) ?></td>
                        <td><?= e($r['counselor']) ?></td>
                        <td>
                            <?= status_badge($r['status']) ?>
                            <?php if ($r['status'] === 'Follow-up' && $r['follow_up_date']): ?>
                                <br><span class="muted small">by <?= e(format_date($r['follow_up_date'])) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= page_links($p) ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
