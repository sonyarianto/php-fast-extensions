<?php

declare(strict_types=1);

// Tests for the XlsxStreamer extension. Run with:
//   php -d extension=target/release/libexcel_streamer.so tests/test.php

$data = __DIR__ . '/data';

$passed = 0;
$failed = 0;

function check(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS  $label\n";
    } else {
        $failed++;
        echo "FAIL  $label\n";
    }
}

function expect_exception(callable $fn, string $label): void
{
    try {
        $fn();
        check($label, false);
    } catch (Throwable $e) {
        check($label, true);
    }
}

// --- 1. Class basics ---
check('class exists', class_exists('XlsxStreamer'));
check('implements Iterator', in_array('Iterator', class_implements('XlsxStreamer'), true));

// --- 2. Sheet listing ---
$sheets = XlsxStreamer::sheets("$data/small.xlsx");
check('sheets() lists visible sheets', $sheets === ['Data', 'Second']);

// --- 3. Constructor + default sheet ---
$s = new XlsxStreamer("$data/small.xlsx");
check('defaults to first visible sheet', $s->sheetName() === 'Data');
check('headers() null without headers', $s->headers() === null);

// --- 4. List mode rows ---
$s = new XlsxStreamer("$data/small.xlsx", 'Second');
$rows = iterator_to_array($s);
check('Second sheet 4 rows', count($rows) === 4);
check('first row is list', $rows[0] === ['a', 'b']);
check('numbers become int', $rows[1][0] === 10);
check('shared strings resolved', $rows[3][1] === 'gamma');

// --- 5. Header mode + cell types ---
$s = new XlsxStreamer("$data/small.xlsx", null, true);
check('sheetName() accessor', $s->sheetName() === 'Data');
check('headers() returns header list', $s->headers() === ['id', 'name', 'active', 'joined', 'note', 'clock']);

$r1 = $s->nextRow();
check('row 1 keyed by header', $r1['id'] === 1 && $r1['name'] === 'Alice');
check('bool cell', $r1['active'] === true);
check('datetime cell formatted', $r1['joined'] === '2024-01-01 12:00:00');
check('inline string cell', $r1['note'] === 'hello');
check('time cell formatted', $r1['clock'] === '12:00:00');

$r2 = $s->nextRow();
check('row 2 quoted string', $r2['name'] === 'Bob "The Builder"');
check('row 2 bool false', $r2['active'] === false);
check('row 2 datetime', $r2['joined'] === '2024-01-02 06:00:00');
check('row 2 time', $r2['clock'] === '06:00:00');

$r3 = $s->nextRow();
check('date-only cell formats as date', $r3['joined'] === '2024-01-03');
check('empty shared string', $r3['name'] === '');
check('blank cell is null', $r3['note'] === null);
check('row 3 time 18:00', $r3['clock'] === '18:00:00');

$r4 = $s->nextRow();
check('unicode preserved', $r4['name'] === '日本語');
check('XML entities unescaped', $r4['note'] === 'escaped <tag> & \'quo\'');
check('row 4 datetime', $r4['joined'] === '2024-01-04 18:00:00');

$r5 = $s->nextRow();
check('row 5 datetime with fraction', $r5['joined'] === '2024-01-05 03:00:00');
check('row 5 blank note null', $r5['note'] === null);
check('nextRow() null at EOF', $s->nextRow() === null);

// --- 6. Iterator protocol ---
$s = new XlsxStreamer("$data/small.xlsx", null, true);
$keys = [];
$count = 0;
foreach ($s as $key => $row) {
    $keys[] = $key;
    $count++;
}
check('foreach yields 5 data rows', $count === 5);
check('key() is 0-based', $keys === [0, 1, 2, 3, 4]);

// --- 7. Rewind ---
$s = new XlsxStreamer("$data/small.xlsx", null, true);
foreach ($s as $row) { /* drain */ }
$count = 0;
foreach ($s as $row) {
    $count++;
}
check('rewind allows second pass', $count === 5);

// --- 8. Errors ---
expect_exception(fn () => new XlsxStreamer("$data/nope.xlsx"), 'missing file throws');
expect_exception(fn () => new XlsxStreamer("$data/small.xlsx", 'NoSuchSheet'), 'unknown sheet throws');
expect_exception(fn () => XlsxStreamer::sheets("$data/nope.xlsx"), 'sheets() missing file throws');

// --- 9. Large file streaming ---
$s = new XlsxStreamer("$data/large.xlsx", null, true);
$count = 0;
$sum = 0;
foreach ($s as $i => $row) {
    $sum += $row['Index'];
    $count++;
}
check('large file 100k rows', $count === 100000);
check('large file sum correct', $sum === 100000 * 100001 / 2);
check('large file peak memory < 20MB', memory_get_peak_usage(true) / 1024 / 1024 < 20);

echo "\n" . ($failed === 0 ? "ALL TESTS PASSED" : "$failed TESTS FAILED") . " ($passed passed)\n";
exit($failed === 0 ? 0 : 1);
