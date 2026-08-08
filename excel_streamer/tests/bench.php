<?php

declare(strict_types=1);

// Benchmark: XlsxStreamer over a 100k-row, 8-column sheet.
// PHP has no built-in xlsx reader, so results are absolute throughput
// (rows/sec) plus peak memory.

$path = __DIR__ . '/data/large.xlsx';

function bench(string $label, callable $fn, int $expectedRows): void
{
    $t0 = hrtime(true);
    $count = $fn();
    $t1 = hrtime(true);
    $ms = ($t1 - $t0) / 1e6;
    $peak = memory_get_peak_usage(true) / 1024 / 1024;
    $valid = $expectedRows < 0 || $count === $expectedRows ? 'OK ' : 'BAD';
    printf("%-35s %10s %12.1f %10.1f %9s %s\n", $label, number_format($count), $ms, $peak, $valid, number_format($count / ($ms / 1000)));
}

printf("%-35s %10s %12s %10s %9s %s\n", 'method', 'rows', 'time (ms)', 'peak MB', 'verify', 'rows/sec');
printf("%'-90s\n", '');

$path = __DIR__ . '/data/large.xlsx';
$expected = 100000;

bench('XlsxStreamer (list rows)', function () use ($path) {
    $count = 0;
    foreach (new XlsxStreamer($path) as $row) {
        strlen($row[1]);
        $count++;
    }
    return $count;
}, $expected + 1); // list mode includes the header row

bench('XlsxStreamer (assoc rows)', function () use ($path) {
    $count = 0;
    foreach (new XlsxStreamer($path, null, true) as $row) {
        strlen($row['Name']);
        $count++;
    }
    return $count;
}, $expected);

bench('XlsxStreamer nextRow() (assoc)', function () use ($path) {
    $s = new XlsxStreamer($path, null, true);
    $count = 0;
    while (($row = $s->nextRow()) !== null) {
        strlen($row['Name']);
        $count++;
    }
    return $count;
}, $expected);

bench('XlsxStreamer nextRows(1000) (assoc)', function () use ($path) {
    $s = new XlsxStreamer($path, null, true);
    $count = 0;
    while (($batch = $s->nextRows(1000)) !== null) {
        foreach ($batch as $row) {
            strlen($row['Name']);
            $count++;
        }
    }
    return $count;
}, $expected);

// Real-world file (optional): 100mb.xlsx is ~560 MB uncompressed; the
// "Tablo3" sheet is a single 354 MB sheet4.xml. Kept out of git.
$real = __DIR__ . '/data/100mb.xlsx';
if (is_file($real)) {
    printf("%'-90s\n", '');
    echo "real-world file present: tests/data/100mb.xlsx\n";

    bench('real: first visible sheet (nextRows 1000)', function () use ($real) {
        $s = new XlsxStreamer($real, null, true);
        $count = 0;
        while (($batch = $s->nextRows(1000)) !== null) {
            $count += count($batch);
        }
        return $count;
    }, -1);

    bench('real: Tablo3 sheet, 354 MB xml (nextRows 1000)', function () use ($real) {
        $s = new XlsxStreamer($real, 'Tablo3', true);
        $count = 0;
        while (($batch = $s->nextRows(1000)) !== null) {
            $count += count($batch);
        }
        return $count;
    }, -1);
}
