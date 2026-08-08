# Usage

## Constructor

```php
new JsonStreamer(string $path)
```

The file's top level must be an array — otherwise construction throws.

## Iterating

### foreach (Iterator interface)

```php
<?php
$streamer = new JsonStreamer('exports.json');

foreach ($streamer as $key => $element) {
    // $key     = 0-based element index
    // $element = ['id' => 1, 'name' => 'Alice', ...]  (objects become assoc arrays)
}
```

### nextRow() / nextRows($n)

```php
<?php
$streamer = new JsonStreamer('exports.json');

// One element per call
while (($element = $streamer->nextRow()) !== null) {
    echo $element['id'], "\n";
}

// Batched — one call per 1,000 elements, fewest PHP calls
$streamer = new JsonStreamer('exports.json');
while (($batch = $streamer->nextRows(1000)) !== null) {
    foreach ($batch as $element) {
        echo $element['id'], "\n";
    }
}
```

## Elements

Objects become associative arrays and arrays become lists; the shape is
preserved recursively:

```php
<?php
// {"id": 1, "active": true, "score": 9.99, "tags": ["a", "b"], "nested": {"x": null}}
$element = $streamer->nextRow();
var_dump($element['id']);     // int(1)
var_dump($element['active']); // bool(true)
var_dump($element['score']);  // float(9.99)
var_dump($element['tags']);   // ['a', 'b']
var_dump($element['nested']); // ['x' => null]
```

Numbers keep their JSON type: `100` arrives as `int`, `100.0` arrives as
`float` — identical to `json_decode`.

## Rewinding

Iterators can be rewound — the file is re-opened from the start:

```php
<?php
$streamer = new JsonStreamer('exports.json');

foreach ($streamer as $element) { /* first pass */ }
foreach ($streamer as $element) { /* second pass */ }
```

## Error handling

All failures are thrown as `\Exception`:

| Situation | Behavior |
|---|---|
| File does not exist / cannot be opened | `\Exception` in constructor |
| Top level is not an array | `\Exception` in constructor |
| Malformed JSON element | `\Exception` during iteration, naming the element index |
