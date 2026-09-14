<?php
/**
 * Serve the signed-in learner's profile picture.
 *
 * Avatars live under storage/, which storage/.htaccess denies Apache from
 * serving or executing, so this is the only way one reaches a page. That is
 * deliberate: the bytes came from a learner, and letting Apache decide what to
 * do with them is exactly what the .htaccess is there to prevent.
 *
 * There is no user id parameter, by design. This serves the session's own
 * avatar and nothing else, so there is no id for anybody to change and no
 * ownership check to get wrong. Nothing in EduFlex shows one learner another
 * learner's picture.
 *
 * A GET, unlike everything else in this directory, because it is the src of an
 * <img>. It changes nothing, so there is nothing for a CSRF token to protect.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/avatar.php';

auth_require_login('../../login.php');
$user = auth_user();

$relative = avatar_relative_path((int) $user['user_id']);

if ($relative === null) {
    http_response_code(404);
    exit;
}

$absolute = __DIR__ . '/../../' . $relative;

/* The Content-Type is taken from the bytes, not from the extension and not from
   anything the browser said at upload time. If the file is no longer a readable
   image, nothing is sent at all. */
$info = @getimagesize($absolute);
if ($info === false || !isset(AVATAR_ALLOWED[(int) $info[2]])) {
    http_response_code(404);
    exit;
}

$mime = image_type_to_mime_type((int) $info[2]);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($absolute));
// Belt and braces with the Content-Type above: no sniffing, no guessing.
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
/* Private, because this is one learner's picture and a shared cache must not
   hand it to the next person. The URL carries the file's modification time, so
   a new upload is fetched immediately despite this. */
header('Cache-Control: private, max-age=86400');

readfile($absolute);
exit;
