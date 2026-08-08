<?php

declare(strict_types=1);

// Benchmark: XmlStreamer vs DOMDocument over a 1M-row file.
// DOMDocument runs in a subprocess so an OOM fatal cannot kill this script.

$data = __DIR__ . '/data/large.xml';

function bench_stream(string $label, string $path, ?int $batch = null): array {
    $s = new XmlStreamer($path);
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

function realPeakMb(): float {
    $p = @file_get_contents('/proc/self/status');
    if ($p !== false && preg_match('/VmHWM:\s+(\d+)/', $p, $m)) {
        return $m[1] / 1024;
    }
    return memory_get_peak_usage(true) / 1024 / 1024;
}

function bench_dom(string $label, string $path, string $memLimit): array {
    $script = '$t0 = hrtime(true);'
        . '$d = new DOMDocument();'
        . '$d->load($argv[1]);'
        . '$rows = $d->getElementsByTagName("row");'
        . '$s = 0; foreach ($rows as $r) { $s += (int) $r->getElementsByTagName("id")->item(0)->textContent; }'
        . '$p = @file_get_contents("/proc/self/status");'
        . '$peak = preg_match("/VmHWM:\s+(\d+)/", $p, $m) ? $m[1] / 1024 : memory_get_peak_usage(true) / 1048576;'
        . 'echo json_encode(["sum" => $s, "rows" => $rows->length, "ms" => (hrtime(true) - $t0) / 1e6, "peak" => $peak]);';
    $out = null;
    $rc = 0;
    exec(
        escapeshellarg(PHP_BINARY)
            . ' -d memory_limit=' . $memLimit
            . ' -r ' . escapeshellarg($script)
            . ' ' . escapeshellarg($path),
        $out,
        $rc
    );
    $result = json_decode(implode('', $out), true);
    return [
        'label' => $label,
        'rows' => $result['rows'] ?? 0,
        'ms' => $result['ms'] ?? 0,
        'peak_mb' => isset($result['peak']) ? $result['peak'] : -1,
        'failed' => $rc !== 0 || $result === null,
    ];
}

$results = [];
$results[] = bench_stream('XmlStreamer foreach', $data);
$results[] = bench_stream('XmlStreamer nextRows(1000)', $data, 1000);
$results[] = bench_dom('DOMDocument (128M limit)', $data, '128M');
echo str_pad('', 80, '=') . "\n";
echo str_pad('implementation', 30) . str_pad('rows', 12) . str_pad('time', 12) . str_pad('rows/sec', 14) . str_pad('peak', 10) . "\n";
echo str_pad('', 80, '=') . "\n";
foreach ($results as $r) {
    if (isset($r['failed']) && $r['failed']) {
        echo str_pad($r['label'], 30) . "FAILED (OOM or crash)\n";
        continue;
    }
    $rate = $r['ms'] > 0 ? $r['rows'] / ($r['ms'] / 1000) : 0;
    printf(
        "%-30s %12d %10.0f ms %12.0f %8.1f MB\n",
        $r['label'],
        $r['rows'],
        $r['ms'],
        $rate,
        $r['peak_mb']
    );
}
