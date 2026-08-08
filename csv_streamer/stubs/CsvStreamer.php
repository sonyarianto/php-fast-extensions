<?php

/**
 * Streaming CSV reader with constant memory usage.
 *
 * Reads a CSV file row by row, implements Iterator, and offers
 * header-named associative rows, custom delimiters, and optional
 * strict UTF-8 validation.
 *
 * @package php-fast-extensions
 */
final class CsvStreamer implements \Iterator
{
    /**
     * Open a CSV file for streaming.
     *
     * @param string $path Path to the CSV file
     * @param string|null $delimiter Field delimiter (default: ",")
     * @param bool|null $has_headers Treat the first row as the header row
     * @param bool|null $strict Validate UTF-8 per row (default: false)
     * @throws \Exception When the file cannot be opened
     */
    public function __construct(string $path, ?string $delimiter = ',', ?bool $has_headers = false, ?bool $strict = false) {}

    /**
     * @return array|null Current row (assoc if headers enabled, else list), or null when exhausted
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
     * Rewind to the first row (re-reads the header row when enabled).
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

    /**
     * @return array|null The header row as a list, or null when disabled
     */
    public function headers(): ?array {}
}
