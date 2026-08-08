# Installation

## Requirements

- PHP 8.2 or later
- Rust (stable)
- PHP development headers (`php-config`, e.g. `php8.3-dev` on Debian)
- Clang (`libclang-dev` on Debian) — required by ext-php-rs build script

## Build

```bash
cd excel_streamer
cargo build --release
# -> target/release/libexcel_streamer.so
```

## Install

Add the extension to `php.ini`:

```ini
extension=/absolute/path/to/excel_streamer/target/release/libexcel_streamer.so
```

or load it per-invocation:

```bash
php -d extension=excel_streamer/target/release/libexcel_streamer.so script.php
```

## From the repo root

```bash
make build   # builds all extensions
make test    # builds + runs every extension's test suite
```
