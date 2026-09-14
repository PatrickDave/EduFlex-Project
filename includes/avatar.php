<?php
/**
 * EduFlex — profile pictures.
 *
 * A learner may upload one image to represent themselves. That is the whole
 * feature, and it is the only place in EduFlex where a learner's own file is
 * displayed back to them in a browser, which is why this file is more careful
 * than its size suggests.
 *
 * Three rules, all asserted in tests/avatar_test.php:
 *
 *   1. The file type is decided by reading the image, never by trusting the
 *      filename. getimagesize() parses the header and reports what the bytes
 *      actually are; a .php renamed to .png never gets past it.
 *   2. The stored name is generated. Nothing the learner's computer supplied
 *      reaches the filesystem, and the extension comes from the detected type.
 *   3. The file lands in storage/, which storage/.htaccess denies Apache from
 *      serving or executing at all. It reaches a page only through
 *      app/actions/avatar_show.php, which sends an explicit Content-Type taken
 *      from the detected type, plus nosniff.
 *
 * Deliberately NOT re-encoded. Re-encoding through GD would strip anything
 * hidden alongside the image data, which is the stronger treatment, but the GD
 * extension is not enabled on the development machine and shipping an untested
 * branch that runs the moment somebody enables it is the worse trade. The three
 * rules above are what stands in for it, and re-encoding is recorded as
 * optional future hardening in docs/week7-hardening.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

/** Where profile pictures are written. Under storage/, so Apache denies it. */
function avatar_dir(): string
{
    return __DIR__ . '/../storage/avatars';
}

/** 3 MB. A profile picture has no reason to be larger. */
const AVATAR_MAX_BYTES = 3 * 1024 * 1024;

/**
 * The image types accepted, and the extension each is stored with.
 *
 * The extension is taken from this map, never from the uploaded filename, so
 * "portrait.png.php" is stored as a .png and nothing else.
 */
const AVATAR_ALLOWED = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
];

/**
 * Dimension limits. The upper bound is not about disk space: a small file can
 * decode to an enormous bitmap, and anything that later opens the image pays
 * for that in memory.
 */
const AVATAR_MIN_DIMENSION = 32;
const AVATAR_MAX_DIMENSION = 4000;

/* -------------------------------------------------------------------------
   Storing
   ------------------------------------------------------------------------- */

/**
 * Validate and store one uploaded profile picture.
 *
 * Replaces whatever the learner had before, and deletes the old file, so the
 * directory holds at most one image per learner.
 *
 * @param array<string,mixed> $file one entry from $_FILES
 * @return array{ok:bool, error:?string, path:?string}
 */
function avatar_store(int $userId, array $file): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'error' => $m, 'path' => null];

    if ($userId <= 0) {
        return $fail('That account could not be identified.');
    }

    // PHP-level upload errors first; tmp_name is unusable in these cases.
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code !== UPLOAD_ERR_OK) {
        return $fail(match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'That image is larger than the server allows. Maximum is 3 MB.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose an image first.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                'The server could not save the image. Check folder permissions.',
            default => 'The upload failed.',
        });
    }

    $tmp = (string) ($file['tmp_name'] ?? '');

    /* Confirms the file really arrived through an HTTP upload rather than being
       an arbitrary server path supplied by a crafted request. Skipped only
       under the CLI, where the test suite stages a file directly and there is
       no upload for PHP to have registered. */
    if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
        return $fail('That upload could not be verified.');
    }

    if (!is_file($tmp) || !is_readable($tmp)) {
        return $fail('The uploaded image could not be read.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        $size = (int) filesize($tmp);
    }
    if ($size <= 0) {
        return $fail('That image is empty.');
    }
    if ($size > AVATAR_MAX_BYTES) {
        return $fail('That image is larger than 3 MB.');
    }

    /* The check that matters. getimagesize() reads the header and reports what
       the bytes are, so the decision never depends on the filename or on the
       Content-Type the browser claimed. It returns false for anything it does
       not recognise as an image, which is the whole of the rejection for a
       script with an image extension. */
    $info = @getimagesize($tmp);
    if ($info === false || !isset($info[2])) {
        return $fail('That file is not an image EduFlex can read. Use a JPEG, PNG, GIF or WebP.');
    }

    [$width, $height] = [(int) $info[0], (int) $info[1]];
    $type = (int) $info[2];

    if (!isset(AVATAR_ALLOWED[$type])) {
        return $fail('EduFlex accepts JPEG, PNG, GIF and WebP images.');
    }

    /* getimagesize() is not enough on its own, and this is worth knowing.
       Handed a file that begins with the 8-byte PNG signature and continues
       with arbitrary bytes, it reports a perfectly good PNG and reads its
       "dimensions" out of whatever followed the signature. A PHP script with a
       PNG signature glued to the front came back as image/png at
       1752113186 by 1885436268 pixels. The dimension bounds below happened to
       catch that one, but only because those particular bytes decoded to absurd
       numbers; four chosen bytes would have produced a plausible size and
       walked straight through.

       So the header is parsed properly as well. For a PNG that means checking
       the IHDR chunk's own CRC, which no payload can satisfy by accident. */
    if (!avatar_header_is_intact($tmp, $type)) {
        return $fail('That file claims to be an image but its contents are not one. '
                   . 'Re-save it from an image editor and try again.');
    }
    if ($width < AVATAR_MIN_DIMENSION || $height < AVATAR_MIN_DIMENSION) {
        return $fail('That image is too small. It must be at least '
                   . AVATAR_MIN_DIMENSION . ' pixels on each side.');
    }
    if ($width > AVATAR_MAX_DIMENSION || $height > AVATAR_MAX_DIMENSION) {
        return $fail('That image is too large. Keep it under '
                   . number_format(AVATAR_MAX_DIMENSION) . ' pixels on each side.');
    }

    $dir = avatar_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return $fail('The storage folder could not be created.');
    }

    // Generated name: unpredictable, no user input, extension from the
    // detected type rather than from whatever was uploaded.
    $storedName = sprintf('%d_%s.%s', $userId, bin2hex(random_bytes(12)), AVATAR_ALLOWED[$type]);
    $storedPath = $dir . '/' . $storedName;

    $moved = PHP_SAPI === 'cli'
        ? @copy($tmp, $storedPath)
        : @move_uploaded_file($tmp, $storedPath);

    if (!$moved) {
        return $fail('The image could not be saved.');
    }
    @chmod($storedPath, 0644);

    $relative = 'storage/avatars/' . $storedName;
    $previous = avatar_relative_path($userId);

    try {
        $stmt = db()->prepare('UPDATE user SET avatar_path = ? WHERE user_id = ?');
        $stmt->execute([$relative, $userId]);
    } catch (Throwable $e) {
        @unlink($storedPath);   // do not leave an orphan behind
        error_log('EduFlex avatar update failed: ' . $e->getMessage());
        return $fail('Your picture could not be saved. Try again.');
    }

    avatar_forget($userId);

    // Only once the new one is recorded, so a failure above leaves the old
    // picture intact rather than leaving the learner with neither.
    if ($previous !== null && $previous !== $relative) {
        avatar_unlink($previous);
    }

    return ['ok' => true, 'error' => null, 'path' => $relative];
}

/**
 * Remove a learner's profile picture, from the database and from disk.
 *
 * @return bool false when there was nothing to remove
 */
function avatar_remove(int $userId): bool
{
    $existing = avatar_relative_path($userId);
    if ($existing === null) {
        return false;
    }

    try {
        $stmt = db()->prepare('UPDATE user SET avatar_path = NULL WHERE user_id = ?');
        $stmt->execute([$userId]);
    } catch (Throwable $e) {
        error_log('EduFlex avatar removal failed: ' . $e->getMessage());
        return false;
    }

    avatar_forget($userId);
    avatar_unlink($existing);
    return true;
}

/**
 * Delete one avatar file, refusing any path that is not inside the avatar
 * directory.
 *
 * The stored value is generated by this file and never comes from a learner, so
 * this is belt and braces. It is here because the consequence of being wrong is
 * deleting an arbitrary file on the server, and a later change might one day
 * make the path less trustworthy than it is today.
 */
function avatar_unlink(string $relativePath): void
{
    if (!str_starts_with($relativePath, 'storage/avatars/')
        || str_contains($relativePath, '..')) {
        error_log('EduFlex refused to delete an avatar outside its directory: ' . $relativePath);
        return;
    }

    $absolute = __DIR__ . '/../' . $relativePath;
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

/**
 * Parse enough of the file to be sure it is the image type it claims.
 *
 * getimagesize() trusts a magic signature and then reads numbers out of the
 * bytes behind it, so it can be satisfied by a file that is not an image at
 * all. This looks at structure that a real encoder produces and a forgery does
 * not: for PNG the IHDR chunk carries its own CRC32, and for the other formats
 * the file has to end the way that format ends.
 *
 * Not a substitute for the rest of the handling. The file is still stored where
 * Apache will not serve it and streamed back with an explicit Content-Type. This
 * is the check that makes "the type is decided by reading the image" true rather
 * than nearly true.
 */
function avatar_header_is_intact(string $path, int $type): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }

    $head = (string) fread($handle, 33);

    // The last bytes, for the formats whose terminator is the telling part.
    $tail = '';
    if (@fseek($handle, -4, SEEK_END) === 0) {
        $tail = (string) fread($handle, 4);
    }
    fclose($handle);

    switch ($type) {
        case IMAGETYPE_PNG:
            /* Signature, then a length-13 "IHDR" chunk whose 4-byte CRC covers
               the chunk type and the 13 bytes of header. A payload pasted behind
               the signature cannot produce a matching CRC. */
            if (strlen($head) < 33 || substr($head, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                return false;
            }
            if (substr($head, 8, 8) !== "\x00\x00\x00\x0dIHDR") {
                return false;
            }
            $declared = unpack('N', substr($head, 29, 4));
            return $declared !== false
                && $declared[1] === crc32(substr($head, 12, 17));

        case IMAGETYPE_JPEG:
            // Start of Image, and End of Image at the very end.
            return str_starts_with($head, "\xFF\xD8\xFF")
                && str_ends_with($tail, "\xFF\xD9");

        case IMAGETYPE_GIF:
            // Header, then the trailer byte that closes every GIF.
            return (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a'))
                && str_ends_with($tail, "\x3B");

        case IMAGETYPE_WEBP:
            // A RIFF container whose form type is WEBP, and whose declared size
            // matches the file rather than being whatever followed the magic.
            if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') {
                return false;
            }
            $riffSize = unpack('V', substr($head, 4, 4));
            return $riffSize !== false
                && $riffSize[1] === (int) filesize($path) - 8;

        default:
            return false;
    }
}

/* -------------------------------------------------------------------------
   Reading
   ------------------------------------------------------------------------- */

/**
 * The per-request lookup cache, by reference so avatar_forget() can clear it.
 *
 * A `static` inside avatar_relative_path() would be unreachable from anywhere
 * else, and the two functions that change an avatar have to be able to
 * invalidate it.
 *
 * @return array<int,?string>
 */
function &avatar_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * The stored path for one learner, or null when they have not set a picture.
 *
 * A row whose file has gone missing reports null, so a deleted file shows the
 * initials fallback rather than a broken image.
 */
function avatar_relative_path(int $userId): ?string
{
    /* Settings renders the avatar three times and the topbar once, so without
       this the same primary-key lookup runs four times on one page render.
       Cleared by avatar_store() and avatar_remove() so an upload is visible
       immediately on the page that follows it. */
    $cache = &avatar_cache();
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    try {
        $stmt = db()->prepare('SELECT avatar_path FROM user WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $path = $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('EduFlex avatar lookup failed: ' . $e->getMessage());
        return null;
    }

    if ($path === false || $path === null || trim((string) $path) === '') {
        return $cache[$userId] = null;
    }

    $path = (string) $path;
    if (!is_file(__DIR__ . '/../' . $path)) {
        return $cache[$userId] = null;
    }

    return $cache[$userId] = $path;
}

/**
 * Forget the cached lookup for one learner, or for all of them.
 *
 * Called by avatar_store() and avatar_remove() so the next read sees the change
 * they just made. The test suite needs it too, because it rewrites rows
 * underneath the cache.
 */
function avatar_forget(?int $userId = null): void
{
    $cache = &avatar_cache();

    if ($userId === null) {
        $cache = [];
        return;
    }
    unset($cache[$userId]);
}

function avatar_has(int $userId): bool
{
    return avatar_relative_path($userId) !== null;
}

/**
 * The URL an app page should point an <img> at, or null.
 *
 * The version suffix is the file's modification time, so a newly uploaded
 * picture appears immediately instead of showing the browser's cached copy of
 * the old one.
 */
function avatar_url(int $userId, string $prefix = 'actions/'): ?string
{
    $path = avatar_relative_path($userId);
    if ($path === null) {
        return null;
    }

    $version = @filemtime(__DIR__ . '/../' . $path) ?: 0;
    return $prefix . 'avatar_show.php?v=' . $version;
}

/**
 * Initials for the fallback, when no picture is set.
 *
 * First and last word, so "Maria Dela Cruz" gives MC rather than MD.
 */
function avatar_initials(?string $fullName): string
{
    $parts = preg_split('/\s+/', trim((string) $fullName)) ?: [];
    $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

    if ($parts === []) {
        return '?';
    }

    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    if (count($parts) === 1) {
        return $first;
    }

    return $first . mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1));
}

/**
 * Render the avatar as an image when one is set, and as initials when not.
 *
 * One function so the topbar, the sidebar and Settings cannot drift apart.
 *
 * @param string $extraStyle inline sizing for the larger Settings rendering
 */
function avatar_html(
    int $userId,
    ?string $fullName,
    string $extraClass = '',
    string $extraStyle = '',
    string $prefix = 'actions/'
): string {
    $classes = trim('ef-avatar ' . $extraClass);
    $style   = $extraStyle === '' ? '' : ' style="' . e($extraStyle) . '"';
    $url     = avatar_url($userId, $prefix);

    if ($url === null) {
        return '<span class="' . e($classes) . ' ef-avatar-initials"' . $style . '>'
             . e(avatar_initials($fullName)) . '</span>';
    }

    return '<img class="' . e($classes) . '" src="' . e($url) . '"'
         . ' alt="' . e(trim((string) $fullName)) . '"' . $style . '>';
}
