# Usage

## Constructor

```php
new XlsxStreamer(
    string $path,
    ?string $sheet = null,       // sheet name; null = first visible sheet
    ?bool   $has_headers = false // first row is the header row
)
```

## Iterating

### foreach (Iterator interface)

```php
<?php
$streamer = new XlsxStreamer('report.xlsx', null, true);

foreach ($streamer as $key => $row) {
    // $key = 0-based row index
    // $row = ['id' => 1, 'name' => 'Alice', 'joined' => '2024-01-01 12:00:00', ...]
}
```

### nextRow() / nextRows($n)

```php
<?php
$streamer = new XlsxStreamer('report.xlsx', null, true);

// One row per call
while (($row = $streamer->nextRow()) !== null) {
    echo $row['name'], "\n";
}

// Batched — one call per 1,000 rows, fewest PHP calls
$streamer = new XlsxStreamer('report.xlsx', null, true);
while (($batch = $streamer->nextRows(1000)) !== null) {
    foreach ($batch as $row) {
        echo $row['name'], "\n";
    }
}
```

## Sheet selection

```php
<?php
// List visible sheets first
$sheets = XlsxStreamer::sheets('report.xlsx'); // ['Summary', 'Raw Data']

// Read a specific sheet
$raw = new XlsxStreamer('report.xlsx', 'Raw Data');

// The sheet being read is always available
echo $raw->sheetName(); // 'Raw Data'
```

## Rows

Without headers, rows are sequential lists. With `$has_headers = true`, rows
are associative arrays keyed by the header row.

Values keep their Excel types: numbers become `int`/`float`, booleans become
`bool`, dates/times become ISO-8601 strings, and blank cells become `null`:

```php
<?php
$row = $streamer->nextRow();
var_dump($row['id']);     // int(1)
var_dump($row['active']); // bool(true)
var_dump($row['joined']); // string(19) "2024-01-01 12:00:00"
var_dump($row['note']);   // NULL (blank cell)
```

## Headers

```php
<?php
$streamer = new XlsxStreamer('report.xlsx', null, true);
$headers = $streamer->headers(); // ['id', 'name', 'active', 'joined', 'note', ...]

// Without $has_headers, headers() returns null
$plain = new XlsxStreamer('report.xlsx');
var_dump($plain->headers()); // NULL
```

## Rewinding

Iterators can be rewound — the sheet is re-opened from the start and the
header row is re-read:

```php
<?php
$streamer = new XlsxStreamer('report.xlsx', null, true);

foreach ($streamer as $row) { /* first pass */ }
foreach ($streamer as $row) { /* second pass */ }
```

## Error handling

All failures are thrown as `\Exception`:

| Situation | Behavior |
|---|---|
| File does not exist / cannot be opened | `\Exception` in constructor or `sheets()` |
| Unknown sheet name | `\Exception` in constructor |
| Malformed workbook / sheet XML | `\Exception` during construction or iteration |
