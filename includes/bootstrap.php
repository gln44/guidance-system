<?php
// Loaded at the top of every page.
// config.php is your own copy (XAMPP or web host). Without one, as on Vercel,
// the example file is used and reads its settings from environment variables.
if (file_exists(__DIR__ . '/../config/config.php')) {
    require_once __DIR__ . '/../config/config.php';
} else {
    require_once __DIR__ . '/../config/config.example.php';
}

// ---------- Session (secure cookie settings) ----------
if (session_status() === PHP_SESSION_NONE) {
    // Vercel sits behind a proxy that tells us the visitor used HTTPS in this header
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,      // JavaScript cannot read the cookie
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    // Vercel forgets saved files between requests, so logins are kept in the database there
    if (getenv('VERCEL')) {
        require_once __DIR__ . '/db_session.php';
        session_set_save_handler(new DbSessionHandler(), true);
    }
    session_name('GCS_SESSION');
    session_start();
}

// Basic security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// ---------- Database ----------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            // Encrypted connection for online databases, checked against the system's certificates
            if (defined('DB_SSL') && DB_SSL) {
                foreach (['/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt', '/etc/ssl/cert.pem'] as $ca) {
                    if (is_readable($ca)) {
                        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                        break;
                    }
                }
            }
            // Older config.php files have no DB_PORT, so fall back to MySQL's usual port
            $port = defined('DB_PORT') ? DB_PORT : '3306';
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                $options
            );
        } catch (PDOException $ex) {
            http_response_code(500);
            echo '<p style="font-family:sans-serif;padding:2rem">Cannot connect to the database. '
               . 'Check that MySQL is running and that the settings in <code>config/config.php</code> are correct.</p>';
            exit;
        }
    }
    return $pdo;
}

// ---------- Phone: mobile view or desktop view ----------
// 'auto' follows the browser: a phone gets the mobile layout, and a phone using the browser's
// "Desktop site" option (which sends a desktop identity) gets the full desktop layout.
// 'desktop' / 'mobile' are chosen with the Desktop view / Mobile view link in the system.
function view_mode(): string
{
    $mode = $_COOKIE['view_mode'] ?? 'auto';
    return in_array($mode, ['auto', 'desktop', 'mobile'], true) ? $mode : 'auto';
}

function browser_is_mobile(): bool
{
    return (bool) preg_match('/Mobi|Android|iPhone|iPod|Opera Mini|IEMobile/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
}

function viewport_meta(): string
{
    $mode = view_mode();
    $desktop = $mode === 'desktop' || ($mode === 'auto' && !browser_is_mobile());
    // Desktop computers ignore this tag; phones in desktop view lay the page out 1280px wide
    $content = $desktop ? 'width=1280' : 'width=device-width, initial-scale=1';
    return '<meta name="viewport" content="' . $content . '">';
}

// Link to switch between mobile and desktop view, returning to the current page
function view_switch_link(): string
{
    $here = basename($_SERVER['PHP_SELF'] ?? 'index.php') . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
    if (view_mode() === 'desktop') {
        return '<a class="view-switch" href="view_mode.php?mode=mobile&amp;back=' . e(urlencode($here)) . '">' . icon('phone') . 'Switch to mobile view</a>';
    }
    return '<a class="view-switch mobile-only" href="view_mode.php?mode=desktop&amp;back=' . e(urlencode($here)) . '">' . icon('monitor') . 'Switch to desktop view</a>';
}

// ---------- Output helpers ----------

// Escape text before printing it into HTML (prevents XSS)
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function format_date(?string $date): string
{
    return $date ? date('M j, Y', strtotime($date)) : '';
}

function format_time(?string $time): string
{
    return $time ? date('g:i A', strtotime($time)) : '';
}

// UTF-8 safe substring/length (works even if the mbstring extension is off)
function text_cut(string $text, int $start, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($text, $start, $length, 'UTF-8');
    }
    preg_match_all('/./us', $text, $chars);
    return implode('', array_slice($chars[0], $start, $length));
}

function text_length(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : (int) preg_match_all('/./us', $text);
}

function student_name(array $row, bool $lastFirst = true): string
{
    $middle = !empty($row['middle_name']) ? ' ' . text_cut($row['middle_name'], 0, 1) . '.' : '';
    return $lastFirst
        ? $row['last_name'] . ', ' . $row['first_name'] . $middle
        : $row['first_name'] . $middle . ' ' . $row['last_name'];
}

// "Grade 11, STEM" (grade level and strand, whichever are filled in)
function grade_strand(array $row): string
{
    $parts = array_filter([$row['year_level'] ?? '', $row['course'] ?? ''], fn($v) => (string) $v !== '');
    return implode(', ', $parts);
}

function excerpt(?string $text, int $length = 80): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));
    return text_length($text) > $length ? rtrim(text_cut($text, 0, $length - 1)) . '…' : $text;
}

// The status written out in full, colored (used on printouts instead of the status button)
function status_text(string $status, ?string $followUpDate = null): string
{
    $meaning = [
        'Open'      => 'Still being worked on',
        'Follow-up' => 'Needs another session' . ($followUpDate ? ' on ' . format_date($followUpDate) : ''),
        'Closed'    => 'Concern resolved',
    ][$status] ?? '';
    $class = strtolower(str_replace(' ', '-', $status));
    return '<span class="status-text status-text-' . e($class) . '"><strong>' . e($status) . '</strong>'
         . ($meaning !== '' ? ': ' . e($meaning) : '') . '</span>';
}

function status_badge(string $status): string
{
    $class = strtolower(str_replace(' ', '-', $status));
    return '<span class="status status-' . e($class) . '">' . e($status) . '</span>';
}

// Returns "selected" for <option> tags when the two values match
function selected($current, $option): string
{
    return (string) $current === (string) $option ? 'selected' : '';
}

// Small colored label, e.g. for import preview rows
function status_tag(string $type, string $label): string
{
    return '<span class="status status-' . e($type) . '">' . e($label) . '</span>';
}

function valid_date(?string $date): bool
{
    if (!$date) {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

// ---------- Icons ----------
// Simple line icons drawn inline, so they work offline. Shapes based on Lucide (lucide.dev, ISC license).
function icon(string $name): string
{
    $user = '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>';
    $icons = [
        'plus'        => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'edit'        => '<path d="M12 20h9"/><path d="M16.4 3.6a2.1 2.1 0 0 1 3 3L7.4 18.6a2 2 0 0 1-.9.5l-2.9.8.8-2.9a2 2 0 0 1 .5-.9z"/>',
        'trash'       => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/>',
        'printer'     => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'search'      => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'filter'      => '<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>',
        'x'           => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'check'       => '<path d="M20 6 9 17l-5-5"/>',
        'save'        => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/>',
        'log-in'      => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
        'log-out'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'key'         => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
        'monitor'     => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
        'phone'       => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
        'user'        => '<circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 0 0-16 0"/>',
        'user-plus'   => $user . '<path d="M19 8v6"/><path d="M22 11h-6"/>',
        'user-x'      => $user . '<path d="m17 8 5 5"/><path d="m22 8-5 5"/>',
        'user-check'  => $user . '<path d="m16 11 2 2 4-4"/>',
        'upload'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        'download'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'layers'      => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
        'arrow-left'  => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'dashboard'   => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'students'    => $user . '<path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'sessions'    => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        'counselors'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . ($icons[$name] ?? '') . '</svg>';
}

// ---------- CSRF protection (for every POST form) ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('This form expired. Go back, refresh the page, and try again.');
    }
}

// ---------- Authentication ----------
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

// Put this at the top of every page that needs a logged-in counselor
function require_login(): void
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }

    // Auto-logout after inactivity
    if (time() - ($_SESSION['last_activity'] ?? 0) > SESSION_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        session_start();
        flash('info', 'You were logged out after ' . (SESSION_TIMEOUT / 60) . ' minutes of inactivity.');
        redirect('login.php');
    }

    // Make sure the account still exists and is still active
    $stmt = db()->prepare('SELECT id, full_name, username, role, is_active FROM counselors WHERE id = ?');
    $stmt->execute([$user['id']]);
    $fresh = $stmt->fetch();
    if (!$fresh || !$fresh['is_active']) {
        $_SESSION = [];
        session_destroy();
        session_start();
        flash('error', 'Your account is no longer active. Contact the guidance office administrator.');
        redirect('login.php');
    }

    $_SESSION['user'] = [
        'id'        => (int) $fresh['id'],
        'full_name' => $fresh['full_name'],
        'username'  => $fresh['username'],
        'role'      => $fresh['role'],
    ];
    $_SESSION['last_activity'] = time();
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        flash('error', 'Only an administrator can open that page.');
        redirect('dashboard.php');
    }
}

// A counselor can edit or delete a session they wrote; an admin can edit any.
function can_modify_session(array $session): bool
{
    return is_admin() || (int) $session['counselor_id'] === (int) current_user()['id'];
}

// ---------- Settings (stored in the database, changed on the Counselors page) ----------

function ensure_settings_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec("
        CREATE TABLE IF NOT EXISTS settings (
            name  VARCHAR(50) NOT NULL PRIMARY KEY,
            value TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

function setting(string $name, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        ensure_settings_table();
        $cache = [];
        foreach (db()->query('SELECT name, value FROM settings')->fetchAll() as $row) {
            $cache[$row['name']] = (string) $row['value'];
        }
    }
    return array_key_exists($name, $cache) ? $cache[$name] : $default;
}

function save_setting(string $name, string $value): void
{
    ensure_settings_table();
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute([$name, $value]);
}

// The three signatures that can be set on the Counselors page.
// Each has a heading (text above the line), a name, and a title (text under the name).
// Older single "Noted by" settings carry over as signature 2.
function signature_settings(): array
{
    return [
        1 => [
            'heading' => setting('sig1_heading', ''),
            'name'    => setting('sig1_name', ''),
            'title'   => setting('sig1_title', setting('counselor_title', 'Guidance Counselor')),
        ],
        2 => [
            'heading' => setting('sig2_heading', 'Noted by'),
            'name'    => setting('sig2_name', setting('noted_by_name', '')),
            'title'   => setting('sig2_title', setting('noted_by_title', 'Guidance Coordinator')),
        ],
        3 => [
            'heading' => setting('sig3_heading', 'Approved by'),
            'name'    => setting('sig3_name', ''),
            'title'   => setting('sig3_title', ''),
        ],
    ];
}

// The kinds of printout, for choosing which signatures go on each one
function print_types(): array
{
    return [
        'student_record' => ['Student record', 'A student’s details and counseling history (Print on a student’s page)'],
        'session_record' => ['Session record', 'One counseling session (Print on a session’s page)'],
        'student_list'   => ['Student list', 'Students page, Print list'],
    ];
}

// Which signatures (1, 2, 3) are chosen for a kind of printout. All three by default.
function signatures_for(string $type): array
{
    $chosen = array_map('intval', explode(',', setting('sigs_' . $type, '1,2,3')));
    return array_values(array_intersect([1, 2, 3], $chosen));
}

// Signature lines at the bottom of printouts.
// $type is the kind of printout (see print_types); only the signatures chosen for it are printed.
// Signature 1 fills itself in when its heading or name is blank: $autoHeading is "Counseled by"
// on a session record and "Prepared by" on lists, and $autoName is that session's counselor or
// the counselor printing. Signatures 2 and 3 are printed only when they have a name.
function signatories(string $autoHeading, string $autoName, ?string $type = null): string
{
    $allowed = $type !== null ? signatures_for($type) : [1, 2, 3];
    $html = '';
    foreach (signature_settings() as $number => $sig) {
        if (!in_array($number, $allowed, true)) {
            continue;
        }
        $heading = trim($sig['heading']);
        $name    = trim($sig['name']);
        $title   = trim($sig['title']);
        if ($number === 1) {
            $heading = $heading !== '' ? $heading : $autoHeading;
            $name    = $name !== '' ? $name : $autoName;
        } elseif ($name === '') {
            continue;
        }
        if ($heading !== '' && substr($heading, -1) !== ':') {
            $heading .= ':';
        }
        $html .= '<div class="signer">'
               . '<span class="signer-label">' . ($heading !== '' ? e($heading) : '&nbsp;') . '</span>'
               . '<span class="signature-line"></span>'
               . '<strong class="signer-name">' . e($name) . '</strong>'
               . ($title !== '' ? '<span class="signer-title">' . e($title) . '</span>' : '')
               . '</div>';
    }
    return $html !== '' ? '<div class="signatories">' . $html . '</div>' : '';
}

// ---------- Grade levels and sections ----------

function lower(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

// Creates the sections table the first time it's needed, so existing
// installations don't have to import anything new.
function ensure_sections_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec("
        CREATE TABLE IF NOT EXISTS sections (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            grade_level VARCHAR(20) NOT NULL,
            name        VARCHAR(30) NOT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_grade_section (grade_level, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

// The sections set on the Sections page: ['Grade 7' => [['id' => 1, 'name' => 'Camia'], ...], ...]
function get_sections(): array
{
    ensure_sections_table();
    $byGrade = array_fill_keys(YEAR_LEVELS, []);
    foreach (db()->query('SELECT id, grade_level, name FROM sections')->fetchAll() as $row) {
        $byGrade[$row['grade_level']][] = ['id' => (int) $row['id'], 'name' => $row['name']];
    }
    foreach ($byGrade as &$list) {
        usort($list, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    }
    return $byGrade;
}

// Just the section names for each grade: ['Grade 7' => ['Camia', 'Gumamela', ...], ...]
function section_names_by_grade(): array
{
    return array_map(fn($list) => array_column($list, 'name'), get_sections());
}

// How many students are in each section: ['Grade 7' => ['camia' => ['name' => 'Camia', 'count' => 40]], ...]
function section_student_counts(): array
{
    $counts = [];
    $rows = db()->query("
        SELECT year_level, section, COUNT(*) AS total FROM students
        WHERE year_level IS NOT NULL AND section IS NOT NULL AND section <> ''
        GROUP BY year_level, section
    ")->fetchAll();
    foreach ($rows as $row) {
        $counts[$row['year_level']][lower($row['section'])] = ['name' => $row['section'], 'count' => (int) $row['total']];
    }
    return $counts;
}

// Splits a student search into a grade level (if one was typed) and the remaining words.
// "grade 7 rizal" -> ['Grade 7', ['rizal']];  "g11 stem" -> ['Grade 11', ['stem']];  "10" -> ['Grade 10', []]
function parse_student_search(string $q): array
{
    $grade = null;
    if (preg_match('/(?<![a-z0-9])(?:grade|gr\.?|g)\s*(1[0-2]|[7-9])(?![0-9])/i', $q, $m)) {
        $grade = 'Grade ' . $m[1];
        $q = str_replace($m[0], ' ', $q);
    } elseif (preg_match('/^\s*(1[0-2]|[7-9])\s*$/', $q, $m)) {
        $grade = 'Grade ' . $m[1];
        $q = '';
    }
    $words = preg_split('/[\s,]+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return [$grade, $words];
}

// Sorting for student searches: best matches first.
// Exact student number, then exact last name, then last name starting with the search,
// then full name, then first name. Everything else (for example a middle-name match) comes after.
// Returns [SQL expression, values], or ['', []] when there are no words to rank by.
// $alias is the table alias, e.g. 's'.
function student_search_rank(array $words, string $alias = ''): array
{
    $text = implode(' ', $words);
    if ($text === '') {
        return ['', []];
    }
    $a = $alias !== '' ? $alias . '.' : '';
    $sql = "(({$a}student_no = ?) * 8
           + ({$a}last_name = ?) * 6
           + ({$a}last_name LIKE ?) * 4
           + (CONCAT({$a}first_name, ' ', {$a}last_name) LIKE ?) * 3
           + (CONCAT({$a}last_name, ' ', {$a}first_name) LIKE ?) * 3
           + ({$a}first_name LIKE ?) * 2)";
    return [$sql, [$text, $text, $text . '%', '%' . $text . '%', '%' . $text . '%', $text . '%']];
}

// If a search matched only the middle name, returns the middle name so the list can show why
function matched_middle_name(array $student, array $words): string
{
    $middle = (string) ($student['middle_name'] ?? '');
    if ($middle === '' || !$words) {
        return '';
    }
    foreach ($words as $word) {
        $w = lower($word);
        if (strpos(lower($middle), $w) !== false
            && strpos(lower($student['last_name'] . ' ' . $student['first_name'] . ' ' . $student['student_no']), $w) === false) {
            return $middle;
        }
    }
    return '';
}

// ---------- Pagination ----------
function paginate(int $total, int $perPage = 20): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'limit' => $perPage];
}

function page_links(array $p): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $query = $_GET;
    $html  = '<nav class="pagination" aria-label="Pages">';
    for ($i = 1; $i <= $p['pages']; $i++) {
        $query['page'] = $i;
        $href = '?' . http_build_query($query);
        $html .= $i === $p['page']
            ? '<span aria-current="page">' . $i . '</span>'
            : '<a href="' . e($href) . '">' . $i . '</a>';
    }
    return $html . '</nav>';
}
