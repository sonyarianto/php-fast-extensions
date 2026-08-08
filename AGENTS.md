# AGENTS.md

Guidance for AI coding agents working in this repository.

## What this is

A collection of high-performance PHP extensions written in Rust with
[ext-php-rs](https://github.com/extphprs/ext-php-rs). Each extension lives in
its own directory and is an independent Rust `cdylib` crate named
`rust_<ext>` that produces `lib<ext>.so`:

| Extension | Crate | PHP class |
|---|---|---|
| `csv_streamer/` | `rust_csv_streamer` | `CsvStreamer` |
| `excel_streamer/` | `rust_excel_streamer` | `XlsxStreamer` |
| `json_streamer/` | `rust_json_streamer` | `JsonStreamer` |
| `xml_streamer/` | `rust_xml_streamer` | `XmlStreamer` |

## Build, test, benchmark

From the repo root (see `Makefile`):

```bash
make build   # cargo build --release for all extensions
make test    # build + run every extension's PHP test suite + stub check
make bench   # build + run every extension's benchmark
```

For a single extension, test data must be generated first — it is NOT
committed (gitignored) and can be large:

```bash
cd xml_streamer
php tests/generate_xml.php    # generates tests/data/*.xml (large.xml is ~280 MB)
php -d extension=target/release/libxml_streamer.so tests/test.php
php -d extension=target/release/libxml_streamer.so tests/bench.php
```

Generators (`tests/generate_*.php`) create both small edge-case fixtures and
a large `data/large.*` file for the memory/perf assertions in `test.php`.

## Conventions (follow these when changing code)

- **PHP-facing API**: `ext-php-rs` exports Rust `snake_case` method names as
  PHP `camelCase` automatically (e.g. `next_row()` → `nextRow()`). Never add
  `#[php(name = ...)]` renames; update the stub instead.
- **Stubs**: every class ships a hand-written stub in `stubs/` (docblocks +
  signatures) for IDE autocomplete. `tests/check_stubs.php` compares stub
  method names and parameter counts against `ReflectionClass` — run
  `make test` (or the stub check alone) after any API change.
- **PHP protocol**: streamer classes implement `\Iterator` (`current`, `key`,
  `next`, `rewind`, `valid`) plus `nextRow()` / `nextRows(int $count)` hot
  paths. `next()`/`valid()` must be lazy (read on demand), `rewind()` must
  re-open the file for a second pass.
- **Memory discipline**: the whole point of these extensions is constant
  memory (2–4 MB on multi-hundred-MB files). Never materialize the whole
  input; reuse buffers between rows; assert peak memory in `test.php`.
- **Errors**: missing files, malformed input and invalid arguments throw
  `\Exception` via `PhpResult` / `String::into()`.
- **Toolchain**: Rust stable, `edition = "2024"`, `ext-php-rs = "0.15"`.
  Release profile uses `lto = true`, `codegen-units = 1`, `panic = "abort"`.

## Verifying changes

1. `cargo build --release` in the extension dir (must be warning-free).
2. Regenerate fixtures, run the extension's `tests/test.php` (exit 0 = pass).
3. Run `make test` from the repo root before committing (also covers the
   stub check).
4. Update `stubs/` and the README's per-extension section when the public
   API or documented behavior changes.
