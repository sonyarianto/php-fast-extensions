<?php

/**
 * Streaming reader for huge top-level JSON arrays with constant memory.
 *
 * Reads `[ { ... }, { ... }, ... ]` element by element straight from the
 * file, implements Iterator, and returns each element as a PHP array
 * (objects become associative arrays, arrays become lists, scalars become
 * single-element lists). Numbers preserve their JSON type: integers stay
 * int, floats stay float.
 *
 * @package php-fast-extensions
 */
final class JsonStreamer implements \Iterator
{
    /**
     * Open a JSON array file for streaming.
     *
     * @param string $path Path to a UTF-8 JSON file whose top level is an array
     * @throws \Exception When the file cannot be opened or contains no elements
     */
    public function __construct(string $path) {}

    /**
     * @return array|null Current element (assoc for objects, list for arrays), or null when exhausted
     */
    public function current(): ?array {}

    /**
     * @return int 0-based element index
     */
    public function key(): int {}

    /**
     * Advance to the next element.
     */
    public function next(): void {}

    /**
     * Rewind to the first element (re-opens the file).
     */
    public function rewind(): void {}

    /**
     * @return bool Whether the current position is valid
     */
    public function valid(): bool {}

    /**
     * Advance to the next element and return it in a single call.
     *
     * @return array|null The element, or null at end of file
     */
    public function nextRow(): ?array {}

    /**
     * Read up to $count elements in a single call.
     *
     * @param int $count Maximum number of elements to read
     * @return array|null An array of elements (fewer than $count near EOF), or null when no elements remain
     */
    public function nextRows(int $count): ?array {}
}
