<?php
// Signatures printed at the bottom of printouts: who signs, and on which printouts.
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $limits = ['heading' => 40, 'name' => 100, 'title' => 80];
    $values = [];
    $tooLong = false;
    foreach ([1, 2, 3] as $n) {
        foreach ($limits as $part => $max) {
            $value = trim(preg_replace('/\s+/', ' ', (string) ($_POST["sig{$n}_{$part}"] ?? '')));
            if (text_length($value) > $max) {
                $tooLong = true;
            }
            $values["sig{$n}_{$part}"] = $value;
        }
    }
    if ($tooLong) {
        flash('error', 'Headings can be up to 40 characters, names up to 100, and titles up to 80.');
    } else {
        foreach ($values as $key => $value) {
            save_setting($key, $value);
        }
        // Which signatures go on each kind of printout (an unticked row means no signatures)
        $use = is_array($_POST['use'] ?? null) ? $_POST['use'] : [];
        foreach (array_keys(print_types()) as $type) {
            $ticked = is_array($use[$type] ?? null) ? array_map('intval', $use[$type]) : [];
            save_setting('sigs_' . $type, implode(',', array_values(array_intersect([1, 2, 3], $ticked))));
        }
        flash('success', 'Printout signatures saved.');
    }
    redirect('signatures.php');
}

$pageTitle = 'Printout signatures';
$activeNav = 'counselors';
require __DIR__ . '/includes/header.php';
?>

<header class="page-head">
    <a class="back" href="counselors.php"><?= icon('arrow-left') ?>Counselor accounts</a>
    <h1>Printout signatures</h1>
    <p class="muted">Set who signs at the bottom of printouts, and choose which signatures go on each kind of printout.</p>
</header>

<?php $sigs = signature_settings(); ?>
<section class="panel form" id="signatures">
    <p class="muted small">Up to three signature lines are printed at the bottom of every printout. Signature 2 and 3 are printed only when they have a name.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="signatures">
        <div class="sig-grid" role="group" aria-label="Signatures">
            <span></span>
            <span class="sig-col">Heading above the line</span>
            <span class="sig-col">Printed name</span>
            <span class="sig-col">Title under the name</span>

            <?php foreach ($sigs as $n => $sig): ?>
                <span class="sig-row-label">
                    Signature <?= $n ?>
                    <?php if ($n === 1): ?><small class="muted">the counselor</small><?php endif; ?>
                </span>
                <label><span class="sr-only">Signature <?= $n ?> heading</span>
                    <input type="text" name="sig<?= $n ?>_heading" value="<?= e($sig['heading']) ?>" maxlength="40"
                           placeholder="<?= $n === 1 ? 'Automatic' : ($n === 2 ? 'e.g. Noted by' : 'e.g. Approved by') ?>">
                </label>
                <label><span class="sr-only">Signature <?= $n ?> name</span>
                    <input type="text" name="sig<?= $n ?>_name" value="<?= e($sig['name']) ?>" maxlength="100"
                           placeholder="<?= $n === 1 ? 'Automatic (the counselor)' : 'Leave blank to leave it out' ?>">
                </label>
                <label><span class="sr-only">Signature <?= $n ?> title</span>
                    <input type="text" name="sig<?= $n ?>_title" value="<?= e($sig['title']) ?>" maxlength="80"
                           placeholder="<?= $n === 1 ? 'e.g. Guidance Counselor' : ($n === 2 ? 'e.g. Guidance Coordinator' : 'e.g. School Principal') ?>">
                </label>
            <?php endforeach; ?>
        </div>
        <p class="hint">For signature 1, leave the heading or name blank to fill them in automatically: a session record is signed by the counselor who conducted the session (“Counseled by”), and lists and student records by the counselor who prints them (“Prepared by”). Type a name there only if the same person should sign every printout.</p>

        <h3 class="sig-subhead">Which signatures go on each printout</h3>
        <div class="table-wrap">
            <table class="sig-matrix">
                <thead>
                    <tr>
                        <th>Printout</th>
                        <?php foreach ($sigs as $n => $sig): ?>
                            <th>
                                Signature <?= $n ?>
                                <span class="sig-who">
                                    <?php if ($n === 1): ?>
                                        <?= e(trim($sig['name']) !== '' ? $sig['name'] : 'The counselor') ?>
                                    <?php elseif (trim($sig['name']) !== ''): ?>
                                        <?= e(trim($sig['heading']) !== '' ? rtrim($sig['heading'], ':') . ': ' : '') ?><?= e($sig['name']) ?>
                                    <?php else: ?>
                                        No name yet, so it won’t print
                                    <?php endif; ?>
                                </span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (print_types() as $type => [$label, $where]): $chosen = signatures_for($type); ?>
                        <tr>
                            <td><strong><?= e($label) ?></strong><span class="sig-who"><?= e($where) ?></span></td>
                            <?php foreach ([1, 2, 3] as $n): ?>
                                <td class="sig-check">
                                    <input type="checkbox" name="use[<?= e($type) ?>][]" value="<?= $n ?>" <?= in_array($n, $chosen, true) ? 'checked' : '' ?>
                                           aria-label="Signature <?= $n ?> on <?= e($label) ?>">
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="hint">Untick every box in a row to print that kind of printout with no signatures.</p>

        <div class="sig-preview">
            <span class="sig-col">How the signatures look (all three, as on a list you print)</span>
            <?= signatories('Prepared by', current_user()['full_name']) ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('save') ?>Save signatures</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
