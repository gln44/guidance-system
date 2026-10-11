<?php
// Shared layout for printable pages (student lists, session lists).
// The page opens the print dialog by itself; the toolbar is hidden on paper.

function print_start(string $title, array $details, string $backUrl, bool $landscape = false): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?= viewport_meta() ?>
    <title><?= e($title) ?> | <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(LOGO_PATH) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/print.css">
    <style>@page { size: <?= $landscape ? 'landscape' : 'portrait' ?>; margin: 12mm; }</style>
</head>
<body data-autoprint>
<div class="print-toolbar">
    <a class="btn" href="<?= e($backUrl) ?>"><?= icon('arrow-left') ?>Back</a>
    <button type="button" class="btn btn-primary" data-print><?= icon('printer') ?>Print</button>
    <span>This is how the printout will look. <?= $landscape ? 'It prints sideways (landscape) to fit the columns.' : '' ?></span>
</div>
<main class="sheet">
    <header class="letterhead">
        <img class="logo" src="<?= e(LOGO_PATH) ?>" alt="">
        <div>
            <strong><?= e(SCHOOL_NAME) ?></strong>
            <span><?= e(APP_NAME) ?></span>
        </div>
        <p class="confidential-mark">Confidential</p>
    </header>
    <h1><?= e($title) ?></h1>
    <p class="print-details"><?= implode(' <span aria-hidden="true">|</span> ', array_map('e', array_filter($details))) ?></p>
    <?php
}

// $type is the kind of printout, e.g. 'student_list' (see print_types in bootstrap.php)
function print_end(string $type): void
{
    ?>
    <?= signatories('Prepared by', current_user()['full_name'], $type) ?>
    <footer class="print-footer">Confidential. For guidance office use only.</footer>
</main>
<script src="assets/js/app.js"></script>
</body>
</html>
    <?php
}
