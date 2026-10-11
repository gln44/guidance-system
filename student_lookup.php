<?php
// Returns students matching a search, for the "Student" box on the session form.
// Understands the same searches as the Students page: names, student numbers,
// "grade 7", "g11 stem", "grade 7 Camia", and so on.
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Your login expired. Refresh the page and log in again.']);
    exit;
}
require_login();

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['results' => [], 'total' => 0]);
    exit;
}

[$grade, $words] = parse_student_search($q);
$conditions = [];
$params = [];
if ($grade !== null) {
    $conditions[] = 'year_level = ?';
    $params[] = $grade;
}
foreach ($words as $word) {
    $conditions[] = '(student_no LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR middle_name LIKE ?
                      OR course LIKE ? OR section LIKE ?)';
    array_push($params, ...array_fill(0, 6, '%' . $word . '%'));
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = db()->prepare("SELECT COUNT(*) FROM students $where");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

// Best matches first (same order as the Students page search)
[$rank, $rankParams] = student_search_rank($words);
$orderBy = ($rank !== '' ? "$rank DESC, " : '') . 'last_name, first_name';

$stmt = db()->prepare("
    SELECT id, student_no, last_name, first_name, middle_name, year_level, course, section
    FROM students $where
    ORDER BY $orderBy
    LIMIT 20
");
$stmt->execute(array_merge($params, $rankParams)); // WHERE values come first in the query, then ORDER BY values

$results = [];
foreach ($stmt->fetchAll() as $s) {
    $detail = grade_strand($s);
    if ($s['section']) {
        $detail .= ($detail ? ', ' : '') . 'Section ' . $s['section'];
    }
    $results[] = [
        'id'     => (int) $s['id'],
        'name'   => student_name($s),
        'middle' => matched_middle_name($s, $words),
        'no'     => $s['student_no'],
        'detail' => $detail,
    ];
}

echo json_encode(['results' => $results, 'total' => $total], JSON_UNESCAPED_UNICODE);
