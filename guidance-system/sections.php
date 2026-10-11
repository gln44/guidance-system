<?php
// Set the sections for each grade level. These become the section buttons on the
// Students page and the choices in the student form.
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();
ensure_sections_table();

function back_to_grade(string $grade): void
{
    redirect('sections.php#grade-' . (int) substr($grade, 6));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $grade  = $_POST['grade'] ?? '';
    if (!in_array($grade, YEAR_LEVELS, true)) {
        flash('error', 'Choose a grade level.');
        redirect('sections.php');
    }

    $existing = array_map('lower', section_names_by_grade()[$grade] ?? []);

    if ($action === 'add' || $action === 'add_found') {
        if ($action === 'add') {
            // Several names can be added at once, separated by commas or new lines
            $names = preg_split('/[,\n\r]+/', $_POST['names'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        } else {
            $names = array_column(section_student_counts()[$grade] ?? [], 'name');
        }
        $added = [];
        $problems = [];
        foreach ($names as $name) {
            $name = trim(preg_replace('/\s+/', ' ', $name));
            if ($name === '') {
                continue;
            }
            if (text_length($name) > 30) {
                $problems[] = '“' . $name . '” is longer than 30 characters.';
            } elseif (in_array(lower($name), $existing, true)) {
                continue; // already on the list
            } else {
                db()->prepare('INSERT INTO sections (grade_level, name) VALUES (?, ?)')->execute([$grade, $name]);
                $existing[] = lower($name);
                $added[] = $name;
            }
        }
        foreach ($problems as $problem) {
            flash('error', $problem);
        }
        if ($added) {
            flash('success', $grade . ': added ' . implode(', ', $added) . '.');
        } elseif (!$problems) {
            flash('info', 'Those sections are already on the ' . $grade . ' list.');
        }
        back_to_grade($grade);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM sections WHERE id = ? AND grade_level = ?');
    $stmt->execute([$id, $grade]);
    $current = $stmt->fetch();
    if (!$current) {
        flash('error', 'That section no longer exists.');
        back_to_grade($grade);
    }

    if ($action === 'rename') {
        $newName = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? ''));
        $others = array_values(array_diff($existing, [lower($current['name'])]));
        if ($newName === '') {
            flash('error', 'Type the new section name.');
        } elseif (text_length($newName) > 30) {
            flash('error', 'Section names can be up to 30 characters.');
        } elseif (in_array(lower($newName), $others, true)) {
            flash('error', $grade . ' already has a section named ' . $newName . '.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE sections SET name = ? WHERE id = ?')->execute([$newName, $id]);
            // Move the students in the old section to the new name
            $move = $pdo->prepare('UPDATE students SET section = ? WHERE year_level = ? AND section = ?');
            $move->execute([$newName, $grade, $current['name']]);
            $pdo->commit();
            $moved = $move->rowCount();
            flash('success', 'Renamed ' . $current['name'] . ' to ' . $newName . ($moved ? " and updated $moved student" . ($moved === 1 ? '' : 's') : '') . '.');
        }
        back_to_grade($grade);
    }

    if ($action === 'delete') {
        $stmt = db()->prepare('SELECT COUNT(*) FROM students WHERE year_level = ? AND section = ?');
        $stmt->execute([$grade, $current['name']]);
        $inSection = (int) $stmt->fetchColumn();
        if ($inSection > 0) {
            flash('error', $current['name'] . ' still has ' . $inSection . ' student' . ($inSection === 1 ? '' : 's')
                . '. Rename the section instead, or move those students to another section first.');
        } else {
            db()->prepare('DELETE FROM sections WHERE id = ?')->execute([$id]);
            flash('success', 'Removed ' . $current['name'] . ' from ' . $grade . '.');
        }
        back_to_grade($grade);
    }

    redirect('sections.php');
}

$sections = get_sections();
$counts = section_student_counts();

$pageTitle = 'Sections';
$activeNav = 'students';
require __DIR__ . '/includes/header.php';
?>

<header class="page-head">
    <a class="back" href="students.php"><?= icon('arrow-left') ?>Students</a>
    <h1>Sections</h1>
    <p class="muted">Set the sections for each grade level. They appear as buttons on the Students page and as choices when adding or editing a student.</p>
</header>

<div class="section-grid">
<?php foreach (YEAR_LEVELS as $level):
    $n = (int) substr($level, 6);
    $list = $sections[$level] ?? [];
    $used = $counts[$level] ?? [];
    $listed = array_map(fn($s) => lower($s['name']), $list);
    $notListed = array_values(array_filter($used, fn($u) => !in_array(lower($u['name']), $listed, true)));
?>
    <section class="panel section-card" id="grade-<?= $n ?>">
        <div class="panel-head">
            <h2><a href="students.php?grade=<?= $n ?>"><?= e($level) ?></a></h2>
            <span class="muted small"><?= count($list) ?> section<?= count($list) === 1 ? '' : 's' ?></span>
        </div>

        <?php if ($list): ?>
            <ul class="section-list">
                <?php foreach ($list as $s): $count = $used[lower($s['name'])]['count'] ?? 0; ?>
                    <li>
                        <div class="section-name">
                            <a href="students.php?grade=<?= $n ?>&amp;section=<?= e(urlencode($s['name'])) ?>"><strong><?= e($s['name']) ?></strong></a>
                            <span class="muted small"><?= $count ?> student<?= $count === 1 ? '' : 's' ?></span>
                        </div>
                        <div class="section-actions">
                            <details class="reset">
                                <summary><?= icon('edit') ?>Rename</summary>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="grade" value="<?= e($level) ?>">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <label><span class="sr-only">New name for <?= e($s['name']) ?></span>
                                        <input type="text" name="name" value="<?= e($s['name']) ?>" maxlength="30" required>
                                    </label>
                                    <button type="submit" class="btn btn-small"><?= icon('check') ?>Save</button>
                                </form>
                            </details>
                            <form method="post" class="inline" data-confirm="Remove <?= e($s['name']) ?> from <?= e($level) ?>?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="grade" value="<?= e($level) ?>">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="link-button danger-link"><?= icon('trash') ?>Remove</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="empty">No sections yet.</p>
        <?php endif; ?>

        <?php if ($notListed): ?>
            <div class="found-sections">
                <p class="small">Found on student records but not on this list:
                    <?= e(implode(', ', array_map(fn($u) => $u['name'] . ' (' . $u['count'] . ')', $notListed))) ?></p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_found">
                    <input type="hidden" name="grade" value="<?= e($level) ?>">
                    <button type="submit" class="btn btn-small"><?= icon('plus') ?>Add <?= count($notListed) === 1 ? 'it' : 'all ' . count($notListed) ?> to the list</button>
                </form>
            </div>
        <?php endif; ?>

        <form method="post" class="add-section">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="grade" value="<?= e($level) ?>">
            <label>Add sections
                <span class="hint">Separate several names with commas.</span>
                <input type="text" name="names" placeholder="<?= $n >= 11 ? 'e.g. STEM-A, STEM-B, ABM-A' : 'e.g. Sampaguita, Rosal, Camia' ?>" required>
            </label>
            <button type="submit" class="btn"><?= icon('plus') ?>Add</button>
        </form>
    </section>
<?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
