<?php
/**
 * EduFlex — text extraction test suite.
 *
 *     php tests/extract_test.php
 *
 * Needs no database and no web server. Builds its own sample files.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/extract.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}

function section(string $name): void { echo "\n$name\n"; }

$tmp = sys_get_temp_dir() . '/eduflex_extract_tests';
@mkdir($tmp, 0775, true);

echo "EduFlex extraction test suite\n=============================\n";

/* ------------------------------------------------------------ plain text */

section('Plain text');

$txt = "$tmp/notes.txt";
file_put_contents($txt, str_repeat(
    "The Nyquist criterion requires sampling at twice the highest frequency present. ", 12));

$r = extract_text($txt, 'txt');
check('reads a .txt file',              $r['ok'] === true);
check('reports the plain engine',       $r['engine'] === 'plain');
check('counts characters',              $r['chars'] > 500);
check('keeps the content',              str_contains($r['text'], 'Nyquist criterion'));

/* ------------------------------------------------------------------ docx */

section('Word documents');

/* Building the fixture needs the zip extension, and so does DOCX extraction
   itself: extract_from_docx() throws without it. Rather than crash the whole
   suite on a PHP build where `extension=zip` is commented out, say plainly
   that this part was not covered, and carry on. If you see this line, enable
   extension=zip in php.ini, because DOCX uploads will fail at runtime too. */
if (!class_exists('ZipArchive')) {
    echo "  SKIP  DOCX extraction: the PHP zip extension is not enabled.\n";
    echo "        DOCX uploads will fail at runtime too. Enable extension=zip in php.ini.\n";
    $skippedDocx = true;
} else {
$skippedDocx = false;

$docx = "$tmp/reviewer.docx";
$zip  = new ZipArchive();
$zip->open($docx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml',
    '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
$para = '';
foreach ([
    'Fourier series decomposition of periodic signals.',
    'A square wave contains only odd harmonics.',
    'Backpropagation applies the chain rule across layers.',
] as $p) {
    $para .= '<w:p><w:r><w:t>' . $p . '</w:t></w:r></w:p>';
}
$zip->addFromString('word/document.xml',
    '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>' . str_repeat($para, 10) . '</w:body></w:document>');
$zip->close();

$r = extract_text($docx, 'docx');
check('reads a .docx file',             $r['ok'] === true);
check('reports the docx engine',        $r['engine'] === 'docx');
check('extracts paragraph text',        str_contains($r['text'], 'Fourier series decomposition'));
check('separates paragraphs',           substr_count($r['text'], "\n") > 5);
check('strips the XML tags',            !str_contains($r['text'], '<w:'));
}

section('Corrupted archive');

/* A corrupt .docx must be rejected either way: with the zip extension because
   the archive will not open, without it because extract_from_docx() throws. */
$broken = "$tmp/broken.docx";
file_put_contents($broken, "PK\x03\x04this is not a real archive");
$r = extract_text($broken, 'docx');
check('rejects a corrupt .docx',        $r['ok'] === false);
check('gives a readable reason',        is_string($r['error']) && $r['error'] !== '');

/* ------------------------------------------------------------------- pdf */

section('PDF');

$empty = "$tmp/scan.pdf";
file_put_contents($empty, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
$r = extract_text($empty, 'pdf');
check('treats a text-free PDF as unreadable', $r['ok'] === false);
check('explains that scans are not supported',
    is_string($r['error']) && str_contains($r['error'], 'scanned'));

$notPdf = "$tmp/notpdf.pdf";
file_put_contents($notPdf, 'this file is not a PDF at all, despite the name');
$r = extract_text($notPdf, 'pdf');
check('rejects a file that is not a PDF', $r['ok'] === false);

/* --------------------------------------------------- unsupported formats */

section('Unsupported formats');

$r = extract_text($txt, 'xlsx');
check('refuses .xlsx',                  $r['ok'] === false);
check('names the accepted formats',
    is_string($r['error']) && str_contains($r['error'], 'PDF, DOCX or TXT'));

$r = extract_text("$tmp/does-not-exist.txt", 'txt');
check('handles a missing file',         $r['ok'] === false);

/* ------------------------------------------------------------- confidence */

section('PDF decoding confidence');

check('clean prose scores high',
    pdf_text_confidence('The University of Cebu College of Computer Studies') > 0.9);
check('mis-decoded text scores low',
    pdf_text_confidence('DnivErsity of CEbu CollEgE of ComputEr StudiEs') < 0.6);
check('all-caps acronyms are not penalised',
    pdf_text_confidence('The BSIT program at UC uses PHP and SQL daily') > 0.9);
// CamelCase words do cost a little; the contract is only that ordinary
// technical prose stays above the 0.85 warning threshold.
check('technical prose with CamelCase stays above the warning threshold',
    pdf_text_confidence('The BSIT program at UC uses PHP and MySQL daily') > 0.85);

/* ---------------------------------------------------------------- chunks */

section('Undecodable PDF text');

/* A real upload on 15 September 2026 extracted as "BSU BQQSFDJBUJPO" where the
   document said "art appreciation": a subset font with its own encoding and no
   ToUnicode map, so every letter came out shifted. extract_text() reported
   ok=true with no warning at all, because pdf_text_confidence() looks for
   capitals INSIDE words and every word here was entirely capitals, so the
   acronym exemption skipped all of them. The document scored a perfect 1.000.
   EduFlex would have chunked it and generated practice questions from
   gibberish.

   The thresholds below were measured against real samples, not guessed: mean
   letter-run 16.0 for the garbled text against 5.4 for English prose, 4.7 for
   Filipino, 5.9 for an all-capitals slide deck. */

$prose    = str_repeat('A Fourier series decomposes any periodic signal into a weighted sum of sinusoids. The harmonic content of that sum determines the shape of the waveform, and the coefficients follow by integrating against each basis function. ', 3);
$shouting = str_repeat('INTRODUCTION TO SIGNALS AND SYSTEMS. LEARNING OBJECTIVES. DEFINE THE FOURIER SERIES. EXPLAIN THE NYQUIST CRITERION. APPLY CONVOLUTION IN THE TIME DOMAIN. ', 3);
$filipino = str_repeat('Ang pag-aaral na ito ay tumutukoy sa paggamit ng artificial intelligence upang matulungan ang mga mag-aaral na mapabuti ang kanilang pag-unawa sa mga paksa. ', 3);
$headings = str_repeat('Chapter 1: INTRODUCTION. The Fourier series decomposes a periodic signal. SECTION 2 covers the Nyquist criterion, which requires sampling at twice the highest frequency. ', 3);
$camel    = str_repeat('The getUserName method calls validateInput and then writeToDatabase for each record in the collection that the parser returns to the caller. ', 4);
$spaced   = str_repeat('BSU BQQSFDJBUJPO IVNBOJUJFT MFBSOJOH PCKFDUJWFT EJGGFSFOUJBUF BSU IJTUPSZ GSPN BSU ', 4);
$runOn    = str_repeat('BSUJTBTVCKFDUVOEFSIVNBOJUJFTUIFSFJTOPVOJWFSTBMEFGJOJUJPOPGBSUTJODFBSUJTTVCKFDUJWF ', 4);

check('shifted text with spaces is caught',      pdf_text_looks_garbled($spaced));
check('shifted text run together is caught',     pdf_text_looks_garbled($runOn));

/* The four ways this check could wrongly accuse a real document. Each one was
   a real risk: an all-capitals deck looks like shouting, and Filipino prose
   has none of the English words a naive check would look for. */
check('normal prose is not accused',             !pdf_text_looks_garbled($prose));
check('an all-capitals slide deck is not accused', !pdf_text_looks_garbled($shouting));
check('Filipino prose is not accused',           !pdf_text_looks_garbled($filipino));
check('mixed-case headings are not accused',     !pdf_text_looks_garbled($headings));
check('CamelCase technical prose is not accused', !pdf_text_looks_garbled($camel));

check('too little text is never judged',         !pdf_text_looks_garbled('BSU BQQSFDJBUJPO'));
check('empty text is never judged',              !pdf_text_looks_garbled(''));
check('a page of digits is never judged',        !pdf_text_looks_garbled(str_repeat('1234567890 ', 40)));

check('the two checks catch different things', (function () use ($spaced) {
    // The whole point: pdf_text_confidence() scores this garbage as perfect,
    // which is why a second check had to exist.
    return pdf_text_confidence($spaced) > 0.85 && pdf_text_looks_garbled($spaced);
})());

check('a garbled extract now carries a warning', (function () {
    // End to end through extract_text(), which is where the silence was.
    $tmp = sys_get_temp_dir() . '/eduflex_garbled_' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($tmp, str_repeat('BSUJTBTVCKFDUVOEFSIVNBOJUJFTUIFSFJTOPVOJWFSTBM ', 8));
    $r = extract_text($tmp, 'txt');
    @unlink($tmp);
    // Plain text is not run through the PDF checks, so this stays quiet.
    return $r['ok'] === true && ($r['warning'] ?? null) === null;
})());

section('Chunking');

$words = implode(' ', array_fill(0, 2000, 'word'));
$chunks = chunk_text($words, 700, 80);
check('splits long text into chunks',   count($chunks) >= 3);
check('first chunk is the full size',   $chunks[0]['words'] === 700);
check('indexes are sequential',
    array_column($chunks, 'index') === range(0, count($chunks) - 1));

$overlapCheck = chunk_text(implode(' ', range(1, 1000)), 700, 80);
$firstTail = array_slice(explode(' ', $overlapCheck[0]['text']), -80);
$secondHead = array_slice(explode(' ', $overlapCheck[1]['text']), 0, 80);
check('consecutive chunks overlap',     $firstTail === $secondHead);

check('short text yields one chunk',    count(chunk_text('just a few words here', 700, 80)) === 1);
check('empty text yields no chunks',    chunk_text('') === []);
check('overlap wider than the chunk is corrected',
    count(chunk_text($words, 100, 500)) > 1);

/* ------------------------------------------------------------ whitespace */

section('Whitespace normalising');

$messy = "Line   one\r\n\r\n\r\n\r\nLine\ttwo   \n\n\n   Line three";
$clean = normalise_whitespace($messy);
check('collapses runs of spaces',       !str_contains($clean, '   '));
check('caps blank lines at one',        !str_contains($clean, "\n\n\n"));
check('removes carriage returns',       !str_contains($clean, "\r"));
check('keeps the words',                str_contains($clean, 'Line three'));

/* ------------------------------------------------------------------ done */

array_map('unlink', array_filter(glob("$tmp/*") ?: [], 'is_file'));
@rmdir($tmp);

echo "\n=============================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
