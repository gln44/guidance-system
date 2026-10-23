<?php
// Student photos (2x2 ID photos).
// Photos are personal information, so they are stored in uploads/student-photos/, which the
// web server is told never to serve directly. They are only shown through student_photo.php,
// which requires a logged-in counselor.

const PHOTO_MAX_BYTES  = 5 * 1024 * 1024;  // 5 MB
const PHOTO_MAX_PIXELS = 25000000;         // about a 25-megapixel picture
const PHOTO_SIZE       = 600;              // saved photos are at most 600 x 600 pixels

// Adds the "photo" column to the students table the first time it's needed,
// so existing installations don't have to change the database by hand.
function ensure_photo_column(): void
{
    static $done = false;
    if ($done || !empty($_SESSION['photo_column_ok'])) {
        $done = true;
        return;
    }
    $exists = db()->query("SHOW COLUMNS FROM students LIKE 'photo'")->fetch();
    if (!$exists) {
        db()->exec('ALTER TABLE students ADD COLUMN photo VARCHAR(100) NULL AFTER guardian_contact');
    }
    $_SESSION['photo_column_ok'] = true;
    $done = true;
}

// The private folder for photos (created, with its "do not serve" rule, if missing)
function photo_dir(): string
{
    $uploads = __DIR__ . '/../uploads';
    $dir = $uploads . '/student-photos';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $rule = $uploads . '/.htaccess';
    if (!is_file($rule)) {
        file_put_contents($rule, "# Student photos are private. They are only shown through student_photo.php after login.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

// Saves an uploaded photo. Returns the stored file name, or null if no photo was chosen.
// On a problem, returns null and sets $error to a message for the counselor.
function save_student_photo(?array $file, string &$error): ?string
{
    $error = '';
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > PHOTO_MAX_BYTES) {
        $error = 'The photo is too large. Choose a photo under 5 MB.';
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $error = 'The photo did not upload. Try choosing it again.';
        return null;
    }

    // Check that it really is a picture (not just a file renamed to .jpg)
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        $error = 'The photo must be a JPG, PNG, or WebP picture.';
        return null;
    }
    [$width, $height, $type] = $info;
    if ($width * $height > PHOTO_MAX_PIXELS) {
        $error = 'That picture is too big to process. Use a smaller photo, such as a phone photo or a scanned 2x2.';
        return null;
    }

    $dir  = photo_dir();
    $name = bin2hex(random_bytes(16));

    $canResize = function_exists('imagecreatetruecolor')
        && ($type !== IMAGETYPE_WEBP || function_exists('imagecreatefromwebp'));

    if ($canResize) {
        $src = null;
        if ($type === IMAGETYPE_JPEG) {
            $src = @imagecreatefromjpeg($file['tmp_name']);
        } elseif ($type === IMAGETYPE_PNG) {
            $src = @imagecreatefrompng($file['tmp_name']);
        } else {
            $src = @imagecreatefromwebp($file['tmp_name']);
        }
        if (!$src) {
            $error = 'That photo could not be read. Try a different picture.';
            return null;
        }

        // Phone photos are often stored sideways with a note saying which way is up
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file['tmp_name']);
            $turn = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($turn) {
                $rotated = imagerotate($src, $turn, 0);
                if ($rotated) {
                    imagedestroy($src);
                    $src = $rotated;
                }
            }
        }

        // Crop a square like a 2x2 ID photo: centered side to side, and a little nearer
        // the top on tall pictures so the head isn't cut off
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $x = (int) (($w - $side) / 2);
        $y = (int) (($h - $side) * 0.3);
        $size = min(PHOTO_SIZE, $side);

        $dst = imagecreatetruecolor($size, $size);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // white behind see-through PNGs
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $size, $size, $side, $side);

        // Saving as a new JPEG also drops hidden data such as where the photo was taken
        $saved = imagejpeg($dst, "$dir/$name.jpg", 88);
        imagedestroy($src);
        imagedestroy($dst);
        if (!$saved) {
            $error = 'The photo could not be saved. Check that the uploads folder can be written to.';
            return null;
        }
        return "$name.jpg";
    }

    // Without PHP's image tools (GD), keep the original picture; it is still shown as a square
    $file_name = $name . '.' . $types[$type];
    if (!move_uploaded_file($file['tmp_name'], "$dir/$file_name")) {
        $error = 'The photo could not be saved. Check that the uploads folder can be written to.';
        return null;
    }
    return $file_name;
}

function delete_student_photo_file(?string $fileName): void
{
    if ($fileName) {
        $path = photo_dir() . '/' . basename($fileName);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

// The square photo, or the student's initials when there is no photo.
// $class adds a size: 'photo-sm' (list), 'photo-md' (session page), or '' for the full 2x2.
function student_photo(array $student, string $class = ''): string
{
    $name = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    if (!empty($student['photo']) && !empty($student['id'] ?? $student['student_id'] ?? null)) {
        $id = (int) ($student['student_id'] ?? $student['id']);
        $src = 'student_photo.php?id=' . $id . '&v=' . substr(md5($student['photo']), 0, 8);
        return '<img class="photo-2x2 ' . e($class) . '" src="' . e($src) . '" alt="Photo of ' . e($name) . '">';
    }
    $initials = strtoupper(text_cut((string) ($student['first_name'] ?? ''), 0, 1) . text_cut((string) ($student['last_name'] ?? ''), 0, 1));
    return '<span class="photo-2x2 photo-empty ' . e($class) . '" aria-hidden="true">' . e($initials) . '</span>';
}
