# API Reference

## Class: `XlsxStreamer`

```php
final class XlsxStreamer implements \Iterator
```

## Constructor

### `__construct(string $path, ?string $sheet = null, ?bool $has_headers = false)`

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$path` | `string` | — | Path to the `.xlsx` / `.xlsm` file |
| `$sheet` | `?string` | `null` | Sheet name to read; `null` = first visible sheet |
| `$has_headers` | `?bool` | `false` | Treat the first row as the header row |

Throws `\Exception` if the file cannot be opened or the sheet is missing.

## Iterator methods

Implements `\Iterator`, so `foreach` works directly on the object.

### `current(): array|null`

Returns the current row:
- associative array keyed by header name when `$has_headers` is enabled
- sequential list otherwise

Cell values keep their Excel types: numbers become `int`/`float`, booleans
become `bool`, dates/times become ISO-8601 strings, blank cells become
`null`.

Returns `null` before iteration has started or once it has finished.

### `key(): int`

The 0-based index of the current row.

### `next(): void`

Advances to the next row. Does nothing once iteration is complete.

### `rewind(): void`

Rewinds to the first row. Re-opens the sheet from the start and re-reads the
header row (if enabled).

### `valid(): bool`

Returns whether the current position is valid. Reads the first row lazily
when iteration has not yet started.

## Convenience methods

### `nextRow(): array|null`

Advances to the next row and returns it in a single call. Returns `null` at
end of file.

### `nextRows(int $count): array|null`

Reads up to `$count` rows in a single call and returns them as an array of
row arrays. Returns fewer than `$count` rows near end of file, or `null`
when no rows remain. A `$count` of 0 returns `null`.

```php
<?php
$streamer = new XlsxStreamer('report.xlsx', null, true);

while (($batch = $streamer->nextRows(1000)) !== null) {
    foreach ($batch as $row) {
        echo $row['name'], "\n";
    }
}
```

### `headers(): array|null`

Returns the header row as a sequential list, or `null` when `$has_headers` is
disabled.

### `sheetName(): string`

Returns the name of the sheet being read.

## Static methods

### `XlsxStreamer::sheets(string $path): array`

Lists the visible sheet names of a workbook without opening any sheet.
Throws `\Exception` if the file cannot be opened.
