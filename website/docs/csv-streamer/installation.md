# Installation

## Requirements

- PHP 8.1 or later
- Rust (stable)
- PHP development headers (`php-config` — e.g. `php8.3-dev` on Debian)
- Clang (`libclang-dev` on Debian) — used by the ext-php-rs build script

## Build

```bash
cd csv_streamer
cargo build --release
```

The extension is produced at:

```
csv_streamer/target/release/librust_csv_streamer.so
```

## Load

Add to `php.ini`:

```ini
extension=/absolute/path/to/csv_streamer/target/release/librust_csv_streamer.so
```

Or load it per invocation:

```bash
php -d extension=csv_streamer/target/release/librust_csv_streamer.so script.php
```

## Verify

```bash
php -m | grep csv_streamer
php -r 'var_dump(new ReflectionClass("CsvStreamer"));'
```
