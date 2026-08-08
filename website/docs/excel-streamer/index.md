# XlsxStreamer

A high-performance, streaming XLSX reader for PHP. Reads `.xlsx` / `.xlsm`
workbooks row by row straight from the sheet XML, so memory usage stays
constant no matter how large the file is — no whole-file loading, no
PhpSpreadsheet memory spikes.

## Why streaming matters

XLSX files are zip containers holding sheet XML. Loading a 100 MB sheet into
memory (the common approach) means holding 100 MB of parsed rows at once.
`XlsxStreamer` reads the sheet XML incrementally in batches of 1,000 rows and
releases each batch before pulling the next — peak memory stays at 2-4 MB
regardless of file size.

## Feature highlights

- **`foreach` support** — implements `Iterator` (`current`, `key`, `next`,
  `rewind`, `valid`)
- **Hot paths** — `nextRow()` for one row per call, `nextRows($n)` for batch
  reads
- **Header-aware** — associative rows keyed by header with pre-built PHP
  string keys
- **Sheet selection** — read any visible sheet by name, defaulting to the
  first one; `XlsxStreamer::sheets($path)` lists all visible sheets
- **Native PHP types** — numbers become `int`/`float`, booleans become
  `bool`, dates and times become ISO-8601 strings, blank cells become `null`
- **Error handling** — missing files, unknown sheets and malformed workbooks
  throw `\Exception`
- **Proven on 500k rows** — streams a 231 MB retail-sales workbook
  (500,000 × 12) in ~5 s with 2 MB peak memory

## Supported cell types

| Excel cell | PHP value |
|---|---|
| Number | `int` when integral, else `float` |
| Boolean | `bool` |
| Date / Datetime / Time | ISO-8601 string (`2024-01-03`, `2024-01-01 12:00:00`, `12:00:00`) |
| Shared / inline string | `string` |
| Blank cell | `null` |

## Limitations

- Reading only — formulas are not evaluated; formula cells expose their
  cached string result if present
- Rows with trailing blank cells have fewer entries (trailing empty cells
  are omitted by the parser)
- `rewind()` re-opens the sheet from the start (rare, so acceptable)
