# Performance

Benchmarked on a **2,000,000-row, 349 MB CSV** (12 columns). Output was
verified byte-identical between all readers, and memory was measured with
`memory_get_peak_usage(true)`.

| Method | Time | Peak memory | Speedup |
|---|---:|---:|---:|
| `fgetcsv` | 21,259 ms | 2.0 MB | x1.00 |
| `CsvStreamer` `foreach` (list rows) | 2,220 ms | 2.0 MB | **x9.6** |
| `CsvStreamer` `foreach` (assoc rows) | 2,492 ms | 2.0 MB | **x9.2** |
| `CsvStreamer` `nextRow()` (assoc rows) | 2,278 ms | 2.0 MB | **x9.3** |
| `fgetcsv` + `array_combine` (assoc rows) | 22,864 ms | 2.0 MB | x1.00 |

## Why it's fast

- **Cached header keys** — header names are pre-built as PHP `zend_string`s
  (`ArrayKey::ZendString`), so associative rows do no key allocation and no
  key hashing per row
- **Keyless list inserts** — list rows use `zend_hash_next_index_insert`
  directly
- **Single allocation per cell** — raw CSV bytes are copied straight from the
  parser buffer into a PHP string, skipping UTF-8 validation (unless strict
  mode is requested) and intermediate allocations
- **1 MB read buffer** — fewer `read()` syscalls
- **Lazy protocol** — `nextRow()` collapses the four-call `foreach` protocol
  into one call per row

## Cost attribution

On the 2M-row benchmark (349 MB file):

| Phase | Time |
|---|---:|
| Parse + FFI protocol | ~1.1 s |
| PHP array construction (24M cells) | ~1.2 s |

The array construction phase is 24M mandatory `zend_string` allocations —
PHP arrays own refcounted string copies, so this is the floor for a
row-array API.

## Reproducing

```bash
cd csv_streamer
cargo build --release
php tests/generate_csv.php
php -d extension=target/release/libcsv_streamer.so tests/bench.php
```

For the real-dataset benchmark, place `customers-2000000.csv` in
`tests/data/` (not part of the repo) and run:

```bash
php -d extension=target/release/libcsv_streamer.so tests/bench_customers.php
```
