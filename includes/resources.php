<?php
/**
 * EduFlex — learning resource upload and processing.
 *
 * Covers Learning Resource Management modules 1 to 4:
 *   1. Upload Learning Resources
 *   2. Analyze Uploaded Content   (text extraction and chunking, no AI yet)
 *   3. View and Organize Resources
 *   4. Update or Remove Resources
 *
 * No language model is called anywhere in this file, so nothing here costs
 * money. Topic extraction, which does call a model, comes in the next phase
 * and reads the chunks this file produces.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/extract.php';
require_once __DIR__ . '/notifications.php';

/** Where uploaded files are written. Kept out of the web root by .htaccess. */
function storage_dir(): string
{
    return __DIR__ . '/../storage/uploads';
}

const UPLOAD_MAX_BYTES = 20 * 1024 * 1024; // 20 MB

/** extension => human label used in the interface */
const UPLOAD_ALLOWED = [
    'pdf'  => 'PDF document',
    'docx' => 'Word document',
    'txt'  => 'Plain text',
    'md'   => 'Markdown',
];

/**
 * Processing states stored in learning_resource.processing_status.
 * The interface maps these onto the mastery band colours.
 */
const STATUS_PENDING   = 'pending';
const STATUS_PROCESSED = 'processed';
const STATUS_FAILED    = 'failed';

/* -------------------------------------------------------------------------
   Upload
   ------------------------------------------------------------------------- */

/**
 * Validate and store one uploaded file, then create its database row.
 *
 * The file is saved with a generated name. The name the learner's computer
 * supplied is never used on disk: it can contain path separators, null bytes
 * or a second extension, all of which are ways to get a script executed.
 *
 * @param array<string,mixed> $file one entry from $_FILES
 * @return array{ok:bool, error:?string, resource_id:?int}
 */
function resource_upload(int $userId, array $file, string $title = ''): array
{
    $fail = static fn(string $m): array =>
        ['ok' => false, 'error' => $m, 'resource_id' => null];

    // PHP-level upload errors come first; $file['tmp_name'] is unusable here.
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code !== UPLOAD_ERR_OK) {
        return $fail(match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'That file is larger than the server allows. Maximum is 20 MB.',
            UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE   => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                'The server could not save the file. Check folder permissions.',
            default              => 'The upload failed.',
        });
    }

    // Confirms the file really arrived through an HTTP upload rather than
    // being an arbitrary server path supplied by a crafted request.
    if (!is_uploaded_file($file['tmp_name'])) {
        return $fail('That upload could not be verified.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return $fail('The file is empty.');
    }
    if ($size > UPLOAD_MAX_BYTES) {
        return $fail('That file is larger than 20 MB.');
    }

    $originalName = (string) ($file['name'] ?? 'upload');
    $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!isset(UPLOAD_ALLOWED[$extension])) {
        return $fail('EduFlex accepts PDF, DOCX and TXT files.');
    }

    // Check the actual bytes, not just the extension. A .exe renamed to .pdf
    // fails here.
    if (!upload_content_matches($file['tmp_name'], $extension)) {
        return $fail('That file does not look like a real .' . $extension . ' file.');
    }

    $dir = storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return $fail('The storage folder could not be created.');
    }

    // Generated name. Unpredictable, no user input, correct extension.
    $storedName = sprintf('%d_%s.%s', $userId, bin2hex(random_bytes(12)), $extension);
    $storedPath = $dir . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
        return $fail('The file could not be saved.');
    }
    @chmod($storedPath, 0644);

    $title = trim($title) !== '' ? trim($title) : pathinfo($originalName, PATHINFO_FILENAME);
    $title = mb_substr($title, 0, 255);

    try {
        $stmt = db()->prepare(
            'INSERT INTO learning_resource
                (user_id, title, file_type, storage_path, processing_status,
                 original_name, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $title,
            $extension,
            'storage/uploads/' . $storedName,
            STATUS_PENDING,
            mb_substr($originalName, 0, 255),
            $size,
        ]);
        $resourceId = (int) db()->lastInsertId();
    } catch (PDOException $e) {
        @unlink($storedPath); // do not leave an orphan file behind
        error_log('EduFlex resource insert failed: ' . $e->getMessage());
        return $fail('The upload could not be recorded. Try again.');
    }

    return ['ok' => true, 'error' => null, 'resource_id' => $resourceId];
}

/**
 * Compare the file's leading bytes against what the extension claims.
 */
function upload_content_matches(string $path, string $extension): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $head = (string) fread($handle, 8);
    fclose($handle);

    return match ($extension) {
        // "%PDF"
        'pdf'  => str_starts_with($head, '%PDF'),
        // .docx is a ZIP: "PK\x03\x04", or an empty/spanned archive variant.
        'docx' => str_starts_with($head, "PK\x03\x04")
               || str_starts_with($head, "PK\x05\x06")
               || str_starts_with($head, "PK\x07\x08"),
        // Text files have no signature. Reject only if they contain null bytes.
        'txt', 'md' => !str_contains($head, "\0"),
        default => false,
    };
}

/* -------------------------------------------------------------------------
   Processing: extract text, split into chunks
   ------------------------------------------------------------------------- */

/**
 * Extract the text of one resource and store its chunks.
 *
 * Safe to call more than once; existing chunks are replaced.
 *
 * @return array{ok:bool, status:string, message:?string, chunks:int, chars:int}
 */
function resource_process(int $resourceId, int $userId): array
{
    $stmt = db()->prepare(
        'SELECT resource_id, title, storage_path, file_type
           FROM learning_resource
          WHERE resource_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$resourceId, $userId]);
    $resource = $stmt->fetch();

    if (!$resource) {
        return ['ok' => false, 'status' => STATUS_FAILED,
                'message' => 'Resource not found.', 'chunks' => 0, 'chars' => 0];
    }

    $absolute = __DIR__ . '/../' . $resource['storage_path'];
    $result   = extract_text($absolute, (string) $resource['file_type']);

    if (!$result['ok']) {
        resource_mark(
            $resourceId, STATUS_FAILED, $result['error'], $result['engine'], 0, 0
        );
        return ['ok' => false, 'status' => STATUS_FAILED,
                'message' => $result['error'], 'chunks' => 0, 'chars' => 0];
    }

    $chunks = chunk_text($result['text']);

    try {
        db()->beginTransaction();

        // Replace rather than append, so reprocessing does not duplicate.
        $del = db()->prepare('DELETE FROM resource_chunk WHERE resource_id = ?');
        $del->execute([$resourceId]);

        $ins = db()->prepare(
            'INSERT INTO resource_chunk (resource_id, chunk_index, content, word_count)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($chunks as $chunk) {
            $ins->execute([$resourceId, $chunk['index'], $chunk['text'], $chunk['words']]);
        }

        resource_mark(
            $resourceId,
            STATUS_PROCESSED,
            $result['warning'],           // null unless decoding looked unreliable
            $result['engine'],
            $result['chars'],
            count($chunks)
        );

        db()->commit();
    } catch (Throwable $e) {
        // Throwable, not PDOException: whatever goes wrong here, the row must
        // not be left on "pending", or the interface will show a spinner that
        // never resolves.
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('EduFlex chunk insert failed: ' . $e->getMessage());
        resource_mark($resourceId, STATUS_FAILED, 'Could not store the extracted text.',
                      $result['engine'], 0, 0);
        return ['ok' => false, 'status' => STATUS_FAILED,
                'message' => 'Could not store the extracted text.', 'chunks' => 0, 'chars' => 0];
    }

    // Written after the commit, so the notification describes a stored fact.
    // notify() never throws, so this cannot turn a successful read into a
    // failure; see the rules at the top of includes/notifications.php.
    notify(
        $userId,
        'material_ready',
        'EduFlex has read ' . mb_substr((string) $resource['title'], 0, 120),
        sprintf(
            'The text was extracted and stored as %d section%s, %s characters in all. '
            . 'Run topic detection on it and EduFlex can start building practice sets.',
            count($chunks),
            count($chunks) === 1 ? '' : 's',
            number_format((int) $result['chars'])
        )
    );

    return [
        'ok'      => true,
        'status'  => STATUS_PROCESSED,
        'message' => $result['warning'],
        'chunks'  => count($chunks),
        'chars'   => $result['chars'],
    ];
}

/**
 * Record the outcome of processing.
 *
 * Uses CURRENT_TIMESTAMP rather than MySQL's NOW(), because the former is
 * standard SQL and works in the test harness too.
 *
 * Never throws. This is called from the error path of resource_process(), and
 * a second exception there would mask the original failure and leave the row
 * stuck on "pending" forever.
 */
function resource_mark(
    int $resourceId, string $status, ?string $message,
    string $engine, int $chars, int $chunkCount
): void {
    try {
        $stmt = db()->prepare(
            'UPDATE learning_resource
                SET processing_status = ?, extract_message = ?, extract_engine = ?,
                    char_count = ?, chunk_count = ?, processed_at = CURRENT_TIMESTAMP
              WHERE resource_id = ?'
        );
        $stmt->execute([
            $status,
            $message === null ? null : mb_substr($message, 0, 500),
            $engine,
            $chars,
            $chunkCount,
            $resourceId,
        ]);
    } catch (PDOException $e) {
        error_log('EduFlex could not update resource status: ' . $e->getMessage());
    }
}

/* -------------------------------------------------------------------------
   Reading and deleting
   ------------------------------------------------------------------------- */

/**
 * @return list<array<string,mixed>>
 */
function resource_list(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT resource_id, title, original_name, file_type, file_size,
                processing_status, char_count, chunk_count, extract_message,
                uploaded_at
           FROM learning_resource
          WHERE user_id = ?
       ORDER BY uploaded_at DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Totals for the sidebar summary.
 *
 * @return array{files:int, processed:int, chunks:int, words:int}
 */
function resource_totals(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS files,
                SUM(processing_status = ?) AS processed,
                COALESCE(SUM(chunk_count), 0) AS chunks,
                COALESCE(SUM(char_count), 0)  AS chars
           FROM learning_resource WHERE user_id = ?'
    );
    $stmt->execute([STATUS_PROCESSED, $userId]);
    $row = $stmt->fetch() ?: [];

    return [
        'files'     => (int) ($row['files'] ?? 0),
        'processed' => (int) ($row['processed'] ?? 0),
        'chunks'    => (int) ($row['chunks'] ?? 0),
        // Rough word estimate; average English word is about 5.5 characters.
        'words'     => (int) round(((int) ($row['chars'] ?? 0)) / 5.5),
    ];
}

/**
 * Remove a resource, its chunks and its file.
 *
 * The user_id in the WHERE clause is what stops one learner deleting another
 * learner's upload by guessing an id.
 */
function resource_delete(int $resourceId, int $userId): bool
{
    $stmt = db()->prepare(
        'SELECT storage_path FROM learning_resource
          WHERE resource_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$resourceId, $userId]);
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    $del = db()->prepare('DELETE FROM learning_resource WHERE resource_id = ? AND user_id = ?');
    $del->execute([$resourceId, $userId]);   // chunks cascade

    $absolute = __DIR__ . '/../' . $row['storage_path'];
    if (is_file($absolute)) {
        @unlink($absolute);
    }

    return true;
}

/* -------------------------------------------------------------------------
   Presentation helpers
   ------------------------------------------------------------------------- */

function resource_status_band(string $status): string
{
    return match ($status) {
        STATUS_PROCESSED => 'mastered',
        STATUS_PENDING   => 'developing',
        default          => 'weak',
    };
}

function resource_status_label(string $status): string
{
    return match ($status) {
        STATUS_PROCESSED => 'Analyzed',
        STATUS_PENDING   => 'Processing',
        default          => 'Failed',
    };
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
}
