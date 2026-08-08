# Usage

## Constructor

```php
new XmlStreamer(string $path, ?string $row = 'row', ?bool $typed = false)
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$path` | `string` | — | Path to a UTF-8 XML file |
| `$row` | `?string` | `'row'` | Local name of the row element |
| `$typed` | `?bool` | `false` | Infer `int` / `float` / `bool` from text |

Throws `\Exception` if the file cannot be opened.

## Iterating

### foreach (Iterator interface)

```php
<?php
$streamer = new XmlStreamer('export.xml');

foreach ($streamer as $key => $row) {
    // $key = 0-based row index
    // $row = ['id' => '1', 'name' => 'Alice', ...]
}
```

### nextRow() / nextRows($n)

```php
<?php
$streamer = new XmlStreamer('export.xml');

// One row per call
while (($row = $streamer->nextRow()) !== null) {
    echo $row['id'], "\n";
}

// Batched — one call per 1,000 rows, fewest PHP calls
$streamer = new XmlStreamer('export.xml');
while (($batch = $streamer->nextRows(1000)) !== null) {
    foreach ($batch as $row) {
        echo $row['id'], "\n";
    }
}
```

## Row shape

Given this row element:

```xml
<row id="10">
  <name>Widget</name>
  <tag>a</tag>
  <tag>b</tag>
  <meta>note</meta>
</row>
```

the streamer yields:

```php
[
    '@attributes' => ['id' => '10'],
    'name' => 'Widget',
    'tag' => ['a', 'b'],   // repeated tags become lists
    'meta' => 'note',
]
```

Rules:

- Child elements become keys; repeated child tags become a list
- Attributes land under `@attributes` (xmlns declarations skipped)
- Text on an element with attributes becomes `@value`
- A text-only leaf row (`<row>5</row>`) is wrapped as `['@value' => '5']`
- An element with both text and children yields children plus a normalized
  `@value` (whitespace collapsed)
- Namespace prefixes are stripped from row, child and attribute names

## Typed mode

With `$typed = true`, text that looks like a boolean, integer or float is
converted:

```php
<?php
$streamer = new XmlStreamer('inventory.xml', 'item', true);

while (($row = $streamer->nextRow()) !== null) {
    var_dump($row['price']); // float(9.99) instead of "9.99"
}
```

The empty string always stays a string, and arbitrary strings are left
untouched.

## Row tag matching

Rows are matched by local name at any depth of the document, so wrapper
elements and comments between rows are skipped:

```php
<?php
// Reads the <entry> elements regardless of nesting or prefixes
$streamer = new XmlStreamer('feed.xml', 'entry');
```

## Rewinding

Iterators can be rewound — the file is re-opened from the start:

```php
<?php
$streamer = new XmlStreamer('export.xml');

foreach ($streamer as $row) { /* first pass */ }
foreach ($streamer as $row) { /* second pass */ }
```

## Error handling

All failures are thrown as `\Exception`:

| Situation | Behavior |
|---|---|
| File does not exist / cannot be opened | `\Exception` in constructor |
| Malformed markup (mismatched tags, bad entities, unterminated input) | `\Exception` during iteration, with the byte offset |
