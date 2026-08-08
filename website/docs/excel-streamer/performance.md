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

## Real-world file

`tests/data/retail-sales-data.xlsx` (not committed to git) is a 231 MB
workbook whose single sheet holds **500,000 rows × 12 columns** (~242 MB of
uncompressed sheet XML):

| Method | Time | Peak memory | Rows/sec |
|---|---:|---:|---:|
| `foreach` (assoc rows) | 4.6–5.1 s | 2.0 MB | 99–109k |
| `nextRow()` (assoc rows) | 4.5–5.3 s | 2.0 MB | 94–112k |
| `nextRows(1000)` (assoc rows) | 4.6–5.1 s | 4.0 MB | 99–108k |

`bench.php` automatically adds these rows when the file is present. With
wide rows, batching no longer helps — cell allocation dominates — and peak
memory stays at 2-4 MB regardless of the 231 MB file size.

## Comparison with PhpSpreadsheet

Same file, same machine (100,000 rows × 8 cols, 3.8 MB xlsx,
PhpSpreadsheet 5.9 with `setReadDataOnly(true)`):

| | PhpSpreadsheet | XlsxStreamer |
|---|---|---|
| Time | 39.1 s | 0.75 s |
| Peak memory | 466 MB | 2 MB |

Roughly **50x faster and 230x less memory** for bulk value extraction.

Honest caveats:

- PhpSpreadsheet is a full spreadsheet toolkit (styles, formulas, merged
  cells, charts, file writing, many formats); this comparison covers only
  reading cell values, which is all XlsxStreamer does
- PhpSpreadsheet's chunked `ReadFilter` mode can cap memory too, but it
  re-parses the file once per chunk and is much slower
- Extrapolating, the 500,000-row retail workbook above would need roughly
  3.5 GB with PhpSpreadsheet; XlsxStreamer streams it in ~5 s with 2 MB

## Reproducing

```bash
cd excel_streamer
cargo build --release
php tests/generate_xlsx.php
php -d extension=target/release/libexcel_streamer.so tests/bench.php
```
