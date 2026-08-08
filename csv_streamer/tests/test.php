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
