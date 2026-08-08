<?php

/**
 * Streaming reader for huge XML files with constant memory.
 *
 * Reads elements whose local name equals `$row` (found at any depth) one at
 * a time straight from the file and implements Iterator. Each row becomes an
 * associative array: child elements become keys (repeated tags become lists),
 * attributes land under `@attributes`, and direct text under `@value`. Leaf
 * rows are wrapped as `{"@value": ...}`. Namespace prefixes are stripped from
 * tag and attribute names.
 *
 * @package php-fast-extensions
 */
final class XmlStreamer implements \Iterator
{
    /**
     * Open an XML file for streaming.
     *
     * @param string $path Path to a UTF-8 XML file
     * @param string|null $row Local name of the row element (default "row")
     * @param bool|null $typed Infer int/float/bool types from text (default false)
     * @throws \Exception When the file cannot be opened
     */
    public function __construct(string $path, ?string $row = null, ?bool $typed = false) {}

    /**
     * @return array|null Current row as an assoc array, or null when exhausted
     */
    public function current(): ?array {}

    /**
     * @return int 0-based row index
     */
    public function key(): int {}

    /**
     * Advance to the next row.
     */
    public function next(): void {}

    /**
     * Rewind to the first row (re-opens the file).
     */
    public function rewind(): void {}

    /**
     * @return bool Whether the current position is valid
     */
    public function valid(): bool {}

    /**
     * Advance to the next row and return it in a single call.
     *
     * @return array|null The row, or null at end of file
     */
    public function nextRow(): ?array {}

    /**
     * Read up to $count rows in a single call.
     *
     * @param int $count Maximum number of rows to read
     * @return array|null An array of rows (fewer than $count near EOF), or null when no rows remain
     */
    public function nextRows(int $count): ?array {}
}
