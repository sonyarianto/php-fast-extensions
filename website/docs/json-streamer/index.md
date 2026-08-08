# JsonStreamer

A high-performance, streaming reader for JSON files whose top level is a
single large array (`[ {...}, {...}, ... ]`). Reads the file element by
element from a small buffer, so memory usage stays constant no matter how
large the array is — no `json_decode` of the whole file.

## Why streaming matters

`json_decode(file_get_contents($file))` holds the entire decoded array in
memory (plus the decoded string in between). A 1,000,000-element array needs
**~2.1 GB** with `json_decode`. `JsonStreamer` scans elements out of a
64 KB file buffer and materializes one element at a time — peak memory
stays at **~2 MB** regardless of file size.

If your data is line-delimited (JSONL), plain PHP (`fgets` + `json_decode`
per line) is already fine — this extension targets single giant arrays.

## Feature highlights

- **`foreach` support** — implements `Iterator` (`current`, `key`, `next`,
  `rewind`, `valid`)
- **Hot paths** — `nextRow()` for one element per call, `nextRows($n)` for
  batch reads
- **Type fidelity** — integers stay `int`, floats stay `float` (including
  `1000.0`-style input), `true`/`false`/`null` map to their PHP types,
  strings preserve UTF-8 and escapes
- **Shape mapping** — objects become associative arrays, arrays become
  lists, scalar elements become single-element lists
- **Error handling** — missing files and malformed JSON throw `\Exception`
  naming the offending element index
- **Proven on 1M elements** — streams a 226 MB array (1,000,000 × 10
  fields) in ~4.8 s with 2 MB peak memory

## Element mapping

| JSON value | PHP value |
|---|---|
| Object `{...}` | Associative array |
| Array `[...]` | Sequential list |
| Number | `int` when integral, else `float` |
| Boolean | `bool` |
| String | `string` (UTF-8 preserved, escapes unescaped) |
| `null` | `null` |
| Scalar element at top level | Single-element list containing the value |

## Limitations

- Top level must be an array; other shapes throw at construction time
- Reading only — no writing, no JSONP / newline-delimited formats
- `rewind()` re-opens the file from the start (rare, so acceptable)
