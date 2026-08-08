<?php

declare(strict_types=1);

// Test suite for JsonStreamer. Run with the extension loaded:
//   php -d extension=target/release/libjson_streamer.so tests/test.php

$dataDir = __DIR__ . '/data';
if (!is_file("$dataDir/small.json")) {
    fwrite(STDERR, "fixtures missing — run tests/generate_json.php first\n");
    exit(2);
}

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label\n";
    }
}

function expectException(string $label, callable $fn): void
{
    try {
        $fn();
        check($label, false);
    } catch (\Exception $e) {
        check($label, true);
    }
}

echo "== construction ==" . "\n";
expectException('missing file throws', fn() => new JsonStreamer("$dataDir/nope.json"));
expectException('malformed element throws', function () use ($dataDir) {
    $p = "$dataDir/broken.json";
    file_put_contents($p, "[ {\"id\": 1}, {\"id\": 2,} ]");
    try {
        $s = new JsonStreamer($p);
        foreach ($s as $row) {
        }
        throw new \Exception('should have thrown');
    } finally {
        unlink($p);
    }
});
$s = new JsonStreamer("$dataDir/small.json");
check('implements Iterator', (new ReflectionClass('JsonStreamer'))->implementsInterface(\Iterator::class));

echo "== iteration ==" . "\n";
$s->rewind();
$rows = [];
foreach ($s as $k => $row) {
    check("foreach key sequential ($k)", $k === count($rows));
    $rows[] = $row;
}
check('3 rows read', count($rows) === 3);

$row = $rows[0];
check('assoc keys', $row['id'] === 1 && $row['name'] === 'Alice');
check('bool type', $row['active'] === true);
check('float type', $row['score'] === 9.99);
check('null type', $row['note'] === null);
check('nested array', $row['tags'] === ['a', 'b', 'c']);
check('deeply nested', $row['nested'] === ['x' => 1, 'y' => [2, 3]]);
check('unicode string', $row['unicode'] === '日本語のテキスト');
check('escaped quotes/backslashes', $row['quotes'] === 'she said "hello" \\ with escapes');

$row = $rows[1];
check('apostrophe in string', $row['name'] === "Bob O'Brien");
check('float syntax stays float', $row['score'] === 1000.0);

$row = $rows[2];
check('empty arrays', $row['tags'] === [] && $row['nested'] === []);

echo "== protocol ==" . "\n";
$s->rewind();
check('key starts at 0', $s->key() === 0);
check('valid before iteration', $s->valid() === true);
// note: valid() lazily reads the first element (mirrors CsvStreamer/XlsxStreamer)
check('current is first row after valid', $s->current()['id'] === 1);
$s->rewind();
check('nextRow returns first row', $s->nextRow()['id'] === 1);
check('nextRow returns second row', $s->nextRow()['id'] === 2);
check('nextRow returns third row', $s->nextRow()['id'] === 3);
check('nextRow returns null at EOF', $s->nextRow() === null);
check('current null after EOF', $s->current() === null);
check('valid false after EOF', $s->valid() === false);

echo "== batch reads ==" . "\n";
$s->rewind();
$batch = $s->nextRows(2);
check('nextRows(2) returns 2', is_array($batch) && count($batch) === 2);
check('batch order', $batch[0]['id'] === 1 && $batch[1]['id'] === 2);
$batch = $s->nextRows(2);
check('nextRows(2) returns 1 at tail', is_array($batch) && count($batch) === 1 && $batch[0]['id'] === 3);
check('nextRows returns null at EOF', $s->nextRows(2) === null);

echo "== rewind ==" . "\n";
$s->rewind();
$first = [];
foreach ($s as $row) {
    $first[] = $row['id'];
}
check('second pass identical', $first === [1, 2, 3]);

echo "== malformed & boundary ==" . "\n";
$tmp = "$dataDir/tmp_malformed.json";
$expectThrow = function (string $label, string $content, bool $duringIteration = false) use ($tmp) {
    file_put_contents($tmp, $content);
    try {
        $s = new JsonStreamer($tmp);
        if ($duringIteration) {
            foreach ($s as $row) {
            }
        }
        check($label, false);
    } catch (\Exception $e) {
        check($label, true);
    } finally {
        unlink($tmp);
    }
};

$expectThrow('top-level object throws', '{"a":1}', true);
$expectThrow('empty file throws', '', true);
$expectThrow('whitespace-only file throws', "   \n\t ", true);
$expectThrow('unterminated element throws', '[1, 2', true);
$expectThrow('unterminated element at EOF throws', '["abc"', true);
$expectThrow('malformed element throws during iteration', '[{"id":1}, {"x":}]', true);

// Scalar-only and trailing-scalar arrays — the closing bracket must be
// recognized even after a scalar element
file_put_contents($tmp, '[1, "two", true, null, 3.5]');
$rows = iterator_to_array(new JsonStreamer($tmp));
check('scalar elements become single-element lists', $rows === [[1], ['two'], [true], [null], [3.5]]);
unlink($tmp);

file_put_contents($tmp, '[1, 2]');
check('trailing scalar array parses', iterator_to_array(new JsonStreamer($tmp), false) === [[1], [2]]);
unlink($tmp);

file_put_contents($tmp, "  [ 1 , 2 ]  \n\t ");
check('whitespace-heavy array parses', iterator_to_array(new JsonStreamer($tmp), false) === [[1], [2]]);
unlink($tmp);

file_put_contents($tmp, '[1,2,]');
check('trailing comma tolerated', iterator_to_array(new JsonStreamer($tmp), false) === [[1], [2]]);
unlink($tmp);

file_put_contents($tmp, '[1,2] trailing garbage');
check('trailing garbage after ] ignored', iterator_to_array(new JsonStreamer($tmp), false) === [[1], [2]]);
unlink($tmp);

file_put_contents($tmp, '[]');
check('empty array yields nothing', iterator_count(new JsonStreamer($tmp)) === 0);
unlink($tmp);

file_put_contents($tmp, '[ ]');
check('whitespace-only array yields nothing', iterator_count(new JsonStreamer($tmp)) === 0);
unlink($tmp);

file_put_contents($tmp, '[[1,2],[3]]');
$rows = iterator_to_array(new JsonStreamer($tmp));
check('array elements become list rows', $rows === [[1, 2], [3]]);
unlink($tmp);

file_put_contents($tmp, '[""]');
check('empty-string element', iterator_to_array(new JsonStreamer($tmp)) === [['']]);
unlink($tmp);

// Integer boundary fidelity: i64 min/max stay int, u64 max overflows to float
file_put_contents($tmp, '[9223372036854775807, -9223372036854775808, 18446744073709551615]');
$rows = iterator_to_array(new JsonStreamer($tmp), false);
check('i64 max stays int', $rows[0] === [PHP_INT_MAX]);
check('i64 min stays int', $rows[1] === [PHP_INT_MIN]);
check('u64 max overflows to float (json_decode parity)', is_float($rows[2][0]) && $rows[2][0] === 18446744073709551615.0);
unlink($tmp);

// Large single element (1 MB string) round-trips
$big = str_repeat('y', 1024 * 1024);
file_put_contents($tmp, '["' . $big . '"]');
$rows = iterator_to_array(new JsonStreamer($tmp));
check('1 MB string element round-trips', $rows[0][0] === $big);
unlink($tmp);

echo "== large file (1M rows, 226 MB) ==" . "\n";
$t0 = hrtime(true);
$n = 0;
$sum = 0;
foreach (new JsonStreamer("$dataDir/large.json") as $row) {
    $sum += $row['id'];
    $n++;
}
$ms = (hrtime(true) - $t0) / 1e6;
$peak = memory_get_peak_usage(true) / 1024 / 1024;
check('1,000,000 rows', $n === 1_000_000);
check('checksum correct', $sum === 500000500000);
check('peak memory under 32 MB', $peak < 32.0);
printf("  INFO  large.json: %.0f ms (%.0f rows/sec), peak %.1f MB\n", $ms, $n / ($ms / 1000), $peak);

echo "\n" . ($fail === 0 ? "ALL TESTS PASSED ($pass passed)" : "FAILURES: $fail (pass: $pass)") . "\n";
exit($fail === 0 ? 0 : 1);
