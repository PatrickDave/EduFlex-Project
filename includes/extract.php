<?php
/**
 * EduFlex — text extraction.
 *
 * Turns an uploaded file into plain text. This is the step BEFORE any AI is
 * involved; nothing here calls a model or costs money.
 *
 * Supported: .pdf, .docx, .txt, .md
 *
 * PDF support has two paths:
 *   1. If smalot/pdfparser is installed via Composer, it is used. It handles
 *      more PDFs, including unusual font encodings.
 *   2. Otherwise a built-in parser runs. It handles the ordinary case: a PDF
 *      produced by Word, Google Docs, LaTeX or a browser's "Print to PDF".
 *
 * To install the better parser on your machine:
 *     composer require smalot/pdfparser
 * Then this file picks it up automatically. No code change needed.
 */

declare(strict_types=1);

/** Extraction produced too little text to be usable. */
const EXTRACT_MIN_CHARS = 200;

/**
 * @return array{ok:bool, text:string, chars:int, error:?string, engine:string}
 */
function extract_text(string $path, string $extension): array
{
    $extension = strtolower(ltrim($extension, '.'));

    if (!is_readable($path)) {
        return extract_fail('The uploaded file could not be read.');
    }

    try {
        switch ($extension) {
            case 'txt':
            case 'md':
                $text   = extract_from_plain($path);
                $engine = 'plain';
                break;

            case 'docx':
                $text   = extract_from_docx($path);
                $engine = 'docx';
                break;

            case 'pdf':
                [$text, $engine] = extract_from_pdf($path);
                break;

            default:
                return extract_fail("EduFlex cannot read .$extension files. Upload a PDF, DOCX or TXT.");
        }
    } catch (Throwable $e) {
        error_log('EduFlex extraction failed: ' . $e->getMessage());
        return extract_fail('The file could not be processed. It may be corrupted.');
    }

    $text  = normalise_whitespace($text);
    $chars = mb_strlen($text);

    if ($chars < EXTRACT_MIN_CHARS) {
        // Almost always a scanned document: the pages are images, not text.
        return extract_fail(
            'No readable text was found. If this is a scanned document, '
            . 'EduFlex cannot read it. Upload a text-based version.',
            $engine
        );
    }

    $result = [
        'ok'      => true,
        'text'    => $text,
        'chars'   => $chars,
        'error'   => null,
        'engine'  => $engine,
        'warning' => null,
    ];

    // The built-in PDF path merges the ToUnicode maps of every embedded font.
    // Where two subset fonts claim the same glyph id, some letters come out
    // wrong: "University" becomes "DnivErsity". The give-away is capitals in
    // the middle of words, which is rare in real prose and common in this
    // failure. Warn rather than reject; the text is still partly usable.
    if ($engine === 'built-in') {
        /* Two independent checks, because they catch different failures and
           each is blind to the other's. See pdf_text_looks_garbled(). */
        if (pdf_text_looks_garbled($text)) {
            $result['warning'] =
                'The text in this PDF could not be decoded. What was extracted is '
                . 'not readable, so any questions generated from it would be '
                . 'meaningless. Upload the document as .docx instead, or install '
                . 'the PDF library (composer require smalot/pdfparser).';
        } elseif (pdf_text_confidence($text) < 0.85) {
            $result['warning'] =
                'Some characters may be decoded incorrectly. For accurate text, '
                . 'install the PDF library (composer require smalot/pdfparser) '
                . 'or upload the document as .docx instead.';
        }
    }

    return $result;
}

/**
 * Rough confidence score for built-in PDF extraction, 0 to 1.
 *
 * Counts words containing an uppercase letter after the first character.
 * Ordinary English prose has very few. Mis-decoded text has many, because the
 * substitution hits the same letters throughout ("the" becomes "thE").
 *
 * Known limitation: genuine CamelCase words such as MySQL or JavaScript are
 * counted as suspicious. A document full of them scores slightly lower than it
 * should. That is tolerable, because a real decoding failure lands near 0.5
 * while a few CamelCase terms move the score by a couple of points, and the
 * result is only a warning, never a rejection.
 */
function pdf_text_confidence(string $text): float
{
    if (!preg_match_all('/\b[A-Za-z]{3,}\b/u', $text, $m)) {
        return 1.0;
    }

    $words = array_slice($m[0], 0, 4000);
    $total = count($words);
    if ($total === 0) {
        return 1.0;
    }

    $suspect = 0;
    foreach ($words as $word) {
        // Skip genuine acronyms, which are legitimately all caps.
        if ($word === strtoupper($word)) {
            continue;
        }
        if (preg_match('/.[A-Z]/', $word)) {
            $suspect++;
        }
    }

    return 1.0 - ($suspect / $total);
}

/**
 * Does this look like text the built-in reader failed to decode at all?
 *
 * pdf_text_confidence() above detects ONE failure mode: two subset fonts
 * colliding on glyph ids, which leaves capitals stranded inside words
 * ("DnivErsity"). It is blind to a second and worse one.
 *
 * When a PDF embeds a subset font with its own encoding and no ToUnicode map,
 * every letter comes out shifted by a fixed amount. "art appreciation" became
 * "$UW$SSUHFLDWLRQ" in one font on a real upload and "BSU BQQSFDJBUJPO" in
 * another. Those are all capitals, so the acronym exemption in
 * pdf_text_confidence() skipped every single word and the whole document scored
 * a perfect 1.000. EduFlex accepted it, chunked it, and would have sent it to a
 * language model to write practice questions from gibberish.
 *
 * Two signals, measured against real samples rather than guessed:
 *
 *   Words run together. Spacing is usually lost along with the encoding, so the
 *   mean run of letters was 16.0 characters against 5.4 for English prose, 4.7
 *   for Filipino, and 5.9 for an all-capitals slide deck. This is the strong
 *   one, and it cares about neither language nor capitalisation.
 *
 *   Shouting with no recognisable words. If spacing survives, the giveaway is
 *   text that is almost entirely capitals AND contains none of the short words
 *   every real sentence is built from. A genuine all-capitals slide deck fails
 *   only the first half of that test, so it is not flagged. The word list
 *   covers English and Filipino, because that is what this system's learners
 *   upload.
 *
 * A warning, never a rejection, exactly like the check above: the learner is
 * told to install the PDF library or upload a .docx, and keeps whatever text
 * was recovered.
 */
function pdf_text_looks_garbled(string $text): bool
{
    // Too little to judge. A short extract is handled by EXTRACT_MIN_CHARS.
    if (mb_strlen($text) < 200) {
        return false;
    }

    /* Enough letters to judge, rather than enough tokens. The first version
       demanded 20 tokens and that worked against the check: text whose words
       have run together has FEW tokens by definition, so a badly garbled page
       could slip through for having too few of them. Letters are the honest
       measure of how much there is to go on. */
    if (preg_match_all('/[A-Za-z]/', $text) < 150) {
        return false;
    }

    /* Only enough to divide by. Do not add a minimum token count here: text
       whose words have run together has very few tokens BY DEFINITION, and a
       page of four 81-character runs is the clearest garbling there is. A
       "< 5 tokens" guard was tried and rejected exactly that sample. The 150
       letters above is what stops a scrap being judged. */
    if (!preg_match_all('/[A-Za-z]{2,}/', $text, $matches)) {
        return false;
    }

    $tokens = $matches[0];
    $meanRun = array_sum(array_map('strlen', $tokens)) / count($tokens);

    // Measured: 16.0 garbled, 6.2 the worst legitimate sample. Sits between.
    if ($meanRun >= 11.0) {
        return true;
    }

    $letters = preg_match_all('/[A-Za-z]/', $text);
    $upper   = preg_match_all('/[A-Z]/', $text);
    if ($letters === 0) {
        return false;
    }

    $upperRatio = $upper / $letters;

    /* The short words every real sentence leans on, in the two languages this
       system's learners write in. An all-capitals deck has plenty; shifted
       gibberish has none, because the shift destroys them too. */
    $common = preg_match_all(
        '/\b(the|and|of|to|in|is|that|for|with|are|as|it|by|be|on|or|from|this|which|can'
        . '|ang|ng|sa|na|mga|ay|at|para|ito|ang|kung|hindi)\b/i',
        $text
    );
    $perThousand = ($common / mb_strlen($text)) * 1000;

    return $upperRatio >= 0.80 && $perThousand < 5.0;
}

function extract_fail(string $message, string $engine = 'none'): array
{
    return ['ok' => false, 'text' => '', 'chars' => 0, 'error' => $message, 'engine' => $engine];
}

/* ------------------------------------------------------------------ plain */

function extract_from_plain(string $path): string
{
    $raw = (string) file_get_contents($path);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252, ISO-8859-1');
    }
    return $raw;
}

/* ------------------------------------------------------------------- docx */

/**
 * A .docx is a ZIP archive. The body text lives in word/document.xml.
 * Paragraph tags (w:p) become line breaks; tabs (w:tab) become spaces.
 */
function extract_from_docx(string $path): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The zip extension is not enabled in PHP.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Not a valid .docx archive.');
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false) {
        throw new RuntimeException('word/document.xml is missing from the archive.');
    }

    // Turn structural tags into whitespace before stripping the rest.
    $xml = preg_replace('#<w:tab[^>]*/>#', "\t", $xml);
    $xml = preg_replace('#<w:br[^>]*/>#', "\n", $xml);
    $xml = preg_replace('#</w:p>#', "\n", $xml);

    $text = strip_tags((string) $xml);

    return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/* -------------------------------------------------------------------- pdf */

/**
 * @return array{0:string,1:string} [text, engine name]
 */
function extract_from_pdf(string $path): array
{
    // Prefer the Composer library when the project has it installed.
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        if (class_exists('\Smalot\PdfParser\Parser')) {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($path);
            return [$pdf->getText(), 'smalot/pdfparser'];
        }
    }

    return [extract_from_pdf_native($path), 'built-in'];
}

/**
 * Minimal PDF text extractor.
 *
 * A PDF stores page content in streams, usually compressed with Flate. Inside
 * a decompressed stream, text is drawn by these operators:
 *
 *     (Hello) Tj              show a string
 *     [(He) -20 (llo)] TJ     show an array of strings with kerning
 *     (Hello) '               move to next line, then show
 *
 * This walks the streams and collects those strings. It handles the ordinary
 * documents students upload. It will return poor results for PDFs that use
 * custom font encodings, and nothing at all for scans, which contain images
 * rather than text. Both cases are caught by the EXTRACT_MIN_CHARS check.
 */
function extract_from_pdf_native(string $path): string
{
    $raw = (string) file_get_contents($path);
    if (!str_starts_with($raw, '%PDF')) {
        throw new RuntimeException('Not a PDF file.');
    }

    // Most PDFs from Word and Google Docs embed subset fonts and draw text as
    // hex glyph ids, e.g. <0003> Tj. Those ids mean nothing on their own; the
    // file carries ToUnicode CMaps that translate them.
    //
    // Resolve one CMap per font resource name (/F1, /F4 ...) so that two
    // subset fonts using the same glyph id do not overwrite each other. Fall
    // back to the merged map when the per-font structure cannot be read.
    $fontCmaps = pdf_build_font_cmaps($raw);
    $cmap      = $fontCmaps === [] ? pdf_build_tounicode_map($raw) : [];

    $out = [];

    // Walk every stream ... endstream block.
    $offset = 0;
    while (($start = strpos($raw, 'stream', $offset)) !== false) {
        $end = strpos($raw, 'endstream', $start);
        if ($end === false) {
            break;
        }

        // Skip the EOL that must follow the "stream" keyword.
        $dataStart = $start + 6;
        if (substr($raw, $dataStart, 2) === "\r\n") {
            $dataStart += 2;
        } elseif (in_array(substr($raw, $dataStart, 1), ["\n", "\r"], true)) {
            $dataStart += 1;
        }

        $chunk  = substr($raw, $dataStart, $end - $dataStart);
        $offset = $end + 9;

        // Content streams are Flate-compressed in every PDF a student will
        // produce. If inflation fails, the stream is an image, a font or an
        // ICC profile. Skipping those is essential: raw JPEG bytes happen to
        // contain the letters "Tj" often enough to produce pages of garbage.
        $content = @gzuncompress($chunk);
        if ($content === false) {
            $content = @gzinflate($chunk);
        }
        if ($content === false) {
            continue;
        }

        // A real content stream marks text with BT ... ET. Requiring BT
        // filters out the remaining non-text streams.
        if (!str_contains($content, 'BT') ||
            (!str_contains($content, 'Tj') && !str_contains($content, 'TJ'))) {
            continue;
        }

        $out[] = pdf_text_from_content($content, $cmap, $fontCmaps);
    }

    return implode("\n", array_filter($out, static fn($s) => trim($s) !== ''));
}

/**
 * Split a PDF into its indirect objects.
 *
 * @return array<int,string> object number => raw body
 */
function pdf_parse_objects(string $raw): array
{
    if (!preg_match_all('/(\d+)\s+\d+\s+obj\b/', $raw, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $objects = [];
    foreach ($m[0] as $i => $hit) {
        $num   = (int) $m[1][$i][0];
        $start = $hit[1] + strlen($hit[0]);
        $end   = strpos($raw, 'endobj', $start);
        if ($end === false) {
            continue;
        }
        $objects[$num] = substr($raw, $start, $end - $start);
    }

    return $objects;
}

/**
 * Decompress the stream inside one object body, if it has one.
 */
function pdf_object_stream(string $body): ?string
{
    $start = strpos($body, 'stream');
    if ($start === false) {
        return null;
    }
    $dataStart = $start + 6;
    if (substr($body, $dataStart, 2) === "\r\n") {
        $dataStart += 2;
    } elseif (in_array(substr($body, $dataStart, 1), ["\n", "\r"], true)) {
        $dataStart += 1;
    }
    $end = strpos($body, 'endstream', $dataStart);
    if ($end === false) {
        return null;
    }

    $chunk    = substr($body, $dataStart, $end - $dataStart);
    $inflated = @gzuncompress($chunk);
    if ($inflated === false) {
        $inflated = @gzinflate($chunk);
    }

    return $inflated === false ? $chunk : $inflated;
}

/**
 * Build one CMap per font resource name.
 *
 * Walks: /Font << /F4 12 0 R >>  ->  object 12 has /ToUnicode 13 0 R
 *        ->  object 13's stream holds the bfchar/bfrange table.
 *
 * Assumes a resource name refers to the same font throughout the document,
 * which holds for files produced by Word, Google Docs and LaTeX. Where it does
 * not, the confidence check downstream will flag the result.
 *
 * @return array<string,array<string,string>> font name => (glyph id => char)
 */
function pdf_build_font_cmaps(string $raw): array
{
    $objects = pdf_parse_objects($raw);
    if ($objects === []) {
        return [];
    }

    // font object number => ToUnicode object number
    $toUnicodeRef = [];
    foreach ($objects as $num => $body) {
        if (preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $body, $m)) {
            $toUnicodeRef[$num] = (int) $m[1];
        }
    }
    if ($toUnicodeRef === []) {
        return [];
    }

    // Parse each referenced CMap once, then reuse.
    $cmapCache = [];
    $cmapFor = static function (int $objNum) use ($objects, &$cmapCache): array {
        if (isset($cmapCache[$objNum])) {
            return $cmapCache[$objNum];
        }
        $body = $objects[$objNum] ?? null;
        $content = $body === null ? null : pdf_object_stream($body);
        return $cmapCache[$objNum] = $content === null ? [] : pdf_parse_cmap($content);
    };

    // /Font << /F4 12 0 R /F5 13 0 R >>
    $byName = [];
    if (preg_match_all('/\/Font\s*<<(.*?)>>/s', $raw, $dicts)) {
        foreach ($dicts[1] as $dict) {
            if (!preg_match_all('/\/([A-Za-z0-9._-]+)\s+(\d+)\s+\d+\s+R/', $dict, $pairs, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($pairs as $p) {
                $name     = $p[1];
                $fontObj  = (int) $p[2];
                if (isset($byName[$name]) || !isset($toUnicodeRef[$fontObj])) {
                    continue;
                }
                $map = $cmapFor($toUnicodeRef[$fontObj]);
                if ($map !== []) {
                    $byName[$name] = $map;
                }
            }
        }
    }

    return $byName;
}

/**
 * Parse the bfchar and bfrange tables inside one CMap stream.
 *
 * @return array<string,string>
 */
function pdf_parse_cmap(string $content): array
{
    $map = [];

    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $content, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER)) {
                foreach ($pairs as $p) {
                    $map[strtoupper(str_pad($p[1], 4, '0', STR_PAD_LEFT))] = pdf_hex_to_utf8($p[2]);
                }
            }
        }
    }

    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $content, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (preg_match_all(
                '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/',
                $block, $ranges, PREG_SET_ORDER
            )) {
                foreach ($ranges as $r) {
                    $from = (int) hexdec($r[1]);
                    $to   = (int) hexdec($r[2]);
                    $dest = (int) hexdec($r[3]);
                    if ($to < $from || ($to - $from) > 65535) {
                        continue;
                    }
                    for ($i = $from; $i <= $to; $i++) {
                        $key = strtoupper(str_pad(dechex($i), 4, '0', STR_PAD_LEFT));
                        $map[$key] = mb_chr($dest + ($i - $from), 'UTF-8') ?: '';
                    }
                }
            }
        }
    }

    return $map;
}

/**
 * Collect every ToUnicode CMap in the file into one lookup table.
 *
 * A CMap stream looks like:
 *
 *     3 beginbfchar
 *     <0003> <0020>
 *     <0024> <0041>
 *     endbfchar
 *     1 beginbfrange
 *     <0024> <003D> <0041>
 *     endbfrange
 *
 * The left side is the glyph id used in the content stream; the right side is
 * the Unicode code point.
 *
 * Caveat: this merges the maps of every font in the document. Two subset fonts
 * can legitimately assign different characters to the same id, and where that
 * happens a few characters will come out wrong. Resolving it properly means
 * tracking the active font per text run through each page's resource
 * dictionary. For reliable results install smalot/pdfparser, which does that.
 *
 * @return array<string,string> glyph id (4 hex chars, uppercase) => character
 */
function pdf_build_tounicode_map(string $raw): array
{
    $map    = [];
    $offset = 0;

    while (($start = strpos($raw, 'stream', $offset)) !== false) {
        $end = strpos($raw, 'endstream', $start);
        if ($end === false) {
            break;
        }

        $dataStart = $start + 6;
        if (substr($raw, $dataStart, 2) === "\r\n") {
            $dataStart += 2;
        } elseif (in_array(substr($raw, $dataStart, 1), ["\n", "\r"], true)) {
            $dataStart += 1;
        }

        $chunk  = substr($raw, $dataStart, $end - $dataStart);
        $offset = $end + 9;

        $content = @gzuncompress($chunk);
        if ($content === false) {
            $content = @gzinflate($chunk);
        }
        if ($content === false || !str_contains($content, 'beginbfchar')
            && !str_contains($content, 'beginbfrange')) {
            continue;
        }

        // Single mappings.
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $content, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $p) {
                        $map[strtoupper($p[1])] = pdf_hex_to_utf8($p[2]);
                    }
                }
            }
        }

        // Range mappings.
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $content, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all(
                    '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/',
                    $block, $ranges, PREG_SET_ORDER
                )) {
                    foreach ($ranges as $r) {
                        $from = hexdec($r[1]);
                        $to   = hexdec($r[2]);
                        $dest = hexdec($r[3]);
                        // Guard against a malformed range claiming thousands of glyphs.
                        if ($to < $from || ($to - $from) > 65535) {
                            continue;
                        }
                        for ($i = $from; $i <= $to; $i++) {
                            $key = strtoupper(str_pad(dechex($i), 4, '0', STR_PAD_LEFT));
                            $map[$key] = mb_chr($dest + ($i - $from), 'UTF-8') ?: '';
                        }
                    }
                }
            }
        }
    }

    return $map;
}

/**
 * A ToUnicode destination may hold several UTF-16BE code units, e.g. a
 * ligature mapping to "ff".
 */
function pdf_hex_to_utf8(string $hex): string
{
    $out = '';
    foreach (str_split($hex, 4) as $unit) {
        if ($unit === '') {
            continue;
        }
        $code = hexdec(str_pad($unit, 4, '0'));
        if ($code > 0) {
            $out .= mb_chr((int) $code, 'UTF-8') ?: '';
        }
    }
    return $out;
}

/**
 * Translate a hex glyph string through the CMap.
 */
function pdf_decode_hex_string(string $hex, array $cmap): string
{
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex) ?? '';
    if ($hex === '') {
        return '';
    }
    // Odd length means single-byte codes; pad so str_split lines up.
    if (strlen($hex) % 4 !== 0 && strlen($hex) % 2 === 0 && $cmap === []) {
        $out = '';
        foreach (str_split($hex, 2) as $byte) {
            $out .= chr((int) hexdec($byte));
        }
        return $out;
    }

    $out = '';
    foreach (str_split($hex, 4) as $unit) {
        $key = strtoupper(str_pad($unit, 4, '0', STR_PAD_LEFT));
        if (isset($cmap[$key])) {
            $out .= $cmap[$key];
        } elseif ($cmap === []) {
            $code = hexdec($key);
            $out .= $code > 31 ? (mb_chr((int) $code, 'UTF-8') ?: '') : '';
        }
    }
    return $out;
}

/**
 * Pull the shown strings out of one decompressed content stream.
 */
function pdf_text_from_content(string $content, array $cmap = [], array $fontCmaps = []): string
{
    $result = '';

    // Five alternatives, in order:
    //   [ ... ] TJ        array of strings, literal and/or hex
    //   ( ... ) Tj|'|"    literal string
    //   < ... > Tj|'|"    hex string (glyph ids)
    //   /Fx size Tf       font switch, selects which CMap decodes what follows
    //   T*|Td|TD|ET       positioning
    $pattern = '/(?:\[((?:[^\[\]\\\\]|\\\\.)*)\]\s*TJ)'
             . '|(?:\(((?:[^()\\\\]|\\\\.)*)\)\s*(?:Tj|\'|"))'
             . '|(?:<([0-9A-Fa-f\s]*)>\s*(?:Tj|\'|"))'
             . '|(?:\/([A-Za-z0-9._-]+)\s+[\d.]+\s+Tf)'
             . '|(T\*|Td|TD|ET)/s';

    if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
        return '';
    }

    // Active decoding table, swapped by each Tf operator.
    $active = $cmap;

    foreach ($matches as $m) {
        // Font switch.
        if (($m[4] ?? '') !== '') {
            $active = $fontCmaps[$m[4]] ?? $cmap;
            continue;
        }
        // TJ array: may hold both (literal) and <hex> pieces.
        if (($m[1] ?? '') !== '') {
            if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)|<([0-9A-Fa-f\s]*)>/s', $m[1], $pieces, PREG_SET_ORDER)) {
                foreach ($pieces as $piece) {
                    if (($piece[1] ?? '') !== '') {
                        $result .= pdf_unescape($piece[1]);
                    } elseif (($piece[2] ?? '') !== '') {
                        $result .= pdf_decode_hex_string($piece[2], $active);
                    }
                }
            }
            continue;
        }

        if (($m[2] ?? '') !== '') {
            $result .= pdf_unescape($m[2]);
            continue;
        }

        if (($m[3] ?? '') !== '') {
            $result .= pdf_decode_hex_string($m[3], $active);
            continue;
        }

        // Positioning. Many generators emit one BT/Td/ET block per word, so
        // treating Td as a line break would put every word on its own line.
        // Only an explicit next-line operator becomes a newline.
        $op = $m[5] ?? '';
        if ($op === 'T*') {
            $result .= "\n";
        } elseif ($op === 'Td' || $op === 'TD' || $op === 'ET') {
            $result .= ' ';
        }
    }

    return $result;
}

/**
 * Resolve PDF string escapes: \( \) \\ \n \r \t and octal codes like \251.
 */
function pdf_unescape(string $s): string
{
    $map = [
        '\\n' => "\n", '\\r' => "\r", '\\t' => "\t",
        '\\b' => "\x08", '\\f' => "\x0C",
        '\\(' => '(',   '\\)' => ')',   '\\\\' => '\\',
    ];
    $s = strtr($s, $map);

    // Octal character codes.
    $s = preg_replace_callback('/\\\\([0-7]{1,3})/', static function (array $m): string {
        $code = octdec($m[1]);
        return $code > 0 && $code < 256 ? chr((int) $code) : '';
    }, $s) ?? $s;

    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252, ISO-8859-1');
    }

    return $s;
}

/* ------------------------------------------------------------ normalising */

/**
 * Collapse the ragged whitespace that extraction always produces, without
 * destroying paragraph boundaries.
 */
function normalise_whitespace(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    // Strip control characters that survive extraction.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

    return trim($text);
}

/* -------------------------------------------------------------- chunking */

/**
 * Split extracted text into overlapping chunks.
 *
 * Why chunk at all: a 40-page reviewer will not fit in one model request, and
 * sending the whole document with every chat message would be slow and
 * expensive. Chunks are the unit you send.
 *
 * The overlap keeps a sentence that straddles a boundary intact in at least
 * one chunk.
 *
 * @return list<array{index:int, text:string, words:int}>
 */
function chunk_text(string $text, int $wordsPerChunk = 700, int $overlapWords = 80): array
{
    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $total = count($words);

    if ($total === 0) {
        return [];
    }

    if ($overlapWords >= $wordsPerChunk) {
        $overlapWords = (int) floor($wordsPerChunk / 4);
    }

    $chunks = [];
    $step   = $wordsPerChunk - $overlapWords;
    $index  = 0;

    for ($start = 0; $start < $total; $start += $step) {
        $slice = array_slice($words, $start, $wordsPerChunk);
        if (!$slice) {
            break;
        }
        $chunks[] = [
            'index' => $index++,
            'text'  => implode(' ', $slice),
            'words' => count($slice),
        ];
        if ($start + $wordsPerChunk >= $total) {
            break;
        }
    }

    return $chunks;
}
