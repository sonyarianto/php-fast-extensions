# php-fast-extensions

[![CI](https://github.com/sonyarianto/php-fast-extensions/actions/workflows/ci.yml/badge.svg)](https://github.com/sonyarianto/php-fast-extensions/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP: >=8.2](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4.svg)](https://www.php.net/)
[![Rust: stable](https://img.shields.io/badge/Rust-stable-orange.svg)](https://www.rust-lang.org/)

A collection of high-performance PHP extensions written in Rust with
[ext-php-rs](https://github.com/extphprs/ext-php-rs).

## Extensions

| Extension | Directory | Status |
|---|---|---|
| `rust_csv_streamer` — streaming CSV reader | `csv_streamer/` | ✅ Working |
| `rust_excel_streamer` — streaming XLSX reader | `excel_streamer/` | ✅ Working |
| `rust_json_streamer` — streaming JSON array reader | `json_streamer/` | ✅ Working |

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

- PHP 8.2 or later
- Rust (stable)
- PHP development headers (`php-config`, e.g. `php8.3-dev` on Debian)
- Clang (`libclang-dev` on Debian) — required by ext-php-rs build script

### Build

```bash
cd csv_streamer
cargo build --release
# -> target/release/libcsv_streamer.so
```

### Install

Add the extension to `php.ini`:

```ini
extension=/absolute/path/to/csv_streamer/target/release/libcsv_streamer.so
```

or load it per-invocation:

```bash
php -d extension=csv_streamer/target/release/libcsv_streamer.so script.php
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
| `nextRows(int $count)` | `array\|null` | Read up to `$count` rows in one batch |
| `headers()` | `array\|null` | Header row as a list, or null when disabled |

### Performance

Benchmarked on a 2,000,000-row / 349 MB CSV (12 columns), output verified
byte-identical between readers, peak memory measured with
`memory_get_peak_usage(true)`:

| Method | Time | Peak memory | Speedup |
|---|---|---|---|
| `fgetcsv` | 22,149 ms | 2.0 MB | x1.00 |
| `CsvStreamer` `foreach` (list) | 2,264 ms | 2.0 MB | **x9.8** |
| `CsvStreamer` `foreach` (assoc) | 2,494 ms | 2.0 MB | **x8.9** |
| `CsvStreamer` `nextRow()` (assoc) | 2,369 ms | 2.0 MB | **x9.4** |
| `CsvStreamer` `nextRows(1000)` (assoc) | 2,342 ms | 4.0 MB | **x9.5** |
| `fgetcsv` + `array_combine` (assoc) | 22,107 ms | 2.0 MB | x1.00 |

Design notes that got it there: header keys cached as PHP `zend_string`s
(`ArrayKey::ZendString` — no per-row key allocation or hashing), keyless
`zend_hash_next_index_insert` for list rows, raw `ByteRecord` parsing (no
UTF-8 validation unless strict mode is requested, single allocation per cell),
and a 1 MB read buffer.

### Tests & benchmarks

```bash
cd csv_streamer
php tests/generate_csv.php          # generates small fixture files
php -d extension=target/release/libcsv_streamer.so tests/test.php
php -d extension=target/release/libcsv_streamer.so tests/bench.php
```

`tests/bench_customers.php` benchmarks the real 2M-row dataset
(`tests/data/customers-2000000.csv`) — not committed to the repo, generate or
place it there yourself.

## Development

Build, test and benchmark every extension from the repo root:

```bash
make build   # cargo build --release for all extensions
make test    # build + run each extension's PHP test suite
make bench   # build + run each extension's benchmark
make clean
```

## rust_excel_streamer

A high-performance, streaming XLSX reader for PHP. Reads `.xlsx` / `.xlsm`
workbooks row by row straight from the sheet XML, keeping memory usage
constant regardless of file size. Implements PHP's `Iterator` interface.

### Features

- **Streaming / lazy**: rows are parsed in internal batches of 1,000 from the
  sheet XML inside the zip container — peak memory stays ~2 MB on a 100k-row
  sheet
- **`foreach` support**: implements `Iterator` (`current`, `key`, `next`,
  `rewind`, `valid`)
- **`nextRow()` / `nextRows($n)` hot paths**: one call per row or one call
  per batch
- **Header-aware**: opt-in associative rows keyed by header (pre-built PHP
  string keys)
- **Sheet selection**: pick any visible sheet by name, or default to the
  first one; `XlsxStreamer::sheets($path)` lists them
- **Native cell types**: numbers become `int`/`float`, booleans become
  `bool`, dates/times become ISO-8601 strings, blank cells become `null`
- **Constant memory**: shared strings and styles are loaded once; only one
  batch of rows is materialized at a time
- **Errors as exceptions**: missing files, unknown sheets and malformed
  workbooks throw `\Exception`

### Usage

```php
<?php
// First visible sheet, header-aware
$streamer = new XlsxStreamer('report.xlsx', null, true);

foreach ($streamer as $i => $row) {
    echo $row['name'], ' joined ', $row['joined'], "\n";
}

// Specific sheet, no headers
$raw = new XlsxStreamer('report.xlsx', 'Raw Data');

// List sheets first
$sheets = XlsxStreamer::sheets('report.xlsx'); // ['Summary', 'Raw Data']
```

### API

`new XlsxStreamer(string $path, ?string $sheet = null, ?bool $has_headers = false)`

Implements `\Iterator` (`current`, `key`, `next`, `rewind`, `valid`), plus:

| Method | Returns | Description |
|---|---|---|
| `nextRow()` | `array\|null` | Advance and return the row in one call |
| `nextRows(int $count)` | `array\|null` | Read up to `$count` rows in one batch |
| `headers()` | `array\|null` | Header row as a list, or null when disabled |
| `sheetName()` | `string` | Name of the sheet being read |
| `XlsxStreamer::sheets($path)` | `string[]` | Visible sheet names (static) |

### Performance

On a 100,000-row / 8-column generated sheet (3.8 MB xlsx):

| Method | Time | Peak memory | Rows/sec |
|---|---|---|---|
| `foreach` (list rows) | 812 ms | 2.0 MB | 123k |
| `foreach` (assoc rows) | 850 ms | 2.0 MB | 118k |
| `nextRow()` (assoc rows) | 762 ms | 2.0 MB | 131k |
| `nextRows(1000)` (assoc rows) | 729 ms | 2.0 MB | **137k** |

Real-world file (`retail-sales-data.xlsx`, 231 MB, 500,000 rows × 12 cols):

| Method | Time | Peak memory | Rows/sec |
|---|---|---|---|
| `foreach` (assoc rows) | 4.6–5.1 s | 2.0 MB | 99–109k |
| `nextRow()` (assoc rows) | 4.5–5.3 s | 2.0 MB | 94–112k |
| `nextRows(1000)` (assoc rows) | 4.6–5.1 s | 4.0 MB | 99–108k |

There is no PHP stdlib baseline — PHP has no built-in XLSX reader. The
classic alternative (loading the whole sheet into memory, e.g. via
PhpSpreadsheet) peaks at the full file size; this reader stays at 2-4 MB
regardless of sheet size.

### Comparison with PhpSpreadsheet

Same file, same machine (100,000 rows × 8 cols, 3.8 MB xlsx):

| | PhpSpreadsheet 5.9 (data-only) | XlsxStreamer |
|---|---|---|
| Time | 39.1 s | 0.75 s |
| Peak memory | 466 MB | 2 MB |
| Reads | full in-memory load | streaming (constant memory) |

That is roughly **50x faster and 230x less memory** for bulk value
extraction. Honest caveats: PhpSpreadsheet is a full spreadsheet toolkit
(styles, formulas, merged cells, charts, *writing* files, many formats) —
this comparison covers only reading cell values, which is all XlsxStreamer
does. PhpSpreadsheet's chunked `ReadFilter` mode can cap memory too, but it
re-parses the file per chunk and is much slower. Extrapolating, the
500,000-row retail workbook above would need roughly 3.5 GB with
PhpSpreadsheet; XlsxStreamer does it in ~5 s with 2 MB.

### Tests & benchmarks

```bash
cd excel_streamer
php tests/generate_xlsx.php          # generates small.xlsx + large.xlsx
php -d extension=target/release/libexcel_streamer.so tests/test.php
php -d extension=target/release/libexcel_streamer.so tests/bench.php
```

## rust_json_streamer

A high-performance, streaming reader for JSON files whose top level is a
single large array (`[ {...}, {...}, ... ]`). Reads the file element by
element from a small buffer, so memory usage stays constant no matter how
big the array is. Implements PHP's `Iterator` interface.

The classic `json_decode(file_get_contents($file))` holds the *entire*
decoded array in memory (and the decoded string in between). JsonStreamer
dodges both: a 1M-element array that needs ~2.1 GB with `json_decode`
streams in ~2 MB. If your data is line-delimited (JSONL), plain PHP
(`fgets` + `json_decode` per line) is already fine — this extension targets
single giant arrays.

### Features

- **Streaming / lazy**: elements are scanned out of a 64 KB file buffer —
  peak memory ~2 MB on a 1M-row / 226 MB file (vs 2.1 GB for `json_decode`)
- **`foreach` support**: implements `Iterator` (`current`, `key`, `next`,
  `rewind`, `valid`)
- **`nextRow()` / `nextRows($n)` hot paths**: one call per element or one
  call per batch
- **Type fidelity**: integers stay `int`, floats stay `float` (including
  `1000.0`-style input), `true`/`false`/`null` map to their PHP types,
  strings preserve UTF-8 and escapes
- **Shape mapping**: objects become associative arrays, arrays become lists,
  scalar elements become single-element lists
- **Robust parsing**: elements are parsed independently — one malformed
  element throws `\Exception` with its index, strings with quotes/backslashes
  are handled per the JSON spec
- **Errors as exceptions**: missing files and malformed JSON throw
  `\Exception`

### Usage

```php
<?php
// Iterate a huge top-level array — ~2 MB of memory regardless of size
$streamer = new JsonStreamer('exports.json');
foreach ($streamer as $i => $element) {
    echo $element['id'], "\n"; // objects arrive as assoc arrays
}

// Batch reads amortize per-call overhead
while (($rows = $streamer->nextRows(1000)) !== null) {
    foreach ($rows as $row) { /* ... */ }
}
```

### API

`new JsonStreamer(string $path)`

Implements `\Iterator` (`current`, `key`, `next`, `rewind`, `valid`), plus:

| Method | Returns | Description |
|---|---|---|
| `nextRow()` | `array\|null` | Advance and return the next element in one call |
| `nextRows(int $count)` | `array\|null` | Read up to `$count` elements in one batch |

### Performance

On a generated 1,000,000-row / 226 MB file (`{"id":1,...}`, 10 fields each),
same machine, peak memory via `memory_get_peak_usage(true)`:

| Method | Time | Peak memory | Rows/sec |
|---|---|---|---|
| `foreach` (assoc) | 4.6–4.9 s | 2.0 MB | 205–220k |
| `nextRows(1000)` (assoc) | 5.1–5.2 s | 6.0 MB | 192–196k |
| `json_decode` (default 128 MB limit) | fatal OOM after 39 ms | n/a | n/a |
| `json_decode` (`memory_limit=-1`) | 5.6–5.8 s | ~2,100 MB | ~175k |

`json_decode` cannot process the file at all under the default memory limit
(tried to allocate 236 MB for a single element); with the limit lifted it
finishes slightly slower while using ~1,000x the memory. JsonStreamer's
edge is memory, not raw speed — use it when the file is too big to decode
whole.

### Tests & benchmarks

```bash
cd json_streamer
php tests/generate_json.php         # generates small.json + large.json (226 MB)
php -d extension=target/release/libjson_streamer.so tests/test.php
php -d extension=target/release/libjson_streamer.so tests/bench.php
```

## IDE autocomplete

All extensions ship hand-written PHP stubs (docblocks + signatures, kept in
sync with the Cargo sources) so IDEs can autocomplete and type-check classes
that only exist at runtime:

- `csv_streamer/stubs/CsvStreamer.php`
- `excel_streamer/stubs/XlsxStreamer.php`
- `json_streamer/stubs/JsonStreamer.php`

Point your IDE's include path at them, or add them to your project's
`composer.json`:

```json
"autoload": {
    "files": [
        "csv_streamer/stubs/CsvStreamer.php",
        "excel_streamer/stubs/XlsxStreamer.php",
        "json_streamer/stubs/JsonStreamer.php"
    ]
}
```

## License

MIT
