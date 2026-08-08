<?php

declare(strict_types=1);

// Generates test fixtures for the json_streamer extension:
//   tests/data/small.json  -- hand-crafted edge cases (committed shape)
//   tests/data/large.json  -- ~1M rows, ~250 MB, enough to make
//                             json_decode() hit memory_limit (not committed)

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

// --- small.json -----------------------------------------------------------
$small = [
    [
        'id' => 1,
        'name' => 'Alice',
        'active' => true,
        'score' => 9.99,
        'note' => null,
        'tags' => ['a', 'b', 'c'],
        'nested' => ['x' => 1, 'y' => [2, 3]],
        'unicode' => '日本語のテキスト',
        'quotes' => 'she said "hello" \\ with escapes',
    ],
    [
        'id' => 2,
        'name' => 'Bob O\'Brien',
        'active' => false,
        'score' => 1e3,
        'note' => 'big number',
    ],
    [
        'id' => 3,
        'name' => 'Carol',
        'active' => true,
        'score' => 0,
        'note' => 'zero',
        'tags' => [],
        'nested' => [],
    ],
];
file_put_contents(
    "$dataDir/small.json",
    "[ " . implode(",\n", array_map(fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION), $small)) . "\n]\n"
);

// --- large.json -----------------------------------------------------------
$rows = 1_000_000;
$fh = fopen("$dataDir/large.json", 'w');
fwrite($fh, "[\n");
for ($i = 0; $i < $rows; $i++) {
    $row = [
        'id' => $i + 1,
        'name' => "Customer Number $i",
        'email' => "user$i@example.com",
        'active' => $i % 2 === 0,
        'score' => round(($i % 1000) / 7.0, 2),
        'balance' => $i * 13,
        'note' => $i % 100 === 0 ? null : "note $i",
        'tags' => $i % 3 === 0 ? ['vip', 'repeat'] : ['regular'],
        'joined' => sprintf('%04d-%02d-%02d', 2020 + $i % 6, 1 + $i % 12, 1 + $i % 28),
        'metadata' => ['source' => 'generator', 'row' => $i],
    ];
    fwrite($fh, ($i > 0 ? ",\n" : "") . json_encode($row));
}
fwrite($fh, "\n]\n");
fclose($fh);

printf("small.json: %s (%.1f KB)\n", filesize("$dataDir/small.json"), filesize("$dataDir/small.json") / 1024);
printf("large.json: %s (%.1f MB)\n", filesize("$dataDir/large.json"), filesize("$dataDir/large.json") / 1024 / 1024);
