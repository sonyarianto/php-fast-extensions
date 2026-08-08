# Performance

Benchmarked on a generated **1,000,000-row / 280 MB file** (10 fields per
row), memory measured with `memory_get_peak_usage(true)`:

| Method | Time | Peak memory | Rows/sec |
|---|---:|---:|---:|
| `foreach` (assoc) | 4.2–4.8 s | 2.0 MB | ~209–228k |
| `nextRows(1000)` (assoc) | 4.2–4.5 s | 4.0 MB | ~222–240k |

## vs `DOMDocument`

`DOMDocument` is the PHP built-in baseline — and the whole point of this
extension. Same file, same machine:

| Method | Time | Peak memory |
|---|---:|---:|
| `DOMDocument` (128 MB limit) | ~9.0 s | ~4,100 MB real RSS |
| `XmlStreamer` `foreach` | 4.2–4.8 s | **2.0 MB** |

Two caveats worth knowing about the baseline:

- **`memory_limit` does not apply** — libxml allocates the document tree
  outside PHP's emalloc, so PHP's limit (and `memory_get_peak_usage`)
  under-report the real cost. The ~4.1 GB figure is real peak RSS from
  `/proc/self/status` (`VmHWM`).
- **~2.6x slower** even on hardware with enough RAM to hold the tree.

`SimpleXML` behaves the same (whole-document tree in libxml).

## Why it's fast

- **Streaming parser** — rows are read element by element from a small
  buffer (quick-xml); only one row's array is ever alive
- **Local-name matching** — row/child/attribute keys are matched against
  the stripped local name, with no namespace resolution overhead
- **Reused buffers** — the per-row text and event buffers are reused, so
  steady-state memory stays flat
- **Batch reads** — `nextRows()` amortizes the PHP-side per-call overhead

## Cost structure

The 4.2–4.8 s for 1M rows breaks down as roughly:

| Phase | Share |
|---|---|
| XML event scanning + row parse (Rust) | ~1/2 |
| PHP array construction per row | ~1/2 |

Batching (`nextRows`) trims the PHP-side per-call overhead; the remaining
work is the parse plus array allocation, which is inherently per-row.

## Reproducing

```bash
cd xml_streamer
cargo build --release
php tests/generate_xml.php   # writes tests/data/large.xml (280 MB)
php -d extension=target/release/libxml_streamer.so tests/bench.php
```
