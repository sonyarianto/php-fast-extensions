# API Reference

## Class: `XmlStreamer`

```php
final class XmlStreamer implements \Iterator
```

## Constructor

### `__construct(string $path, ?string $row = 'row', ?bool $typed = false)`

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$path` | `string` | — | Path to a UTF-8 XML file |
| `$row` | `?string` | `'row'` | Local name of the row element |
| `$typed` | `?bool` | `false` | Infer `int` / `float` / `bool` from text |

Throws `\Exception` if the file cannot be opened.

## Iterator methods

Implements `\Iterator`, so `foreach` works directly on the object.

### `current(): array|null`

Returns the current row as an associative array (see the [usage page](/xml-streamer/usage) for the shape). Returns `null` before iteration has started or once it has finished.

### `key(): int`

The 0-based index of the current row.

### `next(): void`

Advances to the next row. Does nothing once iteration is complete.

### `rewind(): void`

Rewinds to the first row. Re-opens the file from the start.

### `valid(): bool`

Returns whether the current position is valid. Reads the first row lazily when iteration has not yet started.

## Convenience methods

### `nextRow(): array|null`

Advances to the next row and returns it in a single call. Returns `null` at end of file.

### `nextRows(int $count): array|null`

Reads up to `$count` rows in a single call and returns them as an array of row arrays. Returns fewer than `$count` rows near end of file, or `null` when no rows remain. A `$count` of 0 returns `null`.

```php
<?php
$streamer = new XmlStreamer('export.xml');

while (($batch = $streamer->nextRows(1000)) !== null) {
    foreach ($batch as $row) {
        echo $row['id'], "\n";
    }
}
```
