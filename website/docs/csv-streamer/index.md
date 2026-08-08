# CsvStreamer

A high-performance, streaming CSV reader for PHP, written in Rust with
[ext-php-rs](https://github.com/extphprs/ext-php-rs).

## What it does

Parses CSV files row by row using the [csv](https://crates.io/crates/csv) Rust
crate, yielding one row at a time into a single reused buffer. Memory usage is
constant regardless of file size — a 349 MB file streams in ~2 MB of peak
memory.

## Highlights

- **`foreach` support** — implements PHP's `Iterator` interface
- **`nextRow()`** — one-call-per-row hot path with no protocol overhead
- **Associative rows** — opt-in rows keyed by header name
- **Custom delimiters** — any single character
- **Two UTF-8 modes** — lenient (raw bytes, fastest) or strict (validated)
- **Robust parsing** — quoted fields, escaped quotes, embedded delimiters,
  missing trailing newline, BOM stripping
- **Errors as exceptions** — missing files, invalid delimiters, invalid UTF-8

## At a glance

```php
<?php
$streamer = new CsvStreamer('customers.csv', ',', true);

// foreach works out of the box
foreach ($streamer as $row) {
    echo $row['email'], "\n";
}

// ...or use the single-call hot path
$streamer = new CsvStreamer('customers.csv', ',', true);
while (($row = $streamer->nextRow()) !== null) {
    echo $row['email'], "\n";
}
```

## Next steps

- [Installation](./installation)
- [Usage](./usage)
- [API Reference](./api)
- [Performance](./performance)
