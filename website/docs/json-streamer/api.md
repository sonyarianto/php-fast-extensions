# API Reference

## Class: `JsonStreamer`

```php
final class JsonStreamer implements \Iterator
```

## Constructor

### `__construct(string $path)`

| Parameter | Type | Description |
|---|---|---|
| `$path` | `string` | Path to a UTF-8 JSON file whose top level is an array |

Throws `\Exception` if the file cannot be opened or the top level is not an
array.

## Iterator methods

Implements `\Iterator`, so `foreach` works directly on the object.

### `current(): array|null`

Returns the current element:
- objects become associative arrays
- arrays become sequential lists
- scalar elements (top-level `"str"`, `123`, `true`, `null`) become a
  single-element list containing the value

Returns `null` before iteration has started or once it has finished.

### `key(): int`

The 0-based index of the current element.

### `next(): void`

Advances to the next element. Does nothing once iteration is complete.

### `rewind(): void`

Rewinds to the first element. Re-opens the file from the start.

### `valid(): bool`

Returns whether the current position is valid. Reads the first element
lazily when iteration has not yet started.

## Convenience methods

### `nextRow(): array|null`

Advances to the next element and returns it in a single call. Returns
`null` at end of file.

### `nextRows(int $count): array|null`

Reads up to `$count` elements in a single call and returns them as an array
of element arrays. Returns fewer than `$count` elements near end of file,
or `null` when no elements remain. A `$count` of 0 returns `null`.

```php
<?php
$streamer = new JsonStreamer('exports.json');

while (($batch = $streamer->nextRows(1000)) !== null) {
    foreach ($batch as $element) {
        echo $element['id'], "\n";
    }
}
```
