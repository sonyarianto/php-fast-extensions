# Performance

Benchmarked on a generated **100,000-row / 8-column sheet** (3.8 MB xlsx),
memory measured with `memory_get_peak_usage(true)`:

| Method | Time | Peak memory | Rows/sec |
|---|---:|---:|---:|
| `foreach` (list rows) | 812 ms | 2.0 MB | 123k |
| `foreach` (assoc rows) | 850 ms | 2.0 MB | 118k |
| `nextRow()` (assoc rows) | 762 ms | 2.0 MB | 131k |
| `nextRows(1000)` (assoc rows) | 729 ms | 2.0 MB | **137k** |

There is no PHP stdlib baseline: PHP has no built-in XLSX reader. The
classic alternative — loading the whole sheet into memory (e.g. via
PhpSpreadsheet) — peaks at the full file size. This reader stays at ~2 MB
regardless of file size because only one internal batch of 1,000 rows is
materialized at a time.

## Why it's fast

- **Streaming parser** — sheet XML is read incrementally with
  [xlsx_batch_reader](https://crates.io/crates/xlsx_batch_reader); rows are
  parsed directly from the zip stream
- **Shared strings & styles loaded once** — the workbook's string table and
  number-format map are built a single time, not per row
- **Cached header keys** — associative rows do no key allocation or hashing
  per row
- **No conversion detours** — cells become PHP zvals directly, with numbers
  kept as `int`/`float` instead of stringified

## Cost structure

The 729 ms for `nextRows(1000)` breaks down as roughly:

| Phase | Share |
|---|---|
| XML parsing + cell conversion (Rust) | ~2/3 |
| PHP array construction per row | ~1/3 |

Batching (`nextRows`) trims the PHP-side per-call overhead; the remaining
work is the XML parse itself, which is inherently per-cell.

## Reproducing

```bash
cd excel_streamer
cargo build --release
php tests/generate_xlsx.php
php -d extension=target/release/libexcel_streamer.so tests/bench.php
```
