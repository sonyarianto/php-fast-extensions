---
layout: home

hero:
  name: "php-fast-extensions"
  text: "High-performance PHP extensions written in Rust"
  tagline: "Native Zend API bindings via ext-php-rs. Streaming, constant-memory readers that leave the standard library in the dust."
  actions:
    - theme: brand
      text: Get Started
      link: /csv-streamer/
    - theme: alt
      text: View on GitHub
      link: https://github.com/sonyarianto/php-fast-extensions

features:
  - icon: ⚡
    title: Up to 9.6x faster
    details: Parses CSV with the Rust csv crate — ~5x faster than fgetcsv on synthetic data, ~9.6x on real-world 2M-row files.
  - icon: 🧠
    title: Constant memory
    details: Lazy, row-by-row streaming with a single reused buffer. Peak memory ~2 MB on a 349 MB file. No memory_limit errors.
  - icon: 🗂️
    title: Native PHP ergonomics
    details: Implements Iterator, so foreach just works. Associative rows keyed by header, custom delimiters, strict UTF-8 mode.
  - icon: 🦀
    title: Built on Rust
    details: ext-php-rs bindings to the Zend API. Safe, fast, and zero GC pressure in the hot path.
---

## Current extensions

| Extension | Description |
|---|---|
| [CsvStreamer](/csv-streamer/) | Streaming CSV reader with associative rows, custom delimiters and two UTF-8 modes |

More coming soon — streaming Excel (`.xlsx`) readers and batch database ingest.

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
