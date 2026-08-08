# Performance

Benchmarked on a generated **1,000,000-element / 226 MB file**
(`{"id":1,...}`, 10 fields per element), memory measured with
`memory_get_peak_usage(true)`:

| Method | Time | Peak memory | Rows/sec |
|---|---:|---:|---:|
| `foreach` (assoc) | 4.6–4.9 s | 2.0 MB | 205–220k |
| `nextRows(1000)` (assoc) | 5.1–5.2 s | 6.0 MB | 192–196k |

## vs `json_decode`

`json_decode` is the PHP built-in baseline — and the whole point of this
extension. Same file, same machine:

| Method | Time | Peak memory |
|---|---:|---:|
| `json_decode` (default 128 MB limit) | **fatal OOM** after 39 ms | n/a |
| `json_decode` (`memory_limit=-1`) | 5.6–5.8 s | ~2,100 MB |
| `JsonStreamer` `foreach` | 4.6–4.9 s | **2.0 MB** |

`json_decode` cannot process the file at all under the default memory
limit (it tried to allocate 236 MB for a single element); with the limit
lifted it finishes slightly slower while using ~1,000x the memory.

JsonStreamer's edge is memory, not raw speed — use it when the file is too
big to decode whole.

## Why it's fast

- **Streaming scanner** — a dedicated byte scanner walks a 64 KB buffer and
  extracts one top-level element at a time; no full-file reads
- **No per-element string copies** — elements are parsed straight from the
  buffer with serde_json
- **Batch reads** — `nextRows()` amortizes the PHP-side per-call overhead;
  the dominant cost is per-element array allocation

## Cost structure

The 4.6–4.9 s for 1M elements breaks down as roughly:

| Phase | Share |
|---|---|
| Scanning + element parse (Rust) | ~1/3 |
| PHP array construction per element | ~2/3 |

Batching (`nextRows`) trims the PHP-side per-call overhead; the remaining
work is the element parse plus array allocation, which is inherently
per-element.

## Reproducing

```bash
cd json_streamer
cargo build --release
php tests/generate_json.php   # writes tests/data/large.json (226 MB)
php -d extension=target/release/libjson_streamer.so tests/bench.php
```
