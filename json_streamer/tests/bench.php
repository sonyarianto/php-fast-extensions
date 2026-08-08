<?php

declare(strict_types=1);

// Benchmark: JsonStreamer vs json_decode over a 1M-row file.
// json_decode runs in a subprocess so an OOM fatal cannot kill this script.

$data = __DIR__ . '/data/large.json';

function bench_stream(string $label, string $path, ?int $batch = null): array {
    $s = new JsonStreamer($path);
    $sum = 0;
    $count = 0;
    $t0 = hrtime(true);
    if ($batch === null) {
        foreach ($s as $row) {
            $sum += (int) $row['id'];
            $count++;
        }
    } else {
        while (($rows = $s->nextRows($batch)) !== null) {
            foreach ($rows as $row) {
                $sum += (int) $row['id'];
                $count++;
            }
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

function bench_json_decode(string $label, string $path, string $memLimit): array {
    $script = '$d = json_decode(file_get_contents($argv[1]), true);'
        . '$s = 0; foreach ($d as $r) { $s += (int) $r["id"]; }'
        . 'echo json_encode(["sum" => $s, "rows" => count($d), "peak" => memory_get_peak_usage(true)]);';
    $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . escapeshellarg($memLimit)
        . ' -r ' . escapeshellarg($script) . ' ' . escapeshellarg($path) . ' 2>&1';
    $t0 = hrtime(true);
    exec($cmd, $out, $code);
    $ms = (hrtime(true) - $t0) / 1e6;
    $last = trim((string) end($out));
    if ($code === 0) {
        $meta = json_decode($last, true);
        return [
            'label' => $label,
            'rows' => $meta['rows'],
            'ms' => $ms,
            'peak_mb' => $meta['peak'] / 1024 / 1024,
            'sum' => $meta['sum'],
        ];
    }
    return [
        'label' => $label,
        'rows' => '-',
        'ms' => $ms,
        'peak_mb' => $last !== '' ? $last : "exit $code",
        'sum' => null,
    ];
}

$results = [];
$results[] = bench_stream('JsonStreamer (assoc rows)', $data);
$results[] = bench_stream('JsonStreamer nextRows(1000) (assoc)', $data, 1000);
$results[] = bench_json_decode('json_decode whole file (128M limit)', $data, '128M');
$results[] = bench_json_decode('json_decode whole file (unlimited)', $data, '-1');

printf("%-40s %10s %12s %18s\n", 'method', 'rows', 'time (ms)', 'peak MB');
printf("%'-82s\n", '');
$base = null;
foreach ($results as $r) {
    if ($base === null && is_float($r['ms']) && $r['ms'] > 0) {
        $base = $r['ms'];
    }
    $ratio = $base && is_float($r['ms']) ? $base / $r['ms'] : null;
    $time = is_float($r['ms']) ? number_format($r['ms'], 1) : $r['ms'];
    $peak = is_float($r['peak_mb']) ? number_format($r['peak_mb'], 1) : $r['peak_mb'];
    $extra = $ratio !== null ? '   x' . number_format($ratio, 2) : '';
    printf("%-40s %10s %12s %18s%s\n", $r['label'], number_format((int) $r['rows']), $time, $peak, $extra);
}
