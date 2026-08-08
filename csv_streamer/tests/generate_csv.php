<?php

// Generate test CSV files used by test.php and bench.php.

$dir = __DIR__ . '/data';
if (!is_dir($dir)) {
    mkdir($dir);
}

// ---- Small file with headers, quoted commas, escaped quotes, CRLF ----
$rows = [
    ['id', 'name', 'note'],
    ['1', 'Alice', 'hello, world'],
    ['2', 'Bob "The Builder"', 'quote "" inside'],
    ['3', 'César', 'unicode ✓'],
    ['', '', ''],
    ['5', 'last', 'no trailing newline'],
];
$handle = fopen("$dir/small.csv", 'wb');
foreach ($rows as $r) {
    fputcsv($handle, $r, ',', '"', '\\');
}
fclose($handle);

// ---- Semicolon-delimited, no headers ----
file_put_contents(
    "$dir/semicolon.csv",
    "1;alpha;x\n2;beta;y\n3;gamma;z\n"
);

// ---- UTF-8 BOM + headers ----
file_put_contents(
    "$dir/bom.csv",
    "\xEF\xBB\xBFcol_a,col_b\na,1\nb,2\n"
);

// ---- Large file for benchmarking (no headers, 500k rows) ----
$rows = 500_000;
$fh = fopen("$dir/large.csv", 'wb');
for ($i = 0; $i < $rows; $i++) {
    fwrite($fh, "$i,user_$i,email_$i@example.com,42.5,\"quoted,value $i\"\n");
}
fclose($fh);

// ---- Large file with headers for the assoc benchmark ----
$fh = fopen("$dir/large_headers.csv", 'wb');
fwrite($fh, "id,name,email,score,note\n");
for ($i = 0; $i < $rows; $i++) {
    fwrite($fh, "$i,user_$i,email_$i@example.com,42.5,\"quoted,value $i\"\n");
}
fclose($fh);

printf("generated: %s/small.csv, %s/semicolon.csv, %s/bom.csv, %s/large.csv (%d rows), %s/large_headers.csv (%d rows)\n",
    $dir, $dir, $dir, $dir, $rows, $dir, $rows);
