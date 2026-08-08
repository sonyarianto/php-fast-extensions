<?php

declare(strict_types=1);

error_reporting(E_ALL);

$data = __DIR__ . '/data';
$failures = 0;

function check(string $name, bool $cond): void {
    global $failures;
    if ($cond) {
        echo "PASS  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name\n";
    }
}

// --- 1. Class shape: implements Iterator, constructable ---
$rc = new ReflectionClass(CsvStreamer::class);
check('class implements Iterator', $rc->implementsInterface(Iterator::class));

// --- 2. Basic streaming with headers (assoc rows) ---
$s = new CsvStreamer("$data/small.csv", ',', true);
$first = $s->current();
check('current() before valid() returns null', $first === null);

$rows = [];
foreach ($s as $key => $row) {
    $rows[$key] = $row;
}
check('iterates 5 data rows', count($rows) === 5);
check('first row is assoc keyed by header', ($rows[0] ?? []) === ['id' => '1', 'name' => 'Alice', 'note' => 'hello, world']);
check('quoted comma parsed', $rows[0]['note'] === 'hello, world');
check('escaped quotes parsed', $rows[1]['name'] === 'Bob "The Builder"');
check('double-quoted quote parsed', $rows[1]['note'] === 'quote "" inside');
check('unicode preserved', $rows[2]['name'] === 'César');
check('last row (no trailing newline) read', $rows[4] === ['id' => '5', 'name' => 'last', 'note' => 'no trailing newline']);

// --- 3. key() semantics ---
$s = new CsvStreamer("$data/small.csv", ',', true);
$s->rewind();
$s->next();
check('key() is 0-based over data rows', $s->key() === 0);
check('valid() true on data row', $s->valid() === true);

// --- 3b. nextRow() convenience ---
$s = new CsvStreamer("$data/small.csv", ',', true);
$row = $s->nextRow();
check('nextRow() returns first data row', $row === ['id' => '1', 'name' => 'Alice', 'note' => 'hello, world']);
$row = $s->nextRow();
check('nextRow() advances', $row['name'] === 'Bob "The Builder"');
while ($s->nextRow() !== null) { /* drain */ }
check('nextRow() returns null at EOF', $s->nextRow() === null);

// --- 3c. nextRows() batching ---
$s = new CsvStreamer("$data/small.csv", ',', true);
$batch = $s->nextRows(2);
check('nextRows(2) returns 2 rows', is_array($batch) && count($batch) === 2);
check('nextRows(2) first row keyed by header', $batch[0] === ['id' => '1', 'name' => 'Alice', 'note' => 'hello, world']);
check('nextRows(2) advances', $batch[1]['name'] === 'Bob "The Builder"');
$batch = $s->nextRows(10);
check('nextRows(10) returns remaining 3 rows', is_array($batch) && count($batch) === 3);
check('nextRows() returns null at EOF', $s->nextRows(1) === null);
$s = new CsvStreamer("$data/small.csv", ',', true);
$batch = $s->nextRows(100);
check('nextRows(100) drains whole file in one batch', is_array($batch) && count($batch) === 5);
check('nextRows(0) returns null', $s->nextRows(0) === null);

// --- 4. Rewind ---
$s = new CsvStreamer("$data/small.csv", ',', true);
foreach ($s as $row) { /* drain */ }
$counted = 0;
foreach ($s as $row) {
    $counted++;
}
check('rewind allows second pass', $counted === 5);

// --- 5. No headers -> list rows ---
$s = new CsvStreamer("$data/semicolon.csv", ';');
$rows = iterator_to_array($s);
check('semicolon delimiter, 3 rows', count($rows) === 3);
check('list rows with int keys', $rows[0] === ['1', 'alpha', 'x']);
check('headers() is null without headers', $s->headers() === null);

// --- 6. headers() accessor ---
$s = new CsvStreamer("$data/small.csv", ',', true);
check('headers() returns header list', $s->headers() === ['id', 'name', 'note']);

// --- 7. UTF-8 BOM stripped from first header ---
$s = new CsvStreamer("$data/bom.csv", ',', true);
check('BOM stripped from header', $s->headers() === ['col_a', 'col_b']);
$rows = iterator_to_array($s);
check('BOM file rows parsed', $rows[0] === ['col_a' => 'a', 'col_b' => '1']);

// --- 8. Errors -> PHP exceptions ---
try {
    new CsvStreamer("$data/does_not_exist.csv");
    check('missing file throws', false);
} catch (Exception $e) {
    check('missing file throws', true);
}

try {
    new CsvStreamer("$data/small.csv", '||');
    check('multi-char delimiter throws', false);
} catch (Exception $e) {
    check('multi-char delimiter throws', true);
}

// --- 9. Empty iterator returns empty ---
file_put_contents("$data/empty.csv", "");
$s = new CsvStreamer("$data/empty.csv");
check('empty file yields nothing', iterator_count($s) === 0);

// --- 9b. Strict vs lenient UTF-8 modes ---
file_put_contents("$data/bad_utf8.csv", "a,b\nok,ok2\n" . "\xFF\xFE,val\n");
$s = new CsvStreamer("$data/bad_utf8.csv");
$rows = iterator_to_array($s);
check('lenient mode passes raw bytes through', $rows[2][0] === "\xFF\xFE");
try {
    $s = new CsvStreamer("$data/bad_utf8.csv", ',', false, true);
    iterator_to_array($s);
    check('strict mode throws on invalid UTF-8', false);
} catch (Exception $e) {
    check('strict mode throws on invalid UTF-8', true);
}
$s = new CsvStreamer("$data/small.csv", ',', false, true);
check('strict mode passes valid UTF-8', iterator_count($s) === 6);

// --- 9c. Malformed & boundary inputs ---
// CRLF, lone CR and lone LF all terminate records (RFC 4180 + practical leniency)
file_put_contents("$data/eol.csv", "a,b\r\n1,2\r\n3,4\n5,6\r");
$s = new CsvStreamer("$data/eol.csv");
check('CRLF/CR/LF mixed line endings', iterator_to_array($s, false) === [['a', 'b'], ['1', '2'], ['3', '4'], ['5', '6']]);

// Quoted field containing a newline = one record with an embedded newline
file_put_contents("$data/embedded_nl.csv", "id,note\n1,\"line1\nline2\"\n2,x\n");
$s = new CsvStreamer("$data/embedded_nl.csv", ',', true);
$rows = iterator_to_array($s);
check('quoted embedded newline is one record', count($rows) === 2);
check('embedded newline preserved', $rows[0]['note'] === "line1\nline2");

// Unterminated quote: parser must not hang or crash; rest of file becomes one field
file_put_contents("$data/unclosed_quote.csv", "a,b\n\"oops,2\n3,4\n");
$s = new CsvStreamer("$data/unclosed_quote.csv");
$rows = iterator_to_array($s, false);
check('unclosed quote survives to EOF', count($rows) === 2);
check('unclosed quote swallows remainder as one field', $rows[1] === ["oops,2\n3,4\n"]);

// Ragged rows are allowed (flexible mode)
file_put_contents("$data/ragged.csv", "a,b,c\n1,2\n3,4,5,6\n");
$s = new CsvStreamer("$data/ragged.csv");
$rows = iterator_to_array($s, false);
check('ragged rows parsed', $rows[1] === ['1', '2'] && $rows[2] === ['3', '4', '5', '6']);

// Empty quoted field and unquoted empty field are both empty strings
file_put_contents("$data/empty_fields.csv", "a,b,c\n\"\",,\n");
$s = new CsvStreamer("$data/empty_fields.csv");
$rows = iterator_to_array($s, false);
check('quoted and bare empty fields', $rows[1] === ['', '', '']);

// Spaces are field content, not trimmed (RFC 4180)
file_put_contents("$data/spaces.csv", "a,b\n x , y \n");
$s = new CsvStreamer("$data/spaces.csv");
$rows = iterator_to_array($s, false);
check('spaces preserved verbatim', $rows[1] === [' x ', ' y ']);

// Single-column file (no delimiter at all)
file_put_contents("$data/single_col.csv", "one\ntwo\nthree\n");
$s = new CsvStreamer("$data/single_col.csv");
check('single-column file', iterator_to_array($s, false) === [['one'], ['two'], ['three']]);

// BOM only in the middle of the file is content, not stripped
file_put_contents("$data/bom_mid.csv", "a,b\n\xEF\xBB\xBFx,y\n");
$s = new CsvStreamer("$data/bom_mid.csv");
$rows = iterator_to_array($s, false);
check('mid-file BOM kept as content', $rows[1][0] === "\xEF\xBB\xBFx");

// Header-only file yields zero data rows
file_put_contents("$data/header_only.csv", "id,name\n");
$s = new CsvStreamer("$data/header_only.csv", ',', true);
check('header-only file yields nothing', iterator_count($s) === 0);
check('header-only headers() still works', $s->headers() === ['id', 'name']);

// Large single cell (1 MB) round-trips intact
$big = str_repeat('x', 1024 * 1024);
file_put_contents("$data/big_cell.csv", "a,b\n1,$big\n");
$s = new CsvStreamer("$data/big_cell.csv");
$rows = iterator_to_array($s, false);
check('1 MB cell round-trips', $rows[1][1] === $big);
unlink("$data/big_cell.csv");

// NUL bytes pass through lenient mode (NUL is valid UTF-8, so strict too)
file_put_contents("$data/nul.csv", "a,b\nx\x00y,z\n");
$s = new CsvStreamer("$data/nul.csv");
$rows = iterator_to_array($s, false);
check('NUL byte preserved', $rows[1][0] === "x\x00y");
$s = new CsvStreamer("$data/nul.csv", ',', false, true);
check('NUL byte passes strict mode', iterator_count($s) === 2);

// --- 10. Memory stability: large file (compare to memory_limit-ish) ---
$s = new CsvStreamer("$data/large.csv");
$sum = 0;
foreach ($s as $row) {
    $sum += (int) $row[0];
}
check('large file row count', $sum === (499999 * 500000) / 2);
check('peak memory under 10MB during 500k rows', memory_get_peak_usage(true) < 10 * 1024 * 1024);

echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n$failures TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
