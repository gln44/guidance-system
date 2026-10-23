<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$fields = ['student_no', 'last_name', 'first_name', 'middle_name', 'sex', 'birthdate', 'course', 'year_level',
           'section', 'contact_no', 'email', 'address', 'guardian_name', 'guardian_contact'];

$id = (int) ($_GET['id'] ?? 0);
$student = array_fill_keys($fields, '') + ['id' => 0, 'photo' => null];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM students WHERE id = ?');
    $stmt->execute([$id]);
    $student = $stmt->fetch();
    if (!$student) {
        flash('error', 'That student record does not exist.');
        redirect('students.php');
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($fields as $f) {
        $student[$f] = trim($_POST[$f] ?? '');
    }

    if ($student['student_no'] === '') $errors[] = 'Enter the student number.';
    if ($student['last_name'] === '')  $errors[] = 'Enter the last name.';
    if ($student['first_name'] === '') $errors[] = 'Enter the first name.';
    if ($student['birthdate'] !== '' && !valid_date($student['birthdate'])) $errors[] = 'Enter a valid birthdate.';
    if ($student['email'] !== '' && !filter_var($student['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address, or leave it blank.';

    // Student number must be unique
    $stmt = db()->prepare('SELECT id FROM students WHERE student_no = ? AND id <> ?');
    $stmt->execute([$student['student_no'], $id]);
    if ($stmt->fetch()) $errors[] = 'Another student already has student number ' . $student['student_no'] . '.';

    // Photo: a new one replaces the old one; "Remove photo" clears it
    $newPhoto = null;
    $choseAPhoto = ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!$errors && $choseAPhoto) {
        $photoError = '';
        $newPhoto = save_student_photo($_FILES['photo'], $photoError);
        if ($photoError !== '') {
            $errors[] = $photoError;
        }
    } elseif ($errors && $choseAPhoto) {
        $errors[] = 'Choose the photo again after fixing the other problems.';
    }

    if (!$errors) {
        $values = [];
        foreach ($fields as $f) {
            $values[] = $student[$f] === '' ? null : $student[$f]; // store blanks as NULL
        }

        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = ?", $fields));
            $values[] = $id;
            db()->prepare("UPDATE students SET $set WHERE id = ?")->execute($values);
            flash('success', 'Student record updated.');
            $oldPhoto = $student['photo'] ?? null;
            if ($newPhoto !== null || !empty($_POST['remove_photo'])) {
                db()->prepare('UPDATE students SET photo = ? WHERE id = ?')->execute([$newPhoto, $id]);
                if ($oldPhoto && $oldPhoto !== $newPhoto) {
                    delete_student_photo_file($oldPhoto);
                }
            }
        } else {
            $cols = implode(', ', $fields);
            $qs   = implode(', ', array_fill(0, count($fields), '?'));
            db()->prepare("INSERT INTO students ($cols) VALUES ($qs)")->execute($values);
            $id = (int) db()->lastInsertId();
            if ($newPhoto !== null) {
                db()->prepare('UPDATE students SET photo = ? WHERE id = ?')->execute([$newPhoto, $id]);
            }
            flash('success', 'Student added.');
        }
        redirect('student_view.php?id=' . $id);
    }
}

// Sections set for each grade, for the Section dropdown
$sectionsByGrade = section_names_by_grade();
$gradeSections = $sectionsByGrade[$student['year_level']] ?? [];
$useSelect = (bool) $gradeSections;
$currentSection = (string) $student['section'];
$sectionOnList = $currentSection === '' || in_array(lower($currentSection), array_map('lower', $gradeSections), true);

$pageTitle = $id ? 'Edit student' : 'Add student';
$activeNav = 'students';
require __DIR__ . '/includes/header.php';
?>

<header class="page-head">
    <a class="back" href="<?= $id ? 'student_view.php?id=' . $id : 'students.php' ?>"><?= icon('arrow-left') ?>Back</a>
    <h1><?= e($pageTitle) ?></h1>
</header>

<?php foreach ($errors as $err): ?>
    <div class="flash flash-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" class="panel form" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <fieldset>
        <legend>Photo</legend>
        <div class="photo-field">
            <div class="photo-preview" id="photo-preview">
                <?= student_photo($student) ?>
            </div>
            <div class="photo-controls">
                <label>2x2 photo
                    <input type="file" name="photo" id="photo-input" accept="image/jpeg,image/png,image/webp">
                    <span class="hint">A JPG, PNG, or WebP picture. It is cropped to a square like a 2x2 ID photo and made smaller before uploading. On a phone you can take one with the camera.</span>
                </label>
                <?php if (!empty($student['photo'])): ?>
                    <label class="check"><input type="checkbox" name="remove_photo" value="1" id="photo-remove"> Remove the current photo</label>
                <?php endif; ?>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>Student</legend>
        <div class="fields">
            <label>Student number <span class="req">required</span>
                <input type="text" name="student_no" value="<?= e($student['student_no']) ?>" required>
            </label>
            <label>Last name <span class="req">required</span>
                <input type="text" name="last_name" value="<?= e($student['last_name']) ?>" required>
            </label>
            <label>First name <span class="req">required</span>
                <input type="text" name="first_name" value="<?= e($student['first_name']) ?>" required>
            </label>
            <label>Middle name
                <input type="text" name="middle_name" value="<?= e($student['middle_name']) ?>">
            </label>
            <label>Sex
                <select name="sex">
                    <option value=""></option>
                    <?php foreach (['Female', 'Male'] as $opt): ?>
                        <option <?= selected($student['sex'], $opt) ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Birthdate
                <input type="date" name="birthdate" value="<?= e($student['birthdate']) ?>">
            </label>
        </div>
    </fieldset>

    <fieldset>
        <legend>Enrollment</legend>
        <div class="fields">
            <label>Grade level
                <select name="year_level" id="grade-select">
                    <option value=""></option>
                    <?php if ($student['year_level'] && !in_array($student['year_level'], YEAR_LEVELS, true)): ?>
                        <option selected><?= e($student['year_level']) ?></option>
                    <?php endif; ?>
                    <?php foreach (YEAR_LEVELS as $opt): ?>
                        <option <?= selected($student['year_level'], $opt) ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Strand
                <input type="text" name="course" value="<?= e($student['course']) ?>" placeholder="e.g. STEM, ABM, HUMSS">
                <span class="hint">For Grades 11 and 12 only.</span>
            </label>
            <label>Section
                <select name="section" id="section-select" <?= $useSelect ? '' : 'hidden disabled' ?>
                        data-sections="<?= e(json_encode($sectionsByGrade, JSON_UNESCAPED_UNICODE)) ?>">
                    <option value=""></option>
                    <?php if (!$sectionOnList): ?>
                        <option selected><?= e($currentSection) ?></option>
                    <?php endif; ?>
                    <?php foreach ($gradeSections as $name): ?>
                        <option <?= lower($name) === lower($currentSection) ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="section" id="section-text" value="<?= e($currentSection) ?>" maxlength="30" <?= $useSelect ? 'hidden disabled' : '' ?>>
                <span class="hint" id="section-hint"><?= $useSelect ? 'Sections for the chosen grade.' : ($student['year_level'] ? 'No sections set for this grade yet, so type it in.' : 'Choose the grade level first, or type the section.') ?></span>
            </label>
        </div>
    </fieldset>

    <fieldset>
        <legend>Contact</legend>
        <div class="fields">
            <label>Contact number
                <input type="tel" name="contact_no" value="<?= e($student['contact_no']) ?>">
            </label>
            <label>Email
                <input type="email" name="email" value="<?= e($student['email']) ?>">
            </label>
            <label class="span-2">Address
                <input type="text" name="address" value="<?= e($student['address']) ?>">
            </label>
            <label>Parent / guardian
                <input type="text" name="guardian_name" value="<?= e($student['guardian_name']) ?>">
            </label>
            <label>Guardian contact number
                <input type="tel" name="guardian_contact" value="<?= e($student['guardian_contact']) ?>">
            </label>
        </div>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= icon($id ? 'save' : 'user-plus') ?><?= $id ? 'Save changes' : 'Add student' ?></button>
        <a class="btn btn-quiet" href="<?= $id ? 'student_view.php?id=' . $id : 'students.php' ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
