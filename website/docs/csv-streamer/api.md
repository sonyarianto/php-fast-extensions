# API Reference

## Class: `CsvStreamer`

```php
final class CsvStreamer implements \Iterator
```

## Constructor

### `__construct(string $path, ?string $delimiter = ',', ?bool $has_headers = false, ?bool $strict = false)`

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$path` | `string` | — | Path to the CSV file |
| `$delimiter` | `?string` | `','` | Field delimiter, must be a single character |
| `$has_headers` | `?bool` | `false` | Treat the first row as the header row |
| `$strict` | `?bool` | `false` | Validate UTF-8 and throw on invalid input |

Throws `\Exception` if the file cannot be opened or the delimiter is invalid.

## Iterator methods

Implements `\Iterator`, so `foreach` works directly on the object.

### `current(): array|null`

Returns the current row:
- associative array keyed by header name when `$has_headers` is enabled
- sequential list otherwise

Returns `null` before iteration has started or once it has finished.

### `key(): int`

The 0-based index of the current row.

### `next(): void`

Advances to the next row. Does nothing once iteration is complete.

### `rewind(): void`

Rewinds to the first row. Re-seeks the file to offset 0 and re-reads the
header row (if enabled).

### `valid(): bool`

Returns whether the current position is valid. Reads the first record lazily
when iteration has not yet started.

## Convenience methods

### `nextRow(): array|null`

Advances to the next row and returns it in a single call — the fastest way to
iterate. Returns `null` at end of file.

### `nextRows(int $count): array|null`

Reads up to `$count` rows in a single call and returns them as an array of
row arrays. Returns fewer than `$count` rows near end of file, or `null`
when no rows remain. A `$count` of 0 returns `null`.

Amortizes the per-call overhead across the batch — most effective on narrow
rows, and a natural fit for chunked bulk inserts:

```php
<?php
$streamer = new CsvStreamer('customers.csv', ',', true);
$stmt = $pdo->prepare('INSERT INTO customers VALUES (?, ?, ?)');

while (($batch = $streamer->nextRows(1000)) !== null) {
    $pdo->beginTransaction();
    foreach ($batch as $row) {
        $stmt->execute([$row['id'], $row['name'], $row['email']]);
    }
    $pdo->commit();
}
```

### `headers(): array|null`

Returns the header row as a sequential list, or `null` when `$has_headers` is
disabled.
