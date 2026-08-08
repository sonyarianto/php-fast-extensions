# Installation

## Requirements

- PHP 8.2 or later
- Rust (stable)
- PHP development headers (`php-config`, e.g. `php8.3-dev` on Debian)
- Clang (`libclang-dev` on Debian) — required by ext-php-rs build script

## Build

```bash
cd json_streamer
cargo build --release
# -> target/release/libjson_streamer.so
```

## Install

Add the extension to `php.ini`:

```ini
extension=/absolute/path/to/json_streamer/target/release/libjson_streamer.so
```

or load it per-invocation:

```bash
php -d extension=json_streamer/target/release/libjson_streamer.so script.php
```

## From the repo root

```bash
make build   # builds all extensions
make test    # builds + runs every extension's test suite
```

## IDE autocomplete

`JsonStreamer` exists only at runtime, so IDEs need the stub file:

- `json_streamer/stubs/JsonStreamer.php` (signatures + docblocks)

Point your IDE's include path at it, or autoload it via `composer.json`:

```json
"autoload": {
    "files": ["json_streamer/stubs/JsonStreamer.php"]
}
```
