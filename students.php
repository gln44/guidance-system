<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

// ---------- Filters from the address bar ----------
$q = trim($_GET['q'] ?? '');

$gradeNumber = (int) ($_GET['grade'] ?? 0);
$grade = in_array('Grade ' . $gradeNumber, YEAR_LEVELS, true) ? 'Grade ' . $gradeNumber : '';

$section = $grade !== '' ? trim($_GET['section'] ?? '') : '';
const NO_SECTION = '__none';

// Builds a link to this page, keeping the search text
function students_link(array $changes): string
{
    global $q;
    $params = array_filter(array_merge(['q' => $q], $changes), fn($v) => $v !== '' && $v !== null && $v !== 0);
    return 'students.php' . ($params ? '?' . http_build_query($params) : '');
}

// ---------- Counts for the grade and section buttons ----------
$gradeCounts = [];
foreach (db()->query('SELECT year_level, COUNT(*) AS total FROM students GROUP BY year_level')->fetchAll() as $row) {
    $gradeCounts[(string) $row['year_level']] = (int) $row['total'];
}
$totalStudents = array_sum($gradeCounts);

$sectionButtons = [];
$noSectionCount = 0;
if ($grade !== '') {
    $defined = section_names_by_grade()[$grade] ?? [];
    $used = section_student_counts()[$grade] ?? [];

    // Sections set on the Sections page come first, then any others found on student records
    foreach ($defined as $name) {
        $sectionButtons[] = ['name' => $name, 'count' => $used[lower($name)]['count'] ?? 0];
        unset($used[lower($name)]);
    }
    $extra = array_values($used);
    usort($extra, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    foreach ($extra as $u) {
        $sectionButtons[] = ['name' => $u['name'], 'count' => $u['count']];
    }

    $stmt = db()->prepare("SELECT COUNT(*) FROM students WHERE year_level = ? AND (section IS NULL OR section = '')");
    $stmt->execute([$grade]);
    $noSectionCount = (int) $stmt->fetchColumn();
}

// ---------- Build the student query ----------
$conditions = [];
$params = [];

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

// Search: a grade typed in the box ("grade 7", "g11", "10") filters by grade;
// every other word must match the student number, a name, the strand, or the section.
[$searchGrade, $words] = parse_student_search($q);
if ($searchGrade !== null) {
    $conditions[] = 's.year_level = ?';
    $params[] = $searchGrade;
}
foreach ($words as $word) {
    $conditions[] = '(s.student_no LIKE ? OR s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ?
                      OR s.course LIKE ? OR s.section LIKE ? OR s.year_level LIKE ?)';
    array_push($params, ...array_fill(0, 7, '%' . $word . '%'));
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
[$rankSql, $rankParams] = student_search_rank($words, 's');
$orderBy = ($rankSql !== '' ? "$rankSql DESC, " : '') . 's.last_name, s.first_name';

$stmt = db()->prepare("SELECT COUNT(*) FROM students s $where");
$stmt->execute($params);
$matching = (int) $stmt->fetchColumn();
$p = paginate($matching);

$stmt = db()->prepare("
    SELECT s.*,
           (SELECT COUNT(*) FROM counseling_sessions cs WHERE cs.student_id = s.id) AS session_count,
           (SELECT MAX(cs.session_date) FROM counseling_sessions cs WHERE cs.student_id = s.id) AS last_session,
           (SELECT c.full_name FROM counseling_sessions cs JOIN counselors c ON c.id = cs.counselor_id
             WHERE cs.student_id = s.id ORDER BY cs.session_date DESC, cs.session_time DESC, cs.id DESC LIMIT 1) AS last_counselor,
           (SELECT COUNT(DISTINCT cs.counselor_id) FROM counseling_sessions cs WHERE cs.student_id = s.id) AS counselor_count
    FROM students s
    $where
    ORDER BY $orderBy
    LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute(array_merge($params, $rankParams)); // WHERE values first, then ORDER BY values
$students = $stmt->fetchAll();

// What the list is showing, in words
$showing = $grade !== '' ? $grade : 'All grades';
if ($section === NO_SECTION) {
    $showing .= ', no section';
} elseif ($section !== '') {
    $showing .= ', section ' . $section;
}

// ---------- Printable list (all matching students, not just one page) ----------
if (isset($_GET['print'])) {
    require_once __DIR__ . '/includes/print.php';
    $stmt = db()->prepare("
        SELECT s.*, (SELECT COUNT(*) FROM counseling_sessions cs WHERE cs.student_id = s.id) AS session_count,
           (SELECT c.full_name FROM counseling_sessions cs JOIN counselors c ON c.id = cs.counselor_id
             WHERE cs.student_id = s.id ORDER BY cs.session_date DESC, cs.session_time DESC, cs.id DESC LIMIT 1) AS last_counselor,
           (SELECT COUNT(DISTINCT cs.counselor_id) FROM counseling_sessions cs WHERE cs.student_id = s.id) AS counselor_count
        FROM students s
        $where
        ORDER BY CAST(SUBSTRING(s.year_level, 7) AS UNSIGNED), s.section, s.last_name, s.first_name
        LIMIT 5000
    ");
    $stmt->execute($params);
    $all = $stmt->fetchAll();

    print_start('Student list', [
        $showing,
        $q !== '' ? 'Search: “' . $q . '”' : '',
        count($all) . ' student' . (count($all) === 1 ? '' : 's'),
    ], students_link(['grade' => $grade !== '' ? $gradeNumber : '', 'section' => $section]), true);
    ?>
    <?php if (!$all): ?>
        <p class="empty">No students match.</p>
    <?php else: ?>
        <table>
            <thead><tr><th class="num">No.</th><th>Student no.</th><th>Name</th><th>Sex</th><th>Grade / strand</th><th>Section</th><th>Contact no.</th><th>Parent / guardian</th><th>Sessions</th><th>Counselor</th></tr></thead>
            <tbody>
            <?php foreach ($all as $i => $s): ?>
                <tr>
                    <td class="num"><?= $i + 1 ?></td>
                    <td class="nowrap"><?= e($s['student_no']) ?></td>
                    <td><?= e(student_name($s)) ?></td>
                    <td><?= e($s['sex']) ?></td>
                    <td><?= e(grade_strand($s)) ?></td>
                    <td><?= e($s['section']) ?></td>
                    <td class="nowrap"><?= e($s['contact_no']) ?></td>
                    <td><?= e($s['guardian_name']) ?><?php if ($s['guardian_contact']): ?><span class="sub"><?= e($s['guardian_contact']) ?></span><?php endif; ?></td>
                    <td><?= (int) $s['session_count'] ?></td>
                    <td><?= e($s['last_counselor']) ?><?php if ($s['counselor_count'] > 1): ?><span class="sub">and <?= $s['counselor_count'] - 1 ?> other<?= $s['counselor_count'] > 2 ? 's' : '' ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif;
    print_end('student_list');
    exit;
}

$pageTitle = 'Students';
$activeNav = 'students';
require __DIR__ . '/includes/header.php';
?>

<header class="page-head row">
    <h1>Students</h1>
    <div class="button-row">
        <?php if (is_admin()): ?>
            <a class="btn" href="sections.php"><?= icon('layers') ?>Manage sections</a>
        <?php endif; ?>
        <a class="btn" href="<?= e(students_link(['grade' => $grade !== '' ? $gradeNumber : '', 'section' => $section, 'print' => 1])) ?>"><?= icon('printer') ?>Print list</a>
        <a class="btn" href="students_import.php"><?= icon('upload') ?>Import from file</a>
        <a class="btn btn-primary" href="student_form.php"><?= icon('user-plus') ?>Add student</a>
    </div>
</header>

<nav class="chip-row grade-row" aria-label="Filter by grade level">
    <a class="chip" href="<?= e(students_link([])) ?>" <?= $grade === '' ? 'aria-current="true"' : '' ?>>
        All grades <span class="chip-count"><?= $totalStudents ?></span>
    </a>
    <?php foreach (YEAR_LEVELS as $level): $n = (int) substr($level, 6); ?>
        <a class="chip" href="<?= e(students_link(['grade' => $n])) ?>" <?= $grade === $level ? 'aria-current="true"' : '' ?>>
            <?= e($level) ?> <span class="chip-count"><?= $gradeCounts[$level] ?? 0 ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($grade !== ''): ?>
    <nav class="chip-row chip-row-sections" aria-label="Filter <?= e($grade) ?> by section">
        <span class="chip-label">Sections</span>
        <a class="chip" href="<?= e(students_link(['grade' => $gradeNumber])) ?>" <?= $section === '' ? 'aria-current="true"' : '' ?>>
            All sections <span class="chip-count"><?= $gradeCounts[$grade] ?? 0 ?></span>
        </a>
        <?php foreach ($sectionButtons as $b): ?>
            <a class="chip" href="<?= e(students_link(['grade' => $gradeNumber, 'section' => $b['name']])) ?>" <?= lower($section) === lower($b['name']) ? 'aria-current="true"' : '' ?>>
                <?= e($b['name']) ?> <span class="chip-count"><?= $b['count'] ?></span>
            </a>
        <?php endforeach; ?>
        <?php if ($noSectionCount > 0): ?>
            <a class="chip" href="<?= e(students_link(['grade' => $gradeNumber, 'section' => NO_SECTION])) ?>" <?= $section === NO_SECTION ? 'aria-current="true"' : '' ?>>
                No section <span class="chip-count"><?= $noSectionCount ?></span>
            </a>
        <?php endif; ?>
        <?php if (!$sectionButtons): ?>
            <span class="muted small">No sections set for <?= e($grade) ?> yet.</span>
        <?php endif; ?>
        <?php if (is_admin()): ?>
            <a class="chip-manage small" href="sections.php#grade-<?= $gradeNumber ?>"><?= icon('edit') ?>Edit <?= e($grade) ?> sections</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<form class="toolbar" method="get" role="search">
    <?php if ($grade !== ''): ?><input type="hidden" name="grade" value="<?= $gradeNumber ?>"><?php endif; ?>
    <?php if ($section !== ''): ?><input type="hidden" name="section" value="<?= e($section) ?>"><?php endif; ?>
    <label class="grow">
        <span class="sr-only">Search students</span>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name, student number, grade, section, or strand, e.g. “grade 7 Rosal”">
    </label>
    <button type="submit" class="btn"><?= icon('search') ?>Search</button>
    <?php if ($q !== ''): ?><a class="btn btn-quiet" href="<?= e(students_link(['q' => '', 'grade' => $grade !== '' ? $gradeNumber : '', 'section' => $section])) ?>"><?= icon('x') ?>Clear search</a><?php endif; ?>
</form>

<section class="panel">
    <div class="panel-head">
        <h2><?= e($showing) ?></h2>
        <span class="muted small"><?= $matching ?> student<?= $matching === 1 ? '' : 's' ?><?= $q !== '' ? ' matching “' . e($q) . '”' : '' ?></span>
    </div>
    <?php if (!$students): ?>
        <p class="empty">
            <?php if ($q !== ''): ?>
                No students match “<?= e($q) ?>”<?= $grade !== '' ? ' in ' . e($showing) : '' ?>.
            <?php elseif ($grade !== ''): ?>
                No students in <?= e($showing) ?> yet.
            <?php else: ?>
                No student records yet.
            <?php endif; ?>
            <a href="student_form.php">Add a student</a> or <a href="students_import.php">import a class list</a>.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student no.</th><th>Name</th><th>Grade / strand</th><th>Section</th><th>Sessions</th><th>Counselor</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($students as $s): ?>
                    <tr class="row-link" data-href="student_view.php?id=<?= (int) $s['id'] ?>">
                        <td class="nowrap"><?= e($s['student_no']) ?></td>
                        <td class="nowrap">
                            <div class="name-cell">
                            <?= student_photo($s, 'photo-sm') ?>
                            <span>
                            <a class="row-main" href="student_view.php?id=<?= (int) $s['id'] ?>"><?= e(student_name($s)) ?></a>
                            <?php if ($middle = matched_middle_name($s, $words)): ?><br><span class="muted small">Middle name: <?= e($middle) ?></span><?php endif; ?>
                            </span>
                            </div>
                        </td>
                        <td><?= e(grade_strand($s)) ?></td>
                        <td><?= $s['section'] ? e($s['section']) : '<span class="muted">None</span>' ?></td>
                        <td class="nowrap">
                            <?php if ($s['session_count'] > 0): ?>
                                <?= (int) $s['session_count'] ?> session<?= $s['session_count'] == 1 ? '' : 's' ?>
                                <br><span class="muted small">Last: <?= e(format_date($s['last_session'])) ?></span>
                            <?php else: ?>
                                <span class="muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <?php if ($s['last_counselor']): ?>
                                <?= e($s['last_counselor']) ?>
                                <?php if ($s['counselor_count'] > 1): ?><br><span class="muted small">and <?= $s['counselor_count'] - 1 ?> other<?= $s['counselor_count'] > 2 ? 's' : '' ?></span><?php endif; ?>
                            <?php else: ?>
                                <span class="muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions actions-stacked">
                            <a href="session_form.php?student_id=<?= (int) $s['id'] ?>"><?= icon('plus') ?>Record session</a>
                            <a href="student_form.php?id=<?= (int) $s['id'] ?>"><?= icon('edit') ?>Edit</a>
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
