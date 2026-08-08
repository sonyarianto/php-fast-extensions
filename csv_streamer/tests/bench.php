<?php

declare(strict_types=1);

// Benchmark: CsvStreamer vs fgetcsv over a 500k-row file.

$data = __DIR__ . '/data/large.csv';
$headersData = __DIR__ . '/data/large_headers.csv';

function bench_ext(string $label, string $path, ?string $delimiter = null, ?bool $hasHeaders = null, ?string $sumKey = null): array {
    $s = $hasHeaders !== null
        ? new CsvStreamer($path, $delimiter ?? ',', $hasHeaders)
        : new CsvStreamer($path);
    $sum = 0;
    $count = 0;
    $t0 = hrtime(true);
    foreach ($s as $row) {
        $sum += (int) ($sumKey !== null ? $row[$sumKey] : $row[0]);
        $count++;
    }
    $t1 = hrtime(true);
    return [
        'label' => $label,
        'rows' => $count,
        'ms' => ($t1 - $t0) / 1e6,
        'peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
    ];
}

function bench_fgetcsv(string $label, string $path): array {
    $fh = fopen($path, 'rb');
    $sum = 0;
    $count = 0;
    $t0 = hrtime(true);
    while (($row = fgetcsv($fh)) !== false) {
        $sum += (int) ($row[0]);
        $count++;
    }
    $t1 = hrtime(true);
    fclose($fh);
    return [
        'label' => $label,
        'rows' => $count,
        'ms' => ($t1 - $t0) / 1e6,
        'peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
    ];
}

function bench_batch(string $label, string $path, int $batch, ?bool $hasHeaders = null, ?string $sumKey = null): array {
    $s = new CsvStreamer($path, ',', $hasHeaders ?? false);
    $sum = 0;
    $count = 0;
    $t0 = hrtime(true);
    while (($rows = $s->nextRows($batch)) !== null) {
        foreach ($rows as $row) {
            $sum += (int) ($sumKey !== null ? $row[$sumKey] : $row[0]);
            $count++;
        }
    }
    $t1 = hrtime(true);
    return [
        'label' => $label,
        'rows' => $count,
        'ms' => ($t1 - $t0) / 1e6,
        'peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
    ];
}

$results = [];
$results[] = bench_fgetcsv('fgetcsv (list rows)', $data);
$results[] = bench_ext('CsvStreamer (list rows)', $data);
$results[] = bench_ext('CsvStreamer (assoc rows)', $headersData, ',', true, 'id');
$results[] = bench_batch('CsvStreamer nextRows(1000) (list)', $data, 1000);
$results[] = bench_batch('CsvStreamer nextRows(1000) (assoc)', $headersData, 1000, true, 'id');

// fgetcsv with header mapping (assoc equivalent)
$fh = fopen($headersData, 'rb');
$header = fgetcsv($fh);
$sum = 0;
$count = 0;
$t0 = hrtime(true);
while (($row = fgetcsv($fh)) !== false) {
    $row = array_combine($header, $row);
    $sum += (int) ($row['id']);
    $count++;
}
$t1 = hrtime(true);
fclose($fh);
$results[] = [
    'label' => 'fgetcsv + array_combine (assoc rows)',
    'rows' => $count,
    'ms' => ($t1 - $t0) / 1e6,
    'peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
];

printf("%-35s %10s %12s %10s\n", 'method', 'rows', 'time (ms)', 'peak MB');
printf("%'-70s\n", '');
foreach ($results as $r) {
    $ratio = $r['ms'] > 0 ? $results[0]['ms'] / $r['ms'] : 0;
    printf("%-35s %10s %12.1f %10.1f %10s\n", $r['label'], number_format($r['rows']), $r['ms'], $r['peak_mb'], 'x' . number_format($ratio, 2));
}
