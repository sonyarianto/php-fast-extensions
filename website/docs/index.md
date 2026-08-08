---
layout: home

hero:
  name: "php-fast-extensions"
  text: "High-performance PHP extensions written in Rust"
  tagline: "Native Zend API bindings via ext-php-rs. Streaming, constant-memory CSV, XLSX, JSON and XML readers — 9.8x faster than fgetcsv, ~50x faster than a PhpSpreadsheet full load, ~1,000x less memory than json_decode on huge arrays, ~4.1 GB saved on a 280 MB XML document, and ~2-4 MB peak memory on multi-hundred-MB files."
  actions:
    - theme: brand
      text: CsvStreamer
      link: /csv-streamer/
    - theme: alt
      text: XlsxStreamer
      link: /excel-streamer/
    - theme: alt
      text: JsonStreamer
      link: /json-streamer/
    - theme: alt
      text: XmlStreamer
      link: /xml-streamer/
    - theme: alt
      text: View on GitHub
      link: https://github.com/sonyarianto/php-fast-extensions

features:
  - icon: ⚡
    title: Blazing-fast parsing
    details: CSV via the Rust csv crate — up to 9.8x faster than fgetcsv on 2M-row files. XLSX streams 500,000 rows in ~5 s — ~50x faster than a full PhpSpreadsheet load.
  - icon: 🧠
    title: Constant memory
    details: Lazy, row-by-row streaming with a single reused buffer. Peak memory stays at 2-4 MB on a 349 MB CSV, a 231 MB workbook, a 226 MB JSON array, or a 280 MB XML document — while json_decode needs ~2.1 GB, DOMDocument ~4.1 GB, and both under-report through PHP's memory_limit.
  - icon: 🗂️
    title: Native PHP ergonomics
    details: Implements Iterator, so foreach just works. Associative rows keyed by header, native cell types (int, float, bool, datetime, null), custom delimiters, typed XML values.
  - icon: 🦀
    title: Built on Rust
    details: ext-php-rs bindings to the Zend API. Safe, fast, and zero GC pressure in the hot path.
---

## Current extensions

| Extension | Description |
|---|---|
| [CsvStreamer](/csv-streamer/) | Streaming CSV reader with associative rows, custom delimiters and two UTF-8 modes |
| [XlsxStreamer](/excel-streamer/) | Streaming XLSX reader with constant memory, sheet selection and native cell types |
| [JsonStreamer](/json-streamer/) | Streaming reader for huge JSON arrays — ~2 MB peak memory where json_decode needs gigabytes |
| [XmlStreamer](/xml-streamer/) | Streaming XML reader with row-tag matching and typed values — ~4 MB peak memory where DOMDocument needs ~4.1 GB |

## Quick start

```bash
cd csv_streamer
cargo build --release
```

```php
<?php
$streamer = new CsvStreamer('customers.csv', ',', true);

foreach ($streamer as $row) {
    echo $row['email'], "\n"; // header-named access
}
```

Or for XLSX:

```php
<?php
$streamer = new XlsxStreamer('report.xlsx', null, true);

foreach ($streamer as $row) {
    echo $row['name'], ' joined ', $row['joined'], "\n";
}
```

Or for a huge JSON array:

```php
<?php
$streamer = new JsonStreamer('exports.json'); // 226 MB, 1M elements

foreach ($streamer as $element) {
    echo $element['id'], "\n"; // ~2 MB peak memory
}
```
