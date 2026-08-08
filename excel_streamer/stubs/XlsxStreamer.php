<?php

/**
 * Streaming XLSX reader with constant memory usage.
 *
 * Reads .xlsx / .xlsm sheets row by row straight from the sheet XML,
 * implements Iterator, and offers header-named associative rows,
 * sheet selection, and native cell types (int, float, bool,
 * ISO-8601 datetime strings, null).
 *
 * @package php-fast-extensions
 */
final class XlsxStreamer implements \Iterator
{
    /**
     * Open a workbook for streaming.
     *
     * @param string $path Path to the .xlsx / .xlsm file
     * @param string|null $sheet Sheet name to read; null = first visible sheet
     * @param bool|null $has_headers Treat the first row as the header row
     * @throws \Exception When the file cannot be opened or the sheet is missing
     */
    public function __construct(string $path, ?string $sheet = null, ?bool $has_headers = false) {}

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
     * Rewind to the first row (re-opens the sheet and re-reads the header when enabled).
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

    /**
     * @return string Name of the sheet being read
     */
    public function sheetName(): string {}

    /**
     * List the visible sheet names of a workbook without opening any sheet.
     *
     * @param string $path Path to the .xlsx / .xlsm file
     * @return string[]
     * @throws \Exception When the file cannot be opened
     */
    public static function sheets(string $path): array {}
}
