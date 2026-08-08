<?php

declare(strict_types=1);

// Benchmark on the real 2,000,000-row customers dataset.

$path = __DIR__ . '/data/customers-2000000.csv';

function run(string $label, callable $fn): float {
    $t0 = hrtime(true);
    $fn();
    $t1 = hrtime(true);
    $peak = memory_get_peak_usage(true) / 1024 / 1024;
    $ms = ($t1 - $t0) / 1e6;
    printf("%-40s %14s %10.1f\n", $label, number_format($ms, 1) . ' ms', $peak);
    return $ms;
}

function checksum(string $path, bool $assoc): array {
    $sumIndex = 0;
    $sumEmail = 0;
    $rows = 0;
    foreach (new CsvStreamer($path, ',', $assoc) as $i => $row) {
        if (!$assoc && $i === 0) {
            continue;
        }
        $sumIndex += (int) ($assoc ? $row['Index'] : $row[0]);
        $sumEmail += strlen($assoc ? $row['Email'] : $row[9]);
        $rows++;
    }
    return [$rows, $sumIndex, $sumEmail];
}

echo "verifying both readers produce identical results...\n";
[$r1, $s1, $e1] = checksum($path, false);
[$r2, $s2, $e2] = checksum($path, true);
printf("list mode:  rows=%s sumIndex=%s sumEmail=%s\n", number_format($r1), number_format($s1), $e1);
printf("assoc mode: rows=%s sumIndex=%s sumEmail=%s\n", number_format($r2), number_format($s2), $e2);
echo "verification: " . ($r1 === $r2 && $s1 === $s2 && $e1 === $e2 ? "OK\n\n" : "MISMATCH!\n\n");

printf("%-40s %14s %10s\n", 'method', 'time', 'peak MB');
printf("%'-68s\n", '');

$t = [];
$t['fgetcsv_list']  = run('fgetcsv (list rows)', function () use ($path) {
    $fh = fopen($path, 'rb');
    while (($row = fgetcsv($fh)) !== false) { (int) $row[0]; strlen($row[9]); }
    fclose($fh);
});
$t['ext_list'] = run('CsvStreamer (list rows)', function () use ($path) {
    foreach (new CsvStreamer($path) as $row) { (int) $row[0]; strlen($row[9]); }
});
$t['ext_assoc'] = run('CsvStreamer (assoc rows)', function () use ($path) {
    foreach (new CsvStreamer($path, ',', true) as $row) { (int) $row['Index']; strlen($row['Email']); }
});
$t['fgetcsv_assoc'] = run('fgetcsv + array_combine (assoc rows)', function () use ($path) {
    $fh = fopen($path, 'rb');
    $header = fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        $row = array_combine($header, $row);
        (int) $row['Index']; strlen($row['Email']);
    }
    fclose($fh);
});
$t['ext_nextrow'] = run('CsvStreamer nextRow() (assoc rows)', function () use ($path) {
    $s = new CsvStreamer($path, ',', true);
    while (($row = $s->nextRow()) !== null) { (int) $row['Index']; strlen($row['Email']); }
});

echo "\nspeedup vs fgetcsv (list):\n";
printf("  foreach   list : x%.2f\n", $t['fgetcsv_list'] / $t['ext_list']);
printf("  foreach   assoc: x%.2f\n", $t['fgetcsv_assoc'] / $t['ext_assoc']);
printf("  nextRow() assoc: x%.2f\n", $t['fgetcsv_list'] / $t['ext_nextrow']);
