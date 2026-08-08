# XmlStreamer

A high-performance, streaming XML reader for PHP. Parses elements whose
local name equals a configurable row tag (found at any depth of the
document) one at a time straight from the file, so memory usage stays
constant no matter how large the document is — no whole-document
`DOMDocument` / `SimpleXML` load.

## Why streaming matters

`DOMDocument` / `SimpleXML` parse the entire document into an in-memory
tree. A 1,000,000-row document peaks at **~4.1 GB** of real memory
(allocated by libxml outside PHP's `memory_limit`, which only counts
emalloc'd memory — so the limit doesn't even protect you). `XmlStreamer`
walks the file element by element from a small buffer and materializes one
row at a time — peak memory stays at **2-4 MB** regardless of file size.

## Feature highlights

- **`foreach` support** — implements `Iterator` (`current`, `key`, `next`,
  `rewind`, `valid`)
- **Hot paths** — `nextRow()` for one row per call, `nextRows($n)` for
  batch reads
- **Row matching** — rows are elements whose (namespace-prefix-free) local
  name equals the configured row tag, found at any depth; tag and attribute
  names are matched prefix-free
- **Shape mapping** — child elements become assoc-array keys (repeated tags
  become lists), attributes land under `@attributes`, direct text under
  `@value`
- **Typed mode** — opt-in inference of `int` / `float` / `bool` from text
  values
- **Robust parsing** — entities and CDATA decoded, comments / PIs / doctype
  skipped, malformed markup throws `\Exception` with the byte offset
- **Error handling** — missing files and malformed XML throw `\Exception`
- **Proven on 1M rows** — streams a 280 MB document (1,000,000 × 10
  fields) in ~4.2-4.8 s with 2 MB peak memory

## Row mapping

| XML | PHP value |
|---|---|
| `<row><id>1</id></row>` | `['id' => '1']` |
| Repeated child tags | List: `<tag>a</tag><tag>b</tag>` → `'tag' => ['a', 'b']` |
| Attributes | `'@attributes' => ['id' => '10']` |
| Text on an element with attributes | `'@value' => '5'` |
| Text-only leaf row | `['@value' => '5']` (XML has no scalars) |
| Mixed content | Children + `'@value'` with whitespace normalized |
| Namespace prefixes | Stripped from row, child and attribute names |

## Limitations

- Reading only — no writing, no XPath, no stylesheet processing
- XML has no scalar values: a text-only row is wrapped as `{"@value": ...}`
  (or typed with `$typed = true`)
- `rewind()` re-opens the file from the start (rare, so acceptable)
- Large documents stream row by row, but a single oversized row is held in
  memory as one array
