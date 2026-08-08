# php-fast-extensions

A collection of high-performance PHP extensions written in Rust with
[ext-php-rs](https://github.com/extphprs/ext-php-rs).

## Extensions

| Extension | Directory | Status |
|---|---|---|
| `rust_csv_streamer` — streaming CSV reader | `csv_streamer/` | ✅ Working |

---

## rust_csv_streamer

A high-performance, streaming CSV reader for PHP. Parses CSV files row by row
using the [csv](https://crates.io/crates/csv) Rust crate, keeping memory usage
constant regardless of file size. Implements PHP's `Iterator` interface, so it
works directly with `foreach`.

### Features

- **Streaming / lazy**: rows are parsed one at a time into a single reused
  buffer — peak memory stays ~2 MB even for 349 MB files
- **`foreach` support**: implements `Iterator` (`current`, `key`, `next`,
  `rewind`, `valid`)
- **`nextRow()` hot path**: returns the next row in a single call, avoiding
  the 4-call `foreach` protocol overhead
- **Header-aware**: opt-in associative rows keyed by header name (pre-built
  PHP string keys — no per-row key allocation or hashing)
- **Custom delimiter**: any single character (`,` `;` `\t` ...)
- **UTF-8 modes**: lenient (default, raw bytes pass through, fastest) or
  strict (validates and throws on invalid input)
- **Robust parsing**: quoted fields, escaped quotes, embedded delimiters and
  newlines, missing trailing newline, UTF-8 BOM stripped from the header
- **Errors as exceptions**: missing files, bad delimiters and invalid UTF-8
  (strict mode) throw `\Exception`

### Requirements

- PHP 8.1 or later
- Rust (stable)
- PHP development headers (`php-config`, e.g. `php8.3-dev` on Debian)
- Clang (`libclang-dev` on Debian) — required by ext-php-rs build script

### Build

```bash
cd csv_streamer
cargo build --release
# -> target/release/librust_csv_streamer.so
```

### Install

Add the extension to `php.ini`:

```ini
extension=/absolute/path/to/csv_streamer/target/release/librust_csv_streamer.so
```

or load it per-invocation:

```bash
php -d extension=csv_streamer/target/release/librust_csv_streamer.so script.php
```

### Usage

```php
<?php
// Basic iteration — list rows (0-indexed arrays)
$streamer = new CsvStreamer('large_data.csv');
foreach ($streamer as $i => $row) {
    echo $row[0]; // first column
}

// Associative rows keyed by header
$streamer = new CsvStreamer('customers.csv', ',', true);
while (($row = $streamer->nextRow()) !== null) {
    echo $row['email'], "\n"; // header-named access
}

// Custom delimiter, no headers, strict UTF-8 validation
$streamer = new CsvStreamer('data.tsv', "\t", false, true);
foreach ($streamer as $row) {
    // throws \Exception on invalid UTF-8
}

// Inspect headers (when enabled)
$headers = $streamer->headers(); // ['id', 'name', ...] or null

// Iterators can be rewound
foreach ($streamer as $row) { /* ... */ }
foreach ($streamer as $row) { /* second pass */ }
```

### API

`new CsvStreamer(string $path, ?string $delimiter = ',', ?bool $has_headers = false, ?bool $strict = false)`

Implements `\Iterator`:

| Method | Returns | Description |
|---|---|---|
| `current()` | `array\|null` | Current row (assoc if headers enabled, else list) |
| `key()` | `int` | 0-based row index |
| `next()` | `void` | Advance to next row |
| `rewind()` | `void` | Seek back to the first row (re-reads header) |
| `valid()` | `bool` | Whether the current position is valid (lazy first read) |

Convenience:

| Method | Returns | Description |
|---|---|---|
| `nextRow()` | `array\|null` | Advance and return the row in one call |
| `headers()` | `array\|null` | Header row as a list, or null when disabled |

### Performance

Benchmarked on a 2,000,000-row / 349 MB CSV (12 columns), output verified
byte-identical between readers, peak memory measured with
`memory_get_peak_usage(true)`:

| Method | Time | Peak memory | Speedup |
|---|---|---|---|
| `fgetcsv` | 21,259 ms | 2.0 MB | x1.00 |
| `CsvStreamer` `foreach` (list) | 2,220 ms | 2.0 MB | **x9.6** |
| `CsvStreamer` `foreach` (assoc) | 2,492 ms | 2.0 MB | **x9.2** |
| `CsvStreamer` `nextRow()` (assoc) | 2,278 ms | 2.0 MB | **x9.3** |
| `fgetcsv` + `array_combine` (assoc) | 22,864 ms | 2.0 MB | x1.00 |

Design notes that got it there: header keys cached as PHP `zend_string`s
(`ArrayKey::ZendString` — no per-row key allocation or hashing), keyless
`zend_hash_next_index_insert` for list rows, raw `ByteRecord` parsing (no
UTF-8 validation unless strict mode is requested, single allocation per cell),
and a 1 MB read buffer.

### Tests & benchmarks

```bash
cd csv_streamer
php tests/generate_csv.php          # generates small fixture files
php -d extension=target/release/librust_csv_streamer.so tests/test.php
php -d extension=target/release/librust_csv_streamer.so tests/bench.php
```

`tests/bench_customers.php` benchmarks the real 2M-row dataset
(`tests/data/customers-2000000.csv`) — not committed to the repo, generate or
place it there yourself.

## Roadmap

- Excel streaming (`.xlsx`) — see the `xlsx_batch_reader` approach for
  constant-memory reads
- Batch DB ingest mode (`ingestToDb()`) with bulk inserts
- `cargo-php` stub generation for IDE autocomplete

## License

MIT
