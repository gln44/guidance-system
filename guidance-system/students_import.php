<?php
// Import many students at once from a CSV file (for example, a class list saved from Excel).
// Step 1: upload the file. Step 2: check the preview. Step 3: confirm, and the rows are saved.
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

const IMPORT_MAX_ROWS  = 5000;
const IMPORT_MAX_BYTES = 5 * 1024 * 1024; // 5 MB

// Columns the system understands.
// field => [label, required?, max length, column names that count as this field]
// Column names are compared in lowercase with spaces and punctuation removed,
// so "Student No.", "student_no", and "STUDENT NO" all match "studentno".
function import_fields(): array
{
    return [
        'student_no'       => ['Student No',       true,  30,  ['studentno', 'studentnumber', 'studentid', 'idno', 'idnumber', 'lrn', 'id']],
        'last_name'        => ['Last Name',        true,  60,  ['lastname', 'surname', 'familyname']],
        'first_name'       => ['First Name',       true,  60,  ['firstname', 'givenname']],
        'middle_name'      => ['Middle Name',      false, 60,  ['middlename', 'middle', 'mi', 'middleinitial']],
        'sex'              => ['Sex',              false, 20,  ['sex', 'gender']],
        'birthdate'        => ['Birthdate',        false, 10,  ['birthdate', 'birthday', 'dateofbirth', 'dob']],
        'year_level'       => ['Grade Level',      false, 20,  ['gradelevel', 'grade', 'yearlevel', 'level']],
        'course'           => ['Strand',           false, 100, ['strand', 'track', 'course', 'program']],
        'section'          => ['Section',          false, 30,  ['section', 'sec']],
        'contact_no'       => ['Contact No',       false, 30,  ['contactno', 'contactnumber', 'contact', 'phone', 'phoneno', 'phonenumber', 'mobile', 'mobileno', 'mobilenumber', 'cellphone', 'cellphoneno']],
        'email'            => ['Email',            false, 100, ['email', 'emailaddress']],
        'address'          => ['Address',          false, 255, ['address', 'homeaddress']],
        'guardian_name'    => ['Guardian Name',    false, 100, ['guardianname', 'guardian', 'parent', 'parentname', 'parentguardian', 'parentorguardian', 'parentguardianname']],
        'guardian_contact' => ['Guardian Contact', false, 30,  ['guardiancontact', 'guardiancontactno', 'guardiancontactnumber', 'guardianphone', 'guardianmobile', 'parentcontact', 'parentcontactno', 'parentcontactnumber', 'parentguardiancontact', 'parentguardiancontactno']],
    ];
}

function import_normalize_header(string $header): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($header));
}

function import_clean_cell($value): string
{
    $value = (string) $value;
    $clean = preg_replace('/\s+/u', ' ', $value);
    if ($clean === null) {
        $clean = preg_replace('/\s+/', ' ', $value);
    }
    return trim((string) $clean);
}

// Reads a CSV file into rows of cells. Handles Excel's different CSV flavors:
// UTF-8 with or without a byte order mark, older non-UTF-8 files (so ñ survives),
// and comma, semicolon, or tab separators.
function import_read_csv(string $path): array
{
    $content = (string) file_get_contents($path);
    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        $content = substr($content, 3);
    }
    if (!preg_match('//u', $content)) {
        if (function_exists('mb_convert_encoding')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        } elseif (function_exists('iconv')) {
            $converted = iconv('Windows-1252', 'UTF-8//TRANSLIT', $content);
            if ($converted !== false) {
                $content = $converted;
            }
        }
    }
    $content = str_replace(["\r\n", "\r"], "\n", $content);

    $firstLine = (string) strtok($content, "\n");
    $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
    arsort($counts);
    $delimiter = (string) key($counts);

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
        $rows[] = array_map('import_clean_cell', $row);
    }
    fclose($stream);
    return $rows;
}

// Accepts 2009-05-14, 05/14/2009, 5/14/09, 14/05/2009 (when the first number is over 12),
// "May 14, 2009", and Excel date numbers. Returns YYYY-MM-DD, or null if unreadable.
function import_parse_date(string $value): ?string
{
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
    } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})$/', $value, $m)) {
        $a = (int) $m[1]; $b = (int) $m[2]; $y = (int) $m[3];
        if ($y < 100) {
            $y += $y > (int) date('y') ? 1900 : 2000;
        }
        if ($a > 12 && $b <= 12) {
            $d = $a; $mo = $b;
        } else {
            $mo = $a; $d = $b;
        }
    } elseif (preg_match('/^\d{5}$/', $value)) {
        return (new DateTime('1899-12-30'))->modify('+' . (int) $value . ' days')->format('Y-m-d');
    } elseif (preg_match('/[a-z]/i', $value) && ($time = strtotime($value)) !== false) {
        return date('Y-m-d', $time);
    } else {
        return null;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

// "7", "Grade 7", "G7", "Gr. 7" all become "Grade 7". Returns null if not Grade 7 to 12.
function import_parse_grade(string $value): ?string
{
    return preg_match('/(?<!\d)(1[0-2]|[7-9])(?!\d)/', $value, $m) ? 'Grade ' . $m[1] : null;
}

function import_check_upload($file): string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Choose a file to upload.';
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > IMPORT_MAX_BYTES) {
        return 'That file is too large. The limit is 5 MB.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return 'The upload did not finish. Try again.';
    }
    $excelMessage = 'That is an Excel file. In Excel, click File > Save As, choose “CSV UTF-8 (Comma delimited)”, then upload the .csv file.';
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($extension, ['xlsx', 'xls', 'xlsm', 'ods'], true)) {
        return $excelMessage;
    }
    $start = (string) file_get_contents($file['tmp_name'], false, null, 0, 4);
    if (strncmp($start, 'PK', 2) === 0 || strncmp($start, "\xD0\xCF\x11\xE0", 4) === 0) {
        return $excelMessage;
    }
    if (!in_array($extension, ['csv', 'txt'], true)) {
        return 'Upload a .csv file. In Excel, use File > Save As and choose “CSV UTF-8 (Comma delimited)”.';
    }
    return '';
}

// Reads and checks every row without saving anything. Returns [error message, preview].
function import_build_preview(string $path, string $mode): array
{
    $fields  = import_fields();
    $allRows = import_read_csv($path);

    $headerIndex = null;
    foreach ($allRows as $i => $cells) {
        if (implode('', $cells) !== '') {
            $headerIndex = $i;
            break;
        }
    }
    if ($headerIndex === null) {
        return ['The file is empty.', null];
    }
    $header = $allRows[$headerIndex];

    // Match each column in the file to a field
    $map = [];
    $ignored = [];
    foreach ($header as $col => $name) {
        $normalized = import_normalize_header($name);
        $matched = false;
        foreach ($fields as $field => $def) {
            if (!isset($map[$field]) && in_array($normalized, $def[3], true)) {
                $map[$field] = $col;
                $matched = true;
                break;
            }
        }
        if (!$matched && $name !== '') {
            $ignored[] = $name;
        }
    }

    $missing = [];
    foreach ($fields as $field => $def) {
        if ($def[1] && !isset($map[$field])) {
            $missing[] = $def[0];
        }
    }
    if ($missing) {
        $found = array_filter($header, fn($h) => $h !== '');
        return ['The first row of the file must have column names, and these are missing: ' . implode(', ', $missing) . '. '
              . 'Column names found in the file: ' . ($found ? implode(', ', $found) : 'none') . '.', null];
    }

    // Collect the student rows (skip blank lines), keeping their spreadsheet row numbers
    $records = [];
    foreach ($allRows as $i => $cells) {
        if ($i <= $headerIndex || implode('', $cells) === '') {
            continue;
        }
        $data = [];
        foreach ($fields as $field => $def) {
            $data[$field] = isset($map[$field]) ? ($cells[$map[$field]] ?? '') : '';
        }
        $records[] = ['line' => $i + 1, 'data' => $data];
    }
    if (!$records) {
        return ['The file has column names but no students under them.', null];
    }
    if (count($records) > IMPORT_MAX_ROWS) {
        return ['The file has ' . count($records) . ' students. Split it into files of up to ' . IMPORT_MAX_ROWS . ' and import them one at a time.', null];
    }

    // Which student numbers are already in the system?
    $numbers = array_values(array_unique(array_filter(array_map(fn($r) => strtolower($r['data']['student_no']), $records), 'strlen')));
    $existing = [];
    foreach (array_chunk($numbers, 500) as $chunk) {
        $stmt = db()->prepare('SELECT id, student_no FROM students WHERE student_no IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll() as $row) {
            $existing[strtolower($row['student_no'])] = (int) $row['id'];
        }
    }

    $seen   = [];
    $counts = ['new' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
    $thisYear = (int) date('Y');

    foreach ($records as &$record) {
        $d = &$record['data'];
        $problems = [];

        foreach ($fields as $field => $def) {
            if ($def[1] && $d[$field] === '') {
                $problems[] = $def[0] . ' is blank.';
            }
        }

        if ($d['sex'] !== '') {
            $sex = strtolower($d['sex']);
            if (in_array($sex, ['m', 'male'], true)) {
                $d['sex'] = 'Male';
            } elseif (in_array($sex, ['f', 'female'], true)) {
                $d['sex'] = 'Female';
            } else {
                $problems[] = 'Sex “' . $d['sex'] . '” should be Male or Female (or M / F).';
            }
        }

        if ($d['birthdate'] !== '') {
            $date = import_parse_date($d['birthdate']);
            if ($date === null) {
                $problems[] = 'Birthdate “' . $d['birthdate'] . '” could not be read. Use 2009-05-14 or 05/14/2009.';
            } elseif ((int) substr($date, 0, 4) < 1900 || $date > date('Y-m-d')) {
                $problems[] = 'Birthdate “' . $d['birthdate'] . '” is not a possible birthdate.';
            } else {
                $d['birthdate'] = $date;
            }
        }

        if ($d['year_level'] !== '') {
            $grade = import_parse_grade($d['year_level']);
            if ($grade === null) {
                $problems[] = 'Grade level “' . $d['year_level'] . '” should be 7 to 12.';
            } else {
                $d['year_level'] = $grade;
            }
        }

        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $problems[] = 'Email “' . $d['email'] . '” is not a valid email address.';
        }

        foreach ($fields as $field => $def) {
            if (text_length($d[$field]) > $def[2]) {
                $problems[] = $def[0] . ' is longer than ' . $def[2] . ' characters.';
            }
        }

        $key = strtolower($d['student_no']);
        if ($key !== '') {
            if (isset($seen[$key])) {
                $problems[] = 'Student No ' . $d['student_no'] . ' is also on row ' . $seen[$key] . ' of the file.';
            } else {
                $seen[$key] = $record['line'];
            }
        }

        if ($problems) {
            $record['status'] = 'error';
        } elseif (isset($existing[$key])) {
            $record['status'] = $mode === 'update' ? 'update' : 'skip';
        } else {
            $record['status'] = 'new';
        }
        $record['problems'] = $problems;
        $counts[$record['status']]++;
        unset($d);
    }
    unset($record);

    return ['', ['records' => $records, 'counts' => $counts, 'ignored' => $ignored, 'mode' => $mode]];
}

// ------------------------------------------------------------
// Handle the forms
// ------------------------------------------------------------
$error    = '';
$preview  = null;
$fileName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'confirm') {
        $import = $_SESSION['student_import'] ?? null;
        unset($_SESSION['student_import']);
        if (!$import || $import['expires'] < time()) {
            flash('error', 'The preview expired. Upload the file again.');
            redirect('students_import.php');
        }

        $pdo    = db();
        $fields = array_keys(import_fields());
        $added = $updated = $skipped = 0;

        $pdo->beginTransaction();
        try {
            $find   = $pdo->prepare('SELECT id FROM students WHERE student_no = ?');
            $insert = $pdo->prepare('INSERT INTO students (' . implode(', ', $fields) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')');

            foreach ($import['rows'] as $data) {
                $find->execute([$data['student_no']]);
                $id = $find->fetchColumn();

                if ($id === false) {
                    $insert->execute(array_map(fn($f) => $data[$f] === '' ? null : $data[$f], $fields));
                    $added++;
                } elseif ($import['mode'] === 'update') {
                    // Only overwrite details that are filled in on the file
                    $set = [];
                    $values = [];
                    foreach ($fields as $f) {
                        if ($f !== 'student_no' && $data[$f] !== '') {
                            $set[] = "$f = ?";
                            $values[] = $data[$f];
                        }
                    }
                    if ($set) {
                        $values[] = $id;
                        $pdo->prepare('UPDATE students SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($values);
                    }
                    $updated++;
                } else {
                    $skipped++;
                }
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('error', 'Nothing was imported because the database reported a problem. Check the file and try again.');
            redirect('students_import.php');
        }

        $parts = [];
        if ($added)   $parts[] = $added . ' student' . ($added === 1 ? '' : 's') . ' added';
        if ($updated) $parts[] = $updated . ' updated';
        if ($skipped) $parts[] = $skipped . ' skipped because they already exist';
        flash('success', 'Import finished: ' . ($parts ? implode(', ', $parts) : 'no changes') . '.');
        redirect('students.php');
    }

    if ($action === 'preview') {
        $mode = ($_POST['on_duplicate'] ?? '') === 'update' ? 'update' : 'skip';
        $file = $_FILES['file'] ?? null;
        $fileName = $file['name'] ?? '';
        $error = import_check_upload($file);
        if ($error === '') {
            [$error, $preview] = import_build_preview($file['tmp_name'], $mode);
        }
        if ($preview) {
            $toSave = array_values(array_map(
                fn($r) => $r['data'],
                array_filter($preview['records'], fn($r) => in_array($r['status'], ['new', 'update'], true))
            ));
            $_SESSION['student_import'] = ['rows' => $toSave, 'mode' => $mode, 'expires' => time() + 30 * 60];
        }
    }
}

$statusLabels = ['new' => 'New', 'update' => 'Will update', 'skip' => 'Already exists', 'error' => 'Problem'];

$pageTitle = 'Import students';
$activeNav = 'students';
require __DIR__ . '/includes/header.php';
?>

<header class="page-head">
    <a class="back" href="students.php"><?= icon('arrow-left') ?>Students</a>
    <h1>Import students from a file</h1>
    <p class="muted">Add a whole class list at once instead of typing each student. Nothing is saved until you check the preview and confirm.</p>
</header>

<?php if ($error): ?>
    <div class="flash flash-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($preview):
    $c = $preview['counts'];
    $ready = $c['new'] + $c['update'];
    $problemRows = array_filter($preview['records'], fn($r) => $r['status'] === 'error');
    $okRows = array_values(array_filter($preview['records'], fn($r) => $r['status'] !== 'error'));
    $shown = array_slice($okRows, 0, 100);
?>
    <section class="panel">
        <div class="panel-head"><h2>Preview of <?= e($fileName) ?></h2></div>
        <ul class="import-summary">
            <li><?= status_tag('new', 'New') ?> <strong><?= $c['new'] ?></strong> will be added</li>
            <?php if ($preview['mode'] === 'update'): ?>
                <li><?= status_tag('update', 'Will update') ?> <strong><?= $c['update'] ?></strong> already exist and will be updated</li>
            <?php else: ?>
                <li><?= status_tag('skip', 'Already exists') ?> <strong><?= $c['skip'] ?></strong> already exist and will be skipped</li>
            <?php endif; ?>
            <li><?= status_tag('error', 'Problem') ?> <strong><?= $c['error'] ?></strong> have problems and won’t be imported</li>
        </ul>
        <?php if ($preview['ignored']): ?>
            <p class="muted small">These columns aren’t used and will be ignored: <?= e(implode(', ', $preview['ignored'])) ?>.</p>
        <?php endif; ?>

        <div class="form-actions">
            <?php if ($ready > 0): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm">
                    <button type="submit" class="btn btn-primary"><?= icon('check') ?>Import <?= $ready ?> student<?= $ready === 1 ? '' : 's' ?></button>
                </form>
            <?php endif; ?>
            <a class="btn" href="students_import.php"><?= icon('upload') ?>Choose a different file</a>
        </div>
        <?php if ($ready === 0): ?>
            <p class="muted small">There is nothing new to import from this file.</p>
        <?php endif; ?>
    </section>

    <?php if ($problemRows): ?>
        <section class="panel">
            <div class="panel-head"><h2>Rows with problems</h2></div>
            <p class="muted small">Fix these rows in your spreadsheet and import the file again. Students you already imported will be skipped automatically the second time.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Row</th><th>Student no.</th><th>Name</th><th>What to fix</th></tr></thead>
                    <tbody>
                    <?php foreach ($problemRows as $r): ?>
                        <tr>
                            <td><?= (int) $r['line'] ?></td>
                            <td class="nowrap"><?= e($r['data']['student_no']) ?></td>
                            <td class="nowrap"><?= e(trim($r['data']['last_name'] . ', ' . $r['data']['first_name'], ', ')) ?></td>
                            <td class="problem-list"><?php foreach ($r['problems'] as $p): ?><span><?= e($p) ?></span><?php endforeach; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($okRows): ?>
        <section class="panel">
            <div class="panel-head"><h2>Students in the file</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Row</th><th>Student no.</th><th>Name</th><th>Grade / strand</th><th>Section</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($shown as $r): $d = $r['data']; ?>
                        <tr>
                            <td><?= (int) $r['line'] ?></td>
                            <td class="nowrap"><?= e($d['student_no']) ?></td>
                            <td class="nowrap"><?= e(student_name($d)) ?></td>
                            <td><?= e(grade_strand($d)) ?></td>
                            <td><?= e($d['section']) ?></td>
                            <td><?= status_tag($r['status'], $statusLabels[$r['status']]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($okRows) > count($shown)): ?>
                <p class="muted small">Showing the first <?= count($shown) ?> of <?= count($okRows) ?> students.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

<?php else: ?>

    <section class="panel import-steps">
        <h2>1. Prepare the spreadsheet</h2>
        <p>Put one student per row. The <strong>first row must have column names</strong>. The columns can be in any order, and extra columns are ignored.</p>
        <p><a class="btn" href="assets/templates/students-template.csv" download><?= icon('download') ?>Download a blank template</a></p>

        <div class="table-wrap">
            <table>
                <thead><tr><th>Column name</th><th>Needed?</th><th>What to put</th></tr></thead>
                <tbody>
                    <tr><td>Student No</td><td>Required</td><td>The student’s ID number. Each one must be different.</td></tr>
                    <tr><td>Last Name</td><td>Required</td><td></td></tr>
                    <tr><td>First Name</td><td>Required</td><td></td></tr>
                    <tr><td>Middle Name</td><td>Optional</td><td></td></tr>
                    <tr><td>Sex</td><td>Optional</td><td>Male or Female (M or F also works)</td></tr>
                    <tr><td>Birthdate</td><td>Optional</td><td>2009-05-14 or 05/14/2009</td></tr>
                    <tr><td>Grade Level</td><td>Optional</td><td>7 to 12 (“Grade 7”, “G7”, and “7” all work)</td></tr>
                    <tr><td>Strand</td><td>Optional</td><td>For Grades 11 and 12, such as STEM, ABM, HUMSS</td></tr>
                    <tr><td>Section</td><td>Optional</td><td></td></tr>
                    <tr><td>Contact No</td><td>Optional</td><td></td></tr>
                    <tr><td>Email</td><td>Optional</td><td></td></tr>
                    <tr><td>Address</td><td>Optional</td><td></td></tr>
                    <tr><td>Guardian Name</td><td>Optional</td><td></td></tr>
                    <tr><td>Guardian Contact</td><td>Optional</td><td></td></tr>
                </tbody>
            </table>
        </div>
        <p class="muted small">Similar column names also work, such as Surname, LRN, Gender, Birthday, Grade, or Parent/Guardian.</p>

        <h2>2. Save it as a CSV file</h2>
        <p>In <strong>Excel</strong>: click <strong>File</strong>, then <strong>Save As</strong>, and choose <strong>CSV UTF-8 (Comma delimited) (*.csv)</strong> as the file type.<br>
           In <strong>Google Sheets</strong>: click <strong>File</strong>, then <strong>Download</strong>, then <strong>Comma-separated values (.csv)</strong>.</p>
    </section>

    <form method="post" enctype="multipart/form-data" class="panel form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="preview">
        <fieldset>
            <legend>3. Upload the file</legend>
            <label>CSV file
                <input type="file" name="file" accept=".csv,text/csv" required>
            </label>
        </fieldset>
        <fieldset>
            <legend>If a student number is already in the system</legend>
            <div class="radio-list">
                <label class="check"><input type="radio" name="on_duplicate" value="skip" checked> Skip that student and keep the record as it is</label>
                <label class="check"><input type="radio" name="on_duplicate" value="update"> Update the record with the details in the file</label>
            </div>
        </fieldset>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('upload') ?>Check file</button>
            <a class="btn btn-quiet" href="students.php">Cancel</a>
        </div>
    </form>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
