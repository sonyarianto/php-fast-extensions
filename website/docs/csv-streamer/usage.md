# Usage

## Constructor

```php
new CsvStreamer(
    string $path,
    ?string $delimiter = ',',    // single character: ',', ';', "\t", ...
    ?bool   $has_headers = false, // first row is the header row
    ?bool   $strict = false       // validate UTF-8, throw on invalid input
)
```

## Iterating

### foreach (Iterator interface)

```php
<?php
$streamer = new CsvStreamer('customers.csv', ',', true);

foreach ($streamer as $key => $row) {
    // $key  = 0-based row index
    // $row  = ['id' => '1', 'name' => 'Alice', ...]
}
```

### nextRow() — single call per row

Avoids the four-call `foreach` protocol (`rewind`/`valid`/`current`/`next`)
for maximum throughput:

```php
<?php
$streamer = new CsvStreamer('customers.csv', ',', true);

while (($row = $streamer->nextRow()) !== null) {
    echo $row['email'], "\n";
}
```

## Rows

Without headers, rows are sequential lists:

```php
<?php
$streamer = new CsvStreamer('data.csv');
foreach ($streamer as $row) {
    echo $row[0]; // first column
}
```

With `$has_headers = true`, rows are associative arrays keyed by the header
row. The header keys are pre-built PHP strings, so this costs almost nothing
per row.

## Custom delimiters

```php
<?php
$tsv = new CsvStreamer('data.tsv', "\t");
$csv = new CsvStreamer('data.csv', ';');
```

## Strict UTF-8 mode

By default bytes are passed through untouched — the fastest path, and PHP
strings are byte strings anyway.

```php
<?php
// Throws \Exception on invalid UTF-8 (e.g. mojibake from a Windows export)
$streamer = new CsvStreamer('data.csv', ',', false, true);
foreach ($streamer as $row) { /* ... */ }
```

## Headers

```php
<?php
$streamer = new CsvStreamer('customers.csv', ',', true);
$headers = $streamer->headers(); // ['id', 'name', 'email', ...]

// Without $has_headers, headers() returns null
$plain = new CsvStreamer('data.csv');
var_dump($plain->headers()); // NULL
```

## Rewinding

Iterators can be rewound — the file is re-seeked to the start and the header
row is re-read:

```php
<?php
$streamer = new CsvStreamer('customers.csv', ',', true);

foreach ($streamer as $row) { /* first pass */ }
foreach ($streamer as $row) { /* second pass */ }
```

## Error handling

All failures are thrown as `\Exception`:

| Situation | Behavior |
|---|---|
| File does not exist / cannot be opened | `\Exception` in constructor |
| Multi-character delimiter | `\Exception` in constructor |
| Invalid UTF-8 in strict mode | `\Exception` with byte offset |
